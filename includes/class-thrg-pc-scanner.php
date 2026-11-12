<?php
/**
 * Resumable compatibility scanner for plugins, must-use plugins, and themes.
 *
 * Tracks both component-level and file-level progress across time-boxed AJAX
 * steps so large plugins never appear frozen.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resumable scan queue and step runner.
 */
final class THRG_PC_Scanner {

    const STATE_OPTION     = 'thrg_pc_scan_state';
    const STATE_TTL        = 1800;
    const STEP_LOCK_OPTION = 'thrg_pc_scan_step_lock';
    const STEP_LOCK_TTL    = 90;

    /**
     * Normalize a component version string (never empty).
     *
     * @param mixed $version Raw version from plugin/theme headers.
     * @return string
     */
    public static function version_string( $version ) {
        $v = is_string( $version ) ? trim( $version ) : '';
        return '' === $v ? '0' : $v;
    }

    /**
     * Discover all installed plugins, must-use plugins, and themes with active/update metadata.
     *
     * @return array[]
     */
    public static function components() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active_plugins  = (array) get_option( 'active_plugins', array() );
        $network_plugins = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
        $plugin_updates  = get_site_transient( 'update_plugins' );
        $theme_updates   = get_site_transient( 'update_themes' );

        $out = array();
        foreach ( get_plugins() as $file => $data ) {
            $file       = (string) $file;
            $dir        = dirname( $file );
            $is_network = in_array( $file, $network_plugins, true );
            $is_active  = $is_network || in_array( $file, $active_plugins, true );
            $update_ver = '';
            if ( is_object( $plugin_updates ) && isset( $plugin_updates->response[ $file ]->new_version ) ) {
                $update_ver = (string) $plugin_updates->response[ $file ]->new_version;
            }
            $out[] = array(
                'kind'           => 'plugin',
                'slug'           => $file,
                'name'           => isset( $data['Name'] ) && '' !== trim( (string) $data['Name'] ) ? (string) $data['Name'] : $file,
                'version'        => self::version_string( isset( $data['Version'] ) ? $data['Version'] : '' ),
                'dir'            => '.' === $dir ? WP_PLUGIN_DIR : WP_PLUGIN_DIR . '/' . $dir,
                'file'           => '.' === $dir ? basename( $file ) : '',
                'active'         => $is_active,
                'network_active' => $is_network,
                'parent_theme'   => false,
                'update_version' => $update_ver,
            );
        }

        if ( function_exists( 'get_mu_plugins' ) && defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
            foreach ( get_mu_plugins() as $file => $data ) {
                $file  = (string) $file;
                $out[] = array(
                    'kind'           => 'mu-plugin',
                    'slug'           => $file,
                    'name'           => isset( $data['Name'] ) && '' !== trim( (string) $data['Name'] ) ? (string) $data['Name'] : $file,
                    'version'        => self::version_string( isset( $data['Version'] ) ? $data['Version'] : '' ),
                    'dir'            => WPMU_PLUGIN_DIR,
                    'file'           => basename( $file ),
                    'active'         => true,
                    'network_active' => false,
                    'parent_theme'   => false,
                    'update_version' => '',
                );
            }
        }

        if ( function_exists( 'wp_get_themes' ) ) {
            $active_stylesheet = (string) get_option( 'stylesheet' );
            $active_template   = (string) get_option( 'template' );
            foreach ( wp_get_themes() as $theme ) {
                $stylesheet = (string) $theme->get_stylesheet();
                $name       = (string) $theme->get( 'Name' );
                $is_current = $stylesheet === $active_stylesheet;
                $is_parent  = ! $is_current && $stylesheet === $active_template;
                $update_ver = '';
                if ( is_object( $theme_updates ) && isset( $theme_updates->response[ $stylesheet ]['new_version'] ) ) {
                    $update_ver = (string) $theme_updates->response[ $stylesheet ]['new_version'];
                } elseif ( is_object( $theme_updates ) && isset( $theme_updates->response[ $stylesheet ]->new_version ) ) {
                    $update_ver = (string) $theme_updates->response[ $stylesheet ]->new_version;
                }
                $out[] = array(
                    'kind'           => 'theme',
                    'slug'           => $stylesheet,
                    'name'           => '' !== $name ? $name : $stylesheet,
                    'version'        => self::version_string( $theme->get( 'Version' ) ),
                    'dir'            => get_theme_root() . '/' . $stylesheet,
                    'file'           => '',
                    'active'         => $is_current || $is_parent,
                    'network_active' => false,
                    'parent_theme'   => $is_parent,
                    'update_version' => $update_ver,
                );
            }
        }

        return $out;
    }

    /**
     * Get the current running scan job state, or empty array when idle.
     *
     * @return array
     */
    public static function get_state() {
        $state = get_option( self::STATE_OPTION, array() );
        if ( ! is_array( $state ) ) {
            return array();
        }
        if ( $state && ( empty( $state['job_id'] ) || empty( $state['updated_at'] ) || time() - (int) $state['updated_at'] > self::STATE_TTL ) ) {
            self::clear_state( $state );
            $fresh = get_option( self::STATE_OPTION, array() );
            return is_array( $fresh ) && $fresh !== $state ? $fresh : array();
        }
        return $state;
    }

    /**
     * Clear WordPress's cached copy after direct conditional option queries.
     *
     * @return void
     */
    private static function flush_state_cache( $name = self::STATE_OPTION ) {
        wp_cache_delete( $name, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        wp_cache_delete( 'alloptions', 'options' );
    }

    /**
     * Insert one non-autoloaded option only when the slot is empty.
     *
     * @param string $name Option name.
     * @param array  $value Option value.
     * @return bool
     */
    private static function claim_option( $name, array $value ) {
        global $wpdb;
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
                $name,
                maybe_serialize( $value ),
                'no'
            )
        );
        if ( 1 !== $inserted ) {
            return false;
        }
        self::flush_state_cache( $name );
        return true;
    }

    /**
     * Remove an option only if its value has not changed.
     *
     * @param string $name Option name.
     * @param array  $expected Expected value.
     * @return bool
     */
    private static function delete_if_unchanged( $name, array $expected ) {
        global $wpdb;
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                $name,
                maybe_serialize( $expected )
            )
        );
        self::flush_state_cache( $name );
        return 1 === $deleted;
    }

    /**
     * Claim the scan slot with one insert that cannot replace another job.
     *
     * @param array $state New scan state.
     * @return bool
     */
    private static function claim_state( array $state ) {
        return self::claim_option( self::STATE_OPTION, $state );
    }

    /**
     * Whether a scan has work remaining.
     *
     * @param array|null $state Optional known state.
     * @return bool
     */
    public static function is_running( $state = null ) {
        $state = is_array( $state ) ? $state : self::get_state();
        return ! empty( $state['current'] ) || ! empty( $state['queue'] );
    }

    /**
     * Check that a user and job token own the active scan. Cron jobs use user ID
     * zero so any administrator holding the token may resume a partial run.
     *
     * @param string $job_id Job token.
     * @param int    $user_id WordPress user ID.
     * @return bool
     */
    public static function owns_job( $job_id, $user_id ) {
        $state = self::get_state();
        return self::is_running( $state ) && is_string( $job_id ) && '' !== $job_id &&
            isset( $state['job_id'], $state['user_id'] ) && hash_equals( (string) $state['job_id'], $job_id ) &&
            ( 0 === (int) $state['user_id'] || (int) $state['user_id'] === (int) $user_id );
    }

    /**
     * Stop only the job owned by this user and token.
     *
     * @param string $job_id Job token.
     * @param int    $user_id WordPress user ID.
     * @return bool
     */
    public static function stop( $job_id, $user_id ) {
        $state = self::get_state();
        if ( ! self::is_running( $state ) || ! is_string( $job_id ) || '' === $job_id ||
            ! hash_equals( (string) $state['job_id'], $job_id ) ||
            ( 0 !== (int) $state['user_id'] && (int) $state['user_id'] !== (int) $user_id ) ) {
            return false;
        }
        return self::clear_state( $state );
    }

    /**
     * Save the running scan job state (non-autoloaded).
     *
     * @param array $state Scan state.
     * @return void
     */
    public static function set_state( array $state ) {
        update_option( self::STATE_OPTION, $state, false );
    }

    /**
     * Clear scan state, optionally only if it still matches the known job.
     *
     * @param array|null $expected Expected state for conditional deletion.
     * @return bool
     */
    public static function clear_state( $expected = null ) {
        if ( null === $expected ) {
            return delete_option( self::STATE_OPTION );
        }
        return self::delete_if_unchanged( self::STATE_OPTION, $expected );
    }

    /**
     * Replace progress only when no stop, reset, or other step changed it.
     *
     * @param array $expected State read before the scan slice.
     * @param array $next New progress state.
     * @return bool
     */
    private static function replace_state( array $expected, array $next ) {
        global $wpdb;
        $changed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                maybe_serialize( $next ),
                self::STATE_OPTION,
                maybe_serialize( $expected )
            )
        );
        self::flush_state_cache();
        return 1 === $changed;
    }

    /**
     * Claim a short-lived step lock, recovering an abandoned lock when needed.
     *
     * @param string $job_id Current job token.
     * @return array Lock value for conditional release.
     * @throws InvalidArgumentException When another step is active.
     */
    private static function claim_step_lock( $job_id ) {
        $lock = array(
            'job_id'  => (string) $job_id,
            'token'   => bin2hex( random_bytes( 16 ) ),
            'expires' => time() + self::STEP_LOCK_TTL,
        );
        if ( self::claim_option( self::STEP_LOCK_OPTION, $lock ) ) {
            return $lock;
        }
        $previous = get_option( self::STEP_LOCK_OPTION, array() );
        if ( is_array( $previous ) && isset( $previous['expires'] ) && time() > (int) $previous['expires'] &&
            self::delete_if_unchanged( self::STEP_LOCK_OPTION, $previous ) &&
            self::claim_option( self::STEP_LOCK_OPTION, $lock ) ) {
            return $lock;
        }
        throw new InvalidArgumentException( 'A scan step is already running. Please try again.' );
    }

    /**
     * Start a new scan job.
     *
     * @param string $target     Target PHP version ("8.4" style).
     * @param bool   $rescan     True to rescan components that already have a cached result for this version/target.
     * @param string $scope      'all', 'active', or 'single'.
     * @param string $single_key Optional 'kind:slug' when $scope is 'single'.
     * @param int    $user_id    Current user ID (0 for cron).
     * @param string $user_name  Current user login or label.
     * @return array Initialized scan state.
     * @throws InvalidArgumentException When $target is invalid.
     */
    public static function start( $target, $rescan = false, $scope = 'all', $single_key = '', $user_id = 0, $user_name = '' ) {
        $existing = self::get_state();
        if ( self::is_running( $existing ) ) {
            if ( 0 === (int) $existing['user_id'] || (int) $existing['user_id'] === (int) $user_id ) {
                return $existing;
            }
            throw new InvalidArgumentException( 'Another scan is already running.' );
        }
        $target = (string) $target;
        if ( ! in_array( $target, THRG_PHP_Checker::scan_targets(), true ) ) {
            throw new InvalidArgumentException( 'Unknown scan target' );
        }
        $scope      = in_array( $scope, array( 'all', 'active', 'single' ), true ) ? $scope : 'all';
        $single_key = (string) $single_key;
        $queue      = array();

        foreach ( self::components() as $comp ) {
            $key = THRG_PC_Results::key( $comp['kind'], $comp['slug'] );
            if ( 'single' === $scope && $key !== $single_key ) {
                continue;
            }
            if ( 'active' === $scope && empty( $comp['active'] ) ) {
                continue;
            }
            if ( ! $rescan && 'single' !== $scope && null !== THRG_PC_Results::get( $comp['kind'], $comp['slug'], $comp['version'], $target ) ) {
                continue;
            }
            $queue[] = $comp;
        }

        $state = array(
            'job_id'     => bin2hex( random_bytes( 16 ) ),
            'updated_at' => time(),
            'target'     => $target,
            'scope'      => $scope,
            'queue'      => $queue,
            'total'      => count( $queue ),
            'current'    => null,
            'finished'   => 0,
            'completed'  => false,
            'user_id'    => (int) $user_id,
            'user_name'  => (string) $user_name,
        );
        if ( self::claim_state( $state ) ) {
            return $state;
        }
        $existing = self::get_state();
        if ( self::is_running( $existing ) && ( 0 === (int) $existing['user_id'] || (int) $existing['user_id'] === (int) $user_id ) ) {
            return $existing;
        }
        throw new InvalidArgumentException( self::is_running( $existing ) ? 'Another scan is already running.' : 'Could not claim the scan job. Please try again.' );
    }

    /**
     * Prepare a queued component for scanning by enumerating its PHP files.
     *
     * @param array $component Queue entry.
     * @return array
     */
    public static function open_component( array $component ) {
        if ( '' !== $component['file'] ) {
            $files = array( $component['dir'] . '/' . $component['file'] );
        } else {
            $files = array();
            foreach ( THRG_PC_Engine::files( $component['dir'] ) as $rel ) {
                $files[] = $component['dir'] . '/' . $rel;
            }
        }
        $component['files_total'] = count( $files );
        $component['remaining']   = $files;
        $component['findings']    = array();
        return $component;
    }

    /**
     * Relative path inside a component's directory.
     *
     * @param string $path Absolute path.
     * @param string $dir  Component directory.
     * @return string
     */
    public static function relative_path( $path, $dir ) {
        $path   = str_replace( '\\', '/', (string) $path );
        $prefix = rtrim( str_replace( '\\', '/', (string) $dir ), '/' ) . '/';
        return 0 === strpos( $path, $prefix ) ? substr( $path, strlen( $prefix ) ) : ltrim( $path, '/' );
    }

    /**
     * Run the active scan job until $deadline. Scans at least one file per invocation.
     *
     * @param float $deadline microtime(true) cutoff.
     * @return array{done:bool,total:int,finished:int,current:string,files_done:int,files_total:int,target:string}
     */
    public static function step( $deadline, $job_id ) {
        $lock = self::claim_step_lock( $job_id );
        try {
            return self::step_locked( $deadline, $job_id );
        } finally {
            self::delete_if_unchanged( self::STEP_LOCK_OPTION, $lock );
        }
    }

    /**
     * Advance the job while the step lock is held.
     *
     * @param float  $deadline Time limit.
     * @param string $job_id Job token.
     * @return array Progress response.
     */
    private static function step_locked( $deadline, $job_id ) {
        $state = self::get_state();
        if ( $state && ( ! is_string( $job_id ) || ! isset( $state['job_id'] ) || ! hash_equals( (string) $state['job_id'], $job_id ) ) ) {
            throw new InvalidArgumentException( 'Scan job has changed.' );
        }
        $idle = array(
            'done'        => true,
            'total'       => isset( $state['total'] ) ? (int) $state['total'] : 0,
            'finished'    => isset( $state['finished'] ) ? (int) $state['finished'] : 0,
            'current'     => '',
            'files_done'  => 0,
            'files_total' => 0,
            'target'      => isset( $state['target'] ) ? (string) $state['target'] : THRG_PHP_Checker::default_target(),
        );
        if ( ! $state || '' !== THRG_PC_Engine::unavailable_reason() ) {
            return $idle;
        }

        $name        = '';
        $files_done  = 0;
        $files_total = 0;

        do {
            $previous = $state;
            if ( empty( $state['current'] ) ) {
                $next = array_shift( $state['queue'] );
                if ( null === $next ) {
                    break;
                }
                $state['current'] = self::open_component( $next );
            }
            $comp        = $state['current'];
            $name        = (string) $comp['name'];
            $files_total = isset( $comp['files_total'] ) ? (int) $comp['files_total'] : count( $comp['remaining'] );

            $out = THRG_PC_Engine::scan( $comp['remaining'], $state['target'], (float) $deadline );
            foreach ( $out['scanned'] as $path => $findings ) {
                $rel = self::relative_path( (string) $path, $comp['dir'] );
                foreach ( $findings as $finding ) {
                    $comp['findings'][] = THRG_PC_Context::place_finding( $finding, $rel );
                }
            }
            $comp['remaining'] = $out['remaining'];
            $files_done        = max( 0, $files_total - count( $comp['remaining'] ) );

            $completed_comp = null;
            if ( $comp['remaining'] ) {
                $state['current'] = $comp;
            } else {
                $completed_comp = $comp;
                ++$state['finished'];
                $state['current'] = null;
            }
            $state['updated_at'] = time();
            if ( ! self::replace_state( $previous, $state ) ) {
                throw new InvalidArgumentException( 'Scan job changed while this step was running.' );
            }
            if ( null !== $completed_comp ) {
                THRG_PC_Results::save( $completed_comp, $state['target'], $completed_comp['findings'] );
            }
        } while ( microtime( true ) < $deadline && ( $state['current'] || $state['queue'] ) );

        if ( $state['current'] || $state['queue'] ) {
            return array(
                'done'        => false,
                'total'       => (int) $state['total'],
                'finished'    => (int) $state['finished'],
                'current'     => $name,
                'files_done'  => $files_done,
                'files_total' => $files_total,
                'target'      => (string) $state['target'],
            );
        }

        if ( empty( $state['completed'] ) ) {
            $previous           = $state;
            $state['completed'] = true;
            if ( ! self::replace_state( $previous, $state ) ) {
                throw new InvalidArgumentException( 'Scan job changed while this step was running.' );
            }
            if ( 'single' !== ( isset( $state['scope'] ) ? $state['scope'] : 'all' ) ) {
                $rows      = THRG_PHP_Checker::evaluate( THRG_PHP_Checker::collect( $state['target'] ) );
                $user_id   = isset( $state['user_id'] ) ? (int) $state['user_id'] : get_current_user_id();
                $user_name = isset( $state['user_name'] ) && '' !== $state['user_name'] ? (string) $state['user_name'] : '';
                if ( '' === $user_name && $user_id > 0 ) {
                    $u         = get_userdata( $user_id );
                    $user_name = $u ? (string) $u->user_login : '';
                }
                THRG_PC_History::add( $rows, $state['target'], wp_parse_url( home_url(), PHP_URL_HOST ), THRG_PHP_Checker::version(), $user_id, $user_name, time() );
            }
        }
        self::clear_state( $state );
        return array(
            'done'        => true,
            'total'       => (int) $state['total'],
            'finished'    => (int) $state['finished'],
            'current'     => '',
            'files_done'  => 0,
            'files_total' => 0,
            'target'      => (string) $state['target'],
        );
    }
}

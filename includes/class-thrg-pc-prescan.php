<?php
/**
 * Opt-in update pre-scanner: checks offered plugin and theme updates against
 * the running PHP version by reading PHP files inside the update zip in memory.
 *
 * Off by default so no outbound requests occur unless enabled. Never blocks or
 * alters WordPress updates.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

/**
 * In-memory zip pre-scanner for offered plugin and theme updates.
 */
final class THRG_PC_Prescan {

    const STATE_OPTION = 'thrg_pc_prescan_state';
    const HOOK         = 'thrg_pc_prescan_updates';
    const MAX_TRIES    = 3;
    const TIMEOUT      = 300;

    /**
     *Cached open archive handle for the current request.
     *
     * @var array{zip:string,mtime:int,handle:array|null}
     */
    private static $open_archive = array(
        'zip'    => '',
        'mtime'  => 0,
        'handle' => null,
    );

    /**
     * Register the hourly cron callback.
     *
     * @return void
     */
    public static function boot() {
        add_action( self::HOOK, array( __CLASS__, 'cron' ) );
    }

    /**
     * Build the tries-map key for an offered update.
     *
     * @param string $kind    'plugin' or 'theme'.
     * @param string $slug    Offer slug.
     * @param string $version Offered version.
     * @return string
     */
    public static function offer_key( $kind, $slug, $version ) {
        return (string) $kind . ':' . (string) $slug . ':' . (string) $version;
    }

    /**
     * Get the current pre-scan state.
     *
     * @return array
     */
    public static function get_state() {
        $state = get_option( self::STATE_OPTION, array() );
        return is_array( $state ) ? $state : array();
    }

    /**
     * Save the pre-scan state (non-autoloaded).
     *
     * @param array $state State array.
     * @return void
     */
    public static function set_state( array $state ) {
        update_option( self::STATE_OPTION, $state, false );
    }

    /**
     * Enumerate all update offers currently recorded in WordPress's update transients.
     *
     * @return array[] Each with kind, slug, file_key, new_version, package.
     */
    public static function entries() {
        $out = array();
        foreach ( array( 'plugin', 'theme' ) as $kind ) {
            $transient = get_site_transient( 'plugin' === $kind ? 'update_plugins' : 'update_themes' );
            $response  = is_object( $transient ) && isset( $transient->response ) && is_array( $transient->response ) ? $transient->response : array();
            foreach ( $response as $key => $entry ) {
                $entry = (array) $entry;
                if ( 'plugin' === $kind ) {
                    $folder = dirname( (string) $key );
                    $slug   = isset( $entry['slug'] ) ? (string) $entry['slug'] : ( '.' === $folder ? basename( (string) $key, '.php' ) : $folder );
                } else {
                    $slug = isset( $entry['theme'] ) ? (string) $entry['theme'] : (string) $key;
                }
                $version = isset( $entry['new_version'] ) ? (string) $entry['new_version'] : '';
                $package = isset( $entry['package'] ) && is_string( $entry['package'] ) ? $entry['package'] : '';
                if ( '' === $slug || '' === $version || '' === $package ) {
                    continue;
                }
                $out[] = array(
                    'kind'        => $kind,
                    'slug'        => $slug,
                    'file_key'    => (string) $key,
                    'new_version' => $version,
                    'package'     => $package,
                );
            }
        }
        return $out;
    }

    /**
     * The PHP version updates are checked against (the server's running PHP version, or lowest supported target).
     *
     * @return string
     */
    public static function guard_target() {
        $running = THRG_PHP_Checker::running_php();
        if ( preg_match( '/^[5-9]\.\d{1,2}$/', $running ) ) {
            return $running;
        }
        return THRG_PHP_Checker::default_target();
    }

    /**
     * Stored verdict for an offered update against guard_target(), or '' when not checked.
     *
     * @param string $kind    'plugin' or 'theme'.
     * @param string $slug    Offer slug.
     * @param string $version Offered version.
     * @return string
     */
    public static function update_verdict( $kind, $slug, $version ) {
        $row = THRG_PC_Results::get_update( $kind, $slug, $version, self::guard_target() );
        return null === $row ? '' : (string) $row['verdict'];
    }

    /**
     * Status of an offered update ('checked', 'pending', or 'could_not_check').
     *
     * @param string $kind    'plugin' or 'theme'.
     * @param string $slug    Offer slug.
     * @param string $version Offered version.
     * @return string
     */
    public static function status( $kind, $slug, $version ) {
        if ( '' !== self::update_verdict( $kind, $slug, $version ) ) {
            return 'checked';
        }
        $state = self::get_state();
        $tries = isset( $state['tries'] ) && is_array( $state['tries'] ) ? $state['tries'] : array();
        $key   = self::offer_key( $kind, $slug, $version );
        if ( isset( $tries[ $key ] ) && (int) $tries[ $key ] >= self::MAX_TRIES ) {
            return 'could_not_check';
        }
        return 'pending';
    }

    /**
     * Update offers that still need a pre-scan.
     *
     * @return array[]
     */
    public static function pending_offers() {
        $state = self::get_state();
        $tries = isset( $state['tries'] ) && is_array( $state['tries'] ) ? $state['tries'] : array();
        $out   = array();
        foreach ( self::entries() as $offer ) {
            if ( '' !== self::update_verdict( $offer['kind'], $offer['slug'], $offer['new_version'] ) ) {
                continue;
            }
            $key = self::offer_key( $offer['kind'], $offer['slug'], $offer['new_version'] );
            if ( isset( $tries[ $key ] ) && (int) $tries[ $key ] >= self::MAX_TRIES ) {
                continue;
            }
            $out[] = $offer;
        }
        return $out;
    }

    /**
     * Build the list of offered updates and their pre-scan status for the UI and admin warnings.
     *
     * @param array[] $components Output of THRG_PC_Scanner::components().
     * @return array[]
     */
    public static function checks( array $components ) {
        $installed = array();
        foreach ( $components as $c ) {
            if ( 'plugin' === $c['kind'] ) {
                $folder                              = dirname( $c['slug'] );
                $short                               = '.' === $folder ? basename( $c['slug'], '.php' ) : $folder;
                $installed[ 'plugin:' . $short ]     = $c;
                $installed[ 'plugin:' . $c['slug'] ] = $c;
            } elseif ( 'theme' === $c['kind'] ) {
                $installed[ 'theme:' . $c['slug'] ] = $c;
            }
        }

        $target = self::guard_target();
        $rows   = array();
        foreach ( self::entries() as $offer ) {
            $status = self::status( $offer['kind'], $offer['slug'], $offer['new_version'] );
            $result = 'checked' === $status ? THRG_PC_Results::get_update( $offer['kind'], $offer['slug'], $offer['new_version'], $target ) : null;
            $comp   = isset( $installed[ $offer['kind'] . ':' . $offer['slug'] ] ) ? $installed[ $offer['kind'] . ':' . $offer['slug'] ] : null;
            if ( ! $comp && isset( $installed[ $offer['kind'] . ':' . $offer['file_key'] ] ) ) {
                $comp = $installed[ $offer['kind'] . ':' . $offer['file_key'] ];
            }
            $rows[] = array(
                'kind'    => $offer['kind'],
                'slug'    => $offer['slug'],
                'name'    => $comp ? $comp['name'] : $offer['slug'],
                'current' => $comp ? $comp['version'] : '',
                'version' => $offer['new_version'],
                'status'  => null !== $result ? $result['verdict'] : $status,
                'result'  => $result,
                'target'  => $target,
            );
        }
        return $rows;
    }

    /**
     * Close any cached ZipArchive handle.
     *
     * @return void
     */
    public static function zip_close() {
        if ( is_array( self::$open_archive['handle'] ) && self::$open_archive['handle']['archive'] instanceof ZipArchive ) {
            self::$open_archive['handle']['archive']->close();
        }
        self::$open_archive = array(
            'zip'    => '',
            'mtime'  => 0,
            'handle' => null,
        );
    }

    /**
     * Open a downloaded zip file and index its entries.
     *
     * @param string $zip Path to the zip archive.
     * @return array|WP_Error
     */
    public static function zip_open( $zip ) {
        $zip = (string) $zip;
        clearstatcache( true, $zip );
        $mtime = is_file( $zip ) ? (int) filemtime( $zip ) : 0;
        if ( self::$open_archive['zip'] === $zip && self::$open_archive['mtime'] === $mtime && is_array( self::$open_archive['handle'] ) ) {
            return self::$open_archive['handle'];
        }
        self::zip_close();
        $error   = new WP_Error( 'incompatible_archive', __( 'The update package is not a readable zip file.', 'thrg-php-checker' ) );
        $entries = array();
        if ( 0 === $mtime ) {
            return $error;
        }
        // Respect WordPress core's unzip_file_use_ziparchive filter.
        $use_ziparchive = class_exists( 'ZipArchive', false ) && apply_filters( 'unzip_file_use_ziparchive', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        if ( $use_ziparchive ) {
            $archive = new ZipArchive();
            if ( true !== $archive->open( $zip, ZipArchive::CHECKCONS ) ) {
                return $error;
            }
            for ( $i = 0; $i < $archive->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                $stat = $archive->statIndex( $i );
                if ( is_array( $stat ) ) {
                    $entries[ (string) $stat['name'] ] = array( $i, (int) $stat['size'] );
                }
            }
        } else {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            $archive = new PclZip( $zip );
            $list    = $archive->listContent();
            if ( ! is_array( $list ) ) {
                return $error;
            }
            foreach ( $list as $entry ) {
                $entries[ (string) $entry['filename'] ] = array( (int) $entry['index'], (int) $entry['size'] );
            }
        }
        self::$open_archive = array(
            'zip'    => $zip,
            'mtime'  => $mtime,
            'handle' => array(
                'archive' => $archive,
                'entries' => $entries,
            ),
        );
        return self::$open_archive['handle'];
    }

    /**
     * Read one entry from a zip into memory without writing to disk.
     *
     * @param string $zip   Zip path.
     * @param string $name  Entry name.
     * @param int    $limit Max bytes.
     * @return string|false|null
     */
    public static function zip_read( $zip, $name, $limit ) {
        $handle = self::zip_open( $zip );
        if ( is_wp_error( $handle ) || ! isset( $handle['entries'][ $name ] ) ) {
            return false;
        }
        list( $index, $size ) = $handle['entries'][ $name ];
        if ( $size > $limit ) {
            return null;
        }
        if ( $handle['archive'] instanceof ZipArchive ) {
            $content = $handle['archive']->getFromIndex( $index, max( 1, (int) $limit ) );
            return is_string( $content ) ? $content : false;
        }
        $out = $handle['archive']->extract( PCLZIP_OPT_BY_INDEX, $index, PCLZIP_OPT_EXTRACT_AS_STRING );
        return is_array( $out ) && isset( $out[0]['content'] ) && is_string( $out[0]['content'] ) ? $out[0]['content'] : false;
    }

    /**
     * Filter zip entry names to scannable PHP files.
     *
     * @param string[] $names Raw entry names from the zip.
     * @return array{root:string,files:string[]}
     */
    public static function zip_files( array $names ) {
        $normal = array();
        $tops   = array();
        foreach ( $names as $name ) {
            $path     = str_replace( '\\', '/', (string) $name );
            $segments = explode( '/', $path );
            if ( '' === $path || '__MACOSX' === $segments[0] ) {
                continue;
            }
            $tops[ 1 < count( $segments ) ? $segments[0] : "\0" ] = true;
            $normal[ (string) $name ]                             = $path;
        }
        $root  = 1 === count( $tops ) && ! isset( $tops["\0"] ) ? (string) key( $tops ) . '/' : '';
        $files = array();

        foreach ( $normal as $name => $path ) {
            if ( '/' === substr( $path, -1 ) ) {
                continue;
            }
            $rel = '' !== $root && 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : $path;
            if ( self::skipped_rel_path( $rel ) ) {
                continue;
            }
            if ( in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), array( 'php', 'phtml', 'inc' ), true ) ) {
                $files[ (string) $name ] = $path;
            }
        }
        asort( $files, SORT_STRING );
        return array(
            'root'  => $root,
            'files' => array_map( 'strval', array_keys( $files ) ),
        );
    }

    /**
     * Whether a relative zip path sits in a skipped folder or bundled scan engine.
     *
     * @param string $rel Relative path inside the package.
     * @return bool
     */
    private static function skipped_rel_path( $rel ) {
        $rel   = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
        $lower = strtolower( $rel );
        foreach ( array( 'vendor/thrg-scan-engine/', 'vendor/brightlabs-scan-engine/', 'vendor/squizlabs/php_codesniffer/' ) as $bundle ) {
            if ( 0 === strpos( $lower, $bundle ) ) {
                return true;
            }
        }
        $folders = explode( '/', $rel );
        array_pop( $folders );
        foreach ( $folders as $folder ) {
            if ( THRG_PC_Context::skipped_dir( $folder ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Safely delete a temporary zip file created by download_url().
     *
     * @param mixed $zip File path.
     * @return void
     */
    public static function delete_zip( $zip ) {
        if ( ! is_string( $zip ) || '' === $zip || ! is_file( $zip ) || is_link( $zip ) ) {
            return;
        }
        $folder = realpath( dirname( $zip ) );
        $temp   = realpath( get_temp_dir() );
        if ( false !== $folder && false !== $temp && $folder === $temp ) {
            self::zip_close();
            wp_delete_file( $zip );
        }
    }

    /**
     * Download an offered update package and list its scannable files.
     *
     * @param array $offer Offer entry.
     * @return array|WP_Error
     */
    public static function open_offer( array $offer ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $file = download_url( $offer['package'], self::TIMEOUT );
        if ( is_wp_error( $file ) ) {
            return $file;
        }
        $handle = self::zip_open( $file );
        if ( is_wp_error( $handle ) ) {
            self::delete_zip( $file );
            return $handle;
        }
        $listed = self::zip_files( array_map( 'strval', array_keys( $handle['entries'] ) ) );
        return array(
            'kind'      => $offer['kind'],
            'slug'      => $offer['slug'],
            'version'   => $offer['new_version'],
            'target'    => self::guard_target(),
            'zip'       => $file,
            'root'      => $listed['root'],
            'remaining' => $listed['files'],
            'findings'  => array(),
        );
    }

    /**
     * Run the update pre-scan until $deadline.
     *
     * @param float $deadline microtime(true) cutoff.
     * @param bool  $force    True when triggered manually by an administrator.
     * @return array{done:bool,current:string}
     */
    public static function step( $deadline, $force = false ) {
        if ( ( ! $force && ! thrg_pc_enabled( 'prescan_enabled' ) ) || '' !== THRG_PC_Engine::unavailable_reason() ) {
            return array(
                'done'    => true,
                'current' => '',
            );
        }

        $state  = self::get_state();
        $tries  = isset( $state['tries'] ) && is_array( $state['tries'] ) ? $state['tries'] : array();
        $active = isset( $state['current'] ) && is_array( $state['current'] ) ? $state['current'] : null;
        $failed = array();
        $name   = '';

        $listed = array();
        foreach ( self::entries() as $offer ) {
            $listed[ self::offer_key( $offer['kind'], $offer['slug'], $offer['new_version'] ) ] = true;
        }
        $kept = array_intersect_key( $tries, $listed );
        if ( $kept !== $tries ) {
            $tries = $kept;
            self::set_state(
                array(
                    'current' => $active,
                    'tries'   => $tries,
                )
            );
        }

        while ( true ) {
            if ( null === $active ) {
                $next = null;
                foreach ( self::pending_offers() as $offer ) {
                    $key = self::offer_key( $offer['kind'], $offer['slug'], $offer['new_version'] );
                    if ( ! isset( $failed[ $key ] ) && (int) ( isset( $tries[ $key ] ) ? $tries[ $key ] : 0 ) < self::MAX_TRIES ) {
                        $next = $offer;
                        break;
                    }
                }
                if ( null === $next ) {
                    break;
                }
                $opened = self::open_offer( $next );
                if ( is_wp_error( $opened ) ) {
                    $key            = self::offer_key( $next['kind'], $next['slug'], $next['new_version'] );
                    $tries[ $key ]  = (int) ( isset( $tries[ $key ] ) ? $tries[ $key ] : 0 ) + 1;
                    $failed[ $key ] = true;
                    self::set_state( array( 'tries' => $tries ) );
                    if ( microtime( true ) >= $deadline ) {
                        break;
                    }
                    continue;
                }
                $active         = $opened;
                $key            = self::offer_key( $active['kind'], $active['slug'], $active['version'] );
                $tries[ $key ]  = (int) ( isset( $tries[ $key ] ) ? $tries[ $key ] : 0 ) + 1;
                $active['busy'] = false;
            }

            $name = (string) $active['slug'];
            $key  = self::offer_key( $active['kind'], $active['slug'], $active['version'] );
            $zip  = isset( $active['zip'] ) ? (string) $active['zip'] : '';
            if ( '' === $zip || ! is_file( $zip ) ) {
                $failed[ $key ] = true;
                $active         = null;
                self::set_state( array( 'tries' => $tries ) );
                continue;
            }
            if ( ! empty( $active['busy'] ) ) {
                $tries[ $key ] = (int) ( isset( $tries[ $key ] ) ? $tries[ $key ] : 0 ) + 1;
                if ( $tries[ $key ] >= self::MAX_TRIES ) {
                    self::delete_zip( $zip );
                    $failed[ $key ] = true;
                    $active         = null;
                    self::set_state( array( 'tries' => $tries ) );
                    continue;
                }
            }
            $active['busy'] = true;
            self::set_state(
                array(
                    'current' => $active,
                    'tries'   => $tries,
                )
            );

            $out  = THRG_PC_Engine::scan(
                $active['remaining'],
                $active['target'],
                (float) $deadline,
                static function ( $entry, $limit ) use ( $zip ) {
                    return THRG_PC_Prescan::zip_read( $zip, $entry, $limit );
                }
            );
            $root = (string) $active['root'];
            foreach ( $out['scanned'] as $entry => $findings ) {
                $path = str_replace( '\\', '/', (string) $entry );
                $rel  = '' !== $root && 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : $path;
                foreach ( $findings as $finding ) {
                    $active['findings'][] = THRG_PC_Context::place_finding( $finding, $rel );
                }
            }
            $active['remaining'] = $out['remaining'];
            $active['busy']      = false;

            if ( ! $active['remaining'] ) {
                if ( THRG_PC_Results::save_update( $active['kind'], $active['slug'], $active['version'], $active['target'], $active['findings'] ) ) {
                    unset( $tries[ $key ] );
                } else {
                    $failed[ $key ] = true;
                }
                self::delete_zip( $zip );
                $active = null;
            }
            self::set_state(
                array(
                    'current' => $active,
                    'tries'   => $tries,
                )
            );

            if ( microtime( true ) >= $deadline ) {
                break;
            }
        }
        self::zip_close();

        if ( null === $active ) {
            $pending = false;
            foreach ( self::pending_offers() as $offer ) {
                if ( ! isset( $failed[ self::offer_key( $offer['kind'], $offer['slug'], $offer['new_version'] ) ] ) ) {
                    $pending = true;
                    break;
                }
            }
            return array(
                'done'    => ! $pending,
                'current' => '',
            );
        }
        return array(
            'done'    => false,
            'current' => $name,
        );
    }

    /**
     * Schedule the hourly update pre-scan event if not already scheduled.
     *
     * @return void
     */
    public static function schedule() {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::HOOK );
        }
    }

    /**
     * Stop the hourly pre-scan and delete any temporary zip from an active run.
     *
     * @return void
     */
    public static function stop() {
        wp_clear_scheduled_hook( self::HOOK );
        $state = self::get_state();
        if ( isset( $state['current'] ) ) {
            if ( is_array( $state['current'] ) && isset( $state['current']['zip'] ) ) {
                self::delete_zip( $state['current']['zip'] );
            }
            unset( $state['current'] );
            self::set_state( $state );
        }
    }

    /**
     * Synchronize the hourly cron schedule with the prescan_enabled setting.
     *
     * @return void
     */
    public static function sync() {
        if ( thrg_pc_enabled( 'prescan_enabled' ) ) {
            self::schedule();
        } else {
            self::stop();
        }
    }

    /**
     * Hourly cron callback: pre-scan offered updates for up to 20 seconds.
     *
     * @return void
     */
    public static function cron() {
        if ( ! thrg_pc_enabled( 'prescan_enabled' ) || '' !== THRG_PC_Engine::unavailable_reason() ) {
            return;
        }
        if ( ! self::pending_offers() ) {
            return;
        }
        self::step( microtime( true ) + 20 );
    }
}

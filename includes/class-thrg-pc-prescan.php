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

    const STATE_OPTION           = 'thrg_pc_prescan_state';
    const HOOK                   = 'thrg_pc_prescan_updates';
    const MAX_TRIES              = 3;
    const TIMEOUT                = 300;
    const MAX_ARCHIVE_BYTES      = 268435456;
    const MAX_ARCHIVE_ENTRIES    = 20000;
    const MAX_UNCOMPRESSED_BYTES = 1073741824;

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
        $archive_bytes = filesize( $zip );
        if ( false === $archive_bytes || $archive_bytes > self::MAX_ARCHIVE_BYTES ) {
            return $error;
        }
        // Respect WordPress core's unzip_file_use_ziparchive filter.
        $use_ziparchive = class_exists( 'ZipArchive', false ) && apply_filters( 'unzip_file_use_ziparchive', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        if ( $use_ziparchive ) {
            $archive = new ZipArchive();
            if ( true !== $archive->open( $zip, ZipArchive::CHECKCONS ) ) {
                return $error;
            }
            if ( $archive->numFiles > self::MAX_ARCHIVE_ENTRIES ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                $archive->close();
                return $error;
            }
            $total_bytes = 0;
            for ( $i = 0; $i < $archive->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                $stat = $archive->statIndex( $i );
                if ( is_array( $stat ) ) {
                    $total_bytes += (int) $stat['size'];
                    if ( $total_bytes > self::MAX_UNCOMPRESSED_BYTES ) {
                        $archive->close();
                        return $error;
                    }
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
            if ( count( $list ) > self::MAX_ARCHIVE_ENTRIES ) {
                return $error;
            }
            $total_bytes = 0;
            foreach ( $list as $entry ) {
                $total_bytes += (int) $entry['size'];
                if ( $total_bytes > self::MAX_UNCOMPRESSED_BYTES ) {
                    return $error;
                }
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
     * @param array  $offer  Offer entry.
     * @param string $target Optional explicit target PHP version; defaults to guard_target().
     * @return array|WP_Error
     */
    public static function open_offer( array $offer, $target = '' ) {
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
        $listed       = self::zip_files( array_map( 'strval', array_keys( $handle['entries'] ) ) );
        $resolved_tgt = '' !== $target && in_array( (string) $target, THRG_PHP_Checker::scan_targets(), true )
            ? (string) $target
            : self::guard_target();
        return array(
            'kind'      => $offer['kind'],
            'slug'      => $offer['slug'],
            'version'   => $offer['new_version'],
            'target'    => $resolved_tgt,
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
        if ( ( ! $force && empty( THRG_PHP_Checker::settings()['prescan_updates'] ) ) || '' !== THRG_PC_Engine::unavailable_reason() ) {
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
     * Alias for stop() used by settings handler.
     *
     * @return void
     */
    public static function unschedule() {
        self::stop();
    }

    /**
     * Clear all stored pre-scan state and delete any active temporary zip.
     *
     * @return void
     */
    public static function clear_state() {
        $state = self::get_state();
        if ( isset( $state['current'] ) && is_array( $state['current'] ) && isset( $state['current']['zip'] ) ) {
            self::delete_zip( $state['current']['zip'] );
        }
        delete_option( self::STATE_OPTION );
    }

    /**
     * Run a manual time-boxed pre-scan slice and return a summary.
     *
     * @param float $seconds Duration in seconds.
     * @return array{done:bool,current:string,pending:int}
     */
    public static function run_slice( $seconds = 8.0 ) {
        $out = self::step( microtime( true ) + max( 1.0, (float) $seconds ), true );
        return array(
            'done'    => (bool) $out['done'],
            'current' => (string) $out['current'],
            'pending' => count( self::pending_offers() ),
        );
    }

    /**
     * Check whether a failing plugin from WordPress.org has a newer release and whether it fixes issues on $target.
     *
     * @param string $slug              Installed plugin slug ("folder/file.php" or "folder").
     * @param string $installed_version Installed plugin version.
     * @param string $target            Target PHP version ("8.3", "8.4", etc.).
     * @param float  $deadline          Optional microtime(true) cutoff; defaults to +20 seconds.
     * @return array{status:string,version:string,verdict:string,errors:int,warnings:int,message:string}
     */
    public static function check_wporg_fix( $slug, $installed_version, $target, $deadline = 0.0 ) {
        $folder = dirname( (string) $slug );
        $short  = '.' === $folder ? basename( (string) $slug, '.php' ) : $folder;
        $short  = sanitize_key( $short );
        $target = in_array( (string) $target, THRG_PHP_Checker::scan_targets(), true ) ? (string) $target : THRG_PHP_Checker::default_target();
        if ( $deadline <= 0.0 ) {
            $deadline = microtime( true ) + 20.0;
        }

        if ( '' === $short ) {
            return array(
                'status'   => 'error',
                'version'  => '',
                'verdict'  => '',
                'errors'   => 0,
                'warnings' => 0,
                'message'  => __( 'Invalid plugin slug.', 'thrg-php-checker' ),
            );
        }

        $remote_version = '';
        $package_url    = '';

        // 1. Check WordPress update_plugins transient first.
        foreach ( self::entries() as $offer ) {
            if ( 'plugin' === $offer['kind'] && ( $offer['slug'] === $short || $offer['file_key'] === $slug ) ) {
                $remote_version = (string) $offer['new_version'];
                $package_url    = (string) $offer['package'];
                break;
            }
        }

        // 2. Fall back to WordPress.org Plugin Information API (cached for 12 hours).
        if ( '' === $remote_version && function_exists( 'wp_remote_get' ) ) {
            $cache_key = 'thrg_pc_wporg_' . substr( md5( $short ), 0, 12 );
            $cached    = get_transient( $cache_key );
            if ( is_array( $cached ) && isset( $cached['status'] ) ) {
                if ( 'not_found' === $cached['status'] ) {
                    return array(
                        'status'   => 'not_wporg',
                        'version'  => '',
                        'verdict'  => '',
                        'errors'   => 0,
                        'warnings' => 0,
                        'message'  => __( 'This plugin is not listed in the public WordPress.org directory.', 'thrg-php-checker' ),
                    );
                }
                $remote_version = isset( $cached['version'] ) ? (string) $cached['version'] : '';
                $package_url    = isset( $cached['package'] ) ? (string) $cached['package'] : '';
            } else {
                $api_url  = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=' . rawurlencode( $short );
                $response = wp_remote_get( $api_url, array( 'timeout' => 10 ) );
                if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
                    $body = json_decode( wp_remote_retrieve_body( $response ), true );
                    if ( is_array( $body ) && ! empty( $body['version'] ) && ! empty( $body['download_link'] ) ) {
                        $remote_version = (string) $body['version'];
                        $package_url    = (string) $body['download_link'];
                        set_transient(
                            $cache_key,
                            array(
                                'status'  => 'ok',
                                'version' => $remote_version,
                                'package' => $package_url,
                            ),
                            12 * HOUR_IN_SECONDS
                        );
                    } else {
                        set_transient( $cache_key, array( 'status' => 'not_found' ), 12 * HOUR_IN_SECONDS );
                    }
                }
            }
        }

        if ( '' === $remote_version ) {
            return array(
                'status'   => 'not_wporg',
                'version'  => '',
                'verdict'  => '',
                'errors'   => 0,
                'warnings' => 0,
                'message'  => __( 'No public WordPress.org release information found for this plugin.', 'thrg-php-checker' ),
            );
        }

        if ( '' !== $installed_version && version_compare( $remote_version, (string) $installed_version, '<=' ) ) {
            return array(
                'status'   => 'latest_installed',
                'version'  => $remote_version,
                'verdict'  => '',
                'errors'   => 0,
                'warnings' => 0,
                'message'  => sprintf(
                    /* translators: %s: latest version on WordPress.org */
                    __( 'You already have the latest WordPress.org release (v%s) installed.', 'thrg-php-checker' ),
                    $remote_version
                ),
            );
        }

        // Check if we already scanned this newer version against $target.
        $existing = THRG_PC_Results::get_update( 'plugin', $short, $remote_version, $target );
        if ( null === $existing && '' !== $package_url ) {
            $host = (string) wp_parse_url( $package_url, PHP_URL_HOST );
            if ( 0 === strpos( $package_url, 'https://' ) && ( 'downloads.wordpress.org' === $host || '' !== $host ) ) {
                $opened = self::open_offer(
                    array(
                        'kind'        => 'plugin',
                        'slug'        => $short,
                        'new_version' => $remote_version,
                        'package'     => $package_url,
                    ),
                    $target
                );
                if ( ! is_wp_error( $opened ) ) {
                    $zip      = (string) $opened['zip'];
                    $root     = (string) $opened['root'];
                    $out      = THRG_PC_Engine::scan(
                        $opened['remaining'],
                        $target,
                        (float) $deadline,
                        static function ( $entry, $limit ) use ( $zip ) {
                            return THRG_PC_Prescan::zip_read( $zip, $entry, $limit );
                        }
                    );
                    $findings = array();
                    foreach ( $out['scanned'] as $entry => $file_findings ) {
                        $path = str_replace( '\\', '/', (string) $entry );
                        $rel  = '' !== $root && 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : $path;
                        foreach ( $file_findings as $finding ) {
                            $findings[] = THRG_PC_Context::place_finding( $finding, $rel );
                        }
                    }
                    self::delete_zip( $zip );
                    self::zip_close();
                    if ( empty( $out['remaining'] ) ) {
                        THRG_PC_Results::save_update( 'plugin', $short, $remote_version, $target, $findings );
                        $existing = THRG_PC_Results::get_update( 'plugin', $short, $remote_version, $target );
                    }
                }
            }
        }

        if ( null === $existing ) {
            return array(
                'status'   => 'pending',
                'version'  => $remote_version,
                'verdict'  => '',
                'errors'   => 0,
                'warnings' => 0,
                'message'  => sprintf(
                    /* translators: %s: newer version number */
                    __( 'Newer version v%s is available on WordPress.org, but its package could not be completely scanned right now.', 'thrg-php-checker' ),
                    $remote_version
                ),
            );
        }

        $verdict  = (string) $existing['verdict'];
        $errors   = (int) $existing['errors'];
        $warnings = (int) $existing['warnings'];

        if ( in_array( $verdict, array( 'ok', 'clean' ), true ) ) {
            $status = 'fixed';
            $msg    = sprintf(
                /* translators: 1: newer version, 2: target PHP version */
                __( 'Fixed in v%1$s on WordPress.org (0 errors, 0 warnings on PHP %2$s). Update this plugin to resolve its compatibility issues.', 'thrg-php-checker' ),
                $remote_version,
                $target
            );
        } elseif ( in_array( $verdict, array( 'warnings', 'works' ), true ) ) {
            $status = 'warnings_only';
            $msg    = sprintf(
                /* translators: 1: newer version, 2: target PHP version, 3: warning count */
                __( 'v%1$s on WordPress.org has 0 fatal errors on PHP %2$s (%3$d deprecation warning(s) remain).', 'thrg-php-checker' ),
                $remote_version,
                $target,
                $warnings
            );
        } else {
            $status = 'still_fails';
            $msg    = sprintf(
                /* translators: 1: newer version, 2: target PHP version, 3: error count */
                __( 'Still fails in latest v%1$s on WordPress.org (%3$d fatal error(s) on PHP %2$s). Do not rely on updating alone.', 'thrg-php-checker' ),
                $remote_version,
                $target,
                $errors
            );
        }

        return array(
            'status'   => $status,
            'version'  => $remote_version,
            'verdict'  => $verdict,
            'errors'   => $errors,
            'warnings' => $warnings,
            'message'  => $msg,
        );
    }

    /**
     * Synchronize the hourly cron schedule with the prescan_enabled setting.
     *
     * @return void
     */
    public static function sync() {
        if ( ! empty( THRG_PHP_Checker::settings()['prescan_updates'] ) ) {
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
        if ( empty( THRG_PHP_Checker::settings()['prescan_updates'] ) || '' !== THRG_PC_Engine::unavailable_reason() ) {
            return;
        }
        if ( ! self::pending_offers() ) {
            return;
        }
        self::step( microtime( true ) + 20 );
    }
}

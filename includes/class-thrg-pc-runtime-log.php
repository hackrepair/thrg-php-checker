<?php
/**
 * Runtime PHP deprecation notice collector and debug.log parser, grouped by plugin or theme.
 *
 * Opt-in live collector chains to any existing PHP error handler for E_DEPRECATED and
 * E_USER_DEPRECATED without suppressing native logging, attributes notices (including
 * WordPress _deprecated_*() calls via backtrace) to the offending plugin or theme, and
 * can also import existing PHP Deprecated lines from wp-content/debug.log.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runtime PHP deprecation notice logger and debug.log importer.
 */
final class THRG_PC_Runtime_Log {

    const OPTION      = 'thrg_pc_runtime_deprecations';
    const MAX_ENTRIES = 100;
    const MAX_BYTES   = 131072;
    const TAIL_BYTES  = 262144;

    /**
     * In-request buffer of captured deprecation notices flushed on shutdown.
     *
     * @var array<string,array>
     */
    private static $buffer = array();

    /**
     * Previous PHP error handler if chained.
     *
     * @var callable|null
     */
    private static $prev_handler = null;

    /**
     * Whether the live error handler has been registered in this request.
     *
     * @var bool
     */
    private static $registered = false;

    /**
     * Boot admin actions and (when enabled) the live deprecation error handler.
     *
     * @return void
     */
    public static function boot() {
        add_action( 'admin_post_thrg_pc_runtime_log', array( __CLASS__, 'handle_post' ) );

        $settings = THRG_PHP_Checker::settings();
        if ( ! empty( $settings['runtime_log'] ) ) {
            self::register_handler();
        }
    }

    /**
     * Register the non-intrusive E_DEPRECATED | E_USER_DEPRECATED error handler.
     *
     * @return void
     */
    public static function register_handler() {
        if ( self::$registered ) {
            return;
        }
        self::$registered   = true;
        self::$prev_handler = set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
            array( __CLASS__, 'handle_error' ),
            E_DEPRECATED | E_USER_DEPRECATED
        );
        add_action( 'shutdown', array( __CLASS__, 'flush_buffer' ), 999 );
    }

    /**
     * Error handler callback for E_DEPRECATED and E_USER_DEPRECATED.
     *
     * Always chains to any previous handler and returns false so native PHP
     * logging is never suppressed.
     *
     * @param int    $errno   Error level.
     * @param string $errstr  Error message.
     * @param string $errfile File path where notice was triggered.
     * @param int    $errline Line number.
     * @return bool
     */
    public static function handle_error( $errno, $errstr, $errfile = '', $errline = 0 ) {
        if ( ( E_DEPRECATED | E_USER_DEPRECATED ) & $errno ) {
            $file = (string) $errfile;
            $line = (int) $errline;
            $attr = self::attribute_path( $file );

            // If triggered inside WordPress core (e.g., _deprecated_function), inspect backtrace for caller plugin/theme.
            if ( 'core' === $attr['kind'] && function_exists( 'debug_backtrace' ) ) {
                $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
                foreach ( $trace as $frame ) {
                    if ( empty( $frame['file'] ) ) {
                        continue;
                    }
                    $candidate = self::attribute_path( (string) $frame['file'] );
                    if ( in_array( $candidate['kind'], array( 'plugin', 'mu-plugin', 'theme' ), true ) ) {
                        $attr = $candidate;
                        $file = (string) $frame['file'];
                        $line = isset( $frame['line'] ) ? (int) $frame['line'] : 0;
                        break;
                    }
                }
            }

            if ( in_array( $attr['kind'], array( 'plugin', 'mu-plugin', 'theme' ), true ) && 'thrg-php-checker' !== $attr['slug'] ) {
                self::record_in_buffer(
                    $attr['kind'],
                    $attr['slug'],
                    $attr['rel_file'],
                    $line,
                    (string) $errstr,
                    THRG_PHP_Checker::running_php(),
                    time()
                );
            }
        }

        if ( is_callable( self::$prev_handler ) ) {
            return (bool) call_user_func( self::$prev_handler, $errno, $errstr, $errfile, $errline );
        }
        return false;
    }

    /**
     * Attribute an absolute file path to a plugin, mu-plugin, theme, or core.
     *
     * @param string $abs_path Absolute path from PHP error or debug.log.
     * @return array{kind:string,slug:string,rel_file:string}
     */
    public static function attribute_path( $abs_path ) {
        $norm = str_replace( '\\', '/', (string) $abs_path );

        $plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? rtrim( str_replace( '\\', '/', WP_PLUGIN_DIR ), '/' ) . '/' : '';
        if ( '' !== $plugin_dir && 0 === strpos( $norm, $plugin_dir ) ) {
            $rel = substr( $norm, strlen( $plugin_dir ) );
            return self::split_component_rel( 'plugin', $rel );
        }
        if ( preg_match( '#/wp-content/plugins/(.+)$#', $norm, $m ) ) {
            return self::split_component_rel( 'plugin', $m[1] );
        }

        $mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? rtrim( str_replace( '\\', '/', WPMU_PLUGIN_DIR ), '/' ) . '/' : '';
        if ( '' !== $mu_dir && 0 === strpos( $norm, $mu_dir ) ) {
            $rel = substr( $norm, strlen( $mu_dir ) );
            return self::split_component_rel( 'mu-plugin', $rel );
        }
        if ( preg_match( '#/wp-content/mu-plugins/(.+)$#', $norm, $m ) ) {
            return self::split_component_rel( 'mu-plugin', $m[1] );
        }

        if ( preg_match( '#/wp-content/themes/(.+)$#', $norm, $m ) ) {
            return self::split_component_rel( 'theme', $m[1] );
        }

        return array(
            'kind'     => 'core',
            'slug'     => 'wordpress',
            'rel_file' => ltrim( $norm, '/' ),
        );
    }

    /**
     * Split a path relative to plugins/, mu-plugins/, or themes/ into slug and relative file.
     *
     * @param string $kind Component kind.
     * @param string $rel  Relative path under component root.
     * @return array{kind:string,slug:string,rel_file:string}
     */
    private static function split_component_rel( $kind, $rel ) {
        $rel   = ltrim( (string) $rel, '/' );
        $parts = explode( '/', $rel, 2 );
        if ( 1 === count( $parts ) ) {
            $slug = 'plugin' === $kind ? basename( $parts[0], '.php' ) : $parts[0];
            return array(
                'kind'     => $kind,
                'slug'     => $slug,
                'rel_file' => $parts[0],
            );
        }
        return array(
            'kind'     => $kind,
            'slug'     => $parts[0],
            'rel_file' => $parts[1],
        );
    }

    /**
     * Add or increment a deprecation notice inside the in-memory request buffer.
     *
     * @param string $kind      'plugin', 'mu-plugin', or 'theme'.
     * @param string $slug      Component folder/slug.
     * @param string $rel_file  Relative file inside component.
     * @param int    $line      Line number.
     * @param string $message   Deprecation message.
     * @param string $php_ver   PHP branch ("8.2", "8.3", etc.).
     * @param int    $timestamp Unix timestamp.
     * @return void
     */
    public static function record_in_buffer( $kind, $slug, $rel_file, $line, $message, $php_ver = '', $timestamp = 0 ) {
        $clean_msg = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $message ) ) );
        if ( '' === $clean_msg ) {
            return;
        }
        if ( strlen( $clean_msg ) > 320 ) {
            $clean_msg = substr( $clean_msg, 0, 317 ) . '...';
        }
        $ts  = $timestamp > 0 ? (int) $timestamp : time();
        $php = '' !== $php_ver ? (string) $php_ver : THRG_PHP_Checker::running_php();
        $sig = substr( sha1( $kind . '|' . $slug . '|' . $rel_file . '|' . (int) $line . '|' . $clean_msg ), 0, 16 );

        if ( isset( self::$buffer[ $sig ] ) ) {
            ++self::$buffer[ $sig ]['count'];
            if ( $ts > self::$buffer[ $sig ]['last_seen'] ) {
                self::$buffer[ $sig ]['last_seen'] = $ts;
            }
            return;
        }

        self::$buffer[ $sig ] = array(
            'kind'      => (string) $kind,
            'slug'      => (string) $slug,
            'file'      => (string) $rel_file,
            'line'      => max( 0, (int) $line ),
            'message'   => $clean_msg,
            'php'       => $php,
            'count'     => 1,
            'last_seen' => $ts,
        );
    }

    /**
     * Flush buffered deprecation notices into the stored option.
     *
     * @return void
     */
    public static function flush_buffer() {
        if ( empty( self::$buffer ) ) {
            return;
        }
        $entries = self::all();
        foreach ( self::$buffer as $sig => $item ) {
            if ( isset( $entries[ $sig ] ) && is_array( $entries[ $sig ] ) ) {
                $entries[ $sig ]['count']     = (int) $entries[ $sig ]['count'] + (int) $item['count'];
                $entries[ $sig ]['last_seen'] = max( (int) $entries[ $sig ]['last_seen'], (int) $item['last_seen'] );
                $entries[ $sig ]['php']       = $item['php'];
            } else {
                $entries[ $sig ] = $item;
            }
        }
        self::$buffer = array();
        self::save( $entries );
    }

    /**
     * Return all stored runtime deprecation entries keyed by signature.
     *
     * @return array<string,array>
     */
    public static function all() {
        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) ) {
            return array();
        }
        $out = array();
        foreach ( $raw as $sig => $row ) {
            if ( ! is_array( $row ) || empty( $row['slug'] ) || empty( $row['message'] ) ) {
                continue;
            }
            $out[ (string) $sig ] = array(
                'kind'      => isset( $row['kind'] ) ? (string) $row['kind'] : 'plugin',
                'slug'      => (string) $row['slug'],
                'file'      => isset( $row['file'] ) ? (string) $row['file'] : '',
                'line'      => isset( $row['line'] ) ? max( 0, (int) $row['line'] ) : 0,
                'message'   => (string) $row['message'],
                'php'       => isset( $row['php'] ) ? (string) $row['php'] : '',
                'count'     => isset( $row['count'] ) ? max( 1, (int) $row['count'] ) : 1,
                'last_seen' => isset( $row['last_seen'] ) ? (int) $row['last_seen'] : 0,
            );
        }
        return $out;
    }

    /**
     * Persist bounded deprecation entries to the non-autoloaded option.
     *
     * @param array<string,array> $entries Entries keyed by signature.
     * @return void
     */
    public static function save( array $entries ) {
        uasort(
            $entries,
            static function ( $a, $b ) {
                $ta = isset( $a['last_seen'] ) ? (int) $a['last_seen'] : 0;
                $tb = isset( $b['last_seen'] ) ? (int) $b['last_seen'] : 0;
                return $tb <=> $ta;
            }
        );

        if ( count( $entries ) > self::MAX_ENTRIES ) {
            $entries = array_slice( $entries, 0, self::MAX_ENTRIES, true );
        }

        $entry_count = count( $entries );
        $serialized  = serialize( $entries ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
        $byte_len    = strlen( $serialized );
        while ( $entry_count > 1 && $byte_len > self::MAX_BYTES ) {
            array_pop( $entries );
            --$entry_count;
            $serialized = serialize( $entries ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
            $byte_len   = strlen( $serialized );
        }

        update_option( self::OPTION, $entries, false );
    }

    /**
     * Clear all stored runtime deprecation entries.
     *
     * @return void
     */
    public static function clear() {
        self::$buffer = array();
        delete_option( self::OPTION );
    }

    /**
     * Parse PHP Deprecated lines from wp-content/debug.log (or a custom log file) and merge them.
     *
     * Reads at most the last TAIL_BYTES (256 KB) so large debug logs never exhaust memory.
     *
     * @param string $log_path Optional explicit log path; defaults to WP_CONTENT_DIR . '/debug.log'.
     * @return int Number of deprecation lines matched and imported.
     */
    public static function import_debug_log( $log_path = '' ) {
        if ( '' === $log_path ) {
            $log_path = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/debug.log' : ABSPATH . 'wp-content/debug.log';
        }
        if ( ! is_string( $log_path ) || '' === $log_path || ! is_file( $log_path ) || ! is_readable( $log_path ) || is_link( $log_path ) ) {
            return 0;
        }

        $size = (int) filesize( $log_path );
        if ( $size <= 0 ) {
            return 0;
        }

        $fp = @fopen( $log_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! is_resource( $fp ) ) {
            return 0;
        }

        if ( $size > self::TAIL_BYTES ) {
            fseek( $fp, $size - self::TAIL_BYTES );
            fgets( $fp ); // Discard partial first line.
        }

        $imported = 0;
        $php_ver  = THRG_PHP_Checker::running_php();

        while ( ! feof( $fp ) ) {
            $line = fgets( $fp, 4096 );
            if ( ! is_string( $line ) || false === stripos( $line, 'Deprecated:' ) ) {
                continue;
            }
            $parsed = self::parse_log_line( $line );
            if ( null === $parsed ) {
                continue;
            }
            $attr = self::attribute_path( $parsed['file'] );
            if ( ! in_array( $attr['kind'], array( 'plugin', 'mu-plugin', 'theme' ), true ) || 'thrg-php-checker' === $attr['slug'] ) {
                continue;
            }
            self::record_in_buffer(
                $attr['kind'],
                $attr['slug'],
                $attr['rel_file'],
                $parsed['line'],
                $parsed['message'],
                $php_ver,
                $parsed['time']
            );
            ++$imported;
        }
        fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        if ( $imported > 0 ) {
            self::flush_buffer();
        }
        return $imported;
    }

    /**
     * Parse a single PHP error_log line containing a PHP Deprecated notice.
     *
     * @param string $line Raw log line.
     * @return array{time:int,message:string,file:string,line:int}|null
     */
    public static function parse_log_line( $line ) {
        $line = trim( (string) $line );
        if ( ! preg_match( '/^(?:\[([^\]]+)\]\s+)?PHP\s+(?:User\s+)?Deprecated:\s+(.+?)\s+in\s+(.+?)\s+on\s+line\s+(\d+)\s*$/i', $line, $m ) ) {
            return null;
        }
        $ts = 0;
        if ( ! empty( $m[1] ) ) {
            $parsed_ts = strtotime( $m[1] );
            if ( false !== $parsed_ts ) {
                $ts = (int) $parsed_ts;
            }
        }
        return array(
            'time'    => $ts > 0 ? $ts : time(),
            'message' => trim( $m[2] ),
            'file'    => trim( $m[3] ),
            'line'    => (int) $m[4],
        );
    }

    /**
     * Group stored runtime deprecation notices by plugin or theme.
     *
     * @param array[] $components Optional output of THRG_PC_Scanner::components().
     * @return array[] Array of component groups sorted by notice count descending.
     */
    public static function grouped( array $components = array() ) {
        $entries = self::all();
        if ( empty( $entries ) ) {
            return array();
        }

        $comp_map = array();
        foreach ( $components as $c ) {
            if ( empty( $c['kind'] ) || empty( $c['slug'] ) ) {
                continue;
            }
            $folder                                    = dirname( (string) $c['slug'] );
            $short                                     = '.' === $folder ? basename( (string) $c['slug'], '.php' ) : $folder;
            $comp_map[ $c['kind'] . ':' . $short ]     = $c;
            $comp_map[ $c['kind'] . ':' . $c['slug'] ] = $c;
        }

        $groups = array();
        foreach ( $entries as $item ) {
            $gkey = $item['kind'] . ':' . $item['slug'];
            if ( ! isset( $groups[ $gkey ] ) ) {
                $comp            = isset( $comp_map[ $gkey ] ) ? $comp_map[ $gkey ] : null;
                $groups[ $gkey ] = array(
                    'kind'       => $item['kind'],
                    'slug'       => $item['slug'],
                    'name'       => $comp && ! empty( $comp['name'] ) ? (string) $comp['name'] : (string) $item['slug'],
                    'version'    => $comp && ! empty( $comp['version'] ) ? (string) $comp['version'] : '',
                    'active'     => $comp ? ! empty( $comp['active'] ) : true,
                    'total_hits' => 0,
                    'last_seen'  => 0,
                    'items'      => array(),
                );
            }
            $groups[ $gkey ]['total_hits'] += (int) $item['count'];
            $groups[ $gkey ]['last_seen']   = max( $groups[ $gkey ]['last_seen'], (int) $item['last_seen'] );
            $groups[ $gkey ]['items'][]     = $item;
        }

        uasort(
            $groups,
            static function ( $a, $b ) {
                if ( count( $b['items'] ) !== count( $a['items'] ) ) {
                    return count( $b['items'] ) <=> count( $a['items'] );
                }
                return $b['total_hits'] <=> $a['total_hits'];
            }
        );

        return array_values( $groups );
    }

    /**
     * Handle POST actions from the Runtime Deprecation Log panel.
     *
     * @return void
     */
    public static function handle_post() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'thrg-php-checker' ), 403 );
        }
        check_admin_referer( 'thrg_pc_runtime_log' );

        $target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : THRG_PHP_Checker::default_target();
        if ( ! in_array( $target, THRG_PHP_Checker::scan_targets(), true ) ) {
            $target = THRG_PHP_Checker::default_target();
        }

        $subaction = isset( $_POST['subaction'] ) ? sanitize_key( wp_unslash( $_POST['subaction'] ) ) : 'toggle';
        $flag      = 'saved';

        if ( 'clear' === $subaction ) {
            self::clear();
            $flag = 'cleared';
        } elseif ( 'import' === $subaction ) {
            $count = self::import_debug_log();
            $flag  = $count > 0 ? 'imported' : 'empty-import';
        } else {
            $settings                = THRG_PHP_Checker::settings();
            $settings['runtime_log'] = empty( $settings['runtime_log'] ) ? 1 : 0;
            update_option( THRG_PHP_Checker::SETTINGS_OPTION, $settings, false );
            $flag = $settings['runtime_log'] ? 'enabled' : 'disabled';
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'            => THRG_PHP_Checker::SLUG,
                    'target'          => $target,
                    'thrg_pc_runtime' => $flag,
                ),
                admin_url( 'tools.php' )
            ) . '#pc-runtime-log'
        );
        exit;
    }
}

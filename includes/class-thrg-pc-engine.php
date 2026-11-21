<?php
/**
 * Bundled PHP_CodeSniffer 4 + PHPCompatibilityWP scan engine.
 *
 * Runs entirely inside the current PHP request without exec() or shell access.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-thrg-pc-context.php';

/**
 * In-process PHPCompatibilityWP static analysis runner.
 */
final class THRG_PC_Engine {

    /** Maximum file size scanned (2 MB). */
    const MAX_BYTES = 2097152;

    /**
     * Path to the bundled scan engine directory.
     *
     * @return string
     */
    public static function lib_dir() {
        return dirname( __DIR__ ) . '/vendor/thrg-scan-engine';
    }

    /**
     * Why the scan engine cannot run on this server, or '' when ready.
     *
     * @return string
     */
    public static function unavailable_reason() {
        foreach ( array( 'tokenizer', 'simplexml', 'xmlwriter' ) as $ext ) {
            if ( ! extension_loaded( $ext ) ) {
                /* translators: %s: PHP extension name. */
                return sprintf( __( 'The PHP extension "%s" is not enabled on this server. Ask your host to enable it.', 'thrg-php-checker' ), $ext );
            }
        }
        if ( ! is_file( self::lib_dir() . '/php-codesniffer/autoload.php' ) ) {
            return __( 'The bundled scan engine files are missing. Reinstall The Hack Repair Guy\'s PHP Checker.', 'thrg-php-checker' );
        }
        return '';
    }

    /**
     * Whether a directory path inside a component is a bundled PHPCS library that should not be scanned.
     *
     * @param string $pathname Full directory path.
     * @param string $root_dir Component root directory.
     * @return bool
     */
    public static function skipped_bundle_dir( $pathname, $root_dir ) {
        $norm = rtrim( str_replace( '\\', '/', (string) $pathname ), '/' );
        $root = rtrim( str_replace( '\\', '/', (string) $root_dir ), '/' );
        $rel  = 0 === strpos( $norm, $root . '/' ) ? substr( $norm, strlen( $root ) + 1 ) : '';
        if ( '' === $rel ) {
            return false;
        }
        $lower = strtolower( $rel );
        if ( in_array( $lower, array( 'vendor/thrg-scan-engine', 'vendor/brightlabs-scan-engine', 'vendor/squizlabs/php_codesniffer' ), true ) ) {
            return true;
        }
        return false;
    }

    /**
     * Discover all scannable PHP files (.php, .phtml, .inc) under $dir as sorted relative paths.
     *
     * @param string $dir Component directory.
     * @return string[] Relative paths with forward slashes.
     */
    public static function files( $dir ) {
        $out = array();
        $dir = (string) $dir;
        if ( ! is_dir( $dir ) ) {
            return $out;
        }
        $root = rtrim( str_replace( '\\', '/', $dir ), '/' );
        $base = strlen( $root ) + 1;
        $it   = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
                static function ( $file ) use ( $root ) {
                    if ( $file->isLink() ) {
                        return false;
                    }
                    if ( ! $file->isDir() ) {
                        return true;
                    }
                    if ( THRG_PC_Context::skipped_dir( $file->getFilename() ) ) {
                        return false;
                    }
                    return ! THRG_PC_Engine::skipped_bundle_dir( $file->getPathname(), $root );
                }
            )
        );
        foreach ( $it as $file ) {
            if ( $file->isFile() && ! $file->isLink() && in_array( strtolower( $file->getExtension() ), array( 'php', 'phtml', 'inc' ), true ) ) {
                $out[] = substr( str_replace( '\\', '/', $file->getPathname() ), $base );
            }
        }
        sort( $out, SORT_STRING );
        return $out;
    }

    /**
     * Load PHP_CodeSniffer once per request and return the Ruleset and Config for $test_version.
     *
     * @param string $test_version Target PHP version ("8.4" style).
     * @return array{0:\PHP_CodeSniffer\Ruleset,1:\PHP_CodeSniffer\Config}
     */
    public static function ruleset( $test_version ) {
        static $loaded = array();
        $test_version  = (string) $test_version;

        // PHPCS requires these exact constant names at runtime.
        if ( ! defined( 'PHP_CODESNIFFER_CBF' ) ) {
            define( 'PHP_CODESNIFFER_CBF', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
        }
        if ( ! defined( 'PHP_CODESNIFFER_VERBOSITY' ) ) {
            define( 'PHP_CODESNIFFER_VERBOSITY', 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
        }
        $lib = self::lib_dir();
        if ( ! class_exists( '\PHP_CodeSniffer\Config', false ) ) {
            require_once $lib . '/php-codesniffer/autoload.php';
            require_once $lib . '/php-codesniffer/src/Util/Tokens.php';
            require_once $lib . '/phpcsutils/phpcsutils-autoload.php';
        }
        if ( ! isset( $loaded[ $test_version ] ) ) {
            // Source annotations must not suppress an independent compatibility scan.
            $config = new \PHP_CodeSniffer\Config( array( '--no-cache', '--ignore-annotations' ), false );
            $config->setConfigData( 'installed_paths', "$lib/phpcompatibility,$lib/phpcompatibility-paragonie,$lib/phpcompatibility-wp,$lib/phpcsutils", true );
            $config->standards = array( 'PHPCompatibilityWP' );
            $config->setConfigData( 'testVersion', $test_version, true );
            $loaded[ $test_version ] = array( new \PHP_CodeSniffer\Ruleset( $config ), $config );
        }
        $loaded[ $test_version ][1]->setConfigData( 'testVersion', $test_version, true );
        if ( class_exists( '\PHPCompatibility\Helpers\ScannedCode' ) ) {
            $reset = static function () {
                // phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Upstream PHPCompatibility property names.
                self::$testVersion  = null;
                self::$testVersions = null;
                // phpcs:enable
            };
            \Closure::bind( $reset, null, '\PHPCompatibility\Helpers\ScannedCode' )();
        }
        return $loaded[ $test_version ];
    }

    /**
     * Scan a batch of files against $test_version until $deadline. Always scans at least one file.
     *
     * @param string[]      $paths        Absolute file paths or archive entry names.
     * @param string        $test_version Target PHP version ("8.4" style).
     * @param float         $deadline     microtime(true) cutoff.
     * @param callable|null $read         Optional custom reader for in-memory zip entries.
     * @return array{scanned:array<string,array>,remaining:string[]}
     * @throws InvalidArgumentException When $test_version is malformed.
     */
    public static function scan( array $paths, $test_version, $deadline, $read = null ) {
        $test_version = (string) $test_version;
        if ( ! preg_match( '/^[5-9]\.\d{1,2}$/', $test_version ) ) {
            throw new InvalidArgumentException( 'Target PHP version must look like 8.4' );
        }
        $out = array(
            'scanned'   => array(),
            'remaining' => array_values( $paths ),
        );
        if ( ! $paths ) {
            return $out;
        }
        // AJAX and cron requests run at the host's base memory limit; ask WordPress for its admin limit like wp-admin pages do.
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }
        self::quiet_start();
        try {
            list( $ruleset, $config ) = self::ruleset( $test_version );
            while ( $out['remaining'] && ( ! $out['scanned'] || ( microtime( true ) < $deadline && ! self::memory_pressure() ) ) ) {
                $path = array_shift( $out['remaining'] );
                if ( is_callable( $read ) ) {
                    $content = call_user_func( $read, $path, self::MAX_BYTES + 1 );
                    if ( null !== $content && ! is_string( $content ) ) {
                        $out['scanned'][ $path ] = array( self::skipped_note( __( 'Could not read this file.', 'thrg-php-checker' ) ) );
                    } elseif ( null === $content || strlen( $content ) > self::MAX_BYTES ) {
                        $out['scanned'][ $path ] = array( self::skipped_note( __( 'Skipped: file is larger than 2 MB.', 'thrg-php-checker' ) ) );
                    } else {
                        $out['scanned'][ $path ] = self::scan_content( $content, (string) $path, $ruleset, $config, $test_version );
                    }
                    continue;
                }
                $size = is_readable( $path ) ? filesize( $path ) : false;
                if ( false === $size ) {
                    $out['scanned'][ $path ] = array( self::skipped_note( __( 'Could not read this file.', 'thrg-php-checker' ) ) );
                    continue;
                }
                if ( $size > self::MAX_BYTES ) {
                    $out['scanned'][ $path ] = array( self::skipped_note( __( 'Skipped: file is larger than 2 MB.', 'thrg-php-checker' ) ) );
                    continue;
                }
                $out['scanned'][ $path ] = self::scan_file( $path, $ruleset, $config, $test_version );
            }
        } finally {
            restore_error_handler();
        }
        return $out;
    }

    /**
     * Whether this request is close to its PHP memory limit.
     *
     * A scan step stops early when this is true, and the next step starts in a fresh request with
     * its memory freed. A step always scans at least one file, so progress never stalls.
     *
     * @return bool
     */
    public static function memory_pressure() {
        $limit = (string) ini_get( 'memory_limit' );
        if ( '' === $limit || '-1' === $limit ) {
            return false;
        }
        $bytes = (int) $limit;
        switch ( strtoupper( substr( $limit, -1 ) ) ) {
            case 'G':
                $bytes *= 1024;
                // Fall through.
            case 'M':
                $bytes *= 1024;
                // Fall through.
            case 'K':
                $bytes *= 1024;
        }
        if ( $bytes <= 0 ) {
            return false;
        }
        if ( memory_get_usage( true ) > $bytes * 0.5 ) {
            gc_collect_cycles();
        }
        return memory_get_usage( true ) > $bytes * 0.7;
    }

    /**
     * Suppress non-fatal notices emitted by the bundled PHPCS/PHPCompatibility sniffs on malformed code.
     *
     * @return void
     */
    private static function quiet_start() {
        $lib      = rtrim( str_replace( '\\', '/', (string) realpath( self::lib_dir() ) ), '/' ) . '/';
        $quiet    = E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED;
        $previous = null;
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped to a single scan batch and restored in finally.
        $previous = set_error_handler(
            static function ( $errno, $errstr, $errfile = '', $errline = 0 ) use ( &$previous, $lib, $quiet ) {
                if ( ( $errno & $quiet ) && '/' !== $lib && 0 === strpos( str_replace( '\\', '/', (string) $errfile ), $lib ) ) {
                    return true;
                }
                if ( ! is_callable( $previous ) ) {
                    return false;
                }
                return false !== call_user_func( $previous, $errno, $errstr, $errfile, $errline );
            }
        );
    }

    /**
     * Scan a single file on disk.
     *
     * @param string                   $path    Absolute path.
     * @param \PHP_CodeSniffer\Ruleset $ruleset Loaded ruleset.
     * @param \PHP_CodeSniffer\Config  $config  PHPCS config.
     * @param string                   $target  Target PHP version.
     * @return array
     */
    public static function scan_file( $path, $ruleset, $config, $target = '' ) {
        return self::process(
            static function () use ( $path, $ruleset, $config ) {
                return new \PHP_CodeSniffer\Files\LocalFile( (string) $path, $ruleset, $config );
            },
            (string) $target
        );
    }

    /**
     * Scan PHP source held in memory (such as an entry inside an update zip).
     *
     * @param string                   $content PHP source code.
     * @param string                   $name    File path or zip entry name.
     * @param \PHP_CodeSniffer\Ruleset $ruleset Loaded ruleset.
     * @param \PHP_CodeSniffer\Config  $config  PHPCS config.
     * @param string                   $target  Target PHP version.
     * @return array
     */
    public static function scan_content( $content, $name, $ruleset, $config, $target = '' ) {
        $clean_name = '/thrg-pc-memory/' . sha1( (string) $content ) . '/' . ltrim( str_replace( array( '\\', "\r", "\n" ), array( '/', '', '' ), (string) $name ), '/' );
        return self::process(
            static function () use ( $content, $clean_name, $ruleset, $config ) {
                return new \PHP_CodeSniffer\Files\DummyFile( 'phpcs_input_file: ' . $clean_name . "\n" . $content, $ruleset, $config );
            },
            (string) $target
        );
    }

    /**
     * Process a PHPCS File instance and collect sorted findings with guarded-code context applied.
     *
     * @param callable $make   Factory returning a \PHP_CodeSniffer\Files\File.
     * @param string   $target Target PHP version.
     * @return array
     */
    private static function process( callable $make, $target = '' ) {
        $findings = array();
        try {
            $file = call_user_func( $make );
            $file->process();
            foreach ( array(
                'error'   => $file->getErrors(),
                'warning' => $file->getWarnings(),
            ) as $type => $by_line ) {
                foreach ( $by_line as $line => $columns ) {
                    foreach ( $columns as $column => $messages ) {
                        foreach ( $messages as $m ) {
                            $source = (string) $m['source'];
                            // Skip PHPCS internal formatting/line-ending sniffs (not PHP compatibility issues).
                            if ( 0 === strpos( $source, 'Internal.' ) && false === strpos( $source, 'ParseError' ) ) {
                                continue;
                            }
                            $findings[] = array(
                                'line'    => (int) $line,
                                'type'    => $type,
                                'message' => (string) $m['message'],
                                'source'  => $source,
                                'column'  => (int) $column,
                            );
                        }
                    }
                }
            }
            $tokens = '' === $target ? array() : $file->getTokens();
            if ( $tokens ) {
                $findings = array_merge( $findings, THRG_PC_Context::dynamic_property_findings( $tokens, $target ) );
                // The bundled rule goes silent for PHP 8.0+, so add its removed-in-8.0 finding here unless it already reported one.
                $reported = array();
                foreach ( $findings as $existing ) {
                    if ( 0 === strpos( (string) $existing['source'], 'PHPCompatibility.FunctionNameRestrictions.RemovedPHP4StyleConstructors.' ) ) {
                        $reported[ (int) $existing['line'] ] = true;
                    }
                }
                foreach ( THRG_PC_Context::legacy_constructor_findings( $tokens, $target ) as $legacy ) {
                    if ( ! isset( $reported[ (int) $legacy['line'] ] ) ) {
                        $findings[] = $legacy;
                    }
                }
            }
            $findings = THRG_PC_Context::mark_guarded( $findings, $tokens, $target );
            // PHPCS tokenizes malformed source permissively. A host parse failure
            // is a coverage gap, not proof of incompatibility on another PHP target.
            try {
                $source = $file->numTokens ? $file->getTokensAsString( 0, $file->numTokens, true ) : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Upstream PHPCS API.
                token_get_all( $source, TOKEN_PARSE );
            } catch ( CompileError $e ) {
                // A host older than the target cannot be expected to parse newer syntax; that is not a coverage gap.
                $host_branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
                if ( '' !== $target && version_compare( $host_branch, $target, '>=' ) ) {
                    $findings[] = self::skipped_note(
                        sprintf(
                            /* translators: %s: PHP version used to run the scanner */
                            __( 'Syntax could not be validated on PHP %s. The code may be malformed or intended for a different PHP runtime.', 'thrg-php-checker' ),
                            PHP_VERSION
                        )
                    );
                }
            }
            $file->cleanUp();
        } catch ( Throwable $e ) {
            $findings = array( self::skipped_note( __( 'The scanner could not parse this file.', 'thrg-php-checker' ) ) );
        }
        usort(
            $findings,
            static function ( $a, $b ) {
                return $a['line'] <=> $b['line'];
            }
        );
        return $findings;
    }

    /**
     * Build a pseudo-finding explaining why a file was skipped.
     *
     * @param string $message Reason the file was skipped.
     * @return array
     */
    public static function skipped_note( $message ) {
        return array(
            'line'    => 0,
            'type'    => 'skipped',
            'message' => (string) $message,
            'source'  => 'thrg_pc.skipped',
        );
    }
}

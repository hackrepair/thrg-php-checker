<?php
/** Possible startup files discovered from literal include expressions.
 *
 * Starts at the main plugin file (or a theme's functions.php) and follows
 * include/require statements at the top level that use a literal path. It follows one
 * level only and does not resolve control flow, early exits or dynamic paths.
 * The classifier treats membership as a hint, never as execution coverage.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

/** Boot-file discovery for the compatibility grade. */
final class THRG_PC_Boot {

    const MAX_FILES = 60;
    const MAX_BYTES = 2097152;

    /**
     * Relative paths of possible startup files.
     *
     * @param array $component Component with kind, slug, dir and optional file.
     * @return string[]
     */
    public static function files( array $component ) {
        $dir  = isset( $component['dir'] ) ? rtrim( str_replace( '\\', '/', (string) $component['dir'] ), '/' ) : '';
        $kind = isset( $component['kind'] ) ? (string) $component['kind'] : '';
        $slug = isset( $component['slug'] ) ? (string) $component['slug'] : '';
        if ( '' === $dir ) {
            return array();
        }
        if ( 'theme' === $kind ) {
            $main = 'functions.php';
        } elseif ( ! empty( $component['file'] ) ) {
            $main = (string) $component['file'];
        } else {
            $main = basename( $slug );
        }
        if ( '' === $main || '.php' !== substr( $main, -4 ) || ! is_file( $dir . '/' . $main ) ) {
            return array();
        }
        $out = array( $main );
        foreach ( self::includes( $dir . '/' . $main ) as $abs ) {
            $rel = self::relative( $dir, $abs );
            if ( '' !== $rel && ! in_array( $rel, $out, true ) && is_file( $abs ) ) {
                $out[] = $rel;
            }
            if ( count( $out ) >= self::MAX_FILES ) {
                break;
            }
        }
        return $out;
    }

    /**
     * Absolute paths included at the top level of a file with a literal path.
     *
     * @param string $file Absolute path of the including file.
     * @return string[]
     */
    public static function includes( $file ) {
        if ( ! is_readable( $file ) || filesize( $file ) > self::MAX_BYTES ) {
            return array();
        }
        $code = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.
        if ( ! is_string( $code ) ) {
            return array();
        }
        $tokens = token_get_all( $code );
        $base   = str_replace( '\\', '/', dirname( $file ) );
        $depth  = 0;
        $count  = count( $tokens );
        $found  = array();
        $prev   = '';
        for ( $i = 0; $i < $count; $i++ ) {
            $t = $tokens[ $i ];
            if ( is_array( $t ) ) {
                if ( in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
                    continue;
                }
                if ( in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
                    ++$depth;
                }
                if ( 0 === $depth && in_array( $t[0], array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ), true ) && in_array( $prev, array( ';', '}', 'open', 'html' ), true ) ) {
                    $path = self::literal_path( $tokens, $i + 1, $base );
                    if ( null !== $path ) {
                        $found[] = $path;
                    }
                }
                $prev = T_OPEN_TAG === $t[0] || T_OPEN_TAG_WITH_ECHO === $t[0] ? 'open' : ( T_INLINE_HTML === $t[0] ? 'html' : 'other' );
                continue;
            }
            if ( '{' === $t ) {
                ++$depth;
            } elseif ( '}' === $t ) {
                $depth = max( 0, $depth - 1 );
            }
            $prev = $t;
        }
        return $found;
    }

    /**
     * Resolve a literal include expression to an absolute path.
     *
     * Accepts 'file.php', __DIR__ . '/file.php', dirname( __FILE__ ) . '/file.php'
     * and plugin_dir_path( __FILE__ ) . 'file.php'. Anything else returns null.
     *
     * @param array  $tokens Token list from token_get_all().
     * @param int    $start  First token after the include keyword.
     * @param string $base   Directory of the including file.
     * @return string|null
     */
    private static function literal_path( array $tokens, $start, $base ) {
        $parts = array();
        $count = count( $tokens );
        for ( $i = $start; $i < $count; $i++ ) {
            $t = $tokens[ $i ];
            if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
                continue;
            }
            if ( ';' === $t ) {
                break;
            }
            $parts[] = $t;
        }
        // Drop wrapping parentheses: require( 'a.php' ).
        $wrapped = count( $parts ) >= 2 && '(' === $parts[0] && ')' === end( $parts );
        if ( $wrapped ) {
            array_shift( $parts );
            array_pop( $parts );
        }
        $prefix = '';
        $text   = '';
        $i      = 0;
        $n      = count( $parts );
        if ( $n >= 2 && is_array( $parts[0] ) && T_DIR === $parts[0][0] ) {
            $prefix = $base;
            $i      = 1;
        } elseif ( $n >= 4 && is_array( $parts[0] ) && T_STRING === $parts[0][0] && in_array( strtolower( $parts[0][1] ), array( 'dirname', 'plugin_dir_path' ), true ) && '(' === $parts[1] && is_array( $parts[2] ) && T_FILE === $parts[2][0] && ')' === $parts[3] ) {
            $prefix = $base;
            $i      = 4;
        }
        if ( '' !== $prefix ) {
            if ( $i >= $n || '.' !== $parts[ $i ] ) {
                return null;
            }
            ++$i;
        }
        if ( $i !== $n - 1 || ! is_array( $parts[ $i ] ) || T_CONSTANT_ENCAPSED_STRING !== $parts[ $i ][0] ) {
            return null;
        }
        $text = substr( $parts[ $i ][1], 1, -1 );
        if ( false !== strpos( $text, '$' ) || false !== strpos( $text, '{' ) ) {
            return null;
        }
        $text = str_replace( '\\', '/', $text );
        if ( '' === $prefix ) {
            if ( '/' === substr( $text, 0, 1 ) ) {
                return null;
            }
            $prefix = $base;
            $text   = '/' . $text;
        } elseif ( '/' !== substr( $text, 0, 1 ) ) {
            $text = '/' . $text;
        }
        return self::normalize( $prefix . $text );
    }

    /**
     * Collapse ./ and ../ segments.
     *
     * @param string $path Absolute path.
     * @return string
     */
    private static function normalize( $path ) {
        $out = array();
        foreach ( explode( '/', $path ) as $seg ) {
            if ( '' === $seg || '.' === $seg ) {
                continue;
            }
            if ( '..' === $seg ) {
                array_pop( $out );
                continue;
            }
            $out[] = $seg;
        }
        return '/' . implode( '/', $out );
    }

    /**
     * Path relative to the component directory, or '' when outside it.
     *
     * @param string $dir Component directory.
     * @param string $abs Absolute path.
     * @return string
     */
    private static function relative( $dir, $abs ) {
        $dir = self::normalize( $dir );
        if ( 0 !== strpos( $abs, $dir . '/' ) ) {
            return '';
        }
        return substr( $abs, strlen( $dir ) + 1 );
    }
}

<?php
/** PHP 8.0+ old-style (PHP 4) constructor analyzer.
 *
 * The bundled compatibility rules stop reporting old-style constructors once the
 * target is PHP 8.0 or higher, which is exactly when PHP stops treating them as
 * constructors. This analyzer fills that gap.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

/** Detects classes whose only constructor is a method named after the class. */
class THRG_PC_Legacy_Constructors {

    const SOURCE = 'PHPCompatibility.FunctionNameRestrictions.RemovedPHP4StyleConstructors.Removed';

    /**
     * Detect old-style constructors that PHP 8.0+ no longer calls.
     *
     * A class in a namespace never used the old style, and a class that also has
     * __construct() keeps working, so neither is reported.
     *
     * @param array  $tokens PHPCS token stack.
     * @param string $target Target PHP version ("8.4" style).
     * @return array[] Raw finding records with line, column, type, message, source.
     */
    public static function findings( array $tokens, $target ) {
        $target = (string) $target;
        if ( '' === $target || ! $tokens || version_compare( $target, '8.0', '<' ) ) {
            return array();
        }

        $findings = array();
        foreach ( $tokens as $ptr => $token ) {
            if ( 'T_CLASS' !== $token['type'] || ! isset( $token['scope_opener'], $token['scope_closer'] ) ) {
                continue;
            }
            if ( '' !== self::namespace_at( $tokens, $ptr ) ) {
                continue;
            }

            $name_ptr = THRG_PC_Context::next( $tokens, $ptr + 1 );
            if ( null === $name_ptr || $name_ptr >= $token['scope_opener'] || 'T_STRING' !== $tokens[ $name_ptr ]['type'] ) {
                continue;
            }
            $class_lc = strtolower( $tokens[ $name_ptr ]['content'] );

            $has_new = false;
            $old_ptr = null;
            for ( $i = $token['scope_opener'] + 1; $i < $token['scope_closer']; $i++ ) {
                if ( 'T_FUNCTION' !== $tokens[ $i ]['type'] || ! self::is_direct_member( $tokens, $i, $ptr ) ) {
                    continue;
                }
                $fn_ptr = THRG_PC_Context::function_name_ptr( $tokens, $i );
                if ( null === $fn_ptr ) {
                    continue;
                }
                $method_lc = strtolower( $tokens[ $fn_ptr ]['content'] );
                if ( '__construct' === $method_lc ) {
                    $has_new = true;
                } elseif ( $method_lc === $class_lc && null === $old_ptr ) {
                    $old_ptr = $fn_ptr;
                }
            }

            if ( ! $has_new && null !== $old_ptr ) {
                $findings[] = array(
                    'line'    => (int) $tokens[ $old_ptr ]['line'],
                    'type'    => 'error',
                    'message' => 'Declaration of a PHP4 style class constructor is deprecated since PHP 7.0 and removed since PHP 8.0',
                    'source'  => self::SOURCE,
                    'column'  => (int) $tokens[ $old_ptr ]['column'],
                );
            }
        }

        return $findings;
    }

    /**
     * Whether a function token is a member of the given class rather than nested deeper.
     *
     * @param array $tokens    PHPCS token stack.
     * @param int   $fn_ptr    T_FUNCTION pointer.
     * @param int   $class_ptr T_CLASS pointer.
     * @return bool
     */
    private static function is_direct_member( array $tokens, $fn_ptr, $class_ptr ) {
        $conditions = isset( $tokens[ $fn_ptr ]['conditions'] ) ? $tokens[ $fn_ptr ]['conditions'] : array();
        if ( ! $conditions ) {
            return false;
        }
        $last = array_key_last( $conditions );
        return $last === $class_ptr;
    }

    /**
     * Name of the namespace a token sits in ('' for the global namespace).
     *
     * @param array $tokens PHPCS token stack.
     * @param int   $ptr    Token pointer.
     * @return string
     */
    private static function namespace_at( array $tokens, $ptr ) {
        for ( $i = $ptr - 1; $i >= 0; $i-- ) {
            if ( 'T_NAMESPACE' !== $tokens[ $i ]['type'] ) {
                continue;
            }
            $name = '';
            for ( $k = $i + 1; $k < $ptr; $k++ ) {
                $type = $tokens[ $k ]['type'];
                if ( in_array( $type, array( 'T_SEMICOLON', 'T_OPEN_CURLY_BRACKET' ), true ) ) {
                    break;
                }
                if ( in_array( $type, array( 'T_STRING', 'T_NS_SEPARATOR', 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED' ), true ) ) {
                    $name .= $tokens[ $k ]['content'];
                }
            }
            // "namespace\Foo" is a relative name, not a declaration.
            if ( 0 === strpos( $name, '\\' ) ) {
                continue;
            }
            return $name;
        }
        return '';
    }
}

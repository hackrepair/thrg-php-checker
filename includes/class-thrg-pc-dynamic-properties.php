<?php
/** PHP 8.2+ dynamic property analyzer.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

/** Detects undeclared dynamic property assignments while honoring PHP 8.2+ exceptions. */
class THRG_PC_Dynamic_Properties {

    /**
     * Known base classes whose instances permit dynamic properties in PHP 8.2+.
     *
     * @param string $lower_name Lowercase short class name.
     * @return bool
     */
    private static function allows_dynamic_base( $lower_name ) {
        return in_array(
            (string) $lower_name,
            array(
                'stdclass',
                'wp_post',
                'wp_term',
                'wp_user',
                'wp_comment',
                'wp_site',
                'wp_network',
                'wp_roles',
                'wp_query',
                'wp_user_query',
                'wp_tax_query',
                'wp_meta_query',
                'wp_date_query',
                'arrayobject',
                'simplexmlelement',
                'domnode',
                'domelement',
                'domdocument',
            ),
            true
        );
    }

    /**
     * Detect PHP 8.2+ undeclared dynamic property creations ($this->prop = ...)
     * while honoring declared properties, constructor property promotion,
     * #[AllowDynamicProperties] (including inheritance), stdClass, and __set().
     *
     * @param array  $tokens PHPCS token stack.
     * @param string $target Target PHP version ("8.2" style).
     * @return array[] Raw finding records with line, column, type, message, source.
     */
    public static function findings( array $tokens, $target ) {
        $target = (string) $target;
        if ( '' === $target || ! $tokens || version_compare( $target, '8.2', '<' ) ) {
            return array();
        }

        $classes  = array();
        $by_ptr   = array();
        $oo_types = array( 'T_CLASS', 'T_ANON_CLASS', 'T_TRAIT', 'T_INTERFACE', 'T_ENUM' );

        foreach ( $tokens as $ptr => $token ) {
            if ( ! in_array( $token['type'], array( 'T_CLASS', 'T_ANON_CLASS', 'T_TRAIT' ), true ) ) {
                continue;
            }
            if ( ! isset( $token['scope_opener'], $token['scope_closer'] ) ) {
                continue;
            }
            $info                   = self::extract_class_info( $tokens, $ptr, $oo_types );
            $by_ptr[ $ptr ]         = $info;
            $classes[ $info['id'] ] = $info;
        }

        if ( ! $by_ptr ) {
            return array();
        }

        $findings = array();
        foreach ( $by_ptr as $class_ptr => $info ) {
            if ( 'T_TRAIT' === $info['type'] ) {
                continue;
            }
            $resolved = self::resolve_class_hierarchy( $info['id'], $classes, array() );
            if ( $resolved['allow_dynamic'] || $resolved['has_magic_set'] || $resolved['unresolved'] ) {
                continue;
            }

            $opener = $tokens[ $class_ptr ]['scope_opener'];
            $closer = $tokens[ $class_ptr ]['scope_closer'];
            $seen   = array();

            for ( $i = $opener + 1; $i < $closer; $i++ ) {
                if ( 'T_VARIABLE' !== $tokens[ $i ]['type'] || '$this' !== $tokens[ $i ]['content'] ) {
                    continue;
                }
                if ( self::innermost_oo( $tokens[ $i ], $tokens, $oo_types ) !== $class_ptr ) {
                    continue;
                }
                $op_ptr = THRG_PC_Context::next( $tokens, $i + 1 );
                if ( null === $op_ptr || ! in_array( $tokens[ $op_ptr ]['type'], array( 'T_OBJECT_OPERATOR', 'T_NULLSAFE_OBJECT_OPERATOR' ), true ) ) {
                    continue;
                }
                $prop_ptr = THRG_PC_Context::next( $tokens, $op_ptr + 1 );
                if ( null === $prop_ptr || 'T_STRING' !== $tokens[ $prop_ptr ]['type'] ) {
                    continue;
                }
                $after_ptr = THRG_PC_Context::next( $tokens, $prop_ptr + 1 );
                if ( null === $after_ptr || 'T_OPEN_PARENTHESIS' === $tokens[ $after_ptr ]['type'] ) {
                    continue;
                }
                while ( null !== $after_ptr && 'T_OPEN_SQUARE_BRACKET' === $tokens[ $after_ptr ]['type'] && isset( $tokens[ $after_ptr ]['bracket_closer'] ) ) {
                    $after_ptr = THRG_PC_Context::next( $tokens, $tokens[ $after_ptr ]['bracket_closer'] + 1 );
                }
                if ( null === $after_ptr || ! isset( \PHP_CodeSniffer\Util\Tokens::ASSIGNMENT_TOKENS[ $tokens[ $after_ptr ]['code'] ] ) ) {
                    continue;
                }

                $prop_name = $tokens[ $prop_ptr ]['content'];
                if ( isset( $resolved['props'][ $prop_name ] ) ) {
                    continue;
                }

                $line     = (int) $tokens[ $prop_ptr ]['line'];
                $dedupe_k = $prop_name . ':' . $line;
                if ( isset( $seen[ $dedupe_k ] ) ) {
                    continue;
                }
                $seen[ $dedupe_k ] = true;

                $findings[] = array(
                    'line'    => $line,
                    'column'  => (int) $tokens[ $prop_ptr ]['column'],
                    'type'    => 'warning',
                    'source'  => 'PHPCompatibility.Classes.ForbiddenDynamicProperties.Found',
                    'message' => sprintf(
                        'Creation of dynamic property %1$s::$%2$s is deprecated since PHP 8.2. Declare the property on the class, add #[AllowDynamicProperties], or handle it in __set().',
                        $info['display_name'],
                        $prop_name
                    ),
                );
            }
        }

        return $findings;
    }

    /**
     * Extract declared properties, promoted constructor properties, magic __set(),
     * #[AllowDynamicProperties], parent class, and used traits for one class/trait token.
     *
     * @param array    $tokens    PHPCS tokens.
     * @param int      $class_ptr Pointer to T_CLASS, T_ANON_CLASS, or T_TRAIT.
     * @param string[] $oo_types  OO construct token types.
     * @return array
     */
    private static function extract_class_info( array $tokens, $class_ptr, array $oo_types ) {
        $opener       = $tokens[ $class_ptr ]['scope_opener'];
        $closer       = $tokens[ $class_ptr ]['scope_closer'];
        $type         = $tokens[ $class_ptr ]['type'];
        $display_name = 'anonymous@' . $tokens[ $class_ptr ]['line'];
        $id           = 'anon@' . $class_ptr;

        if ( 'T_ANON_CLASS' !== $type ) {
            $name_ptr = THRG_PC_Context::next( $tokens, $class_ptr + 1 );
            if ( null !== $name_ptr && $name_ptr < $opener && 'T_STRING' === $tokens[ $name_ptr ]['type'] ) {
                $display_name = $tokens[ $name_ptr ]['content'];
                $id           = strtolower( $display_name );
            }
        }

        $allow_dynamic = self::has_allow_dynamic_attribute( $tokens, $class_ptr );
        $extends       = '';

        for ( $h = $class_ptr + 1; $h < $opener; $h++ ) {
            if ( 'T_EXTENDS' === $tokens[ $h ]['type'] ) {
                $ext_name = '';
                for ( $k = $h + 1; $k < $opener; $k++ ) {
                    if ( in_array( $tokens[ $k ]['type'], array( 'T_IMPLEMENTS', 'T_OPEN_CURLY_BRACKET' ), true ) ) {
                        break;
                    }
                    if ( 'T_STRING' === $tokens[ $k ]['type'] ) {
                        $ext_name = strtolower( $tokens[ $k ]['content'] );
                    }
                }
                $extends = $ext_name;
                if ( '' !== $extends && self::allows_dynamic_base( $extends ) ) {
                    $allow_dynamic = true;
                }
                break;
            }
        }

        $props         = array();
        $traits        = array();
        $has_magic_set = false;
        $fn_types      = array( 'T_FUNCTION', 'T_CLOSURE', 'T_FN' );

        for ( $i = $opener + 1; $i < $closer; $i++ ) {
            if ( self::innermost_oo( $tokens[ $i ], $tokens, $oo_types ) !== $class_ptr ) {
                continue;
            }
            $in_fn = self::inside_any( $tokens[ $i ], $tokens, $fn_types );
            $ttype = $tokens[ $i ]['type'];

            if ( ! $in_fn && 'T_USE' === $ttype && empty( $tokens[ $i ]['nested_parenthesis'] ) ) {
                $last_str = '';
                for ( $u = $i + 1; $u < $closer; $u++ ) {
                    $ut = $tokens[ $u ]['type'];
                    if ( 'T_STRING' === $ut ) {
                        $last_str = strtolower( $tokens[ $u ]['content'] );
                    } elseif ( in_array( $ut, array( 'T_COMMA', 'T_SEMICOLON', 'T_OPEN_CURLY_BRACKET' ), true ) ) {
                        if ( '' !== $last_str ) {
                            $traits[] = $last_str;
                            $last_str = '';
                        }
                        if ( 'T_OPEN_CURLY_BRACKET' === $ut && isset( $tokens[ $u ]['bracket_closer'] ) ) {
                            $i = $tokens[ $u ]['bracket_closer'];
                            break;
                        }
                        if ( 'T_SEMICOLON' === $ut ) {
                            $i = $u;
                            break;
                        }
                    }
                }
                continue;
            }

            if ( ! $in_fn && 'T_VARIABLE' === $ttype && empty( $tokens[ $i ]['nested_parenthesis'] ) ) {
                $props[ substr( $tokens[ $i ]['content'], 1 ) ] = true;
                continue;
            }

            if ( 'T_FUNCTION' === $ttype ) {
                $fn_name_ptr = THRG_PC_Context::next( $tokens, $i + 1 );
                if ( null !== $fn_name_ptr && 'T_BITWISE_AND' === $tokens[ $fn_name_ptr ]['type'] ) {
                    $fn_name_ptr = THRG_PC_Context::next( $tokens, $fn_name_ptr + 1 );
                }
                $fn_name = ( null !== $fn_name_ptr && 'T_STRING' === $tokens[ $fn_name_ptr ]['type'] )
                    ? strtolower( $tokens[ $fn_name_ptr ]['content'] )
                    : '';
                if ( '__set' === $fn_name ) {
                    $has_magic_set = true;
                } elseif ( '__construct' === $fn_name && isset( $tokens[ $i ]['parenthesis_opener'], $tokens[ $i ]['parenthesis_closer'] ) ) {
                    $p_open  = $tokens[ $i ]['parenthesis_opener'];
                    $p_close = $tokens[ $i ]['parenthesis_closer'];
                    $promo   = false;
                    for ( $p = $p_open + 1; $p < $p_close; $p++ ) {
                        $pt = $tokens[ $p ]['type'];
                        if ( 'T_COMMA' === $pt ) {
                            $promo = false;
                        } elseif ( in_array( $pt, array( 'T_PUBLIC', 'T_PROTECTED', 'T_PRIVATE', 'T_READONLY' ), true ) ) {
                            $promo = true;
                        } elseif ( $promo && 'T_VARIABLE' === $pt ) {
                            $props[ substr( $tokens[ $p ]['content'], 1 ) ] = true;
                            $promo = false;
                        }
                    }
                }
            }
        }

        return array(
            'id'            => $id,
            'display_name'  => $display_name,
            'type'          => $type,
            'extends'       => $extends,
            'traits'        => $traits,
            'allow_dynamic' => $allow_dynamic,
            'has_magic_set' => $has_magic_set,
            'props'         => $props,
        );
    }

    /**
     * Check whether a class declaration is preceded by #[AllowDynamicProperties].
     *
     * @param array $tokens    PHPCS tokens.
     * @param int   $class_ptr Pointer to T_CLASS or T_ANON_CLASS.
     * @return bool
     */
    private static function has_allow_dynamic_attribute( array $tokens, $class_ptr ) {
        $modifiers = array( 'T_FINAL', 'T_ABSTRACT', 'T_READONLY', 'T_NS_SEPARATOR', 'T_STRING', 'T_COMMA', 'T_OPEN_PARENTHESIS', 'T_CLOSE_PARENTHESIS', 'T_ATTRIBUTE', 'T_ATTRIBUTE_END' );
        for ( $i = $class_ptr - 1; $i >= 0; $i-- ) {
            if ( isset( \PHP_CodeSniffer\Util\Tokens::EMPTY_TOKENS[ $tokens[ $i ]['code'] ] ) ) {
                continue;
            }
            $type = $tokens[ $i ]['type'];
            if ( 'T_ATTRIBUTE_END' === $type && isset( $tokens[ $i ]['attribute_opener'] ) ) {
                $attr_open = $tokens[ $i ]['attribute_opener'];
                for ( $a = $attr_open; $a <= $i; $a++ ) {
                    if ( 'T_STRING' === $tokens[ $a ]['type'] && 'allowdynamicproperties' === strtolower( $tokens[ $a ]['content'] ) ) {
                        return true;
                    }
                }
                $i = $attr_open;
                continue;
            }
            if ( 'T_STRING' === $type && 'allowdynamicproperties' === strtolower( $tokens[ $i ]['content'] ) ) {
                return true;
            }
            if ( ! in_array( $type, $modifiers, true ) ) {
                break;
            }
        }
        return false;
    }

    /**
     * Resolve inherited properties, #[AllowDynamicProperties], and __set() across extends and traits.
     *
     * @param string              $id      Lowercase class/trait ID.
     * @param array<string,array> $classes Map of extracted class/trait info in this file.
     * @param array<string,bool>  $visited Cycle guard.
     * @return array{allow_dynamic:bool,has_magic_set:bool,props:array<string,bool>,unresolved:bool}
     */
    private static function resolve_class_hierarchy( $id, array $classes, array $visited ) {
        if ( isset( $visited[ $id ] ) || ! isset( $classes[ $id ] ) ) {
            return array(
                'allow_dynamic' => false,
                'has_magic_set' => false,
                'props'         => array(),
                'unresolved'    => ! isset( $classes[ $id ] ),
            );
        }
        $visited[ $id ] = true;
        $info           = $classes[ $id ];
        $allow_dynamic  = (bool) $info['allow_dynamic'];
        $has_magic_set  = (bool) $info['has_magic_set'];
        $props          = $info['props'];
        $unresolved     = false;

        if ( '' !== $info['extends'] && ! self::allows_dynamic_base( $info['extends'] ) ) {
            if ( isset( $classes[ $info['extends'] ] ) ) {
                $parent        = self::resolve_class_hierarchy( $info['extends'], $classes, $visited );
                $allow_dynamic = $allow_dynamic || $parent['allow_dynamic'];
                $has_magic_set = $has_magic_set || $parent['has_magic_set'];
                $props         = $props + $parent['props'];
                $unresolved    = $unresolved || $parent['unresolved'];
            } else {
                $unresolved = true;
            }
        }

        foreach ( $info['traits'] as $trait_id ) {
            if ( isset( $classes[ $trait_id ] ) ) {
                $tr            = self::resolve_class_hierarchy( $trait_id, $classes, $visited );
                $has_magic_set = $has_magic_set || $tr['has_magic_set'];
                $props         = $props + $tr['props'];
                $unresolved    = $unresolved || $tr['unresolved'];
            } else {
                $unresolved = true;
            }
        }

        return array(
            'allow_dynamic' => $allow_dynamic,
            'has_magic_set' => $has_magic_set,
            'props'         => $props,
            'unresolved'    => $unresolved,
        );
    }

    /**
     * Find the innermost enclosing OO construct pointer for a token.
     *
     * @param array    $token    Current token.
     * @param array    $tokens   All PHPCS tokens.
     * @param string[] $oo_types OO construct token types.
     * @return int|null
     */
    private static function innermost_oo( array $token, array $tokens, array $oo_types ) {
        if ( empty( $token['conditions'] ) ) {
            return null;
        }
        foreach ( array_reverse( array_keys( $token['conditions'] ), false ) as $cond_ptr ) {
            if ( isset( $tokens[ $cond_ptr ] ) && in_array( $tokens[ $cond_ptr ]['type'], $oo_types, true ) ) {
                return (int) $cond_ptr;
            }
        }
        return null;
    }

    /**
     * Check whether a token is inside any of the given condition token types.
     *
     * @param array    $token  Current token.
     * @param array    $tokens All PHPCS tokens.
     * @param string[] $types  Token types to check.
     * @return bool
     */
    private static function inside_any( array $token, array $tokens, array $types ) {
        if ( empty( $token['conditions'] ) ) {
            return false;
        }
        foreach ( array_keys( $token['conditions'] ) as $cond_ptr ) {
            if ( isset( $tokens[ $cond_ptr ] ) && in_array( $tokens[ $cond_ptr ]['type'], $types, true ) ) {
                return true;
            }
        }
        return false;
    }
}

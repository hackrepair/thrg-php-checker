<?php
/** Finding scope and fallback-path analysis.
 *
 * Records where a finding sits in the file (top level, class body, conditional,
 * function) and whether it sits on a path that only runs when an environment
 * check fails. The compatibility grade uses both to tell "breaks on load" from
 * "fallback code that modern servers rarely take".
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

/** Token-level scope and fallback detection for a single finding. */
final class THRG_PC_Scope {

    /**
     * Functions whose result says whether an optional feature exists on this server.
     *
     * Version checks are left out on purpose: the guard analysis already handles
     * them, and the direction of a comparison cannot be read reliably here.
     */
    const ENV_CALLS = array( 'function_exists', 'extension_loaded', 'class_exists', 'interface_exists', 'trait_exists', 'is_callable', 'method_exists', 'defined' );

    const REASON_OR     = 'fallback_or';
    const REASON_ELSE   = 'fallback_else';
    const REASON_NEG    = 'fallback_missing';
    const REASON_CALLER = 'fallback_caller';
    const REASON_LEGACY = 'fallback_legacy';
    const REASON_TWIN   = 'fallback_twin';

    /**
     * Removed extension prefixes and the functions that replace them.
     */
    const TWINS = array(
        'mysql'  => array( 'mysqli_' ),
        'mcrypt' => array( 'openssl_', 'sodium_' ),
        'ereg'   => array( 'preg_' ),
    );

    /**
     * Add scope and fallback reason to a finding.
     *
     * @param array $finding Finding record.
     * @param array $tokens  PHPCS token stack.
     * @param int   $ptr     Token pointer of the finding.
     * @return array
     */
    public static function annotate( array $finding, array $tokens, $ptr ) {
        if ( ! isset( $tokens[ $ptr ] ) ) {
            return $finding;
        }
        $finding['scope'] = self::scope( $tokens, $ptr );
        $reason           = self::fallback_reason( $tokens, $ptr, 0 );
        if ( '' !== $reason ) {
            $finding['reason'] = $reason;
        }
        return $finding;
    }

    /**
     * Whether a file name suggests legacy or fallback code.
     *
     * @param string $file Relative file path.
     * @return bool
     */
    public static function name_hint( $file ) {
        $base = strtolower( basename( str_replace( '\\', '/', (string) $file ) ) );
        return (bool) preg_match( '/(legacy|fallback|php[-_]?[45](?![0-9])|old[-_]?php|deprecated)/', $base );
    }

    /**
     * Plain-language explanation for a fallback reason code.
     *
     * @param string $reason Reason code.
     * @return string
     */
    public static function reason_text( $reason ) {
        switch ( (string) $reason ) {
            case self::REASON_OR:
                return 'runs only when a feature check fails';
            case self::REASON_ELSE:
                return 'in the else branch of a feature check';
            case self::REASON_NEG:
                return 'runs only when a feature is missing';
            case self::REASON_CALLER:
                return 'only called from fallback code';
            case self::REASON_LEGACY:
                return 'runs only for a legacy database or crypto resource';
            case self::REASON_TWIN:
                return 'legacy branch next to its modern replacement';
        }
        return '';
    }

    /**
     * Where the token sits: file, class-body, conditional, or function.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $ptr    Token pointer.
     * @return string
     */
    public static function scope( array $tokens, $ptr ) {
        $conditions = isset( $tokens[ $ptr ]['conditions'] ) ? $tokens[ $ptr ]['conditions'] : array();
        $in_class   = false;
        foreach ( $conditions as $cptr => $code ) {
            $type = isset( $tokens[ $cptr ]['type'] ) ? (string) $tokens[ $cptr ]['type'] : '';
            if ( in_array( $type, array( 'T_FUNCTION', 'T_CLOSURE', 'T_FN' ), true ) ) {
                return 'function';
            }
            if ( in_array( $type, array( 'T_CLASS', 'T_INTERFACE', 'T_TRAIT', 'T_ENUM', 'T_ANON_CLASS' ), true ) ) {
                $in_class = true;
                continue;
            }
            if ( 'T_NAMESPACE' === $type ) {
                continue;
            }
            return 'conditional';
        }
        return $in_class ? 'class-body' : 'file';
    }

    /**
     * Reason code when the token only runs on an environment-check fallback path.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $ptr    Token pointer.
     * @param int   $depth  Recursion depth (callers are followed once).
     * @return string Empty when the path is ordinary.
     */
    private static function fallback_reason( array $tokens, $ptr, $depth ) {
        if ( self::after_failed_check( $tokens, $ptr ) ) {
            return self::REASON_OR;
        }
        $legacy = self::legacy_prefix( $tokens, $ptr );
        if ( '' !== $legacy && self::gated_by_legacy_resource( $tokens, $ptr, $legacy ) ) {
            return self::REASON_LEGACY;
        }
        $function_ptr = null;
        $conditions   = isset( $tokens[ $ptr ]['conditions'] ) ? $tokens[ $ptr ]['conditions'] : array();
        foreach ( $conditions as $cptr => $code ) {
            $type = isset( $tokens[ $cptr ]['type'] ) ? (string) $tokens[ $cptr ]['type'] : '';
            if ( 'T_IF' === $type || 'T_ELSEIF' === $type ) {
                $checks = self::branch_checks( $tokens, $cptr );
                if ( $checks['neg'] && ! $checks['pos'] ) {
                    return self::REASON_NEG;
                }
            }
            if ( 'T_ELSE' === $type || 'T_ELSEIF' === $type ) {
                if ( self::else_of_positive_check( $tokens, $cptr ) ) {
                    return self::REASON_ELSE;
                }
                if ( '' !== $legacy && self::else_of_modern_twin( $tokens, $cptr, $legacy ) ) {
                    return self::REASON_TWIN;
                }
            }
            if ( 'T_FUNCTION' === $type ) {
                $function_ptr = $cptr;
            }
        }
        if ( 0 === $depth && null !== $function_ptr ) {
            return self::called_only_from_fallbacks( $tokens, $function_ptr ) ? self::REASON_CALLER : '';
        }
        return '';
    }

    /**
     * Whether the statement is "<feature check> || <token>" so the token runs only if the check fails.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $ptr    Token pointer.
     * @return bool
     */
    private static function after_failed_check( array $tokens, $ptr ) {
        for ( $i = $ptr - 1; $i >= 0; $i-- ) {
            $type = (string) $tokens[ $i ]['type'];
            if ( in_array( $type, array( 'T_SEMICOLON', 'T_OPEN_CURLY_BRACKET', 'T_CLOSE_CURLY_BRACKET', 'T_OPEN_TAG' ), true ) ) {
                return false;
            }
            if ( 'T_CLOSE_PARENTHESIS' === $type && isset( $tokens[ $i ]['parenthesis_opener'] ) ) {
                $i = (int) $tokens[ $i ]['parenthesis_opener'];
                continue;
            }
            if ( 'T_BOOLEAN_OR' === $type || 'T_LOGICAL_OR' === $type ) {
                $from = $i - 1;
                while ( $from >= 0 && ! in_array( (string) $tokens[ $from ]['type'], array( 'T_SEMICOLON', 'T_OPEN_CURLY_BRACKET', 'T_CLOSE_CURLY_BRACKET', 'T_OPEN_TAG', 'T_BOOLEAN_OR', 'T_LOGICAL_OR', 'T_BOOLEAN_AND', 'T_LOGICAL_AND' ), true ) ) {
                    --$from;
                }
                $checks = self::range_checks( $tokens, $from + 1, $i - 1 );
                return $checks['pos'] && ! $checks['neg'];
            }
        }
        return false;
    }

    /**
     * Environment checks found in the parenthesised condition of an if/elseif.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $owner  Pointer of the if/elseif token.
     * @return array{pos:bool,neg:bool}
     */
    private static function branch_checks( array $tokens, $owner ) {
        if ( ! isset( $tokens[ $owner ]['parenthesis_opener'], $tokens[ $owner ]['parenthesis_closer'] ) ) {
            return array(
                'pos' => false,
                'neg' => false,
            );
        }
        return self::range_checks( $tokens, (int) $tokens[ $owner ]['parenthesis_opener'] + 1, (int) $tokens[ $owner ]['parenthesis_closer'] - 1 );
    }

    /**
     * Find positive and negated environment checks between two pointers.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $from   First pointer.
     * @param int   $to     Last pointer.
     * @return array{pos:bool,neg:bool}
     */
    private static function range_checks( array $tokens, $from, $to ) {
        $pos = false;
        $neg = false;
        for ( $i = $from; $i <= $to; $i++ ) {
            if ( 'T_STRING' !== $tokens[ $i ]['type'] || ! in_array( strtolower( (string) $tokens[ $i ]['content'] ), self::ENV_CALLS, true ) ) {
                continue;
            }
            $next = self::sig( $tokens, $i, 1 );
            if ( null === $next || 'T_OPEN_PARENTHESIS' !== $tokens[ $next ]['type'] ) {
                continue;
            }
            $prev = self::sig( $tokens, $i, -1 );
            if ( null !== $prev && 'T_BOOLEAN_NOT' === $tokens[ $prev ]['type'] ) {
                $neg = true;
            } else {
                $pos = true;
            }
        }
        return array(
            'pos' => $pos,
            'neg' => $neg,
        );
    }

    /**
     * Whether an else/elseif branch follows an if whose condition is a positive feature check.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $branch Pointer of the else/elseif token.
     * @return bool
     */
    private static function else_of_positive_check( array $tokens, $branch ) {
        for ( $guard = 0; $guard < 20; $guard++ ) {
            $prev = self::sig( $tokens, $branch, -1 );
            if ( null === $prev || 'T_CLOSE_CURLY_BRACKET' !== $tokens[ $prev ]['type'] || ! isset( $tokens[ $prev ]['scope_condition'] ) ) {
                return false;
            }
            $owner  = (int) $tokens[ $prev ]['scope_condition'];
            $checks = self::branch_checks( $tokens, $owner );
            if ( $checks['pos'] && ! $checks['neg'] ) {
                return true;
            }
            if ( 'T_ELSEIF' !== $tokens[ $owner ]['type'] && 'T_ELSE' !== $tokens[ $owner ]['type'] ) {
                return false;
            }
            $branch = $owner;
        }
        return false;
    }

    /**
     * Whether every call to a function in this file sits on a fallback path.
     *
     * @param array $tokens   PHPCS tokens.
     * @param int   $function_ptr Pointer of the function keyword.
     * @return bool
     */
    private static function called_only_from_fallbacks( array $tokens, $function_ptr ) {
        $name_ptr = self::sig( $tokens, $function_ptr, 1 );
        if ( null !== $name_ptr && 'T_BITWISE_AND' === $tokens[ $name_ptr ]['type'] ) {
            $name_ptr = self::sig( $tokens, $name_ptr, 1 );
        }
        if ( null === $name_ptr || 'T_STRING' !== $tokens[ $name_ptr ]['type'] ) {
            return false;
        }
        $name  = strtolower( (string) $tokens[ $name_ptr ]['content'] );
        $calls = array();
        foreach ( $tokens as $i => $token ) {
            if ( $i === $name_ptr || 'T_STRING' !== $token['type'] || strtolower( (string) $token['content'] ) !== $name ) {
                continue;
            }
            $next = self::sig( $tokens, $i, 1 );
            $prev = self::sig( $tokens, $i, -1 );
            if ( null === $next || 'T_OPEN_PARENTHESIS' !== $tokens[ $next ]['type'] ) {
                continue;
            }
            if ( null !== $prev && 'T_FUNCTION' === $tokens[ $prev ]['type'] ) {
                continue;
            }
            $calls[] = $i;
        }
        if ( ! $calls ) {
            return false;
        }
        foreach ( $calls as $call ) {
            if ( '' === self::fallback_reason( $tokens, $call, 1 ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Removed-extension prefix (mysql, mcrypt, ereg) of the called function, or ''.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $ptr    Token pointer.
     * @return string
     */
    private static function legacy_prefix( array $tokens, $ptr ) {
        if ( 'T_STRING' !== $tokens[ $ptr ]['type'] ) {
            return '';
        }
        if ( preg_match( '/^(mysql|mcrypt|ereg)_/', strtolower( (string) $tokens[ $ptr ]['content'] ), $m ) ) {
            return $m[1];
        }
        return '';
    }

    /**
     * Whether the call only runs behind a test for a legacy resource, such as 'mysql link' == $type && mysql_ping().
     *
     * @param array  $tokens PHPCS tokens.
     * @param int    $ptr    Token pointer.
     * @param string $prefix Extension prefix.
     * @return bool
     */
    private static function gated_by_legacy_resource( array $tokens, $ptr, $prefix ) {
        $regex = '/\b' . preg_quote( $prefix, '/' ) . '\b/i';
        for ( $i = $ptr - 1; $i >= 0; $i-- ) {
            $type = (string) $tokens[ $i ]['type'];
            if ( in_array( $type, array( 'T_SEMICOLON', 'T_OPEN_CURLY_BRACKET', 'T_CLOSE_CURLY_BRACKET', 'T_OPEN_TAG' ), true ) ) {
                break;
            }
            if ( 'T_CLOSE_PARENTHESIS' === $type && isset( $tokens[ $i ]['parenthesis_opener'] ) ) {
                $i = (int) $tokens[ $i ]['parenthesis_opener'];
                continue;
            }
            if ( 'T_BOOLEAN_AND' === $type || 'T_LOGICAL_AND' === $type ) {
                return self::has_string( $tokens, self::statement_from( $tokens, $i ), $i - 1, $regex );
            }
        }
        $conditions = isset( $tokens[ $ptr ]['conditions'] ) ? $tokens[ $ptr ]['conditions'] : array();
        foreach ( $conditions as $cptr => $code ) {
            $ctype = isset( $tokens[ $cptr ]['type'] ) ? (string) $tokens[ $cptr ]['type'] : '';
            if ( ( 'T_IF' === $ctype || 'T_ELSEIF' === $ctype ) && isset( $tokens[ $cptr ]['parenthesis_opener'], $tokens[ $cptr ]['parenthesis_closer'] ) ) {
                if ( self::has_string( $tokens, (int) $tokens[ $cptr ]['parenthesis_opener'] + 1, (int) $tokens[ $cptr ]['parenthesis_closer'] - 1, $regex ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * First token of the statement that contains the given pointer.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $ptr    Token pointer.
     * @return int
     */
    private static function statement_from( array $tokens, $ptr ) {
        for ( $i = $ptr - 1; $i >= 0; $i-- ) {
            if ( in_array( (string) $tokens[ $i ]['type'], array( 'T_SEMICOLON', 'T_OPEN_CURLY_BRACKET', 'T_CLOSE_CURLY_BRACKET', 'T_OPEN_TAG' ), true ) ) {
                return $i + 1;
            }
            if ( 'T_CLOSE_PARENTHESIS' === $tokens[ $i ]['type'] && isset( $tokens[ $i ]['parenthesis_opener'] ) ) {
                $i = (int) $tokens[ $i ]['parenthesis_opener'];
            }
        }
        return 0;
    }

    /**
     * Whether a string literal between two pointers matches a pattern.
     *
     * @param array  $tokens PHPCS tokens.
     * @param int    $from   First pointer.
     * @param int    $to     Last pointer.
     * @param string $regex  Pattern.
     * @return bool
     */
    private static function has_string( array $tokens, $from, $to, $regex ) {
        for ( $i = max( 0, $from ); $i <= $to; $i++ ) {
            if ( 'T_CONSTANT_ENCAPSED_STRING' === $tokens[ $i ]['type'] && preg_match( $regex, (string) $tokens[ $i ]['content'] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether an else branch sits next to an if-branch that already uses the modern replacement API.
     *
     * @param array  $tokens PHPCS tokens.
     * @param int    $branch Pointer of the else/elseif token.
     * @param string $prefix Removed-extension prefix of the flagged call.
     * @return bool
     */
    private static function else_of_modern_twin( array $tokens, $branch, $prefix ) {
        $twins = isset( self::TWINS[ $prefix ] ) ? self::TWINS[ $prefix ] : array();
        $prev  = self::sig( $tokens, $branch, -1 );
        if ( ! $twins || null === $prev || 'T_CLOSE_CURLY_BRACKET' !== $tokens[ $prev ]['type'] || ! isset( $tokens[ $prev ]['scope_condition'] ) ) {
            return false;
        }
        $owner = (int) $tokens[ $prev ]['scope_condition'];
        if ( ! isset( $tokens[ $owner ]['scope_opener'], $tokens[ $owner ]['scope_closer'] ) ) {
            return false;
        }
        for ( $i = (int) $tokens[ $owner ]['scope_opener']; $i <= (int) $tokens[ $owner ]['scope_closer']; $i++ ) {
            if ( 'T_STRING' !== $tokens[ $i ]['type'] ) {
                continue;
            }
            $name = strtolower( (string) $tokens[ $i ]['content'] );
            foreach ( $twins as $twin ) {
                if ( 0 === strpos( $name, $twin ) ) {
                    $next = self::sig( $tokens, $i, 1 );
                    if ( null !== $next && 'T_OPEN_PARENTHESIS' === $tokens[ $next ]['type'] ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Previous (-1) or next (+1) token that is not whitespace or a comment.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $i      Start pointer.
     * @param int   $step   1 or -1.
     * @return int|null
     */
    private static function sig( array $tokens, $i, $step ) {
        $count = count( $tokens );
        for ( $j = $i + $step; $j >= 0 && $j < $count; $j += $step ) {
            if ( ! in_array( (string) $tokens[ $j ]['type'], array( 'T_WHITESPACE', 'T_COMMENT', 'T_DOC_COMMENT', 'T_DOC_COMMENT_OPEN_TAG', 'T_DOC_COMMENT_CLOSE_TAG', 'T_DOC_COMMENT_STRING', 'T_DOC_COMMENT_STAR', 'T_DOC_COMMENT_WHITESPACE', 'T_DOC_COMMENT_TAG' ), true ) ) {
                return $j;
            }
        }
        return null;
    }
}

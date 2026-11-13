<?php
/**
 * Context analyzer for PHPCompatibility findings.
 *
 * Downgrades runtime findings inside version-guarded branches, feature-existence
 * checks (function_exists, class_exists, interface_exists, trait_exists,
 * enum_exists, defined, extension_loaded, is_callable), early-exit guards, and
 * polyfill/back-compat files into informational notes so they do not produce
 * false "Incompatible" verdicts. Compile-time syntax findings remain errors.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Token-stream guard and polyfill context analyzer.
 */
final class THRG_PC_Context {

    /**
     * Directories that are never scanned (bundled tests, demos, dependencies).
     *
     * @param string $name Single directory segment.
     * @return bool
     */
    public static function skipped_dir( $name ) {
        return in_array( strtolower( (string) $name ), array( 'node_modules', 'test', 'tests', 'example', 'examples', '.git', '.svn' ), true );
    }

    /**
     * Whether a relative file path sits inside a polyfill or back-compat directory.
     *
     * @param string $file Relative path within the component.
     * @return bool
     */
    public static function compat_path( $file ) {
        $segments = explode( '/', strtolower( str_replace( '\\', '/', (string) $file ) ) );
        array_pop( $segments );
        foreach ( $segments as $segment ) {
            if ( false !== strpos( $segment, 'polyfill' ) || preg_match( '/^(?:back[-_]?compat|random_compat|sodium_compat)$/', $segment ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a PHPCompatibility rule only triggers at runtime (and can be guarded).
     *
     * @param string $source PHPCS sniff source code.
     * @return bool
     */
    public static function runtime_rule( $source ) {
        $source = (string) $source;
        foreach ( array( 'FunctionUse.', 'Constants.', 'Classes.RemovedClasses.', 'Classes.ForbiddenDynamicProperties.', 'Extensions.', 'IniDirectives.', 'ParameterValues.', 'MethodUse.' ) as $group ) {
            if ( 0 === strpos( $source, 'PHPCompatibility.' . $group ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a finding reports a removed function, constant, class, or extension.
     *
     * @param array $finding Finding record.
     * @return bool
     */
    public static function removed_finding( array $finding ) {
        if ( 'error' !== ( isset( $finding['type'] ) ? $finding['type'] : '' ) ) {
            return false;
        }
        $source = isset( $finding['source'] ) ? (string) $finding['source'] : '';
        foreach ( array( 'FunctionUse.RemovedFunctions.', 'Constants.RemovedConstants.', 'Classes.RemovedClasses.', 'Extensions.RemovedExtensions.' ) as $group ) {
            if ( 0 === strpos( $source, 'PHPCompatibility.' . $group ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Convert a runtime error/warning finding into a non-counted note.
     *
     * @param array  $finding Finding record.
     * @param string $format  Sprintf format with one %s placeholder.
     * @return array
     */
    public static function as_note( array $finding, $format ) {
        $type   = isset( $finding['type'] ) ? (string) $finding['type'] : '';
        $source = isset( $finding['source'] ) ? (string) $finding['source'] : '';
        if ( ! in_array( $type, array( 'error', 'warning' ), true ) || ! self::runtime_rule( $source ) ) {
            return $finding;
        }
        $finding['type']    = 'note';
        $finding['message'] = sprintf( (string) $format, isset( $finding['message'] ) ? (string) $finding['message'] : '' );
        return $finding;
    }

    /**
     * Attach the relative file path to a finding and downgrade polyfill files to notes.
     *
     * @param array  $finding Finding record.
     * @param string $file    Relative path inside the component.
     * @return array
     */
    public static function place_finding( array $finding, $file ) {
        $finding['file']   = (string) $file;
        $finding['origin'] = THRG_PC_Results::file_origin( $file );
        if ( self::compat_path( $file ) ) {
            $finding = self::as_note(
                $finding,
                /* translators: %s: original compatibility finding message. */
                __( 'Not counted — polyfill or backward-compatibility file: %s', 'thrg-php-checker' )
            );
        }
        return $finding;
    }

    /**
     * Downgrade findings in guarded code to notes.
     *
     * @param array  $findings Raw findings from PHPCS (with line, column, type, message, source).
     * @param array  $tokens   PHPCS token stack for the file.
     * @param string $target   Target PHP version ("8.4" style).
     * @return array Findings with internal column key removed.
     */
    public static function mark_guarded( array $findings, array $tokens, $target ) {
        $target     = (string) $target;
        $at         = array();
        $extensions = array();
        foreach ( $findings as $finding ) {
            $key        = $finding['line'] . ':' . $finding['column'];
            $at[ $key ] = null;
            if ( self::removed_finding( $finding ) && preg_match( "/^Extension '([A-Za-z0-9_]+)'/", (string) $finding['message'], $m ) ) {
                $extensions[ $key ][] = strtolower( rtrim( $m[1], '_' ) );
            }
        }
        if ( '' !== $target && $at ) {
            foreach ( $tokens as $ptr => $token ) {
                $key = $token['line'] . ':' . $token['column'];
                if ( array_key_exists( $key, $at ) && null === $at[ $key ] ) {
                    $at[ $key ] = $ptr;
                }
            }
        }
        $ctx = array(
            'target'    => $target,
            'exits'     => null,
            'cache'     => array(),
            'dead_vars' => null,
        );
        foreach ( $findings as $i => $finding ) {
            $key = $finding['line'] . ':' . $finding['column'];
            unset( $findings[ $i ]['column'] );
            $ptr = isset( $at[ $key ] ) ? $at[ $key ] : null;
            if ( null === $ptr || ! self::runtime_rule( $finding['source'] ) ) {
                continue;
            }
            $removed            = self::removed_finding( $finding );
            $ctx['missing']     = $removed ? array( self::full_name( (string) $tokens[ $ptr ]['content'] ) ) : array();
            $ctx['missing_ext'] = $removed && isset( $extensions[ $key ] ) ? $extensions[ $key ] : array();
            if ( null === $ctx['dead_vars'] && '' !== $target ) {
                $ctx['dead_vars'] = self::collect_dead_branch_vars( $tokens, $ctx );
            }
            if ( self::guarded( $tokens, $ptr, $ctx ) ) {
                $findings[ $i ] = self::as_note(
                    $findings[ $i ],
                    /* translators: %s: original compatibility finding message. */
                    __( 'Not counted — guarded code that does not run on this PHP version: %s', 'thrg-php-checker' )
                );
            }
        }
        return $findings;
    }

    /**
     * Normalize a symbol name (lowercase, no leading backslash).
     *
     * @param string $name Symbol name.
     * @return string
     */
    public static function full_name( $name ) {
        return ltrim( strtolower( trim( (string) $name ) ), '\\' );
    }

    /**
     * Whether token $ptr sits inside a guarded branch or after a version-guarded early return.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $ptr    Flagged token pointer.
     * @param array $ctx    Evaluation context.
     * @return bool
     */
    private static function guarded( array $tokens, $ptr, array &$ctx ) {
        foreach ( array_keys( $tokens[ $ptr ]['conditions'] ) as $owner ) {
            if ( self::branch_guarded( $tokens, $owner, $ctx ) ) {
                return true;
            }
        }

        $start  = self::statement_start( $tokens, $ptr );
        $before = null === $start ? null : self::prev( $tokens, $start - 1 );

        if ( null !== $before ) {
            $type  = $tokens[ $before ]['type'];
            $owner = 'T_CLOSE_PARENTHESIS' === $type && isset( $tokens[ $before ]['parenthesis_owner'] ) ? $tokens[ $before ]['parenthesis_owner'] : null;
            if ( null !== $owner && in_array( $tokens[ $owner ]['type'], array( 'T_IF', 'T_ELSEIF' ), true ) && ! isset( $tokens[ $owner ]['scope_opener'] ) && self::branch_guarded( $tokens, $owner, $ctx ) ) {
                return true;
            }
            if ( 'T_ELSE' === $type && ! isset( $tokens[ $before ]['scope_opener'] ) && self::branch_guarded( $tokens, $before, $ctx ) ) {
                return true;
            }
        }

        $ops        = array( 'T_INLINE_THEN', 'T_BOOLEAN_AND', 'T_LOGICAL_AND', 'T_BOOLEAN_OR', 'T_LOGICAL_OR' );
        $ptr_parens = isset( $tokens[ $ptr ]['nested_parenthesis'] ) ? $tokens[ $ptr ]['nested_parenthesis'] : array();
        for ( $op = null === $start ? $ptr : $start; $op < $ptr; $op++ ) {
            $close = self::group_end( $tokens, $op );
            if ( null !== $close && $close < $ptr ) {
                $op = $close;
                continue;
            }
            $op_parens = isset( $tokens[ $op ]['nested_parenthesis'] ) ? $tokens[ $op ]['nested_parenthesis'] : array();
            if ( ! in_array( $tokens[ $op ]['type'], $ops, true ) || array_diff_key( $op_parens, $ptr_parens ) ) {
                continue;
            }
            $runs_when = self::operand_runs_when( $tokens, $op, $ptr );
            if ( null !== $runs_when && self::condition_guards( $tokens, self::operand_start( $tokens, $op, $start ), $op - 1, $runs_when, $ctx ) ) {
                return true;
            }
        }

        if ( null === $ctx['exits'] ) {
            $ctx['exits'] = self::exit_ifs( $tokens );
        }
        $declarations = array( 'T_FUNCTION', 'T_CLOSURE', 'T_FN', 'T_CLASS', 'T_ANON_CLASS', 'T_TRAIT', 'T_INTERFACE', 'T_ENUM' );
        $scopes       = array_values( array_keys( $tokens[ $ptr ]['conditions'] ) );
        $count        = count( $scopes );
        foreach ( array_keys( $ptr_parens ) as $paren ) {
            if ( isset( $tokens[ $paren ]['parenthesis_owner'] ) && in_array( $tokens[ $tokens[ $paren ]['parenthesis_owner'] ]['type'], $declarations, true ) ) {
                $scopes = array_merge( $scopes, array( $tokens[ $paren ]['parenthesis_owner'] ) );
                break;
            }
        }
        for ( $depth = count( $scopes ); $depth >= 0; $depth-- ) {
            if ( $depth < count( $scopes ) && in_array( $tokens[ $scopes[ $depth ] ]['type'], $declarations, true ) ) {
                break;
            }
            if ( $depth > $count ) {
                continue;
            }
            $scope_key = implode( ',', array_slice( $scopes, 0, $depth ) );
            $scope_ifs = isset( $ctx['exits'][ $scope_key ] ) ? $ctx['exits'][ $scope_key ] : array();
            foreach ( $scope_ifs as $if_ptr => $end ) {
                if ( $if_ptr >= $ptr ) {
                    break;
                }
                if ( $end < $ptr && self::condition_guards( $tokens, $tokens[ $if_ptr ]['parenthesis_opener'] + 1, $tokens[ $if_ptr ]['parenthesis_closer'] - 1, false, $ctx ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Collect all if statements in the file that unconditionally return or rethrow.
     *
     * @param array $tokens PHPCS tokens.
     * @return array<string, array<int, int>>
     */
    private static function exit_ifs( array $tokens ) {
        $out = array();
        foreach ( $tokens as $ptr => $token ) {
            if ( 'T_IF' !== $token['type'] || ! isset( $token['parenthesis_opener'], $token['parenthesis_closer'] ) ) {
                continue;
            }
            $prev = self::prev( $tokens, $ptr - 1 );
            if ( null !== $prev && ! in_array( $tokens[ $prev ]['type'], array( 'T_SEMICOLON', 'T_OPEN_CURLY_BRACKET', 'T_CLOSE_CURLY_BRACKET', 'T_OPEN_TAG', 'T_CLOSE_TAG', 'T_INLINE_HTML', 'T_COLON' ), true ) ) {
                continue;
            }
            $end = self::exit_end( $tokens, $ptr );
            if ( null !== $end ) {
                $out[ implode( ',', array_keys( $token['conditions'] ) ) ][ $ptr ] = $end;
            }
        }
        return $out;
    }

    /**
     * End token of an if statement whose body always leaves its scope.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $if_ptr T_IF pointer.
     * @return int|null
     */
    private static function exit_end( array $tokens, $if_ptr ) {
        $caught = self::caught_variable( $tokens, $if_ptr );
        if ( ! isset( $tokens[ $if_ptr ]['scope_opener'], $tokens[ $if_ptr ]['scope_closer'] ) ) {
            $next = self::next( $tokens, $tokens[ $if_ptr ]['parenthesis_closer'] + 1 );
            if ( null === $next || ! self::leaves( $tokens, $next, $caught ) ) {
                return null;
            }
            $count = count( $tokens );
            for ( $i = $next; $i < $count; $i++ ) {
                if ( in_array( $tokens[ $i ]['type'], array( 'T_SEMICOLON', 'T_CLOSE_TAG' ), true ) && $tokens[ $i ]['level'] === $tokens[ $next ]['level'] ) {
                    return $i;
                }
            }
            return null;
        }
        $opener = $tokens[ $if_ptr ]['scope_opener'];
        $level  = $tokens[ $opener ]['level'] + 1;
        $parens = isset( $tokens[ $if_ptr ]['nested_parenthesis'] ) ? $tokens[ $if_ptr ]['nested_parenthesis'] : array();
        for ( $i = $opener + 1; $i < $tokens[ $if_ptr ]['scope_closer']; $i++ ) {
            $i_parens = isset( $tokens[ $i ]['nested_parenthesis'] ) ? $tokens[ $i ]['nested_parenthesis'] : array();
            if ( $tokens[ $i ]['level'] !== $level || ! self::leaves( $tokens, $i, $caught ) || $i_parens !== $parens ) {
                continue;
            }
            $prev = self::prev( $tokens, $i - 1 );
            if ( $opener === $prev || in_array( $tokens[ $prev ]['type'], array( 'T_SEMICOLON', 'T_CLOSE_CURLY_BRACKET', 'T_OPEN_TAG', 'T_CLOSE_TAG', 'T_INLINE_HTML' ), true ) ) {
                return $tokens[ $if_ptr ]['scope_closer'];
            }
        }
        return null;
    }

    /**
     * Variable name caught by the innermost catch block surrounding $ptr, or null.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $ptr    Token pointer.
     * @return string|null
     */
    private static function caught_variable( array $tokens, $ptr ) {
        foreach ( array_reverse( array_keys( $tokens[ $ptr ]['conditions'] ), false ) as $owner ) {
            if ( 'T_CATCH' !== $tokens[ $owner ]['type'] ) {
                continue;
            }
            if ( ! isset( $tokens[ $owner ]['parenthesis_opener'], $tokens[ $owner ]['parenthesis_closer'] ) ) {
                return null;
            }
            for ( $i = $tokens[ $owner ]['parenthesis_opener'] + 1; $i < $tokens[ $owner ]['parenthesis_closer']; $i++ ) {
                if ( 'T_VARIABLE' === $tokens[ $i ]['type'] ) {
                    return $tokens[ $i ]['content'];
                }
            }
            return null;
        }
        return null;
    }

    /**
     * Whether $ptr starts a return statement or rethrows the caught exception variable.
     *
     * @param array       $tokens PHPCS tokens.
     * @param int         $ptr    Token pointer.
     * @param string|null $caught Caught variable name.
     * @return bool
     */
    private static function leaves( array $tokens, $ptr, $caught ) {
        if ( 'T_RETURN' === $tokens[ $ptr ]['type'] ) {
            return true;
        }
        if ( null === $caught || 'T_THROW' !== $tokens[ $ptr ]['type'] ) {
            return false;
        }
        $var  = self::next( $tokens, $ptr + 1 );
        $semi = null === $var ? null : self::next( $tokens, $var + 1 );
        return null !== $semi && 'T_VARIABLE' === $tokens[ $var ]['type'] && $caught === $tokens[ $var ]['content'] && 'T_SEMICOLON' === $tokens[ $semi ]['type'];
    }

    /**
     * Whether an if, elseif, or else branch is guarded against running on the target PHP version.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $owner  Branch token pointer.
     * @param array $ctx    Evaluation context.
     * @return bool
     */
    private static function branch_guarded( array $tokens, $owner, array &$ctx ) {
        $type = $tokens[ $owner ]['type'];
        if ( ! in_array( $type, array( 'T_IF', 'T_ELSEIF', 'T_ELSE' ), true ) ) {
            return false;
        }
        if ( 'T_ELSE' !== $type && isset( $tokens[ $owner ]['parenthesis_closer'] ) ) {
            if ( self::condition_guards( $tokens, $tokens[ $owner ]['parenthesis_opener'] + 1, $tokens[ $owner ]['parenthesis_closer'] - 1, true, $ctx ) ) {
                return true;
            }
        }
        foreach ( self::earlier_conditions( $tokens, $owner ) as $earlier ) {
            if ( self::condition_guards( $tokens, $tokens[ $earlier ]['parenthesis_opener'] + 1, $tokens[ $earlier ]['parenthesis_closer'] - 1, false, $ctx ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Earlier if/elseif branches in the same chain before $owner.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $owner  Branch token pointer.
     * @return int[]
     */
    private static function earlier_conditions( array $tokens, $owner ) {
        $out  = array();
        $at   = $owner;
        $seen = 0;
        while ( $seen++ < 100 ) {
            if ( 'T_IF' === $tokens[ $at ]['type'] ) {
                $else = self::prev( $tokens, $at - 1 );
                if ( null === $else || 'T_ELSE' !== $tokens[ $else ]['type'] || isset( $tokens[ $else ]['scope_opener'] ) ) {
                    break;
                }
                $at = $else;
            }
            $close = self::prev( $tokens, $at - 1 );
            if ( null === $close || 'T_CLOSE_CURLY_BRACKET' !== $tokens[ $close ]['type'] || ! isset( $tokens[ $close ]['scope_condition'] ) ) {
                break;
            }
            $at = $tokens[ $close ]['scope_condition'];
            if ( ! in_array( $tokens[ $at ]['type'], array( 'T_IF', 'T_ELSEIF' ), true ) || ! isset( $tokens[ $at ]['parenthesis_closer'] ) ) {
                break;
            }
            $out[] = $at;
        }
        return $out;
    }

    /**
     * Determine whether $ptr sits in the right operand of a ternary, &&, or ||.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $op     Operator pointer.
     * @param int   $ptr    Flagged token pointer.
     * @return bool|null
     */
    private static function operand_runs_when( array $tokens, $op, $ptr ) {
        $type   = $tokens[ $op ]['type'];
        $parens = isset( $tokens[ $op ]['nested_parenthesis'] ) ? $tokens[ $op ]['nested_parenthesis'] : array();
        $stops  = array( 'T_COMMA', 'T_SEMICOLON', 'T_DOUBLE_ARROW', 'T_MATCH_ARROW', 'T_CLOSE_SQUARE_BRACKET', 'T_CLOSE_SHORT_ARRAY', 'T_OPEN_TAG', 'T_OPEN_TAG_WITH_ECHO', 'T_CLOSE_TAG', 'T_INLINE_THEN', 'T_LOGICAL_AND', 'T_LOGICAL_OR', 'T_LOGICAL_XOR' );
        if ( 'T_INLINE_THEN' !== $type ) {
            $stops = array_merge( $stops, array( 'T_INLINE_ELSE', 'T_COALESCE' ) );
        }
        if ( 'T_BOOLEAN_AND' === $type || 'T_LOGICAL_AND' === $type ) {
            $stops[] = 'T_BOOLEAN_OR';
        }
        $runs_when = 'T_BOOLEAN_OR' !== $type && 'T_LOGICAL_OR' !== $type;
        $in_else   = false;
        for ( $i = $op + 1; $i < $ptr; $i++ ) {
            $close = self::group_end( $tokens, $i );
            if ( null !== $close && $close < $ptr ) {
                $i = $close;
                continue;
            }
            $i_parens = isset( $tokens[ $i ]['nested_parenthesis'] ) ? $tokens[ $i ]['nested_parenthesis'] : array();
            if ( $i_parens !== $parens ) {
                continue;
            }
            $t = $tokens[ $i ]['type'];
            if ( 'T_INLINE_THEN' === $type && 'T_INLINE_ELSE' === $t ) {
                if ( $in_else ) {
                    return null;
                }
                $in_else   = true;
                $runs_when = false;
                continue;
            }
            if ( in_array( $t, $stops, true ) ) {
                return null;
            }
        }
        return $runs_when;
    }

    /**
     * Closing token pointer for a parenthesis, bracket, or brace group opening at $i.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $i      Token pointer.
     * @return int|null
     */
    private static function group_end( array $tokens, $i ) {
        $type = $tokens[ $i ]['type'];
        if ( 'T_OPEN_PARENTHESIS' === $type && isset( $tokens[ $i ]['parenthesis_closer'] ) ) {
            return $tokens[ $i ]['parenthesis_closer'];
        }
        if ( in_array( $type, array( 'T_OPEN_SQUARE_BRACKET', 'T_OPEN_SHORT_ARRAY', 'T_OPEN_CURLY_BRACKET' ), true ) && isset( $tokens[ $i ]['bracket_closer'] ) ) {
            return $tokens[ $i ]['bracket_closer'];
        }
        return null;
    }

    /**
     * Whether a condition from $from to $to prevents code that runs on $runs_when from executing on the target.
     *
     * @param array $tokens    PHPCS tokens.
     * @param int   $from      Start token pointer.
     * @param int   $to        End token pointer.
     * @param bool  $runs_when Truth value required for the branch to run.
     * @param array $ctx       Evaluation context.
     * @return bool
     */
    private static function condition_guards( array $tokens, $from, $to, $runs_when, array &$ctx ) {
        $key = $from . ':' . $to . ':' . implode( ',', $ctx['missing'] ) . ':' . implode( ',', $ctx['missing_ext'] );
        if ( ! array_key_exists( $key, $ctx['cache'] ) ) {
            $order = array();
            for ( $i = $from; $i <= $to; $i++ ) {
                if ( ! isset( \PHP_CodeSniffer\Util\Tokens::EMPTY_TOKENS[ $tokens[ $i ]['code'] ] ) ) {
                    $order[] = $i;
                }
            }
            $pos                  = 0;
            $value                = $order ? self::eval_or( $tokens, $order, $pos, $ctx ) : null;
            $ctx['cache'][ $key ] = count( $order ) === $pos ? $value : null;
        }
        return null !== $ctx['cache'][ $key ] && $runs_when !== $ctx['cache'][ $key ];
    }

    /**
     * Evaluate a || chain over non-empty token pointers.
     *
     * @param array $tokens PHPCS tokens.
     * @param int[] $order  Non-empty token pointers.
     * @param int   $pos    Current index in $order.
     * @param array $ctx    Evaluation context.
     * @return bool|null
     */
    private static function eval_or( array $tokens, array $order, &$pos, array $ctx ) {
        $value = self::eval_and( $tokens, $order, $pos, $ctx );
        while ( isset( $order[ $pos ] ) && in_array( $tokens[ $order[ $pos ] ]['type'], array( 'T_BOOLEAN_OR', 'T_LOGICAL_OR' ), true ) ) {
            ++$pos;
            $right = self::eval_and( $tokens, $order, $pos, $ctx );
            $value = ( true === $value || true === $right ) ? true : ( ( false === $value && false === $right ) ? false : null );
        }
        return $value;
    }

    /**
     * Evaluate a && chain over non-empty token pointers.
     *
     * @param array $tokens PHPCS tokens.
     * @param int[] $order  Non-empty token pointers.
     * @param int   $pos    Current index in $order.
     * @param array $ctx    Evaluation context.
     * @return bool|null
     */
    private static function eval_and( array $tokens, array $order, &$pos, array $ctx ) {
        $value = self::eval_not( $tokens, $order, $pos, $ctx );
        while ( isset( $order[ $pos ] ) && in_array( $tokens[ $order[ $pos ] ]['type'], array( 'T_BOOLEAN_AND', 'T_LOGICAL_AND' ), true ) ) {
            ++$pos;
            $right = self::eval_not( $tokens, $order, $pos, $ctx );
            $value = ( false === $value || false === $right ) ? false : ( ( true === $value && true === $right ) ? true : null );
        }
        return $value;
    }

    /**
     * Evaluate negation, parenthesized group, or atomic condition.
     *
     * @param array $tokens PHPCS tokens.
     * @param int[] $order  Non-empty token pointers.
     * @param int   $pos    Current index in $order.
     * @param array $ctx    Evaluation context.
     * @return bool|null
     */
    private static function eval_not( array $tokens, array $order, &$pos, array $ctx ) {
        if ( ! isset( $order[ $pos ] ) ) {
            return null;
        }
        $i = $order[ $pos ];
        if ( 'T_BOOLEAN_NOT' === $tokens[ $i ]['type'] ) {
            ++$pos;
            $value = self::eval_not( $tokens, $order, $pos, $ctx );
            return null === $value ? null : ! $value;
        }
        if ( 'T_OPEN_PARENTHESIS' === $tokens[ $i ]['type'] && ! isset( $tokens[ $i ]['parenthesis_owner'] ) && isset( $tokens[ $i ]['parenthesis_closer'] ) ) {
            $close = array_search( $tokens[ $i ]['parenthesis_closer'], $order, true );
            if ( false !== $close ) {
                ++$pos;
                $value = self::eval_or( $tokens, $order, $pos, $ctx );
                if ( $pos !== $close ) {
                    $value = null;
                }
                $pos = $close + 1;
                return $value;
            }
        }
        $atom = array();
        while ( isset( $order[ $pos ] ) && ! in_array( $tokens[ $order[ $pos ] ]['type'], array( 'T_BOOLEAN_AND', 'T_LOGICAL_AND', 'T_BOOLEAN_OR', 'T_LOGICAL_OR', 'T_CLOSE_PARENTHESIS' ), true ) ) {
            $k      = $order[ $pos ];
            $atom[] = $k;
            ++$pos;
            if ( 'T_OPEN_PARENTHESIS' === $tokens[ $k ]['type'] && isset( $tokens[ $k ]['parenthesis_closer'] ) ) {
                while ( isset( $order[ $pos ] ) && $order[ $pos ] <= $tokens[ $k ]['parenthesis_closer'] ) {
                    $atom[] = $order[ $pos ];
                    ++$pos;
                }
            }
        }
        $exists = self::eval_exists( $tokens, $atom, $ctx );
        return null !== $exists ? $exists : self::eval_version( $tokens, $atom, $ctx['target'] );
    }

    /**
     * Evaluate existence checks (function_exists, class_exists, interface_exists, trait_exists, enum_exists, defined, extension_loaded, is_callable).
     *
     * @param array $tokens PHPCS tokens.
     * @param int[] $atom   Token pointers of the operand.
     * @param array $ctx    Evaluation context.
     * @return bool|null
     */
    private static function eval_exists( array $tokens, array $atom, array $ctx ) {
        $closer = isset( $atom[1], $tokens[ $atom[1] ]['parenthesis_closer'] ) ? $tokens[ $atom[1] ]['parenthesis_closer'] : null;
        if ( 4 === count( $atom ) && 'T_ISSET' === $tokens[ $atom[0] ]['type'] && 'T_OPEN_PARENTHESIS' === $tokens[ $atom[1] ]['type'] && 'T_VARIABLE' === $tokens[ $atom[2] ]['type'] && $closer === $atom[3] ) {
            $scope_id = self::var_scope_id( $tokens, $atom[2] );
            $var_name = (string) $tokens[ $atom[2] ]['content'];
            if ( ! empty( $ctx['dead_vars'][ $scope_id ][ $var_name ] ) ) {
                return false;
            }
            return null;
        }
        if ( 4 !== count( $atom ) || 'T_OPEN_PARENTHESIS' !== $tokens[ $atom[1] ]['type'] || 'T_CONSTANT_ENCAPSED_STRING' !== $tokens[ $atom[2] ]['type'] || $closer !== $atom[3] ) {
            return null;
        }
        $call = self::full_name( (string) $tokens[ $atom[0] ]['content'] );
        $name = self::full_name( str_replace( '\\\\', '\\', substr( (string) $tokens[ $atom[2] ]['content'], 1, -1 ) ) );
        if ( '' === $name ) {
            return null;
        }
        if ( 'function_exists' === $call ) {
            $since = self::builtin_function_version( $name );
            if ( null !== $since && '' !== $ctx['target'] && version_compare( (string) $ctx['target'], $since, '>=' ) ) {
                return true;
            }
        }
        if ( 'extension_loaded' === $call ) {
            return in_array( $name, $ctx['missing_ext'], true ) ? false : null;
        }
        if ( in_array( $call, array( 'function_exists', 'is_callable', 'class_exists', 'interface_exists', 'trait_exists', 'enum_exists', 'defined' ), true ) ) {
            return in_array( $name, $ctx['missing'], true ) ? false : null;
        }
        return null;
    }

    /**
     * PHP version where common core functions checked via function_exists() became guaranteed built-ins.
     *
     * @param string $name Normalized lowercase function name.
     * @return string|null PHP major.minor version or null if optional/extension-dependent.
     */
    private static function builtin_function_version( $name ) {
        static $builtins = array(
            'error_clear_last'            => '7.0',
            'intdiv'                      => '7.0',
            'preg_replace_callback_array' => '7.0',
            'random_bytes'                => '7.0',
            'random_int'                  => '7.0',
            'is_iterable'                 => '7.1',
            'array_key_first'             => '7.3',
            'array_key_last'              => '7.3',
            'is_countable'                => '7.3',
            'str_contains'                => '8.0',
            'str_ends_with'               => '8.0',
            'str_starts_with'             => '8.0',
            'fdiv'                        => '8.0',
            'get_debug_type'              => '8.0',
            'array_is_list'               => '8.1',
            'enum_exists'                 => '8.1',
        );
        return isset( $builtins[ $name ] ) ? $builtins[ $name ] : null;
    }

    /**
     * Enclosing function/closure/method scope key for variable tracking.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $ptr    Token pointer.
     * @return string
     */
    private static function var_scope_id( array $tokens, $ptr ) {
        if ( empty( $tokens[ $ptr ]['conditions'] ) ) {
            return 'global';
        }
        foreach ( array_reverse( $tokens[ $ptr ]['conditions'], true ) as $owner => $code ) {
            if ( in_array( $tokens[ $owner ]['type'], array( 'T_FUNCTION', 'T_CLOSURE', 'T_FN' ), true ) ) {
                return (string) $owner;
            }
        }
        return 'global';
    }

    /**
     * Collect variables that are only ever assigned inside guarded branches (unreachable on target PHP).
     *
     * @param array $tokens PHPCS tokens.
     * @param array $ctx    Evaluation context.
     * @return array<string, array<string, bool>>
     */
    private static function collect_dead_branch_vars( array $tokens, array $ctx ) {
        $guarded_only = array();
        $live         = array();
        $count        = count( $tokens );
        $local_ctx    = array(
            'target'      => $ctx['target'],
            'exits'       => $ctx['exits'],
            'cache'       => array(),
            'missing'     => array(),
            'missing_ext' => array(),
            'dead_vars'   => array(),
        );
        for ( $i = 0; $i < $count; $i++ ) {
            if ( 'T_VARIABLE' !== $tokens[ $i ]['type'] ) {
                continue;
            }
            $next = self::next( $tokens, $i + 1 );
            if ( null === $next || 'T_EQUAL' !== $tokens[ $next ]['type'] ) {
                continue;
            }
            $scope_id = self::var_scope_id( $tokens, $i );
            $var_name = (string) $tokens[ $i ]['content'];
            if ( self::guarded( $tokens, $i, $local_ctx ) ) {
                $guarded_only[ $scope_id ][ $var_name ] = true;
            } else {
                $live[ $scope_id ][ $var_name ] = true;
            }
        }
        $out = array();
        foreach ( $guarded_only as $scope_id => $vars ) {
            foreach ( $vars as $var_name => $flag ) {
                if ( empty( $live[ $scope_id ][ $var_name ] ) ) {
                    $out[ $scope_id ][ $var_name ] = true;
                }
            }
        }
        return $out;
    }

    /**
     * Evaluate a PHP version comparison against the target PHP version.
     *
     * @param array  $tokens PHPCS tokens.
     * @param int[]  $atom   Token pointers of the operand.
     * @param string $target Target PHP version ("8.4" style).
     * @return bool|null
     */
    private static function eval_version( array $tokens, array $atom, $target ) {
        $allowed = array( 'T_STRING', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED', 'T_NAME_RELATIVE', 'T_VARIABLE', 'T_LNUMBER', 'T_DNUMBER', 'T_CONSTANT_ENCAPSED_STRING', 'T_OPEN_PARENTHESIS', 'T_CLOSE_PARENTHESIS', 'T_COMMA', 'T_OBJECT_OPERATOR', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_DOUBLE_COLON', 'T_SELF', 'T_STATIC', 'T_PARENT', 'T_OPEN_SQUARE_BRACKET', 'T_CLOSE_SQUARE_BRACKET', 'T_LESS_THAN', 'T_GREATER_THAN', 'T_IS_SMALLER_OR_EQUAL', 'T_IS_GREATER_OR_EQUAL', 'T_IS_EQUAL', 'T_IS_IDENTICAL', 'T_IS_NOT_EQUAL', 'T_IS_NOT_IDENTICAL' );
        $text    = array();
        foreach ( $atom as $i ) {
            if ( ! in_array( $tokens[ $i ]['type'], $allowed, true ) ) {
                return null;
            }
            $text[] = in_array( $tokens[ $i ]['type'], array( 'T_STRING', 'T_NAME_FULLY_QUALIFIED' ), true ) ? strtoupper( ltrim( (string) $tokens[ $i ]['content'], '\\' ) ) : (string) $tokens[ $i ]['content'];
        }
        if ( ! self::names_version( $text ) || ! preg_match( '/^(\d+)\.(\d+)$/', (string) $target, $m ) ) {
            return null;
        }
        $low  = self::version_answer( $text, (int) $m[1], (int) $m[2], 0 );
        $high = self::version_answer( $text, (int) $m[1], (int) $m[2], 99 );
        return null !== $low && $low === $high ? $low : null;
    }

    /**
     * Whether token texts reference a PHP version constant or phpversion().
     *
     * @param string[] $text Uppercased token texts.
     * @return bool
     */
    private static function names_version( array $text ) {
        return (bool) array_intersect( $text, array( 'PHP_VERSION', 'PHP_VERSION_ID', 'PHP_MAJOR_VERSION', 'PHP_MINOR_VERSION', 'PHP_RELEASE_VERSION', 'PHPVERSION' ) );
    }

    /**
     * Evaluate a version expression for a concrete major.minor.patch tuple.
     *
     * @param string[] $text  Uppercased token texts.
     * @param int      $major Major version.
     * @param int      $minor Minor version.
     * @param int      $patch Patch version.
     * @return bool|null
     */
    private static function version_answer( array $text, $major, $minor, $patch ) {
        $ints  = array(
            'PHP_VERSION_ID'      => array( $major * 10000 + $minor * 100 + $patch, $major * 10000 + $minor * 100 - 1 ),
            'PHP_MAJOR_VERSION'   => array( $major, $major - 1 ),
            'PHP_MINOR_VERSION'   => array( $minor, $minor - 1 ),
            'PHP_RELEASE_VERSION' => array( $patch, -1 ),
        );
        $now   = $major . '.' . $minor . '.' . $patch;
        $older = $major . '.' . $minor . '.0-dev';
        $ops   = array( '<', '<=', '>', '>=', '==', '===', '!=', '!==', '<>' );

        $at    = null;
        $depth = 0;
        foreach ( $text as $k => $part ) {
            $depth += ( '(' === $part ? 1 : 0 ) - ( ')' === $part ? 1 : 0 );
            if ( 0 === $depth && in_array( $part, $ops, true ) ) {
                if ( null !== $at ) {
                    return null;
                }
                $at = $k;
            }
        }
        if ( null === $at ) {
            $call = self::version_call( $text, $now, $older );
            return null !== $call && null !== $call[2] ? version_compare( $call[0], $call[1], $call[2] ) : null;
        }

        $op    = $text[ $at ];
        $sides = array( array_slice( $text, 0, $at ), array_slice( $text, $at + 1 ) );
        foreach ( array( 0, 1 ) as $s ) {
            $mine  = $sides[ $s ];
            $other = $sides[ 1 - $s ];
            $flip  = 1 === $s;
            if ( 1 === count( $mine ) && isset( $ints[ $mine[0] ] ) ) {
                $bound = self::int_bound( $other, $ints[ $mine[0] ][1] );
                if ( null === $bound ) {
                    return null;
                }
                return $flip ? self::compare( $bound, $ints[ $mine[0] ][0], $op ) : self::compare( $ints[ $mine[0] ][0], $bound, $op );
            }
            $joined = implode( '', $mine );
            if ( 'PHP_VERSION' === $joined || 'PHPVERSION()' === $joined ) {
                $bound = self::version_bound( $other, $now, $older );
                if ( null === $bound ) {
                    return null;
                }
                return $flip ? self::compare( version_compare( $bound, $now ), 0, $op ) : self::compare( version_compare( $now, $bound ), 0, $op );
            }
            $call = self::version_call( $mine, $now, $older );
            if ( null !== $call && null === $call[2] && 1 === count( $other ) && preg_match( '/^-?\d+$/', $other[0] ) ) {
                $value = version_compare( $call[0], $call[1] );
                return $flip ? self::compare( (int) $other[0], $value, $op ) : self::compare( $value, (int) $other[0], $op );
            }
        }
        return null;
    }

    /**
     * Resolve an integer version bound.
     *
     * @param string[] $part  Bound tokens.
     * @param int      $older Fallback integer older than target.
     * @return int|null
     */
    private static function int_bound( array $part, $older ) {
        if ( 1 === count( $part ) && preg_match( '/^([\'"]?)(\d[\d_]*)\1$/', $part[0], $m ) ) {
            return (int) str_replace( '_', '', $m[2] );
        }
        if ( 1 === count( $part ) && preg_match( '/^[\'"\d]/', $part[0] ) ) {
            return null;
        }
        return $part && ! self::names_version( $part ) ? (int) $older : null;
    }

    /**
     * Resolve a string version bound.
     *
     * @param string[] $part  Bound tokens.
     * @param string   $now   Target version string.
     * @param string   $older Fallback version older than target.
     * @return string|null
     */
    private static function version_bound( array $part, $now, $older ) {
        $joined = implode( '', $part );
        if ( 'PHP_VERSION' === $joined || 'PHPVERSION()' === $joined ) {
            return (string) $now;
        }
        if ( preg_match( '/^([\'"]?)(\d[0-9A-Za-z.+\-]*)\1$/', $joined, $m ) ) {
            return $m[2];
        }
        if ( 1 === count( $part ) && preg_match( '/^[\'"]/', $part[0] ) ) {
            return null;
        }
        return $part && ! self::names_version( $part ) ? (string) $older : null;
    }

    /**
     * Parse a version_compare() call.
     *
     * @param string[] $text  Call tokens.
     * @param string   $now   Target version string.
     * @param string   $older Fallback version older than target.
     * @return array{0:string,1:string,2:string|null}|null
     */
    private static function version_call( array $text, $now, $older ) {
        if ( count( $text ) < 6 || 'VERSION_COMPARE' !== $text[0] || '(' !== $text[1] || ')' !== end( $text ) ) {
            return null;
        }
        $args  = array( array() );
        $depth = 0;
        $last  = count( $text ) - 1;
        for ( $k = 2; $k < $last; $k++ ) {
            $depth += ( '(' === $text[ $k ] ? 1 : 0 ) - ( ')' === $text[ $k ] ? 1 : 0 );
            if ( $depth < 0 ) {
                return null;
            }
            if ( ',' === $text[ $k ] && 0 === $depth ) {
                $args[] = array();
            } else {
                $args[ count( $args ) - 1 ][] = $text[ $k ];
            }
        }
        if ( count( $args ) < 2 || count( $args ) > 3 ) {
            return null;
        }
        $a = self::version_bound( $args[0], $now, $older );
        $b = self::version_bound( $args[1], $now, $older );
        if ( null === $a || null === $b || ( $now === $a ) === ( $now === $b ) ) {
            return null;
        }
        $op = null;
        if ( 3 === count( $args ) ) {
            if ( ! preg_match( '/^([\'"])(<|lt|<=|le|>|gt|>=|ge|==|eq|!=|<>|ne)\1$/', implode( '', $args[2] ), $m ) ) {
                return null;
            }
            $op = $m[2];
        }
        return array( $a, $b, $op );
    }

    /**
     * Compare two integers with a PHP comparison operator.
     *
     * @param int    $left  Left operand.
     * @param int    $right Right operand.
     * @param string $op    Comparison operator.
     * @return bool
     */
    private static function compare( $left, $right, $op ) {
        switch ( $op ) {
            case '<':
                return $left < $right;
            case '<=':
                return $left <= $right;
            case '>':
                return $left > $right;
            case '>=':
                return $left >= $right;
            case '==':
            case '===':
                return $left === $right;
            default:
                return $left !== $right;
        }
    }

    /**
     * Find the start token of the statement containing $ptr.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $ptr    Token pointer.
     * @return int|null
     */
    private static function statement_start( array $tokens, $ptr ) {
        $controls = array( 'T_IF', 'T_ELSEIF', 'T_WHILE', 'T_FOR', 'T_FOREACH', 'T_SWITCH' );
        $stops    = array( 'T_SEMICOLON', 'T_OPEN_CURLY_BRACKET', 'T_CLOSE_CURLY_BRACKET', 'T_OPEN_TAG', 'T_OPEN_TAG_WITH_ECHO', 'T_CLOSE_TAG', 'T_INLINE_HTML', 'T_COLON', 'T_ELSE', 'T_DO' );
        $steps    = 0;
        for ( $i = $ptr - 1; $i >= 0; $i-- ) {
            if ( ++$steps > 400 ) {
                return null;
            }
            $type = $tokens[ $i ]['type'];
            if ( in_array( $type, $stops, true ) ) {
                break;
            }
            if ( 'T_CLOSE_PARENTHESIS' === $type && isset( $tokens[ $i ]['parenthesis_opener'] ) ) {
                if ( isset( $tokens[ $i ]['parenthesis_owner'] ) && in_array( $tokens[ $tokens[ $i ]['parenthesis_owner'] ]['type'], $controls, true ) ) {
                    break;
                }
                $i = $tokens[ $i ]['parenthesis_opener'];
            } elseif ( in_array( $type, array( 'T_CLOSE_SQUARE_BRACKET', 'T_CLOSE_SHORT_ARRAY' ), true ) && isset( $tokens[ $i ]['bracket_opener'] ) ) {
                $i = $tokens[ $i ]['bracket_opener'];
            }
        }
        $start = self::next( $tokens, $i + 1 );
        return null === $start ? $ptr : min( $start, $ptr );
    }

    /**
     * Find the first token of the left operand of a ternary, &&, or ||.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $op     Operator pointer.
     * @param int   $start  Statement start pointer.
     * @return int
     */
    private static function operand_start( array $tokens, $op, $start ) {
        $stops = array( 'T_RETURN', 'T_ECHO', 'T_PRINT', 'T_COMMA', 'T_OPEN_PARENTHESIS', 'T_DOUBLE_ARROW', 'T_FN_ARROW', 'T_MATCH_ARROW', 'T_INLINE_THEN', 'T_INLINE_ELSE', 'T_OPEN_SQUARE_BRACKET', 'T_OPEN_SHORT_ARRAY', 'T_LOGICAL_AND', 'T_LOGICAL_OR', 'T_LOGICAL_XOR' );
        if ( 'T_INLINE_THEN' !== $tokens[ $op ]['type'] ) {
            $stops[] = 'T_COALESCE';
        }
        if ( in_array( $tokens[ $op ]['type'], array( 'T_BOOLEAN_AND', 'T_LOGICAL_AND' ), true ) ) {
            $stops[] = 'T_BOOLEAN_OR';
        }
        for ( $i = $op - 1; $i >= $start; $i-- ) {
            $type = $tokens[ $i ]['type'];
            if ( 'T_CLOSE_PARENTHESIS' === $type && isset( $tokens[ $i ]['parenthesis_opener'] ) ) {
                $i = $tokens[ $i ]['parenthesis_opener'];
                continue;
            }
            if ( in_array( $type, array( 'T_CLOSE_SQUARE_BRACKET', 'T_CLOSE_SHORT_ARRAY' ), true ) && isset( $tokens[ $i ]['bracket_opener'] ) ) {
                $i = $tokens[ $i ]['bracket_opener'];
                continue;
            }
            if ( in_array( $type, $stops, true ) || isset( \PHP_CodeSniffer\Util\Tokens::ASSIGNMENT_TOKENS[ $tokens[ $i ]['code'] ] ) ) {
                return $i + 1;
            }
        }
        return $start;
    }

    /**
     * Previous non-empty token pointer at or before $i.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $i      Start index.
     * @return int|null
     */
    private static function prev( array $tokens, $i ) {
        for ( ; $i >= 0; $i-- ) {
            if ( ! isset( \PHP_CodeSniffer\Util\Tokens::EMPTY_TOKENS[ $tokens[ $i ]['code'] ] ) ) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Next non-empty token pointer at or after $i.
     *
     * @param array $tokens PHPCS tokens.
     * @param int   $i      Start index.
     * @return int|null
     */
    private static function next( array $tokens, $i ) {
        for ( $count = count( $tokens ); $i < $count; $i++ ) {
            if ( ! isset( \PHP_CodeSniffer\Util\Tokens::EMPTY_TOKENS[ $tokens[ $i ]['code'] ] ) ) {
                return $i;
            }
        }
        return null;
    }

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
    public static function dynamic_property_findings( array $tokens, $target ) {
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
                $op_ptr = self::next( $tokens, $i + 1 );
                if ( null === $op_ptr || ! in_array( $tokens[ $op_ptr ]['type'], array( 'T_OBJECT_OPERATOR', 'T_NULLSAFE_OBJECT_OPERATOR' ), true ) ) {
                    continue;
                }
                $prop_ptr = self::next( $tokens, $op_ptr + 1 );
                if ( null === $prop_ptr || 'T_STRING' !== $tokens[ $prop_ptr ]['type'] ) {
                    continue;
                }
                $after_ptr = self::next( $tokens, $prop_ptr + 1 );
                if ( null === $after_ptr || 'T_OPEN_PARENTHESIS' === $tokens[ $after_ptr ]['type'] ) {
                    continue;
                }
                while ( null !== $after_ptr && 'T_OPEN_SQUARE_BRACKET' === $tokens[ $after_ptr ]['type'] && isset( $tokens[ $after_ptr ]['bracket_closer'] ) ) {
                    $after_ptr = self::next( $tokens, $tokens[ $after_ptr ]['bracket_closer'] + 1 );
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
            $name_ptr = self::next( $tokens, $class_ptr + 1 );
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
                $fn_name_ptr = self::next( $tokens, $i + 1 );
                if ( null !== $fn_name_ptr && 'T_BITWISE_AND' === $tokens[ $fn_name_ptr ]['type'] ) {
                    $fn_name_ptr = self::next( $tokens, $fn_name_ptr + 1 );
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

<?php
/** Compatibility grade: how likely a component's findings are to break a site.
 *
 *  1  Breaks on load      fatal error in own code that runs when the plugin or theme loads
 *  2  Likely to break     fatal error in own code inside a function or method
 *  3  Rarely-run code     fatal error in own code on a fallback path modern servers seldom take
 *  4  Bundled library     errors only inside a bundled third-party library
 *  5  Minor changes       non-fatal errors (removed INI settings, behaviour changes)
 *  6  Deprecations        works today, logs notices
 *  7  Compatible          nothing counted
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

/** Grade calculation shared by the report, email and history. */
final class THRG_PC_Grade {

    /**
     * Rule groups that fail when the file is loaded, not only when a feature is used.
     *
     * @param string $source PHPCS rule code.
     * @return bool
     */
    public static function load_time_rule( $source ) {
        $source = (string) $source;
        foreach ( array(
            'PHPCompatibility.FunctionUse.RemovedFunctions.',
            'PHPCompatibility.Constants.RemovedConstants.',
            'PHPCompatibility.Classes.RemovedClasses.',
            'PHPCompatibility.Keywords.',
            'PHPCompatibility.Syntax.',
            'Generic.PHP.Syntax',
        ) as $prefix ) {
            if ( 0 === strpos( $source, $prefix ) ) {
                return true;
            }
        }
        return false !== strpos( $source, 'ParseError' );
    }

    /**
     * Grade for one finding, or 0 when it does not count (notes, skipped files).
     *
     * @param array $finding Finding record.
     * @param array $ctx     Context: boot_files (string[]), marker (bool), strong (bool).
     * @return int 0 or 1-6.
     */
    public static function finding_grade( array $finding, array $ctx ) {
        $type = isset( $finding['type'] ) ? (string) $finding['type'] : '';
        if ( 'warning' === $type ) {
            return 6;
        }
        if ( 'error' !== $type ) {
            return 0;
        }
        $file   = isset( $finding['file'] ) ? (string) $finding['file'] : '';
        $source = isset( $finding['source'] ) ? (string) $finding['source'] : '';
        $origin = '' !== $file
            ? THRG_PC_Results::file_origin( $file )
            : ( isset( $finding['origin'] ) && 'bundled' === $finding['origin'] ? 'bundled' : 'own' );
        if ( 'bundled' === $origin ) {
            return 4;
        }
        if ( ! THRG_PC_Results::is_fatal_rule( $source ) ) {
            return 5;
        }
        $reason = isset( $finding['reason'] ) ? (string) $finding['reason'] : '';
        if ( '' !== $reason ) {
            return 3;
        }
        $scope = isset( $finding['scope'] ) ? (string) $finding['scope'] : 'function';
        $boot  = isset( $ctx['boot_files'] ) && is_array( $ctx['boot_files'] ) ? $ctx['boot_files'] : array();
        if ( in_array( $scope, array( 'file', 'class-body' ), true ) && self::load_time_rule( $source ) && '' !== $file && in_array( $file, $boot, true ) ) {
            return 1;
        }
        if ( ! empty( $ctx['marker'] ) && ! empty( $finding['name_hint'] ) ) {
            return 3;
        }
        if ( ! empty( $ctx['strong'] ) ) {
            return 3;
        }
        return 2;
    }

    /**
     * Worst grade across a component's counted findings.
     *
     * @param array[] $findings Finding records.
     * @param array   $ctx      Context passed to finding_grade().
     * @return array{grade:int,counts:array<int,int>,reasons:array<string,int>}
     */
    public static function component( array $findings, array $ctx ) {
        $counts  = array_fill( 1, 6, 0 );
        $reasons = array();
        $worst   = 7;
        foreach ( $findings as $f ) {
            $g = self::finding_grade( $f, $ctx );
            if ( $g < 1 ) {
                continue;
            }
            ++$counts[ $g ];
            if ( 3 === $g && ! empty( $f['reason'] ) ) {
                $r             = (string) $f['reason'];
                $reasons[ $r ] = isset( $reasons[ $r ] ) ? $reasons[ $r ] + 1 : 1;
            }
            $worst = min( $worst, $g );
        }
        arsort( $reasons );
        return array(
            'grade'   => $worst,
            'counts'  => $counts,
            'reasons' => $reasons,
        );
    }

    /**
     * Short badge wording for a grade.
     *
     * @param int $grade Grade 1-7.
     * @return string
     */
    public static function label( $grade ) {
        switch ( (int) $grade ) {
            case 1:
                return 'Breaks on load';
            case 2:
                return 'Likely to break';
            case 3:
                return 'Review: fallback code';
            case 4:
                return 'Review: bundled library';
            case 5:
                return 'Minor changes';
            case 6:
                return 'Deprecation warnings';
        }
        return 'Compatible';
    }

    /**
     * THRG status label for a grade.
     *
     * @param int $grade Grade 1-7.
     * @return string
     */
    public static function status( $grade ) {
        $grade = (int) $grade;
        if ( $grade <= 2 ) {
            return 'Potential weakness';
        }
        if ( $grade <= 6 ) {
            return 'Review';
        }
        return 'No issue observed';
    }

    /**
     * Whether the grade means "will probably break" (red).
     *
     * @param int $grade Grade 1-7.
     * @return bool
     */
    public static function is_blocking( $grade ) {
        return (int) $grade >= 1 && (int) $grade <= 2;
    }

    /**
     * Whether the grade belongs under the "needs a look" filter.
     *
     * @param int $grade Grade 1-7.
     * @return bool
     */
    public static function needs_review( $grade ) {
        return (int) $grade >= 3 && (int) $grade <= 5;
    }

    /**
     * Evidence flags from what this site already shows.
     *
     * marker: the component is active and this server already runs the target PHP or newer.
     * strong: marker, plus the runtime log is on and WordPress has not paused the component after a fatal error.
     *
     * @param bool   $active         Whether the component is active.
     * @param string $running_php    Running PHP major.minor.
     * @param string $target         Target PHP major.minor.
     * @param bool   $runtime_log_on Whether the runtime log is enabled.
     * @param bool   $paused         Whether WordPress recovery mode paused the component.
     * @return array{marker:bool,strong:bool}
     */
    public static function evidence( $active, $running_php, $target, $runtime_log_on, $paused ) {
        $marker = (bool) $active && '' !== (string) $running_php && version_compare( (string) $running_php, (string) $target, '>=' ) && ! $paused;
        return array(
            'marker' => $marker,
            'strong' => $marker && (bool) $runtime_log_on,
        );
    }
}

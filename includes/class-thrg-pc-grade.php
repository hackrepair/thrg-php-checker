<?php
/** Conservative compatibility priority shared by every report consumer.
 *
 * Grades 1 and 2 retain potential fatal impact; 5 denotes behavior changes,
 * 6 warnings and 7 no counted findings. Origin and execution hints are context.
 * Legacy grade values 3 and 4 remain readable in historical reports.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

/** Grade calculation shared by the report, email and history. */
final class THRG_PC_Grade {

    const CLASSIFICATION_VERSION = 4;

    /**
     * Rules eligible for a potential startup hint when the operation is at file scope.
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
     * Known rule impact. Unmapped errors require review rather than a guessed impact.
     *
     * @param string $source Scanner rule identifier.
     * @return string
     */
    public static function rule_impact( $source ) {
        foreach ( array(
            'PHPCompatibility.IniDirectives.RemovedIniDirectives.',
            'PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue.',
            'PHPCompatibility.Variables.RemovedPredefinedGlobalVariables.',
            'PHPCompatibility.Variables.NewUniformVariableSyntax.',
            'PHPCompatibility.Operators.ChangedConcatOperatorPrecedence.',
            'PHPCompatibility.Lists.AssignmentOrder.',
        ) as $prefix ) {
            if ( 0 === strpos( (string) $source, $prefix ) ) {
                return 'behavior_change';
            }
        }
        foreach ( array(
            'PHPCompatibility.FunctionUse.RemovedFunctions.',
            'PHPCompatibility.Constants.RemovedConstants.',
            'PHPCompatibility.Classes.RemovedClasses.',
            'PHPCompatibility.Extensions.RemovedExtensions.',
            'PHPCompatibility.Syntax.',
            'Generic.PHP.Syntax',
        ) as $prefix ) {
            if ( 0 === strpos( (string) $source, $prefix ) ) {
                return 'fatal';
            }
        }
        return 'unknown';
    }

    /**
     * Classify raw scanner evidence without reading site state or mutating the finding.
     *
     * Boot-file lists and fallback reasons are hints, not execution coverage.
     * Numeric priority is retained for existing consumers; impact is independent.
     *
     * @param array $finding Raw scanner finding.
     * @param array $ctx Explicit context, including target and possible boot files.
     * @return array
     */
    public static function classify( array $finding, array $ctx = array() ) {
        $type   = isset( $finding['type'] ) ? (string) $finding['type'] : '';
        $file   = isset( $finding['file'] ) ? (string) $finding['file'] : '';
        $source = isset( $finding['source'] ) ? (string) $finding['source'] : '';
        $scope  = isset( $finding['scope'] ) ? (string) $finding['scope'] : '';
        $reason = isset( $finding['reason'] ) ? (string) $finding['reason'] : '';
        $boot   = isset( $ctx['boot_files'] ) && is_array( $ctx['boot_files'] ) ? $ctx['boot_files'] : array();
        $out    = array(
            'impact'                 => 'unknown',
            'reachability'           => 'unknown',
            'evidence_kind'          => 'unmapped_rule',
            'evidence_detail'        => array(
				'source' => $source,
				'scope'  => $scope,
				'hint'   => $reason,
			),
            'origin'                 => '' !== $file ? THRG_PC_Results::file_origin( $file ) : ( isset( $finding['origin'] ) && in_array( $finding['origin'], array( 'own', 'bundled' ), true ) ? $finding['origin'] : 'unknown' ),
            'coverage'               => 'skipped' === $type ? 'partial' : 'unknown',
            'target'                 => isset( $ctx['target'] ) ? (string) $ctx['target'] : '',
            'scanner_type'           => $type,
            'counted'                => true,
            'grade'                  => 2,
            'classification_version' => self::CLASSIFICATION_VERSION,
        );
        if ( in_array( $type, array( 'skipped', 'note' ), true ) ) {
            $out['impact']        = 'informational';
            $out['evidence_kind'] = 'skipped' === $type ? 'scan_gap' : 'scanner_note';
            $out['counted']       = false;
            $out['grade']         = 0;
            return $out;
        }
        if ( 'warning' === $type ) {
            $out['impact']        = 'warning';
            $out['evidence_kind'] = 'scanner_warning';
            $out['grade']         = 6;
        } elseif ( 'error' === $type ) {
            $out['impact']        = self::rule_impact( $source );
            $out['evidence_kind'] = 'unknown' === $out['impact'] ? 'unmapped_rule' : 'rule_mapping';
            if ( 'behavior_change' === $out['impact'] ) {
                $out['grade'] = 5;
            }
        }
        // A file-local caller search cannot establish the function's global callers.
        if ( 'fallback_caller' === $reason ) {
            $out['evidence_kind'] = 'local_call_hint';
        } elseif ( 'conditional' === $scope || in_array( $reason, array( 'fallback_or', 'fallback_else', 'fallback_missing', 'fallback_legacy', 'fallback_twin' ), true ) ) {
            $out['reachability']  = 'conditional';
            $out['evidence_kind'] = 'environment_condition';
        }
        $parse_rule = 0 === strpos( $source, 'Generic.PHP.Syntax' ) || 0 === strpos( $source, 'PHPCompatibility.Syntax.' );
        if ( 'fatal' === $out['impact'] && ( $parse_rule || in_array( $scope, array( 'file', 'class-body' ), true ) ) && self::load_time_rule( $source ) && '' !== $file && in_array( $file, $boot, true ) && '' === $reason ) {
            $out['evidence_kind'] = 'startup_file_hint';
            $out['grade']         = 1;
        }
        return $out;
    }

    /**
     * Compatibility adapter for existing numeric-grade consumers.
     *
     * @param array $finding Raw finding.
     * @param array $ctx Explicit classification context.
     * @return int
     */
    public static function finding_grade( array $finding, array $ctx ) {
        return self::classify( $finding, $ctx )['grade'];
    }

    /**
     * Aggregate independent impact, coverage and priority for a component.
     *
     * Coverage must be supplied explicitly by the caller; finding absence alone
     * cannot establish that all files were scanned.
     *
     * @param array[] $findings Raw scanner findings.
     * @param array $ctx Explicit context; coverage may be complete, partial, stale or not_scanned.
     * @return array
     */
    public static function component( array $findings, array $ctx ) {
        $counts       = array_fill( 1, 6, 0 );
        $impacts      = array_fill_keys( array( 'fatal', 'behavior_change', 'warning', 'unknown', 'informational' ), 0 );
        $reachability = array_fill_keys( array( 'unknown', 'conditional' ), 0 );
        $worst        = 7;
        $coverage     = isset( $ctx['coverage'] ) && in_array( $ctx['coverage'], array( 'complete', 'partial', 'stale', 'not_scanned' ), true ) ? $ctx['coverage'] : 'unknown';
        foreach ( $findings as $f ) {
            $classified = self::classify( $f, $ctx );
            ++$impacts[ $classified['impact'] ];
            if ( 'partial' === $classified['coverage'] && ! in_array( $coverage, array( 'stale', 'not_scanned' ), true ) ) {
                $coverage = 'partial';
            }
            if ( ! $classified['counted'] ) {
                continue;
            }
            ++$reachability[ $classified['reachability'] ];
            ++$counts[ $classified['grade'] ];
            $worst = min( $worst, $classified['grade'] );
        }
        $observed_errors  = $impacts['fatal'] + $impacts['behavior_change'] + $impacts['unknown'];
        $missing_errors   = isset( $ctx['expected_errors'] ) ? max( 0, (int) $ctx['expected_errors'] - $observed_errors ) : 0;
        $missing_warnings = isset( $ctx['expected_warnings'] ) ? max( 0, (int) $ctx['expected_warnings'] - $impacts['warning'] ) : 0;
        if ( $missing_errors || $missing_warnings ) {
            // Stored totals can exceed retained findings after bounded storage trimming.
            $coverage                 = 'stale';
            $impacts['unknown']      += $missing_errors;
            $counts[2]               += $missing_errors;
            $counts[6]               += $missing_warnings;
            $impacts['warning']      += $missing_warnings;
            $reachability['unknown'] += $missing_errors + $missing_warnings;
            $worst                    = min( $worst, $missing_errors ? 2 : 6 );
        }
        return array(
            'grade'                  => $worst,
            'counts'                 => $counts,
            'reasons'                => array(),
            'fatal'                  => $impacts['fatal'],
            'unknown'                => $impacts['unknown'],
            'impacts'                => $impacts,
            'reachability'           => $reachability,
            'coverage'               => $coverage,
            'classification_version' => self::CLASSIFICATION_VERSION,
        );
    }

    /**
     * Report evidence limitations without reinterpreting historical snapshots.
     *
     * @param array[] $rows Evaluated or saved rows.
     * @return array
     */
    public static function report_health( array $rows ) {
        $out = array(
			'incomplete' => false,
			'legacy'     => false,
			'unknown'    => 0,
		);
        foreach ( $rows as $row ) {
            if ( 'scan-overview' === ( isset( $row['id'] ) ? $row['id'] : '' ) ) {
                $out['incomplete'] = true;
            }
            if ( isset( $row['id'], $row['coverage'] ) && 'update-checks' === $row['id'] && 'complete' !== $row['coverage'] ) {
                $out['incomplete'] = true;
            }
            if ( empty( $row['comp_key'] ) ) {
                continue;
            }
            if ( ! isset( $row['classification_version'] ) || self::CLASSIFICATION_VERSION !== (int) $row['classification_version'] ) {
                $out['legacy'] = true;
            }
            if ( ! isset( $row['coverage'] ) || 'complete' !== $row['coverage'] ) {
                $out['incomplete'] = true;
            }
            $out['unknown'] += isset( $row['unknown_errors'] ) ? (int) $row['unknown_errors'] : 0;
        }
        return $out;
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
                return 'Likely to break your site';
            case 2:
                return 'May break parts of your site';
            case 3:
                return 'Review: fallback code';
            case 4:
                return 'Review: bundled library';
            case 5:
                return 'Minor changes';
            case 6:
                return 'Warnings (old features)';
        }
        return 'No issues detected';
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
     * strong: deprecated compatibility field, always false; logging cannot prove execution.
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
            'marker'      => $marker,
            'strong'      => false,
            'running_php' => (string) $running_php,
        );
    }
}

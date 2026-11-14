<?php
/**
 * Scan results store in bounded non-autoloaded WordPress options.
 *
 * Unlike custom-table scanners that leave orphaned rows from uninstalled plugins
 * and older versions forever, THRG_PC_Results keys installed results by
 * target PHP version and component key, and automatically prunes uninstalled
 * components when syncing against the current installation.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Component and update scan result storage.
 */
final class THRG_PC_Results {

    const OPTION           = 'thrg_pc_results';
    const UPDATE_OPTION    = 'thrg_pc_update_results';
    const MAX_FINDINGS     = 100;
    const EVIDENCE_VERSION = 2;

    /**
     * Count findings by category (errors, warnings, skipped, notes).
     *
     * @param array $findings Finding list.
     * @return array{errors:int,warnings:int,skipped:int,notes:int}
     */
    public static function count( array $findings ) {
        $out = array(
            'errors'   => 0,
            'warnings' => 0,
            'skipped'  => 0,
            'notes'    => 0,
        );
        foreach ( $findings as $finding ) {
            $type = isset( $finding['type'] ) ? (string) $finding['type'] : '';
            if ( 'error' === $type ) {
                ++$out['errors'];
            } elseif ( 'warning' === $type ) {
                ++$out['warnings'];
            } elseif ( 'skipped' === $type ) {
                ++$out['skipped'];
            } elseif ( 'note' === $type ) {
                ++$out['notes'];
            }
        }
        return $out;
    }

    /**
     * Compute the verdict code for a set of counts.
     *
     * @param int $errors   Error count.
     * @param int $warnings Deprecation/warning count.
     * @param int $skipped  Skipped file count.
     * @return string One of 'breaks', 'warnings', 'partial', 'ok'.
     */
    public static function verdict( $errors, $warnings, $skipped = 0 ) {
        if ( (int) $errors > 0 ) {
            return 'breaks';
        }
        if ( (int) $warnings > 0 ) {
            return 'warnings';
        }
        if ( (int) $skipped > 0 ) {
            return 'partial';
        }
        return 'ok';
    }

    /**
     * Map a verdict code to the THRG status label used across findings and emails.
     *
     * @param string $verdict Verdict code.
     * @return string
     */
    public static function status_from_verdict( $verdict ) {
        if ( 'breaks' === $verdict ) {
            return 'Potential weakness';
        }
        if ( 'warnings' === $verdict ) {
            return 'Review';
        }
        if ( 'partial' === $verdict ) {
            return 'Partial inspection';
        }
        return 'No issue observed';
    }

    /**
     * Classify whether a relative file path inside a component belongs to a bundled
     * third-party dependency ('bundled') or the component's own code ('own').
     *
     * @param string $file Relative file path inside the component.
     * @return string 'bundled' or 'own'.
     */
    public static function file_origin( $file ) {
        $norm     = ltrim( strtolower( str_replace( '\\', '/', (string) $file ) ), '/' );
        $segments = explode( '/', $norm );
        array_pop( $segments );
        $bundled_dirs = array(
            'vendor',
            'vendors',
            'vendor-prefixed',
            'prefixed',
            'strauss',
            'mozart',
            'third-party',
            'third_party',
            'thirdparty',
            'external',
            'externals',
            'bundled',
            'packages',
            'bower_components',
            'node_modules',
            'freemius',
            'action-scheduler',
            implode( '-', array( 'plugin', 'update', 'checker' ) ), // Common third-party library directory name; split so this scanner is not mistaken for a bundled updater.
            'yahnis-elsts',
            'cmb2',
            'tgmpa',
            'pear',
            'phpmailer',
            'mailer',
            'imap2',
            'libraries',
            'library',
            'lib',
            'libs',
            'sdk',
            'sdks',
            'tcpdf',
            'dompdf',
            'mpdf',
        );
        foreach ( $segments as $seg ) {
            if ( in_array( $seg, $bundled_dirs, true ) || 0 === strpos( $seg, 'vendor-' ) || 0 === strpos( $seg, 'vendor_' ) ) {
                return 'bundled';
            }
        }
        return 'own';
    }

    /**
     * Whether a PHPCS error rule can cause a hard/fatal crash at runtime on the target PHP version.
     *
     * Behavioral/INI removal rules (such as RemovedIniDirectives or ArgumentFunctionsReportCurrentValue)
     * do not halt PHP with a fatal error.
     *
     * @param string $source PHPCS rule code.
     * @return bool
     */
    public static function is_fatal_rule( $source ) {
        return 'fatal' === THRG_PC_Grade::rule_impact( $source );
    }

    /**
     * Extract the originating PHP version from a PHPCompatibility finding message or rule code.
     *
     * @param string $message Finding message.
     * @param string $source  PHPCS rule code.
     * @param string $target  Fallback scan target PHP version.
     * @return string Display label such as "since PHP 7.0" or "PHP 8.3".
     */
    public static function rule_php_version( $message, $source, $target ) {
        $msg = (string) $message;
        if ( preg_match( '/(?:since|removed in|deprecated in|introduced in)\s+PHP\s+(\d+\.\d+)/i', $msg, $m ) ) {
            return 'since PHP ' . $m[1];
        }
        if ( preg_match( '/PHP\s+(\d+\.\d+)/i', $msg, $m ) ) {
            return 'PHP ' . $m[1];
        }
        if ( false !== strpos( (string) $source, 'ForbiddenDynamicProperties' ) ) {
            return 'since PHP 8.2';
        }
        return 'PHP ' . (string) $target;
    }

    /**
     * Trim findings to MAX_FINDINGS, keeping errors first, then warnings, skipped, and notes.
     *
     * @param array $findings Full findings list.
     * @return array[]
     */
    public static function trim_findings( array $findings ) {
        $keep = array();
        foreach ( $findings as $finding ) {
            $rel_file = ltrim( str_replace( '\\', '/', isset( $finding['file'] ) ? (string) $finding['file'] : '' ), '/' );
            $origin   = isset( $finding['origin'] ) && in_array( $finding['origin'], array( 'own', 'bundled' ), true )
                ? (string) $finding['origin']
                : self::file_origin( $rel_file );
            $keep[]   = array(
                'file'    => $rel_file,
                'line'    => isset( $finding['line'] ) ? absint( $finding['line'] ) : 0,
                'type'    => isset( $finding['type'] ) ? (string) $finding['type'] : '',
                'message' => isset( $finding['message'] ) ? (string) $finding['message'] : '',
                'source'  => isset( $finding['source'] ) ? (string) $finding['source'] : '',
                'origin'  => $origin,
            );
            $last     = count( $keep ) - 1;
            if ( isset( $finding['scope'] ) && in_array( $finding['scope'], array( 'file', 'class-body', 'conditional', 'function' ), true ) ) {
                $keep[ $last ]['scope'] = (string) $finding['scope'];
            }
            if ( ! empty( $finding['reason'] ) && preg_match( '/^[a-z_]{1,24}$/', (string) $finding['reason'] ) ) {
                $keep[ $last ]['reason'] = (string) $finding['reason'];
            }
            if ( ! empty( $finding['name_hint'] ) ) {
                $keep[ $last ]['name_hint'] = 1;
            }
        }
        if ( count( $keep ) > self::MAX_FINDINGS ) {
            $errors   = array();
            $warnings = array();
            $skipped  = array();
            $notes    = array();
            foreach ( $keep as $f ) {
                if ( 'error' === $f['type'] ) {
                    $errors[] = $f;
                } elseif ( 'warning' === $f['type'] ) {
                    $warnings[] = $f;
                } elseif ( 'skipped' === $f['type'] ) {
                    $skipped[] = $f;
                } else {
                    $notes[] = $f;
                }
            }
            // Keep actionable gap reasons even when errors and warnings fill the
            // display limit. Full impact totals are preserved by the aggregate.
            $gap_count = min( 10, count( $skipped ) );
            $keep      = array_slice(
                array_merge(
                    array_slice( array_merge( $errors, $warnings ), 0, self::MAX_FINDINGS - $gap_count ),
                    array_slice( $skipped, 0, $gap_count ),
                    $notes
                ),
                0,
                self::MAX_FINDINGS
            );
        }
        return $keep;
    }

    /**
     * Keep only plain relative file paths for the boot-file list.
     *
     * @param mixed $files Candidate list.
     * @return string[]
     */
    public static function clean_boot_files( $files ) {
        $out = array();
        if ( ! is_array( $files ) ) {
            return $out;
        }
        foreach ( $files as $f ) {
            $f = ltrim( str_replace( '\\', '/', (string) $f ), '/' );
            if ( '' === $f || false !== strpos( $f, '..' ) || strlen( $f ) > 200 ) {
                continue;
            }
            $out[ $f ] = $f;
            if ( count( $out ) >= THRG_PC_Boot::MAX_FILES ) {
                break;
            }
        }
        return array_values( $out );
    }

    /**
     * Unique key for an installed component (kind + slug).
     *
     * @param string $kind 'plugin', 'mu-plugin', or 'theme'.
     * @param string $slug Component slug or relative file.
     * @return string
     */
    public static function key( $kind, $slug ) {
        return (string) $kind . ':' . (string) $slug;
    }

    /**
     * Retrieve the full stored results map across all targets.
     *
     * @return array<string,array<string,array>>
     */
    public static function all() {
        $raw = get_option( self::OPTION, array() );
        return is_array( $raw ) ? $raw : array();
    }

    /**
     * Save a component scan result for a target PHP version.
     *
     * @param array  $component Component descriptor (kind, slug, name, version, active, files_count).
     * @param string $target    Target PHP version ("8.4" style).
     * @param array  $findings  Findings list.
     * @param int    $now       Timestamp (0 for current time).
     * @return bool
     */
    public static function save( array $component, $target, array $findings, $now = 0 ) {
        $kind    = isset( $component['kind'] ) ? (string) $component['kind'] : '';
        $slug    = isset( $component['slug'] ) ? (string) $component['slug'] : '';
        $version = isset( $component['version'] ) ? (string) $component['version'] : '0';
        $target  = (string) $target;
        if ( ! in_array( $kind, array( 'plugin', 'mu-plugin', 'theme' ), true ) || '' === $slug || ! preg_match( '/^[5-9]\.\d{1,2}$/', $target ) ) {
            return false;
        }
        $counts  = self::count( $findings );
        $verdict = self::verdict( $counts['errors'], $counts['warnings'], $counts['skipped'] );
        $all     = self::all();
        if ( ! isset( $all[ $target ] ) || ! is_array( $all[ $target ] ) ) {
            $all[ $target ] = array();
        }
        $all[ $target ][ self::key( $kind, $slug ) ]     = array(
            'scan_evidence_version' => self::EVIDENCE_VERSION,
            'kind'                  => $kind,
            'slug'                  => $slug,
            'name'                  => isset( $component['name'] ) ? (string) $component['name'] : $slug,
            'version'               => '' === $version ? '0' : $version,
            'target'                => $target,
            'verdict'               => $verdict,
            'errors'                => $counts['errors'],
            'warnings'              => $counts['warnings'],
            'skipped'               => $counts['skipped'],
            'notes'                 => $counts['notes'],
            'files_total'           => isset( $component['files_total'] ) ? absint( $component['files_total'] ) : 0,
            'findings'              => $findings,
            'boot_files'            => self::clean_boot_files( isset( $component['boot_files'] ) ? $component['boot_files'] : array() ),
            'scanned_at'            => $now > 0 ? (int) $now : time(),
        );
        $record_key                                      = self::key( $kind, $slug );
        $all[ $target ][ $record_key ]['classification'] = self::classification( $all[ $target ][ $record_key ] );
        $all[ $target ][ $record_key ]['findings']       = self::trim_findings( $findings );
        update_option( self::OPTION, $all, false );
        return true;
    }

    /**
     * Whether a stored result was produced by the current evidence format.
     *
     * @param array|null $item Stored result or null.
     * @return bool
     */
    public static function is_current( $item ) {
        return is_array( $item ) && isset( $item['scan_evidence_version'] ) && self::EVIDENCE_VERSION === (int) $item['scan_evidence_version'];
    }

    /**
     * Get one component's stored result for a target PHP version and exact component version.
     *
     * @param string $kind    Component kind.
     * @param string $slug    Component slug.
     * @param string $version Component version.
     * @param string $target  Target PHP version.
     * @return array|null
     */
    public static function get( $kind, $slug, $version, $target ) {
        $all  = self::all();
        $k    = self::key( $kind, $slug );
        $item = isset( $all[ $target ][ $k ] ) && is_array( $all[ $target ][ $k ] ) ? $all[ $target ][ $k ] : null;
        $norm = '' === (string) $version ? '0' : (string) $version;
        if ( ! $item || $norm !== (string) $item['version'] ) {
            return null;
        }
        return $item;
    }

    /**
     * Get all current stored results for a target PHP version, matched and pruned against currently installed components.
     *
     * @param string  $target     Target PHP version.
     * @param array[] $components Output of THRG_PC_Scanner::components().
     * @return array[]
     */
    public static function for_target( $target, array $components ) {
        $all = self::all();
        if ( empty( $all[ $target ] ) || ! is_array( $all[ $target ] ) ) {
            return array();
        }
        $by_key = array();
        foreach ( $components as $c ) {
            $by_key[ self::key( $c['kind'], $c['slug'] ) ] = $c;
        }
        $out   = array();
        $dirty = false;
        foreach ( $all[ $target ] as $k => $row ) {
            if ( ! isset( $by_key[ $k ] ) ) {
                unset( $all[ $target ][ $k ] );
                $dirty = true;
                continue;
            }
            $comp = $by_key[ $k ];
            if ( (string) $row['version'] !== (string) $comp['version'] ) {
                unset( $all[ $target ][ $k ] );
                $dirty = true;
                continue;
            }
            $row['name']           = $comp['name'];
            $row['active']         = ! empty( $comp['active'] );
            $row['network_active'] = ! empty( $comp['network_active'] );
            $row['parent_theme']   = ! empty( $comp['parent_theme'] );
            $row['update_version'] = isset( $comp['update_version'] ) ? (string) $comp['update_version'] : '';
            $out[]                 = $row;
        }
        if ( $dirty ) {
            update_option( self::OPTION, $all, false );
        }
        return $out;
    }

    /**
     * Targets that currently have at least one stored result for installed components.
     *
     * @param string[] $targets    Supported target PHP versions.
     * @param array[]  $components Installed components.
     * @return array<string,array{total:int,breaks:int,warnings:int,ok:int}>
     */
    public static function target_summaries( array $targets, array $components ) {
        $out = array();
        foreach ( $targets as $t ) {
            $rows = self::for_target( $t, $components );
            if ( ! $rows ) {
                continue;
            }
            $summary = array(
                'total'    => count( $rows ),
                'unknown'  => 0,
                'stale'    => 0,
                'breaks'   => 0,
                'warnings' => 0,
                'ok'       => 0,
            );
            foreach ( $rows as $r ) {
                $g      = self::classification( $r );
                $likely = THRG_PC_Grade::is_blocking( $g['grade'] );
                if ( $g['unknown'] > 0 ) {
                    ++$summary['unknown'];
                }
                if ( 'stale' === $g['coverage'] ) {
                    ++$summary['stale'];
                }
                if ( $likely ) {
                    ++$summary['breaks'];
                } elseif ( 'breaks' === $r['verdict'] || in_array( $r['verdict'], array( 'warnings', 'partial' ), true ) ) {
                    ++$summary['warnings'];
                } else {
                    ++$summary['ok'];
                }
            }
            $out[ $t ] = $summary;
        }
        return $out;
    }

    /**
     * Classify retained evidence or reuse a matching aggregate saved before trimming.
     *
     * @param array $row Stored component or update record.
     * @return array
     */
    public static function classification( array $row ) {
        $source_counts = array();
        foreach ( array( 'errors', 'warnings', 'skipped', 'notes' ) as $key ) {
            $source_counts[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : 0;
        }
        $target           = isset( $row['target'] ) ? (string) $row['target'] : '';
        $evidence_version = isset( $row['scan_evidence_version'] ) ? (int) $row['scan_evidence_version'] : 0;
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Fingerprint trusted stored evidence; no deserialization.
        $retained_hash = hash( 'sha256', serialize( array( self::trim_findings( isset( $row['findings'] ) && is_array( $row['findings'] ) ? $row['findings'] : array() ), isset( $row['boot_files'] ) ? $row['boot_files'] : array(), $evidence_version ) ) );
        $saved         = isset( $row['classification'] ) && is_array( $row['classification'] ) ? $row['classification'] : array();
        if ( isset( $saved['classification_version'], $saved['source_counts'], $saved['target'], $saved['grade'], $saved['fatal'], $saved['unknown'], $saved['coverage'], $saved['retained_hash'] ) &&
            THRG_PC_Grade::CLASSIFICATION_VERSION === $saved['classification_version'] && $source_counts === $saved['source_counts'] && $target === $saved['target'] && $retained_hash === $saved['retained_hash'] ) {
            return $saved;
        }
        $out                  = THRG_PC_Grade::component(
            isset( $row['findings'] ) && is_array( $row['findings'] ) ? $row['findings'] : array(),
            array(
                'target'            => $target,
                'coverage'          => self::EVIDENCE_VERSION !== $evidence_version ? 'stale' : ( $source_counts['skipped'] ? 'partial' : 'complete' ),
                'expected_errors'   => $source_counts['errors'],
                'expected_warnings' => $source_counts['warnings'],
                'boot_files'        => isset( $row['boot_files'] ) && is_array( $row['boot_files'] ) ? $row['boot_files'] : array(),
            )
        );
        $out['source_counts'] = $source_counts;
        $out['target']        = $target;
        $out['retained_hash'] = $retained_hash;
        return $out;
    }

    /**
     * Shared update assessment; a clear verdict requires complete current evidence.
     *
     * @param array $row Stored update result.
     * @return string pending, needs_review, still_fails, warnings_only or fixed.
     */
    public static function update_assessment( array $row ) {
        $c = self::classification( $row );
        if ( $c['fatal'] > 0 ) {
            return 'still_fails';
        }
        if ( 'complete' !== $c['coverage'] ) {
            return 'pending';
        }
        if ( $c['unknown'] > 0 ) {
            return 'needs_review';
        }
        if ( $c['impacts']['behavior_change'] || $c['impacts']['warning'] ) {
            return 'warnings_only';
        }
        return 'fixed';
    }

    /**
     * Save an offered update pre-scan result.
     *
     * @param string $kind     'plugin' or 'theme'.
     * @param string $slug     Update offer slug.
     * @param string $version  Offered new version.
     * @param string $target   Target PHP version.
     * @param array  $findings Findings list.
     * @return bool
     */
    public static function save_update( $kind, $slug, $version, $target, array $findings ) {
        $kind    = (string) $kind;
        $slug    = (string) $slug;
        $version = (string) $version;
        $target  = (string) $target;
        if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) || '' === $slug || '' === $version || ! preg_match( '/^[5-9]\.\d{1,2}$/', $target ) ) {
            return false;
        }
        $counts  = self::count( $findings );
        $verdict = self::verdict( $counts['errors'], $counts['warnings'], $counts['skipped'] );
        $raw     = get_option( self::UPDATE_OPTION, array() );
        $all     = is_array( $raw ) ? $raw : array();
        $key     = $kind . ':' . $slug . ':' . $version . ':' . $target;

        $all[ $key ]                   = array(
            'scan_evidence_version' => self::EVIDENCE_VERSION,
            'kind'                  => $kind,
            'slug'                  => $slug,
            'version'               => $version,
            'target'                => $target,
            'verdict'               => $verdict,
            'errors'                => $counts['errors'],
            'warnings'              => $counts['warnings'],
            'skipped'               => $counts['skipped'],
            'notes'                 => $counts['notes'],
            'grade'                 => 'breaks' === $verdict ? min( 6, (int) THRG_PC_Grade::component( self::trim_findings( $findings ), array() )['grade'] ) : ( 'warnings' === $verdict ? 6 : 7 ),
            'findings'              => $findings,
            'scanned_at'            => time(),
        );
        $all[ $key ]['classification'] = self::classification( $all[ $key ] );
        $all[ $key ]['grade']          = $all[ $key ]['classification']['grade'];
        $all[ $key ]['findings']       = self::trim_findings( $findings );
        if ( count( $all ) > 100 ) {
            $all = array_slice( $all, -100, 100, true );
        }
        update_option( self::UPDATE_OPTION, $all, false );
        return true;
    }

    /**
     * Get a stored update pre-scan result.
     *
     * @param string $kind    'plugin' or 'theme'.
     * @param string $slug    Update offer slug.
     * @param string $version Offered version.
     * @param string $target  Target PHP version.
     * @return array|null
     */
    public static function get_update( $kind, $slug, $version, $target ) {
        $raw = get_option( self::UPDATE_OPTION, array() );
        if ( ! is_array( $raw ) ) {
            return null;
        }
        $key = (string) $kind . ':' . (string) $slug . ':' . (string) $version . ':' . (string) $target;
        if ( ! isset( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) ) {
            return null;
        }
        $row                   = $raw[ $key ];
        $classification        = self::classification( $row );
        $row['grade']          = $classification['grade'];
        $row['classification'] = $classification;
        return $row;
    }

    /**
     * Clear all stored results (installed and updates).
     *
     * @return void
     */
    public static function clear_all() {
        delete_option( self::OPTION );
        delete_option( self::UPDATE_OPTION );
    }
}

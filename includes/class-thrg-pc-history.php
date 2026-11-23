<?php
/**
 * Report history: the most recent PHP compatibility scans, kept on this site only.
 *
 * Stored in one non-autoloaded option, newest first, bounded by count and size.
 * Reports include plugin, theme, and file names, so history can be paused, a
 * single report deleted, or everything cleared. History never leaves the site
 * unless an administrator emails a report (THRG_PC_Email).
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bounded local report history and diffing.
 */
final class THRG_PC_History {

    const OPTION      = 'thrg_pc_history';
    const PAUSED      = 'thrg_pc_history_paused';
    const MAX_ENTRIES = 25;
    const MAX_BYTES   = 3000000;
    const SCHEMA      = 1;

    /** Identical results within this window are not saved twice. */
    const DEDUPE_SECONDS = 300;

    /**
     * Whether report history is enabled.
     *
     * @return bool
     */
    public static function enabled() {
        return ! get_option( self::PAUSED, false );
    }

    /**
     * Pause or resume report history.
     *
     * @param bool $enabled True to enable, false to pause.
     * @return void
     */
    public static function set_enabled( $enabled ) {
        if ( $enabled ) {
            delete_option( self::PAUSED );
        } else {
            update_option( self::PAUSED, 1, false );
        }
    }

    /**
     * All stored history entries, newest first.
     *
     * @return array[]
     */
    public static function all() {
        $entries = get_option( self::OPTION, array() );
        if ( ! is_array( $entries ) ) {
            return array();
        }
        return array_values(
            array_filter(
                $entries,
                static function ( $e ) {
                    return is_array( $e ) && isset( $e['id'], $e['time'], $e['rows'] ) && is_array( $e['rows'] );
                }
            )
        );
    }

    /**
     * Validate a report ID (12 lowercase hex characters).
     *
     * @param mixed $id Candidate ID.
     * @return bool
     */
    public static function valid_id( $id ) {
        return is_string( $id ) && 1 === preg_match( '/^[a-f0-9]{12}$/', $id );
    }

    /**
     * Find a saved report by ID.
     *
     * @param string $id Report ID.
     * @return array|null
     */
    public static function find( $id ) {
        if ( ! self::valid_id( $id ) ) {
            return null;
        }
        foreach ( self::all() as $entry ) {
            if ( $entry['id'] === $id ) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Summarize findings by tone and collect IDs of non-clean items.
     *
     * @param array[] $rows Evaluation rows.
     * @return array{attention:int,review:int,observed:int,issues:string[]}
     */
    public static function summarize( array $rows ) {
        $out = array(
            'attention' => 0,
            'review'    => 0,
            'observed'  => 0,
            'server'    => 0,
            'issues'    => array(),
        );
        foreach ( $rows as $row ) {
            if ( isset( $row['id'] ) && 'php-version' === $row['id'] ) {
                ++$out['server'];
            } elseif ( 'Potential weakness' === $row['status'] ) {
                ++$out['attention'];
            } elseif ( 'No issue observed' === $row['status'] ) {
                ++$out['observed'];
            } else {
                ++$out['review'];
            }
            if ( 'No issue observed' !== $row['status'] ) {
                $out['issues'][] = (string) $row['id'];
            }
        }
        return $out;
    }

    /**
     * Save a completed scan report to history.
     *
     * @param array[] $rows      Evaluated rows from THRG_PHP_Checker::evaluate().
     * @param string  $target    Target PHP version ("8.4" style).
     * @param string  $site      Site hostname.
     * @param string  $version   Plugin version.
     * @param int     $user_id   User ID (0 for cron).
     * @param string  $user_name User login or label.
     * @param int     $now       Timestamp.
     * @return string|null Report ID, or null when history is paused.
     */
    public static function add( array $rows, $target, $site, $version, $user_id, $user_name, $now ) {
        if ( ! self::enabled() ) {
            return null;
        }
        $entries = self::all();
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Hashing a trusted internal array; no data is unserialized here.
        $hash = md5( $target . ':' . serialize( $rows ) );
        if ( $entries && isset( $entries[0]['hash'] ) && $entries[0]['hash'] === $hash && ( $now - (int) $entries[0]['time'] ) < self::DEDUPE_SECONDS &&
            wp_date( 'Y-m-d', $now ) === wp_date( 'Y-m-d', (int) $entries[0]['time'] ) ) {
            return $entries[0]['id'];
        }
        $summary = self::summarize( $rows );
        $id      = bin2hex( random_bytes( 6 ) );
        array_unshift(
            $entries,
            array(
                'schema'                 => self::SCHEMA,
                'summary'                => $summary,
                'classification_version' => THRG_PC_Grade::CLASSIFICATION_VERSION,
                'id'                     => $id,
                'target'                 => substr( (string) $target, 0, 10 ),
                'time'                   => (int) $now,
                'site'                   => substr( (string) $site, 0, 190 ),
                'version'                => substr( (string) $version, 0, 20 ),
                'user'                   => (int) $user_id,
                'name'                   => substr( (string) $user_name, 0, 60 ),
                'attention'              => $summary['attention'],
                'review'                 => $summary['review'],
                'observed'               => $summary['observed'],
                'issues'                 => $summary['issues'],
                'hash'                   => $hash,
                'rows'                   => $rows,
            )
        );
        $entries = self::retain( $entries );
        update_option( self::OPTION, $entries, false );
        return $id;
    }

    /**
     * Under either limit, drop older repeats before dropping the last report for a day and target.
     * The newest snapshot always survives, even if it alone exceeds the storage budget.
     *
     * @param array[] $entries Snapshots, newest first.
     * @return array[]
     */
    private static function retain( array $entries ) {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Measure the trusted WordPress option as stored.
        $bytes = strlen( serialize( $entries ) );
        if ( count( $entries ) <= self::MAX_ENTRIES && $bytes <= self::MAX_BYTES ) {
            return $entries;
        }
        $days       = array();
        $daily      = array();
        $duplicates = array();
        foreach ( $entries as $i => $entry ) {
            $key = ( $entry['target'] ?? '' ) . ':' . wp_date( 'Y-m-d', (int) $entry['time'] );
            if ( isset( $days[ $key ] ) ) {
                $duplicates[] = $i;
            } else {
                $days[ $key ] = true;
                $daily[]      = $i;
            }
        }
        foreach ( array_merge( array_reverse( $duplicates ), array_reverse( $daily ) ) as $i ) {
            if ( 0 === $i || ( count( $entries ) <= self::MAX_ENTRIES && $bytes <= self::MAX_BYTES ) ) {
                break;
            }
            unset( $entries[ $i ] );
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Reindex to measure the final option accurately.
            $bytes = strlen( serialize( array_values( $entries ) ) );
        }
        return array_values( $entries );
    }

    /**
     * Prefer the latest matching scan from a previous local day, then a same-day scan.
     *
     * @param array   $entry   Current report.
     * @param array[] $history Full history list.
     * @param int     $start_i Index of $entry in $history.
     * @return array|null
     */
    public static function previous_for( array $entry, array $history, $start_i = 0 ) {
        $target   = $entry['target'] ?? '';
        $day      = wp_date( 'Y-m-d', (int) $entry['time'] );
        $same_day = null;
        $count    = count( $history );
        for ( $j = (int) $start_i + 1; $j < $count; $j++ ) {
            $candidate = $history[ $j ];
            if ( ( $candidate['target'] ?? '' ) !== $target ) {
                continue;
            }
            if ( wp_date( 'Y-m-d', (int) $candidate['time'] ) !== $day ) {
                return $candidate;
            }
            if ( null === $same_day ) {
                $same_day = $candidate;
            }
        }
        return $same_day;
    }

    /**
     * Delete a single report by ID.
     *
     * @param string $id Report ID.
     * @return bool
     */
    public static function delete( $id ) {
        if ( ! self::valid_id( $id ) ) {
            return false;
        }
        $entries = self::all();
        $kept    = array_values(
            array_filter(
                $entries,
                static function ( $e ) use ( $id ) {
                    return $e['id'] !== $id;
                }
            )
        );
        if ( count( $kept ) === count( $entries ) ) {
            return false;
        }
        if ( $kept ) {
            update_option( self::OPTION, $kept, false );
        } else {
            delete_option( self::OPTION );
        }
        return true;
    }

    /**
     * Clear all saved reports.
     *
     * @return void
     */
    public static function clear() {
        delete_option( self::OPTION );
    }

    /**
     * Compute what changed compared with an older report.
     *
     * @param array      $entry Current report.
     * @param array|null $older Older report for the same target, if any.
     * @return array{first:bool,new:int,resolved:int}
     */
    public static function change( array $entry, $older ) {
        $reason = '';
        if ( ! is_array( $older ) ) {
            $reason = 'baseline';
        } elseif ( ( isset( $entry['target'] ) ? $entry['target'] : '' ) !== ( isset( $older['target'] ) ? $older['target'] : '' ) ) {
            $reason = 'target_changed';
        } elseif ( ( isset( $entry['classification_version'] ) ? $entry['classification_version'] : 1 ) !== ( isset( $older['classification_version'] ) ? $older['classification_version'] : 1 ) ) {
            $reason = 'rules_changed';
        } else {
            foreach ( array( $entry, $older ) as $snapshot ) {
                $health = THRG_PC_Grade::report_health( isset( $snapshot['rows'] ) ? $snapshot['rows'] : array() );
                if ( $health['incomplete'] || $health['legacy'] ) {
                    $reason = 'incomplete_evidence';
                }
            }
        }
        if ( '' !== $reason ) {
            return array(
				'first'    => true,
				'new'      => 0,
				'resolved' => 0,
				'reason'   => $reason,
			);
        }
        $now  = isset( $entry['issues'] ) ? (array) $entry['issues'] : array();
        $then = isset( $older['issues'] ) ? (array) $older['issues'] : array();
        return array(
            'first'    => false,
            'reason'   => 'comparable',
            'new'      => count( array_diff( $now, $then ) ),
            'resolved' => count( array_diff( $then, $now ) ),
        );
    }
}

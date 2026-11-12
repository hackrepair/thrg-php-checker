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
    const MAX_BYTES   = 600000;
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
            'issues'    => array(),
        );
        foreach ( $rows as $row ) {
            if ( 'Potential weakness' === $row['status'] ) {
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
        if ( $entries && isset( $entries[0]['hash'] ) && $entries[0]['hash'] === $hash && ( $now - (int) $entries[0]['time'] ) < self::DEDUPE_SECONDS ) {
            return $entries[0]['id'];
        }
        $summary = self::summarize( $rows );
        $id      = bin2hex( random_bytes( 6 ) );
        array_unshift(
            $entries,
            array(
                'schema'    => self::SCHEMA,
                'id'        => $id,
                'target'    => substr( (string) $target, 0, 10 ),
                'time'      => (int) $now,
                'site'      => substr( (string) $site, 0, 190 ),
                'version'   => substr( (string) $version, 0, 20 ),
                'user'      => (int) $user_id,
                'name'      => substr( (string) $user_name, 0, 60 ),
                'attention' => $summary['attention'],
                'review'    => $summary['review'],
                'observed'  => $summary['observed'],
                'issues'    => $summary['issues'],
                'hash'      => $hash,
                'rows'      => $rows,
            )
        );
        $entries     = array_slice( $entries, 0, self::MAX_ENTRIES );
        $entry_count = count( $entries );
        while ( $entry_count > 1 ) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Measures the trusted option's serialized storage size.
            $serialized = serialize( $entries );
            if ( strlen( $serialized ) <= self::MAX_BYTES ) {
                break;
            }
            array_pop( $entries );
            --$entry_count;
        }
        update_option( self::OPTION, $entries, false );
        return $id;
    }

    /**
     * Find the next older report for the same target PHP version (or next older overall if none).
     *
     * @param array   $entry   Current entry.
     * @param array[] $history Full history list.
     * @param int     $start_i Index of $entry in $history.
     * @return array|null
     */
    public static function previous_for( array $entry, array $history, $start_i = 0 ) {
        $target = isset( $entry['target'] ) ? (string) $entry['target'] : '';
        $count  = count( $history );
        for ( $j = (int) $start_i + 1; $j < $count; $j++ ) {
            $cand_target = isset( $history[ $j ]['target'] ) ? (string) $history[ $j ]['target'] : '';
            if ( '' === $target || $cand_target === $target ) {
                return $history[ $j ];
            }
        }
        return null;
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
        if ( ! is_array( $older ) ) {
            return array(
                'first'    => true,
                'new'      => 0,
                'resolved' => 0,
            );
        }
        $now  = isset( $entry['issues'] ) ? (array) $entry['issues'] : array();
        $then = isset( $older['issues'] ) ? (array) $older['issues'] : array();
        return array(
            'first'    => false,
            'new'      => count( array_diff( $now, $then ) ),
            'resolved' => count( array_diff( $then, $now ) ),
        );
    }
}

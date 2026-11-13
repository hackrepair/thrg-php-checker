<?php
/** Server PHP lifecycle and security advisory evaluator.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

/** Evaluates server PHP EOL status, patch levels, and security advisories. */
final class THRG_PC_Server_Advisory {

    /**
     * Days remaining until a given EOL date.
     *
     * @param string $eol_date YYYY-MM-DD.
     * @param int    $now      Optional reference timestamp.
     * @return int
     */
    public static function eol_days_left( $eol_date, $now = 0 ) {
        $eol_ts = strtotime( $eol_date . ' 23:59:59 UTC' );
        $ref    = $now > 0 ? (int) $now : time();
        return (int) floor( ( $eol_ts - $ref ) / 86400 );
    }

    /**
     * Evaluate an EOL date into status and days remaining.
     *
     * @param string $eol_date YYYY-MM-DD date string.
     * @param int    $now      Reference timestamp (0 for current time).
     * @return array{status:string,days:int}
     */
    public static function eol_status( $eol_date, $now = 0 ) {
        $days = self::eol_days_left( $eol_date, $now );
        if ( $days < 0 ) {
            return array(
                'status' => 'Potential weakness',
                'days'   => $days,
            );
        }
        if ( $days <= 180 ) {
            return array(
                'status' => 'Review',
                'days'   => $days,
            );
        }
        return array(
            'status' => 'No issue observed',
            'days'   => $days,
        );
    }

    /**
     * Evaluate a PHP version string against PHP_SUPPORT.
     *
     * @param string $php_version Full PHP version string.
     * @param int    $now         Reference timestamp (0 for current time).
     * @return array{branch:string,eol:string,days:int|null,status:string,host_note:string}
     */
    public static function php_status( $php_version, $now = 0 ) {
        $parts     = explode( '.', (string) $php_version );
        $branch    = isset( $parts[0], $parts[1] ) ? $parts[0] . '.' . $parts[1] : (string) $php_version;
        $host_note = isset( THRG_PHP_Checker::HOST_REMOVALS[ $branch ] ) ? THRG_PHP_Checker::HOST_REMOVALS[ $branch ] : '';

        if ( isset( THRG_PHP_Checker::PHP_SUPPORT[ $branch ] ) ) {
            $eol   = THRG_PHP_Checker::PHP_SUPPORT[ $branch ]['eol'];
            $check = self::eol_status( $eol, $now );
            return array(
                'branch'    => $branch,
                'eol'       => $eol,
                'days'      => $check['days'],
                'status'    => $check['status'],
                'host_note' => $host_note,
            );
        }

        if ( version_compare( $branch, '7.4', '<' ) ) {
            return array(
                'branch'    => $branch,
                'eol'       => 'past end-of-life',
                'days'      => -1,
                'status'    => 'Potential weakness',
                'host_note' => '',
            );
        }

        return array(
            'branch'    => $branch,
            'eol'       => 'not in this plugin version table',
            'days'      => null,
            'status'    => 'Review',
            'host_note' => '',
        );
    }

    /**
     * Check whether a full PHP version is behind the latest patch release known to this build.
     *
     * @param string $php_version Full PHP version string.
     * @param int    $now         Reference timestamp (0 for current time).
     * @return array{known:bool,current:string,latest:string,behind:bool,stale_list:bool,as_of:string,advisories:array}
     */
    public static function php_patch( $php_version, $now = 0 ) {
        $clean   = preg_replace( '/^(\d+\.\d+\.\d+).*$/', '$1', (string) $php_version );
        $parts   = explode( '.', $clean );
        $branch  = isset( $parts[0], $parts[1] ) ? $parts[0] . '.' . $parts[1] : '';
        $ref     = $now > 0 ? (int) $now : time();
        $list_ts = strtotime( THRG_PHP_Checker::PATCH_LIST_AS_OF . ' 00:00:00 UTC' );
        $stale   = $list_ts && ( ( $ref - $list_ts ) / 86400 ) > THRG_PHP_Checker::PATCH_LIST_STALE_DAYS;

        if ( ! isset( THRG_PHP_Checker::PHP_SUPPORT[ $branch ]['patched'] ) || substr_count( $clean, '.' ) < 2 ) {
            return array(
                'known'      => false,
                'current'    => $clean,
                'latest'     => '',
                'behind'     => false,
                'stale_list' => $stale,
                'as_of'      => THRG_PHP_Checker::PATCH_LIST_AS_OF,
                'advisories' => array(),
            );
        }

        $latest     = THRG_PHP_Checker::PHP_SUPPORT[ $branch ]['patched'];
        $advisories = array();
        if ( isset( THRG_PHP_Checker::PHP_ADVISORIES[ $branch ] ) ) {
            foreach ( THRG_PHP_Checker::PHP_ADVISORIES[ $branch ] as $advisory ) {
                if ( version_compare( $clean, $advisory['fixed'], '<' ) ) {
                    $advisories[] = $advisory;
                }
            }
        }

        return array(
            'known'      => true,
            'current'    => $clean,
            'latest'     => $latest,
            'behind'     => version_compare( $clean, $latest, '<' ),
            'stale_list' => $stale,
            'as_of'      => THRG_PHP_Checker::PATCH_LIST_AS_OF,
            'advisories' => $advisories,
        );
    }

    /**
     * Migration manual link key for a target PHP version.
     *
     * @param string $target Target PHP version ("8.4").
     * @return string
     */
    public static function migration_link_key( $target ) {
        $key = 'php-migration-' . str_replace( '.', '', (string) $target );
        return isset( THRG_PHP_Checker::LINKS[ $key ] ) ? $key : 'php-supported-versions';
    }
}

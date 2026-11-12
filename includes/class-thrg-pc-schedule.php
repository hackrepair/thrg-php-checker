<?php
/**
 * Monthly PHP compatibility email: runs a scan on a chosen day and emails administrators.
 *
 * Off by default. Uses one single scheduled event at a time, rescheduled after
 * each run, with a 10-minute run lock and heal() on admin_init.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scheduled monthly PHP compatibility check and email.
 */
final class THRG_PC_Schedule {

    const OPTION       = 'thrg_pc_schedule';
    const LOG          = 'thrg_pc_schedule_log';
    const HOOK         = 'thrg_pc_scheduled_report';
    const LOCK         = 'thrg_pc_schedule_lock';
    const HOUR         = 8;
    const LAST_DAY     = 28;
    const LATE_SECONDS = 21600;
    const RUN_BY       = 'Scheduled email';

    /**
     * Register hooks.
     *
     * @return void
     */
    public static function boot() {
        add_action( self::HOOK, array( __CLASS__, 'run' ) );
        add_action( 'admin_post_thrg_pc_schedule', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_init', array( __CLASS__, 'heal' ) );
    }

    /**
     * Default schedule settings.
     *
     * @return array
     */
    public static function defaults() {
        return array(
            'enabled'     => 0,
            'frequency'   => 'weekly',
            'weekday'     => 1,
            'target'      => 'auto',
            'day'         => 1,
            'only_issues' => 0,
            'who'         => 'all',
            'users'       => array(),
            'note'        => '',
            'owner'       => 0,
        );
    }

    /**
     * Normalize stored or submitted schedule settings.
     *
     * @param mixed $s Raw settings.
     * @return array
     */
    public static function normalize( $s ) {
        $s       = array_merge( self::defaults(), is_array( $s ) ? $s : array() );
        $day     = (int) $s['day'];
        $weekday = (int) $s['weekday'];
        $freq    = isset( $s['frequency'] ) && 'monthly' === $s['frequency'] ? 'monthly' : 'weekly';
        $ids     = array();
        foreach ( (array) $s['users'] as $id ) {
            $id = is_scalar( $id ) ? (int) $id : 0;
            if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
                $ids[] = $id;
            }
        }
        $note    = trim( (string) ( is_scalar( $s['note'] ) ? $s['note'] : '' ) );
        $targets = array_merge( array( 'auto' ), THRG_PHP_Checker::scan_targets() );
        $target  = is_scalar( $s['target'] ) && in_array( (string) $s['target'], $targets, true ) ? (string) $s['target'] : 'auto';
        return array(
            'enabled'     => empty( $s['enabled'] ) ? 0 : 1,
            'frequency'   => $freq,
            'weekday'     => max( 0, min( 6, $weekday ) ),
            'target'      => $target,
            'day'         => max( 1, min( self::LAST_DAY, $day ) ),
            'only_issues' => empty( $s['only_issues'] ) ? 0 : 1,
            'who'         => 'chosen' === $s['who'] ? 'chosen' : 'all',
            'users'       => array_slice( $ids, 0, THRG_PC_Email::MAX_RECIPIENTS ),
            'note'        => function_exists( 'mb_substr' ) ? mb_substr( $note, 0, THRG_PC_Email::NOTE_LENGTH ) : substr( $note, 0, THRG_PC_Email::NOTE_LENGTH ),
            'owner'       => max( 0, (int) $s['owner'] ),
        );
    }

    /**
     * Resolve the concrete target PHP version for a scheduled run.
     *
     * @param array $s Normalized settings.
     * @return string
     */
    public static function resolve_target( array $s ) {
        if ( isset( $s['target'] ) && in_array( (string) $s['target'], THRG_PHP_Checker::scan_targets(), true ) ) {
            return (string) $s['target'];
        }
        return THRG_PHP_Checker::default_target();
    }

    /**
     * Compute next due timestamp on $day (or $weekday when weekly) at HOUR site time strictly after $now.
     *
     * @param int          $now       Current timestamp.
     * @param int          $day       Day of month (1-28).
     * @param DateTimeZone $tz        Site timezone.
     * @param string       $frequency 'monthly' or 'weekly'.
     * @param int          $weekday   Day of week (0=Sunday .. 6=Saturday).
     * @return int
     */
    public static function next_run( $now, $day, DateTimeZone $tz, $frequency = 'monthly', $weekday = 1 ) {
        $local = ( new DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz );
        if ( 'weekly' === $frequency ) {
            $weekday = max( 0, min( 6, (int) $weekday ) );
            $cur_dow = (int) $local->format( 'w' );
            $delta   = ( $weekday - $cur_dow + 7 ) % 7;
            $due     = $local->modify( '+' . $delta . ' days' )->setTime( self::HOUR, 0, 0 );
            if ( $due->getTimestamp() <= $now ) {
                $due = $local->modify( '+7 days' )->setTime( self::HOUR, 0, 0 );
            }
            return $due->getTimestamp();
        }

        $day = max( 1, min( self::LAST_DAY, (int) $day ) );
        $due = $local->setDate( (int) $local->format( 'Y' ), (int) $local->format( 'n' ), $day )->setTime( self::HOUR, 0, 0 );
        if ( $due->getTimestamp() <= $now ) {
            $due = $local->setDate( (int) $local->format( 'Y' ), (int) $local->format( 'n' ) + 1, $day )->setTime( self::HOUR, 0, 0 );
        }
        return $due->getTimestamp();
    }

    /**
     * Filter current administrators according to schedule settings.
     *
     * @param array[] $admins Output of THRG_PC_Email::recipients().
     * @param array   $s      Normalized settings.
     * @return array[]
     */
    public static function pick( array $admins, array $s ) {
        if ( 'all' === $s['who'] ) {
            return $admins;
        }
        return array_values(
            array_filter(
                $admins,
                static function ( $r ) use ( $s ) {
                    return in_array( (int) $r['id'], $s['users'], true );
                }
            )
        );
    }

    /**
     * Whether the report should be emailed given $only_issues.
     *
     * @param array[] $rows        Evaluated findings.
     * @param bool    $only_issues True to send only when issues or warnings exist.
     * @return bool
     */
    public static function worth_sending( array $rows, $only_issues ) {
        if ( ! $only_issues ) {
            return true;
        }
        $g = THRG_PC_Email::groups( $rows );
        return (bool) ( $g['fix'] || $g['look'] );
    }

    /**
     * Describe the last scheduled run for the settings UI.
     *
     * @param array|null $log  Last run log.
     * @param string     $when Formatted date.
     * @return string
     */
    public static function describe( $log, $when ) {
        if ( ! is_array( $log ) || empty( $log['result'] ) ) {
            return 'Not sent yet.';
        }
        $sent   = (int) ( isset( $log['sent'] ) ? $log['sent'] : 0 );
        $failed = (int) ( isset( $log['failed'] ) ? $log['failed'] : 0 );
        switch ( $log['result'] ) {
            case 'sent':
                return sprintf( 'Last sent %s to %d administrator%s.', $when, $sent, 1 === $sent ? '' : 's' );
            case 'partial':
                return sprintf( 'Last run %s: %d sent, %d failed. Check your site\'s email setup.', $when, $sent, $failed );
            case 'failed':
                return sprintf( 'Last run %s: WordPress could not send the email. Check your site\'s email setup.', $when );
            case 'skipped':
                return sprintf( 'Last run %s: nothing needed attention, so no email was sent.', $when );
            case 'no-recipients':
                return sprintf( 'Last run %s: none of the chosen administrators could be emailed, so nothing was sent.', $when );
        }
        return 'Not sent yet.';
    }

    /**
     * Get normalized schedule settings.
     *
     * @return array
     */
    public static function settings() {
        return self::normalize( get_option( self::OPTION, array() ) );
    }

    /**
     * Get the last run log record.
     *
     * @return array|null
     */
    public static function last_run() {
        $log = get_option( self::LOG, null );
        return is_array( $log ) ? $log : null;
    }

    /**
     * Replace any pending scheduled event with the next due time (or clear when disabled).
     *
     * @return void
     */
    public static function reschedule() {
        wp_clear_scheduled_hook( self::HOOK );
        $s = self::settings();
        if ( $s['enabled'] ) {
            wp_schedule_single_event(
                self::next_run( time(), $s['day'], wp_timezone(), $s['frequency'], $s['weekday'] ),
                self::HOOK
            );
        }
    }

    /**
     * Restore a missing scheduled event when enabled.
     *
     * @return void
     */
    public static function heal() {
        $s = self::settings();
        if ( $s['enabled'] && ! wp_next_scheduled( self::HOOK ) ) {
            self::reschedule();
        }
    }

    /**
     * Resolve sender name and email for scheduled runs.
     *
     * @param int $user_id Owner user ID.
     * @return array{name:string,email:string}
     */
    private static function sender( $user_id ) {
        $user = $user_id ? get_userdata( $user_id ) : false;
        if ( $user && user_can( $user, 'manage_options' ) ) {
            return array(
                'name'  => $user->display_name ? $user->display_name : $user->user_login,
                'email' => $user->user_email,
            );
        }
        return array(
            'name'  => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
            'email' => '',
        );
    }

    /**
     * Execute the scheduled weekly or monthly PHP compatibility check and send emails.
     *
     * @return array
     */
    public static function run() {
        $s = self::settings();
        if ( ! $s['enabled'] ) {
            return array();
        }
        if ( get_transient( self::LOCK ) ) {
            return array( 'result' => 'busy' );
        }
        set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );

        try {
            $now    = time();
            $target = self::resolve_target( $s );
            $run_by = 'weekly' === $s['frequency'] ? 'Weekly email' : 'Monthly email';
            if ( '' === THRG_PC_Engine::unavailable_reason() ) {
                THRG_PC_Scanner::start( $target, false, 'all', '', 0, $run_by );
                THRG_PC_Scanner::step( microtime( true ) + 30 );
                if ( THRG_PC_Prescan::pending_offers() ) {
                    THRG_PC_Prescan::step( microtime( true ) + 15, true );
                }
            }
            $rows = THRG_PHP_Checker::evaluate( THRG_PHP_Checker::collect( $target ) );
            $id   = THRG_PC_History::add( $rows, $target, wp_parse_url( home_url(), PHP_URL_HOST ), THRG_PHP_Checker::version(), 0, $run_by, $now );
            $all  = THRG_PC_History::all();

            $entry = array(
                'id'     => (string) $id,
                'target' => $target,
                'time'   => $now,
                'rows'   => $rows,
            );
            $older = null;
            foreach ( $all as $i => $e ) {
                if ( $id && $e['id'] === $id ) {
                    $entry = $e;
                    $older = THRG_PC_History::previous_for( $e, $all, $i );
                    break;
                }
            }

            $log = array(
                'time'   => $now,
                'report' => (string) $id,
                'sent'   => 0,
                'failed' => 0,
            );
            $to  = self::pick( THRG_PC_Email::recipients(), $s );
            if ( ! self::worth_sending( $rows, $s['only_issues'] ) ) {
                $log['result'] = 'skipped';
            } elseif ( ! $to ) {
                $log['result'] = 'no-recipients';
            } else {
                $result        = THRG_PC_Email::send( $entry, $older, wp_list_pluck( $to, 'id' ), $s['note'], self::sender( $s['owner'] ), true );
                $log['sent']   = (int) $result['sent'];
                $log['failed'] = (int) $result['failed'];
                $log['result'] = $result['sent'] ? ( $result['failed'] ? 'partial' : 'sent' ) : 'failed';
            }
            update_option( self::LOG, $log, false );
            return $log;
        } finally {
            delete_transient( self::LOCK );
            self::reschedule();
        }
    }

    /**
     * Save schedule settings from the admin form.
     *
     * @return void
     */
    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'thrg-php-checker' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'thrg_pc_schedule' );
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce checked above.
        $raw = array(
            'enabled'     => ! empty( $_POST['enabled'] ),
            'frequency'   => isset( $_POST['frequency'] ) ? sanitize_key( wp_unslash( $_POST['frequency'] ) ) : 'weekly',
            'weekday'     => isset( $_POST['weekday'] ) ? absint( $_POST['weekday'] ) : 1,
            'target'      => isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : 'auto',
            'day'         => isset( $_POST['day'] ) ? absint( $_POST['day'] ) : 1,
            'only_issues' => isset( $_POST['only_issues'] ) && '1' === $_POST['only_issues'],
            'who'         => isset( $_POST['who'] ) ? sanitize_key( wp_unslash( $_POST['who'] ) ) : 'all',
            'users'       => isset( $_POST['users'] ) ? array_map( 'absint', array_filter( (array) wp_unslash( $_POST['users'] ), 'is_scalar' ) ) : array(),
            'note'        => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
            'owner'       => get_current_user_id(),
        );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $s    = self::normalize( $raw );
        $flag = 'schedule-saved';
        if ( 'chosen' === $s['who'] && ! self::pick( THRG_PC_Email::recipients(), $s ) ) {
            $s['enabled'] = 0;
            $flag         = 'schedule-none';
        }
        update_option( self::OPTION, $s, false );
        self::reschedule();
        wp_safe_redirect( add_query_arg( 'thrg_pc_done', $flag, admin_url( THRG_PHP_Checker::PAGE_URL ) ) . '#pc-schedule' );
        exit;
    }
}

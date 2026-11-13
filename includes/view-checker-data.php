<?php
/** Prepare data for the PHP Checker admin view. */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included in render_page().

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report ID validated via THRG_PC_History::valid_id().
$report_id    = isset( $_GET['thrg_report'] ) ? sanitize_key( wp_unslash( $_GET['thrg_report'] ) ) : '';
$viewed_entry = THRG_PC_History::valid_id( $report_id ) ? THRG_PC_History::find( $report_id ) : null;

$observations = THRG_PHP_Checker::collect( $selected_target );
$components   = $observations['components'];
$summaries    = THRG_PC_Results::target_summaries( $scan_targets, $components );

if ( $viewed_entry ) {
    $rows            = $viewed_entry['rows'];
    $selected_target = ! empty( $viewed_entry['target'] ) ? (string) $viewed_entry['target'] : $selected_target;
    $site_host       = ! empty( $viewed_entry['host'] ) ? (string) $viewed_entry['host'] : (string) wp_parse_url( home_url(), PHP_URL_HOST );
    $generated_at    = wp_date( 'Y-m-d H:i:s T', (int) $viewed_entry['time'] );
} else {
    $rows         = THRG_PHP_Checker::evaluate( $observations );
    $site_host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
    $generated_at = wp_date( 'Y-m-d H:i:s T' );
}

$plain_report = THRG_PHP_Checker::plain_text( $rows, $site_host, $generated_at, THRG_PHP_Checker::version(), $selected_target );

$counts        = array(
    'attention' => 0,
    'review'    => 0,
    'clean'     => 0,
);
$primary_rows  = array();
$clean_comps   = array();
$nav_attention = array();
$nav_review    = array();
$broken_names  = array();
$has_fatal_err = false;
$warn_comps    = 0;
$has_tech_rows = 0;

foreach ( $rows as $row ) {
    if ( 'Potential weakness' === $row['status'] ) {
        ++$counts['attention'];
    } elseif ( 'No issue observed' === $row['status'] ) {
        ++$counts['clean'];
    } else {
        ++$counts['review'];
    }

    if ( ! empty( $row['comp_key'] ) && 'No issue observed' === $row['status'] ) {
        $clean_comps[] = $row;
    } else {
        $primary_rows[] = $row;
        if ( 'Potential weakness' === $row['status'] ) {
            $nav_attention[] = $row;
            if ( ! empty( $row['comp_name'] ) ) {
                $broken_names[] = (string) $row['comp_name'];
            }
            if ( ! isset( $row['fatal_errors'] ) || (int) $row['fatal_errors'] > 0 ) {
                $has_fatal_err = true;
            }
        } else {
            $nav_review[] = $row;
            if ( ! empty( $row['comp_key'] ) ) {
                ++$warn_comps;
            }
        }
        if ( ! empty( $row['findings'] ) && is_array( $row['findings'] ) ) {
            ++$has_tech_rows;
        }
    }
}

$scan_state   = THRG_PC_Scanner::get_state();
$scan_running = THRG_PC_Scanner::is_running( $scan_state );
$scan_owned   = $scan_running && isset( $scan_state['user_id'] ) && ( 0 === (int) $scan_state['user_id'] || get_current_user_id() === (int) $scan_state['user_id'] );
$scanned_now  = count( $observations['results'] );
$total_comps  = count( $components );

$history_all     = THRG_PC_History::all();
$history_enabled = THRG_PC_History::enabled();
$runtime_groups  = THRG_PC_Runtime_Log::grouped( $components );
$schedule        = THRG_PC_Schedule::settings();
$schedule_log    = THRG_PC_Schedule::last_run();
$schedule_next   = wp_next_scheduled( THRG_PC_Schedule::HOOK );
$admin_users     = THRG_PC_Email::recipients();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status notice parameters after redirect.
$mail_flag = isset( $_GET['thrg_pc_mail'] ) ? sanitize_key( wp_unslash( $_GET['thrg_pc_mail'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$mail_sent = isset( $_GET['thrg_pc_sent'] ) ? absint( $_GET['thrg_pc_sent'] ) : 0;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$mail_fail = isset( $_GET['thrg_pc_failed'] ) ? absint( $_GET['thrg_pc_failed'] ) : 0;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$hist_flag = isset( $_GET['thrg_pc_history'] ) ? sanitize_key( wp_unslash( $_GET['thrg_pc_history'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$set_flag = isset( $_GET['thrg_pc_settings'] ) ? sanitize_key( wp_unslash( $_GET['thrg_pc_settings'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$rt_flag = isset( $_GET['thrg_pc_runtime'] ) ? sanitize_key( wp_unslash( $_GET['thrg_pc_runtime'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$done_flag = isset( $_GET['thrg_pc_done'] ) ? sanitize_key( wp_unslash( $_GET['thrg_pc_done'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sched_ok = ! empty( $_GET['thrg_pc_schedule_saved'] ) || 'schedule-saved' === $done_flag;

$status_class = static function ( $status ) {
    if ( 'Potential weakness' === $status ) {
        return 'thrg-status-weakness';
    }
    if ( 'No issue observed' === $status ) {
        return 'thrg-status-ok';
    }
    return 'thrg-status-review';
};

$badge_label = static function ( array $row ) {
    if ( ! empty( $row['badge_text'] ) ) {
        return (string) $row['badge_text'];
    }
    if ( 'Potential weakness' === $row['status'] ) {
        return __( 'Action needed', 'thrg-php-checker' );
    }
    if ( 'No issue observed' === $row['status'] ) {
        return __( 'Compatible', 'thrg-php-checker' );
    }
    return __( 'Review', 'thrg-php-checker' );
};

// Build the plain-English top verdict banner.
if ( ! empty( $observations['engine_reason'] ) ) {
    $verdict_tone  = 'weakness';
    $verdict_title = __( 'Scanner engine unavailable on this server', 'thrg-php-checker' );
    $verdict_desc  = (string) $observations['engine_reason'];
} elseif ( 0 === $scanned_now && ! $viewed_entry ) {
    $verdict_tone  = 'neutral';
    $verdict_title = sprintf(
        /* translators: %s: target PHP version */
        __( 'Step 1: Click "Scan for PHP %s" above to test your plugins and themes', 'thrg-php-checker' ),
        $selected_target
    );
    $verdict_desc = sprintf(
        /* translators: 1: current PHP version, 2: total component count */
        __( 'Your server currently runs PHP %1$s. A static scan inspects your %2$d installed components safely in the background without executing code or slowing down site visitors.', 'thrg-php-checker' ),
        PHP_VERSION,
        $total_comps
    );
} elseif ( ! empty( $broken_names ) ) {
    $verdict_tone = 'weakness';
    $shown_names  = implode( ', ', array_slice( $broken_names, 0, 3 ) );
    if ( $has_fatal_err ) {
        $verdict_title = sprintf(
            /* translators: 1: target PHP version, 2: count of incompatible components, 3: component names */
            _n(
                'Hold off on upgrading to PHP %1$s — %2$d component has fatal compatibility errors (%3$s)',
                'Hold off on upgrading to PHP %1$s — %2$d components have fatal compatibility errors (%3$s)',
                count( $broken_names ),
                'thrg-php-checker'
            ),
            $selected_target,
            count( $broken_names ),
            $shown_names
        );
        $verdict_desc = sprintf(
            /* translators: %s: target PHP version */
            __( 'The red item(s) at the top of the report below contain code that can cause a fatal error on PHP %s. Update, replace, or deactivate them before switching PHP versions in your hosting panel.', 'thrg-php-checker' ),
            $selected_target
        );
    } else {
        $verdict_title = sprintf(
            /* translators: 1: target PHP version, 2: count of components with compatibility errors, 3: component names */
            _n(
                'Review before upgrading to PHP %1$s — %2$d component has non-fatal compatibility errors (%3$s)',
                'Review before upgrading to PHP %1$s — %2$d components have non-fatal compatibility errors (%3$s)',
                count( $broken_names ),
                'thrg-php-checker'
            ),
            $selected_target,
            count( $broken_names ),
            $shown_names
        );
        $verdict_desc = sprintf(
            /* translators: %s: target PHP version */
            __( 'The red item(s) below contain unguarded PHP %s compatibility errors (such as removed INI directives or behavioral changes) rather than hard fatal crashes. Review, update, or test them before switching PHP versions.', 'thrg-php-checker' ),
            $selected_target
        );
    }
} elseif ( $warn_comps > 0 ) {
    $verdict_tone  = 'ok';
    $verdict_title = sprintf(
        /* translators: 1: target PHP version, 2: count of components with warnings */
        _n(
            'Good news: 0 fatal PHP %1$s errors found (%2$d component has minor deprecation notices)',
            'Good news: 0 fatal PHP %1$s errors found (%2$d components have minor deprecation notices)',
            $warn_comps,
            'thrg-php-checker'
        ),
        $selected_target,
        $warn_comps
    );
    $verdict_desc = sprintf(
        /* translators: %s: target PHP version */
        __( 'All scanned plugins and themes can run on PHP %s. Deprecation warnings are early heads-up notices for developers about future PHP versions — they will not crash your site today (keep WP_DEBUG_DISPLAY off on live sites).', 'thrg-php-checker' ),
        $selected_target
    );
} else {
    $verdict_tone  = 'ok';
    $verdict_title = sprintf(
        /* translators: 1: count of scanned components, 2: target PHP version */
        __( 'All clear! All %1$d scanned components passed PHP %2$s compatibility checks', 'thrg-php-checker' ),
        $scanned_now,
        $selected_target
    );
    $verdict_desc = __( 'Zero fatal errors or deprecation warnings were found across your scanned plugins and themes. Always test key pages (such as checkout or forms) after switching PHP versions.', 'thrg-php-checker' );
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

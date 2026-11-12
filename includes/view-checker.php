<?php
/**
 * Admin view for Tools > PHP Checker.
 *
 * Renders the THRG brand header, target PHP switcher & resumable scan bar,
 * plain-English readiness verdict banner, summary tiles, grouped navigation,
 * streamlined component finding cards, collapsible clean components, and
 * compact tools/history/settings panels.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to THRG_PHP_Checker::render_page().

$settings        = THRG_PHP_Checker::settings();
$scan_targets    = THRG_PHP_Checker::scan_targets();
$default_target  = THRG_PHP_Checker::default_target();
$selected_target = THRG_PHP_Checker::selected_target();
$running_php     = THRG_PHP_Checker::running_php();

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
$scan_running = ! empty( $scan_state ) && ( ! empty( $scan_state['current'] ) || ! empty( $scan_state['queue'] ) );
$scanned_now  = count( $observations['results'] );
$total_comps  = count( $components );

$history_all     = THRG_PC_History::all();
$history_enabled = THRG_PC_History::enabled();
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
$sched_ok = ! empty( $_GET['thrg_pc_schedule_saved'] );

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
    $verdict_tone  = 'weakness';
    $shown_names   = implode( ', ', array_slice( $broken_names, 0, 3 ) );
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
?>
<div class="wrap thrg-inspector-wrap thrg-pc-wrap">
    <header class="thrg-brand-header">
        <div class="thrg-brand-header__inner">
            <a class="thrg-brand-logo" href="https://hackrepair.com/" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'The Hack Repair Guy website (opens in a new tab)', 'thrg-php-checker' ); ?>">
                <img src="<?php echo esc_url( plugins_url( 'assets/brand/thrg-heart-128.png', THRG_PHP_CHECKER_FILE ) ); ?>" alt="" width="48" height="48" />
            </a>
            <div class="thrg-brand-header__text">
                <div class="thrg-brand-eyebrow"><?php esc_html_e( "The Hack Repair Guy's", 'thrg-php-checker' ); ?></div>
                <h1 class="thrg-brand-title">
                    <?php esc_html_e( 'PHP Checker', 'thrg-php-checker' ); ?>
                    <span class="thrg-version-pill">v<?php echo esc_html( THRG_PHP_Checker::version() ); ?></span>
                </h1>
                <p class="thrg-brand-subtitle">
                    <?php esc_html_e( 'Check if your plugins and themes are ready for PHP 8.1–8.5 before you upgrade — with automatic version-guard filtering to eliminate false alarms.', 'thrg-php-checker' ); ?>
                </p>
            </div>
            <div class="thrg-pc-header-meta">
                <div class="thrg-pc-meta-pill">
                    <span class="thrg-pc-meta-label"><?php esc_html_e( 'Current Server PHP', 'thrg-php-checker' ); ?></span>
                    <strong><?php echo esc_html( PHP_VERSION ); ?></strong>
                </div>
                <div class="thrg-pc-meta-pill thrg-pc-meta-pill--target">
                    <span class="thrg-pc-meta-label"><?php esc_html_e( 'Testing Target PHP', 'thrg-php-checker' ); ?></span>
                    <strong><?php echo esc_html( $selected_target ); ?></strong>
                </div>
            </div>
        </div>
    </header>

    <?php if ( 'emailed' === $mail_flag || 'mail-partial' === $mail_flag ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( THRG_PC_Email::feedback( $mail_sent, $mail_fail ) ); ?></p></div>
    <?php elseif ( 'mail-failed' === $mail_flag ) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html( THRG_PC_Email::feedback( 0, $mail_fail ) ); ?></p></div>
    <?php elseif ( 'mail-none' === $mail_flag ) : ?>
        <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Select at least one administrator recipient before sending an email report.', 'thrg-php-checker' ); ?></p></div>
    <?php elseif ( 'mail-wait' === $mail_flag ) : ?>
        <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Please wait a minute before sending another report email.', 'thrg-php-checker' ); ?></p></div>
    <?php endif; ?>

    <?php if ( 'deleted' === $hist_flag ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved report deleted from history.', 'thrg-php-checker' ); ?></p></div>
    <?php elseif ( 'cleared' === $hist_flag ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Report history cleared.', 'thrg-php-checker' ); ?></p></div>
    <?php elseif ( 'paused' === $hist_flag ) : ?>
        <div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Report history paused. New scans will not be added to history until resumed.', 'thrg-php-checker' ); ?></p></div>
    <?php elseif ( 'resumed' === $hist_flag ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Report history resumed.', 'thrg-php-checker' ); ?></p></div>
    <?php endif; ?>

    <?php if ( 'saved' === $set_flag ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'PHP Checker settings saved.', 'thrg-php-checker' ); ?></p></div>
    <?php elseif ( 'cleared' === $set_flag ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Cached scan results cleared.', 'thrg-php-checker' ); ?></p></div>
    <?php endif; ?>

    <?php if ( $sched_ok ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Monthly PHP readiness email schedule updated.', 'thrg-php-checker' ); ?></p></div>
    <?php endif; ?>

    <?php if ( $viewed_entry ) : ?>
        <div class="thrg-pc-banner thrg-pc-banner--history">
            <div>
                <strong>
                    <?php
                    printf(
                        /* translators: 1: formatted date, 2: target PHP version */
                        esc_html__( 'Viewing saved historical report from %1$s (Target: PHP %2$s).', 'thrg-php-checker' ),
                        esc_html( $generated_at ),
                        esc_html( $selected_target )
                    );
                    ?>
                </strong>
                <?php if ( ! empty( $viewed_entry['name'] ) ) : ?>
                    <span>
                        <?php
                        printf(
                            /* translators: %s: user login or schedule label */
                            esc_html__( 'Run by %s.', 'thrg-php-checker' ),
                            esc_html( $viewed_entry['name'] )
                        );
                        ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php
            $live_view_url = add_query_arg(
                array(
                    'page'   => THRG_PHP_Checker::SLUG,
                    'target' => $selected_target,
                ),
                admin_url( 'tools.php' )
            );
            ?>
            <a class="button button-secondary" href="<?php echo esc_url( $live_view_url ); ?>">
                <?php esc_html_e( 'Return to Live View', 'thrg-php-checker' ); ?>
            </a>
        </div>
    <?php endif; ?>

    <!-- Scan Toolbar & Target Switcher -->
    <section id="pc-scan-bar" class="thrg-pc-toolbar" aria-label="<?php esc_attr_e( 'PHP Compatibility Scanner Controls', 'thrg-php-checker' ); ?>">
        <form id="thrg-pc-scan-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="thrg-pc-toolbar__form">
            <input type="hidden" name="action" value="thrg_pc_start" />
            <?php wp_nonce_field( 'thrg_pc_start' ); ?>

            <div class="thrg-pc-toolbar__field">
                <label for="thrg-pc-target"><?php esc_html_e( '1. Choose PHP version to test', 'thrg-php-checker' ); ?></label>
                <select id="thrg-pc-target" name="target">
                    <?php foreach ( $scan_targets as $t ) : ?>
                        <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $selected_target, $t ); ?>>
                            <?php
                            $label = 'PHP ' . $t;
                            if ( $t === $running_php ) {
                                $label .= ' (' . __( 'current server', 'thrg-php-checker' ) . ')';
                            } elseif ( $t === $default_target ) {
                                $label .= ' (' . __( 'recommended upgrade', 'thrg-php-checker' ) . ')';
                            }
                            if ( isset( $summaries[ $t ] ) ) {
                                $label .= sprintf( ' — %d/%d scanned', $summaries[ $t ]['total'], $total_comps );
                            }
                            echo esc_html( $label );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="thrg-pc-toolbar__field">
                <label for="thrg-pc-scope"><?php esc_html_e( '2. Choose what to scan', 'thrg-php-checker' ); ?></label>
                <select id="thrg-pc-scope" name="scope">
                    <option value="active" <?php selected( $settings['default_scope'], 'active' ); ?>>
                        <?php esc_html_e( 'Active plugins, mu-plugins & active theme (faster)', 'thrg-php-checker' ); ?>
                    </option>
                    <option value="all" <?php selected( $settings['default_scope'], 'all' ); ?>>
                        <?php
                        printf(
                            /* translators: %d: total component count */
                            esc_html__( 'All installed plugins, mu-plugins & themes (%d total)', 'thrg-php-checker' ),
                            (int) $total_comps
                        );
                        ?>
                    </option>
                </select>
            </div>

            <div class="thrg-pc-toolbar__actions">
                <button type="submit" id="thrg-pc-btn-scan" class="button button-primary">
                    <?php
                    if ( 0 === $scanned_now ) {
                        printf(
                            /* translators: %s: target PHP version */
                            esc_html__( 'Scan for PHP %s', 'thrg-php-checker' ),
                            esc_html( $selected_target )
                        );
                    } elseif ( $scanned_now < $total_comps ) {
                        esc_html_e( 'Scan Unchecked', 'thrg-php-checker' );
                    } else {
                        esc_html_e( 'Scan', 'thrg-php-checker' );
                    }
                    ?>
                </button>
                <?php if ( $scanned_now > 0 ) : ?>
                    <button type="submit" id="thrg-pc-btn-rescan" name="rescan" value="1" class="button button-secondary">
                        <?php esc_html_e( 'Rescan All', 'thrg-php-checker' ); ?>
                    </button>
                <?php endif; ?>
                <button type="submit" id="thrg-pc-btn-stop" name="stop" value="1" class="button button-link-delete" <?php echo $scan_running ? '' : 'hidden'; ?>>
                    <?php esc_html_e( 'Stop Scan', 'thrg-php-checker' ); ?>
                </button>
            </div>
        </form>

        <?php if ( ! empty( $summaries ) ) : ?>
            <div class="thrg-pc-target-pills" role="navigation" aria-label="<?php esc_attr_e( 'Scanned PHP Targets', 'thrg-php-checker' ); ?>">
                <span class="thrg-pc-target-pills__label"><?php esc_html_e( 'Quick switch:', 'thrg-php-checker' ); ?></span>
                <?php foreach ( $scan_targets as $t ) : ?>
                    <?php
                    $has_t = isset( $summaries[ $t ] );
                    $url_t = add_query_arg(
                        array(
                            'page'   => THRG_PHP_Checker::SLUG,
                            'target' => $t,
                        ),
                        admin_url( 'tools.php' )
                    );
                    $cls   = 'thrg-pc-target-pill';
                    if ( $t === $selected_target ) {
                        $cls .= ' is-active';
                    }
                    if ( $has_t && $summaries[ $t ]['breaks'] > 0 ) {
                        $cls .= ' has-breaks';
                    } elseif ( $has_t && $summaries[ $t ]['warnings'] > 0 ) {
                        $cls .= ' has-warnings';
                    } elseif ( $has_t ) {
                        $cls .= ' is-clean';
                    }
                    ?>
                    <a href="<?php echo esc_url( $url_t ); ?>" class="<?php echo esc_attr( $cls ); ?>">
                        <strong>PHP <?php echo esc_html( $t ); ?></strong>
                        <?php if ( $has_t ) : ?>
                            <span>
                                <?php
                                if ( $summaries[ $t ]['breaks'] > 0 ) {
                                    printf(
                                        /* translators: %d: count of incompatible components */
                                        esc_html__( '%d incompatible', 'thrg-php-checker' ),
                                        (int) $summaries[ $t ]['breaks']
                                    );
                                } elseif ( $summaries[ $t ]['warnings'] > 0 ) {
                                    printf(
                                        /* translators: %d: count of components with warnings */
                                        esc_html__( '%d warnings', 'thrg-php-checker' ),
                                        (int) $summaries[ $t ]['warnings']
                                    );
                                } else {
                                    printf(
                                        /* translators: %d: count of clean components */
                                        esc_html__( '%d clean', 'thrg-php-checker' ),
                                        (int) $summaries[ $t ]['ok']
                                    );
                                }
                                ?>
                            </span>
                        <?php else : ?>
                            <span><?php esc_html_e( 'not scanned', 'thrg-php-checker' ); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div id="thrg-pc-progress" class="thrg-pc-progress" <?php echo $scan_running ? '' : 'hidden'; ?> aria-live="polite">
            <div class="thrg-pc-progress__bar">
                <div id="thrg-pc-progress-fill" class="thrg-pc-progress__fill" style="width: 0%;"></div>
            </div>
            <div class="thrg-pc-progress__meta">
                <strong id="thrg-pc-progress-status">
                    <?php esc_html_e( 'Preparing scan…', 'thrg-php-checker' ); ?>
                </strong>
                <span id="thrg-pc-progress-detail"></span>
            </div>
        </div>
    </section>

    <!-- Plain-English Readiness Verdict Banner -->
    <section class="thrg-pc-verdict thrg-pc-verdict--<?php echo esc_attr( $verdict_tone ); ?>" aria-label="<?php esc_attr_e( 'Readiness Verdict', 'thrg-php-checker' ); ?>">
        <div class="thrg-pc-verdict__content">
            <h2 class="thrg-pc-verdict__title"><?php echo esc_html( $verdict_title ); ?></h2>
            <p class="thrg-pc-verdict__desc"><?php echo esc_html( $verdict_desc ); ?></p>
        </div>
    </section>

    <!-- Summary Tiles -->
    <section class="thrg-summary" aria-label="<?php esc_attr_e( 'Compatibility Summary', 'thrg-php-checker' ); ?>">
        <div class="thrg-summary-tile thrg-summary-tile--weakness">
            <span class="thrg-summary-count"><?php echo (int) $counts['attention']; ?></span>
            <span class="thrg-summary-label"><?php esc_html_e( 'Incompatible / Action Needed', 'thrg-php-checker' ); ?></span>
            <span class="thrg-summary-sub">
                <?php
                printf(
                    /* translators: %s: target PHP version */
                    esc_html__( 'Will break on PHP %s or server PHP is EOL', 'thrg-php-checker' ),
                    esc_html( $selected_target )
                );
                ?>
            </span>
        </div>
        <div class="thrg-summary-tile thrg-summary-tile--review">
            <span class="thrg-summary-count"><?php echo (int) $counts['review']; ?></span>
            <span class="thrg-summary-label"><?php esc_html_e( 'Warnings / To Review', 'thrg-php-checker' ); ?></span>
            <span class="thrg-summary-sub">
                <?php
                printf(
                    /* translators: %s: target PHP version */
                    esc_html__( 'Works in PHP %s (deprecations or upcoming EOL)', 'thrg-php-checker' ),
                    esc_html( $selected_target )
                );
                ?>
            </span>
        </div>
        <div class="thrg-summary-tile thrg-summary-tile--ok">
            <span class="thrg-summary-count"><?php echo (int) $counts['clean']; ?></span>
            <span class="thrg-summary-label"><?php esc_html_e( 'Compatible (Clean)', 'thrg-php-checker' ); ?></span>
            <span class="thrg-summary-sub">
                <?php
                printf(
                    /* translators: %s: target PHP version */
                    esc_html__( '0 errors or warnings on PHP %s', 'thrg-php-checker' ),
                    esc_html( $selected_target )
                );
                ?>
            </span>
        </div>
        <div class="thrg-summary-tile thrg-summary-tile--neutral">
            <span class="thrg-summary-count"><?php echo (int) $scanned_now; ?> / <?php echo (int) $total_comps; ?></span>
            <span class="thrg-summary-label">
                <?php
                printf(
                    /* translators: %s: target PHP version */
                    esc_html__( 'Scanned for PHP %s', 'thrg-php-checker' ),
                    esc_html( $selected_target )
                );
                ?>
            </span>
            <span class="thrg-summary-sub"><?php esc_html_e( 'Plugins, mu-plugins & themes', 'thrg-php-checker' ); ?></span>
        </div>
    </section>

    <div class="thrg-layout">
        <!-- Sticky Left Navigation -->
        <nav class="thrg-nav" aria-label="<?php esc_attr_e( 'Report Sections', 'thrg-php-checker' ); ?>">
            <?php if ( ! empty( $nav_attention ) ) : ?>
                <div class="thrg-nav-group">
                    <h2 class="thrg-nav-heading thrg-nav-heading--weakness">
                        <?php
                        printf(
                            /* translators: %d: count of action-needed items */
                            esc_html__( 'Action Needed (%d)', 'thrg-php-checker' ),
                            count( $nav_attention )
                        );
                        ?>
                    </h2>
                    <ul class="thrg-nav-list">
                        <?php foreach ( $nav_attention as $row ) : ?>
                            <li>
                                <a href="#<?php echo esc_attr( $row['id'] ); ?>" class="thrg-nav-link">
                                    <span class="thrg-nav-dot thrg-status-weakness"></span>
                                    <span class="thrg-nav-text"><?php echo esc_html( ! empty( $row['comp_name'] ) ? $row['comp_name'] : $row['label'] ); ?></span>
                                    <?php if ( ! empty( $row['nav_hint'] ) ) : ?>
                                        <span class="thrg-nav-badge thrg-nav-badge--weakness"><?php echo esc_html( $row['nav_hint'] ); ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $nav_review ) ) : ?>
                <div class="thrg-nav-group">
                    <h2 class="thrg-nav-heading">
                        <?php
                        printf(
                            /* translators: %d: count of review items */
                            esc_html__( 'Warnings & Review (%d)', 'thrg-php-checker' ),
                            count( $nav_review )
                        );
                        ?>
                    </h2>
                    <ul class="thrg-nav-list">
                        <?php foreach ( $nav_review as $row ) : ?>
                            <li>
                                <a href="#<?php echo esc_attr( $row['id'] ); ?>" class="thrg-nav-link">
                                    <span class="thrg-nav-dot <?php echo esc_attr( $status_class( $row['status'] ) ); ?>"></span>
                                    <span class="thrg-nav-text"><?php echo esc_html( ! empty( $row['comp_name'] ) ? $row['comp_name'] : $row['label'] ); ?></span>
                                    <?php if ( ! empty( $row['nav_hint'] ) ) : ?>
                                        <span class="thrg-nav-badge"><?php echo esc_html( $row['nav_hint'] ); ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $clean_comps ) ) : ?>
                <div class="thrg-nav-group">
                    <h2 class="thrg-nav-heading"><?php esc_html_e( 'Passed Checks', 'thrg-php-checker' ); ?></h2>
                    <ul class="thrg-nav-list">
                        <li>
                            <a href="#pc-clean" class="thrg-nav-link" data-open-details="pc-clean-details">
                                <span class="thrg-nav-dot thrg-status-ok"></span>
                                <span class="thrg-nav-text">
                                    <?php
                                    printf(
                                        /* translators: %d: number of clean components */
                                        esc_html__( 'Compatible (%d)', 'thrg-php-checker' ),
                                        count( $clean_comps )
                                    );
                                    ?>
                                </span>
                            </a>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="thrg-nav-group">
                <h2 class="thrg-nav-heading"><?php esc_html_e( 'Tools & Settings', 'thrg-php-checker' ); ?></h2>
                <ul class="thrg-nav-list">
                    <li><a href="#pc-share" class="thrg-nav-link"><?php esc_html_e( 'Export & Email Report', 'thrg-php-checker' ); ?></a></li>
                    <li><a href="#pc-schedule" class="thrg-nav-link" data-open-details="pc-schedule-details"><?php esc_html_e( 'Monthly Readiness Email', 'thrg-php-checker' ); ?></a></li>
                    <li><a href="#pc-history" class="thrg-nav-link" data-open-details="pc-history-details"><?php esc_html_e( 'Saved Report History', 'thrg-php-checker' ); ?></a></li>
                    <li><a href="#pc-settings" class="thrg-nav-link" data-open-details="pc-settings-details"><?php esc_html_e( 'Scanner Settings', 'thrg-php-checker' ); ?></a></li>
                    <li><a href="#support" class="thrg-nav-link"><?php esc_html_e( 'Support & Help', 'thrg-php-checker' ); ?></a></li>
                </ul>
            </div>
        </nav>

        <!-- Main Findings & Tools Column -->
        <main class="thrg-main">
            <?php if ( $has_tech_rows > 1 ) : ?>
                <div class="thrg-pc-section-bar">
                    <span class="thrg-pc-section-bar__title">
                        <?php
                        if ( ! empty( $broken_names ) ) {
                            esc_html_e( 'Sorted by urgency: Fatal incompatibilities first, deprecation warnings second', 'thrg-php-checker' );
                        } else {
                            esc_html_e( 'Findings sorted by urgency: Active plugins & theme first', 'thrg-php-checker' );
                        }
                        ?>
                    </span>
                    <button type="button" id="thrg-pc-toggle-all-details" class="button button-small button-link" data-expanded="0">
                        <?php esc_html_e( 'Expand all code tables', 'thrg-php-checker' ); ?>
                    </button>
                </div>
            <?php endif; ?>

            <?php foreach ( $primary_rows as $row ) : ?>
                <?php
                $is_comp_card    = ! empty( $row['comp_key'] );
                $own_origin_text = isset( $row['comp_kind'] ) && 'theme' === $row['comp_kind']
                    ? __( 'Theme code', 'thrg-php-checker' )
                    : __( 'Plugin code', 'thrg-php-checker' );
                $own_count       = isset( $row['own_issues'] ) ? (int) $row['own_issues'] : 0;
                $bundled_count   = isset( $row['bundled_issues'] ) ? (int) $row['bundled_issues'] : 0;
                ?>
                <article id="<?php echo esc_attr( $row['id'] ); ?>" class="thrg-card <?php echo esc_attr( $status_class( $row['status'] ) ); ?>">
                    <header class="thrg-card-header">
                        <div class="thrg-card-meta">
                            <span class="thrg-area-badge"><?php echo esc_html( $row['area'] ); ?></span>
                            <span class="thrg-status-pill <?php echo esc_attr( $status_class( $row['status'] ) ); ?>">
                                <?php echo esc_html( $badge_label( $row ) ); ?>
                            </span>
                            <?php if ( $is_comp_card && $own_count > 0 ) : ?>
                                <span class="thrg-pc-origin-badge thrg-pc-origin-badge--own">
                                    <?php
                                    printf(
                                        /* translators: 1: issue count, 2: "plugin code" or "theme code" */
                                        esc_html__( '%1$d in %2$s', 'thrg-php-checker' ),
                                        (int) $own_count,
                                        esc_html( strtolower( $own_origin_text ) )
                                    );
                                    ?>
                                </span>
                            <?php endif; ?>
                            <?php if ( $is_comp_card && $bundled_count > 0 ) : ?>
                                <span class="thrg-pc-origin-badge thrg-pc-origin-badge--bundled">
                                    <?php
                                    printf(
                                        /* translators: %d: issue count in bundled dependency */
                                        esc_html__( '%d in bundled library', 'thrg-php-checker' ),
                                        (int) $bundled_count
                                    );
                                    ?>
                                </span>
                            <?php endif; ?>
                            <?php if ( ! empty( $row['comp_update_version'] ) ) : ?>
                                <span class="thrg-pc-update-pill">
                                    <?php
                                    printf(
                                        /* translators: %s: available new version */
                                        esc_html__( 'Update %s available', 'thrg-php-checker' ),
                                        esc_html( $row['comp_update_version'] )
                                    );
                                    ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="thrg-card-title-row">
                            <h2 class="thrg-card-title">
                                <?php if ( $is_comp_card && ! empty( $row['comp_name'] ) ) : ?>
                                    <?php echo esc_html( $row['comp_name'] ); ?>
                                    <?php if ( ! empty( $row['comp_version'] ) ) : ?>
                                        <span class="thrg-pc-comp-ver">v<?php echo esc_html( $row['comp_version'] ); ?></span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <?php echo esc_html( $row['label'] ); ?>
                                <?php endif; ?>
                            </h2>
                            <?php if ( $is_comp_card ) : ?>
                                <button type="button" class="button button-small thrg-pc-rescan-single" data-single-key="<?php echo esc_attr( $row['comp_key'] ); ?>" data-target="<?php echo esc_attr( $selected_target ); ?>">
                                    <?php esc_html_e( 'Rescan', 'thrg-php-checker' ); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </header>

                    <?php if ( $is_comp_card ) : ?>
                        <!-- Streamlined, easy-to-read summary for plugin/theme cards -->
                        <div class="thrg-pc-action-box">
                            <div class="thrg-pc-action-box__next">
                                <strong><?php esc_html_e( 'What to do:', 'thrg-php-checker' ); ?></strong>
                                <?php echo wp_kses_post( THRG_PHP_Checker::linkify( $row['next_steps'] ) ); ?>
                            </div>
                            <div class="thrg-pc-action-box__meaning">
                                <?php echo wp_kses_post( THRG_PHP_Checker::linkify( $row['meaning'] ) ); ?>
                            </div>
                        </div>
                    <?php else : ?>
                        <dl class="thrg-card-dl">
                            <div class="thrg-card-row">
                                <dt><?php esc_html_e( 'Current status', 'thrg-php-checker' ); ?></dt>
                                <dd><code><?php echo wp_kses_post( THRG_PHP_Checker::linkify( $row['current'] ) ); ?></code></dd>
                            </div>
                            <div class="thrg-card-row">
                                <dt><?php esc_html_e( 'What this means', 'thrg-php-checker' ); ?></dt>
                                <dd><?php echo wp_kses_post( THRG_PHP_Checker::linkify( $row['meaning'] ) ); ?></dd>
                            </div>
                            <div class="thrg-card-row">
                                <dt><?php esc_html_e( 'What to do', 'thrg-php-checker' ); ?></dt>
                                <dd><?php echo wp_kses_post( THRG_PHP_Checker::linkify( $row['next_steps'] ) ); ?></dd>
                            </div>
                        </dl>
                    <?php endif; ?>

                    <?php
                    if ( ! empty( $row['findings'] ) && is_array( $row['findings'] ) ) :
                        $active_issues = array();
                        $guarded_notes = array();
                        foreach ( $row['findings'] as $f ) {
                            if ( isset( $f['type'] ) && 'note' === $f['type'] ) {
                                $guarded_notes[] = $f;
                            } else {
                                $active_issues[] = $f;
                            }
                        }
                        $row_target_label = isset( $row['target'] ) && '' !== $row['target'] ? (string) $row['target'] : $selected_target;
                        ?>
                        <?php if ( ! empty( $active_issues ) ) : ?>
                            <?php
                            $initial_issues  = array_slice( $active_issues, 0, 8 );
                            $overflow_issues = array_slice( $active_issues, 8 );
                            ?>
                            <details class="thrg-pc-tech-details">
                                <summary class="thrg-pc-tech-summary">
                                    <span>
                                        <?php
                                        printf(
                                            /* translators: %d: count of code findings */
                                            esc_html( _n( 'Technical code finding (%d file:line match)', 'Technical code findings (%d file:line matches)', count( $active_issues ), 'thrg-php-checker' ) ),
                                            count( $active_issues )
                                        );
                                        ?>
                                    </span>
                                    <span class="thrg-pc-tech-summary__hint">
                                        <?php esc_html_e( 'Click to toggle file, line & rule details', 'thrg-php-checker' ); ?>
                                    </span>
                                </summary>
                                <div class="thrg-pc-findings-wrap">
                                    <table class="widefat striped thrg-pc-findings-table">
                                        <thead>
                                            <tr>
                                                <th class="thrg-pc-col-sev"><?php esc_html_e( 'Severity & Target', 'thrg-php-checker' ); ?></th>
                                                <th class="thrg-pc-col-loc"><?php esc_html_e( 'File : Line & Origin', 'thrg-php-checker' ); ?></th>
                                                <th class="thrg-pc-col-msg"><?php esc_html_e( 'Compatibility Finding & Rule', 'thrg-php-checker' ); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $initial_issues as $issue ) : ?>
                                                <?php
                                                $itype   = isset( $issue['type'] ) ? (string) $issue['type'] : 'warning';
                                                $ifile   = isset( $issue['file'] ) ? (string) $issue['file'] : '';
                                                $iline   = isset( $issue['line'] ) ? (int) $issue['line'] : 0;
                                                $isource = isset( $issue['source'] ) ? (string) $issue['source'] : '';
                                                $iorigin = isset( $issue['origin'] ) ? (string) $issue['origin'] : THRG_PC_Results::file_origin( $ifile );
                                                ?>
                                                <tr>
                                                    <td>
                                                        <span class="thrg-pc-sev thrg-pc-sev--<?php echo esc_attr( $itype ); ?>">
                                                            <?php echo esc_html( strtoupper( $itype ) ); ?>
                                                        </span>
                                                        <span class="thrg-pc-target-tag">PHP <?php echo esc_html( $row_target_label ); ?></span>
                                                    </td>
                                                    <td>
                                                        <code><?php echo esc_html( $iline > 0 ? $ifile . ':' . $iline : $ifile ); ?></code>
                                                        <span class="thrg-pc-origin-badge thrg-pc-origin-badge--<?php echo esc_attr( $iorigin ); ?>">
                                                            <?php echo 'bundled' === $iorigin ? esc_html__( 'Bundled dependency', 'thrg-php-checker' ) : esc_html( $own_origin_text ); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div><?php echo esc_html( isset( $issue['message'] ) ? (string) $issue['message'] : '' ); ?></div>
                                                        <?php if ( '' !== $isource ) : ?>
                                                            <div class="thrg-pc-rule-meta">
                                                                <?php esc_html_e( 'Rule:', 'thrg-php-checker' ); ?>
                                                                <code><?php echo esc_html( $isource ); ?></code>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>

                                    <?php if ( ! empty( $overflow_issues ) ) : ?>
                                        <details class="thrg-pc-notes-details thrg-pc-more-rows">
                                            <summary>
                                                <?php
                                                printf(
                                                    /* translators: %d: count of additional findings */
                                                    esc_html__( 'Show %d additional finding(s) in this component', 'thrg-php-checker' ),
                                                    count( $overflow_issues )
                                                );
                                                ?>
                                            </summary>
                                            <table class="widefat striped thrg-pc-findings-table thrg-pc-findings-table--notes">
                                                <tbody>
                                                    <?php foreach ( $overflow_issues as $issue ) : ?>
                                                        <?php
                                                        $itype   = isset( $issue['type'] ) ? (string) $issue['type'] : 'warning';
                                                        $ifile   = isset( $issue['file'] ) ? (string) $issue['file'] : '';
                                                        $iline   = isset( $issue['line'] ) ? (int) $issue['line'] : 0;
                                                        $isource = isset( $issue['source'] ) ? (string) $issue['source'] : '';
                                                        $iorigin = isset( $issue['origin'] ) ? (string) $issue['origin'] : THRG_PC_Results::file_origin( $ifile );
                                                        ?>
                                                        <tr>
                                                            <td class="thrg-pc-col-sev">
                                                                <span class="thrg-pc-sev thrg-pc-sev--<?php echo esc_attr( $itype ); ?>">
                                                                    <?php echo esc_html( strtoupper( $itype ) ); ?>
                                                                </span>
                                                                <span class="thrg-pc-target-tag">PHP <?php echo esc_html( $row_target_label ); ?></span>
                                                            </td>
                                                            <td class="thrg-pc-col-loc">
                                                                <code><?php echo esc_html( $iline > 0 ? $ifile . ':' . $iline : $ifile ); ?></code>
                                                                <span class="thrg-pc-origin-badge thrg-pc-origin-badge--<?php echo esc_attr( $iorigin ); ?>">
                                                                    <?php echo 'bundled' === $iorigin ? esc_html__( 'Bundled dependency', 'thrg-php-checker' ) : esc_html( $own_origin_text ); ?>
                                                                </span>
                                                            </td>
                                                            <td class="thrg-pc-col-msg">
                                                                <div><?php echo esc_html( isset( $issue['message'] ) ? (string) $issue['message'] : '' ); ?></div>
                                                                <?php if ( '' !== $isource ) : ?>
                                                                    <div class="thrg-pc-rule-meta">
                                                                        <?php esc_html_e( 'Rule:', 'thrg-php-checker' ); ?>
                                                                        <code><?php echo esc_html( $isource ); ?></code>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </details>
                                    <?php endif; ?>
                                </div>
                            </details>
                        <?php endif; ?>

                        <?php if ( ! empty( $guarded_notes ) ) : ?>
                            <details class="thrg-pc-notes-details">
                                <summary>
                                    <?php
                                    printf(
                                        /* translators: %d: number of guarded or polyfill notes */
                                        esc_html__( 'Show %d version-guarded / polyfill note(s) (verified safe at runtime)', 'thrg-php-checker' ),
                                        count( $guarded_notes )
                                    );
                                    ?>
                                </summary>
                                <table class="widefat striped thrg-pc-findings-table thrg-pc-findings-table--notes">
                                    <thead>
                                        <tr>
                                            <th class="thrg-pc-col-loc"><?php esc_html_e( 'File : Line & Origin', 'thrg-php-checker' ); ?></th>
                                            <th class="thrg-pc-col-msg"><?php esc_html_e( 'Guarded / Polyfilled Construct & Rule', 'thrg-php-checker' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $guarded_notes as $note_item ) : ?>
                                            <?php
                                            $nfile   = isset( $note_item['file'] ) ? (string) $note_item['file'] : '';
                                            $nline   = isset( $note_item['line'] ) ? (int) $note_item['line'] : 0;
                                            $nsource = isset( $note_item['source'] ) ? (string) $note_item['source'] : '';
                                            $norigin = isset( $note_item['origin'] ) ? (string) $note_item['origin'] : THRG_PC_Results::file_origin( $nfile );
                                            ?>
                                            <tr>
                                                <td>
                                                    <code><?php echo esc_html( $nline > 0 ? $nfile . ':' . $nline : $nfile ); ?></code>
                                                    <span class="thrg-pc-origin-badge thrg-pc-origin-badge--<?php echo esc_attr( $norigin ); ?>">
                                                        <?php echo 'bundled' === $norigin ? esc_html__( 'Bundled dependency', 'thrg-php-checker' ) : esc_html( $own_origin_text ); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div><?php echo esc_html( isset( $note_item['message'] ) ? (string) $note_item['message'] : '' ); ?></div>
                                                    <?php if ( '' !== $nsource ) : ?>
                                                        <div class="thrg-pc-rule-meta">
                                                            <?php esc_html_e( 'Rule:', 'thrg-php-checker' ); ?>
                                                            <code><?php echo esc_html( $nsource ); ?></code>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </details>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ( ! empty( $row['links'] ) && is_array( $row['links'] ) ) : ?>
                        <footer class="thrg-card-footer">
                            <span class="thrg-links-label"><?php esc_html_e( 'References:', 'thrg-php-checker' ); ?></span>
                            <ul class="thrg-links-list">
                                <?php foreach ( $row['links'] as $link_key ) : ?>
                                    <?php
                                    $ref_link = THRG_PHP_Checker::href( $link_key );
                                    if ( ! $ref_link ) {
                                        continue;
                                    }
                                    ?>
                                    <li>
                                        <a href="<?php echo esc_url( $ref_link['url'] ); ?>" target="_blank" rel="noopener noreferrer">
                                            <?php echo esc_html( $ref_link['label'] ); ?>
                                            <?php echo wp_kses_post( THRG_PHP_Checker::NEW_TAB_HINT ); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </footer>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>

            <!-- Clean Components Section (Collapsible so it never overwhelms the page) -->
            <?php if ( ! empty( $clean_comps ) ) : ?>
                <section id="pc-clean" class="thrg-card thrg-status-ok">
                    <header class="thrg-card-header">
                        <div class="thrg-card-meta">
                            <span class="thrg-area-badge"><?php esc_html_e( 'Passed Checks', 'thrg-php-checker' ); ?></span>
                            <span class="thrg-status-pill thrg-status-ok"><?php esc_html_e( 'Compatible', 'thrg-php-checker' ); ?></span>
                        </div>
                        <h2 class="thrg-card-title">
                            <?php
                            printf(
                                /* translators: 1: count of clean components, 2: target PHP version */
                                esc_html__( '%1$d plugin(s) & theme(s) passed PHP %2$s checks with 0 errors or warnings', 'thrg-php-checker' ),
                                count( $clean_comps ),
                                esc_html( $selected_target )
                            );
                            ?>
                        </h2>
                    </header>
                    <details id="pc-clean-details" class="thrg-pc-tech-details">
                        <summary class="thrg-pc-tech-summary">
                            <span>
                                <?php
                                printf(
                                    /* translators: %d: count of clean components */
                                    esc_html__( 'Show all %d compatible components', 'thrg-php-checker' ),
                                    count( $clean_comps )
                                );
                                ?>
                            </span>
                            <span class="thrg-pc-tech-summary__hint"><?php esc_html_e( 'Click to expand table', 'thrg-php-checker' ); ?></span>
                        </summary>
                        <div class="thrg-pc-findings-wrap">
                            <table class="widefat striped thrg-pc-clean-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Component', 'thrg-php-checker' ); ?></th>
                                        <th><?php esc_html_e( 'Type / State', 'thrg-php-checker' ); ?></th>
                                        <th><?php esc_html_e( 'Version', 'thrg-php-checker' ); ?></th>
                                        <th><?php esc_html_e( 'Guarded Notes', 'thrg-php-checker' ); ?></th>
                                        <th><?php esc_html_e( 'Action', 'thrg-php-checker' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $clean_comps as $c ) : ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo esc_html( isset( $c['comp_name'] ) ? $c['comp_name'] : $c['label'] ); ?></strong>
                                                <?php if ( ! empty( $c['comp_update_version'] ) ) : ?>
                                                    <span class="thrg-pc-update-pill">
                                                        <?php
                                                        printf(
                                                            /* translators: %s: available new version */
                                                            esc_html__( 'Update %s', 'thrg-php-checker' ),
                                                            esc_html( $c['comp_update_version'] )
                                                        );
                                                        ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo esc_html( $c['area'] ); ?></td>
                                            <td><code><?php echo esc_html( isset( $c['comp_version'] ) ? $c['comp_version'] : '' ); ?></code></td>
                                            <td>
                                                <?php if ( ! empty( $c['notes'] ) ) : ?>
                                                    <?php
                                                    printf(
                                                        /* translators: %d: guarded notes count */
                                                        esc_html__( '%d guarded/polyfill', 'thrg-php-checker' ),
                                                        (int) $c['notes']
                                                    );
                                                    ?>
                                                <?php else : ?>
                                                    <span class="description"><?php esc_html_e( '0', 'thrg-php-checker' ); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ( ! empty( $c['comp_key'] ) ) : ?>
                                                    <button type="button" class="button button-small thrg-pc-rescan-single" data-single-key="<?php echo esc_attr( $c['comp_key'] ); ?>" data-target="<?php echo esc_attr( $selected_target ); ?>">
                                                        <?php esc_html_e( 'Rescan', 'thrg-php-checker' ); ?>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </section>
            <?php endif; ?>

            <!-- Share, Export & Email Section -->
            <section id="pc-share" class="thrg-card thrg-panel">
                <header class="thrg-card-header">
                    <div class="thrg-card-meta">
                        <span class="thrg-area-badge"><?php esc_html_e( 'Export & Email', 'thrg-php-checker' ); ?></span>
                    </div>
                    <h2 class="thrg-card-title"><?php esc_html_e( 'Share, Download, Print, or Email This Report', 'thrg-php-checker' ); ?></h2>
                </header>
                <p class="thrg-pc-section-desc">
                    <?php esc_html_e( 'Copy the plain-text PHP compatibility report for a support ticket, download a .txt file, print the report, or email a formatted summary to site administrators.', 'thrg-php-checker' ); ?>
                </p>

                <div class="thrg-pc-export-bar">
                    <button type="button" id="thrg-pc-copy-btn" class="button button-secondary">
                        <?php esc_html_e( 'Copy Plain-Text Report', 'thrg-php-checker' ); ?>
                    </button>
                    <button type="button" id="thrg-pc-download-btn" class="button button-secondary" data-filename="<?php echo esc_attr( sprintf( 'php-checker-%s-php%s-%s.txt', sanitize_file_name( $site_host ), $selected_target, gmdate( 'Ymd' ) ) ); ?>">
                        <?php esc_html_e( 'Download .txt Report', 'thrg-php-checker' ); ?>
                    </button>
                    <button type="button" id="thrg-pc-print-btn" class="button button-secondary">
                        <?php esc_html_e( 'Print Report', 'thrg-php-checker' ); ?>
                    </button>
                    <span id="thrg-pc-copy-status" class="thrg-pc-copy-status" aria-live="polite"></span>
                </div>

                <details class="thrg-pc-notes-details">
                    <summary><?php esc_html_e( 'Preview plain-text report', 'thrg-php-checker' ); ?></summary>
                    <textarea id="thrg-pc-plain-text" class="thrg-pc-plain-textarea" readonly rows="6" aria-label="<?php esc_attr_e( 'Plain-text compatibility report', 'thrg-php-checker' ); ?>"><?php echo esc_textarea( $plain_report ); ?></textarea>
                </details>

                <details id="pc-email-details" class="thrg-pc-tech-details" <?php echo '' !== $mail_flag ? 'open' : ''; ?>>
                    <summary class="thrg-pc-tech-summary">
                        <span><?php esc_html_e( 'Email Report to Site Administrators', 'thrg-php-checker' ); ?></span>
                        <span class="thrg-pc-tech-summary__hint"><?php esc_html_e( 'Send formatted HTML + plain-text report', 'thrg-php-checker' ); ?></span>
                    </summary>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="thrg-pc-form">
                        <input type="hidden" name="action" value="thrg_pc_email_report" />
                        <input type="hidden" name="target" value="<?php echo esc_attr( $selected_target ); ?>" />
                        <input type="hidden" name="report_id" value="<?php echo esc_attr( $viewed_entry ? $viewed_entry['id'] : '' ); ?>" />
                        <?php wp_nonce_field( 'thrg_pc_email_report' ); ?>

                        <fieldset class="thrg-pc-fieldset">
                            <legend><strong><?php esc_html_e( 'Administrator recipients', 'thrg-php-checker' ); ?></strong></legend>
                            <?php if ( empty( $admin_users ) ) : ?>
                                <p class="description"><?php esc_html_e( 'No administrator accounts with a valid email address were found.', 'thrg-php-checker' ); ?></p>
                            <?php else : ?>
                                <div class="thrg-pc-recipient-grid">
                                    <?php foreach ( $admin_users as $admin_user ) : ?>
                                        <label class="thrg-pc-recipient-item">
                                            <input type="checkbox" name="recipients[]" value="<?php echo (int) $admin_user['id']; ?>" <?php checked( get_current_user_id(), (int) $admin_user['id'] ); ?> />
                                            <span><?php echo esc_html( $admin_user['name'] ); ?> <code>(<?php echo esc_html( $admin_user['email'] ); ?>)</code></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </fieldset>

                        <p>
                            <label for="thrg-pc-email-note"><strong><?php esc_html_e( 'Optional note (included at the top of the email)', 'thrg-php-checker' ); ?></strong></label><br />
                            <textarea id="thrg-pc-email-note" name="note" rows="2" class="large-text" maxlength="<?php echo (int) THRG_PC_Email::NOTE_LENGTH; ?>" placeholder="<?php esc_attr_e( 'e.g., Preparing to upgrade our hosting environment to PHP 8.4 next Tuesday.', 'thrg-php-checker' ); ?>"></textarea>
                        </p>

                        <p>
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Email PHP Report', 'thrg-php-checker' ); ?></button>
                        </p>
                    </form>
                </details>
            </section>

            <!-- Monthly PHP Readiness Email Section -->
            <section id="pc-schedule" class="thrg-card thrg-panel">
                <header class="thrg-card-header">
                    <div class="thrg-card-meta">
                        <span class="thrg-area-badge"><?php esc_html_e( 'Scheduled Monitoring', 'thrg-php-checker' ); ?></span>
                        <span class="thrg-status-pill <?php echo ! empty( $schedule['enabled'] ) ? 'thrg-status-ok' : 'thrg-status-review'; ?>">
                            <?php echo ! empty( $schedule['enabled'] ) ? esc_html__( 'Enabled', 'thrg-php-checker' ) : esc_html__( 'Off by default', 'thrg-php-checker' ); ?>
                        </span>
                    </div>
                    <h2 class="thrg-card-title"><?php esc_html_e( 'Monthly PHP Readiness Email', 'thrg-php-checker' ); ?></h2>
                </header>
                <p class="thrg-pc-section-desc">
                    <?php esc_html_e( 'Optionally run a monthly PHP compatibility check and email administrators a summary of PHP EOL countdowns, security patch status, and any plugin/theme incompatibilities.', 'thrg-php-checker' ); ?>
                </p>

                <details id="pc-schedule-details" class="thrg-pc-tech-details" <?php echo ( $sched_ok || ! empty( $schedule['enabled'] ) ) ? 'open' : ''; ?>>
                    <summary class="thrg-pc-tech-summary">
                        <span><?php esc_html_e( 'Configure monthly schedule & recipients', 'thrg-php-checker' ); ?></span>
                        <span class="thrg-pc-tech-summary__hint">
                            <?php echo $schedule_next ? esc_html( sprintf( 'Next: %s', wp_date( 'Y-m-d', $schedule_next ) ) ) : esc_html__( 'Click to configure', 'thrg-php-checker' ); ?>
                        </span>
                    </summary>

                    <?php if ( $schedule_next ) : ?>
                        <p class="thrg-pc-schedule-status">
                            <strong><?php esc_html_e( 'Next scheduled check:', 'thrg-php-checker' ); ?></strong>
                            <?php echo esc_html( wp_date( 'Y-m-d H:i T', $schedule_next ) ); ?>
                        </p>
                    <?php endif; ?>
                    <?php if ( ! empty( $schedule_log['time'] ) ) : ?>
                        <p class="thrg-pc-schedule-status">
                            <strong><?php esc_html_e( 'Last scheduled check:', 'thrg-php-checker' ); ?></strong>
                            <?php
                            printf(
                                /* translators: 1: date, 2: status code, 3: sent count */
                                esc_html__( '%1$s — status: %2$s (%3$d sent)', 'thrg-php-checker' ),
                                esc_html( wp_date( 'Y-m-d H:i T', (int) $schedule_log['time'] ) ),
                                esc_html( (string) $schedule_log['status'] ),
                                (int) $schedule_log['sent']
                            );
                            ?>
                        </p>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="thrg-pc-form">
                        <input type="hidden" name="action" value="thrg_pc_schedule" />
                        <?php wp_nonce_field( 'thrg_pc_schedule' ); ?>

                        <p>
                            <label>
                                <input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $schedule['enabled'] ) ); ?> />
                                <strong><?php esc_html_e( 'Send a monthly PHP compatibility report email', 'thrg-php-checker' ); ?></strong>
                            </label>
                        </p>

                        <div class="thrg-pc-form-grid">
                            <div>
                                <label for="thrg-pc-sched-target"><strong><?php esc_html_e( 'Target PHP version', 'thrg-php-checker' ); ?></strong></label><br />
                                <select id="thrg-pc-sched-target" name="target">
                                    <option value="auto" <?php selected( $schedule['target'], 'auto' ); ?>>
                                        <?php
                                        printf(
                                            /* translators: %s: default recommended target */
                                            esc_html__( 'Recommended next PHP (currently PHP %s)', 'thrg-php-checker' ),
                                            esc_html( $default_target )
                                        );
                                        ?>
                                    </option>
                                    <?php foreach ( $scan_targets as $t ) : ?>
                                        <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $schedule['target'], $t ); ?>>
                                            PHP <?php echo esc_html( $t ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label for="thrg-pc-sched-day"><strong><?php esc_html_e( 'Day of the month (1–28, 8:00 AM site time)', 'thrg-php-checker' ); ?></strong></label><br />
                                <select id="thrg-pc-sched-day" name="day">
                                    <?php for ( $d = 1; $d <= THRG_PC_Schedule::LAST_DAY; $d++ ) : ?>
                                        <option value="<?php echo (int) $d; ?>" <?php selected( (int) $schedule['day'], $d ); ?>>
                                            <?php echo (int) $d; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>

                        <p>
                            <label>
                                <input type="checkbox" name="only_issues" value="1" <?php checked( ! empty( $schedule['only_issues'] ) ); ?> />
                                <?php esc_html_e( 'Only send the monthly email when errors or warnings are found', 'thrg-php-checker' ); ?>
                            </label>
                        </p>

                        <fieldset class="thrg-pc-fieldset">
                            <legend><strong><?php esc_html_e( 'Who should receive the monthly report?', 'thrg-php-checker' ); ?></strong></legend>
                            <p>
                                <label>
                                    <input type="radio" name="who" value="all" <?php checked( $schedule['who'], 'all' ); ?> />
                                    <?php esc_html_e( 'All site administrators', 'thrg-php-checker' ); ?>
                                </label>
                                &nbsp;&nbsp;
                                <label>
                                    <input type="radio" name="who" value="chosen" <?php checked( $schedule['who'], 'chosen' ); ?> />
                                    <?php esc_html_e( 'Selected administrators only:', 'thrg-php-checker' ); ?>
                                </label>
                            </p>
                            <?php if ( ! empty( $admin_users ) ) : ?>
                                <div class="thrg-pc-recipient-grid">
                                    <?php foreach ( $admin_users as $admin_user ) : ?>
                                        <label class="thrg-pc-recipient-item">
                                            <input type="checkbox" name="users[]" value="<?php echo (int) $admin_user['id']; ?>" <?php checked( in_array( (int) $admin_user['id'], (array) $schedule['users'], true ) ); ?> />
                                            <span><?php echo esc_html( $admin_user['name'] ); ?> <code>(<?php echo esc_html( $admin_user['email'] ); ?>)</code></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </fieldset>

                        <p>
                            <label for="thrg-pc-sched-note"><strong><?php esc_html_e( 'Optional monthly note', 'thrg-php-checker' ); ?></strong></label><br />
                            <textarea id="thrg-pc-sched-note" name="note" rows="2" class="large-text" maxlength="<?php echo (int) THRG_PC_Email::NOTE_LENGTH; ?>"><?php echo esc_textarea( (string) $schedule['note'] ); ?></textarea>
                        </p>

                        <p>
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Monthly Schedule', 'thrg-php-checker' ); ?></button>
                        </p>
                    </form>
                </details>
            </section>

            <!-- Report History Section -->
            <section id="pc-history" class="thrg-card thrg-panel">
                <header class="thrg-card-header">
                    <div class="thrg-card-meta">
                        <span class="thrg-area-badge"><?php esc_html_e( 'Local History', 'thrg-php-checker' ); ?></span>
                        <span class="thrg-status-pill <?php echo $history_enabled ? 'thrg-status-ok' : 'thrg-status-review'; ?>">
                            <?php echo $history_enabled ? esc_html__( 'Recording (max 25)', 'thrg-php-checker' ) : esc_html__( 'Paused', 'thrg-php-checker' ); ?>
                        </span>
                    </div>
                    <h2 class="thrg-card-title"><?php esc_html_e( 'Saved Compatibility Reports', 'thrg-php-checker' ); ?></h2>
                </header>
                <p class="thrg-pc-section-desc">
                    <?php esc_html_e( 'Completed scans are stored locally in a single non-autoloaded WordPress option (up to 25 reports, capped at 600 KB) so you can track progress as you update or replace plugins.', 'thrg-php-checker' ); ?>
                </p>

                <details id="pc-history-details" class="thrg-pc-tech-details" <?php echo ( '' !== $hist_flag || $viewed_entry ) ? 'open' : ''; ?>>
                    <summary class="thrg-pc-tech-summary">
                        <span>
                            <?php
                            printf(
                                /* translators: %d: count of saved reports */
                                esc_html__( 'Saved report snapshots (%d)', 'thrg-php-checker' ),
                                count( $history_all )
                            );
                            ?>
                        </span>
                        <span class="thrg-pc-tech-summary__hint"><?php esc_html_e( 'Click to view or manage history', 'thrg-php-checker' ); ?></span>
                    </summary>

                    <div class="thrg-pc-history-actions">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="thrg-pc-inline-form">
                            <input type="hidden" name="action" value="thrg_pc_toggle_history" />
                            <input type="hidden" name="enable" value="<?php echo $history_enabled ? '0' : '1'; ?>" />
                            <?php wp_nonce_field( 'thrg_pc_toggle_history' ); ?>
                            <button type="submit" class="button button-secondary">
                                <?php echo $history_enabled ? esc_html__( 'Pause History', 'thrg-php-checker' ) : esc_html__( 'Resume History', 'thrg-php-checker' ); ?>
                            </button>
                        </form>

                        <?php if ( ! empty( $history_all ) ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="thrg-pc-inline-form">
                                <input type="hidden" name="action" value="thrg_pc_clear_history" />
                                <?php wp_nonce_field( 'thrg_pc_clear_history' ); ?>
                                <button type="submit" class="button button-link-delete">
                                    <?php esc_html_e( 'Clear All History', 'thrg-php-checker' ); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if ( empty( $history_all ) ) : ?>
                        <p class="description"><?php esc_html_e( 'No saved scan reports yet. Run a scan above to record your first snapshot.', 'thrg-php-checker' ); ?></p>
                    <?php else : ?>
                        <div class="thrg-pc-findings-wrap">
                            <table class="widefat striped thrg-pc-history-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Date', 'thrg-php-checker' ); ?></th>
                                        <th><?php esc_html_e( 'Target', 'thrg-php-checker' ); ?></th>
                                        <th><?php esc_html_e( 'Summary', 'thrg-php-checker' ); ?></th>
                                        <th><?php esc_html_e( 'Change vs Prior', 'thrg-php-checker' ); ?></th>
                                        <th><?php esc_html_e( 'Run By', 'thrg-php-checker' ); ?></th>
                                        <th><?php esc_html_e( 'Actions', 'thrg-php-checker' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $count_hist = count( $history_all );
                                    for ( $i = 0; $i < $count_hist; $i++ ) :
                                        $item  = $history_all[ $i ];
                                        $older = null;
                                        for ( $j = $i + 1; $j < $count_hist; $j++ ) {
                                            if ( ( isset( $history_all[ $j ]['target'] ) ? $history_all[ $j ]['target'] : '' ) === ( isset( $item['target'] ) ? $item['target'] : '' ) ) {
                                                $older = $history_all[ $j ];
                                                break;
                                            }
                                        }
                                        $diff     = THRG_PC_History::change( $item, $older );
                                        $sum      = isset( $item['summary'] ) ? $item['summary'] : THRG_PC_History::summarize( $item['rows'] );
                                        $view_url = add_query_arg(
                                            array(
                                                'page'   => THRG_PHP_Checker::SLUG,
                                                'target' => isset( $item['target'] ) ? $item['target'] : $selected_target,
                                                'thrg_report' => $item['id'],
                                            ),
                                            admin_url( 'tools.php' )
                                        );
                                        ?>
                                        <tr>
                                            <td><strong><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $item['time'] ) ); ?></strong></td>
                                            <td><code>PHP <?php echo esc_html( isset( $item['target'] ) ? $item['target'] : '8.4' ); ?></code></td>
                                            <td>
                                                <?php
                                                printf(
                                                    /* translators: 1: attention count, 2: review count, 3: clean count */
                                                    esc_html__( '%1$d attention · %2$d review · %3$d clean', 'thrg-php-checker' ),
                                                    (int) $sum['attention'],
                                                    (int) $sum['review'],
                                                    (int) $sum['observed']
                                                );
                                                ?>
                                            </td>
                                            <td>
                                                <?php
                                                if ( ! empty( $diff['first'] ) ) {
                                                    esc_html_e( 'Baseline scan', 'thrg-php-checker' );
                                                } elseif ( 0 === (int) $diff['new'] && 0 === (int) $diff['resolved'] ) {
                                                    esc_html_e( 'No change', 'thrg-php-checker' );
                                                } else {
                                                    printf(
                                                        /* translators: 1: new issues, 2: resolved issues */
                                                        esc_html__( '+%1$d new / -%2$d resolved', 'thrg-php-checker' ),
                                                        (int) $diff['new'],
                                                        (int) $diff['resolved']
                                                    );
                                                }
                                                ?>
                                            </td>
                                            <td><?php echo esc_html( ! empty( $item['name'] ) ? $item['name'] : '—' ); ?></td>
                                            <td>
                                                <a class="button button-small" href="<?php echo esc_url( $view_url ); ?>">
                                                    <?php esc_html_e( 'View', 'thrg-php-checker' ); ?>
                                                </a>
                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="thrg-pc-inline-form">
                                                    <input type="hidden" name="action" value="thrg_pc_delete_report" />
                                                    <input type="hidden" name="report_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
                                                    <?php wp_nonce_field( 'thrg_pc_delete_report' ); ?>
                                                    <button type="submit" class="button button-small button-link-delete">
                                                        <?php esc_html_e( 'Delete', 'thrg-php-checker' ); ?>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </details>
            </section>

            <!-- Settings & Update Pre-Scan Section -->
            <section id="pc-settings" class="thrg-card thrg-panel">
                <header class="thrg-card-header">
                    <div class="thrg-card-meta">
                        <span class="thrg-area-badge"><?php esc_html_e( 'Settings & Pre-Scan', 'thrg-php-checker' ); ?></span>
                    </div>
                    <h2 class="thrg-card-title"><?php esc_html_e( 'Scanner Preferences & Pending Update Pre-Scan', 'thrg-php-checker' ); ?></h2>
                </header>
                <p class="thrg-pc-section-desc">
                    <?php esc_html_e( 'Configure your default scan scope, optionally pre-scan pending plugin/theme update zip archives in memory before applying them, or reset cached scan results.', 'thrg-php-checker' ); ?>
                </p>

                <details id="pc-settings-details" class="thrg-pc-tech-details" <?php echo '' !== $set_flag ? 'open' : ''; ?>>
                    <summary class="thrg-pc-tech-summary">
                        <span><?php esc_html_e( 'Open scanner settings & cache controls', 'thrg-php-checker' ); ?></span>
                        <span class="thrg-pc-tech-summary__hint"><?php esc_html_e( 'Click to expand', 'thrg-php-checker' ); ?></span>
                    </summary>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="thrg-pc-form">
                        <input type="hidden" name="action" value="thrg_pc_save_settings" />
                        <input type="hidden" name="target" value="<?php echo esc_attr( $selected_target ); ?>" />
                        <?php wp_nonce_field( 'thrg_pc_save_settings' ); ?>

                        <p>
                            <label>
                                <input type="checkbox" name="prescan_updates" value="1" <?php checked( ! empty( $settings['prescan_updates'] ) ); ?> />
                                <strong>
                                    <?php
                                    printf(
                                        /* translators: %s: running PHP version */
                                        esc_html__( 'Pre-scan offered plugin & theme updates in memory against running PHP %s (hourly cron, opt-in)', 'thrg-php-checker' ),
                                        esc_html( $running_php )
                                    );
                                    ?>
                                </strong>
                            </label><br />
                            <span class="description">
                                <?php esc_html_e( 'Off by default so no update packages are downloaded unless you opt in. When enabled, PHP Checker downloads pending update zip archives to a temporary file, streams PHP entries in memory through PHPCompatibilityWP, and deletes the archive immediately.', 'thrg-php-checker' ); ?>
                            </span>
                        </p>

                        <p>
                            <label for="thrg-pc-default-scope"><strong><?php esc_html_e( 'Default scan scope', 'thrg-php-checker' ); ?></strong></label><br />
                            <select id="thrg-pc-default-scope" name="default_scope">
                                <option value="active" <?php selected( $settings['default_scope'], 'active' ); ?>><?php esc_html_e( 'Active plugins, mu-plugins & active theme only', 'thrg-php-checker' ); ?></option>
                                <option value="all" <?php selected( $settings['default_scope'], 'all' ); ?>><?php esc_html_e( 'All installed plugins, mu-plugins & themes', 'thrg-php-checker' ); ?></option>
                            </select>
                        </p>

                        <div class="thrg-pc-export-bar">
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'thrg-php-checker' ); ?></button>
                            <button type="button" id="thrg-pc-prescan-btn" class="button button-secondary">
                                <?php esc_html_e( 'Check Pending Updates Now', 'thrg-php-checker' ); ?>
                            </button>
                            <button type="submit" name="clear_results" value="1" class="button button-link-delete">
                                <?php esc_html_e( 'Clear Cached Scan Results', 'thrg-php-checker' ); ?>
                            </button>
                            <span id="thrg-pc-prescan-status" class="thrg-pc-copy-status" aria-live="polite"></span>
                        </div>
                    </form>
                </details>
            </section>

            <!-- Support Section -->
            <section id="support" class="thrg-card thrg-panel" aria-labelledby="pc-support-title">
                <header class="thrg-card-header">
                    <div class="thrg-card-meta">
                        <span class="thrg-area-badge"><?php esc_html_e( 'Support', 'thrg-php-checker' ); ?></span>
                    </div>
                    <h2 id="pc-support-title" class="thrg-card-title"><?php esc_html_e( 'Support & Companion Tools', 'thrg-php-checker' ); ?></h2>
                </header>
                <div class="thrg-pc-action-box">
                    <div class="thrg-pc-action-box__next">
                        <span class="dashicons dashicons-search" aria-hidden="true"></span>
                        <strong><?php esc_html_e( 'Recommended while testing plugins & PHP upgrades:', 'thrg-php-checker' ); ?></strong>
                        <?php echo wp_kses_post( __( 'Pair this scanner with our free <a href="https://github.com/hackrepair/hackrepair-plugin-detective" target="_blank" rel="noopener noreferrer"><strong>The Hack Repair Guy\'s Plugin Detective</strong></a> plugin. While PHP Checker flags incompatible code before you upgrade, Plugin Detective helps you pinpoint live plugin conflicts during testing and includes emergency recovery tools in case an update or PHP switch ever locks you out of your WordPress dashboard.', 'thrg-php-checker' ) ); ?>
                    </div>
                </div>
                <p>&#9749; <?php echo wp_kses_post( __( 'If you found this plugin helpful, please <a href="https://hackrepair.com/donations/buy-jim-a-coffee" target="_blank" rel="noopener noreferrer">buy Jim a cup of coffee?</a>', 'thrg-php-checker' ) ); ?></p>
                <p><span class="dashicons dashicons-editor-help" aria-hidden="true"></span> <?php echo wp_kses_post( __( 'Questions and Feedback: For questions, ideas, or help with the plugin, <a href="https://github.com/hackrepair/thrg-php-checker/discussions" target="_blank" rel="noopener noreferrer">visit GitHub Discussions</a>.', 'thrg-php-checker' ) ); ?></p>
            </section>
        </main>
    </div>

    <footer class="thrg-footer thrg-pc-coverage">
        <div class="thrg-pc-coverage__col">
            <p><strong><?php esc_html_e( 'Limits:', 'thrg-php-checker' ); ?></strong> <?php esc_html_e( 'Static analysis inspects PHP source tokens without executing plugin or theme code. It catches removed functions, changed signatures, and deprecated syntax, and filters out version-guarded fallbacks, but cannot replace testing critical workflows on a staging server.', 'thrg-php-checker' ); ?></p>
        </div>
        <div class="thrg-pc-coverage__col thrg-pc-coverage__help">
            <img class="thrg-pc-mascot" src="<?php echo esc_url( plugins_url( 'assets/brand/thrg-heart-128.png', THRG_PHP_CHECKER_FILE ) ); ?>" alt="" width="44" height="44" />
            <p>
                <?php esc_html_e( 'Need a deeper look or someone to assist with PHP upgrades and security?', 'thrg-php-checker' ); ?><br />
                <?php esc_html_e( 'Contact', 'thrg-php-checker' ); ?>
                <a href="https://hackrepair.com/" title="<?php esc_attr_e( 'The Hack Repair Guy: WordPress security and hack repair', 'thrg-php-checker' ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'The Hack Repair Guy', 'thrg-php-checker' ); ?>
                    <?php echo wp_kses_post( THRG_PHP_Checker::NEW_TAB_HINT ); ?>
                </a>
            </p>
        </div>
    </footer>
</div>
<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

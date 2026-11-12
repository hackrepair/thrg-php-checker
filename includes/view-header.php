<?php
/** PHP Checker header panel.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template scope.
?>
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
<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

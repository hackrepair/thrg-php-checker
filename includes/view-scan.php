<?php
/** PHP Checker scan panel.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template scope.
?>
    <section id="pc-scan-bar" class="thrg-pc-toolbar" aria-label="<?php esc_attr_e( 'PHP Compatibility Scanner Controls', 'thrg-php-checker' ); ?>">
        <form id="thrg-pc-scan-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="thrg-pc-toolbar__form">
            <input type="hidden" name="action" value="thrg_pc_start" />
            <input type="hidden" name="job_id" value="<?php echo esc_attr( $scan_owned ? $scan_state['job_id'] : '' ); ?>" />
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
                <?php $all_scanned = $scanned_now > 0 && $scanned_now >= $total_comps; ?>
                <button type="submit" id="thrg-pc-btn-scan" class="button button-primary" <?php disabled( $scan_running && ! $scan_owned ); ?> <?php echo $all_scanned ? 'hidden' : ''; ?>>
                    <?php
                    if ( 0 === $scanned_now ) {
                        printf(
                            /* translators: %s: target PHP version */
                            esc_html__( 'Scan for PHP %s', 'thrg-php-checker' ),
                            esc_html( $selected_target )
                        );
                    } else {
                        esc_html_e( 'Scan Unchecked', 'thrg-php-checker' );
                    }
                    ?>
                </button>
                <?php if ( $scanned_now > 0 ) : ?>
                    <button type="submit" id="thrg-pc-btn-rescan" name="rescan" value="1" class="button <?php echo $all_scanned ? 'button-primary' : 'button-secondary'; ?>" <?php disabled( $scan_running && ! $scan_owned ); ?>>
                        <?php esc_html_e( 'Rescan All', 'thrg-php-checker' ); ?>
                    </button>
                <?php endif; ?>
                <button type="submit" id="thrg-pc-btn-stop" name="stop" value="1" class="button button-link-delete" <?php echo $scan_owned ? '' : 'hidden'; ?>>
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
                    <?php $scan_owned ? esc_html_e( 'Preparing scan…', 'thrg-php-checker' ) : esc_html_e( 'Another administrator or scheduled job is scanning. Reload later to start your scan.', 'thrg-php-checker' ); ?>
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

<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

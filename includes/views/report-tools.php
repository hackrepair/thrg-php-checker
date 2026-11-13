<?php
/** Report runtime log, history, settings, and support partial.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template scope.
?>
            <!-- Runtime PHP Deprecation Log Section -->
            <section id="pc-runtime-log" class="thrg-card thrg-panel">
                <header class="thrg-card-header">
                    <div class="thrg-card-meta">
                        <span class="thrg-area-badge"><?php esc_html_e( 'Runtime Log', 'thrg-php-checker' ); ?></span>
                        <span class="thrg-status-pill <?php echo ! empty( $settings['runtime_log'] ) ? 'thrg-status-ok' : 'thrg-status-review'; ?>">
                            <?php echo ! empty( $settings['runtime_log'] ) ? esc_html__( 'Live capture active', 'thrg-php-checker' ) : esc_html__( 'On-demand / Off by default', 'thrg-php-checker' ); ?>
                        </span>
                    </div>
                    <h2 class="thrg-card-title"><?php esc_html_e( 'Runtime PHP Deprecation Notices (Grouped by Plugin)', 'thrg-php-checker' ); ?></h2>
                </header>
                <p class="thrg-pc-section-desc">
                    <?php esc_html_e( 'Capture live E_DEPRECATED and E_USER_DEPRECATED notices (including WordPress _deprecated_function() calls via backtrace) or import existing PHP Deprecated lines from wp-content/debug.log, grouped by plugin or theme.', 'thrg-php-checker' ); ?>
                </p>

                <details id="pc-runtime-details" class="thrg-pc-tech-details" <?php echo ( '' !== $rt_flag || ! empty( $runtime_groups ) ) ? 'open' : ''; ?>>
                    <summary class="thrg-pc-tech-summary">
                        <span>
                            <?php
                            printf(
                                /* translators: %d: number of plugins/themes with logged runtime deprecations */
                                esc_html__( 'Logged runtime deprecation groups (%d)', 'thrg-php-checker' ),
                                count( $runtime_groups )
                            );
                            ?>
                        </span>
                        <span class="thrg-pc-tech-summary__hint"><?php esc_html_e( 'Click to view or manage runtime deprecations', 'thrg-php-checker' ); ?></span>
                    </summary>

                    <div class="thrg-pc-history-actions">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="thrg-pc-inline-form">
                            <input type="hidden" name="action" value="thrg_pc_runtime_log" />
                            <input type="hidden" name="subaction" value="toggle" />
                            <input type="hidden" name="target" value="<?php echo esc_attr( $selected_target ); ?>" />
                            <?php wp_nonce_field( 'thrg_pc_runtime_log' ); ?>
                            <button type="submit" class="button button-primary">
                                <?php echo ! empty( $settings['runtime_log'] ) ? esc_html__( 'Pause Live Deprecation Capture', 'thrg-php-checker' ) : esc_html__( 'Enable Live Deprecation Capture', 'thrg-php-checker' ); ?>
                            </button>
                        </form>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="thrg-pc-inline-form">
                            <input type="hidden" name="action" value="thrg_pc_runtime_log" />
                            <input type="hidden" name="subaction" value="import" />
                            <input type="hidden" name="target" value="<?php echo esc_attr( $selected_target ); ?>" />
                            <?php wp_nonce_field( 'thrg_pc_runtime_log' ); ?>
                            <button type="submit" class="button button-secondary">
                                <?php esc_html_e( 'Import Deprecations from debug.log', 'thrg-php-checker' ); ?>
                            </button>
                        </form>

                        <?php if ( ! empty( $runtime_groups ) ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="thrg-pc-inline-form">
                                <input type="hidden" name="action" value="thrg_pc_runtime_log" />
                                <input type="hidden" name="subaction" value="clear" />
                                <input type="hidden" name="target" value="<?php echo esc_attr( $selected_target ); ?>" />
                                <?php wp_nonce_field( 'thrg_pc_runtime_log' ); ?>
                                <button type="submit" class="button button-link-delete">
                                    <?php esc_html_e( 'Clear Runtime Log', 'thrg-php-checker' ); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if ( empty( $runtime_groups ) ) : ?>
                        <p class="description">
                            <?php esc_html_e( 'No runtime deprecation notices recorded yet. Enable live capture above or click "Import Deprecations from debug.log" to inspect existing log entries.', 'thrg-php-checker' ); ?>
                        </p>
                    <?php else : ?>
                        <?php foreach ( $runtime_groups as $rt_group ) : ?>
                            <div class="thrg-pc-runtime-group">
                                <h3 class="thrg-pc-runtime-group__title">
                                    <?php echo esc_html( $rt_group['name'] ); ?>
                                    <?php if ( '' !== $rt_group['version'] ) : ?>
                                        <span class="thrg-pc-comp-ver">v<?php echo esc_html( $rt_group['version'] ); ?></span>
                                    <?php endif; ?>
                                    <span class="thrg-pc-origin-badge thrg-pc-origin-badge--own">
                                        <?php
                                        printf(
                                            /* translators: 1: unique notice count, 2: total hit count */
                                            esc_html__( '%1$d notice(s) · %2$d total hit(s)', 'thrg-php-checker' ),
                                            count( $rt_group['items'] ),
                                            (int) $rt_group['total_hits']
                                        );
                                        ?>
                                    </span>
                                </h3>
                                <div class="thrg-pc-findings-wrap">
                                    <table class="widefat striped thrg-pc-findings-table">
                                        <thead>
                                            <tr>
                                                <th class="thrg-pc-col-sev"><?php esc_html_e( 'Hits & PHP', 'thrg-php-checker' ); ?></th>
                                                <th class="thrg-pc-col-loc"><?php esc_html_e( 'File : Line', 'thrg-php-checker' ); ?></th>
                                                <th class="thrg-pc-col-msg"><?php esc_html_e( 'Runtime Deprecation Notice', 'thrg-php-checker' ); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $rt_group['items'] as $rt_item ) : ?>
                                                <tr>
                                                    <td>
                                                        <span class="thrg-pc-sev thrg-pc-sev--warning">
                                                            <?php echo (int) $rt_item['count']; ?>&times;
                                                        </span>
                                                        <?php if ( '' !== $rt_item['php'] ) : ?>
                                                            <span class="thrg-pc-target-tag">PHP <?php echo esc_html( $rt_item['php'] ); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <code><?php echo esc_html( $rt_item['line'] > 0 ? $rt_item['file'] . ':' . $rt_item['line'] : $rt_item['file'] ); ?></code>
                                                    </td>
                                                    <td>
                                                        <div><?php echo esc_html( $rt_item['message'] ); ?></div>
                                                        <?php if ( ! empty( $rt_item['last_seen'] ) ) : ?>
                                                            <div class="thrg-pc-rule-meta">
                                                                <?php
                                                                printf(
                                                                    /* translators: %s: formatted date of last notice */
                                                                    esc_html__( 'Last seen: %s', 'thrg-php-checker' ),
                                                                    esc_html( wp_date( 'Y-m-d H:i', (int) $rt_item['last_seen'] ) )
                                                                );
                                                                ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
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
                        <?php echo wp_kses_post( __( 'Pair this scanner with our free <a href="https://hackrepair.github.io/hackrepair-plugin-detective/" target="_blank" rel="noopener noreferrer"><strong>The Hack Repair Guy\'s Plugin Detective</strong></a> plugin. While PHP Checker flags incompatible code before you upgrade, Plugin Detective helps you pinpoint live plugin conflicts during testing and includes emergency recovery tools in case an update or PHP switch ever locks you out of your WordPress dashboard.', 'thrg-php-checker' ) ); ?>
                    </div>
                </div>
                <p>&#9749; <?php echo wp_kses_post( __( 'If you found this plugin helpful, please <a href="https://hackrepair.com/donations/buy-jim-a-coffee" target="_blank" rel="noopener noreferrer">buy Jim a cup of coffee?</a>', 'thrg-php-checker' ) ); ?></p>
                <p><span class="dashicons dashicons-editor-help" aria-hidden="true"></span> <?php echo wp_kses_post( __( 'Questions and Feedback: For questions, ideas, or help with the plugin, <a href="https://github.com/hackrepair/thrg-php-checker/discussions" target="_blank" rel="noopener noreferrer">visit GitHub Discussions</a>.', 'thrg-php-checker' ) ); ?></p>
            </section>

<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

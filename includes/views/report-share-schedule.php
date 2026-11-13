<?php
/** Report share, export, email, and schedule partial.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template scope.
?>
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

            <!-- Scheduled Weekly / Monthly PHP Readiness Email Section -->
            <section id="pc-schedule" class="thrg-card thrg-panel">
                <header class="thrg-card-header">
                    <div class="thrg-card-meta">
                        <span class="thrg-area-badge"><?php esc_html_e( 'Scheduled Monitoring', 'thrg-php-checker' ); ?></span>
                        <span class="thrg-status-pill <?php echo ! empty( $schedule['enabled'] ) ? 'thrg-status-ok' : 'thrg-status-review'; ?>">
                            <?php
                            if ( ! empty( $schedule['enabled'] ) ) {
                                echo 'weekly' === $schedule['frequency'] ? esc_html__( 'Weekly enabled', 'thrg-php-checker' ) : esc_html__( 'Monthly enabled', 'thrg-php-checker' );
                            } else {
                                esc_html_e( 'Off by default', 'thrg-php-checker' );
                            }
                            ?>
                        </span>
                    </div>
                    <h2 class="thrg-card-title"><?php esc_html_e( 'Weekly or Monthly PHP Readiness & Update Alert Email', 'thrg-php-checker' ); ?></h2>
                </header>
                <p class="thrg-pc-section-desc">
                    <?php esc_html_e( 'Run a weekly or monthly rescan against the next PHP version and pending updates, with an email alert when an offered update or the next PHP version has known compatibility problems.', 'thrg-php-checker' ); ?>
                </p>

                <details id="pc-schedule-details" class="thrg-pc-tech-details" <?php echo ( $sched_ok || ! empty( $schedule['enabled'] ) ) ? 'open' : ''; ?>>
                    <summary class="thrg-pc-tech-summary">
                        <span><?php esc_html_e( 'Configure weekly/monthly schedule & recipients', 'thrg-php-checker' ); ?></span>
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
                            $sched_status_val = isset( $schedule_log['result'] ) ? (string) $schedule_log['result'] : ( isset( $schedule_log['status'] ) ? (string) $schedule_log['status'] : 'sent' );
                            printf(
                                /* translators: 1: date, 2: status code, 3: sent count */
                                esc_html__( '%1$s — status: %2$s (%3$d sent)', 'thrg-php-checker' ),
                                esc_html( wp_date( 'Y-m-d H:i T', (int) $schedule_log['time'] ) ),
                                esc_html( $sched_status_val ),
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
                                <strong><?php esc_html_e( 'Send a scheduled PHP compatibility & update alert email', 'thrg-php-checker' ); ?></strong>
                            </label>
                        </p>

                        <div class="thrg-pc-form-grid">
                            <div>
                                <label for="thrg-pc-sched-freq"><strong><?php esc_html_e( 'Cadence', 'thrg-php-checker' ); ?></strong></label><br />
                                <select id="thrg-pc-sched-freq" name="frequency">
                                    <option value="weekly" <?php selected( $schedule['frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly (recommended)', 'thrg-php-checker' ); ?></option>
                                    <option value="monthly" <?php selected( $schedule['frequency'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'thrg-php-checker' ); ?></option>
                                </select>
                            </div>

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
                                <label for="thrg-pc-sched-weekday"><strong><?php esc_html_e( 'Day of week (if weekly, 8:00 AM)', 'thrg-php-checker' ); ?></strong></label><br />
                                <?php
                                $weekdays = array(
                                    1 => __( 'Monday', 'thrg-php-checker' ),
                                    2 => __( 'Tuesday', 'thrg-php-checker' ),
                                    3 => __( 'Wednesday', 'thrg-php-checker' ),
                                    4 => __( 'Thursday', 'thrg-php-checker' ),
                                    5 => __( 'Friday', 'thrg-php-checker' ),
                                    6 => __( 'Saturday', 'thrg-php-checker' ),
                                    0 => __( 'Sunday', 'thrg-php-checker' ),
                                );
                                ?>
                                <select id="thrg-pc-sched-weekday" name="weekday">
                                    <?php foreach ( $weekdays as $w_idx => $w_name ) : ?>
                                        <option value="<?php echo (int) $w_idx; ?>" <?php selected( (int) $schedule['weekday'], $w_idx ); ?>>
                                            <?php echo esc_html( $w_name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label for="thrg-pc-sched-day"><strong><?php esc_html_e( 'Day of month (if monthly, 1–28)', 'thrg-php-checker' ); ?></strong></label><br />
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
                                <?php esc_html_e( 'Only send the email alert when an offered update or the next PHP version has errors or warnings', 'thrg-php-checker' ); ?>
                            </label>
                        </p>

                        <fieldset class="thrg-pc-fieldset">
                            <legend><strong><?php esc_html_e( 'Who should receive the scheduled report?', 'thrg-php-checker' ); ?></strong></legend>
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
                            <label for="thrg-pc-sched-note"><strong><?php esc_html_e( 'Optional note', 'thrg-php-checker' ); ?></strong></label><br />
                            <textarea id="thrg-pc-sched-note" name="note" rows="2" class="large-text" maxlength="<?php echo (int) THRG_PC_Email::NOTE_LENGTH; ?>"><?php echo esc_textarea( (string) $schedule['note'] ); ?></textarea>
                        </p>

                        <p>
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Schedule', 'thrg-php-checker' ); ?></button>
                        </p>
                    </form>
                </details>
            </section>

<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

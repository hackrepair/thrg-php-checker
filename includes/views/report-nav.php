<?php
/** Report sticky left navigation partial.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template scope.
?>
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
                <div class="thrg-nav-group thrg-nav-group--review">
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
                    <li class="thrg-nav-item-with-tip">
                        <a href="#pc-share" class="thrg-nav-link"><span class="thrg-nav-text"><?php esc_html_e( 'Export & Email Report', 'thrg-php-checker' ); ?></span></a>
                        <button type="button" class="thrg-nav-tip" aria-label="<?php esc_attr_e( 'About Export & Email Report', 'thrg-php-checker' ); ?>" data-tip="<?php esc_attr_e( 'Copy plain-text results, download a .txt file, print a clean summary, or email a formatted readiness report to site administrators.', 'thrg-php-checker' ); ?>">?</button>
                    </li>
                    <li class="thrg-nav-item-with-tip">
                        <a href="#pc-schedule" class="thrg-nav-link" data-open-details="pc-schedule-details"><span class="thrg-nav-text"><?php esc_html_e( 'Scheduled Readiness Email', 'thrg-php-checker' ); ?></span></a>
                        <button type="button" class="thrg-nav-tip" aria-label="<?php esc_attr_e( 'About Scheduled Readiness Email', 'thrg-php-checker' ); ?>" data-tip="<?php esc_attr_e( 'Set up an optional weekly or monthly background scan that emails administrators when an update or the next PHP version has compatibility problems.', 'thrg-php-checker' ); ?>">?</button>
                    </li>
                    <li class="thrg-nav-item-with-tip">
                        <a href="#pc-runtime-log" class="thrg-nav-link" data-open-details="pc-runtime-details">
                            <span class="thrg-nav-text"><?php esc_html_e( 'Runtime Deprecation Log', 'thrg-php-checker' ); ?></span>
                            <?php if ( ! empty( $runtime_groups ) ) : ?>
                                <span class="thrg-nav-badge thrg-nav-badge--warn"><?php echo count( $runtime_groups ); ?></span>
                            <?php endif; ?>
                        </a>
                        <button type="button" class="thrg-nav-tip" aria-label="<?php esc_attr_e( 'About Runtime Deprecation Log', 'thrg-php-checker' ); ?>" data-tip="<?php esc_attr_e( 'Record live PHP deprecation notices (E_DEPRECATED) or import wp-content/debug.log entries, grouped by plugin and theme.', 'thrg-php-checker' ); ?>">?</button>
                    </li>
                    <li class="thrg-nav-item-with-tip">
                        <a href="#pc-history" class="thrg-nav-link" data-open-details="pc-history-details"><span class="thrg-nav-text"><?php esc_html_e( 'Saved Report History', 'thrg-php-checker' ); ?></span></a>
                        <button type="button" class="thrg-nav-tip" aria-label="<?php esc_attr_e( 'About Saved Report History', 'thrg-php-checker' ); ?>" data-tip="<?php esc_attr_e( 'Browse, reopen, compare, or delete up to 25 locally saved compatibility reports for each PHP target.', 'thrg-php-checker' ); ?>">?</button>
                    </li>
                    <li class="thrg-nav-item-with-tip">
                        <a href="#pc-settings" class="thrg-nav-link" data-open-details="pc-settings-details"><span class="thrg-nav-text"><?php esc_html_e( 'Scanner Settings', 'thrg-php-checker' ); ?></span></a>
                        <button type="button" class="thrg-nav-tip" aria-label="<?php esc_attr_e( 'About Scanner Settings', 'thrg-php-checker' ); ?>" data-tip="<?php esc_attr_e( 'Configure your default target PHP version, toggle the Pending Update Pre-Scan, or clear cached scan results.', 'thrg-php-checker' ); ?>">?</button>
                    </li>
                    <li class="thrg-nav-item-with-tip">
                        <a href="#support" class="thrg-nav-link"><span class="thrg-nav-text"><?php esc_html_e( 'Support & Help', 'thrg-php-checker' ); ?></span></a>
                        <button type="button" class="thrg-nav-tip" aria-label="<?php esc_attr_e( 'About Support & Help', 'thrg-php-checker' ); ?>" data-tip="<?php esc_attr_e( 'Recommended companion tools like Plugin Detective, developer support links, and ways to share feedback.', 'thrg-php-checker' ); ?>">?</button>
                    </li>
                </ul>
            </div>
        </nav>

<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

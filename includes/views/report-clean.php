<?php
/** Report clean components partial.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template scope.
?>
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

<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

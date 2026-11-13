<?php
/** Report component finding cards partial.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template scope.
?>
            <?php if ( $has_tech_rows > 1 ) : ?>
                <div class="thrg-pc-section-bar">
                    <span class="thrg-pc-section-bar__title">
                        <?php
                        if ( ! empty( $broken_names ) && $has_fatal_err ) {
                            esc_html_e( 'Sorted by urgency: Fatal incompatibilities first, deprecation warnings second', 'thrg-php-checker' );
                        } elseif ( ! empty( $broken_names ) ) {
                            esc_html_e( 'Sorted by urgency: Compatibility errors first, deprecation warnings second', 'thrg-php-checker' );
                        } else {
                            esc_html_e( 'Findings sorted by urgency: Active plugins & theme first', 'thrg-php-checker' );
                        }
                        ?>
                    </span>
                    <div class="thrg-pc-section-bar__actions">
                        <div class="thrg-pc-filter-group" role="group" aria-label="<?php esc_attr_e( 'Filter findings', 'thrg-php-checker' ); ?>">
                            <button type="button" class="button button-small thrg-pc-filter-btn is-active" data-filter="all" aria-pressed="true"><?php esc_html_e( 'All', 'thrg-php-checker' ); ?></button>
                            <button type="button" class="button button-small thrg-pc-filter-btn" data-filter="breaks" aria-pressed="false"><?php esc_html_e( 'Incompatible only', 'thrg-php-checker' ); ?></button>
                            <button type="button" class="button button-small thrg-pc-filter-btn" data-filter="own" aria-pressed="false"><?php esc_html_e( 'Plugin / theme code only', 'thrg-php-checker' ); ?></button>
                        </div>
                        <button type="button" id="thrg-pc-toggle-all-details" class="button button-small button-link" data-expanded="0">
                            <?php esc_html_e( 'Expand all code tables', 'thrg-php-checker' ); ?>
                        </button>
                    </div>
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
                $is_inactive     = $is_comp_card && empty( $row['comp_active'] ) && empty( $row['comp_network_active'] );
                $card_extra_cls  = ( $is_comp_card ? ' thrg-card--comp' : '' ) . ( $is_inactive ? ' thrg-card--inactive' : '' );
                $row_verdict     = isset( $row['verdict'] ) ? (string) $row['verdict'] : '';
                ?>
                <article id="<?php echo esc_attr( $row['id'] ); ?>" class="thrg-card <?php echo esc_attr( $status_class( $row['status'] ) . $card_extra_cls ); ?>" data-comp="<?php echo $is_comp_card ? '1' : '0'; ?>" data-verdict="<?php echo esc_attr( $row_verdict ); ?>" data-own-issues="<?php echo (int) $own_count; ?>">
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
                            <?php if ( ! empty( $row['wporg_fix_status'] ) && ! empty( $row['wporg_fix_label'] ) ) : ?>
                                <span class="thrg-pc-wporg-pill thrg-pc-wporg-pill--<?php echo esc_attr( $row['wporg_fix_status'] ); ?>">
                                    <?php echo esc_html( $row['wporg_fix_label'] ); ?>
                                </span>
                            <?php elseif ( ! empty( $row['comp_update_version'] ) ) : ?>
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
                                <div class="thrg-pc-card-actions">
                                    <?php
                                    $has_update_link = ! empty( $row['comp_update_version'] ) || ( ! empty( $row['wporg_fix_status'] ) && in_array( $row['wporg_fix_status'], array( 'fixed', 'warnings_only' ), true ) );
                                    $can_update_comp = isset( $row['comp_kind'] ) && 'theme' === $row['comp_kind'] ? current_user_can( 'update_themes' ) : current_user_can( 'update_plugins' );
                                    ?>
                                    <?php if ( $has_update_link && $can_update_comp ) : ?>
                                        <a href="<?php echo esc_url( isset( $row['comp_kind'] ) && 'theme' === $row['comp_kind'] ? admin_url( 'themes.php' ) : admin_url( 'plugins.php?plugin_status=upgrade' ) ); ?>" class="button button-small button-primary">
                                            <?php esc_html_e( 'Go to Updates', 'thrg-php-checker' ); ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ( isset( $row['comp_kind'] ) && 'plugin' === $row['comp_kind'] && in_array( $row['verdict'], array( 'breaks', 'warnings' ), true ) ) : ?>
                                        <button type="button" class="button button-small thrg-pc-check-wporg-fix" data-slug="<?php echo esc_attr( $row['comp_slug'] ); ?>" data-version="<?php echo esc_attr( $row['comp_version'] ); ?>" data-target="<?php echo esc_attr( $selected_target ); ?>">
                                            <?php esc_html_e( 'Check if fixed on WP.org', 'thrg-php-checker' ); ?>
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="button button-small thrg-pc-rescan-single" data-single-key="<?php echo esc_attr( $row['comp_key'] ); ?>" data-target="<?php echo esc_attr( $selected_target ); ?>">
                                        <?php esc_html_e( 'Rescan', 'thrg-php-checker' ); ?>
                                    </button>
                                </div>
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
                            <div class="thrg-pc-wporg-status" aria-live="polite" hidden></div>
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
                                            <?php
                                            $thrg_pc_row_cols = false;
                                            foreach ( $initial_issues as $issue ) {
                                                require __DIR__ . '/finding-row.php';
                                            }
                                            ?>
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
                                                    <?php
                                                    $thrg_pc_row_cols = true;
                                                    foreach ( $overflow_issues as $issue ) {
                                                        require __DIR__ . '/finding-row.php';
                                                    }
                                                    ?>
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

<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

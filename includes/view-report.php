<?php
/** PHP Checker report panel.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template scope.
?>
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
                $is_inactive     = $is_comp_card && empty( $row['comp_active'] ) && empty( $row['comp_network_active'] );
                $card_extra_cls  = ( $is_comp_card ? ' thrg-card--comp' : '' ) . ( $is_inactive ? ' thrg-card--inactive' : '' );
                ?>
                <article id="<?php echo esc_attr( $row['id'] ); ?>" class="thrg-card <?php echo esc_attr( $status_class( $row['status'] ) . $card_extra_cls ); ?>">
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
        </main>
    </div>

<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

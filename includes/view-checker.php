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

require THRG_PHP_CHECKER_DIR . 'includes/view-checker-data.php';
?>
<div class="wrap thrg-inspector-wrap thrg-pc-wrap">
    <?php require THRG_PHP_CHECKER_DIR . 'includes/view-header.php'; ?>
    <?php require THRG_PHP_CHECKER_DIR . 'includes/view-scan.php'; ?>
    <?php require THRG_PHP_CHECKER_DIR . 'includes/view-report.php'; ?>
    <?php require THRG_PHP_CHECKER_DIR . 'includes/view-footer.php'; ?>
</div>
<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

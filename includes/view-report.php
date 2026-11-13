<?php
/** PHP Checker report panel.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template scope.
?>
    <div class="thrg-layout">
        <?php require __DIR__ . '/views/report-nav.php'; ?>

        <!-- Main Findings & Tools Column -->
        <main class="thrg-main">
            <?php require __DIR__ . '/views/report-cards.php'; ?>
            <?php require __DIR__ . '/views/report-clean.php'; ?>
            <?php require __DIR__ . '/views/report-share-schedule.php'; ?>
            <?php require __DIR__ . '/views/report-tools.php'; ?>
        </main>
    </div>
<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

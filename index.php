<?php
/**
 * Plugin Name:       The Hack Repair Guy's PHP Checker
 * Plugin URI:        https://github.com/hackrepair/thrg-php-checker
 * Description:       In-process PHP 8.1–8.5 static compatibility analyzer for plugins, must-use plugins, themes, and pending updates — powered by PHP_CodeSniffer & PHPCompatibilityWP with version-guard awareness, report history, and scheduled readiness emails.
 * Version:           1.2.4
 * Author:            The Hack Repair Guy
 * Author URI:        https://hackrepair.com/
 * Update URI:        https://github.com/hackrepair/thrg-php-checker
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Text Domain:       thrg-php-checker
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

const THRG_PHP_CHECKER_VERSION = '1.2.4';
const THRG_PHP_CHECKER_FILE    = __FILE__;
const THRG_PHP_CHECKER_DIR     = __DIR__ . '/';

/*
 * GitHub release updates. Deliberately NOT gated by is_admin(): WordPress's
 * update-check and auto-update machinery runs on cron, where is_admin() is false.
 * THRG_PC_SKIP_UPDATER is only defined by unit/smoke tests.
 */
if ( ! defined( 'THRG_PC_SKIP_UPDATER' ) ) {
    $thrg_pc_puc = plugin_dir_path( __FILE__ ) . 'plugin-update-checker/plugin-update-checker.php';
    if ( is_readable( $thrg_pc_puc ) ) {
        require_once $thrg_pc_puc;
    }
    unset( $thrg_pc_puc );

    require_once plugin_dir_path( __FILE__ ) . 'includes/class-thrg-pc-updater.php';
    $thrg_pc_updater = new THRG_PC_Updater();
    $thrg_pc_updater->init( __FILE__ );
    unset( $thrg_pc_updater );
}

require_once __DIR__ . '/includes/class-thrg-pc-context.php';
require_once __DIR__ . '/includes/class-thrg-pc-engine.php';
require_once __DIR__ . '/includes/class-thrg-pc-results.php';
require_once __DIR__ . '/includes/class-thrg-pc-scanner.php';
require_once __DIR__ . '/includes/class-thrg-pc-prescan.php';
require_once __DIR__ . '/includes/class-thrg-pc-runtime-log.php';
require_once __DIR__ . '/includes/class-thrg-pc-history.php';
require_once __DIR__ . '/includes/class-thrg-pc-email.php';
require_once __DIR__ . '/includes/class-thrg-pc-schedule.php';
require_once __DIR__ . '/includes/class-thrg-php-checker.php';
require_once __DIR__ . '/includes/companion-plugins.php';

THRG_PHP_Checker::boot();
THRG_PC_Prescan::boot();
THRG_PC_Runtime_Log::boot();
THRG_PC_Schedule::boot();

register_deactivation_hook(
    __FILE__,
    static function () {
        wp_clear_scheduled_hook( THRG_PC_Schedule::HOOK );
        wp_clear_scheduled_hook( THRG_PC_Prescan::HOOK );
        THRG_PC_Scanner::clear_state();
        THRG_PC_Prescan::clear_state();
    }
);

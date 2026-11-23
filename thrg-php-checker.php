<?php
/**
 * Plugin Name:       The Hack Repair Guy's PHP Checker
 * Description:       In-process PHP 8.1–8.5 static compatibility analyzer for plugins, must-use plugins, themes, and pending updates — powered by PHP_CodeSniffer & PHPCompatibilityWP with version-guard awareness, report history, and scheduled readiness emails.
 * Version:           1.2.9
 * Author:            The Hack Repair Guy
 * Author URI:        https://hackrepair.com/
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Text Domain:       thrg-php-checker
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

const THRG_PHP_CHECKER_VERSION = '1.2.9';
const THRG_PHP_CHECKER_FILE    = __FILE__;
const THRG_PHP_CHECKER_DIR     = __DIR__ . '/';

require_once __DIR__ . '/includes/class-thrg-pc-context.php';
require_once __DIR__ . '/includes/class-thrg-pc-dynamic-properties.php';
require_once __DIR__ . '/includes/class-thrg-pc-legacy-constructors.php';
require_once __DIR__ . '/includes/class-thrg-pc-scope.php';
require_once __DIR__ . '/includes/class-thrg-pc-boot.php';
require_once __DIR__ . '/includes/class-thrg-pc-grade.php';
require_once __DIR__ . '/includes/class-thrg-pc-engine.php';
require_once __DIR__ . '/includes/class-thrg-pc-results.php';
require_once __DIR__ . '/includes/class-thrg-pc-scanner.php';
require_once __DIR__ . '/includes/class-thrg-pc-prescan.php';
require_once __DIR__ . '/includes/class-thrg-pc-runtime-log.php';
require_once __DIR__ . '/includes/class-thrg-pc-history.php';
require_once __DIR__ . '/includes/class-thrg-pc-email.php';
require_once __DIR__ . '/includes/class-thrg-pc-schedule.php';
require_once __DIR__ . '/includes/class-thrg-php-checker.php';

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

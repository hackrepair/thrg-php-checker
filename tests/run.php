<?php
/**
 * Unit and integration test suite for The Hack Repair Guy's PHP Checker.
 *
 * Run via: php tests/run.php
 *
 * @package THRG_PHP_Checker
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'THRG_PC_SKIP_UPDATER', true );
define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/thrg-pc-test-plugins' );
define( 'WPMU_PLUGIN_DIR', sys_get_temp_dir() . '/thrg-pc-test-mu-plugins' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

// Minimal WordPress test doubles for isolated CLI execution.
$GLOBALS['thrg_pc_test_options']    = array();
$GLOBALS['thrg_pc_test_transients'] = array();

function get_option( $name, $default_value = false ) {
    return array_key_exists( $name, $GLOBALS['thrg_pc_test_options'] ) ? $GLOBALS['thrg_pc_test_options'][ $name ] : $default_value;
}
function update_option( $name, $value, $autoload = null ) {
    unset( $autoload );
    $GLOBALS['thrg_pc_test_options'][ $name ] = $value;
    return true;
}
function delete_option( $name ) {
    unset( $GLOBALS['thrg_pc_test_options'][ $name ] );
    return true;
}
function get_transient( $name ) {
    return array_key_exists( $name, $GLOBALS['thrg_pc_test_transients'] ) ? $GLOBALS['thrg_pc_test_transients'][ $name ] : false;
}
function set_transient( $name, $value, $expiration = 0 ) {
    unset( $expiration );
    $GLOBALS['thrg_pc_test_transients'][ $name ] = $value;
    return true;
}
function delete_transient( $name ) {
    unset( $GLOBALS['thrg_pc_test_transients'][ $name ] );
    return true;
}
function wp_strip_all_tags( $text ) {
    return strip_tags( (string) $text );
}
function add_action() {}
function add_filter() {}
function register_deactivation_hook() {}
function plugin_basename( $file ) {
    return basename( dirname( $file ) ) . '/' . basename( $file );
}
function plugin_dir_path( $file ) {
    return rtrim( dirname( $file ), '/\\' ) . '/';
}
function esc_url( $url ) {
    return (string) $url;
}
function esc_html( $text ) {
    return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_html__( $text ) {
    return esc_html( $text );
}
function __( $text ) {
    return (string) $text;
}
function absint( $maybeint ) {
    return abs( (int) $maybeint );
}
function sanitize_title( $title ) {
    return strtolower( preg_replace( '/[^a-zA-Z0-9_-]+/', '-', (string) $title ) );
}
function sanitize_key( $key ) {
    return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
}
function is_email( $email ) {
    return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
}
function home_url( $path = '' ) {
    return 'https://example.com/' . ltrim( (string) $path, '/' );
}
function site_url( $path = '' ) {
    return home_url( $path );
}
function admin_url( $path = '' ) {
    return 'https://example.com/wp-admin/' . ltrim( (string) $path, '/' );
}
function wp_parse_url( $url, $component = -1 ) {
    return parse_url( $url, $component );
}
function wp_json_encode( $data ) {
    return json_encode( $data );
}
function wp_date( $format, $timestamp = null ) {
    return gmdate( $format, null === $timestamp ? time() : $timestamp );
}

require_once dirname( __DIR__ ) . '/index.php';

$assertions = 0;
function assert_true( $condition, $message ) {
    global $assertions;
    ++$assertions;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

function assert_same( $expected, $actual, $message ) {
    global $assertions;
    ++$assertions;
    if ( $expected !== $actual ) {
        fwrite( STDERR, sprintf( "FAIL: %s\n  Expected: %s\n  Actual:   %s\n", $message, var_export( $expected, true ), var_export( $actual, true ) ) );
        exit( 1 );
    }
}

// 1. Engine availability & real PHPCompatibilityWP scanning with guard/polyfill filtering.
assert_same( '', THRG_PC_Engine::unavailable_reason(), 'Bundled PHPCompatibilityWP engine should be available' );

$tmp_dir = sys_get_temp_dir() . '/thrg-pc-fixture-' . uniqid();
mkdir( $tmp_dir, 0755, true );

// Un-guarded PHP 8.2+ error (utf8_encode deprecated in 8.2, or create_function removed in 8.0).
$unguarded_file = $tmp_dir . '/unguarded.php';
file_put_contents( $unguarded_file, "<?php\n\$fn = create_function('\$a', 'return \$a;');\n" );

// Guarded fallback (inside version_compare and function_exists).
$guarded_file = $tmp_dir . '/guarded.php';
file_put_contents(
    $guarded_file,
    "<?php\nif ( version_compare( PHP_VERSION, '8.0', '<' ) && function_exists( 'create_function' ) ) {\n    \$fn = create_function( '\$a', 'return \$a;' );\n}\n"
);

// Conditional polyfill declaration.
$polyfill_file = $tmp_dir . '/polyfill.php';
file_put_contents(
    $polyfill_file,
    "<?php\nif ( ! function_exists( 'str_contains' ) ) {\n    function str_contains( \$haystack, \$needle ) {\n        return '' === \$needle || false !== strpos( \$haystack, \$needle );\n    }\n}\n"
);

$scan_out = THRG_PC_Engine::scan( array( $unguarded_file, $guarded_file, $polyfill_file ), '8.4', microtime( true ) + 30.0 );
assert_same( array(), $scan_out['remaining'], 'All fixture files should be scanned in one slice' );

$unguarded_findings = $scan_out['scanned'][ $unguarded_file ];
$unguarded_counts   = THRG_PC_Results::count( $unguarded_findings );
assert_true( $unguarded_counts['errors'] > 0, 'Unguarded create_function() must report an error on PHP 8.4' );

$guarded_findings = $scan_out['scanned'][ $guarded_file ];
$guarded_counts   = THRG_PC_Results::count( $guarded_findings );
assert_same( 0, $guarded_counts['errors'], 'Version-guarded create_function() must have 0 errors' );
assert_true( $guarded_counts['notes'] > 0, 'Version-guarded create_function() must be downgraded to a note' );

@unlink( $unguarded_file );
@unlink( $guarded_file );
@unlink( $polyfill_file );

// 1b. PHP 8.2+ Dynamic Properties & False-Positive Fixtures (per PHP 8.2 migration guide).
$dyn_ordinary_file = $tmp_dir . '/dyn-ordinary.php';
file_put_contents(
    $dyn_ordinary_file,
    "<?php\nclass PlainWidget {\n    public \$declared = '';\n    public function __construct( private string \$promoted = 'ok' ) {}\n    public function run() {\n        \$this->declared = 'fine';\n        \$this->promoted = 'also fine';\n        \$this->undeclared_prop = 'warns on 8.2+';\n    }\n}\n"
);

$dyn_inherited_allow_file = $tmp_dir . '/dyn-allow-inherited.php';
file_put_contents(
    $dyn_inherited_allow_file,
    "<?php\n#[AllowDynamicProperties]\nclass BaseAllowDynamic {}\nclass ChildAllowDynamic extends BaseAllowDynamic {\n    public function run() {\n        \$this->allowed_via_parent = 123;\n    }\n}\n"
);

$dyn_stdclass_file = $tmp_dir . '/dyn-stdclass.php';
file_put_contents(
    $dyn_stdclass_file,
    "<?php\nclass UsesStdClass {\n    public function run() {\n        \$bag = new stdClass();\n        \$bag->arbitrary = 'ok';\n    }\n}\nclass ExtendsStdClass extends stdClass {\n    public function run() {\n        \$this->arbitrary_child = 'ok';\n    }\n}\n"
);

$dyn_setter_file = $tmp_dir . '/dyn-setter.php';
file_put_contents(
    $dyn_setter_file,
    "<?php\nclass MagicSetterHandled {\n    private \$data = array();\n    public function __set( \$name, \$value ) {\n        \$this->data[ \$name ] = \$value;\n    }\n    public function run() {\n        \$this->handled_by_setter = 'no dynamic property created';\n    }\n}\n"
);

$dyn_guarded_file = $tmp_dir . '/dyn-guarded.php';
file_put_contents(
    $dyn_guarded_file,
    "<?php\nclass GuardedDynamic {\n    public function run() {\n        if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {\n            \$this->legacy_only = 1;\n        }\n    }\n}\n"
);

$dyn_files   = array( $dyn_ordinary_file, $dyn_inherited_allow_file, $dyn_stdclass_file, $dyn_setter_file, $dyn_guarded_file );
$dyn_scan_81 = THRG_PC_Engine::scan( array( $dyn_ordinary_file ), '8.1', microtime( true ) + 30.0 );
$dyn_81_cnt  = THRG_PC_Results::count( $dyn_scan_81['scanned'][ $dyn_ordinary_file ] );
assert_same( 0, $dyn_81_cnt['warnings'], 'Ordinary undeclared property must NOT warn on PHP 8.1 target' );

$dyn_scan_82 = THRG_PC_Engine::scan( $dyn_files, '8.2', microtime( true ) + 30.0 );

$ord_findings = $dyn_scan_82['scanned'][ $dyn_ordinary_file ];
$ord_counts   = THRG_PC_Results::count( $ord_findings );
assert_same( 1, $ord_counts['warnings'], 'Ordinary undeclared property ($this->undeclared_prop) must warn on PHP 8.2+ while ignoring declared & promoted properties' );
assert_same( 'PHPCompatibility.Classes.ForbiddenDynamicProperties.Found', $ord_findings[0]['source'], 'Dynamic property warning must report the exact rule code' );

$allow_counts = THRG_PC_Results::count( $dyn_scan_82['scanned'][ $dyn_inherited_allow_file ] );
assert_same( 0, $allow_counts['warnings'], 'Class inheriting #[AllowDynamicProperties] must produce 0 warnings' );

$std_counts = THRG_PC_Results::count( $dyn_scan_82['scanned'][ $dyn_stdclass_file ] );
assert_same( 0, $std_counts['warnings'], 'stdClass instance and subclass assignments must produce 0 warnings' );

$setter_counts = THRG_PC_Results::count( $dyn_scan_82['scanned'][ $dyn_setter_file ] );
assert_same( 0, $setter_counts['warnings'], 'Class with __set() handling the value must produce 0 warnings' );

$guarded_dyn_counts = THRG_PC_Results::count( $dyn_scan_82['scanned'][ $dyn_guarded_file ] );
assert_same( 0, $guarded_dyn_counts['warnings'], 'Version-guarded dynamic property must produce 0 warnings' );
assert_same( 1, $guarded_dyn_counts['notes'], 'Version-guarded dynamic property must be downgraded to a note' );

foreach ( $dyn_files as $f_cleanup ) {
    @unlink( $f_cleanup );
}
@rmdir( $tmp_dir );

// 1c. File origin classification (plugin's own code vs bundled dependency).
assert_same( 'own', THRG_PC_Results::file_origin( 'includes/class-main.php' ), 'Plugin includes/ path should be classified as own code' );
assert_same( 'bundled', THRG_PC_Results::file_origin( 'vendor/acme/sdk/Client.php' ), 'vendor/ path should be classified as bundled dependency' );
assert_same( 'bundled', THRG_PC_Results::file_origin( 'includes/freemius/start.php' ), 'freemius/ path should be classified as bundled dependency' );

// 2. Results classification & orphan pruning (fixing both BrightLabs bugs).
$skipped_only = array(
    array(
        'file'    => 'big-bundle.php',
        'line'    => 0,
        'type'    => 'skipped',
        'message' => 'File over 2 MB was not scanned.',
        'source'  => 'THRG.FileSkipped',
    ),
);
$c_skipped = THRG_PC_Results::count( $skipped_only );
assert_same( 0, $c_skipped['warnings'], 'Skipped oversized files must NOT be counted as deprecation warnings' );
assert_same( 1, $c_skipped['skipped'], 'Skipped oversized files must be counted in skipped' );
assert_same( 'partial', THRG_PC_Results::verdict( $c_skipped['errors'], $c_skipped['warnings'], $c_skipped['skipped'] ), 'Skipped-only component must get partial verdict' );

// Save two components, then verify for_target() prunes uninstalled or version-changed components.
THRG_PC_Results::clear_all();
THRG_PC_Results::save(
    array(
        'kind'        => 'plugin',
        'slug'        => 'alpha/alpha.php',
        'name'        => 'Alpha Plugin',
        'version'     => '1.0.0',
        'files_total' => 4,
    ),
    '8.4',
    $unguarded_findings
);
THRG_PC_Results::save(
    array(
        'kind'        => 'plugin',
        'slug'        => 'ghost/ghost.php',
        'name'        => 'Deleted Ghost Plugin',
        'version'     => '2.0.0',
        'files_total' => 2,
    ),
    '8.4',
    array()
);

$installed_now = array(
    array(
        'kind'           => 'plugin',
        'slug'           => 'alpha/alpha.php',
        'name'           => 'Alpha Plugin',
        'version'        => '1.0.0',
        'active'         => true,
        'network_active' => false,
        'parent_theme'   => false,
        'update_version' => '1.1.0',
    ),
);
$synced = THRG_PC_Results::for_target( '8.4', $installed_now );
assert_same( 1, count( $synced ), 'Uninstalled ghost plugin must be automatically pruned from results' );
assert_same( '1.1.0', $synced[0]['update_version'], 'Pending update version should be attached to synced result' );

// 3. Pure evaluation & PHP lifecycle/advisory checks.
$patch_83 = THRG_PHP_Checker::php_patch( '8.3.10', strtotime( '2026-05-01 UTC' ) );
assert_true( $patch_83['behind'], 'PHP 8.3.10 should be flagged as behind latest 8.3 patch' );
assert_true( ! empty( $patch_83['advisories'] ), 'PHP 8.3.10 should trigger PHP2Shell advisory' );

$eval_rows = THRG_PHP_Checker::evaluate(
    array(
        'now'             => strtotime( '2026-05-01 UTC' ),
        'php_version'     => '8.3.10',
        'running_php'     => '8.3',
        'target'          => '8.4',
        'engine_reason'   => '',
        'components'      => $installed_now,
        'results'         => $synced,
        'prescan_enabled' => false,
        'update_items'    => array(),
    )
);
assert_same( 2, count( $eval_rows ), 'Evaluation with scanned components should return incompatible component first and server row second (no redundant overview row)' );
assert_same( 'comp-plugin-alpha-alpha-php', $eval_rows[0]['id'], 'Incompatible plugin must be the #1 item at the very top of findings' );
assert_same( 'Potential weakness', $eval_rows[0]['status'], 'Incompatible plugin should have Potential weakness status' );
assert_true( false !== strpos( $eval_rows[0]['next_steps'], '1.1.0' ), 'Next steps should highlight available update 1.1.0' );
assert_same( 'php-version', $eval_rows[1]['id'], 'Non-EOL server lifecycle check should appear after broken components' );

$plain_out = THRG_PHP_Checker::plain_text( $eval_rows, 'example.com', '2026-05-01 00:00:00 UTC', '1.0.0', '8.4' );
assert_true( false !== strpos( $plain_out, 'PHP 8.4 | plugin code' ), 'Plain-text report must include target PHP version and origin (plugin code vs bundled dependency)' );
assert_true( false !== strpos( $plain_out, '[Rule: ' . $unguarded_findings[0]['source'] . ']' ), 'Plain-text report must include the triggered rule identifier' );

// 4. History & target-aware diffing.
THRG_PC_History::clear();
$id1    = THRG_PC_History::add( $eval_rows, '8.4', 'example.com', '1.0.0', 1, 'admin', 1700000000 );
$entry1 = THRG_PC_History::find( $id1 );
assert_true( THRG_PC_History::valid_id( $id1 ) && is_array( $entry1 ), 'First history entry should be saved with valid 12-hex ID' );

// 5. Email standard compliance & header injection protection.
$subject = THRG_PC_Email::subject( $eval_rows, 'example.com', '8.4', null, false );
assert_true( 0 === strpos( $subject, '[example.com] [PHP Checker] PHP 8.4 Check:' ), 'Email subject must follow [domain] [PHP Checker] standard' );
assert_same( '', THRG_PC_Email::reply_to( 'Admin', "bad@example.com\r\nBcc: attacker@evil.test" ), 'Reply-To must reject CRLF injection' );

// 6. Schedule next_run calculation (Monthly and Weekly).
$tz   = new DateTimeZone( 'UTC' );
$next = THRG_PC_Schedule::next_run( strtotime( '2026-05-10 12:00:00 UTC' ), 1, $tz );
assert_same( '2026-06-01 08:00:00', gmdate( 'Y-m-d H:i:s', $next ), 'Monthly schedule should pick day 1 of next month at 08:00' );

// 2026-05-10 is a Sunday; weekday 1 = Monday (2026-05-11 08:00:00).
$next_weekly = THRG_PC_Schedule::next_run( strtotime( '2026-05-10 12:00:00 UTC' ), 1, $tz, 'weekly', 1 );
assert_same( '2026-05-11 08:00:00', gmdate( 'Y-m-d H:i:s', $next_weekly ), 'Weekly schedule should pick next Monday at 08:00' );

// 7. WordPress.org newer-version fix verification in component_finding().
THRG_PC_Results::save_update( 'plugin', 'alpha', '1.1.0', '8.4', array() );
$eval_fixed = THRG_PHP_Checker::evaluate(
    array(
        'now'             => strtotime( '2026-05-01 UTC' ),
        'php_version'     => '8.3.10',
        'running_php'     => '8.3',
        'target'          => '8.4',
        'engine_reason'   => '',
        'components'      => $installed_now,
        'results'         => $synced,
        'prescan_enabled' => false,
        'update_items'    => array(),
    )
);
assert_same( 'fixed', $eval_fixed[0]['wporg_fix_status'], 'Plugin whose newer 1.1.0 version has 0 errors/warnings on target 8.4 must report wporg_fix_status = fixed' );
assert_same( 'Fixed in v1.1.0 on WordPress.org', $eval_fixed[0]['wporg_fix_label'], 'Plugin card must display Fixed in v1.1.0 on WordPress.org badge' );

// 8. Runtime PHP deprecation notice collector & debug.log parser grouped by plugin.
THRG_PC_Runtime_Log::clear();
$fake_log = sys_get_temp_dir() . '/thrg-pc-test-debug-' . uniqid() . '.log';
file_put_contents(
    $fake_log,
    "[11-Nov-2026 12:00:00 UTC] PHP Deprecated:  Creation of dynamic property Alpha::\$foo is deprecated in /var/www/html/wp-content/plugins/alpha/includes/main.php on line 42\n" .
    "[11-Nov-2026 12:01:00 UTC] PHP Deprecated:  Creation of dynamic property Alpha::\$foo is deprecated in /var/www/html/wp-content/plugins/alpha/includes/main.php on line 42\n" .
    "[11-Nov-2026 12:02:00 UTC] PHP User Deprecated:  Function old_helper is deprecated since version 2.0 in /var/www/html/wp-content/themes/flavor/functions.php on line 19\n"
);
$imported_cnt = THRG_PC_Runtime_Log::import_debug_log( $fake_log );
@unlink( $fake_log );
assert_same( 3, $imported_cnt, 'import_debug_log() must parse 3 deprecation lines' );

$rt_groups = THRG_PC_Runtime_Log::grouped( $installed_now );
assert_same( 2, count( $rt_groups ), 'Runtime deprecations must be grouped into 2 components (alpha plugin and flavor theme)' );
assert_same( 'Alpha Plugin', $rt_groups[0]['name'], 'First runtime group should resolve installed plugin name Alpha Plugin' );
assert_same( 2, $rt_groups[0]['total_hits'], 'Duplicate deprecation lines for Alpha Plugin should aggregate to 2 hits' );

echo "OK ({$assertions} assertions passed)\n";

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

class THRG_PC_Test_WPDB {
    public $options = 'wp_options';

    public function prepare( $query, ...$args ) {
        return array( $query, $args );
    }

    public function query( $prepared ) {
        list( $sql, $args ) = $prepared;
        $name = $args[0];
        if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
            if ( array_key_exists( $name, $GLOBALS['thrg_pc_test_options'] ) ) {
                return 0;
            }
            $GLOBALS['thrg_pc_test_options'][ $name ] = unserialize( $args[1] );
            return 1;
        }
        if ( 0 === strpos( $sql, 'UPDATE' ) && isset( $GLOBALS['thrg_pc_test_options'][ $args[1] ] ) &&
            maybe_serialize( $GLOBALS['thrg_pc_test_options'][ $args[1] ] ) === $args[2] ) {
            $GLOBALS['thrg_pc_test_options'][ $args[1] ] = unserialize( $args[0] );
            return 1;
        }
        if ( 0 === strpos( $sql, 'DELETE FROM' ) && isset( $GLOBALS['thrg_pc_test_options'][ $name ] ) &&
            maybe_serialize( $GLOBALS['thrg_pc_test_options'][ $name ] ) === $args[1] ) {
            unset( $GLOBALS['thrg_pc_test_options'][ $name ] );
            return 1;
        }
        return 0;
    }
}
$GLOBALS['wpdb'] = new THRG_PC_Test_WPDB();

function maybe_serialize( $value ) {
    return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value;
}
function wp_cache_delete( $key, $group = '' ) {
    unset( $key, $group );
    return true;
}

class WP_Error {
    public function __construct( $code = '', $message = '' ) {
        unset( $code, $message );
    }
}
function is_wp_error( $value ) {
    return $value instanceof WP_Error;
}

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
function apply_filters( $hook, $value ) {
    unset( $hook );
    return $value;
}
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

// Guard analysis must distinguish unreachable runtime calls from reachable ones.
$guard_cases = array(
    'nested'      => "<?php\nif ( PHP_VERSION_ID < 80000 ) {\n    if ( function_exists( 'create_function' ) ) {\n        create_function( '\$a', 'return \$a;' );\n    }\n}\n",
    'short_and'   => "<?php\nPHP_VERSION_ID < 80000 && create_function( '\$a', 'return \$a;' );\n",
    'short_or'    => "<?php\nPHP_VERSION_ID >= 80000 || create_function( '\$a', 'return \$a;' );\n",
    'reachable_or'=> "<?php\nPHP_VERSION_ID < 80000 || create_function( '\$a', 'return \$a;' );\n",
    'namespace'   => "<?php\nnamespace Demo;\nif ( function_exists( 'create_function' ) ) {\n    \\create_function( '\$a', 'return \$a;' );\n}\n",
    'early_exit'  => "<?php\nfunction legacy_only() {\n    if ( PHP_VERSION_ID >= 80000 ) { return; }\n    create_function( '\$a', 'return \$a;' );\n}\n",
    'ext_const'   => "<?php\nif ( function_exists( 'mcrypt_encrypt' ) ) {\n    \$m = MCRYPT_RIJNDAEL_128;\n}\n",
    'ext_loaded'  => "<?php\nif ( extension_loaded( 'mcrypt' ) ) {\n    \$k = mcrypt_get_key_size( MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC );\n}\n",
    'ext_open'    => "<?php\n\$m = MCRYPT_RIJNDAEL_128;\n",
    'wrong_exit'  => "<?php\nfunction modern_only() {\n    if ( PHP_VERSION_ID < 80000 ) { return; }\n    create_function( '\$a', 'return \$a;' );\n}\n",
);
$guard_paths = array();
foreach ( $guard_cases as $name => $source ) {
    $path = $tmp_dir . '/guard-' . $name . '.php';
    file_put_contents( $path, $source );
    $guard_paths[ $name ] = $path;
}
$guard_scan = THRG_PC_Engine::scan( array_values( $guard_paths ), '8.4', microtime( true ) + 30.0 );
foreach ( array( 'nested', 'short_and', 'short_or', 'namespace', 'early_exit' ) as $name ) {
    $counts = THRG_PC_Results::count( $guard_scan['scanned'][ $guard_paths[ $name ] ] );
    assert_same( 0, $counts['errors'], $name . ' must not report a removed function as reachable on PHP 8.4' );
    assert_true( $counts['notes'] > 0, $name . ' must retain a guarded note' );
}
foreach ( array( 'ext_const', 'ext_loaded' ) as $name ) {
    $counts = THRG_PC_Results::count( $guard_scan['scanned'][ $guard_paths[ $name ] ] );
    assert_same( 0, $counts['errors'], $name . ': removed mcrypt symbols guarded by function_exists/extension_loaded must not be errors on PHP 8.4' );
    assert_true( $counts['notes'] > 0, $name . ' must retain a guarded note' );
}
$open_counts = THRG_PC_Results::count( $guard_scan['scanned'][ $guard_paths['ext_open'] ] );
assert_true( $open_counts['errors'] > 0, 'Unguarded removed mcrypt constant must remain an error on PHP 8.4' );
$reachable_counts = THRG_PC_Results::count( $guard_scan['scanned'][ $guard_paths['reachable_or'] ] );
assert_true( $reachable_counts['errors'] > 0, 'OR fallback reachable on PHP 8.4 must remain an error' );
$wrong_exit_counts = THRG_PC_Results::count( $guard_scan['scanned'][ $guard_paths['wrong_exit'] ] );
assert_true( $wrong_exit_counts['errors'] > 0, 'Return on old PHP must not hide a call reachable on PHP 8.4' );
foreach ( $guard_paths as $path ) {
    @unlink( $path );
}

$malformed_file = $tmp_dir . '/malformed.php';
file_put_contents( $malformed_file, "<?php\nfunction broken( {\n" );
$malformed_scan = THRG_PC_Engine::scan( array( $malformed_file ), '8.4', microtime( true ) + 30.0 );
assert_true( isset( $malformed_scan['scanned'][ $malformed_file ] ), 'Malformed PHP must produce a scan result without crashing the request' );
@unlink( $malformed_file );

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

// 1b2. PHP 4 style constructors: the bundled rule is silent on PHP 8.0+, so the plugin reports them itself.
$legacy_dir = sys_get_temp_dir() . '/thrg-pc-legacy-' . uniqid();
mkdir( $legacy_dir );
$legacy_old   = $legacy_dir . '/old.php';
$legacy_both  = $legacy_dir . '/both.php';
$legacy_ns    = $legacy_dir . '/namespaced.php';
$legacy_plain = $legacy_dir . '/plain.php';
file_put_contents( $legacy_old, "<?php\nclass AboutMeWidget extends WP_Widget {\n    function AboutMeWidget() {\n        parent::WP_Widget( false, 'x' );\n    }\n}\n" );
file_put_contents( $legacy_both, "<?php\nclass Both {\n    function __construct() {}\n    function Both() { self::__construct(); }\n}\n" );
file_put_contents( $legacy_ns, "<?php\nnamespace Acme\\Tools;\nclass Thing {\n    function Thing() {}\n}\n" );
file_put_contents( $legacy_plain, "<?php\nclass Plain {\n    function run() {\n        \$f = function () { return 1; };\n    }\n    function other() {}\n}\n" );

$legacy_files = array( $legacy_old, $legacy_both, $legacy_ns, $legacy_plain );
$legacy_74    = THRG_PC_Engine::scan( $legacy_files, '7.4', microtime( true ) + 30.0 );
$legacy_80    = THRG_PC_Engine::scan( $legacy_files, '8.0', microtime( true ) + 30.0 );
$legacy_84    = THRG_PC_Engine::scan( $legacy_files, '8.4', microtime( true ) + 30.0 );

$legacy_74_old = THRG_PC_Results::count( $legacy_74['scanned'][ $legacy_old ] );
assert_same( 1, $legacy_74_old['warnings'], 'PHP 4 style constructor warns once on PHP 7.4 (bundled rule only, no duplicate)' );
foreach ( array( '8.0' => $legacy_80, '8.4' => $legacy_84 ) as $legacy_target => $legacy_scan ) {
    $legacy_rows = $legacy_scan['scanned'][ $legacy_old ];
    $legacy_cnt  = THRG_PC_Results::count( $legacy_rows );
    assert_same( 1, $legacy_cnt['errors'], 'PHP 4 style constructor is an error on PHP ' . $legacy_target );
    assert_same( 'PHPCompatibility.FunctionNameRestrictions.RemovedPHP4StyleConstructors.Removed', $legacy_rows[0]['source'], 'Old constructor reports the removed-in-8.0 rule code on PHP ' . $legacy_target );
    assert_same( 3, $legacy_rows[0]['line'], 'Old constructor is reported on the method line on PHP ' . $legacy_target );
    assert_same( 'since PHP 7.0', THRG_PC_Results::rule_php_version( $legacy_rows[0]['message'], $legacy_rows[0]['source'], $legacy_target ), 'Old constructor message names the version it was deprecated in' );
    assert_same( true, THRG_PC_Results::is_fatal_rule( $legacy_rows[0]['source'] ), 'Old constructor is in the fatal tier' );
    assert_same( 0, THRG_PC_Results::count( $legacy_scan['scanned'][ $legacy_both ] )['errors'], 'Class that also has __construct() is not reported on PHP ' . $legacy_target );
    assert_same( 0, THRG_PC_Results::count( $legacy_scan['scanned'][ $legacy_ns ] )['errors'], 'Namespaced class is not reported on PHP ' . $legacy_target );
    assert_same( 0, THRG_PC_Results::count( $legacy_scan['scanned'][ $legacy_plain ] )['errors'], 'Ordinary class is not reported on PHP ' . $legacy_target );
}
foreach ( $legacy_files as $legacy_cleanup ) {
    @unlink( $legacy_cleanup );
}
@rmdir( $legacy_dir );

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

// 3b. Graded presentation: errors only in bundled libraries are "Review", not "Incompatible".
$bundle_err = array(
    'type'    => 'error',
    'line'    => 12,
    'column'  => 5,
    'message' => 'Function mcrypt_encrypt() is removed since PHP 7.2.',
    'source'  => 'PHPCompatibility.FunctionUse.RemovedFunctions.mcrypt_encryptRemoved',
);
THRG_PC_Results::save(
    array( 'kind' => 'plugin', 'slug' => 'bundled-only/bundled-only.php', 'name' => 'Bundled Only', 'version' => '1.0.0', 'files_total' => 3 ),
    '8.3',
    array( array_merge( $bundle_err, array( 'file' => 'vendor/lib/Crypt.php', 'origin' => 'bundled' ) ) )
);
THRG_PC_Results::save(
    array( 'kind' => 'plugin', 'slug' => 'mixed/mixed.php', 'name' => 'Mixed', 'version' => '1.0.0', 'files_total' => 3 ),
    '8.3',
    array(
        array_merge( $bundle_err, array( 'file' => 'vendor/lib/Crypt.php', 'origin' => 'bundled' ) ),
        array_merge( $bundle_err, array( 'file' => 'includes/main.php', 'origin' => 'own' ) ),
    )
);
$graded_installed = array(
    array( 'kind' => 'plugin', 'slug' => 'bundled-only/bundled-only.php', 'name' => 'Bundled Only', 'version' => '1.0.0', 'active' => true, 'network_active' => false, 'parent_theme' => false ),
    array( 'kind' => 'plugin', 'slug' => 'mixed/mixed.php', 'name' => 'Mixed', 'version' => '1.0.0', 'active' => true, 'network_active' => false, 'parent_theme' => false ),
);
$graded_rows = THRG_PHP_Checker::evaluate(
    array(
        'now'             => strtotime( '2026-05-01 UTC' ),
        'php_version'     => '8.3.10',
        'running_php'     => '8.3',
        'target'          => '8.3',
        'engine_reason'   => '',
        'components'      => $graded_installed,
        'results'         => THRG_PC_Results::for_target( '8.3', $graded_installed ),
        'prescan_enabled' => false,
        'update_items'    => array(),
    )
);
$graded = array();
foreach ( $graded_rows as $graded_row ) {
    if ( ! empty( $graded_row['comp_slug'] ) ) {
        $graded[ $graded_row['comp_slug'] ] = $graded_row;
    }
}
assert_true( ! empty( $graded['bundled-only/bundled-only.php']['bundled_only'] ), 'Errors only in bundled code must be flagged bundled_only' );
assert_same( 'Review', $graded['bundled-only/bundled-only.php']['status'], 'Bundled-only errors must use the Review status, not Potential weakness' );
assert_same( 'breaks', $graded['bundled-only/bundled-only.php']['verdict'], 'Stored verdict stays breaks so filters and update checks keep working' );
assert_true( 0 === strpos( $graded['bundled-only/bundled-only.php']['badge_text'], 'Review: bundled library' ), 'Bundled-only badge must read Review: bundled library' );
assert_true( empty( $graded['mixed/mixed.php']['bundled_only'] ), 'Errors in the plugin\'s own code must not be flagged bundled_only' );
assert_same( 'Potential weakness', $graded['mixed/mixed.php']['status'], 'Own-code errors keep the Potential weakness status' );
assert_true( 0 === strpos( $graded['mixed/mixed.php']['badge_text'], 'Likely to break' ), 'Own-code errors in a function-scope finding read Likely to break' );
assert_same( 2, $graded['mixed/mixed.php']['grade'], 'Own-code error without load-time evidence is grade 2' );
assert_same( 4, $graded['bundled-only/bundled-only.php']['grade'], 'Bundled-only errors are grade 4' );

// 3c. Default target PHP version is 8.3 whatever PHP the server runs.
assert_same( '8.3', THRG_PHP_Checker::default_target(), 'Default target PHP version must be 8.3' );
assert_true( in_array( THRG_PHP_Checker::default_target(), THRG_PHP_Checker::scan_targets(), true ), 'Default target must be a supported scan target' );

// 3d. Compatibility grade 1-7 (1.2.7).
$g_ctx  = array( 'boot_files' => array( 'main.php' ), 'marker' => false, 'strong' => false );
$g_base = array( 'type' => 'error', 'source' => 'PHPCompatibility.FunctionUse.RemovedFunctions.create_functionRemoved', 'file' => 'main.php', 'line' => 3, 'message' => 'x' );
assert_same( 1, THRG_PC_Grade::finding_grade( array_merge( $g_base, array( 'scope' => 'file' ) ), $g_ctx ), 'File-scope removed function in a boot file is grade 1' );
assert_same( 1, THRG_PC_Grade::finding_grade( array_merge( $g_base, array( 'scope' => 'class-body', 'source' => 'PHPCompatibility.Constants.RemovedConstants.mcrypt_rijndael_128Removed' ) ), $g_ctx ), 'Class-body removed constant in a boot file is grade 1' );
assert_same( 2, THRG_PC_Grade::finding_grade( array_merge( $g_base, array( 'scope' => 'file', 'file' => 'other.php' ) ), $g_ctx ), 'File-scope error in a file not known to load on boot is grade 2' );
assert_same( 2, THRG_PC_Grade::finding_grade( array_merge( $g_base, array( 'scope' => 'function' ) ), $g_ctx ), 'Function-scope error is grade 2' );
assert_same( 2, THRG_PC_Grade::finding_grade( array_merge( $g_base, array( 'scope' => 'conditional' ) ), $g_ctx ), 'Conditional top-level error is grade 2 without a fallback reason' );
assert_same( 2, THRG_PC_Grade::finding_grade( array_merge( $g_base, array( 'scope' => 'file' ) ), array() ), 'Without a boot-file list nothing reaches grade 1' );
assert_same( 2, THRG_PC_Grade::finding_grade( array_merge( $g_base, array( 'scope' => 'file', 'source' => 'PHPCompatibility.FunctionNameRestrictions.RemovedPHP4StyleConstructors.Removed' ) ), $g_ctx ), 'Rules that do not fail at load time never reach grade 1' );
assert_same( 3, THRG_PC_Grade::finding_grade( array_merge( $g_base, array( 'scope' => 'file', 'reason' => 'fallback_or' ) ), $g_ctx ), 'A fallback reason makes a fatal own-code error grade 3, even at file scope' );
assert_same( 4, THRG_PC_Grade::finding_grade( array_merge( $g_base, array( 'file' => 'vendor/lib/a.php' ) ), $g_ctx ), 'Bundled library error is grade 4' );
assert_same( 5, THRG_PC_Grade::finding_grade( array_merge( $g_base, array( 'source' => 'PHPCompatibility.IniDirectives.RemovedIniDirectives.safe_modeRemoved' ) ), $g_ctx ), 'Non-fatal rule is grade 5' );
assert_same( 6, THRG_PC_Grade::finding_grade( array_merge( $g_base, array( 'type' => 'warning' ) ), $g_ctx ), 'Warning is grade 6' );
assert_same( 0, THRG_PC_Grade::finding_grade( array_merge( $g_base, array( 'type' => 'note' ) ), $g_ctx ), 'Notes do not count' );
assert_same( 2, THRG_PC_Grade::finding_grade( array_merge( $g_base, array( 'scope' => 'function', 'name_hint' => 1 ) ), $g_ctx ), 'A legacy file name alone does not lower the grade' );
assert_same( 3, THRG_PC_Grade::finding_grade( array_merge( $g_base, array( 'scope' => 'function', 'name_hint' => 1 ) ), array_merge( $g_ctx, array( 'marker' => true ) ) ), 'A legacy file name plus loads-here evidence is grade 3' );
assert_same( 3, THRG_PC_Grade::finding_grade( array_merge( $g_base, array( 'scope' => 'function' ) ), array_merge( $g_ctx, array( 'marker' => true, 'strong' => true ) ) ), 'Strong evidence lowers grade 2 to 3' );
assert_same( 1, THRG_PC_Grade::finding_grade( array_merge( $g_base, array( 'scope' => 'file' ) ), array_merge( $g_ctx, array( 'marker' => true, 'strong' => true ) ) ), 'Evidence never lowers grade 1' );
assert_same( 2, THRG_PC_Grade::finding_grade( array_merge( $g_base, array( 'scope' => 'function' ) ), array_merge( $g_ctx, array( 'marker' => true ) ) ), 'Marker alone (no runtime log) does not lower grade 2' );
$ev_ok = THRG_PC_Grade::evidence( true, '8.3', '8.3', true, false );
assert_true( $ev_ok['marker'] && $ev_ok['strong'], 'Active component on a server already at the target PHP has evidence, strong with the runtime log on' );
$ev_off = THRG_PC_Grade::evidence( true, '8.3', '8.3', false, false );
assert_true( $ev_off['marker'] && ! $ev_off['strong'], 'Without the runtime log the evidence is a label only' );
$ev_old = THRG_PC_Grade::evidence( true, '8.1', '8.3', true, false );
assert_true( ! $ev_old['marker'], 'No evidence when the server runs older PHP than the target' );
$ev_paused = THRG_PC_Grade::evidence( true, '8.3', '8.3', true, true );
assert_true( ! $ev_paused['marker'], 'No evidence when WordPress paused the component after a fatal error' );
$ev_inactive = THRG_PC_Grade::evidence( false, '8.3', '8.3', true, false );
assert_true( ! $ev_inactive['marker'], 'No evidence for an inactive component' );

// Scope and fallback detection on real scans.
$sc_dir = sys_get_temp_dir() . '/thrg-pc-scope-' . uniqid();
mkdir( $sc_dir );
$sc_cases = array(
    'load.php'      => "<?php\n\$x = create_function( '\$a', 'return \$a;' );\n",
    'inner.php'     => "<?php\nfunction run_it() {\n    return create_function( '\$a', 'return \$a;' );\n}\n",
    'else.php'      => "<?php\nif ( function_exists( 'mysqli_connect' ) ) {\n    \$c = mysqli_connect();\n} else {\n    \$c = mysql_connect();\n}\n",
    'missing.php'   => "<?php\nif ( ! function_exists( 'mysqli_connect' ) ) {\n    \$c = mysql_connect();\n}\n",
    'or.php'        => "<?php\nfunction_exists( 'mysqli_connect' ) || mysql_connect();\n",
    'caller.php'    => "<?php\nfunction old_db() {\n    return mysql_connect();\n}\nif ( ! function_exists( 'mysqli_connect' ) ) {\n    old_db();\n}\n",
    'callerok.php'  => "<?php\nfunction old_db2() {\n    return mysql_connect();\n}\nold_db2();\n",
    'legacygate.php' => "<?php\nif ( 'mysql link' == \$type && @mysql_ping( \$h ) ) {\n    return true;\n}\n",
    'twin.php'      => "<?php\nif ( \$this->use_mysqli ) {\n    \$r = mysqli_query( \$this->dbh, 'SELECT 1' );\n} else {\n    \$r = mysql_query( 'SELECT 1', \$this->dbh );\n}\n",
    'notwin.php'    => "<?php\nif ( \$flag ) {\n    \$r = something_else();\n} else {\n    \$r = mysql_query( 'SELECT 1' );\n}\n",
    'classbody.php' => "<?php\nclass Crypto {\n    public \$mode = MCRYPT_RIJNDAEL_128;\n}\n",
    'legacy-db.php' => "<?php\nfunction old_thing() {\n    return create_function( '\$a', 'return \$a;' );\n}\n",
);
$sc_paths = array();
foreach ( $sc_cases as $sc_name => $sc_src ) {
    $sc_paths[ $sc_name ] = $sc_dir . '/' . $sc_name;
    file_put_contents( $sc_paths[ $sc_name ], $sc_src );
}
$sc_scan  = THRG_PC_Engine::scan( array_values( $sc_paths ), '8.4', microtime( true ) + 60.0 );
$sc_first = static function ( $name ) use ( $sc_scan, $sc_paths ) {
    foreach ( $sc_scan['scanned'][ $sc_paths[ $name ] ] as $row ) {
        if ( 'error' === $row['type'] ) {
            return THRG_PC_Context::place_finding( $row, $name );
        }
    }
    return array();
};
assert_same( 'file', $sc_first( 'load.php' )['scope'], 'Top-level call has file scope' );
assert_true( empty( $sc_first( 'load.php' )['reason'] ), 'Top-level call has no fallback reason' );
assert_same( 'function', $sc_first( 'inner.php' )['scope'], 'Call inside a function has function scope' );
assert_same( 'conditional', $sc_first( 'else.php' )['scope'], 'Call inside an else branch is conditional' );
assert_same( 'fallback_else', $sc_first( 'else.php' )['reason'], 'Else branch of function_exists() is a fallback path' );
assert_same( 'fallback_missing', $sc_first( 'missing.php' )['reason'], 'Branch that runs when a function is missing is a fallback path' );
assert_same( 'fallback_or', $sc_first( 'or.php' )['reason'], 'Right side of "check || call" is a fallback path' );
assert_same( 'fallback_caller', $sc_first( 'caller.php' )['reason'], 'Function called only from a fallback branch is fallback code' );
assert_true( empty( $sc_first( 'callerok.php' )['reason'] ), 'Function also called from ordinary code is not fallback code' );
assert_same( 'fallback_legacy', $sc_first( 'legacygate.php' )['reason'], 'Call gated by a legacy resource type string is a fallback path' );
assert_same( 'fallback_twin', $sc_first( 'twin.php' )['reason'], 'Legacy mysql_ call in the else branch next to a mysqli_ branch is a fallback path' );
assert_true( empty( $sc_first( 'notwin.php' )['reason'] ), 'Else branch without a modern replacement is not marked as fallback' );
assert_same( 'class-body', $sc_first( 'classbody.php' )['scope'], 'Property default in a class has class-body scope' );
assert_same( 1, $sc_first( 'legacy-db.php' )['name_hint'], 'A legacy file name sets the name hint' );
assert_true( empty( $sc_first( 'load.php' )['name_hint'] ), 'An ordinary file name sets no name hint' );
foreach ( array( 'else.php', 'missing.php', 'or.php', 'caller.php', 'legacygate.php', 'twin.php' ) as $sc_name ) {
    assert_same( 3, THRG_PC_Grade::finding_grade( $sc_first( $sc_name ), $g_ctx ), $sc_name . ' grades as rarely-run code' );
}
assert_same( 2, THRG_PC_Grade::finding_grade( $sc_first( 'callerok.php' ), $g_ctx ), 'Ordinary function call grades as likely to break' );
foreach ( $sc_paths as $sc_path ) {
    @unlink( $sc_path );
}
@rmdir( $sc_dir );

// Boot files: only literal top-level includes count.
$boot_dir = sys_get_temp_dir() . '/thrg-pc-boot-' . uniqid();
mkdir( $boot_dir . '/inc', 0777, true );
foreach ( array( 'inc/a.php', 'inc/b.php', 'inc/c.php', 'inc/d.php', 'inc/e.php', 'inc/f.php', 'functions.php' ) as $boot_f ) {
    file_put_contents( $boot_dir . '/' . $boot_f, "<?php\n" );
}
file_put_contents(
    $boot_dir . '/bootdemo.php',
    "<?php\nrequire_once __DIR__ . '/inc/a.php';\nrequire_once plugin_dir_path( __FILE__ ) . 'inc/b.php';\nif ( \$x ) {\n    require 'inc/c.php';\n}\nrequire_once \$base . '/inc/d.php';\nrequire( 'inc/e.php' );\nif ( \$y ) require 'inc/f.php';\n"
);
$boot = THRG_PC_Boot::files( array( 'kind' => 'plugin', 'slug' => 'bootdemo/bootdemo.php', 'dir' => $boot_dir, 'file' => '' ) );
sort( $boot );
assert_same( array( 'bootdemo.php', 'inc/a.php', 'inc/b.php', 'inc/e.php' ), $boot, 'Boot files are the main file plus literal top-level includes only' );
file_put_contents( $boot_dir . '/functions.php', "<?php\nrequire_once get_template_directory() . '/inc/a.php';\nrequire __DIR__ . '/inc/a.php';\n" );
$boot_theme = THRG_PC_Boot::files( array( 'kind' => 'theme', 'slug' => 'demo', 'dir' => $boot_dir, 'file' => '' ) );
sort( $boot_theme );
assert_same( array( 'functions.php', 'inc/a.php' ), $boot_theme, 'A theme starts at functions.php and skips includes it cannot resolve' );
assert_same( array(), THRG_PC_Boot::files( array( 'kind' => 'plugin', 'slug' => 'nothere/nothere.php', 'dir' => $boot_dir . '/missing', 'file' => '' ) ), 'A missing main file yields no boot files' );
foreach ( array( 'inc/a.php', 'inc/b.php', 'inc/c.php', 'inc/d.php', 'inc/e.php', 'inc/f.php', 'inc', 'functions.php', 'bootdemo.php' ) as $boot_f ) {
    $boot_p = $boot_dir . '/' . $boot_f;
    is_dir( $boot_p ) ? @rmdir( $boot_p ) : @unlink( $boot_p );
}
@rmdir( $boot_dir );

// Evaluator: grade 1 from boot files, evidence lowers 2 to 3 and adds the label.
THRG_PC_Results::save( array( 'kind' => 'plugin', 'slug' => 'loadfail/loadfail.php', 'name' => 'Load Fail', 'version' => '1.0.0', 'files_total' => 1, 'boot_files' => array( 'main.php' ) ), '8.3', array( array_merge( $g_base, array( 'scope' => 'file', 'origin' => 'own' ) ) ) );
THRG_PC_Results::save( array( 'kind' => 'plugin', 'slug' => 'inner/inner.php', 'name' => 'Inner', 'version' => '1.0.0', 'files_total' => 1 ), '8.3', array( array_merge( $g_base, array( 'scope' => 'function', 'origin' => 'own' ) ) ) );
$gr_installed = array(
    array( 'kind' => 'plugin', 'slug' => 'loadfail/loadfail.php', 'name' => 'Load Fail', 'version' => '1.0.0', 'active' => true, 'network_active' => false, 'parent_theme' => false ),
    array( 'kind' => 'plugin', 'slug' => 'inner/inner.php', 'name' => 'Inner', 'version' => '1.0.0', 'active' => true, 'network_active' => false, 'parent_theme' => false ),
);
$gr_input = array(
    'now'             => strtotime( '2026-05-01 UTC' ),
    'php_version'     => '8.3.10',
    'running_php'     => '8.3',
    'target'          => '8.3',
    'engine_reason'   => '',
    'components'      => $gr_installed,
    'results'         => THRG_PC_Results::for_target( '8.3', $gr_installed ),
    'prescan_enabled' => false,
    'update_items'    => array(),
);
$gr_map = static function ( array $rows ) {
    $out = array();
    foreach ( $rows as $row ) {
        if ( ! empty( $row['comp_slug'] ) ) {
            $out[ $row['comp_slug'] ] = $row;
        }
    }
    return $out;
};
$gr_rows = $gr_map( THRG_PHP_Checker::evaluate( $gr_input ) );
assert_same( 1, $gr_rows['loadfail/loadfail.php']['grade'], 'Boot-file error is grade 1 in the report row' );
assert_true( 0 === strpos( $gr_rows['loadfail/loadfail.php']['badge_text'], 'Breaks on PHP 8.3' ), 'Grade 1 badge reads Breaks on PHP 8.3' );
assert_same( 'Potential weakness', $gr_rows['loadfail/loadfail.php']['status'], 'Grade 1 is red' );
assert_same( 2, $gr_rows['inner/inner.php']['grade'], 'Function-scope error is grade 2 in the report row' );
assert_same( '', $gr_rows['inner/inner.php']['evidence_label'], 'No evidence label without evidence input' );
$gr_input['evidence'] = array(
    'plugin:inner/inner.php'       => array( 'marker' => true, 'strong' => true ),
    'plugin:loadfail/loadfail.php' => array( 'marker' => true, 'strong' => true ),
);
$gr_rows_ev = $gr_map( THRG_PHP_Checker::evaluate( $gr_input ) );
assert_same( 3, $gr_rows_ev['inner/inner.php']['grade'], 'Strong evidence lowers the grade-2 card to grade 3' );
assert_same( 'Review', $gr_rows_ev['inner/inner.php']['status'], 'Grade 3 uses the amber Review status' );
assert_same( 'Loads on PHP 8.3 on this site', $gr_rows_ev['inner/inner.php']['evidence_label'], 'Evidence label names the PHP version' );
assert_same( 1, $gr_rows_ev['loadfail/loadfail.php']['grade'], 'Evidence never lowers a grade-1 card' );
$gr_order = array_values(
    array_filter(
        THRG_PHP_Checker::evaluate( $gr_input ),
        static function ( $row ) {
            return ! empty( $row['comp_slug'] );
        }
    )
);
assert_same( 'loadfail/loadfail.php', $gr_order[0]['comp_slug'], 'Worst grade is listed first' );
$gr_plain = THRG_PHP_Checker::plain_text( THRG_PHP_Checker::evaluate( $gr_input ), 'example.com', '2026-05-01 00:00:00 UTC', '1.2.7', '8.3' );
assert_true( false !== strpos( $gr_plain, 'Grade: Breaks on load' ), 'Plain-text report includes the grade' );

// Update pre-scan: a newer version with only review items counts as the fatal errors being fixed.
THRG_PC_Results::save( array( 'kind' => 'plugin', 'slug' => 'upd/upd.php', 'name' => 'Upd', 'version' => '1.0.0', 'files_total' => 1 ), '8.3', array( array_merge( $g_base, array( 'scope' => 'function', 'origin' => 'own' ) ) ) );
THRG_PC_Results::save_update( 'plugin', 'upd', '1.1.0', '8.3', array( array_merge( $g_base, array( 'file' => 'vendor/lib/a.php', 'origin' => 'bundled' ) ) ) );
$upd_comp = array( array( 'kind' => 'plugin', 'slug' => 'upd/upd.php', 'name' => 'Upd', 'version' => '1.0.0', 'active' => true, 'network_active' => false, 'parent_theme' => false, 'update_version' => '1.1.0' ) );
$upd_rows = THRG_PHP_Checker::evaluate( array_merge( $gr_input, array( 'components' => $upd_comp, 'results' => THRG_PC_Results::for_target( '8.3', $upd_comp ), 'evidence' => array() ) ) );
assert_same( 'warnings_only', $upd_rows[0]['wporg_fix_status'], 'Newer version with only review items is reported as fatal errors fixed' );
assert_same( 4, THRG_PC_Results::get_update( 'plugin', 'upd', '1.1.0', '8.3' )['grade'], 'Update pre-scan results store their grade' );

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

// 9. Update ZIP indexing accepts ordinary packages and rejects oversized archives.
if ( class_exists( 'ZipArchive' ) ) {
    $zip_path = sys_get_temp_dir() . '/thrg-pc-test-' . uniqid() . '.zip';
    $zip      = new ZipArchive();
    assert_same( true, $zip->open( $zip_path, ZipArchive::CREATE ), 'Fixture ZIP should open for writing' );
    $zip->addFromString( 'sample/main.php', '<?php echo "ready";' );
    $zip->close();
    $opened = THRG_PC_Prescan::zip_open( $zip_path );
    assert_true( is_array( $opened ) && isset( $opened['entries']['sample/main.php'] ), 'Ordinary update ZIP should be indexed' );
    assert_same( '<?php echo "ready";', THRG_PC_Prescan::zip_read( $zip_path, 'sample/main.php', 100 ), 'ZIP entry should be read in memory' );
    THRG_PC_Prescan::zip_close();
    @unlink( $zip_path );

    $oversized_zip = sys_get_temp_dir() . '/thrg-pc-test-large-' . uniqid() . '.zip';
    $handle = fopen( $oversized_zip, 'wb' );
    ftruncate( $handle, THRG_PC_Prescan::MAX_ARCHIVE_BYTES + 1 );
    fclose( $handle );
    assert_true( is_wp_error( THRG_PC_Prescan::zip_open( $oversized_zip ) ), 'Oversized archive must be rejected before ZIP parsing' );
    @unlink( $oversized_zip );

    $many_entries_zip = sys_get_temp_dir() . '/thrg-pc-test-many-' . uniqid() . '.zip';
    $zip = new ZipArchive();
    assert_same( true, $zip->open( $many_entries_zip, ZipArchive::CREATE ), 'Many-entry fixture ZIP should open for writing' );
    for ( $i = 0; $i <= THRG_PC_Prescan::MAX_ARCHIVE_ENTRIES; ++$i ) {
        $zip->addEmptyDir( 'entry-' . $i );
    }
    $zip->close();
    assert_true( is_wp_error( THRG_PC_Prescan::zip_open( $many_entries_zip ) ), 'Archive with too many entries must be rejected before indexing' );
    @unlink( $many_entries_zip );

    $compressed_zip = sys_get_temp_dir() . '/thrg-pc-test-compressed-' . uniqid() . '.zip';
    $zip = new ZipArchive();
    assert_same( true, $zip->open( $compressed_zip, ZipArchive::CREATE ), 'Compressed fixture ZIP should open for writing' );
    $zip->addFromString( 'sample/large.php', str_repeat( 'A', 3 * 1024 * 1024 ) );
    $zip->close();
    $opened = THRG_PC_Prescan::zip_open( $compressed_zip );
    assert_true( is_array( $opened ), 'Compressed but bounded package should index' );
    assert_same( null, THRG_PC_Prescan::zip_read( $compressed_zip, 'sample/large.php', 2 * 1024 * 1024 ), 'Per-file limit rejects a highly compressed large entry' );
    THRG_PC_Prescan::zip_close();
    @unlink( $compressed_zip );

    $broken_zip = sys_get_temp_dir() . '/thrg-pc-test-broken-' . uniqid() . '.zip';
    file_put_contents( $broken_zip, "PK\x03\x04truncated" );
    assert_true( is_wp_error( THRG_PC_Prescan::zip_open( $broken_zip ) ), 'Malformed ZIP is rejected without a fatal error' );
    @unlink( $broken_zip );
}

// 10. A scan belongs to its initiating administrator and job token.
$seed_job = array(
    'job_id'     => str_repeat( 'a', 32 ),
    'updated_at' => time(),
    'user_id'    => 11,
    'target'     => '8.4',
    'queue'      => array( array( 'name' => 'Waiting' ) ),
    'current'    => null,
    'total'      => 1,
    'finished'   => 0,
);
THRG_PC_Scanner::set_state( $seed_job );
assert_true( THRG_PC_Scanner::is_running(), 'Seeded scan must be active' );
assert_true( THRG_PC_Scanner::owns_job( $seed_job['job_id'], 11 ), 'Owner with matching token can control scan' );
assert_true( ! THRG_PC_Scanner::owns_job( $seed_job['job_id'], 12 ), 'Another administrator cannot control scan' );
assert_true( ! THRG_PC_Scanner::owns_job( str_repeat( 'b', 32 ), 11 ), 'Old or wrong token cannot control scan' );
$step_rejected = false;
try {
    THRG_PC_Scanner::step( microtime( true ) + 1, str_repeat( 'b', 32 ) );
} catch ( InvalidArgumentException $e ) {
    $step_rejected = true;
}
assert_true( $step_rejected, 'Wrong token cannot advance a scan' );
assert_same( $seed_job, THRG_PC_Scanner::start( '8.5', true, 'all', '', 11 ), 'Same administrator resumes existing job instead of replacing it' );
$busy_rejected = false;
try {
    THRG_PC_Scanner::start( '8.5', true, 'all', '', 12 );
} catch ( InvalidArgumentException $e ) {
    $busy_rejected = true;
}
assert_true( $busy_rejected, 'Second administrator cannot replace an active scan' );
assert_true( ! THRG_PC_Scanner::stop( $seed_job['job_id'], 12 ), 'Second administrator cannot stop active scan' );
assert_true( THRG_PC_Scanner::is_running(), 'Rejected stop leaves scan intact' );
assert_true( THRG_PC_Scanner::stop( $seed_job['job_id'], 11 ), 'Owner can stop active scan' );
assert_true( ! THRG_PC_Scanner::is_running(), 'Successful stop clears scan' );

$seed_job['user_id'] = 0;
THRG_PC_Scanner::set_state( $seed_job );
assert_true( THRG_PC_Scanner::owns_job( $seed_job['job_id'], 12 ), 'Administrator can resume a partial cron scan' );
assert_same( $seed_job, THRG_PC_Scanner::start( '8.5', true, 'all', '', 12 ), 'Administrator resumes rather than replaces cron scan' );
THRG_PC_Scanner::clear_state();

$seed_job['updated_at'] = time() - THRG_PC_Scanner::STATE_TTL - 1;
THRG_PC_Scanner::set_state( $seed_job );
assert_same( array(), THRG_PC_Scanner::get_state(), 'Abandoned scan expires so another administrator can start later' );

$claim = new ReflectionMethod( THRG_PC_Scanner::class, 'claim_state' );
$claim->setAccessible( true ); // Needed before PHP 8.1.
$first_job = $seed_job;
$first_job['updated_at'] = time();
$first_job['job_id'] = str_repeat( 'c', 32 );
$second_job = $first_job;
$second_job['job_id'] = str_repeat( 'd', 32 );
assert_true( $claim->invoke( null, $first_job ), 'First claimant inserts the empty scan slot' );
assert_true( ! $claim->invoke( null, $second_job ), 'Second claimant cannot replace the first job' );
assert_same( $first_job, THRG_PC_Scanner::get_state(), 'Losing claim leaves first job intact' );
assert_true( ! THRG_PC_Scanner::clear_state( $second_job ), 'Stale cleanup cannot delete a different job' );
assert_same( $first_job, THRG_PC_Scanner::get_state(), 'Conditional delete preserves the current job' );
assert_true( THRG_PC_Scanner::clear_state( $first_job ), 'Matching conditional cleanup removes the job' );

$lock_method = new ReflectionMethod( THRG_PC_Scanner::class, 'claim_step_lock' );
$lock_method->setAccessible( true ); // Needed before PHP 8.1.
$lock = $lock_method->invoke( null, $first_job['job_id'] );
$parallel_step_rejected = false;
try {
    $lock_method->invoke( null, $first_job['job_id'] );
} catch ( InvalidArgumentException $e ) {
    $parallel_step_rejected = true;
}
assert_true( $parallel_step_rejected, 'Overlapping step cannot claim a held lock' );
delete_option( THRG_PC_Scanner::STEP_LOCK_OPTION );

$replace = new ReflectionMethod( THRG_PC_Scanner::class, 'replace_state' );
$replace->setAccessible( true ); // Needed before PHP 8.1.
THRG_PC_Scanner::set_state( $first_job );
assert_true( ! $replace->invoke( null, $second_job, $first_job ), 'Progress update rejects a different current state' );
assert_same( $first_job, THRG_PC_Scanner::get_state(), 'Rejected progress update leaves current job intact' );
$advanced_job = $first_job;
$advanced_job['finished'] = 1;
assert_true( $replace->invoke( null, $first_job, $advanced_job ), 'Matching progress update succeeds' );
assert_same( $advanced_job, THRG_PC_Scanner::get_state(), 'Progress update stores the new state' );
THRG_PC_Scanner::clear_state();

// 11. Live runtime capture preserves an existing PHP error handler.
THRG_PC_Runtime_Log::clear();
$prior_calls  = array();
$prior_return = true;
$prior = static function ( $errno, $message, $file, $line ) use ( &$prior_calls, &$prior_return ) {
    $prior_calls[] = array( $errno, $message, $file, $line );
    return $prior_return;
};
$old_reporting = error_reporting( E_ALL );
set_error_handler( $prior, E_DEPRECATED | E_USER_DEPRECATED );
THRG_PC_Runtime_Log::register_handler();
THRG_PC_Runtime_Log::register_handler();
$installed = set_error_handler( static function () { return true; }, E_USER_DEPRECATED );
assert_same( array( 'THRG_PC_Runtime_Log', 'handle_error' ), $installed, 'Repeated registration keeps one runtime capture handler on top' );
restore_error_handler();

$notice_dir = WP_PLUGIN_DIR . '/runtime-fixture';
if ( ! is_dir( $notice_dir ) ) {
    mkdir( $notice_dir, 0755, true );
}
$notice_file = $notice_dir . '/notice.php';
file_put_contents( $notice_file, "<?php trigger_error( 'Fixture deprecation', E_USER_DEPRECATED );\n" );
include $notice_file;
assert_same( 1, count( $prior_calls ), 'Existing handler receives the deprecation exactly once' );
assert_same( realpath( $notice_file ), $prior_calls[0][2], 'Existing handler receives the canonical file path' );
THRG_PC_Runtime_Log::flush_buffer();
$captured = array_values( THRG_PC_Runtime_Log::all() );
assert_same( 1, count( $captured ), 'Runtime capture persists the plugin deprecation' );
assert_same( 'runtime-fixture', $captured[0]['slug'], 'Runtime notice is attributed to the emitting plugin' );

$prior_return = false;
assert_same( false, THRG_PC_Runtime_Log::handle_error( E_USER_DEPRECATED, 'Second deprecation', $notice_file, 1 ), 'A false return from the previous handler remains false' );
assert_same( 2, count( $prior_calls ), 'Direct callback still chains to previous handler' );
restore_error_handler();
restore_error_handler();
error_reporting( $old_reporting );
THRG_PC_Runtime_Log::clear();
@unlink( $notice_file );
@rmdir( $notice_dir );

// Release selection must inspect all assets, and uninstall must remove tracked cooldowns.
require_once dirname( __DIR__ ) . '/includes/class-thrg-pc-updater.php';
$release = (object) array(
    'tag_name' => 'v1.2.0',
    'assets'   => array(
        (object) array( 'name' => 'thrg-php-checker.1.1.9.zip' ),
        (object) array( 'name' => 'thrg-php-checker.1.2.0.zip' ),
    ),
);
assert_true( THRG_PC_Updater::release_has_asset( '1.2.0', $release ), 'Matching release asset can follow another version' );
assert_true( ! THRG_PC_Updater::release_has_asset( '1.2.1', $release ), 'Mismatched release tag is rejected' );
$release->assets = array( (object) array( 'name' => 'thrg-php-checker.1.1.9.zip' ) );
assert_true( ! THRG_PC_Updater::release_has_asset( '1.2.0', $release ), 'Release without exact asset is rejected' );

THRG_PC_Email::mark_cooldown( 11 );
assert_same( 1, get_transient( 'thrg_pc_mail_11' ), 'Email cooldown is created' );
assert_same( array( 11 => 'thrg_pc_mail_11' ), get_option( 'thrg_pc_mail_cooldowns' ), 'Cooldown key is tracked' );

$wporg_cache = new ReflectionMethod( 'THRG_PC_Prescan', 'wporg_cache' );
$wporg_cache->setAccessible( true ); // Needed before PHP 8.1.
$wporg_cache->setAccessible( true );
$wporg_cache->invoke( null, 'thrg_pc_wporg_abcdef012345', array( 'status' => 'ok' ) );
$wporg_cache->invoke( null, 'thrg_pc_wporg_abcdef012345', array( 'status' => 'ok' ) );
assert_same( array( 'status' => 'ok' ), get_transient( 'thrg_pc_wporg_abcdef012345' ), 'WordPress.org lookup is cached' );
assert_same( array( 'thrg_pc_wporg_abcdef012345' ), get_option( 'thrg_pc_wporg_keys' ), 'WordPress.org cache key is tracked once' );

$wporg_scan = new ReflectionMethod( 'THRG_PC_Prescan', 'scan_wporg_package' );
$wporg_scan->setAccessible( true ); // Needed before PHP 8.1.
$wporg_scan->setAccessible( true );
assert_same( null, $wporg_scan->invoke( null, 'demo', '1.0.0', 'https://example.com/demo.zip', '8.3', microtime( true ) + 5 ), 'WordPress.org fix check refuses package hosts other than downloads.wordpress.org' );
assert_same( null, $wporg_scan->invoke( null, 'demo', '1.0.0', 'http://downloads.wordpress.org/plugin/demo.zip', '8.3', microtime( true ) + 5 ), 'WordPress.org fix check refuses plain http package URLs' );
function is_multisite() { return false; }
function wp_clear_scheduled_hook( $hook ) { unset( $hook ); }
function delete_site_option( $name ) { unset( $name ); }
function delete_site_transient( $name ) { unset( $name ); }
define( 'WP_UNINSTALL_PLUGIN', 'index.php' );
require dirname( __DIR__ ) . '/uninstall.php';
assert_same( false, get_transient( 'thrg_pc_mail_11' ), 'Uninstall removes tracked cooldown' );
assert_same( false, get_option( 'thrg_pc_mail_cooldowns' ), 'Uninstall removes cooldown registry' );
assert_same( false, get_transient( 'thrg_pc_wporg_abcdef012345' ), 'Uninstall removes tracked WordPress.org lookup cache' );
assert_same( false, get_option( 'thrg_pc_wporg_keys' ), 'Uninstall removes WordPress.org cache registry' );

// 13. Scanner signal-to-noise & triage improvements (1.2.4).
$mixed_eol_src = "<?php\r\n\$x = 1;\n\$y = 2;\n";
list( $ruleset, $config ) = THRG_PC_Engine::ruleset( '8.3' );
$mixed_eol_res = THRG_PC_Engine::scan_content( $mixed_eol_src, 'mixed-eol.php', $ruleset, $config, '8.3' );
assert_same( array(), $mixed_eol_res, 'Mixed line endings (Internal.LineEndings.Mixed) are excluded' );

$legacy_else_src = "<?php\nfunction demo_socket() {\n    if (function_exists('error_clear_last')) {\n        error_clear_last();\n    } else {\n        \$old_track_errors = @ini_set('track_errors', 1);\n    }\n    if (isset(\$old_track_errors)) {\n        @ini_set('track_errors', \$old_track_errors);\n    }\n}\n";
$legacy_else_res = THRG_PC_Engine::scan_content( $legacy_else_src, 'Socket.php', $ruleset, $config, '8.3' );
$legacy_counts   = THRG_PC_Results::count( $legacy_else_res );
assert_same( 0, $legacy_counts['errors'], 'Dead else branch of function_exists(error_clear_last) and guarded-only variable isset() produce 0 errors' );
assert_true( $legacy_counts['notes'] >= 2, 'Guarded legacy else branch and isset() fallback are downgraded to notes' );

assert_same( 'bundled', THRG_PC_Results::file_origin( 'back/Libraries/Imap2/Connection.php' ), 'Libraries/Imap2 classifies as bundled dependency' );
assert_same( 'bundled', THRG_PC_Results::file_origin( 'back/Libraries/Mailer/Mailer.php' ), 'Libraries/Mailer classifies as bundled dependency' );
assert_same( false, THRG_PC_Results::is_fatal_rule( 'PHPCompatibility.IniDirectives.RemovedIniDirectives.track_errorsDeprecatedRemoved' ), 'RemovedIniDirectives is non-fatal tier' );
assert_same( false, THRG_PC_Results::is_fatal_rule( 'PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue.Changed' ), 'ArgumentFunctionsReportCurrentValue is non-fatal tier' );
assert_same( true, THRG_PC_Results::is_fatal_rule( 'PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved' ), 'RemovedFunctions is fatal tier' );
assert_same( false, THRG_PC_Results::is_fatal_rule( 'PHPCompatibility.Variables.NewUniformVariableSyntax.Found' ), 'Uniform variable syntax is a behaviour change, not fatal' );
assert_same( false, THRG_PC_Results::is_fatal_rule( 'PHPCompatibility.Operators.ChangedConcatOperatorPrecedence.Found' ), 'Concat precedence is a behaviour change, not fatal' );
assert_same( false, THRG_PC_Results::is_fatal_rule( 'PHPCompatibility.Lists.AssignmentOrder.Affected' ), 'List assignment order is a behaviour change, not fatal' );
$behaviour_grade = THRG_PC_Grade::component(
	array(
		array(
			'type'   => 'error',
			'file'   => 'src/DisplayType/ControllerFactory.php',
			'source' => 'PHPCompatibility.Variables.NewUniformVariableSyntax.Found',
			'origin' => 'own',
		),
	),
	array( 'boot_files' => array() )
);
assert_same( 5, $behaviour_grade['grade'], 'A lone behaviour-change error is grade 5 (minor changes), not a likely break' );

// Quick-switch summary follows the grade scale: own fatal = likely to break, bundled-only or behaviour-change = review, clean = ok.
THRG_PC_Results::clear_all();
$sum_components = array(
	array( 'kind' => 'plugin', 'slug' => 'own/own.php', 'name' => 'Own Fatal', 'version' => '1.0', 'files_total' => 1 ),
	array( 'kind' => 'plugin', 'slug' => 'lib/lib.php', 'name' => 'Bundled Only', 'version' => '1.0', 'files_total' => 1 ),
	array( 'kind' => 'plugin', 'slug' => 'beh/beh.php', 'name' => 'Behaviour Change', 'version' => '1.0', 'files_total' => 1 ),
	array( 'kind' => 'plugin', 'slug' => 'ok/ok.php', 'name' => 'Clean', 'version' => '1.0', 'files_total' => 1 ),
);
THRG_PC_Results::save( $sum_components[0], '8.3', array( array( 'type' => 'error', 'file' => 'inc/a.php', 'line' => 3, 'source' => 'PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved', 'message' => 'x', 'origin' => 'own' ) ) );
THRG_PC_Results::save( $sum_components[1], '8.3', array( array( 'type' => 'error', 'file' => 'vendor/lib/a.php', 'line' => 3, 'source' => 'PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved', 'message' => 'x', 'origin' => 'bundled' ) ) );
THRG_PC_Results::save( $sum_components[2], '8.3', array( array( 'type' => 'error', 'file' => 'inc/b.php', 'line' => 3, 'source' => 'PHPCompatibility.Variables.NewUniformVariableSyntax.Found', 'message' => 'x', 'origin' => 'own' ) ) );
THRG_PC_Results::save( $sum_components[3], '8.3', array() );
$sum_out = THRG_PC_Results::target_summaries( array( '8.3' ), $sum_components );
assert_same( 1, $sum_out['8.3']['breaks'], 'Quick-switch counts only own-code fatal errors as likely to break' );
assert_same( 2, $sum_out['8.3']['warnings'], 'Quick-switch counts bundled-only and behaviour-change components as review' );
assert_same( 1, $sum_out['8.3']['ok'], 'Quick-switch counts clean components as ok' );
THRG_PC_Results::clear_all();
assert_same( 'since PHP 7.0', THRG_PC_Results::rule_php_version( 'Since PHP 7.0, functions inspecting arguments...', 'PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue.NeedsInspection', '8.3' ), 'Extracts originating PHP version from message' );
assert_same( 'since PHP 8.2', THRG_PC_Results::rule_php_version( 'Creation of dynamic property...', 'PHPCompatibility.Classes.ForbiddenDynamicProperties.Found', '8.3' ), 'Extracts PHP 8.2 for dynamic properties' );

// Regression: capability helpers must not recurse into themselves.
$main_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-thrg-php-checker.php' );
foreach ( array( 'require_admin_ajax', 'require_admin_post' ) as $helper ) {
    preg_match( '/function ' . $helper . '\(\) \{(.*?)\n    \}/s', $main_src, $helper_body );
    assert_true( ! empty( $helper_body[1] ) && false === strpos( $helper_body[1], 'self::' . $helper ), $helper . '() must not call itself' );
    assert_true( ! empty( $helper_body[1] ) && false !== strpos( $helper_body[1], "current_user_can( 'manage_options' )" ), $helper . '() must check manage_options' );
}

// Every request handler verifies a nonce and an administrator capability.
$handler_sources = array(
    'includes/class-thrg-php-checker.php'    => array( 'ajax_scan_start', 'ajax_scan_step', 'ajax_scan_stop', 'ajax_prescan_now', 'ajax_check_wporg_fix', 'handle_start_post', 'handle_save_settings', 'handle_delete_report', 'handle_clear_history', 'handle_toggle_history', 'handle_email_report' ),
    'includes/class-thrg-pc-runtime-log.php' => array( 'handle_post' ),
    'includes/class-thrg-pc-schedule.php'    => array( 'handle_save' ),
);
foreach ( $handler_sources as $handler_file => $handler_names ) {
    $handler_src = file_get_contents( dirname( __DIR__ ) . '/' . $handler_file );
    foreach ( $handler_names as $handler_name ) {
        preg_match( '/function ' . $handler_name . '\(\) \{(.{0,900})/s', $handler_src, $handler_head );
        $handler_head = isset( $handler_head[1] ) ? $handler_head[1] : '';
        assert_true( (bool) preg_match( '/check_ajax_referer|check_admin_referer|wp_verify_nonce/', $handler_head ), $handler_name . '() verifies a nonce' );
        assert_true( (bool) preg_match( '/current_user_can|require_admin_ajax|require_admin_post/', $handler_head ), $handler_name . '() checks capability' );
    }
}

echo "OK ({$assertions} assertions passed)\n";

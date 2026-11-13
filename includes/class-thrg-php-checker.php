<?php
/**
 * Main controller and pure evaluation engine for The Hack Repair Guy's PHP Checker.
 *
 * Combines live server PHP lifecycle/patch/advisory checks with in-process
 * static PHPCompatibilityWP scanning across PHP 8.1 through 8.5.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-thrg-pc-server-advisory.php';
require_once __DIR__ . '/class-thrg-pc-evaluator.php';
require_once __DIR__ . '/class-thrg-pc-text-report.php';

/**
 * Core controller, lifecycle evaluator, and admin request router.
 */
final class THRG_PHP_Checker {

    /**
     * Days remaining until an EOL date (negative if past).
     *
     * @param string $eol_date YYYY-MM-DD.
     * @param int    $now      Optional reference timestamp.
     * @return int|null
     */
    public static function eol_days_left( $eol_date, $now = 0 ) {
        return THRG_PC_Server_Advisory::eol_days_left( $eol_date, $now );
    }

    /**
     * Classify an EOL date into ok | warn | bad.
     *
     * @param string $eol_date YYYY-MM-DD.
     * @param int    $now      Optional reference timestamp.
     * @return array
     */
    public static function eol_status( $eol_date, $now = 0 ) {
        return THRG_PC_Server_Advisory::eol_status( $eol_date, $now );
    }

    /**
     * Evaluate a PHP version string against official php.net security EOL dates.
     *
     * @param string $php_version PHP version string.
     * @param int    $now         Optional reference timestamp.
     * @return array
     */
    public static function php_status( $php_version, $now = 0 ) {
        return THRG_PC_Server_Advisory::php_status( $php_version, $now );
    }

    /**
     * Compare a running PHP version against the latest known patch release.
     *
     * @param string $php_version PHP version string.
     * @param int    $now         Optional reference timestamp.
     * @return array
     */
    public static function php_patch( $php_version, $now = 0 ) {
        return THRG_PC_Server_Advisory::php_patch( $php_version, $now );
    }

    /**
     * Allowlisted link key for the php.net migration guide matching a target PHP version.
     *
     * @param string $target Target PHP version ("8.4" style).
     * @return string
     */
    public static function migration_link_key( $target ) {
        return THRG_PC_Server_Advisory::migration_link_key( $target );
    }

    /**
     * Collect live site inputs for evaluation.
     *
     * @param string $target Optional target PHP version.
     * @return array
     */
    public static function collect( $target = '' ) {
        return THRG_PC_Evaluator::collect( $target );
    }

    /**
     * Evaluate collected inputs into prioritized finding rows.
     *
     * @param array $o Collected inputs.
     * @return array[]
     */
    public static function evaluate( array $o ) {
        return THRG_PC_Evaluator::evaluate( $o );
    }

    /**
     * Resolve an allowlisted link key to [label, url].
     *
     * @param string $link Link token key.
     * @return array|null
     */
    public static function href( $link ) {
        return THRG_PC_Text_Report::href( $link );
    }

    /**
     * Convert [[token]] placeholders in HTML-escaped text into safe links.
     *
     * @param string $text Raw text.
     * @return string Safe HTML.
     */
    public static function linkify( $text ) {
        return THRG_PC_Text_Report::linkify( $text );
    }

    /**
     * Convert [[token]] placeholders into plain-text labels and URLs.
     *
     * @param string $text Raw text.
     * @return string
     */
    public static function strip_links( $text ) {
        return THRG_PC_Text_Report::strip_links( $text );
    }

    /**
     * Build a plain-text report suitable for clipboard export or email.
     *
     * @param array  $rows      Evaluated rows.
     * @param string $site_host Site hostname.
     * @param string $timestamp Report timestamp.
     * @param string $version   Plugin version.
     * @param string $target    Target PHP version.
     * @return string
     */
    public static function plain_text( array $rows, $site_host = '', $timestamp = '', $version = '', $target = '' ) {
        return THRG_PC_Text_Report::plain_text( $rows, $site_host, $timestamp, $version, $target );
    }

    const VERSION               = '1.2.1';
    const SLUG                  = 'thrg-php-checker';
    const PAGE_URL              = 'tools.php?page=thrg-php-checker';
    const NEW_TAB_HINT          = '<span class="screen-reader-text"> (opens in a new tab)</span>';
    const SETTINGS_OPTION       = 'thrg_pc_settings';
    const PATCH_LIST_AS_OF      = '2026-11-11';
    const PATCH_LIST_STALE_DAYS = 90;

    /**
     * Official php.net security-support end dates and latest known patch releases.
     *
     * @var array<string,array{eol:string,patched:string}>
     */
    const PHP_SUPPORT = array(
        '7.4' => array(
            'eol'     => '2022-11-28',
            'patched' => '7.4.33',
        ),
        '8.0' => array(
            'eol'     => '2023-11-26',
            'patched' => '8.0.30',
        ),
        '8.1' => array(
            'eol'     => '2025-12-31',
            'patched' => '8.1.34',
        ),
        '8.2' => array(
            'eol'     => '2026-12-31',
            'patched' => '8.2.30',
        ),
        '8.3' => array(
            'eol'     => '2027-12-31',
            'patched' => '8.3.30',
        ),
        '8.4' => array(
            'eol'     => '2028-12-31',
            'patched' => '8.4.20',
        ),
        '8.5' => array(
            'eol'     => '2029-12-31',
            'patched' => '8.5.5',
        ),
    );

    /**
     * Known managed-host PHP version removal windows.
     *
     * @var array<string,string>
     */
    const HOST_REMOVALS = array(
        '8.2' => 'Some managed WordPress hosts remove PHP versions ahead of php.net end-of-life; for example, WP Cloud (WordPress.com, Pressable, Bluehost Cloud) has announced PHP 8.2 removal in May 2026.',
    );

    /**
     * Notable upstream PHP security advisories tracked by branch.
     *
     * @var array<string,array<int,array{fixed:string,cve:string,name:string,summary:string,link:string}>>
     */
    const PHP_ADVISORIES = array(
        '8.3' => array(
            array(
                'fixed'   => '8.3.31',
                'cve'     => 'CVE-2026-109618 / CVE-2026-109619',
                'name'    => 'PHP2Shell (filter_var bypass & mail injection)',
                'summary' => 'Fixed in PHP 8.3.31 (April 2026). Versions through 8.3.30 allow trailing-newline bypasses in filter_var() and command argument injection when the 5th additional_params parameter of mail() receives user-controlled input.',
                'link'    => 'php2shell-advisory',
            ),
        ),
        '8.4' => array(
            array(
                'fixed'   => '8.4.20',
                'cve'     => 'CVE-2026-109618 / CVE-2026-109619',
                'name'    => 'PHP2Shell (filter_var bypass & mail injection)',
                'summary' => 'Fixed in PHP 8.4.20 (April 2026). Earlier 8.4 releases allow trailing-newline bypasses in filter_var() and command argument injection when the 5th additional_params parameter of mail() receives user-controlled input.',
                'link'    => 'php2shell-advisory',
            ),
        ),
        '8.5' => array(
            array(
                'fixed'   => '8.5.5',
                'cve'     => 'CVE-2026-109618 / CVE-2026-109619',
                'name'    => 'PHP2Shell (filter_var bypass & mail injection)',
                'summary' => 'Fixed in PHP 8.5.5 (April 2026). Earlier 8.5 releases allow trailing-newline bypasses in filter_var() and command argument injection when the 5th additional_params parameter of mail() receives user-controlled input.',
                'link'    => 'php2shell-advisory',
            ),
        ),
    );

    /**
     * Allowlisted external documentation links.
     *
     * @var array<string,array{label:string,url:string}>
     */
    const LINKS = array(
        'php-supported-versions'      => array(
            'label' => 'PHP.net: Supported Versions',
            'url'   => 'https://www.php.net/supported-versions.php',
        ),
        'php-migration-81'            => array(
            'label' => 'PHP.net: Migrating to PHP 8.1',
            'url'   => 'https://www.php.net/manual/en/migration81.php',
        ),
        'php-migration-82'            => array(
            'label' => 'PHP.net: Migrating to PHP 8.2',
            'url'   => 'https://www.php.net/manual/en/migration82.php',
        ),
        'php-migration-82-deprecated' => array(
            'label' => 'PHP.net: PHP 8.2 Deprecated Features (Dynamic Properties)',
            'url'   => 'https://www.php.net/manual/en/migration82.deprecated.php',
        ),
        'php-migration-83'            => array(
            'label' => 'PHP.net: Migrating to PHP 8.3',
            'url'   => 'https://www.php.net/manual/en/migration83.php',
        ),
        'php-migration-84'            => array(
            'label' => 'PHP.net: Migrating to PHP 8.4',
            'url'   => 'https://www.php.net/manual/en/migration84.php',
        ),
        'php-migration-85'            => array(
            'label' => 'PHP.net: Migrating to PHP 8.5',
            'url'   => 'https://www.php.net/manual/en/migration85.php',
        ),
        'wp-php-compatibility'        => array(
            'label' => 'WordPress.org: PHP Compatibility and WordPress Versions',
            'url'   => 'https://make.wordpress.org/core/handbook/references/php-compatibility-and-wordpress-versions/',
        ),
        'php2shell-advisory'          => array(
            'label' => 'THRG: PHP2Shell (CVE-2026-109618 / CVE-2026-109619) Advisory',
            'url'   => 'https://hackrepair.com/news/php2shell-php-vulnerability-wordpress',
        ),
    );

    /**
     * Register admin menus, assets, AJAX handlers, and form actions.
     *
     * @return void
     */
    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( THRG_PHP_CHECKER_FILE ), array( __CLASS__, 'action_links' ) );

        add_action( 'wp_ajax_thrg_pc_scan_start', array( __CLASS__, 'ajax_scan_start' ) );
        add_action( 'wp_ajax_thrg_pc_scan_step', array( __CLASS__, 'ajax_scan_step' ) );
        add_action( 'wp_ajax_thrg_pc_scan_stop', array( __CLASS__, 'ajax_scan_stop' ) );
        add_action( 'wp_ajax_thrg_pc_prescan_now', array( __CLASS__, 'ajax_prescan_now' ) );
        add_action( 'wp_ajax_thrg_pc_check_wporg_fix', array( __CLASS__, 'ajax_check_wporg_fix' ) );

        add_action( 'admin_post_thrg_pc_start', array( __CLASS__, 'handle_start_post' ) );
        add_action( 'admin_post_thrg_pc_save_settings', array( __CLASS__, 'handle_save_settings' ) );
        add_action( 'admin_post_thrg_pc_delete_report', array( __CLASS__, 'handle_delete_report' ) );
        add_action( 'admin_post_thrg_pc_clear_history', array( __CLASS__, 'handle_clear_history' ) );
        add_action( 'admin_post_thrg_pc_toggle_history', array( __CLASS__, 'handle_toggle_history' ) );
        add_action( 'admin_post_thrg_pc_email_report', array( __CLASS__, 'handle_email_report' ) );
    }

    /**
     * Current plugin version.
     *
     * @return string
     */
    public static function version() {
        return defined( 'THRG_PHP_CHECKER_VERSION' ) ? THRG_PHP_CHECKER_VERSION : self::VERSION;
    }

    /**
     * Supported target PHP minor versions for static compatibility scanning.
     *
     * @return string[]
     */
    public static function scan_targets() {
        return array( '8.1', '8.2', '8.3', '8.4', '8.5' );
    }

    /**
     * Running PHP major.minor version on this server.
     *
     * @return string
     */
    public static function running_php() {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    /**
     * Default target PHP version: the next minor version above the server's running PHP, or highest target.
     *
     * @return string
     */
    public static function default_target() {
        $running = self::running_php();
        $targets = self::scan_targets();
        foreach ( $targets as $target ) {
            if ( version_compare( $target, $running, '>' ) ) {
                return $target;
            }
        }
        return (string) end( $targets );
    }

    /**
     * Selected target PHP version for the current request.
     *
     * @return string
     */
    public static function selected_target() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selector validated against scan_targets().
        $requested = isset( $_GET['target'] ) ? sanitize_text_field( wp_unslash( $_GET['target'] ) ) : '';
        if ( in_array( $requested, self::scan_targets(), true ) ) {
            return $requested;
        }
        $state = THRG_PC_Scanner::get_state();
        if ( isset( $state['target'] ) && in_array( (string) $state['target'], self::scan_targets(), true ) ) {
            return (string) $state['target'];
        }
        return self::default_target();
    }

    /**
     * Default plugin settings.
     *
     * @return array{prescan_updates:int,default_scope:string,runtime_log:int}
     */
    public static function settings_defaults() {
        return array(
            'prescan_updates' => 0,
            'default_scope'   => 'all',
            'runtime_log'     => 0,
        );
    }

    /**
     * Normalized plugin settings.
     *
     * @return array{prescan_updates:int,default_scope:string,runtime_log:int}
     */
    public static function settings() {
        $raw = get_option( self::SETTINGS_OPTION, array() );
        $s   = array_merge( self::settings_defaults(), is_array( $raw ) ? $raw : array() );
        return array(
            'prescan_updates' => empty( $s['prescan_updates'] ) ? 0 : 1,
            'default_scope'   => 'active' === $s['default_scope'] ? 'active' : 'all',
            'runtime_log'     => empty( $s['runtime_log'] ) ? 0 : 1,
        );
    }

    /**
     * Add action link on the Plugins screen.
     *
     * @param array $links Existing action links.
     * @return array
     */
    public static function action_links( array $links ) {
        if ( current_user_can( 'manage_options' ) ) {
            $url = admin_url( 'tools.php?page=' . self::SLUG );
            array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Inspect PHP', 'thrg-php-checker' ) . '</a>' );
        }
        return $links;
    }

    /**
     * Register Tools > PHP Checker submenu page.
     *
     * @return void
     */
    public static function register_menu() {
        add_management_page(
            __( "The Hack Repair Guy's PHP Checker", 'thrg-php-checker' ),
            __( 'PHP Checker', 'thrg-php-checker' ),
            'manage_options',
            self::SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * Enqueue brand styles, checker styles, and AJAX scan script on Tools > PHP Checker.
     *
     * @param string $hook_suffix Current admin page hook.
     * @return void
     */
    public static function enqueue_assets( $hook_suffix ) {
        if ( 'tools_page_' . self::SLUG !== $hook_suffix ) {
            return;
        }
        wp_enqueue_style(
            'thrg-pc-brand',
            plugins_url( 'assets/brand/thrg-brand.css', THRG_PHP_CHECKER_FILE ),
            array(),
            self::version()
        );
        wp_enqueue_style(
            'thrg-pc-checker',
            plugins_url( 'assets/checker.css', THRG_PHP_CHECKER_FILE ),
            array( 'thrg-pc-brand' ),
            self::version()
        );
        wp_enqueue_script(
            'thrg-pc-checker',
            plugins_url( 'assets/js/checker.js', THRG_PHP_CHECKER_FILE ),
            array(),
            self::version(),
            true
        );

        $state   = THRG_PC_Scanner::get_state();
        $running = THRG_PC_Scanner::is_running( $state );
        $owned   = $running && isset( $state['user_id'] ) && ( 0 === (int) $state['user_id'] || get_current_user_id() === (int) $state['user_id'] );
        $target  = self::selected_target();

        wp_localize_script(
            'thrg-pc-checker',
            'thrgPcData',
            array(
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'pageUrl'      => admin_url( 'tools.php?page=' . self::SLUG ),
                'nonce'        => wp_create_nonce( 'thrg_pc_scan' ),
                'prescanNonce' => wp_create_nonce( 'thrg_pc_prescan' ),
                'running'      => $owned,
                'busy'         => $running && ! $owned,
                'jobId'        => $owned ? (string) $state['job_id'] : '',
                'target'       => $target,
                'state'        => array(
                    'total'       => isset( $state['total'] ) ? (int) $state['total'] : 0,
                    'finished'    => isset( $state['finished'] ) ? (int) $state['finished'] : 0,
                    'current'     => isset( $state['current']['name'] ) ? (string) $state['current']['name'] : '',
                    'files_total' => isset( $state['current']['files_total'] ) ? (int) $state['current']['files_total'] : 0,
                    'files_done'  => isset( $state['current']['files_total'], $state['current']['remaining'] )
                        ? max( 0, (int) $state['current']['files_total'] - count( (array) $state['current']['remaining'] ) )
                        : 0,
                ),
            )
        );
    }

    /**
     * Render the Tools > PHP Checker admin view.
     *
     * @return void
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'thrg-php-checker' ) );
        }
        require THRG_PHP_CHECKER_DIR . 'includes/view-checker.php';
    }

    /**
     * Require administrator capability for AJAX handlers.
     *
     * @return void
     */
    private static function require_admin_ajax() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden', 'thrg-php-checker' ) ), 403 );
        }
    }

    /**
     * Require administrator capability for admin-post handlers.
     *
     * @return void
     */
    private static function require_admin_post() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'thrg-php-checker' ), 403 );
        }
    }

    /**
     * AJAX: start a scan job.
     *
     * @return void
     */
    public static function ajax_scan_start() {
        check_ajax_referer( 'thrg_pc_scan' );
        self::require_admin_ajax();
        $reason = THRG_PC_Engine::unavailable_reason();
        if ( '' !== $reason ) {
            wp_send_json_error( array( 'message' => $reason ), 500 );
        }

        $target     = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : self::default_target();
        $rescan     = ! empty( $_POST['rescan'] );
        $scope      = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'all';
        $single_key = isset( $_POST['single_key'] ) ? sanitize_text_field( wp_unslash( $_POST['single_key'] ) ) : '';
        $user       = wp_get_current_user();

        try {
            $state = THRG_PC_Scanner::start(
                $target,
                $rescan,
                $scope,
                $single_key,
                $user ? (int) $user->ID : 0,
                $user ? (string) $user->user_login : ''
            );
        } catch ( InvalidArgumentException $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
        }

        if ( 0 === (int) $state['total'] ) {
            THRG_PC_Scanner::clear_state( $state );
            wp_send_json_success(
                array(
                    'done'        => true,
                    'total'       => 0,
                    'finished'    => 0,
                    'current'     => '',
                    'files_done'  => 0,
                    'files_total' => 0,
                    'target'      => $state['target'],
                    'job_id'      => (string) $state['job_id'],
                )
            );
        }

        wp_send_json_success(
            array(
                'done'        => false,
                'total'       => (int) $state['total'],
                'finished'    => 0,
                'current'     => '',
                'files_done'  => 0,
                'files_total' => 0,
                'target'      => $state['target'],
                'job_id'      => (string) $state['job_id'],
            )
        );
    }

    /**
     * AJAX: run one 5-second step of the active scan.
     *
     * @return void
     */
    public static function ajax_scan_step() {
        check_ajax_referer( 'thrg_pc_scan' );
        self::require_admin_ajax();
        $reason = THRG_PC_Engine::unavailable_reason();
        if ( '' !== $reason ) {
            wp_send_json_error( array( 'message' => $reason ), 500 );
        }
        $job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
        if ( ! THRG_PC_Scanner::owns_job( $job_id, get_current_user_id() ) ) {
            wp_send_json_error( array( 'message' => 'This scan is no longer available to your account.' ), 409 );
        }
        try {
            wp_send_json_success( THRG_PC_Scanner::step( microtime( true ) + 5.0, $job_id ) );
        } catch ( InvalidArgumentException $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ), 409 );
        }
    }

    /**
     * AJAX: stop an active scan job.
     *
     * @return void
     */
    public static function ajax_scan_stop() {
        check_ajax_referer( 'thrg_pc_scan' );
        self::require_admin_ajax();
        $job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
        if ( ! THRG_PC_Scanner::stop( $job_id, get_current_user_id() ) ) {
            wp_send_json_error( array( 'message' => 'This scan is no longer available to your account.' ), 409 );
        }
        wp_send_json_success( array( 'stopped' => true ) );
    }

    /**
     * AJAX: run a time-boxed update pre-scan pass right now.
     *
     * @return void
     */
    public static function ajax_prescan_now() {
        check_ajax_referer( 'thrg_pc_prescan' );
        self::require_admin_ajax();
        $out = THRG_PC_Prescan::run_slice( 8.0 );
        wp_send_json_success( $out );
    }

    /**
     * AJAX: check whether a failing plugin is fixed in a newer WordPress.org release.
     *
     * @return void
     */
    public static function ajax_check_wporg_fix() {
        check_ajax_referer( 'thrg_pc_prescan' );
        self::require_admin_ajax();
        $slug    = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
        $version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
        $target  = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : self::default_target();
        $out     = THRG_PC_Prescan::check_wporg_fix( $slug, $version, $target, microtime( true ) + 20.0 );
        wp_send_json_success( $out );
    }

    /**
     * Non-JS form fallback for starting or stopping a scan.
     *
     * @return void
     */
    public static function handle_start_post() {
        self::require_admin_post();
        check_admin_referer( 'thrg_pc_start' );

        $target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : self::default_target();
        if ( ! in_array( $target, self::scan_targets(), true ) ) {
            $target = self::default_target();
        }

        if ( ! empty( $_POST['stop'] ) ) {
            $job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
            if ( ! THRG_PC_Scanner::stop( $job_id, get_current_user_id() ) ) {
                wp_die( esc_html__( 'This scan is no longer available to your account.', 'thrg-php-checker' ), 409 );
            }
            wp_safe_redirect(
                add_query_arg(
                    array(
                        'page'   => self::SLUG,
                        'target' => $target,
                    ),
                    admin_url( 'tools.php' )
                )
            );
            exit;
        }

        $rescan     = ! empty( $_POST['rescan'] );
        $scope      = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'all';
        $single_key = isset( $_POST['single_key'] ) ? sanitize_text_field( wp_unslash( $_POST['single_key'] ) ) : '';
        $user       = wp_get_current_user();

        if ( '' === THRG_PC_Engine::unavailable_reason() ) {
            $current = THRG_PC_Scanner::get_state();
            if ( THRG_PC_Scanner::is_running( $current ) && 0 !== (int) $current['user_id'] && get_current_user_id() !== (int) $current['user_id'] ) {
                wp_die( esc_html__( 'Another scan is already running. Reload later to start yours.', 'thrg-php-checker' ), 409 );
            }
            $state = THRG_PC_Scanner::start(
                $target,
                $rescan,
                $scope,
                $single_key,
                $user ? (int) $user->ID : 0,
                $user ? (string) $user->user_login : ''
            );
            THRG_PC_Scanner::step( microtime( true ) + 5.0, $state['job_id'] );
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'   => self::SLUG,
                    'target' => $target,
                ),
                admin_url( 'tools.php' )
            )
        );
        exit;
    }

    /**
     * Save general settings (update pre-scanning toggle, default scan scope, or clear stored results).
     *
     * @return void
     */
    public static function handle_save_settings() {
        self::require_admin_post();
        check_admin_referer( 'thrg_pc_save_settings' );

        $target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : self::default_target();
        if ( ! in_array( $target, self::scan_targets(), true ) ) {
            $target = self::default_target();
        }

        if ( ! empty( $_POST['clear_results'] ) ) {
            THRG_PC_Results::clear_all();
            THRG_PC_Scanner::clear_state();
            THRG_PC_Prescan::clear_state();
            wp_safe_redirect(
                add_query_arg(
                    array(
                        'page'             => self::SLUG,
                        'target'           => $target,
                        'thrg_pc_settings' => 'cleared',
                    ),
                    admin_url( 'tools.php' )
                ) . '#pc-settings'
            );
            exit;
        }

        $prescan     = ! empty( $_POST['prescan_updates'] ) ? 1 : 0;
        $scope       = isset( $_POST['default_scope'] ) && 'active' === $_POST['default_scope'] ? 'active' : 'all';
        $runtime_log = isset( $_POST['runtime_log'] ) ? ( ! empty( $_POST['runtime_log'] ) ? 1 : 0 ) : self::settings()['runtime_log'];

        update_option(
            self::SETTINGS_OPTION,
            array(
                'prescan_updates' => $prescan,
                'default_scope'   => $scope,
                'runtime_log'     => $runtime_log,
            ),
            false
        );

        if ( $prescan ) {
            THRG_PC_Prescan::schedule();
        } else {
            THRG_PC_Prescan::unschedule();
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'             => self::SLUG,
                    'target'           => $target,
                    'thrg_pc_settings' => 'saved',
                ),
                admin_url( 'tools.php' )
            ) . '#pc-settings'
        );
        exit;
    }

    /**
     * Delete a single saved report from history.
     *
     * @return void
     */
    public static function handle_delete_report() {
        self::require_admin_post();
        check_admin_referer( 'thrg_pc_delete_report' );
        $id = isset( $_POST['report_id'] ) ? sanitize_text_field( wp_unslash( $_POST['report_id'] ) ) : '';
        THRG_PC_History::delete( $id );
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'            => self::SLUG,
                    'thrg_pc_history' => 'deleted',
                ),
                admin_url( 'tools.php' )
            ) . '#pc-history'
        );
        exit;
    }

    /**
     * Clear all saved report history.
     *
     * @return void
     */
    public static function handle_clear_history() {
        self::require_admin_post();
        check_admin_referer( 'thrg_pc_clear_history' );
        THRG_PC_History::clear();
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'            => self::SLUG,
                    'thrg_pc_history' => 'cleared',
                ),
                admin_url( 'tools.php' )
            ) . '#pc-history'
        );
        exit;
    }

    /**
     * Pause or resume report history.
     *
     * @return void
     */
    public static function handle_toggle_history() {
        self::require_admin_post();
        check_admin_referer( 'thrg_pc_toggle_history' );
        $enable = ! empty( $_POST['enable'] );
        THRG_PC_History::set_enabled( $enable );
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'            => self::SLUG,
                    'thrg_pc_history' => $enable ? 'resumed' : 'paused',
                ),
                admin_url( 'tools.php' )
            ) . '#pc-history'
        );
        exit;
    }

    /**
     * Email a current or historical report to chosen site administrators.
     *
     * @return void
     */
    public static function handle_email_report() {
        self::require_admin_post();
        check_admin_referer( 'thrg_pc_email_report' );

        $report_id = isset( $_POST['report_id'] ) ? sanitize_key( wp_unslash( $_POST['report_id'] ) ) : '';
        $target    = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : self::selected_target();
        if ( ! in_array( $target, self::scan_targets(), true ) ) {
            $target = self::default_target();
        }

        $back = static function ( $flag, $sent = 0, $failed = 0 ) use ( $report_id, $target ) {
            $args = array(
                'page'           => self::SLUG,
                'target'         => $target,
                'thrg_pc_mail'   => $flag,
                'thrg_pc_sent'   => (int) $sent,
                'thrg_pc_failed' => (int) $failed,
            );
            if ( THRG_PC_History::valid_id( $report_id ) ) {
                $args['thrg_report'] = $report_id;
            }
            wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php' ) ) . '#pc-share' );
            exit;
        };

        $ids = isset( $_POST['recipients'] ) && is_array( $_POST['recipients'] )
            ? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['recipients'] ) ) ) )
            : array();
        if ( ! $ids ) {
            $back( 'mail-none' );
        }

        $wait_key = 'thrg_pc_mail_' . get_current_user_id();
        if ( get_transient( $wait_key ) ) {
            $back( 'mail-wait' );
        }

        $note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
        $note = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, THRG_PC_Email::NOTE_LENGTH ) : substr( $note, 0, THRG_PC_Email::NOTE_LENGTH );

        $entry = THRG_PC_History::valid_id( $report_id ) ? THRG_PC_History::find( $report_id ) : null;
        if ( ! $entry ) {
            $rows  = self::evaluate( self::collect( $target ) );
            $entry = array(
                'id'      => '',
                'time'    => time(),
                'target'  => $target,
                'host'    => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
                'version' => self::version(),
                'rows'    => $rows,
            );
        }

        $older = null;
        $all   = THRG_PC_History::all();
        foreach ( $all as $i => $e ) {
            if ( ! empty( $entry['id'] ) && $e['id'] === $entry['id'] ) {
                for ( $j = $i + 1, $len = count( $all ); $j < $len; $j++ ) {
                    if ( ( isset( $all[ $j ]['target'] ) ? $all[ $j ]['target'] : '' ) === $entry['target'] ) {
                        $older = $all[ $j ];
                        break;
                    }
                }
                break;
            }
        }

        $result = THRG_PC_Email::send( $entry, $older, $ids, $note );
        if ( $result['sent'] ) {
            THRG_PC_Email::mark_cooldown( get_current_user_id() );
        }
        $back( $result['sent'] ? ( $result['failed'] ? 'mail-partial' : 'emailed' ) : 'mail-failed', $result['sent'], $result['failed'] );
    }
}

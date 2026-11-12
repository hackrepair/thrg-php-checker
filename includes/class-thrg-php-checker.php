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

/**
 * Core controller, lifecycle evaluator, and admin request router.
 */
final class THRG_PHP_Checker {

    const VERSION               = '1.2.0';
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
     * Days remaining until an YYYY-MM-DD end-of-life date.
     *
     * @param string $eol_date YYYY-MM-DD date string.
     * @param int    $now      Reference timestamp (0 for current time).
     * @return int
     */
    public static function eol_days_left( $eol_date, $now = 0 ) {
        $eol_ts = strtotime( $eol_date . ' 23:59:59 UTC' );
        $ref    = $now > 0 ? (int) $now : time();
        return (int) floor( ( $eol_ts - $ref ) / 86400 );
    }

    /**
     * Evaluate an EOL date into status and days remaining.
     *
     * @param string $eol_date YYYY-MM-DD date string.
     * @param int    $now      Reference timestamp (0 for current time).
     * @return array{status:string,days:int}
     */
    public static function eol_status( $eol_date, $now = 0 ) {
        $days = self::eol_days_left( $eol_date, $now );
        if ( $days < 0 ) {
            return array(
                'status' => 'Potential weakness',
                'days'   => $days,
            );
        }
        if ( $days <= 180 ) {
            return array(
                'status' => 'Review',
                'days'   => $days,
            );
        }
        return array(
            'status' => 'No issue observed',
            'days'   => $days,
        );
    }

    /**
     * Evaluate a PHP version string against PHP_SUPPORT.
     *
     * @param string $php_version Full PHP version string.
     * @param int    $now         Reference timestamp (0 for current time).
     * @return array{branch:string,eol:string,days:int|null,status:string,host_note:string}
     */
    public static function php_status( $php_version, $now = 0 ) {
        $parts     = explode( '.', (string) $php_version );
        $branch    = isset( $parts[0], $parts[1] ) ? $parts[0] . '.' . $parts[1] : (string) $php_version;
        $host_note = isset( self::HOST_REMOVALS[ $branch ] ) ? self::HOST_REMOVALS[ $branch ] : '';

        if ( isset( self::PHP_SUPPORT[ $branch ] ) ) {
            $eol   = self::PHP_SUPPORT[ $branch ]['eol'];
            $check = self::eol_status( $eol, $now );
            return array(
                'branch'    => $branch,
                'eol'       => $eol,
                'days'      => $check['days'],
                'status'    => $check['status'],
                'host_note' => $host_note,
            );
        }

        if ( version_compare( $branch, '7.4', '<' ) ) {
            return array(
                'branch'    => $branch,
                'eol'       => 'past end-of-life',
                'days'      => -1,
                'status'    => 'Potential weakness',
                'host_note' => '',
            );
        }

        return array(
            'branch'    => $branch,
            'eol'       => 'not in this plugin version table',
            'days'      => null,
            'status'    => 'Review',
            'host_note' => '',
        );
    }

    /**
     * Check whether a full PHP version is behind the latest patch release known to this build.
     *
     * @param string $php_version Full PHP version string.
     * @param int    $now         Reference timestamp (0 for current time).
     * @return array{known:bool,current:string,latest:string,behind:bool,stale_list:bool,as_of:string,advisories:array}
     */
    public static function php_patch( $php_version, $now = 0 ) {
        $clean   = preg_replace( '/^(\d+\.\d+\.\d+).*$/', '$1', (string) $php_version );
        $parts   = explode( '.', $clean );
        $branch  = isset( $parts[0], $parts[1] ) ? $parts[0] . '.' . $parts[1] : '';
        $ref     = $now > 0 ? (int) $now : time();
        $list_ts = strtotime( self::PATCH_LIST_AS_OF . ' 00:00:00 UTC' );
        $stale   = $list_ts && ( ( $ref - $list_ts ) / 86400 ) > self::PATCH_LIST_STALE_DAYS;

        if ( ! isset( self::PHP_SUPPORT[ $branch ]['patched'] ) || substr_count( $clean, '.' ) < 2 ) {
            return array(
                'known'      => false,
                'current'    => $clean,
                'latest'     => '',
                'behind'     => false,
                'stale_list' => $stale,
                'as_of'      => self::PATCH_LIST_AS_OF,
                'advisories' => array(),
            );
        }

        $latest     = self::PHP_SUPPORT[ $branch ]['patched'];
        $advisories = array();
        if ( isset( self::PHP_ADVISORIES[ $branch ] ) ) {
            foreach ( self::PHP_ADVISORIES[ $branch ] as $advisory ) {
                if ( version_compare( $clean, $advisory['fixed'], '<' ) ) {
                    $advisories[] = $advisory;
                }
            }
        }

        return array(
            'known'      => true,
            'current'    => $clean,
            'latest'     => $latest,
            'behind'     => version_compare( $clean, $latest, '<' ),
            'stale_list' => $stale,
            'as_of'      => self::PATCH_LIST_AS_OF,
            'advisories' => $advisories,
        );
    }

    /**
     * Migration manual link key for a target PHP version.
     *
     * @param string $target Target PHP version ("8.4").
     * @return string
     */
    public static function migration_link_key( $target ) {
        $key = 'php-migration-' . str_replace( '.', '', (string) $target );
        return isset( self::LINKS[ $key ] ) ? $key : 'php-supported-versions';
    }

    /**
     * Collect current PHP server facts, installed components, and stored scan results for $target.
     *
     * @param string $target Target PHP version (empty for selected_target()).
     * @return array
     */
    public static function collect( $target = '' ) {
        $target = in_array( (string) $target, self::scan_targets(), true ) ? (string) $target : self::selected_target();
        $comps  = THRG_PC_Scanner::components();
        $rows   = THRG_PC_Results::for_target( $target, $comps );
        $offers = THRG_PC_Prescan::entries();
        $guard  = THRG_PC_Prescan::guard_target();

        $update_items = array();
        foreach ( $offers as $offer ) {
            $res            = THRG_PC_Results::get_update( $offer['kind'], $offer['slug'], $offer['new_version'], $guard );
            $update_items[] = array(
                'kind'        => $offer['kind'],
                'slug'        => $offer['slug'],
                'file_key'    => $offer['file_key'],
                'new_version' => $offer['new_version'],
                'guard'       => $guard,
                'result'      => $res,
            );
        }

        return array(
            'php_version'     => PHP_VERSION,
            'running_php'     => self::running_php(),
            'target'          => $target,
            'engine_reason'   => THRG_PC_Engine::unavailable_reason(),
            'components'      => $comps,
            'results'         => $rows,
            'prescan_enabled' => ! empty( self::settings()['prescan_updates'] ),
            'update_items'    => $update_items,
            'now'             => time(),
        );
    }

    /**
     * Pure evaluation: turn collected observations into structured THRG findings.
     *
     * @param array $o Output of collect() or synthetic test fixture.
     * @return array[]
     */
    public static function evaluate( array $o ) {
        $now         = isset( $o['now'] ) ? (int) $o['now'] : time();
        $php_version = isset( $o['php_version'] ) ? (string) $o['php_version'] : PHP_VERSION;
        $target      = isset( $o['target'] ) && in_array( (string) $o['target'], self::scan_targets(), true ) ? (string) $o['target'] : '8.4';
        $comps       = isset( $o['components'] ) && is_array( $o['components'] ) ? $o['components'] : array();
        $results     = isset( $o['results'] ) && is_array( $o['results'] ) ? $o['results'] : array();
        $mig_link    = self::migration_link_key( $target );

        $findings = array();

        // 1. Server PHP version, EOL status, and security patch level.
        $php            = self::php_status( $php_version, $now );
        $php_patch      = self::php_patch( $php_version, $now );
        $php_status     = $php['status'];
        $php_advisories = isset( $php_patch['advisories'] ) ? $php_patch['advisories'] : array();

        if ( 'No issue observed' === $php_status && ( $php_patch['behind'] || ! empty( $php_advisories ) ) ) {
            $php_status = 'Review';
        }

        if ( null !== $php['days'] && $php['days'] < 0 ) {
            $php_current = sprintf( 'PHP %s (security support ended %s) · Target: PHP %s', $php_version, $php['eol'], $target );
            $php_meaning = sprintf(
                'This server runs PHP %1$s. Official php.net security support for PHP %2$s ended on %3$s. Inspecting plugins and themes below against PHP %4$s helps you upgrade safely without unexpected fatal errors.',
                $php_version,
                $php['branch'],
                $php['eol'],
                $target
            );
        } elseif ( null !== $php['days'] && $php['days'] <= 180 ) {
            $php_current = sprintf( 'PHP %s (%d days until security support ends on %s) · Target: PHP %s', $php_version, $php['days'], $php['eol'], $target );
            $php_meaning = sprintf(
                'PHP %1$s security support ends on %2$s (%3$d days remaining). Review the PHP %4$s compatibility results below so you can upgrade before end-of-life.',
                $php['branch'],
                $php['eol'],
                $php['days'],
                $target
            );
        } elseif ( null !== $php['days'] ) {
            $php_current = sprintf( 'PHP %s (supported through %s) · Target: PHP %s', $php_version, $php['eol'], $target );
            $php_meaning = sprintf(
                'PHP %1$s receives official php.net security fixes through %2$s. You are currently reviewing static compatibility against PHP %3$s.',
                $php['branch'],
                $php['eol'],
                $target
            );
        } else {
            $php_current = sprintf( 'PHP %s · Target: PHP %s', $php_version, $target );
            $php_meaning = sprintf(
                'This plugin version table covers PHP 7.4 through 8.5; verify PHP %1$s support dates on php.net.',
                $php['branch']
            );
        }

        if ( $php_patch['known'] && $php_patch['behind'] ) {
            $php_current .= sprintf( ' · Patch %s available', $php_patch['latest'] );
            $php_meaning .= sprintf(
                ' Within PHP %1$s, this server runs %2$s while %3$s was the latest patch release known to this plugin build (patch list dated %4$s).',
                $php['branch'],
                $php_patch['current'],
                $php_patch['latest'],
                $php_patch['as_of']
            );
        }
        if ( ! empty( $php_advisories ) ) {
            foreach ( $php_advisories as $adv ) {
                $php_current .= sprintf( ' · Affected by %s', $adv['cve'] );
                $php_meaning .= sprintf( ' %1$s (%2$s): %3$s', $adv['name'], $adv['cve'], $adv['summary'] );
            }
        }
        if ( '' !== $php['host_note'] ) {
            $php_meaning .= ' ' . $php['host_note'];
        }

        $php_links = array( 'php-supported-versions', 'wp-php-compatibility', $mig_link );
        foreach ( $php_advisories as $adv ) {
            if ( ! empty( $adv['link'] ) && ! in_array( $adv['link'], $php_links, true ) ) {
                $php_links[] = $adv['link'];
            }
        }

        if ( null !== $php['days'] && $php['days'] < 0 ) {
            $php_badge = 'End of Life';
            $php_hint  = 'EOL';
        } elseif ( ! empty( $php_advisories ) ) {
            $php_badge = 'Security Advisory';
            $php_hint  = 'Advisory';
        } elseif ( null !== $php['days'] && $php['days'] <= 180 ) {
            $php_badge = sprintf( 'Support ends in %dd', $php['days'] );
            $php_hint  = sprintf( '%dd left', $php['days'] );
        } elseif ( $php_patch['known'] && $php_patch['behind'] ) {
            $php_badge = sprintf( 'Patch %s available', $php_patch['latest'] );
            $php_hint  = 'Patch avail.';
        } else {
            $php_badge = 'Supported';
            $php_hint  = 'Supported';
        }

        $server_row = array(
            'id'         => 'php-version',
            'area'       => 'Server',
            'label'      => sprintf( 'Server PHP %s lifecycle', $php_version ),
            'title'      => sprintf( 'Server PHP %s & security lifecycle', $php_version ),
            'status'     => $php_status,
            'badge_text' => $php_badge,
            'nav_hint'   => $php_hint,
            'current'    => $php_current,
            'why'        => 'PHP runs WordPress, every plugin, and your active theme. Upgrading PHP improves performance and security, while incompatible code can cause fatal errors or deprecation noise.',
            'meaning'    => $php_meaning,
            'evidence'   => array( $php_current, $php_meaning ),
            'table'      => array(
                'head' => array(),
                'rows' => array(),
            ),
            'next'       => 'Use the PHP target selector above to test plugins and themes against your planned PHP version before switching PHP in your hosting panel.',
            'next_steps' => 'Use the PHP target selector above to test plugins and themes against your planned PHP version before switching PHP in your hosting panel.',
            'links'      => array_values( array_unique( $php_links ) ),
        );

        // 2. Scan coverage & target overview (shown only when engine is unavailable or 0 components scanned yet).
        $total_comps   = count( $comps );
        $scanned_comps = count( $results );
        $overview_row  = null;

        if ( ! empty( $o['engine_reason'] ) ) {
            $overview_row = array(
                'id'         => 'scan-overview',
                'area'       => 'Scanner',
                'label'      => 'Scanner engine unavailable',
                'title'      => 'Scanner engine unavailable',
                'status'     => 'Not available',
                'badge_text' => 'Unavailable',
                'nav_hint'   => 'Error',
                'current'    => (string) $o['engine_reason'],
                'why'        => 'Static analysis parses every PHP file in your plugins, must-use plugins, and themes without executing untrusted code.',
                'meaning'    => 'The bundled PHPCompatibilityWP static analyzer could not initialize on this server.',
                'evidence'   => array( (string) $o['engine_reason'] ),
                'table'      => array(
                    'head' => array(),
                    'rows' => array(),
                ),
                'next'       => 'Ensure the PHP tokenizer extension is enabled and vendor/thrg-scan-engine is intact.',
                'next_steps' => 'Ensure the PHP tokenizer extension is enabled and vendor/thrg-scan-engine is intact.',
                'links'      => array( $mig_link, 'wp-php-compatibility' ),
            );
        } elseif ( 0 === $scanned_comps ) {
            $overview_current = sprintf( '0 of %d installed components scanned for PHP %s', $total_comps, $target );
            $overview_meaning = sprintf(
                'No static scan results are stored yet for PHP %1$s. This site has %2$d installed components (plugins, must-use plugins, and themes) ready to inspect.',
                $target,
                $total_comps
            );
            $overview_next    = sprintf( 'Click Scan above to inspect installed plugins and themes against PHP %s.', $target );
            $overview_row     = array(
                'id'         => 'scan-overview',
                'area'       => 'Getting Started',
                'label'      => sprintf( 'Ready to scan for PHP %s', $target ),
                'title'      => sprintf( 'Ready to scan for PHP %s', $target ),
                'status'     => 'Review',
                'badge_text' => 'Not scanned yet',
                'nav_hint'   => '0 scanned',
                'current'    => $overview_current,
                'why'        => 'Static analysis parses every PHP file in your plugins, must-use plugins, and themes to detect removed functions, fatal signature changes, and deprecated features without executing untrusted code.',
                'meaning'    => $overview_meaning,
                'evidence'   => array( $overview_current, $overview_meaning ),
                'table'      => array(
                    'head' => array(),
                    'rows' => array(),
                ),
                'next'       => $overview_next,
                'next_steps' => $overview_next,
                'links'      => array( $mig_link, 'wp-php-compatibility' ),
            );
        }

        // 3. Pending update compatibility checks (shown only when pre-scan is enabled or at least 1 update was scanned).
        $update_items    = isset( $o['update_items'] ) && is_array( $o['update_items'] ) ? $o['update_items'] : array();
        $prescan_enabled = ! empty( $o['prescan_enabled'] );
        $update_row      = null;

        if ( $prescan_enabled || ! empty( $update_items ) ) {
            $u_breaks  = 0;
            $u_warn    = 0;
            $u_checked = 0;
            $u_lines   = array();
            $guard_ver = isset( $o['running_php'] ) ? (string) $o['running_php'] : self::running_php();

            foreach ( $update_items as $u ) {
                $guard_ver = isset( $u['guard'] ) ? (string) $u['guard'] : $guard_ver;
                if ( ! empty( $u['result'] ) && is_array( $u['result'] ) ) {
                    ++$u_checked;
                    $uv = isset( $u['result']['verdict'] ) ? (string) $u['result']['verdict'] : 'ok';
                    if ( 'breaks' === $uv ) {
                        ++$u_breaks;
                        $u_lines[] = sprintf( '%s %s (%d error(s) on PHP %s)', $u['slug'], $u['new_version'], (int) $u['result']['errors'], $guard_ver );
                    } elseif ( in_array( $uv, array( 'warnings', 'partial' ), true ) ) {
                        ++$u_warn;
                        $u_lines[] = sprintf( '%s %s (%d warning(s) on PHP %s)', $u['slug'], $u['new_version'], (int) $u['result']['warnings'], $guard_ver );
                    }
                }
            }

            if ( $prescan_enabled || $u_checked > 0 ) {
                if ( 0 === count( $update_items ) ) {
                    $u_status  = 'No issue observed';
                    $u_badge   = 'No pending updates';
                    $u_hint    = '0 updates';
                    $u_current = 'No pending plugin or theme updates right now';
                    $u_meaning = sprintf( 'Update pre-scanning is enabled for PHP %s, and WordPress currently reports no pending plugin or theme updates.', $guard_ver );
                    $u_next    = 'When new plugin or theme updates appear, PHP Checker will inspect their zip archives in memory against your running PHP version.';
                } elseif ( $u_breaks > 0 ) {
                    $u_status  = 'Potential weakness';
                    $u_badge   = sprintf( '%d update incompatible', $u_breaks );
                    $u_hint    = sprintf( '%d error', $u_breaks );
                    $u_current = sprintf( '%d of %d pending update(s) reported errors on PHP %s', $u_breaks, count( $update_items ), $guard_ver );
                    $u_meaning = 'In-memory zip analysis detected PHP compatibility errors in offered update(s): ' . implode( '; ', $u_lines ) . '.';
                    $u_next    = 'Hold off on applying the flagged update(s) on this PHP version, or upgrade the server PHP version first if the new plugin release requires a newer PHP branch.';
                } elseif ( $u_warn > 0 || $u_checked < count( $update_items ) ) {
                    $u_status  = 'Review';
                    $u_badge   = $u_warn > 0 ? sprintf( '%d with warnings', $u_warn ) : sprintf( '%d/%d checked', $u_checked, count( $update_items ) );
                    $u_hint    = $u_warn > 0 ? sprintf( '%d warn', $u_warn ) : 'Partial';
                    $u_current = sprintf( '%d of %d pending update(s) checked against PHP %s (%d with warnings)', $u_checked, count( $update_items ), $guard_ver, $u_warn );
                    $u_meaning = $u_lines
                        ? 'Offered update(s) reported deprecation warnings on PHP ' . $guard_ver . ': ' . implode( '; ', $u_lines ) . '.'
                        : sprintf( '%d of %d pending update(s) have been pre-scanned so far against PHP %s.', $u_checked, count( $update_items ), $guard_ver );
                    $u_next    = $u_checked < count( $update_items )
                        ? 'Click Check Pending Updates in Settings below to finish inspecting remaining update packages.'
                        : 'Review deprecation details before updating on production.';
                } else {
                    $u_status  = 'No issue observed';
                    $u_badge   = 'Updates compatible';
                    $u_hint    = 'Clean';
                    $u_current = sprintf( 'All %d pending update(s) passed static checks for PHP %s', $u_checked, $guard_ver );
                    $u_meaning = sprintf( 'Every currently offered plugin and theme update zip was inspected in memory and reported 0 errors or warnings for PHP %s.', $guard_ver );
                    $u_next    = 'No action required for PHP compatibility on these pending updates.';
                }

                $update_row = array(
                    'id'           => 'update-checks',
                    'area'         => 'Updates',
                    'label'        => sprintf( 'Pending updates (PHP %s)', $guard_ver ),
                    'title'        => sprintf( 'Pending update compatibility (PHP %s)', $guard_ver ),
                    'status'       => $u_status,
                    'badge_text'   => $u_badge,
                    'nav_hint'     => $u_hint,
                    'current'      => $u_current,
                    'why'          => 'Occasionally a newly released plugin or theme update introduces syntax or calls that require a newer PHP version than the server currently runs.',
                    'meaning'      => $u_meaning,
                    'evidence'     => array( $u_current, $u_meaning ),
                    'table'        => array(
                        'head' => array(),
                        'rows' => array(),
                    ),
                    'next'         => $u_next,
                    'next_steps'   => $u_next,
                    'links'        => array( 'wp-php-compatibility' ),
                    'update_items' => $update_items,
                );
            }
        }

        // 4. Sort scanned component rows: breaks first, warnings second, partial third, ok last; active before inactive; alphabetical by name.
        $verdict_rank = array(
            'breaks'   => 0,
            'warnings' => 1,
            'partial'  => 2,
            'ok'       => 3,
        );
        usort(
            $results,
            static function ( $a, $b ) use ( $verdict_rank ) {
                $ra = isset( $verdict_rank[ $a['verdict'] ] ) ? $verdict_rank[ $a['verdict'] ] : 9;
                $rb = isset( $verdict_rank[ $b['verdict'] ] ) ? $verdict_rank[ $b['verdict'] ] : 9;
                if ( $ra !== $rb ) {
                    return $ra - $rb;
                }
                $aa = ! empty( $a['active'] ) ? 0 : 1;
                $ab = ! empty( $b['active'] ) ? 0 : 1;
                if ( $aa !== $ab ) {
                    return $aa - $ab;
                }
                return strcasecmp( (string) $a['name'], (string) $b['name'] );
            }
        );

        $comp_breaks   = array();
        $comp_warnings = array();
        $comp_clean    = array();

        foreach ( $results as $r ) {
            $card = self::component_finding( $r, $target, $mig_link );
            if ( 'breaks' === $card['verdict'] ) {
                $comp_breaks[] = $card;
            } elseif ( 'ok' === $card['verdict'] ) {
                $comp_clean[] = $card;
            } else {
                $comp_warnings[] = $card;
            }
        }

        // 5. Assemble findings in client-first priority order:
        //    a) Overview callout only when 0 components are scanned or engine is unavailable.
        //    b) Incompatible plugins/themes (breaks) FIRST so the client sees blocking issues immediately.
        //    c) Critical server/update blockers (Potential weakness).
        //    d) Warning/partial plugins/themes.
        //    e) Non-blocking server PHP lifecycle & pending update checks.
        //    f) Clean components last.
        if ( null !== $overview_row ) {
            $findings[] = $overview_row;
        }

        foreach ( $comp_breaks as $c_row ) {
            $findings[] = $c_row;
        }

        if ( 'Potential weakness' === $server_row['status'] ) {
            $findings[] = $server_row;
        }
        if ( null !== $update_row && 'Potential weakness' === $update_row['status'] ) {
            $findings[] = $update_row;
        }

        foreach ( $comp_warnings as $c_row ) {
            $findings[] = $c_row;
        }

        if ( 'Potential weakness' !== $server_row['status'] ) {
            $findings[] = $server_row;
        }
        if ( null !== $update_row && 'Potential weakness' !== $update_row['status'] ) {
            $findings[] = $update_row;
        }

        foreach ( $comp_clean as $c_row ) {
            $findings[] = $c_row;
        }

        return $findings;
    }

    /**
     * Build a structured finding card for one scanned component.
     *
     * @param array  $r        Stored result row enriched by THRG_PC_Results::for_target().
     * @param string $target   Target PHP version.
     * @param string $mig_link Migration manual link key.
     * @return array
     */
    private static function component_finding( array $r, $target, $mig_link ) {
        $kind       = isset( $r['kind'] ) ? (string) $r['kind'] : 'plugin';
        $slug       = isset( $r['slug'] ) ? (string) $r['slug'] : '';
        $name       = isset( $r['name'] ) ? (string) $r['name'] : $slug;
        $version    = isset( $r['version'] ) ? (string) $r['version'] : '0';
        $active     = ! empty( $r['active'] );
        $network    = ! empty( $r['network_active'] );
        $parent     = ! empty( $r['parent_theme'] );
        $update_ver = isset( $r['update_version'] ) ? (string) $r['update_version'] : '';
        $verdict    = isset( $r['verdict'] ) ? (string) $r['verdict'] : 'ok';
        $errors     = isset( $r['errors'] ) ? (int) $r['errors'] : 0;
        $warnings   = isset( $r['warnings'] ) ? (int) $r['warnings'] : 0;
        $skipped    = isset( $r['skipped'] ) ? (int) $r['skipped'] : 0;
        $notes      = isset( $r['notes'] ) ? (int) $r['notes'] : 0;
        $files_tot  = isset( $r['files_total'] ) ? (int) $r['files_total'] : 0;
        $items      = isset( $r['findings'] ) && is_array( $r['findings'] ) ? $r['findings'] : array();

        if ( 'mu-plugin' === $kind ) {
            $area        = 'Must-Use Plugin';
            $state_label = 'Must-use plugin (always active)';
        } elseif ( 'theme' === $kind ) {
            if ( $parent ) {
                $area        = 'Parent Theme';
                $state_label = 'Active parent theme';
            } elseif ( $active ) {
                $area        = 'Active Theme';
                $state_label = 'Active theme';
            } else {
                $area        = 'Inactive Theme';
                $state_label = 'Inactive theme';
            }
        } elseif ( $network ) {
            $area        = 'Active Plugin';
            $state_label = 'Network active plugin';
        } elseif ( $active ) {
            $area        = 'Active Plugin';
            $state_label = 'Active plugin';
        } else {
            $area        = 'Inactive Plugin';
            $state_label = 'Inactive plugin';
        }

        $status = THRG_PC_Results::status_from_verdict( $verdict );
        $counts = array();
        if ( $errors > 0 ) {
            $counts[] = sprintf( '%d %s', $errors, 1 === $errors ? 'error' : 'errors' );
        }
        if ( $warnings > 0 ) {
            $counts[] = sprintf( '%d %s', $warnings, 1 === $warnings ? 'warning' : 'warnings' );
        }
        if ( $skipped > 0 ) {
            $counts[] = sprintf( '%d oversized %s skipped', $skipped, 1 === $skipped ? 'file' : 'files' );
        }
        if ( ! $counts ) {
            $counts[] = '0 errors, 0 warnings';
        }
        if ( $notes > 0 ) {
            $counts[] = sprintf( '%d guarded/polyfill %s filtered', $notes, 1 === $notes ? 'note' : 'notes' );
        }

        if ( 'breaks' === $verdict ) {
            $badge_text = sprintf( 'Incompatible · %d %s', $errors, 1 === $errors ? 'error' : 'errors' );
            if ( $warnings > 0 ) {
                $badge_text .= sprintf( ', %d %s', $warnings, 1 === $warnings ? 'warning' : 'warnings' );
            }
            $nav_hint = sprintf( '%d %s', $errors, 1 === $errors ? 'error' : 'errors' );
        } elseif ( 'warnings' === $verdict ) {
            $badge_text = sprintf( '%d deprecation %s', $warnings, 1 === $warnings ? 'warning' : 'warnings' );
            $nav_hint   = sprintf( '%d warn', $warnings );
        } elseif ( 'partial' === $verdict ) {
            $badge_text = sprintf( '%d large %s skipped', $skipped, 1 === $skipped ? 'file' : 'files' );
            $nav_hint   = 'Partial';
        } else {
            $badge_text = 'Compatible';
            $nav_hint   = 'Clean';
        }

        $current = sprintf( '%s · %s · v%s', implode( ', ', $counts ), $state_label, $version );
        if ( '' !== $update_ver ) {
            $current .= sprintf( ' · Update %s available', $update_ver );
        }

        if ( 'breaks' === $verdict ) {
            $meaning = sprintf(
                '%1$s (%2$s, v%3$s) contains %4$d unguarded PHP %5$s error(s). If those code paths execute on PHP %5$s, PHP can throw a fatal error or halt the request.',
                $name,
                strtolower( $state_label ),
                $version,
                $errors,
                $target
            );
            if ( '' !== $update_ver ) {
                $next = sprintf(
                    'Version %1$s is available for %2$s — update the %3$s first and click Rescan on this card to check whether the author already resolved the PHP %4$s incompatibility.',
                    $update_ver,
                    $name,
                    'theme' === $kind ? 'theme' : 'plugin',
                    $target
                );
            } elseif ( ! $active ) {
                $next = sprintf(
                    '%1$s is currently inactive, so it cannot break front-end requests unless activated. Delete it if unused, or update/replace it before activating on PHP %2$s.',
                    $name,
                    $target
                );
            } else {
                $next = sprintf(
                    'Check for an updated release of %1$s, contact the developer with the file and line numbers below, or replace this %2$s before upgrading the server to PHP %3$s.',
                    $name,
                    'theme' === $kind ? 'theme' : 'plugin',
                    $target
                );
            }
        } elseif ( 'warnings' === $verdict ) {
            $meaning = sprintf(
                '%1$s (%2$s, v%3$s) reported 0 fatal errors and %4$d deprecation warning(s) on PHP %5$s. Deprecated features continue to work in PHP %5$s (they will not crash your site today), though they may log E_DEPRECATED notices.',
                $name,
                strtolower( $state_label ),
                $version,
                $warnings,
                $target
            );
            $next    = '' !== $update_ver
                ? sprintf( 'Update %1$s to version %2$s and click Rescan, or keep WP_DEBUG_DISPLAY off on live sites while the author updates deprecated calls.', $name, $update_ver )
                : sprintf( 'Safe to upgrade to PHP %1$s with WP_DEBUG_DISPLAY off; keep %2$s updated as future releases clean up these deprecations.', $target, $name );
        } elseif ( 'partial' === $verdict ) {
            $meaning = sprintf(
                '%1$s had 0 errors or warnings in scanned files, but %2$d PHP file(s) larger than 2 MB were skipped to prevent memory exhaustion.',
                $name,
                $skipped
            );
            $next    = 'Inspect the skipped oversized file(s) listed below manually or via CLI if they contain custom runtime code.';
        } else {
            $meaning = sprintf(
                'No unguarded PHP %1$s errors or deprecation warnings were detected across %2$s (%3$s, v%4$s).',
                $target,
                $name,
                strtolower( $state_label ),
                $version
            );
            if ( $notes > 0 ) {
                $meaning .= sprintf(
                    ' %d finding(s) inside runtime version guards or polyfill fallbacks were verified safe and downgraded to informational notes.',
                    $notes
                );
            }
            $next = 'No action required.';
        }

        $wporg_fix_status = '';
        $wporg_fix_label  = '';
        if ( 'plugin' === $kind && in_array( $verdict, array( 'breaks', 'warnings' ), true ) ) {
            $folder_slug = dirname( $slug );
            $short_slug  = '.' === $folder_slug ? basename( $slug, '.php' ) : $folder_slug;
            $check_ver   = $update_ver;
            if ( '' === $check_ver && function_exists( 'get_transient' ) ) {
                $cached_wporg = get_transient( 'thrg_pc_wporg_' . substr( md5( sanitize_key( $short_slug ) ), 0, 12 ) );
                if ( is_array( $cached_wporg ) && ! empty( $cached_wporg['version'] ) && version_compare( (string) $cached_wporg['version'], $version, '>' ) ) {
                    $check_ver = (string) $cached_wporg['version'];
                }
            }
            if ( '' !== $check_ver ) {
                $upd_res = THRG_PC_Results::get_update( 'plugin', sanitize_key( $short_slug ), $check_ver, $target );
                if ( is_array( $upd_res ) && isset( $upd_res['verdict'] ) ) {
                    if ( in_array( $upd_res['verdict'], array( 'ok', 'clean' ), true ) ) {
                        $wporg_fix_status = 'fixed';
                        $wporg_fix_label  = sprintf( 'Fixed in v%s on WordPress.org', $check_ver );
                        $next             = sprintf(
                            'Good news: We scanned v%1$s from WordPress.org against PHP %2$s and confirmed all compatibility issues are fixed (0 errors, 0 warnings). Update %3$s now.',
                            $check_ver,
                            $target,
                            $name
                        );
                    } elseif ( 'breaks' === $verdict && in_array( $upd_res['verdict'], array( 'warnings', 'works' ), true ) ) {
                        $wporg_fix_status = 'warnings_only';
                        $wporg_fix_label  = sprintf( 'Fatal errors fixed in v%s (%d warnings)', $check_ver, (int) $upd_res['warnings'] );
                        $next             = sprintf(
                            'Good news: v%1$s on WordPress.org fixes all fatal PHP %2$s errors (%3$d deprecation warning(s) remain). Update %4$s before upgrading PHP.',
                            $check_ver,
                            $target,
                            (int) $upd_res['warnings'],
                            $name
                        );
                    } elseif ( 'breaks' === $upd_res['verdict'] ) {
                        $wporg_fix_status = 'still_fails';
                        $wporg_fix_label  = sprintf( 'Still fails in v%s (%d errors)', $check_ver, (int) $upd_res['errors'] );
                        $next             = sprintf(
                            'We scanned the newer v%1$s on WordPress.org and it still has %2$d fatal error(s) on PHP %3$s. Updating alone will not fix this — contact the author or replace %4$s.',
                            $check_ver,
                            (int) $upd_res['errors'],
                            $target,
                            $name
                        );
                    }
                }
            }
        }

        $own_issues     = 0;
        $bundled_issues = 0;
        $has_dyn_prop   = false;
        $own_label      = 'theme' === $kind ? 'theme code' : 'plugin code';

        foreach ( $items as $idx => $f ) {
            $loc    = isset( $f['file'] ) ? (string) $f['file'] : '';
            $origin = '' !== $loc
                ? THRG_PC_Results::file_origin( $loc )
                : ( isset( $f['origin'] ) && 'bundled' === $f['origin'] ? 'bundled' : 'own' );

            $items[ $idx ]['origin'] = $origin;
        }

        // Sort findings so fatal errors and the plugin/theme's own code appear at the very top of the table.
        $sev_order = array(
            'error'   => 0,
            'warning' => 1,
            'skipped' => 2,
            'note'    => 3,
        );
        usort(
            $items,
            static function ( $a, $b ) use ( $sev_order ) {
                $sa = isset( $a['type'], $sev_order[ $a['type'] ] ) ? $sev_order[ $a['type'] ] : 9;
                $sb = isset( $b['type'], $sev_order[ $b['type'] ] ) ? $sev_order[ $b['type'] ] : 9;
                if ( $sa !== $sb ) {
                    return $sa - $sb;
                }
                $oa = ( isset( $a['origin'] ) && 'bundled' === $a['origin'] ) ? 1 : 0;
                $ob = ( isset( $b['origin'] ) && 'bundled' === $b['origin'] ) ? 1 : 0;
                if ( $oa !== $ob ) {
                    return $oa - $ob;
                }
                $fa = isset( $a['file'] ) ? (string) $a['file'] : '';
                $fb = isset( $b['file'] ) ? (string) $b['file'] : '';
                $fc = strcmp( $fa, $fb );
                if ( 0 !== $fc ) {
                    return $fc;
                }
                return ( isset( $a['line'] ) ? (int) $a['line'] : 0 ) - ( isset( $b['line'] ) ? (int) $b['line'] : 0 );
            }
        );

        $table_rows = array();
        foreach ( $items as $f ) {
            $ft     = isset( $f['type'] ) ? (string) $f['type'] : 'warning';
            $loc    = isset( $f['file'] ) ? (string) $f['file'] : '';
            $line   = isset( $f['line'] ) ? (int) $f['line'] : 0;
            $source = isset( $f['source'] ) ? (string) $f['source'] : '';
            $origin = isset( $f['origin'] ) ? (string) $f['origin'] : 'own';

            if ( 0 === strpos( $source, 'PHPCompatibility.Classes.ForbiddenDynamicProperties' ) ) {
                $has_dyn_prop = true;
            }
            if ( 'note' === $ft ) {
                continue;
            }
            if ( 'bundled' === $origin ) {
                ++$bundled_issues;
                $origin_tag = 'bundled dependency';
            } else {
                ++$own_issues;
                $origin_tag = $own_label;
            }
            $loc_str      = ( $line > 0 ? $loc . ':' . $line : $loc ) . ' (' . $origin_tag . ')';
            $msg_str      = isset( $f['message'] ) ? (string) $f['message'] : '';
            $rule_str     = '' !== $source ? $msg_str . ' [' . $source . ']' : $msg_str;
            $table_rows[] = array(
                strtoupper( $ft ) . ' (PHP ' . $target . ')',
                $loc_str,
                $rule_str,
            );
        }

        if ( $own_issues > 0 || $bundled_issues > 0 ) {
            $origin_parts = array();
            if ( $own_issues > 0 ) {
                $origin_parts[] = sprintf( '%d in %s', $own_issues, $own_label );
            }
            if ( $bundled_issues > 0 ) {
                $origin_parts[] = sprintf( '%d in bundled dependency', $bundled_issues );
            }
            $current .= ' · ' . implode( ', ', $origin_parts );
        }

        $comp_links = array( $mig_link );
        if ( $has_dyn_prop || '8.2' === $target ) {
            $comp_links[] = 'php-migration-82-deprecated';
        }
        $comp_links = array_values( array_unique( $comp_links ) );

        $title = sprintf( '%s (%s)', $name, $version );

        return array(
            'id'                  => 'comp-' . sanitize_title( $kind . '-' . $slug ),
            'area'                => $area,
            'label'               => $title,
            'title'               => $title,
            'status'              => $status,
            'badge_text'          => $badge_text,
            'nav_hint'            => $nav_hint,
            'current'             => $current,
            'why'                 => $meaning,
            'meaning'             => $meaning,
            'evidence'            => array( $current ),
            'table'               => array(
                'head' => $table_rows ? array( 'Severity & Target', 'File : Line (Origin)', 'Issue & Triggered Rule' ) : array(),
                'rows' => $table_rows,
            ),
            'next'                => 'No action required.' === $next ? '' : $next,
            'next_steps'          => $next,
            'links'               => $comp_links,
            'target'              => $target,
            'comp_kind'           => $kind,
            'comp_slug'           => $slug,
            'comp_key'            => THRG_PC_Results::key( $kind, $slug ),
            'comp_name'           => $name,
            'comp_version'        => $version,
            'comp_active'         => $active,
            'comp_network_active' => $network,
            'comp_parent_theme'   => $parent,
            'comp_update_version' => $update_ver,
            'wporg_fix_status'    => $wporg_fix_status,
            'wporg_fix_label'     => $wporg_fix_label,
            'verdict'             => $verdict,
            'errors'              => $errors,
            'warnings'            => $warnings,
            'skipped'             => $skipped,
            'notes'               => $notes,
            'own_issues'          => $own_issues,
            'bundled_issues'      => $bundled_issues,
            'files_total'         => $files_tot,
            'findings'            => $items,
            'scanned_at'          => isset( $r['scanned_at'] ) ? (int) $r['scanned_at'] : 0,
        );
    }

    /**
     * Resolve an allowlisted documentation link key or https:// URL.
     *
     * @param mixed $link Link key or URL.
     * @return array{label:string,url:string}|null
     */
    public static function href( $link ) {
        if ( is_string( $link ) && isset( self::LINKS[ $link ] ) ) {
            return self::LINKS[ $link ];
        }
        if ( is_array( $link ) && isset( $link['label'], $link['url'] ) && 0 === strpos( (string) $link['url'], 'https://' ) ) {
            return array(
                'label' => (string) $link['label'],
                'url'   => (string) $link['url'],
            );
        }
        return null;
    }

    /**
     * Escape text while converting [[key|label]] tokens to safe external links.
     *
     * @param string $text Raw text with optional link tokens.
     * @return string Safe HTML.
     */
    public static function linkify( $text ) {
        $parts = preg_split( '/(\[\[[a-z0-9-]+(?:\|[^\[\]]+)?\]\])/', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE );
        $html  = '';
        foreach ( $parts as $part ) {
            if ( preg_match( '/^\[\[([a-z0-9-]+)(?:\|([^\[\]]+))?\]\]$/', $part, $m ) ) {
                $link = self::href( $m[1] );
                if ( $link ) {
                    $label = isset( $m[2] ) && '' !== $m[2] ? $m[2] : $link['label'];
                    $html .= '<a href="' . esc_url( $link['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>';
                    continue;
                }
            }
            $html .= esc_html( $part );
        }
        return $html;
    }

    /**
     * Strip [[key|label]] tokens down to plain text.
     *
     * @param string $text Input text.
     * @return string
     */
    public static function strip_links( $text ) {
        return (string) preg_replace_callback(
            '/\[\[([a-z0-9-]+)(?:\|([^\[\]]+))?\]\]/',
            static function ( $m ) {
                if ( isset( $m[2] ) && '' !== $m[2] ) {
                    return $m[2];
                }
                $link = self::href( $m[1] );
                return $link ? $link['label'] : $m[1];
            },
            (string) $text
        );
    }

    /**
     * Build a plain-text report suitable for clipboard copy, .txt download, or email attachment.
     *
     * @param array[] $rows      Evaluated findings.
     * @param string  $site_host Site hostname.
     * @param string  $timestamp Formatted timestamp.
     * @param string  $version   Plugin version.
     * @param string  $target    Target PHP version.
     * @return string
     */
    public static function plain_text( array $rows, $site_host = '', $timestamp = '', $version = '', $target = '' ) {
        $ver    = '' !== $version ? $version : self::version();
        $target = '' !== $target ? $target : self::selected_target();
        $lines  = array(
            sprintf( "The Hack Repair Guy's PHP Checker v%s — PHP %s Compatibility Report", $ver, $target ),
        );
        if ( '' !== $site_host ) {
            $lines[] = 'Site: ' . $site_host;
        }
        if ( '' !== $timestamp ) {
            $lines[] = 'Generated: ' . $timestamp;
        }
        $lines[] = 'Running PHP: ' . PHP_VERSION . ' | Target PHP: ' . $target;
        $lines[] = str_repeat( '=', 64 );
        $lines[] = '';

        foreach ( $rows as $r ) {
            $lines[] = sprintf( '[%s] %s — %s', $r['status'], $r['area'], $r['label'] );
            $lines[] = 'Current value: ' . self::strip_links( $r['current'] );
            $lines[] = 'What this means: ' . self::strip_links( $r['meaning'] );
            $lines[] = 'Suggested next steps: ' . self::strip_links( $r['next_steps'] );

            if ( ! empty( $r['findings'] ) && is_array( $r['findings'] ) ) {
                $row_target = isset( $r['target'] ) && '' !== $r['target'] ? (string) $r['target'] : $target;
                $own_label  = isset( $r['comp_kind'] ) && 'theme' === $r['comp_kind'] ? 'theme code' : 'plugin code';
                foreach ( $r['findings'] as $f ) {
                    $ft = isset( $f['type'] ) ? strtoupper( (string) $f['type'] ) : 'ISSUE';
                    if ( 'NOTE' === $ft && 'No issue observed' === $r['status'] ) {
                        continue;
                    }
                    $f_file   = isset( $f['file'] ) ? (string) $f['file'] : '';
                    $f_origin = isset( $f['origin'] ) && 'bundled' === $f['origin']
                        ? 'bundled dependency'
                        : ( 'bundled' === THRG_PC_Results::file_origin( $f_file ) ? 'bundled dependency' : $own_label );
                    $f_source = isset( $f['source'] ) && '' !== (string) $f['source'] ? ' [Rule: ' . $f['source'] . ']' : '';
                    $lines[]  = sprintf(
                        '  - [%s | PHP %s | %s] %s:%d — %s%s',
                        $ft,
                        $row_target,
                        $f_origin,
                        $f_file,
                        isset( $f['line'] ) ? (int) $f['line'] : 0,
                        isset( $f['message'] ) ? $f['message'] : '',
                        $f_source
                    );
                }
            }
            $lines[] = '';
        }

        return implode( "\n", $lines );
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
        $running = ! empty( $state ) && ( ! empty( $state['current'] ) || ! empty( $state['queue'] ) );
        $target  = self::selected_target();

        wp_localize_script(
            'thrg-pc-checker',
            'thrgPcData',
            array(
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'pageUrl'      => admin_url( 'tools.php?page=' . self::SLUG ),
                'nonce'        => wp_create_nonce( 'thrg_pc_scan' ),
                'prescanNonce' => wp_create_nonce( 'thrg_pc_prescan' ),
                'running'      => $running,
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
     * AJAX: start a scan (all, active-only, or single component).
     *
     * @return void
     */
    public static function ajax_scan_start() {
        check_ajax_referer( 'thrg_pc_scan' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
        }
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
            THRG_PC_Scanner::clear_state();
            wp_send_json_success(
                array(
                    'done'        => true,
                    'total'       => 0,
                    'finished'    => 0,
                    'current'     => '',
                    'files_done'  => 0,
                    'files_total' => 0,
                    'target'      => $state['target'],
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
        }
        $reason = THRG_PC_Engine::unavailable_reason();
        if ( '' !== $reason ) {
            wp_send_json_error( array( 'message' => $reason ), 500 );
        }
        wp_send_json_success( THRG_PC_Scanner::step( microtime( true ) + 5.0 ) );
    }

    /**
     * AJAX: stop an active scan job.
     *
     * @return void
     */
    public static function ajax_scan_stop() {
        check_ajax_referer( 'thrg_pc_scan' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
        }
        THRG_PC_Scanner::clear_state();
        wp_send_json_success( array( 'stopped' => true ) );
    }

    /**
     * AJAX: run a time-boxed update pre-scan pass right now.
     *
     * @return void
     */
    public static function ajax_prescan_now() {
        check_ajax_referer( 'thrg_pc_prescan' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
        }
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
        }
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'thrg-php-checker' ), 403 );
        }
        check_admin_referer( 'thrg_pc_start' );

        $target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : self::default_target();
        if ( ! in_array( $target, self::scan_targets(), true ) ) {
            $target = self::default_target();
        }

        if ( ! empty( $_POST['stop'] ) ) {
            THRG_PC_Scanner::clear_state();
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
            THRG_PC_Scanner::start(
                $target,
                $rescan,
                $scope,
                $single_key,
                $user ? (int) $user->ID : 0,
                $user ? (string) $user->user_login : ''
            );
            THRG_PC_Scanner::step( microtime( true ) + 5.0 );
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'thrg-php-checker' ), 403 );
        }
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'thrg-php-checker' ), 403 );
        }
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'thrg-php-checker' ), 403 );
        }
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'thrg-php-checker' ), 403 );
        }
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'thrg-php-checker' ), 403 );
        }
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
            set_transient( $wait_key, 1, THRG_PC_Email::WAIT_SECONDS );
        }
        $back( $result['sent'] ? ( $result['failed'] ? 'mail-partial' : 'emailed' ) : 'mail-failed', $result['sent'], $result['failed'] );
    }
}

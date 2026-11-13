<?php
/** PHP lifecycle, observation evaluation, and report formatting.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

trait THRG_PC_Report_Methods {
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

        $fatal_errors = 0;
        foreach ( $items as $f ) {
            if ( isset( $f['type'] ) && 'error' === $f['type'] && THRG_PC_Results::is_fatal_rule( isset( $f['source'] ) ? $f['source'] : '' ) ) {
                ++$fatal_errors;
            }
        }

        if ( 'breaks' === $verdict ) {
            if ( $fatal_errors > 0 ) {
                $meaning = sprintf(
                    '%1$s (%2$s, v%3$s) contains %4$d unguarded PHP %5$s error(s). If those code paths execute on PHP %5$s, PHP can throw a fatal error or halt the request.',
                    $name,
                    strtolower( $state_label ),
                    $version,
                    $errors,
                    $target
                );
            } else {
                $meaning = sprintf(
                    '%1$s (%2$s, v%3$s) contains %4$d unguarded PHP %5$s compatibility error(s) (removed INI directives or behavioral changes). These will not throw a fatal crash on PHP %5$s, though affected code paths may behave differently.',
                    $name,
                    strtolower( $state_label ),
                    $version,
                    $errors,
                    $target
                );
            }
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
                $na = ( isset( $a['source'] ) && 'PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue.NeedsInspection' === $a['source'] ) ? 1 : 0;
                $nb = ( isset( $b['source'] ) && 'PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue.NeedsInspection' === $b['source'] ) ? 1 : 0;
                if ( $na !== $nb ) {
                    return $na - $nb;
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
            $rule_ver     = THRG_PC_Results::rule_php_version( $msg_str, $source, $target );
            $rule_str     = '' !== $source ? $msg_str . ' [' . $source . ']' : $msg_str;
            $table_rows[] = array(
                strtoupper( $ft ) . ' (' . $rule_ver . ')',
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
            'fatal_errors'        => $fatal_errors,
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
}

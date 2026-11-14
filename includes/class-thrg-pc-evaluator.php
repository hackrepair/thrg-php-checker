<?php
/** Compatibility report collector and evaluator.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

/** Collects scan/environment inputs and builds prioritized compatibility findings. */
final class THRG_PC_Evaluator {

    /**
     * Collect live site inputs for PHP compatibility evaluation.
     *
     * @param string $target Optional target PHP version.
     * @return array
     */
    public static function collect( $target = '' ) {
        $target = in_array( (string) $target, THRG_PHP_Checker::scan_targets(), true ) ? (string) $target : THRG_PHP_Checker::selected_target();
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

        $settings    = THRG_PHP_Checker::settings();
        $paused_plug = function_exists( 'wp_paused_plugins' ) ? (array) wp_paused_plugins()->get_all() : array();
        $paused_thm  = function_exists( 'wp_paused_themes' ) ? (array) wp_paused_themes()->get_all() : array();
        $evidence    = array();
        foreach ( $comps as $c ) {
            if ( empty( $c['kind'] ) || empty( $c['slug'] ) ) {
                continue;
            }
            $slug   = (string) $c['slug'];
            $short  = 'theme' === $c['kind'] ? $slug : ( '.' === dirname( $slug ) ? basename( $slug, '.php' ) : dirname( $slug ) );
            $paused = 'theme' === $c['kind'] ? isset( $paused_thm[ $short ] ) : isset( $paused_plug[ $short ] );
            $active = ! empty( $c['active'] ) || ! empty( $c['network_active'] ) || ! empty( $c['parent_theme'] ) || 'mu-plugin' === $c['kind'];

            $evidence[ THRG_PC_Results::key( $c['kind'], $slug ) ] = THRG_PC_Grade::evidence( $active, THRG_PHP_Checker::running_php(), $target, ! empty( $settings['runtime_log'] ), $paused );
        }

        return array(
            'evidence'        => $evidence,
            'php_version'     => PHP_VERSION,
            'running_php'     => THRG_PHP_Checker::running_php(),
            'target'          => $target,
            'engine_reason'   => THRG_PC_Engine::unavailable_reason(),
            'components'      => $comps,
            'results'         => $rows,
            'scope'           => 'active' === THRG_PHP_Checker::settings()['default_scope'] ? 'active' : 'all',
            'prescan_enabled' => ! empty( THRG_PHP_Checker::settings()['prescan_updates'] ),
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
        $target      = isset( $o['target'] ) && in_array( (string) $o['target'], THRG_PHP_Checker::scan_targets(), true ) ? (string) $o['target'] : '8.4';
        $comps       = isset( $o['components'] ) && is_array( $o['components'] ) ? $o['components'] : array();
        $results     = isset( $o['results'] ) && is_array( $o['results'] ) ? $o['results'] : array();
        $mig_link    = THRG_PC_Server_Advisory::migration_link_key( $target );

        $findings = array();

        // 1. Server PHP version, EOL status, and security patch level.
        $php            = THRG_PC_Server_Advisory::php_status( $php_version, $now );
        $php_patch      = THRG_PC_Server_Advisory::php_patch( $php_version, $now );
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
        $scoped_comps = $comps;
        if ( 'active' === ( isset( $o['scope'] ) ? $o['scope'] : 'all' ) ) {
            $scoped_comps = array_values(
                array_filter(
                    $comps,
                    static function ( $c ) {
                        return ! empty( $c['active'] );
                    }
                )
            );
        }
        $total_comps  = count( $scoped_comps );
        $scanned_keys = array();
        foreach ( $results as $r ) {
            if ( isset( $r['kind'], $r['slug'] ) ) {
                $scanned_keys[ THRG_PC_Results::key( $r['kind'], $r['slug'] ) ] = true;
            }
        }
        $scanned_comps = 0;
        foreach ( $scoped_comps as $c ) {
            if ( isset( $scanned_keys[ THRG_PC_Results::key( $c['kind'], $c['slug'] ) ] ) ) {
                ++$scanned_comps;
            }
        }
        $overview_row = null;

        if ( ! empty( $o['engine_reason'] ) ) {
            $overview_row = array(
                'id'         => 'scan-overview',
                'area'       => 'Scanner',
                'label'      => 'Scanner engine unavailable',
                'title'      => 'Scanner engine unavailable',
                'coverage'   => 'not_scanned',
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
        } elseif ( $scanned_comps < $total_comps ) {
            $overview_current = sprintf( '%d of %d installed components scanned for PHP %s', $scanned_comps, $total_comps, $target );
            $overview_meaning = sprintf(
                'The scan for PHP %1$s does not cover every installed component. This site has %2$d installed plugins, must-use plugins and themes.',
                $target,
                $total_comps
            );
            $overview_next    = sprintf( 'Click Scan above to inspect installed plugins and themes against PHP %s.', $target );
            $overview_row     = array(
                'id'         => 'scan-overview',
                'area'       => 'Getting Started',
                'coverage'   => $scanned_comps ? 'partial' : 'not_scanned',
                'label'      => sprintf( 'Complete the scan for PHP %s', $target ),
                'title'      => sprintf( 'Ready to scan for PHP %s', $target ),
                'status'     => 'Review',
                'badge_text' => $scanned_comps ? sprintf( '%d of %d scanned', $scanned_comps, $total_comps ) : 'Not scanned yet',
                'nav_hint'   => $scanned_comps ? sprintf( '%d/%d', $scanned_comps, $total_comps ) : '0 scanned',
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
            $u_pending = 0;
            $guard_ver = isset( $o['running_php'] ) ? (string) $o['running_php'] : THRG_PHP_Checker::running_php();

            foreach ( $update_items as $u ) {
                $guard_ver = isset( $u['guard'] ) ? (string) $u['guard'] : $guard_ver;
                if ( ! empty( $u['result'] ) && is_array( $u['result'] ) ) {
                    ++$u_checked;
                    $uv = THRG_PC_Results::update_assessment( $u['result'] );
                    if ( 'pending' === $uv ) {
                        ++$u_pending;
                    }
                    if ( in_array( $uv, array( 'still_fails', 'needs_review' ), true ) ) {
                        ++$u_breaks;
                        $u_lines[] = sprintf( '%s %s (%d error(s) on PHP %s)', $u['slug'], $u['new_version'], (int) $u['result']['errors'], $guard_ver );
                    } elseif ( in_array( $uv, array( 'warnings_only', 'pending' ), true ) ) {
                        ++$u_warn;
                        $u_lines[] = sprintf( '%s %s (review findings or incomplete evidence for PHP %s)', $u['slug'], $u['new_version'], $guard_ver );
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
                        ? 'Offered update(s) need review or a completed scan for PHP ' . $guard_ver . ': ' . implode( '; ', $u_lines ) . '.'
                        : sprintf( '%d of %d pending update(s) have been pre-scanned so far against PHP %s.', $u_checked, count( $update_items ), $guard_ver );
                    $u_next    = $u_checked < count( $update_items )
                        ? 'Click Check Pending Updates in Settings below to finish inspecting remaining update packages.'
                        : 'Review the evidence and complete any partial scans before updating on production.';
                } else {
                    $u_status  = 'No issue observed';
                    $u_badge   = 'No counted update findings';
                    $u_hint    = 'Clean';
                    $u_current = sprintf( 'All %d pending update(s) passed static checks for PHP %s', $u_checked, $guard_ver );
                    $u_meaning = sprintf( 'Every currently offered plugin and theme update zip was inspected in memory and reported 0 errors or warnings for PHP %s.', $guard_ver );
                    $u_next    = 'Test affected features before relying on these pending updates.';
                }

                $update_row = array(
                    'id'           => 'update-checks',
                    'coverage'     => $u_pending || $u_checked < count( $update_items ) ? 'partial' : 'complete',
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
            $ev_key = THRG_PC_Results::key( isset( $r['kind'] ) ? $r['kind'] : 'plugin', isset( $r['slug'] ) ? $r['slug'] : '' );
            $ev     = isset( $o['evidence'][ $ev_key ] ) && is_array( $o['evidence'][ $ev_key ] ) ? $o['evidence'][ $ev_key ] : array();
            $card   = self::component_finding( $r, $target, $mig_link, $ev );
            if ( 'breaks' === $card['verdict'] ) {
                $comp_breaks[] = $card;
            } elseif ( 'ok' === $card['verdict'] ) {
                $comp_clean[] = $card;
            } else {
                $comp_warnings[] = $card;
            }
        }

        // Worst grade first within the red group; the earlier verdict/active/name order breaks ties.
        usort(
            $comp_breaks,
            static function ( $a, $b ) {
                return (int) $a['grade'] <=> (int) $b['grade'];
            }
        );

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
    private static function component_finding( array $r, $target, $mig_link, array $evidence = array() ) {
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
            $counts[] = sprintf( '%d %s not fully analyzed', $skipped, 1 === $skipped ? 'file' : 'files' );
        }
        if ( ! $counts ) {
            $counts[] = '0 errors, 0 warnings';
        }
        if ( $notes > 0 ) {
            $counts[] = sprintf( '%d guarded/polyfill %s filtered', $notes, 1 === $notes ? 'note' : 'notes' );
        }

        $fatal_errors = 0;
        $own_errors   = 0;
        foreach ( $items as $f ) {
            if ( ! isset( $f['type'] ) || 'error' !== $f['type'] ) {
                continue;
            }
            if ( THRG_PC_Results::is_fatal_rule( isset( $f['source'] ) ? $f['source'] : '' ) ) {
                ++$fatal_errors;
            }
            $f_file = isset( $f['file'] ) ? (string) $f['file'] : '';
            $f_orig = '' !== $f_file
                ? THRG_PC_Results::file_origin( $f_file )
                : ( isset( $f['origin'] ) && 'bundled' === $f['origin'] ? 'bundled' : 'own' );
            if ( 'bundled' !== $f_orig ) {
                ++$own_errors;
            }
        }

        // Compatibility grade 1-7: how likely the findings are to break a site (see THRG_PC_Grade).
        $evidence       = array_merge(
            array(
                'marker'            => false,
                'strong'            => false,
                'target'            => $target,
                'coverage'          => $skipped > 0 ? 'partial' : 'complete',
                'expected_errors'   => $errors,
                'expected_warnings' => $warnings,
                'boot_files'        => isset( $r['boot_files'] ) && is_array( $r['boot_files'] ) ? $r['boot_files'] : array(),
            ),
            $evidence
        );
        $graded         = THRG_PC_Results::classification( $r );
        $grade          = (int) $graded['grade'];
        $unknown_errors = (int) $graded['unknown'];
        $fatal_errors   = (int) $graded['fatal'];
        $grade_why      = '';
        if ( 'breaks' === $verdict && $grade > 6 ) {
            $grade = 2;
        } elseif ( 'warnings' === $verdict ) {
            $grade = 6;
        } elseif ( 'breaks' !== $verdict ) {
            $grade = 7;
        }
        $bundled_only = $errors > 0 && 0 === $own_errors;
        if ( 'breaks' === $verdict ) {
            $status = THRG_PC_Grade::status( $grade );
        }
        $evidence_label = ! empty( $evidence['marker'] ) && in_array( $verdict, array( 'breaks', 'warnings' ), true )
            ? sprintf( 'Active on this site; server PHP %s', isset( $evidence['running_php'] ) ? (string) $evidence['running_php'] : PHP_VERSION )
            : '';

        if ( 'breaks' === $verdict ) {
            $err_prefix = $unknown_errors && ! $fatal_errors ? 'Compatibility error: impact unknown' : THRG_PC_Grade::label( $grade );
            $badge_text = sprintf( '%s · %d %s', $err_prefix, $errors, 1 === $errors ? 'error' : 'errors' );
            if ( $fatal_errors > 0 && $errors > $fatal_errors ) {
                $badge_text .= sprintf( ' (%d mapped as fatal)', $fatal_errors );
            }
            if ( $warnings > 0 ) {
                $badge_text .= sprintf( ', %d %s', $warnings, 1 === $warnings ? 'warning' : 'warnings' );
            }
            $nav_hint = sprintf( '%d %s', $errors, 1 === $errors ? 'error' : 'errors' );
        } elseif ( 'warnings' === $verdict ) {
            $badge_text = sprintf( '%d deprecation %s', $warnings, 1 === $warnings ? 'warning' : 'warnings' );
            $nav_hint   = sprintf( '%d warn', $warnings );
        } elseif ( 'partial' === $verdict ) {
            $badge_text = sprintf( '%d %s not fully analyzed', $skipped, 1 === $skipped ? 'file' : 'files' );
            $nav_hint   = 'Partial';
        } else {
            $badge_text = 'No issues detected';
            $nav_hint   = 'Clean';
        }

        $current = sprintf( '%s · %s · v%s', implode( ', ', $counts ), $state_label, $version );
        if ( '' !== $update_ver ) {
            $current .= sprintf( ' · Update %s available', $update_ver );
        }

        if ( 'breaks' === $verdict ) {
            $kind_word = 'theme' === $kind ? 'theme' : 'plugin';
            if ( 1 === $grade ) {
                $meaning      = sprintf(
                    '%1$s (%2$s, v%3$s) has %4$d potential fatal error(s) in a file identified as part of startup. Static include discovery does not prove every operation executes. Test this %6$s on PHP %5$s before relying on it.',
                    $name,
                    strtolower( $state_label ),
                    $version,
                    $errors,
                    $target,
                    $kind_word
                );
                $next_default = sprintf( 'Do not switch to PHP %1$s while %2$s is active. Check for an updated release, contact the developer with the file and line numbers below, or replace this %3$s first.', $target, $name, $kind_word );
            } elseif ( 2 === $grade && $unknown_errors && ! $fatal_errors ) {
                $meaning      = sprintf( '%s has scanner errors whose runtime impact is not mapped. Their priority is retained until the listed rules and affected features are reviewed.', $name );
                $next_default = sprintf( 'Review the listed rules and test %s on PHP %s before upgrading. Unknown impact does not establish compatibility.', $name, $target );
            } elseif ( 2 === $grade ) {
                $meaning      = sprintf(
                    '%1$s (%2$s, v%3$s) contains %4$d unguarded PHP %5$s error(s). If those code paths execute on PHP %5$s, PHP can throw a fatal error or halt the request.',
                    $name,
                    strtolower( $state_label ),
                    $version,
                    $errors,
                    $target
                );
                $next_default = sprintf( 'Check for an updated release of %1$s, contact the developer with the file and line numbers below, or replace this %2$s before upgrading the server to PHP %3$s.', $name, $kind_word, $target );
            } else {
                $meaning      = sprintf(
                    '%1$s (%2$s, v%3$s) contains %4$d PHP %5$s compatibility error(s) (removed INI directives or behavioral changes). These will not throw a fatal crash on PHP %5$s, though affected code paths may behave differently.',
                    $name,
                    strtolower( $state_label ),
                    $version,
                    $errors,
                    $target
                );
                $next_default = sprintf( 'Review the listed settings or behaviour changes in %1$s and test the affected features on PHP %2$s.', $name, $target );
            }
            if ( '' !== $evidence_label ) {
                $meaning .= sprintf( ' This %2$s is active here. Activation does not establish that the affected feature was exercised for the PHP %1$s target.', $target, $kind_word );
            }
            if ( '' !== $update_ver ) {
                $next = sprintf(
                    'Version %1$s is available for %2$s — update the %3$s first and click Rescan on this card to check whether the author already resolved the PHP %4$s incompatibility.',
                    $update_ver,
                    $name,
                    $kind_word,
                    $target
                );
            } elseif ( ! $active ) {
                $next = sprintf(
                    '%1$s is currently inactive, so it cannot break front-end requests unless activated. Delete it if unused, or update/replace it before activating on PHP %2$s.',
                    $name,
                    $target
                );
            } else {
                $next = $next_default;
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
                : sprintf( 'Test %2$s on PHP %1$s before upgrading; keep it updated as future releases address these deprecations.', $target, $name );
        } elseif ( 'partial' === $verdict ) {
            $meaning = sprintf(
                '%1$s had 0 errors or warnings in the analyzed code, but %2$d PHP file check(s) could not finish. Review the recorded reasons before relying on this result.',
                $name,
                $skipped
            );
            $next    = 'Review the per-file reasons below. Validate syntax with the appropriate PHP runtime or inspect files that exceed the scan limits.';
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
                    ' %d finding(s) inside runtime version guards or polyfill fallbacks were excluded by static guard analysis for this target and retained as informational notes.',
                    $notes
                );
            }
            $next = 'No action required.';
        }

        if ( $unknown_errors && $fatal_errors ) {
            $meaning .= sprintf( ' %d additional scanner error(s) have unmapped runtime impact and also need review.', $unknown_errors );
        }
        if ( 'stale' === $graded['coverage'] ) {
            $meaning .= ' Some stored findings are no longer available for classification. Run a new scan to refresh the evidence.';
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
                    $assessment       = THRG_PC_Prescan::summarize_wporg_fix( $upd_res, $check_ver, $target );
                    $wporg_fix_status = $assessment['status'];
                    $labels           = array(
                        'fixed'         => 'No counted issues in v%s',
                        'warnings_only' => 'Review findings in v%s',
                        'still_fails'   => 'Fatal potential remains in v%s',
                        'needs_review'  => 'Unknown impact in v%s',
                        'pending'       => 'Incomplete evidence for v%s',
                    );
                    $wporg_fix_label  = sprintf( $labels[ $wporg_fix_status ], $check_ver );
                    $next             = $assessment['message'];

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
            'id'                     => 'comp-' . sanitize_title( $kind . '-' . $slug ),
            'area'                   => $area,
            'label'                  => $title,
            'title'                  => $title,
            'status'                 => $status,
            'badge_text'             => $badge_text,
            'nav_hint'               => $nav_hint,
            'current'                => $current,
            'why'                    => $meaning,
            'meaning'                => $meaning,
            'evidence'               => array( $current ),
            'table'                  => array(
                'head' => $table_rows ? array( 'Severity & Target', 'File : Line (Origin)', 'Issue & Triggered Rule' ) : array(),
                'rows' => $table_rows,
            ),
            'next'                   => 'No action required.' === $next ? '' : $next,
            'next_steps'             => $next,
            'links'                  => $comp_links,
            'target'                 => $target,
            'comp_kind'              => $kind,
            'comp_slug'              => $slug,
            'comp_key'               => THRG_PC_Results::key( $kind, $slug ),
            'comp_name'              => $name,
            'comp_version'           => $version,
            'comp_active'            => $active,
            'comp_network_active'    => $network,
            'comp_parent_theme'      => $parent,
            'comp_update_version'    => $update_ver,
            'wporg_fix_status'       => $wporg_fix_status,
            'wporg_fix_label'        => $wporg_fix_label,
            'verdict'                => $verdict,
            'errors'                 => $errors,
            'fatal_errors'           => $fatal_errors,
            'startup_errors'         => isset( $graded['counts'][1] ) ? (int) $graded['counts'][1] : 0,
            'unknown_errors'         => $unknown_errors,
            'classification'         => $graded,
            'own_errors'             => $own_errors,
            'bundled_only'           => $bundled_only,
            'grade'                  => $grade,
            'classification_version' => THRG_PC_Grade::CLASSIFICATION_VERSION,
            'coverage'               => $graded['coverage'],
            'grade_label'            => 'partial' === $verdict ? 'Scan incomplete' : ( $unknown_errors && ! $fatal_errors ? 'Compatibility error: impact unknown' : THRG_PC_Grade::label( $grade ) ),
            'grade_reason'           => $grade_why,
            'evidence_label'         => $evidence_label,
            'warnings'               => $warnings,
            'skipped'                => $skipped,
            'notes'                  => $notes,
            'own_issues'             => $own_issues,
            'bundled_issues'         => $bundled_issues,
            'files_total'            => $files_tot,
            'findings'               => $items,
            'scanned_at'             => isset( $r['scanned_at'] ) ? (int) $r['scanned_at'] : 0,
        );
    }

    /**
     * Resolve an allowlisted documentation link key or https:// URL.
     *
     * @param mixed $link Link key or URL.
     * @return array{label:string,url:string}|null
     */
}

<?php
/**
 * Email a saved PHP compatibility report to the site's administrators.
 *
 * Follows the THRG plugin email standard: [domain] [PHP Checker] Email Purpose.
 * Recipients are looked up server-side from administrator user IDs.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure email builders and WordPress administrator sender.
 */
final class THRG_PC_Email {

    const MAX_RECIPIENTS = 25;
    const NOTE_LENGTH    = 1000;
    const TABLE_ROWS     = 8;
    const WAIT_SECONDS   = 60;

    /**
     * Start a per-user send cooldown and record its key for uninstall cleanup.
     *
     * @param int $user_id Administrator user ID.
     * @return void
     */
    public static function mark_cooldown( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return;
        }

        $key = 'thrg_pc_mail_' . $user_id;
        if ( ! set_transient( $key, 1, self::WAIT_SECONDS ) ) {
            return;
        }

        $keys             = get_option( 'thrg_pc_mail_cooldowns', array() );
        $keys             = is_array( $keys ) ? $keys : array();
        $keys[ $user_id ] = $key;
        update_option( 'thrg_pc_mail_cooldowns', $keys, false );
    }

    /** Shown at the top of scheduled emails. */
    const SCHEDULED_INTRO = 'This is your scheduled PHP compatibility report from PHP Checker.';

    /** Shown in the footer of scheduled emails. */
    const SCHEDULED_FOOTER = 'You receive this email because an administrator scheduled it in Tools > PHP Checker, where it can be changed or turned off.';

    /**
     * Group findings into fix (Potential weakness), look (Review / Partial), and good (No issue observed).
     *
     * @param array[] $rows Evaluation rows.
     * @return array{fix:array[],look:array[],good:array[]}
     */
    public static function groups( array $rows ) {
        $out = array(
            'fix'    => array(),
            'look'   => array(),
            'good'   => array(),
            'server' => array(),
        );
        foreach ( $rows as $r ) {
            if ( isset( $r['id'] ) && 'php-version' === $r['id'] ) {
                $out['server'][] = $r;
                continue;
            }
            if ( 'Potential weakness' === $r['status'] ) {
                $out['fix'][] = $r;
            } elseif ( 'No issue observed' === $r['status'] ) {
                $out['good'][] = $r;
            } else {
                $out['look'][] = $r;
            }
        }
        return $out;
    }

    /**
     * Simple English pluralizer.
     *
     * @param int    $n    Count.
     * @param string $one  Singular noun.
     * @param string $many Plural noun.
     * @return string
     */
    private static function plural( $n, $one, $many ) {
        return $n . ' ' . ( 1 === (int) $n ? $one : $many );
    }

    /**
     * Format email send feedback distinguishing acceptance from delivery.
     *
     * @param int $accepted Accepted count.
     * @param int $failed   Failed count.
     * @return string
     */
    public static function feedback( $accepted, $failed ) {
        return sprintf(
            'Email accepted for sending: %d. Failed: %d. Acceptance does not confirm delivery.',
            max( 0, (int) $accepted ),
            max( 0, (int) $failed )
        );
    }

    /**
     * Site domain (including explicit port if present) from home_url() with site_url() fallback.
     *
     * @return string
     */
    public static function site_domain() {
        $url  = home_url( '/' );
        $host = wp_parse_url( $url, PHP_URL_HOST );
        $port = wp_parse_url( $url, PHP_URL_PORT );
        if ( ! $host ) {
            $url  = site_url( '/' );
            $host = wp_parse_url( $url, PHP_URL_HOST );
            $port = wp_parse_url( $url, PHP_URL_PORT );
        }
        return strtolower( (string) $host ) . ( $port ? ':' . (int) $port : '' );
    }

    /**
     * Build the subject line following the THRG email standard:
     * [domain] [PHP Checker] (Monthly )PHP X.Y Check: ...
     *
     * @param array[]    $rows      Findings.
     * @param string     $site      Site domain.
     * @param string     $target    Target PHP version ("8.4" style).
     * @param array|null $change    Output of THRG_PC_History::change().
     * @param bool       $scheduled True for scheduled monthly emails.
     * @return string
     */
    public static function subject( array $rows, $site, $target = '8.4', $change = null, $scheduled = false ) {
        $g    = self::groups( $rows );
        $fix  = count( $g['fix'] );
        $look = count( $g['look'] );
        $new  = is_array( $change ) && empty( $change['first'] ) ? (int) $change['new'] : 0;
        $done = is_array( $change ) && empty( $change['first'] ) ? (int) $change['resolved'] : 0;
        foreach ( $g['server'] as $server_row ) {
            if ( 'Potential weakness' === $server_row['status'] ) {
                ++$fix;
            } elseif ( 'No issue observed' !== $server_row['status'] ) {
                ++$look;
            }
        }
        $prefix = 'PHP ' . ( '' !== (string) $target ? (string) $target : '8.4' ) . ' Check';

        $health = THRG_PC_Grade::report_health( $rows );
        if ( $health['incomplete'] || $health['legacy'] ) {
            $s = $prefix . ': scan evidence needs review';
        } elseif ( $fix ) {
            $s = sprintf( '%s: %s %s attention', $prefix, self::plural( $fix, 'issue', 'issues' ), 1 === $fix ? 'needs' : 'need' );
            if ( $new ) {
                $s .= sprintf( ' (%d new since last check)', $new );
            }
        } elseif ( $look ) {
            $s = sprintf( '%s: %s to review', $prefix, self::plural( $look, 'item', 'items' ) );
        } else {
            $s = sprintf( '%s: no counted findings on %s', $prefix, self::plural( count( $rows ), 'item', 'items' ) );
        }
        if ( ! $fix && $done ) {
            $s .= sprintf( ' (%d no longer flagged since last check)', $done );
        }
        return '[' . $site . '] [PHP Checker] ' . ( $scheduled ? 'Scheduled ' : '' ) . $s;
    }

    /**
     * Opening narrative paragraph for the email.
     *
     * @param array[] $rows   Findings.
     * @param string  $site   Site domain.
     * @param string  $target Target PHP version.
     * @return string
     */
    public static function narrative( array $rows, $site, $target = '8.4' ) {
        $g       = self::groups( $rows );
        $notice  = THRG_PC_Text_Report::evidence_notice( $rows );
        $summary = sprintf( 'The component and scan findings for PHP %s on %s contain %d item(s) needing attention and %d item(s) needing review. Static analysis does not exercise site workflows; test important features before upgrading.', $target, $site, count( $g['fix'] ), count( $g['look'] ) );
        if ( $g['server'] ) {
            $summary .= ' Server PHP lifecycle checks are listed separately.';
        }
        return '' !== $notice ? $notice . ' ' . $summary : $summary;
    }

    /**
     * Summary line comparing with the previous saved report for the same target.
     *
     * @param array|null $change     Change summary.
     * @param string     $older_when Formatted date of older report.
     * @return string
     */
    public static function change_line( $change, $older_when = '' ) {
        if ( is_array( $change ) && isset( $change['reason'] ) ) {
            $messages = array(
                'rules_changed'       => 'Grading rules changed since the previous report; changes are not counted as fixes.',
                'target_changed'      => 'These reports use different PHP targets and cannot be compared.',
                'incomplete_evidence' => 'Evidence is incomplete or uses earlier grading rules; no resolved count is inferred.',
            );
            if ( isset( $messages[ $change['reason'] ] ) ) {
                return $messages[ $change['reason'] ];
            }
        }
        if ( ! is_array( $change ) || ! empty( $change['first'] ) ) {
            return 'This is the first saved report for this PHP target, so future emails will show what changed.';
        }
        $since = '' !== $older_when ? ' on ' . $older_when : '';
        if ( ! $change['new'] && ! $change['resolved'] ) {
            return 'Nothing has changed since the previous check' . $since . '.';
        }
        $parts = array();
        if ( $change['resolved'] ) {
            $parts[] = self::plural( $change['resolved'], 'item no longer flagged', 'items no longer flagged' );
        }
        if ( $change['new'] ) {
            $parts[] = self::plural( $change['new'], 'new issue', 'new issues' );
        }
        return 'Since the previous check' . $since . ': ' . implode( ', ', $parts ) . '.';
    }

    /**
     * Build a safe Reply-To header for the sender.
     *
     * @param string $name  Sender display name.
     * @param string $email Sender email.
     * @return string
     */
    public static function reply_to( $name, $email ) {
        $email = trim( (string) $email );
        if ( '' === $email || ! is_email( $email ) || preg_match( '/[\r\n,<>]/', $email ) ) {
            return '';
        }
        $name = trim( preg_replace( '/\s+/', ' ', str_replace( array( '"', ',', ';', '<', '>', '\\' ), ' ', (string) $name ) ) );
        return 'Reply-To: ' . ( '' !== $name ? '"' . $name . '" ' : '' ) . '<' . $email . '>';
    }

    /**
     * Escape HTML entities.
     *
     * @param string $text Input text.
     * @return string
     */
    private static function e( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
    }

    /**
     * Strip internal link tokens from text.
     *
     * @param string $text Input text.
     * @return string
     */
    private static function clean( $text ) {
        return THRG_PHP_Checker::strip_links( (string) $text );
    }

    /**
     * Extract up to TABLE_ROWS plain-string rows from a finding's table.
     *
     * @param array $table Finding table.
     * @return array[]
     */
    private static function table_rows( $table ) {
        $out = array();
        foreach ( array_slice( $table['rows'], 0, self::TABLE_ROWS ) as $cells ) {
            $row = array();
            foreach ( array_values( $cells ) as $c ) {
                $row[] = is_array( $c ) ? (string) $c[0] : (string) $c;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Render a single finding card as HTML for email.
     *
     * @param array  $r      Finding row.
     * @param string $accent Left border color.
     * @param bool   $full   Whether to include evidence and table rows.
     * @return string
     */
    private static function finding_html( array $r, $accent, $full ) {
        $h  = '<tr><td style="padding:0 0 18px 0;">';
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #ead9cc;border-left:4px solid ' . $accent . ';border-radius:6px;background:#ffffff;">';
        $h .= '<tr><td style="padding:14px 16px;">';
        $h .= '<div style="font-size:16px;font-weight:700;color:#1d2327;margin:0 0 6px;">' . self::e( $r['title'] ) . '</div>';
        if ( ! empty( $r['comp_key'] ) && ! empty( $r['grade'] ) && (int) $r['grade'] < 7 ) {
            $h .= '<div style="font-size:12px;font-weight:700;color:#8a4b00;margin:0 0 6px;">' . self::e( $r['grade_label'] . ( ! empty( $r['evidence_label'] ) ? ' · ' . $r['evidence_label'] : '' ) ) . '</div>';
        }
        foreach ( THRG_PC_Text_Report::classification_lines( $r ) as $line ) {
            $h .= '<div style="font-size:12px;color:#50575e;margin:0 0 4px;">' . self::e( $line ) . '</div>';
        }
        $h .= '<div style="font-size:14px;line-height:1.55;color:#3c434a;margin:0 0 10px;">' . self::e( self::clean( $r['why'] ) ) . '</div>';
        if ( $full && ! empty( $r['evidence'] ) ) {
            $h .= '<div style="background:#faf6f2;border-radius:4px;padding:8px 12px;margin:0 0 10px;font-size:13px;line-height:1.5;color:#50575e;">';
            foreach ( array_slice( $r['evidence'], 0, 3 ) as $line ) {
                $h .= '<div>' . self::e( self::clean( $line ) ) . '</div>';
            }
            $h .= '</div>';
        }
        if ( $full && ! empty( $r['table']['rows'] ) ) {
            $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:12px;margin:0 0 10px;">';
            $h .= '<tr>';
            foreach ( $r['table']['head'] as $head ) {
                $h .= '<th align="left" style="padding:5px 6px;background:#fff1e6;color:#1d2327;border-bottom:1px solid #ead9cc;">' . self::e( $head ) . '</th>';
            }
            $h .= '</tr>';
            foreach ( self::table_rows( $r['table'] ) as $cells ) {
                $h .= '<tr>';
                foreach ( $cells as $c ) {
                    $h .= '<td style="padding:5px 6px;border-bottom:1px solid #f0e6de;color:#3c434a;word-break:break-word;">' . self::e( $c ) . '</td>';
                }
                $h .= '</tr>';
            }
            $more = count( $r['table']['rows'] ) - self::TABLE_ROWS;
            if ( $more > 0 ) {
                $h .= '<tr><td colspan="' . count( $r['table']['head'] ) . '" style="padding:5px 6px;color:#646970;">…and ' . (int) $more . ' more in the full report.</td></tr>';
            }
            $h .= '</table>';
        }
        if ( '' !== (string) $r['next'] ) {
            $h .= '<div style="font-size:14px;line-height:1.55;color:#1d2327;border-left:3px solid #b34700;padding:2px 0 2px 10px;"><strong>What to do:</strong> ' . self::e( self::clean( $r['next'] ) ) . '</div>';
        }
        $h .= '</td></tr></table></td></tr>';
        return $h;
    }

    /**
     * Build the full HTML email body.
     *
     * @param array $a Arguments: rows, site, target, when, change, older_when, note, sender, recipient, view_url, scheduled.
     * @return string
     */
    public static function html( array $a ) {
        $a     += array(
            'scheduled' => false,
            'target'    => '8.4',
        );
        $rows   = $a['rows'];
        $g      = self::groups( $rows );
        $site   = (string) $a['site'];
        $target = (string) $a['target'];

        $h  = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::e( self::subject( $rows, $site, $target, $a['change'], $a['scheduled'] ) ) . '</title></head>';
        $h .= '<body style="margin:0;padding:0;background:#f3eee9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">';
        $h .= '<div style="display:none;max-height:0;overflow:hidden;">' . self::e( self::narrative( $rows, $site, $target ) ) . '</div>';
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3eee9;"><tr><td align="center" style="padding:24px 12px;">';
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">';

        $h .= '<tr><td style="background:#2b170d;border-radius:8px 8px 0 0;padding:22px 24px;">';
        $h .= '<div style="font-size:12px;font-weight:700;letter-spacing:1.5px;color:#ff9933;text-transform:uppercase;">The Hack Repair Guy&rsquo;s</div>';
        $h .= '<div style="font-size:26px;font-weight:700;color:#ffffff;margin:2px 0 6px;">PHP Checker</div>';
        $h .= '<div style="font-size:14px;color:#e8d8cc;">PHP ' . self::e( $target ) . ' readiness report for <strong style="color:#ffffff;">' . self::e( $site ) . '</strong> &middot; ' . self::e( $a['when'] ) . '</div>';
        $h .= '</td></tr>';

        $h    .= '<tr><td style="background:#ffffff;padding:24px;border:1px solid #ead9cc;border-top:0;">';
        $greet = '' !== (string) $a['recipient'] ? 'Hi ' . self::e( $a['recipient'] ) . ',' : 'Hi,';
        $h    .= '<p style="font-size:15px;line-height:1.6;color:#1d2327;margin:0 0 14px;">' . $greet . '</p>';

        if ( '' !== trim( (string) $a['note'] ) ) {
            $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;"><tr><td style="background:#fff8f2;border-left:4px solid #b34700;border-radius:4px;padding:12px 14px;font-size:14px;line-height:1.55;color:#1d2327;">';
            $h .= '<div style="font-size:12px;font-weight:700;color:#8f3a00;margin:0 0 4px;">A note from ' . self::e( $a['sender'] ) . '</div>' . nl2br( self::e( trim( $a['note'] ) ) );
            $h .= '</td></tr></table>';
        }

        if ( $a['scheduled'] ) {
            $h .= '<p style="font-size:14px;line-height:1.6;color:#50575e;margin:0 0 10px;">' . self::e( self::SCHEDULED_INTRO ) . '</p>';
        }
        $h .= '<p style="font-size:15px;line-height:1.6;color:#1d2327;margin:0 0 10px;">' . self::e( self::narrative( $rows, $site, $target ) ) . '</p>';
        $h .= '<p style="font-size:14px;line-height:1.6;color:#50575e;margin:0 0 18px;">' . self::e( self::change_line( $a['change'], $a['older_when'] ) ) . '</p>';

        $tiles = array(
            array( count( $g['fix'] ), 'need attention', '#fdecea', '#c62828' ),
            array( count( $g['look'] ), 'to review', '#fff4d6', '#8a5a00' ),
            array( count( $g['good'] ), 'no counted findings', '#e6f3ec', '#2e6b4f' ),
        );
        $h    .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:6px 0;margin:0 -6px 22px;"><tr>';
        foreach ( $tiles as $t ) {
            $h .= '<td width="33%" style="background:' . $t[2] . ';border-radius:6px;padding:12px 14px;color:' . $t[3] . ';"><div style="font-size:26px;font-weight:700;line-height:1.1;">' . (int) $t[0] . '</div><div style="font-size:13px;font-weight:600;">' . $t[1] . '</div></td>';
        }
        $h .= '</tr></table>';

        $sections = array(
            array( 'fix', 'Review before upgrading PHP', '#e53935', true ),
            array( 'look', 'Review findings and scan coverage', '#d39b1f', true ),
            array( 'server', 'Server PHP lifecycle', '#8a4b00', true ),
        );
        foreach ( $sections as $s ) {
            if ( ! $g[ $s[0] ] ) {
                continue;
            }
            $h .= '<div style="font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#8f3a00;margin:0 0 10px;">' . $s[1] . '</div>';
            $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
            foreach ( $g[ $s[0] ] as $r ) {
                $h .= self::finding_html( $r, $s[2], $s[3] );
            }
            $h .= '</table>';
        }

        if ( $g['good'] ) {
            $h .= '<div style="font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#2e6b4f;margin:4px 0 8px;">No counted findings (' . count( $g['good'] ) . ')</div>';
            $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">';
            foreach ( $g['good'] as $r ) {
                $h .= '<tr><td style="padding:5px 0;font-size:14px;line-height:1.5;color:#3c434a;"><span style="color:#2e6b4f;font-weight:700;">&#10003;</span> <strong style="color:#1d2327;">' . self::e( $r['title'] ) . '</strong> &mdash; ' . self::e( self::clean( $r['why'] ) ) . '</td></tr>';
                foreach ( THRG_PC_Text_Report::classification_lines( $r ) as $line ) {
                    $h .= '<tr><td style="padding:0 0 4px;font-size:12px;color:#50575e;">' . self::e( $line ) . '</td></tr>';
                }
            }
            $h .= '</table>';
        }

        $h .= '<div style="font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#8f3a00;margin:0 0 8px;">Why this matters</div>';
        $h .= '<p style="font-size:14px;line-height:1.6;color:#3c434a;margin:0 0 20px;">Running a supported PHP version gives your site active security fixes and faster performance, while checking plugins and themes ahead of time prevents surprise white-screens on upgrade day. Always test PHP upgrades on a staging site or take a full backup first.</p>';

        if ( '' !== (string) $a['view_url'] ) {
            $h .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 6px;"><tr><td style="background:#b34700;border-radius:4px;"><a href="' . self::e( $a['view_url'] ) . '" style="display:inline-block;padding:11px 20px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;">Open the full report</a></td></tr></table>';
            $h .= '<p style="font-size:12px;color:#646970;margin:0 0 4px;">You&rsquo;ll need to be logged in as an administrator.</p>';
        }
        $h .= '</td></tr>';

        $h .= '<tr><td style="padding:16px 24px;font-size:12px;line-height:1.6;color:#646970;">';
        $h .= 'Sent by ' . self::e( $a['sender'] ) . ' from ' . self::e( $site ) . ' with The Hack Repair Guy&rsquo;s PHP Checker. ';
        if ( $a['scheduled'] ) {
            $h .= self::e( self::SCHEDULED_FOOTER ) . ' ';
        }
        $h .= 'Static analysis reads code without executing it; it can miss dynamic issues or report false positives. ';
        $h .= 'Stuck on a tricky PHP upgrade? Jim and the team are happy to help &mdash; <a href="https://hackguard.com/" title="The Hack Repair Guy: WordPress security and hack repair" style="color:#8f3a00;">say hello at hackguard.com</a>.';
        $h .= '</td></tr></table></td></tr></table></body></html>';
        return $h;
    }

    /**
     * Build the plain-text alternative email body.
     *
     * @param array $a Arguments: rows, site, target, when, change, older_when, note, sender, recipient, view_url, scheduled.
     * @return string
     */
    public static function text( array $a ) {
        $a     += array(
            'scheduled' => false,
            'target'    => '8.4',
        );
        $rows   = $a['rows'];
        $g      = self::groups( $rows );
        $site   = (string) $a['site'];
        $target = (string) $a['target'];
        $out    = ( '' !== (string) $a['recipient'] ? 'Hi ' . $a['recipient'] . ',' : 'Hi,' ) . "\n\n";
        if ( '' !== trim( (string) $a['note'] ) ) {
            $out .= 'A note from ' . $a['sender'] . ":\n" . trim( $a['note'] ) . "\n\n";
        }
        $out .= 'PHP ' . $target . ' COMPATIBILITY REPORT: ' . $site . ' (' . $a['when'] . ")\n\n";
        if ( $a['scheduled'] ) {
            $out .= self::SCHEDULED_INTRO . "\n\n";
        }
        $out .= self::narrative( $rows, $site, $target ) . "\n" . self::change_line( $a['change'], $a['older_when'] ) . "\n\n";
        $out .= count( $g['fix'] ) . ' need attention · ' . count( $g['look'] ) . ' to review · ' . count( $g['good'] ) . " no counted findings\n";
        foreach ( array(
            'fix'    => 'REVIEW BEFORE UPGRADING PHP',
            'look'   => 'REVIEW FINDINGS AND SCAN COVERAGE',
            'server' => 'SERVER PHP LIFECYCLE',
        ) as $k => $label ) {
            if ( ! $g[ $k ] ) {
                continue;
            }
            $out .= "\n" . $label . "\n";
            foreach ( $g[ $k ] as $r ) {
                $out .= "\n- " . $r['title'] . "\n";
                if ( ! empty( $r['comp_key'] ) && ! empty( $r['grade'] ) && (int) $r['grade'] < 7 ) {
                    $out .= '  [' . $r['grade_label'] . ( ! empty( $r['evidence_label'] ) ? ' · ' . $r['evidence_label'] : '' ) . "]\n";
                }
                foreach ( THRG_PC_Text_Report::classification_lines( $r ) as $line ) {
                    $out .= '  ' . $line . "\n";
                }
                $out .= '  ' . self::clean( $r['why'] ) . "\n";
                foreach ( array_slice( $r['evidence'], 0, 3 ) as $line ) {
                    $out .= '  · ' . self::clean( $line ) . "\n";
                }
                if ( ! empty( $r['table']['rows'] ) ) {
                    foreach ( self::table_rows( $r['table'] ) as $cells ) {
                        $out .= '  * ' . implode( ' | ', array_filter( $cells, 'strlen' ) ) . "\n";
                    }
                }
                if ( '' !== (string) $r['next'] ) {
                    $out .= '  What to do: ' . self::clean( $r['next'] ) . "\n";
                }
            }
        }
        if ( $g['good'] ) {
            $out .= "\nNO COUNTED FINDINGS\n";
            foreach ( $g['good'] as $r ) {
                $out .= '✓ ' . $r['title'] . "\n";
                foreach ( THRG_PC_Text_Report::classification_lines( $r ) as $line ) {
                    $out .= '  ' . $line . "\n";
                }
            }
        }
        if ( '' !== (string) $a['view_url'] ) {
            $out .= "\nOpen the full report (administrator login required):\n" . $a['view_url'] . "\n";
        }
        $out .= "\n--\nSent by " . $a['sender'] . ' from ' . $site . " with The Hack Repair Guy's PHP Checker. " . ( $a['scheduled'] ? self::SCHEDULED_FOOTER . ' ' : '' ) . "Static analysis reads code without executing it. Stuck on a tricky PHP upgrade? Jim and the team are happy to help: https://hackguard.com/\n";
        return $out;
    }

    /**
     * Administrators of this site with a valid email address.
     *
     * @return array[] Each with id, name, email.
     */
    public static function recipients() {
        $users = get_users(
            array(
                'role'    => 'administrator',
                'number'  => self::MAX_RECIPIENTS,
                'orderby' => 'display_name',
                'fields'  => array( 'ID', 'display_name', 'user_email' ),
            )
        );
        $out   = array();
        foreach ( $users as $u ) {
            if ( is_email( $u->user_email ) ) {
                $out[] = array(
                    'id'    => (int) $u->ID,
                    'name'  => (string) $u->display_name,
                    'email' => (string) $u->user_email,
                );
            }
        }
        return $out;
    }

    /**
     * Send a saved report to chosen administrators.
     *
     * @param array      $entry     Saved report entry from THRG_PC_History.
     * @param array|null $older     Next older report for comparison.
     * @param int[]      $user_ids  Chosen administrator IDs.
     * @param string     $note      Optional note from sender.
     * @param array|null $from      Optional {name, email} sender for cron runs.
     * @param bool       $scheduled True for scheduled monthly runs.
     * @return array{sent:int,failed:int}
     */
    public static function send( array $entry, $older, array $user_ids, $note, $from = null, $scheduled = false ) {
        $wanted = array_map( 'intval', $user_ids );
        $to     = array_values(
            array_filter(
                self::recipients(),
                static function ( $r ) use ( $wanted ) {
                    return in_array( $r['id'], $wanted, true );
                }
            )
        );
        if ( ! is_array( $from ) ) {
            $user = wp_get_current_user();
            $from = array(
                'name'  => $user->display_name ? $user->display_name : $user->user_login,
                'email' => $user->user_email,
            );
        }
        $target  = isset( $entry['target'] ) && '' !== (string) $entry['target'] ? (string) $entry['target'] : THRG_PHP_Checker::default_target();
        $page    = admin_url( THRG_PHP_Checker::PAGE_URL );
        $fmt     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        $change  = THRG_PC_History::change( $entry, $older );
        $base    = array(
            'rows'       => $entry['rows'],
            'target'     => $target,
            'site'       => self::site_domain(),
            'when'       => wp_date( $fmt, (int) $entry['time'] ),
            'change'     => $change,
            'older_when' => is_array( $older ) ? wp_date( get_option( 'date_format' ), (int) $older['time'] ) : '',
            'note'       => (string) $note,
            'sender'     => (string) $from['name'],
            'view_url'   => '' !== (string) $entry['id'] ? add_query_arg( 'thrg_report', $entry['id'], $page ) : $page,
            'scheduled'  => (bool) $scheduled,
        );
        $subject = self::subject( $entry['rows'], $base['site'], $target, $change, $scheduled );
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $reply   = self::reply_to( $base['sender'], (string) $from['email'] );
        if ( '' !== $reply ) {
            $headers[] = $reply;
        }

        $sent = 0;
        foreach ( $to as $r ) {
            $args = $base + array( 'recipient' => $r['name'] );
            $alt  = self::text( $args );
            $hook = static function ( $mailer ) use ( $alt ) {
                $mailer->AltBody = $alt; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
            };
            add_action( 'phpmailer_init', $hook );
            if ( wp_mail( $r['email'], $subject, self::html( $args ), $headers ) ) {
                ++$sent;
            }
            remove_action( 'phpmailer_init', $hook );
        }
        return array(
            'sent'   => $sent,
            'failed' => count( $to ) - $sent,
        );
    }
}

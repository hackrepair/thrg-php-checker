<?php
/** Link-token resolver and plain-text report formatter.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

/** Formats allowlisted link tokens and plain-text compatibility reports. */
final class THRG_PC_Text_Report {

    public static function href( $link ) {
        if ( is_string( $link ) && isset( THRG_PHP_Checker::LINKS[ $link ] ) ) {
            return THRG_PHP_Checker::LINKS[ $link ];
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
        $ver    = '' !== $version ? $version : THRG_PHP_Checker::version();
        $target = '' !== $target ? $target : THRG_PHP_Checker::selected_target();
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

<?php
/** Finding table row partial.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template scope.
?>
<?php
$itype   = isset( $issue['type'] ) ? (string) $issue['type'] : 'warning';
$ifile   = isset( $issue['file'] ) ? (string) $issue['file'] : '';
$iline   = isset( $issue['line'] ) ? (int) $issue['line'] : 0;
$isource = isset( $issue['source'] ) ? (string) $issue['source'] : '';
$imsg    = isset( $issue['message'] ) ? (string) $issue['message'] : '';
$iorigin = isset( $issue['origin'] ) ? (string) $issue['origin'] : THRG_PC_Results::file_origin( $ifile );
$irulev  = THRG_PC_Results::rule_php_version( $imsg, $isource, $row_target_label );
$use_col = ! empty( $thrg_pc_row_cols );
?>
<tr>
    <td<?php echo $use_col ? ' class="thrg-pc-col-sev"' : ''; ?>>
        <span class="thrg-pc-sev thrg-pc-sev--<?php echo esc_attr( $itype ); ?>">
            <?php echo esc_html( strtoupper( $itype ) ); ?>
        </span>
        <span class="thrg-pc-target-tag"><?php echo esc_html( $irulev ); ?></span>
    </td>
    <td<?php echo $use_col ? ' class="thrg-pc-col-loc"' : ''; ?>>
        <code><?php echo esc_html( $iline > 0 ? $ifile . ':' . $iline : $ifile ); ?></code>
        <span class="thrg-pc-origin-badge thrg-pc-origin-badge--<?php echo esc_attr( $iorigin ); ?>">
            <?php echo 'bundled' === $iorigin ? esc_html__( 'Bundled dependency', 'thrg-php-checker' ) : esc_html( $own_origin_text ); ?>
        </span>
    </td>
    <td<?php echo $use_col ? ' class="thrg-pc-col-msg"' : ''; ?>>
        <div><?php echo esc_html( $imsg ); ?></div>
        <?php if ( '' !== $isource ) : ?>
            <div class="thrg-pc-rule-meta">
                <?php esc_html_e( 'Rule:', 'thrg-php-checker' ); ?>
                <code><?php echo esc_html( $isource ); ?></code>
            </div>
        <?php endif; ?>
    </td>
</tr>

<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

<?php
/** PHP Checker footer panel.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template scope.
?>
    <footer class="thrg-footer thrg-pc-coverage">
        <div class="thrg-pc-coverage__col thrg-pc-coverage__help">
            <img class="thrg-pc-mascot" src="<?php echo esc_url( plugins_url( 'assets/brand/thrg-heart-128.png', THRG_PHP_CHECKER_FILE ) ); ?>" alt="" width="44" height="44" />
            <p>
                <?php esc_html_e( 'Need help with a PHP upgrade or a compatibility problem this scan found?', 'thrg-php-checker' ); ?><br />
                <?php esc_html_e( 'Contact', 'thrg-php-checker' ); ?>
                <a href="https://hackguard.com/" title="<?php esc_attr_e( 'The Hack Repair Guy: WordPress security and hack repair', 'thrg-php-checker' ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'The Hack Repair Guy', 'thrg-php-checker' ); ?>
                    <?php echo wp_kses_post( THRG_PHP_Checker::NEW_TAB_HINT ); ?>
                </a>
            </p>
        </div>
    </footer>
<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

<?php
/** PHP Checker footer panel.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template scope.
?>
    <footer class="thrg-footer thrg-pc-coverage">
        <div class="thrg-pc-coverage__col">
            <p>
                <strong>
                    <a href="https://hackguard.com/wordpress-managed-services.html#php-upgrade" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e( 'Hands-Off PHP 8.2 / 8.3 Upgrade Service ($79 Flat)', 'thrg-php-checker' ); ?>
                        <?php echo wp_kses_post( THRG_PHP_Checker::NEW_TAB_HINT ); ?>
                    </a>
                </strong>
                —
                <?php esc_html_e( 'Worried about breaking your site when switching PHP versions? We\'ll review your plugins and theme, sort out compatibility issues, update your hosting PHP version for you, and test your live pages and forms so you don\'t have to.', 'thrg-php-checker' ); ?>
            </p>
        </div>
        <div class="thrg-pc-coverage__col thrg-pc-coverage__help">
            <img class="thrg-pc-mascot" src="<?php echo esc_url( plugins_url( 'assets/brand/thrg-heart-128.png', THRG_PHP_CHECKER_FILE ) ); ?>" alt="" width="44" height="44" />
            <p>
                <?php esc_html_e( 'Need a deeper look or someone to assist with PHP upgrades and security?', 'thrg-php-checker' ); ?><br />
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

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
            <p>&#9749; <?php echo wp_kses_post( __( 'Hi, I&rsquo;m Jim. I made this! If PHP Checker saved you some worry,<br />I&rsquo;d be so grateful if you&rsquo;d <a href="https://hackrepair.com/donations/buy-jim-a-coffee" target="_blank" rel="noopener noreferrer">buy me a cup of coffee</a>. Thank you!', 'thrg-php-checker' ) ); ?></p>
        </div>
        <div class="thrg-pc-coverage__col thrg-pc-coverage__help">
            <img class="thrg-pc-mascot" src="<?php echo esc_url( plugins_url( 'assets/brand/thrg-heart-128.png', THRG_PHP_CHECKER_FILE ) ); ?>" alt="" width="44" height="44" />
            <p>
                <?php esc_html_e( 'Stuck on a PHP upgrade or a problem this scan found? I\'d be glad to help.', 'thrg-php-checker' ); ?><br />
                <?php esc_html_e( 'Contact Jim at', 'thrg-php-checker' ); ?>
                <a href="https://hackguard.com/" title="<?php esc_attr_e( 'The Hack Repair Guy: WordPress security and hack repair', 'thrg-php-checker' ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'The Hack Repair Guy', 'thrg-php-checker' ); ?>
                    <?php echo wp_kses_post( THRG_PHP_Checker::NEW_TAB_HINT ); ?>
                </a>
            </p>
        </div>
    </footer>
<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

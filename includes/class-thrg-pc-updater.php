<?php
/**
 * Update checks against this plugin's GitHub releases.
 *
 * @package THRG_PHP_Checker
 */

defined( 'ABSPATH' ) || exit;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api;

/**
 * Points WordPress at GitHub releases for this plugin's updates.
 */
final class THRG_PC_Updater {

    const REPOSITORY_URL = 'https://github.com/hackrepair/thrg-php-checker/';
    const BRANCH         = 'main';
    const ASSET_PATTERN  = '/^thrg-php-checker\.[0-9]+(?:\.[0-9]+){2}\.zip$/';

    /**
     * Underlying update checker instance.
     *
     * @var \YahnisElsts\PluginUpdateChecker\v5\Vcs\Api|null
     */
    private $checker = null;

    /**
     * Build and configure the update checker.
     *
     * @param string $plugin_file Absolute path to the main plugin file.
     * @return void
     */
    public function init( $plugin_file ) {
        if ( ! class_exists( PucFactory::class ) ) {
            return;
        }

        $this->checker = PucFactory::buildUpdateChecker( self::REPOSITORY_URL, $plugin_file, basename( dirname( $plugin_file ) ) );
        $this->checker->setBranch( self::BRANCH );

        $api = $this->checker->getVcsApi();

        if ( ! $api || ! method_exists( $api, 'enableReleaseAssets' ) ||
            ! method_exists( $api, 'setReleaseFilter' ) || ! method_exists( $this->checker, 'addFilter' ) ) {
            $this->checker->removeHooks();
            $this->checker = null;
            return;
        }

        require_once __DIR__ . '/class-thrg-pc-update-notices.php';
        new THRG_PC_Update_Notices( $this->checker );

        $api->enableReleaseAssets( self::ASSET_PATTERN, Api::REQUIRE_RELEASE_ASSETS );

        $this->checker->addFilter(
            'vcs_update_detection_strategies',
            static function ( $strategies ) {
                return isset( $strategies[ Api::STRATEGY_LATEST_RELEASE ] )
                    ? array( Api::STRATEGY_LATEST_RELEASE => $strategies[ Api::STRATEGY_LATEST_RELEASE ] )
                    : array();
            }
        );

        $api->setReleaseFilter(
            static function ( $version, $release ) {
                if ( ! is_string( $version ) || ! preg_match( '/^[0-9]+(?:\.[0-9]+){2}$/', $version ) ||
                    ! isset( $release->tag_name, $release->assets ) ||
                    'v' . $version !== $release->tag_name || ! is_array( $release->assets ) ) {
                    return false;
                }

                $expected = 'thrg-php-checker.' . $version . '.zip';
                foreach ( $release->assets as $asset ) {
                    if ( isset( $asset->name ) && is_string( $asset->name ) &&
                        preg_match( self::ASSET_PATTERN, $asset->name ) ) {
                        return $expected === $asset->name;
                    }
                }

                return false;
            },
            Api::RELEASE_FILTER_SKIP_PRERELEASE,
            1
        );

        $this->checker->addFilter( 'request_info_result', array( __CLASS__, 'clean_info' ), 20, 2 );
    }

    /**
     * HTML tags and attributes allowed in update details: WordPress's post allowlist without class attributes.
     *
     * @return array
     */
    public static function allowed_html() {
        $allowed = wp_kses_allowed_html( 'post' );
        foreach ( $allowed as $tag => $attributes ) {
            if ( is_array( $attributes ) ) {
                unset( $allowed[ $tag ]['class'] );
            }
        }
        return $allowed;
    }

    /**
     * Sanitize every text section of the plugin information built from GitHub release notes or the readme.
     *
     * @param mixed $info Plugin information object from the update checker.
     * @return mixed
     */
    public static function clean_info( $info ) {
        if ( ! is_object( $info ) || ! isset( $info->sections ) || ! is_array( $info->sections ) ) {
            return $info;
        }
        $allowed = self::allowed_html();
        foreach ( $info->sections as $name => $html ) {
            if ( is_string( $html ) ) {
                $info->sections[ $name ] = wp_kses( $html, $allowed );
            }
        }
        return $info;
    }

    /**
     * Expose the underlying checker instance.
     *
     * @return \YahnisElsts\PluginUpdateChecker\v5\Vcs\Api|null
     */
    public function checker() {
        return $this->checker;
    }
}

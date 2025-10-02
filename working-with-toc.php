<?php
/**
 * Plugin Name: Working with TOC
 * Plugin URI:   https://wordpress.org/plugins/working-with-toc/
 * Description: Table of Contents plugin with structured data controls and Rank Math / Yoast SEO compatibility.
 * Version:     1.0.0
 * Author:      Alfonso Vertucci - Working with Web
 * Author URI:  https://workingwithweb.it/webagency
 * Icon URI:    assets/images/www-logo.png
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: working-with-toc
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Working_With_TOC
 */

namespace Working_With_TOC {

    defined( 'ABSPATH' ) || exit;

    if ( ! defined( 'WWTOC_VERSION' ) ) {
        define( 'WWTOC_VERSION', '1.0.0' );
    }

    define( 'WWT_TOC_PLUGIN_FILE', __FILE__ );
    define( 'WWT_TOC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
    define( 'WWT_TOC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

    require_once WWT_TOC_PLUGIN_DIR . 'includes/class-autoloader.php';
    require_once WWT_TOC_PLUGIN_DIR . 'includes/functions-dependencies.php';

    Autoloader::register();

    global $wwt_toc_missing_domdocument;

    if ( ! isset( $wwt_toc_missing_domdocument ) ) {
        $wwt_toc_missing_domdocument = false;
    }

    if ( ! class_exists( '\\DOMDocument' ) ) {
        $wwt_toc_missing_domdocument = true;
        add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_domdocument_notice' );
        return;
    }

    function working_with_toc() {
        static $plugin = null;

        if ( null === $plugin ) {
            $plugin = new Plugin();
        }

        return $plugin;
    }

    /**
     * Run tasks on plugin activation.
     */
    function working_with_toc_activate(): void {
        working_with_toc()->ensure_capability();
    }

    \register_activation_hook( __FILE__, __NAMESPACE__ . '\\working_with_toc_activate' );

    working_with_toc()->init();
}

namespace {

    if ( ! function_exists( 'working_with_toc' ) ) {
        /**
         * Provide a global helper for backward compatibility.
         *
         * @return \Working_With_TOC\Plugin
         */
        function working_with_toc() {
            return \Working_With_TOC\working_with_toc();
        }
    }
}

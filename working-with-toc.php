<?php
/**
 * Plugin Name: Working with TOC
 * Plugin URI:   https://wordpress.org/plugins/working-with-toc/
 * Description: Table of Contents plugin with structured data controls and Rank Math / Yoast SEO compatibility.
 * Version:     1.0.0
 * Author:      Alfonso Vertucci - Working with Web
 * Author URI:  https://workingwithweb.it/webagency
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

    Autoloader::register();

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

        global $wpdb;

        $installed_version = get_option( 'wwtoc_db_version' );
        $current_version   = WWTOC_VERSION;

        if ( $installed_version !== $current_version ) {
            $table_name      = $wpdb->prefix . 'working_with_toc';
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            title text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            \dbDelta( $sql );

            update_option( 'wwtoc_db_version', $current_version );
        }
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

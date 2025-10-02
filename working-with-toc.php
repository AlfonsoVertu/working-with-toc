<?php
/**
 * Plugin Name: Working with TOC
 * Plugin URI:  https://example.com/working-with-toc
 * Description: Table of Contents plugin with structured data controls and Rank Math / Yoast SEO compatibility.
 * Version:     1.0.0
 * Author:      Working with TOC Contributors
 * Author URI:  https://example.com
 * Text Domain: working-with-toc
 * Domain Path: /languages
 *
 * @package Working_With_TOC
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WWT_TOC_VERSION' ) ) {
    define( 'WWT_TOC_VERSION', '1.0.0' );
}

define( 'WWT_TOC_PLUGIN_FILE', __FILE__ );
define( 'WWT_TOC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WWT_TOC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WWT_TOC_PLUGIN_DIR . 'includes/class-autoloader.php';

Working_With_TOC\Autoloader::register();

function working_with_toc() {
    static $plugin = null;

    if ( null === $plugin ) {
        $plugin = new Working_With_TOC\Plugin();
    }

    return $plugin;
}

/**
 * Run tasks on plugin activation.
 */
function working_with_toc_activate(): void {
    working_with_toc()->ensure_capability();
}

register_activation_hook( __FILE__, 'working_with_toc_activate' );

working_with_toc()->init();

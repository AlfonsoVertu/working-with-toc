<?php
/**
 * Main plugin bootstrap.
 *
 * @package Working_With_TOC
 */

namespace Working_With_TOC;

defined( 'ABSPATH' ) || exit;

use Working_With_TOC\Admin\Admin_Page;
use Working_With_TOC\Frontend\Frontend;
use Working_With_TOC\Heading_Parser;
use Working_With_TOC\Structured_Data\Structured_Data_Manager;

/**
 * Plugin orchestrator.
 */
class Plugin {

    /**
     * Settings manager.
     *
     * @var Settings
     */
    protected $settings;

    /**
     * Admin UI handler.
     *
     * @var Admin_Page
     */
    protected $admin;

    /**
     * Frontend handler.
     *
     * @var Frontend
     */
    protected $frontend;

    /**
     * Structured data manager.
     *
     * @var Structured_Data_Manager
     */
    protected $structured_data;

    /**
     * Initialize plugin components.
     */
    public function init(): void {
        $this->settings        = new Settings();
        $this->admin           = new Admin_Page( $this->settings );
        $this->frontend        = new Frontend( $this->settings );
        $this->structured_data = new Structured_Data_Manager( $this->settings, $this->frontend );

        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_action( 'init', array( $this, 'ensure_capability' ) );
        add_action( 'init', array( $this->frontend, 'register_shortcode' ) );
        add_action( 'admin_init', array( $this->settings, 'register' ) );
        add_action( 'admin_menu', array( $this->admin, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_assets' ) );
        add_action( 'wp_enqueue_scripts', array( $this->frontend, 'enqueue_assets' ) );
        add_filter( 'the_content', array( $this->frontend, 'inject_toc' ), 15 );
        add_action( 'wp_head', array( $this->structured_data, 'output_structured_data' ) );

        // Rank Math compatibility hooks.
        add_filter( 'rank_math/researches/toc_plugins', array( $this, 'register_rank_math_plugin' ) );
        add_filter( 'rank_math/researches/toc_list', array( $this, 'register_rank_math_plugin' ) );
        add_filter( 'rank_math/toc_plugins', array( $this, 'register_rank_math_plugin' ) );
        add_filter( 'rank_math/researches/is_toc_plugin_active', array( $this, 'mark_rank_math_active' ), 10, 2 );
        add_filter( 'rank_math/researches/is_toc_present', array( $this, 'mark_rank_math_presence' ), 10, 2 );

        // Yoast SEO integration – ensure our schema can be merged safely.
        add_filter( 'wpseo_schema_graph', array( $this->structured_data, 'filter_yoast_schema_graph' ) );

        Logger::log( 'Plugin initialised.' );
    }

    /**
     * Ensure administrators can manage the plugin settings.
     */
    public function ensure_capability(): void {
        if ( ! function_exists( '\wp_roles' ) ) {
            return;
        }

        $roles = \wp_roles();

        if ( ! $roles instanceof \WP_Roles ) {
            return;
        }

        foreach ( $roles->get_names() as $role_name => $_label ) {
            $role = $roles->get_role( $role_name );

            if ( ! $role ) {
                continue;
            }

            if ( ! $role->has_cap( 'manage_options' ) ) {
                continue;
            }

            if ( $role->has_cap( Admin_Page::DEFAULT_CAPABILITY ) ) {
                continue;
            }

            $role->add_cap( Admin_Page::DEFAULT_CAPABILITY );
        }
    }

    /**
     * Load text domain.
     */
    public function load_textdomain(): void {
        load_plugin_textdomain( 'working-with-toc', false, dirname( plugin_basename( WWT_TOC_PLUGIN_FILE ) ) . '/languages' );
    }

    /**
     * Register plugin name with Rank Math.
     *
     * @param array $plugins Registered plugins.
     *
     * @return array
     */
    public function register_rank_math_plugin( $plugins ): array {
        if ( ! is_array( $plugins ) ) {
            return $plugins;
        }

        $plugins['working-with-toc/working-with-toc.php'] = __( 'Working with TOC', 'working-with-toc' );

        return $plugins;
    }

    /**
     * Inform Rank Math that our plugin is active.
     *
     * @param bool   $is_active Whether Rank Math thinks the plugin is active.
     * @param string $plugin    Plugin slug.
     *
     * @return bool
     */
    public function mark_rank_math_active( $is_active, $plugin ): bool {
        if ( 'working-with-toc/working-with-toc.php' === $plugin ) {
            return true;
        }

        return (bool) $is_active;
    }

    /**
     * Help Rank Math detect our TOC within the analysed content.
     *
     * @param bool        $is_present Whether a TOC was detected.
     * @param string|null $content    Analysed content.
     *
     * @return bool
     */
    public function mark_rank_math_presence( $is_present, $content = null ): bool {
        if ( $is_present ) {
            return true;
        }

        if ( is_string( $content ) ) {
            if ( false !== strpos( $content, 'data-wwt-toc' ) ) {
                return true;
            }

            $parsed = Heading_Parser::parse( $content );
            if ( ! empty( $parsed['headings'] ) ) {
                return true;
            }
        }

        if ( is_singular() ) {
            $post = get_post();
            if ( $post && $this->settings->is_enabled_for( $post->post_type ) ) {
                $parsed = Heading_Parser::parse( $post->post_content );
                if ( ! empty( $parsed['headings'] ) ) {
                    return true;
                }
            }
        }

        return (bool) $is_present;
    }
}

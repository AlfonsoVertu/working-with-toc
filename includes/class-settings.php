<?php
/**
 * Settings manager.
 *
 * @package Working_With_TOC
 */

namespace Working_With_TOC;

defined( 'ABSPATH' ) || exit;

/**
 * Handle plugin settings persistence.
 */
class Settings {

    /**
     * Option name.
     */
    public const OPTION_NAME = 'wwt_toc_settings';

    /**
     * Get default settings.
     *
     * @return array
     */
    public function get_defaults(): array {
        return array(
            'enable_posts' => true,
            'enable_pages'    => false,
            'enable_products' => false,
        );
    }

    /**
     * Register settings with WordPress.
     */
    public function register(): void {
        register_setting(
            'wwt_toc_settings_group',
            self::OPTION_NAME,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize' ),
                'default'           => $this->get_defaults(),
            )
        );
    }

    /**
     * Get settings array.
     *
     * @return array
     */
    public function get_settings(): array {
        $settings = get_option( self::OPTION_NAME, $this->get_defaults() );

        return wp_parse_args( $settings, $this->get_defaults() );
    }

    /**
     * Check if structured data is enabled for a post type.
     *
     * @param string $post_type Post type slug.
     *
     * @return bool
     */
    public function is_enabled_for( string $post_type ): bool {
        $settings = $this->get_settings();

        switch ( $post_type ) {
            case 'page':
                return ! empty( $settings['enable_pages'] );
            case 'product':
                return ! empty( $settings['enable_products'] );
            default:
                return ! empty( $settings['enable_posts'] );
        }
    }

    /**
     * Sanitize settings input.
     *
     * @param array $input Submitted values.
     *
     * @return array
     */
    public function sanitize( $input ): array {
        $defaults = $this->get_defaults();
        $output   = array();

        foreach ( $defaults as $key => $default ) {
            $output[ $key ] = isset( $input[ $key ] ) ? (bool) $input[ $key ] : false;
        }

        Logger::log( 'Settings saved: ' . wp_json_encode( $output ) );

        return $output;
    }
}

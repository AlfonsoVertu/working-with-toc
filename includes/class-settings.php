<?php
/**
 * Settings manager.
 *
 * @package Working_With_TOC
 */

namespace Working_With_TOC;

defined( 'ABSPATH' ) || exit;

use WP_Post;

/**
 * Handle plugin settings persistence.
 */
class Settings {

    /**
     * Option name.
     */
    public const OPTION_NAME = 'wwt_toc_settings';

    /**
     * Post meta key used to store per-content preferences.
     */
    public const META_KEY = '_wwt_toc_meta';

    /**
     * Schema describing all available options.
     *
     * @return array<string,array{type:string,default:mixed,choices?:array<int|string,mixed>}>
     */
    public function get_option_schema(): array {
        $schema = array(
            'enable_posts'        => array(
                'type'    => 'boolean',
                'default' => true,
            ),
            'enable_pages'        => array(
                'type'    => 'boolean',
                'default' => false,
            ),
            'enable_products'     => array(
                'type'    => 'boolean',
                'default' => false,
            ),
            'structured_posts'    => array(
                'type'    => 'boolean',
                'default' => true,
            ),
            'structured_pages'    => array(
                'type'    => 'boolean',
                'default' => false,
            ),
            'structured_products' => array(
                'type'    => 'boolean',
                'default' => false,
            ),
        );

        $style_defaults = array(
            'title'                  => __( 'Indice dei contenuti', 'working-with-toc' ),
            'title_color'            => '#f8fafc',
            'title_background_color' => '#1e293b',
            'background_color'       => '#0f172a',
            'link_color'             => '#38bdf8',
            'text_color'             => '#e2e8f0',
        );

        $alignment_defaults = array(
            'horizontal_alignment' => array(
                'type'    => 'enum',
                'default' => 'right',
                'choices' => $this->get_horizontal_alignment_choices(),
            ),
            'vertical_alignment'   => array(
                'type'    => 'enum',
                'default' => 'bottom',
                'choices' => $this->get_vertical_alignment_choices(),
            ),
        );

        foreach ( array( 'posts', 'pages', 'products' ) as $context ) {
            foreach ( $style_defaults as $field => $default ) {
                $type                              = ( 'title' === $field ) ? 'string' : 'color';
                $schema[ $context . '_' . $field ] = array(
                    'type'    => $type,
                    'default' => $default,
                );
            }

            foreach ( $alignment_defaults as $field => $data ) {
                $schema[ $context . '_' . $field ] = $data;
            }
        }

        return $schema;
    }

    /**
     * Get default settings.
     *
     * @return array<string,mixed>
     */
    public function get_defaults(): array {
        $defaults = array();

        foreach ( $this->get_option_schema() as $key => $data ) {
            $defaults[ $key ] = $data['default'];
        }

        return $defaults;
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
     * @return array<string,mixed>
     */
    public function get_settings(): array {
        $settings = get_option( self::OPTION_NAME, $this->get_defaults() );

        return wp_parse_args( $settings, $this->get_defaults() );
    }

    /**
     * Check if the TOC is enabled for a post type.
     *
     * @param string $post_type Post type slug.
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
     * Check if structured data is enabled for a post type.
     *
     * @param string $post_type Post type slug.
     */
    public function is_structured_data_enabled_for( string $post_type ): bool {
        $settings = $this->get_settings();

        switch ( $post_type ) {
            case 'page':
                return ! empty( $settings['structured_pages'] );
            case 'product':
                return ! empty( $settings['structured_products'] );
            default:
                return ! empty( $settings['structured_posts'] );
        }
    }

    /**
     * Sanitize settings input.
     *
     * @param array $input Submitted values.
     *
     * @return array<string,mixed>
     */
    public function sanitize( $input ): array {
        $schema = $this->get_option_schema();
        $output = array();

        foreach ( $schema as $key => $data ) {
            $value = $input[ $key ] ?? null;

            switch ( $data['type'] ) {
                case 'boolean':
                    $output[ $key ] = isset( $input[ $key ] ) ? (bool) $input[ $key ] : false;
                    break;
                case 'color':
                    $output[ $key ] = $this->sanitize_color_value( $value, $data['default'] );
                    break;
                case 'enum':
                    $choices        = isset( $data['choices'] ) && is_array( $data['choices'] ) ? $data['choices'] : array();
                    $output[ $key ] = $this->sanitize_enum_value( $value, $choices, $data['default'] );
                    break;
                case 'string':
                default:
                    $sanitized        = is_string( $value ) ? sanitize_text_field( $value ) : '';
                    $output[ $key ] = ( '' !== $sanitized ) ? $sanitized : $data['default'];
                    break;
            }
        }

        Logger::log( 'Settings saved: ' . wp_json_encode( $output ) );

        return $output;
    }

    /**
     * Get supported post types for TOC customisation.
     *
     * @return array<int,string>
     */
    public function get_supported_post_types(): array {
        return array( 'post', 'page', 'product' );
    }

    /**
     * Retrieve default styling preferences for a given post type.
     *
     * @param string $post_type Post type slug.
     *
     * @return array<string,mixed>
     */
    public function get_default_preferences( string $post_type ): array {
        $settings = $this->get_settings();
        $prefix   = $this->get_style_prefix( $post_type );

        $preferences = array();

        foreach ( $this->get_style_fields() as $field ) {
            $key                   = $prefix . '_' . $field;
            $preferences[ $field ] = $settings[ $key ] ?? '';
        }

        foreach ( $this->get_layout_fields() as $field ) {
            $key                   = $prefix . '_' . $field;
            $preferences[ $field ] = $settings[ $key ] ?? '';
        }

        $preferences['excluded_headings'] = array();
        $preferences['has_custom_title_colors'] = false;

        return $preferences;
    }

    /**
     * Retrieve merged preferences for a single post.
     *
     * @param WP_Post $post Post object.
     *
     * @return array<string,mixed>
     */
    public function get_post_preferences( WP_Post $post ): array {
        $defaults = $this->get_default_preferences( $post->post_type );
        $meta     = get_post_meta( $post->ID, self::META_KEY, true );

        if ( ! is_array( $meta ) ) {
            $meta = array();
        }

        $preferences = $defaults;

        if ( isset( $meta['title'] ) && '' !== $meta['title'] ) {
            $preferences['title'] = sanitize_text_field( $meta['title'] );
        }

        foreach ( array( 'title_color', 'title_background_color', 'background_color', 'link_color', 'text_color' ) as $color_field ) {
            if ( empty( $meta[ $color_field ] ) ) {
                continue;
            }

            $color = $this->sanitize_color_value( $meta[ $color_field ], $defaults[ $color_field ] );
            if ( $color ) {
                $preferences[ $color_field ] = $color;

                if ( in_array( $color_field, array( 'title_color', 'title_background_color' ), true ) ) {
                    $preferences['has_custom_title_colors'] = true;
                }
            }
        }

        if ( isset( $meta['horizontal_alignment'] ) ) {
            $preferences['horizontal_alignment'] = $this->sanitize_horizontal_alignment( $meta['horizontal_alignment'], $defaults['horizontal_alignment'] );
        }

        if ( isset( $meta['vertical_alignment'] ) ) {
            $preferences['vertical_alignment'] = $this->sanitize_vertical_alignment( $meta['vertical_alignment'], $defaults['vertical_alignment'] );
        }

        $preferences['excluded_headings'] = $this->sanitize_excluded_headings( $meta['excluded_headings'] ?? array() );

        return $preferences;
    }

    /**
     * List of fields controlling the TOC styling.
     *
     * @return array<int,string>
     */
    protected function get_style_fields(): array {
        return array( 'title', 'title_color', 'title_background_color', 'background_color', 'link_color', 'text_color' );
    }

    /**
     * List of layout fields controlling the TOC placement.
     *
     * @return array<int,string>
     */
    protected function get_layout_fields(): array {
        return array( 'horizontal_alignment', 'vertical_alignment' );
    }

    /**
     * Map post type to settings prefix.
     *
     * @param string $post_type Post type slug.
     *
     * @return string
     */
    protected function get_style_prefix( string $post_type ): string {
        switch ( $post_type ) {
            case 'page':
                return 'pages';
            case 'product':
                return 'products';
            default:
                return 'posts';
        }
    }

    /**
     * Sanitize an incoming color value.
     *
     * @param mixed  $value    Submitted value.
     * @param string $fallback Default colour to use when the value is empty or invalid.
     *
     * @return string
     */
    protected function sanitize_color_value( $value, string $fallback ): string {
        $color = is_string( $value ) ? sanitize_hex_color( $value ) : '';

        if ( ! $color ) {
            return $fallback;
        }

        return $color;
    }

    /**
     * Sanitize an enum value based on allowed choices.
     *
     * @param mixed            $value    Submitted value.
     * @param array<int,mixed> $choices  Allowed choices.
     * @param string           $fallback Default value when invalid.
     */
    protected function sanitize_enum_value( $value, array $choices, string $fallback ): string {
        if ( ! is_string( $value ) ) {
            $value = '';
        } else {
            $value = sanitize_text_field( $value );
        }

        if ( in_array( $value, $choices, true ) ) {
            return $value;
        }

        return $fallback;
    }

    /**
     * Sanitize the excluded headings list coming from post meta.
     *
     * @param mixed $value Stored value.
     *
     * @return array<int,string>
     */
    protected function sanitize_excluded_headings( $value ): array {
        if ( ! is_array( $value ) ) {
            return array();
        }

        $sanitized = array();

        foreach ( $value as $id ) {
            if ( ! is_string( $id ) ) {
                continue;
            }

            $clean = sanitize_text_field( $id );

            if ( '' === $clean ) {
                continue;
            }

            $sanitized[] = $clean;
        }

        return array_values( array_unique( $sanitized ) );
    }

    /**
     * Allowed values for horizontal alignment.
     *
     * @return array<int,string>
     */
    public function get_horizontal_alignment_choices(): array {
        return array( 'left', 'center', 'right' );
    }

    /**
     * Allowed values for vertical alignment.
     *
     * @return array<int,string>
     */
    public function get_vertical_alignment_choices(): array {
        return array( 'top', 'bottom' );
    }

    /**
     * Sanitize a horizontal alignment value.
     */
    public function sanitize_horizontal_alignment( $value, string $fallback ): string {
        return $this->sanitize_enum_value( $value, $this->get_horizontal_alignment_choices(), $fallback );
    }

    /**
     * Sanitize a vertical alignment value.
     */
    public function sanitize_vertical_alignment( $value, string $fallback ): string {
        return $this->sanitize_enum_value( $value, $this->get_vertical_alignment_choices(), $fallback );
    }
}

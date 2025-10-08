<?php
/**
 * Ensure Open Graph metadata is present when missing.
 *
 * @package Working_With_TOC
 */

namespace Working_With_TOC\Frontend;

defined( 'ABSPATH' ) || exit;

use WP_Post;
use Working_With_TOC\Logger;
use Working_With_TOC\Settings;
use Working_With_TOC\Structured_Data\Structured_Data_Manager;

/**
 * Handles Open Graph fallbacks for the plugin.
 */
class Open_Graph_Manager {

    /**
     * Settings handler.
     *
     * @var Settings
     */
    protected $settings;

    /**
     * Structured data handler.
     *
     * @var Structured_Data_Manager
     */
    protected $structured_data;

    /**
     * Whether the head buffer is currently active.
     *
     * @var bool
     */
    protected $is_buffering = false;

    /**
     * Constructor.
     *
     * @param Settings                $settings        Settings handler.
     * @param Structured_Data_Manager $structured_data Structured data handler.
     */
    public function __construct( Settings $settings, Structured_Data_Manager $structured_data ) {
        $this->settings        = $settings;
        $this->structured_data = $structured_data;
    }

    /**
     * Start buffering the <head> output so we can inspect previously printed tags.
     */
    public function start_buffer(): void {
        if ( $this->is_buffering ) {
            return;
        }

        if ( ! $this->should_handle_request() ) {
            return;
        }

        ob_start();
        $this->is_buffering = true;
    }

    /**
     * Ensure Open Graph tags are present before flushing the buffered <head> output.
     */
    public function output_open_graph_tags(): void {
        if ( ! $this->is_buffering ) {
            return;
        }

        $buffer = ob_get_clean();
        $this->is_buffering = false;

        if ( ! is_string( $buffer ) ) {
            return;
        }

        $updated_buffer = $this->inject_open_graph_markup( $buffer );

        echo $updated_buffer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Determine whether the current request should be handled.
     */
    protected function should_handle_request(): bool {
        if ( is_admin() ) {
            return false;
        }

        if ( is_feed() ) {
            return false;
        }

        if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
            return false;
        }

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return false;
        }

        if ( is_embed() ) {
            return false;
        }

        return apply_filters( 'working_with_toc_should_output_open_graph', true );
    }

    /**
     * Inject Open Graph tags when any of the required properties are missing.
     *
     * @param string $head Buffered <head> markup.
     */
    protected function inject_open_graph_markup( string $head ): string {
        $data = $this->collect_open_graph_data();

        $output = '';

        foreach ( $data as $property => $content ) {
            if ( '' === $content ) {
                continue;
            }

            if ( $this->has_open_graph_tag( $head, $property ) ) {
                continue;
            }

            $output .= sprintf(
                '<meta property="%1$s" content="%2$s" />' . "\n",
                esc_attr( $property ),
                esc_attr( $content )
            );
        }

        if ( '' !== $output ) {
            $head .= $output;

            Logger::log( 'Injected fallback Open Graph tags for URL: ' . $data['og:url'] );
        }

        return $head;
    }

    /**
     * Check whether a specific Open Graph meta tag is already present.
     *
     * @param string $head     Buffered <head> markup.
     * @param string $property Open Graph property to look for.
     */
    protected function has_open_graph_tag( string $head, string $property ): bool {
        $head_lower     = strtolower( $head );
        $property_lower = strtolower( $property );

        $double_property = 'property="' . $property_lower . '"';
        $single_property = "property='" . $property_lower . "'";
        $double_name     = 'name="' . $property_lower . '"';
        $single_name     = "name='" . $property_lower . "'";

        return false !== strpos( $head_lower, $double_property )
            || false !== strpos( $head_lower, $single_property )
            || false !== strpos( $head_lower, $double_name )
            || false !== strpos( $head_lower, $single_name );
    }

    /**
     * Build the Open Graph payload for the current request.
     *
     * @return array<string,string>
     */
    protected function collect_open_graph_data(): array {
        $site_name = get_bloginfo( 'name' );
        if ( ! is_string( $site_name ) ) {
            $site_name = '';
        }

        $site_name = $this->normalise_content( $site_name );

        $data = array(
            'og:title'       => '',
            'og:description' => '',
            'og:type'        => 'website',
            'og:url'         => $this->get_current_url(),
            'og:site_name'   => $site_name,
            'og:image'       => '',
            'og:image:width' => '',
            'og:image:height'=> '',
            'product:price:amount'   => '',
            'product:price:currency' => '',
            'product:availability'   => '',
            'product:condition'      => '',
            'product:brand'          => '',
        );

        if ( is_singular() ) {
            $post = get_post();

            if ( $post instanceof WP_Post ) {
                $values = $this->structured_data->get_resolved_schema_values( $post );

                $data['og:title']       = $this->normalise_content( $values['headline'] ?? '' );
                $data['og:description'] = $this->normalise_content( $values['description'] ?? '' );
                $data['og:image']       = $this->normalise_url( $values['image'] ?? '' );
                $data['og:type']        = $this->get_open_graph_type_for_post( $post );

                if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
                    $product = wc_get_product( $post->ID );

                    if ( $product instanceof \WC_Product ) {
                        $pricing = $this->structured_data->get_product_pricing_snapshot( $product );

                        if ( ! empty( $pricing['price'] ) ) {
                            $data['product:price:amount'] = $pricing['price'];
                        }

                        if ( ! empty( $pricing['currency'] ) ) {
                            $data['product:price:currency'] = $pricing['currency'];
                        }

                        if ( ! empty( $pricing['availability'] ) ) {
                            $data['product:availability'] = $this->map_availability_to_open_graph( $pricing['availability'] );
                        }

                        $product_settings = $this->settings->get_product_structured_data_settings();

                        if ( ! empty( $product_settings['condition_open_graph'] ) ) {
                            $data['product:condition'] = sanitize_key( $product_settings['condition_open_graph'] );
                        }

                        $brand_name = $this->structured_data->get_product_brand_name( $product );
                        if ( '' !== $brand_name ) {
                            $data['product:brand'] = $this->normalise_content( $brand_name );
                        }
                    }
                }

                $permalink = get_permalink( $post );
                if ( is_string( $permalink ) && '' !== $permalink ) {
                    $data['og:url'] = $this->normalise_url( $permalink );
                }
            }
        }

        if ( '' === $data['og:title'] ) {
            $data['og:title'] = $this->normalise_content( wp_get_document_title() );
        }

        if ( '' === $data['og:description'] ) {
            $tagline = get_bloginfo( 'description' );
            if ( is_string( $tagline ) ) {
                $data['og:description'] = $this->normalise_content( $tagline );
            }
        }

        if ( '' === $data['og:image'] ) {
            $data['og:image'] = $this->normalise_url( $this->settings->get_default_schema_image_url() );
        }

        if ( '' !== $data['og:image'] ) {
            $dimensions = $this->get_image_dimensions( $data['og:image'] );
            if ( ! empty( $dimensions['width'] ) ) {
                $data['og:image:width'] = (string) $dimensions['width'];
            }
            if ( ! empty( $dimensions['height'] ) ) {
                $data['og:image:height'] = (string) $dimensions['height'];
            }
        }

        /**
         * Filter the Open Graph data before it is output.
         *
         * @param array<string,string> $data Open Graph data.
         */
        $data = apply_filters( 'working_with_toc_open_graph_data', $data );

        return $data;
    }

    /**
     * Determine the Open Graph type for the current post.
     *
     * @param WP_Post $post Post object.
     */
    protected function get_open_graph_type_for_post( WP_Post $post ): string {
        switch ( $post->post_type ) {
            case 'product':
                return 'product';
            case 'page':
            case 'post':
                return 'article';
            default:
                return apply_filters( 'working_with_toc_open_graph_type', 'article', $post );
        }
    }

    /**
     * Map schema.org availability URLs to Open Graph availability tokens.
     *
     * @param string $availability Schema availability URL.
     */
    protected function map_availability_to_open_graph( string $availability ): string {
        $map = array(
            'https://schema.org/InStock'     => 'instock',
            'https://schema.org/OutOfStock'  => 'oos',
            'https://schema.org/BackOrder'   => 'pending',
            'https://schema.org/PreOrder'    => 'preorder',
        );

        if ( isset( $map[ $availability ] ) ) {
            return $map[ $availability ];
        }

        /**
         * Filter the Open Graph availability value.
         *
         * @param string $fallback    Fallback value.
         * @param string $availability Schema availability URL.
         */
        $filtered = apply_filters( 'working_with_toc_open_graph_availability', '', $availability );

        if ( is_string( $filtered ) && '' !== $filtered ) {
            return $filtered;
        }

        return '';
    }

    /**
     * Attempt to retrieve image dimensions for the provided URL.
     *
     * @param string $url Image URL.
     *
     * @return array{width?:int,height?:int}
     */
    protected function get_image_dimensions( string $url ): array {
        $attachment_id = 0;

        if ( function_exists( 'attachment_url_to_postid' ) ) {
            $attachment_id = attachment_url_to_postid( $url );
        }

        if ( $attachment_id ) {
            $metadata = wp_get_attachment_metadata( $attachment_id );

            if ( is_array( $metadata ) ) {
                $width  = isset( $metadata['width'] ) ? (int) $metadata['width'] : 0;
                $height = isset( $metadata['height'] ) ? (int) $metadata['height'] : 0;

                $dimensions = array();

                if ( $width > 0 ) {
                    $dimensions['width'] = $width;
                }

                if ( $height > 0 ) {
                    $dimensions['height'] = $height;
                }

                if ( ! empty( $dimensions ) ) {
                    return $dimensions;
                }
            }
        }

        $image_size = @getimagesize( $url ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        if ( false === $image_size ) {
            return array();
        }

        return array(
            'width'  => isset( $image_size[0] ) ? (int) $image_size[0] : 0,
            'height' => isset( $image_size[1] ) ? (int) $image_size[1] : 0,
        );
    }

    /**
     * Normalise text values for meta output.
     *
     * @param mixed $content Text to normalise.
     */
    protected function normalise_content( $content ): string {
        if ( ! is_string( $content ) ) {
            return '';
        }

        $content = wp_strip_all_tags( $content );
        $content = trim( $content );

        if ( '' === $content ) {
            return '';
        }

        return wp_html_excerpt( $content, 300, '' );
    }

    /**
     * Normalise URL values for meta output.
     *
     * @param mixed $url URL to normalise.
     */
    protected function normalise_url( $url ): string {
        if ( ! is_string( $url ) ) {
            return '';
        }

        $url = esc_url_raw( trim( $url ) );

        if ( '' === $url ) {
            return '';
        }

        return $url;
    }

    /**
     * Resolve the current URL, preserving query arguments.
     */
    protected function get_current_url(): string {
        if ( is_singular() ) {
            $permalink = get_permalink();

            if ( is_string( $permalink ) && '' !== $permalink ) {
                return $this->normalise_url( $permalink );
            }
        }

        global $wp;

        $request = '';

        if ( isset( $wp ) && is_object( $wp ) && isset( $wp->request ) ) {
            $request = (string) $wp->request;
        }

        $url = home_url( add_query_arg( array(), $request ) );

        return $this->normalise_url( $url );
    }
}

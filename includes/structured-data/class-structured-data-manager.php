<?php
/**
 * Structured data output.
 *
 * @package Working_With_TOC
 */

namespace Working_With_TOC\Structured_Data;

defined( 'ABSPATH' ) || exit;

use WP_Post;
use Working_With_TOC\Heading_Parser;
use Working_With_TOC\Logger;
use Working_With_TOC\Settings;
use Working_With_TOC\Frontend\Frontend;

/**
 * Build structured data for TOC.
 */
class Structured_Data_Manager {

    /**
     * Settings handler.
     *
     * @var Settings
     */
    protected $settings;

    /**
     * Frontend handler.
     *
     * @var Frontend
     */
    protected $frontend;

    /**
     * Constructor.
     *
     * @param Settings $settings Settings handler.
     * @param Frontend $frontend Frontend handler.
     */
    public function __construct( Settings $settings, Frontend $frontend ) {
        $this->settings = $settings;
        $this->frontend = $frontend;
    }

    /**
     * Output JSON-LD for supported post types.
     */
    public function output_structured_data(): void {
        if ( ! is_singular() ) {
            return;
        }

        $post = get_post();
        if ( ! $post instanceof WP_Post ) {
            return;
        }

        if ( ! $this->settings->is_enabled_for( $post->post_type ) ) {
            return;
        }

        if ( ! $this->settings->is_structured_data_enabled_for( $post->post_type ) ) {
            return;
        }

        $headings = $this->collect_headings( $post );
        if ( empty( $headings ) ) {
            return;
        }

        $schema = $this->build_schema( $post, $headings );

        if ( empty( $schema ) ) {
            return;
        }

        Logger::log( 'Rendering structured data for post ID ' . $post->ID );

        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Integrate with Yoast schema graph.
     *
     * @param array $graph Existing graph.
     *
     * @return array
     */
    public function filter_yoast_schema_graph( $graph ): array {
        if ( ! is_singular() ) {
            return $graph;
        }

        $post = get_post();
        if ( ! $post instanceof WP_Post || ! $this->settings->is_enabled_for( $post->post_type ) ) {
            return $graph;
        }

        if ( ! $this->settings->is_structured_data_enabled_for( $post->post_type ) ) {
            return $graph;
        }

        $headings = $this->collect_headings( $post );
        if ( empty( $headings ) ) {
            return $graph;
        }

        $schema = $this->build_schema( $post, $headings );
        if ( empty( $schema ) ) {
            return $graph;
        }

        $graph[] = $schema;
        Logger::log( 'Merged TOC schema into Yoast graph for post ID ' . $post->ID );

        return $graph;
    }

    /**
     * Collect headings for the current post.
     *
     * @param WP_Post $post Post object.
     *
     * @return array<int, array{title:string, url:string}>
     */
    protected function collect_headings( WP_Post $post ): array {
        $callback = array( $this->frontend, 'inject_toc' );
        $priority = has_filter( 'the_content', $callback );

        if ( false !== $priority ) {
            remove_filter( 'the_content', $callback, (int) $priority );
        }

        $content = apply_filters( 'the_content', $post->post_content );

        if ( false !== $priority ) {
            add_filter( 'the_content', $callback, (int) $priority );
        }

        $parsed   = Heading_Parser::parse( $content );
        $headings = array();

        foreach ( $parsed['headings'] as $heading ) {
            $headings[] = array(
                'title' => $heading['title'],
                'url'   => get_permalink( $post ) . '#' . $heading['id'],
            );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            Logger::log( 'Structured data headings raccolte: ' . count( $headings ) );
        }

        return $headings;
    }

    /**
     * Build schema array for JSON-LD.
     *
     * @param WP_Post $post     Post object.
     * @param array   $headings Headings with titles & URLs.
     *
     * @return array<string,mixed>
     */
    protected function build_schema( WP_Post $post, array $headings ): array {
        $type = 'Article';
        if ( 'product' === $post->post_type ) {
            $type = 'Product';
        } elseif ( 'page' === $post->post_type ) {
            $type = 'WebPage';
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => $type,
            '@id'      => get_permalink( $post ) . '#main',
            'name'     => wp_strip_all_tags( get_the_title( $post ) ),
            'url'      => get_permalink( $post ),
            'hasPart'  => array(
                '@type' => 'TableOfContents',
                'name'  => __( 'Indice dei contenuti', 'working-with-toc' ),
                'about' => array(),
            ),
        );

        foreach ( $headings as $heading ) {
            $schema['hasPart']['about'][] = array(
                '@type' => 'Thing',
                'name'  => $heading['title'],
                'url'   => $heading['url'],
            );
        }

        return $schema;
    }
}

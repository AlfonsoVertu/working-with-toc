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
     * Cached schema for the current request.
     *
     * @var array<string,mixed>|null
     */
    protected $cached_schema = null;

    /**
     * Post ID for the cached schema.
     *
     * @var int
     */
    protected $cached_post_id = 0;

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

        $schema = $this->get_schema_for_post( $post );
        if ( empty( $schema ) ) {
            return;
        }

        if ( defined( 'RANK_MATH_VERSION' ) ) {
            Logger::log( 'Skipping direct structured data output because Rank Math integration is active for post ID ' . $post->ID );

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
        if ( ! $post instanceof WP_Post ) {
            return $graph;
        }

        $schema = $this->get_schema_for_post( $post );
        if ( empty( $schema ) ) {
            return $graph;
        }

        $graph[] = $schema;
        Logger::log( 'Merged TOC schema into Yoast graph for post ID ' . $post->ID );

        return $graph;
    }

    /**
     * Integrate with Rank Math JSON-LD graph.
     *
     * @param array<string,mixed> $data    Existing data passed to Rank Math.
     * @param mixed               $jsonld  Rank Math JsonLD instance (unused).
     *
     * @return array<string,mixed>
     */
    public function filter_rank_math_json_ld( $data, $jsonld ): array {
        if ( ! is_singular() ) {
            return is_array( $data ) ? $data : array();
        }

        $post = get_post();
        if ( ! $post instanceof WP_Post ) {
            return is_array( $data ) ? $data : array();
        }

        $schema = $this->get_schema_for_post( $post );
        if ( empty( $schema ) ) {
            return is_array( $data ) ? $data : array();
        }

        if ( ! is_array( $data ) ) {
            $data = array();
        }

        $data['wwtToc'] = $schema;

        Logger::log( 'Merged TOC schema into Rank Math graph for post ID ' . $post->ID );

        return $data;
    }

    /**
     * Build or reuse schema for the given post.
     *
     * @param WP_Post $post Post object.
     *
     * @return array<string,mixed>
     */
    protected function get_schema_for_post( WP_Post $post ): array {
        if ( null !== $this->cached_schema && $this->cached_post_id === $post->ID ) {
            return $this->cached_schema;
        }

        $this->cached_post_id = $post->ID;
        $this->cached_schema  = array();

        if ( ! $this->settings->is_structured_data_enabled_for( $post->post_type ) ) {
            return $this->cached_schema;
        }

        $preferences = $this->settings->get_post_preferences( $post );
        $headings    = $this->collect_headings( $post, $preferences );

        if ( empty( $headings ) ) {
            return $this->cached_schema;
        }

        $schema = $this->build_schema( $post, $headings, $preferences );

        if ( empty( $schema ) ) {
            return $this->cached_schema;
        }

        $this->cached_schema = $schema;

        return $this->cached_schema;
    }

    /**
     * Collect headings for the current post.
     *
     * @param WP_Post                $post         Post object.
     * @param array<string,mixed>    $preferences  Style preferences for the post.
     *
     * @return array<int, array{title:string, url:string}>
     */
    protected function collect_headings( WP_Post $post, array $preferences ): array {
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

        $excluded = isset( $preferences['excluded_headings'] ) && is_array( $preferences['excluded_headings'] )
            ? array_fill_keys( $preferences['excluded_headings'], true )
            : array();

        foreach ( $parsed['headings'] as $heading ) {
            if ( isset( $excluded[ $heading['id'] ] ) ) {
                continue;
            }

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
     * @param WP_Post             $post         Post object.
     * @param array               $headings     Headings with titles & URLs.
     * @param array<string,mixed> $preferences  Post-specific preferences.
     *
     * @return array<string,mixed>
     */
    protected function build_schema( WP_Post $post, array $headings, array $preferences ): array {
        $type = 'Article';
        if ( 'product' === $post->post_type ) {
            $type = 'Product';
        } elseif ( 'page' === $post->post_type ) {
            $type = 'WebPage';
        }

        $toc_title = isset( $preferences['title'] ) && '' !== trim( $preferences['title'] )
            ? $preferences['title']
            : __( 'Table of contents', 'working-with-toc' );

        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => $type,
            '@id'      => get_permalink( $post ) . '#main',
            'name'     => wp_strip_all_tags( get_the_title( $post ) ),
            'url'      => get_permalink( $post ),
            'hasPart'  => array(
                '@type'           => 'ItemList',
                'name'            => $toc_title,
                'itemListElement' => array(),
            ),
        );

        foreach ( $headings as $index => $heading ) {
            $schema['hasPart']['itemListElement'][] = array(
                '@type'    => 'ListItem',
                'position' => $index + 1,
                'item'     => array(
                    '@type' => 'Thing',
                    'name'  => $heading['title'],
                    'url'   => $heading['url'],
                ),
            );
        }

        return $schema;
    }
}

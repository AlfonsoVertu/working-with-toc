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
     * @var array{item_list?:array<string,mixed>,faq?:array<string,mixed>,fallback_graph?:array<string,mixed>}|null
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

        if ( $this->is_seo_plugin_active() ) {
            Logger::log( 'Skipping direct structured data output because an SEO integration is active for post ID ' . $post->ID );

            return;
        }

        if ( empty( $schema['fallback_graph'] ) ) {
            return;
        }

        Logger::log( 'Rendering fallback structured data for post ID ' . $post->ID );

        echo '<script type="application/ld+json">' . wp_json_encode( $schema['fallback_graph'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
        if ( empty( $schema['item_list'] ) ) {
            return $graph;
        }

        $item_list    = $schema['item_list'];
        $item_list_id = is_string( $item_list['@id'] ?? null ) ? $item_list['@id'] : '';

        if ( $item_list_id ) {
            $parent_ids = $this->add_item_list_reference_to_schema_nodes( $graph, $item_list_id );
            $item_list  = $this->link_item_list_to_parents( $item_list, $parent_ids );
        }

        $graph[] = $item_list;

        if ( ! empty( $schema['faq'] ) ) {
            $faq    = $schema['faq'];
            $faq_id = is_string( $faq['@id'] ?? null ) ? $faq['@id'] : '';

            if ( $faq_id ) {
                $parent_ids = $this->add_faq_reference_to_schema_nodes( $graph, $faq_id );
                $faq        = $this->link_faq_to_parents( $faq, $parent_ids );
            }

            $graph[] = $faq;
        }

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
        if ( empty( $schema['item_list'] ) ) {
            return is_array( $data ) ? $data : array();
        }

        if ( ! is_array( $data ) ) {
            $data = array();
        }

        if ( ! isset( $data['@graph'] ) ) {
            $data['@graph'] = array();
        } elseif ( ! is_array( $data['@graph'] ) ) {
            $data['@graph'] = array( $data['@graph'] );
        } else {
            $graph_keys        = array_keys( $data['@graph'] );
            $has_non_int_keys  = array_filter(
                $graph_keys,
                static function ( $key ) {
                    return ! is_int( $key );
                }
            );

            if ( ! empty( $has_non_int_keys ) ) {
                $data['@graph'] = array( $data['@graph'] );
            } else {
                $data['@graph'] = array_values( $data['@graph'] );
            }
        }

        $item_list    = $schema['item_list'];
        $item_list_id = is_string( $item_list['@id'] ?? null ) ? $item_list['@id'] : '';

        if ( $item_list_id ) {
            $parent_ids = $this->add_item_list_reference_to_schema_nodes( $data['@graph'], $item_list_id );
            $item_list  = $this->link_item_list_to_parents( $item_list, $parent_ids );
        }

        $data['@graph'][] = $item_list;

        if ( ! empty( $schema['faq'] ) ) {
            $faq    = $schema['faq'];
            $faq_id = is_string( $faq['@id'] ?? null ) ? $faq['@id'] : '';

            if ( $faq_id ) {
                $parent_ids = $this->add_faq_reference_to_schema_nodes( $data['@graph'], $faq_id );
                $faq        = $this->link_faq_to_parents( $faq, $parent_ids );
            }

            $data['@graph'][] = $faq;
        }

        Logger::log( 'Merged TOC schema into Rank Math graph for post ID ' . $post->ID );

        return $data;
    }

    /**
     * Ensure the given graph nodes reference the ItemList and return their IDs.
     *
     * @param array<int|string,mixed> $nodes        Graph nodes to inspect.
     * @param string                  $item_list_id ItemList ID to reference.
     * @param array<int,string>       $types        Node types to target.
     *
     * @return array<int,string> Parent node IDs that were detected.
     */
    protected function add_item_list_reference_to_schema_nodes( array &$nodes, string $item_list_id, array $types = array( 'WebPage', 'Article' ) ): array {
        $parent_ids = array();

        foreach ( $nodes as &$node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }

            $node_types = $node['@type'] ?? array();
            if ( ! is_array( $node_types ) ) {
                $node_types = array( $node_types );
            }

            if ( empty( array_intersect( $types, $node_types ) ) ) {
                continue;
            }

            if ( $item_list_id ) {
                $has_part = $this->normalize_reference_list( $node['hasPart'] ?? array() );
                $has_part = $this->add_reference_if_missing( $has_part, $item_list_id );

                if ( ! empty( $has_part ) ) {
                    $node['hasPart'] = $has_part;
                }
            }

            if ( isset( $node['@id'] ) && is_string( $node['@id'] ) && '' !== $node['@id'] ) {
                $parent_ids[] = $node['@id'];
            }
        }

        unset( $node );

        if ( empty( $parent_ids ) ) {
            return array();
        }

        return array_values( array_unique( $parent_ids ) );
    }

    /**
     * Ensure the ItemList node references the detected parent nodes.
     *
     * @param array<string,mixed> $item_list ItemList node to adjust.
     * @param array<int,string>   $parent_ids Parent node IDs to reference.
     *
     * @return array<string,mixed> Updated ItemList node.
     */
    protected function link_item_list_to_parents( array $item_list, array $parent_ids ): array {
        if ( empty( $parent_ids ) ) {
            return $item_list;
        }

        $is_part_of = $this->normalize_reference_list( $item_list['isPartOf'] ?? array() );

        foreach ( $parent_ids as $parent_id ) {
            $is_part_of = $this->add_reference_if_missing( $is_part_of, $parent_id );
        }

        if ( ! empty( $is_part_of ) ) {
            $item_list['isPartOf'] = $is_part_of;
        }

        return $item_list;
    }

    /**
     * Ensure schema nodes reference the FAQPage node and return their IDs.
     *
     * @param array<int|string,mixed> $nodes   Graph nodes to inspect.
     * @param string                  $faq_id  FAQPage ID to reference.
     * @param array<int,string>       $types   Node types to target.
     *
     * @return array<int,string>
     */
    protected function add_faq_reference_to_schema_nodes( array &$nodes, string $faq_id, array $types = array( 'WebPage', 'Article' ) ): array {
        $parent_ids = array();

        foreach ( $nodes as &$node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }

            $node_types = $node['@type'] ?? array();
            if ( ! is_array( $node_types ) ) {
                $node_types = array( $node_types );
            }

            if ( empty( array_intersect( $types, $node_types ) ) ) {
                continue;
            }

            $main_entity = $this->normalize_reference_list( $node['mainEntity'] ?? array() );
            $main_entity = $this->add_reference_if_missing( $main_entity, $faq_id );

            if ( ! empty( $main_entity ) ) {
                $node['mainEntity'] = $main_entity;
            }

            if ( isset( $node['@id'] ) && is_string( $node['@id'] ) && '' !== $node['@id'] ) {
                $parent_ids[] = $node['@id'];
            }
        }

        unset( $node );

        if ( empty( $parent_ids ) ) {
            return array();
        }

        return array_values( array_unique( $parent_ids ) );
    }

    /**
     * Link the FAQPage node to the detected parent nodes.
     *
     * @param array<string,mixed> $faq        FAQPage node.
     * @param array<int,string>   $parent_ids Parent node IDs.
     *
     * @return array<string,mixed>
     */
    protected function link_faq_to_parents( array $faq, array $parent_ids ): array {
        if ( empty( $parent_ids ) ) {
            return $faq;
        }

        $is_part_of = $this->normalize_reference_list( $faq['isPartOf'] ?? array() );

        foreach ( $parent_ids as $parent_id ) {
            $is_part_of = $this->add_reference_if_missing( $is_part_of, $parent_id );
        }

        if ( ! empty( $is_part_of ) ) {
            $faq['isPartOf'] = $is_part_of;
        }

        return $faq;
    }

    /**
     * Normalize a schema reference property to a list of references.
     *
     * @param mixed $value Raw property value.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function normalize_reference_list( $value ): array {
        if ( empty( $value ) ) {
            return array();
        }

        if ( is_string( $value ) ) {
            return array( array( '@id' => $value ) );
        }

        if ( ! is_array( $value ) ) {
            return array();
        }

        if ( $this->is_associative_array( $value ) ) {
            return array( $value );
        }

        $normalized = array();

        foreach ( $value as $item ) {
            if ( is_string( $item ) ) {
                $normalized[] = array( '@id' => $item );
            } elseif ( is_array( $item ) ) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    /**
     * Append a reference to the list when missing.
     *
     * @param array<int,array<string,mixed>> $references Reference list.
     * @param string                         $id         ID to inject.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function add_reference_if_missing( array $references, string $id ): array {
        foreach ( $references as $reference ) {
            if ( is_array( $reference ) && isset( $reference['@id'] ) && $reference['@id'] === $id ) {
                return $references;
            }
        }

        $references[] = array( '@id' => $id );

        return $references;
    }

    /**
     * Determine whether an array is associative.
     *
     * @param array<mixed> $array Array to inspect.
     *
     * @return bool
     */
    protected function is_associative_array( array $array ): bool {
        if ( array() === $array ) {
            return false;
        }

        return array_keys( $array ) !== range( 0, count( $array ) - 1 );
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

        $preferences  = $this->settings->get_post_preferences( $post );
        $heading_data = $this->collect_headings( $post, $preferences );
        $headings     = $heading_data['headings'];

        if ( empty( $headings ) ) {
            return $this->cached_schema;
        }

        $item_list = $this->build_item_list_schema( $post, $headings, $preferences );

        if ( empty( $item_list ) ) {
            return $this->cached_schema;
        }

        $schema = array(
            'item_list' => $item_list,
        );

        if ( ! empty( $heading_data['faq'] ) ) {
            $faq_schema = $this->build_faq_schema( $post, $heading_data['faq'] );

            if ( ! empty( $faq_schema ) ) {
                $schema['faq'] = $faq_schema;
            }
        }

        $fallback_graph = $this->build_fallback_graph(
            $post,
            $item_list,
            $this->resolve_schema_values( $post ),
            $schema['faq'] ?? null
        );

        if ( ! empty( $fallback_graph ) ) {
            $schema['fallback_graph'] = $fallback_graph;
        }

        $this->cached_schema = $schema;

        return $this->cached_schema;
    }

    /**
     * Collect headings for the current post.
     *
     * @param WP_Post             $post        Post object.
     * @param array<string,mixed> $preferences Style preferences for the post.
     *
     * @return array{headings:array<int,array{title:string,url:string,id:string,faq_excerpt?:string,faq_answer?:string}>,faq:array<int,array{question:string,answer:string,url:string,id:string}>}
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

        $permalink = get_permalink( $post );

        if ( ! $permalink ) {
            return array(
                'headings' => array(),
                'faq'      => array(),
            );
        }

        $parsed = Heading_Parser::parse(
            $content,
            array(
                'extract_faq' => true,
            )
        );

        $headings    = array();
        $faq_entries = array();

        $excluded = isset( $preferences['excluded_headings'] ) && is_array( $preferences['excluded_headings'] )
            ? array_fill_keys( $preferences['excluded_headings'], true )
            : array();

        $faq_map = isset( $preferences['faq_headings'] ) && is_array( $preferences['faq_headings'] )
            ? array_fill_keys( $preferences['faq_headings'], true )
            : array();

        foreach ( $parsed['headings'] as $heading ) {
            if ( ! isset( $heading['id'], $heading['title'] ) ) {
                continue;
            }

            if ( isset( $excluded[ $heading['id'] ] ) ) {
                continue;
            }

            $url = $permalink . '#' . $heading['id'];

            $entry = array(
                'title'       => $heading['title'],
                'url'         => $url,
                'id'          => $heading['id'],
                'faq_excerpt' => isset( $heading['faq_excerpt'] ) ? (string) $heading['faq_excerpt'] : '',
                'faq_answer'  => isset( $heading['faq_answer'] ) ? (string) $heading['faq_answer'] : '',
            );

            $headings[] = $entry;

            if ( isset( $faq_map[ $heading['id'] ] ) ) {
                $question = wp_strip_all_tags( $heading['title'] );
                $answer   = isset( $entry['faq_answer'] ) ? (string) $entry['faq_answer'] : '';

                if ( '' !== $question && '' !== $answer ) {
                    $faq_entries[] = array(
                        'question' => $question,
                        'answer'   => $answer,
                        'url'      => $url,
                        'id'       => $heading['id'],
                    );
                }
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            Logger::log( 'Structured data headings raccolte: ' . count( $headings ) );
            Logger::log( 'Structured data FAQ raccolte: ' . count( $faq_entries ) );
        }

        return array(
            'headings' => $headings,
            'faq'      => $faq_entries,
        );
    }

    /**
     * Build the ItemList node describing the TOC.
     *
     * @param WP_Post             $post         Post object.
     * @param array<int,array{title:string,url:string,id?:string,faq_excerpt?:string,faq_answer?:string}> $headings Heading data.
     * @param array<string,mixed> $preferences  Post-specific preferences.
     *
     * @return array<string,mixed>
     */
    protected function build_item_list_schema( WP_Post $post, array $headings, array $preferences ): array {
        $toc_title = isset( $preferences['title'] ) && '' !== trim( $preferences['title'] )
            ? $preferences['title']
            : __( 'Table of contents', 'working-with-toc' );

        $item_list = array(
            '@type'           => 'ItemList',
            '@id'             => get_permalink( $post ) . '#table-of-contents',
            'name'            => $toc_title,
            'itemListElement' => array(),
        );

        foreach ( $headings as $index => $heading ) {
            $item_list['itemListElement'][] = array(
                '@type'    => 'ListItem',
                'position' => $index + 1,
                'item'     => array(
                    '@type' => 'Thing',
                    '@id'   => $heading['url'],
                    'name'  => $heading['title'],
                    'url'   => $heading['url'],
                ),
            );
        }

        return $item_list;
    }

    /**
     * Build the FAQPage node for FAQ structured data.
     *
     * @param WP_Post $post    Post object.
     * @param array<int,array{question:string,answer:string,url:string,id:string}> $entries FAQ entries.
     *
     * @return array<string,mixed>
     */
    protected function build_faq_schema( WP_Post $post, array $entries ): array {
        if ( empty( $entries ) ) {
            return array();
        }

        $permalink = get_permalink( $post );

        if ( ! $permalink ) {
            return array();
        }

        $faq_id      = $permalink . '#faq';
        $webpage_id  = $permalink . '#webpage';
        $main_entity = array();

        foreach ( $entries as $index => $entry ) {
            $question = isset( $entry['question'] ) ? trim( (string) $entry['question'] ) : '';
            $answer   = isset( $entry['answer'] ) ? trim( (string) $entry['answer'] ) : '';

            $question = wp_strip_all_tags( $question );
            $answer   = wp_strip_all_tags( $answer );

            if ( '' === $question || '' === $answer ) {
                continue;
            }

            $question_id = $faq_id . '-question-' . ( $index + 1 );
            $answer_id   = $faq_id . '-answer-' . ( $index + 1 );

            if ( ! empty( $entry['url'] ) ) {
                $has_fragment = false !== strpos( $entry['url'], '#' );

                $question_id = $has_fragment
                    ? sprintf( '%s-question', $entry['url'] )
                    : sprintf( '%s#question', $entry['url'] );

                $answer_id = $has_fragment
                    ? sprintf( '%s-answer', $entry['url'] )
                    : sprintf( '%s#answer', $entry['url'] );
            }

            $question_node = array(
                '@type'          => 'Question',
                '@id'            => $question_id,
                'name'           => $question,
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    '@id'   => $answer_id,
                    'text'  => $answer,
                ),
            );

            if ( ! empty( $entry['url'] ) ) {
                $question_node['url'] = $entry['url'];
            }

            $main_entity[] = $question_node;
        }

        if ( empty( $main_entity ) ) {
            return array();
        }

        return array(
            '@type'            => 'FAQPage',
            '@id'              => $faq_id,
            'mainEntity'       => $main_entity,
            'mainEntityOfPage' => array( '@id' => $webpage_id ),
            'isPartOf'         => array( array( '@id' => $webpage_id ) ),
            'url'              => $permalink,
        );
    }

    /**
     * Build the fallback structured data graph when no SEO plugin is active.
     *
     * @param WP_Post             $post        Post object.
     * @param array<string,mixed>  $item_list  ItemList node.
     * @param array<string,string> $values     Resolved schema values.
     * @param array<string,mixed>|null $faq    FAQPage node when available.
     *
     * @return array<string,mixed>
     */
    protected function build_fallback_graph( WP_Post $post, array $item_list, array $values, ?array $faq = null ): array {
        $permalink = get_permalink( $post );

        if ( ! $permalink ) {
            return array();
        }

        $organization     = $this->settings->get_organization_settings();
        $organization_url = $organization['url'] ?: home_url( '/' );
        $organization_id  = trailingslashit( $organization_url ) . '#organization';
        $website_url      = home_url( '/' );
        $website_id       = trailingslashit( $website_url ) . '#website';
        $webpage_id       = $permalink . '#webpage';
        $breadcrumb_id    = $permalink . '#breadcrumb';
        $image_id         = $permalink . '#primaryimage';
        $item_list_id     = $item_list['@id'] ?? ( $permalink . '#table-of-contents' );

        $graph = array();

        $organization_node = array(
            '@type' => 'Organization',
            '@id'   => $organization_id,
            'name'  => $organization['name'] ?: get_bloginfo( 'name' ),
            'url'   => $organization_url,
        );

        if ( ! empty( $organization['logo'] ) ) {
            $organization_node['logo'] = array(
                '@type' => 'ImageObject',
                'url'   => $organization['logo'],
            );
        }

        $graph[] = $organization_node;

        $website_node = array(
            '@type'     => 'WebSite',
            '@id'       => $website_id,
            'url'       => $website_url,
            'name'      => get_bloginfo( 'name' ),
            'publisher' => array( '@id' => $organization_id ),
        );

        $graph[] = $website_node;

        $breadcrumb = $this->build_breadcrumb_list( $post, $breadcrumb_id );

        $webpage = array(
            '@type'      => 'WebPage',
            '@id'        => $webpage_id,
            'url'        => $permalink,
            'name'       => $values['headline'] ?: wp_strip_all_tags( get_the_title( $post ) ),
            'isPartOf'   => array( '@id' => $website_id ),
            'breadcrumb' => array( '@id' => $breadcrumb_id ),
        );

        if ( ! empty( $faq ) ) {
            $faq_id = is_string( $faq['@id'] ?? null ) ? $faq['@id'] : '';
            if ( '' !== $faq_id ) {
                $main_entity = $this->normalize_reference_list( $webpage['mainEntity'] ?? array() );
                $main_entity = $this->add_reference_if_missing( $main_entity, $faq_id );

                if ( ! empty( $main_entity ) ) {
                    $webpage['mainEntity'] = $main_entity;
                }
            }
        }

        if ( '' !== $values['description'] ) {
            $webpage['description'] = $values['description'];
        }

        if ( '' !== $values['image'] ) {
            $webpage['primaryImageOfPage'] = array( '@id' => $image_id );
        }

        $article_type = $this->get_article_type( $post );

        if ( 'WebPage' === $article_type ) {
            $webpage['hasPart']   = array( array( '@id' => $item_list_id ) );
            $webpage['publisher'] = array( '@id' => $organization_id );

            if ( '' !== $values['image'] ) {
                $webpage['image'] = array( '@id' => $image_id );
            }

            $published = get_post_time( DATE_W3C, true, $post );
            if ( $published ) {
                $webpage['datePublished'] = $published;
            }

            $modified = get_post_modified_time( DATE_W3C, true, $post );
            if ( $modified ) {
                $webpage['dateModified'] = $modified;
            }

            $author_name = get_the_author_meta( 'display_name', $post->post_author );
            if ( $author_name ) {
                $author = array(
                    '@type' => 'Person',
                    'name'  => $author_name,
                );

                $author_url = get_author_posts_url( $post->post_author );
                if ( $author_url ) {
                    $author['url'] = $author_url;
                }

                $webpage['author'] = $author;
            }
        }

        $graph[] = $webpage;

        if ( ! empty( $breadcrumb['itemListElement'] ) ) {
            $graph[] = $breadcrumb;
        }

        if ( 'product' === $post->post_type ) {
            $product_node = $this->build_product_node( $post, $values, $webpage_id, $image_id, $permalink );

            if ( ! empty( $product_node ) ) {
                $product_id = is_string( $product_node['@id'] ?? null ) ? $product_node['@id'] : '';

                if ( '' !== $product_id && '' !== $item_list_id ) {
                    $has_part = $this->normalize_reference_list( $product_node['hasPart'] ?? array() );
                    $has_part = $this->add_reference_if_missing( $has_part, $item_list_id );

                    if ( ! empty( $has_part ) ) {
                        $product_node['hasPart'] = $has_part;
                    }

                    $item_list_is_part_of = $this->normalize_reference_list( $item_list['isPartOf'] ?? array() );
                    $item_list_is_part_of = $this->add_reference_if_missing( $item_list_is_part_of, $product_id );

                    if ( ! empty( $item_list_is_part_of ) ) {
                        $item_list['isPartOf'] = $item_list_is_part_of;
                    }
                }

                $graph[] = $product_node;
            }
        } elseif ( 'WebPage' !== $article_type ) {
            $article_id = $permalink . '#article';
            $article    = array(
                '@type'            => $article_type,
                '@id'              => $article_id,
                'headline'         => $values['headline'] ?: wp_strip_all_tags( get_the_title( $post ) ),
                'mainEntityOfPage' => array( '@id' => $webpage_id ),
                'isPartOf'         => array( '@id' => $website_id ),
                'hasPart'          => array( array( '@id' => $item_list_id ) ),
            );

            if ( ! empty( $faq ) ) {
                $faq_id = is_string( $faq['@id'] ?? null ) ? $faq['@id'] : '';
                if ( '' !== $faq_id ) {
                    $article_main_entity = $this->normalize_reference_list( $article['mainEntity'] ?? array() );
                    $article_main_entity = $this->add_reference_if_missing( $article_main_entity, $faq_id );

                    if ( ! empty( $article_main_entity ) ) {
                        $article['mainEntity'] = $article_main_entity;
                    }
                }
            }

            if ( '' !== $values['description'] ) {
                $article['description'] = $values['description'];
            }

            if ( '' !== $values['image'] ) {
                $article['image'] = array( '@id' => $image_id );
            }

            $published = get_post_time( DATE_W3C, true, $post );
            if ( $published ) {
                $article['datePublished'] = $published;
            }

            $modified = get_post_modified_time( DATE_W3C, true, $post );
            if ( $modified ) {
                $article['dateModified'] = $modified;
            }

            $author_name = get_the_author_meta( 'display_name', $post->post_author );
            if ( $author_name ) {
                $author = array(
                    '@type' => 'Person',
                    'name'  => $author_name,
                );

                $author_url = get_author_posts_url( $post->post_author );
                if ( $author_url ) {
                    $author['url'] = $author_url;
                }

                $article['author'] = $author;
            }

            $article['publisher'] = array( '@id' => $organization_id );

            $graph[] = $article;
        }

        if ( '' !== $values['image'] ) {
            $graph[] = array(
                '@type' => 'ImageObject',
                '@id'   => $image_id,
                'url'   => $values['image'],
            );
        }

        $item_list['@id'] = $item_list_id;
        $graph[]          = $item_list;

        if ( ! empty( $faq ) ) {
            $graph[] = $faq;
        }

        return array(
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        );
    }

    /**
     * Build the Product schema node for WooCommerce products.
     *
     * @param WP_Post             $post            Product post object.
     * @param array<string,string> $values         Resolved schema values.
     * @param string              $webpage_id     WebPage node ID.
     * @param string              $image_id       Image node ID.
     * @param string              $permalink      Product permalink.
     *
     * @return array<string,mixed>
     */
    protected function build_product_node( WP_Post $post, array $values, string $webpage_id, string $image_id, string $permalink ): array {
        $product_id = $permalink . '#product';

        $product_node = array(
            '@type'            => 'Product',
            '@id'              => $product_id,
            'name'             => wp_strip_all_tags( get_the_title( $post ) ),
            'mainEntityOfPage' => array( '@id' => $webpage_id ),
        );

        if ( '' !== $values['description'] ) {
            $product_node['description'] = $values['description'];
        }

        if ( '' !== $values['image'] ) {
            $product_node['image'] = array( '@id' => $image_id );
        }

        if ( function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $post->ID );

            if ( $product instanceof \WC_Product ) {
                $sku = $product->get_sku();
                if ( $sku ) {
                    $product_node['sku'] = $sku;
                }

                /**
                 * Filter the attribute used to determine the product brand for structured data output.
                 *
                 * @param string      $attribute_slug Default attribute slug.
                 * @param \WC_Product $product        Current product instance.
                 */
                $brand_attribute = apply_filters( 'working_with_toc_structured_data_brand_attribute', 'pa_brand', $product );
                if ( is_string( $brand_attribute ) && '' !== trim( $brand_attribute ) ) {
                    $brand = $product->get_attribute( $brand_attribute );
                    if ( is_string( $brand ) && '' !== trim( $brand ) ) {
                        $product_node['brand'] = array(
                            '@type' => 'Brand',
                            'name'  => wp_strip_all_tags( $brand ),
                        );
                    }
                }

                if ( empty( $product_node['description'] ) ) {
                    $short_description = $product->get_short_description();
                    if ( is_string( $short_description ) && '' !== trim( $short_description ) ) {
                        $product_node['description'] = wp_strip_all_tags( $short_description );
                    }
                }

                $price = $product->get_price();
                if ( '' !== $price ) {
                    $price_value = (string) $price;
                    if ( is_numeric( $price ) ) {
                        $decimals    = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
                        $price_value = number_format( (float) $price, $decimals, '.', '' );
                    }

                    $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : get_option( 'woocommerce_currency', 'USD' );
                    if ( '' === $currency ) {
                        $currency = 'USD';
                    }

                    $offer = array(
                        '@type'         => 'Offer',
                        'price'         => $price_value,
                        'priceCurrency' => $currency,
                        'url'           => $permalink,
                    );

                    $availability_map = array(
                        'instock'     => 'https://schema.org/InStock',
                        'outofstock'  => 'https://schema.org/OutOfStock',
                        'onbackorder' => 'https://schema.org/BackOrder',
                    );

                    $stock_status = $product->get_stock_status();
                    if ( isset( $availability_map[ $stock_status ] ) ) {
                        $offer['availability'] = $availability_map[ $stock_status ];
                    }

                    $product_node['offers'] = $offer;
                }
            }
        }

        return $product_node;
    }

    /**
     * Resolve schema values combining automatic data, overrides, and fallbacks.
     *
     * @param WP_Post $post Post object.
     *
     * @return array<string,string>
     */
    protected function resolve_schema_values( WP_Post $post ): array {
        $overrides = $this->settings->get_post_schema_overrides( $post );
        $fallbacks = $this->settings->get_schema_fallbacks( $post->post_type );

        $headline = $overrides['headline'];
        if ( '' === $headline ) {
            $headline = $this->resolve_automatic_headline( $post );
        }
        if ( '' === $headline ) {
            $headline = $fallbacks['headline'];
        }

        $description = $overrides['description'];
        if ( '' === $description ) {
            $description = $this->generate_description( $post );
        }
        if ( '' === $description ) {
            $description = $fallbacks['description'];
        }

        $image = $overrides['image'];
        if ( '' === $image ) {
            $image = $this->resolve_primary_image( $post );
        }
        if ( '' === $image ) {
            $image = $fallbacks['image'];
        }

        return array(
            'headline'    => $headline,
            'description' => $description,
            'image'       => $image,
        );
    }

    /**
     * Generate a description from the post content.
     */
    protected function generate_description( WP_Post $post ): string {
        if ( has_excerpt( $post ) ) {
            $excerpt = get_the_excerpt( $post );

            if ( is_string( $excerpt ) && '' !== trim( $excerpt ) ) {
                return wp_strip_all_tags( $excerpt );
            }
        }

        $content = get_post_field( 'post_content', $post );

        if ( ! is_string( $content ) ) {
            return '';
        }

        $content = wp_strip_all_tags( $content );

        if ( '' === $content ) {
            return '';
        }

        return wp_trim_words( $content, 30, '' );
    }

    /**
     * Determine the primary image URL for the post.
     */
    protected function resolve_primary_image( WP_Post $post ): string {
        $thumbnail = get_the_post_thumbnail_url( $post, 'full' );

        if ( is_string( $thumbnail ) && '' !== $thumbnail ) {
            return esc_url_raw( $thumbnail );
        }

        $logo = $this->settings->get_default_schema_image_url();

        if ( '' !== $logo ) {
            return $logo;
        }

        return '';
    }

    /**
     * Determine the automatic headline used for schema output.
     */
    protected function resolve_automatic_headline( WP_Post $post ): string {
        $title = get_the_title( $post );

        if ( is_string( $title ) && '' !== trim( $title ) ) {
            return wp_strip_all_tags( $title );
        }

        if ( function_exists( 'wp_get_document_title' ) ) {
            $document_title = wp_get_document_title();

            if ( is_string( $document_title ) && '' !== trim( $document_title ) ) {
                return wp_strip_all_tags( $document_title );
            }
        }

        return '';
    }

    /**
     * Build breadcrumb list data for the post.
     */
    protected function build_breadcrumb_list( WP_Post $post, string $breadcrumb_id ): array {
        $position = 1;
        $items    = array();

        $items[] = array(
            '@type'    => 'ListItem',
            'position' => $position++,
            'name'     => get_bloginfo( 'name' ),
            'item'     => home_url( '/' ),
        );

        if ( is_post_type_hierarchical( $post->post_type ) ) {
            $ancestors = array_reverse( get_post_ancestors( $post ) );

            foreach ( $ancestors as $ancestor_id ) {
                $items[] = array(
                    '@type'    => 'ListItem',
                    'position' => $position++,
                    'name'     => wp_strip_all_tags( get_the_title( $ancestor_id ) ),
                    'item'     => get_permalink( $ancestor_id ),
                );
            }
        } elseif ( 'post' === $post->post_type ) {
            $categories = get_the_category( $post->ID );

            if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
                $primary = $categories[0];

                $items[] = array(
                    '@type'    => 'ListItem',
                    'position' => $position++,
                    'name'     => $primary->name,
                    'item'     => get_category_link( $primary ),
                );
            }
        }

        $items[] = array(
            '@type'    => 'ListItem',
            'position' => $position,
            'name'     => wp_strip_all_tags( get_the_title( $post ) ),
            'item'     => get_permalink( $post ),
        );

        return array(
            '@type'           => 'BreadcrumbList',
            '@id'             => $breadcrumb_id,
            'itemListElement' => $items,
        );
    }

    /**
     * Determine the schema.org type for the main content.
     */
    protected function get_article_type( WP_Post $post ): string {
        if ( 'page' === $post->post_type ) {
            return 'WebPage';
        }

        return 'Article';
    }

    /**
     * Check whether a supported SEO plugin is active.
     */
    protected function is_seo_plugin_active(): bool {
        return defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_VERSION' );
    }
}

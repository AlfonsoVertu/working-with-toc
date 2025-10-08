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
     * Additional schema nodes collected while building the fallback graph.
     *
     * @var array<int,array<string,mixed>>
     */
    protected $additional_graph_nodes = array();

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
        if ( empty( $schema['item_list'] ) && empty( $schema['fallback_graph'] ) ) {
            return $graph;
        }

        if ( empty( $graph ) || ! is_array( $graph ) ) {
            $graph = array();
        }

        if ( ! empty( $schema['item_list'] ) ) {
            $item_list    = $schema['item_list'];
            $item_list_id = is_string( $item_list['@id'] ?? null ) ? $item_list['@id'] : '';

            if ( $item_list_id ) {
                $parent_ids = $this->add_item_list_reference_to_schema_nodes( $graph, $item_list_id );
                $item_list  = $this->link_item_list_to_parents( $item_list, $parent_ids );
            }

            $graph[] = $item_list;
        }

        if ( ! empty( $schema['faq'] ) ) {
            $faq    = $schema['faq'];
            $faq_id = is_string( $faq['@id'] ?? null ) ? $faq['@id'] : '';

            if ( $faq_id ) {
                $parent_ids = $this->add_faq_reference_to_schema_nodes( $graph, $faq_id );
                $faq        = $this->link_faq_to_parents( $faq, $parent_ids );
            }

            $graph[] = $faq;
        }

        $graph = $this->ensure_missing_schema_nodes( $graph, $schema, $post, 'Yoast' );

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
        if ( empty( $schema['item_list'] ) && empty( $schema['fallback_graph'] ) ) {
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

        if ( ! empty( $schema['item_list'] ) ) {
            $item_list    = $schema['item_list'];
            $item_list_id = is_string( $item_list['@id'] ?? null ) ? $item_list['@id'] : '';

            if ( $item_list_id ) {
                $parent_ids = $this->add_item_list_reference_to_schema_nodes( $data['@graph'], $item_list_id );
                $item_list  = $this->link_item_list_to_parents( $item_list, $parent_ids );
            }

            $data['@graph'][] = $item_list;
        }

        if ( ! empty( $schema['faq'] ) ) {
            $faq    = $schema['faq'];
            $faq_id = is_string( $faq['@id'] ?? null ) ? $faq['@id'] : '';

            if ( $faq_id ) {
                $parent_ids = $this->add_faq_reference_to_schema_nodes( $data['@graph'], $faq_id );
                $faq        = $this->link_faq_to_parents( $faq, $parent_ids );
            }

            $data['@graph'][] = $faq;
        }

        $data['@graph'] = $this->ensure_missing_schema_nodes( $data['@graph'], $schema, $post, 'Rank Math' );

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

            $node_types = $this->normalize_type_list( $node['@type'] ?? array() );

            if ( ! $this->types_match_any( $node_types, $types ) ) {
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

            $node_types = $this->normalize_type_list( $node['@type'] ?? array() );

            if ( ! $this->types_match_any( $node_types, $types ) ) {
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
     * Ensure missing schema nodes from the fallback graph are injected into the SEO graph.
     *
     * @param array<int|string,mixed>        $graph   Existing graph nodes.
     * @param array<string,mixed>            $schema  Generated schema data.
     * @param WP_Post                        $post    Current post object.
     * @param string                         $source  Integration source label for logging.
     *
     * @return array<int|string,mixed>
     */
    protected function ensure_missing_schema_nodes( array $graph, array $schema, WP_Post $post, string $source ): array {
        if ( empty( $schema['fallback_graph'] ) || ! is_array( $schema['fallback_graph'] ) ) {
            return $graph;
        }

        $fallback_nodes = $schema['fallback_graph']['@graph'] ?? array();

        if ( empty( $fallback_nodes ) || ! is_array( $fallback_nodes ) ) {
            return $graph;
        }

        $index       = $this->build_schema_index( $graph );
        $added_types = array();

        foreach ( $fallback_nodes as $fallback_node ) {
            if ( ! is_array( $fallback_node ) ) {
                continue;
            }

            $node_types = $this->normalize_type_list( $fallback_node['@type'] ?? array() );
            if ( empty( $node_types ) ) {
                continue;
            }

            $manageable_types = $this->filter_manageable_schema_types( $node_types );
            if ( empty( $manageable_types ) ) {
                continue;
            }

            $node_id = isset( $fallback_node['@id'] ) && is_string( $fallback_node['@id'] ) ? $fallback_node['@id'] : '';

            if ( '' !== $node_id && isset( $index['ids'][ $node_id ] ) ) {
                continue;
            }

            $needs_injection = false;

            foreach ( $manageable_types as $type ) {
                if ( ! $this->has_schema_type( $index['types'], $type ) ) {
                    $needs_injection = true;
                    break;
                }
            }

            if ( ! $needs_injection ) {
                continue;
            }

            $graph[] = $fallback_node;

            if ( '' !== $node_id ) {
                $index['ids'][ $node_id ] = true;
            }

            foreach ( $node_types as $type ) {
                $index['types'][ $type ] = true;
            }

            foreach ( $manageable_types as $type ) {
                $added_types[ $type ] = true;
            }
        }

        if ( ! empty( $added_types ) ) {
            Logger::log(
                sprintf(
                    'Added fallback schema nodes (%s) to %s graph for post ID %d',
                    implode( ', ', array_keys( $added_types ) ),
                    $source,
                    $post->ID
                )
            );
        }

        return $graph;
    }

    /**
     * Build a lookup index for schema nodes.
     *
     * @param array<int|string,mixed> $graph Graph nodes to inspect.
     *
     * @return array{ids:array<string,bool>,types:array<string,bool>}
     */
    protected function build_schema_index( array $graph ): array {
        $index = array(
            'ids'   => array(),
            'types' => array(),
        );

        foreach ( $graph as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }

            $node_id = isset( $node['@id'] ) && is_string( $node['@id'] ) ? $node['@id'] : '';

            if ( '' !== $node_id ) {
                $index['ids'][ $node_id ] = true;
            }

            $types = $this->normalize_type_list( $node['@type'] ?? array() );

            foreach ( $types as $type ) {
                $index['types'][ $type ] = true;
            }
        }

        return $index;
    }

    /**
     * Normalize schema type values into a flat list of strings.
     *
     * @param mixed $types Raw type value.
     *
     * @return array<int,string>
     */
    protected function normalize_type_list( $types ): array {
        if ( is_string( $types ) ) {
            $types = trim( $types );

            return '' !== $types ? array( $types ) : array();
        }

        if ( ! is_array( $types ) ) {
            return array();
        }

        $normalized = array();

        foreach ( $types as $type ) {
            if ( ! is_string( $type ) ) {
                continue;
            }

            $type = trim( $type );

            if ( '' === $type ) {
                continue;
            }

            $normalized[] = $type;
        }

        return $normalized;
    }

    /**
     * Determine which of the provided types are handled by the plugin.
     *
     * @param array<int,string> $types Schema types detected on a node.
     *
     * @return array<int,string> Base types managed by the plugin.
     */
    protected function filter_manageable_schema_types( array $types ): array {
        $manageable = array();
        $map        = $this->get_manageable_schema_type_map();

        foreach ( $map as $base_type => $equivalents ) {
            foreach ( $types as $type ) {
                if ( $this->is_equivalent_schema_type( $type, $base_type, $equivalents ) ) {
                    if ( ! in_array( $base_type, $manageable, true ) ) {
                        $manageable[] = $base_type;
                    }
                    break;
                }
            }
        }

        return $manageable;
    }

    /**
     * Check whether a schema type already exists within the graph.
     *
     * @param array<string,bool> $existing_types Indexed schema types from the graph.
     * @param string             $type           Base type to check.
     */
    protected function has_schema_type( array $existing_types, string $type ): bool {
        $map         = $this->get_manageable_schema_type_map();
        $equivalents = $map[ $type ] ?? array( $type );

        foreach ( $existing_types as $existing_type => $present ) {
            if ( ! $present || ! is_string( $existing_type ) ) {
                continue;
            }

            if ( $this->is_equivalent_schema_type( $existing_type, $type, $equivalents ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map base schema types to the equivalent variants handled by the plugin.
     *
     * @return array<string,array<int,string>>
     */
    protected function get_manageable_schema_type_map(): array {
        return array(
            'Organization'   => array( 'Organization', 'LocalBusiness', 'Corporation', 'NewsMediaOrganization', 'EducationalOrganization', 'GovernmentOrganization', 'MedicalOrganization', 'Airline', 'SportsOrganization', 'PerformingGroup', 'NGO', 'Project' ),
            'WebSite'        => array( 'WebSite' ),
            'WebPage'        => array( 'WebPage', 'CollectionPage', 'ProfilePage', 'AboutPage', 'ContactPage', 'SearchResultsPage', 'ItemPage', 'CheckoutPage' ),
            'Article'        => array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report', 'SocialMediaPosting', 'LiveBlogPosting' ),
            'Product'        => array( 'Product', 'ProductModel', 'ProductGroup', 'IndividualProduct', 'Vehicle', 'ProductVariation' ),
            'BreadcrumbList' => array( 'BreadcrumbList' ),
            'ItemList'       => array( 'ItemList' ),
            'FAQPage'        => array( 'FAQPage', 'QAPage' ),
            'ImageObject'    => array( 'ImageObject', 'Photograph' ),
            'VideoObject'    => array( 'VideoObject', 'MusicVideoObject', 'Movie' ),
        );
    }

    /**
     * Determine whether a node type matches a given base type.
     *
     * @param string                     $candidate   Type detected on a schema node.
     * @param string                     $base_type   Target base type.
     * @param array<int,string>|null     $equivalents Optional list of preconfigured equivalents.
     */
    protected function is_equivalent_schema_type( string $candidate, string $base_type, ?array $equivalents = null ): bool {
        if ( '' === $candidate || '' === $base_type ) {
            return false;
        }

        if ( null === $equivalents ) {
            $map         = $this->get_manageable_schema_type_map();
            $equivalents = $map[ $base_type ] ?? array( $base_type );
        }

        if ( in_array( $candidate, $equivalents, true ) ) {
            return true;
        }

        switch ( $base_type ) {
            case 'Article':
                return $this->string_ends_with( $candidate, 'Article' );
            case 'Organization':
                return $this->string_ends_with( $candidate, 'Organization' ) || $this->string_ends_with( $candidate, 'Business' );
            case 'WebPage':
                return $this->string_ends_with( $candidate, 'Page' );
            case 'Product':
                return $this->string_ends_with( $candidate, 'Product' );
            case 'ImageObject':
                return $this->string_ends_with( $candidate, 'ImageObject' );
            case 'VideoObject':
                return $this->string_ends_with( $candidate, 'VideoObject' );
            default:
                return false;
        }
    }

    /**
     * Check whether any of the provided node types match the target base types.
     *
     * @param array<int,string> $node_types Types detected on a schema node.
     * @param array<int,string> $targets    Base types to match against.
     */
    protected function types_match_any( array $node_types, array $targets ): bool {
        if ( empty( $node_types ) || empty( $targets ) ) {
            return false;
        }

        foreach ( $targets as $target ) {
            foreach ( $node_types as $node_type ) {
                if ( $this->is_equivalent_schema_type( $node_type, $target ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine whether a string ends with the provided suffix.
     *
     * @param string $haystack String to inspect.
     * @param string $needle   Expected suffix.
     */
    protected function string_ends_with( string $haystack, string $needle ): bool {
        if ( '' === $needle ) {
            return true;
        }

        $needle_length = strlen( $needle );

        if ( $needle_length > strlen( $haystack ) ) {
            return false;
        }

        return substr( $haystack, -$needle_length ) === $needle;
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
        $this->additional_graph_nodes = array();

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
     * @param array<string,mixed> $item_list   ItemList node.
     * @param array{headline:string,description:string,image:string,video:array<string,string>} $values Resolved schema values.
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
        $video_id         = $permalink . '#primaryvideo';

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

        $image_node = $this->build_image_object( $values['image'], $image_id );

        if ( ! empty( $image_node ) ) {
            $webpage['primaryImageOfPage'] = array( '@id' => $image_id );
        }

        $article_type = $this->get_article_type( $post );

        if ( 'WebPage' === $article_type ) {
            $webpage['hasPart']   = array( array( '@id' => $item_list_id ) );
            $webpage['publisher'] = array( '@id' => $organization_id );

            if ( ! empty( $image_node ) ) {
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

        $video_node = array();
        $video_data = is_array( $values['video'] ?? null ) ? $values['video'] : array();

        if ( ! empty( $video_data ) ) {
            $video_node = $this->build_video_object( $video_data, $video_id, $values, $organization_id, $post );

            if ( ! empty( $video_node ) ) {
                $webpage['video'] = array( '@id' => $video_id );
            }
        }

        $product_node = array();
        $article_node = array();

        if ( 'product' === $post->post_type ) {
            $product_node = $this->build_product_node( $post, $values, $webpage_id, $image_id, $permalink, ! empty( $video_node ) ? $video_id : '' );

            $parent_ids = array();

            if ( ! empty( $product_node ) ) {
                $product_id = is_string( $product_node['@id'] ?? null ) ? $product_node['@id'] : '';

                if ( '' !== $product_id && '' !== $item_list_id ) {
                    $has_part = $this->normalize_reference_list( $product_node['hasPart'] ?? array() );
                    $has_part = $this->add_reference_if_missing( $has_part, $item_list_id );

                    if ( ! empty( $has_part ) ) {
                        $product_node['hasPart'] = $has_part;
                    }

                    $parent_ids[] = $product_id;
                }
            }

            if ( empty( $parent_ids ) && '' !== $item_list_id ) {
                $webpage_has_part = $this->normalize_reference_list( $webpage['hasPart'] ?? array() );
                $webpage_has_part = $this->add_reference_if_missing( $webpage_has_part, $item_list_id );

                if ( ! empty( $webpage_has_part ) ) {
                    $webpage['hasPart'] = $webpage_has_part;
                }

                $parent_ids[] = $webpage_id;
            }

            if ( ! empty( $parent_ids ) ) {
                $item_list = $this->link_item_list_to_parents( $item_list, $parent_ids );
            }
        } elseif ( 'WebPage' !== $article_type ) {
            $article_id   = $permalink . '#article';
            $article_node = array(
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
                    $article_main_entity = $this->normalize_reference_list( $article_node['mainEntity'] ?? array() );
                    $article_main_entity = $this->add_reference_if_missing( $article_main_entity, $faq_id );

                    if ( ! empty( $article_main_entity ) ) {
                        $article_node['mainEntity'] = $article_main_entity;
                    }
                }
            }

            if ( '' !== $values['description'] ) {
                $article_node['description'] = $values['description'];
            }

            if ( ! empty( $image_node ) ) {
                $article_node['image'] = array( '@id' => $image_id );
            }

            if ( ! empty( $video_node ) ) {
                $article_node['video'] = array( '@id' => $video_id );
            }

            $published = get_post_time( DATE_W3C, true, $post );
            if ( $published ) {
                $article_node['datePublished'] = $published;
            }

            $modified = get_post_modified_time( DATE_W3C, true, $post );
            if ( $modified ) {
                $article_node['dateModified'] = $modified;
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

                $article_node['author'] = $author;
            }

            $article_node['publisher'] = array( '@id' => $organization_id );
        }

        $graph[] = $webpage;

        if ( ! empty( $breadcrumb['itemListElement'] ) ) {
            $graph[] = $breadcrumb;
        }

        if ( ! empty( $product_node ) ) {
            $graph[] = $product_node;
        } elseif ( ! empty( $article_node ) ) {
            $graph[] = $article_node;
        }

        if ( ! empty( $image_node ) ) {
            $graph[] = $image_node;
        }

        if ( ! empty( $video_node ) ) {
            $graph[] = $video_node;
        }

        $item_list['@id'] = $item_list_id;
        $graph[]          = $item_list;

        if ( ! empty( $faq ) ) {
            $graph[] = $faq;
        }

        if ( ! empty( $this->additional_graph_nodes ) ) {
            $graph = array_merge( $graph, $this->additional_graph_nodes );
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
     * @param array{headline:string,description:string,image:string,video:array<string,string>} $values Resolved schema values.
     * @param string              $webpage_id     WebPage node ID.
     * @param string              $image_id       Image node ID.
     * @param string              $permalink      Product permalink.
     * @param string              $video_id       Video node ID when available.
     *
     * @return array<string,mixed>
     */
    protected function build_product_node( WP_Post $post, array $values, string $webpage_id, string $image_id, string $permalink, string $video_id = '' ): array {
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

        if ( '' !== $video_id ) {
            $product_node['video'] = array( '@id' => $video_id );
        }

        if ( function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $post->ID );

            if ( $product instanceof \WC_Product ) {
                $schema_settings = $this->settings->get_product_structured_data_settings();

                $sku = $this->resolve_product_sku( $product, $schema_settings );
                if ( '' !== $sku ) {
                    $product_node['sku'] = $sku;
                }

                $mpn = $this->resolve_product_mpn( $product, $schema_settings, $sku );
                if ( '' !== $mpn ) {
                    $product_node['mpn'] = $mpn;
                }

                $gtin_values = $this->resolve_product_gtin_values( $product );
                foreach ( $gtin_values as $gtin_key => $gtin_value ) {
                    $product_node[ $gtin_key ] = $gtin_value;
                }

                $brand_data = $this->resolve_product_brand_data( $product, $schema_settings );
                if ( ! empty( $brand_data['node'] ) ) {
                    $product_node['brand'] = $brand_data['node'];
                }

                if ( empty( $product_node['description'] ) ) {
                    $short_description = $product->get_short_description();
                    if ( is_string( $short_description ) && '' !== trim( $short_description ) ) {
                        $product_node['description'] = wp_strip_all_tags( $short_description );
                    }
                }

                $similar_products = $this->resolve_similar_products( $product );
                if ( ! empty( $similar_products ) ) {
                    $product_node['isSimilarTo'] = $similar_products;
                }

                $aggregate_rating = $this->build_product_aggregate_rating( $product );
                if ( ! empty( $aggregate_rating ) ) {
                    $product_node['aggregateRating'] = $aggregate_rating;
                }

                $reviews = $this->build_product_reviews( $product );
                if ( ! empty( $reviews ) ) {
                    $product_node['review'] = $reviews;
                }

                $currency         = $this->get_product_currency();
                $shipping_details = $this->build_shipping_details( $product, $currency );
                $offers           = $this->build_product_offers( $product, $permalink, $schema_settings, $shipping_details );

                if ( ! empty( $offers ) ) {
                    $product_node['offers'] = $offers;
                }

                $variants = $this->build_product_variations( $product, $permalink, $product_id, $schema_settings, $shipping_details );

                if ( ! empty( $variants['references'] ) ) {
                    $product_node['hasVariant'] = $variants['references'];
                }

                if ( ! empty( $variants['nodes'] ) ) {
                    $this->additional_graph_nodes = array_merge( $this->additional_graph_nodes, $variants['nodes'] );
                }
            }
        }

        return $product_node;
    }

    /**
     * Determine the SKU to expose for a product.
     *
     * @param \WC_Product $product         Product instance.
     * @param array        $schema_settings Schema configuration.
     */
    protected function resolve_product_sku( \WC_Product $product, array $schema_settings ): string {
        $sku = $product->get_sku();

        if ( '' === $sku && ! empty( $schema_settings['allow_id_as_sku'] ) ) {
            $sku = (string) $product->get_id();
        }

        /**
         * Filter the SKU value used for structured data.
         *
         * @param string      $sku             Resolved SKU value.
         * @param \WC_Product $product         Product instance.
         * @param array       $schema_settings Schema configuration.
         */
        $sku = apply_filters( 'working_with_toc_structured_data_sku', $sku, $product, $schema_settings );

        if ( ! is_string( $sku ) ) {
            return '';
        }

        $sku = sanitize_text_field( $sku );

        return '' !== $sku ? $sku : '';
    }

    /**
     * Resolve the MPN value for a product.
     *
     * @param \WC_Product $product         Product instance.
     * @param array        $schema_settings Schema configuration.
     * @param string       $sku             Previously resolved SKU.
     */
    protected function resolve_product_mpn( \WC_Product $product, array $schema_settings, string $sku ): string {
        $mpn = $product->get_meta( '_mpn', true );

        if ( '' === $mpn ) {
            $mpn = $product->get_meta( 'mpn', true );
        }

        if ( '' === $mpn ) {
            $mpn = $sku;
        }

        /**
         * Filter the MPN value exposed for structured data.
         *
         * @param string      $mpn             Resolved MPN value.
         * @param \WC_Product $product         Product instance.
         * @param array       $schema_settings Schema configuration.
         */
        $mpn = apply_filters( 'working_with_toc_structured_data_mpn', $mpn, $product, $schema_settings );

        if ( ! is_string( $mpn ) ) {
            return '';
        }

        $mpn = sanitize_text_field( $mpn );

        return '' !== $mpn ? $mpn : '';
    }

    /**
     * Retrieve GTIN values associated with a product.
     *
     * @param \WC_Product $product Product instance.
     *
     * @return array<string,string>
     */
    protected function resolve_product_gtin_values( \WC_Product $product ): array {
        $meta_map = array(
            'gtin8'  => '_gtin8',
            'gtin12' => '_gtin12',
            'gtin13' => '_gtin13',
            'gtin14' => '_gtin14',
            'gtin'   => '_gtin',
        );

        /**
         * Filter the meta keys inspected to resolve GTIN values.
         *
         * @param array<string,string> $meta_map Default meta key map.
         * @param \WC_Product          $product Product instance.
         */
        $meta_map = apply_filters( 'working_with_toc_structured_data_gtin_meta_map', $meta_map, $product );

        if ( ! is_array( $meta_map ) ) {
            return array();
        }

        $gtins = array();

        foreach ( $meta_map as $property => $meta_key ) {
            if ( ! is_string( $property ) || '' === $property ) {
                continue;
            }

            $meta_value = '';

            if ( is_string( $meta_key ) && '' !== $meta_key ) {
                $meta_value = $product->get_meta( $meta_key, true );
            }

            if ( '' === $meta_value && taxonomy_exists( $meta_key ) ) {
                $meta_value = $product->get_attribute( $meta_key );
            }

            if ( ! is_string( $meta_value ) || '' === $meta_value ) {
                continue;
            }

            $sanitized = $this->sanitize_gtin_value( $meta_value );

            if ( '' === $sanitized ) {
                continue;
            }

            $gtins[ $property ] = $sanitized;
        }

        /**
         * Allow filtering the resolved GTIN values before they are used.
         *
         * @param array<string,string> $gtins   Resolved GTIN data.
         * @param \WC_Product          $product Product instance.
         */
        $gtins = apply_filters( 'working_with_toc_structured_data_gtin_values', $gtins, $product );

        return is_array( $gtins ) ? $gtins : array();
    }

    /**
     * Resolve brand data for a product, providing both the schema node and name.
     *
     * @param \WC_Product $product         Product instance.
     * @param array        $schema_settings Schema configuration.
     *
     * @return array{node?:array<string,string>,name:string}
     */
    protected function resolve_product_brand_data( \WC_Product $product, array $schema_settings ): array {
        $brand_name = '';

        $brand_attribute = isset( $schema_settings['brand_attribute'] ) ? (string) $schema_settings['brand_attribute'] : '';

        /**
         * Filter the attribute slug used to detect the product brand.
         *
         * @param string      $brand_attribute Attribute slug.
         * @param \WC_Product $product         Product instance.
         * @param array       $schema_settings Schema configuration.
         */
        $brand_attribute = apply_filters( 'working_with_toc_structured_data_brand_attribute', $brand_attribute, $product, $schema_settings );

        if ( '' !== $brand_attribute ) {
            $brand_value = $product->get_attribute( $brand_attribute );

            if ( is_string( $brand_value ) && '' !== trim( $brand_value ) ) {
                $brand_name = wp_strip_all_tags( $brand_value );
            }
        }

        if ( '' === $brand_name && ! empty( $schema_settings['fallback_brand'] ) ) {
            $brand_name = (string) $schema_settings['fallback_brand'];
        }

        /**
         * Filter the brand name detected for structured data.
         *
         * @param string      $brand_name      Resolved brand label.
         * @param \WC_Product $product         Product instance.
         * @param array       $schema_settings Schema configuration.
         */
        $brand_name = apply_filters( 'working_with_toc_structured_data_brand_name', $brand_name, $product, $schema_settings );

        if ( ! is_string( $brand_name ) ) {
            $brand_name = '';
        }

        $brand_name = trim( wp_strip_all_tags( $brand_name ) );

        $brand_node = array();

        if ( '' !== $brand_name ) {
            $brand_node = array(
                '@type' => 'Brand',
                'name'  => $brand_name,
            );
        }

        return array(
            'node' => $brand_node,
            'name' => $brand_name,
        );
    }

    /**
     * Resolve the list of similar products for a given product.
     *
     * @param \WC_Product $product Product instance.
     *
     * @return array<int,array<string,string>>
     */
    protected function resolve_similar_products( \WC_Product $product ): array {
        $related_ids = array();

        if ( function_exists( 'wc_get_related_products' ) ) {
            $related_ids = wc_get_related_products( $product->get_id(), 8 );
        }

        if ( method_exists( $product, 'get_cross_sell_ids' ) ) {
            $related_ids = array_merge( $related_ids, $product->get_cross_sell_ids() );
        }

        if ( method_exists( $product, 'get_upsell_ids' ) ) {
            $related_ids = array_merge( $related_ids, $product->get_upsell_ids() );
        }

        $related_ids = array_values( array_unique( array_filter( array_map( 'absint', $related_ids ) ) ) );

        $similar = array();

        foreach ( array_slice( $related_ids, 0, 8 ) as $related_id ) {
            $permalink = get_permalink( $related_id );

            if ( ! $permalink ) {
                continue;
            }

            $similar[] = array(
                '@type' => 'Product',
                '@id'   => $permalink . '#product',
            );
        }

        /**
         * Filter the list of similar products exposed in structured data.
         *
         * @param array<int,array<string,string>> $similar Similar product references.
         * @param \WC_Product                     $product Product instance.
         */
        $similar = apply_filters( 'working_with_toc_structured_data_similar_products', $similar, $product );

        return is_array( $similar ) ? $similar : array();
    }

    /**
     * Build the aggregate rating node for a product when review data is available.
     *
     * @param \WC_Product $product Product instance.
     *
     * @return array<string,mixed>
     */
    protected function build_product_aggregate_rating( \WC_Product $product ): array {
        $rating_count = (int) $product->get_rating_count();
        $average      = (float) $product->get_average_rating();
        $review_count = (int) $product->get_review_count();

        if ( $rating_count <= 0 || $average <= 0 ) {
            return array();
        }

        if ( $review_count <= 0 ) {
            $review_count = $rating_count;
        }

        $aggregate = array(
            '@type'       => 'AggregateRating',
            'ratingValue' => number_format( $average, 2, '.', '' ),
            'ratingCount' => $rating_count,
            'reviewCount' => $review_count,
        );

        /**
         * Filter the aggregate rating node for structured data.
         *
         * @param array<string,mixed> $aggregate Aggregate rating data.
         * @param \WC_Product         $product   Product instance.
         */
        $aggregate = apply_filters( 'working_with_toc_structured_data_aggregate_rating', $aggregate, $product );

        return is_array( $aggregate ) ? $aggregate : array();
    }

    /**
     * Build review nodes using approved WooCommerce product reviews.
     *
     * @param \WC_Product $product Product instance.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function build_product_reviews( \WC_Product $product ): array {
        $args = array(
            'post_id' => $product->get_id(),
            'status'  => 'approve',
            'type'    => 'review',
            'number'  => apply_filters( 'working_with_toc_structured_data_review_limit', 20, $product ),
        );

        $comments = get_comments( $args );

        if ( empty( $comments ) ) {
            return array();
        }

        $reviews = array();

        foreach ( $comments as $comment ) {
            if ( ! $comment instanceof \WP_Comment ) {
                continue;
            }

            $rating = get_comment_meta( $comment->comment_ID, 'rating', true );

            if ( '' === $rating ) {
                continue;
            }

            $rating_value = is_numeric( $rating ) ? number_format( (float) $rating, 1, '.', '' ) : '';

            if ( '' === $rating_value ) {
                continue;
            }

            $author_name = $comment->comment_author ? wp_strip_all_tags( $comment->comment_author ) : '';

            $date_published = mysql_to_rfc3339( $comment->comment_date_gmt );
            if ( ! $date_published ) {
                $timestamp = strtotime( $comment->comment_date_gmt );
                if ( false !== $timestamp ) {
                    $date_published = gmdate( DATE_W3C, $timestamp );
                } else {
                    $date_published = '';
                }
            }

            $review_body = wp_strip_all_tags( $comment->comment_content );

            if ( '' === $date_published ) {
                continue;
            }

            $review = array(
                '@type'         => 'Review',
                'datePublished' => $date_published,
                'reviewBody'    => $review_body,
                'reviewRating'  => array(
                    '@type'       => 'Rating',
                    'ratingValue' => $rating_value,
                    'bestRating'  => '5',
                    'worstRating' => '1',
                ),
            );

            if ( '' !== $author_name ) {
                $review['author'] = array(
                    '@type' => 'Person',
                    'name'  => $author_name,
                );
            }

            if ( '' !== $review_body ) {
                $reviews[] = $review;
            }
        }

        /**
         * Filter the list of review nodes included in structured data.
         *
         * @param array<int,array<string,mixed>> $reviews Review nodes.
         * @param \WC_Product                    $product Product instance.
         */
        $reviews = apply_filters( 'working_with_toc_structured_data_reviews', $reviews, $product );

        return is_array( $reviews ) ? $reviews : array();
    }

    /**
     * Retrieve the store currency used for structured data output.
     */
    protected function get_product_currency(): string {
        $currency = '';

        if ( function_exists( 'get_woocommerce_currency' ) ) {
            $currency = get_woocommerce_currency();
        }

        if ( '' === $currency ) {
            $currency = (string) get_option( 'woocommerce_currency', 'USD' );
        }

        if ( '' === $currency ) {
            $currency = 'USD';
        }

        /**
         * Filter the currency code used for structured data.
         *
         * @param string $currency Currency ISO code.
         */
        $currency = apply_filters( 'working_with_toc_structured_data_currency', $currency );

        if ( ! is_string( $currency ) ) {
            $currency = 'USD';
        }

        return strtoupper( sanitize_text_field( $currency ) );
    }

    /**
     * Determine the number of decimal places to use for price formatting.
     */
    protected function get_price_decimals(): int {
        $decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

        if ( ! is_numeric( $decimals ) ) {
            $decimals = 2;
        }

        return max( 0, (int) $decimals );
    }

    /**
     * Build offer data for the current product.
     *
     * @param \WC_Product              $product          Product instance.
     * @param string                    $permalink        Product permalink.
     * @param array<string,mixed>       $schema_settings  Schema configuration.
     * @param array<int,array<string,mixed>> $shipping_details Shipping data.
     *
     * @return array<string,mixed>
     */
    protected function build_product_offers( \WC_Product $product, string $permalink, array $schema_settings, array $shipping_details ): array {
        $currency             = $this->get_product_currency();
        $availability_map     = $this->get_schema_availability_map();
        $condition            = $this->resolve_product_condition_schema( $schema_settings );
        $sanitized_permalink  = $this->settings->sanitize_url( $permalink, '' );

        if ( $product->is_type( 'variable' ) ) {
            $prices = method_exists( $product, 'get_variation_prices' ) ? $product->get_variation_prices( true ) : array();
            $price_list = isset( $prices['price'] ) && is_array( $prices['price'] ) ? $prices['price'] : array();

            $numeric_prices = array();

            foreach ( $price_list as $value ) {
                if ( '' === $value ) {
                    continue;
                }

                $numeric_prices[] = (float) $value;
            }

            if ( empty( $numeric_prices ) ) {
                return array();
            }

            $offer = array(
                '@type'         => 'AggregateOffer',
                'priceCurrency' => $currency,
                'offerCount'    => count( $numeric_prices ),
                'lowPrice'      => $this->format_price_value( min( $numeric_prices ) ),
                'highPrice'     => $this->format_price_value( max( $numeric_prices ) ),
            );

            if ( '' !== $sanitized_permalink ) {
                $offer['url'] = $sanitized_permalink;
            }

            $stock_status = $product->get_stock_status();
            if ( isset( $availability_map[ $stock_status ] ) ) {
                $offer['availability'] = $availability_map[ $stock_status ];
            }

            if ( '' !== $condition ) {
                $offer['itemCondition'] = $condition;
            }

            if ( ! empty( $shipping_details ) ) {
                $offer['shippingDetails'] = $shipping_details;
            }

            /**
             * Filter the aggregate offer node for variable products.
             *
             * @param array<string,mixed> $offer           Aggregate offer data.
             * @param \WC_Product         $product         Product instance.
             * @param array<string,mixed> $schema_settings Schema configuration.
             */
            $offer = apply_filters( 'working_with_toc_structured_data_aggregate_offer', $offer, $product, $schema_settings );

            return is_array( $offer ) ? $offer : array();
        }

        $price = $product->get_price();

        if ( '' === $price ) {
            return array();
        }

        $offer = array(
            '@type'         => 'Offer',
            'price'         => $this->format_price_value( $price ),
            'priceCurrency' => $currency,
        );

        if ( '' !== $sanitized_permalink ) {
            $offer['url'] = $sanitized_permalink;
        }

        $stock_status = $product->get_stock_status();
        if ( isset( $availability_map[ $stock_status ] ) ) {
            $offer['availability'] = $availability_map[ $stock_status ];
        }

        if ( '' !== $condition ) {
            $offer['itemCondition'] = $condition;
        }

        if ( ! empty( $shipping_details ) ) {
            $offer['shippingDetails'] = $shipping_details;
        }

        /**
         * Filter the offer node for simple products.
         *
         * @param array<string,mixed> $offer           Offer data.
         * @param \WC_Product         $product         Product instance.
         * @param array<string,mixed> $schema_settings Schema configuration.
         */
        $offer = apply_filters( 'working_with_toc_structured_data_offer', $offer, $product, $schema_settings );

        return is_array( $offer ) ? $offer : array();
    }

    /**
     * Map WooCommerce stock status codes to schema.org availability URLs.
     *
     * @return array<string,string>
     */
    protected function get_schema_availability_map(): array {
        return array(
            'instock'     => 'https://schema.org/InStock',
            'outofstock'  => 'https://schema.org/OutOfStock',
            'onbackorder' => 'https://schema.org/BackOrder',
            'preorder'    => 'https://schema.org/PreOrder',
        );
    }

    /**
     * Resolve the schema.org URL representing the product condition.
     *
     * @param array<string,mixed> $schema_settings Schema configuration.
     */
    protected function resolve_product_condition_schema( array $schema_settings ): string {
        $condition = isset( $schema_settings['condition_schema'] ) ? (string) $schema_settings['condition_schema'] : '';

        /**
         * Filter the condition URL used for structured data.
         *
         * @param string $condition Condition URL.
         * @param array  $schema_settings Schema configuration.
         */
        $condition = apply_filters( 'working_with_toc_structured_data_condition', $condition, $schema_settings );

        if ( ! is_string( $condition ) ) {
            $condition = '';
        }

        $condition = trim( $condition );

        if ( '' === $condition ) {
            return '';
        }

        return esc_url_raw( $condition );
    }

    /**
     * Build shipping detail nodes based on WooCommerce shipping zones.
     *
     * @param \WC_Product $product  Product instance.
     * @param string       $currency Currency code.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function build_shipping_details( \WC_Product $product, string $currency ): array {
        if ( ! class_exists( '\\WC_Shipping_Zones' ) || ! class_exists( '\\WC_Shipping_Zone' ) || ! class_exists( '\\WC_Shipping_Method' ) ) {
            return array();
        }

        $zones = \WC_Shipping_Zones::get_zones();
        $zone_objects = array();

        foreach ( $zones as $zone_data ) {
            if ( isset( $zone_data['id'] ) ) {
                $zone_objects[] = new \WC_Shipping_Zone( (int) $zone_data['id'] );
            }
        }

        $zone_objects[] = new \WC_Shipping_Zone( 0 );

        $decimals = $this->get_price_decimals();
        $details  = array();

        foreach ( $zone_objects as $zone ) {
            if ( ! $zone instanceof \WC_Shipping_Zone ) {
                continue;
            }

            $region = array(
                '@type' => 'DefinedRegion',
                'name'  => wp_strip_all_tags( $zone->get_zone_name() ),
            );

            $methods = $zone->get_shipping_methods( true );

            foreach ( $methods as $method ) {
                if ( ! $method instanceof \WC_Shipping_Method || ! $method->is_enabled() ) {
                    continue;
                }

                $cost = $this->parse_numeric_price_value( $method->get_option( 'cost', '' ) );

                if ( null === $cost ) {
                    if ( 'free_shipping' === $method->id ) {
                        $cost = 0.0;
                    } else {
                        continue;
                    }
                }

                $detail = array(
                    '@type'               => 'OfferShippingDetails',
                    'shippingDestination' => $region,
                    'shippingRate'        => array(
                        '@type'   => 'MonetaryAmount',
                        'value'   => number_format( (float) $cost, $decimals, '.', '' ),
                        'currency'=> $currency,
                    ),
                );

                /**
                 * Filter an individual shipping detail node.
                 *
                 * @param array<string,mixed> $detail  Shipping detail data.
                 * @param \WC_Shipping_Method $method Shipping method instance.
                 * @param \WC_Shipping_Zone   $zone   Shipping zone instance.
                 * @param \WC_Product         $product Product instance.
                 */
                $detail = apply_filters( 'working_with_toc_structured_data_shipping_detail', $detail, $method, $zone, $product );

                if ( is_array( $detail ) ) {
                    $details[] = $detail;
                }
            }
        }

        /**
         * Filter the compiled list of shipping detail nodes.
         *
         * @param array<int,array<string,mixed>> $details Shipping details.
         * @param \WC_Product                    $product Product instance.
         * @param string                          $currency Currency code.
         */
        $details = apply_filters( 'working_with_toc_structured_data_shipping_details', $details, $product, $currency );

        return is_array( $details ) ? $details : array();
    }

    /**
     * Parse a numeric price value from a raw option.
     *
     * @param mixed $value Raw value.
     */
    protected function parse_numeric_price_value( $value ): ?float {
        if ( is_numeric( $value ) ) {
            return (float) $value;
        }

        if ( ! is_string( $value ) ) {
            return null;
        }

        $normalized = preg_replace( '/[^0-9\.,-]/', '', $value );

        if ( '' === $normalized ) {
            return null;
        }

        $normalized = str_replace( ',', '.', $normalized );

        return is_numeric( $normalized ) ? (float) $normalized : null;
    }

    /**
     * Build schema nodes representing product variations.
     *
     * @param \WC_Product $product          Parent product.
     * @param string       $permalink        Product permalink.
     * @param string       $product_id       Product node identifier.
     * @param array        $schema_settings  Schema configuration.
     * @param array        $shipping_details Shipping details shared with the parent product.
     *
     * @return array{references:array<int,array<string,string>>,nodes:array<int,array<string,mixed>>}
     */
    protected function build_product_variations( \WC_Product $product, string $permalink, string $product_id, array $schema_settings, array $shipping_details ): array {
        if ( ! $product->is_type( 'variable' ) ) {
            return array(
                'references' => array(),
                'nodes'      => array(),
            );
        }

        $children = method_exists( $product, 'get_children' ) ? $product->get_children() : array();

        if ( empty( $children ) ) {
            return array(
                'references' => array(),
                'nodes'      => array(),
            );
        }

        $currency         = $this->get_product_currency();
        $availability_map = $this->get_schema_availability_map();
        $condition        = $this->resolve_product_condition_schema( $schema_settings );

        $references = array();
        $nodes      = array();

        foreach ( $children as $child_id ) {
            $variation = wc_get_product( $child_id );

            if ( ! $variation instanceof \WC_Product_Variation ) {
                continue;
            }

            $variation_id = $permalink . '#product-variation-' . $variation->get_id();

            $node = array(
                '@type'       => array( 'Product', 'ProductVariation' ),
                '@id'         => $variation_id,
                'isVariantOf' => array( '@id' => $product_id ),
            );

            $name = wp_strip_all_tags( $variation->get_name() );
            if ( '' !== $name ) {
                $node['name'] = $name;
            }

            $sku = $this->resolve_product_sku( $variation, $schema_settings );
            if ( '' !== $sku ) {
                $node['sku'] = $sku;
            }

            $mpn = $this->resolve_product_mpn( $variation, $schema_settings, $sku );
            if ( '' !== $mpn ) {
                $node['mpn'] = $mpn;
            }

            $gtins = $this->resolve_product_gtin_values( $variation );
            foreach ( $gtins as $gtin_key => $gtin_value ) {
                $node[ $gtin_key ] = $gtin_value;
            }

            $attributes = method_exists( $variation, 'get_attributes' ) ? $variation->get_attributes() : array();
            $properties = array();

            foreach ( $attributes as $attribute_key => $attribute_value ) {
                if ( '' === $attribute_value ) {
                    continue;
                }

                $label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $attribute_key ) : $attribute_key;
                $properties[] = array(
                    '@type' => 'PropertyValue',
                    'name'  => wp_strip_all_tags( $label ),
                    'value' => wp_strip_all_tags( $attribute_value ),
                );
            }

            if ( ! empty( $properties ) ) {
                $node['additionalProperty'] = $properties;
            }

            $price = $variation->get_price();
            if ( '' !== $price ) {
                $offer = array(
                    '@type'         => 'Offer',
                    'price'         => $this->format_price_value( $price ),
                    'priceCurrency' => $currency,
                    'url'           => $this->settings->sanitize_url( $variation->get_permalink(), '' ),
                );

                $stock_status = $variation->get_stock_status();
                if ( isset( $availability_map[ $stock_status ] ) ) {
                    $offer['availability'] = $availability_map[ $stock_status ];
                }

                if ( '' !== $condition ) {
                    $offer['itemCondition'] = $condition;
                }

                if ( ! empty( $shipping_details ) ) {
                    $offer['shippingDetails'] = $shipping_details;
                }

                $node['offers'] = $offer;
            }

            $image_id = $variation->get_image_id();
            if ( $image_id ) {
                $image_url = wp_get_attachment_image_url( $image_id, 'full' );
                if ( $image_url ) {
                    $node['image'] = $this->settings->sanitize_url( $image_url, '' );
                }
            }

            $references[] = array( '@id' => $variation_id );
            $nodes[]      = $node;
        }

        return array(
            'references' => $references,
            'nodes'      => $nodes,
        );
    }

    /**
     * Normalise a price value for schema output.
     *
     * @param mixed $price Price value to format.
     */
    protected function format_price_value( $price ): string {
        if ( ! is_numeric( $price ) ) {
            return '';
        }

        return number_format( (float) $price, $this->get_price_decimals(), '.', '' );
    }

    /**
     * Strip non-numeric characters from a GTIN value.
     */
    protected function sanitize_gtin_value( string $value ): string {
        $digits = preg_replace( '/[^0-9]/', '', $value );

        if ( ! is_string( $digits ) ) {
            return '';
        }

        $digits = substr( $digits, 0, 14 );

        return $digits;
    }

    /**
     * Retrieve a condensed set of product pricing data for reuse in Open Graph tags.
     *
     * @param \WC_Product $product Product instance.
     *
     * @return array{price:string,currency:string,availability:string,condition:string}
     */
    public function get_product_pricing_snapshot( \WC_Product $product ): array {
        $schema_settings = $this->settings->get_product_structured_data_settings();
        $currency        = $this->get_product_currency();
        $availability    = '';

        $availability_map = $this->get_schema_availability_map();
        $stock_status     = $product->get_stock_status();
        if ( isset( $availability_map[ $stock_status ] ) ) {
            $availability = $availability_map[ $stock_status ];
        }

        $price = '';

        if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_price' ) ) {
            $price = $product->get_variation_price( 'min', true );

            if ( '' === $price ) {
                $price = $product->get_variation_price( 'max', true );
            }
        } else {
            $price = $product->get_price();
        }

        $formatted_price = $this->format_price_value( $price );

        return array(
            'price'       => $formatted_price,
            'currency'    => $currency,
            'availability'=> $availability,
            'condition'   => $this->resolve_product_condition_schema( $schema_settings ),
        );
    }

    /**
     * Return the resolved product brand label for reuse outside structured data.
     *
     * @param \WC_Product $product Product instance.
     */
    public function get_product_brand_name( \WC_Product $product ): string {
        $schema_settings = $this->settings->get_product_structured_data_settings();
        $brand_data      = $this->resolve_product_brand_data( $product, $schema_settings );

        return isset( $brand_data['name'] ) ? (string) $brand_data['name'] : '';
    }

    /**
     * Resolve schema values combining automatic data, overrides, and fallbacks.
     *
     * @param WP_Post $post Post object.
     *
     * @return array{headline:string,description:string,image:string,video:array<string,string>}
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

        $video = $this->resolve_primary_video( $post );

        return array(
            'headline'    => $headline,
            'description' => $description,
            'image'       => $image,
            'video'       => $video,
        );
    }

    /**
     * Provide resolved schema values for reuse in other integrations.
     *
     * @param WP_Post $post Post object.
     *
     * @return array{headline:string,description:string,image:string,video:array<string,string>}
     */
    public function get_resolved_schema_values( WP_Post $post ): array {
        return $this->resolve_schema_values( $post );
    }

    /**
     * Resolve primary video data for the post.
     *
     * @param WP_Post $post Post object.
     *
     * @return array<string,string>
     */
    protected function resolve_primary_video( WP_Post $post ): array {
        $video = apply_filters( 'working_with_toc_structured_data_video_data', array(), $post );

        $video = $this->normalise_video_data( $video );

        if ( ! empty( $video ) ) {
            return $video;
        }

        $content = get_post_field( 'post_content', $post );

        if ( ! is_string( $content ) || '' === trim( $content ) ) {
            return array();
        }

        if ( function_exists( 'parse_blocks' ) ) {
            $blocks = parse_blocks( $content );

            if ( ! empty( $blocks ) ) {
                $block_video = $this->extract_video_from_blocks( $blocks );
                $block_video = $this->normalise_video_data( $block_video );

                if ( ! empty( $block_video ) ) {
                    return $block_video;
                }
            }
        }

        $html_video = $this->extract_video_from_html( $content );

        return $this->normalise_video_data( $html_video );
    }

    /**
     * Normalize video data ensuring expected keys are present and sanitized.
     *
     * @param array<string,mixed> $video Raw video data.
     *
     * @return array<string,string>
     */
    protected function normalise_video_data( $video ): array {
        if ( ! is_array( $video ) ) {
            return array();
        }

        $embed_url   = isset( $video['embed_url'] ) ? $this->settings->sanitize_url( $video['embed_url'], '' ) : '';
        $content_url = isset( $video['content_url'] ) ? $this->settings->sanitize_url( $video['content_url'], '' ) : '';

        if ( '' === $embed_url && '' === $content_url ) {
            return array();
        }

        $thumbnail = isset( $video['thumbnail_url'] ) ? $this->settings->sanitize_url( $video['thumbnail_url'], '' ) : '';
        $name      = isset( $video['name'] ) ? sanitize_text_field( $video['name'] ) : '';
        $duration  = isset( $video['duration'] ) ? sanitize_text_field( $video['duration'] ) : '';
        $upload    = isset( $video['upload_date'] ) ? sanitize_text_field( $video['upload_date'] ) : '';
        $description = isset( $video['description'] ) ? sanitize_textarea_field( $video['description'] ) : '';

        return array(
            'embed_url'     => $embed_url,
            'content_url'   => $content_url,
            'thumbnail_url' => $thumbnail,
            'name'          => $name,
            'description'   => $description,
            'duration'      => $duration,
            'upload_date'   => $upload,
        );
    }

    /**
     * Extract video data from parsed block array.
     *
     * @param array<int,array<string,mixed>> $blocks Parsed blocks.
     *
     * @return array<string,string>
     */
    protected function extract_video_from_blocks( array $blocks ): array {
        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) ) {
                continue;
            }

            $block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

            if ( 'core/video' === $block_name ) {
                $attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

                $src      = isset( $attrs['src'] ) ? $this->settings->sanitize_url( $attrs['src'], '' ) : '';
                $poster   = isset( $attrs['poster'] ) ? $this->settings->sanitize_url( $attrs['poster'], '' ) : '';
                $name     = isset( $attrs['title'] ) ? sanitize_text_field( $attrs['title'] ) : '';
                $duration = '';
                $upload   = '';

                if ( isset( $attrs['id'] ) && is_numeric( $attrs['id'] ) ) {
                    $attachment_id = (int) $attrs['id'];

                    if ( '' === $src ) {
                        $src = $this->settings->sanitize_url( wp_get_attachment_url( $attachment_id ), '' );
                    }

                    if ( '' === $name ) {
                        $attachment_title = get_the_title( $attachment_id );
                        if ( is_string( $attachment_title ) && '' !== trim( $attachment_title ) ) {
                            $name = sanitize_text_field( $attachment_title );
                        }
                    }

                    $metadata = wp_get_attachment_metadata( $attachment_id );

                    if ( is_array( $metadata ) ) {
                        if ( '' === $poster && isset( $metadata['image']['src'] ) ) {
                            $poster = $this->settings->sanitize_url( $this->build_upload_url_from_path( (string) $metadata['image']['src'] ), '' );
                        }

                        if ( isset( $metadata['length'] ) && is_numeric( $metadata['length'] ) && (int) $metadata['length'] > 0 ) {
                            $duration = $this->format_duration_from_seconds( (int) $metadata['length'] );
                        }

                        if ( isset( $metadata['created_timestamp'] ) && is_numeric( $metadata['created_timestamp'] ) ) {
                            $upload = gmdate( DATE_W3C, (int) $metadata['created_timestamp'] );
                        }
                    }

                    if ( '' === $poster ) {
                        $poster = $this->settings->sanitize_url( wp_get_attachment_image_url( $attachment_id, 'full' ), '' );
                    }
                }

                if ( '' !== $src ) {
                    return array(
                        'content_url'   => $src,
                        'thumbnail_url' => $poster,
                        'name'          => $name,
                        'duration'      => $duration,
                        'upload_date'   => $upload,
                    );
                }
            }

            if ( 'core/embed' === $block_name ) {
                $attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
                $url   = isset( $attrs['url'] ) ? $this->settings->sanitize_url( $attrs['url'], '' ) : '';
                $name  = isset( $attrs['title'] ) ? sanitize_text_field( $attrs['title'] ) : '';

                $iframe = isset( $block['innerHTML'] ) ? $this->extract_video_from_html( (string) $block['innerHTML'] ) : array();

                if ( empty( $iframe ) && '' !== $url ) {
                    $iframe = $this->derive_embed_data_from_url( $url );
                }

                if ( ! empty( $iframe ) ) {
                    if ( '' === $name && '' !== ( $attrs['url'] ?? '' ) ) {
                        $name = sanitize_text_field( wp_strip_all_tags( (string) $attrs['url'] ) );
                    }

                    $iframe['name'] = $iframe['name'] ?? $name;

                    return $iframe;
                }
            }

            if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                $child = $this->extract_video_from_blocks( $block['innerBlocks'] );
                if ( ! empty( $child ) ) {
                    return $child;
                }
            }
        }

        return array();
    }

    /**
     * Extract video data from raw HTML markup.
     *
     * @param string $html Raw HTML content.
     *
     * @return array<string,string>
     */
    protected function extract_video_from_html( string $html ): array {
        if ( '' === trim( $html ) ) {
            return array();
        }

        if ( preg_match( '/<iframe[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
            $embed_url = $this->settings->sanitize_url( $matches[1], '' );
            if ( '' !== $embed_url ) {
                $thumbnail = '';

                if ( false !== stripos( $embed_url, 'youtube.com' ) || false !== stripos( $embed_url, 'youtu.be' ) ) {
                    $youtube = $this->derive_embed_data_from_url( $embed_url );
                    if ( ! empty( $youtube['thumbnail_url'] ) ) {
                        $thumbnail = $youtube['thumbnail_url'];
                    }
                }

                return array(
                    'embed_url'     => $embed_url,
                    'thumbnail_url' => $thumbnail,
                );
            }
        }

        if ( preg_match( '/<video[^>]*>(.*?)<\/video>/is', $html, $video_matches ) ) {
            $video_tag = $video_matches[0];

            if ( preg_match( '/poster=["\']([^"\']+)["\']/', $video_tag, $poster_match ) ) {
                $poster = $this->settings->sanitize_url( $poster_match[1], '' );
            } else {
                $poster = '';
            }

            if ( preg_match( '/src=["\']([^"\']+)["\']/', $video_tag, $src_match ) ) {
                $content_url = $this->settings->sanitize_url( $src_match[1], '' );

                if ( '' !== $content_url ) {
                    return array(
                        'content_url'   => $content_url,
                        'thumbnail_url' => $poster,
                    );
                }
            }
        }

        return array();
    }

    /**
     * Derive embed data for known providers from a raw URL.
     *
     * @param string $url Video URL.
     *
     * @return array<string,string>
     */
    protected function derive_embed_data_from_url( string $url ): array {
        $parsed = wp_parse_url( $url );

        if ( empty( $parsed['host'] ) ) {
            return array();
        }

        $host = strtolower( $parsed['host'] );

        if ( false !== strpos( $host, 'youtube.com' ) || false !== strpos( $host, 'youtu.be' ) ) {
            $video_id = '';

            if ( false !== strpos( $host, 'youtu.be' ) && ! empty( $parsed['path'] ) ) {
                $video_id = ltrim( (string) $parsed['path'], '/' );
            } elseif ( ! empty( $parsed['path'] ) && false !== strpos( (string) $parsed['path'], '/embed/' ) ) {
                $video_id = trim( str_replace( '/embed/', '', (string) $parsed['path'] ), '/' );
            } elseif ( ! empty( $parsed['query'] ) ) {
                parse_str( (string) $parsed['query'], $params );
                if ( ! empty( $params['v'] ) ) {
                    $video_id = (string) $params['v'];
                }
            }

            $video_id = trim( $video_id );

            if ( '' === $video_id ) {
                return array();
            }

            $embed_url = sprintf( 'https://www.youtube.com/embed/%s', rawurlencode( $video_id ) );
            $thumbnail = sprintf( 'https://img.youtube.com/vi/%s/hqdefault.jpg', rawurlencode( $video_id ) );

            return array(
                'embed_url'     => $embed_url,
                'thumbnail_url' => $thumbnail,
            );
        }

        if ( false !== strpos( $host, 'vimeo.com' ) ) {
            $path = isset( $parsed['path'] ) ? trim( (string) $parsed['path'], '/' ) : '';

            if ( '' === $path ) {
                return array();
            }

            if ( false !== strpos( $host, 'player.vimeo.com' ) ) {
                $video_id = $path;
            } else {
                $segments = explode( '/', $path );
                $video_id = end( $segments );
            }

            if ( '' === $video_id ) {
                return array();
            }

            $embed_url = sprintf( 'https://player.vimeo.com/video/%s', rawurlencode( $video_id ) );

            return array(
                'embed_url' => $embed_url,
            );
        }

        return array();
    }

    /**
     * Build the URL to a file inside the uploads directory from a relative path.
     *
     * @param string $relative_path Relative file path.
     *
     * @return string
     */
    protected function build_upload_url_from_path( string $relative_path ): string {
        if ( '' === $relative_path ) {
            return '';
        }

        $uploads = wp_get_upload_dir();

        if ( empty( $uploads['baseurl'] ) ) {
            return '';
        }

        return trailingslashit( $uploads['baseurl'] ) . ltrim( $relative_path, '/' );
    }

    /**
     * Convert a duration in seconds to an ISO8601 string.
     *
     * @param int $seconds Duration in seconds.
     *
     * @return string
     */
    protected function format_duration_from_seconds( int $seconds ): string {
        if ( $seconds <= 0 ) {
            return '';
        }

        $interval = 'PT';

        $hours = (int) floor( $seconds / 3600 );
        if ( $hours > 0 ) {
            $interval .= $hours . 'H';
        }

        $minutes = (int) floor( ( $seconds % 3600 ) / 60 );
        if ( $minutes > 0 ) {
            $interval .= $minutes . 'M';
        }

        $remaining_seconds = $seconds % 60;
        if ( $remaining_seconds > 0 || 'PT' === $interval ) {
            $interval .= $remaining_seconds . 'S';
        }

        return $interval;
    }

    /**
     * Build the ImageObject node with metadata when available.
     *
     * @param string $image_url Image URL.
     * @param string $image_id  Image node ID.
     *
     * @return array<string,mixed>
     */
    protected function build_image_object( string $image_url, string $image_id ): array {
        if ( '' === $image_url ) {
            return array();
        }

        $image = array(
            '@type' => 'ImageObject',
            '@id'   => $image_id,
            'url'   => $image_url,
        );

        $attachment_id = attachment_url_to_postid( $image_url );

        if ( $attachment_id ) {
            $metadata = wp_get_attachment_metadata( $attachment_id );

            if ( is_array( $metadata ) ) {
                if ( isset( $metadata['width'] ) && is_numeric( $metadata['width'] ) ) {
                    $image['width'] = (int) $metadata['width'];
                }

                if ( isset( $metadata['height'] ) && is_numeric( $metadata['height'] ) ) {
                    $image['height'] = (int) $metadata['height'];
                }
            }

            $caption = wp_get_attachment_caption( $attachment_id );
            if ( is_string( $caption ) && '' !== trim( $caption ) ) {
                $image['caption'] = wp_strip_all_tags( $caption );
            }
        }

        return $image;
    }

    /**
     * Build VideoObject node for the graph.
     *
     * @param array<string,string> $video_data     Video data.
     * @param string               $video_id       Video node ID.
     * @param array{headline:string,description:string,image:string,video:array<string,string>} $values Schema values.
     * @param string               $organization_id Organization node ID.
     * @param WP_Post              $post           Post object.
     *
     * @return array<string,mixed>
     */
    protected function build_video_object( array $video_data, string $video_id, array $values, string $organization_id, WP_Post $post ): array {
        if ( empty( $video_data['embed_url'] ) && empty( $video_data['content_url'] ) ) {
            return array();
        }

        $video = array(
            '@type'     => 'VideoObject',
            '@id'       => $video_id,
            'publisher' => array( '@id' => $organization_id ),
        );

        $name = $video_data['name'];

        if ( '' === $name ) {
            $name = $values['headline'] ?: wp_strip_all_tags( get_the_title( $post ) );
        }

        if ( '' !== $name ) {
            $video['name'] = $name;
        }

        $description = $video_data['description'];

        if ( '' === $description ) {
            $description = $values['description'];
        }

        if ( '' !== $description ) {
            $video['description'] = $description;
        }

        if ( '' !== $video_data['embed_url'] ) {
            $video['embedUrl'] = $video_data['embed_url'];
        }

        if ( '' !== $video_data['content_url'] ) {
            $video['contentUrl'] = $video_data['content_url'];
        }

        if ( '' !== $video_data['thumbnail_url'] ) {
            $video['thumbnailUrl'] = array( $video_data['thumbnail_url'] );
        } elseif ( '' !== $values['image'] ) {
            $video['thumbnailUrl'] = array( $values['image'] );
        }

        if ( '' !== $video_data['duration'] ) {
            $video['duration'] = $video_data['duration'];
        }

        $upload_date = $video_data['upload_date'];

        if ( '' === $upload_date ) {
            $upload_date = get_post_time( DATE_W3C, true, $post );
        }

        if ( $upload_date ) {
            $video['uploadDate'] = $upload_date;
        }

        return $video;
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

<?php
/**
 * Frontend behaviour.
 *
 * @package Working_With_TOC
 */

namespace Working_With_TOC\Frontend;

defined( 'ABSPATH' ) || exit;

use DOMDocument;
use DOMXPath;
use WP_Post;
use Working_With_TOC\Logger;
use Working_With_TOC\Heading_Parser;
use Working_With_TOC\Settings;

/**
 * Handles TOC injection and assets.
 */
class Frontend {

    /**
     * Shortcode tag.
     */
    public const SHORTCODE_TAG = 'working_with_toc';

    /**
     * Placeholder used to swap shortcode output with the final TOC markup.
     */
    private const SHORTCODE_PLACEHOLDER = '<!-- wwt-toc-shortcode -->';

    /**
     * Settings.
     *
     * @var Settings
     */
    protected $settings;

    /**
     * Constructor.
     *
     * @param Settings $settings Settings handler.
     */
    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Enqueue frontend assets.
     */
    public function enqueue_assets(): void {
        if ( ! is_singular() ) {
            return;
        }

        $post = get_queried_object();
        if ( $post instanceof WP_Post && ! $this->settings->is_enabled_for( $post->post_type ) ) {
            return;
        }

        wp_enqueue_style(
            'wwt-toc-frontend',
            WWT_TOC_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            WWT_TOC_VERSION
        );

        wp_enqueue_script(
            'wwt-toc-frontend',
            WWT_TOC_PLUGIN_URL . 'assets/js/frontend.js',
            array(),
            WWT_TOC_VERSION,
            true
        );
    }

    /**
     * Register shortcode handler.
     */
    public function register_shortcode(): void {
        add_shortcode( self::SHORTCODE_TAG, array( $this, 'render_shortcode' ) );
    }

    /**
     * Render shortcode placeholder that will be replaced after heading parsing.
     */
    public function render_shortcode(): string {
        return self::SHORTCODE_PLACEHOLDER;
    }

    /**
     * Inject TOC markup into the content.
     *
     * @param string $content Post content.
     *
     * @return string
     */
    public function inject_toc( string $content ): string {
        if ( ! is_singular() || ! is_main_query() ) {
            return $content;
        }

        if ( is_feed() ) {
            return $content;
        }

        if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
            return $content;
        }

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return $content;
        }

        $post = get_post();
        if ( ! $post instanceof WP_Post ) {
            return $content;
        }

        if ( ! $this->settings->is_enabled_for( $post->post_type ) ) {
            Logger::log( 'TOC disabled for post type: ' . $post->post_type );
            return $content;
        }

        $preferences = $this->settings->get_post_preferences( $post );

        $result            = Heading_Parser::parse( $content );
        $original_headings = $result['headings'];
        $headings          = $this->filter_headings( $original_headings, $preferences['excluded_headings'] );
        $content           = $result['content'];

        if ( empty( $headings ) ) {
            if ( empty( $original_headings ) ) {
                Logger::log( 'No headings found for TOC.' );
            } else {
                Logger::log( 'All headings excluded from TOC for post ID ' . $post->ID );
            }
            return $content;
        }

        $toc_markup = $this->build_toc_markup( $headings, $preferences );

        $shortcode_present = false;

        $wrapped_placeholder_pattern = '#<p>\s*' . preg_quote( self::SHORTCODE_PLACEHOLDER, '#' ) . '\s*</p>#i';
        $content_with_replaced_wrapped = preg_replace( $wrapped_placeholder_pattern, $toc_markup, $content, -1, $wrapped_replacements );

        if ( is_string( $content_with_replaced_wrapped ) && $wrapped_replacements > 0 ) {
            $content             = $content_with_replaced_wrapped;
            $shortcode_present   = true;
        }

        if ( false !== strpos( $content, self::SHORTCODE_PLACEHOLDER ) ) {
            $content           = str_replace( self::SHORTCODE_PLACEHOLDER, $toc_markup, $content );
            $shortcode_present = true;
        }

        if ( $shortcode_present ) {
            return $content;
        }

        $content_with_inserted_toc = $this->insert_toc_after_first_heading( $content, $toc_markup );

        if ( null !== $content_with_inserted_toc ) {
            return $content_with_inserted_toc;
        }

        return $content . $toc_markup;
    }

    /**
     * Build TOC markup.
     *
     * @param array<int,array{title:string,id:string,level:int}> $headings Headings list.
     *
     * @return string
     */
    protected function build_toc_markup( array $headings, array $preferences ): string {
        $items = '';
        foreach ( $headings as $heading ) {
            $indent = max( 0, $heading['level'] - 2 );
            $items .= sprintf(
                '<li class="wwt-level-%1$d"><a href="#%2$s">%3$s</a></li>',
                (int) $indent,
                esc_attr( $heading['id'] ),
                esc_html( $heading['title'] )
            );
        }

        $label = isset( $preferences['title'] ) && '' !== trim( $preferences['title'] )
            ? $preferences['title']
            : __( 'Indice dei contenuti', 'working-with-toc' );

        $style_attribute = $this->build_inline_styles( $preferences );

        $markup = sprintf(
            '<div class="wwt-toc-container" data-wwt-toc="true" data-expanded="false"%4$s>
                <button class="wwt-toc-toggle" type="button" aria-expanded="false">
                    <span class="wwt-toc-label">%1$s</span>
                    <span class="wwt-toc-icon" aria-hidden="true"></span>
                </button>
                <div class="wwt-toc-content" hidden="hidden">
                    <nav class="wwt-toc-nav" aria-label="%2$s">
                        <ol class="wwt-toc-list">%3$s</ol>
                    </nav>
                </div>
            </div>',
            esc_html( $label ),
            esc_attr( $label ),
            $items,
            $style_attribute
        );

        return $markup;
    }

    /**
     * Filter headings according to the excluded list.
     *
     * @param array<int,array{title:string,id:string,level:int}> $headings Headings list.
     * @param array<int,string>                                  $excluded IDs to exclude.
     *
     * @return array<int,array{title:string,id:string,level:int}>
     */
    protected function filter_headings( array $headings, array $excluded ): array {
        if ( empty( $excluded ) ) {
            return $headings;
        }

        $map = array_fill_keys( $excluded, true );

        return array_values(
            array_filter(
                $headings,
                static function ( $heading ) use ( $map ) {
                    if ( ! isset( $heading['id'] ) || ! is_string( $heading['id'] ) ) {
                        return true;
                    }

                    return ! isset( $map[ $heading['id'] ] );
                }
            )
        );
    }

    /**
     * Build inline style attribute with CSS custom properties.
     *
     * @param array<string,mixed> $preferences Style preferences.
     */
    protected function build_inline_styles( array $preferences ): string {
        $styles = array(
            '--wwt-toc-bg'          => $preferences['background_color'] ?? '',
            '--wwt-toc-text'        => $preferences['text_color'] ?? '',
            '--wwt-toc-link'        => $preferences['link_color'] ?? '',
            '--wwt-toc-title-bg'    => $preferences['title_background_color'] ?? '',
            '--wwt-toc-title-color' => $preferences['title_color'] ?? '',
        );

        $parts = array();

        foreach ( $styles as $property => $value ) {
            if ( is_string( $value ) && '' !== $value ) {
                $parts[] = $property . ':' . $value;
            }
        }

        if ( empty( $parts ) ) {
            return '';
        }

        $style = implode( ';', $parts ) . ';';

        return ' style="' . esc_attr( $style ) . '"';
    }

    /**
     * Insert the TOC markup immediately after the first heading (h2-h5).
     *
     * @param string $content    Parsed post content.
     * @param string $toc_markup TOC HTML markup.
     *
     * @return string|null Updated content when a heading is found, otherwise null.
     */
    protected function insert_toc_after_first_heading( string $content, string $toc_markup ): ?string {
        $dom       = new DOMDocument();
        $previous  = libxml_use_internal_errors( true );
        $loaded    = $dom->loadHTML( '<?xml encoding="utf-8"?>' . $content );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $loaded ) {
            return null;
        }

        $xpath   = new DOMXPath( $dom );
        $headings = $xpath->query( '//h2 | //h3 | //h4 | //h5' );

        if ( ! $headings instanceof \DOMNodeList || 0 === $headings->length ) {
            return null;
        }

        $heading = $headings->item( 0 );

        if ( ! $heading ) {
            return null;
        }

        $fragment = $dom->createDocumentFragment();

        if ( ! $fragment->appendXML( $toc_markup ) ) {
            return null;
        }

        $parent = $heading->parentNode;

        if ( ! $parent ) {
            return null;
        }

        if ( $heading->nextSibling ) {
            $parent->insertBefore( $fragment, $heading->nextSibling );
        } else {
            $parent->appendChild( $fragment );
        }

        $body = $dom->getElementsByTagName( 'body' )->item( 0 );

        if ( $body ) {
            $updated = '';

            foreach ( $body->childNodes as $child ) {
                $updated .= $dom->saveHTML( $child );
            }

            return $updated;
        }

        return $dom->saveHTML();
    }
}

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
use function Working_With_TOC\is_domdocument_missing;
use function sanitize_html_class;
use function wp_json_encode;

/**
 * Handles TOC injection and assets.
 */
class Frontend {

    /**
     * AMP color schemes keyed by generated class names.
     *
     * @var array<string,array<string,string>>
     */
    protected $amp_color_schemes = array();

    /**
     * Cached AMP color scheme class assigned to each post ID.
     *
     * @var array<int,string>
     */
    protected $amp_post_color_class = array();

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
            WWTOC_VERSION
        );

        if ( $post instanceof WP_Post && $this->is_amp_request() ) {
            $preferences = $this->settings->get_post_preferences( $post );
            $class       = $this->get_amp_color_scheme_class( $preferences, (int) $post->ID );
            $css         = $this->build_amp_color_scheme_css();

            if ( null !== $class && '' !== $css ) {
                wp_add_inline_style( 'wwt-toc-frontend', $css );
            }

            return;
        }

        wp_enqueue_script(
            'wwt-toc-frontend',
            WWT_TOC_PLUGIN_URL . 'assets/js/frontend.js',
            array(),
            WWTOC_VERSION,
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

        if ( is_domdocument_missing() ) {
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

        $faq_ids = isset( $preferences['faq_headings'] ) && is_array( $preferences['faq_headings'] )
            ? $preferences['faq_headings']
            : array();

        $result            = Heading_Parser::parse(
            $content,
            array(
                'faq_ids'          => $faq_ids,
                'mark_faq'         => ! empty( $faq_ids ),
                'mark_faq_answers' => true,
            )
        );
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

        $toc_markup = $this->build_toc_markup(
            $headings,
            $preferences,
            (int) $post->ID,
            $this->is_amp_request()
        );

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
     * @param array<int,array{title:string,id:string,level:int}> $headings         Headings list.
     * @param array<string,mixed>                               $preferences      TOC preferences.
     * @param int                                               $post_id          Current post ID.
     * @param bool                                              $use_static_layout Whether the markup should avoid JS toggles.
     *
     * @return string
     */
    protected function build_toc_markup( array $headings, array $preferences, int $post_id, bool $use_static_layout = false ): string {
        if ( $use_static_layout ) {
            return $this->build_static_toc_markup( $headings, $preferences, $post_id );
        }

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
            : __( 'Table of contents', 'working-with-toc' );

        $container_id         = $this->get_container_id( $post_id );
        $heading_id           = $container_id . '-heading';
        $toggle_id            = $container_id . '-toggle';
        $toggle_hint_id       = $toggle_id . '-hint';
        $content_id           = $container_id . '-content';
        $container_attributes = $this->build_container_attributes( $preferences, $post_id );
        $toggle_attributes    = $this->stringify_attributes(
            array(
                'id'              => $toggle_id,
                'class'           => 'wwt-toc-toggle',
                'type'            => 'button',
                'aria-expanded'   => 'true',
                'aria-controls'   => $content_id,
                'aria-labelledby' => $heading_id,
                'aria-describedby' => $toggle_hint_id,
            )
        );
        $heading_attributes   = $this->stringify_attributes(
            array(
                'id'    => $heading_id,
                'class' => 'wwt-toc-heading',
            )
        );
        $content_attributes   = $this->stringify_attributes(
            array(
                'id'              => $content_id,
                'class'           => 'wwt-toc-content',
                'aria-labelledby' => $heading_id,
            )
        );

        $style_override = $this->build_custom_title_style( $preferences, $toggle_id );

        $markup = sprintf(
            '<div %1$s>' .
                '<div class="wwt-toc-header">' .
                    '<h2 %2$s>%3$s</h2>' .
                    '<button %4$s>' .
                        '<span id="%5$s" class="screen-reader-text">%6$s</span>' .
                        '<span class="wwt-toc-icon" aria-hidden="true"></span>' .
                    '</button>' .
                '</div>' .
                '<div %7$s>' .
                    '<nav class="wwt-toc-nav" aria-label="%8$s" role="doc-toc">' .
                        '<ol class="wwt-toc-list">%9$s</ol>' .
                    '</nav>' .
                '</div>' .
            '</div>',
            $container_attributes,
            $heading_attributes,
            esc_html( $label ),
            $toggle_attributes,
            esc_attr( $toggle_hint_id ),
            esc_html__( 'Toggle table of contents visibility', 'working-with-toc' ),
            $content_attributes,
            esc_attr( $label ),
            $items
        );

        if ( '' !== $style_override ) {
            $markup = $style_override . "\n" . $markup;
        }

        return $markup;
    }

    /**
     * Build a static TOC markup that works without JavaScript listeners.
     *
     * @param array<int,array{title:string,id:string,level:int}> $headings    Headings list.
     * @param array<string,mixed>                               $preferences TOC preferences.
     * @param int                                               $post_id     Current post ID.
     *
     * @return string
     */
    protected function build_static_toc_markup( array $headings, array $preferences, int $post_id ): string {
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
            : __( 'Table of contents', 'working-with-toc' );

        $container_id = $this->get_container_id( $post_id );
        $summary_id   = $container_id . '-summary';
        $content_id   = $container_id . '-content';

        $extra_classes = array();
        $amp_color_class = $this->get_amp_color_scheme_class( $preferences, $post_id );
        if ( null !== $amp_color_class ) {
            $extra_classes[] = $amp_color_class;
        }

        $container_attributes = $this->build_container_attributes( $preferences, $post_id, false, $extra_classes );
        $summary_attributes   = $this->stringify_attributes(
            array(
                'id'            => $summary_id,
                'class'         => 'wwt-toc-summary',
                'aria-controls' => $content_id,
            )
        );
        $content_attributes   = $this->stringify_attributes(
            array(
                'id'              => $content_id,
                'class'           => 'wwt-toc-content',
                'aria-labelledby' => $summary_id,
            )
        );

        $style_override = $this->build_custom_title_style( $preferences, $summary_id );

        $markup = sprintf(
            '<div %1$s>' .
                '<details class="wwt-toc-accordion" open>' .
                    '<summary %2$s>' .
                        '<span class="wwt-toc-summary-text">%3$s</span>' .
                        '<span class="wwt-toc-icon" aria-hidden="true"></span>' .
                    '</summary>' .
                    '<div %4$s>' .
                        '<nav class="wwt-toc-nav" aria-label="%5$s" role="doc-toc">' .
                            '<ol class="wwt-toc-list">%6$s</ol>' .
                        '</nav>' .
                    '</div>' .
                '</details>' .
            '</div>',
            $container_attributes,
            $summary_attributes,
            esc_html( $label ),
            $content_attributes,
            esc_attr( $label ),
            $items
        );

        if ( '' !== $style_override ) {
            $markup = $style_override . "\n" . $markup;
        }

        return $markup;
    }

    /**
     * Filter headings according to the excluded list.
     *
     * @param array<int,array{title:string,id:string,level:int,faq_excerpt?:string,faq_answer?:string}> $headings Headings list.
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

        return implode( ';', $parts ) . ';';
    }

    /**
     * Build the attribute string applied to the TOC container element.
     *
     * @param array<string,mixed> $preferences    TOC preferences.
     * @param int                 $post_id        Current post ID.
     * @param bool                $is_interactive Whether the container expects JS listeners.
     * @param array<int,string>   $extra_classes  Additional classes to append to the container.
     */
    protected function build_container_attributes( array $preferences, int $post_id, bool $is_interactive = true, array $extra_classes = array() ): string {
        $post_id = absint( $post_id );
        $id      = $this->get_container_id( $post_id );

        $horizontal_choices = $this->settings->get_horizontal_alignment_choices();
        $vertical_choices   = $this->settings->get_vertical_alignment_choices();

        $horizontal = $preferences['horizontal_alignment'] ?? '';
        if ( ! is_string( $horizontal ) || ! in_array( $horizontal, $horizontal_choices, true ) ) {
            $horizontal = 'right';
        }

        $vertical = $preferences['vertical_alignment'] ?? '';
        if ( ! is_string( $vertical ) || ! in_array( $vertical, $vertical_choices, true ) ) {
            $vertical = 'bottom';
        }

        $classes = array( 'wwt-toc-container' );

        if ( ! empty( $preferences['has_custom_title_colors'] ) ) {
            $classes[] = 'wwt-has-custom-title-colors';
        }

        foreach ( $extra_classes as $extra_class ) {
            if ( ! is_string( $extra_class ) || '' === $extra_class ) {
                continue;
            }

            $sanitized = sanitize_html_class( $extra_class );

            if ( '' === $sanitized ) {
                continue;
            }

            $classes[] = $sanitized;
        }

        $sanitized_classes = array_filter(
            array_map( 'sanitize_html_class', $classes )
        );

        $attributes = array(
            'id'               => $id,
            'class'            => implode( ' ', $sanitized_classes ),
            'data-align-x'     => $horizontal,
            'data-align-y'     => $vertical,
            'data-render-mode' => $is_interactive ? 'interactive' : 'static',
        );

        if ( $is_interactive ) {
            $attributes['data-wwt-toc']  = 'true';
            $attributes['data-expanded'] = 'true';
        }

        $style = $this->build_inline_styles( $preferences );
        if ( '' !== $style && ! $this->is_amp_request() ) {
            $attributes['style'] = $style;
        }

        $color_map = array(
            'bg'          => 'background_color',
            'text'        => 'text_color',
            'link'        => 'link_color',
            'title-bg'    => 'title_background_color',
            'title-color' => 'title_color',
        );

        foreach ( $color_map as $data_key => $preference_key ) {
            if ( empty( $preferences[ $preference_key ] ) ) {
                continue;
            }

            $attributes[ 'data-' . $data_key ] = $preferences[ $preference_key ];
        }

        return $this->stringify_attributes( $attributes );
    }

    /**
     * Generate a unique container ID based on the post ID.
     *
     * @param int $post_id Current post ID.
     */
    protected function get_container_id( int $post_id ): string {
        $post_id = absint( $post_id );

        return $post_id > 0 ? 'wwt-toc-' . $post_id : 'wwt-toc';
    }

    /**
     * Determine whether the current request is served in AMP mode.
     */
    protected function is_amp_request(): bool {
        if ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) {
            return true;
        }

        if ( function_exists( 'amp_is_request' ) && amp_is_request() ) {
            return true;
        }

        return false;
    }

    /**
     * Convert an attribute array into a HTML attribute string.
     *
     * @param array<string,string> $attributes Attributes list.
     */
    protected function stringify_attributes( array $attributes ): string {
        $parts = array();

        foreach ( $attributes as $name => $value ) {
            if ( '' === $value ) {
                continue;
            }

            $parts[] = sprintf( '%s="%s"', $name, esc_attr( $value ) );
        }

        return implode( ' ', $parts );
    }

    /**
     * Retrieve the AMP color scheme class for the current post.
     *
     * @param array<string,mixed> $preferences Style preferences.
     * @param int                 $post_id     Current post ID.
     */
    protected function get_amp_color_scheme_class( array $preferences, int $post_id ): ?string {
        if ( ! $this->is_amp_request() ) {
            return null;
        }

        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return null;
        }

        if ( isset( $this->amp_post_color_class[ $post_id ] ) ) {
            return $this->amp_post_color_class[ $post_id ];
        }

        $colors = $this->extract_color_preferences( $preferences );
        if ( empty( $colors ) ) {
            return null;
        }

        $hash_source = wp_json_encode( $colors );
        if ( ! is_string( $hash_source ) ) {
            return null;
        }

        $class = sanitize_html_class( 'wwt-color-scheme-' . substr( md5( $hash_source ), 0, 12 ) );

        $this->amp_post_color_class[ $post_id ] = $class;
        $this->amp_color_schemes[ $class ]      = $colors;

        return $class;
    }

    /**
     * Extract the color-related preferences that map to CSS custom properties.
     *
     * @param array<string,mixed> $preferences Style preferences.
     *
     * @return array<string,string>
     */
    protected function extract_color_preferences( array $preferences ): array {
        $color_keys = array(
            'background_color',
            'text_color',
            'link_color',
            'title_background_color',
            'title_color',
        );

        $colors = array();

        foreach ( $color_keys as $key ) {
            if ( empty( $preferences[ $key ] ) || ! is_string( $preferences[ $key ] ) ) {
                continue;
            }

            $colors[ $key ] = $preferences[ $key ];
        }

        return $colors;
    }

    /**
     * Build inline CSS rules that expose AMP-safe color schemes.
     */
    protected function build_amp_color_scheme_css(): string {
        if ( empty( $this->amp_color_schemes ) ) {
            return '';
        }

        $variable_map = array(
            'background_color'       => '--wwt-toc-bg',
            'text_color'             => '--wwt-toc-text',
            'link_color'             => '--wwt-toc-link',
            'title_background_color' => '--wwt-toc-title-bg',
            'title_color'            => '--wwt-toc-title-color',
        );

        $rules = array();

        foreach ( $this->amp_color_schemes as $class => $colors ) {
            $declarations = array();

            foreach ( $variable_map as $preference_key => $variable_name ) {
                if ( empty( $colors[ $preference_key ] ) ) {
                    continue;
                }

                $declarations[] = sprintf( '%1$s:%2$s;', $variable_name, $colors[ $preference_key ] );
            }

            if ( empty( $declarations ) ) {
                continue;
            }

            $rules[] = sprintf(
                '.wwt-toc-container.%1$s{%2$s}',
                sanitize_html_class( $class ),
                implode( '', $declarations )
            );
        }

        return implode( '', $rules );
    }

    /**
     * Build an inline style block that enforces title colors when custom values are used.
     *
     * @param array<string,mixed> $preferences Style preferences.
     * @param string              $element_id  Control element ID.
     */
    protected function build_custom_title_style( array $preferences, string $element_id ): string {
        if ( $this->is_amp_request() || empty( $preferences['has_custom_title_colors'] ) ) {
            return '';
        }

        $rules = array();

        if ( ! empty( $preferences['title_background_color'] ) ) {
            $rules[] = sprintf( 'background:%s !important;', $preferences['title_background_color'] );
        }

        if ( ! empty( $preferences['title_color'] ) ) {
            $rules[] = sprintf( 'color:%s !important;', $preferences['title_color'] );
        }

        if ( empty( $rules ) ) {
            return '';
        }

        $style_id = $element_id . '-style';

        return sprintf(
            '<style id="%1$s">#%2$s{%3$s}</style>',
            esc_attr( $style_id ),
            esc_attr( $element_id ),
            esc_html( implode( '', $rules ) )
        );
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
        if ( is_domdocument_missing() || ! class_exists( \DOMDocument::class ) ) {
            return $content;
        }

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

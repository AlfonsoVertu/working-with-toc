<?php
/**
 * Frontend behaviour.
 *
 * @package Working_With_TOC
 */

namespace Working_With_TOC\Frontend;

defined( 'ABSPATH' ) || exit;

use WP_Post;
use Working_With_TOC\Logger;
use Working_With_TOC\Heading_Parser;
use Working_With_TOC\Settings;

/**
 * Handles TOC injection and assets.
 */
class Frontend {

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
     * Inject TOC markup into the content.
     *
     * @param string $content Post content.
     *
     * @return string
     */
    public function inject_toc( string $content ): string {
        if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
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

        $result   = Heading_Parser::parse( $content );
        $headings = $result['headings'];
        $content  = $result['content'];

        if ( empty( $headings ) ) {
            Logger::log( 'No headings found for TOC.' );
            return $content;
        }

        $toc_markup = $this->build_toc_markup( $headings );

        return $content . $toc_markup;
    }

    /**
     * Build TOC markup.
     *
     * @param array<int,array{title:string,id:string,level:int}> $headings Headings list.
     *
     * @return string
     */
    protected function build_toc_markup( array $headings ): string {
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

        $markup = sprintf(
            '<div class="wwt-toc-container" data-wwt-toc data-expanded="false">
                <button class="wwt-toc-toggle" type="button" aria-expanded="false">
                    <span class="wwt-toc-label">%1$s</span>
                    <span class="wwt-toc-icon" aria-hidden="true"></span>
                </button>
                <div class="wwt-toc-content" hidden>
                    <nav class="wwt-toc-nav" aria-label="%1$s">
                        <ol class="wwt-toc-list">%2$s</ol>
                    </nav>
                </div>
            </div>',
            esc_html__( 'Indice dei contenuti', 'working-with-toc' ),
            $items
        );

        return $markup;
    }
}

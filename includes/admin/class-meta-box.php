<?php
/**
 * Post editor meta box for TOC customisation.
 *
 * @package Working_With_TOC
 */

namespace Working_With_TOC\Admin;

defined( 'ABSPATH' ) || exit;

use WP_Post;
use Working_With_TOC\Heading_Parser;
use Working_With_TOC\Logger;
use Working_With_TOC\Settings;

/**
 * Register and render the per-post TOC controls.
 */
class Meta_Box {

    /**
     * Settings handler.
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
     * Register meta boxes for supported post types.
     */
    public function register(): void {
        foreach ( $this->settings->get_supported_post_types() as $post_type ) {
            add_meta_box(
                'wwt-toc-meta',
                __( 'Indice dei contenuti', 'working-with-toc' ),
                array( $this, 'render' ),
                $post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * Enqueue assets required inside the editor.
     */
    public function enqueue_assets( string $hook ): void {
        if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'wwt-toc-meta-box',
            WWT_TOC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WWT_TOC_VERSION
        );

        wp_enqueue_script(
            'wwt-toc-meta-box',
            WWT_TOC_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery', 'wp-a11y' ),
            WWT_TOC_VERSION,
            true
        );
    }

    /**
     * Render the meta box markup.
     */
    public function render( WP_Post $post ): void {
        wp_nonce_field( 'wwt_toc_meta_nonce', 'wwt_toc_meta_nonce' );

        $defaults = $this->settings->get_default_preferences( $post->post_type );
        $meta     = get_post_meta( $post->ID, Settings::META_KEY, true );

        if ( ! is_array( $meta ) ) {
            $meta = array();
        }

        $title_value = isset( $meta['title'] ) ? sanitize_text_field( $meta['title'] ) : '';

        $colors = array(
            'title_color'            => $defaults['title_color'],
            'title_background_color' => $defaults['title_background_color'],
            'background_color'       => $defaults['background_color'],
            'text_color'             => $defaults['text_color'],
            'link_color'             => $defaults['link_color'],
        );

        foreach ( $colors as $key => $fallback ) {
            if ( empty( $meta[ $key ] ) ) {
                continue;
            }

            $sanitised = sanitize_hex_color( $meta[ $key ] );
            if ( $sanitised ) {
                $colors[ $key ] = $sanitised;
            }
        }

        $headings = $this->get_headings( $post );
        $excluded = $this->sanitize_heading_ids( $meta['excluded_headings'] ?? array() );
        ?>
        <div class="wwt-toc-meta">
            <p class="wwt-toc-meta__description"><?php esc_html_e( 'Personalizza l\'indice dei contenuti per questo elemento. Le impostazioni globali del plugin verranno utilizzate quando i campi restano invariati.', 'working-with-toc' ); ?></p>

            <div class="wwt-toc-meta__group">
                <label class="wwt-toc-meta__label" for="wwt_toc_meta_title"><?php esc_html_e( 'Titolo della TOC', 'working-with-toc' ); ?></label>
                <div class="wwt-toc-meta__control">
                    <input type="text" id="wwt_toc_meta_title" name="wwt_toc_meta[title]" value="<?php echo esc_attr( $title_value ); ?>" placeholder="<?php echo esc_attr( $defaults['title'] ); ?>" data-default="<?php echo esc_attr( $defaults['title'] ); ?>" class="widefat" />
                    <button type="button" class="button-link wwt-toc-meta__reset" data-target="wwt_toc_meta_title"><?php esc_html_e( 'Ripristina', 'working-with-toc' ); ?></button>
                </div>
            </div>

            <div class="wwt-toc-meta__group">
                <span class="wwt-toc-meta__label"><?php esc_html_e( 'Colori personalizzati', 'working-with-toc' ); ?></span>
                <div class="wwt-toc-meta__color-grid">
                    <?php foreach ( $colors as $key => $value ) :
                        $field_id = 'wwt_toc_meta_' . $key;
                        $label    = $this->get_color_label( $key );
                        ?>
                        <div class="wwt-toc-meta__color">
                            <label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label>
                            <div class="wwt-toc-meta__color-controls">
                                <input type="color" id="<?php echo esc_attr( $field_id ); ?>" name="wwt_toc_meta[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" data-default="<?php echo esc_attr( $defaults[ $key ] ); ?>" class="wwt-toc-meta__color-input" />
                                <button type="button" class="button-link wwt-toc-meta__reset" data-target="<?php echo esc_attr( $field_id ); ?>"><?php esc_html_e( 'Ripristina', 'working-with-toc' ); ?></button>
                            </div>
                            <span class="wwt-toc-meta__color-value" data-target="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( strtoupper( $value ) ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="wwt-toc-meta__group">
                <span class="wwt-toc-meta__label"><?php esc_html_e( 'Titoli inclusi', 'working-with-toc' ); ?></span>
                <?php if ( ! empty( $headings ) ) : ?>
                    <ul class="wwt-toc-meta__headings-list">
                        <?php foreach ( $headings as $heading ) :
                            $indent  = max( 0, (int) $heading['level'] - 2 );
                            $checked = ! in_array( $heading['id'], $excluded, true );
                            ?>
                            <li class="wwt-toc-meta__headings-item wwt-level-<?php echo esc_attr( $indent ); ?>">
                                <label>
                                    <input type="checkbox" name="wwt_toc_meta[headings][]" value="<?php echo esc_attr( $heading['id'] ); ?>" <?php checked( $checked ); ?> />
                                    <span><?php echo esc_html( $heading['title'] ); ?></span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="wwt-toc-meta__hint"><?php esc_html_e( 'Deseleziona i titoli che non vuoi mostrare nell\'indice.', 'working-with-toc' ); ?></p>
                <?php else : ?>
                    <p class="wwt-toc-meta__empty"><?php esc_html_e( 'Aggiungi dei titoli (H2-H6) al contenuto e salva per generare automaticamente l\'indice.', 'working-with-toc' ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Persist meta box data.
     */
    public function save( int $post_id, WP_Post $post ): void {
        if ( ! isset( $_POST['wwt_toc_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wwt_toc_meta_nonce'] ) ), 'wwt_toc_meta_nonce' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! in_array( $post->post_type, $this->settings->get_supported_post_types(), true ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $input = $_POST['wwt_toc_meta'] ?? array();
        if ( ! is_array( $input ) ) {
            $input = array();
        }

        $defaults = $this->settings->get_default_preferences( $post->post_type );

        $data  = array();
        $title = isset( $input['title'] ) ? sanitize_text_field( wp_unslash( $input['title'] ) ) : '';

        if ( '' !== $title && $title !== $defaults['title'] ) {
            $data['title'] = $title;
        }

        foreach ( array( 'title_color', 'title_background_color', 'background_color', 'text_color', 'link_color' ) as $field ) {
            $value = isset( $input[ $field ] ) ? sanitize_hex_color( wp_unslash( $input[ $field ] ) ) : '';

            if ( ! $value ) {
                continue;
            }

            if ( strtolower( $value ) !== strtolower( $defaults[ $field ] ) ) {
                $data[ $field ] = $value;
            }
        }

        $headings    = $this->get_headings( $post );
        $all_heading_ids = array();
        foreach ( $headings as $heading ) {
            $all_heading_ids[] = $heading['id'];
        }

        if ( ! empty( $all_heading_ids ) ) {
            $included = $this->sanitize_heading_selection( $input['headings'] ?? array(), $all_heading_ids );
            $excluded = array_values( array_diff( $all_heading_ids, $included ) );

            if ( ! empty( $excluded ) ) {
                $data['excluded_headings'] = $excluded;
            }
        }

        if ( ! empty( $data ) ) {
            update_post_meta( $post_id, Settings::META_KEY, $data );
            Logger::log( 'Saved TOC meta for post ID ' . $post_id );
        } else {
            delete_post_meta( $post_id, Settings::META_KEY );
            Logger::log( 'Cleared TOC meta for post ID ' . $post_id );
        }
    }

    /**
     * Parse headings from the post content.
     *
     * @param WP_Post $post Post object.
     *
     * @return array<int,array{title:string,id:string,level:int}>
     */
    protected function get_headings( WP_Post $post ): array {
        if ( '' === $post->post_content ) {
            return array();
        }

        $parsed = Heading_Parser::parse( $post->post_content );

        return $parsed['headings'];
    }

    /**
     * Convert stored heading IDs into a sanitised array.
     *
     * @param mixed $ids Stored IDs.
     *
     * @return array<int,string>
     */
    protected function sanitize_heading_ids( $ids ): array {
        if ( ! is_array( $ids ) ) {
            return array();
        }

        $sanitised = array();

        foreach ( $ids as $id ) {
            if ( ! is_string( $id ) ) {
                continue;
            }

            $clean = sanitize_text_field( $id );
            if ( '' === $clean ) {
                continue;
            }

            $sanitised[] = $clean;
        }

        return array_values( array_unique( $sanitised ) );
    }

    /**
     * Filter the included headings based on the submitted checkboxes.
     *
     * @param mixed                $values  Submitted values.
     * @param array<int,string>    $allowed List of allowed heading IDs.
     *
     * @return array<int,string>
     */
    protected function sanitize_heading_selection( $values, array $allowed ): array {
        if ( ! is_array( $values ) ) {
            return array();
        }

        $allowed_map = array_fill_keys( $allowed, true );
        $sanitised   = array();

        foreach ( $values as $value ) {
            if ( ! is_string( $value ) ) {
                continue;
            }

            $clean = sanitize_text_field( wp_unslash( $value ) );
            if ( '' === $clean ) {
                continue;
            }

            if ( isset( $allowed_map[ $clean ] ) ) {
                $sanitised[] = $clean;
            }
        }

        return array_values( array_unique( $sanitised ) );
    }

    /**
     * Get a human-readable label for a colour field.
     */
    protected function get_color_label( string $field ): string {
        switch ( $field ) {
            case 'title_color':
                return __( 'Colore del titolo', 'working-with-toc' );
            case 'title_background_color':
                return __( 'Sfondo del titolo', 'working-with-toc' );
            case 'background_color':
                return __( 'Sfondo del box', 'working-with-toc' );
            case 'text_color':
                return __( 'Colore del testo', 'working-with-toc' );
            case 'link_color':
                return __( 'Colore dei link', 'working-with-toc' );
            default:
                return __( 'Colore', 'working-with-toc' );
        }
    }
}

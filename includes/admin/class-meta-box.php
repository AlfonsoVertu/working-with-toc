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
                __( 'Table of contents', 'working-with-toc' ),
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
            WWTOC_VERSION
        );

        wp_enqueue_script(
            'wwt-toc-meta-box',
            WWT_TOC_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery', 'wp-a11y' ),
            WWTOC_VERSION,
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

        $horizontal_alignment = $defaults['horizontal_alignment'];
        if ( isset( $meta['horizontal_alignment'] ) ) {
            $horizontal_alignment = $this->settings->sanitize_horizontal_alignment( $meta['horizontal_alignment'], $horizontal_alignment );
        }

        $vertical_alignment = $defaults['vertical_alignment'];
        if ( isset( $meta['vertical_alignment'] ) ) {
            $vertical_alignment = $this->settings->sanitize_vertical_alignment( $meta['vertical_alignment'], $vertical_alignment );
        }

        $horizontal_options = array(
            'left'   => __( 'Left', 'working-with-toc' ),
            'center' => __( 'Center', 'working-with-toc' ),
            'right'  => __( 'Right', 'working-with-toc' ),
        );

        $vertical_options = array(
            'top'    => __( 'Top', 'working-with-toc' ),
            'bottom' => __( 'Bottom', 'working-with-toc' ),
        );

        $headings = $this->get_headings( $post );
        $excluded = $this->sanitize_heading_ids( $meta['excluded_headings'] ?? array() );
        ?>
        <div class="wwt-toc-meta">
        <p class="wwt-toc-meta__description"><?php esc_html_e( "Customize the table of contents for this entry. The plugin's global settings apply when fields are left unchanged.", 'working-with-toc' ); ?></p>

            <div class="wwt-toc-meta__group">
                <label class="wwt-toc-meta__label" for="wwt_toc_meta_title"><?php esc_html_e( 'TOC title', 'working-with-toc' ); ?></label>
                <div class="wwt-toc-meta__control">
                    <input type="text" id="wwt_toc_meta_title" name="wwt_toc_meta[title]" value="<?php echo esc_attr( $title_value ); ?>" placeholder="<?php echo esc_attr( $defaults['title'] ); ?>" data-default="<?php echo esc_attr( $defaults['title'] ); ?>" class="widefat" />
                    <button type="button" class="button-link wwt-toc-meta__reset" data-target="wwt_toc_meta_title"><?php esc_html_e( 'Reset', 'working-with-toc' ); ?></button>
                </div>
            </div>

            <div class="wwt-toc-meta__group">
                <span class="wwt-toc-meta__label"><?php esc_html_e( 'Custom colors', 'working-with-toc' ); ?></span>
                <div class="wwt-toc-meta__color-grid">
                    <?php foreach ( $colors as $key => $value ) :
                        $field_id = 'wwt_toc_meta_' . $key;
                        $label    = $this->get_color_label( $key );
                        ?>
                        <div class="wwt-toc-meta__color">
                            <label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label>
                            <div class="wwt-toc-meta__color-controls">
                                <input type="color" id="<?php echo esc_attr( $field_id ); ?>" name="wwt_toc_meta[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" data-default="<?php echo esc_attr( $defaults[ $key ] ); ?>" class="wwt-toc-meta__color-input" />
                                <button type="button" class="button-link wwt-toc-meta__reset" data-target="<?php echo esc_attr( $field_id ); ?>"><?php esc_html_e( 'Reset', 'working-with-toc' ); ?></button>
                            </div>
                            <span class="wwt-toc-meta__color-value" data-target="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( strtoupper( $value ) ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="wwt-toc-meta__group">
                <span class="wwt-toc-meta__label"><?php esc_html_e( 'TOC placement', 'working-with-toc' ); ?></span>
                <div class="wwt-toc-meta__layout">
                    <label for="wwt_toc_meta_horizontal_alignment">
                        <?php esc_html_e( 'Horizontal position', 'working-with-toc' ); ?>
                        <select id="wwt_toc_meta_horizontal_alignment" name="wwt_toc_meta[horizontal_alignment]">
                            <?php foreach ( $horizontal_options as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $horizontal_alignment, $value ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label for="wwt_toc_meta_vertical_alignment">
                        <?php esc_html_e( 'Vertical position', 'working-with-toc' ); ?>
                        <select id="wwt_toc_meta_vertical_alignment" name="wwt_toc_meta[vertical_alignment]">
                            <?php foreach ( $vertical_options as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $vertical_alignment, $value ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
            </div>

            <div class="wwt-toc-meta__group">
                <span class="wwt-toc-meta__label"><?php esc_html_e( 'Included headings', 'working-with-toc' ); ?></span>
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
                    <p class="wwt-toc-meta__hint"><?php esc_html_e( 'Deselect the headings you do not want to display in the table of contents.', 'working-with-toc' ); ?></p>
                <?php else : ?>
                    <p class="wwt-toc-meta__empty"><?php esc_html_e( 'Add headings (H2-H6) to the content and save to generate the table of contents automatically.', 'working-with-toc' ); ?></p>
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

        if ( isset( $input['horizontal_alignment'] ) ) {
            $alignment = $this->settings->sanitize_horizontal_alignment( wp_unslash( $input['horizontal_alignment'] ), $defaults['horizontal_alignment'] );

            if ( $alignment !== $defaults['horizontal_alignment'] ) {
                $data['horizontal_alignment'] = $alignment;
            }
        }

        if ( isset( $input['vertical_alignment'] ) ) {
            $alignment = $this->settings->sanitize_vertical_alignment( wp_unslash( $input['vertical_alignment'] ), $defaults['vertical_alignment'] );

            if ( $alignment !== $defaults['vertical_alignment'] ) {
                $data['vertical_alignment'] = $alignment;
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
                return __( 'Title color', 'working-with-toc' );
            case 'title_background_color':
                return __( 'Title background', 'working-with-toc' );
            case 'background_color':
                return __( 'Container background', 'working-with-toc' );
            case 'text_color':
                return __( 'Text color', 'working-with-toc' );
            case 'link_color':
                return __( 'Link color', 'working-with-toc' );
            default:
                return __( 'Color', 'working-with-toc' );
        }
    }
}

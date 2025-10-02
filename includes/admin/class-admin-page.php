<?php
/**
 * Admin settings page.
 *
 * @package Working_With_TOC
 */

namespace Working_With_TOC\Admin;

defined( 'ABSPATH' ) || exit;

use Working_With_TOC\Logger;
use Working_With_TOC\Settings;

/**
 * Render and manage plugin admin page.
 */
class Admin_Page {

    /**
     * Settings object.
     *
     * @var Settings
     */
    protected $settings;

    /**
     * Capability required to access the admin page.
     *
     * @var string
     */
    protected $capability;

    /**
     * Default capability required to access the admin page.
     */
    public const DEFAULT_CAPABILITY = 'manage_working_with_toc';

    /**
     * Constructor.
     *
     * @param Settings $settings Settings manager.
     */
    public function __construct( Settings $settings ) {
        $this->settings   = $settings;
        $this->capability = $this->determine_capability();
    }

    /**
     * Determine the capability required to access the admin page.
     */
    protected function determine_capability(): string {
        $capability = apply_filters( 'working_with_toc_admin_capability', self::DEFAULT_CAPABILITY );

        if ( ! is_string( $capability ) || '' === $capability ) {
            $capability = self::DEFAULT_CAPABILITY;
        }

        return $capability;
    }

    /**
     * Register menu item.
     */
    public function register_menu(): void {
        add_menu_page(
            __( 'Working with TOC', 'working-with-toc' ),
            __( 'Working with TOC', 'working-with-toc' ),
            $this->capability,
            'working-with-toc',
            array( $this, 'render_page' ),
            'dashicons-list-view'
        );
    }

    /**
     * Enqueue admin assets.
     */
    public function enqueue_assets( string $hook ): void {
        if ( 'toplevel_page_working-with-toc' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'wwt-toc-admin',
            WWT_TOC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WWT_TOC_VERSION
        );

        wp_enqueue_script(
            'wwt-toc-admin',
            WWT_TOC_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery', 'wp-a11y' ),
            WWT_TOC_VERSION,
            true
        );

        wp_localize_script(
            'wwt-toc-admin',
            'wwtTocAdmin',
            array(
                'on'  => __( 'Activated', 'working-with-toc' ),
                'off' => __( 'Deactivated', 'working-with-toc' ),
            )
        );
    }

    /**
     * Render settings page markup.
     */
    public function render_page(): void {
        if ( ! current_user_can( $this->capability ) ) {
            return;
        }

        $settings = $this->settings->get_settings();
        Logger::log( 'Rendering settings page.' );
        ?>
        <div class="wrap wwt-toc-admin">
            <h1><?php esc_html_e( 'Working with TOC', 'working-with-toc' ); ?></h1>
            <p class="wwt-toc-subtitle"><?php esc_html_e( 'Manage structured data for posts, products, and pages.', 'working-with-toc' ); ?></p>

            <form action="options.php" method="post" class="wwt-toc-form">
                <?php
                settings_fields( 'wwt_toc_settings_group' );
                ?>
                <?php
                $cards = array(
                    'posts'    => array(
                        'heading'         => __( 'Posts', 'working-with-toc' ),
                        'description'     => __( 'Enable the TOC and structured data for standard posts.', 'working-with-toc' ),
                        'enable_key'      => 'enable_posts',
                        'structured_key'  => 'structured_posts',
                    ),
                    'pages'    => array(
                        'heading'         => __( 'Pages', 'working-with-toc' ),
                        'description'     => __( "Enable the TOC for the site's static pages.", 'working-with-toc' ),
                        'enable_key'      => 'enable_pages',
                        'structured_key'  => 'structured_pages',
                    ),
                    'products' => array(
                        'heading'         => __( 'Products', 'working-with-toc' ),
                        'description'     => __( 'Integrate with WooCommerce for complete product pages.', 'working-with-toc' ),
                        'enable_key'      => 'enable_products',
                        'structured_key'  => 'structured_products',
                    ),
                );

                $color_labels = array(
                    'title_color'            => __( 'Title color', 'working-with-toc' ),
                    'title_background_color' => __( 'Title background', 'working-with-toc' ),
                    'background_color'       => __( 'Container background', 'working-with-toc' ),
                    'text_color'             => __( 'Text color', 'working-with-toc' ),
                    'link_color'             => __( 'Link color', 'working-with-toc' ),
                );

                $horizontal_options = array(
                    'left'   => __( 'Left', 'working-with-toc' ),
                    'center' => __( 'Center', 'working-with-toc' ),
                    'right'  => __( 'Right', 'working-with-toc' ),
                );

                $vertical_options = array(
                    'top'    => __( 'Top', 'working-with-toc' ),
                    'bottom' => __( 'Bottom', 'working-with-toc' ),
                );
                ?>
                <div class="wwt-toc-card-grid">
                    <?php foreach ( $cards as $prefix => $card ) :
                        $title_field_name = sprintf( '%s[%s_title]', Settings::OPTION_NAME, $prefix );
                        $title_field_id   = sprintf( 'wwt_toc_%s_title', $prefix );
                        $horizontal_field_name = sprintf( '%s[%s_horizontal_alignment]', Settings::OPTION_NAME, $prefix );
                        $horizontal_field_id   = sprintf( 'wwt_toc_%s_horizontal_alignment', $prefix );
                        $vertical_field_name   = sprintf( '%s[%s_vertical_alignment]', Settings::OPTION_NAME, $prefix );
                        $vertical_field_id     = sprintf( 'wwt_toc_%s_vertical_alignment', $prefix );
                        ?>
                        <div class="wwt-toc-card">
                            <h2><?php echo esc_html( $card['heading'] ); ?></h2>
                            <p><?php echo esc_html( $card['description'] ); ?></p>
                            <div class="wwt-toc-toggle-group">
                                <div class="wwt-toc-toggle-row">
                                    <span class="wwt-toc-toggle-label"><?php esc_html_e( 'Table of contents', 'working-with-toc' ); ?></span>
                                    <label class="wwt-switch">
                                        <input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[<?php echo esc_attr( $card['enable_key'] ); ?>]" value="1" <?php checked( $settings[ $card['enable_key'] ] ); ?>>
                                        <span class="wwt-slider"></span>
                                    </label>
                                </div>
                                <div class="wwt-toc-toggle-row">
                                    <span class="wwt-toc-toggle-label"><?php esc_html_e( 'TOC structured data', 'working-with-toc' ); ?></span>
                                    <label class="wwt-switch">
                                        <input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[<?php echo esc_attr( $card['structured_key'] ); ?>]" value="1" <?php checked( $settings[ $card['structured_key'] ] ); ?>>
                                        <span class="wwt-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="wwt-toc-style">
                                <h3 class="wwt-toc-style__title"><?php esc_html_e( 'Default style', 'working-with-toc' ); ?></h3>
                                <p class="wwt-toc-style__hint"><?php esc_html_e( 'Set the title and base colors for the table of contents on this content type.', 'working-with-toc' ); ?></p>
                                <div class="wwt-toc-style__field">
                                    <label for="<?php echo esc_attr( $title_field_id ); ?>"><?php esc_html_e( 'TOC title', 'working-with-toc' ); ?></label>
                                    <input type="text" id="<?php echo esc_attr( $title_field_id ); ?>" name="<?php echo esc_attr( $title_field_name ); ?>" value="<?php echo esc_attr( $settings[ $prefix . '_title' ] ); ?>" class="widefat" />
                                </div>
                                <div class="wwt-toc-style__colors">
                                    <?php foreach ( $color_labels as $field => $label ) :
                                        $field_name = sprintf( '%s[%s_%s]', Settings::OPTION_NAME, $prefix, $field );
                                        $field_id   = sprintf( 'wwt_toc_%s_%s', $prefix, $field );
                                        ?>
                                        <div class="wwt-toc-style__color">
                                            <label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label>
                                            <input type="color" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $settings[ $prefix . '_' . $field ] ); ?>" />
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="wwt-toc-style__layout">
                                    <div class="wwt-toc-style__layout-field">
                                        <label for="<?php echo esc_attr( $horizontal_field_id ); ?>"><?php esc_html_e( 'Horizontal position', 'working-with-toc' ); ?></label>
                                        <select id="<?php echo esc_attr( $horizontal_field_id ); ?>" name="<?php echo esc_attr( $horizontal_field_name ); ?>">
                                            <?php foreach ( $horizontal_options as $value => $label ) : ?>
                                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings[ $prefix . '_horizontal_alignment' ], $value ); ?>><?php echo esc_html( $label ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="wwt-toc-style__layout-field">
                                        <label for="<?php echo esc_attr( $vertical_field_id ); ?>"><?php esc_html_e( 'Vertical position', 'working-with-toc' ); ?></label>
                                        <select id="<?php echo esc_attr( $vertical_field_id ); ?>" name="<?php echo esc_attr( $vertical_field_name ); ?>">
                                            <?php foreach ( $vertical_options as $value => $label ) : ?>
                                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings[ $prefix . '_vertical_alignment' ], $value ); ?>><?php echo esc_html( $label ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="wwt-toc-submit">
                    <?php submit_button( __( 'Save settings', 'working-with-toc' ) ); ?>
                </div>
            </form>
            <footer class="wwt-toc-footer">
                <p><?php esc_html_e( 'Compatible with Rank Math and Yoast SEO. Enable WordPress debug mode to log plugin events.', 'working-with-toc' ); ?></p>
            </footer>
        </div>
        <?php
    }
}

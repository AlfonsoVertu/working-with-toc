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
     * Retrieve plugin metadata for the footer.
     */
    protected function get_plugin_metadata(): array {
        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $raw_data = get_plugin_data( WWT_TOC_PLUGIN_FILE, false, false );

        return array(
            'name'         => $raw_data['Name'] ?? __( 'Working with TOC', 'working-with-toc' ),
            'description'  => isset( $raw_data['Description'] ) ? wp_strip_all_tags( $raw_data['Description'] ) : '',
            'version'      => $raw_data['Version'] ?? WWTOC_VERSION,
            'author'       => $raw_data['AuthorName'] ?? '',
            'author_url'   => $raw_data['AuthorURI'] ?? '',
            'plugin_url'   => $raw_data['PluginURI'] ?? '',
            'license'      => $raw_data['License'] ?? 'GPLv2 or later',
            'requires_wp'  => $raw_data['RequiresWP'] ?? '',
            'requires_php' => $raw_data['RequiresPHP'] ?? '',
        );
    }

    /**
     * Register menu item.
     */
    public function register_menu(): void {
        add_menu_page(
            __( 'Working with TOC', 'working-with-toc' ),
            __( 'www TOC', 'working-with-toc' ),
            $this->capability,
            'working-with-toc',
            array( $this, 'render_page' ),
            WWT_TOC_PLUGIN_URL . 'assets/images/menu-icon.svg'
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
            WWTOC_VERSION
        );

        wp_enqueue_script(
            'wwt-toc-admin',
            WWT_TOC_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery', 'wp-a11y' ),
            WWTOC_VERSION,
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
        $metadata = $this->get_plugin_metadata();
        $logo_url = WWT_TOC_PLUGIN_URL . 'assets/images/www-logo.png';
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
                $condition_options = array(
                    'https://schema.org/NewCondition'         => __( 'New', 'working-with-toc' ),
                    'https://schema.org/UsedCondition'        => __( 'Used', 'working-with-toc' ),
                    'https://schema.org/RefurbishedCondition' => __( 'Refurbished', 'working-with-toc' ),
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
                        $headline_field_name   = sprintf( '%s[%s_schema_fallback_headline]', Settings::OPTION_NAME, $prefix );
                        $headline_field_id     = sprintf( 'wwt_toc_%s_schema_fallback_headline', $prefix );
                        $description_field_name = sprintf( '%s[%s_schema_fallback_description]', Settings::OPTION_NAME, $prefix );
                        $description_field_id   = sprintf( 'wwt_toc_%s_schema_fallback_description', $prefix );
                        $image_field_name       = sprintf( '%s[%s_schema_fallback_image]', Settings::OPTION_NAME, $prefix );
                        $image_field_id         = sprintf( 'wwt_toc_%s_schema_fallback_image', $prefix );
                        $brand_attribute_field_name = sprintf( '%s[products_schema_brand_attribute]', Settings::OPTION_NAME );
                        $brand_attribute_field_id   = 'wwt_toc_products_schema_brand_attribute';
                        $brand_fallback_field_name  = sprintf( '%s[products_schema_fallback_brand]', Settings::OPTION_NAME );
                        $brand_fallback_field_id    = 'wwt_toc_products_schema_fallback_brand';
                        $sku_fallback_field_name    = sprintf( '%s[products_schema_allow_id_as_sku]', Settings::OPTION_NAME );
                        $sku_fallback_field_id      = 'wwt_toc_products_schema_allow_id_as_sku';
                        $condition_field_name       = sprintf( '%s[products_schema_condition]', Settings::OPTION_NAME );
                        $condition_field_id         = 'wwt_toc_products_schema_condition';
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

                            <div class="wwt-toc-structured-data">
                                <h3 class="wwt-toc-structured-data__title"><?php esc_html_e( 'Base structured data defaults', 'working-with-toc' ); ?></h3>
                                <p class="wwt-toc-structured-data__hint"><?php esc_html_e( 'These values are used when the automatic headline, description, or image cannot be detected.', 'working-with-toc' ); ?></p>
                                <div class="wwt-toc-structured-data__field">
                                    <label for="<?php echo esc_attr( $headline_field_id ); ?>"><?php esc_html_e( 'Fallback headline', 'working-with-toc' ); ?></label>
                                    <input type="text" id="<?php echo esc_attr( $headline_field_id ); ?>" name="<?php echo esc_attr( $headline_field_name ); ?>" value="<?php echo esc_attr( $settings[ $prefix . '_schema_fallback_headline' ] ); ?>" class="widefat" />
                                </div>
                                <div class="wwt-toc-structured-data__field">
                                    <label for="<?php echo esc_attr( $description_field_id ); ?>"><?php esc_html_e( 'Fallback description', 'working-with-toc' ); ?></label>
                                    <textarea id="<?php echo esc_attr( $description_field_id ); ?>" name="<?php echo esc_attr( $description_field_name ); ?>" rows="3" class="widefat"><?php echo esc_textarea( $settings[ $prefix . '_schema_fallback_description' ] ); ?></textarea>
                                </div>
                                <div class="wwt-toc-structured-data__field">
                                    <label for="<?php echo esc_attr( $image_field_id ); ?>"><?php esc_html_e( 'Fallback image URL', 'working-with-toc' ); ?></label>
                                    <input type="url" id="<?php echo esc_attr( $image_field_id ); ?>" name="<?php echo esc_attr( $image_field_name ); ?>" value="<?php echo esc_attr( $settings[ $prefix . '_schema_fallback_image' ] ); ?>" class="widefat" />
                                </div>
                                <?php if ( 'products' === $prefix ) : ?>
                                    <div class="wwt-toc-structured-data__field">
                                        <label for="<?php echo esc_attr( $brand_attribute_field_id ); ?>"><?php esc_html_e( 'Brand attribute slug', 'working-with-toc' ); ?></label>
                                        <input type="text" id="<?php echo esc_attr( $brand_attribute_field_id ); ?>" name="<?php echo esc_attr( $brand_attribute_field_name ); ?>" value="<?php echo esc_attr( $settings['products_schema_brand_attribute'] ); ?>" class="widefat" />
                                        <p class="description"><?php esc_html_e( 'Provide the product attribute slug that stores the brand (for example "pa_brand").', 'working-with-toc' ); ?></p>
                                    </div>
                                    <div class="wwt-toc-structured-data__field">
                                        <label for="<?php echo esc_attr( $brand_fallback_field_id ); ?>"><?php esc_html_e( 'Fallback brand name', 'working-with-toc' ); ?></label>
                                        <input type="text" id="<?php echo esc_attr( $brand_fallback_field_id ); ?>" name="<?php echo esc_attr( $brand_fallback_field_name ); ?>" value="<?php echo esc_attr( $settings['products_schema_fallback_brand'] ); ?>" class="widefat" />
                                        <p class="description"><?php esc_html_e( 'Used when the attribute above is empty for a product.', 'working-with-toc' ); ?></p>
                                    </div>
                                    <div class="wwt-toc-structured-data__field wwt-toc-structured-data__field--checkbox">
                                        <label for="<?php echo esc_attr( $sku_fallback_field_id ); ?>">
                                            <input type="checkbox" id="<?php echo esc_attr( $sku_fallback_field_id ); ?>" name="<?php echo esc_attr( $sku_fallback_field_name ); ?>" value="1" <?php checked( ! empty( $settings['products_schema_allow_id_as_sku'] ) ); ?> />
                                            <?php esc_html_e( 'Use the product ID when the SKU is missing', 'working-with-toc' ); ?>
                                        </label>
                                    </div>
                                    <div class="wwt-toc-structured-data__field">
                                        <label for="<?php echo esc_attr( $condition_field_id ); ?>"><?php esc_html_e( 'Default product condition', 'working-with-toc' ); ?></label>
                                        <select id="<?php echo esc_attr( $condition_field_id ); ?>" name="<?php echo esc_attr( $condition_field_name ); ?>">
                                            <?php foreach ( $condition_options as $value => $label ) : ?>
                                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['products_schema_condition'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <section class="wwt-toc-organization">
                    <h2><?php esc_html_e( 'Organization structured data', 'working-with-toc' ); ?></h2>
                    <p><?php esc_html_e( 'Customise the organisation details used for fallback structured data output.', 'working-with-toc' ); ?></p>
                    <div class="wwt-toc-organization__grid">
                        <div class="wwt-toc-organization__field">
                            <label for="wwt_toc_organization_name"><?php esc_html_e( 'Organization name', 'working-with-toc' ); ?></label>
                            <input type="text" id="wwt_toc_organization_name" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[organization_name]" value="<?php echo esc_attr( $settings['organization_name'] ); ?>" class="regular-text" />
                        </div>
                        <div class="wwt-toc-organization__field">
                            <label for="wwt_toc_organization_url"><?php esc_html_e( 'Organization URL', 'working-with-toc' ); ?></label>
                            <input type="url" id="wwt_toc_organization_url" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[organization_url]" value="<?php echo esc_attr( $settings['organization_url'] ); ?>" class="regular-text" />
                        </div>
                        <div class="wwt-toc-organization__field">
                            <label for="wwt_toc_organization_logo"><?php esc_html_e( 'Logo URL', 'working-with-toc' ); ?></label>
                            <input type="url" id="wwt_toc_organization_logo" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[organization_logo]" value="<?php echo esc_attr( $settings['organization_logo'] ); ?>" class="regular-text" />
                        </div>
                        <div class="wwt-toc-organization__field">
                            <label for="wwt_toc_organization_address_street"><?php esc_html_e( 'Street address', 'working-with-toc' ); ?></label>
                            <input type="text" id="wwt_toc_organization_address_street" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[organization_address_street]" value="<?php echo esc_attr( $settings['organization_address_street'] ); ?>" class="regular-text" />
                        </div>
                        <div class="wwt-toc-organization__field">
                            <label for="wwt_toc_organization_address_locality"><?php esc_html_e( 'City / locality', 'working-with-toc' ); ?></label>
                            <input type="text" id="wwt_toc_organization_address_locality" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[organization_address_locality]" value="<?php echo esc_attr( $settings['organization_address_locality'] ); ?>" class="regular-text" />
                        </div>
                        <div class="wwt-toc-organization__field">
                            <label for="wwt_toc_organization_address_region"><?php esc_html_e( 'Region / state', 'working-with-toc' ); ?></label>
                            <input type="text" id="wwt_toc_organization_address_region" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[organization_address_region]" value="<?php echo esc_attr( $settings['organization_address_region'] ); ?>" class="regular-text" />
                        </div>
                        <div class="wwt-toc-organization__field">
                            <label for="wwt_toc_organization_address_postal_code"><?php esc_html_e( 'Postal code', 'working-with-toc' ); ?></label>
                            <input type="text" id="wwt_toc_organization_address_postal_code" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[organization_address_postal_code]" value="<?php echo esc_attr( $settings['organization_address_postal_code'] ); ?>" class="regular-text" />
                        </div>
                        <div class="wwt-toc-organization__field">
                            <label for="wwt_toc_organization_address_country"><?php esc_html_e( 'Country', 'working-with-toc' ); ?></label>
                            <input type="text" id="wwt_toc_organization_address_country" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[organization_address_country]" value="<?php echo esc_attr( $settings['organization_address_country'] ); ?>" class="regular-text" />
                        </div>
                    </div>
                </section>

                <div class="wwt-toc-submit">
                    <?php submit_button( __( 'Save settings', 'working-with-toc' ) ); ?>
                </div>
            </form>
            <?php $this->render_product_diagnostics(); ?>
            <footer class="wwt-toc-footer">
                <div class="wwt-toc-footer__branding">
                    <img class="wwt-toc-footer__logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'Working with Web logo', 'working-with-toc' ); ?>" />
                    <div class="wwt-toc-footer__summary">
                        <h3 class="wwt-toc-footer__title"><?php echo esc_html( $metadata['name'] ); ?></h3>
                        <?php if ( '' !== $metadata['description'] ) : ?>
                            <p class="wwt-toc-footer__description"><?php echo esc_html( $metadata['description'] ); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <ul class="wwt-toc-footer__details">
                    <li><strong><?php esc_html_e( 'Version:', 'working-with-toc' ); ?></strong> <?php echo esc_html( $metadata['version'] ); ?></li>
                    <?php if ( '' !== $metadata['author'] ) : ?>
                        <li><strong><?php esc_html_e( 'Author:', 'working-with-toc' ); ?></strong>
                            <?php if ( '' !== $metadata['author_url'] ) : ?>
                                <a href="<?php echo esc_url( $metadata['author_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $metadata['author'] ); ?></a>
                            <?php else : ?>
                                <?php echo esc_html( $metadata['author'] ); ?>
                            <?php endif; ?>
                        </li>
                    <?php endif; ?>
                    <?php if ( '' !== $metadata['plugin_url'] ) : ?>
                        <li><strong><?php esc_html_e( 'Plugin URI:', 'working-with-toc' ); ?></strong> <a href="<?php echo esc_url( $metadata['plugin_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View plugin page', 'working-with-toc' ); ?></a></li>
                    <?php endif; ?>
                    <li><strong><?php esc_html_e( 'License:', 'working-with-toc' ); ?></strong> <?php echo esc_html( $metadata['license'] ); ?></li>
                    <?php if ( '' !== $metadata['requires_wp'] ) : ?>
                        <li><strong><?php esc_html_e( 'Requires WordPress:', 'working-with-toc' ); ?></strong> <?php echo esc_html( $metadata['requires_wp'] ); ?></li>
                    <?php endif; ?>
                    <?php if ( '' !== $metadata['requires_php'] ) : ?>
                        <li><strong><?php esc_html_e( 'Requires PHP:', 'working-with-toc' ); ?></strong> <?php echo esc_html( $metadata['requires_php'] ); ?></li>
                    <?php endif; ?>
                </ul>
            </footer>
        </div>
        <?php
    }

    /**
     * Display a diagnostic table highlighting products missing key metadata.
     */
    protected function render_product_diagnostics(): void {
        if ( ! class_exists( '\\WooCommerce' ) || ! function_exists( 'wc_get_products' ) ) {
            return;
        }

        $issues = $this->collect_product_metadata_gaps();

        if ( empty( $issues ) ) {
            echo '<section class="wwt-toc-diagnostics"><h2>' . esc_html__( 'Product metadata diagnostics', 'working-with-toc' ) . '</h2>';
            echo '<p class="wwt-toc-diagnostics__description">' . esc_html__( 'All recently modified products include the metadata required for structured data and Open Graph output.', 'working-with-toc' ) . '</p>';
            echo '</section>';

            return;
        }

        ?>
        <section class="wwt-toc-diagnostics">
            <h2><?php esc_html_e( 'Product metadata diagnostics', 'working-with-toc' ); ?></h2>
            <p class="wwt-toc-diagnostics__description"><?php esc_html_e( 'The products below are missing critical values used by the structured data and Open Graph generators.', 'working-with-toc' ); ?></p>
            <table class="widefat striped wwt-toc-diagnostics__table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e( 'Product', 'working-with-toc' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Missing fields', 'working-with-toc' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $issues as $issue ) :
                        $product   = $issue['product'];
                        $missing   = $issue['missing'];
                        $edit_link = get_edit_post_link( $product->get_id() );
                        ?>
                        <tr>
                            <td>
                                <span class="wwt-toc-diagnostics__product">
                                    <?php if ( $edit_link ) : ?>
                                        <a href="<?php echo esc_url( $edit_link ); ?>">
                                            <?php echo esc_html( $product->get_name() ); ?>
                                        </a>
                                    <?php else : ?>
                                        <?php echo esc_html( $product->get_name() ); ?>
                                    <?php endif; ?>
                                </span>
                                <span class="wwt-toc-diagnostics__meta">#<?php echo esc_html( (string) $product->get_id() ); ?></span>
                            </td>
                            <td>
                                <ul class="wwt-toc-diagnostics__missing">
                                    <?php foreach ( $missing as $label ) : ?>
                                        <li><?php echo esc_html( $label ); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php
    }

    /**
     * Compile a list of products missing key metadata fields.
     *
     * @return array<int,array{product:\WC_Product,missing:array<int,string>}>
     */
    protected function collect_product_metadata_gaps(): array {
        $issues = array();

        $schema_settings = $this->settings->get_product_structured_data_settings();

        $products = wc_get_products(
            array(
                'limit'   => 25,
                'orderby' => 'modified',
                'order'   => 'DESC',
                'status'  => array( 'publish', 'private' ),
            )
        );

        foreach ( $products as $product ) {
            if ( ! $product instanceof \WC_Product ) {
                continue;
            }

            $missing = array();

            $sku = $product->get_sku();
            if ( '' === $sku ) {
                if ( empty( $schema_settings['allow_id_as_sku'] ) ) {
                    $missing[] = __( 'SKU', 'working-with-toc' );
                } else {
                    $missing[] = __( 'SKU (using product ID fallback)', 'working-with-toc' );
                }
            }

            if ( ! $this->diagnostic_product_has_price( $product ) ) {
                $missing[] = __( 'Price', 'working-with-toc' );
            }

            $stock_status = $product->get_stock_status();
            $allowed      = array( 'instock', 'outofstock', 'onbackorder', 'preorder' );
            if ( '' === $stock_status || ! in_array( $stock_status, $allowed, true ) ) {
                $missing[] = __( 'Stock status', 'working-with-toc' );
            }

            if ( '' === $this->diagnostic_product_brand_label( $product, $schema_settings ) ) {
                $missing[] = __( 'Brand', 'working-with-toc' );
            }

            if ( ! $this->diagnostic_product_has_global_identifier( $product ) ) {
                $missing[] = __( 'Global identifier (GTIN/MPN)', 'working-with-toc' );
            }

            if ( empty( $missing ) ) {
                continue;
            }

            $issues[] = array(
                'product' => $product,
                'missing' => $missing,
            );

            if ( count( $issues ) >= 10 ) {
                break;
            }
        }

        return $issues;
    }

    /**
     * Determine whether a product exposes a usable price.
     */
    protected function diagnostic_product_has_price( \WC_Product $product ): bool {
        if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_price' ) ) {
            $min_price = $product->get_variation_price( 'min', false );

            if ( '' !== $min_price ) {
                return true;
            }

            $max_price = $product->get_variation_price( 'max', false );

            return '' !== $max_price;
        }

        if ( $product->is_type( 'grouped' ) ) {
            $children = $product->get_children();

            foreach ( $children as $child_id ) {
                $child = wc_get_product( $child_id );

                if ( $child instanceof \WC_Product && '' !== $child->get_price() ) {
                    return true;
                }
            }

            return false;
        }

        return '' !== $product->get_price();
    }

    /**
     * Resolve the brand label for diagnostics using the configured attribute and fallback.
     *
     * @param \WC_Product $product         Product instance.
     * @param array       $schema_settings Schema configuration.
     */
    protected function diagnostic_product_brand_label( \WC_Product $product, array $schema_settings ): string {
        $brand_name      = '';
        $brand_attribute = isset( $schema_settings['brand_attribute'] ) ? (string) $schema_settings['brand_attribute'] : '';

        if ( '' !== $brand_attribute ) {
            $brand_value = $product->get_attribute( $brand_attribute );

            if ( is_string( $brand_value ) && '' !== trim( $brand_value ) ) {
                $brand_name = wp_strip_all_tags( $brand_value );
            }
        }

        if ( '' === $brand_name && ! empty( $schema_settings['fallback_brand'] ) ) {
            $brand_name = (string) $schema_settings['fallback_brand'];
        }

        $brand_name = trim( wp_strip_all_tags( $brand_name ) );

        return $brand_name;
    }

    /**
     * Determine whether a product has a global identifier (GTIN/MPN).
     */
    protected function diagnostic_product_has_global_identifier( \WC_Product $product ): bool {
        $mpn = $product->get_meta( '_mpn', true );
        if ( '' === $mpn ) {
            $mpn = $product->get_meta( 'mpn', true );
        }

        if ( is_string( $mpn ) && '' !== trim( $mpn ) ) {
            return true;
        }

        $meta_map = array(
            'gtin8'  => '_gtin8',
            'gtin12' => '_gtin12',
            'gtin13' => '_gtin13',
            'gtin14' => '_gtin14',
            'gtin'   => '_gtin',
        );

        $meta_map = apply_filters( 'working_with_toc_structured_data_gtin_meta_map', $meta_map, $product );

        if ( ! is_array( $meta_map ) ) {
            $meta_map = array();
        }

        foreach ( $meta_map as $meta_key ) {
            $value = '';

            if ( is_string( $meta_key ) && '' !== $meta_key ) {
                $value = $product->get_meta( $meta_key, true );
            }

            if ( '' === $value && is_string( $meta_key ) && taxonomy_exists( $meta_key ) ) {
                $value = $product->get_attribute( $meta_key );
            }

            if ( is_string( $value ) && '' !== trim( $value ) ) {
                return true;
            }
        }

        return false;
    }
}

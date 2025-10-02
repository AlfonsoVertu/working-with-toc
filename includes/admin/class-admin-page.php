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
     * Constructor.
     *
     * @param Settings $settings Settings manager.
     */
    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Register menu item.
     */
    public function register_menu(): void {
        add_menu_page(
            __( 'Working with TOC', 'working-with-toc' ),
            __( 'Working with TOC', 'working-with-toc' ),
            'manage_options',
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
                'on'  => __( 'attivato', 'working-with-toc' ),
                'off' => __( 'disattivato', 'working-with-toc' ),
            )
        );
    }

    /**
     * Render settings page markup.
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = $this->settings->get_settings();
        Logger::log( 'Rendering settings page.' );
        ?>
        <div class="wrap wwt-toc-admin">
            <h1><?php esc_html_e( 'Working with TOC', 'working-with-toc' ); ?></h1>
            <p class="wwt-toc-subtitle"><?php esc_html_e( 'Gestisci i dati strutturati per articoli, prodotti e pagine.', 'working-with-toc' ); ?></p>

            <form action="options.php" method="post" class="wwt-toc-form">
                <?php
                settings_fields( 'wwt_toc_settings_group' );
                ?>
                <div class="wwt-toc-card-grid">
                    <div class="wwt-toc-card">
                        <h2><?php esc_html_e( 'Articoli', 'working-with-toc' ); ?></h2>
                        <p><?php esc_html_e( 'Abilita la TOC e i dati strutturati per i post standard.', 'working-with-toc' ); ?></p>
                        <div class="wwt-toc-toggle-group">
                            <div class="wwt-toc-toggle-row">
                                <span class="wwt-toc-toggle-label"><?php esc_html_e( 'Indice dei contenuti', 'working-with-toc' ); ?></span>
                                <label class="wwt-switch">
                                    <input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[enable_posts]" value="1" <?php checked( $settings['enable_posts'] ); ?>>
                                    <span class="wwt-slider"></span>
                                </label>
                            </div>
                            <div class="wwt-toc-toggle-row">
                                <span class="wwt-toc-toggle-label"><?php esc_html_e( 'Dati strutturati TOC', 'working-with-toc' ); ?></span>
                                <label class="wwt-switch">
                                    <input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[structured_posts]" value="1" <?php checked( $settings['structured_posts'] ); ?>>
                                    <span class="wwt-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="wwt-toc-card">
                        <h2><?php esc_html_e( 'Pagine', 'working-with-toc' ); ?></h2>
                        <p><?php esc_html_e( 'Attiva la TOC per le pagine statiche del sito.', 'working-with-toc' ); ?></p>
                        <div class="wwt-toc-toggle-group">
                            <div class="wwt-toc-toggle-row">
                                <span class="wwt-toc-toggle-label"><?php esc_html_e( 'Indice dei contenuti', 'working-with-toc' ); ?></span>
                                <label class="wwt-switch">
                                    <input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[enable_pages]" value="1" <?php checked( $settings['enable_pages'] ); ?>>
                                    <span class="wwt-slider"></span>
                                </label>
                            </div>
                            <div class="wwt-toc-toggle-row">
                                <span class="wwt-toc-toggle-label"><?php esc_html_e( 'Dati strutturati TOC', 'working-with-toc' ); ?></span>
                                <label class="wwt-switch">
                                    <input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[structured_pages]" value="1" <?php checked( $settings['structured_pages'] ); ?>>
                                    <span class="wwt-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="wwt-toc-card">
                        <h2><?php esc_html_e( 'Prodotti', 'working-with-toc' ); ?></h2>
                        <p><?php esc_html_e( 'Integrazione con WooCommerce per schede prodotto complete.', 'working-with-toc' ); ?></p>
                        <div class="wwt-toc-toggle-group">
                            <div class="wwt-toc-toggle-row">
                                <span class="wwt-toc-toggle-label"><?php esc_html_e( 'Indice dei contenuti', 'working-with-toc' ); ?></span>
                                <label class="wwt-switch">
                                    <input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[enable_products]" value="1" <?php checked( $settings['enable_products'] ); ?>>
                                    <span class="wwt-slider"></span>
                                </label>
                            </div>
                            <div class="wwt-toc-toggle-row">
                                <span class="wwt-toc-toggle-label"><?php esc_html_e( 'Dati strutturati TOC', 'working-with-toc' ); ?></span>
                                <label class="wwt-switch">
                                    <input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[structured_products]" value="1" <?php checked( $settings['structured_products'] ); ?>>
                                    <span class="wwt-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="wwt-toc-submit">
                    <?php submit_button( __( 'Salva impostazioni', 'working-with-toc' ) ); ?>
                </div>
            </form>
            <footer class="wwt-toc-footer">
                <p><?php esc_html_e( 'Compatibile con Rank Math e Yoast SEO. Attiva la modalità debug di WordPress per registrare gli eventi del plugin.', 'working-with-toc' ); ?></p>
            </footer>
        </div>
        <?php
    }
}

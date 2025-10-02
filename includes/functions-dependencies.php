<?php
/**
 * Dependency helpers.
 *
 * @package Working_With_TOC
 */

namespace Working_With_TOC;

defined( 'ABSPATH' ) || exit;

/**
 * Check whether the DOMDocument dependency is missing.
 */
function is_domdocument_missing(): bool {
    return ! empty( $GLOBALS['wwt_toc_missing_domdocument'] );
}

/**
 * Render an admin notice when DOMDocument is unavailable.
 */
function render_missing_domdocument_notice(): void {
    if ( ! is_domdocument_missing() ) {
        return;
    }

    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    printf(
        '<div class="notice notice-error"><p>%s</p></div>',
        esc_html__( 'Working with TOC requires the PHP DOM extension. Please enable the DOM extension to use the plugin.', 'working-with-toc' )
    );
}

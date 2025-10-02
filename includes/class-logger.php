<?php
/**
 * Debug logger.
 *
 * @package Working_With_TOC
 */

namespace Working_With_TOC;

defined( 'ABSPATH' ) || exit;

/**
 * Simple wrapper around error_log respecting WP_DEBUG.
 */
class Logger {

    /**
     * Log a message when debugging is enabled.
     *
     * @param string $message Message to log.
     */
    public static function log( string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Working with TOC] ' . $message );
        }
    }
}

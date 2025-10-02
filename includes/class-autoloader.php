<?php
/**
 * PSR-4 like autoloader for plugin classes.
 *
 * @package Working_With_TOC
 */

namespace Working_With_TOC;

defined( 'ABSPATH' ) || exit;

/**
 * Simple autoloader.
 */
class Autoloader {

    /**
     * Base namespace for the plugin.
     *
     * @var string
     */
    protected static $namespace = __NAMESPACE__ . '\\';

    /**
     * Register autoloader with SPL.
     */
    public static function register(): void {
        spl_autoload_register( array( __CLASS__, 'autoload' ) );
    }

    /**
     * Handle class autoloading.
     *
     * @param string $class Class name.
     */
    protected static function autoload( string $class ): void {
        if ( 0 !== strpos( $class, self::$namespace ) ) {
            return;
        }

        $relative = str_replace( self::$namespace, '', $class );
        $relative = str_replace( '\\', '/', $relative );
        $relative = strtolower( $relative );
        $relative = str_replace( '_', '-', $relative );

        $parts    = explode( '/', $relative );
        $filename = array_pop( $parts );
        $base_dir = WWT_TOC_PLUGIN_DIR . 'includes/';

        if ( ! empty( $parts ) ) {
            $base_dir .= implode( '/', $parts ) . '/';
        }

        $candidates = array(
            $base_dir . 'class-' . $filename . '.php',
            $base_dir . $filename . '.php',
        );

        foreach ( $candidates as $file ) {
            if ( file_exists( $file ) ) {
                require_once $file;
                return;
            }
        }
    }
}

<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( function_exists( 'delete_option' ) ) {
    delete_option( 'wwt_toc_settings' );
}

if ( function_exists( 'delete_metadata' ) ) {
    delete_metadata( 'post', 0, '_wwt_toc_meta', '', true );
}

if ( function_exists( 'wp_roles' ) ) {
    $wp_roles = wp_roles();

    if ( $wp_roles && is_object( $wp_roles ) && method_exists( $wp_roles, 'get_role' ) && property_exists( $wp_roles, 'roles' ) && is_array( $wp_roles->roles ) ) {
        foreach ( $wp_roles->roles as $role_name => $role_info ) {
            $role = $wp_roles->get_role( $role_name );

            if ( $role && method_exists( $role, 'remove_cap' ) && isset( $role->capabilities['manage_working_with_toc'] ) ) {
                $role->remove_cap( 'manage_working_with_toc' );
            }
        }
    }
}

<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;
$table_name = $wpdb->prefix . 'working_with_toc';

$wpdb->query( "DROP TABLE IF EXISTS $table_name" );

delete_option( 'wwtoc_db_version' );

<?php
/** Uninstall removes plugin settings, queued payloads and automatic-send markers. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

function ava_woo_uninstall_site() {
    global $wpdb;
    wp_clear_scheduled_hook( 'ava_woo_send' );
    wp_clear_scheduled_hook( 'ava_woo_report' );
    wp_clear_scheduled_hook( 'ava_woo_housekeeping' );
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( 'ava_woo_send', null, 'ava-woocommerce' );
        as_unschedule_all_actions( 'ava_woo_report', null, 'ava-woocommerce' );
    }
    // Both names are constructed exclusively from the trusted WordPress table prefix.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall permanently removes only this plugin-owned table; DDL has no cacheable result or equivalent WordPress API.
    $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'ava_woo_messages' ) );
    wp_cache_set_last_changed( 'ava_woo_logs' );
    foreach ( array( 'ava_woo_settings', 'ava_woo_schema', 'ava_woo_store_id', 'ava_woo_store_origin', 'ava_woo_template_revisions' ) as $option ) {
        delete_option( $option );
    }
    $revision_options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'ava_woo_template_revision_' ) . '%' ) );
    foreach ( $revision_options as $option ) {
        delete_option( $option );
    }
    // WC_Order CRUD storage differs by HPOS; remove only this plugin's markers.
    foreach ( array( $wpdb->postmeta, $wpdb->prefix . 'wc_orders_meta' ) as $table ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must inspect actual table existence; a cached answer could skip cleanup or query a missing HPOS table.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time uninstall removes only this plugin's prefixed markers across both order stores; no cached query result is used.
            $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $table, $wpdb->esc_like( '_ava_woo_' ) . '%' ) );
        }
    }
}

if ( is_multisite() ) {
    $ava_woo_offset = 0;
    do {
        $ava_woo_sites = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $ava_woo_offset ) );
        foreach ( $ava_woo_sites as $ava_woo_site ) {
            switch_to_blog( $ava_woo_site );
            ava_woo_uninstall_site();
            restore_current_blog();
        }
        $ava_woo_offset += 100;
    } while ( count( $ava_woo_sites ) === 100 );
} else {
    ava_woo_uninstall_site();
}

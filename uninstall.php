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
    $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ava_woo_messages' );
    foreach ( array( 'ava_woo_settings', 'ava_woo_schema', 'ava_woo_store_id' ) as $option ) {
        delete_option( $option );
    }
    // WC_Order CRUD storage differs by HPOS; remove only this plugin's markers.
    foreach ( array( $wpdb->postmeta, $wpdb->prefix . 'wc_orders_meta' ) as $table ) {
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE meta_key LIKE %s", $wpdb->esc_like( '_ava_woo_' ) . '%' ) );
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

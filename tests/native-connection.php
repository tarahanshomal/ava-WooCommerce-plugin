<?php
/** Native WordPress/WooCommerce acceptance; explicit disposable fixture only. */
$args = getopt( '', array( 'wp-root:', 'phase:', 'upgrade:' ) );
$root = realpath( $args['wp-root'] ?? '' );
if ( PHP_SAPI !== 'cli' || ! $root || 0 !== strpos( $root, sys_get_temp_dir() . '/' ) || ! is_file( $root . '/wp-load.php' ) ) {
    throw new RuntimeException( 'An explicit disposable WordPress fixture under /tmp is required.' );
}
$_SERVER['HTTP_HOST'] = 'woo-fixture.invalid';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=ava-woo-sms';
define( 'WP_ADMIN', true );
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
if ( ! defined( 'DB_ENGINE' ) || 'sqlite' !== DB_ENGINE || 0 !== strpos( realpath( DB_PATH ), dirname( $root ) . '/' ) ||
    ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || ! WP_HTTP_BLOCK_EXTERNAL || ! DISABLE_WP_CRON || 'local' !== wp_get_environment_type() ||
    ! preg_match( '/\.invalid$/D', wp_parse_url( home_url(), PHP_URL_HOST ) ) || function_exists( 'curl_exec' ) || function_exists( 'mail' ) || ini_get( 'allow_url_fopen' ) ) {
    throw new RuntimeException( 'Disposable SQLite, .invalid origin, local environment and offline transports are required.' );
}

use AvaWoo\Api;
use AvaWoo\Admin;
use AvaWoo\Settings;
use AvaWoo\Queue;

$checks = 0;
function native_check( $condition, $label ) {
    global $checks;
    ++$checks;
    if ( ! $condition ) {
        throw new RuntimeException( 'FAIL: ' . $label );
    }
}
function native_response( $status, $body ) {
    return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => $status, 'message' => 'Synthetic' ), 'cookies' => array() );
}
function native_rows() {
    global $wpdb;
    return $wpdb->get_results( 'SELECT * FROM ' . Queue::table() . ' ORDER BY id', ARRAY_A );
}
function native_queue( $id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Queue::table() . ' WHERE id = %d', $id ) );
}
function native_metadata( $entries ) {
    $rows = json_decode( wp_json_encode( $entries ), true );
    usort( $rows, static function( $a, $b ) { return $a['id'] <=> $b['id']; } );
    return $rows;
}
$administrators = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
native_check( ! empty( $administrators ), 'Synthetic administrator exists' );
$admin = $administrators[0]->ID;
wp_set_current_user( $admin );
$plugin = 'ava-WooCommerce-plugin/ava-woocommerce-plugin.php';
native_check( class_exists( 'WooCommerce' ) && class_exists( Queue::class ), 'Actual installed WooCommerce and Ava boot normally' );
native_check( is_plugin_active( $plugin ) && ! is_dir( WP_PLUGIN_DIR . '/ava-sms-for-woocommerce' ), 'Only the original plugin directory is installed and active' );
native_check( is_wp_error( wp_remote_get( 'https://unmocked.invalid/denied' ) ), 'All unmocked HTTP is intercepted before transport' );

$state_path = dirname( $root ) . '/native-state.json';
if ( 'before' === ( $args['phase'] ?? '' ) ) {
    native_check( '1.2.0' === AVA_WOO_VERSION, 'Upgrade begins with the published 1.2.0 plugin' );
    Queue::install();
    $settings = Settings::defaults();
    $settings['enabled'] = 'yes';
    $settings['api_url'] = 'https://ava-fixture.invalid';
    $settings['api_key'] = 'ava_live_' . str_repeat( 'x', 32 );
    $settings['admin_new_order'] = 'no';
    $settings['admin_phones'] = '';
    $settings['customer_statuses'] = array( 'processing' );
    update_option( 'ava_woo_settings', $settings, false );
    $order = wc_create_order();
    $order->set_billing_first_name( 'مشتری' );
    $order->set_billing_last_name( 'آزمایشی' );
    $order->set_billing_phone( '09120000000' );
    $order->set_currency( 'IRR' );
    $order->set_total( '125000' );
    $order->save();
    $order->update_status( 'processing' );
    global $wpdb;
    $job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Queue::table() . ' WHERE order_id = %d ORDER BY id DESC LIMIT 1', $order->get_id() ) );
    native_check( $job && 'pending' === $job->status && ! empty( $job->template_context ), 'Real order hook admits a frozen template-aware notification' );
    $wpdb->update( Queue::table(), array( 'status' => 'unknown', 'detail' => 'Synthetic pre-upgrade uncertain result' ), array( 'id' => $job->id ) );
    $state = array( 'settings' => Settings::get(), 'store_id' => AvaWoo\Template::store_id(), 'queue' => native_rows(), 'order_id' => $order->get_id(), 'job_id' => $job->id, 'metadata' => $order->get_meta_data() );
    file_put_contents( $state_path, wp_json_encode( $state ) );
    $upgrade = realpath( $args['upgrade'] ?? '' );
    native_check( $upgrade && 0 === strpos( $upgrade, dirname( $root ) . '/' ), 'Upgrade package is confined to the disposable fixture' );
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    $upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
    $result = $upgrader->install( $upgrade, array( 'overwrite_package' => true ) );
    native_check( true === $result, 'Native WordPress ZIP replacement succeeds in the original plugin directory' );
    native_check( is_plugin_active( $plugin ) && ! is_dir( WP_PLUGIN_DIR . '/ava-sms-for-woocommerce' ), 'ZIP replacement preserves activation and introduces no second plugin' );
} elseif ( 'after' === ( $args['phase'] ?? '' ) ) {
    native_check( '1.2.3' === AVA_WOO_VERSION, 'A fresh WordPress process boots the upgraded 1.2.3 plugin' );
    $state = json_decode( file_get_contents( $state_path ), true );
    native_check( $state['settings'] === Settings::get(), 'Native upgrade preserves the complete saved connection and notification settings' );
    native_check( $state['store_id'] === AvaWoo\Template::store_id(), 'Native upgrade preserves the store identity' );
    native_check( $state['queue'] === native_rows(), 'Native upgrade preserves every existing queue row and uncertain result' );
    $order = wc_get_order( $state['order_id'] );
    native_check( native_metadata( $state['metadata'] ) == native_metadata( $order->get_meta_data() ), 'Native upgrade preserves order deduplication metadata' );

    $requests = array();
    $connection_body = array( 'authenticated' => true, 'sms_send' => true, 'sms_read' => true, 'sms_account_ready' => true, 'sms_service_ready' => true );
    $GLOBALS['ava_woo_native_http'] = static function( $request, $url ) use ( &$requests, $connection_body ) {
        $requests[] = array( 'method' => $request['method'], 'url' => $url );
        if ( 'GET' === $request['method'] && 'https://ava-fixture.invalid/api/v1/connection' === $url ) {
            return native_response( 200, $connection_body );
        }
        if ( 'GET' === $request['method'] && 'https://ava-fixture.invalid/api/v1/wallet' === $url ) {
            return native_response( 200, array( 'balance_irr' => 1000, 'held_irr' => 0, 'available_irr' => 1000, 'currency' => 'IRR' ) );
        }
        return new WP_Error( 'unexpected_native_http', 'Unexpected request is blocked' );
    };
    $rows = native_rows();
    native_check( 'ready' === Api::connection()['state'], 'Actual WordPress HTTP filter supports the no-send connection check' );
    native_check( $rows === native_rows(), 'Native diagnostic leaves the message queue unchanged' );
    native_check( 1 === count( $requests ) && 'GET' === $requests[0]['method'], 'Native diagnostic emits exactly one read-only request' );
    $_GET['tab'] = 'settings';
    ob_start(); Admin::page(); $html = ob_get_clean();
    native_check( false !== strpos( $html, 'بررسی اتصال بدون ارسال پیامک' ), 'Actual WordPress admin renders the diagnostic action' );
    native_check( false === strpos( $html, Settings::get()['api_key'] ), 'Actual WordPress admin never exposes the synthetic key' );

    $GLOBALS['ava_woo_native_http'] = static function( $request, $url ) use ( &$requests ) {
        $requests[] = array( 'method' => $request['method'], 'url' => $url );
        return native_response( 401, array( 'auth_reason' => 'key_version_mismatch', 'error' => 'private-upstream-text' ) );
    };
    $failed = Queue::enqueue( '09120000000', 'پیام آزمون اتصال', 'native-auth-failure', 0, 'test' );
    Queue::process( $failed );
    native_check( 'failed' === native_queue( $failed )->status, 'Native Action Scheduler send path records a definite unauthorized failure' );
    native_check( false !== strpos( native_queue( $failed )->detail, 'بازیابی رمز' ), 'Native failed queue row explains security invalidation' );
    ob_start(); Admin::connection_notice(); $notice = ob_get_clean();
    native_check( false !== strpos( $notice, 'کلید تازه' ) && false === strpos( $notice, 'private-upstream-text' ), 'Native merchant notice offers safe credential replacement steps' );
    $settings = Settings::get();
    $settings['api_key'] = 'ava_live_' . str_repeat( 'z', 32 );
    update_option( 'ava_woo_settings', $settings, false );
    native_check( false === Api::connection_snapshot(), 'Native key replacement isolates the prior diagnostic' );
    $count = count( $requests );
    Queue::process( $failed );
    Queue::process( $state['job_id'] );
    native_check( $count === count( $requests ), 'Native key replacement replays neither a rejected nor an uncertain message' );
} else {
    throw new RuntimeException( 'Choose --phase=before or --phase=after.' );
}
echo 'PASS: ' . $checks . ' native WordPress/WooCommerce assertions (' . $args['phase'] . "), offline transports only.\n";

<?php
/**
 * Destructive integration smoke for a disposable local .invalid WordPress site only.
 * Requires WooCommerce, this plugin, DISABLE_WP_CRON=true, WP_ENVIRONMENT_TYPE=local.
 * Invoke with AVA_WOO_RUN_INTEGRATION=1 wp eval-file tests/integration-smoke.php classic
 * (or hpos); select/synchronize storage before loading each invocation.
 * All WordPress HTTP and mail are intercepted. No real Ava key or SMS is used.
 */
if (!defined('WP_CLI') || !WP_CLI || !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON || wp_get_environment_type() !== 'local' || getenv('AVA_WOO_RUN_INTEGRATION') !== '1') {
    throw new RuntimeException('Run only through WP CLI on the disposable offline fixture.');
}
if (!preg_match('/\.invalid$/D', (string) wp_parse_url(get_option('home'), PHP_URL_HOST))) {
    throw new RuntimeException('Synthetic site guard failed.');
}
use AvaWoo\Settings;
use AvaWoo\Queue;
use AvaWoo\Notifications;
use AvaWoo\Admin;

add_filter('pre_http_request', static function($response, $request, $url) {
    if (false !== $response) return $response;
    if (isset($GLOBALS['ava_integration_http']) && is_callable($GLOBALS['ava_integration_http'])) {
        return call_user_func($GLOBALS['ava_integration_http'], $request, $url);
    }
    return new WP_Error('integration_offline', 'All outbound HTTP is blocked in this disposable integration test.');
}, PHP_INT_MAX, 3);
add_filter('pre_wp_mail', '__return_true');

global $wpdb, $checks;
$checks = 0;
function ava_check($condition, $message) {
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}
function ava_row($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Queue::table() . ' WHERE id=%d', $id));
}
function ava_count($order_id) {
    global $wpdb;
    return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Queue::table() . ' WHERE order_id=%d', $order_id));
}

$mode = $args[0] ?? 'classic';
$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
ava_check($hpos === ('hpos' === $mode), "Requested order storage {$mode} is active");
ava_check(class_exists('AvaWoo\\Queue'), 'Plugin bootstrapped with real WooCommerce');
ava_check($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(Queue::table()))) === Queue::table(), 'Activation created queue table');

$settings = Settings::defaults();
$settings['enabled'] = 'yes';
$settings['api_url'] = 'https://ava-integration.invalid';
$settings['api_key'] = 'ava_live_' . str_repeat('0', 40);
$settings['sender'] = '300000000000';
$settings['admin_phones'] = '09120000002';
$settings['customer_statuses'] = ['processing', 'completed'];
$settings['templates']['processing'] = '{billing_first_name} {billing_last_name}: {order_number} {order_status} {order_total} {currency}';
update_option('ava_woo_settings', $settings, false);
$administrators = get_users(['role' => 'administrator', 'number' => 1]);
if (!$administrators) throw new RuntimeException('Disposable site needs an administrator.');
wp_set_current_user((int)$administrators[0]->ID);
Admin::register();
$settings_before = Settings::get();
$sanitized = Settings::sanitize(array_merge($settings, ['api_key' => '']));
ava_check($sanitized['api_key'] === $settings['api_key'], 'Blank API key preserves saved credential');

foreach (['settings', 'send', 'logs'] as $tab) {
    $_GET['tab'] = $tab;
    ob_start();
    Admin::page();
    $html = ob_get_clean();
    ava_check(strpos($html, 'پیامک آوا') !== false && strlen($html) > 1000, "Admin {$tab} screen renders");
    ava_check(strpos($html, $settings['api_key']) === false, "Admin {$tab} screen does not reveal API key");
}

$order = wc_create_order();
$order->set_billing_first_name('مشتری');
$order->set_billing_last_name('آزمایشی');
$order->set_billing_phone('+98 912 000 0001');
$order->set_currency('IRR');
$order->set_total('125000');
$order->save();
$oid = $order->get_id();
ava_check($oid > 0 && 'pending' === $order->get_status(), 'Synthetic WC order saved');
$order->update_status('processing');
ava_check(ava_count($oid) === 1, 'Status change enqueues one customer notification');
do_action('woocommerce_checkout_order_processed', $oid);
do_action('woocommerce_store_api_checkout_order_processed', $order);
do_action('woocommerce_order_status_changed', $oid, 'pending', 'processing', $order);
ava_check(ava_count($oid) === 2, 'Classic, Blocks and repeated status hooks deduplicate customer/admin events');
$jobs = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Queue::table() . ' WHERE order_id=%d ORDER BY id', $oid));
foreach ($jobs as $job) {
    ava_check('pending' === $job->status && false !== as_next_scheduled_action('ava_woo_send', [(int)$job->id], Queue::GROUP), 'Notification schedules actual Action Scheduler job');
}
$posts = [];
$gets = [];
$GLOBALS['ava_integration_http'] = static function($request, $url) use (&$posts, &$gets, $settings) {
    ava_check(strpos($url, 'https://ava-integration.invalid/api/v1/sms') === 0, 'HTTP is intercepted at synthetic API origin');
    ava_check($request['headers']['Authorization'] === 'Bearer ' . $settings['api_key'], 'Bearer authentication header matches fixture');
    ava_check($request['redirection'] === 0 && $request['sslverify'] === true, 'Redirects disabled and TLS verification retained');
    if ('POST' === $request['method']) {
        $payload = json_decode($request['body'], true);
        ava_check(isset($payload['phone'], $payload['text'], $payload['sender']) && $payload['sender'] === $settings['sender'], 'POST uses real Ava phone/text/sender contract');
        ava_check(preg_match('/^09[0-9]{9}$/', $payload['phone']) === 1 && strpos($payload['text'], '{order_') === false, 'Phone normalized and template placeholders rendered');
        ava_check(preg_match('/^[a-f0-9]{64}$/', $request['headers']['Idempotency-Key']) === 1, 'Durable idempotency key has accepted syntax');
        $posts[] = $request;
        return ['headers' => [], 'body' => json_encode(['id' => 'synthetic_' . count($posts), 'status' => 'pending']), 'response' => ['code' => 202, 'message' => 'Accepted'], 'cookies' => []];
    }
    $gets[] = $request;
    $id = basename($url);
    return ['headers' => [], 'body' => json_encode(['id' => $id, 'status' => 'delivered']), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
};
foreach ($jobs as $job) {
    Queue::process($job->id);
    ava_check('queued' === ava_row($job->id)->status, '202 pending response is stored as queued, not delivered');
    Queue::process($job->id);
}
ava_check(count($posts) === 2, 'Repeated worker calls cannot resend queued jobs');
$report = Queue::refresh($jobs[0]->id);
ava_check(!is_wp_error($report) && 'delivered' === $report['status'] && 'delivered' === ava_row($jobs[0]->id)->status, 'Mocked delivery report updates persisted job');
ava_check(count($gets) === 1 && count($posts) === 2, 'Refreshing delivery performs only GET');

$unknown_calls = 0;
$GLOBALS['ava_integration_http'] = static function($request, $url) use (&$unknown_calls) {
    ++$unknown_calls;
    return new WP_Error('http_request_failed', 'Synthetic timeout');
};
$unknown_id = Queue::enqueue('09120000003', 'Synthetic timeout', 'unknown-' . $mode . '-' . wp_generate_uuid4(), 0, 'test');
Queue::process($unknown_id);
ava_check('unknown' === ava_row($unknown_id)->status, 'Network timeout persists unknown result');
Queue::process($unknown_id);
Queue::housekeeping();
ava_check($unknown_calls === 1 && 'unknown' === ava_row($unknown_id)->status, 'Unknown result never resends during worker or housekeeping');

$stale_id = Queue::enqueue('09120000004', 'Synthetic interrupted worker', 'stale-' . $mode . '-' . wp_generate_uuid4(), 0, 'test');
$wpdb->update(Queue::table(), ['status' => 'processing', 'updated_at' => gmdate('Y-m-d H:i:s', time()-1200)], ['id' => $stale_id]);
Queue::housekeeping();
Queue::process($stale_id);
ava_check('unknown' === ava_row($stale_id)->status && $unknown_calls === 1, 'Interrupted processing becomes unknown without retry');

$cancel_id = Queue::enqueue('09120000005', 'Synthetic account change', 'cancel-' . $mode . '-' . wp_generate_uuid4(), 0, 'test');
$modified = $settings;
$modified['api_key'] = 'ava_live_' . str_repeat('1', 40);
update_option('ava_woo_settings', $modified);
Queue::process($cancel_id);
ava_check('cancelled' === ava_row($cancel_id)->status && $unknown_calls === 1, 'Changing API account cancels queued payload before HTTP');
update_option('ava_woo_settings', $settings);

$invalid = Queue::enqueue('not-a-phone', 'Synthetic invalid', 'invalid-' . $mode, 0, 'test');
ava_check(is_wp_error($invalid), 'Invalid phone cannot enter queue');

$wpdb->query($wpdb->prepare('UPDATE ' . Queue::table() . ' SET created_at=%s WHERE order_id=%d', gmdate('Y-m-d H:i:s', time()-35*DAY_IN_SECONDS), $oid));
Queue::cleanup();
ava_check(ava_count($oid) === 0, 'Retention removes expired sensitive message payload');
$order = wc_get_order($oid);
do_action('woocommerce_checkout_order_processed', $oid);
do_action('woocommerce_store_api_checkout_order_processed', $order);
do_action('woocommerce_order_status_changed', $oid, 'pending', 'processing', $order);
ava_check(ava_count($oid) === 0, 'WC order tombstones prevent resending after retention');

$store = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
ava_check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$store} WHERE meta_key LIKE %s", $wpdb->esc_like('_ava_woo_') . '%')) > 0, 'Notification markers persist through correct WC storage API');
update_option('ava_integration_' . $mode . '_order', $oid);
update_option('ava_integration_' . $mode . '_checks', $checks);
unset($GLOBALS['ava_integration_http']);
echo "INTEGRATION_OK mode={$mode} checks={$checks} WP=" . get_bloginfo('version') . ' WC=' . WC_VERSION . ' PHP=' . PHP_VERSION . "\n";

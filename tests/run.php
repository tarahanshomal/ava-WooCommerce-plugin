<?php
/** Dependency-free behavioral regression tests. All HTTP is mocked. */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/fixtures/wordpress/');
define('AVA_WOO_VERSION', '1.1.0');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

final class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function __($text, $domain = '') { return $text; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_strip_all_tags($text) { return strip_tags(preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text)); }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function sanitize_text_field($text) { return trim(preg_replace('/[\r\n\t ]+/', ' ', wp_strip_all_tags($text))); }
function sanitize_textarea_field($text) { return trim(wp_strip_all_tags($text)); }
function get_bloginfo($field = '') { return 'فروشگاه نمونه &amp; آزمایشی'; }
function get_option($name, $default = false) { return array_key_exists($name, $GLOBALS['ava_test_options']) ? $GLOBALS['ava_test_options'][$name] : $default; }
function wp_parse_url($url) { return parse_url($url); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function absint($value) { return abs((int) $value); }
function add_option($name, $value, $deprecated = '', $autoload = null) { if (array_key_exists($name, $GLOBALS['ava_test_options'])) { return false; } $GLOBALS['ava_test_options'][$name] = $value; return true; }
function current_time($type, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
function wp_generate_uuid4() { static $id = 0; return sprintf('00000000-0000-4000-a000-%012d', ++$id); }
function wp_next_scheduled($hook, $args = array()) { foreach ($GLOBALS['ava_test_schedules'] as $scheduled) { if ($scheduled['hook'] === $hook && $scheduled['args'] === $args) { return $scheduled['time']; } } return false; }
function wp_schedule_single_event($time, $hook, $args = array()) { $GLOBALS['ava_test_schedules'][] = array('time' => $time, 'hook' => $hook, 'args' => $args); return true; }
function wc_get_order($id) { return $GLOBALS['ava_test_orders'][(int) $id] ?? false; }
final class AvaTestStop extends RuntimeException {}
function current_user_can($capability) { return $GLOBALS['ava_test_can_manage']; }
function wp_die($message, $title = '', $args = array()) { throw new AvaTestStop('denied'); }
function check_admin_referer($nonce) { $GLOBALS['ava_test_nonce_checks'][] = $nonce; if (!$GLOBALS['ava_test_valid_nonce']) { throw new AvaTestStop('bad_nonce'); } return true; }
function wp_unslash($value) { return stripslashes($value); }
function get_current_user_id() { return 7; }
function set_transient($name, $value, $expires = 0) { $GLOBALS['ava_test_transients'][$name] = $value; $GLOBALS['ava_test_transient_expirations'][$name] = $expires ? time() + $expires : 0; return true; }
function get_transient($name) { if (($GLOBALS['ava_test_transient_expirations'][$name] ?? 0) && $GLOBALS['ava_test_transient_expirations'][$name] <= time()) { return false; } return $GLOBALS['ava_test_transients'][$name] ?? false; }
function delete_transient($name) { unset($GLOBALS['ava_test_transients'][$name]); return true; }
function wp_safe_redirect($url) { $GLOBALS['ava_test_redirect'] = $url; throw new AvaTestStop('redirect'); }
function admin_url($path = '') { return 'https://store.example.com/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function esc_textarea($value) { return esc_html($value); }
function esc_html__($value, $domain = '') { return esc_html($value); }
function checked($checked, $current = true, $display = true) { $output = ((string) $checked === (string) $current) ? ' checked="checked"' : ''; if ($display) { echo $output; } return $output; }
function disabled($disabled, $current = true, $display = true) { $output = ((string) $disabled === (string) $current) ? ' disabled="disabled"' : ''; if ($display) { echo $output; } return $output; }
function settings_fields($group) { echo '<input type="hidden" name="option_page" value="' . esc_attr($group) . '">'; }
function settings_errors($setting) {}
function submit_button($text = 'Save', $type = 'primary', $name = 'submit', $wrap = true) { echo '<button type="submit">' . esc_html($text) . '</button>'; }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="synthetic_nonce">'; }
function wp_date($format, $timestamp) { return gmdate($format, $timestamp); }


function add_settings_error($setting, $code, $message, $type = 'error') { $GLOBALS['ava_test_settings_errors'][] = array($setting, $code, $message, $type); }
function wc_get_order_statuses() { return array('wc-pending' => 'در انتظار پرداخت', 'wc-processing' => 'در حال انجام', 'wc-on-hold' => 'در انتظار بررسی', 'wc-completed' => 'تکمیل شده', 'wc-cancelled' => 'لغو شده', 'wc-failed' => 'ناموفق', 'wc-refunded' => 'مسترد شده', 'wc-shipped' => 'ارسال شده'); }
function wc_get_order_status_name($status) { return wc_get_order_statuses()['wc-' . $status] ?? $status; }
function wp_safe_remote_request($url, $args) {
    $GLOBALS['ava_test_requests'][] = array('url' => $url, 'args' => $args);
    if (!$GLOBALS['ava_test_responses']) { throw new RuntimeException('Unexpected HTTP request: all traffic must be mocked.'); }
    return array_shift($GLOBALS['ava_test_responses']);
}
function wp_remote_retrieve_response_code($response) { return $response['response']['code'] ?? ''; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }

final class WC_Order {
    public $id = 42;
    public $status = 'processing';
    private $meta = array();
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function save_meta_data() { return true; }
    public function get_id() { return $this->id; }
    public function get_order_number() { return 'WEB-0042'; }
    public function get_status() { return $this->status; }
    public function get_total() { return '125000'; }
    public function get_currency() { return 'IRR'; }
    public function get_billing_first_name() { return '<b>کاربر</b>'; }
    public function get_billing_last_name() { return 'نمونه'; }
    public function get_billing_phone() { return '09120000000'; }
    public function get_payment_method_title() { return 'پرداخت &amp; آزمایشی'; }
    public function get_shipping_method() { return 'پست'; }
}

final class AvaTestWpdb {
    public $prefix = 'wp_';
    private $db;
    public function __construct() {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE wp_ava_woo_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, event_key TEXT UNIQUE, order_id INTEGER, audience TEXT, phone TEXT, message TEXT, api_url TEXT, key_fingerprint TEXT, sender TEXT, status TEXT, provider_id TEXT NOT NULL DEFAULT '', review_status TEXT NOT NULL DEFAULT '', campaign_status TEXT NOT NULL DEFAULT '', requires_review INTEGER NOT NULL DEFAULT 0, dispatch_state TEXT NOT NULL DEFAULT '', dispatch_detail TEXT NOT NULL DEFAULT '', report_due INTEGER NOT NULL DEFAULT 0, report_attempts INTEGER NOT NULL DEFAULT 0, detail TEXT, created_at TEXT, updated_at TEXT)");
    }
    public function prepare($query, ...$args) {
        $position = 0;
        return preg_replace_callback('/%[sd]/', function ($match) use ($args, &$position) {
            if (!array_key_exists($position, $args)) { throw new RuntimeException('Missing prepared parameter'); }
            $value = $args[$position++];
            return $match[0] === '%d' ? (string) ((int) $value) : $this->db->quote((string) $value);
        }, $query);
    }
    public function query($query) { return $this->db->exec(str_replace('INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', $query)); }
    public function get_var($query) { $value = $this->db->query($query)->fetchColumn(); return $value === false ? null : $value; }
    public function get_row($query) { $row = $this->db->query($query)->fetch(PDO::FETCH_OBJ); return $row === false ? null : $row; }
    public function get_col($query) { return $this->db->query($query)->fetchAll(PDO::FETCH_COLUMN); }
    public function update($table, $values, $where) {
        $sets = $conditions = array();
        foreach ($values as $key => $value) { $sets[] = $key . '=' . $this->db->quote((string) $value); }
        foreach ($where as $key => $value) { $conditions[] = $key . '=' . $this->db->quote((string) $value); }
        return $this->query('UPDATE ' . $table . ' SET ' . implode(',', $sets) . ' WHERE ' . implode(' AND ', $conditions));
    }
}
$GLOBALS['ava_test_can_manage'] = true;
$GLOBALS['ava_test_valid_nonce'] = true;
$GLOBALS['ava_test_nonce_checks'] = array();
$GLOBALS['ava_test_transients'] = array();
$GLOBALS['ava_test_schedules'] = array();
$GLOBALS['ava_test_orders'] = array();
$GLOBALS['ava_test_options'] = array();
$GLOBALS['ava_test_requests'] = array();
$GLOBALS['ava_test_responses'] = array();
$GLOBALS['ava_test_settings_errors'] = array();
foreach (array('phone', 'template', 'settings', 'api', 'queue', 'notifications', 'admin') as $class) {
    require dirname(__DIR__) . '/includes/class-' . $class . '.php';
}

use AvaWoo\Api;
use AvaWoo\Phone;
use AvaWoo\Settings;
use AvaWoo\Template;
use AvaWoo\Queue;
use AvaWoo\Notifications;
use AvaWoo\Admin;

$count = 0;
function check($condition, $message) {
    global $count;
    ++$count;
    if (!$condition) { throw new RuntimeException('Assertion failed: ' . $message); }
}
function same($expected, $actual, $message) { check($expected === $actual, $message); }
function error_code($expected, $actual, $message) { check(is_wp_error($actual) && $expected === $actual->get_error_code(), $message); }
function mock_response($code, $body) { return array('response' => array('code' => $code), 'body' => is_string($body) ? $body : json_encode($body)); }
function reset_api($overrides = array()) {
    $GLOBALS['ava_test_options']['ava_woo_settings'] = array_merge(array('api_url' => 'https://ava.example.com', 'api_key' => 'ava_live_' . str_repeat('a', 32)), $overrides);
    $GLOBALS['ava_test_requests'] = array();
    $GLOBALS['ava_test_responses'] = array();
    $GLOBALS['ava_test_transients'] = array();
    $GLOBALS['ava_test_transient_expirations'] = array();
}

try {
    foreach (array('09120000000', '۹۱۲۰۰۰۰۰۰۰', '۰۹۱۲۰۰۰۰۰۰۰', '٠٩١٢٠٠٠٠٠٠٠', '+989120000000', '00989120000000', '989120000000', '9120000000', ' +98 (912) 000-0000 ', "\u{200f}۰۹۱۲\u{200c}۰۰۰۰۰۰۰") as $phone) {
        same('09120000000', Phone::normalize($phone), 'Iranian number and Unicode digit normalization');
    }
    foreach (array('', '+12025550123', '02100000000', '0912000000', '091200000000', '0912abc0000', array('09120000000'), null, false, '09120000000<script>') as $phone) {
        error_code('ava_phone', Phone::normalize($phone), 'Invalid phones fail before any send');
    }
    same('0912***0000', Phone::mask('09120000000'), 'Logs mask the middle of a phone number');
    same('***', Phone::mask('12'), 'Short invalid phones are not disclosed');

    $order = new WC_Order();
    $rendered = Template::render('<p>{billing_first_name} {billing_last_name}: {order_number} / {order_id} / {order_total} {currency} / {order_status}</p>\n{payment_method} | {shipping_method} | {site_name}', $order);
    check(strpos($rendered, 'کاربر نمونه: WEB-0042 / 42 / 125000 IRR / در حال انجام') !== false, 'Templates use order getters and readable status');
    check(strpos($rendered, '<p>') === false && strpos($rendered, '<b>') === false, 'HTML from templates and order fields is removed');
    check(strpos($rendered, 'پرداخت & آزمایشی | پست | فروشگاه نمونه & آزمایشی') !== false, 'HTML entities become readable SMS text');
    same('ناشناخته {unknown}', Template::render('ناشناخته {unknown}', $order), 'Unsupported placeholders remain visible for correction');
    same('سلام', Template::validate('  سلام  '), 'Valid text is trimmed');
    same(str_repeat('آ', 1000), Template::validate(str_repeat('آ', 1000)), 'Unicode character limit accepts exactly 1000 characters');
    foreach (array('', " \n\t ", str_repeat('آ', 1001), array('text')) as $text) {
        error_code('ava_text', Template::validate($text), 'Empty, oversize and non-string messages are rejected');
    }

    same('no', Settings::defaults()['enabled'], 'Automatic sending is opt-in');
    same(30, Settings::defaults()['retention_days'], 'Default log retention is 30 days');
    same('https://panel.example.com', Settings::valid_url('https://PANEL.example.com/'), 'Panel URL is normalized to HTTPS origin');
    foreach (array('http://ava.example.com', 'https://user:pass@ava.example.com', 'https://ava.example.com/path', 'https://ava.example.com?key=x', 'https://ava.example.com#key', 'https://ava.example.com:8443', 'https://127.0.0.1', 'https://localhost', array()) as $url) {
        same(false, Settings::valid_url($url), 'Unsafe or ambiguous panel origins are rejected');
    }
    reset_api();
    $saved = Settings::sanitize(array('api_url' => 'https://ava.example.com', 'api_key' => '', 'admin_phones' => '۰۹۱۲۰۰۰۰۰۰۰،+989120000000,02100000000', 'retention_days' => 999, 'customer_statuses' => array('processing', 'invented', 'shipped')));
    same('ava_live_' . str_repeat('a', 32), $saved['api_key'], 'Blank key input preserves the existing secret');
    same('09120000000', $saved['admin_phones'], 'Administrator phones are normalized, deduplicated and validated');
    same(90, $saved['retention_days'], 'Log retention has a bounded maximum');
    same(array('processing', 'shipped'), $saved['customer_statuses'], 'Known custom WooCommerce statuses are accepted');
    $saved = Settings::sanitize(array('api_url' => 'https://ava.example.com', 'delete_api_key' => '1'));
    same('', $saved['api_key'], 'Explicit delete removes the saved key');
    $saved = Settings::sanitize(array('api_url' => 'http://bad.example.com', 'api_key' => 'malformed-key'));
    same('https://ava.example.com', $saved['api_url'], 'Invalid panel URL preserves old origin');
    same('ava_live_' . str_repeat('a', 32), $saved['api_key'], 'Invalid replacement key preserves the old secret');

    reset_api(array('sender' => '30001234'));
    $GLOBALS['ava_test_responses'][] = mock_response(202, array('id' => 'sms_test_1', 'status' => 'queued', 'phone' => '09120000000', 'text' => 'private response text'));
    same(array('id' => 'sms_test_1', 'status' => 'queued'), Api::send('+989120000000', 'سلام', 'ava_test_unique_12345'), 'Queued response is preserved without claiming delivery');
    $request = $GLOBALS['ava_test_requests'][0];
    same('https://ava.example.com/api/v1/sms', $request['url'], 'Send uses the public Ava endpoint');
    same('POST', $request['args']['method'], 'Send uses POST');
    same('Bearer ava_live_' . str_repeat('a', 32), $request['args']['headers']['Authorization'], 'Bearer authentication is attached');
    same('ava_test_unique_12345', $request['args']['headers']['Idempotency-Key'], 'Stable idempotency key is attached');
    same(array('phone' => '09120000000', 'text' => 'سلام', 'sender' => '30001234'), json_decode($request['args']['body'], true), 'Request matches the Ava text SMS schema');
    same(0, $request['args']['redirection'], 'Credentials cannot follow an HTTP redirect');
    same(true, $request['args']['sslverify'], 'TLS validation is enabled');
    same(true, $request['args']['reject_unsafe_urls'], 'WordPress rejects unsafe URL destinations');
    same(1, count($GLOBALS['ava_test_requests']), 'A send makes exactly one HTTP attempt');

    reset_api();
    $GLOBALS['ava_test_responses'][] = mock_response(200, array('id' => 'existing_1', 'status' => 'accepted'));
    same(array('id' => 'existing_1', 'status' => 'accepted'), Api::send('09120000000', 'سلام', 'ava_test_unique_12345'), 'Idempotent 200 replay is accepted');
    check(!isset(json_decode($GLOBALS['ava_test_requests'][0]['args']['body'], true)['sender']), 'Blank sender is omitted so Ava chooses the default');

    foreach (array(400, 401, 403, 404, 409, 422, 429, 500, 503, 408, 302) as $code) {
        reset_api();
        $GLOBALS['ava_test_responses'][] = mock_response($code, array('error' => 'private provider message containing a secret'));
        $error = Api::send('09120000000', 'سلام', 'ava_test_unique_12345');
        error_code('ava_http_' . $code, $error, 'HTTP errors have stable error codes');
        same(1, count($GLOBALS['ava_test_requests']), 'HTTP error never triggers a blind retry');
        check(strpos($error->get_error_message(), 'private provider') === false, 'Provider response bodies are not exposed in logs');
        same($code >= 500 || $code === 408 || ($code >= 200 && $code < 400), $error->get_error_data()['uncertain'], 'Only unknown send outcomes are marked uncertain');
    }
    reset_api();
    $GLOBALS['ava_test_responses'][] = new WP_Error('http_request_failed', 'secret internal proxy detail');
    $error = Api::send('09120000000', 'سلام', 'ava_test_unique_12345');
    error_code('ava_transport', $error, 'Transport failure is captured');
    same(true, $error->get_error_data()['uncertain'], 'Transport failure does not pretend the SMS was rejected');
    same(1, count($GLOBALS['ava_test_requests']), 'Transport failure has no automatic retry');
    check(strpos($error->get_error_message(), 'secret internal') === false, 'Transport details do not disclose secrets');

    foreach (array('not JSON', array('status' => 'queued'), array('id' => '../unsafe', 'status' => 'queued'), array('id' => 'sms_1', 'status' => array('queued'))) as $body) {
        reset_api();
        $GLOBALS['ava_test_responses'][] = mock_response(202, $body);
        $error = Api::send('09120000000', 'سلام', 'ava_test_unique_12345');
        error_code('ava_response', $error, 'Malformed success is an uncertain result');
        same(true, $error->get_error_data()['uncertain'], 'Malformed send response is not safe to resend');
    }
    reset_api();
    error_code('ava_idempotency', Api::send('09120000000', 'سلام', 'too-short'), 'Invalid idempotency key is rejected locally');
    error_code('ava_phone', Api::send('not-a-phone', 'سلام', 'ava_test_unique_12345'), 'Bad phone never reaches the API');
    error_code('ava_text', Api::send('09120000000', '', 'ava_test_unique_12345'), 'Bad text never reaches the API');
    same(0, count($GLOBALS['ava_test_requests']), 'Invalid input cannot cause network activity');
    reset_api(array('api_key' => ''));
    error_code('ava_configuration', Api::send('09120000000', 'سلام', 'ava_test_unique_12345'), 'Missing credentials fail locally');
    same(0, count($GLOBALS['ava_test_requests']), 'Missing key never reaches the network');

    reset_api();
    $GLOBALS['ava_test_responses'][] = mock_response(200, array('id' => 'sms_test_1', 'status' => 'delivered'));
    same(array('id' => 'sms_test_1', 'status' => 'delivered'), Api::report('sms_test_1'), 'Report uses the returned provider status');
    same('GET', $GLOBALS['ava_test_requests'][0]['args']['method'], 'Status retrieval uses GET');
    same('https://ava.example.com/api/v1/sms/sms_test_1', $GLOBALS['ava_test_requests'][0]['url'], 'Reports use the public per-message endpoint');
    check(!isset($GLOBALS['ava_test_requests'][0]['args']['body']), 'Report cannot send a new SMS');
    check(!isset($GLOBALS['ava_test_requests'][0]['args']['headers']['Idempotency-Key']), 'Reports do not misuse send idempotency headers');
    error_code('ava_id', Api::report('../unsafe'), 'Report ID cannot inject a path');
    $metadata = array('id' => 'sms_meta', 'status' => 'pending', 'review_status' => 'approved', 'campaign_status' => 'draft', 'requires_review' => false, 'dispatch_state' => 'awaiting_dispatch', 'dispatch_detail' => 'dispatch_wait_capacity', 'detail' => 'dispatch_wait_capacity');
    $GLOBALS['ava_test_responses'][] = mock_response(200, array_merge($metadata, array('text' => 'private payload', 'credentials' => 'private-key')));
    $metadata_report = Api::report('sms_meta');
    ksort($metadata);
    ksort($metadata_report);
    same($metadata, $metadata_report, 'Only the public dispatch and review metadata is retained, excluding message payload and credentials');
    $GLOBALS['ava_test_responses'][] = mock_response(200, array('id' => 'sms_meta', 'status' => 'pending', 'dispatch_state' => array('bad'), 'requires_review' => 'false'));
    same(array('id' => 'sms_meta', 'status' => 'pending'), Api::report('sms_meta'), 'Malformed optional metadata cannot inject a status or change the review boolean');
    reset_api();
    $GLOBALS['ava_test_responses'][] = new WP_Error('http_request_failed', 'private detail');
    $error = Api::report('sms_test_1');
    same(false, $error->get_error_data()['uncertain'], 'Read-only report failure does not mark a new send uncertain');

    $wallet_body = array('balance_irr' => 1234567, 'held_irr' => 4567, 'available_irr' => 1230000, 'currency' => 'IRR');
    reset_api(array('enabled' => 'no'));
    $GLOBALS['ava_test_responses'][] = mock_response(200, array_merge($wallet_body, array('private' => 'do-not-display')));
    $wallet = Api::wallet();
    same($wallet_body, array_diff_key($wallet, array('checked_at' => true)), 'Wallet exposes only validated balances and IRR even when automatic sending is off');
    check(is_int($wallet['checked_at']) && abs(time() - $wallet['checked_at']) <= 1, 'Wallet timestamp records the local successful read');
    $request = $GLOBALS['ava_test_requests'][0];
    same('https://ava.example.com/api/v1/wallet', $request['url'], 'Wallet uses the authenticated public wallet endpoint');
    same('GET', $request['args']['method'], 'Wallet read never sends a message');
    same(5, $request['args']['timeout'], 'Wallet does not block admin rendering for a long send timeout');
    same(0, $request['args']['redirection'], 'Wallet cannot redirect an API secret');
    same(true, $request['args']['sslverify'], 'Wallet requires valid TLS');
    check(!isset($request['args']['body']) && !isset($request['args']['headers']['Idempotency-Key']), 'Wallet request has no message body or send idempotency header');
    same('Bearer ava_live_' . str_repeat('a', 32), $request['args']['headers']['Authorization'], 'Wallet reuses the saved sending credential');
    same($wallet, Api::wallet(), 'Second wallet read returns the bounded cached snapshot');
    same(1, count($GLOBALS['ava_test_requests']), 'Repeated admin rendering uses a single wallet read');
    $cache_key = array_keys($GLOBALS['ava_test_transients'])[0];
    check(strpos($cache_key, str_repeat('a', 32)) === false && strpos($cache_key, 'example.com') === false, 'Cache identifiers never disclose origin or API key');
    check($GLOBALS['ava_test_transient_expirations'][$cache_key] >= time() + 59 && $GLOBALS['ava_test_transient_expirations'][$cache_key] <= time() + 60, 'Wallet cache expires after sixty seconds');
    $changed_wallet = array('balance_irr' => 900, 'held_irr' => 0, 'available_irr' => 900, 'currency' => 'IRR');
    $GLOBALS['ava_test_options']['ava_woo_settings']['api_key'] = 'ava_live_' . str_repeat('z', 32);
    $GLOBALS['ava_test_responses'][] = mock_response(200, $changed_wallet);
    same(900, Api::wallet()['available_irr'], 'Changing the key cannot reuse another account wallet');
    $GLOBALS['ava_test_options']['ava_woo_settings']['api_url'] = 'https://other.example.com';
    $GLOBALS['ava_test_responses'][] = mock_response(200, array_merge($changed_wallet, array('balance_irr' => 800, 'available_irr' => 800)));
    same(800, Api::wallet()['available_irr'], 'Changing the panel origin cannot reuse the previous wallet cache');
    $GLOBALS['ava_test_responses'][] = mock_response(200, $wallet_body);
    same(1230000, Api::wallet(true)['available_irr'], 'Explicit refresh replaces the cached snapshot');
    $GLOBALS['ava_test_responses'][] = new WP_Error('transport', 'private detail');
    error_code('ava_wallet_unavailable', Api::wallet(true), 'Refresh failure does not show a previous successful balance as current');
    $requests_before = count($GLOBALS['ava_test_requests']);
    error_code('ava_wallet_unavailable', Api::wallet(), 'Failures are briefly cached without pretending the balance is zero');
    same($requests_before, count($GLOBALS['ava_test_requests']), 'Failure cache prevents bursts of failing admin reads');
    foreach (array_keys($GLOBALS['ava_test_transient_expirations']) as $name) { $GLOBALS['ava_test_transient_expirations'][$name] = time() - 1; }
    $GLOBALS['ava_test_responses'][] = mock_response(200, $wallet_body);
    same(1230000, Api::wallet()['available_irr'], 'Expired wallet snapshot is replaced by a new GET');
    foreach (array(401 => 'ava_wallet_auth', 403 => 'ava_wallet_scope', 429 => 'ava_wallet_rate', 302 => 'ava_wallet_unavailable', 404 => 'ava_wallet_unavailable', 503 => 'ava_wallet_unavailable', 202 => 'ava_wallet_unavailable') as $status => $expected) {
        reset_api();
        $GLOBALS['ava_test_responses'][] = mock_response($status, array('error' => 'secret upstream body'));
        $error = Api::wallet();
        error_code($expected, $error, 'Wallet HTTP error has a local safe message');
        check(strpos($error->get_error_message(), 'secret') === false, 'Wallet HTTP response cannot expose upstream text');
        same(1, count($GLOBALS['ava_test_requests']), 'Wallet HTTP failure has no automatic retries');
    }
    foreach (array('not JSON', array(), array_merge($wallet_body, array('balance_irr' => '1234567')), array_merge($wallet_body, array('held_irr' => -1)), array_merge($wallet_body, array('available_irr' => 3)), array_merge($wallet_body, array('currency' => 'IRT')), '{"balance_irr":1234567.0,"held_irr":4567,"available_irr":1230000,"currency":"IRR"}', array_merge($wallet_body, array('available_irr' => false)), array_merge($wallet_body, array('held_irr' => null))) as $malformed) {
        reset_api();
        $GLOBALS['ava_test_responses'][] = mock_response(200, $malformed);
        error_code('ava_wallet_unavailable', Api::wallet(), 'Malformed financial values are never coerced into a balance');
    }
    reset_api();
    $GLOBALS['ava_test_responses'][] = mock_response(200, array('balance_irr' => -5, 'held_irr' => 10, 'available_irr' => -15, 'currency' => 'IRR'));
    same(-15, Api::wallet()['available_irr'], 'A legitimate signed available balance remains visible');
    reset_api(array('api_key' => ''));
    error_code('ava_wallet_configuration', Api::wallet(), 'Missing wallet credentials produce a configuration message');
    same(0, count($GLOBALS['ava_test_requests']), 'Missing wallet configuration does not contact the network');

    reset_api(array('sender' => '30001111'));
    $snapshot = Settings::get();
    $GLOBALS['ava_test_options']['ava_woo_settings'] = array('api_url' => 'https://replacement.example.com', 'api_key' => 'ava_live_' . str_repeat('c', 32), 'sender' => '30002222');
    $GLOBALS['ava_test_responses'][] = mock_response(202, array('id' => 'snapshot_job', 'status' => 'pending'));
    Api::send('09120000000', 'تصویر تنظیمات', 'ava_snapshot_12345678', $snapshot);
    same('https://ava.example.com/api/v1/sms', $GLOBALS['ava_test_requests'][0]['url'], 'Validated account snapshot supplies the exact HTTP origin');
    same('Bearer ava_live_' . str_repeat('a', 32), $GLOBALS['ava_test_requests'][0]['args']['headers']['Authorization'], 'Concurrent option changes cannot replace the validated account key');
    same('30001111', json_decode($GLOBALS['ava_test_requests'][0]['args']['body'], true)['sender'], 'Sender and credentials use the same validated snapshot');

    // Exercise the actual queue SQL against a disposable in-memory database.
    if (!extension_loaded('pdo_sqlite')) { throw new RuntimeException('pdo_sqlite is required for queue tests'); }
    $GLOBALS['wpdb'] = new AvaTestWpdb();
    $wpdb = $GLOBALS['wpdb'];
    reset_api(array('enabled' => 'yes'));
    $first = Queue::enqueue('09120000000', 'پیام نمونه', 'event-one');
    check(is_int($first) && $first > 0, 'Valid SMS is stored in the local queue');
    same($first, Queue::enqueue('09120000000', 'پیام نمونه', 'event-one'), 'Unique event key returns the original job');
    same(1, (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_ava_woo_messages'), 'Duplicate enqueue cannot create another message');
    same(1, count($GLOBALS['ava_test_schedules']), 'Duplicate enqueue does not schedule a second send');
    $GLOBALS['ava_test_responses'][] = mock_response(202, array('id' => 'ava_job_one', 'status' => 'pending'));
    Queue::process($first);
    same('queued', $wpdb->get_var('SELECT status FROM wp_ava_woo_messages WHERE id=' . $first), 'Provider pending means queued rather than delivered');
    Queue::process($first);
    same(1, count($GLOBALS['ava_test_requests']), 'Claimed job cannot be sent by a second worker');
    same('ava_job_one', $wpdb->get_var('SELECT provider_id FROM wp_ava_woo_messages WHERE id=' . $first), 'Provider ID is persisted for read-only reports');

    // An API message can await review for hours. Reporting must survive that delay
    // and recover from lost scheduler actions without posting the message again.
    reset_api(array('enabled' => 'yes'));
    $waiting = Queue::enqueue('09120000000', 'در انتظار تأیید دیرهنگام', 'delayed-approval');
    $waiting_body = array('id' => 'delayed_approval_job', 'status' => 'pending', 'review_status' => 'pending', 'campaign_status' => 'draft', 'requires_review' => true, 'dispatch_state' => 'awaiting_review', 'dispatch_detail' => '', 'detail' => '');
    $GLOBALS['ava_test_responses'][] = mock_response(202, $waiting_body);
    Queue::process($waiting);
    same('awaiting_review', $wpdb->get_var('SELECT status FROM wp_ava_woo_messages WHERE id=' . $waiting), 'Pending review is not shown as an active send queue');
    same('pending', $wpdb->get_var('SELECT review_status FROM wp_ava_woo_messages WHERE id=' . $waiting), 'Review metadata is retained locally');
    same('draft', $wpdb->get_var('SELECT campaign_status FROM wp_ava_woo_messages WHERE id=' . $waiting), 'Campaign state is retained locally');
    same(1, (int) $wpdb->get_var('SELECT requires_review FROM wp_ava_woo_messages WHERE id=' . $waiting), 'Review requirement is retained locally');
    for ($attempt = 0; $attempt < 8; ++$attempt) {
        $GLOBALS['ava_test_responses'][] = mock_response(200, $waiting_body);
        Queue::poll($waiting, $attempt);
    }
    same(8, (int) $wpdb->get_var('SELECT report_attempts FROM wp_ava_woo_messages WHERE id=' . $waiting), 'Reporting continues beyond six attempts');
    $due = (int) $wpdb->get_var('SELECT report_due FROM wp_ava_woo_messages WHERE id=' . $waiting);
    check($due >= time() + HOUR_IN_SECONDS - 2 && $due <= time() + HOUR_IN_SECONDS, 'Long waits use an hourly maximum polling rate');
    $before_poll = count($GLOBALS['ava_test_requests']);
    Queue::poll($waiting, 2);
    same($before_poll, count($GLOBALS['ava_test_requests']), 'Obsolete scheduled generations cannot restart reporting');
    $GLOBALS['ava_test_schedules'] = array();
    $wpdb->query('UPDATE wp_ava_woo_messages SET report_due=0 WHERE id=' . $waiting);
    Queue::housekeeping();
    $restored = array_values(array_filter($GLOBALS['ava_test_schedules'], function($event) use ($waiting) { return $event['hook'] === 'ava_woo_report' && $event['args'] === array($waiting, 8); }));
    same(1, count($restored), 'Housekeeping restores a lost read-only report action');
    Queue::housekeeping();
    same(1, count(array_filter($GLOBALS['ava_test_schedules'], function($event) use ($waiting) { return $event['hook'] === 'ava_woo_report' && $event['args'] === array($waiting, 8); })), 'Housekeeping cannot duplicate the restored report action');
    $GLOBALS['ava_test_responses'][] = new WP_Error('temporary_read_error', 'private network data');
    Queue::poll($waiting, 8);
    same('awaiting_review', $wpdb->get_var('SELECT status FROM wp_ava_woo_messages WHERE id=' . $waiting), 'Transient GET failure preserves the last confirmed state');
    same(9, (int) $wpdb->get_var('SELECT report_attempts FROM wp_ava_woo_messages WHERE id=' . $waiting), 'Transient GET failure schedules the next bounded report');
    $blocked_body = array_merge($waiting_body, array('review_status' => 'approved', 'requires_review' => false, 'dispatch_state' => 'credit_blocked', 'dispatch_detail' => 'dispatch_wait_insufficient_credit', 'detail' => 'dispatch_wait_insufficient_credit'));
    $GLOBALS['ava_test_responses'][] = mock_response(200, $blocked_body);
    Queue::poll($waiting, 9);
    same('credit_blocked', $wpdb->get_var('SELECT status FROM wp_ava_woo_messages WHERE id=' . $waiting), 'Approved but unfunded messages show the actual dispatch block');
    same('dispatch_wait_insufficient_credit', $wpdb->get_var('SELECT dispatch_detail FROM wp_ava_woo_messages WHERE id=' . $waiting), 'Machine-readable dispatch reason is retained');
    same(0, (int) $wpdb->get_var('SELECT requires_review FROM wp_ava_woo_messages WHERE id=' . $waiting), 'Approval clears the stored review requirement');
    $GLOBALS['ava_test_responses'][] = mock_response(200, array_merge($blocked_body, array('status' => 'delivered', 'campaign_status' => 'completed', 'dispatch_state' => 'delivered', 'dispatch_detail' => 'provider_report_not_human_receipt')));
    Queue::poll($waiting, 10);
    same('delivered', $wpdb->get_var('SELECT status FROM wp_ava_woo_messages WHERE id=' . $waiting), 'Delivery after delayed approval reaches a terminal state');
    same(0, (int) $wpdb->get_var('SELECT report_due FROM wp_ava_woo_messages WHERE id=' . $waiting), 'Terminal report clears its durable polling deadline');
    $request_total = count($GLOBALS['ava_test_requests']);
    Queue::poll($waiting, 10);
    same($request_total, count($GLOBALS['ava_test_requests']), 'Terminal messages stop automatic reports');
    same(1, count(array_filter($GLOBALS['ava_test_requests'], function($event) { return $event['args']['method'] === 'POST'; })), 'Delayed approval and recovery never POST the SMS again');

    foreach (array('awaiting_dispatch', 'awaiting_start', 'waiting_capacity', 'awaiting_configuration', 'paused', 'scheduled', 'queued', 'blocked', 'review_rejected') as $dispatch) {
        reset_api(array('enabled' => 'yes'));
        $state_id = Queue::enqueue('09120000000', 'وضعیت ارسال', 'dispatch-' . $dispatch);
        $GLOBALS['ava_test_responses'][] = mock_response(202, array('id' => 'state_' . $dispatch, 'status' => 'pending', 'review_status' => 'approved', 'requires_review' => false, 'dispatch_state' => $dispatch));
        Queue::process($state_id);
        same($dispatch === 'blocked' ? 'dispatch_blocked' : ($dispatch === 'review_rejected' ? 'rejected' : $dispatch), $wpdb->get_var('SELECT status FROM wp_ava_woo_messages WHERE id=' . $state_id), 'Dispatch projection preserves the actual wait or rejection reason');
        if ($dispatch === 'blocked') {
            $GLOBALS['ava_test_responses'][] = mock_response(200, array('id' => 'state_blocked', 'status' => 'pending', 'dispatch_state' => 'queued'));
            Queue::poll($state_id, 0);
            same('queued', $wpdb->get_var('SELECT status FROM wp_ava_woo_messages WHERE id=' . $state_id), 'A pre-dispatch block remains reportable and can clear');
        }
    }

    reset_api(array('enabled' => 'yes'));

    $GLOBALS['ava_test_responses'][] = mock_response(200, array('id' => 'ava_job_one', 'status' => 'delivered'));
    $report = Queue::refresh($first);
    same('delivered', $report['status'], 'Read-only report updates delivery status');
    same('GET', $GLOBALS['ava_test_requests'][0]['args']['method'], 'Refreshing a queue job cannot send another SMS');
    $GLOBALS['ava_test_responses'][] = mock_response(200, array('id' => 'different_job', 'status' => 'rejected'));
    error_code('ava_report_mismatch', Queue::refresh($first), 'Report must refer to the original provider ID');
    same('delivered', $wpdb->get_var('SELECT status FROM wp_ava_woo_messages WHERE id=' . $first), 'Mismatched report cannot overwrite a message');

    reset_api(array('enabled' => 'yes'));
    $unknown = Queue::enqueue('09120000000', 'پیام نامشخص', 'event-unknown');
    $GLOBALS['ava_test_responses'][] = new WP_Error('http_request_failed', 'simulated interruption');
    Queue::process($unknown);
    same('unknown', $wpdb->get_var('SELECT status FROM wp_ava_woo_messages WHERE id=' . $unknown), 'Uncertain sends are stored as unknown');
    Queue::process($unknown);
    same(1, count($GLOBALS['ava_test_requests']), 'Unknown job is never sent again automatically');
    error_code('ava_no_report', Queue::refresh($unknown), 'A missing provider ID does not trigger a new send');

    reset_api(array('enabled' => 'yes'));
    $changed = Queue::enqueue('09120000000', 'پیام قدیمی', 'event-changed');
    $GLOBALS['ava_test_options']['ava_woo_settings']['api_key'] = 'ava_live_' . str_repeat('c', 32);
    Queue::process($changed);
    same('cancelled', $wpdb->get_var('SELECT status FROM wp_ava_woo_messages WHERE id=' . $changed), 'Credential changes cancel old queued messages');
    same(0, count($GLOBALS['ava_test_requests']), 'Old queue cannot send through a replacement service account');
    error_code('ava_account_changed', Queue::refresh($first), 'Report credentials must match the original service account');

    reset_api(array('enabled' => 'yes'));
    $disabled = Queue::enqueue('09120000000', 'پیام غیرفعال', 'event-disabled');
    $GLOBALS['ava_test_options']['ava_woo_settings']['enabled'] = 'no';
    Queue::process($disabled);
    same('cancelled', $wpdb->get_var('SELECT status FROM wp_ava_woo_messages WHERE id=' . $disabled), 'Disabling automatic messages cancels unsent automatic jobs');
    same(0, count($GLOBALS['ava_test_requests']), 'Master switch is checked again before sending');
    error_code('ava_disabled', Queue::enqueue('09120000000', 'خودکار', 'event-auto-off'), 'Disabled auto sending cannot enqueue automatic messages');
    check(is_int(Queue::enqueue('09120000000', 'آزمایش صریح', 'event-explicit-test', 0, 'test')), 'Explicit paid test remains available while auto sending is off');

    reset_api(array('enabled' => 'yes', 'admin_new_order' => 'no'));
    $order = new WC_Order();
    $GLOBALS['ava_test_orders'][42] = $order;
    $before = (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_ava_woo_messages');
    Notifications::checkout($order);
    Notifications::checkout(42);
    Notifications::status_changed(42, 'pending', 'processing', $order);
    same($before + 1, (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_ava_woo_messages'), 'Classic/Store API checkout and status hooks deduplicate the same order status');
    $wpdb->query('DELETE FROM wp_ava_woo_messages WHERE order_id=42');
    Notifications::status_changed(42, 'on-hold', 'processing', $order);
    same(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_ava_woo_messages WHERE order_id=42'), 'Order tombstone prevents resending after log retention');
    error_code('ava_order', Notifications::manual(999999, 'متن'), 'Manual send rejects nonexistent orders');
    $one = Notifications::manual(42, '{order_number} نمونه');
    $two = Notifications::manual(42, '{order_number} نمونه');
    check(is_int($one) && is_int($two) && $one !== $two, 'Each explicit manual send is a new message');
    same('WEB-0042 نمونه', $wpdb->get_var('SELECT message FROM wp_ava_woo_messages WHERE id=' . $one), 'Manual messages render order placeholders');
    $wpdb->query("UPDATE wp_ava_woo_messages SET created_at='2000-01-01 00:00:00',status='delivered' WHERE id=" . $one);
    $wpdb->query("UPDATE wp_ava_woo_messages SET created_at='2000-01-01 00:00:00' WHERE id=" . $two);
    Queue::cleanup();
    same(null, $wpdb->get_var('SELECT id FROM wp_ava_woo_messages WHERE id=' . $one), 'Expired message payloads are removed by retention cleanup');
    same($two, (int) $wpdb->get_var('SELECT id FROM wp_ava_woo_messages WHERE id=' . $two), 'Retention cannot discard an unresolved admitted message');

    reset_api(array('enabled' => 'yes'));
    $same_request = '12345678-1234-4123-8123-123456789012';
    $one = Notifications::manual(42, 'فرم یکسان', $same_request);
    $two = Notifications::manual(42, 'فرم یکسان', $same_request);
    same($one, $two, 'Browser replay of one manual form keeps the original request');
    error_code('ava_request', Notifications::manual(42, 'متن', 'not-a-uuid'), 'Manual request ID has a validated shape');

    // Admin handlers must reject capability/nonce failures before queue mutation.
    foreach (array('test_send', 'manual_send', 'refresh', 'wallet_refresh') as $handler) {
        $GLOBALS['ava_test_can_manage'] = false;
        $_POST = array('job_id' => 1);
        try { Admin::$handler(); throw new RuntimeException('Unauthorized handler returned'); }
        catch (AvaTestStop $stop) { same('denied', $stop->getMessage(), 'Admin writes require manage_woocommerce'); }
        $GLOBALS['ava_test_can_manage'] = true;
        $GLOBALS['ava_test_valid_nonce'] = false;
        try { Admin::$handler(); throw new RuntimeException('Bad nonce handler returned'); }
        catch (AvaTestStop $stop) { same('bad_nonce', $stop->getMessage(), 'Admin writes require a valid nonce'); }
    }
    $GLOBALS['ava_test_valid_nonce'] = true;
    same(0, count($GLOBALS['ava_test_requests']), 'Authorization failures cannot contact the provider');
    $before = (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_ava_woo_messages');
    $_POST = array('phone' => '09120000000', 'message' => 'آزمایش', 'confirm_cost' => 'yes');
    try { Admin::test_send(); } catch (AvaTestStop $stop) { same('redirect', $stop->getMessage(), 'Invalid form returns to the admin UI'); }
    same($before, (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_ava_woo_messages'), 'A missing form request ID cannot enqueue');
    $_POST['request_id'] = '87654321-1234-4123-8123-123456789012';
    unset($_POST['confirm_cost']);
    try { Admin::test_send(); } catch (AvaTestStop $stop) { same('redirect', $stop->getMessage(), 'Missing cost confirmation returns to the UI'); }
    same($before, (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_ava_woo_messages'), 'Cost confirmation is checked on the server');
    $_POST['confirm_cost'] = 'yes';
    try { Admin::test_send(); } catch (AvaTestStop $stop) { same('redirect', $stop->getMessage(), 'Confirmed paid test queues safely'); }
    try { Admin::test_send(); } catch (AvaTestStop $stop) { same('redirect', $stop->getMessage(), 'Browser replay redirects safely'); }
    same($before + 1, (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_ava_woo_messages'), 'Replaying a paid test form cannot duplicate the send');
    $_GET = array('tab' => 'settings');
    $_POST = array();
    $GLOBALS['ava_test_transients'] = array();
    $GLOBALS['ava_test_responses'][] = mock_response(200, $wallet_body);
    ob_start();
    Admin::page();
    $html = ob_get_clean();
    check(strpos($html, 'ava_live_' . str_repeat('a', 32)) === false, 'Saved API secret is absent from rendered admin HTML');
    check(strpos($html, 'type="password"') !== false && strpos($html, 'autocomplete="new-password"') !== false, 'Secret replacement input avoids browser autofill');
    check(strpos($html, 'dir="rtl"') !== false, 'Persian settings render right-to-left');
    check(strpos($html, 'ماندهٔ قابل مصرف') !== false && strpos($html, '۱٬۲۳۰٬۰۰۰') !== false, 'Admin wallet prominently displays the real available rials');
    check(strpos($html, 'موجودی کل') !== false && strpos($html, 'اعتبار رزروشده') !== false && strpos($html, 'ریال') !== false, 'Admin wallet distinguishes the total from reserved credit and labels the currency');
    check(strpos($html, 'https://ava.example.com/panel#wallet') !== false, 'Charge link opens the configured panel wallet');
    check(strpos($html, 'ava_woo_wallet_refresh') !== false, 'Wallet has a separate refresh form');
    $network_before = count($GLOBALS['ava_test_requests']);
    same('manage_woocommerce', Admin::capability(), 'Settings save uses the same merchant capability');
    $GLOBALS['ava_test_can_manage'] = false;
    ob_start(); Admin::page(); $html = ob_get_clean();
    same('', $html, 'Users without merchant permission cannot read the admin screen');
    same($network_before, count($GLOBALS['ava_test_requests']), 'Unauthorized page access cannot retrieve wallet balances');
    $GLOBALS['ava_test_can_manage'] = true;
    $GLOBALS['ava_test_responses'][] = mock_response(200, $changed_wallet);
    $queue_before = (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_ava_woo_messages');
    try { Admin::wallet_refresh(); } catch (AvaTestStop $stop) { same('redirect', $stop->getMessage(), 'Authorized wallet refresh returns to settings'); }
    same($queue_before, (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_ava_woo_messages'), 'Wallet refresh cannot enqueue a message');
    same(900, Api::wallet()['available_irr'], 'Authorized refresh replaces the displayed wallet cache');
    same('ava_woo_wallet_refresh', end($GLOBALS['ava_test_nonce_checks']), 'Wallet refresh validates its own nonce');
    $GLOBALS['ava_test_responses'][] = mock_response(500, array('error' => 'secret upstream body'));
    Api::wallet(true);
    ob_start(); Admin::page(); $wallet_error_html = ob_get_clean();
    check(strpos($wallet_error_html, 'موجودی حساب آوا دریافت نشد') !== false && strpos($wallet_error_html, 'ava-woo-wallet-available') === false, 'Wallet failure renders an explicit error without a made-up zero');
    check(strpos($wallet_error_html, 'secret upstream body') === false, 'Wallet admin errors never render the upstream response');

    // WooCommerce's scheduler refuses a duplicate of an action that is running.
    // A new persisted generation must still be admitted from inside the callback.
    function as_schedule_single_action($timestamp, $hook, $args, $group, $unique) {
        foreach ($GLOBALS['ava_test_as_actions'] as $action) {
            if ($unique && $action['hook'] === $hook && $action['args'] === $args && $action['group'] === $group && in_array($action['state'], array('pending', 'running'), true)) {
                return 0;
            }
        }
        $GLOBALS['ava_test_as_actions'][] = array('timestamp' => $timestamp, 'hook' => $hook, 'args' => $args, 'group' => $group, 'state' => 'pending');
        return count($GLOBALS['ava_test_as_actions']);
    }
    $GLOBALS['wpdb'] = new AvaTestWpdb();
    $wpdb = $GLOBALS['wpdb'];
    $GLOBALS['ava_test_as_actions'] = array();
    reset_api(array('enabled' => 'yes'));
    $as_job = Queue::enqueue('09120000000', 'پیگیری ووکامرس', 'as-report-generation');
    $GLOBALS['ava_test_responses'][] = mock_response(202, array('id' => 'as_job', 'status' => 'pending', 'requires_review' => true));
    Queue::process($as_job);
    foreach ($GLOBALS['ava_test_as_actions'] as &$action) {
        if ($action['hook'] === 'ava_woo_report') { $action['state'] = 'running'; }
    }
    unset($action);
    $GLOBALS['ava_test_responses'][] = mock_response(200, array('id' => 'as_job', 'status' => 'pending', 'requires_review' => true));
    Queue::poll($as_job, 0);
    same(1, count(array_filter($GLOBALS['ava_test_as_actions'], function($action) use ($as_job) { return $action['hook'] === 'ava_woo_report' && $action['args'] === array($as_job, 1) && $action['state'] === 'pending'; })), 'Action Scheduler admits the next report while the previous generation is running');

    $GLOBALS['wpdb'] = new AvaTestWpdb();
    $wpdb = $GLOBALS['wpdb'];
    $GLOBALS['ava_test_as_actions'] = array();
    reset_api(array('enabled' => 'yes'));
    for ($record = 0; $record < 101; ++$record) {
        $repair_id = Queue::enqueue('09120000000', 'در انتظار بررسی', 'repair-batch-' . $record);
        $wpdb->query("UPDATE wp_ava_woo_messages SET status='awaiting_review',provider_id='existing_" . $record . "' WHERE id=" . $repair_id);
    }
    Queue::housekeeping();
    same(100, count(array_filter($GLOBALS['ava_test_as_actions'], function($action) { return $action['hook'] === 'ava_woo_report'; })), 'Housekeeping recovery schedules at most 100 reports per batch');
    Queue::housekeeping();
    same(101, count(array_filter($GLOBALS['ava_test_as_actions'], function($action) { return $action['hook'] === 'ava_woo_report'; })), 'An additional recovery batch reaches remaining migrated records without duplicate actions');
    same(0, count($GLOBALS['ava_test_requests']), 'Recovery only schedules reports and never invokes the SMS send endpoint');

    // Constants cannot be undefined, so the external-secret override is tested last.
    reset_api();
    define('AVA_WOO_API_KEY', 'ava_live_' . str_repeat('b', 32));
    same(AVA_WOO_API_KEY, Settings::get()['api_key'], 'wp-config constant overrides the stored key');
    $saved = Settings::sanitize(array('api_url' => 'https://ava.example.com', 'api_key' => 'ava_live_' . str_repeat('c', 32), 'delete_api_key' => '1'));
    same('ava_live_' . str_repeat('a', 32), $saved['api_key'], 'Constant secret is not copied into the WordPress option');

    echo 'PASS: ' . $count . " behavioral assertions; HTTP fully mocked.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

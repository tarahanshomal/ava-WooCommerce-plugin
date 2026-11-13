<?php
/** Behavioral checks included by the dependency-free mocked test harness. */
use AvaWoo\Template;
use AvaWoo\Settings;
use AvaWoo\Queue;
use AvaWoo\Api;
reset_api( array( 'enabled' => 'yes' ) );
$context_order = new WC_Order();
$context_template = '{billing_first_name} عزیز، سفارش {order_number} به مبلغ {order_total} {currency} در {site_name} ثبت شد.';
$context_a = Template::context( $context_template, $context_order, 'customer:processing' );
check( is_array( $context_a ), 'Supported custom template produces explicit metadata' );
same( array( 'billing_first_name', 'order_number', 'order_total', 'currency', 'site_name' ), array_keys( $context_a['values'] ), 'Metadata contains only actually used variables' );
same( 'کاربر', $context_a['values']['billing_first_name'], 'Metadata uses the same sanitized order values as rendering' );
check( Template::valid_context( $context_a, Template::render( $context_template, $context_order ) ), 'Context exactly describes the rendered message' );
$context_order->fields['billing_first_name'] = 'علی';
$context_order->fields['order_number'] = 'WEB-0043';
$context_order->fields['total'] = '250000';
$context_b = Template::context( $context_template, $context_order, 'customer:processing' );
same( $context_a['revision'], $context_b['revision'], 'Different customer/order/amount keeps the same template revision' );
same( $context_a['store_id'], $context_b['store_id'], 'One site keeps its stable store identity' );
check( $context_a['values'] !== $context_b['values'], 'Every message snapshots its own order variable values' );
check( $context_b['revision'] !== Template::context( $context_template, $context_order, 'manual' )['revision'], 'Different template slots have separate approval versions' );

$saved_a = Settings::sanitize( array( 'api_url' => 'https://ava.example.com', 'templates' => array( 'processing' => $context_template ) ) );
$GLOBALS['ava_test_options']['ava_woo_settings'] = $saved_a;
$revision_a = Template::context( $context_template, $context_order, 'customer:processing' )['revision'];
$saved_b = Settings::sanitize( array( 'api_url' => 'https://ava.example.com', 'templates' => array( 'processing' => $context_template . ' سپاسگزاریم.' ) ) );
$GLOBALS['ava_test_options']['ava_woo_settings'] = $saved_b;
$saved_a = Settings::sanitize( array( 'api_url' => 'https://ava.example.com', 'templates' => array( 'processing' => $context_template ) ) );
$GLOBALS['ava_test_options']['ava_woo_settings'] = $saved_a;
$revision_after_edit = Template::context( $context_template, $context_order, 'customer:processing' )['revision'];
check( $revision_a !== $revision_after_edit, 'Saving A to B to A requires fresh approval even without an enqueue while B was saved' );
Settings::sanitize( array( 'api_url' => 'https://ava.example.com', 'templates' => array( 'processing' => $context_template ), 'sender' => '300000' ) );
same( $revision_after_edit, Template::context( $context_template, $context_order, 'customer:processing' )['revision'], 'Unrelated settings save preserves the current revision' );

foreach ( array( '{unknown}', '{billing_first_name', '{order_number}}' ) as $unsupported_template ) {
    same( null, Template::context( $unsupported_template, $context_order, 'manual' ), 'Unsupported/malformed placeholders keep the legacy review path' );
}
foreach ( array( 'دو  فاصله', "نام\nدیگر", 'نام😀', 'name.example', str_repeat( 'الف', 25 ) ) as $bad_name ) {
    $context_order->fields['billing_first_name'] = $bad_name;
    same( null, Template::context( $context_template, $context_order, 'customer:processing' ), 'Unsupported name remains deliverable through the ordinary reviewed path' );
}
$context_order->fields['billing_first_name'] = '';
check( is_array( Template::context( $context_template, $context_order, 'customer:processing' ) ), 'An empty first name is supported' );
$context_order->fields['shipping_method'] = '';
check( is_array( Template::context( 'روش ارسال سفارش {order_number}: {shipping_method}', $context_order, 'manual' ) ), 'Empty shipping labels remain eligible for versioned template review' );
$context_order->fields['payment_method_title'] = '';
check( is_array( Template::context( 'پرداخت سفارش {order_number}: {payment_method}', $context_order, 'manual' ) ), 'Empty payment labels remain eligible for versioned template review' );
$context_order->fields['shipping_method'] = 'پست';
$context_order->fields['billing_first_name'] = '&amp;lt;';
same( null, Template::context( $context_template, $context_order, 'customer:processing' ), 'Nested entity transformations cannot describe a different rendered message' );
$context_order->fields['billing_first_name'] = '&amp;amp;lt;';
same( '&lt;', Template::render( '{billing_first_name}', $context_order ), 'Rendering preserves the original value-once and final-once entity decoding' );
$context_order->fields['payment_method_title'] = 'روش Ⅳ';
same( null, Template::context( 'پرداخت سفارش {order_number}: {payment_method}', $context_order, 'manual' ), 'Nondecimal numeric label characters fall back before the server can reject metadata' );
$context_order->fields['payment_method_title'] = 'پرداخت';
$context_order->fields['billing_phone'] = "0912\u{200C}0000000";
same( null, Template::context( 'شماره مشتری سفارش {order_number}: {billing_phone}', $context_order, 'manual' ), 'Normalized-away phone controls do not produce server-invalid metadata' );
$context_order->fields['billing_phone'] = '09120000000';

$GLOBALS['wpdb'] = new AvaTestWpdb();
$wpdb = $GLOBALS['wpdb'];
reset_api( array( 'enabled' => 'yes' ) );
$context_order->fields['billing_first_name'] = 'علی';
$snapshot_context = Template::context( $context_template, $context_order, 'customer:processing' );
$snapshot_text = Template::render( $context_template, $context_order );
$snapshot_job = Queue::enqueue( '09120000000', $snapshot_text, 'template-snapshot', 42, 'customer', $snapshot_context );
$stored_context = json_decode( $wpdb->get_row( 'SELECT * FROM wp_ava_woo_messages WHERE id=' . $snapshot_job )->template_context, true );
same( $snapshot_context, $stored_context, 'Queue stores the admitted template facts with the message' );
$context_order->fields['billing_first_name'] = 'نام جدید';
Template::context( 'قالب تازه برای سفارش {order_number}', $context_order, 'customer:processing' );
$GLOBALS['ava_test_responses'][] = mock_response( 202, array( 'id' => 'template_snapshot', 'status' => 'pending', 'requires_review' => true ) );
Queue::process( $snapshot_job );
$snapshot_payload = json_decode( $GLOBALS['ava_test_requests'][0]['args']['body'], true );
same( $snapshot_context, $snapshot_payload['woocommerce_template'], 'Worker sends the queued version and values after current order/template edits' );
same( $snapshot_text, $snapshot_payload['text'], 'Worker never reconstructs a queued message from the changed order' );
same( 1, count( $GLOBALS['ava_test_requests'] ), 'Structured metadata creates no extra HTTP request' );

reset_api();
$literal_context = Template::context( 'پیام آزمایشی ثابت و سفارشی', null, 'test' );
$GLOBALS['ava_test_responses'][] = mock_response( 202, array( 'id' => 'literal_template', 'status' => 'pending' ) );
Api::send( '09120000000', 'پیام آزمایشی ثابت و سفارشی', 'literal_template_123456789', null, $literal_context );
$literal_payload = json_decode( $GLOBALS['ava_test_requests'][0]['args']['body'] );
check( is_object( $literal_payload->woocommerce_template->values ), 'Literal-only templates serialize empty variables as a JSON object' );
$forged = $literal_context;
$forged['template'] = 'متن متفاوت';
$GLOBALS['ava_test_responses'][] = mock_response( 202, array( 'id' => 'legacy_metadata_fallback', 'status' => 'pending' ) );
Api::send( '09120000000', 'پیام آزمایشی ثابت و سفارشی', 'literal_template_223456789', null, $forged );
check( ! isset( json_decode( $GLOBALS['ava_test_requests'][1]['args']['body'], true )['woocommerce_template'] ), 'Metadata inconsistent with the actual message falls back to ordinary review' );

$old_store = Template::store_id();
$old_context = $literal_context;
$GLOBALS['ava_test_home'] = 'https://another-store.example.com';
$new_store = Template::store_id();
check( $old_store !== $new_store, 'A cloned store UUID rotates after its canonical site origin changes' );
same( $new_store, Template::store_id(), 'A stable cloned site origin does not repeatedly rotate identity' );
same( $old_store, $old_context['store_id'], 'Origin changes cannot mutate existing queued context snapshots' );
$GLOBALS['ava_test_home'] = 'https://store.example.com';

// A competing request wins after this request read the old slot definition.
$race_order = new WC_Order();
$old_race = Template::context( 'قالب قبلی سفارش {order_number}', $race_order, 'customer:race' );
$other_slot = Template::context( 'قالب مستقل مدیر سفارش {order_number}', $race_order, 'admin' );
$winner = '11111111-1111-4111-a111-111111111111';
$GLOBALS['ava_test_cas_competitor'] = function( $name, $next, $old ) use ( $winner ) {
    $GLOBALS['ava_test_options'][ $name ] = array( 'fingerprint' => $next['fingerprint'], 'revision' => $winner );
};
$race = Template::context( 'قالب جدید سفارش {order_number}', $race_order, 'customer:race' );
same( $winner, $race['revision'], 'A stale option writer reloads and reuses the concurrent winner revision' );
same( $winner, Template::context( 'قالب جدید سفارش {order_number}', $race_order, 'customer:race' )['revision'], 'Unchanged concurrent template keeps its single winning revision' );
same( $other_slot['revision'], Template::context( 'قالب مستقل مدیر سفارش {order_number}', $race_order, 'admin' )['revision'], 'Updating one slot cannot lose or replace another slot revision' );
check( ! empty( $GLOBALS['ava_test_cache_deletes'] ), 'CAS updates and losers invalidate WordPress option caches before reloading' );
$GLOBALS['ava_test_cas_error'] = true;
same( null, Template::context( 'قالب دیگر سفارش {order_number}', $race_order, 'customer:race' ), 'An options write error falls back to ordinary review rather than inventing a revision' );
$GLOBALS['ava_test_cas_error'] = false;

// An older unpublished revision-array option can seed one slot without loss.
$legacy_template = 'قالب مهاجرت سفارش {order_number}';
$legacy_store = Template::store_id();
$legacy_revision = '22222222-2222-4222-a222-222222222222';
$GLOBALS['ava_test_options']['ava_woo_template_revisions']['customer:legacy'] = array(
    'fingerprint' => hash( 'sha256', wp_json_encode( array( 'store_id' => $legacy_store, 'template' => $legacy_template ), JSON_UNESCAPED_UNICODE ) ),
    'revision' => $legacy_revision,
);
same( $legacy_revision, Template::context( $legacy_template, $race_order, 'customer:legacy' )['revision'], 'A matching legacy revision migrates to its atomic per-slot option unchanged' );

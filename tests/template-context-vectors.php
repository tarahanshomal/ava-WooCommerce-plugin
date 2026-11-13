<?php
/** Pure synthetic protocol vectors; no HTTP path is invoked. */
define( 'AVA_WOO_TEST_BOOTSTRAP_ONLY', true );
require __DIR__ . '/run.php';

$vectors = array();
$order = new WC_Order();
$order->fields['billing_first_name'] = "O'Neil";
$order->fields['order_number'] = 'WEB-۰۰۴۲';
$order->fields['total'] = '125000.50';
$definitions = array(
    array( 'customer:processing', '{billing_first_name} عزیز، سفارش {order_number} به مبلغ {order_total} {currency} در {site_name} ثبت شد.' ),
    array( 'admin', "سفارش {order_number} در {site_name}\nمشتری: {billing_first_name} {billing_last_name}\nمبلغ: {order_total} {currency}\nپرداخت: {payment_method}\nارسال: {shipping_method}\nوضعیت: {order_status}" ),
    array( 'manual', 'اطلاع‌رسانی سفارش {order_id} برای {billing_first_name}: {order_total} {currency}' ),
    array( 'test', 'متن آزمایشی سفارشی و ثابت' ),
);
foreach ( $definitions as $definition ) {
    list( $slot, $template ) = $definition;
    $source_order = 'test' === $slot ? null : $order;
    $context = \AvaWoo\Template::context( $template, $source_order, $slot );
    $vectors[] = array( 'label' => $slot, 'text' => \AvaWoo\Template::render( $template, $source_order ), 'metadata' => $context ? \AvaWoo\Template::wire_context( $context ) : null );
}
$order->fields['billing_first_name'] = '';
$template = '{billing_first_name} عزیز، سفارش {order_number} در {site_name} ثبت شد.';
$context = \AvaWoo\Template::context( $template, $order, 'customer:completed' );
$vectors[] = array( 'label' => 'empty-name', 'text' => \AvaWoo\Template::render( $template, $order ), 'metadata' => \AvaWoo\Template::wire_context( $context ) );
$order->fields['payment_method_title'] = '';
$order->fields['shipping_method'] = '';
$template = 'روش پرداخت سفارش {order_number}: {payment_method} و روش ارسال: {shipping_method}';
$context = \AvaWoo\Template::context( $template, $order, 'manual' );
$vectors[] = array( 'label' => 'empty-methods', 'text' => \AvaWoo\Template::render( $template, $order ), 'metadata' => \AvaWoo\Template::wire_context( $context ) );
echo json_encode( $vectors, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n";

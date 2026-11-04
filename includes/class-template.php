<?php
namespace AvaWoo;

defined( 'ABSPATH' ) || exit;

final class Template {
    public static function placeholders() {
        return array( 'order_id', 'order_number', 'order_status', 'order_total', 'currency', 'billing_first_name', 'billing_last_name', 'billing_phone', 'payment_method', 'shipping_method', 'site_name' );
    }

    public static function plain( $value ) {
        return trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    }

    public static function render( $template, $order ) {
        $values = array(
            'order_id'           => $order->get_id(),
            'order_number'       => $order->get_order_number(),
            'order_status'       => wc_get_order_status_name( $order->get_status() ),
            'order_total'        => $order->get_total(),
            'currency'           => $order->get_currency(),
            'billing_first_name' => $order->get_billing_first_name(),
            'billing_last_name'  => $order->get_billing_last_name(),
            'billing_phone'      => $order->get_billing_phone(),
            'payment_method'     => $order->get_payment_method_title(),
            'shipping_method'    => $order->get_shipping_method(),
            'site_name'          => get_bloginfo( 'name' ),
        );
        $replace = array();
        foreach ( $values as $key => $value ) {
            $replace[ '{' . $key . '}' ] = self::plain( $value );
        }
        return self::plain( strtr( $template, $replace ) );
    }

    public static function validate( $text ) {
        if ( ! is_string( $text ) ) {
            return new \WP_Error( 'ava_text', __( 'متن پیامک معتبر نیست.', 'ava-woocommerce-plugin' ) );
        }
        $text = trim( $text );
        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : preg_match_all( '/./us', $text );
        if ( $length < 1 || $length > 1000 ) {
            return new \WP_Error( 'ava_text', __( 'طول متن پیامک باید بین ۱ و ۱۰۰۰ نویسه باشد.', 'ava-woocommerce-plugin' ) );
        }
        return $text;
    }
}

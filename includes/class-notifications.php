<?php
namespace AvaWoo;

defined( 'ABSPATH' ) || exit;

final class Notifications {
    public static function init() {
        add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'checkout' ), 20, 1 );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'checkout' ), 20, 1 );
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'status_changed' ), 20, 4 );
    }

    public static function checkout( $order ) {
        $order = is_a( $order, 'WC_Order' ) ? $order : wc_get_order( $order );
        if ( ! $order || 'checkout-draft' === $order->get_status() ) {
            return;
        }
        $settings = Settings::get();
        if ( 'yes' !== $settings['enabled'] ) {
            return;
        }
        if ( 'yes' === $settings['admin_new_order'] ) {
            self::admins( $order, 'new', $settings['admin_template'], $settings );
        }
        self::customer( $order, $order->get_status(), $settings );
    }

    public static function status_changed( $id, $from, $to, $order = null ) {
        $order = is_a( $order, 'WC_Order' ) ? $order : wc_get_order( $id );
        if ( ! $order || 'checkout-draft' === $to ) {
            return;
        }
        $settings = Settings::get();
        if ( 'yes' !== $settings['enabled'] ) {
            return;
        }
        self::customer( $order, $to, $settings );
        if ( in_array( $to, $settings['admin_statuses'], true ) ) {
            self::admins( $order, 'status:' . $to, $settings['admin_template'], $settings );
        }
    }

    private static function customer( $order, $status, $settings ) {
        if ( ! in_array( $status, $settings['customer_statuses'], true ) || empty( $settings['templates'][ $status ] ) ) {
            return;
        }
        $template = $settings['templates'][ $status ];
        self::once( $order, $order->get_billing_phone(), Template::render( $template, $order ), 'status:' . $status, 'customer', Template::context( $template, $order, 'customer:' . $status ) );
    }

    private static function admins( $order, $event, $template, $settings ) {
        $phones = preg_split( '/[\s,;،]+/u', $settings['admin_phones'], -1, PREG_SPLIT_NO_EMPTY );
        foreach ( array_slice( array_unique( $phones ), 0, 10 ) as $phone ) {
            self::once( $order, $phone, Template::render( $template, $order ), $event, 'admin', Template::context( $template, $order, 'admin' ) );
        }
    }

    private static function once( $order, $phone, $message, $event, $audience, $template_context = null ) {
        $phone = Phone::normalize( $phone );
        if ( is_wp_error( $phone ) ) {
            return;
        }
        $event_key = 'order:' . $order->get_id() . ':' . $event . ':' . $audience . ':' . $phone;
        $marker = '_ava_woo_' . hash( 'sha256', $event_key );
        // Keep a tombstone on the order after log retention has removed the payload.
        if ( $order->get_meta( $marker, true ) ) {
            return;
        }
        $id = Queue::enqueue( $phone, $message, $event_key, $order->get_id(), $audience, $template_context );
        if ( ! is_wp_error( $id ) ) {
            $order->update_meta_data( $marker, (string) $id );
            $order->save_meta_data();
        }
    }

    public static function manual( $order_id, $message, $request_id = '' ) {
        $order = wc_get_order( absint( $order_id ) );
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
            return new \WP_Error( 'ava_order', __( 'سفارش پیدا نشد.', 'ava-sms-for-woocommerce' ) );
        }
        if ( '' !== $request_id && ( ! is_string( $request_id ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $request_id ) ) ) {
            return new \WP_Error( 'ava_request', __( 'شناسهٔ درخواست ارسال معتبر نیست.', 'ava-sms-for-woocommerce' ) );
        }
        $context = Template::context( $message, $order, 'manual' );
        $message = Template::render( $message, $order );
        return Queue::enqueue( $order->get_billing_phone(), $message, 'manual:' . $order->get_id() . ':' . ( $request_id ?: wp_generate_uuid4() ), $order->get_id(), 'manual', $context );
    }
}

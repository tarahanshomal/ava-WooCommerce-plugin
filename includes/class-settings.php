<?php
namespace AvaWoo;

defined( 'ABSPATH' ) || exit;

final class Settings {
    public static function defaults() {
        $templates = array();
        foreach ( array( 'pending', 'on-hold', 'processing', 'completed', 'cancelled', 'failed', 'refunded' ) as $status ) {
            $templates[ $status ] = '{billing_first_name} عزیز، وضعیت سفارش {order_number} در {site_name}: {order_status}';
        }
        $templates['processing'] = '{billing_first_name} عزیز، سفارش {order_number} شما در {site_name} در حال پردازش است.';
        $templates['completed'] = '{billing_first_name} عزیز، سفارش {order_number} شما در {site_name} تکمیل شد. از خرید شما سپاسگزاریم.';
        return array(
            'enabled' => 'no', 'api_url' => 'https://ava.tarpardaz.com', 'api_key' => '', 'sender' => '',
            'admin_phones' => '', 'admin_new_order' => 'yes',
            'admin_template' => "سفارش {order_number} در {site_name}\nمشتری: {billing_first_name} {billing_last_name}\nمبلغ: {order_total} {currency}\nوضعیت: {order_status}",
            'retention_days' => 30,
            'customer_statuses' => array( 'processing', 'completed', 'on-hold', 'cancelled', 'failed', 'refunded' ),
            'admin_statuses' => array(), 'templates' => $templates,
        );
    }

    public static function get() {
        $stored = get_option( 'ava_woo_settings', array() );
        $settings = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
        $settings['templates'] = array_merge( self::defaults()['templates'], is_array( $settings['templates'] ) ? $settings['templates'] : array() );
        if ( defined( 'AVA_WOO_API_KEY' ) ) {
            $settings['api_key'] = (string) AVA_WOO_API_KEY;
        }
        return $settings;
    }

    public static function valid_url( $url ) {
        if ( ! is_string( $url ) ) {
            return false;
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) ||
             isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ||
             ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ||
             ( ! empty( $parts['path'] ) && '/' !== $parts['path'] ) ||
             ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/iD', $parts['host'] ) ) {
            return false;
        }
        return 'https://' . strtolower( $parts['host'] );
    }

    public static function sanitize( $input ) {
        $old = self::get();
        $input = is_array( $input ) ? $input : array();
        $out = $old;
        foreach ( array( 'enabled', 'admin_new_order' ) as $key ) {
            $out[ $key ] = isset( $input[ $key ] ) && in_array( $input[ $key ], array( 'yes', '1', 1 ), true ) ? 'yes' : 'no';
        }
        $url = self::valid_url( $input['api_url'] ?? '' );
        if ( $url ) {
            $out['api_url'] = $url;
        } else {
            add_settings_error( 'ava_woo_settings', 'ava_url', __( 'نشانی آوا باید فقط دامنه با HTTPS باشد؛ نشانی قبلی حفظ شد.', 'ava-woocommerce-plugin' ) );
        }
        if ( ! defined( 'AVA_WOO_API_KEY' ) ) {
            if ( ! empty( $input['delete_api_key'] ) ) {
                $out['api_key'] = '';
            } elseif ( isset( $input['api_key'] ) && is_string( $input['api_key'] ) && '' !== trim( $input['api_key'] ) ) {
                $key = trim( $input['api_key'] );
                if ( preg_match( '/^ava_live_[A-Za-z0-9_-]{16,240}$/D', $key ) ) {
                    $out['api_key'] = $key;
                } else {
                    add_settings_error( 'ava_woo_settings', 'ava_key', __( 'ساختار کلید API آوا معتبر نیست؛ کلید قبلی حفظ شد.', 'ava-woocommerce-plugin' ) );
                }
            }
        } else {
            // The constant is never persisted to the WordPress database.
            $raw = get_option( 'ava_woo_settings', array() );
            $out['api_key'] = is_array( $raw ) ? ( $raw['api_key'] ?? '' ) : '';
        }
        $sender = isset( $input['sender'] ) && is_scalar( $input['sender'] ) ? sanitize_text_field( (string) $input['sender'] ) : '';
        if ( strlen( $sender ) <= 64 ) {
            $out['sender'] = $sender;
        }
        $phones = isset( $input['admin_phones'] ) && is_string( $input['admin_phones'] ) ? preg_split( '/[\s,;،]+/u', trim( $input['admin_phones'] ), -1, PREG_SPLIT_NO_EMPTY ) : array();
        $normalized = array();
        foreach ( $phones as $phone ) {
            $valid = Phone::normalize( $phone );
            if ( is_wp_error( $valid ) ) {
                add_settings_error( 'ava_woo_settings', 'ava_admin_phone', __( 'یک شمارهٔ مدیر نامعتبر بود و ذخیره نشد.', 'ava-woocommerce-plugin' ) );
            } else {
                $normalized[] = $valid;
            }
        }
        $out['admin_phones'] = implode( ',', array_slice( array_unique( $normalized ), 0, 10 ) );
        $out['retention_days'] = max( 1, min( 90, absint( $input['retention_days'] ?? 30 ) ) );
        $statuses = array_map( static function ( $s ) { return substr( $s, 3 ); }, array_keys( wc_get_order_statuses() ) );
        foreach ( array( 'customer_statuses', 'admin_statuses' ) as $key ) {
            $out[ $key ] = array_values( array_intersect( $statuses, isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array() ) );
        }
        foreach ( $statuses as $status ) {
            if ( isset( $input['templates'][ $status ] ) && is_string( $input['templates'][ $status ] ) ) {
                $text = sanitize_textarea_field( $input['templates'][ $status ] );
                if ( ! is_wp_error( Template::validate( $text ) ) ) {
                    $out['templates'][ $status ] = $text;
                } else {
                    add_settings_error( 'ava_woo_settings', 'ava_template_' . $status, __( 'قالب باید بین ۱ و ۱۰۰۰ نویسه باشد؛ قالب قبلی حفظ شد.', 'ava-woocommerce-plugin' ) );
                }
            }
        }
        if ( isset( $input['admin_template'] ) && is_string( $input['admin_template'] ) ) {
            $text = sanitize_textarea_field( $input['admin_template'] );
            if ( ! is_wp_error( Template::validate( $text ) ) ) {
                $out['admin_template'] = $text;
            }
        }
        return $out;
    }
}

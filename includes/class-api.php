<?php
namespace AvaWoo;

defined( 'ABSPATH' ) || exit;

final class Api {
    public static function send( $phone, $text, $idempotency_key, $settings = null ) {
        $phone = Phone::normalize( $phone );
        if ( is_wp_error( $phone ) ) {
            return $phone;
        }
        $text = Template::validate( $text );
        if ( is_wp_error( $text ) ) {
            return $text;
        }
        if ( ! is_string( $idempotency_key ) || ! preg_match( '/^[A-Za-z0-9_-]{16,100}$/D', $idempotency_key ) ) {
            return new \WP_Error( 'ava_idempotency', __( 'شناسهٔ یکتای ارسال معتبر نیست.', 'ava-woocommerce-plugin' ) );
        }
        $settings = null === $settings ? Settings::get() : $settings;
        $payload = array( 'phone' => $phone, 'text' => $text );
        if ( '' !== $settings['sender'] ) {
            $payload['sender'] = $settings['sender'];
        }
        return self::request( 'POST', '/api/v1/sms', $payload, $idempotency_key, $settings );
    }

    public static function report( $id, $settings = null ) {
        if ( ! is_string( $id ) || ! preg_match( '/^[A-Za-z0-9_-]{1,100}$/D', $id ) ) {
            return new \WP_Error( 'ava_id', __( 'شناسهٔ پیامک معتبر نیست.', 'ava-woocommerce-plugin' ) );
        }
        return self::request( 'GET', '/api/v1/sms/' . rawurlencode( $id ), null, '', $settings );
    }

    private static function request( $method, $path, $payload = null, $idempotency_key = '', $settings = null ) {
        $settings = null === $settings ? Settings::get() : $settings;
        $url = Settings::valid_url( $settings['api_url'] );
        if ( ! $url || ! preg_match( '/^ava_live_[A-Za-z0-9_-]{16,240}$/D', $settings['api_key'] ) ) {
            return new \WP_Error( 'ava_configuration', __( 'نشانی یا کلید API آوا تنظیم نشده است.', 'ava-woocommerce-plugin' ) );
        }
        $args = array(
            'method' => $method, 'timeout' => 15, 'redirection' => 0, 'sslverify' => true,
            'reject_unsafe_urls' => true, 'limit_response_size' => 65536,
            'headers' => array( 'Authorization' => 'Bearer ' . $settings['api_key'], 'Accept' => 'application/json' ),
            'user-agent' => 'AvaWooCommerce/' . AVA_WOO_VERSION . '; WordPress',
        );
        if ( 'POST' === $method ) {
            $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
            $args['headers']['Idempotency-Key'] = $idempotency_key;
            $args['body'] = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
            $args['data_format'] = 'body';
        }
        $response = wp_safe_remote_request( $url . $path, $args );
        if ( is_wp_error( $response ) ) {
            $message = 'POST' === $method
                ? __( 'پاسخ سرویس دریافت نشد؛ وضعیت ارسال نامشخص است. برای جلوگیری از هزینهٔ تکراری ارسال خودکار تکرار نمی‌شود.', 'ava-woocommerce-plugin' )
                : __( 'گزارش آوا دریافت نشد؛ وضعیت قبلی پیامک حفظ شد.', 'ava-woocommerce-plugin' );
            return new \WP_Error( 'ava_transport', $message, array( 'uncertain' => 'POST' === $method ) );
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( ! in_array( $code, array( 200, 202 ), true ) ) {
            $messages = array(
                400 => 'درخواست پیامک پذیرفته نشد؛ متن و شماره را بررسی کنید.',
                401 => 'کلید API نامعتبر یا منقضی است.',
                403 => 'مجوز کلید یا دسترسی سرویس کافی نیست.',
                404 => 'پیامک یا مسیر API پیدا نشد.',
                409 => 'سرویس آماده نیست یا درخواست با شناسهٔ قبلی تعارض دارد.',
                422 => 'متن، شماره یا خط فرستنده معتبر نیست.',
                429 => 'سقف درخواست API پر شده است؛ ارسال خودکار تکرار نمی‌شود.',
            );
            $message = $messages[ $code ] ?? 'پاسخ سرویس آوا قابل تأیید نیست؛ گزارش پنل آوا را بررسی کنید.';
            return new \WP_Error( 'ava_http_' . $code, $message, array( 'status' => $code, 'uncertain' => 'POST' === $method && ( $code >= 500 || 408 === $code || ( $code >= 200 && $code < 400 ) ) ) );
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['id'] ) || ! is_string( $body['id'] ) ||
             ! preg_match( '/^[A-Za-z0-9_-]{1,100}$/D', $body['id'] ) ||
             ! isset( $body['status'] ) || ! is_string( $body['status'] ) ) {
            return new \WP_Error( 'ava_response', __( 'پاسخ آوا ساختار قابل تأیید ندارد؛ از ارسال مجدد خودکار جلوگیری شد.', 'ava-woocommerce-plugin' ), array( 'uncertain' => 'POST' === $method ) );
        }
        return array( 'id' => $body['id'], 'status' => sanitize_key( $body['status'] ) );
    }
}

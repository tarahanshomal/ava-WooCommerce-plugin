<?php
namespace AvaWoo;

defined( 'ABSPATH' ) || exit;

final class Phone {
    public static function normalize( $phone ) {
        if ( ! is_scalar( $phone ) ) {
            return new \WP_Error( 'ava_phone', __( 'شمارهٔ موبایل معتبر نیست.', 'ava-woocommerce-plugin' ) );
        }
        $phone = strtr( (string) $phone, array_combine(
            preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ),
            str_split( '01234567890123456789' )
        ) );
        $phone = preg_replace( '/[\s()\-\x{200c}\x{200e}\x{200f}]+/u', '', $phone );
        if ( 0 === strpos( $phone, '+98' ) ) {
            $phone = '0' . substr( $phone, 3 );
        } elseif ( 0 === strpos( $phone, '0098' ) ) {
            $phone = '0' . substr( $phone, 4 );
        } elseif ( 0 === strpos( $phone, '98' ) && 12 === strlen( $phone ) ) {
            $phone = '0' . substr( $phone, 2 );
        } elseif ( 10 === strlen( $phone ) && '9' === substr( $phone, 0, 1 ) ) {
            $phone = '0' . $phone;
        }
        return preg_match( '/^09[0-9]{9}$/D', $phone ) ? $phone : new \WP_Error( 'ava_phone', __( 'شماره باید موبایل ایران باشد؛ مانند ۰۹۱۲۳۴۵۶۷۸۹.', 'ava-woocommerce-plugin' ) );
    }

    public static function mask( $phone ) {
        return strlen( $phone ) >= 7 ? substr( $phone, 0, 4 ) . '***' . substr( $phone, -4 ) : '***';
    }
}

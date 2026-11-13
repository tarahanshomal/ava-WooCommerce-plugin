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

    private static function values( $order ) {
        $values = $order ? array(
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
        ) : array( 'site_name' => get_bloginfo( 'name' ) );
        return array_map( array( __CLASS__, 'plain' ), $values );
    }

    public static function render( $template, $order ) {
        $values = self::values( $order );
        $replace = array();
        foreach ( $values as $key => $value ) {
            $replace[ '{' . $key . '}' ] = $value;
        }
        return self::plain( strtr( $template, $replace ) );
    }

    private static function uuid( $value ) {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value );
    }

    /** Keep cloned stores separate without changing existing queued snapshots. */
    public static function store_id() {
        $store = get_option( 'ava_woo_store_id', '' );
        if ( ! $store ) {
            add_option( 'ava_woo_store_id', wp_generate_uuid4(), '', false );
            $store = get_option( 'ava_woo_store_id', '' );
        }
        $parts = function_exists( 'home_url' ) ? wp_parse_url( home_url( '/' ) ) : false;
        if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
            $scheme = strtolower( $parts['scheme'] ?? 'https' );
            $port = isset( $parts['port'] ) && ! ( 'https' === $scheme && 443 === $parts['port'] ) && ! ( 'http' === $scheme && 80 === $parts['port'] ) ? ':' . $parts['port'] : '';
            $origin = $scheme . '://' . strtolower( $parts['host'] ) . $port . rtrim( $parts['path'] ?? '', '/' );
            $previous = get_option( 'ava_woo_store_origin', '' );
            if ( '' === $previous ) {
                add_option( 'ava_woo_store_origin', $origin, '', false );
            } elseif ( $previous !== $origin ) {
                // A copied UUID must not carry approval to a different store.
                $store = wp_generate_uuid4();
                update_option( 'ava_woo_store_id', $store, false );
                update_option( 'ava_woo_store_origin', $origin, false );
            }
        }
        return $store;
    }

    private static function revision( $slot, $template, $values ) {
        global $wpdb;
        $store = self::store_id();
        if ( ! self::uuid( $store ) ) {
            return '';
        }
        $definition = array( 'store_id' => $store, 'template' => $template );
        if ( strpos( $template, '{site_name}' ) !== false ) {
            $definition['site_name'] = $values['site_name'] ?? self::plain( get_bloginfo( 'name' ) );
        }
        $fingerprint = hash( 'sha256', wp_json_encode( $definition, JSON_UNESCAPED_UNICODE ) );
        $name = 'ava_woo_template_revision_' . hash( 'sha256', $slot );
        for ( $attempt = 0; $attempt < 4; ++$attempt ) {
            $current = get_option( $name, null );
            if ( null === $current ) {
                $legacy = get_option( 'ava_woo_template_revisions', array() );
                $seed = is_array( $legacy ) && isset( $legacy[ $slot ] ) ? $legacy[ $slot ] : null;
                if ( ! is_array( $seed ) || ( $seed['fingerprint'] ?? '' ) !== $fingerprint || ! self::uuid( $seed['revision'] ?? '' ) ) {
                    $seed = array( 'fingerprint' => $fingerprint, 'revision' => wp_generate_uuid4() );
                }
                // The unique option name chooses one winner for first use.
                add_option( $name, $seed, '', false );
                self::clear_revision_cache( $name );
                $current = get_option( $name, null );
                if ( null === $current ) {
                    return '';
                }
            }
            if ( is_array( $current ) && ( $current['fingerprint'] ?? '' ) === $fingerprint && self::uuid( $current['revision'] ?? '' ) ) {
                return $current['revision'];
            }
            $next = array( 'fingerprint' => $fingerprint, 'revision' => wp_generate_uuid4() );
            // Compare the actual serialized old value so a concurrent checkout
            // can neither replace the winner nor overwrite another slot.
            $changed = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
                maybe_serialize( $next ), $name, maybe_serialize( $current )
            ) );
            self::clear_revision_cache( $name );
            if ( false === $changed ) {
                return '';
            }
            if ( 1 === $changed ) {
                return $next['revision'];
            }
            // The CAS loser reloads and reuses the winner for this definition.
        }
        return '';
    }

    private static function clear_revision_cache( $name ) {
        wp_cache_delete( $name, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        wp_cache_delete( 'alloptions', 'options' );
    }

    /** A saved edit advances the revision even if no order used the interim text. */
    public static function settings_changed( $settings ) {
        foreach ( $settings['templates'] ?? array() as $status => $template ) {
            if ( is_string( $template ) && preg_match( '/^[a-z0-9_-]{1,40}$/D', (string) $status ) ) {
                self::revision( 'customer:' . $status, self::plain( $template ), array() );
            }
        }
        if ( isset( $settings['admin_template'] ) && is_string( $settings['admin_template'] ) ) {
            self::revision( 'admin', self::plain( $settings['admin_template'] ), array() );
        }
    }

    /** Snapshot only the template variables actually used in this message. */
    public static function context( $template, $order, $slot ) {
        if ( ! is_string( $template ) || ! is_string( $slot ) || ! preg_match( '/^(?:customer:[a-z0-9_-]{1,40}|admin|manual|test)$/D', $slot ) ) {
            return null;
        }
        $canonical = self::plain( $template );
        if ( is_wp_error( self::validate( $canonical ) ) || preg_match( '/^[\p{Z}\s]|[\p{Z}\s]$/u', $canonical ) || preg_match( '/[<>]/u', $canonical ) || preg_match( '/[\p{C}\p{Zl}\p{Zp}]/u', str_replace( array( "\n", "\u{200C}" ), '', $canonical ) ) ) {
            return null;
        }
        preg_match_all( '/\{([^{}]+)\}/u', $canonical, $matches );
        $tokens = array_unique( $matches[1] );
        $all = self::values( $order );
        $used = $replace = array();
        foreach ( $tokens as $token ) {
            if ( ! in_array( $token, self::placeholders(), true ) || ! array_key_exists( $token, $all ) || ! self::variable_valid( $token, $all[ $token ] ) ) {
                return null;
            }
            $used[ $token ] = $all[ $token ];
            $replace[ '{' . $token . '}' ] = $all[ $token ];
        }
        $remaining = str_replace( array_keys( $replace ), '', $canonical );
        if ( false !== strpos( $remaining, '{' ) || false !== strpos( $remaining, '}' ) ) {
            return null;
        }
        $text = self::render( $template, $order );
        // PHP strtr is one pass. A second entity decode or stripping introduced
        // markup must never make the metadata describe different sent content.
        if ( trim( strtr( $canonical, $replace ) ) !== $text ) {
            return null;
        }
        $revision = self::revision( $slot, $canonical, $used );
        if ( ! $revision ) {
            return null;
        }
        return array( 'version' => 1, 'store_id' => self::store_id(), 'slot' => $slot, 'revision' => $revision, 'template' => $canonical, 'values' => $used );
    }

    private static function variable_valid( $token, $value ) {
        if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) || preg_match( '/^[\p{Z}\s]|[\p{Z}\s]$/u', $value ) || preg_match( '/[<>\p{C}\p{Zl}\p{Zp}]/u', str_replace( "\u{200C}", '', $value ) ) ) {
            return false;
        }
        switch ( $token ) {
            case 'order_id': return 1 === preg_match( '/^[1-9][0-9]{0,17}$/D', $value );
            case 'order_number': return 1 === preg_match( '/^[A-Za-z0-9۰-۹٠-٩_-]{1,32}$/uD', $value );
            case 'order_total': return 1 === preg_match( '/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,6})?$/D', $value );
            case 'currency': return in_array( $value, array( 'IRR', 'IRT', 'IRHR', 'IRHT', 'USD', 'EUR', 'GBP', 'AED', 'TRY' ), true );
            case 'billing_phone': return 1 === preg_match( '/^[0-9۰-۹٠-٩+ ()-]{1,32}$/uD', $value ) && ! is_wp_error( Phone::normalize( $value ) );
            case 'billing_first_name':
            case 'billing_last_name': return self::label_valid( $value, false, 60, 6 );
            case 'payment_method':
            case 'shipping_method': return self::label_valid( $value, true, 60, 8 );
            case 'site_name': return '' !== $value && self::length( $value ) <= 120;
            case 'order_status': return self::label_valid( $value, true, 60, 8 );
        }
        return false;
    }

    private static function label_valid( $value, $method, $maximum, $words ) {
        if ( self::length( $value ) > $maximum || false !== strpos( $value, '  ' ) || count( preg_split( '/ +/u', $value, -1, PREG_SPLIT_NO_EMPTY ) ) > $words || ( '' !== $value && ! preg_match( '/\p{L}/u', $value ) ) ) {
            return false;
        }
        $pattern = $method ? "/^[\p{L}\p{M}\p{Nd} '\x{2019}\x{200C}&()\-]*$/uD" : "/^[\p{L}\p{M} '\x{2019}\x{200C}\-]*$/uD";
        return 1 === preg_match( $pattern, $value );
    }

    private static function length( $value ) {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : preg_match_all( '/./us', $value );
    }

    public static function valid_context( $context, $text ) {
        if ( ! is_array( $context ) || array_diff( array_keys( $context ), array( 'version', 'store_id', 'slot', 'revision', 'template', 'values' ) ) ||
             1 !== ( $context['version'] ?? null ) || ! self::uuid( $context['store_id'] ?? null ) || ! self::uuid( $context['revision'] ?? null ) ||
             ! is_string( $context['slot'] ?? null ) || ! preg_match( '/^(?:customer:[a-z0-9_-]{1,40}|admin|manual|test)$/D', $context['slot'] ) ||
             ! is_string( $context['template'] ?? null ) || is_wp_error( self::validate( $context['template'] ) ) || $context['template'] !== trim( $context['template'] ) || preg_match( '/^[\p{Z}\s]|[\p{Z}\s]$/u', $context['template'] ) || preg_match( '/[<>]/u', $context['template'] ) || preg_match( '/[\p{C}\p{Zl}\p{Zp}]/u', str_replace( array( "\n", "\u{200C}" ), '', $context['template'] ) ) || ! is_array( $context['values'] ?? null ) ) {
            return false;
        }
        preg_match_all( '/\{([^{}]+)\}/u', $context['template'], $matches );
        $tokens = array_unique( $matches[1] );
        if ( array_diff( $tokens, array_keys( $context['values'] ) ) || array_diff( array_keys( $context['values'] ), $tokens ) ) {
            return false;
        }
        $replace = array();
        foreach ( $context['values'] as $token => $value ) {
            if ( ! in_array( $token, self::placeholders(), true ) || ! self::variable_valid( $token, $value ) ) {
                return false;
            }
            $replace[ '{' . $token . '}' ] = $value;
        }
        $remaining = str_replace( array_keys( $replace ), '', $context['template'] );
        return false === strpos( $remaining, '{' ) && false === strpos( $remaining, '}' ) && trim( strtr( $context['template'], $replace ) ) === $text;
    }

    public static function wire_context( $context ) {
        // An empty JSON object is required even for a literal-only template.
        $context['values'] = (object) $context['values'];
        return $context;
    }

    public static function validate( $text ) {
        if ( ! is_string( $text ) ) {
            return new \WP_Error( 'ava_text', __( 'متن پیامک معتبر نیست.', 'ava-sms-for-woocommerce' ) );
        }
        $text = trim( $text );
        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : preg_match_all( '/./us', $text );
        if ( $length < 1 || $length > 1000 ) {
            return new \WP_Error( 'ava_text', __( 'طول متن پیامک باید بین ۱ و ۱۰۰۰ نویسه باشد.', 'ava-sms-for-woocommerce' ) );
        }
        return $text;
    }
}

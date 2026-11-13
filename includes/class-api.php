<?php
namespace AvaWoo;

defined( 'ABSPATH' ) || exit;

final class Api {
    private static function connection_key( $settings ) {
        return 'ava_woo_connection_' . hash( 'sha256', (string) ( $settings['api_url'] ?? '' ) . "\n" . (string) ( $settings['api_key'] ?? '' ) );
    }

    /** A diagnostic belongs only to the saved endpoint and credential. */
    public static function connection_snapshot( $settings = null ) {
        $settings = null === $settings ? Settings::get() : $settings;
        return get_transient( self::connection_key( $settings ) );
    }

    private static function remember_connection( $settings, $state, $message ) {
        $result = array( 'state' => $state, 'message' => $message, 'checked_at' => time() );
        set_transient( self::connection_key( $settings ), $result, DAY_IN_SECONDS );
        return $result;
    }

    private static function clear_authentication_failure( $settings ) {
        $snapshot = self::connection_snapshot( $settings );
        if ( is_array( $snapshot ) && 'authentication_failed' === ( $snapshot['state'] ?? '' ) ) {
            delete_transient( self::connection_key( $settings ) );
        }
    }

    private static function authentication_failure( $settings, $response ) {
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $reason = is_array( $body ) && is_string( $body['auth_reason'] ?? null ) ? $body['auth_reason'] : '';
        $messages = array(
            'key_version_mismatch' => 'کلید اتصال پس از تغییر امنیتی یا بازیابی رمز حساب آوا باطل شده است.',
            'key_expired' => 'کلید اتصال آوا منقضی شده است.',
            'key_revoked' => 'کلید اتصال آوا لغو شده است.',
            'account_inactive' => 'حساب آوا فعال نیست؛ وضعیت حساب را در پنل آوا یا با پشتیبانی بررسی کنید.',
        );
        $message = $messages[ $reason ] ?? 'کلید اتصال آوا پذیرفته نشد (خطای ۴۰۱).';
        if ( 'account_inactive' !== $reason ) {
            $message .= ' در «اتصال و کلیدهای API» پنل آوا، کلید تازه با مجوزهای sms:send و sms:read بسازید، آن را در تنظیمات افزونه جایگزین و ذخیره کنید و «بررسی اتصال» را بزنید.';
        }
        $message .= ' پیامک‌های قبلی خودکار دوباره ارسال نمی‌شوند.';
        self::remember_connection( $settings, 'authentication_failed', $message );
        return $message;
    }

    /** Authenticate and inspect readiness without creating a message or a charge. */
    public static function connection( $settings = null ) {
        $settings = null === $settings ? Settings::get() : $settings;
        $url = Settings::valid_url( $settings['api_url'] ?? null );
        $key = $settings['api_key'] ?? '';
        if ( ! $url || ! is_string( $key ) || ! preg_match( '/^ava_live_[A-Za-z0-9_-]{16,240}$/D', $key ) ) {
            $message = 'ابتدا نشانی پنل و کلید API آوا را ذخیره کنید؛ سپس اتصال را بررسی کنید.';
            self::remember_connection( $settings, 'configuration', $message );
            return new \WP_Error( 'ava_connection_configuration', $message );
        }
        $response = wp_safe_remote_request( $url . '/api/v1/connection', array(
            'method' => 'GET', 'timeout' => 5, 'redirection' => 0, 'sslverify' => true,
            'reject_unsafe_urls' => true, 'limit_response_size' => 16384,
            'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json' ),
            'user-agent' => 'AvaWooCommerce/' . AVA_WOO_VERSION . '; WordPress',
        ) );
        $message = 'پاسخ بررسی اتصال دریافت نشد؛ نشانی پنل و دسترسی HTTPS فروشگاه را بررسی و دوباره تلاش کنید.';
        if ( ! is_wp_error( $response ) ) {
            $status = wp_remote_retrieve_response_code( $response );
            if ( 401 === $status ) {
                return new \WP_Error( 'ava_connection_auth', self::authentication_failure( $settings, $response ) );
            }
            if ( 404 === $status ) {
                $message = 'این پنل هنوز بررسی اتصال را پشتیبانی نمی‌کند؛ پنل آوا باید به‌روز شود.';
            } elseif ( 429 === $status ) {
                $message = 'سقف درخواست API پر شده است؛ کمی بعد اتصال را بررسی کنید.';
            } elseif ( 403 === $status ) {
                $message = 'دسترسی اتصال آوا مجاز نیست؛ وضعیت حساب و دامنهٔ پنل را بررسی کنید.';
            } elseif ( 200 === $status ) {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                $fields = array( 'authenticated', 'sms_send', 'sms_read', 'sms_account_ready', 'sms_service_ready' );
                $valid = is_array( $body );
                foreach ( $fields as $field ) {
                    $valid = $valid && isset( $body[ $field ] ) && is_bool( $body[ $field ] );
                }
                if ( $valid && true === $body['authenticated'] ) {
                    $issues = array();
                    if ( ! $body['sms_send'] || ! $body['sms_read'] ) {
                        $issues[] = 'کلید باید هر دو مجوز sms:send و sms:read را داشته باشد؛ کلید مناسب بسازید و در افزونه ذخیره کنید.';
                    }
                    if ( ! $body['sms_account_ready'] ) {
                        $issues[] = 'حساب برای پیامک آماده نیست؛ وضعیت حساب یا نماینده را در پنل آوا بررسی کنید.';
                    }
                    if ( ! $body['sms_service_ready'] ) {
                        $issues[] = 'اتصال پیامکی سرویس آوا آماده نیست؛ با پشتیبانی آوا پیگیری کنید.';
                    }
                    $message = $issues ? implode( ' ', $issues ) : 'کلید، مجوزهای ارسال و گزارش و آمادگی اتصال پیامکی تأیید شد. این بررسی پیامک نمی‌فرستد و هزینه ندارد؛ موجودی کافی، بررسی محتوا و نتیجهٔ تحویل هر پیام در گزارش آن مشخص می‌شود.';
                    return self::remember_connection( $settings, $issues ? 'attention' : 'ready', $message );
                }
                $message = 'پاسخ بررسی اتصال معتبر نیست؛ نسخه و نشانی پنل آوا را بررسی کنید.';
            }
        }
        self::remember_connection( $settings, 'unavailable', $message );
        return new \WP_Error( 'ava_connection_unavailable', $message );
    }

    /** Read only the current account wallet; never infer credit from send results. */
    public static function wallet( $refresh = false, $settings = null ) {
        $settings = null === $settings ? Settings::get() : $settings;
        $url = Settings::valid_url( $settings['api_url'] ?? null );
        $key = $settings['api_key'] ?? '';
        if ( ! $url || ! is_string( $key ) || ! preg_match( '/^ava_live_[A-Za-z0-9_-]{16,240}$/D', $key ) ) {
            return new \WP_Error( 'ava_wallet_configuration', 'برای نمایش موجودی، نشانی پنل و کلید API آوا را ذخیره کنید.' );
        }
        // A different URL/key must never inherit another account's cached balance.
        $cache_key = 'ava_woo_wallet_' . hash( 'sha256', $url . "\n" . $key );
        if ( ! $refresh ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) && isset( $cached['value'] ) ) {
                return $cached['value'];
            }
        }
        $response = wp_safe_remote_request( $url . '/api/v1/wallet', array(
            'method' => 'GET', 'timeout' => 5, 'redirection' => 0, 'sslverify' => true,
            'reject_unsafe_urls' => true, 'limit_response_size' => 16384,
            'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json' ),
            'user-agent' => 'AvaWooCommerce/' . AVA_WOO_VERSION . '; WordPress',
        ) );
        $result = new \WP_Error( 'ava_wallet_unavailable', 'موجودی حساب آوا دریافت نشد؛ کمی بعد تازه‌سازی کنید یا کیف پول پنل آوا را بررسی کنید.' );
        if ( ! is_wp_error( $response ) ) {
            $status = wp_remote_retrieve_response_code( $response );
            if ( 401 === $status ) {
                $result = new \WP_Error( 'ava_wallet_auth', self::authentication_failure( $settings, $response ) );
            } elseif ( 403 === $status ) {
                $result = new \WP_Error( 'ava_wallet_scope', 'کلید API اجازهٔ مشاهدهٔ موجودی ندارد؛ از کلید دارای مجوز sms:send یا wallet:read استفاده کنید.' );
            } elseif ( 429 === $status ) {
                $result = new \WP_Error( 'ava_wallet_rate', 'سقف درخواست API پر شده است؛ کمی بعد موجودی را تازه‌سازی کنید.' );
            } elseif ( 200 === $status ) {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( is_array( $body ) && isset( $body['balance_irr'], $body['held_irr'], $body['available_irr'], $body['currency'] ) &&
                    'IRR' === $body['currency'] && is_int( $body['balance_irr'] ) && is_int( $body['held_irr'] ) &&
                    is_int( $body['available_irr'] ) && $body['held_irr'] >= 0 &&
                    $body['balance_irr'] - $body['held_irr'] === $body['available_irr'] ) {
                    $result = array( 'balance_irr' => $body['balance_irr'], 'held_irr' => $body['held_irr'],
                        'available_irr' => $body['available_irr'], 'currency' => 'IRR', 'checked_at' => time() );
                    self::clear_authentication_failure( $settings );
                }
            }
        }
        // Cache failures too, without reusing a previous successful account value.
        set_transient( $cache_key, array( 'value' => $result ), 60 );
        return $result;
    }

    public static function send( $phone, $text, $idempotency_key, $settings = null, $template_context = null ) {
        $phone = Phone::normalize( $phone );
        if ( is_wp_error( $phone ) ) {
            return $phone;
        }
        $text = Template::validate( $text );
        if ( is_wp_error( $text ) ) {
            return $text;
        }
        if ( ! is_string( $idempotency_key ) || ! preg_match( '/^[A-Za-z0-9_-]{16,100}$/D', $idempotency_key ) ) {
            return new \WP_Error( 'ava_idempotency', __( 'شناسهٔ یکتای ارسال معتبر نیست.', 'ava-sms-for-woocommerce' ) );
        }
        $settings = null === $settings ? Settings::get() : $settings;
        $payload = array( 'phone' => $phone, 'text' => $text );
        if ( Template::valid_context( $template_context, $text ) ) {
            $payload['woocommerce_template'] = Template::wire_context( $template_context );
        }
        if ( '' !== $settings['sender'] ) {
            $payload['sender'] = $settings['sender'];
        }
        return self::request( 'POST', '/api/v1/sms', $payload, $idempotency_key, $settings );
    }

    public static function report( $id, $settings = null ) {
        if ( ! is_string( $id ) || ! preg_match( '/^[A-Za-z0-9_-]{1,100}$/D', $id ) ) {
            return new \WP_Error( 'ava_id', __( 'شناسهٔ پیامک معتبر نیست.', 'ava-sms-for-woocommerce' ) );
        }
        return self::request( 'GET', '/api/v1/sms/' . rawurlencode( $id ), null, '', $settings );
    }

    private static function request( $method, $path, $payload = null, $idempotency_key = '', $settings = null ) {
        $settings = null === $settings ? Settings::get() : $settings;
        $url = Settings::valid_url( $settings['api_url'] );
        if ( ! $url || ! preg_match( '/^ava_live_[A-Za-z0-9_-]{16,240}$/D', $settings['api_key'] ) ) {
            return new \WP_Error( 'ava_configuration', __( 'نشانی یا کلید API آوا تنظیم نشده است.', 'ava-sms-for-woocommerce' ) );
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
                ? __( 'پاسخ سرویس دریافت نشد؛ وضعیت ارسال نامشخص است. برای جلوگیری از هزینهٔ تکراری ارسال خودکار تکرار نمی‌شود.', 'ava-sms-for-woocommerce' )
                : __( 'گزارش آوا دریافت نشد؛ وضعیت قبلی پیامک حفظ شد.', 'ava-sms-for-woocommerce' );
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
            $message = 401 === $code ? self::authentication_failure( $settings, $response ) : ( $messages[ $code ] ?? 'پاسخ سرویس آوا قابل تأیید نیست؛ گزارش پنل آوا را بررسی کنید.' );
            return new \WP_Error( 'ava_http_' . $code, $message, array( 'status' => $code, 'uncertain' => 'POST' === $method && ( $code >= 500 || 408 === $code || ( $code >= 200 && $code < 400 ) ) ) );
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['id'] ) || ! is_string( $body['id'] ) ||
             ! preg_match( '/^[A-Za-z0-9_-]{1,100}$/D', $body['id'] ) ||
             ! isset( $body['status'] ) || ! is_string( $body['status'] ) ) {
            return new \WP_Error( 'ava_response', __( 'پاسخ آوا ساختار قابل تأیید ندارد؛ از ارسال مجدد خودکار جلوگیری شد.', 'ava-sms-for-woocommerce' ), array( 'uncertain' => 'POST' === $method ) );
        }
        $result = array( 'id' => $body['id'], 'status' => sanitize_key( $body['status'] ) );
        self::clear_authentication_failure( $settings );
        foreach ( array( 'review_status', 'campaign_status', 'dispatch_state' ) as $field ) {
            if ( isset( $body[ $field ] ) && is_string( $body[ $field ] ) && preg_match( '/^[a-z0-9_-]{0,64}$/D', $body[ $field ] ) ) {
                $result[ $field ] = $body[ $field ];
            }
        }
        if ( isset( $body['requires_review'] ) && is_bool( $body['requires_review'] ) ) {
            $result['requires_review'] = $body['requires_review'];
        }
        foreach ( array( 'detail', 'dispatch_detail' ) as $field ) {
            if ( isset( $body[ $field ] ) && is_string( $body[ $field ] ) ) {
                $result[ $field ] = sanitize_text_field( mb_substr( $body[ $field ], 0, 240 ) );
            }
        }
        return $result;
    }
}

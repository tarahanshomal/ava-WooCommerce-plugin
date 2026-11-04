<?php
namespace AvaWoo;

defined( 'ABSPATH' ) || exit;

final class Queue {
    const SCHEMA = '1.0.0';
    const GROUP = 'ava-woocommerce';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'ava_woo_messages';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_key varchar(64) NOT NULL,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            audience varchar(16) NOT NULL,
            phone varchar(16) NOT NULL,
            message text NOT NULL,
            api_url varchar(255) NOT NULL,
            key_fingerprint varchar(64) NOT NULL,
            sender varchar(64) NOT NULL DEFAULT '',
            status varchar(24) NOT NULL DEFAULT 'pending',
            provider_id varchar(100) NOT NULL DEFAULT '',
            detail text NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_key (event_key),
            KEY order_id (order_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};" );
        update_option( 'ava_woo_schema', self::SCHEMA, false );
        add_option( 'ava_woo_settings', Settings::defaults(), '', false );
        if ( ! wp_next_scheduled( 'ava_woo_housekeeping' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'ava_woo_housekeeping' );
        }
    }

    public static function init() {
        if ( self::SCHEMA !== get_option( 'ava_woo_schema' ) ) {
            self::install();
        }
        if ( ! wp_next_scheduled( 'ava_woo_housekeeping' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'ava_woo_housekeeping' );
        }
        add_action( 'ava_woo_send', array( __CLASS__, 'process' ), 10, 1 );
        add_action( 'ava_woo_report', array( __CLASS__, 'poll' ), 10, 2 );
        add_action( 'ava_woo_housekeeping', array( __CLASS__, 'housekeeping' ) );
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'ava_woo_send' );
        wp_clear_scheduled_hook( 'ava_woo_report' );
        wp_clear_scheduled_hook( 'ava_woo_housekeeping' );
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'ava_woo_send', null, self::GROUP );
            as_unschedule_all_actions( 'ava_woo_report', null, self::GROUP );
        }
    }

    private static function schedule( $hook, $args, $delay = 1 ) {
        if ( function_exists( 'as_schedule_single_action' ) ) {
            return as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP, true );
        }
        if ( ! wp_next_scheduled( $hook, $args ) ) {
            return wp_schedule_single_event( time() + $delay, $hook, $args );
        }
        return true;
    }

    public static function enqueue( $phone, $message, $event_key, $order_id = 0, $audience = 'customer' ) {
        global $wpdb;
        $phone = Phone::normalize( $phone );
        if ( is_wp_error( $phone ) ) {
            return $phone;
        }
        $message = Template::validate( $message );
        if ( is_wp_error( $message ) ) {
            return $message;
        }
        if ( ! is_string( $event_key ) || '' === $event_key || ! in_array( $audience, array( 'customer', 'admin', 'test', 'manual' ), true ) ) {
            return new \WP_Error( 'ava_event', __( 'رویداد پیامک معتبر نیست.', 'ava-woocommerce-plugin' ) );
        }
        $settings = Settings::get();
        if ( in_array( $audience, array( 'customer', 'admin' ), true ) && 'yes' !== $settings['enabled'] ) {
            return new \WP_Error( 'ava_disabled', __( 'ارسال خودکار خاموش است.', 'ava-woocommerce-plugin' ) );
        }
        $url = Settings::valid_url( $settings['api_url'] );
        if ( ! $url || ! preg_match( '/^ava_live_[A-Za-z0-9_-]{16,240}$/D', $settings['api_key'] ) ) {
            return new \WP_Error( 'ava_configuration', __( 'ابتدا نشانی و کلید API آوا را ذخیره کنید.', 'ava-woocommerce-plugin' ) );
        }
        $table = self::table();
        // A random key per store prevents request-key collisions between separate shops.
        $store_id = get_option( 'ava_woo_store_id' );
        if ( ! $store_id ) {
            add_option( 'ava_woo_store_id', wp_generate_uuid4(), '', false );
            $store_id = get_option( 'ava_woo_store_id' );
        }
        $key = hash( 'sha256', $store_id . '|' . $event_key );
        $now = current_time( 'mysql', true );
        $inserted = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$table} (event_key,order_id,audience,phone,message,api_url,key_fingerprint,sender,status,detail,created_at,updated_at) VALUES (%s,%d,%s,%s,%s,%s,%s,%s,'pending','',%s,%s)",
            $key, absint( $order_id ), $audience, $phone, $message, $url, hash( 'sha256', $settings['api_key'] ), $settings['sender'], $now, $now
        ) );
        if ( false === $inserted ) {
            return new \WP_Error( 'ava_storage', __( 'ذخیره در صف پیامک ناموفق بود.', 'ava-woocommerce-plugin' ) );
        }
        $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE event_key = %s", $key ) );
        if ( ! $id ) {
            return new \WP_Error( 'ava_storage', __( 'شناسهٔ صف پیامک پیدا نشد.', 'ava-woocommerce-plugin' ) );
        }
        if ( 1 === $inserted ) {
            self::schedule( 'ava_woo_send', array( $id ) );
        }
        return $id;
    }

    public static function process( $id ) {
        global $wpdb;
        $id = absint( $id );
        $table = self::table();
        // Only one worker can enter the send path, even with concurrent cron requests.
        $claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'processing', updated_at = %s WHERE id = %d AND status = 'pending'", current_time( 'mysql', true ), $id ) );
        if ( 1 !== $claimed ) {
            return;
        }
        $job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
        if ( ! $job ) {
            return;
        }
        $settings = Settings::get();
        if ( in_array( $job->audience, array( 'customer', 'admin' ), true ) && 'yes' !== $settings['enabled'] ) {
            self::update( $id, array( 'status' => 'cancelled', 'detail' => 'ارسال خودکار پیش از اجرا خاموش شد.' ) );
            return;
        }
        if ( ! self::same_account( $job, $settings ) || $job->sender !== $settings['sender'] ) {
            self::update( $id, array( 'status' => 'cancelled', 'detail' => 'تنظیمات اتصال یا خط فرستنده پس از ثبت در صف تغییر کرد؛ پیامک ارسال نشد.' ) );
            return;
        }
        $result = Api::send( $job->phone, $job->message, $job->event_key, $settings );
        if ( is_wp_error( $result ) ) {
            $data = $result->get_error_data();
            self::update( $id, array( 'status' => ! empty( $data['uncertain'] ) ? 'unknown' : 'failed', 'detail' => $result->get_error_message() ) );
            return;
        }
        $status = self::provider_status( $result['status'] );
        self::update( $id, array( 'status' => $status, 'provider_id' => $result['id'], 'detail' => 'در آوا ثبت شد؛ ثبت یا پذیرش به معنی تحویل به گیرنده نیست.' ) );
        if ( ! self::terminal( $status ) ) {
            self::schedule( 'ava_woo_report', array( $id, 0 ), 60 );
        }
    }

    private static function same_account( $job, $settings ) {
        return $job->api_url === Settings::valid_url( $settings['api_url'] ) && hash_equals( $job->key_fingerprint, hash( 'sha256', $settings['api_key'] ) );
    }

    private static function provider_status( $status ) {
        $known = array( 'pending', 'sending', 'unknown', 'accepted', 'sent', 'delivered', 'undelivered', 'rejected', 'blocked', 'cancelled', 'simulated', 'expired' );
        if ( 'pending' === $status ) {
            return 'queued';
        }
        return in_array( $status, $known, true ) ? $status : 'unknown';
    }

    private static function terminal( $status ) {
        return in_array( $status, array( 'delivered', 'undelivered', 'rejected', 'blocked', 'cancelled', 'simulated', 'expired', 'failed' ), true );
    }

    private static function update( $id, $values ) {
        global $wpdb;
        $values['updated_at'] = current_time( 'mysql', true );
        $wpdb->update( self::table(), $values, array( 'id' => $id ) );
    }

    public static function refresh( $id ) {
        global $wpdb;
        $table = self::table();
        $job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) );
        if ( ! $job || '' === $job->provider_id ) {
            return new \WP_Error( 'ava_no_report', __( 'شناسهٔ آوا برای این پیامک در دسترس نیست؛ گزارش پنل آوا را بررسی کنید.', 'ava-woocommerce-plugin' ) );
        }
        $settings = Settings::get();
        if ( ! self::same_account( $job, $settings ) ) {
            return new \WP_Error( 'ava_account_changed', __( 'اتصال آوا تغییر کرده است؛ گزارش را در حساب اولیهٔ آوا بررسی کنید.', 'ava-woocommerce-plugin' ) );
        }
        $report = Api::report( $job->provider_id, $settings );
        if ( is_wp_error( $report ) ) {
            return $report;
        }
        if ( $report['id'] !== $job->provider_id ) {
            return new \WP_Error( 'ava_report_mismatch', __( 'شناسهٔ گزارش آوا با پیامک مطابقت ندارد.', 'ava-woocommerce-plugin' ) );
        }
        $report['status'] = self::provider_status( $report['status'] );
        self::update( $job->id, array( 'status' => $report['status'], 'detail' => 'وضعیت از گزارش آوا دریافت شد؛ فقط «تحویل‌شده» تأیید تحویل است.' ) );
        return $report;
    }

    public static function poll( $id, $attempt = 0 ) {
        $attempt = absint( $attempt );
        $report = self::refresh( $id );
        if ( $attempt < 5 && ( is_wp_error( $report ) || ! self::terminal( $report['status'] ) ) ) {
            self::schedule( 'ava_woo_report', array( absint( $id ), $attempt + 1 ), min( HOUR_IN_SECONDS, 120 * ( 2 ** $attempt ) ) );
        }
    }

    public static function housekeeping() {
        global $wpdb;
        $table = self::table();
        // A killed worker may already have sent. Mark unknown, never send it again.
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'unknown', detail = %s, updated_at = %s WHERE status = 'processing' AND updated_at < %s",
            'اجرای قبلی کامل نشد؛ وضعیت نامشخص است و ارسال تکرار نمی‌شود.', current_time( 'mysql', true ), gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ) ) );
        $pending = $wpdb->get_col( "SELECT id FROM {$table} WHERE status = 'pending' ORDER BY id ASC LIMIT 100" );
        foreach ( $pending as $id ) {
            self::schedule( 'ava_woo_send', array( (int) $id ) );
        }
        self::cleanup();
    }

    public static function cleanup() {
        global $wpdb;
        $settings = Settings::get();
        $days = max( 1, min( 90, absint( $settings['retention_days'] ) ) );
        $table = self::table();
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
    }
}

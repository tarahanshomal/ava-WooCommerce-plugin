<?php
namespace AvaWoo;

defined( 'ABSPATH' ) || exit;

final class Queue {
    const SCHEMA = '1.0.1';
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
            review_status varchar(64) NOT NULL DEFAULT '',
            campaign_status varchar(64) NOT NULL DEFAULT '',
            requires_review tinyint(1) NOT NULL DEFAULT 0,
            dispatch_state varchar(64) NOT NULL DEFAULT '',
            dispatch_detail varchar(240) NOT NULL DEFAULT '',
            report_due bigint(20) unsigned NOT NULL DEFAULT 0,
            report_attempts bigint(20) unsigned NOT NULL DEFAULT 0,
            detail text NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_key (event_key),
            KEY order_id (order_id),
            KEY status (status),
            KEY report_due (report_due),
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
        $values = self::report_values( $result );
        $status = $values['status'];
        $values['provider_id'] = $result['id'];
        self::update( $id, $values );
        if ( ! self::terminal( $status ) ) {
            self::schedule_report( $id, 0, 60 );
        }
    }

    private static function same_account( $job, $settings ) {
        return $job->api_url === Settings::valid_url( $settings['api_url'] ) && hash_equals( $job->key_fingerprint, hash( 'sha256', $settings['api_key'] ) );
    }

    private static function provider_status( $status, $report = array() ) {
        $known = array( 'pending', 'queued', 'sending', 'unknown', 'accepted', 'sent', 'delivered', 'undelivered', 'rejected', 'blocked', 'cancelled', 'simulated', 'expired' );
        if ( 'pending' === $status ) {
            $dispatch = $report['dispatch_state'] ?? '';
            if ( 'review_rejected' === $dispatch || 'rejected' === ( $report['review_status'] ?? '' ) ) {
                return 'rejected';
            }
            $waiting = array( 'awaiting_review', 'awaiting_dispatch', 'credit_blocked', 'waiting_capacity', 'awaiting_configuration', 'blocked', 'awaiting_start', 'paused', 'scheduled', 'queued' );
            if ( in_array( $dispatch, $waiting, true ) ) {
                // A pre-dispatch block may clear; keep reporting it, without resending.
                return 'blocked' === $dispatch ? 'dispatch_blocked' : $dispatch;
            }
            if ( ! empty( $report['requires_review'] ) ) {
                return 'awaiting_review';
            }
            return 'queued';
        }
        return in_array( $status, $known, true ) ? $status : 'unknown';
    }

    private static function report_values( $report ) {
        $status = self::provider_status( $report['status'], $report );
        $details = array(
            'awaiting_review' => 'در آوا ثبت شد و منتظر تأیید محتواست؛ هنوز وارد صف ارسال نشده است.',
            'awaiting_dispatch' => 'محتوا تأیید شد؛ آوا در حال آماده‌سازی ارسال است.',
            'awaiting_start' => 'محتوا آماده است؛ شروع ارسال باید در پنل آوا انجام شود.',
            'credit_blocked' => 'ارسال منتظر افزایش اعتبار حساب آواست؛ درخواست دوباره ارسال نمی‌شود.',
            'waiting_capacity' => 'ارسال منتظر ظرفیت مجاز حساب آواست.',
            'awaiting_configuration' => 'ارسال منتظر آماده‌شدن اتصال پیامک آواست.',
            'dispatch_blocked' => 'آوا ارسال را پیش از ارسال به اپراتور متوقف کرده است؛ وضعیت حساب و محتوا را در پنل آوا بررسی کنید.',
            'paused' => 'ارسال در آوا متوقف شده است؛ ادامه را در پنل آوا بررسی کنید.',
            'scheduled' => 'ارسال برای زمان تعیین‌شده در آوا برنامه‌ریزی شده است.',
            'rejected' => 'درخواست پیامک در آوا رد شد؛ دلیل را در پنل آوا بررسی کنید.',
        );
        $values = array( 'status' => $status, 'detail' => $details[ $status ] ?? 'وضعیت از آوا دریافت شد؛ فقط «تحویل‌شده» تأیید تحویل است.' );
        foreach ( array( 'review_status', 'campaign_status', 'dispatch_state' ) as $field ) {
            $values[ $field ] = $report[ $field ] ?? '';
        }
        $values['requires_review'] = ! empty( $report['requires_review'] ) ? 1 : 0;
        $values['dispatch_detail'] = $report['dispatch_detail'] ?? ( $report['detail'] ?? '' );
        return $values;
    }

    private static function schedule_report( $id, $attempt, $delay ) {
        $delay = max( 1, min( HOUR_IN_SECONDS, (int) $delay ) );
        self::update( $id, array( 'report_due' => time() + $delay, 'report_attempts' => $attempt ) );
        return self::schedule( 'ava_woo_report', array( (int) $id, (int) $attempt ), $delay );
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
        $values = self::report_values( $report );
        $report['status'] = $values['status'];
        if ( self::terminal( $report['status'] ) ) {
            $values['report_due'] = 0;
        }
        self::update( $job->id, $values );
        return $report;
    }

    public static function poll( $id, $attempt = 0 ) {
        global $wpdb;
        $job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', absint( $id ) ) );
        if ( ! $job || '' === $job->provider_id || self::terminal( $job->status ) ) {
            return;
        }
        // Ignore an obsolete scheduled generation after another poll made progress.
        $attempt = absint( $attempt );
        if ( $attempt !== (int) $job->report_attempts ) {
            return;
        }
        $report = self::refresh( $id );
        if ( is_wp_error( $report ) || ! self::terminal( $report['status'] ) ) {
            self::schedule_report( $id, $attempt + 1, min( HOUR_IN_SECONDS, 120 * ( 2 ** min( 5, $attempt ) ) ) );
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
        // Restore lost report actions, including jobs admitted by earlier versions.
        // Each batch is bounded; only read-only GET requests are scheduled here.
        $reports = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE provider_id != '' AND status NOT IN ('delivered','undelivered','rejected','blocked','cancelled','simulated','expired','failed') AND report_due <= %d ORDER BY report_due ASC, id ASC LIMIT 100", time() ) );
        foreach ( $reports as $id ) {
            $job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
            if ( $job ) {
                self::schedule_report( $id, (int) $job->report_attempts, 1 );
            }
        }
        self::cleanup();
    }

    public static function cleanup() {
        global $wpdb;
        $settings = Settings::get();
        $days = max( 1, min( 90, absint( $settings['retention_days'] ) ) );
        $table = self::table();
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s AND status IN ('delivered','undelivered','rejected','blocked','cancelled','simulated','expired','failed')", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
    }
}

<?php
/** Administration screens for Ava WooCommerce. */

namespace AvaWoo;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const PAGE = 'ava-woo-sms';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'option_page_capability_ava_woo', array( __CLASS__, 'capability' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_ava_woo_test', array( __CLASS__, 'test_send' ) );
		add_action( 'admin_post_ava_woo_manual', array( __CLASS__, 'manual_send' ) );
		add_action( 'admin_post_ava_woo_refresh', array( __CLASS__, 'refresh' ) );
		add_action( 'admin_post_ava_woo_wallet_refresh', array( __CLASS__, 'wallet_refresh' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ) );
	}

	public static function capability() {
		return 'manage_woocommerce';
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'پیامک آوا', 'پیامک آوا', 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function register() {
		register_setting( 'ava_woo', 'ava_woo_settings', array( 'type' => 'array', 'sanitize_callback' => array( Settings::class, 'sanitize' ), 'show_in_rest' => false ) );
	}

	public static function assets( $hook ) {
		if ( 'woocommerce_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ava-woo-admin', plugins_url( 'assets/admin.css', dirname( __DIR__ ) . '/ava-woocommerce-plugin.php' ), array(), AVA_WOO_VERSION );
	}

	private static function authorize( $nonce ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'اجازهٔ مدیریت پیامک‌های آوا را ندارید.', 'ava-woocommerce-plugin' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce );
	}

	private static function url( $tab = 'settings', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	private static function posted( $name ) {
		return isset( $_POST[ $name ] ) && is_scalar( $_POST[ $name ] ) ? wp_unslash( (string) $_POST[ $name ] ) : '';
	}

	private static function request_id() {
		$request_id = strtolower( self::posted( 'request_id' ) );
		if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $request_id ) ) {
			return new \WP_Error( 'ava_form_request', 'شناسهٔ فرم ارسال معتبر نیست. فرم را دوباره باز کنید.' );
		}
		return $request_id;
	}

	private static function finish( $result, $tab, $args = array() ) {
		$is_error = is_wp_error( $result );
		$message = $is_error ? $result->get_error_message() : (string) $result;
		set_transient( 'ava_woo_notice_' . get_current_user_id(), array( 'error' => $is_error, 'message' => $message ), 60 );
		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	public static function test_send() {
		self::authorize( 'ava_woo_test' );
		$request_id = self::request_id();
		if ( is_wp_error( $request_id ) ) {
			self::finish( $request_id, 'send' );
		}
		if ( 'yes' !== self::posted( 'confirm_cost' ) ) {
			self::finish( new \WP_Error( 'confirmation', 'برای ارسال، هزینهٔ پیامک آزمایشی را تأیید کنید.' ), 'send' );
		}
		$result = Queue::enqueue( sanitize_text_field( self::posted( 'phone' ) ), sanitize_textarea_field( self::posted( 'message' ) ), 'test:' . get_current_user_id() . ':' . $request_id, 0, 'test' );
		self::finish( is_wp_error( $result ) ? $result : sprintf( 'پیامک آزمایشی با شناسهٔ %d در صف قرار گرفت. نتیجه را در گزارش‌ها ببینید.', $result ), 'logs' );
	}

	public static function manual_send() {
		self::authorize( 'ava_woo_manual' );
		$order_id = absint( self::posted( 'order_id' ) );
		$request_id = self::request_id();
		if ( is_wp_error( $request_id ) ) {
			self::finish( $request_id, 'send', array( 'order_id' => $order_id ) );
		}
		if ( 'yes' !== self::posted( 'confirm_cost' ) ) {
			self::finish( new \WP_Error( 'confirmation', 'برای ارسال، هزینهٔ پیامک دستی را تأیید کنید.' ), 'send', array( 'order_id' => $order_id ) );
		}
		$result = Notifications::manual( $order_id, sanitize_textarea_field( self::posted( 'message' ) ), $request_id );
		self::finish( is_wp_error( $result ) ? $result : sprintf( 'پیامک سفارش با شناسهٔ %d در صف قرار گرفت. نتیجه را در گزارش‌ها ببینید.', $result ), 'logs' );
	}

	public static function refresh() {
		$id = absint( self::posted( 'job_id' ) );
		self::authorize( 'ava_woo_refresh_' . $id );
		$result = Queue::refresh( $id );
		self::finish( is_wp_error( $result ) ? $result : 'گزارش پیامک از آوا دریافت شد.', 'logs' );
	}

	public static function wallet_refresh() {
		self::authorize( 'ava_woo_wallet_refresh' );
		$result = Api::wallet( true );
		self::finish( is_wp_error( $result ) ? $result : 'موجودی حساب آوا تازه‌سازی شد.', 'settings' );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( ! in_array( $tab, array( 'settings', 'send', 'logs' ), true ) ) {
			$tab = 'settings';
		}
		$settings = Settings::get();
		?>
		<div class="wrap ava-woo" dir="rtl">
			<div class="ava-woo-header">
				<div><span class="ava-woo-eyebrow">AVA / WOOCOMMERCE</span><h1>پیامک آوا</h1><p>خبر سفارش‌ها، در زمان مناسب؛ با پیامک آوا.</p></div>
				<span class="ava-woo-badge <?php echo 'yes' === $settings['enabled'] ? 'is-enabled' : ''; ?>"><?php echo 'yes' === $settings['enabled'] ? 'ارسال خودکار فعال است' : 'ارسال خودکار خاموش است'; ?></span>
			</div>
			<?php
			settings_errors( 'ava_woo_settings' );
			$notice = get_transient( 'ava_woo_notice_' . get_current_user_id() );
			if ( is_array( $notice ) && isset( $notice['message'] ) ) {
				delete_transient( 'ava_woo_notice_' . get_current_user_id() );
				echo '<div class="notice ' . ( ! empty( $notice['error'] ) ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
			}
			?>
			<nav class="nav-tab-wrapper" aria-label="بخش‌های پیامک آوا">
				<?php foreach ( array( 'settings' => 'تنظیمات و قالب‌ها', 'send' => 'ارسال آزمایشی و دستی', 'logs' => 'گزارش پیامک‌ها' ) as $key => $label ) : ?>
					<a class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ( 'logs' === $tab ) {
				self::logs();
			} elseif ( 'send' === $tab ) {
				self::send_form();
			} else {
				self::settings_form( $settings );
			}
			?>
			<p class="ava-woo-footer">آوا · طراحان شمال <a href="https://github.com/tarahanshomal/ava-WooCommerce-plugin" target="_blank" rel="noopener noreferrer">راهنما و نسخه‌ها</a></p>
		</div>
		<?php
	}

	private static function checkbox( $name, $checked, $label ) {
		?>
		<label class="ava-woo-check"><input type="checkbox" name="ava_woo_settings[<?php echo esc_attr( $name ); ?>]" value="yes" <?php checked( $checked, 'yes' ); ?>> <?php echo esc_html( $label ); ?></label>
		<?php
	}

	private static function settings_form( $s ) {
		$statuses = wc_get_order_statuses();
		self::wallet_card( $s );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( 'ava_woo' ); ?>
			<section class="ava-woo-card">
				<h2>اتصال فروشگاه به آوا</h2>
				<p class="description">از پنل آوا یک کلید با دسترسی‌های <code>sms:send</code> و <code>sms:read</code> بسازید و اینجا وارد کنید.</p>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row">ارسال خودکار</th><td><input type="hidden" name="ava_woo_settings[enabled]" value="no"><?php self::checkbox( 'enabled', $s['enabled'], 'اعلان‌های انتخاب‌شده برای سفارش‌های جدید ارسال شوند' ); ?><p class="description">پس از ذخیرهٔ اتصال و بررسی پیامک آزمایشی، این گزینه را فعال کنید.</p></td></tr>
					<tr><th scope="row"><label for="ava-api-url">نشانی پنل آوا</label></th><td><input id="ava-api-url" class="regular-text code" dir="ltr" type="url" name="ava_woo_settings[api_url]" value="<?php echo esc_attr( $s['api_url'] ); ?>" required><p class="description">نشانی HTTPS پنل، بدون مسیر <code>/api/v1</code>؛ برای پنل نمایندگی از دامنهٔ همان پنل استفاده کنید.</p></td></tr>
					<tr><th scope="row"><label for="ava-api-key">کلید API</label></th><td><input id="ava-api-key" class="regular-text code" dir="ltr" type="password" name="ava_woo_settings[api_key]" value="" autocomplete="new-password" spellcheck="false" <?php disabled( defined( 'AVA_WOO_API_KEY' ) ); ?> placeholder="<?php echo esc_attr( empty( $s['api_key'] ) ? 'ava_live_…' : 'کلید ذخیره شده؛ برای حفظ آن خالی بگذارید' ); ?>"><p class="description"><?php echo defined( 'AVA_WOO_API_KEY' ) ? 'کلید از تنظیمات سرور با ثابت AVA_WOO_API_KEY خوانده می‌شود.' : ( empty( $s['api_key'] ) ? 'هنوز کلیدی ذخیره نشده است.' : 'کلید ذخیره شده است و در این صفحه نمایش داده نمی‌شود. فیلد خالی، کلید فعلی را نگه می‌دارد.' ); ?></p><?php if ( ! empty( $s['api_key'] ) && ! defined( 'AVA_WOO_API_KEY' ) ) : ?><label><input type="checkbox" name="ava_woo_settings[delete_api_key]" value="yes"> حذف کلید ذخیره‌شده</label><?php endif; ?></td></tr>
					<tr><th scope="row"><label for="ava-sender">خط فرستنده</label></th><td><input id="ava-sender" class="regular-text code" dir="ltr" name="ava_woo_settings[sender]" value="<?php echo esc_attr( $s['sender'] ); ?>"><p class="description">شمارهٔ خط مجاز در حساب آوا؛ خالی بماند تا خط پیش‌فرض حساب استفاده شود.</p></td></tr>
					<tr><th scope="row"><label for="ava-retention">نگهداری گزارش‌ها</label></th><td><input id="ava-retention" class="small-text" type="number" min="1" max="90" name="ava_woo_settings[retention_days]" value="<?php echo esc_attr( $s['retention_days'] ); ?>"> روز<p class="description">گزارش‌ها شامل شماره و متن پیامک هستند؛ پس از این مدت به‌صورت خودکار پاک می‌شوند.</p></td></tr>
				</tbody></table>
			</section>
			<section class="ava-woo-card">
				<h2>اعلان مدیر فروشگاه</h2>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><label for="ava-admin-phones">شماره‌های مدیر</label></th><td><textarea id="ava-admin-phones" class="regular-text code" dir="ltr" name="ava_woo_settings[admin_phones]" rows="3"><?php echo esc_textarea( $s['admin_phones'] ); ?></textarea><p class="description">هر شمارهٔ موبایل ایران در یک خط؛ جداکردن با ویرگول هم پذیرفته می‌شود.</p></td></tr>
					<tr><th scope="row">سفارش تازه</th><td><input type="hidden" name="ava_woo_settings[admin_new_order]" value="no"><?php self::checkbox( 'admin_new_order', $s['admin_new_order'], 'برای سفارش تازه به مدیر پیامک ارسال شود' ); ?></td></tr>
					<tr><th scope="row"><label for="ava-admin-template">قالب پیامک مدیر</label></th><td><textarea id="ava-admin-template" class="large-text" name="ava_woo_settings[admin_template]" rows="3" maxlength="1000"><?php echo esc_textarea( $s['admin_template'] ); ?></textarea><p class="description">این قالب برای سفارش تازه و وضعیت‌های انتخاب‌شدهٔ مدیر استفاده می‌شود.</p></td></tr>
				</tbody></table>
			</section>
			<section class="ava-woo-card">
				<h2>وضعیت سفارش و متن پیامک</h2>
				<p>گیرندگان هر وضعیت را انتخاب کنید و متن پیامک مشتری را بنویسید. پیامک مدیر از قالب پیامک مدیر در بخش بالا استفاده می‌کند. متغیرها هنگام ارسال با اطلاعات سفارش جایگزین می‌شوند.</p>
				<div class="ava-woo-tokens" dir="ltr"><?php foreach ( array( 'order_number', 'order_id', 'order_status', 'order_total', 'currency', 'billing_first_name', 'billing_last_name', 'billing_phone', 'payment_method', 'shipping_method', 'site_name' ) as $token ) : ?><code><?php echo esc_html( '{' . $token . '}' ); ?></code> <?php endforeach; ?></div>
				<input type="hidden" name="ava_woo_settings[customer_statuses][]" value=""><input type="hidden" name="ava_woo_settings[admin_statuses][]" value="">
				<?php foreach ( $statuses as $status_key => $status_label ) : $status = preg_replace( '/^wc-/', '', $status_key ); ?>
					<div class="ava-woo-template">
						<div class="ava-woo-template-heading"><h3><label for="ava-template-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_label ); ?></label></h3><div>
							<label><input type="checkbox" name="ava_woo_settings[customer_statuses][]" value="<?php echo esc_attr( $status ); ?>" <?php checked( in_array( $status, $s['customer_statuses'], true ) ); ?>> مشتری</label>
							<label><input type="checkbox" name="ava_woo_settings[admin_statuses][]" value="<?php echo esc_attr( $status ); ?>" <?php checked( in_array( $status, $s['admin_statuses'], true ) ); ?>> مدیر</label>
						</div></div>
						<textarea id="ava-template-<?php echo esc_attr( $status ); ?>" class="large-text" rows="3" maxlength="1000" name="ava_woo_settings[templates][<?php echo esc_attr( $status ); ?>]" aria-label="<?php echo esc_attr( 'متن پیامک مشتری در وضعیت ' . $status_label ); ?>"><?php echo esc_textarea( isset( $s['templates'][ $status ] ) ? $s['templates'][ $status ] : '' ); ?></textarea>
					</div>
				<?php endforeach; ?>
				<p class="description">سقف متن نهایی API آوا ۱۰۰۰ نویسه است. متن طولانی ممکن است چند بخش پیامک محاسبه شود.</p>
			</section>
			<?php submit_button( 'ذخیرهٔ تنظیمات' ); ?>
		</form>
		<?php
	}

	private static function wallet_card( $s ) {
		$wallet = Api::wallet( false, $s );
		$origin = Settings::valid_url( $s['api_url'] );
		?>
		<section class="ava-woo-card ava-woo-wallet" aria-labelledby="ava-woo-wallet-title">
			<h2 id="ava-woo-wallet-title">موجودی حساب پیامکی آوا</h2>
			<?php if ( is_wp_error( $wallet ) ) : ?>
				<p class="ava-woo-callout" role="status"><?php echo esc_html( $wallet->get_error_message() ); ?></p>
			<?php else : ?>
				<p class="ava-woo-wallet-available"><span>ماندهٔ قابل مصرف</span><strong dir="ltr"><?php echo esc_html( self::rial( $wallet['available_irr'] ) ); ?></strong><span>ریال</span></p>
				<dl class="ava-woo-wallet-details"><div><dt>موجودی کل</dt><dd><?php echo esc_html( self::rial( $wallet['balance_irr'] ) ); ?> ریال</dd></div><div><dt>اعتبار رزروشده</dt><dd><?php echo esc_html( self::rial( $wallet['held_irr'] ) ); ?> ریال</dd></div></dl>
				<p class="description">آخرین دریافت: <?php echo esc_html( wp_date( 'Y/m/d H:i:s', $wallet['checked_at'] ) ); ?> · ماندهٔ قابل مصرف برابر موجودی کل منهای اعتبار رزروشده است.</p>
			<?php endif; ?>
			<div class="ava-woo-wallet-actions"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ava_woo_wallet_refresh"><?php wp_nonce_field( 'ava_woo_wallet_refresh' ); ?><button type="submit" class="button">تازه‌سازی موجودی</button></form><?php if ( $origin ) : ?><a class="button button-primary" href="<?php echo esc_url( $origin . '/panel#wallet' ); ?>" target="_blank" rel="noopener noreferrer">شارژ حساب آوا</a><?php endif; ?></div>
			<p class="description">موجودی حداکثر ۶۰ ثانیه ذخیره می‌شود. مشاهده و تازه‌سازی موجودی رایگان است.</p>
		</section>
		<?php
	}

	/** Format integer rials without converting large balances to a float. */
	private static function rial( $amount ) {
		return strtr( preg_replace( '/\B(?=(\d{3})+(?!\d))/', '٬', (string) $amount ), array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
	}

	private static function send_form() {
		$order_id = isset( $_GET['order_id'] ) && is_scalar( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( $order_id && ! ( $order instanceof \WC_Order ) ) {
			echo '<div class="notice notice-error"><p>سفارش معتبر نیست.</p></div>';
			return;
		}
		$manual = $order instanceof \WC_Order;
		?>
		<section class="ava-woo-card ava-woo-send">
			<h2><?php echo $manual ? esc_html( 'پیامک دستی سفارش #' . $order->get_order_number() ) : 'پیامک آزمایشی'; ?></h2>
			<p><?php echo $manual ? 'پیامک به شمارهٔ صورتحساب این سفارش ارسال می‌شود. هر ارسال دستی یک پیامک جداگانه است.' : 'با یک شمارهٔ متعلق به خودتان، اتصال و متن پیامک را بررسی کنید.'; ?></p>
			<p class="ava-woo-callout">این ارسال واقعی و مشمول هزینهٔ حساب آواست و مستقل از کلید ارسال خودکار انجام می‌شود.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo $manual ? 'ava_woo_manual' : 'ava_woo_test'; ?>">
				<input type="hidden" name="request_id" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>">
				<?php wp_nonce_field( $manual ? 'ava_woo_manual' : 'ava_woo_test' ); ?>
				<?php if ( $manual ) : ?>
					<input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>"><p>گیرنده: <code dir="ltr"><?php echo esc_html( self::mask( $order->get_billing_phone() ) ); ?></code> · <a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">بازگشت به سفارش</a></p>
				<?php else : ?>
					<p><label for="ava-test-phone"><strong>شمارهٔ گیرنده</strong></label></p><input id="ava-test-phone" type="tel" class="regular-text code" dir="ltr" name="phone" placeholder="09123456789" autocomplete="off" required>
				<?php endif; ?>
				<p><label for="ava-test-message"><strong>متن پیامک</strong></label></p><textarea id="ava-test-message" class="large-text" name="message" rows="5" maxlength="1000" required><?php echo esc_textarea( $manual ? '' : 'این پیامک آزمایشی از افزونهٔ ووکامرس آوا ارسال شده است.' ); ?></textarea>
				<?php if ( $manual ) : ?><p class="description">متغیرهای قالب مانند <code>{order_number}</code> در پیامک دستی نیز پذیرفته می‌شوند.</p><?php endif; ?>
				<p><label><input type="checkbox" name="confirm_cost" value="yes" required> ارسال واقعی و هزینهٔ آن را تأیید می‌کنم.</label></p>
				<?php submit_button( $manual ? 'ارسال پیامک سفارش' : 'ارسال پیامک آزمایشی' ); ?>
			</form>
		</section>
		<?php
	}

	private static function mask( $phone ) {
		$normal = Phone::normalize( $phone );
		if ( is_wp_error( $normal ) ) {
			return '••••';
		}
		return substr( $normal, 0, 4 ) . '••••' . substr( $normal, -3 );
	}

	private static function status_label( $status ) {
			$labels = array( 'pending' => 'در صف فروشگاه', 'processing' => 'در حال ثبت', 'awaiting_review' => 'منتظر تأیید محتوا', 'awaiting_dispatch' => 'در حال آماده‌سازی ارسال', 'awaiting_start' => 'منتظر شروع در آوا', 'credit_blocked' => 'منتظر افزایش اعتبار', 'waiting_capacity' => 'منتظر ظرفیت ارسال', 'awaiting_configuration' => 'منتظر اتصال پیامک', 'dispatch_blocked' => 'ارسال متوقف شده', 'paused' => 'متوقف در آوا', 'scheduled' => 'زمان‌بندی‌شده', 'sending' => 'در حال ارسال آوا', 'queued' => 'در صف آوا', 'accepted' => 'پذیرفته‌شده', 'submitted' => 'ثبت‌شده', 'sent' => 'ارسال‌شده', 'delivered' => 'تحویل‌شده', 'undelivered' => 'تحویل‌نشده', 'failed' => 'ناموفق', 'rejected' => 'ردشده', 'blocked' => 'مسدودشده', 'unknown' => 'نتیجه نامشخص', 'cancelled' => 'لغوشده', 'simulated' => 'شبیه‌سازی‌شده', 'expired' => 'منقضی‌شده', 'partial' => 'نتیجه جزئی' );
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	private static function logs() {
		global $wpdb;
		$table = Queue::table();
		$page = isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$filter = isset( $_GET['status'] ) && is_string( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
			$allowed = array( '', 'pending', 'processing', 'awaiting_review', 'awaiting_dispatch', 'awaiting_start', 'credit_blocked', 'waiting_capacity', 'awaiting_configuration', 'dispatch_blocked', 'paused', 'scheduled', 'queued', 'sending', 'accepted', 'sent', 'delivered', 'undelivered', 'failed', 'unknown', 'rejected', 'blocked', 'cancelled', 'simulated', 'expired' );
		if ( ! in_array( $filter, $allowed, true ) ) {
			$filter = '';
		}
		// The table identifier comes exclusively from wpdb's prefix and Queue::table().
		if ( $filter ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $filter ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$pages = max( 1, (int) ceil( $total / 20 ) );
		$page = min( $page, $pages );
		$offset = ( $page - 1 ) * 20;
		if ( $filter ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d", $filter, 20, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", 20, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		?>
		<section class="ava-woo-card">
			<div class="ava-woo-log-heading"><div><h2>گزارش پیامک‌ها</h2><p class="description">ثبت در صف آوا به معنی تحویل به گوشی نیست. نتیجهٔ نامشخص را پیش از ارسال دوباره بررسی کنید.</p></div><a class="button" href="<?php echo esc_url( self::url( 'logs', array( 'status' => $filter, 'paged' => $page ) ) ); ?>">تازه‌سازی فهرست</a></div>
			<form method="get" class="ava-woo-filter"><input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>"><input type="hidden" name="tab" value="logs"><label for="ava-status">وضعیت</label> <select id="ava-status" name="status"><option value="">همهٔ وضعیت‌ها</option><?php foreach ( array_filter( $allowed ) as $status ) : ?><option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filter, $status ); ?>><?php echo esc_html( self::status_label( $status ) ); ?></option><?php endforeach; ?></select> <?php submit_button( 'نمایش', 'secondary', '', false ); ?><span class="description"><?php echo esc_html( number_format_i18n( $total ) ); ?> پیامک</span></form>
			<div class="ava-woo-table-wrap"><table class="widefat striped"><thead><tr><th>شناسه / زمان UTC</th><th>سفارش / گیرنده</th><th>متن</th><th>وضعیت</th><th>جزئیات / پیگیری</th></tr></thead><tbody>
				<?php if ( ! $rows ) : ?><tr><td colspan="5" class="ava-woo-empty">هنوز پیامکی در این فهرست نیست.</td></tr><?php endif; ?>
				<?php foreach ( (array) $rows as $row ) : $order = $row->order_id ? wc_get_order( $row->order_id ) : false; ?>
					<tr>
						<td><strong>#<?php echo esc_html( $row->id ); ?></strong><br><time dir="ltr"><?php echo esc_html( $row->created_at ); ?></time></td>
						<td><?php if ( $order instanceof \WC_Order ) : ?><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><?php echo esc_html( '#' . $order->get_order_number() ); ?></a><br><?php endif; ?><code dir="ltr"><?php echo esc_html( self::mask( $row->phone ) ); ?></code><br><small><?php echo esc_html( array( 'customer' => 'مشتری', 'admin' => 'مدیر', 'test' => 'آزمایشی', 'manual' => 'دستی' )[ $row->audience ] ?? $row->audience ); ?></small></td>
						<td class="ava-woo-message"><?php echo esc_html( wp_html_excerpt( $row->message, 120, '…' ) ); ?></td>
						<td><span class="ava-woo-status <?php echo esc_attr( 'status-' . sanitize_html_class( $row->status ) ); ?>"><?php echo esc_html( self::status_label( $row->status ) ); ?></span></td>
						<td class="ava-woo-detail"><?php echo esc_html( wp_html_excerpt( (string) $row->detail, 240, '…' ) ); ?><?php if ( $row->provider_id ) : ?><br><code dir="ltr"><?php echo esc_html( $row->provider_id ); ?></code><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ava_woo_refresh"><input type="hidden" name="job_id" value="<?php echo esc_attr( $row->id ); ?>"><?php wp_nonce_field( 'ava_woo_refresh_' . $row->id ); ?><button class="button button-small" type="submit">دریافت گزارش آوا</button></form><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody></table></div>
			<?php if ( $pages > 1 ) : ?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', self::url( 'logs', array( 'status' => $filter ) ) ), 'format' => '', 'current' => $page, 'total' => $pages, 'prev_text' => 'قبلی', 'next_text' => 'بعدی' ) ) ); ?></div></div><?php endif; ?>
		</section>
		<?php
	}

	public static function metabox() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'ava-woo-order-sms', 'پیامک آوا', array( __CLASS__, 'order_metabox' ), $screen, 'side', 'default' );
		}
	}

	public static function order_metabox( $object ) {
		$order = $object instanceof \WC_Order ? $object : ( isset( $object->ID ) ? wc_get_order( $object->ID ) : false );
		if ( ! ( $order instanceof \WC_Order ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<p>پیامک دلخواه برای مشتری این سفارش ارسال کنید.</p>
		<p><code dir="ltr"><?php echo esc_html( self::mask( $order->get_billing_phone() ) ); ?></code></p>
		<a class="button" href="<?php echo esc_url( self::url( 'send', array( 'order_id' => $order->get_id() ) ) ); ?>">نوشتن پیامک سفارش</a>
		<?php
	}
}

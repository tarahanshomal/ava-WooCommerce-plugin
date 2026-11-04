=== Ava SMS for WooCommerce ===
Contributors: tarahanshomal
Tags: woocommerce, sms, notifications, persian, ava
Requires at least: 6.5
Tested up to: 7.1.4
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

پیامک سفارش ووکامرس با سرویس آوا؛ پیام مشتری و مدیر، متن قابل ویرایش، صف و گزارش ارسال.

== Description ==

افزونهٔ پیامک آوا برای ووکامرس، توسعه‌یافته توسط طراحان شمال.

* پیامک وضعیت سفارش به مشتری و مدیر.
* پیام سفارش جدید به مدیر فروشگاه.
* متن جداگانه برای وضعیت‌های سفارش با متغیرهای نام، شمارهٔ سفارش و مبلغ.
* ارسال دستی از سفارش و ارسال آزمایشی با تأیید هزینه.
* ارسال در پس‌زمینه و گزارش وضعیت؛ جلوگیری از ثبت تکراری اعلان خودکار.
* رابط فارسی، شمارهٔ ایرانی، WooCommerce CRUD و سازگاری HPOS.

اعلان سفارش تازه مربوط به تسویه‌حساب کلاسیک و بلوکی است؛ سفارش‌های مدیریت یا REST API فقط اعلان وضعیت‌های انتخاب‌شده را دریافت می‌کنند. قالب مدیر برای سفارش تازه و وضعیت‌های منتخب مشترک است.

ارسال خودکار پس از نصب غیرفعال است. برای استفاده، حساب آوا و کلید API با sms:send و sms:read لازم است. این افزونه پیامک متنی می‌فرستد و API مخصوص OTP را برای پیام وضعیت سفارش به کار نمی‌برد.

= External service: Ava SMS =

The plugin sends requests to the HTTPS Ava panel URL selected by the merchant, defaulting to https://ava.tarpardaz.com. A send request contains the recipient phone number, rendered message, optional sender, Bearer API token and idempotency key. Message templates can include customer and order fields. Report requests retrieve message status using the same service account. Requests happen when enabled order notifications, administrator-confirmed manual messages or paid test messages are processed. The merchant must have an Ava account and should review the service terms and privacy information in their Ava panel before enabling this integration.

Service: https://ava.tarpardaz.com
API: POST /api/v1/sms and GET /api/v1/sms/{id}
Local logs contain message text and recipient phone, with a default retention of 30 days. Queued and provider-accepted statuses do not mean a message was delivered.

== Installation ==

1. Install WooCommerce 8.2 or newer on WordPress 6.5 or newer, with PHP 7.4 or newer.
2. Upload the installable ava-WooCommerce-plugin release ZIP under Plugins > Add New.
3. Activate the plugin and open WooCommerce > پیامک آوا.
4. Enter the HTTPS panel URL and an API key with sms:send and sms:read.
5. Configure recipients, statuses and message text; enable automatic notifications.
6. Use the explicit paid-test form if you want to send a real test message.

== Frequently Asked Questions ==

= Does the plugin send through IPPanel? =
No. It integrates directly with Ava's public API.

= Is queued the same as delivered? =
No. HTTP 202 confirms that Ava queued the request. Check the report for later status.

= Are uncertain sends automatically repeated? =
No. A connection failure with an unknown outcome is recorded for review, without an automatic resend.

= Does this implement OTP or template-pattern notifications? =
Order messages use the text-message API. Ava's OTP API is for genuine one-time codes and is not used for order notifications.

= Why does a low-traffic shop show pending messages? =
WordPress scheduled jobs must run. Use a server cron for WordPress if visits are infrequent, and check WooCommerce scheduled actions.

= Is the API key visible after saving? =
No. Leave the field blank to keep the existing key. The key is stored in the WordPress database and is never bundled with the plugin.

== Changelog ==

= 1.0.0 =
* Initial Ava SMS integration for WooCommerce: customer/admin notifications, editable text templates, asynchronous sending, explicit manual/test sends and status reports.
* Iranian phone normalization, idempotency, permission/nonces and HPOS-compatible order access.

== Upgrade Notice ==

= 1.0.0 =
Initial release. Configure your own Ava API key and enable notifications after installation.

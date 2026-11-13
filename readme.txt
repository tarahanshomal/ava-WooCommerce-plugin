=== Ava SMS for WooCommerce ===
Contributors: tarahanshomal
Tags: woocommerce, sms, notifications, persian, order notifications
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WooCommerce order SMS through Ava with editable templates, customer and admin notifications, a background queue and delivery reports.

== Description ==

Ava SMS for WooCommerce connects your store to the Ava SMS service, developed by Tarahan Shomal. An Ava account, API key and paid SMS credit are required.

* Notify customers about selected order status changes.
* Notify store administrators about new orders and selected status changes.
* Customize Persian message templates with customer, order, amount and store placeholders.
* Send manual order messages and test messages with explicit cost confirmation.
* Process messages in the background and retrieve delivery status reports.
* Prevent duplicate automatic notifications without automatically retrying uncertain sends.
* Normalize Iranian phone numbers, including Persian and Arabic digits.
* Use WooCommerce order CRUD APIs for HPOS compatibility and support classic and block checkout order events.
* Show available, total and reserved Ava account credit in rials, with manual refresh and a link to the account wallet.
* Send immutable store/template revision metadata to supporting Ava panels for one-time review of each custom template revision.

The administration interface is primarily in Persian. Automatic order notifications are disabled by default. New-order notifications apply to classic and block checkout. Orders created through the administrator or REST API receive only the selected status notifications. The administrator template is shared by new-order and selected administrator status notifications.

= Connection diagnostics =

After saving the connection settings, use the no-charge connection check to authenticate the key and inspect both required SMS scopes and account/service readiness via GET /api/v1/connection. This does not send SMS or confirm a particular message delivery. Older Ava panels must be updated to support this endpoint. Credential errors explain how to replace expired, revoked or security-invalidated keys, including after password recovery. A safe diagnostic summary is stored for up to one day per URL/key; credential changes never automatically replay previous messages.

= Message templates =

Supported placeholders: {order_id}, {order_number}, {order_status}, {order_total}, {currency}, {billing_first_name}, {billing_last_name}, {billing_phone}, {payment_method}, {shipping_method}, and {site_name}.

Messages use Ava's text SMS API, not its OTP API. Supporting Ava panels can approve a custom template revision once for the same store. Ordinary changes to customer names, order numbers or amounts do not require a new revision; saving an edited template does. Unsupported template data follows Ava's normal message review process.

= External service: Ava SMS =

This plugin requires the Ava external service. The merchant supplies an HTTPS Ava panel URL, defaulting to https://ava.tarpardaz.com, and an API key with sms:send and sms:read access. An Ava account and paid messaging credit are required; service charges are handled by Ava.

A send request contains the recipient phone number, rendered message, optional sender, Bearer API token and idempotency key. It can also contain the original template, an independent store UUID, template revision UUID, slot, and only the order variables used by the template when safely representable. Customer or order information included in the message or template is therefore transmitted to Ava. Queued message and template data are immutable snapshots.

Send requests occur when enabled order notifications, administrator-confirmed manual messages or administrator-confirmed paid test messages are processed. Delivery report requests use the configured account to retrieve the status of messages already submitted. Opening the settings page or explicitly refreshing the balance also sends an authenticated read-only wallet request; that wallet request does not contain customer, order or message data.

Service website: https://ava.tarpardaz.com
Service information: https://ava.tarpardaz.com/services
Account registration: https://ava.tarpardaz.com/register
API: POST /api/v1/sms, GET /api/v1/sms/{id}, and GET /api/v1/wallet

Contact Ava through its service website for the applicable service terms and privacy information before enabling the integration. Merchants using a separately configured Ava panel should obtain the applicable policies from that panel's operator.

= Privacy and local storage =

The API key is stored in the WordPress options database or supplied through the AVA_WOO_API_KEY constant. It authenticates the configured Ava connection and is not displayed after saving. Local queue and log records contain recipient phone numbers, message text, order references, template metadata and delivery status. Access to settings and logs requires the manage_woocommerce capability.

Finalized local records are removed after the configured retention period: 30 days by default, configurable from 1 to 90 days. Unresolved records are retained for follow-up. Duplicate-prevention order metadata is kept separately to avoid resending old notifications. Wallet responses are cached locally for up to sixty seconds per configured URL and API key. Administrator log counts and rows may also be stored in the WordPress object cache for up to sixty seconds. Queue mutations invalidate those display snapshots; worker decisions always read the database. These local settings do not control Ava's service-side retention.

The merchant is responsible for reflecting these data flows in the store's privacy notice and obtaining any necessary customer consent.

== Installation ==

1. Install WooCommerce 8.2 or newer on WordPress 6.5 or newer, with PHP 7.4 or newer.
2. Upload the ava-sms-for-woocommerce release ZIP using Plugins > Add New > Upload Plugin, or install it from the plugin directory once available.
3. Activate the plugin and open the Ava SMS submenu under WooCommerce.
4. Enter the HTTPS Ava panel URL and your own API key with sms:send and sms:read access.
5. Configure recipients, statuses and message templates, then enable automatic notifications if required.
6. Use the explicit paid-test form only when you intend to send a real, chargeable test message.

For an existing installation in the original ava-WooCommerce-plugin directory, use the ava-WooCommerce-plugin upgrade ZIP and choose WordPress's replacement option. The ava-sms-for-woocommerce package is for fresh installations or installations already using that directory. Do not install both variants together.

== Frequently Asked Questions ==

= Is an Ava account required? =
Yes. An Ava account, API key and paid messaging credit are required. This plugin connects directly to Ava's public API.

= Does the plugin include WooCommerce or the Ava backend? =
No. WooCommerce must be installed separately. This package contains the WordPress integration code only.

= Is queued the same as delivered? =
No. HTTP 202 means Ava accepted the request into its queue. Only a delivered report confirms delivery. Content review, credit, capacity and configuration can delay processing.

= Are uncertain sends automatically repeated? =
No. An unknown connection outcome is recorded for follow-up without an automatic resend, to reduce the risk of duplicate messages and charges.

= Does this implement OTP notifications? =
No. Order messages use the text-message API. Ava's OTP API is for genuine one-time codes and is not used for order notifications.

= Why does a low-traffic shop show pending messages? =
WordPress scheduled jobs must run. Configure regular WordPress cron execution if visits are infrequent and check WooCommerce scheduled actions.

= Is the API key visible after saving? =
No. Leave the field blank to keep the existing key. You may alternatively supply the key through the AVA_WOO_API_KEY constant in wp-config.php. Credentials are not bundled with the plugin.

= What happens if I change the Ava connection? =
Queued messages admitted under a different panel URL, key or sender are cancelled instead of silently sending through a different account. Reports for previous messages require their original account connection.

= Does reading the balance send an SMS? =
No. It sends a read-only wallet request. Reading or refreshing account credit does not send an SMS.

== Changelog ==

= 1.2.3 =
* Check credentials, SMS scopes and account/service readiness without sending SMS or charging credit.
* Explain credential invalidation and replacement safely, including after password recovery.
* Preserve atomic template revisions and prevent credential rotation from replaying prior messages.

= 1.2.2 =
* Cache administrator log counts and pages for up to sixty seconds and invalidate snapshots after queue changes.
* Keep atomic worker claims, duplicate prevention and delivery-state decisions uncached.
* Document narrowly scoped coding-standard exceptions for required custom-table operations and uninstall cleanup.

= 1.2.1 =
* Prepare the WordPress.org package with a consistent plugin name, lowercase directory slug and translation domain.
* Provide an English directory readme and clarify installation, external service data handling and local log retention.
* Use WordPress identifier placeholders for custom-table queries.

= 1.2.0 =
* Snapshot store/template revision metadata and used variables with each queued message for one-time template approval on supporting Ava panels.
* Keep ordinary order/customer/amount changes on the same revision, advance on saved edits, and separate cloned sites after an origin change.
* Preserve old queued records and use ordinary review for unsupported template values.

= 1.1.0 =
* Display available, total and reserved Ava account credit in rials.
* Cache wallet reads for sixty seconds per URL/key combination, offer protected manual refresh, and show safe errors.

= 1.0.1 =
* Distinguish content review, credit, capacity and configuration waits from the active send queue.
* Continue read-only message reports after delayed approval and recover lost report actions without resending messages.
* Preserve unresolved records during retention cleanup and retain reporting metadata through additive schema migration.

= 1.0.0 =
* Initial Ava SMS integration for WooCommerce with customer and administrator notifications, editable templates, background processing and delivery reports.
* Add Iranian phone normalization, duplicate prevention, protected administrator actions and HPOS-compatible order access.

== Upgrade Notice ==

= 1.2.3 =
Add generation-based administrator log caching with mutation invalidation. Queue processing and duplicate-send protection continue to read current database state.

= 1.2.1 =
WordPress.org packaging and documentation update. Existing Ava connection settings and queued message history are preserved.

# Ava WooCommerce plugin

Standalone public WordPress/WooCommerce plugin. Never copy Ava backend code, credentials,
private docs, customer records, or server topology into this repository. Tests use
synthetic fixtures and HTTP mocks only; never send real SMS or call production APIs.
Target PHP 7.4+, WordPress 6.5+, WooCommerce 8.2+. Use WC_Order CRUD for HPOS.
All state-changing admin handlers require manage_woocommerce and a nonce.
Escape output, validate input, preserve blank API-key input without revealing keys.

## Architecture

Namespace `AvaWoo`; main file `ava-woocommerce-plugin.php`.
`Settings::get()` returns merged array; `Settings::defaults()` contains:
enabled=no, api_url=https://ava.tarpardaz.com, api_key='', sender='',
admin_phones='', admin_new_order=yes, admin_template=<Persian>, retention_days=30,
customer_statuses=[processing,completed,on-hold,cancelled,failed,refunded],
admin_statuses=[], templates=[status => message].
`Settings::sanitize($input)` is the option sanitizer; option name `ava_woo_settings`.
`Phone::normalize($phone)` returns Iranian 09xxxxxxxxx or WP_Error.
`Template::render($template, $order)` returns plain text, supporting
{order_id},{order_number},{order_status},{order_total},{currency},{billing_first_name},
{billing_last_name},{billing_phone},{payment_method},{shipping_method},{site_name}.
`Api::send($phone,$text,$idempotency_key)` returns array (id,status) or WP_Error.
`Api::report($id)` returns report array or WP_Error; uses current settings.
`Queue::enqueue($phone,$message,$event_key,$order_id=0,$audience='customer')`
returns integer job ID or WP_Error. Deduplicated event_key; sends asynchronously.
`Queue::process($id)`, `Queue::refresh($id)`; `Queue::table()` returns table name.
Table fields id, event_key, order_id, audience, phone, message, status,
provider_id, detail, created_at, updated_at. No automatic retry on uncertain sends.
`Queue::cleanup()` retention deletion.
`Notifications::manual($order_id,$message)` validates/order-checks and enqueues.
`Admin::init()` registers admin menu/forms/actions. UI owned by admin_ui agent.

Settings and API helpers must never reveal credentials in HTML or log messages.
Queue snapshots the endpoint, sender and key fingerprint. Changed settings cancel
pending jobs instead of changing an admitted request. A claimed worker that fails
to finish becomes unknown, never pending. Only provider status delivered proves
delivery. Keep the per-order duplicate markers after log retention expires.

Run `php tests/run.php`, `python3 -m unittest discover -s tests -p 'test_*.py' -v`
and PHP syntax checks before publication. Package with `python3 scripts/package.py`;
the explicit allowlist prevents development files and secrets entering the ZIP.

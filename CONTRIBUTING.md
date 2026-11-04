# Contributing

Open an issue describing the concrete behavior and expected result, or submit a focused pull request. Do not include API keys, customer records or private message contents.

The runtime targets PHP 7.4+, WordPress 6.5+ and WooCommerce 8.2+. Use WooCommerce order CRUD so changes work with HPOS. Require `manage_woocommerce` and a valid nonce for administrator writes, validate input, escape output and keep API keys out of responses and logs. Never automatically repeat an SMS send when the previous outcome is unknown.

Run these checks before submitting:

```sh
php tests/run.php
python3 -m unittest discover -s tests -p 'test_*.py' -v
python3 scripts/package.py
```

Use synthetic data and mocked HTTP for automated tests. Validate WordPress and WooCommerce behavior in a disposable local installation; do not send real messages or use production customer data in regression tests. Describe relevant manual validation in your pull request. Keep release version headers, the changelog and stable tag consistent.

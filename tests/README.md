# Running the tests

The standard regression suite needs PHP with `mbstring` and `pdo_sqlite`, plus Python 3.9 or newer. It uses synthetic orders, in-memory SQL and mocked WordPress HTTP:

```sh
php tests/run.php
python3 -m unittest discover -s tests -p 'test_*.py' -v
python3 scripts/package.py
```

`pdo_sqlite` is a test dependency. The installed plugin uses the normal WordPress database connection and does not require SQLite.

## Optional WordPress integration smoke

`integration-smoke.php` exercises the actual WordPress database, WooCommerce orders, HPOS, Action Scheduler and administrator rendering. It changes plugin settings and creates orders, queue records, metadata and scheduled actions. Use a disposable local installation that will be discarded after testing.

Install WordPress, WooCommerce and the plugin in that installation, create an administrator, and set its home URL to a synthetic hostname ending in `.invalid`, such as `https://ava-plugin-test.invalid`. In that installation's `wp-config.php`, define:

```php
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'DISABLE_WP_CRON', true );
```

Select classic order storage or HPOS in WooCommerce and complete any required synchronization before running the corresponding invocation. Substitute absolute paths for both placeholders:

```sh
AVA_WOO_RUN_INTEGRATION=1 wp --path=/absolute/disposable-wordpress eval-file /absolute/plugin-source/tests/integration-smoke.php classic
AVA_WOO_RUN_INTEGRATION=1 wp --path=/absolute/disposable-wordpress eval-file /absolute/plugin-source/tests/integration-smoke.php hpos
```

The script refuses to run without WP-CLI, the local environment, disabled WP-Cron, the `.invalid` home hostname and the explicit opt-in variable. It intercepts WordPress HTTP and email, uses generated fake API keys and never contacts Ava. These checks validate server hooks and storage; they do not drive a browser through a complete checkout.

The initial release was verified with WordPress 7.1.4, WooCommerce 11.2.1, PHP 8.5.4 and MariaDB 11.8.6, in both order storage modes. This smoke is optional and separate from the dependency-free CI suite.

<?php
/** Regression checks for display caching, invalidation and in-flight reads. */

$logs_method = new ReflectionMethod(\AvaWoo\Admin::class, 'logs');
if (PHP_VERSION_ID < 80100) { $logs_method->setAccessible(true); }
$render_logs = static function ($status = '', $page = 1) use ($logs_method) {
    $_GET = array('status' => $status, 'paged' => $page);
    ob_start();
    try { $logs_method->invoke(null); return ob_get_contents(); }
    finally { ob_end_clean(); }
};
$GLOBALS['wpdb'] = new AvaTestWpdb();
$wpdb = $GLOBALS['wpdb'];
$GLOBALS['ava_test_cache'] = array();
$GLOBALS['ava_test_orders'] = array();
reset_api(array('enabled' => 'yes'));
\AvaWoo\Queue::invalidate_log_cache();

$empty = $render_logs();
same(2, $wpdb->reads, 'Empty logs read count and rows once');
same($empty, $render_logs(), 'Empty log snapshots are reusable');
same(2, $wpdb->reads, 'Zero counts and empty rows are cache hits');

$first = \AvaWoo\Queue::enqueue('09120000000', 'cache-first-message', 'cache-first', 0, 'test');
$before = $wpdb->reads;
$html = $render_logs();
check(strpos($html, 'cache-first-message') !== false, 'Enqueue invalidates cached empty logs');
same($before + 2, $wpdb->reads, 'Enqueue forces fresh count and row reads');
$before = $wpdb->reads;
$render_logs();
same($before, $wpdb->reads, 'Unchanged log renders avoid database reads');

$GLOBALS['ava_test_responses'][] = mock_response(202, array('id' => 'cache-provider', 'status' => 'queued'));
\AvaWoo\Queue::process($first);
$html = $render_logs();
check(strpos($html, 'status-queued') !== false, 'Processing invalidates cached pending status');
\AvaWoo\Queue::process($first);
same(1, count($GLOBALS['ava_test_requests']), 'Cached admin rows cannot cause a second send');
$GLOBALS['ava_test_responses'][] = mock_response(200, array('id' => 'cache-provider', 'status' => 'delivered'));
\AvaWoo\Queue::refresh($first);
check(strpos($render_logs(), 'status-delivered') !== false, 'Fresh delivery reports invalidate display status');
check(strpos($render_logs('pending'), 'cache-first-message') === false, 'Status filter caches do not share rows');
check(strpos($render_logs('delivered'), 'cache-first-message') !== false, 'Delivered filter has its own cached rows');

// Simulate an older request finishing after a concurrent queue mutation.
$old_entries = $GLOBALS['ava_test_cache']['ava_woo_logs'];
$second = \AvaWoo\Queue::enqueue('09120000000', 'cache-second-message', 'cache-second', 0, 'test');
foreach ($old_entries as $key => $entry) {
    if ('last_changed' !== $key) { $GLOBALS['ava_test_cache']['ava_woo_logs'][$key] = $entry; }
}
check(strpos($render_logs(), 'cache-second-message') !== false, 'Late old-generation writes cannot replace current logs');

// An evicted generation must not reuse any old count or rows still in cache.
$wpdb->query('DELETE FROM wp_ava_woo_messages WHERE id=' . (int)$second);
wp_cache_delete('last_changed', 'ava_woo_logs');
check(strpos($render_logs(), 'cache-second-message') === false, 'Generation eviction creates a fresh cache namespace');

// Invalidation between a SELECT and its cache fill cannot poison the next render.
\AvaWoo\Queue::invalidate_log_cache();
$wpdb->after_results = static function () {
    \AvaWoo\Queue::enqueue('09120000000', 'cache-race-message', 'cache-race', 0, 'test');
};
$render_logs();
check(strpos($render_logs(), 'cache-race-message') !== false, 'Mutation during a cache fill is visible on the next render');

for ($i = 0; $i < 21; ++$i) {
    \AvaWoo\Queue::enqueue('09120000000', 'cache-page-message-' . $i, 'cache-page-' . $i, 0, 'test');
}
$page_one = $render_logs('', 1);
$page_two = $render_logs('', 2);
check(strpos($page_one, 'cache-page-message-20') !== false, 'Newest row appears on page one');
check(strpos($page_two, 'cache-page-message-20') === false, 'Page two cannot reuse page one rows');
$before = $wpdb->reads;
$render_logs('', 1);
$render_logs('', 2);
same($before, $wpdb->reads, 'Both pages reuse their own cached snapshots');

$wpdb->query("UPDATE wp_ava_woo_messages SET created_at='2000-01-01 00:00:00' WHERE id=" . (int)$first);
\AvaWoo\Queue::cleanup();
check(strpos($render_logs(), 'cache-first-message') === false, 'Retention deletion invalidates cached finalized records');

$wpdb->query("UPDATE wp_ava_woo_messages SET status='processing',updated_at='2000-01-01 00:00:00' WHERE message='cache-race-message'");
\AvaWoo\Queue::invalidate_log_cache();
$render_logs('processing');
\AvaWoo\Queue::housekeeping();
check(strpos($render_logs('unknown'), 'cache-race-message') !== false, 'Abandoned worker recovery invalidates filtered log caches');
check(strpos($render_logs('processing'), 'cache-race-message') === false, 'Expired claims cannot remain in cached processing lists');

\AvaWoo\Queue::invalidate_log_cache();
$wpdb->fail_next_count = true;
$failed = $render_logs();
check(strpos($failed, 'role="alert"') !== false, 'A failed count shows an accessible load error');
check(strpos($failed, 'synthetic-private-database-error') === false, 'Raw database errors are never shown');
check(strpos($failed, 'هنوز پیامکی') === false, 'A failed count is not presented as an empty log');
check(strpos($render_logs(), 'cache-page-message-20') !== false, 'A recovered count is read immediately instead of caching a false zero');

\AvaWoo\Queue::invalidate_log_cache();
$wpdb->fail_next_results = true;
$failed = $render_logs();
check(strpos($failed, 'role="alert"') !== false, 'A failed rows query shows an accessible load error');
check(strpos($failed, 'synthetic-private-database-error') === false && strpos($failed, 'هنوز پیامکی') === false, 'Failed rows expose neither database details nor a false empty list');
check(strpos($render_logs(), 'cache-page-message-20') !== false, 'Rows are refreshed immediately after a temporary query failure');
same(2, count($GLOBALS['ava_test_requests']), 'Log cache tests add no HTTP beyond the mocked send and report');
$_GET = array();

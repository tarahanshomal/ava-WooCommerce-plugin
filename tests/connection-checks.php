<?php
/** Read-only connection checks and credential-rotation regression coverage. */
use AvaWoo\Api;
use AvaWoo\Admin;
use AvaWoo\Settings;
use AvaWoo\Queue;

$connection_body = array( 'authenticated' => true, 'sms_send' => true, 'sms_read' => true, 'sms_account_ready' => true, 'sms_service_ready' => true );
reset_api();
$GLOBALS['ava_test_transients'] = array();
$GLOBALS['ava_test_responses'][] = mock_response( 200, array_merge( $connection_body, array( 'private' => 'do-not-retain' ) ) );
$connection = Api::connection();
same( 'ready', $connection['state'], 'Only a complete positive readiness response confirms the connection' );
$request = $GLOBALS['ava_test_requests'][0];
same( 'GET', $request['args']['method'], 'Connection check is read-only' );
same( 'https://ava.example.com/api/v1/connection', $request['url'], 'Connection check uses the authenticated diagnostic endpoint' );
same( 'Bearer ' . Settings::get()['api_key'], $request['args']['headers']['Authorization'], 'Connection check authenticates with the saved key' );
same( 0, $request['args']['redirection'], 'Connection diagnostics cannot redirect a credential' );
same( true, $request['args']['sslverify'], 'Connection diagnostics retain certificate validation' );
check( ! isset( $request['args']['body'], $request['args']['headers']['Idempotency-Key'] ), 'Diagnostic GET cannot carry an SMS payload or a send key' );
same( array( 'state', 'message', 'checked_at' ), array_keys( Api::connection_snapshot() ), 'Cached diagnostic contains only a safe summary' );
check( false === strpos( wp_json_encode( Api::connection_snapshot() ), 'do-not-retain' ), 'Untrusted response fields never enter the cached diagnostic' );
$before = count( $GLOBALS['ava_test_requests'] );
Api::connection_snapshot();
same( $before, count( $GLOBALS['ava_test_requests'] ), 'Reading a cached diagnosis never makes an HTTP call' );

foreach ( array( 'sms_send', 'sms_read', 'sms_account_ready', 'sms_service_ready' ) as $field ) {
    reset_api();
    $GLOBALS['ava_test_responses'][] = mock_response( 200, array_merge( $connection_body, array( $field => false ) ) );
    same( 'attention', Api::connection()['state'], 'Each missing scope or readiness gate is actionable rather than a successful connection: ' . $field );
}
foreach ( array( 'sms_send' => 'true', 'sms_read' => 1, 'authenticated' => false ) as $field => $value ) {
    reset_api();
    $GLOBALS['ava_test_responses'][] = mock_response( 200, array_merge( $connection_body, array( $field => $value ) ) );
    error_code( 'ava_connection_unavailable', Api::connection(), 'Malformed or unauthenticated diagnostics cannot confirm readiness' );
}
reset_api();
$missing = $connection_body;
unset( $missing['sms_read'] );
$GLOBALS['ava_test_responses'][] = mock_response( 200, $missing );
error_code( 'ava_connection_unavailable', Api::connection(), 'Incomplete diagnostic responses cannot confirm readiness' );

foreach ( array( 'key_version_mismatch', 'key_expired', 'key_revoked', 'account_inactive', '<script>private</script>' ) as $reason ) {
    reset_api();
    $GLOBALS['ava_test_responses'][] = mock_response( 401, array( 'auth_reason' => $reason, 'error' => 'private-upstream-secret' ) );
    $error = Api::connection();
    error_code( 'ava_connection_auth', $error, 'Unauthorized diagnostics explain the credential failure' );
    check( false === strpos( $error->get_error_message(), 'private' ), 'Credential guidance never echoes untrusted upstream text' );
    same( 'authentication_failed', Api::connection_snapshot()['state'], 'A definite 401 leaves a diagnostic for the merchant' );
    if ( 'key_version_mismatch' === $reason ) {
        check( false !== strpos( $error->get_error_message(), 'بازیابی رمز' ), 'Security-version mismatch explains password-recovery key invalidation' );
    }
}
$old_settings = Settings::get();
$GLOBALS['ava_test_options']['ava_woo_settings']['api_key'] = 'ava_live_' . str_repeat( 'z', 32 );
same( false, Api::connection_snapshot(), 'Replacing the key cannot inherit the old key diagnosis' );
check( is_array( Api::connection_snapshot( $old_settings ) ), 'Old diagnosis stays isolated to its admitted credential' );
$GLOBALS['ava_test_options']['ava_woo_settings']['api_url'] = 'https://other.example.com';
same( false, Api::connection_snapshot(), 'Endpoint changes cannot inherit another connection diagnosis' );

reset_api();
$GLOBALS['ava_test_responses'][] = mock_response( 401, array( 'auth_reason' => 'key_version_mismatch' ) );
$error = Api::send( '09120000000', 'سلام', 'auth_failure_12345678' );
same( false, $error->get_error_data()['uncertain'], 'A definite unauthorized send remains rejected, not uncertain' );
check( false !== strpos( $error->get_error_message(), 'کلید تازه' ), 'Queue send failures explain how to replace the invalid key' );
ob_start(); Admin::connection_notice(); $notice_html = ob_get_clean();
check( false !== strpos( $notice_html, 'تنظیمات پیامک آوا' ), 'Merchant sees a credential failure outside the SMS log' );
check( false === strpos( $notice_html, Settings::get()['api_key'] ), 'Credential failure notice never prints the key' );
$GLOBALS['ava_test_can_manage'] = false;
ob_start(); Admin::connection_notice(); $notice_html = ob_get_clean();
same( '', $notice_html, 'Credential diagnosis is hidden without merchant permission' );
$GLOBALS['ava_test_can_manage'] = true;
$GLOBALS['ava_test_responses'][] = mock_response( 200, array( 'id' => 'valid_report', 'status' => 'delivered' ) );
Api::report( 'valid_report' );
same( false, Api::connection_snapshot(), 'An authenticated successful report clears an obsolete authentication warning' );

foreach ( array( 403, 404, 429, 503, 302 ) as $status ) {
    reset_api();
    $GLOBALS['ava_test_responses'][] = mock_response( $status, array( 'error' => 'private-upstream-secret' ) );
    $error = Api::connection();
    error_code( 'ava_connection_unavailable', $error, 'Diagnostic HTTP failures stay explicit and do not imply readiness' );
    check( false === strpos( $error->get_error_message(), 'private' ), 'Diagnostic HTTP errors hide upstream details' );
    same( 1, count( $GLOBALS['ava_test_requests'] ), 'Diagnostic failures do not retry or create sends' );
}
reset_api( array( 'api_key' => '' ) );
error_code( 'ava_connection_configuration', Api::connection(), 'Missing saved key fails the connection check locally' );
same( 0, count( $GLOBALS['ava_test_requests'] ), 'Missing diagnostic credentials never reach the network' );

$GLOBALS['wpdb'] = new AvaTestWpdb();
$wpdb = $GLOBALS['wpdb'];
reset_api( array( 'enabled' => 'yes' ) );
$GLOBALS['ava_test_valid_nonce'] = false;
try { Admin::connection_check(); } catch ( AvaTestStop $stop ) { same( 'bad_nonce', $stop->getMessage(), 'Connection check rejects a missing or invalid nonce' ); }
same( 0, count( $GLOBALS['ava_test_requests'] ), 'Bad diagnostic nonce cannot reach the API' );
$GLOBALS['ava_test_valid_nonce'] = true;
$GLOBALS['ava_test_can_manage'] = false;
try { Admin::connection_check(); } catch ( AvaTestStop $stop ) { same( 'denied', $stop->getMessage(), 'Connection check requires merchant permission' ); }
$GLOBALS['ava_test_can_manage'] = true;
$GLOBALS['ava_test_responses'][] = mock_response( 200, $connection_body );
try { Admin::connection_check(); } catch ( AvaTestStop $stop ) { same( 'redirect', $stop->getMessage(), 'Authorized diagnosis returns to settings' ); }
same( 'ava_woo_connection', end( $GLOBALS['ava_test_nonce_checks'] ), 'Diagnostic action verifies its dedicated nonce' );
same( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_ava_woo_messages' ), 'Connection check creates no queue rows' );
same( false, $GLOBALS['ava_test_transients']['ava_woo_notice_7']['error'], 'A ready connection is shown as a successful check' );
$GLOBALS['ava_test_responses'][] = mock_response( 200, array_merge( $connection_body, array( 'sms_read' => false ) ) );
try { Admin::connection_check(); } catch ( AvaTestStop $stop ) {}
same( true, $GLOBALS['ava_test_transients']['ava_woo_notice_7']['error'], 'Incomplete permissions are shown as attention, not success' );

$unknown_job = Queue::enqueue( '09120000000', 'نتیجه نامشخص', 'rotation-unknown', 0, 'test' );
$GLOBALS['ava_test_responses'][] = new WP_Error( 'timeout', 'private transport detail' );
Queue::process( $unknown_job );
same( 'unknown', $wpdb->get_row( 'SELECT * FROM wp_ava_woo_messages WHERE id=' . $unknown_job )->status, 'A timed-out send remains uncertain' );
$GLOBALS['ava_test_options']['ava_woo_settings']['api_key'] = 'ava_live_' . str_repeat( 'z', 32 );
$request_count = count( $GLOBALS['ava_test_requests'] );
Queue::process( $unknown_job );
same( $request_count, count( $GLOBALS['ava_test_requests'] ), 'Rotating a credential cannot resend an uncertain message' );

reset_api( array( 'enabled' => 'yes' ) );
$rejected_job = Queue::enqueue( '09120000000', 'عدم پذیرش اتصال', 'rotation-rejected', 0, 'test' );
$GLOBALS['ava_test_responses'][] = mock_response( 401, array( 'auth_reason' => 'key_version_mismatch' ) );
Queue::process( $rejected_job );
same( 'failed', $wpdb->get_row( 'SELECT * FROM wp_ava_woo_messages WHERE id=' . $rejected_job )->status, 'Unauthorized sends retain a definite failure' );
$GLOBALS['ava_test_options']['ava_woo_settings']['api_key'] = 'ava_live_' . str_repeat( 'z', 32 );
Queue::process( $rejected_job );
same( 1, count( $GLOBALS['ava_test_requests'] ), 'Even a definite old failure is never silently replayed after rotation' );

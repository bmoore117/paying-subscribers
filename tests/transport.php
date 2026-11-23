<?php
// Standalone contract test; never boot WordPress or contact Stripe. Run: php tests/transport.php
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
error_reporting(E_ALL);
set_error_handler(static function ($level, $message, $file, $line) { throw new ErrorException($message, 0, $level, $file, $line); });

class WP_Error {
    private $code, $message, $data;
    function __construct($code, $message, $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
    function get_error_code() { return $this->code; }
    function get_error_data() { return $this->data; }
    function get_error_message() { return $this->message; }
}
class WP_REST_Response {
    private $data, $status;
    function __construct($data = null, $status = 200) { $this->data = $data; $this->status = $status; }
    function get_status() { return $this->status; }
    function get_data() { return $this->data; }
}
class TransportRequest {
    private $body, $header;
    function __construct($body, $header) { $this->body = $body; $this->header = $header; }
    function get_body() { return $this->body; }
    function get_header($key) { return strtolower($key) === 'stripe-signature' ? $this->header : null; }
}
function add_action($hook, $callback) { $GLOBALS['hooks'][$hook][] = $callback; }
function register_rest_route($ns, $path, $args) { $GLOBALS['routes'][$ns . $path] = $args; }
function __return_true() { return true; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function skywall_config($key) { return $GLOBALS['cfg'][$key]; }
function skywall_apply_event($event) { $GLOBALS['events'][] = $event; return $GLOBALS['apply_result']; }
function wp_remote_get($url, $args) { $GLOBALS['requests'][] = array($url, $args); return $GLOBALS['http']; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }

$cfg = array('mode' => 'test', 'link_id' => 'plink_Foo', 'price_id' => 'price_Abc', 'api_key' => 'rk_test_' . bin2hex(random_bytes(16)), 'secret' => 'whsec_' . bin2hex(random_bytes(16)));
$apply_result = true;
$hooks = $routes = $events = $requests = array();
$checks = 0;
function check($condition, $description) {
    global $checks;
    if (!$condition) { throw new RuntimeException($description); }
    $checks++;
}
function status_of($result) { return is_wp_error($result) ? ($result->get_error_data()['status'] ?? 0) : $result->get_status(); }
function signed_header($body, $time = null) {
    $t = $time ?? time();
    return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $GLOBALS['cfg']['secret']);
}
function post_raw($body, $header) { return call_user_func($GLOBALS['route']['callback'], new TransportRequest($body, $header)); }
function post_event($event) { $body = json_encode($event); return post_raw($body, signed_header($body)); }
function snapshot($type, $object) {
    return array('object' => 'event', 'id' => 'evt_Example', 'created' => 1650000000, 'livemode' => false, 'type' => $type, 'data' => array('object' => $object));
}
function http_result($code, $obj) { return array('response' => array('code' => $code), 'body' => json_encode($obj)); }
require dirname(__DIR__) . '/includes/stripe.php';
foreach ($hooks['rest_api_init'] as $callback) { $callback(); }
$route = $routes['skywall/v1/stripe-webhook'];
check($route['methods'] === 'POST' && call_user_func($route['permission_callback']) === true, 'Public, POST-only REST registration');

$checkout = array('id' => 'cs_test_123', 'object' => 'checkout.session', 'livemode' => false, 'mode' => 'subscription', 'payment_link' => 'plink_Foo', 'subscription' => 'sub_X9', 'customer' => 'cus_42', 'payment_status' => 'paid', 'customer_details' => array('email' => 'buyer@example.test'));
$base = snapshot('checkout.session.completed', $checkout);
$body = json_encode($base, JSON_PRETTY_PRINT) . "\n";
$sig = signed_header($body);
foreach (array(
    array($body, null), array($body, ''), array($body, str_replace('v1=', 'v0=', $sig)), array(rtrim($body), $sig),
    array($body, signed_header($body, time() - 305)), array($body, signed_header($body, time() + 305)),
    array($body, $sig . ',t=' . time()), array($body, 't=1e9,v1=' . str_repeat('a', 64)),
    array($body, str_replace('v1=', 'v1=0', $sig)), array('{}', signed_header('{}')), array('{', signed_header('{')),
) as list($payload, $header)) {
    check(status_of(post_raw($payload, $header)) === 400, 'Invalid request/signature rejected');
}
check(count($events) === 0 && count($requests) === 0, 'Invalid requests have no side effects');
check(status_of(post_raw(str_repeat(' ', 2097153), null)) === 413, 'Oversized request rejected before parsing');
foreach (array(-301 => false, -300 => true, 300 => true, 301 => false) as $delta => $ok) {
    check(skywall_stripe_signed($body, signed_header($body, 2000000000 + $delta), $cfg['secret'], 2000000000) === $ok, 'Exact timestamp window, offset ' . $delta);
}
// Simulates endpoint-secret rotation: valid v1 is not necessarily first.
check(status_of(post_raw($body, 'v1=' . str_repeat('1', 64) . ',v0=ignored,' . $sig)) === 200, 'Matches any valid v1 on exact raw bytes');
check(count($events) === 1 && $events[0]['type'] === $base['type'], 'Valid checkout dispatched once');
check($events[0]['data']['object'] === $checkout && $events[0]['customer_id'] === 'cus_42' && $events[0]['subscription_id'] === 'sub_X9', 'Downstream receives original checkout and canonical customer/subscription IDs');

$before = count($events);
foreach (array(array('payment_link' => 'plink_Other'), array('payment_link' => null), array('mode' => 'payment')) as $patch) {
    check(status_of(post_event(snapshot($base['type'], array_replace($checkout, $patch)))) === 200, 'Unrelated checkout acknowledged');
}
check(status_of(post_event(snapshot('invoice.paid', array('object' => 'invoice', 'id' => 'in_42', 'subscription' => null)))) === 200, 'One-off invoice ignored');
check(status_of(post_event(snapshot('payment_intent.succeeded', array('object' => 'payment_intent')))) === 200, 'Unsubscribed event type ignored');
foreach (array('account', 'context') as $account_key) {
    check(status_of(post_event($base + array($account_key => 'acct_Foreign'))) === 200, 'Ignore foreign account events: ' . $account_key);
}
check(count($events) === $before, 'Unrelated events never reached access');
foreach (array(array('livemode' => true), array('livemode' => 'false'), array('type' => array()), array('id' => 'broken')) as $patch) {
    check(status_of(post_event(array_replace($base, $patch))) === 400, 'Invalid event identity/mode rejected');
}
foreach (array(array('object' => 'invoice'), array('subscription' => null), array('subscription' => 'sub_X/other'), array('livemode' => true)) as $patch) {
    check(status_of(post_event(snapshot($base['type'], array_replace($checkout, $patch)))) === 400, 'Invalid in-scope checkout rejected');
}
check(count($events) === $before, 'Invalid in-scope events not dispatched');

$invoice = array('object' => 'invoice', 'id' => 'in_42', 'customer' => 'cus_42');
$sub_event = array('object' => 'subscription', 'id' => 'sub_X9', 'customer' => 'cus_42');
foreach (array(array('parent' => array('type' => 'subscription_details')), array('parent' => array('type' => 123)), array('subscription' => 'not-a-stripe-id')) as $patch) {
    check(status_of(post_event(snapshot('invoice.paid', $invoice + $patch))) === 400, 'Malformed subscription invoice rejected');
}
foreach (array(
    snapshot('checkout.session.async_payment_succeeded', $checkout),
    snapshot('invoice.paid', $invoice + array('parent' => array('type' => 'subscription_details', 'subscription_details' => array('subscription' => 'sub_X9')))),
    snapshot('invoice.payment_failed', $invoice + array('subscription' => 'sub_X9')),
    snapshot('customer.subscription.updated', $sub_event), snapshot('customer.subscription.deleted', $sub_event),
) as $trigger) {
    $n = count($events);
    check(status_of(post_event($trigger)) === 200 && count($events) === $n + 1, 'Supported trigger dispatched: ' . $trigger['type']);
    check(end($events)['subscription_id'] === 'sub_X9', 'Normalized subscription ID: ' . $trigger['type']);
}
check(count($requests) === 0, 'Route leaves reconciliation to locked access handler');
$apply_result = new WP_Error('db_failed', 'Do not send these internal details', array('status' => 503));
$failure = post_event($base);
check(status_of($failure) === 503 && strpos($failure->get_error_message(), 'internal details') === false, 'Access failure is retryable, details not returned');
$apply_result = new WP_Error('malformed', 'private', array('status' => 422));
check(status_of(post_event($base)) === 422, 'Preserve permanent error status from access');
$apply_result = new WP_Error('database', 'private');
check(status_of(post_event($base)) === 503, 'Access errors without a status are retryable');
$apply_result = true;
$valid_config = $cfg;
foreach (array('secret' => '', 'api_key' => 'pk_test_x', 'mode' => 'TEST', 'link_id' => 'https://example.test/x', 'price_id' => '',) as $key => $bad) {
    $cfg[$key] = $bad;
    $before = count($events);
    check(status_of(post_event($base)) === 503 && count($events) === $before, 'Bad server config must not acknowledge a supported event: ' . $key);
    $cfg = $valid_config;
}
$cfg['mode'] = 'live';
$cfg['api_key'] = 'sk_live_' . bin2hex(random_bytes(16));
$live = $base; $live['livemode'] = $live['data']['object']['livemode'] = true;
check(status_of(post_event($live)) === 200 && status_of(post_event($base)) === 400, 'Live mode accepts live, rejects test');
$cfg = $valid_config;
$cfg['api_key'] = '';
check(status_of(post_event(snapshot('payment_intent.succeeded', array()))) === 200, 'Irrelevant events do not depend on the API key');
$cfg = $valid_config;

$sub = $sub_event + array('livemode' => false, 'status' => 'active', 'items' => array('data' => array(array('price' => array('id' => 'price_Abc')))), 'latest_invoice' => $invoice + array('status' => 'paid'));
$http = http_result(200, $sub);
foreach (array('', null, array(), 'sub_', "sub_X\n", 'sub_X%2fother', 'sub_X?expand=customer') as $bad_id) {
    check(status_of(skywall_stripe_subscription($bad_id)) === 503, 'Invalid reconciliation ID fails closed');
}
$cfg['api_key'] = 'rk_live_' . bin2hex(random_bytes(16));
check(status_of(skywall_stripe_subscription('sub_X9')) === 503, 'Read key must belong to configured mode');
check(count($requests) === 0, 'No network requests for invalid inputs/config');
$cfg = $valid_config;
check(skywall_stripe_subscription('sub_X9') === $sub, 'Valid subscription data returned intact');
list($url, $args) = end($requests);
$parts = parse_url($url); parse_str($parts['query'], $query);
check($parts['scheme'] === 'https' && $parts['host'] === 'api.stripe.com' && $parts['path'] === '/v1/subscriptions/sub_X9' && $query === array('expand' => array('latest_invoice')), 'Fixed HTTPS destination and expansion');
check($args['headers']['Authorization'] === 'Bearer ' . $cfg['api_key'] && $args['headers']['Stripe-Version'] === '2024-06-20', 'Authenticated version-pinned read');
check($args['redirection'] === 0 && $args['sslverify'] === true && $args['timeout'] > 0, 'Redirects disabled, TLS verification enabled, bounded timeout');
$no_invoice = array_replace($sub, array('latest_invoice' => null));
$http = http_result(200, $no_invoice);
check(skywall_stripe_subscription('sub_X9') === $no_invoice, 'Null invoice is distinguishable from an upstream error');
foreach (array(301, 401, 403, 404, 429, 500) as $code) {
    $http = http_result($code, array('error' => array('message' => 'private error details')));
    $error = skywall_stripe_subscription('sub_X9');
    check(status_of($error) === 503 && strpos($error->get_error_message(), 'private error details') === false, 'Non-success reads never become lifecycle decisions: ' . $code);
}
foreach (array(array('object' => 'invoice'), array('id' => 'sub_Another'), array('livemode' => true), array('livemode' => 0), array('latest_invoice' => 'in_42'), array('items' => null)) as $patch) {
    $http = http_result(200, array_replace($sub, $patch));
    check(status_of(skywall_stripe_subscription('sub_X9')) === 503, 'Unusable response rejected');
}
$http = new WP_Error('timeout', 'private details');
check(status_of(skywall_stripe_subscription('sub_X9')) === 503, 'Network failure retryable');
$http = array('response' => array('code' => 200), 'body' => '<not-json>');
check(status_of(skywall_stripe_subscription('sub_X9')) === 503, 'Malformed upstream body retryable');
fwrite(STDOUT, "Transport: $checks checks passed\n");

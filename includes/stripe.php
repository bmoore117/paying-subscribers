<?php
/** Signed snapshot-event transport. Access decisions belong to skywall_apply_event(). */
defined('ABSPATH') || exit;

add_action('rest_api_init', static function () {
    register_rest_route('skywall/v1', '/stripe-webhook', array(
        'methods' => 'POST',
        'permission_callback' => '__return_true', // Stripe authenticates with a signature, not a WP session.
        'callback' => 'skywall_stripe_webhook',
    ));
});

function skywall_stripe_bad_event() {
    return new WP_Error('skywall_invalid_event', 'Invalid Stripe event.', array('status' => 400));
}

function skywall_stripe_retry() {
    // Never expose raw Stripe/HTTP/DB errors, payloads or credentials to the caller.
    return new WP_Error('skywall_temporarily_unavailable', 'Subscription processing unavailable.', array('status' => 503));
}

/** Return the exact ID or null, including for Stripe's optional expanded-object representation. */
function skywall_stripe_object_id($value, $prefix) {
    if (is_array($value)) { $value = $value['id'] ?? null; }
    return is_string($value) && strlen($value) <= 255 && preg_match('/\A' . preg_quote($prefix, '/') . '_[A-Za-z0-9]+\z/', $value) === 1 ? $value : null;
}

function skywall_stripe_valid_key($key, $mode) {
    return in_array($mode, array('test', 'live'), true) && is_string($key) && strlen($key) <= 512
        && preg_match('/\A(?:rk|sk)_' . $mode . '_[A-Za-z0-9_-]+\z/', $key) === 1;
}

/** Verify the delivery timestamp (not event.created) against unmodified request bytes. */
function skywall_stripe_signed($body, $header, $secret, $now) {
    if (!is_string($header) || strlen($header) > 8192) { return false; }
    $time = null;
    $signatures = array();
    foreach (explode(',', $header) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) !== 2) { continue; }
        if ($pair[0] === 't') {
            if ($time !== null || !preg_match('/\A[1-9][0-9]{0,9}\z/', $pair[1])) { return false; }
            $time = $pair[1];
        } elseif ($pair[0] === 'v1' && preg_match('/\A[0-9a-f]{64}\z/', $pair[1])) {
            $signatures[] = $pair[1];
        }
    }
    if ($time === null || abs($now - (int) $time) > 300 || !$signatures) { return false; }
    $expected = hash_hmac('sha256', $time . '.' . $body, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) { return true; }
    }
    return false;
}

/** Normalize the subscription identifier without trusting billing snapshots for current entitlement. */
function skywall_stripe_normalize_event($event, $mode, $link_id) {
    if (!is_array($event) || ($event['object'] ?? '') !== 'event' || !is_string($event['id'] ?? null)
        || !skywall_stripe_object_id($event['id'], 'evt') || !is_string($event['type'] ?? null) || $event['type'] === ''
        || !is_bool($event['livemode'] ?? null) || $event['livemode'] !== ($mode === 'live')) {
        return skywall_stripe_bad_event();
    }
    // This destination and its API key are for the merchant's own account, not Connect or Organizations.
    if (!empty($event['account']) || !empty($event['context'])) { return null; }
    $types = array(
        'checkout.session.completed' => 'checkout.session', 'checkout.session.async_payment_succeeded' => 'checkout.session',
        'invoice.paid' => 'invoice', 'invoice.payment_failed' => 'invoice',
        'customer.subscription.updated' => 'subscription', 'customer.subscription.deleted' => 'subscription',
    );
    $kind = $types[$event['type']] ?? null;
    if ($kind === null) { return null; }
    $object = $event['data']['object'] ?? null;
    if (!is_array($object) || ($object['object'] ?? '') !== $kind
        || (array_key_exists('livemode', $object) && $object['livemode'] !== $event['livemode'])) {
        return skywall_stripe_bad_event();
    }
    if ($kind === 'checkout.session') {
        if (($object['mode'] ?? null) !== 'subscription' || ($object['payment_link'] ?? null) === null) { return null; }
        if (!skywall_stripe_object_id($link_id, 'plink')) { return skywall_stripe_retry(); }
        if (skywall_stripe_object_id($object['payment_link'], 'plink') !== $link_id) { return null; }
        $sub = $object['subscription'] ?? null;
    } elseif ($kind === 'invoice') {
        $parent = $object['parent'] ?? null;
        if ($parent === null) {
            $sub = $object['subscription'] ?? null; // Pre-Basil webhook version.
            if ($sub === null) { return null; } // One-off invoice.
        } else {
            if (!is_array($parent) || !is_string($parent['type'] ?? null)) { return skywall_stripe_bad_event(); }
            if ($parent['type'] !== 'subscription_details') { return null; }
            $sub = $parent['subscription_details']['subscription'] ?? null;
        }
    } else {
        $sub = $object['id'] ?? null;
    }
    $sub_id = skywall_stripe_object_id($sub, 'sub');
    $customer_id = skywall_stripe_object_id($object['customer'] ?? null, 'cus');
    if ($sub_id === null || $customer_id === null) { return skywall_stripe_bad_event(); }
    // Keep signed data.object for access checks; overwrite any incoming derived keys.
    $event['subscription_id'] = $sub_id;
    $event['customer_id'] = $customer_id;
    return $event;
}

function skywall_stripe_webhook($request) {
    $mode = skywall_config('mode');
    $secret = skywall_config('secret');
    if (!in_array($mode, array('test', 'live'), true) || !is_string($secret) || strlen($secret) > 512
        || !preg_match('/\Awhsec_[\x21-\x7e]+\z/', $secret)) { return skywall_stripe_retry(); }
    $body = $request->get_body();
    if (!is_string($body) || $body === '') { return skywall_stripe_bad_event(); }
    if (strlen($body) > 2097152) { return new WP_Error('skywall_body_too_large', 'Event too large.', array('status' => 413)); }
    if (!skywall_stripe_signed($body, $request->get_header('stripe-signature'), $secret, time())) {
        return new WP_Error('skywall_invalid_signature', 'Invalid Stripe signature.', array('status' => 400));
    }
    $link_id = skywall_config('link_id');
    $event = skywall_stripe_normalize_event(json_decode($body, true, 64), $mode, $link_id);
    if (is_wp_error($event)) { return $event; }
    if ($event !== null) {
        if (!skywall_stripe_object_id($link_id, 'plink') || !skywall_stripe_object_id(skywall_config('price_id'), 'price')
            || !skywall_stripe_valid_key(skywall_config('api_key'), $mode)) { return skywall_stripe_retry(); }
        $result = skywall_apply_event($event);
        if ($result !== true) {
            if (is_wp_error($result)) {
                $error_data = $result->get_error_data();
                $status = is_array($error_data) ? ($error_data['status'] ?? null) : null;
                if (is_int($status) && $status >= 400 && $status < 500) {
                    return new WP_Error('skywall_event_rejected', 'Stripe event rejected.', array('status' => $status));
                }
            }
            return skywall_stripe_retry();
        }
    }
    return new WP_REST_Response(array('received' => true), 200);
}

/** Called inside the access module's lock; all lookup failures are retryable, never evidence of cancellation. */
function skywall_stripe_subscription($sub_id) {
    $mode = skywall_config('mode');
    $key = skywall_config('api_key');
    if (!is_string($sub_id) || !skywall_stripe_object_id($sub_id, 'sub') || !skywall_stripe_valid_key($key, $mode)) {
        return skywall_stripe_retry();
    }
    $response = wp_remote_get('https://api.stripe.com/v1/subscriptions/' . rawurlencode($sub_id) . '?expand%5B%5D=latest_invoice', array(
        'headers' => array('Authorization' => 'Bearer ' . $key, 'Stripe-Version' => '2024-06-20'),
        'timeout' => 8, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 2097152,
    ));
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) { return skywall_stripe_retry(); }
    $sub = json_decode(wp_remote_retrieve_body($response), true, 64);
    if (!is_array($sub) || ($sub['object'] ?? '') !== 'subscription' || ($sub['id'] ?? null) !== $sub_id
        || ($sub['livemode'] ?? null) !== ($mode === 'live') || !skywall_stripe_object_id($sub['customer'] ?? null, 'cus')
        || !is_string($sub['status'] ?? null) || !is_array($sub['items']['data'] ?? null) || !array_key_exists('latest_invoice', $sub)) {
        return skywall_stripe_retry();
    }
    $invoice = $sub['latest_invoice']; // Null is legitimate for subscriptions without an invoice yet.
    if ($invoice !== null && (!is_array($invoice) || ($invoice['object'] ?? '') !== 'invoice'
        || !skywall_stripe_object_id($invoice['id'] ?? null, 'in') || (array_key_exists('livemode', $invoice) && $invoice['livemode'] !== $sub['livemode']))) {
        return skywall_stripe_retry();
    }
    return $sub;
}

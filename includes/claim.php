<?php
/** First binding of a paid checkout to an existing WP account requires that account's consent. */
defined('ABSPATH') || exit;

function skywall_claim_id($sub_id, $customer, $mode) {
    return hash('sha256', $mode . ':' . $sub_id . ':' . $customer);
}

function skywall_claim_matches_email($uid, $binding) {
    $user = get_userdata($uid);
    return $user && isset($binding['email']) && is_string($binding['email']) && strcasecmp($binding['email'], $user->user_email) === 0;
}

/** Called under the access lock, AFTER independent paid-window verification; true means wait. */
function skywall_claim_required($uid, $sub_id, $customer, $mode) {
    $uid = (int) $uid;
    $key = skywall_access_key('subscription', $sub_id, $mode);
    $binding = get_option($key, false);
    if ($uid <= 0 || !in_array($mode, array('test','live'), true) || ($binding['customer'] ?? null) !== $customer || !empty($binding['user'])) {
        return skywall_stripe_retry();
    }
    if ((!empty($binding['claim_user']) && (int) $binding['claim_user'] !== $uid) || !skywall_claim_matches_email($uid, $binding)) { return true; }
    // Persist the first candidate before publishing the form. The reconciler must respect
    // this pin even if the checkout address no longer matches ANY WordPress user.
    if (empty($binding['claim_user'])) {
        $binding['claim_user'] = $uid;
        $saved = skywall_access_store($key, $binding);
        if (is_wp_error($saved)) { return $saved; }
    }
    $index = skywall_access_key('claims', $uid, $mode);
    $requests = get_option($index, array());
    $id = skywall_claim_id($sub_id, $customer, $mode);
    if (isset($requests[$id])) { return $requests[$id]['approved'] !== true; }
    $requests[$id] = array('sub'=>$sub_id, 'customer'=>$customer, 'mode'=>$mode, 'approved'=>false);
    $saved = skywall_access_store($index, $requests);
    if (is_wp_error($saved)) { return $saved; }
    // At most one dispatch attempt. Mail is informational; even if it fails, the form works.
    wp_mail($binding['email'], 'Review your SkyWall subscription request',
        'A SkyWall purchase used your email address. If you recognize this purchase, sign in normally and open My Account to confirm it. If not, ignore this request.');
    return true;
}

/** Run under the access lock, only after the entire projection succeeded. Safe on replays. */
function skywall_claim_cleanup($uid, $sub_id, $customer, $mode) {
    $index = skywall_access_key('claims', $uid, $mode);
    $requests = get_option($index, array());
    $id = skywall_claim_id($sub_id, $customer, $mode);
    if (!isset($requests[$id])) { return true; }
    $key = skywall_access_key('subscription', $sub_id, $mode);
    $binding = get_option($key, false);
    if ((int) ($binding['user'] ?? 0) !== (int) $uid || ($binding['customer'] ?? null) !== $customer) { return true; }
    unset($requests[$id]);
    if ($requests) { return skywall_access_store($index, $requests); }
    return delete_option($index) || get_option($index, null) === null ? true : skywall_stripe_retry();
}

function skywall_claim_dashboard() {
    if (!is_user_logged_in()) { return; }
    $uid = get_current_user_id();
    $mode = skywall_config('mode');
    foreach (get_option(skywall_access_key('claims', $uid, $mode), array()) as $id=>$request) {
        $binding = get_option(skywall_access_key('subscription', $request['sub'], $mode), false);
        if (!skywall_claim_can_review($uid, $request, $binding)) { continue; }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><p>Only confirm if you purchased this SkyWall subscription and want it linked to this account.</p>'
            . '<input type="hidden" name="action" value="skywall_claim"><input type="hidden" name="claim" value="' . esc_attr($id) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('skywall_claim:' . $id)) . '">'
            . '<button type="submit">' . ($request['approved'] ? 'Retry subscription check' : 'Confirm SkyWall subscription') . '</button></form>';
    }
}

function skywall_claim_can_review($uid, $request, $binding) {
    if (!$binding || $binding['customer'] !== $request['customer']) { return false; }
    if (!empty($binding['user'])) {
        // The binding may have committed before a later grant/role write failed.
        return $request['approved'] === true && (int) $binding['user'] === (int) $uid;
    }
    return (int) ($binding['claim_user'] ?? 0) === (int) $uid && skywall_claim_matches_email($uid, $binding);
}

function skywall_claim_denied() { return new WP_Error('skywall_claim_denied', 'Subscription confirmation unavailable.', array('status'=>403)); }

/** Process a signed-in POST, independently of the redirect used by admin-post. */
function skywall_claim_process() {
    $uid = get_current_user_id();
    $mode = skywall_config('mode');
    $id = $_POST['claim'] ?? null;
    $nonce = $_POST['_wpnonce'] ?? null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$uid || !is_user_logged_in() || !in_array($mode, array('test','live'), true)
        || ($_POST['action'] ?? null) !== 'skywall_claim' || !is_string($id) || !preg_match('/\A[0-9a-f]{64}\z/', $id)
        || !is_string($nonce) || !wp_verify_nonce(wp_unslash($nonce), 'skywall_claim:' . $id)) { return skywall_claim_denied(); }
    $request = skywall_access_locked(static function () use ($uid, $mode, $id) {
        $index = skywall_access_key('claims', $uid, $mode);
        $requests = get_option($index, array());
        $request = $requests[$id] ?? null;
        if (!$request || $request['mode'] !== $mode || $id !== skywall_claim_id($request['sub'], $request['customer'], $mode)
            || !skywall_claim_can_review($uid, $request, get_option(skywall_access_key('subscription', $request['sub'], $mode), false))) { return skywall_claim_denied(); }
        $requests[$id]['approved'] = true;
        $saved = skywall_access_store($index, $requests);
        return is_wp_error($saved) ? $saved : $request;
    });
    if (is_wp_error($request)) { return $request; }
    // Do NOT call this under the approval lock. Reconciliation takes the lock anew,
    // refetches Stripe and alone decides whether there is any current paid access.
    $result = skywall_apply_event(array('type'=>'customer.subscription.updated', 'livemode'=>$mode === 'live',
        'subscription_id'=>$request['sub'], 'customer_id'=>$request['customer'], 'data'=>array('object'=>array())));
    if ($result !== true) { return skywall_stripe_retry(); }
    return skywall_access_locked(static function () use ($uid, $request, $mode) {
        return skywall_claim_cleanup($uid, $request['sub'], $request['customer'], $mode);
    });
}

function skywall_claim_handle_post() {
    nocache_headers();
    $result = skywall_claim_process();
    if (is_wp_error($result)) {
        $denied = $result->get_error_code() === 'skywall_claim_denied';
        wp_die($denied ? 'Subscription confirmation unavailable.' : 'Subscription check unavailable. Return to My Account and retry.', '', array('response'=>$denied ? 403 : 503));
    }
    wp_safe_redirect(wc_get_page_permalink('myaccount'), 303);
    exit;
}

add_action('woocommerce_account_dashboard', 'skywall_claim_dashboard');
add_action('admin_post_skywall_claim', 'skywall_claim_handle_post');

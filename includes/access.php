<?php
/**
 * Local projection of a signed SkyWall checkout. No schema/install hook: per-site,
 * non-autoloaded options hold the immutable bindings and per-user paid windows.
 * One connection-scoped MySQL/MariaDB lock serializes checkout email matching,
 * all Stripe subscriptions of a user, and role expiry. Intended for a small site.
 */
defined('ABSPATH') || exit;

function skywall_access_key($kind, $id, $mode = '') {
    return 'skywall_' . $kind . '_' . hash('sha256', $mode . ':' . $id);
}

/** WordPress returns false for both an unchanged option and a failed write. */
function skywall_access_store($key, $value) {
    if (get_option($key, null) === $value || update_option($key, $value, false)) { return true; }
    return get_option($key, null) === $value ? true : skywall_stripe_retry();
}

function skywall_access_locked($callback) {
    global $wpdb;
    $name = 'skywall:' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix), 0, 48);
    if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)', $name))) {
        return skywall_stripe_retry(); // Never steal an expired transient from a worker still in Stripe I/O.
    }
    try { return $callback(); }
    finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)); }
}

/** Download authorization is local, price/mode-scoped and expires even if Stripe/WP-Cron is down. */
function skywall_has_stripe_access($user_id) {
    $id = (int) $user_id;
    $mode = skywall_config('mode');
    $price = skywall_config('price_id');
    if ($id <= 0 || !in_array($mode, array('test','live'), true) || !$price) { return false; }
    $grants = get_option(skywall_access_key('grants', $id, $mode), array());
    foreach ($grants as $grant) {
        if ($grant['price'] === $price && $grant['until'] > time()) { return true; }
    }
    return false;
}

function skywall_access_woo_paid($id) {
    return get_user_meta($id, '_skywall_woo_paid', true) === 'yes'
        || (function_exists('wcs_user_has_subscription') && wcs_user_has_subscription($id, '', 'active'));
}

/** Keep the shared role additive. Remove only a role supplied by a known billing source. */
function skywall_access_sync_role($id) {
    $user = get_userdata($id);
    if (!$user) { return true; } // A deleted account's binding is a tombstone; never rematch its email.
    $role = 'paying-subscriber';
    $has = in_array($role, $user->roles, true);
    $owner_key = skywall_access_key('role', $id);
    if (skywall_has_stripe_access($id)) {
        // If Woo supplied the role first, track Stripe's interest too: Woo can end
        // while Stripe is active and deliberately leave cleanup to this projection.
        if (!$has || skywall_access_woo_paid($id)) {
            // A scalar boolean is stringified by wp_options on a later PHP request.
            $result = skywall_access_store($owner_key, 'yes');
            if (is_wp_error($result)) { return $result; }
        }
        if (!$has) {
            $user->add_role($role);
            $fresh = get_userdata($id);
            if (!$fresh || !in_array($role, $fresh->roles, true)) { return skywall_stripe_retry(); }
        }
    } elseif (get_option($owner_key, '') === 'yes') {
        if ($has && !skywall_access_woo_paid($id)) {
            if (array_values($user->roles) === array($role)) { $user->add_role('subscriber'); }
            $user->remove_role($role);
            $fresh = get_userdata($id);
            if (!$fresh || in_array($role, $fresh->roles, true)) { return skywall_stripe_retry(); }
        }
        return skywall_access_store($owner_key, 'no');
    }
    return true;
}

/** Each term schedules one local role recheck; the timestamp in args distinguishes renewals. */
function skywall_access_schedule($id, $mode, $at) {
    $args = array($id, $mode, $at);
    if (wp_next_scheduled('skywall_expire_paid_window', $args)) { return true; }
    $r = wp_schedule_single_event($at, 'skywall_expire_paid_window', $args, true);
    return $r === true || wp_next_scheduled('skywall_expire_paid_window', $args) ? true : skywall_stripe_retry();
}

add_action('skywall_expire_paid_window', 'skywall_expire_paid_window', 10, 3);
function skywall_expire_paid_window($id, $mode, $at) {
    if ($mode !== skywall_config('mode')) { return; } // Sandbox and production must use different user databases.
    $result = skywall_access_locked(static function () use ($id) { return skywall_access_sync_role($id); });
    if (is_wp_error($result)) { skywall_access_schedule($id, $mode, time() + 60); }
}

/** A paid invoice for the configured recurring item, not an invoice total from another product.
 * Both current and pre-Basil Stripe layouts are accepted. A paid term is never inferred
 * from a trial, active status alone, a free invoice, a credit/proration, or invoice.period_end.
 */
function skywall_paid_window($sub, $customer, $sub_id, $price, $previous) {
    if (!in_array($sub['status'], array('active','past_due','trialing','incomplete','incomplete_expired','canceled','unpaid','paused'), true)) { return skywall_stripe_retry(); }
    // Cancellation stops renewal, not a term already paid for. Never infer new time from a canceled snapshot.
    if ($sub['status'] === 'canceled') { return $previous; }
    if (!in_array($sub['status'], array('active','past_due'), true) || !empty($sub['pause_collection'])) { return 0; }
    $item_ends = array();
    foreach ($sub['items']['data'] as $item) {
        $terms = $item['price'] ?? null;
        $item_price = skywall_stripe_object_id($terms, 'price');
        if (!$item_price) { return skywall_stripe_retry(); }
        if ($item_price !== $price || ($item['quantity'] ?? 1) <= 0) { continue; }
        // An operator can configure a real Price ID for the wrong offer. Verify the expanded item's terms.
        if (!is_array($terms) || ($terms['currency'] ?? null) !== 'usd' || ($terms['unit_amount'] ?? null) !== 1500
            || ($terms['type'] ?? null) !== 'recurring' || ($terms['recurring']['interval'] ?? null) !== 'month'
            || ($terms['recurring']['interval_count'] ?? null) !== 1) { continue; }
        $item_id = skywall_stripe_object_id($item['id'] ?? null, 'si');
        $end = (int) ($item['current_period_end'] ?? $sub['current_period_end'] ?? 0);
        if (!$item_id || $end <= 0) { return skywall_stripe_retry(); }
        $item_ends[$item_id] = $end;
    }
    if (!$item_ends) { return !empty($sub['items']['has_more']) ? skywall_stripe_retry() : 0; }
    $limit = max($item_ends);

    if (!empty($sub['cancel_at'])) { $limit = min($limit, (int) $sub['cancel_at']); }
    // A changed Stripe schedule bounds *new* time only. A shorter cancel_at or
    // current_period_end is not evidence that the earlier paid term was refunded.
    $until = $previous;
    $inv = $sub['latest_invoice'];
    // past_due: no new time; grace is only the remainder of a previously proven paid term.
    if ($sub['status'] === 'past_due' || !is_array($inv) || ($inv['status'] ?? '') !== 'paid' || ($inv['amount_paid'] ?? 0) <= 0) { return $until; }
    $inv_sub = $inv['parent']['subscription_details']['subscription'] ?? $inv['subscription'] ?? null;
    if (skywall_stripe_object_id($inv_sub, 'sub') !== $sub_id || skywall_stripe_object_id($inv['customer'] ?? null, 'cus') !== $customer) { return skywall_stripe_retry(); }
    if (!is_array($inv['lines']['data'] ?? null)) { return skywall_stripe_retry(); }
    $matched = false;
    foreach ($inv['lines']['data'] as $line) {
        $details = $line['parent']['subscription_item_details'] ?? array();
        $item_id = skywall_stripe_object_id($details['subscription_item'] ?? $line['subscription_item'] ?? null, 'si');
        $line_price = $line['pricing']['price_details']['price'] ?? $line['price'] ?? null;
        if (!isset($item_ends[$item_id]) || skywall_stripe_object_id($line_price, 'price') !== $price
            || ($line['amount'] ?? 0) <= 0 || !empty($details['proration']) || !empty($line['proration'])) { continue; }
        $discount = 0;
        foreach ($line['discount_amounts'] ?? array() as $part) { $discount += (int) ($part['amount'] ?? 0); }
        if ($discount >= $line['amount']) { continue; } // Another line can make an otherwise-free item look paid.
        $start = (int) ($line['period']['start'] ?? 0);
        $end = (int) ($line['period']['end'] ?? 0);
        if (!$start || $end <= $start) { return skywall_stripe_retry(); }
        $matched = true;
        if ($start <= time()) { $until = max($until, min($end, $item_ends[$item_id], $limit)); }
    }
    if (!$matched && !empty($inv['lines']['has_more'])) { return skywall_stripe_retry(); } // Unusual multi-item offer; manual review rather than guessing.
    return $until;
}

/** Entry point from verified transport: only normalized subscription/customer IDs are accepted.
 * Every retry asks Stripe for current truth INSIDE the lock; event timestamps/order aren't state.
 * Unbound invoices/updates are ignored; a later checkout independently reads the latest invoice.
 */
function skywall_apply_event(array $event) {
    $mode = skywall_config('mode');
    $price = skywall_config('price_id');
    $sub_id = $event['subscription_id'] ?? null;
    $customer = $event['customer_id'] ?? null;
    if (!skywall_stripe_object_id($sub_id, 'sub') || !skywall_stripe_object_id($customer, 'cus')
        || !in_array($mode, array('test','live'), true) || ($event['livemode'] ?? null) !== ($mode === 'live') || !$price) { return skywall_stripe_retry(); }
    $checkout = in_array($event['type'], array('checkout.session.completed','checkout.session.async_payment_succeeded'), true);
    $obj = $event['data']['object'];
    if ($checkout && (($obj['status'] ?? '') !== 'complete' || ($obj['mode'] ?? '') !== 'subscription'
        || skywall_stripe_object_id($obj['payment_link'] ?? null, 'plink') !== skywall_config('link_id'))) { return true; }
    return skywall_access_locked(static function () use ($mode, $price, $sub_id, $customer, $checkout, $obj) {
        $key = skywall_access_key('subscription', $sub_id, $mode);
        $binding = get_option($key, false);
        if (!$binding && !$checkout) { return true; }
        if ($binding && $binding['customer'] !== $customer) { return skywall_stripe_retry(); }
        $sub = skywall_stripe_subscription($sub_id);
        if (is_wp_error($sub)) { return $sub; }
        if ($sub['id'] !== $sub_id || skywall_stripe_object_id($sub['customer'], 'cus') !== $customer || $sub['livemode'] !== ($mode === 'live')) { return skywall_stripe_retry(); }
        if (!$binding) {
            $email = $obj['customer_details']['email'] ?? $obj['customer_email'] ?? '';
            if (!is_string($email) || !is_email($email)) { return skywall_stripe_retry(); }
            // Preserve the first signed checkout email even for delayed initial payment. Never take invoice email.
            $binding = array('customer'=>$customer, 'user'=>0, 'email'=>$email);
            $result = skywall_access_store($key, $binding);
            if (is_wp_error($result)) { return $result; }
        }
        $customer_key = skywall_access_key('customer', $customer, $mode);
        $known_user = (int) get_option($customer_key, 0);
        if (!empty($binding['claim_user'])) {
            $pinned = (int) $binding['claim_user'];
            if (($known_user && $known_user !== $pinned) || !skywall_claim_matches_email($pinned, $binding)) { return true; }
            // An outstanding claim must pass its own confirmation, including when a second subscription
            // established the customer mapping in the meantime. Never rematch a recycled email address.
            $claim = skywall_claim_required($pinned, $sub_id, $customer, $mode);
            if ($claim !== false) { return $claim; }
        }
        $id = $binding['user'] ?: $known_user;
        if ($known_user && $id !== $known_user) { return skywall_stripe_retry(); }
        if ($id && !get_userdata($id)) { return true; }
        $grants = $id ? get_option(skywall_access_key('grants', $id, $mode), array()) : array();
        $previous = $grants[$sub_id] ?? null;
        $until = skywall_paid_window($sub, $customer, $sub_id, $price, $previous && $previous['price'] === $price ? $previous['until'] : 0);
        if (is_wp_error($until)) { return $until; }
        if (!$binding['user'] && $until <= time()) { return true; } // Pending mappings alone confer nothing.
        $welcome_key = skywall_access_key('welcome', $sub_id, $mode);
        if (!$id) {
            $user = get_user_by('email', $binding['email']);
            if ($user) {
                // An email match is not proof of account ownership. Only our own unfinished signup bypasses the claim.
                if (get_user_meta($user->ID, $welcome_key, true) !== 'pending') {
                    $claim = skywall_claim_required($user->ID, $sub_id, $customer, $mode);
                    if ($claim !== false) { return $claim; } // true = awaiting claim; WP_Error = retry.
                }
                $id = $user->ID;
            } else {
                $id = wp_insert_user(array('user_login'=>'skywall-' . wp_generate_password(24, false),
                    'user_email'=>$binding['email'], 'user_pass'=>wp_generate_password(64, true, true), 'role'=>'subscriber',
                    'meta_input'=>array($welcome_key=>'pending')));
                if (is_wp_error($id)) { return $id; }
            }
        }
        // Persist both identity bindings before access or mail. On replay, never rematch a bound email.
        if (!$known_user) {
            $result = skywall_access_store($customer_key, (string) $id);
            if (is_wp_error($result)) { return $result; }
        }
        if (!$binding['user']) {
            $binding['user'] = $id;
            unset($binding['email'], $binding['claim_user']); // No ongoing copy of the checkout email.
            $result = skywall_access_store($key, $binding);
            if (is_wp_error($result)) { return $result; }
        }
        if ($until > time()) { $grants[$sub_id] = array('price'=>$price,'until'=>$until); }
        else { unset($grants[$sub_id]); }
        $result = skywall_access_store(skywall_access_key('grants', $id, $mode), $grants);
        if (is_wp_error($result)) { return $result; }
        if ($until > time()) {
            $result = skywall_access_schedule($id, $mode, $until);
            if (is_wp_error($result)) { return $result; }
        }
        $result = skywall_access_sync_role($id);
        if (is_wp_error($result)) { return $result; }
        if ($until > time() && get_user_meta($id, $welcome_key, true) === 'pending') {
            // At-most-once attempt: WP mail isn't transactional. If dispatch fails or the worker
            // dies after this claim, the buyer can request a normal WordPress password-reset link.
            if (!delete_user_meta($id, $welcome_key, 'pending')) { return skywall_stripe_retry(); }
            wp_new_user_notification($id, null, 'user');
        }
        return skywall_claim_cleanup($id, $sub_id, $customer, $mode);
    });
}

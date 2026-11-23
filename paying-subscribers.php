<?php
/**
 * Plugin Name: Paying Subscribers
 * Description: Grants SkyWall access after a verified Stripe subscription purchase. Keeps existing Woo subscription support.
 * Version: 1.0.1
 * Requires PHP: 7.4
 */
defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/stripe.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/downloads.php';
require_once __DIR__ . '/includes/claim.php';

// Keep WordPress's native set-password URL; add the store destination for these purchasers only.
add_filter('wp_new_user_notification_email', function ($email, $user) {
    if (skywall_has_stripe_access($user->ID) && function_exists('wc_get_page_permalink')) {
        $account = wc_get_page_permalink('myaccount');
        if ($account) { $email['message'] .= "\nAfter setting your password, sign in to get your SkyWall installer or Android setup instructions:\n" . $account . "\n"; }
    }
    return $email;
}, 10, 2);

function skywall_register_paid_role() {
    add_role('paying-subscriber', 'Paying Subscriber', array('read' => true, 'level_0' => true));
}
register_activation_hook(__FILE__, 'skywall_register_paid_role');
add_action('init', 'skywall_register_paid_role');

/** Never use set_role() on an existing account: paid access is an additional role. */
function skywall_woo_status_changed($subscription, $new_status, $old_status) {
    if (!is_object($subscription) || !is_callable(array($subscription, 'get_user_id'))) {
        return;
    }
    $id = (int) $subscription->get_user_id();
    $user = $id > 0 ? get_userdata($id) : false;
    if (!$user) {
        return;
    }
    if ('active' === $new_status) {
        update_user_meta($id, '_skywall_woo_paid', 'yes');
        $user->add_role('paying-subscriber');
    } elseif (in_array($new_status, array('expired', 'cancelled', 'on-hold'), true)) {
        if (function_exists('wcs_user_has_subscription') && wcs_user_has_subscription($id, '', 'active')) {
            return;
        }
        delete_user_meta($id, '_skywall_woo_paid');
        if (!skywall_has_stripe_access($id)) {
            // Users of the old plugin may have no underlying role to fall back on.
            if (array('paying-subscriber') === array_values($user->roles)) {
                $user->add_role('subscriber');
            }
            $user->remove_role('paying-subscriber');
        }
    }
}
add_action('woocommerce_subscription_status_updated', 'skywall_woo_status_changed', 100, 3);

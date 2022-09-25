<?php
/*
 * Plugin Name: Paying Subscribers
 * Description: Creates a new role called paying-subscriber, and automatically toggles users between subscriber and paying-subscriber based on their woocommerce subscription status
 */

function create_paying_subscriber_role() {
    // same permissions as the subscriber role, just with a different moniker to indicate revenue
    add_role(
        'paying-subscriber',
        'Paying Subscriber',
        array(
            'read' => true,
            'level_0' => true,
        )
    );
}
add_action('wp_loaded', 'create_paying_subscriber_role');

function toggle_roles($subscription, $new_status, $old_status) {
    // Get an instance of the customer WP_User Object
    $order = wc_get_order($subscription->get_parent_id());
    $user = $order->get_user();

    // Check that it's not a guest customer
    if (is_a($user, 'WP_User') && $user->ID > 0) {
        if ($new_status == 'active') {
            if (in_array('subscriber', $user->roles)) {
                $user->remove_role('subscriber');
            }
            $user->set_role('paying-subscriber');
        } else if ($new_status == 'cancelled' || $new_status == 'on-hold' || $new_status == 'expired') {
            if (in_array('paying-subscriber', $user->roles)) {
                $user->remove_role('paying-subscriber');
            }
            $user->set_role('subscriber');
        }
    }
}
//Toggle roles based on subscription status - https://woocommerce.com/document/subscriptions/develop/action-reference/#section-3
add_action('woocommerce_subscription_status_updated', 'toggle_roles', 100, 3);

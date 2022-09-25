# Paying Subscribers
This plugin marks users who have active woocommerce subscriptions with an explicit role to that effect.

When users start a paid subscription, their role is changed to paying-subscriber, which is a clone of subscriber as far as permissions go. When their subscription is cancelled, put on-hold, or expired, their role is changed back to subscriber. That's it really.

## Installing
1. Download the plugin into your plugins directory
2. Enable in the WordPress admin

## Using
This plugin does what it does with a minimum of fuss, and should require no further configuration, however it is based on the assumption that you have woocommerce configured.

Additionally, if uninstalled, it will not remove the paying-subscriber role, anyone who has it will still have it.
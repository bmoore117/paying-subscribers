<?php
defined('ABSPATH') || exit;

/** Constants override site-specific, non-autoloaded settings. Never expose secrets via REST or HTML. */
function skywall_config($key) {
    $keys = array('mode','link_id','price_id','api_key','secret','product_id');
    if (!in_array($key, $keys, true)) { return ''; }
    $constant = 'SKYWALL_STRIPE_' . strtoupper($key);
    $opts = get_option('skywall_stripe_settings', array());
    $value = defined($constant) ? constant($constant) : ($opts[$key] ?? ('product_id' === $key ? '45' : ''));
    if (!is_string($value) && !is_int($value)) { return ''; }
    if ('mode' === $key && $value === 'test' && wp_get_environment_type() === 'production' && !(defined('SKYWALL_ALLOW_TEST_WEBHOOKS') && SKYWALL_ALLOW_TEST_WEBHOOKS === true)) {
        return ''; // A sandbox checkout must never create live app access.
    }
    return (string) $value;
}
function skywall_settings_fields() {
    return array('link_id'=>'Payment Link ID (plink_…, not the buy.stripe.com URL)', 'price_id'=>'Monthly price ID (price_…)', 'api_key'=>'Stripe read key (restricted recommended; read subscriptions and invoices)', 'secret'=>'Webhook endpoint signing secret (whsec_…)', 'product_id'=>'WooCommerce downloadable product ID');
}
add_action('admin_menu', function () {
    add_options_page('SkyWall billing', 'SkyWall billing', 'manage_options', 'skywall-billing', 'skywall_render_settings');
});
function skywall_render_settings() {
    if (!current_user_can('manage_options')) { return; }
    $opts = get_option('skywall_stripe_settings', array());
    $mode = $opts['mode'] ?? '';
    if (defined('SKYWALL_STRIPE_MODE')) { $mode = SKYWALL_STRIPE_MODE; }
    echo '<div class="wrap"><h1>SkyWall billing</h1><p>Stripe webhook destination (POST): <code>' . esc_html(rest_url('skywall/v1/stripe-webhook')) . '</code></p>';
    if (isset($_GET['updated'])) { echo '<div class="notice notice-success"><p>Settings saved.</p></div>'; }
    echo '<form method="post" autocomplete="off" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="skywall_save_settings">';
    wp_nonce_field('skywall_save_settings');
    echo '<p><label>Webhook mode <select name="mode" ' . disabled(defined('SKYWALL_STRIPE_MODE'), true, false) . '>';
    foreach (array(''=>'Off','test'=>'Sandbox — isolated staging only','live'=>'Live') as $value=>$label) {
        echo '<option value="' . esc_attr($value) . '" ' . selected($mode, $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label></p>';
    if ($mode === 'test' && skywall_config('mode') !== 'test') { echo '<p><strong>Test webhooks are blocked on this production environment.</strong> Use an isolated staging site.</p>'; }
    foreach (skywall_settings_fields() as $key=>$label) {
        $locked = defined('SKYWALL_STRIPE_' . strtoupper($key));
        $private = in_array($key, array('api_key','secret'), true);
        $value = skywall_config($key);
        echo '<p><label>' . esc_html($label) . '<br><input class="regular-text" type="' . ($private ? 'password' : 'text') . '" name="' . esc_attr($key) . '" autocomplete="off" value="' . ($private ? '' : esc_attr($value)) . '" ' . disabled($locked,true,false) . '></label> ';
        echo esc_html($locked ? 'Managed by configuration constant.' : ($private && $value !== '' ? 'Stored; leave blank to keep.' : '')) . '</p>';
    }
    echo '<fieldset><legend>Installer downloads</legend><label><input type="checkbox" name="storage_checked" value="yes" ' . checked(skywall_download_protection_confirmed(), true, false) . ' ' . disabled(defined('SKYWALL_WOO_DOWNLOADS_PROTECTED'),true,false) . '> I verified that the raw installer file in woocommerce_uploads cannot be downloaded anonymously from the origin or CDN.</label><p>Leave unchecked if unsure. This setting does not change server permissions. External URLs and ordinary Media Library uploads are not supported.</p></fieldset>';
    submit_button(); echo '</form></div>';
}
add_action('admin_post_skywall_save_settings', function () {
    if (!current_user_can('manage_options')) { wp_die('Forbidden', '', array('response'=>403)); }
    check_admin_referer('skywall_save_settings');
    $opts = get_option('skywall_stripe_settings', array());
    foreach (array_merge(array('mode'=>''), skywall_settings_fields()) as $key=>$label) {
        if (defined('SKYWALL_STRIPE_' . strtoupper($key))) { continue; }
        $value = isset($_POST[$key]) && is_string($_POST[$key]) ? trim(wp_unslash($_POST[$key])) : '';
        if (strlen($value) > 512 || preg_match('/[\x00-\x20\x7f]/', $value)) { wp_die('Invalid SkyWall setting.'); }
        if ($key === 'mode' && !in_array($value, array('','test','live'), true)) { wp_die('Invalid mode.'); }
        if ($value === '' && in_array($key, array('api_key','secret'), true)) { continue; }
        if ($value !== '' && isset(array('link_id'=>1,'price_id'=>1,'product_id'=>1)[$key])) {
            $patterns = array('link_id'=>'/^plink_[a-zA-Z0-9]+$/D','price_id'=>'/^price_[a-zA-Z0-9]+$/D','product_id'=>'/^[1-9][0-9]*$/D');
            if (!preg_match($patterns[$key],$value)) { wp_die('Expected a Stripe ID or positive product ID, not a public link.'); }
        }
        $opts[$key] = $value;
    }
    update_option('skywall_stripe_settings', $opts, false);
    if (!defined('SKYWALL_WOO_DOWNLOADS_PROTECTED')) {
        update_option('skywall_protected_storage', ($_POST['storage_checked'] ?? '') === 'yes' ? skywall_storage_stamp() : '', false);
    }
    wp_safe_redirect(add_query_arg(array('page'=>'skywall-billing','updated'=>'1'),admin_url('options-general.php'))); exit;
});

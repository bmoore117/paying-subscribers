<?php
/** Run ONLY in an expendable WordPress + WooCommerce DB: wp eval-file tests/wp-integration.php */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local' || wp_parse_url(home_url(), PHP_URL_HOST) !== 'skywall.test' || getenv('SKYWALL_DISPOSABLE_TEST_DB') !== '1') {
    throw new RuntimeException('This mutating integration fixture runs only on an explicitly disposable local skywall.test installation.');
}
function sw_check($ok, $name) { if (!$ok) { throw new RuntimeException($name); } WP_CLI::log('PASS ' . $name); }
$seed = bin2hex(random_bytes(6));
$mail = 'buyer-' . $seed . '@example.invalid'; $customer = 'cus_' . $seed; $sub = 'sub_' . $seed;
$link = 'plink_Fixture'; $price = 'price_Fixture'; $secret = 'whsec_localfixture';
// No external network, email, credentials, checkout, or payment is involved.
$mails = array();
add_filter('pre_wp_mail', function ($discard, $msg) use (&$mails) { $mails[] = $msg; return true; }, 10, 2);
$product = new WC_Product_Simple(); $product->set_name('SkyWall test installer'); $product->set_virtual(true); $product->set_downloadable(true); $product->set_regular_price('15'); $product->set_status('publish');
$up = wp_upload_dir(); $folder = $up['basedir'] . '/woocommerce_uploads'; wp_mkdir_p($folder); file_put_contents($folder . '/skywall-fixture.zip', 'dummy installer bytes');
$allowed = wc_get_container()->get(\Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register::class);
$fixture_url = $up['baseurl'] . '/woocommerce_uploads/';
$rule = $allowed->add_approved_directory($fixture_url, true);
$allowed->update_approved_directory($rule, $fixture_url, true);
// Simulate the operator's attestation for this disposable local storage only.
if (!defined('SKYWALL_WOO_DOWNLOADS_PROTECTED')) {
    update_option('skywall_protected_storage','',false);
    sw_check(!skywall_download_protection_confirmed(),'unverified storage blocked');
    update_option('skywall_protected_storage',skywall_storage_stamp(),false);
    sw_check(skywall_download_protection_confirmed(),'protection confirmation scoped to local site and storage');
}
$file = new WC_Product_Download(); $file->set_id('test-file'); $file->set_name('SkyWall fixture'); $file->set_file($up['baseurl'] . '/woocommerce_uploads/skywall-fixture.zip'); $product->set_downloads(array($file)); $pid = $product->save();
update_option('skywall_stripe_settings', array('mode'=>'test','link_id'=>$link,'price_id'=>$price,'api_key'=>'rk_test_onlyfixture','secret'=>$secret,'product_id'=>(string)$pid), false);
$now=time(); $end=$now+2592000; $inv='in_'.$seed;
$item = array('id'=>'si_'.$seed,'price'=>array('id'=>$price,'type'=>'recurring','currency'=>'usd','unit_amount'=>1500,'recurring'=>array('interval'=>'month','interval_count'=>1)),'quantity'=>1,'current_period_start'=>$now,'current_period_end'=>$end);
$invoice=array('id'=>$inv,'object'=>'invoice','livemode'=>false,'status'=>'paid','paid'=>true,'amount_paid'=>1500,'currency'=>'usd','customer'=>$customer,'parent'=>array('type'=>'subscription_details','subscription_details'=>array('subscription'=>$sub)),'period_start'=>$now,'period_end'=>$end);
$invoice['lines'] = array('has_more'=>false, 'data'=>array(array('id'=>'il_'.$seed,'subscription_item'=>$item['id'],'price'=>$item['price'],'amount'=>1500,'proration'=>false,'period'=>array('start'=>$now,'end'=>$end))));
$snapshot=array('id'=>$sub,'object'=>'subscription','livemode'=>false,'customer'=>$customer,'status'=>'active','cancel_at_period_end'=>false,'current_period_start'=>$now,'current_period_end'=>$end,'items'=>array('data'=>array($item),'has_more'=>false),'latest_invoice'=>$invoice);
add_filter('pre_http_request', function ($pre,$args,$url) use (&$snapshot,&$sub) {
    if (wp_parse_url($url,PHP_URL_HOST)==='api.stripe.com' && wp_parse_url($url,PHP_URL_PATH)==='/v1/subscriptions/'.$sub) {
        return array('response'=>array('code'=>200,'message'=>'OK'),'headers'=>array(),'body'=>wp_json_encode($snapshot),'cookies'=>array());
    }
    return new WP_Error('unexpected_http','Outbound HTTP disabled by local fixture');
},10,3);
$endpoint='/skywall/v1/stripe-webhook';
$post=function ($id,$type,$object,$key=null,$live=false) use ($secret,$endpoint) {
    $payload=wp_json_encode(array('id'=>$id,'object'=>'event','created'=>time(),'type'=>$type,'livemode'=>$live,'data'=>array('object'=>$object))); $t=time();
    $r=new WP_REST_Request('POST',$endpoint); $r->set_header('Content-Type','application/json'); $r->set_header('Stripe-Signature','t='.$t.',v1='.hash_hmac('sha256',$t.'.'.$payload,$key??$secret)); $r->set_body($payload); return rest_do_request($r);
};
$checkout=array('id'=>'cs_test_'.$seed,'object'=>'checkout.session','status'=>'complete','mode'=>'subscription','livemode'=>false,'payment_status'=>'paid','payment_link'=>$link,'customer'=>$customer,'subscription'=>$sub,'customer_details'=>array('email'=>$mail),'amount_total'=>1500,'currency'=>'usd');
$eid='evt_'.$seed.'a'; $ct='checkout.session.completed';
$r=$post($eid,$ct,$checkout,'wrong-secret'); sw_check($r->get_status()>=400 && !email_exists($mail),'bad signature produces no account');
$other=$checkout; $other['payment_link']='plink_other'; $post('evt_'.$seed.'b',$ct,$other); sw_check(!email_exists($mail),'other payment link ignored');
$post('evt_'.$seed.'c','invoice.paid',$invoice); sw_check(!email_exists($mail),'invoice arriving first does not guess the account');
$r=$post($eid,$ct,$checkout); if($r->get_status()>=300){WP_CLI::log(wp_json_encode($r->get_data()));} sw_check($r->get_status()<300,'verified checkout accepted');
$id=(int) email_exists($mail); sw_check($id>0 && in_array('paying-subscriber',get_userdata($id)->roles,true),'new WordPress account has app role');
$setup=array_values(array_filter($mails,function($msg) use($mail){return in_array($mail,(array)$msg['to'],true)&&strpos($msg['message'],'action=rp')!==false;}));
sw_check(count($setup)===1,'one set-password email to subscriber (captured locally)'); $count=count($mails);
$post($eid,$ct,$checkout); $post('evt_'.$seed.'d',$ct,$checkout); sw_check(count($mails)===$count,'replayed checkout sends no second email');
preg_match('~https?://[^\s<>]+action=rp[^\s<>]*~',$setup[0]['message'],$found);
parse_str(wp_parse_url(html_entity_decode($found[0] ?? ''),PHP_URL_QUERY) ?: '',$setup_args);
$recipient=check_password_reset_key($setup_args['key'] ?? '', $setup_args['login'] ?? '');
sw_check($recipient instanceof WP_User && $recipient->ID===$id,'native set-password link belongs to the intended buyer');
$password=wp_generate_password(35,true); reset_password($recipient,$password);
$signed_in=wp_authenticate($mail,$password);
sw_check($signed_in instanceof WP_User && $signed_in->ID===$id && !user_can($signed_in,'manage_options'), 'buyer can sign in with the newly chosen password, without admin access');
wp_set_current_user($id); $user=get_userdata($id);
sw_check(skywall_has_stripe_access($id),'entitled user has installer access');
$short=do_shortcode('[skywall_downloads]'); sw_check(strpos($short,'test-file')!==false || strpos($short,'SkyWall fixture')!==false,'subscriber receives My Account installer link');
$user->add_role('editor'); // Verify that later entitlement removal preserves unrelated roles.
// Model a paid renewal for an additional term; re-read the actual extended grant from the database.
$end += 2592000;
$snapshot['items']['data'][0]['current_period_end'] = $snapshot['current_period_end'] = $end;
$snapshot['latest_invoice']['id'] = 'in_renewal'.$seed;
$snapshot['latest_invoice']['lines']['data'][0]['period']['end'] = $end;
$snapshot['latest_invoice']['lines']['data'][0]['period']['start'] = $now;
$renewal=$post('evt_'.$seed.'renew','invoice.paid',$snapshot['latest_invoice']);
$grant_key=skywall_access_key('grants',$id,'test'); $grants=get_option($grant_key,array());
sw_check($renewal->get_status()===200 && ($grants[$sub]['until'] ?? 0)===$end,'paid renewal extends the stored entitlement without a second checkout');
$snapshot['cancel_at_period_end']=true; $post('evt_'.$seed.'e','customer.subscription.updated',$snapshot);
sw_check(skywall_has_stripe_access($id),'cancellation scheduled for period end preserves access');
$snapshot['status']='canceled'; $post('evt_'.$seed.'f','customer.subscription.deleted',$snapshot); $post('evt_'.$seed.'g','invoice.paid',$invoice);
$grant_key = skywall_access_key('grants',$id,'test'); $grants=get_option($grant_key,array());
sw_check(($grants[$sub]['until'] ?? 0) === $end && skywall_has_stripe_access($id), 'confirmed prepaid time survives cancellation and stale invoice');
// Advance only the stored fixture window, then exercise the actual expiry handler; do not move the system clock.
$grants[$sub]['until'] = time()-1; update_option($grant_key,$grants,false);
do_action('skywall_expire_paid_window',$id,'test',$end);
$post('evt_'.$seed.'h','invoice.paid',$invoice);
$effective=get_userdata($id)->roles;
sw_check(!in_array('paying-subscriber',$effective,true) && in_array('editor',$effective,true),'ended subscription removes paid role only, stale invoice cannot regrant');
sw_check(!skywall_has_stripe_access($id),'former subscriber loses installer access');
wp_set_current_user(0); sw_check(strpos(do_shortcode('[skywall_downloads]'),'test-file')===false,'no guest installer link');
// A first checkout using an existing site's account needs that account's consent, not just an email match.
$existing_mail='existing-'.$seed.'@example.invalid'; $existing_password=wp_generate_password(32,true);
$existing_id=wp_insert_user(array('user_login'=>'existing-'.$seed,'user_email'=>$existing_mail,'user_pass'=>$existing_password,'role'=>'editor'));
$customer.='existing'; $sub.='existing'; $snapshot['customer']=$customer; $snapshot['id']=$sub; $snapshot['status']='active'; $snapshot['cancel_at_period_end']=false;
$snapshot['latest_invoice']['customer']=$customer; $snapshot['latest_invoice']['parent']['subscription_details']['subscription']=$sub;
$checkout['customer_details']['email']=$existing_mail; $checkout['customer']=$customer; $checkout['subscription']=$sub;
$post('evt_'.$seed.'claim',$ct,$checkout);
sw_check(!skywall_has_stripe_access($existing_id) && !in_array('paying-subscriber',get_userdata($existing_id)->roles,true),'first email match waits for account holder confirmation');
wp_set_current_user($existing_id); ob_start(); skywall_claim_dashboard(); $form=ob_get_clean();
preg_match_all('/name="([^"]+)" value="([^"]+)"/',$form,$fields,PREG_SET_ORDER); $_POST=array(); foreach($fields as $field) { $_POST[$field[1]]=html_entity_decode($field[2]); }
$_SERVER['REQUEST_METHOD']='POST'; sw_check(skywall_claim_process()===true && skywall_has_stripe_access($existing_id),'signed-in owner confirms and receives the paid entitlement');
sw_check(wp_authenticate($existing_mail,$existing_password) instanceof WP_User && in_array('editor',get_userdata($existing_id)->roles,true),'existing password and original role are preserved');
sw_check(is_wp_error(skywall_claim_process()),'completed claim cannot be submitted a second time');
WP_CLI::success('Local WordPress/WooCommerce integration completed; Stripe HTTP and email delivery were simulated.');

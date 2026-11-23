<?php
/** Standalone component checks; no WordPress install, network, mail or credentials. */
if (PHP_SAPI !== 'cli' || defined('ABSPATH')) { exit(1); }
define('ABSPATH', __DIR__); define('DB_NAME', 'testdb');
class WP_Error { public $code; function __construct($code, $message = '', $data = null) { $this->code = $code; } }
function is_wp_error($v) { return $v instanceof WP_Error; }
class AccessDb {
    public $prefix = 'wp_', $held = false, $busy = false;
    function prepare($q, ...$args) { return $q; }
    function get_var($q) {
        if (strpos($q, 'RELEASE_LOCK') !== false) { $this->held = false; return '1'; }
        if (strpos($q, 'GET_LOCK') !== false && !$this->busy && !$this->held) { $this->held = true; return '1'; }
        return '0';
    }
}
$wpdb = new AccessDb(); $cfg = array('mode'=>'test','price_id'=>'price_A','link_id'=>'plink_A','api_key'=>'rk_test_onlyfixture');
$options = $users = $meta = $mail = $cron = $snapshots = $wc = array(); $reads = 0; $fail_option = null;
function skywall_config($k) { return $GLOBALS['cfg'][$k]; }
function skywall_claim_cleanup($uid,$sub,$customer,$mode) { return true; } // Real seam exercised by wp-integration.php.
function get_option($k, $default = false) { return $GLOBALS['options'][$k] ?? $default; }
function update_option($k, $v, $autoload = null) {
    if ($GLOBALS['fail_option'] && strpos($k, $GLOBALS['fail_option']) !== false) { return false; }
    if (get_option($k) === $v) { return false; } $GLOBALS['options'][$k] = $v; return true;
}
function add_action(...$args) {}
function wp_next_scheduled($hook, $args) { return $GLOBALS['cron'][$hook.serialize($args)] ?? false; }
function wp_schedule_single_event($at, $hook, $args, $error = false) { $GLOBALS['cron'][$hook.serialize($args)] = $at; return true; }
function is_email($s) { return is_string($s) && filter_var($s, FILTER_VALIDATE_EMAIL) ? $s : false; }
function wp_generate_password($n, $special = true, $extra = false) { return substr(bin2hex(random_bytes($n)), 0, $n); }
function get_user_meta($id, $key, $single) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_user_meta($id, $key, $v) { $GLOBALS['meta'][$id][$key] = $v; return true; }
function delete_user_meta($id, $key, $prev = '') {
    if (get_user_meta($id,$key,true) === '' || ($prev !== '' && get_user_meta($id,$key,true) !== $prev)) { return false; }
    unset($GLOBALS['meta'][$id][$key]); return true;
}
function get_userdata($id) { return isset($GLOBALS['users'][$id]) ? clone $GLOBALS['users'][$id] : false; }
function get_user_by($field, $value) {
    $prop = 'user_'.$field;
    foreach ($GLOBALS['users'] as $u) { if (strcasecmp($u->$prop, $value) === 0) { return clone $u; } } return false;
}
class AccessUser {
    public $roles = array(), $ID, $user_email, $user_pass, $user_login;
    function add_role($r) { if (!in_array($r, $this->roles, true)) { $this->roles[] = $r; $GLOBALS['users'][$this->ID] = clone $this; } }
    function remove_role($r) { $this->roles = array_values(array_diff($this->roles, array($r))); $GLOBALS['users'][$this->ID] = clone $this; }
}
function wp_insert_user($fields) {
    if (get_user_by('email', $fields['user_email']) || get_user_by('login', $fields['user_login'])) { return new WP_Error('duplicate'); }
    $u = new AccessUser(); $u->ID = $GLOBALS['next_user_id'] = ($GLOBALS['next_user_id'] ?? 0) + 1;
    foreach (array('user_login','user_email','user_pass') as $k) { $u->$k = $fields[$k]; }
    $u->roles[] = $fields['role']; $GLOBALS['users'][$u->ID] = $u;
    foreach ($fields['meta_input'] ?? array() as $k=>$v) { update_user_meta($u->ID, $k, $v); } return $u->ID;
}
function wp_new_user_notification($id, $unused = null, $who = '') { check($unused === null && $who === 'user', 'user-only notification'); $GLOBALS['mail'][] = $id; }
function wcs_user_has_subscription($id, $product, $status) { return !empty($GLOBALS['wc'][$id]) && 'active' === $status; }
$claim_calls = $claim_results = array();
function skywall_claim_required($id, $sub, $customer, $mode) {
    $GLOBALS['claim_calls'][] = array($id,$sub,$customer,$mode);
    return $GLOBALS['claim_results'][$sub] ?? true; // Unapproved unless explicitly set to false.
}
function wp_remote_get($url, $args) {
    check($GLOBALS['wpdb']->held, 'fetch must run inside lock'); ++$GLOBALS['reads'];
    $sub = basename(parse_url($url,PHP_URL_PATH)); if (!isset($GLOBALS['snapshots'][$sub])) { return new WP_Error('stripe_io'); }
    return array('body'=>json_encode($GLOBALS['snapshots'][$sub]));
}
function wp_remote_retrieve_response_code($r) { return 200; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
require dirname(__DIR__) . '/includes/stripe.php'; // Exercise the same signed-event normalization contract as REST.
require dirname(__DIR__) . '/includes/access.php';
function event($type, $obj) {
    $obj['object'] = strpos($type, 'checkout.') === 0 ? 'checkout.session' : (strpos($type, 'invoice.') === 0 ? 'invoice' : 'subscription');
    return skywall_stripe_normalize_event(array('object'=>'event','type'=>$type,'livemode'=>false,'id'=>'evt_Fixture','data'=>array('object'=>$obj)), 'test','plink_A');
}
function send_event($type, $obj) { $r = skywall_apply_event(event($type,$obj)); check(true === $r,'unexpected failure: '.$type.' '.(is_wp_error($r) ? $r->code : '')); }
function snapshot($sub, $cus, $price = 'price_A', $end = null) {
    $end = $end ?? time()+86400;
    $terms = array('object'=>'price','id'=>$price,'currency'=>'usd','unit_amount'=>1500,'type'=>'recurring','recurring'=>array('interval'=>'month','interval_count'=>1));
    $item = array('id'=>'si_Fixture','price'=>$terms,'quantity'=>1,'current_period_end'=>$end);
    $line = array('amount'=>1500,'parent'=>array('type'=>'subscription_item_details','subscription_item_details'=>array('subscription'=>$sub,'subscription_item'=>'si_Fixture','proration'=>false)), 'pricing'=>array('price_details'=>array('price'=>$price)),'period'=>array('start'=>$end-2592000,'end'=>$end));
    $inv = array('id'=>'in_Fixture','object'=>'invoice','status'=>'paid','amount_paid'=>1500,'customer'=>$cus,'livemode'=>false,'parent'=>array('type'=>'subscription_details','subscription_details'=>array('subscription'=>$sub)),'lines'=>array('data'=>array($line), 'has_more'=>false));
    return array('object'=>'subscription','id'=>$sub,'customer'=>$cus,'status'=>'active','livemode'=>false,'items'=>array('data'=>array($item),'has_more'=>false),'latest_invoice'=>$inv);
}
function checkout($sub, $cus, $email) { return array('id'=>'cs_test_Fixture','status'=>'complete','mode'=>'subscription','payment_status'=>'paid','payment_link'=>'plink_A','subscription'=>$sub,'customer'=>$cus,'customer_details'=>array('email'=>$email)); }
function updated($sub) { send_event('customer.subscription.updated', array('id'=>$sub,'customer'=>$GLOBALS['snapshots'][$sub]['customer'],'status'=>'active')); }
function paid_role($id) { return in_array('paying-subscriber', get_userdata($id)->roles, true); }
$co = checkout('sub_A','cus_A','alice@example.invalid'); $type='checkout.session.completed';
$snapshots['sub_A'] = snapshot('sub_A','cus_A'); $inv = $snapshots['sub_A']['latest_invoice'];
send_event('invoice.paid',$inv); check(!$users && !$reads,'ignore invoice preceding checkout (no lookup by invoice email)');
$wpdb->busy = true; check(is_wp_error(skywall_apply_event(event($type,$co))) && !$users && !$reads, 'contending writer retries without side effects'); $wpdb->busy = false;
$initial = $snapshots['sub_A']; unset($snapshots['sub_A']); check(is_wp_error(skywall_apply_event(event($type,$co))) && !$wpdb->held && !$users,'failed lookup releases lock for retry');
$snapshots['sub_A'] = $initial; $snapshots['sub_A']['status'] = 'canceled'; send_event($type,$co); send_event('invoice.paid',$inv); check(!$users && !$mail && !$cron,'canceled first snapshot cannot start access, even with a paid invoice');
$snapshots['sub_A']['status'] = 'incomplete'; send_event($type,$co); check(!$users && !$mail,'no user before first settled payment');
$terms = $initial['items']['data'][0]['price'];
foreach (array('unexpanded'=>'price_A', 'ID only'=>array('id'=>'price_A'), 'currency'=>array_replace($terms,array('currency'=>'eur')),
    'zero price'=>array_replace($terms,array('unit_amount'=>0)), 'wrong amount'=>array_replace($terms,array('unit_amount'=>100)),
    'non-integer amount'=>array_replace($terms,array('unit_amount'=>'1500')), 'one-time price'=>array_replace($terms,array('type'=>'one_time')),
    'annual'=>array_replace_recursive($terms,array('recurring'=>array('interval'=>'year'))),
    'two months'=>array_replace_recursive($terms,array('recurring'=>array('interval_count'=>2))),
    'no interval count'=>array_replace($terms,array('recurring'=>array('interval'=>'month')))) as $case=>$bad_price) {
    $snapshots['sub_A']=$initial; $snapshots['sub_A']['items']['data'][0]['price']=$bad_price; updated('sub_A');
    check(!$users && !$mail && !$cron,'configured price ID with invalid terms cannot start access: '.$case);
}
$snapshots['sub_A'] = $initial; $snapshots['sub_A']['latest_invoice']['amount_paid'] = 0; updated('sub_A'); check(!$users,'a free invoice cannot start access');
$snapshots['sub_A']['latest_invoice']['amount_paid'] = 1500; $snapshots['sub_A']['latest_invoice']['lines']['data'][0]['pricing']['price_details']['price'] = 'price_OTHER'; updated('sub_A'); check(!$users,'a paid invoice for another price is not proof');
$snapshots['sub_A']=$initial; $snapshots['sub_A']['latest_invoice']['lines']['data'][0]['discount_amounts']=array(array('amount'=>1500)); updated('sub_A'); check(!$users,'fully discounted target with positive aggregate invoice cannot start access');
$snapshots['sub_A']=$initial; $snapshots['sub_A']['customer']='cus_Different'; check(is_wp_error(skywall_apply_event(event($type,$co))) && !$users,'current customer must match signed checkout');
$snapshots['sub_A']=$initial; send_event('invoice.paid',$inv); $alice = get_user_by('email','alice@example.invalid'); check($alice && $alice->roles === array('subscriber','paying-subscriber') && strlen($alice->user_pass)>=32 && $mail===array($alice->ID),'later paid invoice finishes pending checkout exactly once');
$id=$alice->ID; check(skywall_has_stripe_access($id) && !skywall_has_stripe_access(0) && !skywall_has_stripe_access(123456),'download gate');
check(count($cron)===1,'one role expiration scheduled on first grant');
$cfg['mode']='live'; check(!skywall_has_stripe_access($id), 'test grant cannot authorize live download'); $cfg['mode']='test';
$cfg['price_id']='price_OTHER'; check(!skywall_has_stripe_access($id),'price-specific download gate'); $cfg['price_id']='price_A';
$users[$id]->user_email = 'alice-new@example.invalid'; $co['customer_details']['email'] = 'different@example.invalid'; send_event($type,$co); send_event('checkout.session.async_payment_succeeded',$co);
check(count($users)===1 && $mail===array($id) && $users[$id]->user_email==='alice-new@example.invalid' && $users[$id]->user_pass===$alice->user_pass,'replay never rebinds, renames or resets');
$reads_before=$reads; send_event('invoice.paid',array('subscription'=>'sub_Other','customer'=>'cus_A')); check($reads===$reads_before,'another subscription of same customer cannot grant or revoke');
$snapshots['sub_A']['status']='past_due'; $snapshots['sub_A']['latest_invoice']['status']='open'; updated('sub_A'); check(skywall_has_stripe_access($id),'past_due retains already-paid time only');
$snapshots['sub_A'] = $initial; $snapshots['sub_A']['cancel_at_period_end']=true; updated('sub_A'); check(paid_role($id),'scheduled cancellation retains paid term');
$gkey=skywall_access_key('grants',$id,'test'); $prepaid=get_option($gkey)['sub_A']['until'];
$snapshots['sub_A']=snapshot('sub_A','cus_A','price_A',$prepaid+86400); $snapshots['sub_A']['status']='canceled';
$snapshots['sub_A']['cancel_at']=time()-1; send_event('customer.subscription.deleted',array('id'=>'sub_A','customer'=>'cus_A')); send_event('invoice.paid',$inv);
check(skywall_has_stripe_access($id) && paid_role($id) && get_option($gkey)['sub_A']['until']===$prepaid && count($cron)===1,'cancellation and stale paid replay neither erase nor extend the original paid term');
$snapshots['sub_B']=snapshot('sub_B','cus_A'); send_event($type,checkout('sub_B','cus_A','different@example.invalid')); check(count($users)===1,'same customer, different subscription and email still uses original user');
$options[$gkey]['sub_A']['until']=time()-1; updated('sub_A'); check(!isset(get_option($gkey)['sub_A']) && paid_role($id) && skywall_has_stripe_access($id),'expired canceled term cannot restart; another paid subscription preserves access');
$users[$id]->add_role('editor'); $snapshots['sub_B'] = snapshot('sub_B','cus_A','price_OTHER'); updated('sub_B'); send_event('invoice.paid',$inv);
check(!paid_role($id) && !skywall_has_stripe_access($id) && in_array('editor',$users[$id]->roles,true) && in_array('subscriber',$users[$id]->roles,true),'wrong price after canceled term expires revokes only managed role; stale paid event cannot restore');
$snapshots['sub_A']=$initial; $oldinv=$inv; unset($oldinv['parent']); $oldinv['subscription']='sub_A'; send_event('invoice.paid',$oldinv); check(paid_role($id),'legacy invoice.subscription reconciles');
// Exercise actual shape of the pinned pre-Basil read API as well as current webhook layouts.
$old=snapshot('sub_A','cus_A','price_A',time()+172800); $item=$old['items']['data'][0]; $old['current_period_end']=$item['current_period_end']; unset($old['items']['data'][0]['current_period_end']);
$old['latest_invoice']['subscription']='sub_A'; unset($old['latest_invoice']['parent']);
$line=&$old['latest_invoice']['lines']['data'][0]; $line['price']=array('id'=>'price_A'); $line['subscription_item']=$item['id']; $line['proration']=false; unset($line['pricing'],$line['parent'],$line);
$snapshots['sub_A']=$old; send_event('invoice.paid',$oldinv); $gkey=skywall_access_key('grants',$id,'test'); check(get_option($gkey)['sub_A']['until']===$old['current_period_end'],'pinned legacy invoice line extends paid window on renewal');
$options[$gkey]['sub_A']['until']=time()-1; check(!skywall_has_stripe_access($id) && paid_role($id),'download gate expires without Stripe, cron or another webhook'); skywall_expire_paid_window($id,'test',time()-1); check(!paid_role($id),'local expiry job removes stale role');
$snapshots['sub_A']=$initial; updated('sub_A');
$stored=get_option($gkey)['sub_A']['until']; $snapshots['sub_A']['cancel_at']=time()+3600;
$snapshots['sub_A']['items']['data'][0]['current_period_end']=$snapshots['sub_A']['cancel_at']; updated('sub_A');
check(get_option($gkey)['sub_A']['until']===$stored,'active schedule change cannot shorten the already paid term');
$snapshots['sub_A']['status']='canceled'; updated('sub_A'); check(get_option($gkey)['sub_A']['until']===$stored && paid_role($id),'deletion after active schedule change does not reduce original paid time');
foreach (array('manual','woo','wcs') as $i=>$source) {
    $customer='cus_'.$source; $sub='sub_'.$source; $email=$source.'@example.invalid'; $uid=wp_insert_user(array('user_login'=>$source,'user_email'=>$email,'user_pass'=>'unchanged','role'=>'editor'));
    if ($source==='manual') { $users[$uid]->add_role('paying-subscriber'); }
    $snapshots[$sub]=snapshot($sub,$customer); $existing=checkout($sub,$customer,$email); send_event($type,$existing);
    check(end($claim_calls)===array($uid,$sub,$customer,'test') && !skywall_has_stripe_access($uid) && paid_role($uid)===($source==='manual')
        && get_option(skywall_access_key('customer',$customer,'test'))===false && get_option(skywall_access_key('subscription',$sub,'test'))['user']===0,'waiting claim does not bind or change roles: '.$source);
    $claim_results[$sub]=new WP_Error('claim_io'); check(skywall_apply_event(event($type,$existing))===$claim_results[$sub] && !skywall_has_stripe_access($uid),'claim error propagated without access');
    $claim_results[$sub]=false; updated($sub);
    if ($source==='woo') { update_user_meta($uid,'_skywall_woo_paid','yes'); }
    if ($source==='wcs') { $wc[$uid]=true; }
    check(skywall_has_stripe_access($uid) && $users[$uid]->user_pass==='unchanged' && $mail===array($id),'existing account has no welcome or password change');
    $snapshots[$sub]['status']='unpaid'; updated($sub); check(paid_role($uid) && !skywall_has_stripe_access($uid), 'preserve non-Stripe role: '.$source);
    $snapshots[$sub]['status']='active'; updated($sub);
    $snapshots[$sub]['status']='canceled'; updated($sub); check(paid_role($uid) && skywall_has_stripe_access($uid),'canceled term remains prepaid: '.$source);
    $options[skywall_access_key('grants',$uid,'test')][$sub]['until']=time()-1; skywall_expire_paid_window($uid,'test',time()-1);
    check(paid_role($uid) && !skywall_has_stripe_access($uid) && in_array('editor',$users[$uid]->roles,true), 'expiry preserves non-Stripe roles: '.$source);
}
$snapshots['sub_C']=snapshot('sub_C','cus_C'); $new = checkout('sub_C','cus_C','carol@example.invalid'); $fail_option='customer'; $claims_before=count($claim_calls);
check(is_wp_error(skywall_apply_event(event($type,$new))), 'persistence failure is retryable'); $fail_option=null;
send_event($type,$new); send_event($type,$new); $carol = get_user_by('email','carol@example.invalid'); check($carol && $mail===array($id,$carol->ID) && paid_role($carol->ID) && count($claim_calls)===$claims_before,'retry after partial new-user write bypasses claim and sends one welcome');
unset($users[$carol->ID]); send_event($type,$new); check(!get_user_by('email','carol@example.invalid') && count($mail)===2,'deleted bound account never reacquired by checkout email');
$oldid=wp_insert_user(array('user_login'=>'legacy','user_email'=>'legacy@example.invalid','user_pass'=>'existing','role'=>'paying-subscriber'));
update_user_meta($oldid,'_skywall_woo_paid','yes'); $snapshots['sub_legacy']=snapshot('sub_legacy','cus_legacy'); $claim_results['sub_legacy']=false; send_event($type,checkout('sub_legacy','cus_legacy','legacy@example.invalid'));
delete_user_meta($oldid,'_skywall_woo_paid'); check(paid_role($oldid) && skywall_has_stripe_access($oldid),'Woo can hand off a legacy role to active Stripe');
$snapshots['sub_legacy']['status']='canceled'; updated('sub_legacy'); check(paid_role($oldid) && skywall_has_stripe_access($oldid),'legacy role handoff survives cancellation through the paid term');
$options[skywall_access_key('grants',$oldid,'test')]['sub_legacy']['until']=time()-1; skywall_expire_paid_window($oldid,'test',time()-1);
check(!skywall_has_stripe_access($oldid) && $users[$oldid]->roles===array('subscriber'),'legacy role handoff ends when the prepaid term expires without Woo');
fwrite(STDOUT,"PASS: Stripe account lifecycle, paid windows, ownership, ordering, retry and idempotency.\n");

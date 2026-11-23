<?php
/** Local confirmation component checks. No WordPress installation, mail delivery or HTTP. */
if (PHP_SAPI !== 'cli' || defined('ABSPATH')) { exit(1); }
define('ABSPATH', __DIR__);
class WP_Error { public $code; function __construct($code, $msg = '', $data = null) { $this->code = $code; } }
function is_wp_error($r) { return $r instanceof WP_Error; }
function check($ok, $label) { if (!$ok) { throw new RuntimeException($label); } }
$options = $mail = $events = array(); $locked = $http_fail = $unpaid = $fail_write = false; $uid = 0; $mode = 'test';
$users = array(1=>(object)array('ID'=>1,'user_email'=>'Buyer@example.invalid'), 2=>(object)array('ID'=>2,'user_email'=>'other@example.invalid'));
function add_action(...$args) {}
function get_userdata($id) { return $GLOBALS['users'][$id] ?? false; }
function get_current_user_id() { return $GLOBALS['uid']; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function skywall_config($key) { return $GLOBALS['mode']; }
function skywall_access_key($kind,$id,$mode='') { return $kind.':'.$mode.':'.$id; }
function skywall_access_store($key,$value) { if ($GLOBALS['fail_write']) { return skywall_stripe_retry(); } $GLOBALS['options'][$key]=$value; return true; }
function get_option($key,$default=false) { return $GLOBALS['options'][$key] ?? $default; }
function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
function skywall_access_locked($callback) { check(!$GLOBALS['locked'],'must not nest account locks'); $GLOBALS['locked']=true; try { return $callback(); } finally { $GLOBALS['locked']=false; } }
function wp_mail($to,$subject,$message) { $GLOBALS['mail'][]=array($to,$subject,$message); return true; }
function wp_create_nonce($action) { return hash('sha256',get_current_user_id().':'.$action); }
function wp_verify_nonce($nonce,$action) { return wp_create_nonce($action) === $nonce; }
function wp_unslash($v) { return $v; }
function admin_url($path) { return 'https://example.invalid/wp-admin/'.$path; }
function esc_url($v) { return htmlspecialchars($v, ENT_QUOTES); }
function esc_attr($v) { return htmlspecialchars($v, ENT_QUOTES); }
function skywall_apply_event(array $event) {
    check(!$GLOBALS['locked'],'refresh called with existing lock'); $GLOBALS['events'][]=$event;
    check($event['type']==='customer.subscription.updated' && $event['subscription_id']==='sub_A' && $event['customer_id']==='cus_A' && $event['livemode']===false && is_array($event['data']['object']), 'refresh uses ONLY saved normalized identity and boolean mode');
    if ($GLOBALS['http_fail']) { return skywall_stripe_retry(); }
    if ($GLOBALS['unpaid']) { return true; } // Production reconciler deliberately acknowledges without binding.
    return skywall_access_locked(static function () {
        check(skywall_claim_required(1,'sub_A','cus_A','test') === false, 'exact tuple previously approved');
        $GLOBALS['options']['customer:test:cus_A']='1'; $GLOBALS['options']['subscription:test:sub_A']=array('customer'=>'cus_A','user'=>1);
        return true;
    });
}
require dirname(__DIR__).'/includes/stripe.php';
require dirname(__DIR__).'/includes/claim.php';
function form() { ob_start(); skywall_claim_dashboard(); return ob_get_clean(); }
function post_claim() { return skywall_claim_process(); }
$options['subscription:test:sub_A']=array('user'=>0,'customer'=>'cus_A','email'=>'buyer@example.invalid');
$fail_write=true; check(is_wp_error(skywall_claim_required(1,'sub_A','cus_A','test')) && !$mail,'storage failure retries without notification'); $fail_write=false;
check(true===skywall_claim_required(1,'sub_A','cus_A','test') && true===skywall_claim_required(1,'sub_A','cus_A','test'),'email match and replays wait');
check(count($mail)===1 && $mail[0][0]==='buyer@example.invalid' && strpos($mail[0][2],'My Account')!==false && !preg_match('/https?:|sub_A|cus_A|nonce|token/i',$mail[0][2]),'one generic notice; no credential or billing identifiers');
check($options['subscription:test:sub_A']['claim_user']===1,'first pending identity pinned to immutable uid');
check(form()==='', 'no guest form'); $uid=2; check(form()==='', 'no other user form');
$uid=1; $html=form(); check(strpos($html,'method="post"')!==false && strpos($html,'sub_A')===false && strpos($html,'cus_A')===false,'explicit POST, no Stripe IDs');
preg_match_all('/name="([^"]+)" value="([^"]+)"/',$html,$fields,PREG_SET_ORDER); foreach ($fields as $f) { $_POST[$f[1]]=$f[2]; } $good=$_POST;
$_SERVER['REQUEST_METHOD']='GET'; check(is_wp_error(post_claim()),'GET cannot approve'); $_SERVER['REQUEST_METHOD']='POST';
$uid=0; check(is_wp_error(post_claim()),'guest cannot approve'); $uid=2; check(is_wp_error(post_claim()),'another session cannot approve captured form');
$_POST['_wpnonce']=wp_create_nonce('skywall_claim:'.$good['claim']); check(is_wp_error(post_claim()),'even a valid nonce cannot substitute for ownership');
$uid=1; $_POST=$good; $_POST['_wpnonce']='wrong'; check(is_wp_error(post_claim()),'bad nonce');
$_POST['claim']=str_repeat('0',64); $_POST['_wpnonce']=wp_create_nonce('skywall_claim:'.$_POST['claim']); check(is_wp_error(post_claim()),'nonce for invented request does not authorize');
$_POST=$good; $mode='live'; check(is_wp_error(post_claim()),'test request rejected in live mode'); $mode='test';
$users[1]->user_email='changed@example.invalid'; $users[2]->user_email='buyer@example.invalid';
check(is_wp_error(post_claim()) && true===skywall_claim_required(2,'sub_A','cus_A','test') && count($mail)===1, 'cannot approve stale address or transfer invitation to new address owner');
$users[1]->user_email='buyer@example.invalid'; $users[2]->user_email='other@example.invalid';
check(!$events && true===skywall_claim_required(1,'sub_A','cus_A','test'),'denials triggered no refresh or persisted approval');
$_POST['subscription_id']='sub_B'; $_POST['customer_id']='cus_B'; $_POST['mode']='live'; $_POST['uid']=2;
$http_fail=true; check(is_wp_error(post_claim()) && count($events)===1 && form()!=='','failed HTTP retains retryable approved request'); $http_fail=false;
$options['subscription:test:sub_B']=array('user'=>0,'customer'=>'cus_A','email'=>'buyer@example.invalid');
check(true===skywall_claim_required(1,'sub_B','cus_A','test'),'approval never applies to second subscription');
$users[1]->user_email='changed@example.invalid'; check(true===skywall_claim_required(1,'sub_A','cus_A','test'),'approved proof rechecks email at commit'); $users[1]->user_email='buyer@example.invalid';
$unpaid=true; check(!is_wp_error(post_claim()) && !$options['subscription:test:sub_A']['user'],'refresh acknowledgement alone does not grant'); $unpaid=false;
check(!is_wp_error(post_claim()) && $options['subscription:test:sub_A']['user']===1,'retry finishes binding');
$n=count($events); check(is_wp_error(post_claim()) && count($events)===$n && strpos(form(),$good['claim'])===false, 'completed record removed; replay cannot refresh');
fwrite(STDOUT,"PASS: pending identity, one notice, session/POST/nonce controls, billing refresh, failure retry and cleanup.\n");

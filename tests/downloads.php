<?php
// Isolated CLI tests; no WP database, network requests, or production downloads.
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', __DIR__ . '/' );
if ( '--unconfigured' !== ( $argv[1] ?? '' ) ) {
    define( 'SKYWALL_WOO_DOWNLOADS_PROTECTED', true );
}

$uid = 11;
$entitled = $can_read = true;
function get_option( $key, $default = false ) { return $default; }
$config_id = '45';
$site = 'https://shop.example.test';
$tmp = sys_get_temp_dir() . '/skywall-download-test-' . bin2hex( random_bytes( 8 ) );
$root = $tmp . '/woocommerce_uploads';
$source = $site . '/media/woocommerce_uploads/releases/SkyWall%20Installer.zip';
$expected_path = $root . '/releases/SkyWall Installer.zip';
$file_id = 'ac6e57fa-a793-4ecf-8313-fa9c3a22bee4';
$actions = $shortcodes = array();
function add_shortcode( $tag, $cb ) { $GLOBALS['shortcodes'][ $tag ] = $cb; }
function add_action( $tag, $cb, $p = 10, $n = 1 ) { $GLOBALS['actions'][ $tag ] = $cb; }
function skywall_config( $key ) { return 'product_id' === $key ? $GLOBALS['config_id'] : ''; }
function skywall_has_stripe_access( $id ) { return $id > 0 && $GLOBALS['entitled']; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function get_current_user_id() { return $GLOBALS['uid']; }
function current_user_can( $cap ) { return 'read' === $cap && $GLOBALS['can_read']; }
function wc_get_product( $id ) { return (int) $id === 45 ? $GLOBALS['product'] : false; }
function wp_get_upload_dir() { return array( 'basedir' => $GLOBALS['tmp'], 'baseurl' => $GLOBALS['site'] . '/media', 'error' => false ); }
function home_url() { return 'https://shop.example.test'; }
function wp_parse_url( $url, $part = -1 ) { return parse_url( $url, $part ); }
function admin_url( $path ) { return home_url() . '/wp-admin/' . $path; }
function add_query_arg( $args, $base ) { return $base . '?' . http_build_query( $args ); }
function esc_url( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function wp_unslash( $s ) { return stripslashes( $s ); }
function wp_create_nonce( $action ) { return hash_hmac( 'sha256', $action . ':' . get_current_user_id(), 'fixture-only' ); }
function wp_verify_nonce( $v, $action ) { return hash_equals( wp_create_nonce( $action ), $v ); }
function wc_nocache_headers() {}
function nocache_headers() {}
function wc_set_time_limit( $n ) {}
function wp_die( $msg, $title = '', $opts = array() ) { throw new RuntimeException( $msg, $opts['response'] ); }
class WC_Product {
    public $id = 45, $status = 'publish', $downloadable = true, $files;
    function get_id() { return $this->id; }
    function get_status() { return $this->status; }
    function is_downloadable() { return $this->downloadable; }
    function get_downloads() { return $this->files; }
}
class WC_Product_Download {
    public $enabled = true, $name = 'Windows installer', $id, $file;
    function get_enabled() { return $this->enabled; }
    function get_id() { return $this->id; }
    function get_name() { return $this->name; }
    function get_file() { return $this->file; }
}
// For an additional integration check, the very same route can stream using a real installed Woo handler.
if ( getenv( 'SKYWALL_TEST_WOO_HANDLER' ) ) {
    require getenv( 'SKYWALL_TEST_WOO_HANDLER' );
} else {
    class WC_Download_Handler {
        static function readfile_chunked( $path ) {
            if ( $GLOBALS['expected_path'] !== $path ) { throw new Exception( 'Unexpected path passed to Woo.' ); }
            return false !== readfile( $path );
        }
    }
}
require dirname( __DIR__ ) . '/includes/downloads.php';

function check_download( $condition, $description ) {
    if ( ! $condition ) { throw new Exception( $description ); }
    ++$GLOBALS['checks'];
}
function get_listing() { return call_user_func( $GLOBALS['shortcodes']['skywall_downloads'] ); }
function no_link() { return false === strpos( get_listing(), 'href=' ); }
function deny_download( $args, $status ) {
    $_GET = $args;
    try { call_user_func( $GLOBALS['actions']['admin_post_skywall_download'] ); }
    catch ( RuntimeException $e ) { check_download( $status === $e->getCode(), 'Wrong denial status: ' . $e->getCode() ); return; }
    throw new Exception( 'Expected request to be denied.' );
}
$checks = 0;
mkdir( $root . '/releases', 0700, true );
$bytes = "test-only installer\0\xff\n";
file_put_contents( $expected_path, $bytes );
file_put_contents( $tmp . '/outside.zip', 'OUTSIDE' );
symlink( $tmp . '/outside.zip', $root . '/escape.zip' );
register_shutdown_function( static function () use ( $tmp, $root, $expected_path ) {
    unlink( $expected_path ); unlink( $tmp . '/outside.zip' ); unlink( $root . '/escape.zip' );
    rmdir( $root . '/releases' ); rmdir( $root ); rmdir( $tmp );
} );
$product = new WC_Product();
$file = new WC_Product_Download();
$file->id = $file_id;
$file->file = $source;
$product->files = array( $file_id => $file );
$_SERVER['REQUEST_METHOD'] = 'GET';
if ( '--unconfigured' === ( $argv[1] ?? '' ) ) {
    check_download( no_link(), 'Storage must be disabled by default.' );
    deny_download( array( 'product_id' => '45', 'file_id' => $file_id, '_wpnonce' => wp_create_nonce( skywall_download_nonce_action( '45', $file_id ) ) ), 404 );
    exit;
}
$html = get_listing();
check_download( false !== strpos( $html, 'Windows installer' ) && false === strpos( $html, 'woocommerce_uploads' ), 'Missing file label or disclosed URL.' );
check_download( preg_match( '/href="([^"]+)"/', $html, $m ) === 1, 'Missing gated link.' );
$url = html_entity_decode( $m[1], ENT_QUOTES );
check_download( strpos( $url, home_url() . '/wp-admin/admin-post.php?' ) === 0, 'Wrong receiver.' );
parse_str( parse_url( $url, PHP_URL_QUERY ), $args );
check_download( $args['action'] === 'skywall_download' && $args['product_id'] === '45' && $args['file_id'] === $file_id, 'Wrong product/file in URL.' );
if ( '--stream' === ( $argv[1] ?? '' ) ) {
    $_GET = $args;
    call_user_func( $actions['admin_post_skywall_download'] );
    exit( 3 ); // Handler must terminate immediately after streaming.
}
ob_start(); call_user_func( $actions['woocommerce_account_dashboard'] ); $dashboard = ob_get_clean();
check_download( $html === $dashboard, 'Dashboard links and shortcode differ.' );
$uid = 0; check_download( no_link(), 'Guest can see link.' ); deny_download( $args, 403 );
$uid = 12; deny_download( $args, 403 ); // Another entitled user cannot replay this nonce.
$uid = 11; $entitled = false; check_download( no_link(), 'Revoked user can see link.' ); deny_download( $args, 403 );
$entitled = true; $can_read = false; deny_download( $args, 403 ); $can_read = true;
foreach ( array( '', '0', 'other', array( 'bad' ) ) as $bad ) { deny_download( array_merge( $args, array( '_wpnonce' => $bad ) ), 403 ); }
foreach ( array( '46', '045', array( '45' ) ) as $bad ) { deny_download( array_merge( $args, array( 'product_id' => $bad ) ), 403 ); }
deny_download( array_merge( $args, array( 'file_id' => 'different-file' ) ), 403 );
deny_download( array_merge( $args, array( 'file_id' => array( $file_id ) ) ), 403 );
$_SERVER['REQUEST_METHOD'] = 'POST'; deny_download( $args, 405 ); $_SERVER['REQUEST_METHOD'] = 'GET';
$original_product = $product; $product = false; check_download( no_link(), 'Product removed.' ); deny_download( $args, 404 ); $product = $original_product;
$product->downloadable = false; check_download( no_link(), 'Product not downloadable.' ); deny_download( $args, 404 ); $product->downloadable = true;
$product->status = 'draft'; check_download( no_link(), 'Unpublished product.' ); deny_download( $args, 404 ); $product->status = 'publish';
$product->id = 46; deny_download( $args, 404 ); $product->id = 45;
$config_id = '45abc'; check_download( no_link(), 'Malformed configuration accepted.' ); deny_download( $args, 403 ); $config_id = '45';
$file->enabled = false; check_download( no_link(), 'Disabled Woo file.' ); deny_download( $args, 404 ); $file->enabled = true;
$product->files = array(); deny_download( $args, 404 ); $product->files = array( $file_id => $file );
$file->id = 'different'; deny_download( $args, 404 ); $file->id = $file_id;
foreach ( array( $tmp . '/outside.zip', $root . '/escape.zip', $root . '/releases/../releases/SkyWall Installer.zip',
    $root . '/absent.zip', $root . '/releases', $source . '?token=test-only', str_replace( '%20', '%2520', $source ),
    $site . '/media/outside.zip', str_replace( 'shop.', 'cdn.', $source ), 'file://' . $expected_path, '[download_fixture]',
    str_replace( '/releases/', '/%2e%2e/releases/', $source ), 'https://user:pass@shop.example.test/media/woocommerce_uploads/escape.zip',
    '//shop.example.test/media/woocommerce_uploads/escape.zip', $source . "\r\n", $source . '#fragment' ) as $bad ) {
    $file->file = $bad; check_download( no_link(), 'Accepted unsupported file source.' ); deny_download( $args, 404 );
}
$file->file = $expected_path; check_download( ! no_link(), 'Canonical absolute path not accepted.' );
$site = 'https://cdn.example.test'; check_download( no_link(), 'Offloaded uploads accepted.' ); $site = home_url();
$file->name = '<unsafe> & https://example.test/?credential=test-only';
$html = get_listing(); check_download( false === strpos( $html, '<unsafe>' ) && false === strpos( $html, 'credential=' ), 'Disclosed unsafe label.' );
foreach ( array( '--unconfigured' => '', '--stream' => $bytes ) as $mode => $expected ) {
    $p = proc_open( array( PHP_BINARY, '-n', __FILE__, $mode ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
    $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
    check_download( 0 === proc_close( $p ) && $expected === $out && '' === $err, 'Child ' . $mode . ' failed: ' . $err );
}
fwrite( STDOUT, "Download checks passed ($checks).\n" );

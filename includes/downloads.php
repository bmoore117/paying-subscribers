<?php
/** Stripe-entitled access to the configured WooCommerce product's installer files. */
defined( 'ABSPATH' ) || exit;

function skywall_download_entitled() {
    return is_user_logged_in() && current_user_can( 'read' ) && skywall_has_stripe_access( get_current_user_id() );
}

function skywall_download_product_id() {
    $id = skywall_config( 'product_id' );
    return is_string( $id ) && preg_match( '/\A[1-9][0-9]*\z/', $id ) && (string) (int) $id === $id ? $id : '';
}

function skywall_download_valid_id( $id ) {
    return is_string( $id ) && preg_match( '/\A[A-Za-z0-9_-]{1,128}\z/', $id );
}

/**
 * Accept only local, web-server-protected Woo uploads. PHP cannot determine whether an
 * nginx rule, Apache override, alternate host or CDN permits anonymous file access.
 * Only set SKYWALL_WOO_DOWNLOADS_PROTECTED=true in wp-config.php AFTER verifying that
 * direct GETs of the raw file are denied at the origin AND every public endpoint.
 * Keep this directory administrator-controlled. Never fall back to redirect/remote I/O.
 */
function skywall_storage_stamp() {
    $up = wp_get_upload_dir();
    return hash( 'sha256', home_url() . '|' . ($up['baseurl'] ?? '') . '|' . ($up['basedir'] ?? '') );
}
function skywall_download_protection_confirmed() {
    if ( defined( 'SKYWALL_WOO_DOWNLOADS_PROTECTED' ) ) { return SKYWALL_WOO_DOWNLOADS_PROTECTED === true; }
    return get_option( 'skywall_protected_storage', '' ) === skywall_storage_stamp();
}
function skywall_download_local_path( $source ) {
    if ( ! skywall_download_protection_confirmed() || ! is_string( $source ) ) {
        return false;
    }
    $uploads = wp_get_upload_dir();
    if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
        return false;
    }
    $base_url = rtrim( $uploads['baseurl'], '/' );
    $url      = wp_parse_url( $base_url );
    $home     = wp_parse_url( home_url() );
    if ( empty( $url['host'] ) || empty( $home['host'] ) || ! isset( $url['scheme'], $home['scheme'] ) ||
        ! in_array( $url['scheme'], array( 'http', 'https' ), true ) || $url['scheme'] !== $home['scheme'] ||
        strcasecmp( $url['host'], $home['host'] ) !== 0 || ( $url['port'] ?? null ) !== ( $home['port'] ?? null ) ||
        isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['query'] ) || isset( $url['fragment'] ) ) {
        return false;
    }
    $base = realpath( $uploads['basedir'] );
    if ( false === $base ) {
        return false;
    }
    $root        = $base . '/woocommerce_uploads/';
    $web_prefix  = $base_url . '/woocommerce_uploads/';
    if ( 0 === strpos( $source, $web_prefix ) ) {
        // Decode ordinary filename spaces only; never turn escaped separators into paths.
        $relative = str_replace( '%20', ' ', substr( $source, strlen( $web_prefix ) ) );
    } elseif ( 0 === strpos( $source, $root ) ) {
        $relative = substr( $source, strlen( $root ) );
    } else {
        return false;
    }
    foreach ( explode( '/', $relative ) as $part ) {
        // Deliberately exclude hidden/dot segments, empty segments, controls, URL wrappers,
        // escapes, headers, alternate streams and backslashes. Support simple installer names.
        if ( ! preg_match( '/\A[A-Za-z0-9][A-Za-z0-9 ._()+-]{0,254}\z/', $part ) ) {
            return false;
        }
    }
    $candidate = $root . $relative;
    $real      = realpath( $candidate );
    // Exact equality also rejects symlinks (including at the root or inside a subdirectory).
    return $real === $candidate && is_file( $real ) && is_readable( $real ) ? $real : false;
}

/** Returns only currently enabled, available files; never consult a client-supplied path. */
function skywall_download_files( $product_id, $only_id = null ) {
    if ( '' === $product_id || ! skywall_download_protection_confirmed() ||
        ! function_exists( 'wc_get_product' ) || ! is_callable( array( 'WC_Download_Handler', 'readfile_chunked' ) ) ) {
        return array();
    }
    $product = wc_get_product( (int) $product_id );
    if ( ! $product instanceof WC_Product || (string) $product->get_id() !== $product_id || 'publish' !== $product->get_status() || ! $product->is_downloadable() ) {
        return array();
    }
    $files = array();
    foreach ( $product->get_downloads() as $key => $download ) {
        $id = (string) $key;
        if ( ( null !== $only_id && $only_id !== $id ) || ! skywall_download_valid_id( $id ) ||
            ! $download instanceof WC_Product_Download || (string) $download->get_id() !== $id ||
            ! is_callable( array( $download, 'get_enabled' ) ) || ! $download->get_enabled() ) {
            continue;
        }
        $path = skywall_download_local_path( $download->get_file() );
        if ( false === $path ) {
            continue;
        }
        $name = $download->get_name();
        // Woo labels can be arbitrary, even a URL including credentials. Only show plain labels.
        $label = is_string( $name ) && preg_match( '/\A[\pL\pN][\pL\pN .,()_+&-]{0,119}\z/u', $name ) ? $name : 'Download installer';
        $files[ $id ] = array( 'path' => $path, 'label' => $label );
    }
    return $files;
}

function skywall_download_nonce_action( $product_id, $file_id ) {
    return 'skywall_download:' . $product_id . ':' . $file_id;
}

function skywall_downloads_shortcode() {
    if ( ! skywall_download_entitled() ) {
        return '';
    }
    // Shortcodes can also appear outside Woo's non-cacheable account pages. The endpoint
    // still checks the session and current entitlement if a page cache ignores this hint.
    if ( function_exists( 'wc_nocache_headers' ) ) {
        wc_nocache_headers();
    }
    $product_id = skywall_download_product_id();
    $files      = skywall_download_files( $product_id );
    if ( ! $files ) {
        return '<p>SkyWall installer downloads are currently unavailable.</p>';
    }
    $items = '';
    foreach ( $files as $id => $file ) {
        $args = array(
            'action'     => 'skywall_download',
            'product_id' => $product_id,
            'file_id'    => $id,
            '_wpnonce'   => wp_create_nonce( skywall_download_nonce_action( $product_id, $id ) ),
        );
        $link = add_query_arg( $args, admin_url( 'admin-post.php' ) );
        $items .= '<li><a rel="nofollow" href="' . esc_url( $link ) . '">' . esc_html( $file['label'] ) . '</a></li>';
    }
    return '<section class="skywall-downloads"><h2>SkyWall installer downloads</h2><ul>' . $items . '</ul></section>';
}
add_shortcode( 'skywall_downloads', 'skywall_downloads_shortcode' );

function skywall_downloads_dashboard() {
    echo skywall_downloads_shortcode(); // Markup built above; dynamic content escaped at insertion.
}
add_action( 'woocommerce_account_dashboard', 'skywall_downloads_dashboard' );

function skywall_serve_download() {
    nocache_headers();
    if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
        header( 'Allow: GET' );
        wp_die( 'Method not allowed.', '', array( 'response' => 405 ) );
    }
    $product_id = skywall_download_product_id();
    $file_id    = isset( $_GET['file_id'] ) && is_string( $_GET['file_id'] ) ? wp_unslash( $_GET['file_id'] ) : '';
    $nonce      = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : '';
    if ( ! skywall_download_entitled() || '' === $product_id || ( $_GET['product_id'] ?? null ) !== $product_id ||
        ! skywall_download_valid_id( $file_id ) || ! wp_verify_nonce( $nonce, skywall_download_nonce_action( $product_id, $file_id ) ) ) {
        wp_die( 'Download access denied.', '', array( 'response' => 403 ) );
    }
    $files = skywall_download_files( $product_id, $file_id );
    if ( ! isset( $files[ $file_id ] ) ) {
        wp_die( 'Download unavailable.', '', array( 'response' => 404 ) );
    }
    $path = $files[ $file_id ]['path'];
    // Use Woo's chunked reader on the verified path. Its higher-level handlers can re-map
    // paths, deliver inline, or redirect depending on filters and store-wide settings.
    wc_set_time_limit( 0 );
    @ini_set( 'zlib.output_compression', 'Off' );
    while ( ob_get_level() > 0 && @ob_end_clean() ) { /* Discard buffered notices. */ }
    if ( headers_sent() ) {
        wp_die( 'Download unavailable.', '', array( 'response' => 500 ) );
    }
    wc_nocache_headers();
    header( 'Cache-Control: private, no-store, max-age=0' );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'X-Robots-Tag: noindex, nofollow' );
    header( 'Referrer-Policy: no-referrer' );
    header( 'Content-Type: application/octet-stream' );
    header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
    $size = @filesize( $path ); // May have been removed since product lookup; don't leak its path.
    if ( false !== $size ) {
        header( 'Content-Length: ' . $size );
    }
    if ( ! WC_Download_Handler::readfile_chunked( $path ) && ! headers_sent() ) {
        header_remove( 'Content-Disposition' );
        header_remove( 'Content-Length' );
        header( 'Content-Type: text/plain; charset=utf-8' );
        wp_die( 'Download unavailable.', '', array( 'response' => 404 ) );
    }
    exit;
}
add_action( 'admin_post_skywall_download', 'skywall_serve_download' );
add_action( 'admin_post_nopriv_skywall_download', 'skywall_serve_download' );

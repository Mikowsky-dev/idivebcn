<?php
/**
 * Plugin Name: DEV Outbound Guard (block n8n / automation webhooks)
 * Description: Prevents the DEV clone from calling production automation endpoints (n8n) so no
 *              real workflows are triggered from DEV. Blocks any outbound HTTP whose host contains
 *              "n8n" or matches known automation IPs. Other outbound traffic is left untouched.
 * Version: 1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/00-dev-guard.php';

// Sin esto, el fichero llega a produccion en el siguiente despliegue y corta su correo real.
if ( ! mikowsky_dev_is_dev_site() ) {
	return;
}

add_filter( 'pre_http_request', function( $pre, $args, $url ) {
    $host = (string) parse_url( $url, PHP_URL_HOST );
    if ( $host === '' ) return $pre;
    $blocked_ips = array( '194.163.173.218' ); // old Contabo n8n
    if ( stripos( $host, 'n8n' ) !== false || in_array( $host, $blocked_ips, true ) ) {
        if ( function_exists( 'error_log' ) ) {
            error_log( '[DEV outbound guard] blocked call to ' . $host . ' (' . $url . ')' );
        }
        return new WP_Error( 'dev_outbound_blocked', 'Blocked outbound to ' . $host . ' by DEV guard' );
    }
    return $pre;
}, 0, 3 );

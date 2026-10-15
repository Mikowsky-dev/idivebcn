<?php
/**
 * Plugin Name: DEV Mail Guard (reroute all outgoing email)
 * Description: Safety net for the DEV clone of production. Forces every outgoing email to a
 *              single safe inbox so real customers are never contacted from DEV.
 * Version: 1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/00-dev-guard.php';

// Sin esto, el fichero llega a produccion en el siguiente despliegue y corta su correo real.
if ( ! mikowsky_dev_is_dev_site() ) {
	return;
}
if ( ! defined( 'SD_DEV_MAIL_GUARD_TO' ) ) define( 'SD_DEV_MAIL_GUARD_TO', 'info@mikowsky.com' );

add_filter( 'wp_mail', function( $args ) {
    $orig = is_array( $args['to'] ) ? implode( ',', $args['to'] ) : (string) $args['to'];
    $args['to'] = SD_DEV_MAIL_GUARD_TO;
    if ( ! empty( $args['headers'] ) ) {
        $args['headers'] = array_values( array_filter( (array) $args['headers'], function( $h ) {
            return ! preg_match( '/^\s*(cc|bcc)\s*:/i', (string) $h );
        } ) );
    }
    $args['subject'] = '[DEV->' . $orig . '] ' . ( isset($args['subject']) ? $args['subject'] : '' );
    return $args;
}, PHP_INT_MAX );

add_action( 'phpmailer_init', function( $phpmailer ) {
    $phpmailer->clearAllRecipients();
    $phpmailer->clearCCs();
    $phpmailer->clearBCCs();
    $phpmailer->addAddress( SD_DEV_MAIL_GUARD_TO );
}, PHP_INT_MAX );

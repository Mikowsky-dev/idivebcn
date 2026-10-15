<?php
/**
 * Plugin Name: Dev — sin compras
 * Description: Makes it impossible to place an order in the dev environment, and says so.
 *
 * The risk this guards against is not financial — Redsys here points at its sandbox, so no card
 * is ever charged. It is that somebody who does not realise this is a test site believes they
 * have booked a dive and turns up at the centre. Basic auth already keeps the public out; this
 * is the layer that still holds if the auth is ever lifted for a demo.
 *
 * Deliberately NOT marking products unpurchasable: that strips the Extra Product Options form
 * from the product page, and with it the booking calendar we need to verify. The block sits at
 * checkout, so the pages still render exactly as in production.
 *
 * @package Mikowsky\Dev
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/00-dev-guard.php';

if ( ! mikowsky_dev_is_dev_site() ) {
	return;
}

const MIKOWSKY_DEV_NOTICE = 'Esto es el ENTORNO DE PRUEBAS de SuperDive. Aquí no se puede comprar y ninguna reserva es real. Para reservar de verdad, ve a superdivetossa.com.';

// 1. No payment method is available, so the order can never be placed.
add_filter( 'woocommerce_available_payment_gateways', '__return_empty_array', PHP_INT_MAX );

// 2. And if anything got that far anyway, refuse the order outright.
add_action(
	'woocommerce_checkout_process',
	function () {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( MIKOWSKY_DEV_NOTICE, 'error' );
		}
	},
	1
);

// 3. Say it on the cart and checkout, where someone would be about to pay.
foreach ( [ 'woocommerce_before_cart', 'woocommerce_before_checkout_form' ] as $hook ) {
	add_action(
		$hook,
		function () {
			if ( function_exists( 'wc_print_notice' ) ) {
				wc_print_notice( MIKOWSKY_DEV_NOTICE, 'notice' );
			}
		},
		5
	);
}

// 4. A banner on every front end page, so nobody has to reach the cart to find out.
add_action(
	'wp_body_open',
	function () {
		if ( is_admin() ) {
			return;
		}
		echo '<div style="position:relative;z-index:99999;background:#e08a1e;color:#3a2708;'
			. 'font:600 13px/1.4 system-ui,sans-serif;text-align:center;padding:7px 12px">'
			. 'ENTORNO DE PRUEBAS · las reservas hechas aquí no son reales</div>';
	}
);

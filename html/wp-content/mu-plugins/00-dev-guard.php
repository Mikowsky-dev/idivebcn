<?php
/**
 * Plugin Name: Dev — guard compartido
 * Description: Decides whether this install is a development site.
 *
 * Lives in its own file, and the others `require_once` it, so the answer never depends on the
 * order mu-plugins happen to load in. It bit once already: `00-dev-no-purchase.php` sorts BEFORE
 * `00-dev-noindex.php` ("-" sorts before "i"), so a guard defined in the latter did not exist yet
 * and the purchase block silently did nothing.
 *
 * @package Mikowsky\Dev
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether this install is a dev site.
 *
 * A POSITIVE match on a dev hostname, never an exclusion of production. These files live in the
 * repository and the repository is what gets deployed, so on the next merge they reach production
 * too; without this they would take the shop down there — noindex on every page and zero payment
 * gateways. If the host cannot be resolved the guard stays off, which is the harmless direction
 * for the live shop. Dev keeps its own protection regardless: basic auth and Redsys in sandbox.
 *
 * @return bool
 */
function mikowsky_dev_is_dev_site() {
	$home = defined( 'WP_HOME' ) ? (string) WP_HOME : '';

	// La opcion `home` es la fuente fiable: viene de la base de datos, existe en CLI y en web,
	// y no depende de que el wp-config defina WP_HOME. idive NO lo define, y por eso la primera
	// version de este guard devolvia false en su dev y dejaba los guards apagados: el correo
	// salia y habia pasarelas de pago activas. Medido el 15-oct-2026.
	if ( '' === $home && function_exists( 'get_option' ) ) {
		$home = (string) get_option( 'home' );
	}

	if ( '' === $home && isset( $_SERVER['HTTP_HOST'] ) ) {
		$home = 'https://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
	}

	$host = (string) wp_parse_url( $home, PHP_URL_HOST );

	return '' !== $host && ( str_starts_with( $host, 'dev.' ) || str_contains( $host, '-dev' ) );
}

<?php
/**
 * Plugin Name: Mikowsky — Calendario de cierres
 * Description: REST API to close/reopen days on the booking calendar of ThemeComplete EPO
 *              date elements, with a single source of truth across WPML languages.
 * Version:     0.1.0
 * Author:      Mikowsky
 *
 * Must-use plugin: always active, cannot be disabled by accident from the admin.
 *
 * @package Mikowsky\Cierres
 */

defined( 'ABSPATH' ) || exit;

/**
 * Capability required to read or change closures.
 *
 * Granted to administrators and to the dedicated `cierres_manager` role.
 */
const MIKOWSKY_CIERRES_CAP = 'manage_mikowsky_cierres';

/**
 * Activity → product map. The hard allowlist: nothing outside this map is readable
 * or writable through the REST API.
 *
 * Only base (Spanish) product ids go here. Translations are resolved through WPML
 * for reporting, never written to — see the single source of truth filter below.
 *
 * `element` pins the EPO date element by its internal name, for products that have
 * more than one. null = the product has a single date element, use that one.
 *
 * This is the only per-site configuration. To replicate on another site, change
 * this map and nothing else.
 */
const MIKOWSKY_CIERRES_ACTIVITIES = [
	'dsd' => [
		'product' => 56,
		'label'   => 'Discover Scuba Diving',
		'element' => null,
	],
];

require_once __DIR__ . '/mikowsky-cierres/class-store.php';
require_once __DIR__ . '/mikowsky-cierres/class-writer.php';
require_once __DIR__ . '/mikowsky-cierres/class-audit.php';
require_once __DIR__ . '/mikowsky-cierres/class-rest.php';
require_once __DIR__ . '/mikowsky-cierres/class-page.php';

/**
 * Single source of truth for disabled dates across WPML languages.
 *
 * ThemeComplete EPO lists `date_disabled_dates` as a per-language field
 * (class-themecomplete-epo-cp-wpml.php:163). When a translation holds its own value,
 * wc_epo_use_original_builder() (same class, line 236) gives it priority over the
 * original product — so writing only the base product would leave the other languages
 * bookable. Measured on 14-oct-2026: product 16822 had its own values in ca (18001)
 * and en (19148), 27406 did not.
 *
 * Dropping the field from that list makes every language read the base product, so a
 * closure is stored once. Stale per-language values in `tm_meta_wpml` stop being read;
 * they are reported by the API as a warning rather than silently honoured.
 *
 * A closure is a fact about the dive centre's diary, never about the language the
 * customer happens to browse in, so there is nothing here worth translating.
 */
add_filter(
	'wc_epo_wpml_element_fields',
	function ( $fields ) {
		return is_array( $fields )
			? array_values( array_diff( $fields, [ Mikowsky_Cierres_Store::FIELD ] ) )
			: $fields;
	}
);

/**
 * Roles allowed to manage closures.
 *
 * `shop_manager` is in the list because the person who actually closes days at the centre
 * manages the shop (decided 15-oct-2026). `cierres_manager` stays as the role to give someone
 * who should do this and nothing else. Administrators are included so the tool can always be
 * fixed and its audit trail read.
 */
const MIKOWSKY_CIERRES_ROLES = [ 'cierres_manager', 'shop_manager', 'administrator' ];

/**
 * Make sure those roles hold the capability.
 *
 * mu-plugins have no activation hook, so this has to be conditional. The condition is the roles
 * themselves, not a "we already did it" flag: a flag got the rewrite rule of this same plugin
 * wrong — set in the same request that was meant to apply the change, which then never applied
 * and never retried. Asking whether the capability is actually there costs a lookup on an option
 * WordPress already has in memory, heals itself after a database import from another site, and
 * picks up changes to the list above without anyone remembering to bump a version.
 */
add_action(
	'init',
	function () {
		if ( ! get_role( 'cierres_manager' ) ) {
			add_role(
				'cierres_manager',
				'Gestor de cierres',
				[
					'read'               => true,
					MIKOWSKY_CIERRES_CAP => true,
				]
			);
		}

		foreach ( MIKOWSKY_CIERRES_ROLES as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && ! $role->has_cap( MIKOWSKY_CIERRES_CAP ) ) {
				$role->add_cap( MIKOWSKY_CIERRES_CAP );
			}
		}
	}
);

add_action(
	'rest_api_init',
	function () {
		$store  = new Mikowsky_Cierres_Store( MIKOWSKY_CIERRES_ACTIVITIES );
		$writer = new Mikowsky_Cierres_Writer( $store );
		( new Mikowsky_Cierres_Rest( $store, $writer ) )->register_routes();
	}
);

( new Mikowsky_Cierres_Page( new Mikowsky_Cierres_Store( MIKOWSKY_CIERRES_ACTIVITIES ) ) )->register();

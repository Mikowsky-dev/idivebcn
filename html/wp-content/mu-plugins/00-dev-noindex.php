<?php
/**
 * Plugin Name: Dev — noindex
 * Description: Sends X-Robots-Tag on every WordPress response in the dev environment.
 *
 * Why here and not in nginx: the X-Robots-Tag of `dev-noindex.conf` sits at server level, and
 * nginx does NOT inherit add_header into a location that defines its own — `location ~ \.php$`
 * defines X-Cache-Status, so the tag never reached a single HTML page (measured 15-oct-2026).
 * The fix cannot go in `nginx/default.conf.template` because that file is shared with the prod
 * branch, where a noindex would be catastrophic. A dev-only mu-plugin travels with the other
 * dev guards and cannot be switched off from the admin.
 *
 * @package Mikowsky\Dev
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/00-dev-guard.php';

if ( ! mikowsky_dev_is_dev_site() ) {
	return;
}

add_filter(
	'wp_headers',
	function ( $headers ) {
		$headers['X-Robots-Tag'] = 'noindex, nofollow, noarchive, nosnippet';

		return $headers;
	}
);

// wp_headers does not cover admin or REST responses; send_headers does.
add_action(
	'send_headers',
	function () {
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet' );
		}
	}
);

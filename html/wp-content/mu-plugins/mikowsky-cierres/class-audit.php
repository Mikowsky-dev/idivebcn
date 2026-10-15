<?php
/**
 * Audit trail for the closures calendar.
 *
 * @package Mikowsky\Cierres
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records who closed or reopened what, and when.
 *
 * Kept in a non-autoloaded option rather than a log file: anything under wp-content is
 * served by nginx unless explicitly denied, and this trail carries user names and IPs.
 * A bounded option needs no migration, survives container rebuilds and cannot leak
 * through an unguarded URL.
 */
final class Mikowsky_Cierres_Audit {

	/**
	 * Option holding the trail.
	 */
	const OPTION = 'mikowsky_cierres_audit';

	/**
	 * How many entries to keep. Closures are occasional, so this is months of history.
	 */
	const KEEP = 200;

	/**
	 * Record one write attempt.
	 *
	 * @param array<string,mixed> $entry Fields to record.
	 * @return void
	 */
	public static function record( array $entry ) {
		$user = wp_get_current_user();

		$trail   = self::all();
		$trail[] = array_merge(
			[
				'at'      => current_time( 'c' ),
				'user_id' => (int) $user->ID,
				'user'    => (string) $user->user_login,
				'ip'      => self::client_ip(),
			],
			$entry
		);

		if ( count( $trail ) > self::KEEP ) {
			$trail = array_slice( $trail, -self::KEEP );
		}

		update_option( self::OPTION, $trail, false );
	}

	/**
	 * The whole trail, oldest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all() {
		$trail = get_option( self::OPTION, [] );

		return is_array( $trail ) ? $trail : [];
	}

	/**
	 * The client address, as seen behind Traefik.
	 *
	 * X-Forwarded-For is only trusted for its last hop and only to the extent of being
	 * recorded: it never gates access, so a spoofed value misleads the trail but grants
	 * nothing.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
			? explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) )
			: [];

		$candidate = $forwarded
			? trim( (string) end( $forwarded ) )
			: ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );

		return filter_var( $candidate, FILTER_VALIDATE_IP ) ? $candidate : '';
	}
}

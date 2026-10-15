<?php
/**
 * Write layer for the closures calendar.
 *
 * @package Mikowsky\Cierres
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applies a closure or a reopening and invalidates the caches that would hide it.
 */
final class Mikowsky_Cierres_Writer {

	/**
	 * Data layer.
	 *
	 * @var Mikowsky_Cierres_Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param Mikowsky_Cierres_Store $store Data layer.
	 */
	public function __construct( Mikowsky_Cierres_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Close or reopen one day of one activity.
	 *
	 * Idempotent: closing an already closed day, or reopening an open one, succeeds and
	 * reports `changed = false` without touching the product.
	 *
	 * Only the base product is written. The single source of truth filter in the loader
	 * makes every WPML language read it, so there is one value to keep straight instead
	 * of one per language.
	 *
	 * @param array<string,mixed> $resolved Output of Store::resolve().
	 * @param string              $iso      Day as YYYY-MM-DD.
	 * @param string              $action   Either `close` or `open`.
	 * @return array<string,mixed>|WP_Error
	 */
	public function apply( array $resolved, $iso, $action ) {
		$current = $this->store->closed_dates( $resolved );
		$dates   = $current['dates'];
		$closed  = in_array( $iso, $dates, true );

		if ( 'close' === $action ) {
			if ( $closed ) {
				return $this->unchanged( $dates, $current['invalid'] );
			}
			$dates[] = $iso;
		} else {
			if ( ! $closed ) {
				return $this->unchanged( $dates, $current['invalid'] );
			}
			$dates = array_values( array_diff( $dates, [ $iso ] ) );
		}

		sort( $dates );

		$raw = $this->build_raw_value( $dates, $current['invalid'], $resolved['format'] );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$meta = $resolved['meta'];
		$meta['tmfbuilder'][ Mikowsky_Cierres_Store::FIELD ][ $resolved['index'] ] = $raw;

		// wp_slash() is mandatory: update_post_meta() unslashes internally, and without it
		// the backslashes of the rest of tm_meta (option titles, HTML) get eaten.
		update_post_meta( $resolved['product_id'], Mikowsky_Cierres_Store::META_KEY, wp_slash( $meta ) );

		$written = $this->store->resolve( $resolved['activity'] );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		$after = $this->store->closed_dates( $written );
		if ( in_array( $iso, $after['dates'], true ) !== ( 'close' === $action ) ) {
			return new WP_Error(
				'mikowsky_cierres_write_failed',
				'El cambio no se ha guardado; el producto sigue como estaba.',
				[ 'status' => 500 ]
			);
		}

		$this->purge( $written );

		return [
			'changed'      => true,
			'closed'       => $after['dates'],
			'kept_invalid' => $after['invalid'],
		];
	}

	/**
	 * Result of a request that asked for the state the product was already in.
	 *
	 * @param array<int,string> $dates   Closed days, as ISO.
	 * @param array<int,string> $invalid Unparseable stored values.
	 * @return array<string,mixed>
	 */
	private function unchanged( array $dates, array $invalid ) {
		return [
			'changed'      => false,
			'closed'       => $dates,
			'kept_invalid' => $invalid,
		];
	}

	/**
	 * Build the comma separated value EPO expects.
	 *
	 * No space after the comma: neither EPO layer trims the individual items
	 * (class-themecomplete-epo-fields-date.php:491 on the server, tm-epo.js on the
	 * client), so a padded value would never match the day the customer picks.
	 *
	 * Values that did not parse are carried over verbatim instead of being dropped. They
	 * are already reported as a warning; deleting data the client never asked to touch
	 * would be worse than keeping it.
	 *
	 * @param array<int,string> $dates   Closed days, as ISO.
	 * @param array<int,string> $invalid Unparseable stored values, verbatim.
	 * @param string            $format  PHP date format of the element.
	 * @return string|WP_Error
	 */
	private function build_raw_value( array $dates, array $invalid, $format ) {
		$pieces = [];

		foreach ( $dates as $iso ) {
			$stored = Mikowsky_Cierres_Store::from_iso( $iso, $format );
			if ( null === $stored ) {
				return new WP_Error(
					'mikowsky_cierres_bad_date',
					sprintf( 'La fecha %s no se puede guardar en el formato del producto.', $iso ),
					[ 'status' => 400 ]
				);
			}
			$pieces[] = $stored;
		}

		return implode( ',', array_merge( $pieces, $invalid ) );
	}

	/**
	 * Invalidate the caches we can reach, and be honest about the one we cannot.
	 *
	 * - Object cache (Redis): works. update_post_meta() already invalidates the product's
	 *   meta; clean_post_cache() on the base product and its translations covers the
	 *   derived WooCommerce product caches too.
	 *
	 * - nginx fastcgi: DOES NOT WORK FROM HERE, and the call below is kept only because it
	 *   is the right thing to call on a host where it can work. Measured on 14-oct-2026:
	 *   the cache lives in a volume owned by nginx (uid 101) whose level directories are
	 *   0700, so php-fpm running as www-data cannot unlink them — unlinking a file needs
	 *   write permission on its directory. nginx-helper reports success regardless, and
	 *   its own RT_WP_NGINX_HELPER_CACHE_PATH points at its default /var/run/nginx-cache
	 *   rather than the configured path, so it would purge the wrong directory anyway.
	 *   Consequence: product pages can keep serving the old calendar for up to
	 *   fastcgi_cache_valid, today 60 minutes. Accepted deliberately by Miquel on
	 *   14-oct-2026 over the alternatives (short TTL or no cache on those two URLs);
	 *   the API warns the client so a closure never looks like it failed. No overbooking
	 *   either way: EPO revalidates disabled dates on add to cart
	 *   (class-themecomplete-epo-fields-date.php:490), not just in the datepicker.
	 *
	 * - Cloudflare: NOT purged, and not needed. Measured the same day, product HTML comes
	 *   back as `cf-cache-status: DYNAMIC` in production and dev is not proxied at all, so
	 *   there is nothing cached at the edge — and injecting a Cloudflare API key into the
	 *   web container to perform a no-op would hand a compromised WordPress the keys to
	 *   the DNS zone. If a cache-everything rule is ever added, hook the action below.
	 *
	 * @param array<string,mixed> $resolved Output of Store::resolve().
	 * @return void
	 */
	private function purge( array $resolved ) {
		clean_post_cache( $resolved['product_id'] );
		foreach ( $this->store->languages( $resolved ) as $language ) {
			clean_post_cache( $language['product_id'] );
		}

		global $nginx_purger;
		if ( is_object( $nginx_purger ) && method_exists( $nginx_purger, 'purge_all' ) ) {
			$nginx_purger->purge_all();
		}

		/**
		 * Fires after the closures calendar invalidated its caches.
		 *
		 * @param array<string,mixed> $resolved The resolved activity.
		 */
		do_action( 'mikowsky_cierres_after_purge', $resolved );
	}
}

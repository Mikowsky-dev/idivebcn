<?php
/**
 * Data layer for the closures calendar.
 *
 * Reads and writes the disabled dates of a ThemeComplete EPO date element, which live
 * inside the serialised `tm_meta` of the product — there is no table of their own.
 *
 * @package Mikowsky\Cierres
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves activities to products and reads their closed days.
 */
final class Mikowsky_Cierres_Store {

	/**
	 * The EPO builder field holding the disabled dates, as a single comma separated string.
	 */
	const FIELD = 'date_disabled_dates';

	/**
	 * Product meta holding the EPO builder of a base product.
	 */
	const META_KEY = 'tm_meta';

	/**
	 * Product meta holding the per-language EPO builder of a translated product.
	 */
	const WPML_META_KEY = 'tm_meta_wpml';

	/**
	 * EPO date formats whose disabled dates actually work, mapped to PHP date formats.
	 *
	 * EPO offers twelve formats but normalises stored disabled dates only for these six
	 * (class-themecomplete-extra-product-options.php:6888). Year-first formats (6-11) fall
	 * through its own parser and are dropped without notice, so an activity using one of
	 * them is refused instead of writing dates that would never take effect.
	 */
	const SUPPORTED_FORMATS = [
		'0' => 'd/m/Y',
		'1' => 'm/d/Y',
		'2' => 'd.m.Y',
		'3' => 'm.d.Y',
		'4' => 'd-m-Y',
		'5' => 'm-d-Y',
	];

	/**
	 * Activity map, as given by the configuration.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $activities;

	/**
	 * Constructor.
	 *
	 * @param array<string,array<string,mixed>> $activities Activity → product map.
	 */
	public function __construct( array $activities ) {
		$this->activities = $activities;
	}

	/**
	 * The configured activities, for the activity selector of the front end.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function activities() {
		$out = [];
		foreach ( $this->activities as $key => $config ) {
			$out[] = [
				'activity' => (string) $key,
				'label'    => (string) ( $config['label'] ?? $key ),
			];
		}

		return $out;
	}

	/**
	 * Resolve an activity to its product and date element.
	 *
	 * Everything the callers need to read or write is returned together, so the builder
	 * is located exactly once per request.
	 *
	 * @param string $activity Activity key.
	 * @return array<string,mixed>|WP_Error
	 */
	public function resolve( $activity ) {
		$activity = (string) $activity;

		if ( ! isset( $this->activities[ $activity ] ) ) {
			return new WP_Error(
				'mikowsky_cierres_unknown_activity',
				'Actividad no reconocida.',
				[ 'status' => 403 ]
			);
		}

		$config     = $this->activities[ $activity ];
		$product_id = (int) ( $config['product'] ?? 0 );

		if ( 'product' !== get_post_type( $product_id ) ) {
			return new WP_Error(
				'mikowsky_cierres_no_product',
				sprintf( 'El producto %d de la actividad "%s" no existe.', $product_id, $activity ),
				[ 'status' => 500 ]
			);
		}

		$meta    = get_post_meta( $product_id, self::META_KEY, true );
		$builder = is_array( $meta ) && isset( $meta['tmfbuilder'] ) && is_array( $meta['tmfbuilder'] )
			? $meta['tmfbuilder']
			: null;

		if ( null === $builder ) {
			return new WP_Error(
				'mikowsky_cierres_no_builder',
				sprintf( 'El producto %d no tiene opciones de Extra Product Options.', $product_id ),
				[ 'status' => 500 ]
			);
		}

		$index = $this->find_date_element( $builder, $config['element'] ?? null );
		if ( is_wp_error( $index ) ) {
			return $index;
		}

		$format_key = (string) ( $builder['date_format'][ $index ] ?? '0' );
		if ( ! isset( self::SUPPORTED_FORMATS[ $format_key ] ) ) {
			return new WP_Error(
				'mikowsky_cierres_unsupported_format',
				sprintf(
					'El elemento de fecha del producto %d usa el formato EPO "%s", que no admite fechas deshabilitadas.',
					$product_id,
					$format_key
				),
				[ 'status' => 500 ]
			);
		}

		return [
			'activity'   => $activity,
			'label'      => (string) ( $config['label'] ?? $activity ),
			'product_id' => $product_id,
			'index'      => $index,
			'format_key' => $format_key,
			'format'     => self::SUPPORTED_FORMATS[ $format_key ],
			'meta'       => $meta,
		];
	}

	/**
	 * Locate the date element inside a builder.
	 *
	 * Each position of the date arrays is one date element of the product, so the index
	 * is never assumed: it is looked up by internal name, and an ambiguous product is
	 * refused rather than guessed at.
	 *
	 * @param array<string,mixed> $builder      The product builder.
	 * @param string|null         $element_name Configured internal name, or null for single-element products.
	 * @return int|WP_Error
	 */
	private function find_date_element( array $builder, $element_name ) {
		$names = $builder['date_internal_name'] ?? null;

		if ( ! is_array( $names ) || [] === $names ) {
			return new WP_Error(
				'mikowsky_cierres_no_date_element',
				'El producto no tiene ningún elemento de fecha.',
				[ 'status' => 500 ]
			);
		}

		if ( null !== $element_name ) {
			$index = array_search( (string) $element_name, array_map( 'strval', $names ), true );
			if ( false === $index ) {
				return new WP_Error(
					'mikowsky_cierres_element_not_found',
					sprintf( 'El producto no tiene ningún elemento de fecha llamado "%s".', $element_name ),
					[ 'status' => 500 ]
				);
			}

			return (int) $index;
		}

		if ( count( $names ) > 1 ) {
			return new WP_Error(
				'mikowsky_cierres_ambiguous_element',
				sprintf(
					'El producto tiene %d elementos de fecha (%s); hay que fijar "element" en la configuración de la actividad.',
					count( $names ),
					implode( ', ', array_map( 'strval', $names ) )
				),
				[ 'status' => 500 ]
			);
		}

		return (int) array_key_first( $names );
	}

	/**
	 * The closed days of an activity, as ISO dates.
	 *
	 * Unparseable entries are reported instead of being dropped in silence, so a
	 * hand-edited value cannot quietly disappear the next time the list is written.
	 *
	 * @param array<string,mixed> $resolved Output of resolve().
	 * @return array{dates:array<int,string>,invalid:array<int,string>}
	 */
	public function closed_dates( array $resolved ) {
		$builder = $resolved['meta']['tmfbuilder'];
		$raw     = (string) ( $builder[ self::FIELD ][ $resolved['index'] ] ?? '' );

		$dates   = [];
		$invalid = [];

		foreach ( explode( ',', $raw ) as $piece ) {
			$piece = trim( $piece );
			if ( '' === $piece ) {
				continue;
			}

			$iso = self::to_iso( $piece, $resolved['format'] );
			if ( null === $iso ) {
				$invalid[] = $piece;
				continue;
			}

			$dates[] = $iso;
		}

		$dates = array_values( array_unique( $dates ) );
		sort( $dates );

		return [
			'dates'   => $dates,
			'invalid' => $invalid,
		];
	}

	/**
	 * The WPML languages covered by an activity.
	 *
	 * Translations are never written to — the single source of truth filter in the loader
	 * makes every language read the base product. They are listed so the front end can say
	 * which languages a closure covers, and `stale_own_value` flags a translation still
	 * holding its own disabled dates in `tm_meta_wpml`: harmless while the filter is in
	 * place, but worth knowing about.
	 *
	 * @param array<string,mixed> $resolved Output of resolve().
	 * @return array<int,array<string,mixed>>
	 */
	public function languages( array $resolved ) {
		global $sitepress;

		if ( ! is_object( $sitepress ) || ! method_exists( $sitepress, 'get_element_trid' ) ) {
			return [];
		}

		$trid = $sitepress->get_element_trid( $resolved['product_id'], 'post_product' );
		if ( ! $trid ) {
			return [];
		}

		$translations = $sitepress->get_element_translations( $trid, 'post_product' );
		if ( ! is_array( $translations ) ) {
			return [];
		}

		$out = [];
		foreach ( $translations as $language => $translation ) {
			$translated_id = (int) $translation->element_id;
			$wpml_meta     = get_post_meta( $translated_id, self::WPML_META_KEY, true );
			$has_own       = is_array( $wpml_meta )
				&& isset( $wpml_meta['tmfbuilder'][ self::FIELD ] );

			$out[] = [
				'language'        => (string) $language,
				'product_id'      => $translated_id,
				'is_source'       => $translated_id === $resolved['product_id'],
				'stale_own_value' => $has_own,
			];
		}

		// WPML returns translations in no particular order; the front end shows this list
		// to the client, so give it a stable one: source language first, then alphabetical.
		usort(
			$out,
			function ( $a, $b ) {
				return $b['is_source'] <=> $a['is_source']
					?: strcmp( $a['language'], $b['language'] );
			}
		);

		return $out;
	}

	/**
	 * Convert a stored date to ISO, or null when it does not parse.
	 *
	 * The round trip rejects loose input that DateTime would otherwise accept, such as
	 * 31/02/2026 rolling over into March.
	 *
	 * @param string $value  Date as stored in the builder.
	 * @param string $format PHP date format of the element.
	 * @return string|null
	 */
	public static function to_iso( $value, $format ) {
		$date = DateTime::createFromFormat( '!' . $format, $value );

		return ( $date && $date->format( $format ) === $value )
			? $date->format( 'Y-m-d' )
			: null;
	}

	/**
	 * Convert an ISO date to the format stored by the element, or null when invalid.
	 *
	 * @param string $iso    Date as YYYY-MM-DD.
	 * @param string $format PHP date format of the element.
	 * @return string|null
	 */
	public static function from_iso( $iso, $format ) {
		$date = DateTime::createFromFormat( '!Y-m-d', $iso );

		return ( $date && $date->format( 'Y-m-d' ) === $iso )
			? $date->format( $format )
			: null;
	}
}

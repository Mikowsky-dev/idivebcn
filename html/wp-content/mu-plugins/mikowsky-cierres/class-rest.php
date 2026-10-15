<?php
/**
 * REST controller for the closures calendar.
 *
 * @package Mikowsky\Cierres
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the closures calendar under the mikowsky/v1 namespace.
 */
final class Mikowsky_Cierres_Rest {

	/**
	 * REST namespace.
	 */
	const NS = 'mikowsky/v1';

	/**
	 * Writes allowed per user inside the rate limit window.
	 */
	const RATE_LIMIT = 40;

	/**
	 * Rate limit window, in seconds.
	 */
	const RATE_WINDOW = 300;

	/**
	 * What the client is told about how long the public site may lag behind.
	 *
	 * The nginx page cache cannot be purged from PHP (see Writer::purge), so a closure is
	 * authoritative the moment it is saved but may take up to `fastcgi_cache_valid` to show
	 * on the product page. Saying so is what keeps a working closure from looking broken.
	 */
	const CACHE_LAG_NOTICE = 'Guardado. La web pública puede tardar hasta una hora en mostrarlo, pero la reserva de ese día ya está bloqueada desde ahora.';

	/**
	 * Data layer.
	 *
	 * @var Mikowsky_Cierres_Store
	 */
	private $store;

	/**
	 * Write layer.
	 *
	 * @var Mikowsky_Cierres_Writer
	 */
	private $writer;

	/**
	 * Constructor.
	 *
	 * @param Mikowsky_Cierres_Store  $store  Data layer.
	 * @param Mikowsky_Cierres_Writer $writer Write layer.
	 */
	public function __construct( Mikowsky_Cierres_Store $store, Mikowsky_Cierres_Writer $writer ) {
		$this->store  = $store;
		$this->writer = $writer;
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/activities',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_activities' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::NS,
			'/calendar',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_calendar' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'activity' => $this->activity_arg(),
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'post_calendar' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'activity' => $this->activity_arg(),
						'date'     => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => [ $this, 'validate_date' ],
						],
						'action'   => [
							'required' => true,
							'type'     => 'string',
							'enum'     => [ 'close', 'open' ],
						],
					],
				],
			]
		);
	}

	/**
	 * Shared definition of the activity argument.
	 *
	 * @return array<string,mixed>
	 */
	private function activity_arg() {
		return [
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_key',
		];
	}

	/**
	 * Who may use the API.
	 *
	 * A real check, never __return_true: the endpoint is reachable from the internet and
	 * edits live product data. Works for both cookie plus nonce (the client page, served
	 * from WordPress itself) and application passwords.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission() {
		if ( current_user_can( MIKOWSKY_CIERRES_CAP ) ) {
			return true;
		}

		return is_user_logged_in()
			? new WP_Error(
				'mikowsky_cierres_forbidden',
				'Tu usuario no tiene permiso para gestionar cierres.',
				[ 'status' => 403 ]
			)
			: new WP_Error(
				'mikowsky_cierres_unauthorized',
				'Hay que identificarse para gestionar cierres.',
				[ 'status' => 401 ]
			);
	}

	/**
	 * A day must be a real ISO date, and not already past.
	 *
	 * Compared in the site timezone, not UTC: around midnight the two disagree, and the
	 * dive centre's today is the one that matters. Past days need no guard beyond this —
	 * the element already starts at tomorrow, so they are never bookable.
	 *
	 * @param mixed $value The submitted value.
	 * @return true|WP_Error
	 */
	public function validate_date( $value ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $value, wp_timezone() );

		if ( ! $date || $date->format( 'Y-m-d' ) !== (string) $value ) {
			return new WP_Error(
				'mikowsky_cierres_bad_date',
				'La fecha tiene que ser una fecha real en formato YYYY-MM-DD.',
				[ 'status' => 400 ]
			);
		}

		if ( $date < current_datetime()->setTime( 0, 0 ) ) {
			return new WP_Error(
				'mikowsky_cierres_past_date',
				'No se puede cambiar un día que ya ha pasado.',
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * GET /activities — the configured activities.
	 *
	 * Served from the configuration so the front end does not restate the map.
	 *
	 * @return WP_REST_Response
	 */
	public function get_activities() {
		return $this->respond( [ 'activities' => $this->store->activities() ] );
	}

	/**
	 * GET /calendar?activity=… — the closed days of one activity.
	 *
	 * Dates always travel as ISO YYYY-MM-DD, whatever format the product stores.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_calendar( WP_REST_Request $request ) {
		$resolved = $this->store->resolve( $request->get_param( 'activity' ) );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$closed    = $this->store->closed_dates( $resolved );
		$languages = $this->store->languages( $resolved );

		return $this->respond(
			[
				'activity'  => $resolved['activity'],
				'label'     => $resolved['label'],
				'closed'    => $closed['dates'],
				'languages' => wp_list_pluck( $languages, 'language' ),
				'warnings'  => $this->warnings( $closed['invalid'], $languages ),
			]
		);
	}

	/**
	 * POST /calendar — close or reopen one day.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_calendar( WP_REST_Request $request ) {
		$limited = $this->check_rate_limit();
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$activity = $request->get_param( 'activity' );
		$date     = $request->get_param( 'date' );
		$action   = $request->get_param( 'action' );

		$resolved = $this->store->resolve( $activity );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$result = $this->writer->apply( $resolved, $date, $action );

		Mikowsky_Cierres_Audit::record(
			[
				'activity'   => $resolved['activity'],
				'product_id' => $resolved['product_id'],
				'date'       => $date,
				'action'     => $action,
				'changed'    => ! is_wp_error( $result ) && $result['changed'],
				'error'      => is_wp_error( $result ) ? $result->get_error_code() : null,
			]
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$languages = $this->store->languages( $resolved );
		$codes     = wp_list_pluck( $languages, 'language' );
		$warnings  = $this->warnings( $result['kept_invalid'], $languages );

		if ( $result['changed'] ) {
			array_unshift( $warnings, self::CACHE_LAG_NOTICE );
		}

		return $this->respond(
			[
				'activity'  => $resolved['activity'],
				'label'     => $resolved['label'],
				'date'      => $date,
				'action'    => $action,
				'changed'   => $result['changed'],
				'closed'    => $result['closed'],
				'languages' => $codes,
				'warnings'  => $warnings,
			],
			$this->describe( $date, $action, $result['changed'], $codes )
		);
	}

	/**
	 * A sentence the client page can show as is.
	 *
	 * @param string            $date      Day, as ISO.
	 * @param string            $action    Either `close` or `open`.
	 * @param bool              $changed   Whether anything was written.
	 * @param array<int,string> $languages Language codes covered.
	 * @return string
	 */
	private function describe( $date, $action, $changed, array $languages ) {
		$day    = wp_date( 'j \d\e F \d\e Y', strtotime( $date . ' 12:00:00' ) );
		$idioms = strtoupper( implode( ' · ', $languages ) );

		if ( ! $changed ) {
			return 'close' === $action
				? sprintf( 'El %s ya estaba cerrado. No se ha cambiado nada.', $day )
				: sprintf( 'El %s ya estaba abierto. No se ha cambiado nada.', $day );
		}

		return 'close' === $action
			? sprintf( 'Cerrado el %s en %s.', $day, $idioms )
			: sprintf( 'Reabierto el %s en %s.', $day, $idioms );
	}

	/**
	 * Keep a stolen or shared login from rewriting the calendar in bulk.
	 *
	 * Counted per user over a sliding window. Generous enough that nobody clicking
	 * through a month of closures will notice.
	 *
	 * @return true|WP_Error
	 */
	private function check_rate_limit() {
		$key   = 'mikowsky_cierres_rl_' . get_current_user_id();
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return new WP_Error(
				'mikowsky_cierres_rate_limited',
				'Demasiados cambios seguidos. Espera unos minutos y vuelve a probar.',
				[ 'status' => 429 ]
			);
		}

		set_transient( $key, $count + 1, self::RATE_WINDOW );

		return true;
	}

	/**
	 * Build the human readable warnings of a response.
	 *
	 * @param array<int,string>              $invalid   Unparseable stored dates.
	 * @param array<int,array<string,mixed>> $languages Output of Store::languages().
	 * @return array<int,string>
	 */
	private function warnings( array $invalid, array $languages ) {
		$warnings = [];

		if ( [] !== $invalid ) {
			$warnings[] = sprintf(
				'El producto tiene %d fecha(s) guardada(s) que no se entienden y no se muestran: %s.',
				count( $invalid ),
				implode( ', ', $invalid )
			);
		}

		$stale = wp_list_pluck(
			array_filter(
				$languages,
				function ( $language ) {
					return ! empty( $language['stale_own_value'] );
				}
			),
			'language'
		);

		if ( [] !== $stale ) {
			$warnings[] = sprintf(
				'Las traducciones en %s guardan sus propias fechas deshabilitadas; se ignoran, pero conviene limpiarlas.',
				implode( ', ', $stale )
			);
		}

		return $warnings;
	}

	/**
	 * Wrap a payload in the project standard response shape.
	 *
	 * @param array<string,mixed> $data    Payload.
	 * @param string|null         $message Optional message.
	 * @return WP_REST_Response
	 */
	private function respond( array $data, $message = null ) {
		return new WP_REST_Response(
			[
				'status'  => 'success',
				'data'    => $data,
				'message' => $message,
				'errors'  => [],
			],
			200
		);
	}
}

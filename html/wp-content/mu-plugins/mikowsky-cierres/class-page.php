<?php
/**
 * Client facing page for the closures calendar.
 *
 * Served by WordPress itself rather than as a static file behind Traefik: same origin means
 * no CORS, and the REST endpoints authenticate with the login cookie plus a nonce, so no
 * secret ever reaches the browser.
 *
 * @package Mikowsky\Cierres
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes and renders the closures page.
 */
final class Mikowsky_Cierres_Page {

	/**
	 * Path the page answers on, relative to the site root.
	 */
	const PATH = 'calendario-cierres';

	/**
	 * Query var the rewrite rule sets.
	 */
	const QUERY_VAR = 'mikowsky_cierres';

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
	 * Hook the route.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', [ $this, 'add_route' ] );
		add_filter( 'query_vars', [ $this, 'add_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
	}

	/**
	 * Register the rewrite rule, flushing when it is not actually in place.
	 *
	 * mu-plugins have no activation hook, so the flush has to be conditional. The condition
	 * is the stored rules themselves rather than a "we already flushed" flag: a flag got the
	 * first version of this wrong — it was set in the same request that flushed, the rule did
	 * not land, and the guard then refused to retry, leaving the page a permanent 404. Asking
	 * whether the rule is in the table costs one lookup on an autoloaded option and heals
	 * itself on the next request, whatever else rewrote the rules.
	 *
	 * @return void
	 */
	public function add_route() {
		$pattern = '^' . self::PATH . '/?$';
		add_rewrite_rule( $pattern, 'index.php?' . self::QUERY_VAR . '=1', 'top' );

		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || ! isset( $rules[ $pattern ] ) ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Allow the query var through.
	 *
	 * @param array<int,string> $vars Registered query vars.
	 * @return array<int,string>
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Render the page when the route matches.
	 *
	 * @return void
	 */
	public function maybe_render() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		// Never cache this: it carries a REST nonce and a logged in user's data. The logged
		// in cookie already trips nginx's skip_cache, and no fastcgi_ignore_headers is set,
		// so these headers keep the page out of the page cache for anonymous hits too.
		nocache_headers();
		status_header( 200 );

		echo $this->document(); // phpcs:ignore WordPress.Security.EscapeOutput -- assembled below.
		exit;
	}

	/**
	 * The whole document.
	 *
	 * @return string
	 */
	private function document() {
		$environment = $this->environment_notice();

		return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="robots" content="noindex,nofollow">'
			. '<title>Calendario de cierres · ' . esc_html( get_bloginfo( 'name' ) ) . '</title>'
			. $this->favicon()
			. '<link rel="preconnect" href="https://fonts.googleapis.com">'
			. '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
			. '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap">'
			. '<link rel="stylesheet" href="' . esc_url( $this->asset( 'cierres.css' ) ) . '">'
			. '</head><body class="' . ( $environment ? 'has-env' : '' ) . '">'
			. ( $environment ? '<div class="env">' . esc_html( $environment ) . '</div>' : '' )
			. '<div class="wrap">' . $this->body() . '</div>'
			. '</body></html>';
	}

	/**
	 * Login card, refusal, or the application itself.
	 *
	 * @return string
	 */
	private function body() {
		if ( ! is_user_logged_in() ) {
			return $this->gate(
				'Calendario de cierres',
				'Acceso privado del centro.',
				wp_login_form(
					[
						'echo'     => false,
						'redirect' => home_url( '/' . self::PATH . '/' ),
						'label_username' => 'Usuario',
						'label_password' => 'Contraseña',
						'label_remember' => 'No cerrar sesión',
						'label_log_in'   => 'Entrar',
					]
				)
			);
		}

		if ( ! current_user_can( MIKOWSKY_CIERRES_CAP ) ) {
			return $this->gate(
				'Sin permiso',
				'Tu usuario existe pero no puede gestionar cierres. Pídele al administrador el rol «Gestor de cierres».',
				'<p class="note"><a href="' . esc_url( wp_logout_url( home_url( '/' . self::PATH . '/' ) ) ) . '">Salir</a></p>'
			);
		}

		return $this->app();
	}

	/**
	 * A centred card, for the states that are not the application.
	 *
	 * @param string $title    Heading.
	 * @param string $subtitle Explanation.
	 * @param string $inner    Markup below the explanation.
	 * @return string
	 */
	private function gate( $title, $subtitle, $inner ) {
		return '<div class="gate"><div class="box">'
			. $this->logo()
			. '<h2>' . esc_html( $title ) . '</h2>'
			. '<p>' . esc_html( $subtitle ) . '</p>'
			. $inner
			. '</div></div>';
	}

	/**
	 * The application markup, mirroring the approved mockup.
	 *
	 * @return string
	 */
	private function app() {
		$user = wp_get_current_user();

		return '<header class="top">'
			. $this->logo()
			. '<div><h1>Calendario de cierres</h1><div class="site">'
			. esc_html( get_bloginfo( 'name' ) . ' · ' . wp_parse_url( home_url(), PHP_URL_HOST ) )
			. '</div></div>'
			. '<a class="out" href="' . esc_url( wp_logout_url( home_url( '/' . self::PATH . '/' ) ) ) . '">'
			. esc_html( $user->display_name ) . ' · salir</a>'
			. '</header>'

			. '<div class="card">'
			. '<div class="sect"><div class="label">Actividad</div><div class="acts" id="acts"></div></div>'
			. '<div class="sect">'
			. '<div class="mnav">'
			. '<button class="nav-btn" id="prev" aria-label="Mes anterior">&lsaquo;</button>'
			. '<h2 id="month"></h2>'
			. '<button class="nav-btn" id="next" aria-label="Mes siguiente">&rsaquo;</button>'
			. '</div>'
			. '<div class="dow"><span>Lun</span><span>Mar</span><span>Mié</span><span>Jue</span>'
			. '<span>Vie</span><span>Sáb</span><span>Dom</span></div>'
			. '<div class="grid" id="grid"></div>'
			. '<div class="legend">'
			. '<span class="lg-open"><i></i>Disponible</span>'
			. '<span class="lg-closed"><i></i>Cerrado</span>'
			. '<span class="lg-pend"><i></i>Pendiente</span>'
			. '<span class="lg-today"><i></i>Hoy</span>'
			. '</div></div></div>'

			. '<div class="notice" id="warnings" hidden></div>'
			. '<p class="hint">Toca un día para <b>cerrarlo</b> (o <b>reabrirlo</b> si ya está cerrado). '
			. 'Al confirmar, el cambio se aplica a la web en <b>todos los idiomas</b>. '
			. 'Los días pasados no se pueden editar.</p>'

			. '<div class="bar" id="bar"><div class="bar-in">'
			. '<div class="summary" id="summary"></div>'
			. '<button class="btn ghost" id="cancel">Cancelar</button>'
			. '<button class="btn primary" id="confirm">Confirmar</button>'
			. '</div></div>'

			. '<div class="scrim" id="scrim"><div class="modal" role="dialog" aria-modal="true">'
			. '<h3>Confirmar cambios</h3><p id="modal-act"></p>'
			. '<div class="lines" id="modal-lines"></div>'
			. '<div class="wpml">🌐 <span>Se aplicará en todos los idiomas '
			. '(<b id="modal-langs"></b>).</span></div>'
			. '<div class="modal-actions">'
			. '<button class="btn ghost" id="back">Volver</button>'
			. '<button class="btn primary" id="apply">Aplicar</button>'
			. '</div></div></div>'

			. '<div class="toast" id="toast"></div>'

			. '<script>window.MIKOWSKY_CIERRES=' . wp_json_encode(
				[
					'rest'       => rest_url( Mikowsky_Cierres_Rest::NS ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'today'      => current_datetime()->format( 'Y-m-d' ),
					'activities' => $this->store->activities(),
				]
			) . ';</script>'
			. '<script src="' . esc_url( $this->asset( 'cierres.js' ) ) . '"></script>';
	}

	/**
	 * The centre's logo, taken from the site settings rather than pasted in.
	 *
	 * @return string
	 */
	private function logo() {
		$logo_id = get_theme_mod( 'custom_logo' );
		$url     = $logo_id ? wp_get_attachment_url( $logo_id ) : '';

		return $url
			? '<img class="brand" src="' . esc_url( $url ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '">'
			: '';
	}

	/**
	 * The site icon, so the browser does not go hunting for a /favicon.ico that is not there.
	 *
	 * @return string
	 */
	private function favicon() {
		$icon = get_site_icon_url( 32 );

		return $icon ? '<link rel="icon" href="' . esc_url( $icon ) . '">' : '';
	}

	/**
	 * URL of one of our assets, versioned by its modification time so a deploy is picked up
	 * despite the immutable cache headers nginx puts on static files.
	 *
	 * @param string $file File name inside assets/.
	 * @return string
	 */
	private function asset( $file ) {
		$path = __DIR__ . '/assets/' . $file;

		return add_query_arg(
			'v',
			file_exists( $path ) ? (string) filemtime( $path ) : '0',
			WPMU_PLUGIN_URL . '/mikowsky-cierres/assets/' . $file
		);
	}

	/**
	 * Banner text when this is not the production site, empty on production.
	 *
	 * Reads the host rather than a hardcoded flag so a copy of the database cannot end up
	 * claiming to be production.
	 *
	 * @return string
	 */
	private function environment_notice() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		return str_starts_with( $host, 'dev.' ) || str_contains( $host, '-dev' )
			? 'ENTORNO DE PRUEBAS · ' . $host
			: '';
	}
}

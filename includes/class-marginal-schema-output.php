<?php
/**
 * Udskriver JSON-LD i <head> på frontend.
 *
 * @package Marginal_Schema_Markup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Frontend-output.
 */
final class Marginal_Schema_Output {

	const OPTION_GLOBAL = 'marginal_schema_global_jsonld';
	const META_KEY      = '_marginal_schema_jsonld';

	/**
	 * Om WordPress er nået til at indlæse en skabelon i denne request.
	 *
	 * @var bool
	 */
	private static $template_request = false;

	/**
	 * Om print_global() er blevet kørt i denne request.
	 *
	 * @var bool
	 */
	private static $global_handled = false;

	/**
	 * Om print_post() er blevet kørt i denne request.
	 *
	 * @var bool
	 */
	private static $post_handled = false;

	/**
	 * Registrér hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );

		// Global JSON-LD tidligt i <head>, side-specifik helt nederst i <head>.
		add_action( 'wp_head', array( __CLASS__, 'print_global' ), 5 );
		add_action( 'wp_head', array( __CLASS__, 'print_post' ), PHP_INT_MAX );

		// template_include kører først, når WordPress rent faktisk skal vise en side – efter
		// redirects, feeds, robots.txt og HEAD-requests (som WordPress stopper før skabelonen).
		add_filter( 'template_include', array( __CLASS__, 'mark_template_request' ), PHP_INT_MAX );
		add_action( 'shutdown', array( __CLASS__, 'detect_missing_output' ) );
	}

	/**
	 * Hvilke indholdstyper har feltet. Standard er kun Pages.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = apply_filters( 'marginal_schema_post_types', array( 'page' ) );
		if ( ! is_array( $types ) ) {
			return array( 'page' );
		}
		return array_values( array_filter( array_map( 'sanitize_key', $types ) ) );
	}

	/**
	 * Registrér post meta. Nøglen starter med "_", så den er beskyttet og ikke vises
	 * under "Brugerdefinerede felter". Den eksponeres ikke i REST API'et.
	 */
	public static function register_meta() {
		foreach ( self::post_types() as $type ) {
			register_post_meta(
				$type,
				self::META_KEY,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => array( 'Marginal_Schema_Json', 'sanitize_input' ),
					// Feltet ændres kun via pluginnets eget felt (kun administratorer). Ingen andre veje –
					// XML-RPC, REST API eller "Brugerdefinerede felter" – må kunne læse eller ændre det.
					'auth_callback'     => '__return_false',
				)
			);
		}
	}

	/**
	 * Udskriv global JSON-LD.
	 */
	public static function print_global() {
		self::$global_handled = true;

		try {
			$raw = get_option( self::OPTION_GLOBAL, '' );
			if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
				return;
			}

			$html = Marginal_Schema_Json::build_script_tag( $raw, 'marginal-schema-global' );

			if ( is_wp_error( $html ) ) {
				Marginal_Schema_Logger::error( 'Global JSON-LD blev ikke udskrevet: ' . $html->get_error_message() );
				return;
			}

			if ( is_string( $html ) ) {
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- genkodet med JSON_HEX_TAG i Marginal_Schema_Json.
			}
		} catch ( \Throwable $e ) {
			Marginal_Schema_Logger::error( 'Uventet fejl ved global JSON-LD: ' . $e->getMessage() );
		}
	}

	/**
	 * Udskriv side-specifik JSON-LD.
	 */
	public static function print_post() {
		self::$post_handled = true;
		$post_id            = 0;

		try {
			$post_id = self::current_post_id();
			if ( ! $post_id ) {
				return;
			}

			$raw = get_post_meta( $post_id, self::META_KEY, true );
			if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
				return;
			}

			$html = Marginal_Schema_Json::build_script_tag( $raw, 'marginal-schema-page' );

			if ( is_wp_error( $html ) ) {
				Marginal_Schema_Logger::error(
					sprintf( 'JSON-LD blev ikke udskrevet på "%s": %s', self::post_label( $post_id ), $html->get_error_message() ),
					$post_id
				);
				return;
			}

			if ( is_string( $html ) ) {
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- genkodet med JSON_HEX_TAG i Marginal_Schema_Json.
			}
		} catch ( \Throwable $e ) {
			Marginal_Schema_Logger::error( 'Uventet fejl ved side-specifik JSON-LD: ' . $e->getMessage(), $post_id );
		}
	}

	/**
	 * Find ID på den side der vises, hvis den understøtter side-specifik JSON-LD.
	 *
	 * @return int
	 */
	private static function current_post_id() {
		$post_id = 0;

		if ( is_singular() ) {
			$post_id = (int) get_queried_object_id();
		} elseif ( is_home() && ! is_front_page() ) {
			// Siden der er valgt som "Indlægsside" under Indstillinger → Læsning.
			$post_id = (int) get_option( 'page_for_posts', 0 );
		}

		$post_id = (int) apply_filters( 'marginal_schema_current_post_id', $post_id );
		if ( $post_id <= 0 ) {
			return 0;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return 0;
		}

		// Læk ikke data fra kodeordsbeskyttede sider.
		if ( post_password_required( $post ) ) {
			return 0;
		}

		// Heller ikke fra private eller ikke-udgivne sider (fx en privat "Indlægsside"),
		// medmindre den besøgende selv må se siden.
		if ( ! is_post_publicly_viewable( $post ) && ! current_user_can( 'read_post', $post->ID ) ) {
			return 0;
		}

		return $post_id;
	}

	/**
	 * Markér at WordPress viser en side med en skabelon.
	 *
	 * @param string $template Skabelonens sti (returneres uændret).
	 * @return string
	 */
	public static function mark_template_request( $template ) {
		self::$template_request = true;
		return $template;
	}

	/**
	 * Log hvis JSON-LD skulle have været udskrevet, men ikke blev det – fordi temaet ikke
	 * kalder wp_head(), eller fordi et tema/plugin har fjernet pluginnets hook.
	 */
	public static function detect_missing_output() {
		try {
			// Normale sidevisninger slutter her med det samme.
			if ( ! self::$template_request || ( self::$global_handled && self::$post_handled ) ) {
				return;
			}
			if ( ! self::is_html_page_response() ) {
				return;
			}

			$reason = did_action( 'wp_head' )
				? 'et tema eller plugin har fjernet pluginnets udskrivning fra wp_head'
				: 'temaet/skabelonen ikke kalder wp_head()';

			if ( ! self::$global_handled ) {
				$raw = get_option( self::OPTION_GLOBAL, '' );
				if ( is_string( $raw ) && '' !== trim( $raw ) ) {
					Marginal_Schema_Logger::error( sprintf( 'Global JSON-LD blev ikke udskrevet, fordi %s.', $reason ) );
				}
			}

			if ( ! self::$post_handled ) {
				$post_id = self::current_post_id();
				$raw     = $post_id ? get_post_meta( $post_id, self::META_KEY, true ) : '';
				if ( is_string( $raw ) && '' !== trim( $raw ) ) {
					Marginal_Schema_Logger::error(
						sprintf( 'JSON-LD blev ikke udskrevet på "%s", fordi %s.', self::post_label( $post_id ), $reason ),
						$post_id
					);
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Er dette en almindelig HTML-sidevisning, hvor <head> burde være udskrevet?
	 *
	 * @return bool
	 */
	private static function is_html_page_response() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		// HEAD-requests (fx fra oppetidsovervågning) får aldrig et <head> – WordPress stopper før skabelonen.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'head' === $method ) {
			return false;
		}

		if ( is_feed() || is_embed() || is_robots() || is_favicon() || is_trackback() ) {
			return false;
		}

		// http_response_code() giver false uden for en web-request (fx WP-CLI).
		$status = function_exists( 'http_response_code' ) ? http_response_code() : false;
		if ( false !== $status && 200 !== (int) $status ) {
			return false;
		}

		// Kun HTML-svar (ikke fx filer eller JSON).
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'text/html' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Læsbar beskrivelse af en side til loggen.
	 *
	 * @param int $post_id Side-ID.
	 * @return string
	 */
	private static function post_label( $post_id ) {
		$title = get_the_title( $post_id );
		return ( '' !== $title ? $title : '(uden titel)' ) . ' (ID ' . (int) $post_id . ')';
	}
}

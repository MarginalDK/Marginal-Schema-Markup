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
	 * Om side-specifik JSON-LD forventedes udskrevet i denne request.
	 *
	 * @var int
	 */
	private static $pending_post_id = 0;

	/**
	 * Registrér hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );

		// Global JSON-LD tidligt i <head>, side-specifik helt nederst i <head>.
		add_action( 'wp_head', array( __CLASS__, 'print_global' ), 5 );
		add_action( 'wp_head', array( __CLASS__, 'print_post' ), PHP_INT_MAX );

		add_action( 'template_redirect', array( __CLASS__, 'remember_pending' ), PHP_INT_MAX );
		add_action( 'shutdown', array( __CLASS__, 'detect_missing_head' ) );
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
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', (int) $post_id );
					},
				)
			);
		}
	}

	/**
	 * Udskriv global JSON-LD.
	 */
	public static function print_global() {
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
		$post_id = 0;

		try {
			self::$pending_post_id = 0;

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

		return $post_id;
	}

	/**
	 * Husk om den aktuelle side har JSON-LD, så vi kan opdage hvis temaet aldrig kalder wp_head().
	 */
	public static function remember_pending() {
		try {
			$post_id = self::current_post_id();
			if ( $post_id ) {
				$raw = get_post_meta( $post_id, self::META_KEY, true );
				if ( is_string( $raw ) && '' !== trim( $raw ) ) {
					self::$pending_post_id = $post_id;
				}
			}
		} catch ( \Throwable $e ) {
			self::$pending_post_id = 0;
		}
	}

	/**
	 * Hvis siden har JSON-LD, men temaet/page builderen aldrig kaldte wp_head(), logges det.
	 */
	public static function detect_missing_head() {
		try {
			if ( ! self::$pending_post_id || did_action( 'wp_head' ) ) {
				return;
			}
			if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return;
			}
			if ( is_feed() || is_embed() || is_robots() || is_trackback() ) {
				return;
			}
			// http_response_code() giver false uden for en web-request (fx WP-CLI).
			$status = function_exists( 'http_response_code' ) ? http_response_code() : false;
			if ( false !== $status && 200 !== (int) $status ) {
				return;
			}
			// Kun HTML-svar (ikke redirects, filer o.l.).
			foreach ( headers_list() as $header ) {
				if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'text/html' ) ) {
					return;
				}
			}

			Marginal_Schema_Logger::error(
				sprintf(
					'JSON-LD kunne ikke udskrives på "%s", fordi temaet/skabelonen ikke kalder wp_head().',
					self::post_label( self::$pending_post_id )
				),
				self::$pending_post_id
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}
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

<?php
/**
 * Simpel fejl-log gemt i databasen.
 *
 * Loggen gemmes som en WordPress-option (ikke som fil), så den aldrig kan tilgås
 * offentligt via en URL. Identiske fejl samles i én linje, så loggen ikke vokser
 * ukontrolleret ved mange sidevisninger.
 *
 * @package Marginal_Schema_Markup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logger.
 */
final class Marginal_Schema_Logger {

	const OPTION = 'marginal_schema_log';

	/** Maksimalt antal linjer i loggen. Ældste fjernes først. */
	const MAX_ENTRIES = 100;

	/** Maksimal længde på en besked. */
	const MAX_MESSAGE_LENGTH = 500;

	/** En identisk fejl opdateres højst én gang pr. interval (sekunder) for at spare databaseskrivninger. */
	const THROTTLE = HOUR_IN_SECONDS;

	/**
	 * Nøgler der allerede er logget i denne request.
	 *
	 * @var array<string, bool>
	 */
	private static $seen = array();

	/**
	 * Skriv en fejl i loggen.
	 *
	 * @param string $message Beskrivelse af fejlen (ingen følsomme data).
	 * @param int    $post_id Relevant side-ID, 0 hvis global.
	 * @param string $level   'error', 'warning' eller 'info'.
	 */
	public static function error( $message, $post_id = 0, $level = 'error' ) {
		// Logning må aldrig selv kunne vælte siden.
		try {
			self::write( (string) $message, (int) $post_id, (string) $level );
		} catch ( \Throwable $e ) {
			// Bevidst tom: vi har intet sikkert sted at rapportere fejlen.
			unset( $e );
		}
	}

	/**
	 * Skriv en advarsel i loggen.
	 *
	 * @param string $message Beskrivelse.
	 * @param int    $post_id Side-ID.
	 */
	public static function warning( $message, $post_id = 0 ) {
		self::error( $message, $post_id, 'warning' );
	}

	/**
	 * Skriv en info-linje i loggen.
	 *
	 * @param string $message Beskrivelse.
	 * @param int    $post_id Side-ID.
	 */
	public static function info( $message, $post_id = 0 ) {
		self::error( $message, $post_id, 'info' );
	}

	/**
	 * Hent alle log-linjer, nyeste først.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_entries() {
		$entries = get_option( self::OPTION, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}

		$entries = array_values( array_filter( $entries, 'is_array' ) );
		usort(
			$entries,
			static function ( $a, $b ) {
				return (int) ( isset( $b['last'] ) ? $b['last'] : 0 ) - (int) ( isset( $a['last'] ) ? $a['last'] : 0 );
			}
		);

		return $entries;
	}

	/**
	 * Tøm loggen.
	 */
	public static function clear() {
		update_option( self::OPTION, array(), false );
	}

	/**
	 * Selve skrivningen.
	 *
	 * @param string $message Besked.
	 * @param int    $post_id Side-ID.
	 * @param string $level   Niveau.
	 */
	private static function write( $message, $post_id, $level ) {
		if ( ! in_array( $level, array( 'error', 'warning', 'info' ), true ) ) {
			$level = 'error';
		}

		$message = sanitize_text_field( $message );
		if ( function_exists( 'mb_substr' ) ) {
			$message = mb_substr( $message, 0, self::MAX_MESSAGE_LENGTH );
		} else {
			$message = substr( $message, 0, self::MAX_MESSAGE_LENGTH );
		}

		if ( '' === $message ) {
			return;
		}

		$key = md5( $level . '|' . $post_id . '|' . $message );
		if ( isset( self::$seen[ $key ] ) ) {
			return;
		}
		self::$seen[ $key ] = true;

		$now     = time();
		$entries = get_option( self::OPTION, array() );
		if ( ! is_array( $entries ) ) {
			$entries = array();
		}

		if ( isset( $entries[ $key ] ) && is_array( $entries[ $key ] ) ) {
			$last = isset( $entries[ $key ]['last'] ) ? (int) $entries[ $key ]['last'] : 0;
			if ( ( $now - $last ) < self::THROTTLE ) {
				return;
			}
			$entries[ $key ]['last']  = $now;
			$entries[ $key ]['count'] = ( isset( $entries[ $key ]['count'] ) ? (int) $entries[ $key ]['count'] : 1 ) + 1;
			$entries[ $key ]['url']   = self::current_url();
		} else {
			$entries[ $key ] = array(
				'level'   => $level,
				'message' => $message,
				'post_id' => $post_id,
				'url'     => self::current_url(),
				'first'   => $now,
				'last'    => $now,
				'count'   => 1,
			);
		}

		// Behold kun de nyeste linjer.
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			uasort(
				$entries,
				static function ( $a, $b ) {
					return (int) ( isset( $b['last'] ) ? $b['last'] : 0 ) - (int) ( isset( $a['last'] ) ? $a['last'] : 0 );
				}
			);
			$entries = array_slice( $entries, 0, self::MAX_ENTRIES, true );
		}

		// autoload = false: loggen skal ikke indlæses på hver sidevisning.
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $entries, '', false );
		} else {
			update_option( self::OPTION, $entries, false );
		}
	}

	/**
	 * Den aktuelle URL-sti (uden query string, for at undgå at logge tokens o.l.).
	 *
	 * @return string
	 */
	private static function current_url() {
		if ( wp_doing_cron() ) {
			return '(cron)';
		}
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		$uri  = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- saniteres nedenfor.
		$path = wp_parse_url( (string) $uri, PHP_URL_PATH );

		return is_string( $path ) ? substr( sanitize_text_field( $path ), 0, 255 ) : '';
	}
}

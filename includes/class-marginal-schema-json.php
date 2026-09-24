<?php
/**
 * Validering, normalisering og sikker udskrivning af JSON-LD.
 *
 * SIKKERHED: Brugerens tekst udskrives ALDRIG direkte. Den afkodes altid med
 * json_decode() og kodes igen med wp_json_encode() og JSON_HEX_TAG, så tegnene
 * < og > altid unicode-escapes og aldrig står som rå tegn i outputtet. Det gør det umuligt at bryde ud af
 * <script>-tagget (fx med "</script><script>...") selv hvis nogen indsætter
 * ondsindet indhold.
 *
 * @package Marginal_Schema_Markup
 */

defined( 'ABSPATH' ) || exit;

/**
 * JSON-hjælper.
 */
final class Marginal_Schema_Json {

	/** Maksimal størrelse på indsat JSON-LD (bytes). */
	const MAX_BYTES = 200000;

	/** Maksimal indlejringsdybde ved afkodning. */
	const MAX_DEPTH = 128;

	/** Tegn der tæller som mellemrum i HTML-tags. */
	const WHITESPACE = " \t\n\r\f\x0B";

	/** Bogstaver, tal og _ (samme som \w i regulære udtryk). */
	const WORD_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';

	/**
	 * Rens brugerens input før det gemmes. Indholdet gemmes som tekst, også hvis det
	 * er ugyldigt, så brugeren ikke mister sit arbejde. Det valideres igen ved udskrivning.
	 *
	 * Størrelsen tjekkes af kalderen med check_size() FØR denne funktion, så for store
	 * værdier afvises med en tydelig besked i stedet for at blive klippet over.
	 *
	 * @param mixed $raw Rå input (allerede unslashed).
	 * @return string
	 */
	public static function sanitize_input( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		// Fjern null-bytes og ugyldig UTF-8.
		$raw = str_replace( "\0", '', $raw );
		$raw = (string) wp_check_invalid_utf8( $raw, true );

		// For store værdier behandles ikke yderligere – decode() afviser dem med en tydelig besked.
		if ( strlen( $raw ) <= self::MAX_BYTES ) {
			$raw = self::strip_script_wrappers( $raw );
		}

		return trim( $raw );
	}

	/**
	 * Tjek størrelsen på JSON-LD.
	 *
	 * @param string $raw Tekst.
	 * @return true|WP_Error
	 */
	public static function check_size( $raw ) {
		$bytes = strlen( (string) $raw );
		if ( $bytes <= self::MAX_BYTES ) {
			return true;
		}

		return new WP_Error(
			'marginal_schema_size',
			sprintf( 'JSON-LD er for stor (%1$d KB – maks. %2$d KB).', (int) ceil( $bytes / 1000 ), (int) ( self::MAX_BYTES / 1000 ) )
		);
	}

	/**
	 * Hvis brugeren har indsat hele <script type="application/ld+json">...</script>-blokke,
	 * trækkes JSON-indholdet ud. Flere blokke samles i et JSON-array.
	 *
	 * Bevidst uden regulære udtryk: koden kører i lineær tid, så mærkeligt input
	 * (fx tusindvis af "<script>" uden lukke-tag) ikke kan få serveren til at gå i stå.
	 *
	 * @param string $raw Input.
	 * @return string
	 */
	public static function strip_script_wrappers( $raw ) {
		// Kun hvis feltet starter med et tag. Gyldig JSON starter aldrig med "<", og tekst som
		// "</script>" inde i en JSON-streng må ikke forveksles med en indpakning.
		if ( 0 !== stripos( ltrim( $raw ), '<script' ) ) {
			return $raw;
		}

		$parts  = array();
		$found  = 0;
		$offset = 0;

		while ( true ) {
			$open = stripos( $raw, '<script', $offset );
			if ( false === $open ) {
				break;
			}

			// "<scripts" o.l. er ikke et script-tag (der skal være en ordgrænse efter "<script").
			$next = substr( $raw, $open + 7, 1 );
			if ( '' !== $next && false !== strpos( self::WORD_CHARS, $next ) ) {
				$offset = $open + 7;
				continue;
			}

			$tag_end = strpos( $raw, '>', $open );
			if ( false === $tag_end ) {
				break;
			}

			$close = self::find_closing_tag( $raw, $tag_end + 1 );
			if ( null === $close ) {
				break;
			}

			++$found;
			$inner = trim( substr( $raw, $tag_end + 1, $close[0] - $tag_end - 1 ) );
			if ( '' !== $inner ) {
				$parts[] = $inner;
			}
			$offset = $close[1];
		}

		if ( 0 === $found ) {
			return $raw;
		}

		if ( empty( $parts ) ) {
			return '';
		}

		if ( 1 === count( $parts ) ) {
			return $parts[0];
		}

		return "[\n" . implode( ",\n", $parts ) . "\n]";
	}

	/**
	 * Find det næste lukke-tag "</script>" (evt. med mellemrum før ">") fra $offset.
	 *
	 * @param string $raw    Tekst.
	 * @param int    $offset Startposition.
	 * @return int[]|null Array med [start på lukke-tagget, position lige efter det], eller null.
	 */
	private static function find_closing_tag( $raw, $offset ) {
		$length = strlen( $raw );

		while ( $offset < $length ) {
			$end = strpos( $raw, '>', $offset );
			if ( false === $end ) {
				return null;
			}

			// Gå baglæns over mellemrum og tjek om der står "</script" lige før ">".
			$pos = $end - 1;
			while ( $pos >= $offset && false !== strpos( self::WHITESPACE, $raw[ $pos ] ) ) {
				--$pos;
			}
			$start = $pos - 7;
			if ( $start >= $offset && 0 === strncasecmp( substr( $raw, $start, 8 ), '</script', 8 ) ) {
				return array( $start, $end + 1 );
			}

			$offset = $end + 1;
		}

		return null;
	}

	/**
	 * Valider JSON-LD.
	 *
	 * Valideringen bygger det rigtige output, så "gyldig" altid betyder "bliver udskrevet".
	 *
	 * @param string $raw Gemt tekst.
	 * @return true|WP_Error True hvis gyldig (eller tom), ellers WP_Error med dansk forklaring.
	 */
	public static function validate( $raw ) {
		$result = self::build_script_tag( $raw, 'marginal-schema' );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Afkod JSON-LD.
	 *
	 * @param string $raw Gemt tekst.
	 * @return mixed|null|WP_Error Afkodet værdi, null hvis tom, WP_Error hvis ugyldig.
	 */
	public static function decode( $raw ) {
		if ( ! is_string( $raw ) ) {
			return new WP_Error( 'marginal_schema_type', 'Værdien er ikke tekst.' );
		}

		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}

		$size = self::check_size( $raw );
		if ( is_wp_error( $size ) ) {
			return $size;
		}

		// Objekter afkodes som stdClass (ikke arrays), så tomme objekter {} bevares som {} ved genkodning.
		$data = json_decode( $raw, false, self::MAX_DEPTH );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'marginal_schema_syntax', 'Ugyldig JSON: ' . self::translate_error( json_last_error() ) );
		}

		if ( ! is_object( $data ) && ! is_array( $data ) ) {
			return new WP_Error( 'marginal_schema_structure', 'JSON-LD skal være et objekt { ... } eller en liste [ ... ].' );
		}

		return $data;
	}

	/**
	 * Lav den færdige, sikre <script>-blok.
	 *
	 * @param string $raw       Gemt tekst.
	 * @param string $css_class CSS-klasse til script-tagget (til fejlsøgning i kildekoden).
	 * @return string|null|WP_Error HTML, null hvis intet at udskrive, WP_Error ved fejl.
	 */
	public static function build_script_tag( $raw, $css_class ) {
		$data = self::decode( $raw );

		if ( null === $data || is_wp_error( $data ) ) {
			return $data;
		}

		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
		$json  = wp_json_encode( $data, $flags, self::MAX_DEPTH );

		if ( ! is_string( $json ) || '' === $json ) {
			return new WP_Error( 'marginal_schema_encode', 'JSON-LD kan ikke udskrives: ' . self::translate_error( json_last_error() ) );
		}

		// Ekstra sikkerhedsnet: der må aldrig forekomme "<" i outputtet.
		if ( false !== strpos( $json, '<' ) ) {
			return new WP_Error( 'marginal_schema_unsafe', 'Output indeholdt usikre tegn og blev blokeret.' );
		}

		return '<script type="application/ld+json" class="' . esc_attr( $css_class ) . '">' . $json . "</script>\n";
	}

	/**
	 * Afkort tekst til højst $max_bytes uden at klippe et tegn (fx æ, ø, å) midt over.
	 * Halve tegn giver ugyldig UTF-8, som databasen afviser.
	 *
	 * @param string $text      Tekst.
	 * @param int    $max_bytes Maks. antal bytes.
	 * @return string
	 */
	public static function truncate_utf8( $text, $max_bytes ) {
		$text = (string) $text;
		if ( strlen( $text ) <= $max_bytes ) {
			return $text;
		}

		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $text, 0, $max_bytes, 'UTF-8' );
		}

		// Uden mbstring: klip, og fjern et evt. overklippet tegn til sidst.
		return (string) preg_replace( '/[\xC0-\xFF][\x80-\xBF]*\z/', '', substr( $text, 0, $max_bytes ) );
	}

	/**
	 * Oversæt json_last_error() til dansk.
	 *
	 * @param int $code Fejlkode.
	 * @return string
	 */
	private static function translate_error( $code ) {
		switch ( $code ) {
			case JSON_ERROR_DEPTH:
				return 'for mange niveauer af indlejring.';
			case JSON_ERROR_STATE_MISMATCH:
			case JSON_ERROR_SYNTAX:
				return 'syntaksfejl. Tjek for manglende kommaer, anførselstegn eller klammer, og at der ikke er et komma efter sidste element.';
			case JSON_ERROR_CTRL_CHAR:
				return 'ugyldigt kontroltegn (fx et linjeskift inde i en tekststreng).';
			case JSON_ERROR_UTF8:
				return 'ugyldige tegn (forkert tegnkodning).';
			case JSON_ERROR_INF_OR_NAN:
				return 'et tal er for stort til at kunne gengives (fx 1e400). Skriv meget store tal som tekst i anførselstegn.';
			default:
				return 'ukendt fejl (' . (int) $code . ').';
		}
	}
}

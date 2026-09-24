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

	/**
	 * Rens brugerens input før det gemmes. Indholdet gemmes som tekst, også hvis det
	 * er ugyldigt, så brugeren ikke mister sit arbejde. Det valideres igen ved udskrivning.
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
		$raw = wp_check_invalid_utf8( $raw, true );

		$raw = self::strip_script_wrappers( $raw );
		$raw = trim( $raw );

		if ( strlen( $raw ) > self::MAX_BYTES ) {
			$raw = substr( $raw, 0, self::MAX_BYTES );
			// Kan have klippet et multibyte-tegn over.
			$raw = wp_check_invalid_utf8( $raw, true );
		}

		return $raw;
	}

	/**
	 * Hvis brugeren har indsat hele <script type="application/ld+json">...</script>-blokke,
	 * trækkes JSON-indholdet ud. Flere blokke samles i et JSON-array.
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

		if ( ! preg_match_all( '#<script\b[^>]*>(.*?)</script\s*>#is', $raw, $matches ) || empty( $matches[1] ) ) {
			return $raw;
		}

		$parts = array();
		foreach ( $matches[1] as $inner ) {
			$inner = trim( $inner );
			if ( '' !== $inner ) {
				$parts[] = $inner;
			}
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
	 * Valider JSON-LD.
	 *
	 * @param string $raw Gemt tekst.
	 * @return true|WP_Error True hvis gyldig (eller tom), ellers WP_Error med dansk forklaring.
	 */
	public static function validate( $raw ) {
		$result = self::decode( $raw );
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

		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return new WP_Error( 'marginal_schema_size', 'JSON-LD er for stor (maks. 200 KB).' );
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
			return new WP_Error( 'marginal_schema_encode', 'JSON kunne ikke kodes til udskrivning.' );
		}

		// Ekstra sikkerhedsnet: der må aldrig forekomme "<" i outputtet.
		if ( false !== strpos( $json, '<' ) ) {
			return new WP_Error( 'marginal_schema_unsafe', 'Output indeholdt usikre tegn og blev blokeret.' );
		}

		return '<script type="application/ld+json" class="' . esc_attr( $css_class ) . '">' . $json . "</script>\n";
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
			default:
				return 'ukendt fejl (' . (int) $code . ').';
		}
	}
}

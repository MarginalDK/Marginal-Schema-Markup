<?php
/**
 * Opdateringer fra GitHub-releases.
 *
 * Bruger WordPress' officielle "Update URI"-mekanisme (filteret update_plugins_{hostname},
 * WordPress 5.8+). "Update URI" i plugin-headeren sikrer også, at WordPress.org aldrig
 * kan "overtage" pluginnet med et andet plugin der tilfældigvis har samme navn.
 *
 * SIKKERHED:
 * - Der hentes kun fra det faste repository MarginalDK/Marginal-Schema-Markup over HTTPS
 *   (SSL-certifikatet verificeres af WordPress).
 * - Download-URL'en valideres, og en pakke til dette plugin afvises, hvis den ikke kommer
 *   fra repositoryets egne releases.
 * - Pakken tjekkes for at indeholde pluginnets hovedfil, før den gamle version fjernes.
 *   Er pakken ødelagt, afbrydes opdateringen, og den nuværende version bevares.
 *
 * @package Marginal_Schema_Markup
 */

defined( 'ABSPATH' ) || exit;

/**
 * GitHub-updater.
 */
final class Marginal_Schema_Updater {

	const REPO_URL   = 'https://github.com/MarginalDK/Marginal-Schema-Markup';
	const API_URL    = 'https://api.github.com/repos/MarginalDK/Marginal-Schema-Markup/releases/latest';
	const RAW_URL    = 'https://raw.githubusercontent.com/MarginalDK/Marginal-Schema-Markup/';
	const ASSET_NAME = 'marginal-schema-markup.zip';
	const TRANSIENT  = 'marginal_schema_release';

	/** Hvor længe et vellykket tjek caches (sekunder). GitHub tillader 60 kald/time uden login. */
	const CACHE_TTL = 21600;

	/** Hvor længe der ventes efter et fejlet tjek. */
	const ERROR_TTL = 3600;

	/** Maks. pakkestørrelse (bytes) – beskyttelse mod forkerte filer. */
	const MAX_PACKAGE_SIZE = 20971520;

	/**
	 * Registrér hooks.
	 */
	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'filter_update' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verify_package_url' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'source_selection' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_update' ), 10, 2 );
	}

	/**
	 * Pluginnets basename, fx "marginal-schema-markup/marginal-schema-markup.php".
	 *
	 * @return string
	 */
	public static function basename() {
		return plugin_basename( MARGINAL_SCHEMA_FILE );
	}

	/**
	 * Mappenavn / slug for den installerede version.
	 *
	 * @return string
	 */
	public static function slug() {
		$dir = dirname( self::basename() );
		return ( '.' === $dir || '' === $dir ) ? 'marginal-schema-markup' : $dir;
	}

	/**
	 * Giv WordPress besked om den nyeste version.
	 *
	 * @param array|false $update      Eksisterende data.
	 * @param array       $plugin_data Plugin-headers.
	 * @param string      $plugin_file Plugin basename.
	 * @param string[]    $locales     Sprog.
	 * @return array|false
	 */
	public static function filter_update( $update, $plugin_data, $plugin_file, $locales ) {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}

		try {
			$release = self::get_release();
			if ( ! $release ) {
				return $update;
			}

			return array(
				'id'           => self::REPO_URL,
				'slug'         => self::slug(),
				'plugin'       => $plugin_file,
				'version'      => $release['version'],
				'url'          => $release['html_url'],
				'package'      => $release['package'],
				'requires'     => $release['requires'],
				'requires_php' => $release['requires_php'],
				'tested'       => $release['tested'],
				'icons'        => array(),
				'banners'      => array(),
				'translations' => array(),
			);
		} catch ( \Throwable $e ) {
			Marginal_Schema_Logger::error( 'Opdateringstjek fejlede: ' . $e->getMessage() );
			return $update;
		}
	}

	/**
	 * Data til "Vis detaljer"-vinduet på plugin-siden.
	 *
	 * @param false|object|array $result Resultat.
	 * @param string             $action Handling.
	 * @param object             $args   Argumenter.
	 * @return false|object|array
	 */
	public static function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || self::slug() !== $args->slug ) {
			return $result;
		}

		try {
			$release = self::get_release();
			if ( ! $release ) {
				return $result;
			}

			$changelog = '' !== $release['body']
				? wpautop( esc_html( $release['body'] ) )
				: '<p>' . esc_html__( 'Ingen beskrivelse af denne version.', 'marginal-schema-markup' ) . '</p>';

			return (object) array(
				'name'          => 'Marginal Schema Markup',
				'slug'          => self::slug(),
				'version'       => $release['version'],
				'author'        => '<a href="https://marginal.dk">Marginal</a>',
				'homepage'      => self::REPO_URL,
				'requires'      => $release['requires'],
				'requires_php'  => $release['requires_php'],
				'tested'        => $release['tested'],
				'last_updated'  => $release['published'],
				'download_link' => $release['package'],
				'sections'      => array(
					'description' => '<p>' . esc_html__( 'Tilføjer global og side-specifik JSON-LD schema markup i <head>.', 'marginal-schema-markup' ) . '</p>',
					'changelog'   => $changelog,
				),
			);
		} catch ( \Throwable $e ) {
			return $result;
		}
	}

	/**
	 * Afvis download af en pakke til dette plugin, hvis den ikke kommer fra vores GitHub-releases.
	 *
	 * @param bool|WP_Error $reply      Standard: false (fortsæt).
	 * @param string        $package    URL til pakken.
	 * @param WP_Upgrader   $upgrader   Upgrader.
	 * @param array         $hook_extra Ekstra data.
	 * @return bool|WP_Error
	 */
	public static function verify_package_url( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( false !== $reply || ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
			return $reply;
		}

		if ( ! self::is_allowed_package_url( (string) $package ) ) {
			Marginal_Schema_Logger::error( 'Opdatering afvist: pakken kom ikke fra pluginnets GitHub-releases.' );
			return new WP_Error( 'marginal_schema_untrusted_package', __( 'Opdateringen blev afvist, fordi pakken ikke kommer fra Marginal Schema Markups GitHub-releases.', 'marginal-schema-markup' ) );
		}

		return $reply;
	}

	/**
	 * Sørg for at den udpakkede mappe har samme navn som den installerede, og at pakken er gyldig.
	 *
	 * @param string|WP_Error $source        Udpakket mappe.
	 * @param string          $remote_source Overordnet midlertidig mappe.
	 * @param WP_Upgrader     $upgrader      Upgrader.
	 * @param array           $hook_extra    Ekstra data.
	 * @return string|WP_Error
	 */
	public static function source_selection( $source, $remote_source, $upgrader = null, $hook_extra = array() ) {
		if ( is_wp_error( $source ) || ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! is_object( $wp_filesystem ) ) {
			return $source;
		}

		$main_file = basename( self::basename() );

		if ( ! $wp_filesystem->exists( trailingslashit( $source ) . $main_file ) ) {
			Marginal_Schema_Logger::error( 'Opdatering afbrudt: pakken indeholder ikke ' . $main_file . '. Den nuværende version er bevaret.' );
			return new WP_Error( 'marginal_schema_invalid_package', __( 'Opdateringspakken er ugyldig (hovedfilen mangler). Den nuværende version er bevaret.', 'marginal-schema-markup' ) );
		}

		$desired = trailingslashit( $remote_source ) . self::slug();
		if ( untrailingslashit( $source ) === $desired ) {
			return $source;
		}

		if ( $wp_filesystem->exists( $desired ) ) {
			$wp_filesystem->delete( $desired, true );
		}

		if ( ! $wp_filesystem->move( untrailingslashit( $source ), $desired, true ) ) {
			Marginal_Schema_Logger::error( 'Opdatering afbrudt: den udpakkede mappe kunne ikke omdøbes.' );
			return new WP_Error( 'marginal_schema_rename_failed', __( 'Opdateringen kunne ikke gennemføres (mappen kunne ikke omdøbes). Den nuværende version er bevaret.', 'marginal-schema-markup' ) );
		}

		return trailingslashit( $desired );
	}

	/**
	 * Ryd cache efter en opdatering.
	 *
	 * @param WP_Upgrader $upgrader Upgrader.
	 * @param array       $options  Data.
	 */
	public static function after_update( $upgrader, $options ) {
		if ( ! is_array( $options ) || empty( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return;
		}

		$plugins = array();
		if ( ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
			$plugins = $options['plugins'];
		} elseif ( ! empty( $options['plugin'] ) ) {
			$plugins = array( $options['plugin'] );
		}

		if ( in_array( self::basename(), $plugins, true ) ) {
			delete_site_transient( self::TRANSIENT );
		}
	}

	/**
	 * Tving et nyt tjek (knappen på indstillingssiden).
	 */
	public static function force_check() {
		delete_site_transient( self::TRANSIENT );
		self::get_release();

		// Få WordPress til at genopbygge sin liste over tilgængelige opdateringer.
		delete_site_transient( 'update_plugins' );
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
	}

	/**
	 * Cachede release-data uden at kontakte GitHub (til visning).
	 *
	 * @return array|null
	 */
	public static function get_cached_release() {
		$cached = get_site_transient( self::TRANSIENT );
		return ( is_array( $cached ) && empty( $cached['error'] ) ) ? $cached : null;
	}

	/**
	 * Hent nyeste release (cachet).
	 *
	 * @return array|null
	 */
	public static function get_release() {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return empty( $cached['error'] ) ? $cached : null;
		}

		$release = self::fetch_release();

		if ( is_wp_error( $release ) ) {
			Marginal_Schema_Logger::warning( 'Kunne ikke tjekke for opdateringer på GitHub: ' . $release->get_error_message() );
			set_site_transient(
				self::TRANSIENT,
				array(
					'error'   => true,
					'checked' => time(),
				),
				self::ERROR_TTL
			);
			return null;
		}

		set_site_transient( self::TRANSIENT, $release, self::CACHE_TTL );
		return $release;
	}

	/**
	 * Kontakt GitHub og valider svaret.
	 *
	 * @return array|WP_Error
	 */
	private static function fetch_release() {
		$response = wp_safe_remote_get(
			self::API_URL,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'user-agent'  => 'Marginal-Schema-Markup/' . MARGINAL_SCHEMA_VERSION,
				'headers'     => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'marginal_schema_http', 'Netværksfejl: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return new WP_Error( 'marginal_schema_404', 'Ingen udgivet release fundet (eller repository er privat).' );
		}
		if ( 403 === $code || 429 === $code ) {
			return new WP_Error( 'marginal_schema_rate', 'GitHub afviste forespørgslen (sandsynligvis for mange kald). Prøver igen senere.' );
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'marginal_schema_status', 'Uventet svar fra GitHub (HTTP ' . $code . ').' );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'marginal_schema_json', 'Svaret fra GitHub kunne ikke læses.' );
		}

		if ( ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return new WP_Error( 'marginal_schema_prerelease', 'Nyeste release er en kladde eller pre-release.' );
		}

		$tag = isset( $data['tag_name'] ) && is_string( $data['tag_name'] ) ? trim( $data['tag_name'] ) : '';
		if ( ! preg_match( '/^v?(\d+\.\d+(?:\.\d+)?)$/', $tag, $m ) ) {
			return new WP_Error( 'marginal_schema_tag', 'Release-tag har et ugyldigt format (forventet fx v1.2.3).' );
		}
		$version = $m[1];

		$package = '';
		if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				if ( ! is_array( $asset ) || ! isset( $asset['name'], $asset['browser_download_url'] ) || self::ASSET_NAME !== $asset['name'] ) {
					continue;
				}
				if ( isset( $asset['state'] ) && 'uploaded' !== $asset['state'] ) {
					continue;
				}
				$size = isset( $asset['size'] ) ? (int) $asset['size'] : 0;
				if ( $size <= 0 || $size > self::MAX_PACKAGE_SIZE ) {
					continue;
				}
				$url = (string) $asset['browser_download_url'];
				if ( self::is_allowed_package_url( $url ) ) {
					$package = $url;
					break;
				}
			}
		}

		if ( '' === $package ) {
			return new WP_Error( 'marginal_schema_asset', 'Release ' . $tag . ' mangler filen ' . self::ASSET_NAME . '.' );
		}

		$html_url = isset( $data['html_url'] ) && is_string( $data['html_url'] ) && 0 === strpos( $data['html_url'], self::REPO_URL . '/' )
			? $data['html_url']
			: self::REPO_URL . '/releases';

		$body = isset( $data['body'] ) && is_string( $data['body'] ) ? $data['body'] : '';
		$body = substr( wp_strip_all_tags( $body ), 0, 20000 );

		$release = array(
			'version'      => $version,
			'tag'          => $tag,
			'package'      => $package,
			'html_url'     => $html_url,
			'body'         => $body,
			'published'    => isset( $data['published_at'] ) && is_string( $data['published_at'] ) ? sanitize_text_field( $data['published_at'] ) : '',
			'requires'     => MARGINAL_SCHEMA_MIN_WP,
			'requires_php' => MARGINAL_SCHEMA_MIN_PHP,
			'tested'       => '',
			'checked'      => time(),
		);

		// Læs krav (Requires PHP m.m.) fra den nye versions plugin-header, så WordPress
		// selv blokerer opdateringen hvis serveren ikke opfylder kravene.
		$headers = self::fetch_remote_headers( $tag );
		if ( is_array( $headers ) ) {
			if ( ! empty( $headers['version'] ) && $headers['version'] !== $version ) {
				return new WP_Error( 'marginal_schema_version_mismatch', 'Release-tag (' . $version . ') matcher ikke versionen i pluginfilen (' . $headers['version'] . '). Ret releasen på GitHub.' );
			}
			foreach ( array( 'requires', 'requires_php', 'tested' ) as $key ) {
				if ( ! empty( $headers[ $key ] ) ) {
					$release[ $key ] = $headers[ $key ];
				}
			}
		}

		return $release;
	}

	/**
	 * Hent plugin-headeren fra den taggede version på GitHub.
	 *
	 * @param string $tag Valideret tag.
	 * @return array|null
	 */
	private static function fetch_remote_headers( $tag ) {
		$response = wp_safe_remote_get(
			self::RAW_URL . rawurlencode( $tag ) . '/marginal-schema-markup.php',
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'limit_response_size' => 16384,
				'user-agent'          => 'Marginal-Schema-Markup/' . MARGINAL_SCHEMA_VERSION,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$content = substr( (string) wp_remote_retrieve_body( $response ), 0, 8192 );
		$map     = array(
			'version'      => 'Version',
			'requires'     => 'Requires at least',
			'requires_php' => 'Requires PHP',
			'tested'       => 'Tested up to',
		);
		$result  = array();

		foreach ( $map as $key => $header ) {
			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':[ \t]*([0-9][0-9.]*)[ \t]*\r?$/mi', $content, $m ) ) {
				$result[ $key ] = $m[1];
			}
		}

		return $result;
	}

	/**
	 * Kun pakker fra dette repositorys egne releases er tilladt.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_allowed_package_url( $url ) {
		$prefix = self::REPO_URL . '/releases/download/';
		if ( 0 !== strpos( $url, $prefix ) ) {
			return false;
		}

		$rest = substr( $url, strlen( $prefix ) );

		// Forventet format: <tag>/marginal-schema-markup.zip – ingen "..", query strings e.l.
		return (bool) preg_match( '#^v?\d+\.\d+(?:\.\d+)?/' . preg_quote( self::ASSET_NAME, '#' ) . '$#', $rest );
	}
}

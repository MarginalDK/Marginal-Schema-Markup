<?php
/**
 * Fælles basisklasse for alle tests.
 *
 * @package Marginal_Schema_Markup
 */

/**
 * Kastes i stedet for at redirecte, så testen ikke stopper ved exit.
 */
class Marginal_Schema_Test_Redirect extends Exception {
}

/**
 * Basisklasse.
 */
abstract class Marginal_Schema_TestCase extends WP_UnitTestCase {

	/**
	 * Nulstil pluginnets tilstand før hver test.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Marginal_Schema_Output::OPTION_GLOBAL );
		delete_option( Marginal_Schema_Logger::OPTION );
		delete_site_transient( Marginal_Schema_Updater::TRANSIENT );
		$this->reset_logger_request_cache();

		// Sikkerhedsnet: ingen test må lave rigtige HTTP-kald. Tests der har brug for
		// HTTP, registrerer selv et pre_http_request-filter med højere prioritet.
		add_filter( 'pre_http_request', array( $this, 'block_real_http' ), 999, 3 );
	}

	/**
	 * Blokér rigtige HTTP-kald.
	 *
	 * @param mixed  $pre  Kortslutning.
	 * @param array  $args Argumenter.
	 * @param string $url  URL.
	 * @return mixed
	 */
	public function block_real_http( $pre, $args, $url ) {
		if ( false !== $pre ) {
			return $pre;
		}
		return new WP_Error( 'http_blocked_in_tests', 'Rigtige HTTP-kald er blokeret i testene: ' . $url );
	}

	/**
	 * Kør en admin-post-handler og returnér URL'en den redirecter til.
	 *
	 * @param callable $handler Handler.
	 * @return string
	 */
	protected function run_redirecting_handler( callable $handler ) {
		$throw = static function ( $location ) {
			throw new Marginal_Schema_Test_Redirect( $location );
		};
		add_filter( 'wp_redirect', $throw );
		try {
			$handler();
		} catch ( Marginal_Schema_Test_Redirect $e ) {
			return $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $throw );
		}
		$this->fail( 'Handleren redirectede ikke.' );
		return '';
	}

	/**
	 * Mock GitHub-API'et.
	 *
	 * @param array|null  $release Release-data (null = HTTP-fejl via $code).
	 * @param string|null $header  Indhold af plugin-filen på raw.githubusercontent.com (null = 404).
	 * @param int         $code    HTTP-statuskode for API-kaldet.
	 * @return object Tæller med ->api og ->raw antal kald.
	 */
	protected function mock_github( $release, $header = null, $code = 200 ) {
		$calls = (object) array(
			'api' => 0,
			'raw' => 0,
		);
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $release, $header, $code, $calls ) {
				if ( 0 === strpos( $url, Marginal_Schema_Updater::API_URL ) ) {
					++$calls->api;
					return array(
						'headers'  => array(),
						'body'     => null === $release ? '{"message":"Not Found"}' : wp_json_encode( $release ),
						'response' => array(
							'code'    => $code,
							'message' => '',
						),
						'cookies'  => array(),
					);
				}
				if ( 0 === strpos( $url, Marginal_Schema_Updater::RAW_URL ) ) {
					++$calls->raw;
					return array(
						'headers'  => array(),
						'body'     => null === $header ? '404: Not Found' : $header,
						'response' => array(
							'code'    => null === $header ? 404 : 200,
							'message' => '',
						),
						'cookies'  => array(),
					);
				}
				return $pre;
			},
			10,
			3
		);
		return $calls;
	}

	/**
	 * Et gyldigt release-svar fra GitHub.
	 *
	 * @param string $tag       Tag.
	 * @param array  $overrides Overskrivninger.
	 * @return array
	 */
	protected function release( $tag = 'v9.9.9', $overrides = array() ) {
		return array_merge(
			array(
				'tag_name'     => $tag,
				'name'         => $tag,
				'draft'        => false,
				'prerelease'   => false,
				'html_url'     => Marginal_Schema_Updater::REPO_URL . '/releases/tag/' . $tag,
				'published_at' => '2026-01-01T12:00:00Z',
				'body'         => 'Rettelser og forbedringer.',
				'assets'       => array(
					array(
						'name'                 => Marginal_Schema_Updater::ASSET_NAME,
						'state'                => 'uploaded',
						'size'                 => 50000,
						'browser_download_url' => Marginal_Schema_Updater::REPO_URL . '/releases/download/' . $tag . '/' . Marginal_Schema_Updater::ASSET_NAME,
					),
				),
			),
			$overrides
		);
	}

	/**
	 * Loggerens "allerede logget i denne request"-cache.
	 */
	protected function reset_logger_request_cache() {
		$prop = new ReflectionProperty( 'Marginal_Schema_Logger', 'seen' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( null, array() );
	}

	/**
	 * Fang output fra en funktion.
	 *
	 * @param callable $callback Funktion.
	 * @return string
	 */
	protected function capture( callable $callback ) {
		ob_start();
		try {
			$callback();
		} finally {
			$output = (string) ob_get_clean();
		}
		return $output;
	}

	/**
	 * Hele <head>-outputtet for den aktuelle forespørgsel.
	 *
	 * @return string
	 */
	protected function render_head() {
		return $this->capture( 'wp_head' );
	}

	/**
	 * Alle JSON-LD-blokke fra pluginnet i en HTML-streng.
	 *
	 * @param string $html HTML.
	 * @return array<int, array{class: string, type: string, json: string}>
	 */
	protected function extract_blocks( $html ) {
		preg_match_all( '#<script\b([^>]*)>(.*?)</script>#is', $html, $m, PREG_SET_ORDER );
		$blocks = array();
		foreach ( $m as $match ) {
			if ( false === strpos( $match[1], 'marginal-schema' ) ) {
				continue;
			}
			preg_match( '#type="([^"]*)"#', $match[1], $type );
			preg_match( '#class="([^"]*)"#', $match[1], $class );
			$blocks[] = array(
				'type'  => isset( $type[1] ) ? $type[1] : '',
				'class' => isset( $class[1] ) ? $class[1] : '',
				'json'  => $match[2],
			);
		}
		return $blocks;
	}

	/**
	 * Opret en side med JSON-LD (gemt som WordPress ville gemme den).
	 *
	 * @param string $json JSON-LD.
	 * @param array  $args Ekstra post-argumenter.
	 * @return int
	 */
	protected function create_page_with_jsonld( $json, $args = array() ) {
		$post_id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => 'Testside',
				),
				$args
			)
		);
		update_post_meta( $post_id, Marginal_Schema_Output::META_KEY, wp_slash( $json ) );
		return $post_id;
	}

	/**
	 * Log-beskeder som en flad liste.
	 *
	 * @return string[]
	 */
	protected function log_messages() {
		return array_map(
			static function ( $entry ) {
				return (string) $entry['message'];
			},
			Marginal_Schema_Logger::get_entries()
		);
	}

	/**
	 * Log ind som en bruger med en given rolle.
	 *
	 * @param string $role Rolle.
	 * @return int
	 */
	protected function login_as( $role ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}
}

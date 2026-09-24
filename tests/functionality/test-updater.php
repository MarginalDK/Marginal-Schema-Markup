<?php
/**
 * Tests af opdatering fra GitHub-releases (alle HTTP-kald er mocket).
 *
 * @package Marginal_Schema_Markup
 */

/**
 * @covers Marginal_Schema_Updater
 */
class Test_Marginal_Schema_Updater extends Marginal_Schema_TestCase {

	const HEADER = "<?php\n/**\n * Plugin Name: Marginal Schema Markup\n * Version:           9.9.9\n * Requires at least: 6.0\n * Requires PHP:      8.1\n * Tested up to:      7.1\n */\n";

	private function filter_update() {
		return Marginal_Schema_Updater::filter_update( false, array(), Marginal_Schema_Updater::basename(), array() );
	}

	public function test_plugin_is_installed_in_expected_folder() {
		$this->assertSame( 'marginal-schema-markup/marginal-schema-markup.php', Marginal_Schema_Updater::basename() );
		$this->assertSame( 'marginal-schema-markup', Marginal_Schema_Updater::slug() );
	}

	public function test_update_hook_uses_update_uri_hostname() {
		$data = get_file_data( MARGINAL_SCHEMA_FILE, array( 'uri' => 'Update URI' ) );
		$host = wp_parse_url( $data['uri'], PHP_URL_HOST );
		$this->assertSame( 'github.com', $host );
		$this->assertNotFalse( has_filter( 'update_plugins_' . $host, array( 'Marginal_Schema_Updater', 'filter_update' ) ) );
	}

	public function test_valid_release_is_offered() {
		$this->mock_github( $this->release(), self::HEADER );
		$update = $this->filter_update();

		$this->assertIsArray( $update );
		$this->assertSame( '9.9.9', $update['version'] );
		$this->assertSame( Marginal_Schema_Updater::REPO_URL . '/releases/download/v9.9.9/marginal-schema-markup.zip', $update['package'] );
		$this->assertSame( 'marginal-schema-markup', $update['slug'] );
	}

	public function test_requirements_are_read_from_new_version() {
		$this->mock_github( $this->release(), self::HEADER );
		$update = $this->filter_update();

		$this->assertSame( '8.1', $update['requires_php'], 'WordPress blokerer selv opdateringen hvis serverens PHP er for gammel.' );
		$this->assertSame( '6.0', $update['requires'] );
		$this->assertSame( '7.1', $update['tested'] );
	}

	public function test_requirements_default_when_raw_file_unavailable() {
		$this->mock_github( $this->release(), null );
		$update = $this->filter_update();

		$this->assertSame( '9.9.9', $update['version'] );
		$this->assertSame( MARGINAL_SCHEMA_MIN_PHP, $update['requires_php'] );
	}

	public function test_tag_without_v_prefix_is_accepted() {
		$this->mock_github( $this->release( '9.9.9' ), null );
		$this->assertSame( '9.9.9', $this->filter_update()['version'] );
	}

	public function test_other_plugins_are_not_affected() {
		$calls  = $this->mock_github( $this->release() );
		$result = Marginal_Schema_Updater::filter_update( false, array(), 'andet-plugin/andet-plugin.php', array() );
		$this->assertFalse( $result );
		$this->assertSame( 0, $calls->api );
	}

	public function test_successful_check_is_cached() {
		$calls = $this->mock_github( $this->release(), null );
		$this->filter_update();
		$this->filter_update();
		$this->filter_update();
		$this->assertSame( 1, $calls->api );
	}

	public function test_failed_check_is_cached_and_logged() {
		$calls = $this->mock_github( null, null, 500 );
		$this->assertFalse( $this->filter_update() );
		$this->assertFalse( $this->filter_update() );
		$this->assertSame( 1, $calls->api, 'Et fejlet tjek må ikke gentages ved hver sidevisning.' );
		$this->assertStringContainsString( 'HTTP 500', implode( ' ', $this->log_messages() ) );
	}

	/**
	 * @dataProvider rejected_release_provider
	 *
	 * @param array  $overrides Ændringer i release-svaret.
	 * @param string $expected  Forventet tekst i loggen.
	 */
	public function test_bad_releases_are_rejected( $overrides, $expected ) {
		$this->mock_github( $this->release( 'v9.9.9', $overrides ), null );
		$this->assertFalse( $this->filter_update() );
		$this->assertStringContainsString( $expected, implode( ' ', $this->log_messages() ) );
	}

	/**
	 * @return array
	 */
	public function rejected_release_provider() {
		$asset = static function ( $changes ) {
			return array(
				'assets' => array(
					array_merge(
						array(
							'name'                 => 'marginal-schema-markup.zip',
							'state'                => 'uploaded',
							'size'                 => 50000,
							'browser_download_url' => 'https://github.com/MarginalDK/Marginal-Schema-Markup/releases/download/v9.9.9/marginal-schema-markup.zip',
						),
						$changes
					),
				),
			);
		};

		return array(
			'kladde'                 => array( array( 'draft' => true ), 'kladde' ),
			'pre-release'            => array( array( 'prerelease' => true ), 'pre-release' ),
			'ugyldigt tag'           => array( array( 'tag_name' => 'nyeste' ), 'ugyldigt format' ),
			'tag med shell-tegn'     => array( array( 'tag_name' => 'v1.0.0;rm -rf' ), 'ugyldigt format' ),
			'ingen assets'           => array( array( 'assets' => array() ), 'mangler filen' ),
			'forkert filnavn'        => array( $asset( array( 'name' => 'andet.zip' ) ), 'mangler filen' ),
			'ikke færdig-uploadet'   => array( $asset( array( 'state' => 'starter' ) ), 'mangler filen' ),
			'tom fil'                => array( $asset( array( 'size' => 0 ) ), 'mangler filen' ),
			'for stor fil'           => array( $asset( array( 'size' => 999999999 ) ), 'mangler filen' ),
			'fremmed domæne'         => array( $asset( array( 'browser_download_url' => 'https://evil.example/marginal-schema-markup.zip' ) ), 'mangler filen' ),
			'andet GitHub-repo'      => array( $asset( array( 'browser_download_url' => 'https://github.com/evil/Marginal-Schema-Markup/releases/download/v9.9.9/marginal-schema-markup.zip' ) ), 'mangler filen' ),
			'http i stedet for https' => array( $asset( array( 'browser_download_url' => 'http://github.com/MarginalDK/Marginal-Schema-Markup/releases/download/v9.9.9/marginal-schema-markup.zip' ) ), 'mangler filen' ),
			'path traversal'         => array( $asset( array( 'browser_download_url' => 'https://github.com/MarginalDK/Marginal-Schema-Markup/releases/download/v9.9.9/../../../../evil/marginal-schema-markup.zip' ) ), 'mangler filen' ),
			'query string'           => array( $asset( array( 'browser_download_url' => 'https://github.com/MarginalDK/Marginal-Schema-Markup/releases/download/v9.9.9/marginal-schema-markup.zip?x=https://evil.example' ) ), 'mangler filen' ),
		);
	}

	public function test_version_mismatch_between_tag_and_plugin_file_is_rejected() {
		$this->mock_github( $this->release( 'v9.9.9' ), str_replace( '9.9.9', '9.9.8', self::HEADER ) );
		$this->assertFalse( $this->filter_update() );
		$this->assertStringContainsString( 'matcher ikke', implode( ' ', $this->log_messages() ) );
	}

	/**
	 * @dataProvider http_error_provider
	 *
	 * @param int    $code     HTTP-kode.
	 * @param string $expected Tekst i loggen.
	 */
	public function test_http_errors_are_handled( $code, $expected ) {
		$this->mock_github( null, null, $code );
		$this->assertFalse( $this->filter_update() );
		$this->assertStringContainsString( $expected, implode( ' ', $this->log_messages() ) );
	}

	/**
	 * @return array
	 */
	public function http_error_provider() {
		return array(
			'ikke fundet'  => array( 404, 'Ingen udgivet release' ),
			'rate limit'   => array( 403, 'for mange kald' ),
			'for mange'    => array( 429, 'for mange kald' ),
			'serverfejl'   => array( 502, 'HTTP 502' ),
		);
	}

	public function test_network_error_is_handled() {
		// Standard-filteret fra testcase blokerer alle rigtige kald med en WP_Error.
		$this->assertFalse( $this->filter_update() );
		$this->assertStringContainsString( 'Netværksfejl', implode( ' ', $this->log_messages() ) );
	}

	public function test_garbage_response_is_handled() {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => '<html>ikke json</html>',
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
				);
			}
		);
		$this->assertFalse( $this->filter_update() );
	}

	public function test_wordpress_registers_the_update() {
		$this->mock_github( $this->release(), null );
		// WordPress.org svarer at der ikke er opdateringer til andre plugins.
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, 'api.wordpress.org/plugins/update-check' ) ) {
					return array(
						'headers'  => array(),
						'body'     => '{"plugins":[],"translations":[],"no_update":[]}',
						'response' => array( 'code' => 200 ),
						'cookies'  => array(),
					);
				}
				return $pre;
			},
			10,
			3
		);

		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );

		$this->assertArrayHasKey( Marginal_Schema_Updater::basename(), $updates->response );
		$this->assertSame( '9.9.9', $updates->response[ Marginal_Schema_Updater::basename() ]->new_version );
	}

	public function test_same_version_is_not_offered_as_update() {
		$this->mock_github( $this->release( 'v' . MARGINAL_SCHEMA_VERSION ), null );
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, 'api.wordpress.org/plugins/update-check' ) ) {
					return array(
						'headers'  => array(),
						'body'     => '{"plugins":[],"translations":[],"no_update":[]}',
						'response' => array( 'code' => 200 ),
						'cookies'  => array(),
					);
				}
				return $pre;
			},
			10,
			3
		);

		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );

		$this->assertArrayNotHasKey( Marginal_Schema_Updater::basename(), $updates->response );
		$this->assertArrayHasKey( Marginal_Schema_Updater::basename(), $updates->no_update, 'Nødvendigt for at auto-opdatering kan slås til.' );
	}

	public function test_plugins_api_returns_details_for_our_slug_only() {
		$this->mock_github( $this->release(), null );

		$info = Marginal_Schema_Updater::plugins_api( false, 'plugin_information', (object) array( 'slug' => 'marginal-schema-markup' ) );
		$this->assertIsObject( $info );
		$this->assertSame( '9.9.9', $info->version );
		$this->assertArrayHasKey( 'changelog', $info->sections );

		$this->assertFalse( Marginal_Schema_Updater::plugins_api( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );
		$this->assertFalse( Marginal_Schema_Updater::plugins_api( false, 'query_plugins', (object) array( 'slug' => 'marginal-schema-markup' ) ) );
	}

	public function test_package_url_verification() {
		$ours  = array( 'plugin' => Marginal_Schema_Updater::basename() );
		$other = array( 'plugin' => 'andet/andet.php' );
		$good  = Marginal_Schema_Updater::REPO_URL . '/releases/download/v1.2.3/marginal-schema-markup.zip';
		$bad   = 'https://evil.example/marginal-schema-markup.zip';

		$this->assertFalse( Marginal_Schema_Updater::verify_package_url( false, $good, null, $ours ) );
		$this->assertWPError( Marginal_Schema_Updater::verify_package_url( false, $bad, null, $ours ) );
		$this->assertFalse( Marginal_Schema_Updater::verify_package_url( false, $bad, null, $other ), 'Andre plugins må ikke påvirkes.' );
	}

	public function test_cache_is_cleared_after_update() {
		set_site_transient( Marginal_Schema_Updater::TRANSIENT, array( 'version' => '1.0.0' ), HOUR_IN_SECONDS );
		Marginal_Schema_Updater::after_update(
			null,
			array(
				'type'    => 'plugin',
				'plugins' => array( Marginal_Schema_Updater::basename() ),
			)
		);
		$this->assertFalse( get_site_transient( Marginal_Schema_Updater::TRANSIENT ) );
	}

	public function test_cache_is_kept_after_other_plugin_update() {
		set_site_transient( Marginal_Schema_Updater::TRANSIENT, array( 'version' => '1.0.0' ), HOUR_IN_SECONDS );
		Marginal_Schema_Updater::after_update(
			null,
			array(
				'type'    => 'plugin',
				'plugins' => array( 'andet/andet.php' ),
			)
		);
		$this->assertIsArray( get_site_transient( Marginal_Schema_Updater::TRANSIENT ) );
	}
}

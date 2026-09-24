<?php
/**
 * Sikkerhed: det må være umuligt at injicere HTML/JavaScript via JSON-LD-felterne.
 *
 * Hvis du tilføjer ny output-logik, så tilføj også angrebsstrenge her.
 *
 * @package Marginal_Schema_Markup
 */

/**
 * @coversNothing
 */
class Test_Marginal_Schema_Security_Xss extends Marginal_Schema_TestCase {

	/**
	 * Angrebsstrenge som placeres inde i en JSON-streng.
	 *
	 * @return array
	 */
	public function payload_provider() {
		return array(
			'lukke script-tag'          => array( '</script><script>alert(1)</script>' ),
			'lukke script, store bogst' => array( '</SCRIPT><SCRIPT>alert(1)</SCRIPT>' ),
			'lukke script med mellemrum' => array( '</script ><img src=x onerror=alert(1)>' ),
			'html-kommentar'            => array( '<!--<script>' ),
			'CDATA'                     => array( ']]><script>alert(1)</script>' ),
			'img onerror'               => array( '<img src=x onerror=alert(1)>' ),
			'svg onload'                => array( '<svg/onload=alert(1)>' ),
			'lukke head'                => array( '</head><body onload=alert(1)>' ),
			'html entities'             => array( '&lt;/script&gt;&#60;script&#62;' ),
			'unicode linjeskift'        => array( "a\u{2028}b\u{2029}c" ),
			'null-byte'                 => array( "a\\u0000</script>" ),
			'javascript-url'            => array( 'javascript:alert(document.cookie)' ),
			'php-tag'                   => array( '<?php echo 1; ?>' ),
			'escaped slash'             => array( '<\/script><script>alert(1)<\/script>' ),
		);
	}

	/**
	 * Tjek at et <head> kun indeholder ufarlige JSON-LD-blokke fra pluginnet.
	 *
	 * @param string $head      HTML.
	 * @param int    $expected  Forventet antal blokke.
	 * @param string $payload   Angrebsstrengen.
	 */
	private function assert_safe_head( $head, $expected, $payload ) {
		$blocks = $this->extract_blocks( $head );
		$this->assertCount( $expected, $blocks );

		foreach ( $blocks as $block ) {
			$this->assertSame( 'application/ld+json', $block['type'] );
			$this->assertStringNotContainsString( '<', $block['json'], 'Rå "<" i JSON-LD-output åbner for XSS.' );
			$this->assertStringNotContainsString( '>', $block['json'] );
			$this->assertNotNull( json_decode( $block['json'] ), 'Output skal være gyldig JSON.' );
		}

		// Fjern pluginnets (ufarlige) JSON-blokke. Resten af <head> må ikke indeholde noget
		// fra angrebsstrengen – ellers er den sluppet ud af JSON-strengen.
		$outside = preg_replace( '#<script type="application/ld\+json" class="marginal-schema-[a-z]+">[^<]*</script>#', '', $head );
		foreach ( array( 'alert(', 'onerror', 'onload', '<?php', '<img', '<svg', '<body', '<!--<script' ) as $marker ) {
			$this->assertStringNotContainsString( $marker, $outside, "Angrebet slap ud af JSON-LD: $marker" );
		}
		unset( $payload );
	}

	/**
	 * @dataProvider payload_provider
	 *
	 * @param string $payload Angreb.
	 */
	public function test_global_jsonld_cannot_inject_html( $payload ) {
		$json = wp_json_encode( array( '@type' => 'Organization', 'name' => $payload, 'nested' => array( 'x' => array( $payload ) ) ) );
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, $json );

		$this->go_to( home_url( '/' ) );
		$head = $this->render_head();
		$this->assert_safe_head( $head, 1, $payload );

		// Og data skal stadig være præcis det brugeren skrev.
		$blocks = $this->extract_blocks( $head );
		$this->assertSame( json_decode( $json, true ), json_decode( $blocks[0]['json'], true ) );
	}

	/**
	 * @dataProvider payload_provider
	 *
	 * @param string $payload Angreb.
	 */
	public function test_page_jsonld_cannot_inject_html( $payload ) {
		$json    = wp_json_encode( array( '@type' => 'WebPage', 'name' => $payload ) );
		$page_id = $this->create_page_with_jsonld( $json );

		$this->go_to( get_permalink( $page_id ) );
		$this->assert_safe_head( $this->render_head(), 1, $payload );
	}

	/**
	 * @dataProvider payload_provider
	 *
	 * @param string $payload Angreb.
	 */
	public function test_payload_in_object_keys_is_neutralised( $payload ) {
		$json = wp_json_encode( array( $payload => 'x' ) );
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, $json );

		$this->go_to( home_url( '/' ) );
		$this->assert_safe_head( $this->render_head(), 1, $payload );
	}

	public function test_raw_html_instead_of_json_is_never_output() {
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, '<script>alert(1)</script>' );
		$page_id = $this->create_page_with_jsonld( '"></script><script>alert(1)</script>' );

		$this->go_to( get_permalink( $page_id ) );
		$head = $this->render_head();

		$this->assertCount( 0, $this->extract_blocks( $head ) );
		$this->assertStringNotContainsString( 'alert(1)', $head );
	}

	public function test_data_stored_directly_in_database_is_still_escaped() {
		// Hvis nogen skriver direkte i databasen (uden om sanitize), må output stadig ikke kunne misbruges.
		global $wpdb;
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $page_id,
				'meta_key'   => Marginal_Schema_Output::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => '{"a":"</script><script>alert(1)</script>"}', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		wp_cache_delete( $page_id, 'post_meta' );

		$this->go_to( get_permalink( $page_id ) );
		$this->assert_safe_head( $this->render_head(), 1, '' );
	}

	public function test_admin_textarea_cannot_be_broken_out_of() {
		$this->login_as( 'administrator' );
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, '</textarea><script>alert(1)</script>' );

		$html = $this->capture( array( 'Marginal_Schema_Admin', 'render_page' ) );

		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertSame( 1, substr_count( $html, '</textarea>' ) );
	}

	public function test_log_view_escapes_everything() {
		$this->login_as( 'administrator' );
		// Skriv direkte i loggen, uden om loggerens egen sanering (forsvar i dybden).
		update_option(
			Marginal_Schema_Logger::OPTION,
			array(
				'k' => array(
					'level'   => '"><script>alert(1)</script>',
					'message' => '<script>alert(1)</script>',
					'url'     => '<img src=x onerror=alert(1)>',
					'post_id' => '1 OR 1=1',
					'last'    => time(),
					'count'   => '<b>9</b>',
				),
			),
			false
		);
		$_GET['tab'] = 'log';
		$html        = $this->capture( array( 'Marginal_Schema_Admin', 'render_page' ) );
		$_GET        = array();

		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<b>9</b>', $html );
	}

	public function test_logger_strips_html_from_messages() {
		Marginal_Schema_Logger::error( 'Fejl <script>alert(1)</script> på side' );
		$this->assertStringNotContainsString( '<script>', $this->log_messages()[0] );
	}

	public function test_release_notes_from_github_are_escaped() {
		$this->mock_github(
			$this->release( 'v9.9.9', array( 'body' => "Nyt\n<script>alert(1)</script><img src=x onerror=alert(1)>" ) ),
			null
		);
		$info = Marginal_Schema_Updater::plugins_api( false, 'plugin_information', (object) array( 'slug' => Marginal_Schema_Updater::slug() ) );

		$this->assertStringNotContainsString( '<script', $info->sections['changelog'] );
		$this->assertStringNotContainsString( '<img', $info->sections['changelog'] );
	}

	public function test_release_html_url_cannot_point_elsewhere() {
		$this->mock_github( $this->release( 'v9.9.9', array( 'html_url' => 'javascript:alert(1)' ) ), null );
		$update = Marginal_Schema_Updater::filter_update( false, array(), Marginal_Schema_Updater::basename(), array() );
		$this->assertStringStartsWith( Marginal_Schema_Updater::REPO_URL, $update['url'] );
	}
}

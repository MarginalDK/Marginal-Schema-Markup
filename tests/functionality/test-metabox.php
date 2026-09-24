<?php
/**
 * Tests af feltet på redigeringsskærmen for Pages.
 *
 * @package Marginal_Schema_Markup
 */

/**
 * @covers Marginal_Schema_Metabox
 */
class Test_Marginal_Schema_Metabox extends Marginal_Schema_TestCase {

	/**
	 * @var int
	 */
	private $page_id;

	public function set_up() {
		parent::set_up();
		$this->login_as( 'administrator' );
		$this->page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
	}

	public function tear_down() {
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Simulér at redigeringsformularen for siden sendes og gemmes.
	 *
	 * @param string      $value JSON.
	 * @param string|null $nonce Nonce (null = gyldig).
	 */
	private function submit( $value, $nonce = null ) {
		$_POST = $this->metabox_post( $this->page_id, $value, $nonce );
		Marginal_Schema_Metabox::save( $this->page_id, get_post( $this->page_id ) );
	}

	private function stored( $post_id = null ) {
		return get_post_meta( null === $post_id ? $this->page_id : $post_id, Marginal_Schema_Output::META_KEY, true );
	}

	private function meta_boxes_for( $role ) {
		global $wp_meta_boxes;
		$wp_meta_boxes = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->login_as( $role );
		set_current_screen( 'page' );
		Marginal_Schema_Metabox::add( 'page' );
		Marginal_Schema_Metabox::add( 'post' );

		return $wp_meta_boxes;
	}

	public function test_meta_box_is_added_for_pages_only() {
		$boxes = $this->meta_boxes_for( 'administrator' );
		$this->assertArrayHasKey( 'marginal-schema-markup', $boxes['page']['normal']['default'] );
		$this->assertArrayNotHasKey( 'post', $boxes );
	}

	/**
	 * @dataProvider non_admin_roles
	 *
	 * @param string $role Rolle.
	 */
	public function test_meta_box_is_hidden_for_non_admins( $role ) {
		$this->assertSame( array(), $this->meta_boxes_for( $role ) );
	}

	/**
	 * @return array
	 */
	public function non_admin_roles() {
		return array(
			'editor'      => array( 'editor' ),
			'author'      => array( 'author' ),
			'contributor' => array( 'contributor' ),
		);
	}

	public function test_assets_are_only_loaded_for_admins() {
		set_current_screen( 'page' );

		$this->login_as( 'editor' );
		Marginal_Schema_Metabox::enqueue( 'post.php' );
		$this->assertFalse( wp_script_is( 'marginal-schema-admin', 'enqueued' ) );

		$this->login_as( 'administrator' );
		Marginal_Schema_Metabox::enqueue( 'post.php' );
		$this->assertTrue( wp_script_is( 'marginal-schema-admin', 'enqueued' ) );

		wp_dequeue_script( 'marginal-schema-admin' );
		wp_dequeue_style( 'marginal-schema-admin' );
	}

	public function test_valid_json_is_saved() {
		$json = '{"@context":"https://schema.org","@type":"WebPage"}';
		$this->submit( $json );
		$this->assertSame( $json, $this->stored() );
	}

	public function test_backslashes_and_quotes_survive_saving() {
		$json = '{"name":"Citat: \"hej\"","path":"C:\\\\mappe","nl":"a\nb","u":"\u00e6"}';
		$this->submit( $json );
		$this->assertSame( $json, $this->stored() );
		$this->assertTrue( Marginal_Schema_Json::validate( $this->stored() ) );
	}

	public function test_script_wrapper_is_removed_on_save() {
		$this->submit( '<script type="application/ld+json">{"@type":"WebPage"}</script>' );
		$this->assertSame( '{"@type":"WebPage"}', $this->stored() );
	}

	public function test_invalid_json_is_saved_but_logged() {
		$this->submit( '{"@type":' );
		$this->assertSame( '{"@type":', $this->stored(), 'Brugerens tekst må ikke gå tabt.' );
		$this->assertStringContainsString( 'Ugyldig JSON-LD gemt', implode( ' ', $this->log_messages() ) );
	}

	public function test_empty_value_deletes_meta() {
		$this->submit( '{"@type":"WebPage"}' );
		$this->submit( '   ' );
		$this->assertFalse( metadata_exists( 'post', $this->page_id, Marginal_Schema_Output::META_KEY ) );
	}

	public function test_oversized_value_is_rejected_and_previous_version_kept() {
		$this->submit( '{"@type":"WebPage"}' );
		$this->submit( '{"a":"' . str_repeat( 'x', Marginal_Schema_Json::MAX_BYTES ) . '"}' );

		$this->assertSame( '{"@type":"WebPage"}', $this->stored() );
		$messages = implode( ' ', $this->log_messages() );
		$this->assertStringContainsString( 'blev ikke gemt', $messages );
		$this->assertStringContainsString( 'maks. 200 KB', $messages );
	}

	public function test_nothing_happens_without_our_form() {
		$_POST = array();
		Marginal_Schema_Metabox::save( $this->page_id, get_post( $this->page_id ) );
		$this->assertSame( '', $this->stored() );
	}

	public function test_form_for_another_page_is_ignored() {
		$other_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		// Formular (post_ID og nonce) for $other_id, men save_post kører for $this->page_id.
		$_POST = $this->metabox_post( $other_id, '{"@type":"Forkert"}' );
		Marginal_Schema_Metabox::save( $this->page_id, get_post( $this->page_id ) );

		$this->assertSame( '', $this->stored() );
	}

	public function test_nonce_for_another_page_is_rejected() {
		$other_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->submit( '{"@type":"Forkert"}', wp_create_nonce( Marginal_Schema_Metabox::nonce_action( $other_id ) ) );
		$this->assertSame( '', $this->stored() );
	}

	public function test_sync_plugin_saving_another_page_does_not_overwrite_it() {
		// Fx et oversættelsesplugin, der opdaterer den engelske version, når den danske gemmes.
		$translation_id = $this->create_page_with_jsonld( '{"@type":"WebPage","inLanguage":"en"}' );
		$page_id        = $this->page_id;
		$sync           = static function ( $post_id ) use ( $page_id, $translation_id ) {
			if ( $post_id === $page_id ) {
				wp_update_post(
					array(
						'ID'         => $translation_id,
						'post_title' => 'Synkroniseret',
					)
				);
			}
		};
		add_action( 'save_post', $sync, 5 );

		$_POST = $this->metabox_post( $this->page_id, '{"@type":"WebPage","inLanguage":"da"}' );
		wp_update_post(
			array(
				'ID'         => $this->page_id,
				'post_title' => 'Dansk side',
			)
		);
		remove_action( 'save_post', $sync, 5 );

		$this->assertSame( '{"@type":"WebPage","inLanguage":"da"}', $this->stored() );
		$this->assertSame( '{"@type":"WebPage","inLanguage":"en"}', $this->stored( $translation_id ), 'Den anden side må ikke blive overskrevet.' );
	}

	public function test_render_outputs_escaped_value_and_page_bound_nonce() {
		update_post_meta( $this->page_id, Marginal_Schema_Output::META_KEY, '{"a":"</textarea><b>x</b>"}' );
		$html = $this->capture(
			function () {
				Marginal_Schema_Metabox::render( get_post( $this->page_id ) );
			}
		);

		$this->assertStringContainsString( 'name="' . Marginal_Schema_Metabox::NONCE_FIELD . '"', $html );
		$this->assertStringContainsString( 'value="' . wp_create_nonce( Marginal_Schema_Metabox::nonce_action( $this->page_id ) ) . '"', $html );
		$this->assertStringContainsString( '&lt;/textarea&gt;', $html );
		$this->assertSame( 1, substr_count( $html, '</textarea>' ), 'Værdien må ikke kunne lukke textarea-feltet.' );
		$this->assertStringContainsString( 'data-max-bytes="' . Marginal_Schema_Json::MAX_BYTES . '"', $html );
	}

	public function test_render_shows_warning_for_invalid_saved_json() {
		update_post_meta( $this->page_id, Marginal_Schema_Output::META_KEY, '{"a":' );
		$html = $this->capture(
			function () {
				Marginal_Schema_Metabox::render( get_post( $this->page_id ) );
			}
		);
		$this->assertStringContainsString( 'marginal-schema-saved-error', $html );
	}
}

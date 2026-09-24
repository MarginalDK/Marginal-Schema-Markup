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
		$this->login_as( 'editor' );
		$this->page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
	}

	public function tear_down() {
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Simulér at redigeringsformularen sendes (WordPress slasher altid $_POST).
	 *
	 * @param string      $value JSON.
	 * @param string|null $nonce Nonce (null = gyldig).
	 */
	private function submit( $value, $nonce = null ) {
		$_POST = wp_slash(
			array(
				Marginal_Schema_Metabox::NONCE_FIELD => null === $nonce ? wp_create_nonce( Marginal_Schema_Metabox::NONCE_ACTION ) : $nonce,
				Marginal_Schema_Metabox::FIELD       => $value,
			)
		);
		Marginal_Schema_Metabox::save( $this->page_id, get_post( $this->page_id ) );
	}

	private function stored() {
		return get_post_meta( $this->page_id, Marginal_Schema_Output::META_KEY, true );
	}

	public function test_meta_box_is_added_for_pages_only() {
		global $wp_meta_boxes;
		$wp_meta_boxes = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		set_current_screen( 'page' );
		Marginal_Schema_Metabox::add( 'page' );
		Marginal_Schema_Metabox::add( 'post' );

		$this->assertArrayHasKey( 'marginal-schema-markup', $wp_meta_boxes['page']['normal']['default'] );
		$this->assertArrayNotHasKey( 'post', $wp_meta_boxes );
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

	public function test_nothing_happens_without_our_form() {
		$_POST = array();
		Marginal_Schema_Metabox::save( $this->page_id, get_post( $this->page_id ) );
		$this->assertSame( '', $this->stored() );
	}

	public function test_render_outputs_escaped_value_and_nonce() {
		update_post_meta( $this->page_id, Marginal_Schema_Output::META_KEY, '{"a":"</textarea><b>x</b>"}' );
		$html = $this->capture(
			function () {
				Marginal_Schema_Metabox::render( get_post( $this->page_id ) );
			}
		);

		$this->assertStringContainsString( 'name="' . Marginal_Schema_Metabox::NONCE_FIELD . '"', $html );
		$this->assertStringContainsString( '&lt;/textarea&gt;', $html );
		$this->assertSame( 1, substr_count( $html, '</textarea>' ), 'Værdien må ikke kunne lukke textarea-feltet.' );
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

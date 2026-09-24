<?php
/**
 * Tests af JSON-validering og -udskrivning.
 *
 * @package Marginal_Schema_Markup
 */

/**
 * @covers Marginal_Schema_Json
 */
class Test_Marginal_Schema_Json extends Marginal_Schema_TestCase {

	public function test_empty_input_outputs_nothing() {
		$this->assertNull( Marginal_Schema_Json::decode( '' ) );
		$this->assertNull( Marginal_Schema_Json::decode( "   \n\t " ) );
		$this->assertNull( Marginal_Schema_Json::build_script_tag( '', 'x' ) );
		$this->assertTrue( Marginal_Schema_Json::validate( '' ) );
	}

	public function test_valid_object_is_accepted() {
		$this->assertTrue( Marginal_Schema_Json::validate( '{"@context":"https://schema.org","@type":"Organization"}' ) );
	}

	public function test_valid_array_is_accepted() {
		$this->assertTrue( Marginal_Schema_Json::validate( '[{"@type":"Organization"},{"@type":"WebSite"}]' ) );
	}

	/**
	 * @dataProvider invalid_json_provider
	 *
	 * @param string $input Ugyldigt input.
	 */
	public function test_invalid_json_is_rejected( $input ) {
		$result = Marginal_Schema_Json::validate( $input );
		$this->assertWPError( $result );
		$this->assertNotSame( '', $result->get_error_message() );
	}

	/**
	 * @return array
	 */
	public function invalid_json_provider() {
		return array(
			'manglende komma'       => array( '{"a":1 "b":2}' ),
			'komma til sidst'       => array( '{"a":1,}' ),
			'enkelt-anførselstegn'  => array( "{'a':1}" ),
			'ufuldstændig'          => array( '{"a":' ),
			'ren tekst'             => array( 'hej' ),
			'tal (ikke objekt)'     => array( '42' ),
			'streng (ikke objekt)'  => array( '"tekst"' ),
			'true (ikke objekt)'    => array( 'true' ),
			'null (ikke objekt)'    => array( 'null' ),
			'HTML'                  => array( '<p>hej</p>' ),
			'ugyldig UTF-8'         => array( "{\"a\":\"\xB1\x31\"}" ),
		);
	}

	public function test_too_deep_nesting_is_rejected() {
		$json = str_repeat( '[', 200 ) . str_repeat( ']', 200 );
		$this->assertWPError( Marginal_Schema_Json::validate( $json ) );
	}

	public function test_too_large_input_is_rejected() {
		$json = '{"a":"' . str_repeat( 'x', Marginal_Schema_Json::MAX_BYTES ) . '"}';
		$this->assertWPError( Marginal_Schema_Json::validate( $json ) );
	}

	public function test_script_tag_has_correct_type_attribute() {
		$html = Marginal_Schema_Json::build_script_tag( '{"@type":"Thing"}', 'marginal-schema-global' );
		$this->assertStringStartsWith( '<script type="application/ld+json" class="marginal-schema-global">', $html );
		$this->assertStringEndsWith( "</script>\n", $html );
	}

	public function test_output_roundtrips_to_same_data() {
		$input = '{"@context":"https://schema.org","@type":"Organization","name":"Marginal ÆØÅ","url":"https://marginal.dk/a/b","n":1.5,"b":true,"nul":null,"tom":{},"liste":[]}';
		$html  = Marginal_Schema_Json::build_script_tag( $input, 'x' );
		$json  = preg_replace( '#^<script[^>]*>|</script>\n$#', '', $html );

		$this->assertSame( json_decode( $input, true ), json_decode( $json, true ) );
		// Tomme objekter skal forblive objekter (vigtigt for JSON-LD).
		$this->assertStringContainsString( '"tom":{}', $json );
		$this->assertStringContainsString( '"liste":[]', $json );
		// Læsbare URL'er og danske tegn.
		$this->assertStringContainsString( 'https://marginal.dk/a/b', $json );
		$this->assertStringContainsString( 'ÆØÅ', $json );
	}

	public function test_backslashes_and_quotes_are_preserved() {
		$input = '{"a":"Citat: \"hej\" og back\\\\slash"}';
		$html  = Marginal_Schema_Json::build_script_tag( $input, 'x' );
		$json  = preg_replace( '#^<script[^>]*>|</script>\n$#', '', $html );
		$this->assertSame( 'Citat: "hej" og back\\slash', json_decode( $json )->a );
	}

	public function test_sanitize_strips_single_script_wrapper() {
		$input = "<script type=\"application/ld+json\">\n{\"@type\":\"Thing\"}\n</script>";
		$this->assertSame( '{"@type":"Thing"}', Marginal_Schema_Json::sanitize_input( $input ) );
	}

	public function test_sanitize_combines_multiple_script_wrappers_into_array() {
		$input  = '<script type="application/ld+json">{"@type":"A"}</script>' . "\n" . '<script type="application/ld+json">{"@type":"B"}</script>';
		$result = Marginal_Schema_Json::sanitize_input( $input );
		$this->assertTrue( Marginal_Schema_Json::validate( $result ) );
		$this->assertSame( array( array( '@type' => 'A' ), array( '@type' => 'B' ) ), json_decode( $result, true ) );
	}

	public function test_sanitize_does_not_treat_script_text_inside_json_as_wrapper() {
		$input = '{"name":"x </script><script>y</script>"}';
		$this->assertSame( $input, Marginal_Schema_Json::sanitize_input( $input ) );
	}

	public function test_sanitize_trims_and_removes_null_bytes() {
		$this->assertSame( '{"a": 1}', Marginal_Schema_Json::sanitize_input( "  {\"a\":\0 1}  \n" ) );
	}

	public function test_sanitize_non_string_returns_empty() {
		$this->assertSame( '', Marginal_Schema_Json::sanitize_input( array( 'a' ) ) );
		$this->assertSame( '', Marginal_Schema_Json::sanitize_input( null ) );
		$this->assertSame( '', Marginal_Schema_Json::sanitize_input( 123 ) );
	}

	public function test_sanitize_truncates_oversized_input() {
		$result = Marginal_Schema_Json::sanitize_input( str_repeat( 'a', Marginal_Schema_Json::MAX_BYTES + 5000 ) );
		$this->assertLessThanOrEqual( Marginal_Schema_Json::MAX_BYTES, strlen( $result ) );
	}

	public function test_sanitize_is_idempotent() {
		$input = '<script type="application/ld+json">{"@type":"Thing","n":"æøå"}</script>';
		$once  = Marginal_Schema_Json::sanitize_input( $input );
		$this->assertSame( $once, Marginal_Schema_Json::sanitize_input( $once ) );
	}
}

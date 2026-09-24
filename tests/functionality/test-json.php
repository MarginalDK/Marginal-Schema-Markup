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
		$json   = '{"a":"' . str_repeat( 'x', Marginal_Schema_Json::MAX_BYTES ) . '"}';
		$result = Marginal_Schema_Json::validate( $json );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'for stor', $result->get_error_message() );
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

	public function test_oversized_input_is_not_silently_truncated() {
		$json   = '{"a":"' . str_repeat( 'x', Marginal_Schema_Json::MAX_BYTES ) . '"}';
		$result = Marginal_Schema_Json::sanitize_input( $json );
		$this->assertSame( $json, $result, 'For store værdier må ikke klippes over (det giver en forvirrende fejl).' );

		$error = Marginal_Schema_Json::validate( $result );
		$this->assertWPError( $error );
		$this->assertSame( 'JSON-LD er for stor (201 KB – maks. 200 KB).', $error->get_error_message() );
	}

	public function test_check_size_boundary() {
		$this->assertTrue( Marginal_Schema_Json::check_size( str_repeat( 'a', Marginal_Schema_Json::MAX_BYTES ) ) );
		$this->assertWPError( Marginal_Schema_Json::check_size( str_repeat( 'a', Marginal_Schema_Json::MAX_BYTES + 1 ) ) );
	}

	public function test_too_large_number_fails_validation_with_clear_message() {
		$result = Marginal_Schema_Json::validate( '{"@type":"Product","x":1e400}' );
		$this->assertWPError( $result, '"Gyldig" skal betyde, at det også kan udskrives.' );
		$this->assertStringContainsString( '1e400', $result->get_error_message() );
	}

	/**
	 * @dataProvider validation_consistency_provider
	 *
	 * @param string $input Input.
	 */
	public function test_validate_agrees_with_output( $input ) {
		$valid  = Marginal_Schema_Json::validate( $input );
		$output = Marginal_Schema_Json::build_script_tag( $input, 'x' );
		$this->assertSame( true === $valid, ! is_wp_error( $output ), 'validate() og det faktiske output skal altid være enige.' );
	}

	/**
	 * @return array
	 */
	public function validation_consistency_provider() {
		return array(
			'gyldig'           => array( '{"@type":"Thing"}' ),
			'tom'              => array( '' ),
			'syntaksfejl'      => array( '{"a":' ),
			'uendeligt tal'    => array( '{"a":-1e400}' ),
			'stort heltal'     => array( '{"a":123456789012345678901234567890}' ),
			'dybt indlejret'   => array( str_repeat( '[', 127 ) . str_repeat( ']', 127 ) ),
			'for dybt'         => array( str_repeat( '[', 129 ) . str_repeat( ']', 129 ) ),
			'for stor'         => array( '["' . str_repeat( 'x', Marginal_Schema_Json::MAX_BYTES ) . '"]' ),
		);
	}

	public function test_wrapper_extraction_is_fast_on_pathological_input() {
		// Tusindvis af "<script>" uden lukke-tag fik tidligere et regulært udtryk til at køre i sekunder
		// (når PCRE JIT er slået fra, som på nogle servere).
		$old = ini_get( 'pcre.jit' );
		ini_set( 'pcre.jit', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$start = microtime( true );
		$input = str_repeat( '<script>', (int) ( Marginal_Schema_Json::MAX_BYTES / 8 ) );
		$this->assertSame( $input, Marginal_Schema_Json::sanitize_input( $input ) );
		$seconds = microtime( true ) - $start;
		ini_set( 'pcre.jit', $old ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		$this->assertLessThan( 1.0, $seconds );
	}

	public function test_script_like_tags_are_not_treated_as_wrappers() {
		$this->assertSame( '<scripts>{"a":1}</scripts>', Marginal_Schema_Json::sanitize_input( '<scripts>{"a":1}</scripts>' ) );
	}

	public function test_closing_tag_with_whitespace_is_accepted() {
		$this->assertSame( '{"a":1}', Marginal_Schema_Json::sanitize_input( "<SCRIPT type=\"application/ld+json\">{\"a\":1}</script \n>" ) );
	}

	public function test_empty_wrappers_give_empty_value() {
		$this->assertSame( '', Marginal_Schema_Json::sanitize_input( '<script type="application/ld+json"></script>' ) );
	}

	public function test_truncate_utf8_never_cuts_a_character() {
		$text = str_repeat( 'a', 9 ) . 'æøå';
		for ( $max = 8; $max <= strlen( $text ); $max++ ) {
			$cut = Marginal_Schema_Json::truncate_utf8( $text, $max );
			$this->assertLessThanOrEqual( $max, strlen( $cut ) );
			$this->assertSame( $cut, wp_check_invalid_utf8( $cut ), "Ugyldig UTF-8 ved $max bytes." );
		}
		$this->assertSame( 'kort', Marginal_Schema_Json::truncate_utf8( 'kort', 100 ) );
	}

	public function test_sanitize_is_idempotent() {
		$input = '<script type="application/ld+json">{"@type":"Thing","n":"æøå"}</script>';
		$once  = Marginal_Schema_Json::sanitize_input( $input );
		$this->assertSame( $once, Marginal_Schema_Json::sanitize_input( $once ) );
	}
}

<?php
/**
 * Tests af fejl-loggen.
 *
 * @package Marginal_Schema_Markup
 */

/**
 * @covers Marginal_Schema_Logger
 */
class Test_Marginal_Schema_Logger extends Marginal_Schema_TestCase {

	public function test_error_is_stored() {
		Marginal_Schema_Logger::error( 'Noget gik galt', 42 );

		$entries = Marginal_Schema_Logger::get_entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'error', $entries[0]['level'] );
		$this->assertSame( 'Noget gik galt', $entries[0]['message'] );
		$this->assertSame( 42, $entries[0]['post_id'] );
		$this->assertSame( 1, $entries[0]['count'] );
	}

	public function test_levels() {
		Marginal_Schema_Logger::warning( 'A' );
		Marginal_Schema_Logger::info( 'B' );
		Marginal_Schema_Logger::error( 'C', 0, 'ukendt-niveau' );

		$levels = wp_list_pluck( Marginal_Schema_Logger::get_entries(), 'level', 'message' );
		$this->assertSame( 'warning', $levels['A'] );
		$this->assertSame( 'info', $levels['B'] );
		$this->assertSame( 'error', $levels['C'] );
	}

	public function test_log_is_not_autoloaded() {
		global $wpdb;
		Marginal_Schema_Logger::error( 'X' );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Marginal_Schema_Logger::OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_identical_errors_are_merged_in_same_request() {
		for ( $i = 0; $i < 50; $i++ ) {
			Marginal_Schema_Logger::error( 'Samme fejl', 7 );
		}
		$entries = Marginal_Schema_Logger::get_entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( 1, $entries[0]['count'] );
	}

	public function test_identical_errors_are_throttled_across_requests() {
		Marginal_Schema_Logger::error( 'Samme fejl', 7 );
		$this->reset_logger_request_cache();
		Marginal_Schema_Logger::error( 'Samme fejl', 7 );

		$this->assertSame( 1, Marginal_Schema_Logger::get_entries()[0]['count'], 'Inden for en time skrives der ikke igen.' );
	}

	public function test_count_increases_after_throttle_period() {
		Marginal_Schema_Logger::error( 'Samme fejl', 7 );

		$entries = get_option( Marginal_Schema_Logger::OPTION );
		$key     = key( $entries );
		$entries[ $key ]['last'] = time() - Marginal_Schema_Logger::THROTTLE - 1;
		update_option( Marginal_Schema_Logger::OPTION, $entries, false );

		$this->reset_logger_request_cache();
		Marginal_Schema_Logger::error( 'Samme fejl', 7 );

		$this->assertSame( 2, Marginal_Schema_Logger::get_entries()[0]['count'] );
	}

	public function test_log_is_capped() {
		for ( $i = 0; $i < Marginal_Schema_Logger::MAX_ENTRIES + 25; $i++ ) {
			Marginal_Schema_Logger::error( 'Fejl nummer ' . $i );
		}
		$this->assertCount( Marginal_Schema_Logger::MAX_ENTRIES, Marginal_Schema_Logger::get_entries() );
	}

	public function test_long_messages_are_truncated() {
		Marginal_Schema_Logger::error( str_repeat( 'æ', 2000 ) );
		$message = Marginal_Schema_Logger::get_entries()[0]['message'];
		$this->assertSame( Marginal_Schema_Logger::MAX_MESSAGE_LENGTH, mb_strlen( $message ) );
	}

	public function test_empty_message_is_ignored() {
		Marginal_Schema_Logger::error( '' );
		Marginal_Schema_Logger::error( '   ' );
		$this->assertSame( array(), Marginal_Schema_Logger::get_entries() );
	}

	public function test_entries_are_sorted_newest_first() {
		update_option(
			Marginal_Schema_Logger::OPTION,
			array(
				'a' => array( 'message' => 'gammel', 'last' => 100 ),
				'b' => array( 'message' => 'ny', 'last' => 300 ),
				'c' => array( 'message' => 'midt', 'last' => 200 ),
			),
			false
		);
		$this->assertSame( array( 'ny', 'midt', 'gammel' ), $this->log_messages() );
	}

	public function test_corrupt_option_does_not_break() {
		update_option( Marginal_Schema_Logger::OPTION, 'ødelagt', false );
		$this->assertSame( array(), Marginal_Schema_Logger::get_entries() );

		Marginal_Schema_Logger::error( 'Virker stadig' );
		$this->assertSame( array( 'Virker stadig' ), $this->log_messages() );
	}

	public function test_clear() {
		Marginal_Schema_Logger::error( 'X' );
		Marginal_Schema_Logger::clear();
		$this->assertSame( array(), Marginal_Schema_Logger::get_entries() );
	}

	public function test_query_string_is_not_logged() {
		$_SERVER['REQUEST_URI'] = '/side/?token=hemmelig&key=123';
		Marginal_Schema_Logger::error( 'X' );
		$url = Marginal_Schema_Logger::get_entries()[0]['url'];
		$this->assertSame( '/side/', $url );
	}
}

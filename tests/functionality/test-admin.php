<?php
/**
 * Tests af indstillingssiden.
 *
 * @package Marginal_Schema_Markup
 */

/**
 * @covers Marginal_Schema_Admin
 */
class Test_Marginal_Schema_Admin extends Marginal_Schema_TestCase {

	public function tear_down() {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	public function test_settings_page_is_registered_for_admins() {
		$this->login_as( 'administrator' );
		set_current_screen( 'dashboard' );
		Marginal_Schema_Admin::menu();

		$this->assertNotEmpty( menu_page_url( Marginal_Schema_Admin::PAGE, false ) );
	}

	public function test_setting_is_registered_without_rest() {
		Marginal_Schema_Admin::register_settings();
		$settings = get_registered_settings();

		$this->assertArrayHasKey( Marginal_Schema_Output::OPTION_GLOBAL, $settings );
		$this->assertSame( Marginal_Schema_Admin::GROUP, $settings[ Marginal_Schema_Output::OPTION_GLOBAL ]['group'] );
		$this->assertFalse( $settings[ Marginal_Schema_Output::OPTION_GLOBAL ]['show_in_rest'] );
	}

	public function test_saving_via_settings_api_sanitizes() {
		Marginal_Schema_Admin::register_settings();
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, '  <script type="application/ld+json">{"@type":"Organization"}</script>  ' );
		$this->assertSame( '{"@type":"Organization"}', get_option( Marginal_Schema_Output::OPTION_GLOBAL ) );
	}

	public function test_valid_global_gives_no_error() {
		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		Marginal_Schema_Admin::sanitize_global( '{"@type":"Organization"}' );
		$this->assertSame( array(), get_settings_errors( Marginal_Schema_Output::OPTION_GLOBAL ) );
	}

	public function test_invalid_global_is_kept_but_warned_once() {
		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$prop = new ReflectionProperty( 'Marginal_Schema_Admin', 'notice_added' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( null, false );

		$this->assertSame( '{"a":', Marginal_Schema_Admin::sanitize_global( '{"a":' ) );
		Marginal_Schema_Admin::sanitize_global( '{"a":' );

		$this->assertCount( 1, get_settings_errors( Marginal_Schema_Output::OPTION_GLOBAL ) );
		$this->assertStringContainsString( 'Ugyldig global JSON-LD', implode( ' ', $this->log_messages() ) );
	}

	public function test_render_global_tab() {
		$this->login_as( 'administrator' );
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, '{"@type":"Organization"}' );

		$html = $this->capture( array( 'Marginal_Schema_Admin', 'render_page' ) );

		$this->assertStringContainsString( 'action="options.php"', $html );
		$this->assertMatchesRegularExpression( '#name=[\'"]option_page[\'"] value=[\'"]' . Marginal_Schema_Admin::GROUP . '[\'"]#', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( '{&quot;@type&quot;:&quot;Organization&quot;}', $html );
	}

	public function test_render_log_tab() {
		$this->login_as( 'administrator' );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		Marginal_Schema_Logger::error( 'En testfejl', $page_id );

		$_GET['tab'] = 'log';
		$html        = $this->capture( array( 'Marginal_Schema_Admin', 'render_page' ) );

		$this->assertStringContainsString( 'En testfejl', $html );
		$this->assertStringContainsString( 'post=' . $page_id, $html );
		$this->assertStringContainsString( 'marginal_schema_clear_log', $html );
	}

	public function test_render_updates_tab() {
		$this->login_as( 'administrator' );
		$_GET['tab'] = 'updates';
		$html        = $this->capture( array( 'Marginal_Schema_Admin', 'render_page' ) );

		$this->assertStringContainsString( MARGINAL_SCHEMA_VERSION, $html );
		$this->assertStringContainsString( 'marginal_schema_check_update', $html );
	}

	public function test_unknown_tab_falls_back_to_global() {
		$this->login_as( 'administrator' );
		$_GET['tab'] = '../../etc/passwd';
		$html        = $this->capture( array( 'Marginal_Schema_Admin', 'render_page' ) );
		$this->assertStringContainsString( 'action="options.php"', $html );
	}

	public function test_clear_log_with_valid_nonce() {
		$this->login_as( 'administrator' );
		Marginal_Schema_Logger::error( 'X' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'marginal_schema_clear_log' );

		$location = $this->run_redirecting_handler( array( 'Marginal_Schema_Admin', 'handle_clear_log' ) );

		$this->assertSame( array(), Marginal_Schema_Logger::get_entries() );
		$this->assertStringContainsString( 'tab=log', $location );
	}

	public function test_check_update_with_valid_nonce() {
		$this->login_as( 'administrator' );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		$calls = $this->mock_github( $this->release(), null );
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
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'marginal_schema_check_update' );

		$location = $this->run_redirecting_handler( array( 'Marginal_Schema_Admin', 'handle_check_update' ) );

		$this->assertSame( 1, $calls->api );
		$this->assertStringContainsString( 'tab=updates', $location );
	}

	public function test_action_link_is_added() {
		$links = Marginal_Schema_Admin::action_links( array( '<a href="#">Deaktivér</a>' ) );
		$this->assertCount( 2, $links );
		$this->assertStringContainsString( 'page=marginal-schema-markup', $links[0] );
	}
}

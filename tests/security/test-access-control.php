<?php
/**
 * Sikkerhed: kun de rigtige brugere må ændre noget, og kun via nonce-beskyttede formularer.
 *
 * @package Marginal_Schema_Markup
 */

/**
 * @coversNothing
 */
class Test_Marginal_Schema_Security_Access extends Marginal_Schema_TestCase {

	public function tear_down() {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * @return array
	 */
	public function non_admin_roles() {
		return array(
			'ikke logget ind' => array( '' ),
			'subscriber'      => array( 'subscriber' ),
			'contributor'     => array( 'contributor' ),
			'author'          => array( 'author' ),
			'editor'          => array( 'editor' ),
		);
	}

	private function login_role( $role ) {
		if ( '' === $role ) {
			wp_set_current_user( 0 );
			return 0;
		}
		return $this->login_as( $role );
	}

	/**
	 * @dataProvider non_admin_roles
	 *
	 * @param string $role Rolle.
	 */
	public function test_settings_page_requires_manage_options( $role ) {
		$this->login_role( $role );
		$this->expectException( 'WPDieException' );
		Marginal_Schema_Admin::render_page();
	}

	/**
	 * @dataProvider non_admin_roles
	 *
	 * @param string $role Rolle.
	 */
	public function test_clear_log_requires_manage_options( $role ) {
		Marginal_Schema_Logger::error( 'Skal blive' );
		$this->login_role( $role );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'marginal_schema_clear_log' );

		try {
			Marginal_Schema_Admin::handle_clear_log();
			$this->fail( 'Burde være afvist.' );
		} catch ( WPDieException $e ) {
			$this->assertCount( 1, Marginal_Schema_Logger::get_entries() );
		}
	}

	/**
	 * @dataProvider non_admin_roles
	 *
	 * @param string $role Rolle.
	 */
	public function test_check_update_requires_update_plugins( $role ) {
		$this->login_role( $role );
		$calls                = $this->mock_github( $this->release() );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'marginal_schema_check_update' );

		try {
			Marginal_Schema_Admin::handle_check_update();
			$this->fail( 'Burde være afvist.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 0, $calls->api );
		}
	}

	public function test_clear_log_requires_valid_nonce() {
		Marginal_Schema_Logger::error( 'Skal blive' );
		$this->login_as( 'administrator' );

		foreach ( array( '', 'forkert', wp_create_nonce( 'en_anden_handling' ) ) as $nonce ) {
			$_REQUEST['_wpnonce'] = $nonce;
			try {
				Marginal_Schema_Admin::handle_clear_log();
				$this->fail( 'Burde være afvist (CSRF).' );
			} catch ( WPDieException $e ) {
				$this->assertCount( 1, Marginal_Schema_Logger::get_entries() );
			}
		}
	}

	public function test_check_update_requires_valid_nonce() {
		$this->login_as( 'administrator' );
		$calls                = $this->mock_github( $this->release() );
		$_REQUEST['_wpnonce'] = 'forkert';

		try {
			Marginal_Schema_Admin::handle_check_update();
			$this->fail( 'Burde være afvist (CSRF).' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 0, $calls->api );
		}
	}

	public function test_settings_are_protected_by_manage_options_capability() {
		// options.php bruger dette filter til at afgøre hvem der må gemme indstillingsgruppen.
		$this->assertSame( 'manage_options', apply_filters( 'option_page_capability_' . Marginal_Schema_Admin::GROUP, 'manage_options' ) );
	}

	public function test_admin_post_actions_are_not_available_to_logged_out_users() {
		$this->assertFalse( has_action( 'admin_post_nopriv_marginal_schema_clear_log' ) );
		$this->assertFalse( has_action( 'admin_post_nopriv_marginal_schema_check_update' ) );
	}

	public function test_plugin_registers_no_ajax_rest_or_shortcode_endpoints() {
		global $wp_filter, $shortcode_tags;

		foreach ( array_keys( $wp_filter ) as $hook ) {
			if ( 0 === strpos( $hook, 'wp_ajax_' ) ) {
				$this->assertStringNotContainsString( 'marginal', $hook, 'Nye AJAX-endpoints skal have egne sikkerhedstests.' );
			}
		}

		$routes = array_keys( rest_get_server()->get_routes() );
		foreach ( $routes as $route ) {
			$this->assertStringNotContainsString( 'marginal', $route, 'Nye REST-routes skal have egne sikkerhedstests.' );
		}

		foreach ( array_keys( $shortcode_tags ) as $tag ) {
			$this->assertStringNotContainsString( 'marginal', $tag );
		}
	}

	/**
	 * @return array
	 */
	public function cannot_edit_page_roles() {
		return array(
			'ikke logget ind'           => array( '' ),
			'subscriber'                => array( 'subscriber' ),
			'contributor'               => array( 'contributor' ),
			'author (andres side)'      => array( 'author' ),
		);
	}

	/**
	 * @dataProvider cannot_edit_page_roles
	 *
	 * @param string $role Rolle.
	 */
	public function test_meta_cannot_be_saved_without_edit_permission( $role ) {
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_author' => $admin,
			)
		);
		$this->login_role( $role );

		$_POST = wp_slash(
			array(
				Marginal_Schema_Metabox::NONCE_FIELD => wp_create_nonce( Marginal_Schema_Metabox::NONCE_ACTION ),
				Marginal_Schema_Metabox::FIELD       => '{"@type":"Hacked"}',
			)
		);
		Marginal_Schema_Metabox::save( $page_id, get_post( $page_id ) );

		$this->assertSame( '', get_post_meta( $page_id, Marginal_Schema_Output::META_KEY, true ) );
	}

	public function test_meta_cannot_be_saved_with_invalid_nonce() {
		$this->login_as( 'editor' );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		foreach ( array( '', 'forkert', wp_create_nonce( 'anden_handling' ) ) as $nonce ) {
			$_POST = wp_slash(
				array(
					Marginal_Schema_Metabox::NONCE_FIELD => $nonce,
					Marginal_Schema_Metabox::FIELD       => '{"@type":"Hacked"}',
				)
			);
			Marginal_Schema_Metabox::save( $page_id, get_post( $page_id ) );
			$this->assertSame( '', get_post_meta( $page_id, Marginal_Schema_Output::META_KEY, true ) );
		}
	}

	public function test_nonce_from_another_user_is_rejected() {
		$this->login_as( 'editor' );
		$nonce = wp_create_nonce( Marginal_Schema_Metabox::NONCE_ACTION );

		$this->login_as( 'editor' );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$_POST   = wp_slash(
			array(
				Marginal_Schema_Metabox::NONCE_FIELD => $nonce,
				Marginal_Schema_Metabox::FIELD       => '{"@type":"Hacked"}',
			)
		);
		Marginal_Schema_Metabox::save( $page_id, get_post( $page_id ) );

		$this->assertSame( '', get_post_meta( $page_id, Marginal_Schema_Output::META_KEY, true ) );
	}

	public function test_meta_is_not_saved_on_revisions_or_other_post_types() {
		$this->login_as( 'administrator' );
		$page_id     = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$post_id     = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$revision_id = wp_save_post_revision( $page_id );
		if ( ! $revision_id ) {
			$revision_id = _wp_put_post_revision( get_post( $page_id ) );
		}

		$_POST = wp_slash(
			array(
				Marginal_Schema_Metabox::NONCE_FIELD => wp_create_nonce( Marginal_Schema_Metabox::NONCE_ACTION ),
				Marginal_Schema_Metabox::FIELD       => '{"@type":"X"}',
			)
		);

		Marginal_Schema_Metabox::save( $post_id, get_post( $post_id ) );
		Marginal_Schema_Metabox::save( $revision_id, get_post( $revision_id ) );

		$this->assertSame( '', get_post_meta( $post_id, Marginal_Schema_Output::META_KEY, true ) );
		$this->assertSame( '', get_metadata( 'post', $revision_id, Marginal_Schema_Output::META_KEY, true ) );
	}

	public function test_meta_edit_capability_follows_page_permissions() {
		Marginal_Schema_Output::register_meta();
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->login_as( 'subscriber' );
		$this->assertFalse( current_user_can( 'edit_post_meta', $page_id, Marginal_Schema_Output::META_KEY ) );

		$this->login_as( 'editor' );
		$this->assertTrue( current_user_can( 'edit_post_meta', $page_id, Marginal_Schema_Output::META_KEY ) );
	}

	public function test_meta_is_not_exposed_or_writable_via_rest_api() {
		Marginal_Schema_Output::register_meta();
		$this->login_as( 'administrator' );
		$page_id = $this->create_page_with_jsonld( '{"@type":"WebPage"}' );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $page_id );
		$request->set_param( 'context', 'edit' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$meta     = isset( $data['meta'] ) ? (array) $data['meta'] : array();
		$this->assertArrayNotHasKey( Marginal_Schema_Output::META_KEY, $meta );

		$request = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $page_id );
		$request->set_body_params( array( 'meta' => array( Marginal_Schema_Output::META_KEY => '{"@type":"Hacked"}' ) ) );
		rest_get_server()->dispatch( $request );
		$this->assertSame( '{"@type":"WebPage"}', get_post_meta( $page_id, Marginal_Schema_Output::META_KEY, true ) );
	}

	public function test_settings_not_exposed_via_rest_api() {
		Marginal_Schema_Admin::register_settings();
		$this->login_as( 'administrator' );
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, '{"@type":"Organization"}' );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/settings' ) );
		$this->assertArrayNotHasKey( Marginal_Schema_Output::OPTION_GLOBAL, $response->get_data() );
	}

	public function test_draft_page_jsonld_is_not_public() {
		$page_id = $this->create_page_with_jsonld( '{"@type":"WebPage","name":"Hemmelig kladde"}', array( 'post_status' => 'draft' ) );
		wp_set_current_user( 0 );
		$this->go_to( add_query_arg( 'page_id', $page_id, home_url( '/' ) ) );
		$this->assertStringNotContainsString( 'Hemmelig kladde', $this->render_head() );
	}
}

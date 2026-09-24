<?php
/**
 * Tests af frontend-output i <head>.
 *
 * @package Marginal_Schema_Markup
 */

/**
 * @covers Marginal_Schema_Output
 */
class Test_Marginal_Schema_Output extends Marginal_Schema_TestCase {

	const GLOBAL_JSON = '{"@context":"https://schema.org","@type":"Organization","name":"Marginal"}';
	const PAGE_JSON   = '{"@context":"https://schema.org","@type":"WebPage","name":"Om os"}';

	public function test_hooks_are_registered_with_expected_priorities() {
		$this->assertSame( 5, has_action( 'wp_head', array( 'Marginal_Schema_Output', 'print_global' ) ) );
		$this->assertSame( PHP_INT_MAX, has_action( 'wp_head', array( 'Marginal_Schema_Output', 'print_post' ) ) );
	}

	public function test_nothing_is_output_when_empty() {
		$this->go_to( home_url( '/' ) );
		$this->assertSame( array(), $this->extract_blocks( $this->render_head() ) );
	}

	public function test_global_jsonld_is_output_on_front_page() {
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, self::GLOBAL_JSON );
		$this->go_to( home_url( '/' ) );

		$blocks = $this->extract_blocks( $this->render_head() );
		$this->assertCount( 1, $blocks );
		$this->assertSame( 'application/ld+json', $blocks[0]['type'] );
		$this->assertSame( 'marginal-schema-global', $blocks[0]['class'] );
		$this->assertSame( json_decode( self::GLOBAL_JSON, true ), json_decode( $blocks[0]['json'], true ) );
	}

	public function test_global_jsonld_is_output_on_all_page_types() {
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, self::GLOBAL_JSON );
		$post_id = self::factory()->post->create();
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$cat_id  = self::factory()->category->create();

		$urls = array(
			'forside'   => home_url( '/' ),
			'indlæg'    => get_permalink( $post_id ),
			'side'      => get_permalink( $page_id ),
			'kategori'  => get_category_link( $cat_id ),
			'søgning'   => home_url( '/?s=test' ),
			'404'       => home_url( '/?p=999999' ),
		);

		foreach ( $urls as $label => $url ) {
			$this->go_to( $url );
			$blocks = $this->extract_blocks( $this->render_head() );
			$this->assertCount( 1, $blocks, "Global JSON-LD mangler på: $label" );
		}
	}

	public function test_page_jsonld_is_output_on_that_page_only() {
		$page_id  = $this->create_page_with_jsonld( self::PAGE_JSON );
		$other_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->go_to( get_permalink( $page_id ) );
		$blocks = $this->extract_blocks( $this->render_head() );
		$this->assertCount( 1, $blocks );
		$this->assertSame( 'application/ld+json', $blocks[0]['type'] );
		$this->assertSame( 'marginal-schema-page', $blocks[0]['class'] );

		$this->go_to( get_permalink( $other_id ) );
		$this->assertCount( 0, $this->extract_blocks( $this->render_head() ) );

		$this->go_to( home_url( '/' ) );
		$this->assertCount( 0, $this->extract_blocks( $this->render_head() ) );
	}

	public function test_page_jsonld_comes_after_global_and_last_in_head() {
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, self::GLOBAL_JSON );
		$page_id = $this->create_page_with_jsonld( self::PAGE_JSON );

		// Et andet plugin/tema der skriver i <head> med høj prioritet.
		add_action(
			'wp_head',
			static function () {
				echo "<meta name=\"late\" content=\"1\">\n";
			},
			99999
		);

		$this->go_to( get_permalink( $page_id ) );
		$head = $this->render_head();

		$global_pos = strpos( $head, 'marginal-schema-global' );
		$page_pos   = strpos( $head, 'marginal-schema-page' );
		$late_pos   = strpos( $head, 'name="late"' );

		$this->assertNotFalse( $global_pos );
		$this->assertNotFalse( $page_pos );
		$this->assertLessThan( $page_pos, $global_pos );
		$this->assertLessThan( $page_pos, $late_pos, 'Side-specifik JSON-LD skal stå nederst i <head>.' );
		$this->assertSame( '', trim( substr( $head, strpos( $head, '</script>', $page_pos ) + 9 ) ), 'Intet må komme efter side-JSON-LD.' );
	}

	public function test_page_jsonld_on_static_front_page() {
		$page_id = $this->create_page_with_jsonld( self::PAGE_JSON );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );

		$this->go_to( home_url( '/' ) );
		$this->assertCount( 1, $this->extract_blocks( $this->render_head() ) );
	}

	public function test_page_jsonld_on_posts_page() {
		$front_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$blog_id  = $this->create_page_with_jsonld( self::PAGE_JSON );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_id );
		update_option( 'page_for_posts', $blog_id );

		$this->go_to( get_permalink( $blog_id ) );
		$this->assertTrue( is_home() );
		$blocks = $this->extract_blocks( $this->render_head() );
		$this->assertCount( 1, $blocks );
		$this->assertSame( 'marginal-schema-page', $blocks[0]['class'] );
	}

	public function test_posts_are_not_supported_by_default() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Marginal_Schema_Output::META_KEY, self::PAGE_JSON );

		$this->go_to( get_permalink( $post_id ) );
		$this->assertCount( 0, $this->extract_blocks( $this->render_head() ) );
	}

	public function test_post_types_filter_enables_posts() {
		$filter = static function () {
			return array( 'page', 'post' );
		};
		add_filter( 'marginal_schema_post_types', $filter );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Marginal_Schema_Output::META_KEY, self::PAGE_JSON );

		$this->go_to( get_permalink( $post_id ) );
		$this->assertCount( 1, $this->extract_blocks( $this->render_head() ) );
	}

	public function test_invalid_post_types_filter_falls_back_to_pages() {
		add_filter(
			'marginal_schema_post_types',
			static function () {
				return 'ikke-et-array';
			}
		);
		$this->assertSame( array( 'page' ), Marginal_Schema_Output::post_types() );
	}

	public function test_invalid_global_jsonld_is_skipped_and_logged() {
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, '{"@type": "Organization",}' );
		$this->go_to( home_url( '/' ) );

		$this->assertCount( 0, $this->extract_blocks( $this->render_head() ) );
		$messages = $this->log_messages();
		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( 'Global JSON-LD blev ikke udskrevet', $messages[0] );
	}

	public function test_invalid_page_jsonld_is_skipped_and_logged_with_page_id() {
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, self::GLOBAL_JSON );
		$page_id = $this->create_page_with_jsonld( '{"@type": "WebPage"', array( 'post_title' => 'Kontakt' ) );

		$this->go_to( get_permalink( $page_id ) );
		$blocks = $this->extract_blocks( $this->render_head() );

		// Den globale bliver stadig udskrevet – én fejl må ikke ødelægge resten.
		$this->assertCount( 1, $blocks );
		$this->assertSame( 'marginal-schema-global', $blocks[0]['class'] );

		$entries = Marginal_Schema_Logger::get_entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( $page_id, $entries[0]['post_id'] );
		$this->assertStringContainsString( 'Kontakt', $entries[0]['message'] );
	}

	public function test_exception_during_output_does_not_break_page() {
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, self::GLOBAL_JSON );
		add_filter(
			'marginal_schema_current_post_id',
			static function () {
				throw new RuntimeException( 'Simuleret fejl' );
			}
		);
		$page_id = $this->create_page_with_jsonld( self::PAGE_JSON );
		$this->go_to( get_permalink( $page_id ) );

		$head = $this->render_head();
		$this->assertCount( 1, $this->extract_blocks( $head ) );
		$this->assertStringContainsString( 'Simuleret fejl', implode( ' ', $this->log_messages() ) );
	}

	public function test_password_protected_page_does_not_leak_jsonld() {
		$page_id = $this->create_page_with_jsonld( self::PAGE_JSON, array( 'post_password' => 'hemmelig' ) );
		$this->go_to( get_permalink( $page_id ) );
		$this->assertCount( 0, $this->extract_blocks( $this->render_head() ) );
	}

	/**
	 * Simulér en sidevisning: WordPress når til skabelonen (template_include), og så
	 * kører $render (fx wp_head) – eller ikke – før shutdown.
	 *
	 * @param callable|null $render Hvad skabelonen gør.
	 */
	private function simulate_page_view( $render = null ) {
		global $wp_actions;
		$saved = isset( $wp_actions['wp_head'] ) ? $wp_actions['wp_head'] : null;
		unset( $wp_actions['wp_head'] );

		apply_filters( 'template_include', 'template.php' );
		if ( $render ) {
			$this->capture( $render );
		}
		Marginal_Schema_Output::detect_missing_output();

		unset( $wp_actions['wp_head'] );
		if ( null !== $saved ) {
			$wp_actions['wp_head'] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	public function test_missing_wp_head_is_detected_and_logged() {
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, self::GLOBAL_JSON );
		$page_id = $this->create_page_with_jsonld( self::PAGE_JSON, array( 'post_title' => 'Kontakt' ) );
		$this->go_to( get_permalink( $page_id ) );

		$this->simulate_page_view(); // Temaet kalder aldrig wp_head().

		$messages = implode( ' | ', $this->log_messages() );
		$this->assertStringContainsString( 'Global JSON-LD blev ikke udskrevet, fordi temaet/skabelonen ikke kalder wp_head()', $messages );
		$this->assertStringContainsString( 'JSON-LD blev ikke udskrevet på "Kontakt (ID ' . $page_id . ')", fordi temaet/skabelonen ikke kalder wp_head()', $messages );
	}

	public function test_no_warning_when_wp_head_ran() {
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, self::GLOBAL_JSON );
		$page_id = $this->create_page_with_jsonld( self::PAGE_JSON );
		$this->go_to( get_permalink( $page_id ) );

		$this->simulate_page_view( 'wp_head' );

		$this->assertSame( array(), $this->log_messages() );
	}

	public function test_removed_output_hook_is_detected_and_logged() {
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, self::GLOBAL_JSON );
		$page_id = $this->create_page_with_jsonld( self::PAGE_JSON );
		$this->go_to( get_permalink( $page_id ) );

		// Fx et page builder-tema, der rydder wp_head for andres output.
		remove_action( 'wp_head', array( 'Marginal_Schema_Output', 'print_global' ), 5 );
		remove_action( 'wp_head', array( 'Marginal_Schema_Output', 'print_post' ), PHP_INT_MAX );
		$this->simulate_page_view( 'wp_head' );

		$entries = Marginal_Schema_Logger::get_entries();
		$this->assertCount( 2, $entries );
		foreach ( $entries as $entry ) {
			$this->assertStringContainsString( 'har fjernet pluginnets udskrivning', $entry['message'] );
		}
	}

	public function test_only_removed_page_output_is_reported() {
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, self::GLOBAL_JSON );
		$page_id = $this->create_page_with_jsonld( self::PAGE_JSON );
		$this->go_to( get_permalink( $page_id ) );

		remove_action( 'wp_head', array( 'Marginal_Schema_Output', 'print_post' ), PHP_INT_MAX );
		$this->simulate_page_view( 'wp_head' );

		$entries = Marginal_Schema_Logger::get_entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( $page_id, $entries[0]['post_id'] );
	}

	public function test_head_requests_are_not_reported() {
		// Oppetidsovervågning bruger ofte HEAD. WordPress stopper så før skabelonen og <head>.
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, self::GLOBAL_JSON );
		$page_id = $this->create_page_with_jsonld( self::PAGE_JSON );
		$this->go_to( get_permalink( $page_id ) );

		$_SERVER['REQUEST_METHOD'] = 'HEAD';
		try {
			// Selv hvis template_include skulle nå at køre, må en HEAD-request aldrig give en fejl.
			$this->simulate_page_view();
		} finally {
			$_SERVER['REQUEST_METHOD'] = 'GET';
		}

		$this->assertSame( array(), $this->log_messages() );
	}

	public function test_requests_that_never_reach_a_template_are_ignored() {
		// Fx redirects, feeds, robots.txt og HEAD-requests: template_include kører aldrig.
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, self::GLOBAL_JSON );
		$page_id = $this->create_page_with_jsonld( self::PAGE_JSON );
		$this->go_to( get_permalink( $page_id ) );

		Marginal_Schema_Output::detect_missing_output();

		$this->assertSame( array(), $this->log_messages() );
	}

	public function test_feeds_are_ignored() {
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, self::GLOBAL_JSON );
		self::factory()->post->create();
		$this->go_to( get_feed_link() );
		$this->assertTrue( is_feed() );

		$this->simulate_page_view();

		$this->assertSame( array(), $this->log_messages() );
	}

	public function test_private_posts_page_does_not_leak_jsonld() {
		$front_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$blog_id  = $this->create_page_with_jsonld( self::PAGE_JSON, array( 'post_status' => 'private' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_id );
		update_option( 'page_for_posts', $blog_id );

		wp_set_current_user( 0 );
		$this->go_to( add_query_arg( 'page_id', $blog_id, home_url( '/' ) ) );
		$this->assertCount( 0, $this->extract_blocks( $this->render_head() ), 'Anonyme besøgende må ikke se JSON-LD fra en privat side.' );

		// En administrator, der må se den private side, får den stadig.
		$this->login_as( 'administrator' );
		$this->go_to( add_query_arg( 'page_id', $blog_id, home_url( '/' ) ) );
		$this->assertCount( 1, $this->extract_blocks( $this->render_head() ) );
	}

	public function test_draft_posts_page_does_not_leak_jsonld() {
		$front_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$blog_id  = $this->create_page_with_jsonld( self::PAGE_JSON, array( 'post_status' => 'draft' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_id );
		update_option( 'page_for_posts', $blog_id );

		wp_set_current_user( 0 );
		$this->go_to( add_query_arg( 'page_id', $blog_id, home_url( '/' ) ) );
		$this->assertCount( 0, $this->extract_blocks( $this->render_head() ) );
	}

	public function test_meta_is_registered_as_protected_and_hidden_from_rest() {
		// Test-frameworket nulstiller registrerede meta-nøgler mellem tests.
		Marginal_Schema_Output::register_meta();

		$this->assertTrue( is_protected_meta( Marginal_Schema_Output::META_KEY, 'post' ) );

		$registered = get_registered_meta_keys( 'post', 'page' );
		$this->assertArrayHasKey( Marginal_Schema_Output::META_KEY, $registered );
		$this->assertFalse( $registered[ Marginal_Schema_Output::META_KEY ]['show_in_rest'] );
		$this->assertSame( '__return_false', $registered[ Marginal_Schema_Output::META_KEY ]['auth_callback'], 'Ingen må kunne ændre feltet via XML-RPC/REST.' );
	}

	public function test_template_include_filter_returns_template_unchanged() {
		$this->assertSame( '/sti/til/skabelon.php', Marginal_Schema_Output::mark_template_request( '/sti/til/skabelon.php' ) );
	}
}

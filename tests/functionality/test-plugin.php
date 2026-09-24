<?php
/**
 * Tests af plugin-opstart, metadata og afinstallation.
 *
 * @package Marginal_Schema_Markup
 */

/**
 * @coversNothing
 */
class Test_Marginal_Schema_Plugin extends Marginal_Schema_TestCase {

	private function headers() {
		return get_file_data(
			MARGINAL_SCHEMA_FILE,
			array(
				'name'         => 'Plugin Name',
				'version'      => 'Version',
				'requires'     => 'Requires at least',
				'requires_php' => 'Requires PHP',
				'update_uri'   => 'Update URI',
				'text_domain'  => 'Text Domain',
			)
		);
	}

	public function test_plugin_is_loaded() {
		$this->assertTrue( defined( 'MARGINAL_SCHEMA_VERSION' ) );
		foreach ( array( 'Logger', 'Json', 'Output', 'Metabox', 'Admin', 'Updater' ) as $class ) {
			$this->assertTrue( class_exists( 'Marginal_Schema_' . $class, false ) );
		}
	}

	public function test_version_numbers_match() {
		$headers = $this->headers();
		$this->assertSame( MARGINAL_SCHEMA_VERSION, $headers['version'], 'Version-headeren og MARGINAL_SCHEMA_VERSION skal være ens.' );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', MARGINAL_SCHEMA_VERSION );
	}

	public function test_requirements_match_constants() {
		$headers = $this->headers();
		$this->assertSame( MARGINAL_SCHEMA_MIN_PHP, $headers['requires_php'] );
		$this->assertSame( MARGINAL_SCHEMA_MIN_WP, $headers['requires'] );
	}

	public function test_update_uri_points_to_our_repository() {
		// Forhindrer at WordPress.org kan "overtage" pluginnet med et andet plugin med samme navn.
		$this->assertSame( Marginal_Schema_Updater::REPO_URL, $this->headers()['update_uri'] );
	}

	public function test_bootstrap_twice_does_not_fatal() {
		// Simulerer fx en dobbelt-installation: må kun give en admin-notits, aldrig en fatal fejl.
		marginal_schema_bootstrap();

		$this->login_as( 'administrator' );
		$html = $this->capture(
			static function () {
				do_action( 'admin_notices' );
			}
		);
		$this->assertStringContainsString( 'allerede indlæst', $html );
	}

	public function test_bootstrap_notice_is_escaped_and_admin_only() {
		marginal_schema_bootstrap_notice( '<script>alert(1)</script>' );

		$this->login_as( 'subscriber' );
		$this->assertStringNotContainsString( 'Marginal Schema Markup', $this->capture( static function () {
			do_action( 'admin_notices' );
		} ) );

		$this->login_as( 'administrator' );
		$html = $this->capture( static function () {
			do_action( 'admin_notices' );
		} );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>alert', $html );
	}

	public function test_main_file_uses_only_old_php_syntax() {
		// Hovedfilen skal kunne indlæses på meget gamle PHP-versioner, så versionstjekket kan
		// vise en pæn besked i stedet for en fatal fejl.
		$code = (string) file_get_contents( MARGINAL_SCHEMA_FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		foreach ( array( '?->', 'fn(', 'fn (', 'match (', 'match(', '??=', '...', 'declare(strict_types' ) as $syntax ) {
			$this->assertStringNotContainsString( $syntax, $code, "Hovedfilen må ikke bruge $syntax." );
		}
	}

	public function test_all_hooked_callbacks_exist() {
		global $wp_filter;
		foreach ( $wp_filter as $hook => $wp_hook ) {
			foreach ( $wp_hook->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'];
					if ( is_array( $fn ) && is_string( $fn[0] ) && 0 === strpos( $fn[0], 'Marginal_Schema_' ) ) {
						$this->assertTrue( is_callable( $fn ), "Callback {$fn[0]}::{$fn[1]} på $hook findes ikke." );
					}
				}
			}
		}
	}

	public function test_deactivation_keeps_data() {
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, '{"@type":"Organization"}' );
		set_site_transient( Marginal_Schema_Updater::TRANSIENT, array( 'x' => 1 ) );

		marginal_schema_deactivate();

		$this->assertSame( '{"@type":"Organization"}', get_option( Marginal_Schema_Output::OPTION_GLOBAL ) );
		$this->assertFalse( get_site_transient( Marginal_Schema_Updater::TRANSIENT ) );
	}

	public function test_uninstall_removes_all_data() {
		$page_id = $this->create_page_with_jsonld( '{"@type":"WebPage"}' );
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, '{"@type":"Organization"}' );
		Marginal_Schema_Logger::error( 'X' );
		$other_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $other_id, 'andet_plugins_data', 'skal blive' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', Marginal_Schema_Updater::basename() );
		}
		include MARGINAL_SCHEMA_TESTS_ROOT . '/uninstall.php';

		$this->assertFalse( get_option( Marginal_Schema_Output::OPTION_GLOBAL ) );
		$this->assertFalse( get_option( Marginal_Schema_Logger::OPTION ) );
		$this->assertSame( '', get_post_meta( $page_id, Marginal_Schema_Output::META_KEY, true ) );
		$this->assertSame( 'skal blive', get_post_meta( $other_id, 'andet_plugins_data', true ) );
	}
}

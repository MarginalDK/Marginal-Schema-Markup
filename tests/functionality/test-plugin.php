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

	public function test_second_copy_of_plugin_does_not_fatal() {
		// To kopier af pluginnet (fx i to mapper, eller som både plugin og mu-plugin) indlæses i
		// samme request. Det skete tidligere med "Cannot redeclare function" – en fatal fejl.
		if ( ! function_exists( 'exec' ) ) {
			$this->markTestSkipped( 'exec() er ikke tilgængelig.' );
		}

		$dir = trailingslashit( get_temp_dir() ) . 'marginal-schema-dublet-' . wp_generate_password( 8, false );
		foreach ( array( 'kopi-1', 'kopi-2' ) as $copy ) {
			wp_mkdir_p( "$dir/$copy" );
			copy( MARGINAL_SCHEMA_FILE, "$dir/$copy/marginal-schema-markup.php" );
		}
		$runner = <<<'PHP'
<?php
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['hooks'] = array();
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function add_action( $hook, $callback ) { $GLOBALS['hooks'][] = $hook; }
function register_deactivation_hook( $file, $callback ) {}
include __DIR__ . '/kopi-1/marginal-schema-markup.php';
include __DIR__ . '/kopi-2/marginal-schema-markup.php';
echo 'OK:' . implode( ',', $GLOBALS['hooks'] );
PHP;
		file_put_contents( "$dir/runner.php", $runner ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( "$dir/runner.php" ) . ' 2>&1', $output, $code );

		foreach ( array( 'kopi-1', 'kopi-2' ) as $copy ) {
			unlink( "$dir/$copy/marginal-schema-markup.php" );
			rmdir( "$dir/$copy" );
		}
		unlink( "$dir/runner.php" );
		rmdir( $dir );

		// Første kopi starter pluginnet; anden kopi viser kun en admin-notits. En fatal fejl ville
		// aldrig nå at skrive "OK" (exit-koden tjekkes ikke, da den er upålidelig i nogle PHP-miljøer).
		$this->assertSame( 'OK:plugins_loaded,admin_notices', implode( "\n", $output ), "Exit-kode $code" );
	}

	public function test_duplicate_notice_names_the_unused_folder() {
		// Samme kode som anden kopi kører, når konstanten allerede er defineret.
		marginal_schema_bootstrap_notice( sprintf( 'Pluginnet er installeret mere end én gang. Kopien i mappen "%s" bruges ikke – deaktivér og slet den under Plugins.', 'marginal-schema-markup-kopi' ) );

		$this->login_as( 'administrator' );
		$html = $this->capture(
			static function () {
				do_action( 'admin_notices' );
			}
		);
		$this->assertStringContainsString( 'marginal-schema-markup-kopi', $html );
	}

	public function test_bootstrap_called_twice_shows_notice() {
		// Hvis bootstrap alligevel kører to gange, må det kun give en admin-notits, aldrig en fatal fejl.
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

		$this->run_uninstall();

		$this->assertFalse( get_option( Marginal_Schema_Output::OPTION_GLOBAL ) );
		$this->assertFalse( get_option( Marginal_Schema_Logger::OPTION ) );
		$this->assertSame( '', get_post_meta( $page_id, Marginal_Schema_Output::META_KEY, true ) );
		$this->assertSame( 'skal blive', get_post_meta( $other_id, 'andet_plugins_data', true ) );
	}

	public function test_uninstall_keeps_data_while_another_copy_is_installed() {
		// Sletter man en dublet, må dataene ikke forsvinde for den kopi, der stadig er i brug.
		$page_id = $this->create_page_with_jsonld( '{"@type":"WebPage"}' );
		update_option( Marginal_Schema_Output::OPTION_GLOBAL, '{"@type":"Organization"}' );

		$this->run_uninstall(
			array(
				'marginal-schema-markup-kopi/marginal-schema-markup.php' => array(
					'Name'      => 'Marginal Schema Markup',
					'UpdateURI' => Marginal_Schema_Updater::REPO_URL,
				),
			)
		);

		$this->assertSame( '{"@type":"Organization"}', get_option( Marginal_Schema_Output::OPTION_GLOBAL ) );
		$this->assertSame( '{"@type":"WebPage"}', get_post_meta( $page_id, Marginal_Schema_Output::META_KEY, true ) );
	}

	/**
	 * Kør uninstall.php som WordPress gør, når pluginnet slettes.
	 *
	 * @param array $other_plugins Andre installerede plugins (simuleret).
	 */
	private function run_uninstall( $other_plugins = array() ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', Marginal_Schema_Updater::basename() );
		}

		$plugins = array_merge(
			array(
				Marginal_Schema_Updater::basename() => array(
					'Name'      => 'Marginal Schema Markup',
					'UpdateURI' => Marginal_Schema_Updater::REPO_URL,
				),
			),
			$other_plugins
		);
		wp_cache_set( 'plugins', array( '' => $plugins ), 'plugins' );

		try {
			// include (ikke include_once): filen skal kunne indlæses flere gange uden fatal fejl.
			include MARGINAL_SCHEMA_TESTS_ROOT . '/uninstall.php';
		} finally {
			wp_cache_delete( 'plugins', 'plugins' );
		}
	}
}

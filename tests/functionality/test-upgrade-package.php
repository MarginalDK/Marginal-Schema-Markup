<?php
/**
 * Tests af håndtering af den udpakkede opdateringspakke (upgrader_source_selection).
 *
 * @package Marginal_Schema_Markup
 */

/**
 * @covers Marginal_Schema_Updater::source_selection
 */
class Test_Marginal_Schema_Upgrade_Package extends Marginal_Schema_TestCase {

	/**
	 * @var string
	 */
	private $tmp;

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		$this->tmp = trailingslashit( get_temp_dir() ) . 'marginal-schema-upgrade-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->tmp );
	}

	public function tear_down() {
		global $wp_filesystem;
		$wp_filesystem->delete( $this->tmp, true );
		parent::tear_down();
	}

	private function make_package( $folder, $with_main_file = true ) {
		$dir = $this->tmp . '/' . $folder;
		wp_mkdir_p( $dir );
		if ( $with_main_file ) {
			file_put_contents( $dir . '/marginal-schema-markup.php', "<?php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return trailingslashit( $dir );
	}

	private function select( $source, $plugin = null ) {
		return Marginal_Schema_Updater::source_selection(
			$source,
			$this->tmp,
			null,
			array( 'plugin' => null === $plugin ? Marginal_Schema_Updater::basename() : $plugin )
		);
	}

	public function test_correct_package_is_accepted_unchanged() {
		$source = $this->make_package( 'marginal-schema-markup' );
		$this->assertSame( $source, $this->select( $source ) );
	}

	public function test_wrongly_named_folder_is_renamed() {
		$source = $this->make_package( 'MarginalDK-Marginal-Schema-Markup-abc123' );
		$result = $this->select( $source );

		$this->assertSame( trailingslashit( $this->tmp . '/marginal-schema-markup' ), $result );
		$this->assertFileExists( $result . 'marginal-schema-markup.php' );
	}

	public function test_package_without_main_file_is_rejected() {
		$source = $this->make_package( 'marginal-schema-markup', false );
		$result = $this->select( $source );

		$this->assertWPError( $result );
		$this->assertSame( 'marginal_schema_invalid_package', $result->get_error_code() );
		$this->assertStringContainsString( 'nuværende version er bevaret', implode( ' ', $this->log_messages() ) );
	}

	public function test_other_plugins_are_not_touched() {
		$source = $this->make_package( 'noget-andet', false );
		$this->assertSame( $source, $this->select( $source, 'andet/andet.php' ) );
	}

	public function test_existing_error_is_passed_through() {
		$error = new WP_Error( 'x', 'y' );
		$this->assertSame( $error, $this->select( $error ) );
	}
}

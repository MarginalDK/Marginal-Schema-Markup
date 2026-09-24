<?php
/**
 * PHPUnit bootstrap: starter en rigtig WordPress og indlæser pluginnet.
 *
 * @package Marginal_Schema_Markup
 */

$marginal_root = dirname( __DIR__ );
$marginal_base = getenv( 'WP_TESTS_BASE_DIR' ) ? getenv( 'WP_TESTS_BASE_DIR' ) : $marginal_root . '/.wp-tests';
$marginal_lib  = $marginal_base . '/lib/vendor/wp-phpunit/wp-phpunit';

if ( ! is_readable( $marginal_lib . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress-testmiljøet mangler. Kør først: composer install-wp\n" );
	exit( 1 );
}

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $marginal_root . '/vendor/yoast/phpunit-polyfills' );
define( 'MARGINAL_SCHEMA_TESTS_ROOT', $marginal_root );

require_once $marginal_lib . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		$file = WP_PLUGIN_DIR . '/marginal-schema-markup/marginal-schema-markup.php';
		// Samme som WordPress gør for symlinkede plugins, så plugin_basename() virker.
		wp_register_plugin_realpath( $file );
		require $file;
	}
);

// Admin-delene starter normalt kun når is_admin() er sand. I testene startes de altid.
tests_add_filter(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'Marginal_Schema_Metabox' ) ) {
			Marginal_Schema_Metabox::init();
			Marginal_Schema_Admin::init();
		}
	},
	20
);

require $marginal_lib . '/includes/bootstrap.php';
require __DIR__ . '/class-marginal-schema-testcase.php';

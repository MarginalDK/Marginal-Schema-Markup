<?php
/**
 * Konfiguration til WordPress' test-bibliotek.
 *
 * Styres af miljøvariabler, så den samme fil virker lokalt (SQLite) og i GitHub Actions (MySQL).
 *
 * @package Marginal_Schema_Markup
 */

$marginal_base = getenv( 'WP_TESTS_BASE_DIR' ) ? getenv( 'WP_TESTS_BASE_DIR' ) : dirname( __DIR__ ) . '/.wp-tests';
$marginal_db   = is_readable( $marginal_base . '/.db-engine' ) ? trim( (string) file_get_contents( $marginal_base . '/.db-engine' ) ) : 'sqlite';

define( 'ABSPATH', $marginal_base . '/wordpress/' );

// Plugin-mappen indeholder et symlink til projektet (oprettes af tests/bin/install-wp.sh).
define( 'WP_PLUGIN_DIR', $marginal_base . '/plugins' );

if ( 'sqlite' === $marginal_db ) {
	define( 'DB_ENGINE', 'sqlite' );
	define( 'DB_DIR', $marginal_base . '/database/' );
	define( 'DB_FILE', 'tests.sqlite' );
}

define( 'DB_NAME', getenv( 'WP_DB_NAME' ) ? getenv( 'WP_DB_NAME' ) : 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_DB_USER' ) ? getenv( 'WP_DB_USER' ) : 'root' );
define( 'DB_PASSWORD', false !== getenv( 'WP_DB_PASSWORD' ) ? getenv( 'WP_DB_PASSWORD' ) : 'root' );
define( 'DB_HOST', getenv( 'WP_DB_HOST' ) ? getenv( 'WP_DB_HOST' ) : '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );
define( 'FS_METHOD', 'direct' );

define( 'AUTH_KEY', 'test' );
define( 'SECURE_AUTH_KEY', 'test' );
define( 'LOGGED_IN_KEY', 'test' );
define( 'NONCE_KEY', 'test' );
define( 'AUTH_SALT', 'test' );
define( 'SECURE_AUTH_SALT', 'test' );
define( 'LOGGED_IN_SALT', 'test' );
define( 'NONCE_SALT', 'test' );

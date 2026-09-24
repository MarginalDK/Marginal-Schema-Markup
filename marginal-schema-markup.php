<?php
/**
 * Plugin Name:       Marginal Schema Markup
 * Plugin URI:        https://github.com/MarginalDK/Marginal-Schema-Markup
 * Description:       Tilføjer global og side-specifik JSON-LD schema markup i &lt;head&gt;. Udviklet af Marginal.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Marginal
 * Author URI:        https://marginal.dk
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       marginal-schema-markup
 * Update URI:        https://github.com/MarginalDK/Marginal-Schema-Markup
 *
 * @package Marginal_Schema_Markup
 */

defined( 'ABSPATH' ) || exit;

// Undgå dobbelt-indlæsning (fx hvis pluginnet ligger i to mapper).
if ( defined( 'MARGINAL_SCHEMA_VERSION' ) ) {
	return;
}

define( 'MARGINAL_SCHEMA_VERSION', '1.0.0' );
define( 'MARGINAL_SCHEMA_FILE', __FILE__ );
define( 'MARGINAL_SCHEMA_DIR', plugin_dir_path( __FILE__ ) );
define( 'MARGINAL_SCHEMA_MIN_PHP', '7.4' );
define( 'MARGINAL_SCHEMA_MIN_WP', '6.0' );

/**
 * Viser en admin-notits hvis pluginnet ikke kan starte, i stedet for at crashe siden.
 *
 * @param string $message Besked til administratoren.
 */
function marginal_schema_bootstrap_notice( $message ) {
	add_action(
		'admin_notices',
		static function () use ( $message ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p><strong>Marginal Schema Markup:</strong> ' . esc_html( $message ) . '</p></div>';
		}
	);
}

/**
 * Starter pluginnet. Kører først på plugins_loaded, så alle WordPress-funktioner er tilgængelige.
 */
function marginal_schema_bootstrap() {
	global $wp_version;

	if ( version_compare( PHP_VERSION, MARGINAL_SCHEMA_MIN_PHP, '<' ) ) {
		marginal_schema_bootstrap_notice(
			sprintf( 'Kræver PHP %s eller nyere (serveren kører %s). Pluginnet er sat på pause.', MARGINAL_SCHEMA_MIN_PHP, PHP_VERSION )
		);
		return;
	}

	if ( isset( $wp_version ) && version_compare( $wp_version, MARGINAL_SCHEMA_MIN_WP, '<' ) ) {
		marginal_schema_bootstrap_notice(
			sprintf( 'Kræver WordPress %s eller nyere. Pluginnet er sat på pause.', MARGINAL_SCHEMA_MIN_WP )
		);
		return;
	}

	$files = array(
		'Marginal_Schema_Logger'  => 'includes/class-marginal-schema-logger.php',
		'Marginal_Schema_Json'    => 'includes/class-marginal-schema-json.php',
		'Marginal_Schema_Output'  => 'includes/class-marginal-schema-output.php',
		'Marginal_Schema_Metabox' => 'includes/class-marginal-schema-metabox.php',
		'Marginal_Schema_Admin'   => 'includes/class-marginal-schema-admin.php',
		'Marginal_Schema_Updater' => 'includes/class-marginal-schema-updater.php',
	);

	// Tjek at alle filer findes FØR noget indlæses. En ufuldstændig opdatering må aldrig give fatal error.
	foreach ( $files as $class => $file ) {
		if ( ! is_readable( MARGINAL_SCHEMA_DIR . $file ) ) {
			marginal_schema_bootstrap_notice(
				sprintf( 'Filen "%s" mangler. Geninstallér pluginnet. Pluginnet er sat på pause.', $file )
			);
			return;
		}
		if ( class_exists( $class, false ) ) {
			marginal_schema_bootstrap_notice(
				sprintf( 'Klassen "%s" er allerede indlæst af et andet plugin. Pluginnet er sat på pause.', $class )
			);
			return;
		}
	}

	foreach ( $files as $file ) {
		require_once MARGINAL_SCHEMA_DIR . $file;
	}

	try {
		Marginal_Schema_Output::init();
		Marginal_Schema_Updater::init();

		if ( is_admin() ) {
			Marginal_Schema_Metabox::init();
			Marginal_Schema_Admin::init();
		}
	} catch ( \Throwable $e ) {
		marginal_schema_bootstrap_notice( 'Kunne ikke starte: ' . $e->getMessage() );
	}
}
add_action( 'plugins_loaded', 'marginal_schema_bootstrap' );

/**
 * Ryd cache ved deaktivering. Indstillinger og data bevares (slettes kun i uninstall.php).
 */
function marginal_schema_deactivate() {
	delete_site_transient( 'marginal_schema_release' );
}
register_deactivation_hook( __FILE__, 'marginal_schema_deactivate' );

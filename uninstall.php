<?php
/**
 * Kører kun når pluginnet SLETTES (ikke ved deaktivering eller opdatering).
 * Fjerner alle data, som pluginnet har gemt.
 *
 * @package Marginal_Schema_Markup
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Slet data for det aktuelle site.
 */
function marginal_schema_uninstall_site() {
	delete_option( 'marginal_schema_global_jsonld' );
	delete_option( 'marginal_schema_log' );
	delete_post_meta_by_key( '_marginal_schema_jsonld' );
}

if ( is_multisite() && function_exists( 'get_sites' ) ) {
	$marginal_schema_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $marginal_schema_site_ids as $marginal_schema_site_id ) {
		switch_to_blog( (int) $marginal_schema_site_id );
		marginal_schema_uninstall_site();
		restore_current_blog();
	}
} else {
	marginal_schema_uninstall_site();
}

delete_site_transient( 'marginal_schema_release' );

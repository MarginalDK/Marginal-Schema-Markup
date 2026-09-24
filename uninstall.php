<?php
/**
 * Kører kun når pluginnet SLETTES (ikke ved deaktivering eller opdatering).
 * Fjerner alle data, som pluginnet har gemt.
 *
 * Bemærk: Der erklæres ingen funktioner på øverste niveau her (kun closures), så filen kan
 * indlæses flere gange i samme request uden fatal fejl – fx når to kopier slettes samtidig.
 *
 * @package Marginal_Schema_Markup
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * Er pluginnet også installeret i en anden mappe (fx en dublet), bruger den kopi stadig
 * dataene. Så slettes intet.
 */
if ( function_exists( 'get_plugins' ) ) {
	foreach ( get_plugins() as $marginal_schema_file => $marginal_schema_data ) {
		if ( WP_UNINSTALL_PLUGIN !== $marginal_schema_file
			&& isset( $marginal_schema_data['UpdateURI'] )
			&& 'https://github.com/MarginalDK/Marginal-Schema-Markup' === $marginal_schema_data['UpdateURI'] ) {
			return;
		}
	}
}

$marginal_schema_uninstall_site = static function () {
	delete_option( 'marginal_schema_global_jsonld' );
	delete_option( 'marginal_schema_log' );
	delete_post_meta_by_key( '_marginal_schema_jsonld' );
};

if ( is_multisite() && function_exists( 'get_sites' ) ) {
	$marginal_schema_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $marginal_schema_site_ids as $marginal_schema_site_id ) {
		switch_to_blog( (int) $marginal_schema_site_id );
		$marginal_schema_uninstall_site();
		restore_current_blog();
	}
} else {
	$marginal_schema_uninstall_site();
}

delete_site_transient( 'marginal_schema_release' );

<?php
/**
 * Indstillingsside: global JSON-LD, log og opdateringer.
 *
 * @package Marginal_Schema_Markup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
final class Marginal_Schema_Admin {

	const PAGE       = 'marginal-schema-markup';
	const GROUP      = 'marginal_schema_settings';
	const CAPABILITY = 'manage_options';

	/**
	 * Så validerings-notitsen kun vises én gang pr. gem.
	 *
	 * @var bool
	 */
	private static $notice_added = false;

	/**
	 * Registrér hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_marginal_schema_clear_log', array( __CLASS__, 'handle_clear_log' ) );
		add_action( 'admin_post_marginal_schema_check_update', array( __CLASS__, 'handle_check_update' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MARGINAL_SCHEMA_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menupunkt under Indstillinger.
	 */
	public static function menu() {
		add_options_page(
			__( 'Schema Markup', 'marginal-schema-markup' ),
			__( 'Schema Markup', 'marginal-schema-markup' ),
			self::CAPABILITY,
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Link til indstillinger på plugin-listen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Indstillinger', 'marginal-schema-markup' ) . '</a>' );
		return $links;
	}

	/**
	 * Registrér indstillingen via Settings API (håndterer nonce og capability).
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			Marginal_Schema_Output::OPTION_GLOBAL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_global' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Rens og valider global JSON-LD ved gem.
	 *
	 * @param mixed $value Værdi (options.php har allerede kørt wp_unslash()).
	 * @return string
	 */
	public static function sanitize_global( $value ) {
		try {
			// For stor værdi: afvis den og behold den tidligere version.
			if ( is_string( $value ) ) {
				$size = Marginal_Schema_Json::check_size( $value );
				if ( is_wp_error( $size ) ) {
					self::add_error_once( __( 'Global JSON-LD blev IKKE gemt: ', 'marginal-schema-markup' ) . $size->get_error_message() . ' ' . __( 'Den tidligere version er bevaret.', 'marginal-schema-markup' ) );
					Marginal_Schema_Logger::warning( 'Global JSON-LD blev ikke gemt: ' . $size->get_error_message() );
					return self::current_global();
				}
			}

			$value = Marginal_Schema_Json::sanitize_input( $value );

			if ( '' !== $value ) {
				$valid = Marginal_Schema_Json::validate( $value );
				if ( is_wp_error( $valid ) && self::add_error_once( __( 'JSON-LD er gemt, men er ugyldig og bliver IKKE udskrevet på siden: ', 'marginal-schema-markup' ) . $valid->get_error_message() ) ) {
					Marginal_Schema_Logger::warning( 'Ugyldig global JSON-LD gemt: ' . $valid->get_error_message() );
				}
			}

			return $value;
		} catch ( \Throwable $e ) {
			Marginal_Schema_Logger::error( 'Global JSON-LD kunne ikke gemmes: ' . $e->getMessage() );
			return self::current_global();
		}
	}

	/**
	 * Den gemte globale JSON-LD.
	 *
	 * @return string
	 */
	private static function current_global() {
		$old = get_option( Marginal_Schema_Output::OPTION_GLOBAL, '' );
		return is_string( $old ) ? $old : '';
	}

	/**
	 * Vis en fejlbesked på indstillingssiden – kun én gang pr. gem (WordPress kan køre
	 * sanitize-funktionen to gange, første gang en indstilling gemmes).
	 *
	 * @param string $message Besked.
	 * @return bool True hvis beskeden blev tilføjet nu.
	 */
	private static function add_error_once( $message ) {
		if ( self::$notice_added ) {
			return false;
		}
		self::$notice_added = true;
		add_settings_error( Marginal_Schema_Output::OPTION_GLOBAL, 'marginal_schema_invalid', $message, 'error' );
		return true;
	}

	/**
	 * Indlæs assets på indstillingssiden.
	 *
	 * @param string $hook Admin-side.
	 */
	public static function enqueue( $hook ) {
		if ( 'settings_page_' . self::PAGE === $hook ) {
			self::enqueue_assets();
		}
	}

	/**
	 * Fælles CSS/JS til JSON-felterne (ingen eksterne afhængigheder).
	 */
	public static function enqueue_assets() {
		$url = plugin_dir_url( MARGINAL_SCHEMA_FILE );
		wp_enqueue_style( 'marginal-schema-admin', $url . 'assets/admin.css', array(), MARGINAL_SCHEMA_VERSION );
		wp_enqueue_script( 'marginal-schema-admin', $url . 'assets/admin.js', array(), MARGINAL_SCHEMA_VERSION, true );
	}

	/**
	 * Textarea med live-validering og formatér-knap.
	 *
	 * @param string $name  Feltnavn.
	 * @param string $id    Felt-ID.
	 * @param string $value Værdi.
	 * @param int    $rows  Antal rækker.
	 */
	public static function render_textarea( $name, $id, $value, $rows ) {
		printf(
			'<textarea name="%1$s" id="%2$s" rows="%3$d" class="large-text code marginal-schema-json" spellcheck="false" autocomplete="off" autocapitalize="off" data-max-bytes="%5$d">%4$s</textarea>',
			esc_attr( $name ),
			esc_attr( $id ),
			(int) $rows,
			esc_textarea( $value ),
			(int) Marginal_Schema_Json::MAX_BYTES
		);
		echo '<div class="marginal-schema-toolbar">';
		echo '<button type="button" class="button marginal-schema-format" data-target="' . esc_attr( $id ) . '">' . esc_html__( 'Formatér JSON', 'marginal-schema-markup' ) . '</button>';
		echo '<span class="marginal-schema-status" data-for="' . esc_attr( $id ) . '" aria-live="polite"></span>';
		echo '</div>';
	}

	/**
	 * Aktiv fane.
	 *
	 * @return string
	 */
	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'global'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- kun visning.
		return in_array( $tab, array( 'global', 'log', 'updates' ), true ) ? $tab : 'global';
	}

	/**
	 * Vis indstillingssiden.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Du har ikke adgang til denne side.', 'marginal-schema-markup' ), 403 );
		}

		$tab  = self::current_tab();
		$base = admin_url( 'options-general.php?page=' . self::PAGE );
		$tabs = array(
			'global'  => __( 'Global JSON-LD', 'marginal-schema-markup' ),
			'log'     => __( 'Log', 'marginal-schema-markup' ),
			'updates' => __( 'Opdateringer', 'marginal-schema-markup' ),
		);

		$log_count = count( Marginal_Schema_Logger::get_entries() );

		echo '<div class="wrap marginal-schema-wrap">';
		echo '<h1>' . esc_html__( 'Marginal Schema Markup', 'marginal-schema-markup' ) . '</h1>';

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$class = 'nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' );
			if ( 'log' === $key && $log_count > 0 ) {
				$label .= ' (' . $log_count . ')';
			}
			printf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( add_query_arg( 'tab', $key, $base ) ),
				esc_attr( $class ),
				esc_html( $label )
			);
		}
		echo '</nav>';

		try {
			if ( 'log' === $tab ) {
				self::render_log_tab();
			} elseif ( 'updates' === $tab ) {
				self::render_updates_tab();
			} else {
				self::render_global_tab();
			}
		} catch ( \Throwable $e ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Siden kunne ikke vises.', 'marginal-schema-markup' ) . '</p></div>';
			Marginal_Schema_Logger::error( 'Indstillingssiden kunne ikke vises: ' . $e->getMessage() );
		}

		echo '</div>';
	}

	/**
	 * Fane: global JSON-LD.
	 */
	private static function render_global_tab() {
		$value = get_option( Marginal_Schema_Output::OPTION_GLOBAL, '' );
		$value = is_string( $value ) ? $value : '';

		// Bemærk: WordPress viser selv settings_errors() på sider under Indstillinger-menuen.

		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );

		echo '<h2>' . esc_html__( 'Global JSON-LD for organisationen', 'marginal-schema-markup' ) . '</h2>';
		echo '<p>' . esc_html__( 'Denne JSON-LD udskrives i <head> på alle sider på hjemmesiden. Brug den typisk til Organization / LocalBusiness og WebSite. Du kan indsætte ren JSON eller hele <script type="application/ld+json">-blokken. Et tomt felt betyder, at der ikke udskrives noget.', 'marginal-schema-markup' ) . '</p>';

		self::render_textarea( Marginal_Schema_Output::OPTION_GLOBAL, 'marginal-schema-global', $value, 22 );

		if ( '' !== trim( $value ) ) {
			$valid = Marginal_Schema_Json::validate( $value );
			if ( is_wp_error( $valid ) ) {
				echo '<p class="marginal-schema-saved-error"><strong>' . esc_html__( 'Den gemte JSON-LD er ugyldig og bliver IKKE udskrevet:', 'marginal-schema-markup' ) . '</strong> ' . esc_html( $valid->get_error_message() ) . '</p>';
			}
		}

		echo '<p class="description">' . wp_kses(
			sprintf(
				/* translators: %s: link til Googles test-værktøj */
				__( 'Test resultatet med %s efter du har gemt.', 'marginal-schema-markup' ),
				'<a href="https://search.google.com/test/rich-results" target="_blank" rel="noopener noreferrer">Google Rich Results Test</a>'
			),
			array(
				'a' => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
		) . '</p>';

		submit_button( __( 'Gem global JSON-LD', 'marginal-schema-markup' ) );
		echo '</form>';
	}

	/**
	 * Fane: log.
	 */
	private static function render_log_tab() {
		self::render_result_notice();

		$entries = Marginal_Schema_Logger::get_entries();

		echo '<h2>' . esc_html__( 'Fejl-log', 'marginal-schema-markup' ) . '</h2>';
		echo '<p>' . esc_html__( 'Her vises fejl, fx hvis JSON-LD ikke kunne udskrives på en side. Identiske fejl samles i én linje. De seneste 100 linjer gemmes.', 'marginal-schema-markup' ) . '</p>';

		if ( empty( $entries ) ) {
			echo '<p><em>' . esc_html__( 'Ingen fejl registreret.', 'marginal-schema-markup' ) . '</em></p>';
			return;
		}

		$levels = array(
			'error'   => __( 'Fejl', 'marginal-schema-markup' ),
			'warning' => __( 'Advarsel', 'marginal-schema-markup' ),
			'info'    => __( 'Info', 'marginal-schema-markup' ),
		);

		echo '<table class="widefat striped marginal-schema-log">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Sidst set', 'marginal-schema-markup' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'marginal-schema-markup' ) . '</th>';
		echo '<th>' . esc_html__( 'Besked', 'marginal-schema-markup' ) . '</th>';
		echo '<th>' . esc_html__( 'Side', 'marginal-schema-markup' ) . '</th>';
		echo '<th>' . esc_html__( 'URL', 'marginal-schema-markup' ) . '</th>';
		echo '<th>' . esc_html__( 'Antal', 'marginal-schema-markup' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$level   = isset( $entry['level'] ) && isset( $levels[ $entry['level'] ] ) ? $entry['level'] : 'error';
			$post_id = isset( $entry['post_id'] ) ? (int) $entry['post_id'] : 0;
			$last    = isset( $entry['last'] ) ? (int) $entry['last'] : 0;

			echo '<tr>';
			echo '<td>' . esc_html( $last ? wp_date( 'Y-m-d H:i:s', $last ) : '' ) . '</td>';
			echo '<td><span class="marginal-schema-level marginal-schema-level-' . esc_attr( $level ) . '">' . esc_html( $levels[ $level ] ) . '</span></td>';
			echo '<td>' . esc_html( isset( $entry['message'] ) ? (string) $entry['message'] : '' ) . '</td>';
			echo '<td>';
			if ( $post_id > 0 && get_post( $post_id ) ) {
				$edit = get_edit_post_link( $post_id, 'url' );
				if ( $edit ) {
					echo '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Redigér', 'marginal-schema-markup' ) . ' #' . (int) $post_id . '</a>';
				} else {
					echo '#' . (int) $post_id;
				}
			} else {
				echo '&ndash;';
			}
			echo '</td>';
			echo '<td><code>' . esc_html( isset( $entry['url'] ) ? (string) $entry['url'] : '' ) . '</code></td>';
			echo '<td>' . (int) ( isset( $entry['count'] ) ? $entry['count'] : 1 ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="marginal-schema-clear">';
		echo '<input type="hidden" name="action" value="marginal_schema_clear_log" />';
		wp_nonce_field( 'marginal_schema_clear_log' );
		submit_button( __( 'Ryd log', 'marginal-schema-markup' ), 'delete', 'submit', false );
		echo '</form>';
	}

	/**
	 * Fane: opdateringer.
	 */
	private static function render_updates_tab() {
		self::render_result_notice();

		$release = Marginal_Schema_Updater::get_cached_release();

		echo '<h2>' . esc_html__( 'Opdateringer fra GitHub', 'marginal-schema-markup' ) . '</h2>';
		echo '<p>' . esc_html__( 'Pluginnet henter nye versioner fra GitHub-releases. Når der findes en nyere version, vises den under Plugins og Kontrolpanel → Opdateringer som alle andre plugins.', 'marginal-schema-markup' ) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th>' . esc_html__( 'Installeret version', 'marginal-schema-markup' ) . '</th><td><code>' . esc_html( MARGINAL_SCHEMA_VERSION ) . '</code></td></tr>';

		echo '<tr><th>' . esc_html__( 'Nyeste version på GitHub', 'marginal-schema-markup' ) . '</th><td>';
		if ( is_array( $release ) && ! empty( $release['version'] ) ) {
			echo '<code>' . esc_html( $release['version'] ) . '</code>';
			if ( version_compare( $release['version'], MARGINAL_SCHEMA_VERSION, '>' ) ) {
				echo ' &mdash; <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Opdatering tilgængelig', 'marginal-schema-markup' ) . '</a>';
			} else {
				echo ' &mdash; ' . esc_html__( 'du har den nyeste version', 'marginal-schema-markup' );
			}
		} else {
			echo esc_html__( 'Ikke tjekket endnu, eller seneste tjek fejlede (se loggen).', 'marginal-schema-markup' );
		}
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Sidst tjekket', 'marginal-schema-markup' ) . '</th><td>';
		echo esc_html( is_array( $release ) && ! empty( $release['checked'] ) ? wp_date( 'Y-m-d H:i:s', (int) $release['checked'] ) : '–' );
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Kilde', 'marginal-schema-markup' ) . '</th><td><a href="' . esc_url( Marginal_Schema_Updater::REPO_URL . '/releases' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( Marginal_Schema_Updater::REPO_URL ) . '</a></td></tr>';
		echo '</tbody></table>';

		if ( current_user_can( 'update_plugins' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="marginal_schema_check_update" />';
			wp_nonce_field( 'marginal_schema_check_update' );
			submit_button( __( 'Tjek for opdateringer nu', 'marginal-schema-markup' ), 'secondary', 'submit', false );
			echo '</form>';
		}
	}

	/**
	 * Vis resultat af en handling (efter redirect).
	 */
	private static function render_result_notice() {
		$done = isset( $_GET['marginal_schema_done'] ) ? sanitize_key( wp_unslash( $_GET['marginal_schema_done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- kun visning af fast tekst.
		$messages = array(
			'cleared' => __( 'Loggen er ryddet.', 'marginal-schema-markup' ),
			'checked' => __( 'Der er tjekket for opdateringer.', 'marginal-schema-markup' ),
		);
		if ( isset( $messages[ $done ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $done ] ) . '</p></div>';
		}
	}

	/**
	 * Ryd log (POST + nonce + capability).
	 */
	public static function handle_clear_log() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Du har ikke adgang til denne handling.', 'marginal-schema-markup' ), 403 );
		}
		check_admin_referer( 'marginal_schema_clear_log' );

		Marginal_Schema_Logger::clear();

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE . '&tab=log&marginal_schema_done=cleared' ) );
		exit;
	}

	/**
	 * Tjek for opdateringer nu (POST + nonce + capability).
	 */
	public static function handle_check_update() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Du har ikke adgang til denne handling.', 'marginal-schema-markup' ), 403 );
		}
		check_admin_referer( 'marginal_schema_check_update' );

		Marginal_Schema_Updater::force_check();

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE . '&tab=updates&marginal_schema_done=checked' ) );
		exit;
	}
}

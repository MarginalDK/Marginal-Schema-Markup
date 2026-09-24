<?php
/**
 * Felt til side-specifik JSON-LD på redigeringsskærmen for Pages.
 *
 * Bruger en klassisk meta box, som virker i både blok-editoren (Gutenberg)
 * og den klassiske editor.
 *
 * @package Marginal_Schema_Markup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Meta box.
 */
final class Marginal_Schema_Metabox {

	const NONCE_ACTION = 'marginal_schema_save_meta';
	const NONCE_FIELD  = 'marginal_schema_meta_nonce';
	const FIELD        = 'marginal_schema_jsonld';

	/**
	 * Registrér hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Tilføj meta box.
	 *
	 * @param string $post_type Indholdstype.
	 */
	public static function add( $post_type ) {
		if ( ! in_array( $post_type, Marginal_Schema_Output::post_types(), true ) ) {
			return;
		}

		add_meta_box(
			'marginal-schema-markup',
			__( 'Schema markup (JSON-LD)', 'marginal-schema-markup' ),
			array( __CLASS__, 'render' ),
			$post_type,
			'normal',
			'default'
		);
	}

	/**
	 * Indlæs admin-assets kun på redigeringsskærmen.
	 *
	 * @param string $hook Admin-side.
	 */
	public static function enqueue( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, Marginal_Schema_Output::post_types(), true ) ) {
			return;
		}
		Marginal_Schema_Admin::enqueue_assets();
	}

	/**
	 * Vis feltet.
	 *
	 * @param WP_Post $post Siden.
	 */
	public static function render( $post ) {
		try {
			$value = get_post_meta( $post->ID, Marginal_Schema_Output::META_KEY, true );
			$value = is_string( $value ) ? $value : '';

			wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

			echo '<p>' . esc_html__( 'Indsæt JSON-LD der kun gælder for denne side. Det udskrives nederst i <head>, efter den globale JSON-LD. Du kan indsætte ren JSON eller hele <script type="application/ld+json">-blokken.', 'marginal-schema-markup' ) . '</p>';

			Marginal_Schema_Admin::render_textarea( self::FIELD, self::FIELD, $value, 14 );

			if ( '' !== trim( $value ) ) {
				$valid = Marginal_Schema_Json::validate( $value );
				if ( is_wp_error( $valid ) ) {
					echo '<p class="marginal-schema-saved-error"><strong>' . esc_html__( 'Den gemte JSON-LD er ugyldig og bliver IKKE udskrevet:', 'marginal-schema-markup' ) . '</strong> ' . esc_html( $valid->get_error_message() ) . '</p>';
				}
			}
		} catch ( \Throwable $e ) {
			echo '<p>' . esc_html__( 'Feltet kunne ikke vises. Se loggen under Indstillinger → Schema Markup.', 'marginal-schema-markup' ) . '</p>';
			Marginal_Schema_Logger::error( 'Meta box kunne ikke vises: ' . $e->getMessage(), isset( $post->ID ) ? (int) $post->ID : 0 );
		}
	}

	/**
	 * Gem feltet.
	 *
	 * @param int     $post_id Side-ID.
	 * @param WP_Post $post    Siden.
	 */
	public static function save( $post_id, $post ) {
		// Kun når vores formular faktisk er sendt med.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, Marginal_Schema_Output::post_types(), true ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::FIELD ] ) ) {
			return;
		}

		try {
			// Renses i Marginal_Schema_Json::sanitize_input() (også via register_post_meta).
			$value = Marginal_Schema_Json::sanitize_input( wp_unslash( $_POST[ self::FIELD ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( '' === $value ) {
				delete_post_meta( $post_id, Marginal_Schema_Output::META_KEY );
				return;
			}

			// update_post_meta() kører wp_unslash() på værdien, så den skal slashes først for at bevare \ i JSON.
			update_post_meta( $post_id, Marginal_Schema_Output::META_KEY, wp_slash( $value ) );

			$valid = Marginal_Schema_Json::validate( $value );
			if ( is_wp_error( $valid ) ) {
				Marginal_Schema_Logger::warning(
					sprintf( 'Ugyldig JSON-LD gemt på "%s" (ID %d): %s', get_the_title( $post_id ), $post_id, $valid->get_error_message() ),
					$post_id
				);
			}
		} catch ( \Throwable $e ) {
			Marginal_Schema_Logger::error( 'JSON-LD kunne ikke gemmes: ' . $e->getMessage(), $post_id );
		}
	}
}

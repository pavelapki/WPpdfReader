<?php
/**
 * Keep the addresses of imported records working.
 *
 * Taking over the other plugin's URL prefix is the clean way to preserve
 * links, but it only works when every slug lines up. This catches whatever is
 * left: a 404 whose path matches a record that was imported gets a permanent
 * redirect to its document.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Redirects for addresses that belonged to another plugin.
 */
class WPPDF_Redirects {

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 5 );
	}

	/**
	 * Redirect a 404 that used to be an imported record.
	 */
	public function maybe_redirect() {
		if ( ! WPPDF_Settings::get( 'redirect_old_urls' ) || ! is_404() ) {
			return;
		}

		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}

		$path = self::get_request_path();

		if ( '' === $path ) {
			return;
		}

		$post_id = self::find_document( $path );

		if ( ! $post_id ) {
			return;
		}

		$target = get_permalink( $post_id );

		if ( ! $target || untrailingslashit( wp_parse_url( $target, PHP_URL_PATH ) ) === untrailingslashit( $path ) ) {
			return;
		}

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * The path of the current request, normalised.
	 *
	 * @return string
	 */
	protected static function get_request_path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$path = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );

		if ( ! $path ) {
			return '';
		}

		// Sites in a subdirectory carry that prefix in every request.
		$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) - 1 );
		}

		return '/' . trim( (string) $path, '/' ) . '/';
	}

	/**
	 * Find the document an old path belongs to.
	 *
	 * The full old path is matched first, so a record that shared its slug
	 * with something else still lands on the right document; only then is the
	 * bare slug tried.
	 *
	 * @param string $path Request path.
	 * @return int Document ID, 0 when there is no match.
	 */
	protected static function find_document( $path ) {
		global $wpdb;

		$post_types   = WPPDF_Post_Type::get_supported_post_types();
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		$sql = "SELECT p.ID FROM {$wpdb->posts} AS p
			INNER JOIN {$wpdb->postmeta} AS m ON m.post_id = p.ID
			WHERE p.post_status = 'publish'
			AND p.post_type IN ( {$placeholders} )
			AND m.meta_key = %s AND m.meta_value = %s
			LIMIT 1";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above and passed to prepare.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				$sql,
				array_merge( $post_types, array( WPPDF_Migrator::META_PATH, $path ) )
			)
		);

		if ( $found ) {
			return (int) $found;
		}

		// Without a trailing slash, for sites that do not use one.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above and passed to prepare.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				$sql,
				array_merge( $post_types, array( WPPDF_Migrator::META_PATH, untrailingslashit( $path ) ) )
			)
		);

		if ( $found ) {
			return (int) $found;
		}

		$segments = array_values( array_filter( explode( '/', $path ) ) );
		$slug     = end( $segments );

		if ( ! $slug ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above and passed to prepare.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				$sql,
				array_merge( $post_types, array( WPPDF_Migrator::META_SLUG, $slug ) )
			)
		);

		return $found ? (int) $found : 0;
	}
}

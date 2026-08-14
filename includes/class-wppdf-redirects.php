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

		// A 404 is a cheap page to serve and bots find plenty of them, so sites
		// that never imported anything must not pay for a lookup at all.
		if ( ! self::has_imported_documents() ) {
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
	 * Whether this site has any imported record to redirect to.
	 *
	 * @return bool
	 */
	protected static function has_imported_documents() {
		$flag = wp_cache_get( 'has_imported', 'wppdf' );

		if ( false !== $flag ) {
			return (bool) $flag;
		}

		$flag = get_transient( 'wppdf_has_imported' );

		if ( false === $flag ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- result is cached in the transient right below.
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s ) LIMIT 1",
					WPPDF_Migrator::META_PATH,
					WPPDF_Migrator::META_SLUG
				)
			);

			$flag = $exists ? '1' : '0';

			set_transient( 'wppdf_has_imported', $flag, DAY_IN_SECONDS );
		}

		wp_cache_set( 'has_imported', $flag, 'wppdf' );

		return '1' === (string) $flag;
	}

	/**
	 * Find the document an old path belongs to.
	 *
	 * The full old path is matched first, so a record that shared its slug
	 * with something else still lands on the right document; only then is the
	 * bare slug tried. All three candidates go in one query, ranked in SQL,
	 * because a 404 should not cost three round trips.
	 *
	 * @param string $path Request path.
	 * @return int Document ID, 0 when there is no match.
	 */
	protected static function find_document( $path ) {
		global $wpdb;

		$post_types   = WPPDF_Post_Type::get_supported_post_types();
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		$segments = array_values( array_filter( explode( '/', $path ) ) );
		$slug     = (string) end( $segments );

		$sql = "SELECT p.ID,
				CASE
					WHEN m.meta_key = %s AND m.meta_value = %s THEN 1
					WHEN m.meta_key = %s AND m.meta_value = %s THEN 2
					ELSE 3
				END AS match_rank
			FROM {$wpdb->posts} AS p
			INNER JOIN {$wpdb->postmeta} AS m ON m.post_id = p.ID
			WHERE p.post_status = 'publish'
			AND p.post_type IN ( {$placeholders} )
			AND (
				( m.meta_key = %s AND m.meta_value IN ( %s, %s ) )
				OR ( m.meta_key = %s AND m.meta_value = %s )
			)
			ORDER BY match_rank ASC
			LIMIT 1";

		$params = array_merge(
			array(
				WPPDF_Migrator::META_PATH,
				$path,
				WPPDF_Migrator::META_PATH,
				untrailingslashit( $path ),
			),
			$post_types,
			array(
				WPPDF_Migrator::META_PATH,
				$path,
				untrailingslashit( $path ),
				WPPDF_Migrator::META_SLUG,
				$slug,
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- placeholders are built above and passed to prepare; a 404 path is not worth its own cache entry.
		$found = $wpdb->get_var( $wpdb->prepare( $sql, $params ) );

		return $found ? (int) $found : 0;
	}
}

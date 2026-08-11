<?php
/**
 * View and download counters per language.
 *
 * The numbers answer a concrete question: is the fallback language actually
 * being read, or is nobody opening it? They are deliberately approximate —
 * counting happens once per browser session and is never blocking.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Document statistics.
 */
class WPPDF_Stats {

	/**
	 * REST namespace.
	 */
	const NAMESPACE_ROUTE = 'wp-pdf-reader/v1';

	/**
	 * Meta key prefix for views.
	 */
	const META_VIEWS = '_wppdf_views_';

	/**
	 * Meta key prefix for downloads.
	 */
	const META_DOWNLOADS = '_wppdf_downloads_';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Meta key for a counter.
	 *
	 * @param string $type view or download.
	 * @param string $code Language code.
	 * @return string
	 */
	public static function meta_key( $type, $code ) {
		$prefix = 'download' === $type ? self::META_DOWNLOADS : self::META_VIEWS;

		return $prefix . str_replace( '-', '_', WPPDF_Settings::sanitize_language_code( $code ) );
	}

	/**
	 * Register the counting endpoint.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_ROUTE,
			'/hit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_hit' ),
				// Counting is anonymous by design; the callback validates that
				// the target really is a published document of ours.
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'   => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'lang' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => array( 'WPPDF_Settings', 'sanitize_language_code' ),
					),
					'type' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'view', 'download' ),
					),
				),
			)
		);
	}

	/**
	 * Record one hit.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_hit( $request ) {
		if ( ! WPPDF_Settings::get( 'count_views' ) ) {
			return new WP_REST_Response( array( 'counted' => false ), 200 );
		}

		$post_id = absint( $request->get_param( 'id' ) );
		$code    = WPPDF_Settings::sanitize_language_code( $request->get_param( 'lang' ) );
		$type    = 'download' === $request->get_param( 'type' ) ? 'download' : 'view';

		$post = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, WPPDF_Post_Type::get_supported_post_types(), true ) ) {
			return new WP_Error( 'wppdf_unknown_document', __( 'Unknown document.', 'wp-pdf-reader' ), array( 'status' => 404 ) );
		}

		if ( '' === $code || ! in_array( $code, WPPDF_Languages::get_codes(), true ) ) {
			return new WP_Error( 'wppdf_unknown_language', __( 'Unknown language.', 'wp-pdf-reader' ), array( 'status' => 400 ) );
		}

		/**
		 * Filter whether a hit is counted, e.g. to skip bots or rate limit.
		 *
		 * @param bool   $count   Whether to count.
		 * @param int    $post_id Document ID.
		 * @param string $code    Language code.
		 * @param string $type    view or download.
		 */
		if ( ! apply_filters( 'wppdf_count_hit', true, $post_id, $code, $type ) ) {
			return new WP_REST_Response( array( 'counted' => false ), 200 );
		}

		if ( self::is_throttled( $post_id, $code, $type ) ) {
			return new WP_REST_Response( array( 'counted' => false ), 200 );
		}

		self::increment( $post_id, self::meta_key( $type, $code ) );

		return new WP_REST_Response( array( 'counted' => true ), 200 );
	}

	/**
	 * Rate limit repeated hits from the same client.
	 *
	 * The endpoint is anonymous, so it needs a cheap guard against being used
	 * to hammer the database. This only runs where a persistent object cache
	 * exists — without one, a throttle would cost more writes than the counter
	 * it protects, so the browser side deduplication is left to do the work.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $code    Language code.
	 * @param string $type    view or download.
	 * @return bool Whether this hit should be ignored.
	 */
	protected static function is_throttled( $post_id, $code, $type ) {
		if ( ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
			return false;
		}

		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( '' === $address ) {
			return false;
		}

		$key = 'hit_' . md5( $address . '|' . $post_id . '|' . $code . '|' . $type );

		if ( wp_cache_get( $key, 'wppdf' ) ) {
			return true;
		}

		/**
		 * Filter how long the same client is ignored for the same document.
		 *
		 * @param int $seconds Throttle window.
		 */
		wp_cache_set( $key, 1, 'wppdf', (int) apply_filters( 'wppdf_hit_throttle', 10 * MINUTE_IN_SECONDS ) );

		return false;
	}

	/**
	 * Increment a counter without reading it first.
	 *
	 * @param int    $post_id  Document ID.
	 * @param string $meta_key Counter meta key.
	 */
	protected static function increment( $post_id, $meta_key ) {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s",
				$post_id,
				$meta_key
			)
		);

		if ( ! $updated ) {
			add_post_meta( $post_id, $meta_key, 1, true );
		}

		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * Read a counter.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $type    view or download.
	 * @param string $code    Language code, empty for the sum of all languages.
	 * @return int
	 */
	public static function get( $post_id, $type = 'view', $code = '' ) {
		if ( '' !== $code ) {
			return (int) get_post_meta( $post_id, self::meta_key( $type, $code ), true );
		}

		$total = 0;

		foreach ( WPPDF_Languages::get_codes() as $language ) {
			$total += (int) get_post_meta( $post_id, self::meta_key( $type, $language ), true );
		}

		return $total;
	}

	/**
	 * Counters of every language, for the admin.
	 *
	 * @param int $post_id Document ID.
	 * @return array Map of language code => array( views, downloads ).
	 */
	public static function get_breakdown( $post_id ) {
		$breakdown = array();

		foreach ( WPPDF_Languages::get_codes() as $code ) {
			$views     = (int) get_post_meta( $post_id, self::meta_key( 'view', $code ), true );
			$downloads = (int) get_post_meta( $post_id, self::meta_key( 'download', $code ), true );

			if ( $views || $downloads ) {
				$breakdown[ $code ] = array(
					'views'     => $views,
					'downloads' => $downloads,
				);
			}
		}

		return $breakdown;
	}
}

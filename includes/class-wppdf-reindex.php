<?php
/**
 * Backfill for documents that were created before extraction existed, or
 * whose files were attached without going through the editor.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reindexing tools.
 */
class WPPDF_Reindex {

	/**
	 * Nonce action.
	 */
	const NONCE = 'wppdf_reindex';

	/**
	 * Documents handled per request.
	 */
	const BATCH = 5;

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'wp_ajax_wppdf_reindex', array( $this, 'handle_ajax' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'pdf-reader reindex', array( $this, 'cli_reindex' ) );
		}
	}

	/**
	 * Documents that hold at least one file.
	 *
	 * @param bool $only_missing Limit to documents without an index yet.
	 * @param int  $limit        Maximum IDs to return, 0 for all.
	 * @param int  $offset       Offset for batching.
	 * @return int[]
	 */
	public static function get_document_ids( $only_missing = true, $limit = 0, $offset = 0 ) {
		global $wpdb;

		$post_types = WPPDF_Post_Type::get_supported_post_types();
		$types      = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		$file_prefix = $wpdb->esc_like( WPPDF_Languages::META_FILE ) . '%';
		$text_prefix = $wpdb->esc_like( WPPDF_Text::META_TEXT ) . '%';

		$sql = "SELECT DISTINCT p.ID
			FROM {$wpdb->posts} AS p
			INNER JOIN {$wpdb->postmeta} AS f ON ( f.post_id = p.ID AND f.meta_key LIKE %s AND f.meta_value != '' )
			WHERE p.post_type IN ( {$types} )
			AND p.post_status NOT IN ( 'trash', 'auto-draft' )";

		$params = array_merge( array( $file_prefix ), $post_types );

		if ( $only_missing ) {
			$sql     .= " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS t WHERE t.post_id = p.ID AND t.meta_key LIKE %s AND t.meta_value != '' )";
			$params[] = $text_prefix;
		}

		$sql .= ' ORDER BY p.ID ASC';

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = (int) $limit;
			$params[] = (int) $offset;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above and passed to prepare.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * How many documents still need indexing.
	 *
	 * @return array Array with total and pending counts.
	 */
	public static function get_progress() {
		return array(
			'total'   => count( self::get_document_ids( false ) ),
			'pending' => count( self::get_document_ids( true ) ),
		);
	}

	/**
	 * Index one document, all of its languages.
	 *
	 * @param int  $post_id Document ID.
	 * @param bool $force   Re-extract even when text is already stored.
	 * @param bool $covers  Also render missing covers.
	 * @return array Languages processed.
	 */
	public static function index_document( $post_id, $force = false, $covers = true ) {
		$done = array();

		foreach ( WPPDF_Languages::get_codes() as $code ) {
			$attachment_id = absint( get_post_meta( $post_id, WPPDF_Languages::file_meta_key( $code ), true ) );

			if ( ! $attachment_id || ! WPPDF_Documents::is_valid_attachment( $attachment_id ) ) {
				continue;
			}

			$has_text = '' !== (string) get_post_meta( $post_id, WPPDF_Text::text_meta_key( $code ), true );

			if ( $force || ! $has_text ) {
				WPPDF_Text::run( $post_id, $code, $attachment_id );
				$done[] = $code;
			}

			if ( $covers && ! get_post_meta( $post_id, WPPDF_Languages::cover_meta_key( $code ), true ) ) {
				WPPDF_Cover::run( $post_id, $code, $attachment_id );
			}
		}

		return $done;
	}

	/**
	 * Process one batch from the admin screen.
	 */
	public function handle_ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to reindex documents.', 'wp-pdf-reader' ) ), 403 );
		}

		$force  = ! empty( $_POST['force'] );
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;

		// Without force the pending list shrinks as work is done, so the batch
		// is always taken from the front of the queue.
		$ids = self::get_document_ids( ! $force, self::BATCH, $force ? $offset : 0 );

		$processed = array();

		foreach ( $ids as $post_id ) {
			self::index_document( $post_id, $force );

			$processed[] = array(
				'id'    => $post_id,
				'title' => get_the_title( $post_id ),
				'pages' => WPPDF_Text::get_page_count( $post_id ),
			);
		}

		$progress = self::get_progress();

		wp_send_json_success(
			array(
				'processed' => $processed,
				'offset'    => $offset + count( $ids ),
				'done'      => count( $ids ) < self::BATCH,
				'pending'   => $progress['pending'],
				'total'     => $progress['total'],
			)
		);
	}

	/**
	 * Reindex documents.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Re-extract documents that already have text stored.
	 *
	 * [--skip-covers]
	 * : Do not render missing cover images.
	 *
	 * [--limit=<number>]
	 * : Stop after this many documents.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pdf-reader reindex
	 *     wp pdf-reader reindex --force --limit=50
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cli_reindex( $args, $assoc_args ) {
		$force  = ! empty( $assoc_args['force'] );
		$covers = empty( $assoc_args['skip-covers'] );
		$limit  = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;

		$ids = self::get_document_ids( ! $force, $limit );

		if ( empty( $ids ) ) {
			WP_CLI::success( 'Nothing to index.' );

			return;
		}

		$progress = WP_CLI\Utils\make_progress_bar( 'Indexing documents', count( $ids ) );
		$indexed  = 0;

		foreach ( $ids as $post_id ) {
			$done = self::index_document( $post_id, $force, $covers );

			if ( $done ) {
				$indexed++;
			}

			$progress->tick();
		}

		$progress->finish();

		WP_CLI::success( sprintf( '%d of %d documents indexed.', $indexed, count( $ids ) ) );
	}
}

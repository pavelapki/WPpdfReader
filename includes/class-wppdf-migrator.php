<?php
/**
 * Import documents from another plugin's post type.
 *
 * There is one adapter written against a plugin's actual storage — TNC
 * FlipBook 3D, whose meta keys were read from its source — and a generic path
 * that finds PDF attachments in any post type by inspecting its meta. The
 * generic path exists because most flipbook plugins are commercial and their
 * key names cannot be verified, and guessing them would be worse than looking.
 *
 * Nothing is ever deleted from the source: an import copies.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Migration from other plugins.
 */
class WPPDF_Migrator {

	/**
	 * Nonce action.
	 */
	const NONCE = 'wppdf_migrate';

	/**
	 * Records handled per request.
	 */
	const BATCH = 10;

	/**
	 * Records listed per page when picking them by hand.
	 */
	const PREVIEW = 25;

	/**
	 * Meta key holding the source record ID.
	 */
	const META_SOURCE_ID = '_wppdf_imported_from';

	/**
	 * Meta key holding the source post type.
	 */
	const META_SOURCE_TYPE = '_wppdf_imported_source';

	/**
	 * Meta key flagging a source record that held no PDF.
	 */
	const META_SKIPPED = '_wppdf_import_skipped';

	/**
	 * Meta key holding the slug the record had in the other plugin.
	 */
	const META_SLUG = '_wppdf_imported_slug';

	/**
	 * Meta key holding the full path the record used to answer on.
	 */
	const META_PATH = '_wppdf_imported_path';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'wp_ajax_wppdf_migrate', array( $this, 'handle_ajax' ) );
		add_action( 'wp_ajax_wppdf_migrate_preview', array( $this, 'handle_preview' ) );
		add_action( 'wp_ajax_wppdf_adopt_slug', array( $this, 'handle_adopt_slug' ) );
	}

	/**
	 * Adapters for plugins whose storage is known.
	 *
	 * @return array Map of post type => adapter definition.
	 */
	public static function get_adapters() {
		$adapters = array(
			'tnc_flipbook' => array(
				'label'     => 'TNC FlipBook 3D',
				'file'      => array( '_tncfb3d_pdf_id' ),
				'file_list' => array( '_tncfb3d_pdf_ids' ),
				'pages'     => '_tncfb3d_text_page_count',
				'text'      => '_tncfb3d_extracted_text',
				'note'      => __( 'Image based flipbooks hold no PDF and are reported as skipped.', 'wp-pdf-reader' ),
			),
		);

		/**
		 * Filter the known import adapters.
		 *
		 * @param array $adapters Map of post type => adapter definition.
		 */
		return apply_filters( 'wppdf_import_adapters', $adapters );
	}

	/**
	 * Post types that could hold documents, with their record counts.
	 *
	 * Deliberately a plain grouped count over an indexed column: it also lists
	 * types whose plugin is deactivated, which is exactly when a migration is
	 * wanted, and it never scans meta.
	 *
	 * @return array List of arrays with type, label, count, adapter and imported.
	 */
	public static function get_sources() {
		global $wpdb;

		$excluded = array_merge(
			WPPDF_Post_Type::get_supported_post_types(),
			array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' )
		);

		$placeholders = implode( ',', array_fill( 0, count( $excluded ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $placeholders is a list of %s built from a count and filled by prepare(); an admin screen listing what can be migrated must not show stale counts.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_type, COUNT(*) AS total
				FROM {$wpdb->posts}
				WHERE post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )
				AND post_type NOT IN ( {$placeholders} )
				GROUP BY post_type
				ORDER BY total DESC",
				$excluded
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$adapters = self::get_adapters();
		$imported = self::count_imported_by_type();
		$sources  = array();

		foreach ( (array) $rows as $row ) {
			$type   = (string) $row->post_type;
			$object = get_post_type_object( $type );

			$sources[] = array(
				'type'     => $type,
				'label'    => $object && ! empty( $object->labels->name ) ? $object->labels->name : $type,
				'count'    => (int) $row->total,
				'adapter'  => isset( $adapters[ $type ] ) ? $adapters[ $type ]['label'] : '',
				'imported' => isset( $imported[ $type ] ) ? $imported[ $type ] : 0,
				'active'   => (bool) $object,
				'slug'     => self::get_rewrite_slug( $type ),
			);
		}

		return $sources;
	}

	/**
	 * Imported counts for every source at once.
	 *
	 * One grouped query instead of one per source type, which on a site with
	 * many post types was the slowest part of opening the migration screen.
	 *
	 * @return array Map of post type => count.
	 */
	public static function count_imported_by_type() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin screen only, and the numbers must be current.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS post_type, COUNT(*) AS total
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s
				GROUP BY meta_value",
				self::META_SOURCE_TYPE
			)
		);

		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row->post_type ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * The URL prefix a source post type uses.
	 *
	 * Only readable while its plugin is active, so it is also remembered on
	 * the first import for later.
	 *
	 * @param string $post_type Source post type.
	 * @return string Prefix without slashes, empty when unknown.
	 */
	public static function get_rewrite_slug( $post_type ) {
		$object = get_post_type_object( $post_type );

		if ( $object && ! empty( $object->rewrite['slug'] ) ) {
			$slug = sanitize_title( $object->rewrite['slug'] );

			if ( '' !== $slug ) {
				self::remember_slug( $post_type, $slug );

				return $slug;
			}
		}

		$remembered = get_option( 'wppdf_source_slugs', array() );

		return isset( $remembered[ $post_type ] ) ? (string) $remembered[ $post_type ] : '';
	}

	/**
	 * Remember a source's URL prefix for when its plugin is gone.
	 *
	 * @param string $post_type Source post type.
	 * @param string $slug      URL prefix.
	 */
	protected static function remember_slug( $post_type, $slug ) {
		$remembered = get_option( 'wppdf_source_slugs', array() );

		if ( ! is_array( $remembered ) ) {
			$remembered = array();
		}

		if ( isset( $remembered[ $post_type ] ) && $remembered[ $post_type ] === $slug ) {
			return;
		}

		$remembered[ $post_type ] = $slug;

		update_option( 'wppdf_source_slugs', $remembered, false );
	}

	/**
	 * Adopt a source's URL prefix for the documents.
	 *
	 * @param string $slug URL prefix.
	 * @return bool
	 */
	public static function adopt_slug( $slug ) {
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			return false;
		}

		$settings                   = WPPDF_Settings::all();
		$settings['post_type_slug'] = $slug;

		update_option( WPPDF_Settings::OPTION, $settings );
		update_option( 'wppdf_flush_rewrite', 1 );

		WPPDF_Settings::flush_cache();

		return true;
	}

	/**
	 * Take over a source's URL prefix from the import screen.
	 */
	public function handle_adopt_slug() {
		check_ajax_referer( self::NONCE, 'nonce' );

		// This rewrites a plugin setting, so it needs more than edit_posts.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to change the settings.', 'wp-pdf-reader' ) ), 403 );
		}

		$slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';

		if ( '' === $slug ) {
			wp_send_json_error( array( 'message' => __( 'That is not a usable URL prefix.', 'wp-pdf-reader' ) ), 400 );
		}

		if ( ! self::adopt_slug( $slug ) ) {
			wp_send_json_error( array( 'message' => __( 'The URL prefix could not be changed.', 'wp-pdf-reader' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: URL prefix. */
					__( 'Documents now live under /%s/. Deactivate the other plugin so it stops claiming the same addresses.', 'wp-pdf-reader' ),
					$slug
				),
			)
		);
	}

	/**
	 * How many records of a source were already imported.
	 *
	 * @param string $post_type Source post type.
	 * @return int
	 */
	public static function count_imported( $post_type ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counting meta rows has no API, and a stale count would misreport migration progress.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				self::META_SOURCE_TYPE,
				$post_type
			)
		);
	}

	/**
	 * Records of a source that were not imported yet.
	 *
	 * @param string $post_type Source post type.
	 * @param int    $limit     Maximum IDs.
	 * @param int    $offset    Offset into the pending list.
	 * @return int[]
	 */
	public static function get_pending_ids( $post_type, $limit = 0, $offset = 0 ) {
		return self::query_pending(
			$post_type,
			array(
				'limit'  => $limit,
				'offset' => $offset,
			)
		);
	}

	/**
	 * How many records of a source are still waiting.
	 *
	 * @param string $post_type Source post type.
	 * @return int
	 */
	public static function count_pending( $post_type ) {
		return (int) self::query_pending( $post_type, array( 'count_only' => true ) );
	}

	/**
	 * Keep only the IDs that really are pending records of this source.
	 *
	 * When the browser names the records to import, the names cannot be taken
	 * at face value: they would otherwise reach any post on the site, of any
	 * type and status. The pending query is the authority on what may be
	 * imported, so the request is intersected with it rather than checked
	 * against it field by field.
	 *
	 * @param string $post_type Source post type.
	 * @param int[]  $ids       Requested record IDs.
	 * @return int[] The subset that may be imported, in the requested order.
	 */
	public static function filter_pending( $post_type, array $ids ) {
		$ids = array_values( array_filter( array_unique( array_map( 'absint', $ids ) ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$allowed = self::query_pending( $post_type, array( 'include' => $ids ) );

		return array_values( array_intersect( $ids, $allowed ) );
	}

	/**
	 * Shared query behind the pending list and its count.
	 *
	 * A record that held no PDF is flagged on the source, otherwise the same
	 * ten records would be handed back on every batch and the run would never
	 * finish. Starting a fresh run clears those flags again.
	 *
	 * @param string $post_type Source post type.
	 * @param array  $args      limit, offset, count_only and include.
	 * @return int[]|int
	 */
	protected static function query_pending( $post_type, array $args = array() ) {
		global $wpdb;

		$args = array_merge(
			array(
				'limit'      => 0,
				'offset'     => 0,
				'count_only' => false,
				'include'    => array(),
			),
			$args
		);

		$count_only = (bool) $args['count_only'];
		$limit      = (int) $args['limit'];
		$select     = $count_only ? 'COUNT(*)' : 'p.ID';

		$sql = "SELECT {$select} FROM {$wpdb->posts} AS p
			WHERE p.post_type = %s
			AND p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' )
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} AS m
				WHERE m.meta_key = %s AND m.meta_value = CAST( p.ID AS CHAR )
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} AS s
				WHERE s.post_id = p.ID AND s.meta_key = %s
			)";

		$params = array( $post_type, self::META_SOURCE_ID, self::META_SKIPPED );

		$include = array_values( array_filter( array_map( 'absint', (array) $args['include'] ) ) );

		if ( $include ) {
			$sql   .= ' AND p.ID IN ( ' . implode( ',', array_fill( 0, count( $include ), '%d' ) ) . ' )';
			$params = array_merge( $params, $include );
		}

		if ( $count_only ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a NOT EXISTS over meta is not expressible in WP_Query, and the remaining count changes with every batch.
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		}

		$sql .= ' ORDER BY p.ID ASC';

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = max( 0, (int) $args['offset'] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a NOT EXISTS over meta is not expressible in WP_Query, and each batch must see what the last one wrote.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * What a page of pending records holds, so they can be picked by hand.
	 *
	 * A source usually contains records that carry no PDF at all — stub
	 * translations, drafts someone started, an index page. Importing those
	 * produces documents with nothing to show, so the screen lists what was
	 * found in each record and lets the operator choose.
	 *
	 * @param string $post_type Source post type.
	 * @param int    $limit     Records per page.
	 * @param int    $offset    Offset into the pending list.
	 * @return array[] One row per record.
	 */
	public static function preview( $post_type, $limit = 25, $offset = 0 ) {
		$ids = self::get_pending_ids( $post_type, max( 1, (int) $limit ), $offset );

		if ( empty( $ids ) ) {
			return array();
		}

		// describe() reads several meta values per record and the generic path
		// reads all of them, so without priming this is one query per row twice
		// over. Both caches are filled in one query each.
		_prime_post_caches( $ids, false, true );

		$terms = self::get_source_terms_batch( $ids );
		$rows  = array();

		foreach ( $ids as $id ) {
			$post  = get_post( $id );
			$found = self::describe( $id, $post_type );

			$names = array();

			foreach ( isset( $terms[ $id ] ) ? $terms[ $id ] : array() as $taxonomy_terms ) {
				foreach ( $taxonomy_terms as $term ) {
					$names[] = $term['name'];
				}
			}

			$file = '';

			if ( ! empty( $found['attachments'] ) ) {
				$file = basename( (string) get_attached_file( $found['attachments'][0] ) );
			} elseif ( ! empty( $found['url'] ) ) {
				$file = basename( (string) wp_parse_url( $found['url'], PHP_URL_PATH ) );
			}

			$rows[] = array(
				'id'     => (int) $id,
				'title'  => $post && '' !== $post->post_title ? $post->post_title : sprintf( '#%d', $id ),
				'status' => $post ? $post->post_status : '',
				'date'   => $post ? mysql2date( get_option( 'date_format' ), $post->post_date ) : '',
				'hasPdf' => ! empty( $found['attachments'] ) || ! empty( $found['url'] ),
				'file'   => $file,
				'pages'  => (int) $found['pages'],
				'terms'  => array_values( array_unique( $names ) ),
				'edit'   => (string) get_edit_post_link( $id, '' ),
			);
		}

		return $rows;
	}

	/**
	 * Hand the screen one page of records to choose from.
	 */
	public function handle_preview() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to migrate documents.', 'wp-pdf-reader' ) ), 403 );
		}

		$post_type = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';

		if ( '' === $post_type || in_array( $post_type, WPPDF_Post_Type::get_supported_post_types(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown source.', 'wp-pdf-reader' ) ), 400 );
		}

		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$rows   = self::preview( $post_type, self::PREVIEW, $offset );

		wp_send_json_success(
			array(
				'rows'   => $rows,
				'offset' => $offset + count( $rows ),
				'total'  => self::count_pending( $post_type ),
				'done'   => count( $rows ) < self::PREVIEW,
			)
		);
	}

	/**
	 * Forget which records were skipped, so a new run retries them.
	 *
	 * @param string $post_type Source post type.
	 */
	public static function clear_skipped( $post_type ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one joined DELETE instead of walking every flagged record through delete_post_meta().
		$wpdb->query(
			$wpdb->prepare(
				"DELETE m FROM {$wpdb->postmeta} AS m
				INNER JOIN {$wpdb->posts} AS p ON p.ID = m.post_id
				WHERE m.meta_key = %s AND p.post_type = %s",
				self::META_SKIPPED,
				$post_type
			)
		);
	}

	/**
	 * Find the PDFs and extras a source record holds.
	 *
	 * @param int    $post_id   Source record ID.
	 * @param string $post_type Source post type.
	 * @return array {
	 *     @type int[]  $attachments PDF attachment IDs, in order.
	 *     @type string $url         External PDF URL when no attachment was found.
	 *     @type int    $pages       Page count, 0 when unknown.
	 *     @type string $text        Extracted text, empty when unknown.
	 * }
	 */
	public static function describe( $post_id, $post_type ) {
		$adapters = self::get_adapters();
		$adapter  = isset( $adapters[ $post_type ] ) ? $adapters[ $post_type ] : null;

		$found = array(
			'attachments' => array(),
			'url'         => '',
			'pages'       => 0,
			'text'        => '',
		);

		if ( $adapter ) {
			foreach ( (array) $adapter['file'] as $key ) {
				$id = absint( get_post_meta( $post_id, $key, true ) );

				if ( $id && WPPDF_Documents::is_valid_attachment( $id ) ) {
					$found['attachments'][] = $id;
				}
			}

			foreach ( (array) $adapter['file_list'] as $key ) {
				foreach ( (array) get_post_meta( $post_id, $key, true ) as $item ) {
					$id = is_array( $item ) ? absint( isset( $item['id'] ) ? $item['id'] : 0 ) : absint( $item );

					if ( $id && WPPDF_Documents::is_valid_attachment( $id ) ) {
						$found['attachments'][] = $id;
					}
				}
			}

			if ( ! empty( $adapter['pages'] ) ) {
				$found['pages'] = absint( get_post_meta( $post_id, $adapter['pages'], true ) );
			}

			if ( ! empty( $adapter['text'] ) ) {
				$text = get_post_meta( $post_id, $adapter['text'], true );

				if ( is_string( $text ) ) {
					$found['text'] = $text;
				}
			}
		}

		if ( empty( $found['attachments'] ) ) {
			$generic = self::find_pdfs_in_meta( $post_id );

			$found['attachments'] = $generic['attachments'];
			$found['url']         = $generic['url'];
		}

		$found['attachments'] = array_values( array_unique( $found['attachments'] ) );

		/**
		 * Filter what an import found in a source record.
		 *
		 * @param array  $found     Discovered data.
		 * @param int    $post_id   Source record ID.
		 * @param string $post_type Source post type.
		 */
		return apply_filters( 'wppdf_import_describe', $found, $post_id, $post_type );
	}

	/**
	 * Look through every meta value of a record for something PDF shaped.
	 *
	 * @param int $post_id Source record ID.
	 * @return array Attachments and a URL fallback.
	 */
	protected static function find_pdfs_in_meta( $post_id ) {
		$attachments = array();
		$url         = '';

		foreach ( (array) get_post_meta( $post_id ) as $values ) {
			foreach ( (array) $values as $raw ) {
				$value = maybe_unserialize( $raw );

				foreach ( self::flatten( $value ) as $candidate ) {
					if ( is_numeric( $candidate ) ) {
						$id = absint( $candidate );

						if ( $id && WPPDF_Documents::is_valid_attachment( $id ) ) {
							$attachments[] = $id;
						}

						continue;
					}

					if ( ! is_string( $candidate ) || false === stripos( $candidate, '.pdf' ) ) {
						continue;
					}

					$path = wp_parse_url( $candidate, PHP_URL_PATH );

					if ( ! $path || '.pdf' !== strtolower( substr( $path, -4 ) ) ) {
						continue;
					}

					$id = function_exists( 'attachment_url_to_postid' ) ? attachment_url_to_postid( $candidate ) : 0;

					if ( $id && WPPDF_Documents::is_valid_attachment( $id ) ) {
						$attachments[] = $id;
					} elseif ( '' === $url && preg_match( '#^https?://#i', $candidate ) ) {
						$url = esc_url_raw( $candidate );
					}
				}
			}
		}

		return array(
			'attachments' => $attachments,
			'url'         => $url,
		);
	}

	/**
	 * Flatten a meta value into scalars, bounded so a huge blob cannot stall.
	 *
	 * @param mixed $value Meta value.
	 * @param int   $depth Current depth.
	 * @return array
	 */
	protected static function flatten( $value, $depth = 0 ) {
		if ( is_scalar( $value ) ) {
			return array( $value );
		}

		if ( ! is_array( $value ) || $depth > 3 ) {
			return array();
		}

		$out = array();

		foreach ( $value as $item ) {
			foreach ( self::flatten( $item, $depth + 1 ) as $scalar ) {
				$out[] = $scalar;

				if ( count( $out ) > 200 ) {
					return $out;
				}
			}
		}

		return $out;
	}

	/**
	 * Import one source record into a document.
	 *
	 * @param int   $source_id Source record ID.
	 * @param array $args      Import arguments.
	 * @return array|WP_Error Result with the new ID and notes.
	 */
	public static function import( $source_id, array $args ) {
		$source = get_post( $source_id );

		if ( ! $source ) {
			return new WP_Error( 'wppdf_missing_source', __( 'The record no longer exists.', 'wp-pdf-reader' ) );
		}

		$found = self::describe( $source_id, $source->post_type );

		if ( empty( $found['attachments'] ) && '' === $found['url'] ) {
			return new WP_Error( 'wppdf_no_pdf', __( 'No PDF found in this record.', 'wp-pdf-reader' ) );
		}

		$code   = $args['language'];
		$status = 'source' === $args['status'] ? $source->post_status : $args['status'];

		if ( 'publish' === $status && ! current_user_can( 'publish_posts' ) ) {
			$status = 'draft';
		}

		$post_id = wp_insert_post(
			array(
				'post_type'     => WPPDF_Post_Type::get_key(),
				'post_status'   => $status,
				'post_title'    => $source->post_title,
				// Keeping the slug is what lets the old address survive.
				'post_name'     => $source->post_name,
				'post_content'  => $source->post_content,
				'post_excerpt'  => $source->post_excerpt,
				'post_date'     => $source->post_date,
				'post_date_gmt' => $source->post_date_gmt,
				'post_author'   => $source->post_author,
				'menu_order'    => $source->menu_order,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$notes = array();

		// The first PDF becomes the chosen language; the rest are reported so
		// they can be placed by hand rather than guessed at.
		if ( ! empty( $found['attachments'] ) ) {
			update_post_meta( $post_id, WPPDF_Languages::file_meta_key( $code ), $found['attachments'][0] );

			if ( count( $found['attachments'] ) > 1 ) {
				$notes[] = sprintf(
					/* translators: %d: number of further PDFs. */
					_n( '%d further PDF was left unassigned', '%d further PDFs were left unassigned', count( $found['attachments'] ) - 1, 'wp-pdf-reader' ),
					count( $found['attachments'] ) - 1
				);
			}
		} elseif ( '' !== $found['url'] ) {
			update_post_meta( $post_id, WPPDF_Documents::url_meta_key( $code ), $found['url'] );
			$notes[] = __( 'linked as an external URL', 'wp-pdf-reader' );
		}

		update_post_meta( $post_id, self::META_SOURCE_ID, (int) $source_id );
		update_post_meta( $post_id, self::META_SOURCE_TYPE, $source->post_type );

		if ( '' !== $source->post_name ) {
			update_post_meta( $post_id, self::META_SLUG, $source->post_name );
		}

		// Captured while the other plugin is still registered, because once it
		// is switched off its permalink can no longer be built.
		$old_path = self::get_source_path( $source_id );

		if ( '' !== $old_path ) {
			update_post_meta( $post_id, self::META_PATH, $old_path );
		}

		// The 404 handler skips its lookup entirely until this is set.
		delete_transient( 'wppdf_has_imported' );
		wp_cache_delete( 'has_imported', 'wppdf' );

		self::copy_terms( $source_id, $post_id );

		$thumbnail = get_post_thumbnail_id( $source_id );

		if ( $thumbnail ) {
			set_post_thumbnail( $post_id, $thumbnail );
		}

		// Reusing what the other plugin already extracted saves a full pass.
		if ( $found['pages'] > 0 ) {
			update_post_meta( $post_id, WPPDF_Text::pages_meta_key( $code ), $found['pages'] );
		}

		$reused = false;

		if ( '' !== $found['text'] && WPPDF_Settings::get( 'extract_text' ) ) {
			$text = self::clean_text( $found['text'] );

			if ( '' !== $text ) {
				update_post_meta( $post_id, WPPDF_Text::text_meta_key( $code ), $text );
				$notes[] = __( 'text index taken over', 'wp-pdf-reader' );
				$reused  = true;
			}
		}

		if ( ! empty( $found['attachments'] ) ) {
			WPPDF_Cover::schedule( $post_id, $code, $found['attachments'][0] );

			if ( ! $reused ) {
				WPPDF_Text::schedule( $post_id, $code, $found['attachments'][0] );
			}
		}

		/**
		 * Fires after a record was imported from another plugin.
		 *
		 * @param int   $post_id   New document ID.
		 * @param int   $source_id Source record ID.
		 * @param array $found     Discovered data.
		 */
		do_action( 'wppdf_record_imported', $post_id, $source_id, $found );

		return array(
			'id'    => (int) $post_id,
			'title' => get_the_title( $post_id ),
			'edit'  => get_edit_post_link( $post_id, 'raw' ),
			'notes' => $notes,
		);
	}

	/**
	 * The path a source record answers on, while its plugin is still active.
	 *
	 * @param int $source_id Source record ID.
	 * @return string Path with leading and trailing slash, empty when unknown.
	 */
	public static function get_source_path( $source_id ) {
		$permalink = get_permalink( $source_id );

		if ( ! $permalink ) {
			return '';
		}

		$path = wp_parse_url( $permalink, PHP_URL_PATH );

		if ( ! $path || '/' === $path ) {
			return '';
		}

		return user_trailingslashit( $path );
	}

	/**
	 * Copy the categories and tags a source record carries.
	 *
	 * @param int $source_id Source record ID.
	 * @param int $post_id   New document ID.
	 */
	protected static function copy_terms( $source_id, $post_id ) {
		$target = self::get_target_taxonomy();

		foreach ( self::get_source_terms( $source_id ) as $taxonomy => $terms ) {
			// A taxonomy our documents already use needs no translation.
			if ( is_object_in_taxonomy( get_post_type( $post_id ), $taxonomy ) ) {
				wp_set_object_terms( $post_id, wp_list_pluck( $terms, 'term_id' ), $taxonomy, true );
				continue;
			}

			if ( '' === $target ) {
				continue;
			}

			$mapped = array();

			foreach ( $terms as $term ) {
				$id = self::map_term( $term, $target );

				if ( $id ) {
					$mapped[] = $id;
				}
			}

			if ( ! empty( $mapped ) ) {
				wp_set_object_terms( $post_id, $mapped, $target, true );
			}
		}
	}

	/**
	 * The taxonomy foreign categories are mapped into.
	 *
	 * @return string Taxonomy name, empty when documents have none.
	 */
	public static function get_target_taxonomy() {
		$target = '';

		if ( WPPDF_Settings::get( 'shared_taxonomies' ) ) {
			$target = 'category';
		} elseif ( WPPDF_Settings::get( 'own_taxonomy' ) ) {
			$target = WPPDF_Post_Type::OWN_TAXONOMY;
		}

		/**
		 * Filter the taxonomy imported categories are mapped into.
		 *
		 * @param string $target Taxonomy name.
		 */
		return (string) apply_filters( 'wppdf_import_target_taxonomy', $target );
	}

	/**
	 * Every term a source record carries, whatever taxonomy it lives in.
	 *
	 * Read straight from the tables rather than through wp_get_object_terms,
	 * because the usual reason to migrate is that the other plugin has been
	 * switched off — and then its taxonomy is no longer registered, even
	 * though the rows are still there.
	 *
	 * @param int $source_id Source record ID.
	 * @return array Map of taxonomy => list of term rows.
	 */
	public static function get_source_terms( $source_id ) {
		$all = self::get_source_terms_batch( array( $source_id ) );

		return isset( $all[ (int) $source_id ] ) ? $all[ (int) $source_id ] : array();
	}

	/**
	 * The same, for many records at once.
	 *
	 * The preview lists the categories of every record on the screen, which
	 * one query per record would make quadratic in all but name.
	 *
	 * @param int[] $source_ids Source record IDs.
	 * @return array Grouped terms keyed by record ID; records with none are absent.
	 */
	public static function get_source_terms_batch( array $source_ids ) {
		global $wpdb;

		$source_ids = array_values( array_filter( array_unique( array_map( 'absint', $source_ids ) ) ) );

		if ( empty( $source_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $source_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $placeholders is a list of %d built from a count and filled by prepare(); the source taxonomy is usually unregistered by now, so the term APIs return nothing and the relationships have to be read directly.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tr.object_id, t.term_id, t.name, t.slug, tt.taxonomy, tt.parent, tt.description
				FROM {$wpdb->term_relationships} AS tr
				INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->terms} AS t ON t.term_id = tt.term_id
				WHERE tr.object_id IN ( {$placeholders} )
				ORDER BY tt.parent ASC, t.name ASC",
				$source_ids
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$grouped = array();

		foreach ( (array) $rows as $row ) {
			$taxonomy = (string) $row->taxonomy;

			// Formats and similar internal taxonomies are not categories.
			if ( in_array( $taxonomy, array( 'post_format', 'link_category', 'nav_menu' ), true ) ) {
				continue;
			}

			$grouped[ (int) $row->object_id ][ $taxonomy ][] = array(
				'term_id'     => (int) $row->term_id,
				'name'        => (string) $row->name,
				'slug'        => (string) $row->slug,
				'parent'      => (int) $row->parent,
				'description' => (string) $row->description,
			);
		}

		return $grouped;
	}

	/**
	 * Find or create the counterpart of a foreign term.
	 *
	 * Matching is by slug first and name second, so running the import twice
	 * reuses the same terms instead of making near duplicates.
	 *
	 * @param array  $term   Source term row.
	 * @param string $target Target taxonomy.
	 * @return int Term ID, 0 on failure.
	 */
	protected static function map_term( array $term, $target ) {
		$existing = get_term_by( 'slug', $term['slug'], $target );

		if ( ! $existing ) {
			$existing = get_term_by( 'name', $term['name'], $target );
		}

		if ( $existing && ! is_wp_error( $existing ) ) {
			return (int) $existing->term_id;
		}

		$args = array( 'slug' => $term['slug'] );

		if ( $term['parent'] > 0 && is_taxonomy_hierarchical( $target ) ) {
			$parent = self::find_parent( $term['parent'], $target );

			if ( $parent ) {
				$args['parent'] = $parent;
			}
		}

		$created = wp_insert_term( $term['name'], $target, $args );

		if ( is_wp_error( $created ) ) {
			// A slug clash across taxonomies is the usual cause; retry without.
			$created = wp_insert_term( $term['name'], $target );
		}

		return is_wp_error( $created ) ? 0 : (int) $created['term_id'];
	}

	/**
	 * Resolve a foreign parent term to its counterpart, creating it if needed.
	 *
	 * @param int    $parent_id Parent term ID in the source taxonomy.
	 * @param string $target    Target taxonomy.
	 * @return int Term ID, 0 when it could not be resolved.
	 */
	protected static function find_parent( $parent_id, $target ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- same reason: get_term() needs a registered taxonomy, and the source one is gone.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT t.term_id, t.name, t.slug, tt.parent
				FROM {$wpdb->terms} AS t
				INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = t.term_id
				WHERE t.term_id = %d
				LIMIT 1",
				$parent_id
			)
		);

		if ( ! $row ) {
			return 0;
		}

		return self::map_term(
			array(
				'term_id'     => (int) $row->term_id,
				'name'        => (string) $row->name,
				'slug'        => (string) $row->slug,
				// One level of nesting is resolved; deeper chains flatten.
				'parent'      => 0,
				'description' => '',
			),
			$target
		);
	}

	/**
	 * Normalise text taken from another plugin before indexing it.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	protected static function clean_text( $text ) {
		$text = preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) );
		$text = trim( (string) $text );

		if ( strlen( $text ) > WPPDF_Text::MAX_CHARS ) {
			$text = substr( $text, 0, WPPDF_Text::MAX_CHARS );
			$text = (string) preg_replace( '/[\x80-\xBF]+$/', '', $text );
		}

		return $text;
	}

	/**
	 * Process one batch from the import screen.
	 */
	public function handle_ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );

		// Migration copies whatever a source post type holds, drafts and
		// private records included, into documents that may be published. That
		// is more than an editor's own content, so it takes the same capability
		// as the screen it is driven from rather than edit_posts.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to migrate documents.', 'wp-pdf-reader' ) ), 403 );
		}

		$post_type = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';

		if ( '' === $post_type || in_array( $post_type, WPPDF_Post_Type::get_supported_post_types(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown source.', 'wp-pdf-reader' ) ), 400 );
		}

		$code = isset( $_POST['lang'] ) ? wppdf_sanitize_language_code( wp_unslash( $_POST['lang'] ) ) : '';

		if ( '' === $code || ! in_array( $code, WPPDF_Languages::get_codes(), true ) ) {
			$code = WPPDF_Languages::get_default_language();
		}

		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft';

		if ( ! in_array( $status, array( 'draft', 'publish', 'source' ), true ) ) {
			$status = 'draft';
		}

		if ( ! empty( $_POST['reset'] ) ) {
			self::clear_skipped( $post_type );
		}

		// The screen may name the records to import rather than take the next
		// batch. filter_pending() decides which of them may actually be
		// imported, so a hand-edited request cannot reach another post type.
		if ( isset( $_POST['ids'] ) ) {
			$chosen = self::filter_pending( $post_type, (array) wp_unslash( $_POST['ids'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() is applied to every element inside filter_pending().

			if ( empty( $chosen ) ) {
				wp_send_json_error( array( 'message' => __( 'None of the selected records can be imported.', 'wp-pdf-reader' ) ), 400 );
			}

			$ids = array_slice( $chosen, 0, self::BATCH );
		} else {
			$ids = self::get_pending_ids( $post_type, self::BATCH );
		}

		$requested = count( $ids );
		$imported  = array();
		$skipped   = array();

		foreach ( $ids as $source_id ) {
			$result = self::import(
				$source_id,
				array(
					'language' => $code,
					'status'   => $status,
				)
			);

			if ( is_wp_error( $result ) ) {
				// Remember the miss so the batch does not hand it back forever.
				update_post_meta( $source_id, self::META_SKIPPED, $result->get_error_code() );

				$skipped[] = array(
					'id'     => $source_id,
					'title'  => get_the_title( $source_id ),
					'reason' => $result->get_error_message(),
				);

				continue;
			}

			$imported[] = $result;
		}

		wp_send_json_success(
			array(
				'imported'  => $imported,
				'skipped'   => $skipped,
				// With a hand-picked list the browser knows what is left of its
				// own selection, so it decides when to stop; only the run that
				// walks the whole source ends when a batch comes back short.
				'done'      => $requested < self::BATCH,
				'processed' => $requested,
				'left'      => self::count_pending( $post_type ),
			)
		);
	}
}

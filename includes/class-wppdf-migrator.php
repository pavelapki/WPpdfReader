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
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'wp_ajax_wppdf_migrate', array( $this, 'handle_ajax' ) );
	}

	/**
	 * Adapters for plugins whose storage is known.
	 *
	 * @return array Map of post type => adapter definition.
	 */
	public static function get_adapters() {
		$adapters = array(
			'tnc_flipbook' => array(
				'label'      => 'TNC FlipBook 3D',
				'file'       => array( '_tncfb3d_pdf_id' ),
				'file_list'  => array( '_tncfb3d_pdf_ids' ),
				'pages'      => '_tncfb3d_text_page_count',
				'text'       => '_tncfb3d_extracted_text',
				'note'       => __( 'Image based flipbooks hold no PDF and are reported as skipped.', 'wp-pdf-reader' ),
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

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above and passed to prepare.
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

		$adapters = self::get_adapters();
		$sources  = array();

		foreach ( (array) $rows as $row ) {
			$type   = (string) $row->post_type;
			$object = get_post_type_object( $type );

			$sources[] = array(
				'type'     => $type,
				'label'    => $object && ! empty( $object->labels->name ) ? $object->labels->name : $type,
				'count'    => (int) $row->total,
				'adapter'  => isset( $adapters[ $type ] ) ? $adapters[ $type ]['label'] : '',
				'imported' => self::count_imported( $type ),
				'active'   => (bool) $object,
			);
		}

		return $sources;
	}

	/**
	 * How many records of a source were already imported.
	 *
	 * @param string $post_type Source post type.
	 * @return int
	 */
	public static function count_imported( $post_type ) {
		global $wpdb;

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
	 * @return int[]
	 */
	public static function get_pending_ids( $post_type, $limit = 0 ) {
		return self::query_pending( $post_type, $limit, false );
	}

	/**
	 * How many records of a source are still waiting.
	 *
	 * @param string $post_type Source post type.
	 * @return int
	 */
	public static function count_pending( $post_type ) {
		return (int) self::query_pending( $post_type, 0, true );
	}

	/**
	 * Shared query behind the pending list and its count.
	 *
	 * A record that held no PDF is flagged on the source, otherwise the same
	 * ten records would be handed back on every batch and the run would never
	 * finish. Starting a fresh run clears those flags again.
	 *
	 * @param string $post_type  Source post type.
	 * @param int    $limit      Maximum IDs.
	 * @param bool   $count_only Return the number of matches.
	 * @return int[]|int
	 */
	protected static function query_pending( $post_type, $limit, $count_only ) {
		global $wpdb;

		$select = $count_only ? 'COUNT(*)' : 'p.ID';

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

		if ( $count_only ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above and passed to prepare.
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		}

		$sql .= ' ORDER BY p.ID ASC';

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d';
			$params[] = (int) $limit;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above and passed to prepare.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Forget which records were skipped, so a new run retries them.
	 *
	 * @param string $post_type Source post type.
	 */
	public static function clear_skipped( $post_type ) {
		global $wpdb;

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
	 * Copy the categories and tags a source record carries.
	 *
	 * @param int $source_id Source record ID.
	 * @param int $post_id   New document ID.
	 */
	protected static function copy_terms( $source_id, $post_id ) {
		if ( ! WPPDF_Settings::get( 'shared_taxonomies' ) ) {
			return;
		}

		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$terms = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'ids' ) );

			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				wp_set_object_terms( $post_id, $terms, $taxonomy );
			}
		}
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

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to create documents.', 'wp-pdf-reader' ) ), 403 );
		}

		$post_type = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';

		if ( '' === $post_type || in_array( $post_type, WPPDF_Post_Type::get_supported_post_types(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown source.', 'wp-pdf-reader' ) ), 400 );
		}

		$code = isset( $_POST['lang'] ) ? WPPDF_Settings::sanitize_language_code( wp_unslash( $_POST['lang'] ) ) : '';

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

		$ids       = self::get_pending_ids( $post_type, self::BATCH );
		$imported  = array();
		$skipped   = array();

		foreach ( $ids as $source_id ) {
			$result = self::import( $source_id, array( 'language' => $code, 'status' => $status ) );

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
				'imported' => $imported,
				'skipped'  => $skipped,
				'done'     => count( $ids ) < self::BATCH,
				'left'     => self::count_pending( $post_type ),
			)
		);
	}
}

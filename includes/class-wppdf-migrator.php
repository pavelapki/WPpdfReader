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
				'slug'     => self::get_rewrite_slug( $type ),
			);
		}

		return $sources;
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
				$id = self::map_term( $term, $target, $source_id );

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
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.parent, tt.description
				FROM {$wpdb->term_relationships} AS tr
				INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->terms} AS t ON t.term_id = tt.term_id
				WHERE tr.object_id = %d
				ORDER BY tt.parent ASC, t.name ASC",
				$source_id
			)
		);

		$grouped = array();

		foreach ( (array) $rows as $row ) {
			$taxonomy = (string) $row->taxonomy;

			// Formats and similar internal taxonomies are not categories.
			if ( in_array( $taxonomy, array( 'post_format', 'link_category', 'nav_menu' ), true ) ) {
				continue;
			}

			$grouped[ $taxonomy ][] = array(
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
	 * @param array  $term      Source term row.
	 * @param string $target    Target taxonomy.
	 * @param int    $source_id Source record ID, for resolving parents.
	 * @return int Term ID, 0 on failure.
	 */
	protected static function map_term( array $term, $target, $source_id ) {
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
			$target,
			0
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

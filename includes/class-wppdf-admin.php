<?php
/**
 * Admin screens: settings page, list table columns and filters.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin integration.
 */
class WPPDF_Admin {

	/**
	 * Settings page slug.
	 */
	const PAGE = 'wppdf-settings';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . WPPDF_BASENAME, array( $this, 'action_links' ) );

		add_action( 'admin_init', array( $this, 'register_list_table_hooks' ) );
		add_action( 'restrict_manage_posts', array( $this, 'render_language_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'apply_language_filter' ) );
	}

	/**
	 * Hook the list table columns for the configured post type.
	 */
	public function register_list_table_hooks() {
		foreach ( WPPDF_Post_Type::get_supported_post_types() as $post_type ) {
			add_filter( "manage_edit-{$post_type}_columns", array( $this, 'columns' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'column_content' ), 10, 2 );
		}
	}

	/**
	 * Add the settings page under the document menu.
	 */
	public function add_menu() {
		add_submenu_page(
			'edit.php?post_type=' . WPPDF_Post_Type::get_key(),
			__( 'PDF Reader settings', 'wp-pdf-reader' ),
			__( 'Settings', 'wp-pdf-reader' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = add_query_arg(
			array(
				'post_type' => WPPDF_Post_Type::get_key(),
				'page'      => self::PAGE,
			),
			admin_url( 'edit.php' )
		);

		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wp-pdf-reader' ) . '</a>' );

		return $links;
	}

	/**
	 * Enqueue admin assets where they are needed.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue( $hook ) {
		$screen     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_types = WPPDF_Post_Type::get_supported_post_types();

		$is_editor   = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && $screen && in_array( $screen->post_type, $post_types, true );
		$is_settings = $screen && false !== strpos( (string) $screen->id, self::PAGE );
		$is_import   = $screen && false !== strpos( (string) $screen->id, WPPDF_Importer::PAGE );
		$is_list     = 'edit.php' === $hook && $screen && in_array( $screen->post_type, $post_types, true );

		if ( ! $is_editor && ! $is_settings && ! $is_list && ! $is_import ) {
			return;
		}

		wp_enqueue_style( 'wppdf-admin', WPPDF_URL . 'assets/css/admin.css', array(), WPPDF_VERSION );

		if ( $is_import ) {
			wp_enqueue_media();
			wp_enqueue_script( 'wppdf-import', WPPDF_URL . 'assets/js/import.js', array( 'jquery' ), WPPDF_VERSION, true );

			wp_localize_script(
				'wppdf-import',
				'wppdfImport',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( WPPDF_Importer::NONCE ),
					'batch'   => WPPDF_Importer::BATCH,
					'i18n'    => array(
						'selectTitle'  => __( 'Select the PDFs to import', 'wp-pdf-reader' ),
						'selectButton' => __( 'Import these PDFs', 'wp-pdf-reader' ),
						'created'      => __( 'created', 'wp-pdf-reader' ),
						'failed'       => __( 'The import request failed.', 'wp-pdf-reader' ),
						/* translators: %d: number of files. */
						'finished'     => __( 'Finished, %d files processed.', 'wp-pdf-reader' ),
						/* translators: 1: number imported, 2: number left unimported. */
						'migrated'     => __( 'Done: %1$d imported, %2$d left.', 'wp-pdf-reader' ),
						/* translators: %s: old URL prefix. */
						'oldPrefix'    => __( 'The records answer under %s.', 'wp-pdf-reader' ),
						/* translators: %s: URL prefix. */
						'adopt'        => __( 'Take over %s', 'wp-pdf-reader' ),
						'slugUnknown'  => __( 'Their URL prefix cannot be read, because the plugin is no longer active and it was not seen before.', 'wp-pdf-reader' ),
						'stillActive'  => __( 'The other plugin is still active — deactivate it first, otherwise both claim these addresses.', 'wp-pdf-reader' ),
					),
				)
			);

			return;
		}

		if ( $is_editor || $is_settings ) {
			if ( $is_editor ) {
				wp_enqueue_media();
			}

			wp_enqueue_script( 'wppdf-admin', WPPDF_URL . 'assets/js/admin.js', array( 'jquery' ), WPPDF_VERSION, true );

			wp_localize_script(
				'wppdf-admin',
				'wppdfAdmin',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'i18n' => array(
						/* translators: 1: number of documents indexed, 2: number still pending. */
						'reindexed'    => __( '%1$d indexed, %2$d left.', 'wp-pdf-reader' ),
						'reindexDone'  => __( 'Everything is indexed.', 'wp-pdf-reader' ),
						'reindexError' => __( 'The request failed, the remaining documents were not indexed.', 'wp-pdf-reader' ),
						'selectTitle'  => __( 'Select a PDF file', 'wp-pdf-reader' ),
						'selectButton' => __( 'Use this PDF', 'wp-pdf-reader' ),
						'noFile'       => __( 'No file for this language.', 'wp-pdf-reader' ),
						'removeRow'    => __( 'Remove language', 'wp-pdf-reader' ),
						'confirmRow'   => __( 'Remove this language? Files already uploaded for it stay in the media library.', 'wp-pdf-reader' ),
						'code'         => __( 'Code', 'wp-pdf-reader' ),
						'label'        => __( 'Label', 'wp-pdf-reader' ),
					),
				)
			);
		}
	}

	/**
	 * List table columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$new['wppdf_cover'] = __( 'Cover', 'wp-pdf-reader' );
			}

			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['wppdf_files'] = __( 'PDF files', 'wp-pdf-reader' );

				if ( WPPDF_Settings::get( 'count_views' ) ) {
					$new['wppdf_stats'] = __( 'Views', 'wp-pdf-reader' );
				}
			}
		}

		return $new;
	}

	/**
	 * List table column content.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function column_content( $column, $post_id ) {
		if ( 'wppdf_cover' === $column ) {
			$cover_id = WPPDF_Documents::get_cover_id( $post_id );

			if ( $cover_id ) {
				echo wp_kses_post( wp_get_attachment_image( $cover_id, array( 40, 55 ), false, array( 'class' => 'wppdf-column-cover' ) ) );
			} else {
				echo '<span class="wppdf-column-cover wppdf-column-cover--empty dashicons dashicons-media-document"></span>';
			}

			return;
		}

		if ( 'wppdf_stats' === $column ) {
			$views     = WPPDF_Stats::get( $post_id, 'view' );
			$downloads = WPPDF_Stats::get( $post_id, 'download' );

			if ( ! $views && ! $downloads ) {
				echo '<span class="wppdf-muted">—</span>';

				return;
			}

			printf(
				/* translators: 1: number of views, 2: number of downloads. */
				esc_html__( '%1$d views, %2$d downloads', 'wp-pdf-reader' ),
				(int) $views,
				(int) $downloads
			);

			$breakdown = WPPDF_Stats::get_breakdown( $post_id );

			if ( count( $breakdown ) > 1 ) {
				$parts = array();

				foreach ( $breakdown as $code => $numbers ) {
					$parts[] = strtoupper( $code ) . ' ' . (int) $numbers['views'];
				}

				echo '<br /><span class="wppdf-muted">' . esc_html( implode( ' · ', $parts ) ) . '</span>';
			}

			return;
		}

		if ( 'wppdf_files' !== $column ) {
			return;
		}

		$available = WPPDF_Documents::get_available_languages( $post_id );

		echo '<span class="wppdf-lang-list">';

		foreach ( WPPDF_Languages::get_languages() as $code => $language ) {
			$has = in_array( $code, $available, true );

			printf(
				'<span class="wppdf-lang-badge %1$s" title="%2$s">%3$s</span>',
				$has ? 'is-available' : 'is-missing',
				esc_attr( $language['label'] ),
				esc_html( strtoupper( $code ) )
			);
		}

		echo '</span>';

		if ( empty( $available ) ) {
			echo '<br /><span class="wppdf-muted">' . esc_html__( 'No file', 'wp-pdf-reader' ) . '</span>';

			return;
		}

		$file  = WPPDF_Documents::get_file( $post_id, WPPDF_Languages::get_default_language() );
		$notes = array();

		if ( $file && $file['is_fallback'] ) {
			$notes[] = sprintf(
				/* translators: %s: language label. */
				__( 'Default language falls back to %s', 'wp-pdf-reader' ),
				$file['language_label']
			);
		}

		$pages = $file ? WPPDF_Text::get_page_count( $post_id, $file['lang'] ) : 0;

		if ( $pages > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of pages. */
				_n( '%d page', '%d pages', $pages, 'wp-pdf-reader' ),
				$pages
			);
		}

		if ( $notes ) {
			echo '<br /><span class="wppdf-muted">' . esc_html( implode( ' · ', $notes ) ) . '</span>';
		}
	}

	/**
	 * Dropdown to filter documents by language availability.
	 *
	 * @param string $post_type Current post type.
	 */
	public function render_language_filter( $post_type ) {
		if ( ! in_array( $post_type, WPPDF_Post_Type::get_supported_post_types(), true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list table filter.
		$current = isset( $_GET['wppdf_lang_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['wppdf_lang_filter'] ) ) : '';

		echo '<select name="wppdf_lang_filter">';
		echo '<option value="">' . esc_html__( 'All languages', 'wp-pdf-reader' ) . '</option>';

		foreach ( WPPDF_Languages::get_languages() as $code => $language ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( 'has:' . $code ),
				selected( $current, 'has:' . $code, false ),
				/* translators: %s: language label. */
				esc_html( sprintf( __( 'Has %s file', 'wp-pdf-reader' ), $language['label'] ) )
			);
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( 'missing:' . $code ),
				selected( $current, 'missing:' . $code, false ),
				/* translators: %s: language label. */
				esc_html( sprintf( __( 'Missing %s file', 'wp-pdf-reader' ), $language['label'] ) )
			);
		}

		echo '</select>';
	}

	/**
	 * Apply the language availability filter to the list table query.
	 *
	 * @param WP_Query $query Query instance.
	 */
	public function apply_language_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list table filter.
		if ( empty( $_GET['wppdf_lang_filter'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list table filter.
		$filter = sanitize_text_field( wp_unslash( $_GET['wppdf_lang_filter'] ) );
		$parts  = explode( ':', $filter, 2 );

		if ( 2 !== count( $parts ) ) {
			return;
		}

		list( $mode, $code ) = $parts;
		$code                = WPPDF_Settings::sanitize_language_code( $code );

		if ( '' === $code || ! in_array( $code, WPPDF_Languages::get_codes(), true ) ) {
			return;
		}

		$file_key = WPPDF_Languages::file_meta_key( $code );
		$url_key  = WPPDF_Documents::url_meta_key( $code );

		if ( 'has' === $mode ) {
			$meta_query = array(
				'relation' => 'OR',
				array(
					'key'     => $file_key,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => $url_key,
					'value'   => '',
					'compare' => '!=',
				),
			);
		} elseif ( 'missing' === $mode ) {
			$meta_query = array(
				'relation' => 'AND',
				array(
					'key'     => $file_key,
					'compare' => 'NOT EXISTS',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => $url_key,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => $url_key,
						'value'   => '',
						'compare' => '=',
					),
				),
			);
		} else {
			return;
		}

		$existing = $query->get( 'meta_query' );
		if ( ! empty( $existing ) && is_array( $existing ) ) {
			$meta_query = array(
				'relation' => 'AND',
				$existing,
				$meta_query,
			);
		}

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Render the settings screen.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings  = WPPDF_Settings::all();
		$languages = WPPDF_Languages::get_languages();
		$option    = WPPDF_Settings::OPTION;
		?>
		<div class="wrap wppdf-settings">
			<h1><?php esc_html_e( 'PDF Reader settings', 'wp-pdf-reader' ); ?></h1>

			<?php settings_errors( $option ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'wppdf_settings_group' ); ?>

				<h2 class="title"><?php esc_html_e( 'Document type', 'wp-pdf-reader' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wppdf-post-type-key"><?php esc_html_e( 'Post type key', 'wp-pdf-reader' ); ?></label></th>
						<td>
							<input type="text" id="wppdf-post-type-key" class="regular-text" name="<?php echo esc_attr( $option ); ?>[post_type_key]" value="<?php echo esc_attr( $settings['post_type_key'] ); ?>" maxlength="20" />
							<p class="description"><?php esc_html_e( 'Internal key, lowercase, max 20 characters. Existing documents are moved automatically when you change it.', 'wp-pdf-reader' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wppdf-post-type-slug"><?php esc_html_e( 'URL slug', 'wp-pdf-reader' ); ?></label></th>
						<td>
							<input type="text" id="wppdf-post-type-slug" class="regular-text" name="<?php echo esc_attr( $option ); ?>[post_type_slug]" value="<?php echo esc_attr( $settings['post_type_slug'] ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: example permalink. */
									esc_html__( 'Documents will live at %s.', 'wp-pdf-reader' ),
									'<code>' . esc_html( home_url( '/' . $settings['post_type_slug'] . '/example/' ) ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wppdf-label-singular"><?php esc_html_e( 'Labels', 'wp-pdf-reader' ); ?></label></th>
						<td>
							<input type="text" id="wppdf-label-singular" name="<?php echo esc_attr( $option ); ?>[label_singular]" value="<?php echo esc_attr( $settings['label_singular'] ); ?>" placeholder="<?php esc_attr_e( 'Singular', 'wp-pdf-reader' ); ?>" />
							<input type="text" name="<?php echo esc_attr( $option ); ?>[label_plural]" value="<?php echo esc_attr( $settings['label_plural'] ); ?>" placeholder="<?php esc_attr_e( 'Plural', 'wp-pdf-reader' ); ?>" />
							<p class="description"><?php esc_html_e( 'Shown in the admin menu and on archive pages.', 'wp-pdf-reader' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wppdf-menu-icon"><?php esc_html_e( 'Menu icon', 'wp-pdf-reader' ); ?></label></th>
						<td>
							<input type="text" id="wppdf-menu-icon" class="regular-text" name="<?php echo esc_attr( $option ); ?>[menu_icon]" value="<?php echo esc_attr( $settings['menu_icon'] ); ?>" />
							<p class="description"><?php esc_html_e( 'A Dashicons class, for example dashicons-media-document.', 'wp-pdf-reader' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Taxonomies', 'wp-pdf-reader' ); ?></th>
						<td>
							<?php
							$this->checkbox( 'shared_taxonomies', __( 'Share the post categories and tags', 'wp-pdf-reader' ), __( 'Documents use the same category tree as posts, so category archives list both.', 'wp-pdf-reader' ) );
							$this->checkbox( 'own_taxonomy', __( 'Add a separate document category taxonomy', 'wp-pdf-reader' ) );
							$this->checkbox( 'show_in_blog', __( 'Show documents in the blog loop, feeds and author/date archives', 'wp-pdf-reader' ) );
							$this->checkbox( 'has_archive', __( 'Enable the document archive page', 'wp-pdf-reader' ) );
							$this->checkbox( 'supports_excerpt', __( 'Enable excerpts', 'wp-pdf-reader' ) );
							$this->checkbox( 'supports_thumbnail', __( 'Enable featured images', 'wp-pdf-reader' ) );
							?>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Languages and fallback', 'wp-pdf-reader' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Languages', 'wp-pdf-reader' ); ?></th>
						<td>
							<div id="wppdf-language-rows" data-option="<?php echo esc_attr( $option ); ?>">
								<?php foreach ( $settings['languages'] as $index => $language ) : ?>
									<div class="wppdf-language-row">
										<input type="text" class="wppdf-language-code" name="<?php echo esc_attr( $option ); ?>[languages][<?php echo (int) $index; ?>][code]" value="<?php echo esc_attr( $language['code'] ); ?>" placeholder="cs" size="6" />
										<input type="text" name="<?php echo esc_attr( $option ); ?>[languages][<?php echo (int) $index; ?>][label]" value="<?php echo esc_attr( $language['label'] ); ?>" placeholder="Čeština" />
										<button type="button" class="button-link wppdf-remove-language" aria-label="<?php esc_attr_e( 'Remove language', 'wp-pdf-reader' ); ?>">&times;</button>
									</div>
								<?php endforeach; ?>
							</div>
							<p>
								<button type="button" class="button" id="wppdf-add-language"><?php esc_html_e( 'Add language', 'wp-pdf-reader' ); ?></button>
							</p>
							<p class="description"><?php esc_html_e( 'One PDF field per language is shown on every document. Codes such as cs, en or en-gb.', 'wp-pdf-reader' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wppdf-default-language"><?php esc_html_e( 'Default language', 'wp-pdf-reader' ); ?></label></th>
						<td>
							<select id="wppdf-default-language" name="<?php echo esc_attr( $option ); ?>[default_language]">
								<?php foreach ( $languages as $code => $language ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['default_language'], $code ); ?>>
										<?php echo esc_html( $language['label'] . ' (' . $code . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wppdf-fallback-chain"><?php esc_html_e( 'Fallback chain', 'wp-pdf-reader' ); ?></label></th>
						<td>
							<input type="text" id="wppdf-fallback-chain" class="regular-text" name="<?php echo esc_attr( $option ); ?>[fallback_chain]" value="<?php echo esc_attr( implode( ', ', (array) $settings['fallback_chain'] ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Comma separated codes tried in order when the current language has no file, for example: cs, en', 'wp-pdf-reader' ); ?></p>
							<?php
							$this->checkbox( 'fallback_any', __( 'As a last resort use any language that has a file', 'wp-pdf-reader' ) );
							$this->checkbox( 'show_fallback_notice', __( 'Tell visitors when they are seeing a fallback language', 'wp-pdf-reader' ) );
							$this->checkbox( 'sync_with_wpml', __( 'Take the current language from WPML or Polylang when active', 'wp-pdf-reader' ) );
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Current resolution', 'wp-pdf-reader' ); ?></th>
						<td>
							<p>
								<code><?php echo esc_html( implode( ' → ', WPPDF_Languages::get_fallback_order( WPPDF_Languages::get_default_language() ) ) ); ?></code>
							</p>
							<p class="description">
								<?php
								printf(
									/* translators: %s: detected language code. */
									esc_html__( 'Detected admin language: %s', 'wp-pdf-reader' ),
									'<code>' . esc_html( WPPDF_Languages::get_current_language() ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Reader', 'wp-pdf-reader' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wppdf-viewer-height"><?php esc_html_e( 'Height', 'wp-pdf-reader' ); ?></label></th>
						<td>
							<input type="number" id="wppdf-viewer-height" name="<?php echo esc_attr( $option ); ?>[viewer_height]" value="<?php echo (int) $settings['viewer_height']; ?>" min="200" max="4000" step="10" /> px
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wppdf-viewer-zoom"><?php esc_html_e( 'Initial zoom', 'wp-pdf-reader' ); ?></label></th>
						<td>
							<select id="wppdf-viewer-zoom" name="<?php echo esc_attr( $option ); ?>[viewer_zoom]">
								<?php
								$zoom_labels = array(
									'auto'       => __( 'Automatic', 'wp-pdf-reader' ),
									'page-width' => __( 'Fit width', 'wp-pdf-reader' ),
									'page-fit'   => __( 'Fit page', 'wp-pdf-reader' ),
									'100'        => '100 %',
									'125'        => '125 %',
									'150'        => '150 %',
								);
								foreach ( $zoom_labels as $value => $label ) :
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['viewer_zoom'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Behaviour', 'wp-pdf-reader' ); ?></th>
						<td>
							<?php
							$this->checkbox( 'show_toolbar', __( 'Show the toolbar', 'wp-pdf-reader' ) );
							$this->checkbox( 'allow_download', __( 'Show the download button', 'wp-pdf-reader' ) );
							$this->checkbox( 'allow_print', __( 'Show the print button', 'wp-pdf-reader' ) );
							$this->checkbox( 'lazy_load', __( 'Load the document only when it scrolls into view', 'wp-pdf-reader' ) );
							$this->checkbox( 'append_to_content', __( 'Append the reader to the content of single documents', 'wp-pdf-reader' ) );
							?>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Archive and grid', 'wp-pdf-reader' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wppdf-archive-layout"><?php esc_html_e( 'Layout', 'wp-pdf-reader' ); ?></label></th>
						<td>
							<select id="wppdf-archive-layout" name="<?php echo esc_attr( $option ); ?>[archive_layout]">
								<option value="grid" <?php selected( $settings['archive_layout'], 'grid' ); ?>><?php esc_html_e( 'Grid', 'wp-pdf-reader' ); ?></option>
								<option value="list" <?php selected( $settings['archive_layout'], 'list' ); ?>><?php esc_html_e( 'List', 'wp-pdf-reader' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wppdf-archive-columns"><?php esc_html_e( 'Columns', 'wp-pdf-reader' ); ?></label></th>
						<td>
							<input type="number" id="wppdf-archive-columns" name="<?php echo esc_attr( $option ); ?>[archive_columns]" value="<?php echo (int) $settings['archive_columns']; ?>" min="1" max="6" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wppdf-archive-per-page"><?php esc_html_e( 'Documents per page', 'wp-pdf-reader' ); ?></label></th>
						<td>
							<input type="number" id="wppdf-archive-per-page" name="<?php echo esc_attr( $option ); ?>[archive_per_page]" value="<?php echo (int) $settings['archive_per_page']; ?>" min="1" max="100" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Templates and covers', 'wp-pdf-reader' ); ?></th>
						<td>
							<?php
							$this->checkbox( 'archive_filters', __( 'Show the filter bar on the document archive', 'wp-pdf-reader' ), __( 'Full text, category, language and year, plus sorting. The same filters can be put anywhere with [pdf_grid filters="1"].', 'wp-pdf-reader' ) );
							$this->checkbox( 'override_templates', __( 'Use the bundled archive and single templates when the theme has none', 'wp-pdf-reader' ) );
							$this->checkbox( 'generate_covers', __( 'Generate a cover image from the first page when a PDF is uploaded', 'wp-pdf-reader' ) );
							?>
							<?php if ( ! WPPDF_Cover::is_available() ) : ?>
								<p class="description wppdf-warning"><?php esc_html_e( 'Imagick with PDF support is not available on this server, so covers cannot be generated. Featured images are used instead.', 'wp-pdf-reader' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Documents belonging to a page', 'wp-pdf-reader' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wppdf-acf-field"><?php esc_html_e( 'Field with the categories', 'wp-pdf-reader' ); ?></label></th>
						<td>
							<?php
							$acf_fields  = WPPDF_Acf::get_selectable_fields();
							$acf_current = (string) $settings['acf_category_field'];

							if ( WPPDF_Acf::is_active() ) :
								?>
								<select id="wppdf-acf-field" name="<?php echo esc_attr( $option ); ?>[acf_category_field]">
									<option value=""><?php esc_html_e( '— do not use —', 'wp-pdf-reader' ); ?></option>
									<?php
									// A stored field that no longer exists stays selectable, so
									// saving the page does not silently drop it.
									if ( '' !== $acf_current && ! isset( $acf_fields[ $acf_current ] ) ) {
										$acf_fields[ $acf_current ] = $acf_current;
									}

									foreach ( $acf_fields as $name => $label ) :
										?>
										<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $acf_current, $name ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input type="text" id="wppdf-acf-field" class="regular-text" name="<?php echo esc_attr( $option ); ?>[acf_category_field]" value="<?php echo esc_attr( $acf_current ); ?>" placeholder="pdf_kategorie" />
								<p class="description"><?php esc_html_e( 'ACF was not found, so type the meta key by hand. Any plugin storing term IDs or slugs under that key works.', 'wp-pdf-reader' ); ?></p>
							<?php endif; ?>

							<div class="wppdf-recommendation">
								<p><strong><?php esc_html_e( 'How to set the field up in ACF', 'wp-pdf-reader' ); ?></strong></p>
								<ul>
									<li>
										<?php
										printf(
											/* translators: %s: ACF field type name. */
											esc_html__( 'Field type: %s — not a plain select. It offers the real categories, so nothing is mistyped, and it keeps working when a category is renamed because it stores the ID rather than the text.', 'wp-pdf-reader' ),
											'<strong>' . esc_html__( 'Taxonomy', 'wp-pdf-reader' ) . '</strong>'
										);
										?>
									</li>
									<li>
										<?php
										printf(
											/* translators: %s: taxonomy name used by the documents. */
											esc_html__( 'Taxonomy: %s', 'wp-pdf-reader' ),
											'<code>' . esc_html( implode( ', ', WPPDF_Post_Type::get_document_taxonomies() ) ) . '</code>'
										);
										?>
									</li>
									<li><?php esc_html_e( 'Appearance: Multi Select or Checkbox, so a page can list several categories.', 'wp-pdf-reader' ); ?></li>
									<li><?php esc_html_e( 'Return format: Term ID.', 'wp-pdf-reader' ); ?></li>
									<li>
										<strong><?php esc_html_e( 'Save Terms: off.', 'wp-pdf-reader' ); ?></strong>
										<?php esc_html_e( 'This one matters. With it on, ACF files the page itself into those categories, and the page then turns up in category archives among the documents.', 'wp-pdf-reader' ); ?>
									</li>
									<li><?php esc_html_e( 'Load Terms: off, for the same reason.', 'wp-pdf-reader' ); ?></li>
								</ul>
								<p class="description">
									<?php esc_html_e( 'A Select or Checkbox field works too — category slugs or names are matched — but it drifts apart from the real category list, so Taxonomy is the safer choice.', 'wp-pdf-reader' ); ?>
								</p>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Use it in a template', 'wp-pdf-reader' ); ?></th>
						<td>
							<p class="description">
								<?php esc_html_e( 'Both read the field of the page being shown and list only the documents from those categories. When the field is empty, nothing is printed.', 'wp-pdf-reader' ); ?>
							</p>
							<p>
								<code>&lt;?php wppdf_the_page_documents(); ?&gt;</code><br />
								<code>&lt;?php wppdf_the_page_documents( array( 'columns' =&gt; 4, 'layout' =&gt; 'list' ) ); ?&gt;</code>
							</p>
							<p>
								<code>[pdf_grid from_field="1"]</code><br />
								<code>[pdf_grid from_field="jine_pole" columns="4"]</code>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Search, statistics and updates', 'wp-pdf-reader' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Text in PDFs', 'wp-pdf-reader' ); ?></th>
						<td>
							<?php
							$this->checkbox( 'extract_text', __( 'Extract the text of uploaded PDFs in the background', 'wp-pdf-reader' ) );
							$this->checkbox( 'search_pdf_text', __( 'Let the site search look inside documents', 'wp-pdf-reader' ) );
							?>
							<p class="description">
								<?php
								if ( WPPDF_Text::binary_available() ) {
									esc_html_e( 'pdftotext was found on this server, so extraction is fast and accurate.', 'wp-pdf-reader' );
								} else {
									esc_html_e( 'pdftotext was not found, so the built-in PHP parser is used. It handles most text PDFs.', 'wp-pdf-reader' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wppdf-ocr-languages"><?php esc_html_e( 'Scanned documents', 'wp-pdf-reader' ); ?></label></th>
						<td>
							<?php $this->checkbox( 'ocr_enabled', __( 'Read scans with OCR when a document has no text layer', 'wp-pdf-reader' ) ); ?>
							<p>
								<label>
									<?php esc_html_e( 'Languages', 'wp-pdf-reader' ); ?>
									<input type="text" id="wppdf-ocr-languages" name="<?php echo esc_attr( $option ); ?>[ocr_languages]" value="<?php echo esc_attr( $settings['ocr_languages'] ); ?>" size="12" />
								</label>
								<label>
									<?php esc_html_e( 'Pages at most', 'wp-pdf-reader' ); ?>
									<input type="number" name="<?php echo esc_attr( $option ); ?>[ocr_max_pages]" value="<?php echo (int) $settings['ocr_max_pages']; ?>" min="1" max="500" />
								</label>
							</p>
							<p class="description">
								<?php
								if ( WPPDF_Text::ocr_available() ) {
									esc_html_e( 'pdftoppm and tesseract were found. OCR only runs for documents with no text layer at all, on a scheduled event, and is slow — keep the page limit sensible.', 'wp-pdf-reader' );
								} else {
									esc_html_e( 'OCR needs pdftoppm (poppler) and tesseract on the server. Neither was found, so scans stay unindexed.', 'wp-pdf-reader' );
								}
								?>
								<br />
								<?php esc_html_e( 'Tesseract language codes joined with a plus, for example ces+eng.', 'wp-pdf-reader' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Existing documents', 'wp-pdf-reader' ); ?></th>
						<td>
							<?php $progress = WPPDF_Reindex::get_progress(); ?>
							<p id="wppdf-reindex-status">
								<?php
								printf(
									/* translators: 1: number of documents without an index, 2: total number of documents. */
									esc_html__( '%1$d of %2$d documents have no extracted text yet.', 'wp-pdf-reader' ),
									(int) $progress['pending'],
									(int) $progress['total']
								);
								?>
							</p>
							<p>
								<button type="button" class="button" id="wppdf-reindex-start" data-nonce="<?php echo esc_attr( wp_create_nonce( WPPDF_Reindex::NONCE ) ); ?>">
									<?php esc_html_e( 'Index them now', 'wp-pdf-reader' ); ?>
								</button>
								<label class="wppdf-checkbox wppdf-inline">
									<input type="checkbox" id="wppdf-reindex-force" value="1" />
									<?php esc_html_e( 'Re-extract everything, including documents that already have text', 'wp-pdf-reader' ); ?>
								</label>
								<span class="spinner wppdf-reindex__spinner"></span>
							</p>
							<p class="description">
								<?php esc_html_e( 'Text is extracted when a file is saved, so documents added before this feature existed need one pass. Large libraries are better served by WP-CLI: wp pdf-reader reindex', 'wp-pdf-reader' ); ?>
							</p>
							<div id="wppdf-reindex-log" class="wppdf-import__results" aria-live="polite"></div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Front end', 'wp-pdf-reader' ); ?></th>
						<td>
							<?php
							$this->checkbox( 'language_switcher', __( 'Let visitors switch language versions in the reader toolbar', 'wp-pdf-reader' ) );
							$this->checkbox( 'count_views', __( 'Count views and downloads per language', 'wp-pdf-reader' ) );
							$this->checkbox( 'seo_metadata', __( 'Output structured data and Open Graph tags on documents', 'wp-pdf-reader' ) );
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wppdf-github-repository"><?php esc_html_e( 'Updates from GitHub', 'wp-pdf-reader' ); ?></label></th>
						<td>
							<?php $this->checkbox( 'github_updates', __( 'Offer plugin updates from GitHub releases', 'wp-pdf-reader' ) ); ?>
							<input type="text" id="wppdf-github-repository" class="regular-text" name="<?php echo esc_attr( $option ); ?>[github_repository]" value="<?php echo esc_attr( $settings['github_repository'] ); ?>" placeholder="owner/repository" />
							<p class="description">
								<?php esc_html_e( 'The repository in owner/name form. The newest published release is offered as an update.', 'wp-pdf-reader' ); ?>
								<?php
								$release = WPPDF_Updater::get_release();
								if ( $release && ! empty( $release['version'] ) ) {
									echo '<br />';
									printf(
										/* translators: 1: latest version, 2: installed version. */
										esc_html__( 'Latest release: %1$s, installed: %2$s', 'wp-pdf-reader' ),
										'<code>' . esc_html( $release['version'] ) . '</code>',
										'<code>' . esc_html( WPPDF_VERSION ) . '</code>'
									);
								}
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Shortcodes', 'wp-pdf-reader' ); ?></h2>
				<p class="description">
					<code>[pdf_reader id="12" lang="en" height="800"]</code><br />
					<code>[pdf_grid columns="3" per_page="12" category="reports"]</code><br />
					<code>[pdf_list per_page="20" pagination="1"]</code><br />
					<code>[pdf_download id="12" text="<?php esc_attr_e( 'Download PDF', 'wp-pdf-reader' ); ?>"]</code>
				</p>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a settings checkbox.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Checkbox label.
	 * @param string $description Optional description.
	 */
	protected function checkbox( $key, $label, $description = '' ) {
		$settings = WPPDF_Settings::all();
		$value    = ! empty( $settings[ $key ] );

		printf(
			'<label class="wppdf-checkbox"><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( WPPDF_Settings::OPTION ),
			esc_attr( $key ),
			checked( $value, true, false ),
			esc_html( $label )
		);

		if ( '' !== $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}
	}
}

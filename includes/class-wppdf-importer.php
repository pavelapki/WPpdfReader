<?php
/**
 * Bulk import: turn a pile of uploaded PDFs into documents.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bulk importer.
 */
class WPPDF_Importer {

	/**
	 * Admin page slug.
	 */
	const PAGE = 'wppdf-import';

	/**
	 * Nonce action.
	 */
	const NONCE = 'wppdf_import';

	/**
	 * Files handled per request, to keep each request short.
	 */
	const BATCH = 20;

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'wp_ajax_wppdf_import', array( $this, 'handle_ajax' ) );
	}

	/**
	 * Add the import screen under the document menu.
	 */
	public function add_page() {
		add_submenu_page(
			'edit.php?post_type=' . WPPDF_Post_Type::get_key(),
			__( 'Import PDFs', 'wp-pdf-reader' ),
			__( 'Import', 'wp-pdf-reader' ),
			'upload_files',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Whether the current user may turn media into documents.
	 *
	 * The screen picks files out of the media library, so it takes the
	 * capability that grants the library itself on top of the one that creates
	 * posts. A contributor has edit_posts but no business reading other
	 * people's uploads.
	 *
	 * @return bool
	 */
	protected static function user_can_import() {
		return current_user_can( 'edit_posts' ) && current_user_can( 'upload_files' );
	}

	/**
	 * Render the import screen.
	 */
	public function render() {
		if ( ! self::user_can_import() ) {
			return;
		}

		$languages = WPPDF_Languages::get_languages();
		$default   = WPPDF_Languages::get_default_language();
		?>
		<div class="wrap wppdf-import">
			<h1><?php esc_html_e( 'Import PDFs', 'wp-pdf-reader' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Pick several PDFs at once and each of them becomes a document. The file name is used as the title, so rename the files before uploading if you want tidy titles.', 'wp-pdf-reader' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wppdf-import-language"><?php esc_html_e( 'Language of the files', 'wp-pdf-reader' ); ?></label></th>
					<td>
						<select id="wppdf-import-language">
							<?php foreach ( $languages as $code => $language ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $default ); ?>>
									<?php echo esc_html( $language['label'] . ' (' . $code . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wppdf-import-status"><?php esc_html_e( 'Create as', 'wp-pdf-reader' ); ?></label></th>
					<td>
						<select id="wppdf-import-status">
							<option value="draft"><?php esc_html_e( 'Draft', 'wp-pdf-reader' ); ?></option>
							<option value="publish"><?php esc_html_e( 'Published', 'wp-pdf-reader' ); ?></option>
						</select>
					</td>
				</tr>
				<?php if ( WPPDF_Settings::get( 'shared_taxonomies' ) ) : ?>
					<tr>
						<th scope="row"><label for="wppdf-import-category"><?php esc_html_e( 'Category', 'wp-pdf-reader' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_categories(
								array(
									'id'                => 'wppdf-import-category',
									'name'              => 'wppdf_import_category',
									'show_option_none'  => __( 'No category', 'wp-pdf-reader' ),
									'option_none_value' => 0,
									'hide_empty'        => false,
									'orderby'           => 'name',
								)
							);
							?>
						</td>
					</tr>
				<?php endif; ?>
			</table>

			<p>
				<button type="button" class="button button-primary" id="wppdf-import-select">
					<?php esc_html_e( 'Select PDFs and import', 'wp-pdf-reader' ); ?>
				</button>
				<span class="spinner wppdf-import__spinner"></span>
			</p>

			<div id="wppdf-import-results" class="wppdf-import__results" aria-live="polite"></div>

			<?php $this->render_migration(); ?>
		</div>
		<?php
	}

	/**
	 * Render the "import from another plugin" panel.
	 */
	protected function render_migration() {
		$sources = WPPDF_Migrator::get_sources();

		if ( empty( $sources ) ) {
			return;
		}

		?>
		<hr />

		<h2><?php esc_html_e( 'Import from another plugin', 'wp-pdf-reader' ); ?></h2>

		<p class="description">
			<?php esc_html_e( 'Copies existing records into documents: title, text, date, author, featured image, the PDF itself and the categories — including categories from a taxonomy of the other plugin, which are matched to yours by name or created. The originals are left untouched, and a record that was already imported is never imported twice.', 'wp-pdf-reader' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wppdf-migrate-source"><?php esc_html_e( 'Source', 'wp-pdf-reader' ); ?></label></th>
				<td>
					<select id="wppdf-migrate-source">
						<?php foreach ( $sources as $source ) : ?>
							<option value="<?php echo esc_attr( $source['type'] ); ?>"
								data-slug="<?php echo esc_attr( $source['slug'] ); ?>"
								data-active="<?php echo $source['active'] ? '1' : '0'; ?>">
								<?php
								$label = $source['adapter'] ? $source['adapter'] : $source['label'];

								printf(
									/* translators: 1: source name, 2: post type key, 3: number of records. */
									esc_html__( '%1$s (%2$s) — %3$d records', 'wp-pdf-reader' ),
									esc_html( $label ),
									esc_html( $source['type'] ),
									(int) $source['count']
								);

								if ( $source['imported'] > 0 ) {
									printf(
										/* translators: %d: number already imported. */
										esc_html__( ', %d already imported', 'wp-pdf-reader' ),
										(int) $source['imported']
									);
								}

								if ( ! $source['active'] ) {
									echo esc_html__( ', plugin inactive', 'wp-pdf-reader' );
								}
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'TNC FlipBook is recognised directly, including its page count and extracted text, which saves re-reading every PDF. For any other post type the PDF is located by inspecting the record, which works as long as it points at a PDF in the media library.', 'wp-pdf-reader' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wppdf-migrate-language"><?php esc_html_e( 'Language of the files', 'wp-pdf-reader' ); ?></label></th>
				<td>
					<select id="wppdf-migrate-language">
						<?php foreach ( WPPDF_Languages::get_languages() as $code => $language ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, WPPDF_Languages::get_default_language() ); ?>>
								<?php echo esc_html( $language['label'] . ' (' . $code . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wppdf-migrate-status"><?php esc_html_e( 'Create as', 'wp-pdf-reader' ); ?></label></th>
				<td>
					<select id="wppdf-migrate-status">
						<option value="source"><?php esc_html_e( 'Same status as the original', 'wp-pdf-reader' ); ?></option>
						<option value="draft"><?php esc_html_e( 'Draft', 'wp-pdf-reader' ); ?></option>
						<option value="publish"><?php esc_html_e( 'Published', 'wp-pdf-reader' ); ?></option>
					</select>
				</td>
			</tr>
		</table>

		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Addresses', 'wp-pdf-reader' ); ?></th>
					<td>
						<p id="wppdf-migrate-urls">
							<?php
							printf(
								/* translators: %s: current URL prefix of the documents. */
								esc_html__( 'Documents currently live under %s.', 'wp-pdf-reader' ),
								'<code>/' . esc_html( WPPDF_Settings::get( 'post_type_slug' ) ) . '/</code>'
							);
							?>
							<span id="wppdf-migrate-oldurl"></span>
						</p>
						<p>
							<button type="button" class="button" id="wppdf-adopt-slug" data-nonce="<?php echo esc_attr( wp_create_nonce( WPPDF_Migrator::NONCE ) ); ?>" hidden>
								<?php esc_html_e( 'Take over this prefix', 'wp-pdf-reader' ); ?>
							</button>
							<span id="wppdf-adopt-result"></span>
						</p>
						<p class="description">
							<?php esc_html_e( 'The slug of each record is kept during the import, so taking over the prefix makes every old address resolve to the same document. Do it after the other plugin is deactivated — while both are active they claim the same addresses and only one wins.', 'wp-pdf-reader' ); ?>
						</p>
						<?php $this->checkbox_note(); ?>
					</td>
				</tr>
			</table>
		<?php endif; ?>

		<p>
			<button type="button" class="button" id="wppdf-migrate-choose" data-nonce="<?php echo esc_attr( wp_create_nonce( WPPDF_Migrator::NONCE ) ); ?>">
				<?php esc_html_e( 'Choose records…', 'wp-pdf-reader' ); ?>
			</button>
			<button type="button" class="button button-primary" id="wppdf-migrate-start" data-nonce="<?php echo esc_attr( wp_create_nonce( WPPDF_Migrator::NONCE ) ); ?>">
				<?php esc_html_e( 'Import all records', 'wp-pdf-reader' ); ?>
			</button>
			<span class="spinner wppdf-migrate__spinner"></span>
		</p>

		<p class="description">
			<?php esc_html_e( 'Choosing shows what was found in each record, so the ones holding no PDF — stub translations, notes, index pages — can be left behind. Importing all takes everything the source has.', 'wp-pdf-reader' ); ?>
		</p>

		<div id="wppdf-migrate-picker" class="wppdf-migrate__picker" hidden>
			<p class="wppdf-migrate__tools">
				<button type="button" class="button-link" data-wppdf-select="pdf"><?php esc_html_e( 'Select those with a PDF', 'wp-pdf-reader' ); ?></button>
				<button type="button" class="button-link" data-wppdf-select="all"><?php esc_html_e( 'Select all', 'wp-pdf-reader' ); ?></button>
				<button type="button" class="button-link" data-wppdf-select="none"><?php esc_html_e( 'Select none', 'wp-pdf-reader' ); ?></button>
				<span class="wppdf-migrate__count" aria-live="polite"></span>
			</p>

			<table class="widefat striped wppdf-migrate__table">
				<thead>
					<tr>
						<td class="check-column"></td>
						<th scope="col"><?php esc_html_e( 'Record', 'wp-pdf-reader' ); ?></th>
						<th scope="col"><?php esc_html_e( 'PDF', 'wp-pdf-reader' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Categories', 'wp-pdf-reader' ); ?></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>

			<p>
				<button type="button" class="button" id="wppdf-migrate-more" hidden><?php esc_html_e( 'Load more', 'wp-pdf-reader' ); ?></button>
				<button type="button" class="button button-primary" id="wppdf-migrate-selected" data-nonce="<?php echo esc_attr( wp_create_nonce( WPPDF_Migrator::NONCE ) ); ?>">
					<?php esc_html_e( 'Import selected', 'wp-pdf-reader' ); ?>
				</button>
			</p>
		</div>

		<div id="wppdf-migrate-results" class="wppdf-import__results" aria-live="polite"></div>
		<?php
	}

	/**
	 * Note about the safety net for addresses that do not line up.
	 */
	protected function checkbox_note() {
		if ( WPPDF_Settings::get( 'redirect_old_urls' ) ) {
			echo '<p class="description">' . esc_html__( 'Whatever does not line up is caught anyway: an address that would 404 and belonged to an imported record is permanently redirected to its document.', 'wp-pdf-reader' ) . '</p>';

			return;
		}

		echo '<p class="wppdf-notice-warning">' . esc_html__( 'Redirecting old addresses is switched off in the settings, so anything whose address changes will 404.', 'wp-pdf-reader' ) . '</p>';
	}

	/**
	 * Create documents from the selected attachments.
	 */
	public function handle_ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! self::user_can_import() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to create documents.', 'wp-pdf-reader' ) ), 403 );
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_slice( array_filter( array_unique( $ids ) ), 0, self::BATCH );

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No files were selected.', 'wp-pdf-reader' ) ), 400 );
		}

		$code = isset( $_POST['lang'] ) ? wppdf_sanitize_language_code( wp_unslash( $_POST['lang'] ) ) : '';

		if ( '' === $code || ! in_array( $code, WPPDF_Languages::get_codes(), true ) ) {
			$code = WPPDF_Languages::get_default_language();
		}

		$status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft';
		$status   = 'publish' === $status ? 'publish' : 'draft';
		$category = isset( $_POST['category'] ) ? absint( wp_unslash( $_POST['category'] ) ) : 0;

		if ( 'publish' === $status && ! current_user_can( 'publish_posts' ) ) {
			$status = 'draft';
		}

		$created = array();
		$skipped = array();

		foreach ( $ids as $attachment_id ) {
			// The IDs arrive from the browser, so an unlisted attachment could
			// be named directly. Publishing a document for a file the user
			// cannot read would hand it to everybody.
			if ( ! current_user_can( 'read_post', $attachment_id ) ) {
				$skipped[] = array(
					'id'     => $attachment_id,
					'reason' => __( 'You are not allowed to use this file.', 'wp-pdf-reader' ),
				);
				continue;
			}

			if ( ! WPPDF_Documents::is_valid_attachment( $attachment_id ) ) {
				$skipped[] = array(
					'id'     => $attachment_id,
					'reason' => __( 'Not a PDF file.', 'wp-pdf-reader' ),
				);
				continue;
			}

			$post_id = $this->create_document( $attachment_id, $code, $status, $category );

			if ( is_wp_error( $post_id ) ) {
				$skipped[] = array(
					'id'     => $attachment_id,
					'reason' => $post_id->get_error_message(),
				);
				continue;
			}

			$created[] = array(
				'id'    => $post_id,
				'title' => get_the_title( $post_id ),
				'edit'  => get_edit_post_link( $post_id, 'raw' ),
			);
		}

		wp_send_json_success(
			array(
				'created' => $created,
				'skipped' => $skipped,
			)
		);
	}

	/**
	 * Create one document from an attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $code          Language code.
	 * @param string $status        Post status.
	 * @param int    $category      Category term ID, 0 for none.
	 * @return int|WP_Error
	 */
	protected function create_document( $attachment_id, $code, $status, $category ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => WPPDF_Post_Type::get_key(),
				'post_status' => $status,
				'post_title'  => self::title_from_attachment( $attachment_id ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, WPPDF_Languages::file_meta_key( $code ), $attachment_id );

		if ( $category && WPPDF_Settings::get( 'shared_taxonomies' ) ) {
			wp_set_post_terms( $post_id, array( $category ), 'category' );
		}

		WPPDF_Cover::schedule( $post_id, $code, $attachment_id );
		WPPDF_Text::schedule( $post_id, $code, $attachment_id );

		/**
		 * Fires after the importer created a document.
		 *
		 * @param int    $post_id       Document ID.
		 * @param int    $attachment_id Attachment ID.
		 * @param string $code          Language code.
		 */
		do_action( 'wppdf_document_imported', $post_id, $attachment_id, $code );

		return (int) $post_id;
	}

	/**
	 * Readable title for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function title_from_attachment( $attachment_id ) {
		$title = get_the_title( $attachment_id );

		if ( '' === trim( (string) $title ) ) {
			$path  = get_attached_file( $attachment_id );
			$title = $path ? pathinfo( $path, PATHINFO_FILENAME ) : '';
		}

		$title = str_replace( array( '-', '_' ), ' ', (string) $title );
		$title = trim( preg_replace( '/\s+/', ' ', $title ) );

		if ( '' === $title ) {
			$title = __( 'Untitled document', 'wp-pdf-reader' );
		}

		return function_exists( 'mb_convert_case' )
			? mb_convert_case( mb_substr( $title, 0, 1 ), MB_CASE_UPPER, 'UTF-8' ) . mb_substr( $title, 1 )
			: ucfirst( $title );
	}
}

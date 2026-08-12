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
			'edit_posts',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the import screen.
	 */
	public function render() {
		if ( ! current_user_can( 'edit_posts' ) ) {
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
									'id'               => 'wppdf-import-category',
									'name'             => 'wppdf_import_category',
									'show_option_none' => __( 'No category', 'wp-pdf-reader' ),
									'option_none_value' => 0,
									'hide_empty'       => false,
									'orderby'          => 'name',
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
			<?php esc_html_e( 'Copies existing records into documents: title, text, date, author, categories and featured image, plus the PDF itself. The originals are left untouched, and a record that was already imported is never imported twice.', 'wp-pdf-reader' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wppdf-migrate-source"><?php esc_html_e( 'Source', 'wp-pdf-reader' ); ?></label></th>
				<td>
					<select id="wppdf-migrate-source">
						<?php foreach ( $sources as $source ) : ?>
							<option value="<?php echo esc_attr( $source['type'] ); ?>">
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

		<p>
			<button type="button" class="button button-primary" id="wppdf-migrate-start" data-nonce="<?php echo esc_attr( wp_create_nonce( WPPDF_Migrator::NONCE ) ); ?>">
				<?php esc_html_e( 'Import records', 'wp-pdf-reader' ); ?>
			</button>
			<span class="spinner wppdf-migrate__spinner"></span>
		</p>

		<div id="wppdf-migrate-results" class="wppdf-import__results" aria-live="polite"></div>
		<?php
	}

	/**
	 * Create documents from the selected attachments.
	 */
	public function handle_ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to create documents.', 'wp-pdf-reader' ) ), 403 );
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_slice( array_filter( array_unique( $ids ) ), 0, self::BATCH );

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No files were selected.', 'wp-pdf-reader' ) ), 400 );
		}

		$code = isset( $_POST['lang'] ) ? WPPDF_Settings::sanitize_language_code( wp_unslash( $_POST['lang'] ) ) : '';

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

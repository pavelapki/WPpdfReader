<?php
/**
 * The per-language PDF file meta box.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Meta box for the per-language files.
 */
class WPPDF_Meta {

	/**
	 * Nonce action.
	 */
	const NONCE = 'wppdf_save_files';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Register the meta box on every supported post type.
	 */
	public function add_meta_box() {
		foreach ( WPPDF_Post_Type::get_supported_post_types() as $post_type ) {
			add_meta_box(
				'wppdf-files',
				__( 'PDF files by language', 'wp-pdf-reader' ),
				array( $this, 'render' ),
				$post_type,
				'normal',
				'high',
				array( '__block_editor_compatible_meta_box' => true )
			);
		}
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render( $post ) {
		wp_nonce_field( self::NONCE, 'wppdf_nonce' );

		$languages = WPPDF_Languages::get_languages();
		$default   = WPPDF_Languages::get_default_language();
		$has_any   = false;

		echo '<div class="wppdf-files">';

		echo '<p class="wppdf-files__intro">' . esc_html__( 'Upload one PDF per language. Languages left empty fall back to the first available language in the fallback chain. Each language can also carry its own address; the one matching the site language is used, the others redirect to it.', 'wp-pdf-reader' ) . '</p>';

		foreach ( $languages as $code => $language ) {
			$file_key  = WPPDF_Languages::file_meta_key( $code );
			$url_key   = WPPDF_Documents::url_meta_key( $code );
			$file_id   = absint( get_post_meta( $post->ID, $file_key, true ) );
			$file_url  = (string) get_post_meta( $post->ID, $url_key, true );
			$cover_id  = absint( get_post_meta( $post->ID, WPPDF_Languages::cover_meta_key( $code ), true ) );
			$attach_ok = $file_id && WPPDF_Documents::is_valid_attachment( $file_id );
			$filled    = $attach_ok || '' !== trim( $file_url );
			$has_any   = $has_any || $filled;

			$filename = '';
			$filesize = '';
			if ( $attach_ok ) {
				$path = get_attached_file( $file_id );
				if ( $path && file_exists( $path ) ) {
					$filename = basename( $path );
					$filesize = WPPDF_Documents::format_filesize( filesize( $path ) );
				} else {
					$filename = get_the_title( $file_id );
				}
			}

			printf(
				'<div class="wppdf-file-row%1$s" data-lang="%2$s">',
				$filled ? ' is-filled' : '',
				esc_attr( $code )
			);

			echo '<div class="wppdf-file-row__header">';
			printf(
				'<span class="wppdf-lang-badge">%s</span> <strong>%s</strong>',
				esc_html( strtoupper( $code ) ),
				esc_html( $language['label'] )
			);
			if ( $code === $default ) {
				echo ' <span class="wppdf-pill">' . esc_html__( 'default', 'wp-pdf-reader' ) . '</span>';
			}
			echo '</div>';

			echo '<div class="wppdf-file-row__body">';

			if ( $cover_id ) {
				$thumb = wp_get_attachment_image( $cover_id, array( 60, 80 ), false, array( 'class' => 'wppdf-file-row__cover' ) );
				if ( $thumb ) {
					echo wp_kses_post( $thumb );
				}
			}

			printf(
				'<input type="hidden" class="wppdf-file-id" name="wppdf_file[%1$s]" value="%2$d" />',
				esc_attr( $code ),
				$attach_ok ? (int) $file_id : 0
			);

			echo '<div class="wppdf-file-row__info">';
			printf(
				'<span class="wppdf-file-name"%1$s>%2$s</span>',
				$filename ? '' : ' hidden',
				esc_html( $filename )
			);
			printf(
				'<span class="wppdf-file-size"%1$s>%2$s</span>',
				$filesize ? '' : ' hidden',
				esc_html( $filesize )
			);
			printf(
				'<span class="wppdf-file-empty"%1$s>%2$s</span>',
				$filled ? ' hidden' : '',
				esc_html__( 'No file for this language.', 'wp-pdf-reader' )
			);
			echo '</div>';

			echo '<div class="wppdf-file-row__actions">';
			printf(
				'<button type="button" class="button wppdf-select">%s</button> ',
				esc_html__( 'Select PDF', 'wp-pdf-reader' )
			);
			printf(
				'<button type="button" class="button-link wppdf-remove"%1$s>%2$s</button>',
				$attach_ok ? '' : ' hidden',
				esc_html__( 'Remove', 'wp-pdf-reader' )
			);
			echo '</div>';

			echo '</div>';

			echo '<div class="wppdf-file-row__url">';
			printf(
				'<label>%1$s <input type="url" class="regular-text" name="wppdf_url[%2$s]" value="%3$s" placeholder="https://…" /></label>',
				esc_html__( 'or external URL:', 'wp-pdf-reader' ),
				esc_attr( $code ),
				esc_attr( $file_url )
			);
			echo '</div>';

			echo '<div class="wppdf-file-row__title">';
			printf(
				'<label>%1$s <input type="text" class="regular-text" name="wppdf_title[%2$s]" value="%3$s" placeholder="%4$s" /></label>',
				esc_html__( 'title in this language:', 'wp-pdf-reader' ),
				esc_attr( $code ),
				esc_attr( (string) get_post_meta( $post->ID, WPPDF_Languages::title_meta_key( $code ), true ) ),
				esc_attr( $post->post_title )
			);
			echo '</div>';

			echo '<div class="wppdf-file-row__slug">';
			printf(
				'<label>%1$s <input type="text" class="regular-text" name="wppdf_slug[%2$s]" value="%3$s" placeholder="%4$s" /></label>',
				esc_html__( 'address in this language:', 'wp-pdf-reader' ),
				esc_attr( $code ),
				esc_attr( (string) WPPDF_Permalinks::get_slug( $post->ID, $code ) ),
				esc_attr( $post->post_name )
			);
			echo '</div>';

			echo '</div>';
		}

		printf(
			'<p class="wppdf-files__protection"><label><input type="checkbox" name="wppdf_protected" value="1" %1$s /> %2$s</label></p>',
			checked( WPPDF_Protection::is_protected( $post->ID ), true, false ),
			esc_html__( 'Only logged in visitors may open this document', 'wp-pdf-reader' )
		);

		echo '<p class="description">' . esc_html__( 'The files are moved into a directory that denies direct access and are served through PHP after a permission check, so the old file URL stops working.', 'wp-pdf-reader' ) . '</p>';

		if ( ! WPPDF_Protection::guards_are_effective() ) {
			echo '<p class="wppdf-notice-warning">' . esc_html__( 'This server is not Apache or LiteSpeed, so the deny file has no effect. Add a rule to the server config that blocks /wp-content/uploads/wppdf-protected/.', 'wp-pdf-reader' ) . '</p>';
		}

		echo '<p class="wppdf-files__fallback">';
		printf(
			/* translators: %s: ordered list of language labels. */
			esc_html__( 'Fallback order: %s', 'wp-pdf-reader' ),
			'<code>' . esc_html( implode( ' → ', WPPDF_Languages::get_fallback_order( $default ) ) ) . '</code>'
		);
		echo '</p>';

		if ( ! $has_any ) {
			echo '<p class="wppdf-notice-warning">' . esc_html__( 'This document has no file yet, so the reader will not be rendered.', 'wp-pdf-reader' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Persist the submitted files.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, WPPDF_Post_Type::get_supported_post_types(), true ) ) {
			return;
		}

		if ( ! isset( $_POST['wppdf_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wppdf_nonce'] ) ), self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		WPPDF_Documents::flush_cache();

		$protected     = ! empty( $_POST['wppdf_protected'] );
		$was_protected = WPPDF_Protection::is_protected( $post_id );

		// Both arrive as one value per language, so they are reduced to what
		// they are allowed to be here rather than element by element below.
		$files  = isset( $_POST['wppdf_file'] ) && is_array( $_POST['wppdf_file'] )
			? array_map( 'absint', wp_unslash( $_POST['wppdf_file'] ) )
			: array();
		$urls   = isset( $_POST['wppdf_url'] ) && is_array( $_POST['wppdf_url'] )
			? array_map( 'esc_url_raw', wp_unslash( $_POST['wppdf_url'] ) )
			: array();
		$slugs  = isset( $_POST['wppdf_slug'] ) && is_array( $_POST['wppdf_slug'] )
			? array_map( 'sanitize_title', wp_unslash( $_POST['wppdf_slug'] ) )
			: array();
		$titles = isset( $_POST['wppdf_title'] ) && is_array( $_POST['wppdf_title'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['wppdf_title'] ) )
			: array();

		foreach ( WPPDF_Languages::get_codes() as $code ) {
			$file_key = WPPDF_Languages::file_meta_key( $code );
			$url_key  = WPPDF_Documents::url_meta_key( $code );

			$attachment_id = isset( $files[ $code ] ) ? $files[ $code ] : 0;

			// The field holds whatever ID was posted, so a file the editor
			// cannot read must not be republished through a document.
			if ( $attachment_id && ! current_user_can( 'read_post', $attachment_id ) ) {
				$attachment_id = 0;
			}

			if ( $attachment_id && ! WPPDF_Documents::is_valid_attachment( $attachment_id ) ) {
				$attachment_id = 0;
			}

			$previous = absint( get_post_meta( $post_id, $file_key, true ) );

			if ( $attachment_id ) {
				update_post_meta( $post_id, $file_key, $attachment_id );
			} else {
				delete_post_meta( $post_id, $file_key );
			}

			$url = isset( $urls[ $code ] ) ? trim( (string) $urls[ $code ] ) : '';
			if ( '' !== $url ) {
				update_post_meta( $post_id, $url_key, $url );
			} else {
				delete_post_meta( $post_id, $url_key );
			}

			// Sanitizing, uniqueness and removal all live in one place, because
			// a duplicate address decides which document answers on it.
			WPPDF_Permalinks::set_slug( $post_id, $code, isset( $slugs[ $code ] ) ? $slugs[ $code ] : '' );

			$title_key = WPPDF_Languages::title_meta_key( $code );
			$title     = isset( $titles[ $code ] ) ? trim( (string) $titles[ $code ] ) : '';

			if ( '' !== $title ) {
				update_post_meta( $post_id, $title_key, $title );
			} else {
				delete_post_meta( $post_id, $title_key );
			}

			if ( $attachment_id !== $previous ) {
				delete_post_meta( $post_id, WPPDF_Languages::cover_meta_key( $code ) );
				WPPDF_Text::clear( $post_id, $code );

				if ( $attachment_id ) {
					// Both are heavy, so they run on scheduled events.
					WPPDF_Cover::schedule( $post_id, $code, $attachment_id );
					WPPDF_Text::schedule( $post_id, $code, $attachment_id );
				}
			}
		}

		// Files are moved after they were stored, so newly attached ones are
		// protected straight away.
		if ( $protected ) {
			update_post_meta( $post_id, WPPDF_Protection::META, 1 );
		} else {
			delete_post_meta( $post_id, WPPDF_Protection::META );
		}

		if ( $protected !== $was_protected || $protected ) {
			WPPDF_Protection::apply( $post_id, $protected );
		}
	}
}

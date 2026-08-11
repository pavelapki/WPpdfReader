<?php
/**
 * Cover images rendered from the first page of a PDF.
 *
 * Requires Imagick with PDF support (Ghostscript). Every failure is silent:
 * the grid simply falls back to the featured image or a placeholder.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * First page cover generator.
 */
class WPPDF_Cover {

	/**
	 * Scheduled event name.
	 */
	const EVENT = 'wppdf_generate_cover';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( self::EVENT, array( __CLASS__, 'run' ), 10, 3 );
	}

	/**
	 * Queue cover rendering.
	 *
	 * Rendering a page with Imagick is expensive, so it happens on a scheduled
	 * event instead of inside the save request.
	 *
	 * @param int    $post_id       Document ID.
	 * @param string $code          Language code.
	 * @param int    $attachment_id Attachment ID.
	 */
	public static function schedule( $post_id, $code, $attachment_id ) {
		if ( ! WPPDF_Settings::get( 'generate_covers' ) || ! self::is_available() ) {
			return;
		}

		$args = array( (int) $post_id, (string) $code, (int) $attachment_id );

		if ( wp_next_scheduled( self::EVENT, $args ) ) {
			return;
		}

		wp_schedule_single_event( time() + 10, self::EVENT, $args );
	}

	/**
	 * Scheduled callback: render the cover if the file is still in place.
	 *
	 * @param int    $post_id       Document ID.
	 * @param string $code          Language code.
	 * @param int    $attachment_id Attachment ID.
	 */
	public static function run( $post_id, $code, $attachment_id ) {
		$post_id = absint( $post_id );
		$code    = WPPDF_Settings::sanitize_language_code( $code );

		if ( ! $post_id || '' === $code || ! get_post( $post_id ) ) {
			return;
		}

		$current = absint( get_post_meta( $post_id, WPPDF_Languages::file_meta_key( $code ), true ) );

		if ( ! $current || $current !== absint( $attachment_id ) ) {
			return;
		}

		self::generate( $post_id, $code, $current );
	}

	/**
	 * Whether covers can be rendered on this server.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick' ) ) {
			return false;
		}

		try {
			$formats = Imagick::queryFormats( 'PDF' );
		} catch ( Exception $e ) {
			return false;
		}

		return ! empty( $formats );
	}

	/**
	 * Render the first page of a PDF into an attachment.
	 *
	 * @param int    $post_id       Document post ID.
	 * @param string $code          Language code.
	 * @param int    $attachment_id PDF attachment ID.
	 * @return int Cover attachment ID, 0 on failure.
	 */
	public static function generate( $post_id, $code, $attachment_id ) {
		if ( ! self::is_available() ) {
			return 0;
		}

		$path = get_attached_file( $attachment_id );

		if ( ! $path || ! file_exists( $path ) ) {
			return 0;
		}

		/**
		 * Filter the pixel width of generated covers.
		 *
		 * @param int $width Cover width.
		 */
		$width = (int) apply_filters( 'wppdf_cover_width', 800 );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return 0;
		}

		$basename = pathinfo( $path, PATHINFO_FILENAME );
		$filename = wp_unique_filename( $uploads['path'], sanitize_file_name( $basename . '-' . $code . '-cover.jpg' ) );
		$target   = trailingslashit( $uploads['path'] ) . $filename;

		try {
			$imagick = new Imagick();
			$imagick->setResolution( 150, 150 );
			$imagick->readImage( $path . '[0]' );
			$imagick->setImageBackgroundColor( 'white' );
			$imagick = $imagick->flattenImages();
			$imagick->setImageFormat( 'jpeg' );
			$imagick->setImageCompressionQuality( 82 );
			$imagick->thumbnailImage( $width, 0 );
			$imagick->writeImage( $target );
			$imagick->clear();
			$imagick->destroy();
		} catch ( Exception $e ) {
			if ( file_exists( $target ) ) {
				wp_delete_file( $target );
			}

			return 0;
		}

		if ( ! file_exists( $target ) ) {
			return 0;
		}

		$cover_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => sprintf(
					/* translators: 1: document title, 2: language code. */
					__( '%1$s cover (%2$s)', 'wp-pdf-reader' ),
					get_the_title( $post_id ),
					strtoupper( $code )
				),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_parent'    => $post_id,
			),
			$target,
			$post_id
		);

		if ( is_wp_error( $cover_id ) || ! $cover_id ) {
			wp_delete_file( $target );

			return 0;
		}

		wp_update_attachment_metadata( $cover_id, wp_generate_attachment_metadata( $cover_id, $target ) );
		update_post_meta( $post_id, WPPDF_Languages::cover_meta_key( $code ), $cover_id );
		update_post_meta( $cover_id, '_wppdf_generated_cover', 1 );

		return (int) $cover_id;
	}

	/**
	 * Delete a previously generated cover.
	 *
	 * @param int    $post_id Document post ID.
	 * @param string $code    Language code.
	 */
	public static function delete( $post_id, $code ) {
		$cover_id = absint( get_post_meta( $post_id, WPPDF_Languages::cover_meta_key( $code ), true ) );

		if ( $cover_id && get_post_meta( $cover_id, '_wppdf_generated_cover', true ) ) {
			wp_delete_attachment( $cover_id, true );
		}

		delete_post_meta( $post_id, WPPDF_Languages::cover_meta_key( $code ) );
	}
}

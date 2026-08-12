<?php
/**
 * Documents that only logged in visitors may read.
 *
 * Hiding the URL is not protection: the file itself is moved into a directory
 * that denies direct access, and is served through PHP after a capability
 * check. On Apache and LiteSpeed the deny rule is written automatically; nginx
 * needs one line in the site config, which the settings screen prints.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Access protection for document files.
 */
class WPPDF_Protection {

	/**
	 * Meta key holding the protection flag.
	 */
	const META = '_wppdf_protected';

	/**
	 * Query variable used by the delivery endpoint.
	 */
	const QUERY_VAR = 'wppdf_file';

	/**
	 * Directory inside uploads that holds protected files.
	 */
	const DIRECTORY = 'wppdf-protected';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 0 );
		add_filter( 'wppdf_file_url', array( $this, 'filter_file_url' ), 10, 3 );
		add_filter( 'wppdf_resolved_file', array( $this, 'filter_resolved_file' ), 10, 2 );
	}

	/**
	 * Route protected files through the delivery endpoint.
	 *
	 * @param string $url     File URL.
	 * @param int    $post_id Document ID.
	 * @param string $code    Language code.
	 * @return string
	 */
	public function filter_file_url( $url, $post_id, $code ) {
		if ( ! self::is_protected( $post_id ) ) {
			return $url;
		}

		return self::get_delivery_url( $post_id, $code );
	}

	/**
	 * Whether a document is protected.
	 *
	 * @param int $post_id Document ID.
	 * @return bool
	 */
	public static function is_protected( $post_id ) {
		/**
		 * Filter whether a document requires a logged in visitor.
		 *
		 * @param bool $protected Whether the document is protected.
		 * @param int  $post_id   Document ID.
		 */
		return (bool) apply_filters( 'wppdf_is_protected', (bool) get_post_meta( $post_id, self::META, true ), $post_id );
	}

	/**
	 * Whether the current visitor may read a document.
	 *
	 * @param int $post_id Document ID.
	 * @return bool
	 */
	public static function current_user_can_read( $post_id ) {
		$allowed = is_user_logged_in();

		if ( $allowed && ! current_user_can( 'read_post', $post_id ) ) {
			$allowed = false;
		}

		/**
		 * Filter whether the current visitor may read a protected document.
		 *
		 * Use this to open documents up to a membership plugin's rules.
		 *
		 * @param bool $allowed Whether reading is allowed.
		 * @param int  $post_id Document ID.
		 */
		return (bool) apply_filters( 'wppdf_user_can_read', $allowed, $post_id );
	}

	/**
	 * Register the delivery query variable.
	 *
	 * @param array $vars Public query variables.
	 * @return array
	 */
	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = 'wppdf_lang';

		return $vars;
	}

	/**
	 * URL that serves a protected file through PHP.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $code    Language code.
	 * @return string
	 */
	public static function get_delivery_url( $post_id, $code ) {
		return add_query_arg(
			array(
				self::QUERY_VAR => (int) $post_id,
				'wppdf_lang'    => rawurlencode( $code ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Point protected documents at the delivery endpoint.
	 *
	 * @param array|null $result  Resolved file.
	 * @param int        $post_id Document ID.
	 * @return array|null
	 */
	public function filter_resolved_file( $result, $post_id ) {
		if ( ! is_array( $result ) || empty( $result['post_id'] ) ) {
			return $result;
		}

		$result['protected'] = self::is_protected( $result['post_id'] );

		return $result;
	}

	/**
	 * Serve a protected file when the endpoint is requested.
	 */
	public function maybe_serve() {
		$post_id = (int) get_query_var( self::QUERY_VAR );

		if ( ! $post_id ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, WPPDF_Post_Type::get_supported_post_types(), true ) ) {
			self::deny( 404 );
		}

		if ( self::is_protected( $post_id ) && ! self::current_user_can_read( $post_id ) ) {
			self::deny( is_user_logged_in() ? 403 : 401, $post_id );
		}

		if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post_id ) ) {
			self::deny( 404 );
		}

		$code = WPPDF_Settings::sanitize_language_code( get_query_var( 'wppdf_lang' ) );
		$raw  = $code ? WPPDF_Documents::get_raw_file( $post_id, $code ) : null;

		if ( ! $raw || empty( $raw['attachment_id'] ) ) {
			self::deny( 404 );
		}

		// A document that is no longer protected has no business being piped
		// through PHP: send the client to the file itself, so links that were
		// generated while it was protected keep working without the overhead.
		if ( ! self::is_protected( $post_id ) ) {
			$direct = wp_get_attachment_url( $raw['attachment_id'] );

			if ( $direct ) {
				wp_safe_redirect( $direct, 302 );
				exit;
			}
		}

		$path = get_attached_file( $raw['attachment_id'] );

		if ( ! $path || ! is_readable( $path ) || ! self::is_inside_uploads( $path ) ) {
			self::deny( 404 );
		}

		self::stream( $path );
	}

	/**
	 * Whether a path really sits inside the uploads directory.
	 *
	 * The path comes from the attachment record rather than the request, but
	 * this endpoint reads a file from disk and echoes it, so it verifies where
	 * that file lives before doing so.
	 *
	 * @param string $path Absolute file path.
	 * @return bool
	 */
	protected static function is_inside_uploads( $path ) {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return false;
		}

		$real = realpath( $path );
		$base = realpath( $uploads['basedir'] );

		if ( ! $real || ! $base ) {
			return false;
		}

		return 0 === strpos( $real, trailingslashit( $base ) );
	}

	/**
	 * Stop with a status code.
	 *
	 * @param int $status HTTP status.
	 */
	protected static function deny( $status, $post_id = 0 ) {
		status_header( $status );
		nocache_headers();

		if ( 401 === $status ) {
			$login = wp_login_url( $post_id ? get_permalink( $post_id ) : home_url( '/' ) );

			wp_die(
				sprintf(
					'%s <a href="%s">%s</a>',
					esc_html__( 'This document is only available to logged in users.', 'wp-pdf-reader' ),
					esc_url( $login ),
					esc_html__( 'Sign in', 'wp-pdf-reader' )
				),
				esc_html__( 'Sign in required', 'wp-pdf-reader' ),
				array( 'response' => 401 )
			);
		}

		wp_die(
			esc_html__( 'This document is not available.', 'wp-pdf-reader' ),
			esc_html__( 'Not available', 'wp-pdf-reader' ),
			array( 'response' => $status )
		);
	}

	/**
	 * Send the file, honouring range requests.
	 *
	 * PDF.js asks for byte ranges so it can show the first page before the
	 * whole file arrives; answering them keeps large documents responsive.
	 *
	 * @param string $path Absolute file path.
	 */
	protected static function stream( $path ) {
		$size = (int) filesize( $path );
		$name = basename( $path );

		$start = 0;
		$end   = $size - 1;

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . rawurlencode( $name ) . '"' );
		header( 'Accept-Ranges: bytes' );
		header( 'X-Content-Type-Options: nosniff' );

		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';

		if ( '' !== $range && preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $matches ) ) {
			$from = '' === $matches[1] ? null : (int) $matches[1];
			$to   = '' === $matches[2] ? null : (int) $matches[2];

			if ( null === $from && null !== $to ) {
				// Suffix range: the last N bytes.
				$start = max( 0, $size - $to );
			} elseif ( null !== $from ) {
				$start = $from;
				$end   = null === $to ? $end : min( $to, $end );
			}

			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}

			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}

		$length = $end - $start + 1;
		header( 'Content-Length: ' . $length );

		if ( function_exists( 'wp_ob_end_flush_all' ) ) {
			wp_ob_end_flush_all();
		}

		// Sending a large file to a slow client must not trip the time limit.
		if ( function_exists( 'set_time_limit' ) && ! ini_get( 'safe_mode' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors -- disabled on some hosts.
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a local file.

		if ( ! $handle ) {
			self::deny( 404 );
		}

		if ( $start > 0 ) {
			fseek( $handle, $start );
		}

		$remaining = $length;
		$chunk     = 256 * KB_IN_BYTES;

		while ( $remaining > 0 && ! feof( $handle ) ) {
			$buffer = fread( $handle, (int) min( $chunk, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- streaming a local file.

			if ( false === $buffer ) {
				break;
			}

			echo $buffer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file contents.
			flush();

			$remaining -= strlen( $buffer );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a local file.
		exit;
	}

	/**
	 * Absolute path of the protected uploads directory, creating it if needed.
	 *
	 * @return string Empty string when it could not be prepared.
	 */
	public static function get_directory() {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		$directory = trailingslashit( $uploads['basedir'] ) . self::DIRECTORY;

		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return '';
		}

		self::write_guards( $directory );

		return $directory;
	}

	/**
	 * Drop the files that stop a web server from serving the directory.
	 *
	 * @param string $directory Absolute directory path.
	 */
	protected static function write_guards( $directory ) {
		$htaccess = trailingslashit( $directory ) . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Added by WP PDF Reader. Files here are served through PHP only.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- plain guard file.
		}

		$index = trailingslashit( $directory ) . 'index.php';

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- plain guard file.
		}
	}

	/**
	 * Whether the web server is one the deny file works on.
	 *
	 * @return bool
	 */
	public static function guards_are_effective() {
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';

		return false !== strpos( $server, 'apache' ) || false !== strpos( $server, 'litespeed' );
	}

	/**
	 * Move a document's files in or out of the protected directory.
	 *
	 * @param int  $post_id   Document ID.
	 * @param bool $protected Target state.
	 * @return int Number of files moved.
	 */
	public static function apply( $post_id, $protected ) {
		$moved = 0;

		foreach ( WPPDF_Languages::get_codes() as $code ) {
			$attachment_id = absint( get_post_meta( $post_id, WPPDF_Languages::file_meta_key( $code ), true ) );

			if ( ! $attachment_id ) {
				continue;
			}

			if ( self::move_attachment( $attachment_id, $protected ) ) {
				$moved++;
			}
		}

		return $moved;
	}

	/**
	 * Move one attachment between the normal and the protected directory.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $protected     Whether the file should be protected.
	 * @return bool Whether the file was moved.
	 */
	protected static function move_attachment( $attachment_id, $protected ) {
		$path = get_attached_file( $attachment_id );

		if ( ! $path || ! file_exists( $path ) ) {
			return false;
		}

		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return false;
		}

		$basedir     = trailingslashit( $uploads['basedir'] );
		$is_protected = false !== strpos( $path, $basedir . self::DIRECTORY . '/' );

		if ( $protected === $is_protected ) {
			return false;
		}

		if ( $protected ) {
			$directory = self::get_directory();

			if ( '' === $directory ) {
				return false;
			}

			$target = trailingslashit( $directory ) . wp_unique_filename( $directory, basename( $path ) );
		} else {
			$target_dir = trailingslashit( $uploads['basedir'] ) . trim( (string) $uploads['subdir'], '/' );

			if ( ! is_dir( $target_dir ) && ! wp_mkdir_p( $target_dir ) ) {
				return false;
			}

			$target = trailingslashit( $target_dir ) . wp_unique_filename( $target_dir, basename( $path ) );
		}

		if ( ! @rename( $path, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is handled.
			return false;
		}

		self::move_generated_sizes( $attachment_id, dirname( $path ), dirname( $target ) );

		update_attached_file( $attachment_id, $target );

		return true;
	}

	/**
	 * Move the preview images WordPress renders for a PDF.
	 *
	 * WordPress rasterises the first page of an uploaded PDF into ordinary
	 * JPEGs next to it. Leaving those behind would publish a readable image of
	 * page one of a document that was just locked down. Their names live in the
	 * attachment metadata and are resolved relative to the main file, so moving
	 * the files is enough — the metadata stays valid.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $from          Directory the file came from.
	 * @param string $to            Directory the file moved to.
	 */
	protected static function move_generated_sizes( $attachment_id, $from, $to ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return;
		}

		foreach ( $metadata['sizes'] as $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}

			$name = basename( (string) $size['file'] );
			$old  = trailingslashit( $from ) . $name;
			$new  = trailingslashit( $to ) . $name;

			if ( ! file_exists( $old ) || file_exists( $new ) ) {
				continue;
			}

			@rename( $old, $new ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a preview that cannot move is deleted below.

			if ( file_exists( $old ) && ! file_exists( $new ) ) {
				// Better to lose a thumbnail than to leave a readable page
				// behind in a public directory.
				wp_delete_file( $old );
			}
		}
	}
}

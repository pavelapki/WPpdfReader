<?php
/**
 * Text extraction from PDF files.
 *
 * The extracted text feeds the WordPress search index and the page count is
 * shown in listings. Extraction runs on a scheduled event rather than during
 * the save request, so uploading a large PDF never blocks the editor.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * PDF text and metadata extraction.
 */
class WPPDF_Text {

	/**
	 * Meta key prefix for the extracted text of a language.
	 */
	const META_TEXT = '_wppdf_text_';

	/**
	 * Meta key prefix for the page count of a language.
	 */
	const META_PAGES = '_wppdf_pagecount_';

	/**
	 * Scheduled event name.
	 */
	const EVENT = 'wppdf_extract_text';

	/**
	 * Transient caching whether the poppler binaries are usable.
	 */
	const TRANSIENT_BINARY = 'wppdf_pdftotext';

	/**
	 * Hard cap on the characters stored per language.
	 */
	const MAX_CHARS = 200000;

	/**
	 * Files larger than this are not parsed in PHP.
	 */
	const MAX_BYTES = 62914560; // 60 MB.

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( self::EVENT, array( __CLASS__, 'run' ), 10, 3 );
	}

	/**
	 * Meta key holding the extracted text of a language.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function text_meta_key( $code ) {
		return self::META_TEXT . str_replace( '-', '_', WPPDF_Settings::sanitize_language_code( $code ) );
	}

	/**
	 * Meta key holding the page count of a language.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function pages_meta_key( $code ) {
		return self::META_PAGES . str_replace( '-', '_', WPPDF_Settings::sanitize_language_code( $code ) );
	}

	/**
	 * Queue extraction for a file.
	 *
	 * @param int    $post_id       Document ID.
	 * @param string $code          Language code.
	 * @param int    $attachment_id Attachment ID.
	 */
	public static function schedule( $post_id, $code, $attachment_id ) {
		$args = array( (int) $post_id, (string) $code, (int) $attachment_id );

		if ( wp_next_scheduled( self::EVENT, $args ) ) {
			return;
		}

		wp_schedule_single_event( time() + 10, self::EVENT, $args );
	}

	/**
	 * Drop what was extracted for a language.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $code    Language code.
	 */
	public static function clear( $post_id, $code ) {
		delete_post_meta( $post_id, self::text_meta_key( $code ) );
		delete_post_meta( $post_id, self::pages_meta_key( $code ) );
	}

	/**
	 * Scheduled callback: extract and store text plus page count.
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

		// The file may have been swapped again between scheduling and running.
		$current = absint( get_post_meta( $post_id, WPPDF_Languages::file_meta_key( $code ), true ) );

		if ( ! $current || $current !== absint( $attachment_id ) ) {
			return;
		}

		$path = get_attached_file( $current );

		if ( ! $path || ! is_readable( $path ) ) {
			return;
		}

		$pages = self::count_pages( $path );

		if ( $pages > 0 ) {
			update_post_meta( $post_id, self::pages_meta_key( $code ), $pages );
		}

		if ( ! WPPDF_Settings::get( 'extract_text' ) ) {
			return;
		}

		$text = self::extract( $path );

		if ( '' !== $text ) {
			update_post_meta( $post_id, self::text_meta_key( $code ), $text );
		} else {
			delete_post_meta( $post_id, self::text_meta_key( $code ) );
		}

		/**
		 * Fires after a document's text was extracted.
		 *
		 * @param int    $post_id Document ID.
		 * @param string $code    Language code.
		 * @param string $text    Extracted text, empty when nothing was found.
		 */
		do_action( 'wppdf_text_extracted', $post_id, $code, $text );
	}

	/**
	 * Extract the text of a PDF.
	 *
	 * Prefers poppler's pdftotext and falls back to a small PHP parser, which
	 * handles the common case of uncompressed or Flate compressed content
	 * streams with standard encodings.
	 *
	 * @param string $path Absolute file path.
	 * @return string Normalised text, empty when nothing usable was found.
	 */
	public static function extract( $path ) {
		$text = self::extract_with_binary( $path );

		if ( '' === $text ) {
			$text = self::extract_with_php( $path );
		}

		return self::normalise( $text );
	}

	/**
	 * Run pdftotext when the host allows it.
	 *
	 * @param string $path Absolute file path.
	 * @return string
	 */
	protected static function extract_with_binary( $path ) {
		$binary = self::binary( 'pdftotext' );

		if ( '' === $binary ) {
			return '';
		}

		$command = sprintf(
			'%s -q -enc UTF-8 -eol unix -nopgbrk %s -',
			escapeshellcmd( $binary ),
			escapeshellarg( $path )
		);

		$output = self::shell( $command );

		return null === $output ? '' : $output;
	}

	/**
	 * Count pages, preferring pdfinfo and falling back to the page tree.
	 *
	 * @param string $path Absolute file path.
	 * @return int
	 */
	public static function count_pages( $path ) {
		$binary = self::binary( 'pdfinfo' );

		if ( '' !== $binary ) {
			$output = self::shell( sprintf( '%s %s', escapeshellcmd( $binary ), escapeshellarg( $path ) ) );

			if ( null !== $output && preg_match( '/^Pages:\s+(\d+)/m', $output, $matches ) ) {
				return (int) $matches[1];
			}
		}

		$raw = self::read( $path );

		if ( '' === $raw ) {
			return 0;
		}

		// Linearised files declare the count up front; otherwise count the
		// page objects themselves.
		if ( preg_match( '/\/Type\s*\/Pages\b[^>]*?\/Count\s+(\d+)/s', $raw, $matches ) ) {
			return (int) $matches[1];
		}

		return (int) preg_match_all( '/\/Type\s*\/Page\b(?!s)/', $raw );
	}

	/**
	 * Locate a poppler binary, caching the result.
	 *
	 * @param string $name Binary name.
	 * @return string Absolute path or an empty string.
	 */
	protected static function binary( $name ) {
		$name = preg_replace( '/[^a-z]/', '', (string) $name );

		if ( '' === $name ) {
			return '';
		}

		$cached = get_transient( self::TRANSIENT_BINARY . '_' . $name );

		if ( false !== $cached ) {
			return (string) $cached;
		}

		$path = '';

		if ( self::can_shell() ) {
			$found = self::shell( 'command -v ' . escapeshellarg( $name ) );

			if ( null !== $found ) {
				$found = trim( strtok( $found, "\n" ) );

				if ( '' !== $found && is_string( $found ) && 0 === strpos( $found, '/' ) && @is_executable( $found ) ) {
					$path = $found;
				}
			}
		}

		/**
		 * Filter the path to a poppler binary.
		 *
		 * Set it explicitly when the binary lives outside the web user's PATH.
		 *
		 * @param string $path Absolute path, empty when unavailable.
		 * @param string $name Binary name.
		 */
		$path = (string) apply_filters( 'wppdf_binary_path', $path, $name );

		set_transient( self::TRANSIENT_BINARY . '_' . $name, $path, DAY_IN_SECONDS );

		return $path;
	}

	/**
	 * Whether poppler's pdftotext can be used on this host.
	 *
	 * @return bool
	 */
	public static function binary_available() {
		return '' !== self::binary( 'pdftotext' );
	}

	/**
	 * Whether shell commands can be run at all on this host.
	 *
	 * @return bool
	 */
	protected static function can_shell() {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		return ! in_array( 'proc_open', $disabled, true );
	}

	/**
	 * Run a command and return its standard output.
	 *
	 * @param string $command Full command line.
	 * @return string|null Output, or null when the command could not run.
	 */
	protected static function shell( $command ) {
		if ( ! self::can_shell() ) {
			return null;
		}

		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = @proc_open( $command, $descriptors, $pipes );

		if ( ! is_resource( $process ) ) {
			return null;
		}

		$output = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$status = proc_close( $process );

		if ( 0 !== $status ) {
			return null;
		}

		return is_string( $output ) ? $output : null;
	}

	/**
	 * Read a PDF from disk, refusing files that are too big to parse in PHP.
	 *
	 * @param string $path Absolute file path.
	 * @return string
	 */
	protected static function read( $path ) {
		if ( ! is_readable( $path ) ) {
			return '';
		}

		$size = (int) filesize( $path );

		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			return '';
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.

		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Minimal PDF text extraction in pure PHP.
	 *
	 * Walks the content streams, inflating those that are Flate compressed,
	 * and collects the operands of the text showing operators. Documents using
	 * subset fonts with custom encodings produce garbage, which the sanity
	 * check at the end throws away rather than poisoning the search index.
	 *
	 * @param string $path Absolute file path.
	 * @return string
	 */
	protected static function extract_with_php( $path ) {
		$raw = self::read( $path );

		if ( '' === $raw ) {
			return '';
		}

		if ( ! preg_match_all( '/stream\r?\n?(.*?)endstream/s', $raw, $streams ) ) {
			return '';
		}

		$text   = '';
		$chunks = 0;

		foreach ( $streams[1] as $stream ) {
			if ( strlen( $text ) > self::MAX_CHARS || $chunks > 5000 ) {
				break;
			}

			$chunks++;

			$content = @gzuncompress( $stream );

			if ( false === $content ) {
				$content = @gzinflate( substr( $stream, 2 ) );
			}

			if ( false === $content ) {
				// Uncompressed streams are usable as they are; anything else
				// (images, fonts) simply will not match the text operators.
				$content = $stream;
			}

			if ( ! is_string( $content ) || false === strpos( $content, 'T' ) ) {
				continue;
			}

			$text .= self::text_from_content_stream( $content );
		}

		return $text;
	}

	/**
	 * Pull the shown strings out of one content stream.
	 *
	 * @param string $content Decoded content stream.
	 * @return string
	 */
	protected static function text_from_content_stream( $content ) {
		$out = '';

		// Tj / ' / " take a single string, TJ takes an array of them.
		if ( ! preg_match_all( '/(?:\[(.*?)\]\s*TJ)|(?:\(((?:\\\\.|[^\\\\()])*)\)\s*(?:Tj|\'|"))/s', $content, $matches, PREG_SET_ORDER ) ) {
			return '';
		}

		foreach ( $matches as $match ) {
			if ( isset( $match[2] ) && '' !== $match[2] ) {
				$out .= self::decode_pdf_string( $match[2] ) . ' ';
				continue;
			}

			if ( ! isset( $match[1] ) || '' === $match[1] ) {
				continue;
			}

			if ( preg_match_all( '/\((?:\\\\.|[^\\\\()])*\)/s', $match[1], $parts ) ) {
				foreach ( $parts[0] as $part ) {
					$out .= self::decode_pdf_string( substr( $part, 1, -1 ) );
				}
			}

			$out .= ' ';
		}

		return $out;
	}

	/**
	 * Resolve the escape sequences of a PDF literal string.
	 *
	 * @param string $string Raw string without its parentheses.
	 * @return string
	 */
	protected static function decode_pdf_string( $string ) {
		$replacements = array(
			'\\n'  => "\n",
			'\\r'  => "\r",
			'\\t'  => "\t",
			'\\b'  => "\x08",
			'\\f'  => "\x0C",
			'\\('  => '(',
			'\\)'  => ')',
			'\\\\' => '\\',
		);

		$string = strtr( $string, $replacements );

		return preg_replace_callback(
			'/\\\\([0-7]{1,3})/',
			static function ( $matches ) {
				return chr( octdec( $matches[1] ) );
			},
			$string
		);
	}

	/**
	 * Collapse whitespace, drop control characters and reject garbage.
	 *
	 * @param string $text Raw extracted text.
	 * @return string
	 */
	protected static function normalise( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}

		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text );

		if ( ! seems_utf8( $text ) ) {
			$text = wp_check_invalid_utf8( $text, true );
		}

		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return '';
		}

		if ( ! self::looks_like_text( $text ) ) {
			return '';
		}

		if ( strlen( $text ) > self::MAX_CHARS ) {
			$text = substr( $text, 0, self::MAX_CHARS );
			// Do not cut a multibyte character in half.
			$text = (string) preg_replace( '/[\x80-\xBF]+$/', '', $text );
		}

		return $text;
	}

	/**
	 * Heuristic guard against indexing mojibake from custom font encodings.
	 *
	 * @param string $text Normalised text.
	 * @return bool
	 */
	protected static function looks_like_text( $text ) {
		$sample = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 2000 ) : substr( $text, 0, 2000 );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $sample ) : strlen( $sample );

		if ( $length < 12 ) {
			return false;
		}

		$letters = preg_match_all( '/[\p{L}\p{N}\s.,;:!?()\-\'"]/u', $sample );

		/**
		 * Filter the share of plausible characters required to index the text.
		 *
		 * @param float $ratio Between 0 and 1.
		 */
		$ratio = (float) apply_filters( 'wppdf_text_quality_ratio', 0.75 );

		return ( $letters / $length ) >= $ratio;
	}

	/**
	 * Page count stored for a document, using the resolved language first.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $code    Language code.
	 * @return int
	 */
	public static function get_page_count( $post_id, $code = '' ) {
		if ( '' !== $code ) {
			return (int) get_post_meta( $post_id, self::pages_meta_key( $code ), true );
		}

		$file = WPPDF_Documents::get_file( $post_id );

		if ( ! $file ) {
			return 0;
		}

		return (int) get_post_meta( $post_id, self::pages_meta_key( $file['lang'] ), true );
	}
}

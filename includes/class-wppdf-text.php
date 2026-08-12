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
	 *
	 * pdftotext streams the file and has no such limit; this only bounds the
	 * in-process fallback, which has to hold the document in memory.
	 */
	const MAX_BYTES = 20971520; // 20 MB.

	/**
	 * Last file read, so page counting and extraction do not read it twice.
	 *
	 * @var array
	 */
	protected static $last_read = array(
		'path' => '',
		'raw'  => '',
	);

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
		self::forget_file();

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
		$text = self::normalise( self::extract_with_binary( $path ) );

		if ( '' === $text ) {
			$text = self::normalise( self::extract_with_php( $path ) );
		}

		// A scan has no text layer at all, so nothing above finds anything.
		if ( '' === $text ) {
			$text = self::normalise( self::extract_with_ocr( $path ) );
		}

		return $text;
	}

	/**
	 * Whether this server can OCR scanned documents.
	 *
	 * @return bool
	 */
	public static function ocr_available() {
		return '' !== self::binary( 'pdftoppm' ) && '' !== self::binary( 'tesseract' );
	}

	/**
	 * Read a scanned document by rendering its pages and running OCR.
	 *
	 * Expensive by nature, so it only runs when the document has no text layer
	 * whatsoever, is capped to a number of pages and happens on the scheduled
	 * event rather than in a page request.
	 *
	 * @param string $path Absolute file path.
	 * @return string
	 */
	protected static function extract_with_ocr( $path ) {
		if ( ! WPPDF_Settings::get( 'ocr_enabled' ) || ! self::ocr_available() ) {
			return '';
		}

		/**
		 * Filter how many pages of a scan are read.
		 *
		 * @param int $pages Maximum pages.
		 */
		$max_pages = (int) apply_filters( 'wppdf_ocr_max_pages', max( 1, (int) WPPDF_Settings::get( 'ocr_max_pages' ) ) );

		$languages = (string) WPPDF_Settings::get( 'ocr_languages' );
		$languages = preg_replace( '/[^a-zA-Z+_]/', '', $languages );

		if ( '' === $languages ) {
			$languages = 'eng';
		}

		$directory = self::temp_directory();

		if ( '' === $directory ) {
			return '';
		}

		$prefix = trailingslashit( $directory ) . 'wppdf-ocr-' . wp_generate_password( 8, false, false );
		$text   = '';

		// One page image at a time keeps the peak disk usage to a single page.
		for ( $page = 1; $page <= $max_pages; $page++ ) {
			$rendered = self::run_binary(
				array(
					self::binary( 'pdftoppm' ),
					'-r',
					'200',
					'-f',
					(string) $page,
					'-l',
					(string) $page,
					'-png',
					'-singlefile',
					$path,
					$prefix,
				)
			);

			$image = $prefix . '.png';

			if ( null === $rendered || ! file_exists( $image ) ) {
				break;
			}

			$page_text = self::run_binary(
				array(
					self::binary( 'tesseract' ),
					$image,
					'stdout',
					'-l',
					$languages,
				)
			);

			wp_delete_file( $image );

			if ( is_string( $page_text ) && '' !== trim( $page_text ) ) {
				$text .= ' ' . $page_text;
			}

			if ( strlen( $text ) >= self::MAX_CHARS ) {
				break;
			}
		}

		if ( '' !== trim( $text ) ) {
			/**
			 * Fires when a document was read with OCR.
			 *
			 * @param string $path Absolute file path.
			 */
			do_action( 'wppdf_ocr_used', $path );
		}

		return $text;
	}

	/**
	 * A writable directory for the intermediate page images.
	 *
	 * @return string
	 */
	protected static function temp_directory() {
		$directory = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();

		return is_string( $directory ) && is_writable( $directory ) ? $directory : '';
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

		$output = self::run_binary( array( $binary, '-q', '-enc', 'UTF-8', '-eol', 'unix', '-nopgbrk', $path, '-' ) );

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
			$output = self::run_binary( array( $binary, $path ) );

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

		if ( self::can_run() ) {
			foreach ( self::search_paths() as $directory ) {
				$candidate = rtrim( $directory, '/' ) . '/' . $name;

				if ( @is_file( $candidate ) && @is_executable( $candidate ) ) {
					$path = $candidate;
					break;
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
	 * Directories searched for the poppler binaries.
	 *
	 * @return string[]
	 */
	protected static function search_paths() {
		$paths = array( '/usr/bin', '/usr/local/bin', '/opt/homebrew/bin', '/bin' );

		$env = getenv( 'PATH' );

		if ( is_string( $env ) && '' !== $env ) {
			foreach ( explode( PATH_SEPARATOR, $env ) as $directory ) {
				if ( '' !== $directory && 0 === strpos( $directory, '/' ) ) {
					$paths[] = $directory;
				}
			}
		}

		return array_unique( $paths );
	}

	/**
	 * Whether external binaries can be run at all on this host.
	 *
	 * @return bool
	 */
	protected static function can_run() {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		return ! in_array( 'proc_open', $disabled, true );
	}

	/**
	 * Run a binary and return its standard output.
	 *
	 * The command is passed as an array, so PHP executes the binary directly
	 * instead of handing a string to a shell. There is no shell to inject
	 * into, whatever a file name happens to contain.
	 *
	 * @param string[] $command Binary path followed by its arguments.
	 * @return string|null Output, or null when the command could not run.
	 */
	protected static function run_binary( array $command ) {
		if ( ! self::can_run() || empty( $command[0] ) ) {
			return null;
		}

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = @proc_open( array_values( array_map( 'strval', $command ) ), $descriptors, $pipes );

		if ( ! is_resource( $process ) ) {
			return null;
		}

		fclose( $pipes[0] );

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
		if ( self::$last_read['path'] === $path ) {
			return self::$last_read['raw'];
		}

		$raw = '';

		if ( is_readable( $path ) ) {
			$size = (int) filesize( $path );

			if ( $size > 0 && $size <= self::MAX_BYTES ) {
				$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
				$raw      = is_string( $contents ) ? $contents : '';
			}
		}

		self::$last_read = array(
			'path' => $path,
			'raw'  => $raw,
		);

		return $raw;
	}

	/**
	 * Release the cached file contents.
	 */
	public static function forget_file() {
		self::$last_read = array(
			'path' => '',
			'raw'  => '',
		);
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

		$text   = '';
		$chunks = 0;
		$offset = 0;
		$length = strlen( $raw );

		// Walked one stream at a time on purpose: capturing every stream of a
		// large document at once would hold a second copy of the file in
		// memory, on top of the file itself.
		while ( $offset < $length && $chunks < 5000 && strlen( $text ) < self::MAX_CHARS ) {
			$start = strpos( $raw, 'stream', $offset );

			if ( false === $start ) {
				break;
			}

			$from = $start + 6;

			// Skip the end of line that follows the keyword.
			if ( "\r" === substr( $raw, $from, 1 ) ) {
				$from++;
			}
			if ( "\n" === substr( $raw, $from, 1 ) ) {
				$from++;
			}

			$end = strpos( $raw, 'endstream', $from );

			if ( false === $end ) {
				break;
			}

			$offset = $end + 9;
			$chunks++;

			$stream = substr( $raw, $from, $end - $from );

			if ( '' === $stream ) {
				continue;
			}

			$content = @gzuncompress( $stream );

			if ( false === $content ) {
				$content = @gzinflate( substr( $stream, 2 ) );
			}

			if ( false === $content ) {
				// Uncompressed streams are usable as they are; anything else
				// (images, fonts) simply will not match the text operators.
				$content = $stream;
			}

			unset( $stream );

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

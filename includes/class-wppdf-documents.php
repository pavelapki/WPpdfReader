<?php
/**
 * Reading document data: per-language files and the fallback resolution.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Document data access.
 */
class WPPDF_Documents {

	/**
	 * Meta key prefix for an external (non media library) file URL.
	 */
	const META_URL = '_wppdf_url_';

	/**
	 * Resolved files, keyed by post + requested language.
	 *
	 * @var array
	 */
	protected static $resolved = array();

	/**
	 * Drop the resolved-file cache, e.g. after files were saved.
	 */
	public static function flush_cache() {
		self::$resolved = array();
	}

	/**
	 * Meta key for an external URL.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function url_meta_key( $code ) {
		return self::META_URL . str_replace( '-', '_', WPPDF_Settings::sanitize_language_code( $code ) );
	}

	/**
	 * The raw file stored on a post for one language, without any fallback.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $code    Language code.
	 * @return array|null Array with attachment_id/url, or null when empty.
	 */
	public static function get_raw_file( $post_id, $code ) {
		$post_id = absint( $post_id );
		$code    = WPPDF_Settings::sanitize_language_code( $code );

		if ( ! $post_id || '' === $code ) {
			return null;
		}

		$attachment_id = absint( get_post_meta( $post_id, WPPDF_Languages::file_meta_key( $code ), true ) );

		if ( $attachment_id && self::is_valid_attachment( $attachment_id ) ) {
			$url = wp_get_attachment_url( $attachment_id );
			if ( $url ) {
				return array(
					'attachment_id' => $attachment_id,
					/**
					 * Filter the URL a document file is served from.
					 *
					 * Protected documents route through a PHP endpoint here,
					 * so no caller ever hands out the direct path.
					 *
					 * @param string $url           File URL.
					 * @param int    $post_id       Document ID.
					 * @param string $code          Language code.
					 * @param int    $attachment_id Attachment ID.
					 */
					'url'           => apply_filters( 'wppdf_file_url', $url, $post_id, $code, $attachment_id ),
				);
			}
		}

		$external = get_post_meta( $post_id, self::url_meta_key( $code ), true );
		if ( is_string( $external ) && '' !== trim( $external ) ) {
			$external = esc_url_raw( trim( $external ) );
			if ( $external ) {
				return array(
					'attachment_id' => 0,
					'url'           => $external,
				);
			}
		}

		return null;
	}

	/**
	 * Whether an attachment exists and looks like a PDF.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_valid_attachment( $attachment_id ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return false;
		}

		$mime = get_post_mime_type( $attachment );

		/**
		 * Filter the mime types accepted as a document file.
		 *
		 * @param string[] $mimes Allowed mime types.
		 */
		$allowed = apply_filters( 'wppdf_allowed_mime_types', array( 'application/pdf' ) );

		return in_array( $mime, (array) $allowed, true );
	}

	/**
	 * Language codes that have a file directly on this post.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public static function get_available_languages( $post_id ) {
		$available = array();

		foreach ( WPPDF_Languages::get_codes() as $code ) {
			if ( self::get_raw_file( $post_id, $code ) ) {
				$available[] = $code;
			}
		}

		return $available;
	}

	/**
	 * Resolve the file to show for a post, applying the fallback chain.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $requested Requested language code. Defaults to the current language.
	 * @return array|null {
	 *     @type int    $post_id       Post the file was taken from.
	 *     @type int    $attachment_id Attachment ID, 0 for an external URL.
	 *     @type string $url           File URL.
	 *     @type string $lang          Language the file belongs to.
	 *     @type string $requested     Language originally asked for.
	 *     @type bool   $is_fallback   Whether a fallback language was used.
	 *     @type string $language_label Label of the resolved language.
	 *     @type string $filename      File name.
	 *     @type int    $filesize      Size in bytes, 0 when unknown.
	 *     @type int    $cover_id      Cover attachment ID, 0 when none.
	 * }
	 */
	public static function get_file( $post_id, $requested = '' ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return null;
		}

		$requested = WPPDF_Settings::sanitize_language_code( $requested );
		if ( '' === $requested ) {
			$requested = WPPDF_Languages::get_current_language();
		}

		$cache_key = $post_id . '|' . $requested;
		if ( isset( self::$resolved[ $cache_key ] ) ) {
			return self::$resolved[ $cache_key ];
		}

		$order  = WPPDF_Languages::get_fallback_order( $requested );
		$result = null;

		// 1) The post's own per-language fields.
		foreach ( $order as $code ) {
			$raw = self::get_raw_file( $post_id, $code );
			if ( $raw ) {
				$result = self::build_result( $post_id, $code, $requested, $raw );
				break;
			}
		}

		// 2) WPML/Polylang siblings, when the document itself is translated.
		if ( null === $result && WPPDF_Settings::get( 'sync_with_wpml' ) ) {
			foreach ( $order as $code ) {
				$sibling = self::get_translated_post_id( $post_id, $code );
				if ( ! $sibling || $sibling === $post_id ) {
					continue;
				}

				foreach ( $order as $sibling_code ) {
					$raw = self::get_raw_file( $sibling, $sibling_code );
					if ( $raw ) {
						$result = self::build_result( $sibling, $sibling_code, $requested, $raw );
						break 2;
					}
				}
			}
		}

		/**
		 * Filter the resolved document file.
		 *
		 * @param array|null $result    Resolved file data.
		 * @param int        $post_id   Post ID.
		 * @param string     $requested Requested language code.
		 */
		$result = apply_filters( 'wppdf_resolved_file', $result, $post_id, $requested );

		self::$resolved[ $cache_key ] = $result;

		return $result;
	}

	/**
	 * Assemble the resolved file payload.
	 *
	 * @param int    $post_id   Post the file lives on.
	 * @param string $code      Resolved language code.
	 * @param string $requested Requested language code.
	 * @param array  $raw       Raw file data.
	 * @return array
	 */
	protected static function build_result( $post_id, $code, $requested, array $raw ) {
		$filename = '';
		$filesize = 0;

		if ( ! empty( $raw['attachment_id'] ) ) {
			$path = get_attached_file( $raw['attachment_id'] );
			if ( $path && file_exists( $path ) ) {
				$filename = basename( $path );
				$filesize = (int) filesize( $path );
			}
		}

		if ( '' === $filename ) {
			$filename = basename( wp_parse_url( $raw['url'], PHP_URL_PATH ) );
		}

		return array(
			'post_id'        => (int) $post_id,
			'attachment_id'  => (int) $raw['attachment_id'],
			'url'            => $raw['url'],
			'lang'           => $code,
			'requested'      => $requested,
			'is_fallback'    => $code !== $requested,
			'language_label' => WPPDF_Languages::get_label( $code ),
			'filename'       => $filename,
			'filesize'       => $filesize,
			'cover_id'       => (int) get_post_meta( $post_id, WPPDF_Languages::cover_meta_key( $code ), true ),
		);
	}

	/**
	 * Translated post ID through WPML or Polylang.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $code    Language code.
	 * @return int
	 */
	protected static function get_translated_post_id( $post_id, $code ) {
		$post_type = get_post_type( $post_id );

		if ( has_filter( 'wpml_object_id' ) ) {
			$translated = apply_filters( 'wpml_object_id', $post_id, $post_type, false, $code );
			if ( $translated ) {
				return (int) $translated;
			}
		}

		if ( function_exists( 'pll_get_post' ) ) {
			$translated = pll_get_post( $post_id, $code );
			if ( $translated ) {
				return (int) $translated;
			}
		}

		return 0;
	}

	/**
	 * Cover image ID for a document, falling back to the featured image.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $code    Language code, defaults to the resolved one.
	 * @return int
	 */
	public static function get_cover_id( $post_id, $code = '' ) {
		if ( has_post_thumbnail( $post_id ) ) {
			return (int) get_post_thumbnail_id( $post_id );
		}

		if ( '' === $code ) {
			$file = self::get_file( $post_id );
			$code = $file ? $file['lang'] : WPPDF_Languages::get_default_language();
		}

		$cover = (int) get_post_meta( $post_id, WPPDF_Languages::cover_meta_key( $code ), true );
		if ( $cover && get_post( $cover ) ) {
			return $cover;
		}

		// Any generated cover is better than none.
		foreach ( WPPDF_Languages::get_codes() as $candidate ) {
			$cover = (int) get_post_meta( $post_id, WPPDF_Languages::cover_meta_key( $candidate ), true );
			if ( $cover && get_post( $cover ) ) {
				return $cover;
			}
		}

		return 0;
	}

	/**
	 * Query documents.
	 *
	 * @param array $args Overrides for WP_Query.
	 * @return WP_Query
	 */
	public static function query( array $args = array() ) {
		$defaults = array(
			'post_type'           => WPPDF_Post_Type::get_key(),
			'post_status'         => 'publish',
			'posts_per_page'      => (int) WPPDF_Settings::get( 'archive_per_page' ),
			'ignore_sticky_posts' => true,
		);

		$args = wp_parse_args( $args, $defaults );

		/**
		 * Filter the document query arguments.
		 *
		 * @param array $args Query arguments.
		 */
		$args = apply_filters( 'wppdf_query_args', $args );

		return new WP_Query( $args );
	}

	/**
	 * Human readable file size.
	 *
	 * @param int $bytes Size in bytes.
	 * @return string
	 */
	public static function format_filesize( $bytes ) {
		$bytes = (int) $bytes;

		if ( $bytes <= 0 ) {
			return '';
		}

		return size_format( $bytes, $bytes >= MB_IN_BYTES ? 1 : 0 );
	}
}

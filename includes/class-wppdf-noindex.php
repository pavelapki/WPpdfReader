<?php
/**
 * Keeping documents out of search engines and AI crawlers.
 *
 * This is deliberately a different thing from WPPDF_Protection. Protection is
 * a lock: the file leaves the public uploads tree and PHP checks a capability
 * before streaming a byte. What is here is a set of *requests* — a robots meta
 * tag, an X-Robots-Tag header, robots.txt rules — and requests are honoured
 * only by crawlers that choose to. Google, Bing, GPTBot and ClaudeBot do; a
 * scraper written to take the content does not, and nothing short of
 * WPPDF_Protection will stop it.
 *
 * Say that out loud in the settings screen too, so nobody ticks these boxes
 * and believes the library is now private.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Indexation rules for documents and other selected content.
 */
class WPPDF_Noindex {

	/**
	 * Meta key holding the per-post exclusion flag.
	 */
	const META = '_wppdf_noindex';

	/**
	 * Marker used for the block written into the uploads .htaccess.
	 */
	const MARKER = 'WP PDF Reader';

	/**
	 * The robots directives sent for excluded content.
	 *
	 * `noindex` drops the page, `noimageindex` the cover, and `noarchive` and
	 * `nosnippet` stop the text from surviving in a cached copy or in a search
	 * result snippet after the page itself is gone.
	 */
	const DIRECTIVES = 'noindex, nofollow, noimageindex, noarchive, nosnippet';

	/**
	 * Tokens that say "do not mine this for AI", as opposed to "do not index".
	 *
	 * `noai` and `noimageai` are a convention rather than a standard — no
	 * registry defines them and unknown tokens are simply ignored, which is why
	 * they cost nothing to send. The TDM reservation below is the one that has
	 * teeth.
	 */
	const AI_DIRECTIVES = 'noai, noimageai';

	/**
	 * Option remembering what the generated files were last written for.
	 */
	const STATE_OPTION = 'wppdf_noindex_state';

	/**
	 * Memoised list of posts published inside an excluded post type.
	 *
	 * @var int[]|null
	 */
	protected static $allowed_cache = null;

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'template_redirect', array( $this, 'send_header' ) );
		add_action( 'wp_head', array( $this, 'render_tdm_meta' ), 1 );
		add_filter( 'wp_robots', array( $this, 'filter_robots' ) );
		add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 10, 2 );

		// Speak to the SEO plugins in their own meta keys rather than guessing
		// at their filter names: a post they read as noindex also drops out of
		// the sitemap they generate, which is where the crawler starts.
		add_filter( 'get_post_metadata', array( $this, 'filter_seo_meta' ), 10, 4 );

		// Core sitemaps.
		add_filter( 'wp_sitemaps_post_types', array( $this, 'filter_sitemap_post_types' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'filter_sitemap_query_args' ), 10, 2 );

		// Per-post switch.
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );

		// The uploads rule and the reservation are files, so on a fresh install
		// or after an update they do not exist yet — and nobody should have to
		// open the settings screen and press Save to get the protection the
		// defaults already say is on.
		add_action( 'admin_init', array( $this, 'maybe_write_files' ) );
	}

	/**
	 * Write the generated files when they do not match the settings.
	 *
	 * Cheap enough to run on every admin request: one option read, and a write
	 * only when something actually changed.
	 */
	public function maybe_write_files() {
		$wanted = array(
			'files'    => (bool) WPPDF_Settings::get( 'noindex_pdf_files' ),
			'tdm'      => (bool) WPPDF_Settings::get( 'noindex_tdm' ),
			'paths'    => self::blocked_paths(),
			'policy'   => self::tdm_policy_url(),
			// Publishing one document out of an excluded library changes what
			// the reservation file has to carve out, so it belongs in the
			// comparison — otherwise the file would keep the old exceptions.
			'allowed'  => self::allowed_paths(),
			// The uploads rule names files, so swapping the PDF behind a
			// published document has to rewrite it too.
			'files_ok' => self::allowed_file_names(),
		);

		if ( get_option( self::STATE_OPTION ) === $wanted ) {
			return;
		}

		self::write_file_rules( $wanted['files'] );
		self::write_tdmrep_json( $wanted['tdm'] );

		// Stored even when a write failed: retrying on every admin request
		// would be a write attempt per page load on a read-only web root. The
		// settings screen shows the warning, and pressing Save retries.
		update_option( self::STATE_OPTION, $wanted, false );
	}

	// --- Deciding ---.

	/**
	 * Post types excluded as a whole.
	 *
	 * @return array
	 */
	public static function excluded_post_types() {
		$types = WPPDF_Settings::get( 'noindex_post_types' );
		$types = is_array( $types ) ? array_map( 'strval', $types ) : array();

		/**
		 * Filter the post types excluded from indexing as a whole.
		 *
		 * @param array $types Post type keys.
		 */
		return (array) apply_filters( 'wppdf_noindex_post_types', $types );
	}

	/**
	 * Whether a single post is excluded from indexing.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_noindex( $post_id ) {
		$post_id   = (int) $post_id;
		$post_type = $post_id ? get_post_type( $post_id ) : '';

		// The post type decides, and a single post may say otherwise in either
		// direction. Both exceptions are needed: a library that is excluded
		// wholesale still has the odd document meant to be seen — a
		// certificate, a price list, a leaflet — and publishing that should not
		// mean unticking the whole type and re-ticking it document by document,
		// which would leave every new upload public until somebody remembered.
		//
		// The meta is only stored when it disagrees with the post type, so '1'
		// means "exclude this one" and '0' means "publish this one anyway".
		$exception = $post_id ? get_post_meta( $post_id, self::META, true ) : '';

		if ( '1' === (string) $exception ) {
			$noindex = true;
		} elseif ( '0' === (string) $exception ) {
			$noindex = false;
		} else {
			$noindex = (bool) $post_type && in_array( $post_type, self::excluded_post_types(), true );
		}

		/**
		 * Filter whether a post is kept out of search engines and AI crawlers.
		 *
		 * Use this to drive the rule from something else — a category, an ACF
		 * field, a membership plugin.
		 *
		 * @param bool $noindex Whether the post is excluded.
		 * @param int  $post_id Post ID.
		 */
		return (bool) apply_filters( 'wppdf_is_noindex', $noindex, $post_id );
	}

	/**
	 * Whether the request being answered is for excluded content.
	 *
	 * @return bool
	 */
	public static function current_request_is_noindex() {
		$excluded = self::excluded_post_types();

		if ( $excluded && is_post_type_archive( $excluded ) ) {
			return true;
		}

		if ( is_singular() ) {
			$post_id = get_queried_object_id();

			return $post_id ? self::is_noindex( $post_id ) : false;
		}

		return false;
	}

	// --- Telling crawlers ---.

	/**
	 * Send the X-Robots-Tag header for excluded content.
	 *
	 * The header rather than only the meta tag, because it is the one signal
	 * that also covers what is not HTML — and because it keeps working when an
	 * SEO plugin owns the <head>.
	 */
	public function send_header() {
		if ( headers_sent() || ! self::current_request_is_noindex() ) {
			return;
		}

		header( 'X-Robots-Tag: ' . self::DIRECTIVES . ', ' . self::AI_DIRECTIVES );

		if ( ! WPPDF_Settings::get( 'noindex_tdm' ) ) {
			return;
		}

		// TDM Reservation Protocol, as HTTP headers. See render_tdm_meta() for
		// why this one is worth more than the tokens above.
		header( 'tdm-reservation: 1' );

		$policy = self::tdm_policy_url();

		if ( '' !== $policy ) {
			header( 'tdm-policy: ' . $policy );
		}
	}

	/**
	 * Reserve text and data mining rights on excluded content.
	 *
	 * This is the one signal in this class that is more than an appeal to good
	 * manners. EU copyright law (the DSM directive, article 4) lets anyone mine
	 * lawfully accessible content for text and data mining *unless the rights
	 * holder has reserved that right in an appropriate machine-readable way* —
	 * and the W3C's TDM Reservation Protocol is what "machine-readable" has come
	 * to mean in practice. Sending it turns "please do not train on this" from a
	 * request into a reservation the exception no longer covers.
	 *
	 * What it is not: enforcement. It gives a rights holder something to stand
	 * on afterwards; it does not stop the download, and it says nothing about
	 * jurisdictions that never had that exception.
	 */
	public function render_tdm_meta() {
		if ( ! WPPDF_Settings::get( 'noindex_tdm' ) || ! self::current_request_is_noindex() ) {
			return;
		}

		echo "\n<meta name=\"tdm-reservation\" content=\"1\" />\n";

		$policy = self::tdm_policy_url();

		if ( '' !== $policy ) {
			printf( "<meta name=\"tdm-policy\" content=\"%s\" />\n", esc_url( $policy ) );
		}

		$notice = self::ai_notice();

		if ( '' !== $notice ) {
			// A sentence in the markup that an agent reading the page will see.
			// Worth the two lines it costs, and worth no more confidence than
			// that: an agent is free to read it and carry on.
			printf( "<!-- %s -->\n", esc_html( $notice ) );
		}
	}

	/**
	 * The sentence printed for whoever — or whatever — is reading.
	 *
	 * The fallback lives here rather than in the defaults so it is translated:
	 * defaults() can run before the text domain is loaded, and a stored default
	 * would also freeze the site's language at install time.
	 *
	 * @return string
	 */
	public static function ai_notice() {
		$notice = trim( (string) WPPDF_Settings::get( 'noindex_ai_notice' ) );

		if ( '' !== $notice ) {
			return $notice;
		}

		return __( 'This document is copyrighted. Text and data mining rights are reserved: it is not available for training models or as a basis for developing software.', 'wp-pdf-reader' );
	}

	/**
	 * The URL of the page stating the usage terms, when there is one.
	 *
	 * @return string
	 */
	public static function tdm_policy_url() {
		$policy = (string) WPPDF_Settings::get( 'noindex_tdm_policy' );

		return '' === trim( $policy ) ? '' : esc_url_raw( $policy );
	}

	/**
	 * Add the robots directives to the ones WordPress prints.
	 *
	 * Skipped while an SEO plugin is active: that plugin prints its own robots
	 * meta tag, the meta shim below has already told it to say noindex, and two
	 * tags saying the same thing is just noise in the markup. The header above
	 * is sent either way, so the signal never depends on this filter running.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public function filter_robots( $robots ) {
		if ( ! self::current_request_is_noindex() || WPPDF_Seo::seo_plugin_active() ) {
			return $robots;
		}

		unset( $robots['index'], $robots['follow'], $robots['max-image-preview'], $robots['max-snippet'] );

		foreach ( explode( ',', self::DIRECTIVES ) as $directive ) {
			$directive = trim( $directive );

			if ( '' !== $directive ) {
				$robots[ $directive ] = true;
			}
		}

		return $robots;
	}

	/**
	 * Report excluded posts as noindex to the SEO plugin that is installed.
	 *
	 * Each of these keys is the one its plugin reads when it decides what to
	 * print and what to put in the sitemap. Writing nothing to the database and
	 * answering the read instead means the editor's own choice is untouched:
	 * turn the exclusion off again and the post goes back to whatever it said.
	 *
	 * @param mixed  $value     Existing short-circuit value.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key being read.
	 * @param bool   $single    Whether a single value was asked for. Unused: a
	 *                          short-circuit returns the list shape either way,
	 *                          and get_metadata() takes the first element when
	 *                          a single value was asked for.
	 * @return mixed
	 */
	public function filter_seo_meta( $value, $object_id, $meta_key, $single ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the get_post_metadata signature.
		$keys = array(
			'rank_math_robots'                  => array( 'noindex', 'nofollow' ),
			'_yoast_wpseo_meta-robots-noindex'  => '1',
			'_yoast_wpseo_meta-robots-nofollow' => '1',
			'_seopress_robots_index'            => 'yes',
			'_seopress_robots_follow'           => 'yes',
		);

		if ( ! isset( $keys[ $meta_key ] ) || ! $object_id ) {
			return $value;
		}

		// is_noindex() reads post meta itself, so guard against recursion.
		static $inside = false;

		if ( $inside ) {
			return $value;
		}

		$inside  = true;
		$noindex = self::is_noindex( $object_id );
		$inside  = false;

		if ( ! $noindex ) {
			return $value;
		}

		$replacement = $keys[ $meta_key ];

		// get_post_metadata short-circuits expect the list shape either way:
		// with $single the caller takes the first element of what is returned.
		return array( $replacement );
	}

	/**
	 * Drop wholly excluded post types from the core sitemap.
	 *
	 * @param array $post_types Post type objects keyed by name.
	 * @return array
	 */
	public function filter_sitemap_post_types( $post_types ) {
		// A type with an exception in it stays, or the document published on
		// purpose would have no way of being found. The query filter below then
		// narrows that sitemap down to the exceptions.
		$keep = self::allowed_post_types_with_exceptions();

		foreach ( self::excluded_post_types() as $type ) {
			if ( ! in_array( $type, $keep, true ) ) {
				unset( $post_types[ $type ] );
			}
		}

		return $post_types;
	}

	/**
	 * Excluded post types that hold at least one post published on purpose.
	 *
	 * @return array
	 */
	protected static function allowed_post_types_with_exceptions() {
		$types = array();

		foreach ( self::allowed_posts() as $post_id ) {
			$type = get_post_type( $post_id );

			if ( $type ) {
				$types[] = $type;
			}
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Drop individually excluded posts from the core sitemap.
	 *
	 * @param array  $args      Query arguments.
	 * @param string $post_type Post type being listed.
	 * @return array
	 */
	public function filter_sitemap_query_args( $args, $post_type ) {
		$args['meta_query'] = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the sitemap is generated, not requested per visitor.

		if ( in_array( (string) $post_type, self::excluded_post_types(), true ) ) {
			// The type only survived filter_sitemap_post_types() because it
			// holds an exception, so this sitemap lists nothing else.
			$args['meta_query'][] = array(
				array(
					'key'   => self::META,
					'value' => '0',
				),
			);

			return $args;
		}

		$args['meta_query'][] = array(
			'relation' => 'OR',
			array(
				'key'     => self::META,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::META,
				'value'   => '1',
				'compare' => '!=',
			),
		);

		return $args;
	}

	/**
	 * Add the AI crawler rules to robots.txt.
	 *
	 * Note what is *not* here: a Disallow for Googlebot. A page Google may not
	 * crawl is a page whose noindex Google never reads, so it can sit in the
	 * index for months on the strength of inbound links alone. Search engines
	 * get the noindex above and are left free to come and read it; only the
	 * crawlers that feed training corpora and answer engines — which do not
	 * index, they just take — are turned away at the door.
	 *
	 * @param string $output    Robots.txt body.
	 * @param bool   $is_public Whether the site is public.
	 * @return string
	 */
	public function filter_robots_txt( $output, $is_public ) {
		if ( ! $is_public || ! WPPDF_Settings::get( 'noindex_block_ai' ) ) {
			return $output;
		}

		$paths = self::blocked_paths();

		if ( ! $paths ) {
			return $output;
		}

		$lines   = array( '', '# Added by WP PDF Reader: no AI crawlers on the excluded content.' );
		$allowed = self::allowed_paths();

		foreach ( self::ai_user_agents() as $agent ) {
			$lines[] = 'User-agent: ' . $agent;

			// Allow before Disallow, and more specific than it: a document
			// published on purpose sits under the same path as the rest, so
			// without this line the path-wide rule would hide it too. Google
			// and Bing resolve the overlap by the longest match, which is the
			// Allow — that is the whole reason this works.
			foreach ( $allowed as $path ) {
				$lines[] = 'Allow: ' . $path;
			}

			foreach ( $paths as $path ) {
				$lines[] = 'Disallow: ' . $path;
			}

			$lines[] = '';
		}

		return $output . implode( "\n", $lines ) . "\n";
	}

	/**
	 * Posts published on purpose inside an otherwise excluded post type.
	 *
	 * A certificate or a leaflet sits under the same path as the manuals, so
	 * every path-wide rule this class writes has to carve it out again by name:
	 * robots.txt, the mining reservation, the sitemap.
	 *
	 * @return int[] Post IDs.
	 */
	public static function allowed_posts() {
		if ( null !== self::$allowed_cache ) {
			return self::$allowed_cache;
		}

		$excluded = self::excluded_post_types();

		if ( ! $excluded ) {
			self::$allowed_cache = array();

			return self::$allowed_cache;
		}

		$found = get_posts(
			array(
				'post_type'        => $excluded,
				'post_status'      => 'publish',
				// A cap, because this list is written into robots.txt and into
				// the .htaccess: an exception is meant to be the odd document,
				// and a library where hundreds are exceptions wants the post
				// type unticked instead.
				'numberposts'      => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- deliberate cap, see above.
				'fields'           => 'ids',
				'suppress_filters' => false,
				'no_found_rows'    => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the exception is the rare case, so this matches few rows.
					array(
						'key'   => self::META,
						'value' => '0',
					),
				),
			)
		);

		self::$allowed_cache = is_array( $found ) ? array_map( 'intval', $found ) : array();

		return self::$allowed_cache;
	}

	/**
	 * Forget the memoised exception list.
	 *
	 * Called when a post is saved and from the settings, because both can turn
	 * a document into an exception or back.
	 */
	public static function flush_cache() {
		self::$allowed_cache = null;
	}

	/**
	 * File names of the PDFs belonging to posts published on purpose.
	 *
	 * The uploads rule matches on the file name, because that is all Apache
	 * has to go on, so this is what an exception to it has to be written from.
	 *
	 * @return array
	 */
	public static function allowed_file_names() {
		$names = array();

		foreach ( self::allowed_posts() as $post_id ) {
			foreach ( WPPDF_Languages::get_codes() as $code ) {
				$attachment_id = absint( get_post_meta( $post_id, WPPDF_Languages::file_meta_key( $code ), true ) );

				if ( ! $attachment_id ) {
					continue;
				}

				$file = get_attached_file( $attachment_id );

				if ( $file ) {
					$names[] = basename( $file );
				}
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Paths of the posts published inside an excluded post type.
	 *
	 * @return array
	 */
	public static function allowed_paths() {
		$paths = array();

		foreach ( self::allowed_posts() as $post_id ) {
			$path = wp_parse_url( (string) get_permalink( $post_id ), PHP_URL_PATH );

			if ( is_string( $path ) && '' !== $path && '/' !== $path ) {
				$paths[] = $path;
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Paths the AI crawlers are asked to stay out of.
	 *
	 * @return array
	 */
	public static function blocked_paths() {
		$paths = array();

		foreach ( self::excluded_post_types() as $type ) {
			$object = get_post_type_object( $type );

			if ( ! $object ) {
				continue;
			}

			$slug = '';

			if ( is_array( $object->rewrite ) && ! empty( $object->rewrite['slug'] ) ) {
				$slug = $object->rewrite['slug'];
			} elseif ( 'post' === $type ) {
				// Posts have no rewrite slug of their own; their permalinks sit
				// all over the site, so there is no one path to name.
				continue;
			}

			if ( '' !== $slug ) {
				$paths[] = '/' . trim( $slug, '/' ) . '/';
			}
		}

		if ( WPPDF_Settings::get( 'noindex_pdf_files' ) ) {
			$paths[] = '/*.pdf$';
		}

		/**
		 * Filter the paths written into the robots.txt AI crawler block.
		 *
		 * @param array $paths Paths, each starting with a slash.
		 */
		$paths = (array) apply_filters( 'wppdf_noindex_blocked_paths', array_values( array_unique( $paths ) ) );

		return $paths;
	}

	/**
	 * The AI crawler user agents from the settings.
	 *
	 * @return array
	 */
	public static function ai_user_agents() {
		$raw    = (string) WPPDF_Settings::get( 'noindex_ai_agents' );
		$agents = array();

		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}

			$agents[] = $line;
		}

		return array_values( array_unique( $agents ) );
	}

	/**
	 * The user agents suggested out of the box.
	 *
	 * Kept as a plain list rather than something clever: it dates, and the
	 * point is that the site owner can see it and edit it.
	 *
	 * @return string
	 */
	public static function default_ai_agents() {
		return implode(
			"\n",
			array(
				'GPTBot',
				'OAI-SearchBot',
				'ChatGPT-User',
				'ClaudeBot',
				'Claude-User',
				'Claude-SearchBot',
				'anthropic-ai',
				'PerplexityBot',
				'Perplexity-User',
				'Google-Extended',
				'Applebot-Extended',
				'meta-externalagent',
				'FacebookBot',
				'Bytespider',
				'CCBot',
				'Amazonbot',
				'cohere-ai',
				'Diffbot',
				'omgili',
				'Timpibot',
				'YouBot',
				'ImagesiftBot',
				'AI2Bot',
				'DataForSeoBot',
			)
		);
	}

	// --- The PDF files themselves ---.

	/**
	 * Absolute path of the uploads .htaccess.
	 *
	 * @return string Empty when uploads are unavailable.
	 */
	protected static function htaccess_path() {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		return trailingslashit( $uploads['basedir'] ) . '.htaccess';
	}

	/**
	 * Write or remove the X-Robots-Tag rule for PDFs in uploads.
	 *
	 * A PDF is served by the web server, not by WordPress, so the only way to
	 * mark it noindex is an HTTP header from the server config. The rule is
	 * written into the uploads .htaccess between markers, the way WordPress
	 * writes its own rewrite block, so nothing else in that file is touched.
	 *
	 * It necessarily covers *every* PDF under uploads, not only the documents:
	 * Apache matches on the file name, and it has no idea which post an
	 * attachment belongs to. The settings screen says so.
	 *
	 * @param bool $enable Whether the rule should be present.
	 * @return bool Whether the file now says what was asked.
	 */
	public static function write_file_rules( $enable ) {
		$path = self::htaccess_path();

		if ( '' === $path ) {
			return false;
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		if ( ! file_exists( $path ) && ! $enable ) {
			return true;
		}

		$rules = array();

		if ( $enable ) {
			$rules = array(
				'<IfModule mod_headers.c>',
				'<FilesMatch "\.pdf$">',
				'Header set X-Robots-Tag "' . self::DIRECTIVES . '"',
				'</FilesMatch>',
			);

			// The rule above cannot tell one PDF from another, so a document
			// published on purpose gets its file named here and the header set
			// back. Apache applies Header directives in order, so this one,
			// coming second, wins for those files.
			$names = array();

			foreach ( self::allowed_file_names() as $name ) {
				// The name goes into a regex. WordPress sanitises upload names,
				// but a dot is still a metacharacter and a hand-placed file can
				// hold anything.
				$names[] = preg_quote( $name, '' );
			}

			if ( $names ) {
				$rules[] = '<FilesMatch "^(' . implode( '|', $names ) . ')$">';
				$rules[] = 'Header set X-Robots-Tag "all"';
				$rules[] = '</FilesMatch>';
			}

			$rules[] = '</IfModule>';
		}

		return (bool) insert_with_markers( $path, self::MARKER, $rules );
	}

	/**
	 * Write or remove /.well-known/tdmrep.json.
	 *
	 * The meta tags only reach a crawler that renders the page. The well-known
	 * file states the reservation for whole paths at once, which is how a
	 * crawler that takes the PDF and never looks at the HTML finds out.
	 *
	 * @param bool $enable Whether the file should be present.
	 * @return bool Whether the file now says what was asked.
	 */
	public static function write_tdmrep_json( $enable ) {
		$directory = ABSPATH . '.well-known';
		$path      = $directory . '/tdmrep.json';

		if ( ! $enable ) {
			if ( ! file_exists( $path ) ) {
				return true;
			}

			return wp_delete_file_from_directory( $path, $directory ) || ! file_exists( $path );
		}

		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return false;
		}

		$policy  = self::tdm_policy_url();
		$entries = array();

		foreach ( self::blocked_paths() as $blocked ) {
			// Locations are root-relative and have no leading slash, and the
			// reservation covers everything below them.
			$location = ltrim( $blocked, '/' );
			$location = '' === $location ? '*' : rtrim( $location, '/' ) . '/*';

			$entry = array(
				'location'        => $location,
				'tdm-reservation' => 1,
			);

			if ( '' !== $policy ) {
				$entry['tdm-policy'] = $policy;
			}

			$entries[] = $entry;
		}

		// Same carve-out as in robots.txt, for the same reason: the entries
		// above name whole paths, and a document published on purpose lives
		// under one of them. Listed after, so a reader taking the last match
		// gets the exception; TDMRep itself says the most specific location
		// wins, and these are more specific.
		foreach ( self::allowed_paths() as $allowed ) {
			$entries[] = array(
				'location'        => ltrim( $allowed, '/' ),
				'tdm-reservation' => 0,
			);
		}

		if ( ! $entries ) {
			return false;
		}

		$json = wp_json_encode( $entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( ! $json ) {
			return false;
		}

		return false !== file_put_contents( $path, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a small static file in the web root.
	}

	/**
	 * Whether the TDM reservation file exists.
	 *
	 * @return bool
	 */
	public static function tdmrep_json_present() {
		return file_exists( ABSPATH . '.well-known/tdmrep.json' );
	}

	/**
	 * Whether the uploads .htaccess currently carries the rule.
	 *
	 * @return bool
	 */
	public static function file_rules_present() {
		$path = self::htaccess_path();

		if ( '' === $path || ! is_readable( $path ) ) {
			return false;
		}

		$contents = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local config file, not a URL.

		return false !== strpos( $contents, '# BEGIN ' . self::MARKER );
	}

	/**
	 * Whether a static robots.txt shadows the one WordPress generates.
	 *
	 * With a real file in the web root the robots_txt filter never runs, so the
	 * AI crawler block silently does nothing. Worth saying in the admin.
	 *
	 * @return bool
	 */
	public static function static_robots_txt_exists() {
		return file_exists( ABSPATH . 'robots.txt' );
	}

	// --- Per-post switch ---.

	/**
	 * Post types that get the per-post exclusion box.
	 *
	 * Every public post type, so a single post or page can be excluded without
	 * excluding its whole type — which is the case the moment a document is
	 * rewritten as a post.
	 *
	 * @return array
	 */
	public static function meta_box_post_types() {
		$types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			)
		);

		unset( $types['attachment'] );

		/**
		 * Filter the post types offering the per-post indexing switch.
		 *
		 * @param array $types Post type keys.
		 */
		return (array) apply_filters( 'wppdf_noindex_meta_box_post_types', array_values( $types ) );
	}

	/**
	 * Register the meta box.
	 */
	public function add_meta_box() {
		add_meta_box(
			'wppdf-noindex',
			__( 'Search engines and AI', 'wp-pdf-reader' ),
			array( $this, 'render_meta_box' ),
			self::meta_box_post_types(),
			'side',
			'low'
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_meta_box( $post ) {
		$by_type = in_array( $post->post_type, self::excluded_post_types(), true );
		$checked = self::is_noindex( $post->ID );

		wp_nonce_field( 'wppdf_noindex', 'wppdf_noindex_nonce' );

		// Hidden companion field: an unticked checkbox is not submitted at all,
		// so without something that always arrives there is no telling "the
		// editor unticked it" from "this save never showed the box".
		echo '<input type="hidden" name="wppdf_noindex_present" value="1" />';

		printf(
			'<p><label><input type="checkbox" name="wppdf_noindex" value="1" %1$s /> %2$s</label></p>',
			checked( $checked, true, false ),
			esc_html__( 'Keep out of search engines and AI crawlers', 'wp-pdf-reader' )
		);

		if ( $by_type ) {
			echo '<p class="description">' . esc_html__( 'Every post of this type is excluded in the plugin settings. Unticking it here publishes this one document anyway — for a certificate, a price list or a leaflet that is meant to be found.', 'wp-pdf-reader' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Asks crawlers to stay away and drops the post from the sitemap. It does not lock anything: a crawler that ignores the request still reads the page, and any attached PDF stays downloadable by its URL.', 'wp-pdf-reader' ) . '</p>';
	}

	/**
	 * Save the meta box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['wppdf_noindex_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wppdf_noindex_nonce'] ) ), 'wppdf_noindex' ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, self::meta_box_post_types(), true ) ) {
			return;
		}

		// Quick Edit and REST saves never render the box, and treating their
		// silence as "unticked" would publish a document nobody touched.
		if ( empty( $_POST['wppdf_noindex_present'] ) ) {
			return;
		}

		$wanted       = ! empty( $_POST['wppdf_noindex'] );
		$type_default = in_array( $post->post_type, self::excluded_post_types(), true );

		self::flush_cache();

		// The meta is the exception, not the state: storing it only when it
		// disagrees with the post type means a library-wide change in the
		// settings still moves every document that never said otherwise.
		if ( $wanted === $type_default ) {
			delete_post_meta( $post_id, self::META );
			return;
		}

		update_post_meta( $post_id, self::META, $wanted ? '1' : '0' );
	}
}

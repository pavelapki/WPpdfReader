<?php
/**
 * Front-end reader markup and assets.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * PDF.js based reader.
 */
class WPPDF_Viewer {

	/**
	 * Incrementing instance counter for unique ids.
	 *
	 * @var int
	 */
	protected static $instances = 0;

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_filter( 'the_content', array( $this, 'append_to_content' ), 20 );
	}

	/**
	 * Make the reader work inside the block editor preview too.
	 */
	public function enqueue_editor_assets() {
		$this->register_assets();
		self::enqueue();
		wp_enqueue_style( 'wppdf-archive' );
	}

	/**
	 * Register (but do not enqueue) the front-end assets.
	 */
	public function register_assets() {
		// PDF.js 4 ships as an ES module, so viewer.js imports it on demand
		// instead of it being enqueued as a script of its own.
		wp_register_script(
			'wppdf-viewer',
			WPPDF_URL . 'assets/js/viewer.js',
			array(),
			WPPDF_VERSION,
			true
		);

		$vendor = WPPDF_URL . 'assets/vendor/pdfjs/';

		wp_localize_script(
			'wppdf-viewer',
			'wppdfSettings',
			array(
				'libSrc'              => $vendor . 'pdf.min.mjs',
				'workerSrc'           => $vendor . 'pdf.worker.min.mjs',
				'standardFontDataUrl' => $vendor . 'standard_fonts/',

				/*
				 * Only for CJK documents: copy the cmaps directory of pdfjs-dist
				 * next to the build and it is picked up automatically.
				 */
				'cMapUrl'             => is_dir( WPPDF_PATH . 'assets/vendor/pdfjs/cmaps' ) ? $vendor . 'cmaps/' : '',
				'i18n'                => array(
					'loading'    => __( 'Loading document…', 'wp-pdf-reader' ),
					'error'      => __( 'The document could not be loaded.', 'wp-pdf-reader' ),
					'page'       => __( 'Page', 'wp-pdf-reader' ),
					/* translators: %s: total number of pages. */
					'of'         => __( 'of %s', 'wp-pdf-reader' ),
					'prev'       => __( 'Previous page', 'wp-pdf-reader' ),
					'next'       => __( 'Next page', 'wp-pdf-reader' ),
					'zoomIn'     => __( 'Zoom in', 'wp-pdf-reader' ),
					'zoomOut'    => __( 'Zoom out', 'wp-pdf-reader' ),
					'fitWidth'   => __( 'Fit width', 'wp-pdf-reader' ),
					'fitPage'    => __( 'Fit page', 'wp-pdf-reader' ),
					'fullscreen' => __( 'Fullscreen', 'wp-pdf-reader' ),
					'download'   => __( 'Download', 'wp-pdf-reader' ),
					'print'      => __( 'Print', 'wp-pdf-reader' ),
					'searching'  => __( 'Searching…', 'wp-pdf-reader' ),
					'copied'     => __( 'Link copied', 'wp-pdf-reader' ),
					'copyFailed' => __( 'Copy this link:', 'wp-pdf-reader' ),
					'preparing'  => __( 'Preparing pages…', 'wp-pdf-reader' ),
					'noOutline'  => __( 'This document has no contents.', 'wp-pdf-reader' ),
					'noMatches'  => __( 'No matches', 'wp-pdf-reader' ),
					/* translators: 1: current match, 2: total matches. */
					'matches'    => __( '%1$d of %2$d', 'wp-pdf-reader' ),
					/* translators: 1: current page, 2: total pages. */
					'pageOf'     => __( 'Page %1$d of %2$d', 'wp-pdf-reader' ),
				),
				'rest'                => array(
					'hit' => esc_url_raw( rest_url( WPPDF_Stats::NAMESPACE_ROUTE . '/hit' ) ),
				),
			)
		);

		wp_register_style(
			'wppdf-viewer',
			WPPDF_URL . 'assets/css/viewer.css',
			array(),
			WPPDF_VERSION
		);

		wp_register_style(
			'wppdf-archive',
			WPPDF_URL . 'assets/css/archive.css',
			array(),
			WPPDF_VERSION
		);
	}

	/**
	 * Enqueue the reader assets.
	 */
	public static function enqueue() {
		wp_enqueue_style( 'wppdf-viewer' );
		wp_enqueue_script( 'wppdf-viewer' );
	}

	/**
	 * Append the reader below the content of a single document.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function append_to_content( $content ) {
		if ( ! WPPDF_Settings::get( 'append_to_content' ) ) {
			return $content;
		}

		if ( ! is_singular( WPPDF_Post_Type::get_supported_post_types() ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = get_the_ID();

		if ( ! $post_id || false !== strpos( $content, 'wppdf-viewer' ) ) {
			return $content;
		}

		$viewer = self::render( $post_id );

		if ( '' === $viewer ) {
			return $content;
		}

		return $content . $viewer;
	}

	/**
	 * Build the reader markup for a document.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $args    Overrides.
	 * @return string HTML, empty when there is nothing to show.
	 */
	public static function render( $post_id, array $args = array() ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return '';
		}

		$defaults = array(
			'lang'     => '',
			'height'   => (int) WPPDF_Settings::get( 'viewer_height' ),
			'zoom'     => WPPDF_Settings::get( 'viewer_zoom' ),
			'toolbar'  => (bool) WPPDF_Settings::get( 'show_toolbar' ),
			'download' => (bool) WPPDF_Settings::get( 'allow_download' ),
			'print'    => (bool) WPPDF_Settings::get( 'allow_print' ),
			'lazy'     => (bool) WPPDF_Settings::get( 'lazy_load' ),
			'page'     => 1,
			'class'    => '',
		);

		$args = wp_parse_args( $args, $defaults );
		$file = WPPDF_Documents::get_file( $post_id, $args['lang'] );

		if ( ! $file ) {
			/**
			 * Filter the markup shown when a document has no file at all.
			 *
			 * @param string $html    Markup.
			 * @param int    $post_id Post ID.
			 */
			return apply_filters( 'wppdf_no_file_html', '', $post_id );
		}

		self::enqueue();
		++self::$instances;

		$id      = 'wppdf-viewer-' . $post_id . '-' . self::$instances;
		$height  = min( 4000, max( 200, (int) $args['height'] ) );
		$zoom    = in_array( $args['zoom'], WPPDF_Settings::zoom_modes(), true ) ? $args['zoom'] : 'auto';
		$classes = array( 'wppdf-viewer' );

		if ( ! $args['toolbar'] ) {
			$classes[] = 'wppdf-viewer--no-toolbar';
		}
		if ( $args['class'] ) {
			$classes[] = sanitize_html_class( $args['class'] );
		}

		$sources = self::get_sources( $post_id, $args );

		$config = array(
			'postId'   => $post_id,
			'url'      => $file['url'],
			'zoom'     => $zoom,
			'page'     => max( 1, (int) $args['page'] ),
			'lazy'     => (bool) $args['lazy'],
			'download' => (bool) $args['download'],
			'print'    => (bool) $args['print'],
			'lang'     => $file['lang'],
			'sources'  => $sources,
			'stats'    => (bool) WPPDF_Settings::get( 'count_views' ),
		);

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			data-wppdf="<?php echo esc_attr( wp_json_encode( $config ) ); ?>"
			role="region"
			aria-label="<?php echo esc_attr( get_the_title( $post_id ) ); ?>"
			lang="<?php echo esc_attr( $file['lang'] ); ?>"
			style="--wppdf-height: <?php echo (int) $height; ?>px;">

			<?php if ( $file['is_fallback'] && WPPDF_Settings::get( 'show_fallback_notice' ) ) : ?>
				<p class="wppdf-viewer__fallback">
					<?php
					printf(
						/* translators: %s: language label. */
						esc_html__( 'This document is not available in your language, showing the %s version.', 'wp-pdf-reader' ),
						'<strong>' . esc_html( $file['language_label'] ) . '</strong>'
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $args['toolbar'] ) : ?>
				<div class="wppdf-viewer__toolbar">
					<div class="wppdf-toolbar__group">
						<button type="button" class="wppdf-btn wppdf-sidebar-toggle" aria-expanded="false" aria-label="<?php esc_attr_e( 'Contents and thumbnails', 'wp-pdf-reader' ); ?>">&#9776;</button>
						<button type="button" class="wppdf-btn wppdf-prev" aria-label="<?php esc_attr_e( 'Previous page', 'wp-pdf-reader' ); ?>">&#8249;</button>
						<span class="wppdf-pages">
							<input type="number" class="wppdf-page-input" value="1" min="1" aria-label="<?php esc_attr_e( 'Page', 'wp-pdf-reader' ); ?>" />
							<span class="wppdf-page-total">/ –</span>
						</span>
						<button type="button" class="wppdf-btn wppdf-next" aria-label="<?php esc_attr_e( 'Next page', 'wp-pdf-reader' ); ?>">&#8250;</button>
					</div>

					<div class="wppdf-toolbar__group">
						<button type="button" class="wppdf-btn wppdf-zoom-out" aria-label="<?php esc_attr_e( 'Zoom out', 'wp-pdf-reader' ); ?>">&minus;</button>
						<span class="wppdf-zoom-level">100&nbsp;%</span>
						<button type="button" class="wppdf-btn wppdf-zoom-in" aria-label="<?php esc_attr_e( 'Zoom in', 'wp-pdf-reader' ); ?>">+</button>
						<button type="button" class="wppdf-btn wppdf-fit" aria-label="<?php esc_attr_e( 'Fit width', 'wp-pdf-reader' ); ?>">&#9635;</button>
					</div>

					<div class="wppdf-toolbar__group wppdf-toolbar__search">
						<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-search"><?php esc_html_e( 'Search in the document', 'wp-pdf-reader' ); ?></label>
						<input type="search" id="<?php echo esc_attr( $id ); ?>-search" class="wppdf-search-input" placeholder="<?php esc_attr_e( 'Search…', 'wp-pdf-reader' ); ?>" />
						<span class="wppdf-search-count" aria-live="polite"></span>
						<button type="button" class="wppdf-btn wppdf-search-prev" aria-label="<?php esc_attr_e( 'Previous match', 'wp-pdf-reader' ); ?>" disabled>&#8249;</button>
						<button type="button" class="wppdf-btn wppdf-search-next" aria-label="<?php esc_attr_e( 'Next match', 'wp-pdf-reader' ); ?>" disabled>&#8250;</button>
					</div>

					<div class="wppdf-toolbar__group wppdf-toolbar__group--end">
						<?php if ( count( $sources ) > 1 ) : ?>
							<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-lang"><?php esc_html_e( 'Document language', 'wp-pdf-reader' ); ?></label>
							<select id="<?php echo esc_attr( $id ); ?>-lang" class="wppdf-language-select">
								<?php foreach ( $sources as $source ) : ?>
									<option value="<?php echo esc_attr( $source['lang'] ); ?>" <?php selected( $source['lang'], $file['lang'] ); ?>>
										<?php echo esc_html( $source['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>
						<button type="button" class="wppdf-btn wppdf-share" aria-label="<?php esc_attr_e( 'Copy a link to this page', 'wp-pdf-reader' ); ?>">&#128279;</button>
						<?php if ( $args['print'] ) : ?>
							<button type="button" class="wppdf-btn wppdf-print" aria-expanded="false" aria-label="<?php esc_attr_e( 'Print', 'wp-pdf-reader' ); ?>">&#9113;</button>
						<?php endif; ?>
						<?php if ( $args['download'] ) : ?>
							<a class="wppdf-btn wppdf-download" href="<?php echo esc_url( $file['url'] ); ?>" download aria-label="<?php esc_attr_e( 'Download', 'wp-pdf-reader' ); ?>">&#8595;</a>
						<?php endif; ?>
						<button type="button" class="wppdf-btn wppdf-fullscreen" aria-pressed="false" aria-label="<?php esc_attr_e( 'Fullscreen', 'wp-pdf-reader' ); ?>">&#9974;</button>
					</div>
				</div>

				<?php if ( $args['print'] ) : ?>
					<div class="wppdf-print-dialog" hidden>
						<fieldset>
							<legend><?php esc_html_e( 'Pages to print', 'wp-pdf-reader' ); ?></legend>
							<label><input type="radio" name="<?php echo esc_attr( $id ); ?>-range" value="all" checked /> <?php esc_html_e( 'All', 'wp-pdf-reader' ); ?></label>
							<label><input type="radio" name="<?php echo esc_attr( $id ); ?>-range" value="current" /> <?php esc_html_e( 'Current page', 'wp-pdf-reader' ); ?></label>
							<label>
								<input type="radio" name="<?php echo esc_attr( $id ); ?>-range" value="range" />
								<?php esc_html_e( 'From', 'wp-pdf-reader' ); ?>
								<input type="number" class="wppdf-print-from" min="1" value="1" />
								<?php esc_html_e( 'to', 'wp-pdf-reader' ); ?>
								<input type="number" class="wppdf-print-to" min="1" value="1" />
							</label>
						</fieldset>
						<p>
							<button type="button" class="wppdf-btn wppdf-print-start"><?php esc_html_e( 'Print', 'wp-pdf-reader' ); ?></button>
							<button type="button" class="wppdf-btn wppdf-print-cancel"><?php esc_html_e( 'Cancel', 'wp-pdf-reader' ); ?></button>
							<span class="wppdf-print-progress"></span>
						</p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="wppdf-viewer__body">
				<aside class="wppdf-viewer__sidebar" hidden>
					<div class="wppdf-sidebar__tabs" role="tablist">
						<button type="button" class="wppdf-sidebar__tab is-active" role="tab" aria-selected="true" data-panel="thumbs"><?php esc_html_e( 'Pages', 'wp-pdf-reader' ); ?></button>
						<button type="button" class="wppdf-sidebar__tab" role="tab" aria-selected="false" data-panel="outline" hidden><?php esc_html_e( 'Contents', 'wp-pdf-reader' ); ?></button>
					</div>
					<div class="wppdf-sidebar__panel wppdf-thumbs" role="tabpanel"></div>
					<div class="wppdf-sidebar__panel wppdf-outline" role="tabpanel" hidden></div>
				</aside>

				<div class="wppdf-viewer__stage">
					<div class="wppdf-viewer__pages" tabindex="0" role="document"></div>
					<div class="wppdf-viewer__status" role="status">
						<span class="wppdf-spinner" aria-hidden="true"></span>
						<span class="wppdf-status-text"><?php esc_html_e( 'Loading document…', 'wp-pdf-reader' ); ?></span>
					</div>
				</div>
			</div>

			<p class="screen-reader-text wppdf-live" aria-live="polite"></p>

			<noscript>
				<p>
					<a href="<?php echo esc_url( $file['url'] ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Open the PDF', 'wp-pdf-reader' ); ?>
					</a>
				</p>
			</noscript>
		</div>
		<?php
		$html = (string) ob_get_clean();

		/**
		 * Filter the reader markup.
		 *
		 * @param string $html    Markup.
		 * @param int    $post_id Post ID.
		 * @param array  $file    Resolved file data.
		 * @param array  $args    Reader arguments.
		 */
		return apply_filters( 'wppdf_viewer_html', $html, $post_id, $file, $args );
	}

	/**
	 * The language versions a visitor can switch between in the toolbar.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $args    Reader arguments.
	 * @return array List of arrays with lang, label and url.
	 */
	protected static function get_sources( $post_id, array $args ) {
		if ( ! WPPDF_Settings::get( 'language_switcher' ) || ! empty( $args['lang'] ) ) {
			return array();
		}

		$sources = array();

		foreach ( WPPDF_Documents::get_available_languages( $post_id ) as $code ) {
			$raw = WPPDF_Documents::get_raw_file( $post_id, $code );

			if ( ! $raw ) {
				continue;
			}

			$sources[] = array(
				'lang'  => $code,
				'label' => WPPDF_Languages::get_label( $code ),
				'url'   => $raw['url'],
			);
		}

		return count( $sources ) > 1 ? $sources : array();
	}
}

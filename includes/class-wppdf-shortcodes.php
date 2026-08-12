<?php
/**
 * Shortcodes.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode handlers.
 */
class WPPDF_Shortcodes {

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_shortcode( 'pdf_reader', array( $this, 'reader' ) );
		add_shortcode( 'pdf_grid', array( $this, 'grid' ) );
		add_shortcode( 'pdf_list', array( $this, 'grid' ) );
		add_shortcode( 'pdf_download', array( $this, 'download' ) );
	}

	/**
	 * [pdf_reader id="12" lang="en" height="800"]
	 *
	 * @param array  $atts Shortcode attributes.
	 * @param string $content Enclosed content.
	 * @param string $tag  Shortcode tag.
	 * @return string
	 */
	public function reader( $atts, $content = '', $tag = 'pdf_reader' ) {
		$atts = shortcode_atts(
			array(
				'id'       => 0,
				'lang'     => '',
				'height'   => (int) WPPDF_Settings::get( 'viewer_height' ),
				'zoom'     => WPPDF_Settings::get( 'viewer_zoom' ),
				'page'     => 1,
				'toolbar'  => WPPDF_Settings::get( 'show_toolbar' ),
				'download' => WPPDF_Settings::get( 'allow_download' ),
				'print'    => WPPDF_Settings::get( 'allow_print' ),
				'lazy'     => WPPDF_Settings::get( 'lazy_load' ),
				'class'    => '',
			),
			$atts,
			$tag
		);

		$post_id = absint( $atts['id'] );
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		if ( ! $post_id ) {
			return '';
		}

		return WPPDF_Viewer::render(
			$post_id,
			array(
				'lang'     => WPPDF_Settings::sanitize_language_code( $atts['lang'] ),
				'height'   => (int) $atts['height'],
				'zoom'     => sanitize_text_field( $atts['zoom'] ),
				'page'     => absint( $atts['page'] ),
				'toolbar'  => self::to_bool( $atts['toolbar'] ),
				'download' => self::to_bool( $atts['download'] ),
				'print'    => self::to_bool( $atts['print'] ),
				'lazy'     => self::to_bool( $atts['lazy'] ),
				'class'    => sanitize_html_class( $atts['class'] ),
			)
		);
	}

	/**
	 * [pdf_grid columns="3" per_page="12" category="reports"]
	 *
	 * @param array  $atts Shortcode attributes.
	 * @param string $content Enclosed content.
	 * @param string $tag  Shortcode tag.
	 * @return string
	 */
	public function grid( $atts, $content = '', $tag = 'pdf_grid' ) {
		$atts = shortcode_atts(
			array(
				'columns'    => (int) WPPDF_Settings::get( 'archive_columns' ),
				'per_page'   => (int) WPPDF_Settings::get( 'archive_per_page' ),
				'layout'     => 'pdf_list' === $tag ? 'list' : WPPDF_Settings::get( 'archive_layout' ),
				'category'   => '',
				'tag'        => '',
				'taxonomy'   => '',
				'terms'      => '',
				'ids'        => '',
				'exclude'    => '',
				'author'     => '',
				'orderby'    => 'date',
				'order'      => 'DESC',
				'search'     => '',
				'lang'       => '',
				'excerpt'    => 1,
				'meta'       => 1,
				'pagination' => 0,
				'filters'    => 0,
			),
			$atts,
			$tag
		);

		$filters = self::to_bool( $atts['filters'] );

		$query_args = array(
			'posts_per_page' => (int) $atts['per_page'],
			'orderby'        => sanitize_key( $atts['orderby'] ),
			'order'          => 'ASC' === strtoupper( $atts['order'] ) ? 'ASC' : 'DESC',
		);

		if ( '' !== $atts['category'] ) {
			$query_args['category_name'] = sanitize_text_field( $atts['category'] );
		}

		if ( '' !== $atts['tag'] ) {
			$query_args['tag'] = sanitize_text_field( $atts['tag'] );
		}

		if ( '' !== $atts['taxonomy'] && '' !== $atts['terms'] ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => sanitize_key( $atts['taxonomy'] ),
					'field'    => 'slug',
					'terms'    => array_map( 'sanitize_title', self::to_array( $atts['terms'] ) ),
				),
			);
		}

		if ( '' !== $atts['ids'] ) {
			$query_args['post__in'] = array_map( 'absint', self::to_array( $atts['ids'] ) );
			if ( 'date' === $query_args['orderby'] ) {
				$query_args['orderby'] = 'post__in';
			}
		}

		if ( '' !== $atts['exclude'] ) {
			$query_args['post__not_in'] = array_map( 'absint', self::to_array( $atts['exclude'] ) );
		}

		if ( '' !== $atts['author'] ) {
			$query_args['author'] = sanitize_text_field( $atts['author'] );
		}

		if ( '' !== $atts['search'] ) {
			$query_args['s'] = sanitize_text_field( $atts['search'] );
		}

		if ( self::to_bool( $atts['pagination'] ) ) {
			$query_args['paged'] = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		}

		if ( $filters ) {
			$query_args = WPPDF_Filters::apply_to_args( $query_args );
		}

		$query = WPPDF_Documents::query( $query_args );

		$html = WPPDF_Templates::get_part(
			'parts/archive-loop.php',
			array(
				'query'      => $query,
				'columns'    => min( 6, max( 1, (int) $atts['columns'] ) ),
				'layout'     => 'list' === $atts['layout'] ? 'list' : 'grid',
				'lang'       => WPPDF_Settings::sanitize_language_code( $atts['lang'] ),
				'excerpt'    => self::to_bool( $atts['excerpt'] ),
				'show_meta'  => self::to_bool( $atts['meta'] ),
				'pagination' => self::to_bool( $atts['pagination'] ),
			)
		);

		wp_reset_postdata();

		if ( $filters ) {
			$html = WPPDF_Filters::render( array( 'action' => get_permalink() ? get_permalink() : home_url( '/' ) ) ) . $html;
		}

		return $html;
	}

	/**
	 * [pdf_download id="12" text="Download the report"]
	 *
	 * @param array  $atts Shortcode attributes.
	 * @param string $content Enclosed content.
	 * @param string $tag  Shortcode tag.
	 * @return string
	 */
	public function download( $atts, $content = '', $tag = 'pdf_download' ) {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'lang'  => '',
				'text'  => '',
				'class' => '',
				'meta'  => 1,
			),
			$atts,
			$tag
		);

		$post_id = absint( $atts['id'] );
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		$file = $post_id ? WPPDF_Documents::get_file( $post_id, WPPDF_Settings::sanitize_language_code( $atts['lang'] ) ) : null;

		if ( ! $file ) {
			return '';
		}

		wp_enqueue_style( 'wppdf-archive' );

		$text  = '' !== $atts['text'] ? $atts['text'] : __( 'Download PDF', 'wp-pdf-reader' );
		$class = 'wppdf-download-link';
		if ( $atts['class'] ) {
			$class .= ' ' . sanitize_html_class( $atts['class'] );
		}

		$meta = '';
		if ( self::to_bool( $atts['meta'] ) ) {
			$parts = array( strtoupper( $file['lang'] ) );
			$size  = WPPDF_Documents::format_filesize( $file['filesize'] );
			if ( $size ) {
				$parts[] = $size;
			}
			$meta = ' <span class="wppdf-download-link__meta">(' . esc_html( implode( ', ', $parts ) ) . ')</span>';
		}

		return sprintf(
			'<a class="%1$s" href="%2$s" download>%3$s%4$s</a>',
			esc_attr( $class ),
			esc_url( $file['url'] ),
			esc_html( $text ),
			$meta
		);
	}

	/**
	 * Cast a shortcode attribute to a boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Split a comma separated attribute.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	public static function to_array( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}

		return array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
	}
}

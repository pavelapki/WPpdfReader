<?php
/**
 * Smoke test for WP PDF Reader.
 *
 * Stubs the handful of WordPress functions the plugin touches so the language
 * resolution, fallback chain, reader markup, shortcodes and settings
 * sanitizing can be exercised without a WordPress install.
 *
 * Run with:  php tests/smoke.php
 *
 * @package WP_PDF_Reader
 */

define( 'ABSPATH', '/tmp/fake-wp/' );
define( 'MB_IN_BYTES', 1048576 );

$GLOBALS['stub_options'] = array();
$GLOBALS['stub_meta']    = array();
$GLOBALS['stub_posts']   = array();
$GLOBALS['stub_actions'] = array();
$GLOBALS['stub_enqueued'] = array();

// --- Hooks ---------------------------------------------------------------.
function add_action( $tag, $cb, $priority = 10, $args = 1 ) { $GLOBALS['stub_actions'][ $tag ][] = $cb; }
function add_filter( $tag, $cb, $priority = 10, $args = 1 ) { $GLOBALS['stub_actions'][ $tag ][] = $cb; }
function has_filter( $tag, $cb = false ) { return isset( $GLOBALS['stub_actions'][ $tag ] ); }
function apply_filters( $tag, $value ) { return $value; }
function do_action( $tag ) {}
function register_activation_hook( $file, $cb ) {}
function register_deactivation_hook( $file, $cb ) {}
function add_shortcode( $tag, $cb ) { $GLOBALS['stub_shortcodes'][ $tag ] = $cb; }
function shortcode_atts( $pairs, $atts, $shortcode = '' ) { $atts = (array) $atts; $out = array(); foreach ( $pairs as $name => $default ) { $out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default; } return $out; }
function add_meta_box() {}
function add_submenu_page() {}
function register_setting() {}
function add_settings_error( $a, $b, $c, $d = 'error' ) { echo "SETTINGS ERROR: $c\n"; }
function settings_errors() {}
function settings_fields() {}
function submit_button() {}
function load_plugin_textdomain() {}
function register_post_type( $key, $args ) { $GLOBALS['stub_post_types'][ $key ] = $args; }
function register_taxonomy() {}
function register_taxonomy_for_object_type() {}
function register_block_type( $path, $args = array() ) { $GLOBALS['stub_blocks'][] = $path; }
function wp_set_script_translations() {}
function flush_rewrite_rules() {}
function wp_enqueue_media() {}

// --- Options / meta ------------------------------------------------------.
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['stub_options'] ) ? $GLOBALS['stub_options'][ $key ] : $default; }
function update_option( $key, $value ) { $GLOBALS['stub_options'][ $key ] = $value; return true; }
function add_option( $key, $value ) { return update_option( $key, $value ); }
function delete_option( $key ) { unset( $GLOBALS['stub_options'][ $key ] ); }
function get_post_meta( $id, $key = '', $single = false ) {
	// Matching WordPress: with no key, every meta value of the post, each
	// wrapped in an array.
	if ( '' === $key ) {
		$all = isset( $GLOBALS['stub_meta'][ $id ] ) ? $GLOBALS['stub_meta'][ $id ] : array();
		$out = array();
		foreach ( $all as $k => $v ) { $out[ $k ] = array( $v ); }
		return $out;
	}
	return isset( $GLOBALS['stub_meta'][ $id ][ $key ] ) ? $GLOBALS['stub_meta'][ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $value ) { $GLOBALS['stub_meta'][ $id ][ $key ] = $value; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['stub_meta'][ $id ][ $key ] ); }

// --- Posts / attachments -------------------------------------------------.
function get_post( $id ) { return isset( $GLOBALS['stub_posts'][ $id ] ) ? (object) $GLOBALS['stub_posts'][ $id ] : null; }
function get_post_type( $id ) { $p = get_post( $id ); return $p ? $p->post_type : ''; }
function get_post_mime_type( $post ) { $id = is_object( $post ) ? $post->ID : $post; $p = get_post( $id ); return $p && isset( $p->post_mime_type ) ? $p->post_mime_type : ''; }
function wp_get_attachment_url( $id ) { $p = get_post( $id ); return $p && isset( $p->url ) ? $p->url : false; }
function get_attached_file( $id ) { $p = get_post( $id ); return $p && isset( $p->file ) ? $p->file : false; }
function get_the_title( $id = 0 ) { $p = get_post( $id ); return $p && isset( $p->post_title ) ? $p->post_title : 'Untitled'; }
function get_the_ID() { return isset( $GLOBALS['stub_current'] ) ? $GLOBALS['stub_current'] : 0; }
function get_permalink( $id = 0 ) {
	$p = get_post( $id );
	if ( $p && isset( $p->post_name ) && '' !== $p->post_name ) {
		$prefix = isset( $GLOBALS['stub_permalink_prefix'][ $p->post_type ] ) ? $GLOBALS['stub_permalink_prefix'][ $p->post_type ] : 'pdf';
		return 'https://example.test/' . $prefix . '/' . $p->post_name . '/';
	}
	return 'https://example.test/pdf/doc-' . $id . '/';
}
$GLOBALS['stub_permalink_prefix'] = array( 'tnc_flipbook' => 'flipbook', 'pdf_document' => 'pdf' );
function has_post_thumbnail( $id = 0 ) { return false; }
function get_post_thumbnail_id( $id = 0 ) { return 0; }
function wp_get_attachment_image() { return '<img src="cover.jpg" alt="" />'; }
function get_the_excerpt( $id = 0 ) { return 'Excerpt text.'; }
function get_the_date( $format = '', $id = 0 ) { return '11. 8. 2026'; }
function get_the_term_list() { return '<a href="#">Reports</a>'; }
function post_class( $class = '', $id = null ) { echo 'class="' . ( is_array( $class ) ? implode( ' ', $class ) : $class ) . '"'; }
function wp_reset_postdata() {
	// Mirrors WordPress: the loop's current post is restored afterwards.
	if ( ! empty( $GLOBALS['stub_reset_stack'] ) ) {
		$GLOBALS['stub_current'] = array_pop( $GLOBALS['stub_reset_stack'] );
	}
}
$GLOBALS['stub_reset_stack'] = array();
class WP_Error {
	protected $code;
	protected $message;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function paginate_links( $args ) { return '<a class="page-numbers" href="#">2</a>'; }

class WP_Query {
	public $args;
	public $max_num_pages = 1;
	protected $index = -1;
	protected $ids;
	public function __construct( $args = array() ) { $this->args = $args; $this->ids = isset( $GLOBALS['stub_query_ids'] ) ? $GLOBALS['stub_query_ids'] : array(); }
	public function have_posts() { return $this->index + 1 < count( $this->ids ); }
	protected $saved = null;
	public function the_post() {
		if ( null === $this->saved ) {
			$this->saved = isset( $GLOBALS['stub_current'] ) ? $GLOBALS['stub_current'] : 0;
			$GLOBALS['stub_reset_stack'][] = $this->saved;
		}
		$this->index++;
		$GLOBALS['stub_current'] = $this->ids[ $this->index ];
	}
	public function get( $key ) { return isset( $this->args[ $key ] ) ? $this->args[ $key ] : ''; }
	public function set( $key, $value ) { $this->args[ $key ] = $value; }
	public function is_main_query() { return false; }
	public function is_search() { return ! empty( $this->args['s'] ); }
	public function is_post_type_archive( $t = '' ) { return false; }
	public function is_home() { return false; }
	public function is_feed() { return false; }
	public function is_category() { return false; }
	public function is_tag() { return false; }
	public function is_author() { return false; }
	public function is_date() { return false; }
}

// --- Sanitizing / escaping ----------------------------------------------.
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_title( $v ) {
	$v = (string) $v;
	if ( function_exists( 'iconv' ) ) {
		$converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $v );
		if ( false !== $converted ) { $v = $converted; }
	}
	$v = strtolower( str_replace( array( ' ', '_' ), '-', $v ) );
	return trim( preg_replace( '/-+/', '-', preg_replace( '/[^a-z0-9\-]/', '', $v ) ), '-' );
}
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_html_class( $v ) { return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $v ); }
function sanitize_file_name( $v ) { return preg_replace( '/[^A-Za-z0-9_\-\.]/', '-', (string) $v ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_url( $v ) { return (string) $v; }
function esc_url_raw( $v ) { return (string) $v; }
function esc_textarea( $v ) { return htmlspecialchars( (string) $v ); }
function wp_kses_post( $v ) { return $v; }
function wp_unslash( $v ) { return $v; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_trim_words( $text, $n = 55 ) { return $text; }
function size_format( $bytes, $decimals = 0 ) { return round( $bytes / 1024, $decimals ) . ' KB'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
function trailingslashit( $v ) { return rtrim( (string) $v, '/\\' ) . '/'; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/wp-pdf-reader/'; }
function plugin_basename( $file ) { return 'wp-pdf-reader/wp-pdf-reader.php'; }
function checked( $a, $b = true, $echo = true ) { return $a == $b ? ' checked' : ''; }
function selected( $a, $b = true, $echo = true ) { return $a == $b ? ' selected' : ''; }

// --- i18n ----------------------------------------------------------------.
function __( $text, $domain = '' ) { return $text; }
function _e( $text, $domain = '' ) { echo $text; }
function _x( $text, $ctx, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
function esc_attr__( $text, $domain = '' ) { return esc_attr( $text ); }
function esc_html_e( $text, $domain = '' ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = '' ) { echo esc_attr( $text ); }

// --- Conditionals / assets ----------------------------------------------.
function is_admin() { return false; }
function is_singular( $types = '' ) { return ! empty( $GLOBALS['stub_is_singular'] ); }
function is_post_type_archive( $t = '' ) { return false; }
function in_the_loop() { return true; }
function is_main_query() { return true; }
function is_multisite() { return false; }
function get_locale() { return 'cs_CZ'; }
function determine_locale() { return 'cs_CZ'; }
function wp_register_script() {}
function wp_register_style() {}
function wp_localize_script() {}
function wp_enqueue_script( $h ) { $GLOBALS['stub_enqueued'][] = $h; }
function wp_enqueue_style( $h ) { $GLOBALS['stub_enqueued'][] = $h; }
function locate_template( $templates ) { return ''; }
function current_user_can( $cap = '', $object_id = 0 ) {
	// Tests that care about a capability list the ones the current user is
	// missing; everything else stays permitted so the rest keeps working.
	if ( isset( $GLOBALS['stub_denied_caps'] ) && in_array( $cap, (array) $GLOBALS['stub_denied_caps'], true ) ) {
		return false;
	}

	if ( 'read_post' === $cap && isset( $GLOBALS['stub_unreadable_posts'] ) && in_array( (int) $object_id, (array) $GLOBALS['stub_unreadable_posts'], true ) ) {
		return false;
	}

	return true;
}
function wp_nonce_field() {}
function wp_verify_nonce() { return true; }
function wp_is_post_revision() { return false; }
function wp_is_post_autosave() { return false; }
function get_current_screen() { return null; }
function wp_upload_dir() { return array( 'path' => '/tmp', 'basedir' => '/tmp', 'baseurl' => 'https://example.test/uploads', 'subdir' => '', 'error' => false ); }
function wp_unique_filename( $dir, $name ) { return $name; }
function wp_insert_attachment() { return 0; }
function wp_update_attachment_metadata() {}
function wp_generate_attachment_metadata() { return array(); }
function wp_delete_file( $f ) {}
function wp_delete_attachment() {}
function get_header() {}
function get_footer() {}
function comments_open() { return false; }
function get_comments_number() { return 0; }
function comments_template() {}
function post_type_archive_title() { echo 'PDF documents'; }
function get_the_post_type_description() { return ''; }
function have_posts() { return false; }
function the_post() {}
function the_title() { echo get_the_title( get_the_ID() ); }
function the_content() { echo '<p>Content</p>'; }
function clean_post_cache() {}
function wp_generate_password( $n = 12, $s = true, $e = true ) { return str_repeat( 'a', $n ); }
function get_temp_dir() { return sys_get_temp_dir() . '/'; }
function is_user_logged_in() { return ! empty( $GLOBALS['stub_logged_in'] ); }
function status_header( $c ) { $GLOBALS['stub_status'] = $c; }
function nocache_headers() {}
function wp_die( $m = '', $t = '', $a = array() ) { throw new RuntimeException( 'wp_die:' . ( isset( $a['response'] ) ? $a['response'] : 0 ) ); }
function get_query_var( $v, $d = '' ) { return isset( $GLOBALS['stub_query_vars'][ $v ] ) ? $GLOBALS['stub_query_vars'][ $v ] : $d; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function update_attached_file( $id, $file ) { $GLOBALS['stub_posts'][ $id ]['file'] = $file; return true; }
function wp_using_ext_object_cache() { return false; }
function _prime_post_caches( $ids, $terms = true, $meta = true ) { $GLOBALS['stub_primed'] = array_merge( isset( $GLOBALS['stub_primed'] ) ? $GLOBALS['stub_primed'] : array(), (array) $ids ); }
function update_meta_cache( $type, $ids ) { return true; }
function wp_get_attachment_metadata( $id ) { return isset( $GLOBALS['stub_attachment_meta'][ $id ] ) ? $GLOBALS['stub_attachment_meta'][ $id ] : array(); }
function wp_login_url( $r = '' ) { return 'https://example.test/wp-login.php'; }
function user_trailingslashit( $v ) { return rtrim( (string) $v, '/' ) . '/'; }
function untrailingslashit( $v ) { return rtrim( (string) $v, '/' ); }
function wp_doing_ajax() { return false; }
function get_term( $id, $tax = '' ) {
	foreach ( $GLOBALS['stub_terms_db'] as $tid => $term ) {
		if ( $tid === (int) $id && ( '' === $tax || $term['taxonomy'] === $tax ) ) {
			return (object) array_merge( array( 'term_id' => $tid ), $term );
		}
	}
	return null;
}
class WP_Term { public $term_id; public $taxonomy; public $slug; public $name; }
function get_post_type_object( $t ) { return null; }
function maybe_unserialize( $v ) { return is_string( $v ) && preg_match( '/^[aOs]:/', $v ) ? @unserialize( $v ) : $v; }
function attachment_url_to_postid( $u ) { foreach ( $GLOBALS['stub_posts'] as $id => $p ) { if ( isset( $p['url'] ) && $p['url'] === $u ) { return $id; } } return 0; }
function set_post_thumbnail( $post_id, $thumb ) { return true; }
function wp_get_object_terms( $id, $tax, $args = array() ) { return array(); }
function wp_set_object_terms( $id, $terms, $tax, $append = false ) {
	if ( ! $append || ! isset( $GLOBALS['stub_object_terms'][ $id ][ $tax ] ) ) { $GLOBALS['stub_object_terms'][ $id ][ $tax ] = array(); }
	foreach ( (array) $terms as $t ) { $GLOBALS['stub_object_terms'][ $id ][ $tax ][] = (int) $t; }
	return true;
}
function is_object_in_taxonomy( $post_type, $tax ) { return in_array( $tax, array( 'category', 'post_tag' ), true ); }
function wp_list_pluck( $list, $field ) { $out = array(); foreach ( (array) $list as $item ) { $out[] = is_object( $item ) ? $item->$field : $item[ $field ]; } return $out; }
function is_taxonomy_hierarchical( $tax ) { return 'category' === $tax; }
function get_term_by( $field, $value, $tax ) {
	foreach ( $GLOBALS['stub_terms_db'] as $id => $term ) {
		if ( $term['taxonomy'] === $tax && $term[ $field ] === $value ) { return (object) array_merge( array( 'term_id' => $id ), $term ); }
	}
	return false;
}
function wp_insert_term( $name, $tax, $args = array() ) {
	$id = 900 + count( $GLOBALS['stub_terms_db'] );
	$GLOBALS['stub_terms_db'][ $id ] = array( 'name' => $name, 'slug' => isset( $args['slug'] ) ? $args['slug'] : sanitize_title( $name ), 'taxonomy' => $tax, 'parent' => isset( $args['parent'] ) ? $args['parent'] : 0 );
	return array( 'term_id' => $id );
}
$GLOBALS['stub_terms_db'] = array();
$GLOBALS['stub_object_terms'] = array();
$GLOBALS['stub_term_rows'] = array();
function wp_send_json_success( $d ) { $GLOBALS['stub_json'] = $d; }
function wp_send_json_error( $d, $c = 0 ) { $GLOBALS['stub_json'] = $d; }
function check_ajax_referer( $a, $b ) { return true; }
function wp_safe_redirect( $u, $s = 302 ) { $GLOBALS['stub_redirect'] = $u; return true; }
$GLOBALS['stub_primed'] = array();
function get_terms( $args = array() ) { return $GLOBALS['stub_terms']; }
function get_post_type_archive_link( $t ) { return 'https://example.test/pdf/'; }
$GLOBALS['stub_terms'] = array( (object) array( 'slug' => 'vyrocni-zpravy', 'name' => 'Výroční zprávy' ) );
function wp_cache_get( $k, $g = '' ) { return false; }
function wp_cache_set( $k, $v, $g = '', $t = 0 ) { return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }
$GLOBALS['stub_query_vars'] = array();
function seems_utf8( $s ) { return (bool) preg_match( '//u', $s ); }
function wp_check_invalid_utf8( $s, $strip = false ) {
	// As WordPress does: valid UTF-8 comes back untouched, only broken input
	// is stripped.
	if ( 1 === @preg_match( '/^./us', (string) $s ) ) {
		return (string) $s;
	}
	return $strip && function_exists( 'iconv' ) ? (string) @iconv( 'utf-8', 'utf-8//IGNORE', (string) $s ) : '';
}
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function get_transient( $k ) { return isset( $GLOBALS['stub_transients'][ $k ] ) ? $GLOBALS['stub_transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['stub_transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['stub_transients'][ $k ] ); }
function get_site_transient( $k ) { return get_transient( $k ); }
function set_site_transient( $k, $v, $t = 0 ) { return set_transient( $k, $v, $t ); }
function delete_site_transient( $k ) { delete_transient( $k ); }
function wp_next_scheduled( $hook, $args = array() ) { return isset( $GLOBALS['stub_cron'][ $hook . md5( serialize( $args ) ) ] ); }
function wp_schedule_single_event( $when, $hook, $args = array() ) { $GLOBALS['stub_cron'][ $hook . md5( serialize( $args ) ) ] = $args; return true; }
function register_rest_route() {}
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); }
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function get_bloginfo( $what = '' ) { return '6.5'; }
function get_edit_post_link( $id, $ctx = '' ) { return 'https://example.test/wp-admin/post.php?post=' . (int) $id; }
function wp_dropdown_categories() {}
function _n( $single, $plural, $number, $domain = '' ) { return 1 === (int) $number ? $single : $plural; }
function get_queried_object_id() { return get_the_ID(); }
function wp_get_attachment_image_url() { return 'https://example.test/cover.jpg'; }
function get_the_modified_date( $f = '', $id = 0 ) { return '2026-08-11T00:00:00+00:00'; }
function get_the_author_meta() { return 'Pavel'; }
function get_post_field( $field, $id ) { return 1; }
function wpautop( $s ) { return $s; }
function wp_set_post_terms() { return true; }
function wp_insert_post( $args, $error = false ) { $id = 500 + count( $GLOBALS['stub_posts'] ); $GLOBALS['stub_posts'][ $id ] = array_merge( array( 'ID' => $id ), $args ); return $id; }

if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

$GLOBALS['stub_transients'] = array();
$GLOBALS['stub_cron'] = array();

/**
 * Call a protected static method.
 *
 * @param string $class  Class name.
 * @param string $method Method name.
 * @param array  $args   Arguments.
 * @return mixed
 */
function call_protected( $class, $method, array $args = array() ) {
	$reflection = new ReflectionMethod( $class, $method );
	$reflection->setAccessible( true );

	return $reflection->invokeArgs( null, $args );
}

/**
 * Build a tiny but valid PDF holding one line of text.
 *
 * @param string $text Text to place on the page.
 * @return string Path to the file.
 */
function make_pdf( $text ) {
	$content = "BT /F1 24 Tf 72 760 Td (" . $text . ") Tj ET";
	$pdf  = "%PDF-1.4\n";
	$pdf .= "1 0 obj <</Type /Catalog /Pages 2 0 R>> endobj\n";
	$pdf .= "2 0 obj <</Type /Pages /Kids [3 0 R] /Count 1>> endobj\n";
	$pdf .= "3 0 obj <</Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R>> endobj\n";
	$pdf .= "4 0 obj <</Length " . strlen( $content ) . ">>\nstream\n" . $content . "\nendstream endobj\n";
	$pdf .= "5 0 obj <</Type /Font /Subtype /Type1 /BaseFont /Helvetica>> endobj\n";
	$pdf .= "trailer <</Root 1 0 R>>\n%%EOF\n";

	$path = sys_get_temp_dir() . '/wppdf-smoke-' . md5( $text ) . '.pdf';
	file_put_contents( $path, $pdf );

	return $path;
}

class Stub_WPDB {
	public $posts = 'wp_posts';
	public $blogs = 'wp_blogs';
	public $postmeta = 'wp_postmeta';
	public function update( $table, $data, $where ) { return 3; }
	public function esc_like( $t ) { return addcslashes( (string) $t, '_%\\' ); }
	public function query( $q ) { return 1; }
	public function get_col( $q ) { return array( 10, 11 ); }
	public function get_var( $q ) {
		// "Did anything get imported at all", the guard in front of the lookup.
		if ( false !== strpos( $q, 'SELECT 1 FROM' ) ) {
			return 1;
		}

		// The redirect lookup asks for a post by the import meta keys in one
		// ranked query: the old path and its unslashed form first, the bare
		// slug last. The stub answers in that same order so the test still
		// checks which candidate wins.
		if ( false !== strpos( $q, '_wppdf_imported_path' ) || false !== strpos( $q, '_wppdf_imported_slug' ) ) {
			preg_match_all( "/'([^']*)'/", $q, $m );

			foreach ( array( '_wppdf_imported_path', '_wppdf_imported_slug' ) as $key ) {
				if ( false === strpos( $q, $key ) ) {
					continue;
				}

				foreach ( $m[1] as $wanted ) {
					foreach ( $GLOBALS['stub_meta'] as $post_id => $meta ) {
						if ( isset( $meta[ $key ] ) && (string) $meta[ $key ] === (string) $wanted ) {
							return $post_id;
						}
					}
				}
			}

			return null;
		}

		return 2;
	}
	public function get_results( $q ) { return isset( $GLOBALS['stub_term_rows'] ) ? $GLOBALS['stub_term_rows'] : array(); }
	public function get_row( $q ) { return isset( $GLOBALS['stub_term_parent'] ) ? $GLOBALS['stub_term_parent'] : null; }
	public $term_relationships = 'wp_term_relationships';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $terms = 'wp_terms';
	public function prepare( $q, ...$a ) {
		if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; }
		foreach ( $a as $value ) {
			$q = preg_replace( '/%[sd]/', is_int( $value ) ? (string) $value : "'" . $value . "'", $q, 1 );
		}
		return $q;
	}
}
$GLOBALS['wpdb'] = new Stub_WPDB();

// --- Fixtures ------------------------------------------------------------.
$GLOBALS['stub_posts'] = array(
	10 => array( 'ID' => 10, 'post_type' => 'pdf_document', 'post_title' => 'Výroční zpráva 2025' ),
	11 => array( 'ID' => 11, 'post_type' => 'pdf_document', 'post_title' => 'Jen anglicky' ),
	20 => array( 'ID' => 20, 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf', 'url' => 'https://example.test/uploads/cs.pdf', 'file' => '/tmp/nonexistent-cs.pdf', 'post_title' => 'cs.pdf' ),
	21 => array( 'ID' => 21, 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf', 'url' => 'https://example.test/uploads/en.pdf', 'file' => '/tmp/nonexistent-en.pdf', 'post_title' => 'en.pdf' ),
	22 => array( 'ID' => 22, 'post_type' => 'attachment', 'post_mime_type' => 'image/png', 'url' => 'https://example.test/uploads/not-a-pdf.png' ),
);

require dirname( __DIR__ ) . '/wp-pdf-reader.php';

function ok( $label, $condition ) {
	echo ( $condition ? '  PASS  ' : '  FAIL  ' ) . $label . "\n";
	if ( ! $condition ) {
		$GLOBALS['stub_failed'] = true;
	}
}

echo "== Settings ==\n";
$settings = WPPDF_Settings::all();
ok( 'defaults loaded', 'pdf_document' === $settings['post_type_key'] );
ok( 'default language is cs', 'cs' === WPPDF_Languages::get_default_language() );
ok( 'languages are cs+en', array( 'cs', 'en' ) === WPPDF_Languages::get_codes() );

echo "\n== Fallback order ==\n";
echo '  cs → ' . implode( ', ', WPPDF_Languages::get_fallback_order( 'cs' ) ) . "\n";
echo '  en → ' . implode( ', ', WPPDF_Languages::get_fallback_order( 'en' ) ) . "\n";
echo '  de → ' . implode( ', ', WPPDF_Languages::get_fallback_order( 'de' ) ) . "\n";
echo '  en-gb → ' . implode( ', ', WPPDF_Languages::get_fallback_order( 'en-gb' ) ) . "\n";
ok( 'cs first for cs', 'cs' === WPPDF_Languages::get_fallback_order( 'cs' )[0] );
ok( 'en first for en', 'en' === WPPDF_Languages::get_fallback_order( 'en' )[0] );
ok( 'unknown language keeps itself then the chain', array( 'de', 'cs', 'en' ) === WPPDF_Languages::get_fallback_order( 'de' ) );
ok( 'regional variant maps to base', 'en' === WPPDF_Languages::get_fallback_order( 'en-gb' )[0] );

echo "\n== File resolution ==\n";
update_post_meta( 10, '_wppdf_file_cs', 20 );
update_post_meta( 10, '_wppdf_file_en', 21 );
update_post_meta( 11, '_wppdf_file_en', 21 );

$cs = WPPDF_Documents::get_file( 10, 'cs' );
ok( 'cs document resolves to the cs file', $cs && 'cs' === $cs['lang'] && ! $cs['is_fallback'] );

$en = WPPDF_Documents::get_file( 10, 'en' );
ok( 'en document resolves to the en file', $en && 'en' === $en['lang'] );

$fallback = WPPDF_Documents::get_file( 11, 'cs' );
ok( 'missing cs falls back to en', $fallback && 'en' === $fallback['lang'] && $fallback['is_fallback'] );

$de = WPPDF_Documents::get_file( 11, 'de' );
ok( 'unknown language falls back to en', $de && 'en' === $de['lang'] );

update_post_meta( 11, '_wppdf_url_cs', 'https://cdn.example.test/external-cs.pdf' );
WPPDF_Documents::flush_cache();
$external = WPPDF_Documents::get_file( 11, 'cs' );
ok( 'external URL is used', $external && 'https://cdn.example.test/external-cs.pdf' === $external['url'] && ! $external['is_fallback'] );

update_post_meta( 10, '_wppdf_file_cs', 22 );
WPPDF_Documents::flush_cache();
$invalid = WPPDF_Documents::get_file( 10, 'cs' );
ok( 'non-PDF attachment is rejected and falls back', $invalid && 'en' === $invalid['lang'] );
update_post_meta( 10, '_wppdf_file_cs', 20 );
WPPDF_Documents::flush_cache();

ok( 'available languages listed', array( 'cs', 'en' ) === WPPDF_Documents::get_available_languages( 10 ) );
ok( 'document without files resolves to null', null === WPPDF_Documents::get_file( 999 ) );

echo "\n== Viewer markup ==\n";
$html = WPPDF_Viewer::render( 10, array( 'lang' => 'cs' ) );
ok( 'viewer renders', false !== strpos( $html, 'wppdf-viewer' ) );
ok( 'viewer carries the file url', false !== strpos( $html, 'uploads\/cs.pdf' ) || false !== strpos( $html, 'uploads/cs.pdf' ) );
ok( 'toolbar rendered', false !== strpos( $html, 'wppdf-page-input' ) );
ok( 'no fallback notice for a matching language', false === strpos( $html, 'wppdf-viewer__fallback' ) );
ok( 'assets enqueued', in_array( 'wppdf-viewer', $GLOBALS['stub_enqueued'], true ) );

$html_fallback = WPPDF_Viewer::render( 11, array( 'lang' => 'de' ) );
ok( 'fallback notice rendered', false !== strpos( $html_fallback, 'wppdf-viewer__fallback' ) );

$html_none = WPPDF_Viewer::render( 999 );
ok( 'no markup without a file', '' === $html_none );

$config = array();
if ( preg_match( '/data-wppdf="([^"]+)"/', $html, $m ) ) {
	$config = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
}
ok( 'config is valid JSON', is_array( $config ) && isset( $config['url'] ) );
echo '  config: ' . json_encode( $config, JSON_UNESCAPED_SLASHES ) . "\n";

echo "\n== Shortcodes ==\n";
$shortcodes = new WPPDF_Shortcodes();
$reader = $shortcodes->reader( array( 'id' => 10, 'height' => 500, 'toolbar' => '0' ) );
ok( 'reader shortcode renders', false !== strpos( $reader, '--wppdf-height: 500px' ) );
ok( 'toolbar can be switched off', false === strpos( $reader, 'wppdf-page-input' ) );

$download = $shortcodes->download( array( 'id' => 10, 'text' => 'Stáhnout' ) );
ok( 'download shortcode renders', false !== strpos( $download, 'wppdf-download-link' ) );
echo '  ' . $download . "\n";

$GLOBALS['stub_query_ids'] = array( 10, 11 );
$grid = $shortcodes->grid( array( 'columns' => 2 ) );
ok( 'grid renders both cards', 2 === substr_count( $grid, '<article' ) );
ok( 'grid uses the requested column count', false !== strpos( $grid, 'wppdf-collection--cols-2' ) );

$GLOBALS['stub_query_ids'] = array();
$empty = $shortcodes->grid( array() );
ok( 'empty grid shows a message', false !== strpos( $empty, 'wppdf-empty' ) );

echo "\n== Settings sanitizing ==\n";
$sanitizer = new WPPDF_Settings();
$clean = $sanitizer->sanitize(
	array(
		'post_type_key'    => 'My Docs!',
		'languages'        => array(
			array( 'code' => 'CS', 'label' => 'Čeština' ),
			array( 'code' => 'en_GB', 'label' => 'British' ),
			array( 'code' => '', 'label' => 'ignored' ),
		),
		'default_language' => 'cs',
		'fallback_chain'   => 'cs, en-gb, xx',
		'viewer_height'    => '99999',
		'archive_columns'  => '12',
		'show_toolbar'     => '1',
	)
);
ok( 'post type key sanitized', 'mydocs' === $clean['post_type_key'] );
ok( 'language codes normalised', array( 'cs', 'en-gb' ) === array_column( $clean['languages'], 'code' ) );
ok( 'unknown fallback codes dropped', array( 'cs', 'en-gb' ) === $clean['fallback_chain'] );
ok( 'height clamped', 4000 === $clean['viewer_height'] );
ok( 'columns clamped', 6 === $clean['archive_columns'] );
ok( 'unchecked boxes become 0', 0 === $clean['allow_download'] );

$reserved = $sanitizer->sanitize( array( 'post_type_key' => 'post' ) );
ok( 'reserved key rejected', 'pdf_document' === $reserved['post_type_key'] );

echo "\n== Post type ==\n";
$post_type = new WPPDF_Post_Type();
$post_type->register();
ok( 'post type registered', isset( $GLOBALS['stub_post_types']['pdf_document'] ) );
$args = $GLOBALS['stub_post_types']['pdf_document'];
ok( 'shares post categories', in_array( 'category', $args['taxonomies'], true ) );
ok( 'archive enabled', 'pdf' === $args['has_archive'] );
ok( 'labels applied', 'PDF documents' === $args['labels']['name'] );


echo "\n== PDF text extraction ==\n";
$pdf_path = make_pdf( 'Vyrocni zprava 2025 o hospodareni spolecnosti' );
$extracted = WPPDF_Text::extract( $pdf_path );
ok( 'text extracted from a PDF', false !== strpos( $extracted, 'Vyrocni zprava 2025' ) );
echo '  extracted: ' . $extracted . "\n";
ok( 'page count read', 1 === WPPDF_Text::count_pages( $pdf_path ) );

$garbage = call_protected( 'WPPDF_Text', 'normalise', array( "\x01\x02 ??? ### %%% @@@ ~~~ ^^^ ||| <<< >>> ¤¤¤ §§§" ) );
ok( 'mojibake is not indexed', '' === $garbage );

$clean = call_protected( 'WPPDF_Text', 'normalise', array( "  Zpráva   o\n\nhospodaření  za rok 2025.  " ) );
ok( 'whitespace collapsed', 'Zpráva o hospodaření za rok 2025.' === $clean );

$long = call_protected( 'WPPDF_Text', 'normalise', array( str_repeat( 'slovo ', 100000 ) ) );
ok( 'stored text is capped', strlen( $long ) <= WPPDF_Text::MAX_CHARS );

ok( 'text meta key derived from language', '_wppdf_text_en_gb' === WPPDF_Text::text_meta_key( 'en-GB' ) );

echo "\n== Search widening ==\n";
$search_obj = new WPPDF_Search();
$GLOBALS['stub_query_ids'] = array();
$search_query = new WP_Query( array( 's' => 'zpráva', 'post_type' => 'pdf_document' ) );
$search_query->searching = true;
$original = " AND (((wp_posts.post_title LIKE '%zpráva%') OR (wp_posts.post_excerpt LIKE '%zpráva%') OR (wp_posts.post_content LIKE '%zpráva%')))";
$widened  = $search_obj->search( $original, $search_query );
ok( 'search clause reaches the PDF text', false !== strpos( $widened, 'wppdf_text.meta_value LIKE' ) );
ok( 'original title clause kept', false !== strpos( $widened, 'wp_posts.post_title' ) );
$join = $search_obj->join( '', $search_query );
ok( 'join restricted to the text meta keys', false !== strpos( $join, 'LEFT JOIN' ) && false !== strpos( $join, '\\_wppdf' ) );
ok( 'distinct applied', 'DISTINCT' === $search_obj->distinct( '', $search_query ) );

$other = new WP_Query( array( 's' => 'zpráva', 'post_type' => 'page' ) );
$other->searching = true;
ok( 'other post types are left alone', $original === $search_obj->search( $original, $other ) );

echo "\n== Statistics ==\n";
ok( 'view meta key', '_wppdf_views_cs' === WPPDF_Stats::meta_key( 'view', 'cs' ) );
ok( 'download meta key', '_wppdf_downloads_en' === WPPDF_Stats::meta_key( 'download', 'en' ) );
update_post_meta( 10, '_wppdf_views_cs', 12 );
update_post_meta( 10, '_wppdf_views_en', 3 );
ok( 'views summed across languages', 15 === WPPDF_Stats::get( 10, 'view' ) );
ok( 'breakdown lists only used languages', array( 'cs', 'en' ) === array_keys( WPPDF_Stats::get_breakdown( 10 ) ) );

echo "\n== GitHub updater ==\n";
ok( 'https github asset accepted', true === call_protected( 'WPPDF_Updater', 'is_allowed_package', array( 'https://github.com/o/r/releases/download/1.1.0/p.zip' ) ) );
ok( 'foreign host rejected', false === call_protected( 'WPPDF_Updater', 'is_allowed_package', array( 'https://evil.example/p.zip' ) ) );
ok( 'plain http rejected', false === call_protected( 'WPPDF_Updater', 'is_allowed_package', array( 'http://github.com/o/r/p.zip' ) ) );

$release = call_protected(
	'WPPDF_Updater',
	'parse',
	array(
		array(
			'tag_name'    => 'v1.2.0',
			'html_url'    => 'https://github.com/o/r/releases/tag/v1.2.0',
			'zipball_url' => 'https://api.github.com/repos/o/r/zipball/v1.2.0',
			'body'        => 'Notes',
		),
		'o/r',
	)
);
ok( 'version parsed without the v prefix', '1.2.0' === $release['version'] );
ok( 'package taken from the zipball', false !== strpos( $release['package'], 'api.github.com' ) );

$bad = call_protected( 'WPPDF_Updater', 'parse', array( array( 'tag_name' => 'release-candidate; rm -rf', 'zipball_url' => 'https://api.github.com/x' ), 'o/r' ) );
ok( 'nonsense tag rejected', ! isset( $bad['version'] ) );

$hijack = call_protected( 'WPPDF_Updater', 'parse', array( array( 'tag_name' => '9.9.9', 'zipball_url' => 'https://attacker.example/evil.zip' ), 'o/r' ) );
ok( 'package on a foreign host rejected', ! isset( $hijack['version'] ) );

// Dots are legal in GitHub names, so the repository pattern alone would let
// "../.." build an API path that climbs out of /repos/.
$repo_settings = WPPDF_Settings::all();

foreach ( array( '../..', '.', 'owner/..', '../repo' ) as $bad_repo ) {
	$repo_settings['github_repository'] = $bad_repo;
	update_option( WPPDF_Settings::OPTION, $repo_settings );
	WPPDF_Settings::flush_cache();

	ok( 'traversal repository refused: ' . $bad_repo, '' === WPPDF_Updater::get_repository() );
}

$repo_settings['github_repository'] = 'pavelapki/WPpdfReader';
update_option( WPPDF_Settings::OPTION, $repo_settings );
WPPDF_Settings::flush_cache();
ok( 'a normal repository is still accepted', 'pavelapki/WPpdfReader' === WPPDF_Updater::get_repository() );

echo "\n== Importer ==\n";
$GLOBALS['stub_posts'][30] = array( 'ID' => 30, 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf', 'post_title' => 'vyrocni_zprava-2025', 'file' => '/tmp/x.pdf' );
ok( 'title cleaned from the file name', 'Vyrocni zprava 2025' === WPPDF_Importer::title_from_attachment( 30 ) );

echo "\n== Reader markup additions ==\n";
update_post_meta( 11, '_wppdf_file_cs', 20 );
WPPDF_Documents::flush_cache();
$switcher = WPPDF_Viewer::render( 11 );
ok( 'language switcher rendered for two versions', false !== strpos( $switcher, 'wppdf-language-select' ) );
ok( 'search box rendered', false !== strpos( $switcher, 'wppdf-search-input' ) );
ok( 'live region present', false !== strpos( $switcher, 'wppdf-live' ) );
$switcher_config = array();
if ( preg_match( '/data-wppdf="([^"]+)"/', $switcher, $m ) ) {
	$switcher_config = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
}
ok( 'sources passed to the reader', isset( $switcher_config['sources'] ) && 2 === count( $switcher_config['sources'] ) );


echo "\n== External binaries run without a shell ==\n";
if ( is_executable( '/bin/echo' ) ) {
	$echoed = call_protected( 'WPPDF_Text', 'run_binary', array( array( '/bin/echo', 'hello world' ) ) );
	ok( 'binary output captured', 'hello world' === trim( (string) $echoed ) );

	// Passing the command as an array means there is no shell to expand this.
	$injected = call_protected( 'WPPDF_Text', 'run_binary', array( array( '/bin/echo', '$(id); rm -rf /tmp/nope' ) ) );
	ok( 'shell metacharacters stay literal', '$(id); rm -rf /tmp/nope' === trim( (string) $injected ) );
} else {
	echo "  SKIP  /bin/echo not available\n";
}

$hostile = make_pdf( 'Bezpecny obsah' );
$canary = sys_get_temp_dir() . '/wppdf-pwned';
@unlink( $canary );
$hostile_name = sys_get_temp_dir() . '/wppdf smoke; touch ' . basename( $canary ) . ' $(id).pdf';
copy( $hostile, $hostile_name );
$hostile_text = WPPDF_Text::extract( $hostile_name );
ok( 'file name with metacharacters still extracts', false !== strpos( $hostile_text, 'Bezpecny obsah' ) );
ok( 'nothing was executed', ! file_exists( $canary ) && ! file_exists( getcwd() . '/wppdf-pwned' ) );
@unlink( $hostile_name );


echo "\n== Sidebar, links and print markup ==\n";
$full = WPPDF_Viewer::render( 10 );
ok( 'sidebar rendered', false !== strpos( $full, 'wppdf-viewer__sidebar' ) );
ok( 'thumbnail panel rendered', false !== strpos( $full, 'wppdf-thumbs' ) );
ok( 'outline panel rendered', false !== strpos( $full, 'wppdf-outline' ) );
ok( 'print dialog rendered', false !== strpos( $full, 'wppdf-print-dialog' ) );
ok( 'share button rendered', false !== strpos( $full, 'wppdf-share' ) );

echo "\n== Protected documents ==\n";
ok( 'documents are public by default', false === WPPDF_Protection::is_protected( 10 ) );

$public_url = WPPDF_Documents::get_file( 10, 'cs' );
ok( 'public document keeps its direct URL', false !== strpos( $public_url['url'], '/uploads/cs.pdf' ) );

update_post_meta( 10, WPPDF_Protection::META, 1 );
WPPDF_Documents::flush_cache();
$protection = new WPPDF_Protection();
add_filter( 'wppdf_file_url', array( $protection, 'filter_file_url' ) );
$rewritten = $protection->filter_file_url( 'https://example.test/uploads/cs.pdf', 10, 'cs' );
ok( 'protected document is served through the endpoint', false !== strpos( $rewritten, 'wppdf_file=10' ) && false !== strpos( $rewritten, 'wppdf_lang=cs' ) );
ok( 'direct upload path is gone from the URL', false === strpos( $rewritten, '/uploads/' ) );

$GLOBALS['stub_logged_in'] = false;
ok( 'logged out visitor is refused', false === WPPDF_Protection::current_user_can_read( 10 ) );
$GLOBALS['stub_logged_in'] = true;
ok( 'logged in visitor is allowed', true === WPPDF_Protection::current_user_can_read( 10 ) );
delete_post_meta( 10, WPPDF_Protection::META );
WPPDF_Documents::flush_cache();

echo "\n== Backfill ==\n";
$backfill_pdf = make_pdf( 'Doindexovany dokument s textem' );
$GLOBALS['stub_posts'][40] = array( 'ID' => 40, 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf', 'url' => 'https://example.test/uploads/backfill.pdf', 'file' => $backfill_pdf, 'post_title' => 'backfill.pdf' );
$GLOBALS['stub_posts'][12] = array( 'ID' => 12, 'post_type' => 'pdf_document', 'post_title' => 'Starý dokument' );
update_post_meta( 12, '_wppdf_file_cs', 40 );
WPPDF_Documents::flush_cache();

ok( 'document starts without an index', '' === get_post_meta( 12, WPPDF_Text::text_meta_key( 'cs' ), true ) );
WPPDF_Reindex::index_document( 12, false, false );
$indexed_text = get_post_meta( 12, WPPDF_Text::text_meta_key( 'cs' ), true );
ok( 'backfill stored the text', false !== strpos( (string) $indexed_text, 'Doindexovany dokument' ) );
ok( 'backfill stored the page count', 1 === (int) get_post_meta( 12, WPPDF_Text::pages_meta_key( 'cs' ), true ) );


echo "\n== Archive filters ==\n";
$_GET = array(
	WPPDF_Filters::VAR_SEARCH   => '  hospodaření  ',
	WPPDF_Filters::VAR_CATEGORY => 'Výroční Zprávy',
	WPPDF_Filters::VAR_LANGUAGE => 'EN',
	WPPDF_Filters::VAR_YEAR     => '2025',
	WPPDF_Filters::VAR_SORT     => 'title',
);

$selected = WPPDF_Filters::get_current();
ok( 'search term trimmed', 'hospodaření' === $selected['search'] );
ok( 'category turned into a slug', 'vyrocni-zpravy' === $selected['category'] );
ok( 'language code normalised', 'en' === $selected['language'] );
ok( 'year read as a number', 2025 === $selected['year'] );
ok( 'filters reported as active', true === WPPDF_Filters::is_filtered() );

$filter_args = WPPDF_Filters::apply_to_args( array( 'posts_per_page' => 12 ) );
ok( 'search passed to the query', 'hospodaření' === $filter_args['s'] );
ok( 'category became a tax query', 'category' === $filter_args['tax_query'][0]['taxonomy'] && array( 'vyrocni-zpravy' ) === $filter_args['tax_query'][0]['terms'] );
ok( 'language became a meta query', 'OR' === $filter_args['meta_query'][0]['relation'] && '_wppdf_file_en' === $filter_args['meta_query'][0][0]['key'] );
ok( 'year became a date query', 2025 === $filter_args['date_query'][0]['year'] );
ok( 'sorting applied', 'title' === $filter_args['orderby'] && 'ASC' === $filter_args['order'] );
ok( 'other arguments survive', 12 === $filter_args['posts_per_page'] );

// A term the filter does not know about must not reach the query.
$_GET[ WPPDF_Filters::VAR_LANGUAGE ] = 'zz';
$_GET[ WPPDF_Filters::VAR_SORT ]     = '; DROP TABLE';
// The values are read once per request, so the test says when the request
// changed.
WPPDF_Filters::flush_cache();
$hostile = WPPDF_Filters::get_current();
ok( 'unknown language dropped', '' === $hostile['language'] );
ok( 'unknown sort dropped', '' === $hostile['sort'] );
$hostile_args = WPPDF_Filters::apply_to_args( array() );
ok( 'no meta query without a language', ! isset( $hostile_args['meta_query'] ) );
ok( 'no ordering without a sort', ! isset( $hostile_args['orderby'] ) );

// The filter sets only the term, so the query is not a search query — the
// text join has to key off the term itself.
$filtered_query = new WP_Query( array( 's' => 'hospodaření', 'post_type' => 'pdf_document' ) );
$search_widened = ( new WPPDF_Search() )->search(
	" AND (((wp_posts.post_title LIKE '%hospoda%')))",
	$filtered_query
);
ok( 'filtered archive still searches inside PDFs', false !== strpos( $search_widened, 'wppdf_text.meta_value' ) );

$GLOBALS['stub_query_ids'] = array( 10 );
$filter_form = ( new WPPDF_Shortcodes() )->grid( array( 'filters' => '1' ) );
ok( 'shortcode renders the filter form', false !== strpos( $filter_form, 'wppdf-filters' ) );
ok( 'form offers the category', false !== strpos( $filter_form, 'vyrocni-zpravy' ) );
ok( 'form offers the languages', false !== strpos( $filter_form, 'wppdf-filter-language' ) );

$_GET = array();
WPPDF_Filters::flush_cache();
ok( 'no filters means nothing is active', false === WPPDF_Filters::is_filtered() );
ok( 'no filters leaves the query untouched', array() === WPPDF_Filters::apply_to_args( array() ) );


echo "\n== Cache priming ==\n";
$GLOBALS['stub_primed'] = array();
$GLOBALS['stub_posts'][60] = array( 'ID' => 60, 'post_type' => 'pdf_document', 'post_title' => 'Karta' );
update_post_meta( 60, '_wppdf_file_cs', 20 );
update_post_meta( 60, '_wppdf_file_en', 21 );
update_post_meta( 60, '_wppdf_cover_cs', 22 );

$documents = new WPPDF_Documents();
$documents->prime_from_query( array( (object) array( 'ID' => 60, 'post_type' => 'pdf_document' ) ) );

ok( 'files primed in one batch', in_array( 20, $GLOBALS['stub_primed'], true ) && in_array( 21, $GLOBALS['stub_primed'], true ) );
ok( 'covers primed too', in_array( 22, $GLOBALS['stub_primed'], true ) );

$GLOBALS['stub_primed'] = array();
$documents->prime_from_query( array( (object) array( 'ID' => 5, 'post_type' => 'page' ) ) );
ok( 'other post types are skipped', array() === $GLOBALS['stub_primed'] );

echo "\n== Reindex counting ==\n";
ok( 'counting returns a number, not a list of IDs', is_int( WPPDF_Reindex::count_documents( false ) ) );

echo "\n== Who may attach which file ==\n";
// The file field carries an attachment ID from the browser, so an editor
// naming a file they cannot read must not get it republished.
$GLOBALS['stub_posts'][61] = array( 'ID' => 61, 'post_type' => 'pdf_document', 'post_title' => 'Práva' );
$_POST = array(
	'wppdf_nonce' => 'x',
	'wppdf_file'  => array( 'cs' => 20 ),
);

$GLOBALS['stub_unreadable_posts'] = array( 20 );
( new WPPDF_Meta() )->save( 61, (object) array( 'ID' => 61, 'post_type' => 'pdf_document' ) );
ok( 'a file the editor cannot read is refused', '' === (string) get_post_meta( 61, '_wppdf_file_cs', true ) );

$GLOBALS['stub_unreadable_posts'] = array();
( new WPPDF_Meta() )->save( 61, (object) array( 'ID' => 61, 'post_type' => 'pdf_document' ) );
ok( 'a file the editor may read is stored', 20 === (int) get_post_meta( 61, '_wppdf_file_cs', true ) );

$_POST = array();

// The import screen reads the media library, so it takes the capability that
// grants the library and not just the one that creates posts.
$GLOBALS['stub_denied_caps'] = array( 'upload_files' );
ok( 'without upload_files the import is refused', false === call_protected( 'WPPDF_Importer', 'user_can_import' ) );

$GLOBALS['stub_denied_caps'] = array( 'edit_posts' );
ok( 'without edit_posts the import is refused', false === call_protected( 'WPPDF_Importer', 'user_can_import' ) );

$GLOBALS['stub_denied_caps'] = array();
ok( 'with both the import is allowed', true === call_protected( 'WPPDF_Importer', 'user_can_import' ) );

echo "\n== Protected delivery ==\n";
// realpath() needs the file to exist, so the test creates its own.
$inside = sys_get_temp_dir() . '/wppdf-inside.pdf';
file_put_contents( $inside, '%PDF-1.4' );
ok( 'a path inside uploads is accepted', true === call_protected( 'WPPDF_Protection', 'is_inside_uploads', array( $inside ) ) );
@unlink( $inside );
ok( 'a path outside uploads is refused', false === call_protected( 'WPPDF_Protection', 'is_inside_uploads', array( '/etc/passwd' ) ) );
ok( 'a traversal attempt is refused', false === call_protected( 'WPPDF_Protection', 'is_inside_uploads', array( '/tmp/../etc/passwd' ) ) );


echo "\n== Import from another plugin ==\n";

// A TNC FlipBook record as that plugin actually stores it: CPT tnc_flipbook,
// meta prefixed _tncfb3d_, the PDF as an attachment ID.
$tnc_pdf = make_pdf( 'Katalog produktu 2025' );
$GLOBALS['stub_posts'][70] = array( 'ID' => 70, 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf', 'url' => 'https://example.test/uploads/katalog.pdf', 'file' => $tnc_pdf, 'post_title' => 'katalog.pdf' );
$GLOBALS['stub_posts'][71] = array(
	'ID' => 71, 'post_type' => 'tnc_flipbook', 'post_title' => 'Katalog 2025', 'post_name' => 'katalog-2025', 'post_content' => 'Popis katalogu',
	'post_excerpt' => 'Stručně', 'post_status' => 'publish', 'post_date' => '2025-03-01 10:00:00',
	'post_date_gmt' => '2025-03-01 09:00:00', 'post_author' => 1, 'menu_order' => 3,
);
update_post_meta( 71, '_tncfb3d_source_type', 'pdf' );
update_post_meta( 71, '_tncfb3d_pdf_id', 70 );
update_post_meta( 71, '_tncfb3d_text_page_count', 24 );
update_post_meta( 71, '_tncfb3d_extracted_text', "Katalog   produktu\n\n2025 s cenami" );

$described = WPPDF_Migrator::describe( 71, 'tnc_flipbook' );
ok( 'adapter finds the PDF', array( 70 ) === $described['attachments'] );
ok( 'adapter takes over the page count', 24 === $described['pages'] );
ok( 'adapter takes over the text', false !== strpos( $described['text'], 'Katalog' ) );

$migrated = WPPDF_Migrator::import( 71, array( 'language' => 'cs', 'status' => 'source' ) );
ok( 'record imported', is_array( $migrated ) && $migrated['id'] > 0 );

$new_id = $migrated['id'];
ok( 'title carried over', 'Katalog 2025' === get_the_title( $new_id ) );
ok( 'PDF placed in the chosen language', 70 === (int) get_post_meta( $new_id, '_wppdf_file_cs', true ) );
ok( 'page count reused', 24 === (int) get_post_meta( $new_id, WPPDF_Text::pages_meta_key( 'cs' ), true ) );
ok( 'text index reused, whitespace collapsed', 'Katalog produktu 2025 s cenami' === get_post_meta( $new_id, WPPDF_Text::text_meta_key( 'cs' ), true ) );
ok( 'source recorded', 71 === (int) get_post_meta( $new_id, WPPDF_Migrator::META_SOURCE_ID, true ) );
ok( 'source type recorded', 'tnc_flipbook' === get_post_meta( $new_id, WPPDF_Migrator::META_SOURCE_TYPE, true ) );

// An image-only flipbook holds no PDF at all.
$GLOBALS['stub_posts'][72] = array( 'ID' => 72, 'post_type' => 'tnc_flipbook', 'post_title' => 'Galerie', 'post_name' => 'galerie', 'post_content' => '', 'post_excerpt' => '', 'post_status' => 'publish', 'post_date' => '2025-01-01 00:00:00', 'post_date_gmt' => '2025-01-01 00:00:00', 'post_author' => 1, 'menu_order' => 0 );
update_post_meta( 72, '_tncfb3d_source_type', 'images' );
update_post_meta( 72, '_tncfb3d_image_ids', array( 22, 23 ) );

$image_only = WPPDF_Migrator::import( 72, array( 'language' => 'cs', 'status' => 'draft' ) );
ok( 'image only flipbook is refused, not half imported', is_wp_error( $image_only ) );

// Multi PDF: the first is placed, the rest are reported rather than guessed.
$GLOBALS['stub_posts'][73] = array( 'ID' => 73, 'post_type' => 'tnc_flipbook', 'post_title' => 'Dvojjazyčný', 'post_name' => 'dvojjazycny', 'post_content' => '', 'post_excerpt' => '', 'post_status' => 'publish', 'post_date' => '2025-01-01 00:00:00', 'post_date_gmt' => '2025-01-01 00:00:00', 'post_author' => 1, 'menu_order' => 0 );
update_post_meta( 73, '_tncfb3d_pdf_ids', array( array( 'id' => 70, 'name' => 'cs' ), array( 'id' => 21, 'name' => 'en' ) ) );

$multi = WPPDF_Migrator::import( 73, array( 'language' => 'cs', 'status' => 'draft' ) );
ok( 'first PDF of a multi record is placed', 70 === (int) get_post_meta( $multi['id'], '_wppdf_file_cs', true ) );
ok( 'the remaining PDF is reported, not guessed at', ! empty( $multi['notes'] ) );

// The generic path has to cope with a plugin whose keys are unknown.
$GLOBALS['stub_posts'][74] = array( 'ID' => 74, 'post_type' => 'some_flipbook', 'post_name' => 'neznamy-plugin', 'post_title' => 'Neznámý plugin', 'post_content' => '', 'post_excerpt' => '', 'post_status' => 'publish', 'post_date' => '2025-01-01 00:00:00', 'post_date_gmt' => '2025-01-01 00:00:00', 'post_author' => 1, 'menu_order' => 0 );
update_post_meta( 74, 'some_random_key', array( 'settings' => array( 'file' => 21 ) ) );

$generic = WPPDF_Migrator::describe( 74, 'some_flipbook' );
ok( 'generic path finds a PDF nested in meta', in_array( 21, $generic['attachments'], true ) );

$GLOBALS['stub_posts'][75] = array( 'ID' => 75, 'post_type' => 'some_flipbook', 'post_name' => 'externi', 'post_title' => 'Externí', 'post_content' => '', 'post_excerpt' => '', 'post_status' => 'publish', 'post_date' => '2025-01-01 00:00:00', 'post_date_gmt' => '2025-01-01 00:00:00', 'post_author' => 1, 'menu_order' => 0 );
update_post_meta( 75, 'pdf_url', 'https://cdn.example.test/manual.pdf' );

$external = WPPDF_Migrator::describe( 75, 'some_flipbook' );
ok( 'a URL that is not in the library becomes an external link', 'https://cdn.example.test/manual.pdf' === $external['url'] );

$GLOBALS['stub_posts'][76] = array( 'ID' => 76, 'post_type' => 'some_flipbook', 'post_name' => 'bez-pdf', 'post_title' => 'Bez PDF', 'post_content' => '', 'post_excerpt' => '', 'post_status' => 'publish', 'post_date' => '2025-01-01 00:00:00', 'post_date_gmt' => '2025-01-01 00:00:00', 'post_author' => 1, 'menu_order' => 0 );
update_post_meta( 76, 'colour', 'blue' );
update_post_meta( 76, 'image', 22 );
$nothing = WPPDF_Migrator::describe( 76, 'some_flipbook' );
ok( 'a non-PDF attachment is not mistaken for one', array() === $nothing['attachments'] && '' === $nothing['url'] );


echo "\n== Categories survive the migration ==\n";

// TNC Classic keeps its categories in its own taxonomy. With the plugin
// switched off that taxonomy is no longer registered, but the rows remain.
$GLOBALS['stub_term_rows'] = array(
	(object) array( 'term_id' => 501, 'name' => 'Výroční zprávy', 'slug' => 'vyrocni-zpravy', 'taxonomy' => 'tnc_category', 'parent' => 0, 'description' => '' ),
	(object) array( 'term_id' => 502, 'name' => 'Katalogy', 'slug' => 'katalogy', 'taxonomy' => 'tnc_category', 'parent' => 0, 'description' => '' ),
);

$grouped = WPPDF_Migrator::get_source_terms( 71 );
ok( 'terms are read even from an unregistered taxonomy', isset( $grouped['tnc_category'] ) && 2 === count( $grouped['tnc_category'] ) );

$GLOBALS['stub_terms_db'] = array();
$GLOBALS['stub_object_terms'] = array();
$GLOBALS['stub_posts'][80] = array( 'ID' => 80, 'post_type' => 'tnc_flipbook', 'post_title' => 'S kategoriemi', 'post_name' => 's-kategoriemi', 'post_content' => '', 'post_excerpt' => '', 'post_status' => 'publish', 'post_date' => '2025-01-01 00:00:00', 'post_date_gmt' => '2025-01-01 00:00:00', 'post_author' => 1, 'menu_order' => 0 );
update_post_meta( 80, '_tncfb3d_pdf_id', 70 );

$with_terms = WPPDF_Migrator::import( 80, array( 'language' => 'cs', 'status' => 'draft' ) );
$assigned = isset( $GLOBALS['stub_object_terms'][ $with_terms['id'] ]['category'] ) ? $GLOBALS['stub_object_terms'][ $with_terms['id'] ]['category'] : array();
ok( 'foreign categories land in our category taxonomy', 2 === count( $assigned ) );
ok( 'the terms were created with their original names', 'Výroční zprávy' === $GLOBALS['stub_terms_db'][ $assigned[0] ]['name'] );
ok( 'and their original slugs', 'vyrocni-zpravy' === $GLOBALS['stub_terms_db'][ $assigned[0] ]['slug'] );

// A second record with the same categories must reuse them, not duplicate.
$created_before = count( $GLOBALS['stub_terms_db'] );
$GLOBALS['stub_posts'][81] = array( 'ID' => 81, 'post_type' => 'tnc_flipbook', 'post_title' => 'Další', 'post_name' => 'dalsi', 'post_content' => '', 'post_excerpt' => '', 'post_status' => 'publish', 'post_date' => '2025-01-01 00:00:00', 'post_date_gmt' => '2025-01-01 00:00:00', 'post_author' => 1, 'menu_order' => 0 );
update_post_meta( 81, '_tncfb3d_pdf_id', 70 );
WPPDF_Migrator::import( 81, array( 'language' => 'cs', 'status' => 'draft' ) );
ok( 'a second record reuses the same terms', $created_before === count( $GLOBALS['stub_terms_db'] ) );

// A taxonomy documents already use is copied straight across.
$GLOBALS['stub_term_rows'] = array(
	(object) array( 'term_id' => 601, 'name' => 'Zprávy', 'slug' => 'zpravy', 'taxonomy' => 'category', 'parent' => 0, 'description' => '' ),
);
$GLOBALS['stub_object_terms'] = array();
$GLOBALS['stub_posts'][82] = array( 'ID' => 82, 'post_type' => 'tnc_flipbook', 'post_title' => 'Nativní kategorie', 'post_name' => 'nativni-kategorie', 'post_content' => '', 'post_excerpt' => '', 'post_status' => 'publish', 'post_date' => '2025-01-01 00:00:00', 'post_date_gmt' => '2025-01-01 00:00:00', 'post_author' => 1, 'menu_order' => 0 );
update_post_meta( 82, '_tncfb3d_pdf_id', 70 );
$native = WPPDF_Migrator::import( 82, array( 'language' => 'cs', 'status' => 'draft' ) );
ok( 'a native category keeps its own term ID', array( 601 ) === $GLOBALS['stub_object_terms'][ $native['id'] ]['category'] );

$GLOBALS['stub_term_rows'] = array();


echo "\n== Addresses survive the migration ==\n";

$GLOBALS['stub_posts'][90] = array(
	'ID' => 90, 'post_type' => 'tnc_flipbook', 'post_name' => 'vyrocni-zprava-2024',
	'post_title' => 'Výroční zpráva 2024', 'post_content' => '', 'post_excerpt' => '',
	'post_status' => 'publish', 'post_date' => '2024-06-01 00:00:00', 'post_date_gmt' => '2024-06-01 00:00:00',
	'post_author' => 1, 'menu_order' => 0,
);
update_post_meta( 90, '_tncfb3d_pdf_id', 70 );

$moved = WPPDF_Migrator::import( 90, array( 'language' => 'cs', 'status' => 'source' ) );
$moved_post = get_post( $moved['id'] );

ok( 'the record keeps its own slug', 'vyrocni-zprava-2024' === $moved_post->post_name );
ok( 'the old slug is remembered', 'vyrocni-zprava-2024' === get_post_meta( $moved['id'], WPPDF_Migrator::META_SLUG, true ) );
ok( 'the old path is remembered', '/flipbook/vyrocni-zprava-2024/' === get_post_meta( $moved['id'], WPPDF_Migrator::META_PATH, true ) );

echo '  old: /flipbook/vyrocni-zprava-2024/' . "\n";
echo '  new: ' . wp_parse_url( get_permalink( $moved['id'] ), PHP_URL_PATH ) . "\n";

echo "\n== Taking over the URL prefix ==\n";
ok( 'documents start under their own prefix', 'pdf' === WPPDF_Settings::get( 'post_type_slug' ) );
ok( 'the prefix is adopted', true === WPPDF_Migrator::adopt_slug( 'flipbook' ) );
ok( 'the setting really changed', 'flipbook' === WPPDF_Settings::get( 'post_type_slug' ) );
ok( 'permalinks are queued for a flush', 1 === (int) get_option( 'wppdf_flush_rewrite' ) );

$GLOBALS['stub_permalink_prefix']['pdf_document'] = 'flipbook';
ok(
	'the old address now resolves to the document itself',
	'/flipbook/vyrocni-zprava-2024/' === wp_parse_url( get_permalink( $moved['id'] ), PHP_URL_PATH )
);

ok( 'an empty prefix is refused', false === WPPDF_Migrator::adopt_slug( '   ' ) );

// Reset for anything that runs later.
$settings_after = WPPDF_Settings::all();
$settings_after['post_type_slug'] = 'pdf';
update_option( WPPDF_Settings::OPTION, $settings_after );
WPPDF_Settings::flush_cache();
$GLOBALS['stub_permalink_prefix']['pdf_document'] = 'pdf';

echo "\n== Old addresses that do not line up ==\n";
$redirects = new WPPDF_Redirects();
ok( 'the redirect handler is wired to template_redirect', method_exists( $redirects, 'maybe_redirect' ) );

$found = call_protected( 'WPPDF_Redirects', 'find_document', array( '/flipbook/vyrocni-zprava-2024/' ) );
ok( 'the full old path finds its document', $moved['id'] === $found );

$by_slug = call_protected( 'WPPDF_Redirects', 'find_document', array( '/nejaka/jina/cesta/vyrocni-zprava-2024/' ) );
ok( 'a changed prefix still finds it by slug', $moved['id'] === $by_slug );

$nowhere = call_protected( 'WPPDF_Redirects', 'find_document', array( '/flipbook/neexistuje/' ) );
ok( 'an unknown address stays a 404', 0 === $nowhere );


echo "\n== Documents belonging to a page ==\n";

$GLOBALS['stub_terms_db'] = array(
	11 => array( 'name' => 'Výroční zprávy', 'slug' => 'vyrocni-zpravy', 'taxonomy' => 'category', 'parent' => 0 ),
	12 => array( 'name' => 'Katalogy', 'slug' => 'katalogy', 'taxonomy' => 'category', 'parent' => 0 ),
);

$acf_settings = WPPDF_Settings::all();
$acf_settings['acf_category_field'] = 'pdf_kategorie';
update_option( WPPDF_Settings::OPTION, $acf_settings );
WPPDF_Settings::flush_cache();

$GLOBALS['stub_posts'][200] = array( 'ID' => 200, 'post_type' => 'page', 'post_title' => 'Ke stažení' );

// Taxonomy field, return format Term ID — the recommended setup.
update_post_meta( 200, 'pdf_kategorie', array( 11, 12 ) );
ok( 'term IDs are read', array( 11, 12 ) === WPPDF_Acf::get_term_ids( 200 ) );

// Same field with return format Term Object.
$term_object = new WP_Term();
$term_object->term_id = 11;
$term_object->taxonomy = 'category';
update_post_meta( 200, 'pdf_kategorie', array( $term_object ) );
ok( 'term objects are read', array( 11 ) === WPPDF_Acf::get_term_ids( 200 ) );

// A Select or Checkbox field hands back slugs.
update_post_meta( 200, 'pdf_kategorie', array( 'katalogy' ) );
ok( 'slugs are matched to terms', array( 12 ) === WPPDF_Acf::get_term_ids( 200 ) );

// A plain text field, comma separated.
update_post_meta( 200, 'pdf_kategorie', 'vyrocni-zpravy, katalogy' );
ok( 'a comma separated list is split', array( 11, 12 ) === WPPDF_Acf::get_term_ids( 200 ) );

// Names work too, for a Checkbox field storing labels.
update_post_meta( 200, 'pdf_kategorie', 'Katalogy' );
ok( 'names are matched as well', array( 12 ) === WPPDF_Acf::get_term_ids( 200 ) );

// A single value, not an array.
update_post_meta( 200, 'pdf_kategorie', 11 );
ok( 'a single value is accepted', array( 11 ) === WPPDF_Acf::get_term_ids( 200 ) );

// Nonsense must not become a term.
update_post_meta( 200, 'pdf_kategorie', array( 'neexistuje', 99999, '' ) );
ok( 'unknown values are dropped', array() === WPPDF_Acf::get_term_ids( 200 ) );

delete_post_meta( 200, 'pdf_kategorie' );
ok( 'an empty field yields nothing', array() === WPPDF_Acf::get_term_ids( 200 ) );

$GLOBALS['stub_current'] = 200;
$GLOBALS['stub_query_ids'] = array( 10 );
$empty_page = ( new WPPDF_Shortcodes() )->grid( array( 'from_field' => '1' ) );
ok( 'a page without categories lists nothing, not everything', '' === $empty_page );

update_post_meta( 200, 'pdf_kategorie', array( 11 ) );
$page_grid = ( new WPPDF_Shortcodes() )->grid( array( 'from_field' => '1' ) );
ok( 'a page with categories lists its documents', false !== strpos( $page_grid, 'wppdf-collection' ) );

ok( 'the template tag returns the same markup', $page_grid === wppdf_get_page_documents() );
ok( 'the categories are exposed to templates', array( 11 ) === wppdf_get_page_categories( 200 ) );

$acf_settings['acf_category_field'] = '';
update_option( WPPDF_Settings::OPTION, $acf_settings );
WPPDF_Settings::flush_cache();
$GLOBALS['stub_current'] = 0;

echo "\n";
echo empty( $GLOBALS['stub_failed'] ) ? "ALL CHECKS PASSED\n" : "SOME CHECKS FAILED\n";
exit( empty( $GLOBALS['stub_failed'] ) ? 0 : 1 );

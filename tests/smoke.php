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

// Site-wide GitHub updaters install the repository archive rather than the
// release zip, so this file can end up inside a live plugin directory. It
// defines ABSPATH and stubs core functions, which is exactly what must never
// happen in response to a web request.
if ( 'cli' !== PHP_SAPI ) {
	header( 'HTTP/1.1 403 Forbidden' );
	exit( 1 );
}

define( 'ABSPATH', '/tmp/fake-wp/' );
define( 'OBJECT', 'OBJECT' );
define( 'MB_IN_BYTES', 1048576 );

$GLOBALS['stub_options'] = array();
$GLOBALS['stub_meta']    = array();
$GLOBALS['stub_posts']   = array();
$GLOBALS['stub_actions'] = array();
$GLOBALS['stub_enqueued'] = array();
$GLOBALS['stub_filters']  = array();
$GLOBALS['stub_filter_callbacks'] = array();

// --- Hooks ---------------------------------------------------------------.
function add_action( $tag, $cb, $priority = 10, $args = 1 ) { $GLOBALS['stub_actions'][ $tag ][] = $cb; }
function remove_action( $tag, $cb, $priority = 10 ) { return true; }
function add_filter( $tag, $cb, $priority = 10, $args = 1 ) { $GLOBALS['stub_actions'][ $tag ][] = $cb; }
function has_filter( $tag, $cb = false ) { return isset( $GLOBALS['stub_actions'][ $tag ] ); }
function apply_filters( $tag, $value ) {
	if ( array_key_exists( $tag, $GLOBALS['stub_filters'] ) ) {
		return $GLOBALS['stub_filters'][ $tag ];
	}

	// Tests that need a hook to really fire register the callback themselves.
	if ( ! empty( $GLOBALS['stub_filter_callbacks'][ $tag ] ) ) {
		$args = array_slice( func_get_args(), 1 );

		foreach ( $GLOBALS['stub_filter_callbacks'][ $tag ] as $callback ) {
			$args[0] = call_user_func_array( $callback, $args );
		}

		return $args[0];
	}

	return $value;
}
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
function get_post( $id ) {
	// Core accepts an ID or a post object; the stub has to as well, or code
	// that legitimately passes an object blows up only here.
	if ( is_object( $id ) ) { return $id; }
	return isset( $GLOBALS['stub_posts'][ $id ] ) ? (object) $GLOBALS['stub_posts'][ $id ] : null;
}
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
// Mirrors WordPress: get_post_thumbnail_id() applies the filter, and
// has_post_thumbnail() is built on it — which is how a careless cover filter
// would recurse.
function get_post_thumbnail_id( $id = 0 ) {
	$stored = (int) get_post_meta( $id, '_thumbnail_id', true );
	return (int) apply_filters( 'post_thumbnail_id', $stored, $id );
}
function has_post_thumbnail( $id = 0 ) { return (bool) get_post_thumbnail_id( $id ); }
function wp_get_attachment_image() { return '<img src="cover.jpg" alt="" />'; }
function get_the_excerpt( $id = 0 ) { return isset( $GLOBALS['stub_excerpt'] ) ? $GLOBALS['stub_excerpt'] : 'Excerpt text.'; }
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
function is_admin() { return ! empty( $GLOBALS["stub_is_admin"] ); }
function is_singular( $types = '' ) { return ! empty( $GLOBALS['stub_is_singular'] ); }
function is_post_type_archive( $t = '' ) { return false; }
function in_the_loop() { return true; }
function is_main_query() { return true; }
function is_multisite() { return false; }
function get_locale() { return isset( $GLOBALS['stub_locale'] ) ? $GLOBALS['stub_locale'] : 'cs_CZ'; }
function determine_locale() { return isset( $GLOBALS['stub_locale'] ) ? $GLOBALS['stub_locale'] : 'cs_CZ'; }
function wp_register_script() {}
function wp_register_style() {}
function wp_localize_script() {}
function wp_enqueue_script( $h ) { $GLOBALS['stub_enqueued'][] = $h; }
function wp_enqueue_style( $h ) { $GLOBALS['stub_enqueued'][] = $h; }
function locate_template( $templates ) { return ''; }
function wp_get_referer() { return isset( $GLOBALS['stub_referer'] ) ? $GLOBALS['stub_referer'] : false; }
function get_page_by_path( $path, $output = OBJECT, $post_type = 'page' ) {
	foreach ( $GLOBALS['stub_posts'] as $post ) {
		if ( isset( $post['post_name'] ) && $post['post_name'] === $path && $post['post_type'] === $post_type ) {
			return (object) $post;
		}
	}
	return null;
}
function get_post_status( $id ) { $p = get_post( $id ); return $p && isset( $p->post_status ) ? $p->post_status : false; }
function get_post_status_object( $status ) {
	$public = array( 'publish' );
	return $status ? (object) array( 'name' => $status, 'public' => in_array( $status, $public, true ) ) : null;
}
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
function mysql2date( $format, $date, $translate = true ) { return date( $format, strtotime( $date ) ); }
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
	public function get_col( $q ) {
		// The migrator's "not imported yet" query, which the picker tests drive
		// with their own records.
		if ( isset( $GLOBALS['stub_pending_ids'] ) && false !== strpos( $q, '_wppdf_import_skipped' ) ) {
			return $GLOBALS['stub_pending_ids'];
		}

		return array( 10, 11 );
	}
	public function get_var( $q ) {
		// "Did anything get imported at all", the guard in front of the lookup.
		if ( false !== strpos( $q, 'SELECT 1 FROM' ) ) {
			return 1;
		}

		// Per-language slugs: "does another document already answer on this
		// address", and "which document does this address belong to". The
		// meta_key arrives through esc_like(), so the underscores are escaped.
		$plain = str_replace( '\\', '', $q );

		if ( false !== strpos( $plain, '_wppdf_slug_' ) ) {
			preg_match_all( "/'([^']*)'/", $plain, $m );
			$slug = isset( $m[1][1] ) ? $m[1][1] : '';
			preg_match( '/post_id != (\d+)/', $plain, $x );
			$exclude = isset( $x[1] ) ? (int) $x[1] : 0;

			foreach ( $GLOBALS['stub_meta'] as $pid => $meta ) {
				if ( (int) $pid === $exclude ) {
					continue;
				}

				foreach ( $meta as $key => $value ) {
					if ( 0 === strpos( $key, '_wppdf_slug_' ) && (string) $value === $slug ) {
						return $pid;
					}
				}
			}

			return null;
		}

		if ( false !== strpos( $q, 'post_name =' ) ) {
			preg_match( "/post_name = '([^']*)'/", $q, $m );
			preg_match( '/ID != (\d+)/', $q, $x );
			$wanted  = isset( $m[1] ) ? $m[1] : '';
			$exclude = isset( $x[1] ) ? (int) $x[1] : 0;

			foreach ( $GLOBALS['stub_posts'] as $pid => $post ) {
				if ( (int) $pid === $exclude ) {
					continue;
				}

				if ( isset( $post['post_name'] ) && $post['post_name'] === $wanted && false !== strpos( $q, "'" . $post['post_type'] . "'" ) ) {
					return $pid;
				}
			}

			return null;
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
	public function get_results( $q ) {
		$rows = isset( $GLOBALS['stub_term_rows'] ) ? $GLOBALS['stub_term_rows'] : array();

		// The term lookup asks for many records at once and groups the answer
		// by object_id, so the stub has to say which record each row belongs
		// to. Every queried record gets the fixture's terms.
		if ( $rows && preg_match( '/tr\.object_id IN \(([^)]*)\)/', $q, $m ) ) {
			$ids  = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $m[1] ) ), 'is_numeric' ) );
			$rows = array();

			foreach ( $ids as $id ) {
				foreach ( $GLOBALS['stub_term_rows'] as $row ) {
					$copy            = clone $row;
					$copy->object_id = $id;
					$rows[]          = $copy;
				}
			}
		}

		return $rows;
	}
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

// The rule people actually want: the site's own language, and English when
// there is no file in it. It hangs on the default language, which is both the
// end of the chain and where a site language missing from the list lands.
$lang_before = WPPDF_Settings::all();
$to_english  = array_merge(
	$lang_before,
	array(
		'default_language' => 'en',
		'fallback_chain'   => array( 'en' ),
		'fallback_any'     => 0,
	)
);
update_option( WPPDF_Settings::OPTION, $to_english );
WPPDF_Settings::flush_cache();
WPPDF_Languages::flush_cache();

$GLOBALS['stub_locale'] = 'cs_CZ';
ok( 'a Czech site asks for Czech and falls back to English', array( 'cs', 'en' ) === WPPDF_Languages::get_fallback_order() );

$GLOBALS['stub_locale'] = 'en_US';
ok( 'an English site stays on English', array( 'en' ) === WPPDF_Languages::get_fallback_order() );

// The case that motivated this: a language with no PDFs at all, and not even
// in the configured list. Without the default set to English it would land on
// Czech instead.
$GLOBALS['stub_locale'] = 'bg_BG';
ok( 'a Bulgarian site is served English, not the first configured language', array( 'en' ) === WPPDF_Languages::get_fallback_order() );

$GLOBALS['stub_locale'] = 'de_DE';
ok( 'so is any other unconfigured language', array( 'en' ) === WPPDF_Languages::get_fallback_order() );

// With Czech as the default — the shipped setting — the same Bulgarian site
// gets Czech. Asserted so the difference between the two setups is a fact
// rather than a claim in the documentation.
update_option( WPPDF_Settings::OPTION, $lang_before );
WPPDF_Settings::flush_cache();
WPPDF_Languages::flush_cache();

$GLOBALS['stub_locale'] = 'bg_BG';
ok( 'with Czech as the default the same site gets Czech first', 'cs' === WPPDF_Languages::get_fallback_order()[0] );

unset( $GLOBALS['stub_locale'] );
WPPDF_Languages::flush_cache();

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

// A site-wide updater reads the GitHub Plugin URI header and manages the
// plugin itself. Both updaters answering would mean the offered version and
// the unpack directory depend on filter order, so the built-in one stands
// down. It reports through get_repository(), which every other path goes
// through, so silencing it there silences all of them.
ok( 'nothing else present, so the built-in updater stays on', false === WPPDF_Updater::handled_elsewhere() );

$GLOBALS['stub_filters']['wppdf_updates_handled_elsewhere'] = true;
ok( 'the filter hands updates over', true === WPPDF_Updater::handled_elsewhere() );
ok( 'handing over silences the built-in updater', '' === WPPDF_Updater::get_repository() );
unset( $GLOBALS['stub_filters']['wppdf_updates_handled_elsewhere'] );
ok( 'and it comes back when the filter is gone', 'pavelapki/WPpdfReader' === WPPDF_Updater::get_repository() );

// The header a site-wide updater discovers the plugin by must actually be in
// the main file, and must name the repository the releases are published to.
$header = file_get_contents( __DIR__ . '/../wp-pdf-reader.php' );
ok( 'the main file carries the GitHub Plugin URI header', (bool) preg_match( '/^ \* GitHub Plugin URI: pavelapki\/WPpdfReader$/m', $header ) );

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
	(object) array( 'object_id' => 71, 'term_id' => 501, 'name' => 'Výroční zprávy', 'slug' => 'vyrocni-zpravy', 'taxonomy' => 'tnc_category', 'parent' => 0, 'description' => '' ),
	(object) array( 'object_id' => 71, 'term_id' => 502, 'name' => 'Katalogy', 'slug' => 'katalogy', 'taxonomy' => 'tnc_category', 'parent' => 0, 'description' => '' ),
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


echo "\n== Picking which records to migrate ==\n";

// A source usually holds records with no PDF — stub translations and the
// like. The screen lists what each record holds so those can be left out.
update_option( 'date_format', 'Y-m-d' );

$GLOBALS['stub_posts'][150] = array( 'ID' => 150, 'post_type' => 'tnc_flipbook', 'post_title' => 'Má PDF', 'post_name' => 'ma-pdf', 'post_content' => '', 'post_excerpt' => '', 'post_status' => 'publish', 'post_date' => '2025-03-04 00:00:00', 'post_date_gmt' => '2025-03-04 00:00:00', 'post_author' => 1, 'menu_order' => 0 );
$GLOBALS['stub_posts'][151] = array( 'ID' => 151, 'post_type' => 'tnc_flipbook', 'post_title' => 'Prázdný překlad', 'post_name' => 'prazdny', 'post_content' => '', 'post_excerpt' => '', 'post_status' => 'draft', 'post_date' => '2025-03-05 00:00:00', 'post_date_gmt' => '2025-03-05 00:00:00', 'post_author' => 1, 'menu_order' => 0 );
update_post_meta( 150, '_tncfb3d_pdf_id', 70 );
$GLOBALS['stub_pending_ids'] = array( 150, 151 );

$preview = WPPDF_Migrator::preview( 'tnc_flipbook', 25, 0 );
ok( 'the preview lists the pending records', 2 === count( $preview ) );
ok( 'a record holding a PDF is marked as such', true === $preview[0]['hasPdf'] );
ok( 'and the file name is shown', '' !== $preview[0]['file'] );
ok( 'a record without a PDF is marked too', false === $preview[1]['hasPdf'] );
ok( 'the status is carried over so drafts are visible', 'draft' === $preview[1]['status'] );

// The browser sends the chosen IDs back. They decide what gets copied into
// published documents, so they are intersected with the pending query rather
// than trusted: the stub's pending set is 10 and 11.
ok( 'a chosen record is accepted', array( 150 ) === WPPDF_Migrator::filter_pending( 'tnc_flipbook', array( 150 ) ) );
ok( 'a record outside the pending set is dropped', array( 150, 151 ) === WPPDF_Migrator::filter_pending( 'tnc_flipbook', array( 150, 151, 4242 ) ) );
ok( 'nothing is accepted from an empty request', array() === WPPDF_Migrator::filter_pending( 'tnc_flipbook', array() ) );
ok( 'junk is not turned into a record', array() === WPPDF_Migrator::filter_pending( 'tnc_flipbook', array( 'abc', 0, -5 ) ) );

unset( $GLOBALS['stub_posts'][150], $GLOBALS['stub_posts'][151] );


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


echo "\n== A WPML translation with no PDF falls back ==\n";

// Reported live. A translation is created empty and the files stay on the
// original, whose language need not be in the fallback chain: with "site
// language, otherwise English" an Italian visitor resolves through [it, en],
// and the Czech original holding the English PDF was never looked at, because
// siblings were only searched in the chain's own languages.
$wpml_settings = WPPDF_Settings::all();
// Italian is in the list, as it is on a site where WPML sync adds it.
$wpml_settings['languages']        = array(
	array( 'code' => 'cs', 'label' => 'Čeština' ),
	array( 'code' => 'en', 'label' => 'English' ),
	array( 'code' => 'it', 'label' => 'Italiano' ),
);
$wpml_settings['default_language'] = 'en';
$wpml_settings['fallback_chain']   = array( 'en' );
$wpml_settings['fallback_any']     = 0;
$wpml_settings['sync_with_wpml']   = 1;
update_option( WPPDF_Settings::OPTION, $wpml_settings );
WPPDF_Settings::flush_cache();
WPPDF_Languages::flush_cache();
WPPDF_Documents::flush_cache();

// 300 = Czech original, holds both PDFs. 301 = Italian translation, empty.
$GLOBALS['stub_posts'][300] = array( 'ID' => 300, 'post_type' => 'pdf_document', 'post_title' => 'Smlouva', 'post_status' => 'publish' );
$GLOBALS['stub_posts'][301] = array( 'ID' => 301, 'post_type' => 'pdf_document', 'post_title' => 'Accordo', 'post_status' => 'publish' );
update_post_meta( 300, '_wppdf_file_cs', 20 );
update_post_meta( 300, '_wppdf_file_en', 21 );

// WPML answers "which translation is in language X" and "all translations of
// this one". The Italian post has no translation registered for en or cs by
// language lookup — only the trid listing knows about the original, which is
// exactly the situation that failed.
$GLOBALS['stub_actions']['wpml_element_trid'] = array( 'stub' );
$GLOBALS['stub_filters']['wpml_element_trid'] = 77;
$GLOBALS['stub_filters']['wpml_get_element_translations'] = array(
	(object) array( 'element_id' => 300 ),
	(object) array( 'element_id' => 301 ),
);

$GLOBALS['stub_locale'] = 'it_IT';
WPPDF_Languages::flush_cache();
WPPDF_Documents::flush_cache();

$italian = WPPDF_Documents::get_file( 301 );
ok( 'the empty Italian translation resolves to a file at all', null !== $italian );
ok( 'and it is the English PDF from the original', $italian && 'en' === $italian['lang'] );
ok( 'taken from the post that actually holds it', $italian && 300 === $italian['post_id'] );
ok( 'and it is reported as a fallback', $italian && true === $italian['is_fallback'] );

// The original itself is unaffected: it still answers from its own fields.
WPPDF_Documents::flush_cache();
$GLOBALS['stub_locale'] = 'cs_CZ';
WPPDF_Languages::flush_cache();
$czech = WPPDF_Documents::get_file( 300 );
ok( 'the original still answers from its own fields', $czech && 300 === $czech['post_id'] );

unset(
	$GLOBALS['stub_actions']['wpml_element_trid'],
	$GLOBALS['stub_filters']['wpml_element_trid'],
	$GLOBALS['stub_filters']['wpml_get_element_translations'],
	$GLOBALS['stub_locale'],
	$GLOBALS['stub_posts'][300],
	$GLOBALS['stub_posts'][301]
);
WPPDF_Languages::flush_cache();
WPPDF_Documents::flush_cache();


echo "\n== Titles follow the language ==\n";

// Reported live: a document served the English PDF on the Italian site but
// was still headed with the Czech title. The title walks the same fallback
// chain as the file, so the heading and the document under it agree.
$title_settings = WPPDF_Settings::all();
$title_settings['default_language'] = 'en';
$title_settings['fallback_chain']   = array( 'en' );
$title_settings['fallback_any']     = 0;
update_option( WPPDF_Settings::OPTION, $title_settings );
WPPDF_Settings::flush_cache();
WPPDF_Languages::flush_cache();

$GLOBALS['stub_posts'][190] = array( 'ID' => 190, 'post_type' => 'pdf_document', 'post_title' => 'Smlouva o zpracování osobních údajů', 'post_status' => 'publish' );
update_post_meta( 190, WPPDF_Languages::title_meta_key( 'en' ), 'Personal data processing agreement' );

$documents_titles = new WPPDF_Documents();

$GLOBALS['stub_locale'] = 'en_US';
WPPDF_Languages::flush_cache();
ok( 'the English site gets the English title', 'Personal data processing agreement' === $documents_titles->filter_title( 'Smlouva o zpracování osobních údajů', 190 ) );

// The case from the report: Italian has no title and no PDF, both fall back
// to English, so the heading must not stay Czech.
$GLOBALS['stub_locale'] = 'it_IT';
WPPDF_Languages::flush_cache();
ok( 'a language with neither title nor PDF falls back with the file', 'Personal data processing agreement' === $documents_titles->filter_title( 'Smlouva o zpracování osobních údajů', 190 ) );

update_post_meta( 190, WPPDF_Languages::title_meta_key( 'cs' ), 'Smlouva o zpracování osobních údajů' );
$GLOBALS['stub_locale'] = 'cs_CZ';
WPPDF_Languages::flush_cache();
ok( 'and Czech still gets Czech', 'Smlouva o zpracování osobních údajů' === $documents_titles->filter_title( 'x', 190 ) );

// Nothing stored anywhere leaves the post's own title alone.
$GLOBALS['stub_posts'][191] = array( 'ID' => 191, 'post_type' => 'pdf_document', 'post_title' => 'Bez překladu', 'post_status' => 'publish' );
ok( 'a document with no language titles keeps its own', 'Bez překladu' === $documents_titles->filter_title( 'Bez překladu', 191 ) );

$GLOBALS['stub_posts'][192] = array( 'ID' => 192, 'post_type' => 'post', 'post_title' => 'Obyčejný příspěvek', 'post_status' => 'publish' );
update_post_meta( 192, WPPDF_Languages::title_meta_key( 'cs' ), 'Nesahat' );
ok( 'another post type is not touched', 'Obyčejný příspěvek' === $documents_titles->filter_title( 'Obyčejný příspěvek', 192 ) );

// In wp-admin the editor must see what it edits, or saving would write the
// translation back into post_title.
$GLOBALS['stub_is_admin'] = true;
ok( 'the editor keeps seeing the real post title', 'Smlouva o zpracování osobních údajů' === $documents_titles->filter_title( 'Smlouva o zpracování osobních údajů', 190 ) );
$GLOBALS['stub_is_admin'] = false;

update_option( WPPDF_Settings::OPTION, WPPDF_Settings::all() );
unset( $GLOBALS['stub_locale'], $GLOBALS['stub_posts'][190], $GLOBALS['stub_posts'][191], $GLOBALS['stub_posts'][192] );
WPPDF_Languages::flush_cache();


echo "\n== Covers stand in for the featured image ==\n";

// Covers live in per-language meta, so a document came out imageless in any
// loop that shows one — a category archive, a page builder's post grid —
// while the posts beside it had pictures.
$documents = new WPPDF_Documents();
$GLOBALS['stub_filter_callbacks']['post_thumbnail_id'] = array( array( $documents, 'filter_thumbnail_id' ) );

$GLOBALS['stub_posts'][180] = array( 'ID' => 180, 'post_type' => 'pdf_document', 'post_title' => 'S obálkou', 'post_status' => 'publish' );
update_post_meta( 180, '_wppdf_file_cs', 20 );
update_post_meta( 180, WPPDF_Languages::cover_meta_key( 'cs' ), 22 );

ok( 'a generated cover is offered as the featured image', 22 === get_post_thumbnail_id( 180 ) );
ok( 'so a loop that asks sees one', true === has_post_thumbnail( 180 ) );

// get_cover_id() used to call has_post_thumbnail(), which now answers with the
// cover — reaching this line at all means it no longer recurses.
ok( 'and the plugin\'s own lookup still resolves without recursing', 22 === WPPDF_Documents::get_cover_id( 180 ) );

// A picture the editor chose must win over one rendered from page one.
update_post_meta( 180, '_thumbnail_id', 21 );
ok( 'a real featured image wins', 21 === get_post_thumbnail_id( 180 ) );
ok( 'and the plugin agrees', 21 === WPPDF_Documents::get_cover_id( 180 ) );
delete_post_meta( 180, '_thumbnail_id' );

$GLOBALS['stub_posts'][181] = array( 'ID' => 181, 'post_type' => 'post', 'post_title' => 'Obyčejný příspěvek', 'post_status' => 'publish' );
update_post_meta( 181, WPPDF_Languages::cover_meta_key( 'cs' ), 22 );
ok( 'a post of another type is left alone', 0 === get_post_thumbnail_id( 181 ) );

$GLOBALS['stub_filters']['wppdf_cover_as_thumbnail'] = false;
ok( 'and the whole thing can be switched off', 0 === get_post_thumbnail_id( 180 ) );
unset( $GLOBALS['stub_filters']['wppdf_cover_as_thumbnail'] );

$GLOBALS['stub_filter_callbacks'] = array();
unset( $GLOBALS['stub_posts'][180], $GLOBALS['stub_posts'][181] );


echo "\n== The full page reader actually renders ==\n";

// This one shipped broken and looked fine: the toolbar drew, the document did
// not, and clicking the sidebar open "fixed" it. .wppdf-viewer__pages is
// absolutely positioned, so .wppdf-viewer__body has nothing in flow and no
// intrinsic height; a percentage height on it needs every ancestor to have a
// definite height, which a flex item does not. It collapsed to zero.
$standalone_css = file_get_contents( __DIR__ . '/../assets/css/viewer.css' );
$standalone_css = substr( $standalone_css, (int) strpos( $standalone_css, 'Full page reader' ) );

ok( 'the reader body is sized by flex, not by a percentage', false !== strpos( $standalone_css, '.wppdf-standalone .wppdf-viewer__body' ) && false !== strpos( $standalone_css, 'flex: 1 1 auto' ) );
ok( 'the percentage height that collapsed to zero is gone', false === strpos( $standalone_css, '--wppdf-height: 100%' ) );
ok( 'the page itself has a definite height to hand down', false !== strpos( $standalone_css, 'height: 100dvh' ) );
ok( 'the title sets its own colour instead of inheriting one a theme can win', false !== strpos( $standalone_css, ".wppdf-standalone__title {\n\tgrid-column: 2;\n\tmargin: 0;" ) && false !== strpos( $standalone_css, 'text-align: center' ) );

$standalone_template = file_get_contents( __DIR__ . '/../templates/single-document-standalone.php' );

// Matched as statements, because the file explains in prose what it is not
// calling and that must not be what the test reads.
ok( 'the standalone template never calls get_header', 0 === preg_match( '/^\s*get_header\s*\(/m', $standalone_template ) );
ok( 'nor get_footer', 0 === preg_match( '/^\s*get_footer\s*\(/m', $standalone_template ) );
ok( 'while the theme template does call them', 1 === preg_match( '/^\s*get_header\s*\(/m', file_get_contents( __DIR__ . '/../templates/single-document.php' ) ) );
ok( 'but does call wp_head, which the reader needs', false !== strpos( $standalone_template, 'wp_head()' ) );
ok( 'and wp_footer', false !== strpos( $standalone_template, 'wp_footer()' ) );

// Deferring the reader until it scrolls into view is pointless when it is the
// page, and it is one more way for it never to start.
ok( 'the reader is not lazy loaded when it is the whole page', false !== strpos( $standalone_template, "'lazy' => false" ) );
ok( 'the back link is marked for the history handler', false !== strpos( $standalone_template, 'data-wppdf-back' ) );
ok( 'and still carries a real address for a direct hit', false !== strpos( $standalone_template, 'wppdf_get_back_url' ) );

$eager = WPPDF_Viewer::render( 10, array( 'lang' => 'cs', 'lazy' => false ) );
ok( 'and the rendered config says so', false !== strpos( $eager, '&quot;lazy&quot;:false' ) || false !== strpos( $eager, '"lazy":false' ) );


echo "\n== Per-language slugs ==\n";

$GLOBALS['stub_posts'][170] = array( 'ID' => 170, 'post_type' => 'pdf_document', 'post_title' => 'Výroční zpráva 2025', 'post_name' => 'vyrocni-zprava-2025', 'post_status' => 'publish' );
$GLOBALS['stub_posts'][171] = array( 'ID' => 171, 'post_type' => 'pdf_document', 'post_title' => 'Jiný dokument', 'post_name' => 'jiny-dokument', 'post_status' => 'publish' );

ok( 'a slug is stored per language', 'annual-report-2025' === WPPDF_Permalinks::set_slug( 170, 'en', 'Annual Report 2025' ) );
ok( 'and read back', 'annual-report-2025' === WPPDF_Permalinks::get_slug( 170, 'en' ) );
ok( 'a language without one reports nothing', '' === WPPDF_Permalinks::get_slug( 170, 'cs' ) );

// Two documents on one address would make which of them answers depend on
// row order, so the second is suffixed the way WordPress suffixes post_name.
ok( 'a taken address gets a suffix', 'annual-report-2025-2' === WPPDF_Permalinks::set_slug( 171, 'en', 'annual-report-2025' ) );
ok( 'a post_name of another document is taken too', 'jiny-dokument-2' === WPPDF_Permalinks::set_slug( 170, 'cs', 'jiny-dokument' ) );
ok( 'keeping your own slug does not suffix it', 'annual-report-2025' === WPPDF_Permalinks::set_slug( 170, 'en', 'annual-report-2025' ) );

ok( 'emptying the field removes the slug', '' === WPPDF_Permalinks::set_slug( 170, 'cs', '' ) );
ok( 'and it is gone', '' === WPPDF_Permalinks::get_slug( 170, 'cs' ) );

// The permalink follows the site language, which is the same rule that picks
// the PDF.
$GLOBALS['stub_locale'] = 'en_US';
WPPDF_Languages::flush_cache();
$permalinks = new WPPDF_Permalinks();
$doc170     = get_post( 170 );

ok(
	'the permalink uses the language slug',
	'https://example.test/pdf/annual-report-2025/' === $permalinks->filter_permalink( 'https://example.test/pdf/vyrocni-zprava-2025/', $doc170 )
);

$GLOBALS['stub_locale'] = 'cs_CZ';
WPPDF_Languages::flush_cache();
ok(
	'a language with no slug keeps the address it always had',
	'https://example.test/pdf/vyrocni-zprava-2025/' === $permalinks->filter_permalink( 'https://example.test/pdf/vyrocni-zprava-2025/', $doc170 )
);

$GLOBALS['stub_locale'] = 'en_US';
WPPDF_Languages::flush_cache();
ok(
	'the editor placeholder is left alone',
	'https://example.test/pdf/%postname%/' === $permalinks->filter_permalink( 'https://example.test/pdf/%postname%/', $doc170, true )
);

// Plain permalinks end in ?p=123 rather than the name; a structure the filter
// does not recognise must be returned untouched rather than mangled.
ok(
	'an unexpected permalink structure is not rewritten',
	'https://example.test/?p=170' === $permalinks->filter_permalink( 'https://example.test/?p=170', $doc170 )
);

// Every language resolves, not just the current one, so a link to the English
// address does not 404 on a Czech site — redirect_canonical sends it on.
$resolved = $permalinks->resolve_request( array( 'name' => 'annual-report-2025', 'post_type' => 'pdf_document' ) );
ok( 'a language slug finds its document', isset( $resolved['p'] ) && 170 === $resolved['p'] );
ok( 'and the name is dropped so it cannot 404', ! isset( $resolved['name'] ) );

$untouched = $permalinks->resolve_request( array( 'name' => 'vyrocni-zprava-2025', 'post_type' => 'pdf_document' ) );
ok( 'a real post name is left to WordPress', ! isset( $untouched['p'] ) && isset( $untouched['name'] ) );

$unknown = $permalinks->resolve_request( array( 'name' => 'nic-takoveho', 'post_type' => 'pdf_document' ) );
ok( 'an unknown address still 404s', ! isset( $unknown['p'] ) );

$other = $permalinks->resolve_request( array( 'name' => 'annual-report-2025', 'post_type' => 'post' ) );
ok( 'another post type is not touched', ! isset( $other['p'] ) );

$noname = $permalinks->resolve_request( array( 'post_type' => 'pdf_document' ) );
ok( 'a request without a name is not touched', ! isset( $noname['p'] ) );

// Found live: a leftover post — an old translation, a draft, something in the
// trash — carrying the same post_name made the resolver stand aside, and
// WordPress then 404'd because that post is not servable. Only a post
// WordPress would really serve may take precedence over a language slug.
$GLOBALS['stub_posts'][175] = array( 'ID' => 175, 'post_type' => 'pdf_document', 'post_title' => 'Starý překlad', 'post_name' => 'annual-report-2025', 'post_status' => 'draft' );

$GLOBALS['stub_denied_caps'] = array( 'read_post' );
$shadowed = $permalinks->resolve_request( array( 'name' => 'annual-report-2025', 'post_type' => 'pdf_document' ) );
ok( 'an unservable post of the same name does not block the language slug', isset( $shadowed['p'] ) && 170 === $shadowed['p'] );
unset( $GLOBALS['stub_denied_caps'] );

// The editor who may read that draft still gets it, because that is what
// WordPress would have served.
$editors = $permalinks->resolve_request( array( 'name' => 'annual-report-2025', 'post_type' => 'pdf_document' ) );
ok( 'but an editor who can read it still reaches it', ! isset( $editors['p'] ) );

$GLOBALS['stub_posts'][175]['post_status'] = 'publish';
$published = $permalinks->resolve_request( array( 'name' => 'annual-report-2025', 'post_type' => 'pdf_document' ) );
ok( 'and a published post of that name always wins', ! isset( $published['p'] ) );
unset( $GLOBALS['stub_posts'][175] );

// A draft keeps its language slug so an editor can preview it, but the slug
// must not be a way around the status for anyone else.
$GLOBALS['stub_posts'][172] = array( 'ID' => 172, 'post_type' => 'pdf_document', 'post_title' => 'Rozepsaný', 'post_name' => 'rozepsany', 'post_status' => 'draft' );
WPPDF_Permalinks::set_slug( 172, 'en', 'unpublished-report' );

$GLOBALS['stub_denied_caps'] = array( 'read_post' );
$hidden = $permalinks->resolve_request( array( 'name' => 'unpublished-report', 'post_type' => 'pdf_document' ) );
ok( 'a draft does not resolve for someone who may not read it', ! isset( $hidden['p'] ) );

unset( $GLOBALS['stub_denied_caps'] );
$preview = $permalinks->resolve_request( array( 'name' => 'unpublished-report', 'post_type' => 'pdf_document' ) );
ok( 'but an editor can still preview it', isset( $preview['p'] ) && 172 === $preview['p'] );

unset( $GLOBALS['stub_locale'] );
WPPDF_Languages::flush_cache();


echo "\n== Fallback pages and search engines ==\n";

// The case from Search Console: /de/ and /pl/ addresses of a document that
// only has a Czech file. They serve the Czech PDF on purpose, but to Google
// they were pages whose only words were "not available in your language" plus
// a reader that had not loaded — filed as soft 404s. The page stays; what
// changes is that it now says where the content it shows really lives.
$GLOBALS['stub_posts'][180] = array( 'ID' => 180, 'post_type' => 'pdf_document', 'post_title' => 'Lokality', 'post_name' => 'lokality', 'post_status' => 'publish' );
update_post_meta( 180, '_wppdf_file_cs', 20 );

// The shipped setup, plus the German that WPML sync adds to the list on a site
// that has a German version of everything else.
$before_fallback                = WPPDF_Settings::all();
$fallback_settings              = WPPDF_Settings::defaults();
$fallback_settings['languages'] = array(
	array( 'code' => 'cs', 'label' => 'Čeština' ),
	array( 'code' => 'en', 'label' => 'English' ),
	array( 'code' => 'de', 'label' => 'Deutsch' ),
);
update_option( WPPDF_Settings::OPTION, $fallback_settings );
WPPDF_Settings::flush_cache();

$GLOBALS['stub_locale']      = 'de_DE';
$GLOBALS['stub_is_singular'] = true;
$GLOBALS['stub_current']     = 180;
WPPDF_Languages::flush_cache();
WPPDF_Documents::flush_cache();
WPPDF_Canonical::flush_cache();

$german = WPPDF_Documents::get_file( 180 );
ok( 'a German visitor gets the Czech file', $german && 'cs' === $german['lang'] && $german['is_fallback'] );

// One address per language is what a multilingual plugin makes; without one
// there is only ever a single address, and pointing it at itself says nothing.
ok( 'nothing to point at without a multilingual plugin', '' === WPPDF_Canonical::get_target() );

// WPML's own filter is what puts /cs/ or /de/ in front of an address, so it is
// asked for the language that holds the file rather than the visitor's.
function stub_wpml_permalink( $url, $code = '', $absolute = false ) {
	return str_replace( 'https://example.test/', 'https://example.test/' . $code . '/', $url );
}
$GLOBALS['stub_actions']['wpml_permalink']           = array( 'stub_wpml_permalink' );
$GLOBALS['stub_filter_callbacks']['wpml_permalink']  = array( 'stub_wpml_permalink' );

WPPDF_Canonical::flush_cache();
ok( 'a fallback page is canonical to the language holding the file', 'https://example.test/cs/pdf/lokality/' === WPPDF_Canonical::get_target() );

// And to that language's own address when it has one, not to the slug the
// visitor's language happens to be using.
WPPDF_Permalinks::set_slug( 180, 'cs', 'ceske-lokality' );
WPPDF_Canonical::flush_cache();
ok( 'the language slug of the target is used', 'https://example.test/cs/pdf/ceske-lokality/' === WPPDF_Canonical::get_target() );
ok( 'the address is built for the asked language, not the current one', 'https://example.test/cs/pdf/ceske-lokality/' === WPPDF_Permalinks::get_language_permalink( 180, 'cs' ) );

// A visitor in the language the file belongs to is on the real page already.
$GLOBALS['stub_locale'] = 'cs_CZ';
WPPDF_Languages::flush_cache();
WPPDF_Documents::flush_cache();
WPPDF_Canonical::flush_cache();
ok( 'no canonical when the language matches', '' === WPPDF_Canonical::get_target() );

$GLOBALS['stub_locale'] = 'de_DE';
WPPDF_Languages::flush_cache();
WPPDF_Documents::flush_cache();

$canonical_off                       = WPPDF_Settings::all();
$canonical_off['canonical_fallback'] = 0;
update_option( WPPDF_Settings::OPTION, $canonical_off );
WPPDF_Settings::flush_cache();
WPPDF_Canonical::flush_cache();
ok( 'the setting switches it off', '' === WPPDF_Canonical::get_target() );

$canonical_off['canonical_fallback'] = 1;
update_option( WPPDF_Settings::OPTION, $canonical_off );
WPPDF_Settings::flush_cache();
WPPDF_Canonical::flush_cache();

// The notice tells a visitor what they are looking at without sounding like a
// missing page, and the interface is kept out of search result snippets.
$notice = WPPDF_Viewer::render( 180 );
ok( 'the notice does not announce a failure', false === strpos( $notice, 'not available' ) );
ok( 'the notice names the language it is showing', false !== strpos( $notice, 'version of the document' ) );
ok( 'the interface is marked as no snippet material', false !== strpos( $notice, 'data-nosnippet' ) );

echo "\n== Document descriptions ==\n";

update_post_meta( 180, '_wppdf_text_cs', 'Seznam lokalit a jejich obsluhy v jednotlivých směnách.' );
ok( 'extracted text is readable back', 'Seznam lokalit a jejich obsluhy v jednotlivých směnách.' === WPPDF_Text::get_text( 180, 'cs' ) );
ok( 'and follows the resolved language', 'Seznam lokalit a jejich obsluhy v jednotlivých směnách.' === WPPDF_Text::get_text( 180 ) );

$GLOBALS['stub_excerpt'] = '';
ok( 'a document with no excerpt is described by its own text', 'Seznam lokalit a jejich obsluhy v jednotlivých směnách.' === WPPDF_Seo::get_description( 180 ) );

$GLOBALS['stub_excerpt'] = 'Přehled lokalit.';
ok( 'an excerpt wins when there is one', 'Přehled lokalit.' === WPPDF_Seo::get_description( 180 ) );

// What Search Console showed as the description of those pages: the reader's
// own buttons, summarised by an SEO plugin as if they were the document.
$seo   = new WPPDF_Seo();
$chrome = 'This is the Čeština version of the document. ☰ ‹ / – › Search in the document Loading document… Open the PDF';
ok( 'a description made of toolbar labels is replaced', 'Přehled lokalit.' === $seo->filter_description( $chrome ) );
ok( 'a real description is left alone', 'Roční přehled.' === $seo->filter_description( 'Roční přehled.' ) );

$GLOBALS['stub_excerpt'] = '';
delete_post_meta( 180, '_wppdf_text_cs' );
ok( 'and an empty one beats button labels when there is nothing else', '' === $seo->filter_description( $chrome ) );
unset( $GLOBALS['stub_excerpt'] );

unset( $GLOBALS['stub_actions']['wpml_permalink'], $GLOBALS['stub_filter_callbacks']['wpml_permalink'] );
unset( $GLOBALS['stub_locale'], $GLOBALS['stub_is_singular'] );
$GLOBALS['stub_current'] = 0;
update_option( WPPDF_Settings::OPTION, $before_fallback );
WPPDF_Settings::flush_cache();
WPPDF_Languages::flush_cache();
WPPDF_Documents::flush_cache();
WPPDF_Canonical::flush_cache();


echo "\n== Full page reader ==\n";

// The theme's header, menu and footer come from get_header()/get_footer(),
// which the standalone template does not call. Picking the wrong file is the
// only way that can go wrong, so that is what is checked.
$layout_settings = WPPDF_Settings::all();
ok( 'a document opens on its own page by default', 'single-document-standalone.php' === WPPDF_Templates::single_template_name() );

$layout_settings['single_layout'] = 'theme';
update_option( WPPDF_Settings::OPTION, $layout_settings );
WPPDF_Settings::flush_cache();
ok( 'the theme layout can be chosen instead', 'single-document.php' === WPPDF_Templates::single_template_name() );

$layout_settings['single_layout'] = 'nonsense';
$sanitizer = new WPPDF_Settings();
ok( 'an unknown layout falls back to the full page', 'standalone' === $sanitizer->sanitize( $layout_settings )['single_layout'] );

$layout_settings['single_layout'] = 'standalone';
update_option( WPPDF_Settings::OPTION, $layout_settings );
WPPDF_Settings::flush_cache();

ok( 'both single templates exist', '' !== WPPDF_Templates::locate( 'single-document-standalone.php' ) && '' !== WPPDF_Templates::locate( 'single-document.php' ) );

// A page with no navigation needs a way out.
$GLOBALS['stub_posts'][160] = array( 'ID' => 160, 'post_type' => 'pdf_document', 'post_title' => 'Dokument', 'post_name' => 'dokument', 'post_status' => 'publish' );

$GLOBALS['stub_referer'] = 'https://example.test/vyrocni-zpravy/';
ok( 'the back link returns the visitor where they came from', 'https://example.test/vyrocni-zpravy/' === wppdf_get_back_url( 160 ) );

// Reloading the reader makes the document itself the referer, which would be
// a link to nowhere.
$GLOBALS['stub_referer'] = get_permalink( 160 );
ok( 'a reload does not make the link point at itself', 'https://example.test/pdf/' === wppdf_get_back_url( 160 ) );

$GLOBALS['stub_referer'] = 'https://elsewhere.example/link/';
ok( 'a referer from another site is not followed', 'https://example.test/pdf/' === wppdf_get_back_url( 160 ) );

$GLOBALS['stub_referer'] = false;
ok( 'without a referer the archive is offered', 'https://example.test/pdf/' === wppdf_get_back_url( 160 ) );

echo "\n";
echo empty( $GLOBALS['stub_failed'] ) ? "ALL CHECKS PASSED\n" : "SOME CHECKS FAILED\n";
exit( empty( $GLOBALS['stub_failed'] ) ? 0 : 1 );

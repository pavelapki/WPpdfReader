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
function get_post_meta( $id, $key, $single = false ) { return isset( $GLOBALS['stub_meta'][ $id ][ $key ] ) ? $GLOBALS['stub_meta'][ $id ][ $key ] : ''; }
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
function get_permalink( $id = 0 ) { return 'https://example.test/pdf/doc-' . $id . '/'; }
function has_post_thumbnail( $id = 0 ) { return false; }
function get_post_thumbnail_id( $id = 0 ) { return 0; }
function wp_get_attachment_image() { return '<img src="cover.jpg" alt="" />'; }
function get_the_excerpt( $id = 0 ) { return 'Excerpt text.'; }
function get_the_date( $format = '', $id = 0 ) { return '11. 8. 2026'; }
function get_the_term_list() { return '<a href="#">Reports</a>'; }
function post_class( $class = '', $id = null ) { echo 'class="' . ( is_array( $class ) ? implode( ' ', $class ) : $class ) . '"'; }
function wp_reset_postdata() {}
function is_wp_error( $t ) { return false; }
function paginate_links( $args ) { return '<a class="page-numbers" href="#">2</a>'; }

class WP_Query {
	public $args;
	public $max_num_pages = 1;
	protected $index = -1;
	protected $ids;
	public function __construct( $args = array() ) { $this->args = $args; $this->ids = isset( $GLOBALS['stub_query_ids'] ) ? $GLOBALS['stub_query_ids'] : array(); }
	public function have_posts() { return $this->index + 1 < count( $this->ids ); }
	public function the_post() { $this->index++; $GLOBALS['stub_current'] = $this->ids[ $this->index ]; }
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
function sanitize_title( $v ) { return preg_replace( '/[^a-z0-9\-]/', '', strtolower( str_replace( ' ', '-', (string) $v ) ) ); }
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
function current_user_can() { return true; }
function wp_nonce_field() {}
function wp_verify_nonce() { return true; }
function wp_is_post_revision() { return false; }
function wp_is_post_autosave() { return false; }
function get_current_screen() { return null; }
function wp_upload_dir() { return array( 'path' => '/tmp', 'error' => false ); }
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
function wp_cache_get( $k, $g = '' ) { return false; }
function wp_cache_set( $k, $v, $g = '', $t = 0 ) { return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }
$GLOBALS['stub_query_vars'] = array();
function seems_utf8( $s ) { return (bool) preg_match( '//u', $s ); }
function wp_check_invalid_utf8( $s, $strip = false ) { return $strip ? (string) preg_replace( '/[^\x00-\x7F]/', '', $s ) : $s; }
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
	public function prepare( $q, ...$a ) {
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

echo "\n";
echo empty( $GLOBALS['stub_failed'] ) ? "ALL CHECKS PASSED\n" : "SOME CHECKS FAILED\n";
exit( empty( $GLOBALS['stub_failed'] ) ? 0 : 1 );

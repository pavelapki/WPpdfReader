<?php
/**
 * Plugin updates straight from GitHub releases.
 *
 * WordPress only asks for update information twice a day, and the API answer
 * is cached on top of that, so the admin never waits on GitHub. Every failure
 * is cached too: an unreachable or rate limited API must not slow down the
 * plugins screen on every request.
 *
 * @package WP_PDF_Reader
 */

defined( 'ABSPATH' ) || exit;

/**
 * GitHub release updater.
 */
class WPPDF_Updater {

	/**
	 * Transient holding the last API answer.
	 */
	const TRANSIENT = 'wppdf_github_release';

	/**
	 * How long a successful lookup is cached.
	 */
	const TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * How long a failed lookup is cached.
	 */
	const TTL_FAILURE = 2 * HOUR_IN_SECONDS;

	/**
	 * Hosts a release package may be downloaded from.
	 *
	 * @var string[]
	 */
	protected static $allowed_hosts = array(
		'github.com',
		'api.github.com',
		'codeload.github.com',
		'objects.githubusercontent.com',
		'release-assets.githubusercontent.com',
	);

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'rename_source' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush' ), 10, 0 );
		add_action( 'wppdf_flush_update_cache', array( $this, 'flush' ) );
	}

	/**
	 * Configured repository, validated as owner/name.
	 *
	 * @return string
	 */
	public static function get_repository() {
		if ( ! WPPDF_Settings::get( 'github_updates' ) ) {
			return '';
		}

		$repository = trim( (string) WPPDF_Settings::get( 'github_repository' ) );

		if ( ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository ) ) {
			return '';
		}

		return $repository;
	}

	/**
	 * Drop the cached release.
	 */
	public function flush() {
		delete_site_transient( self::TRANSIENT );
	}

	/**
	 * Fetch the latest release, from cache when possible.
	 *
	 * @param bool $force Ignore the cache.
	 * @return array|null Release data or null when unavailable.
	 */
	public static function get_release( $force = false ) {
		$repository = self::get_repository();

		if ( '' === $repository ) {
			return null;
		}

		if ( ! $force ) {
			$cached = get_site_transient( self::TRANSIENT );

			if ( is_array( $cached ) ) {
				return isset( $cached['repository'] ) && $cached['repository'] === $repository && ! empty( $cached['version'] ) ? $cached : null;
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $repository . '/releases/latest',
			array(
				'timeout'   => 6,
				'headers'   => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WP-PDF-Reader/' . WPPDF_VERSION . '; ' . home_url( '/' ),
				),
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( self::TRANSIENT, array( 'repository' => $repository ), self::TTL_FAILURE );

			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_site_transient( self::TRANSIENT, array( 'repository' => $repository ), self::TTL_FAILURE );

			return null;
		}

		$release = self::parse( $body, $repository );

		set_site_transient( self::TRANSIENT, $release, $release ? self::TTL : self::TTL_FAILURE );

		return $release && ! empty( $release['version'] ) ? $release : null;
	}

	/**
	 * Turn the API payload into the few fields that are actually used.
	 *
	 * @param array  $body       Decoded API response.
	 * @param string $repository Repository in owner/name form.
	 * @return array
	 */
	protected static function parse( array $body, $repository ) {
		$release = array( 'repository' => $repository );

		$version = ltrim( sanitize_text_field( (string) $body['tag_name'] ), 'vV' );

		// Only accept something that looks like a version number.
		if ( ! preg_match( '/^\d+(\.\d+){0,3}(-[A-Za-z0-9.]+)?$/', $version ) ) {
			return $release;
		}

		$package = '';

		// A release asset is a ready to install plugin zip; the source zipball
		// is the fallback.
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( empty( $asset['browser_download_url'] ) ) {
					continue;
				}

				$url = (string) $asset['browser_download_url'];

				if ( '.zip' === strtolower( substr( $url, -4 ) ) && self::is_allowed_package( $url ) ) {
					$package = $url;
					break;
				}
			}
		}

		if ( '' === $package && ! empty( $body['zipball_url'] ) && self::is_allowed_package( $body['zipball_url'] ) ) {
			$package = (string) $body['zipball_url'];
		}

		if ( '' === $package ) {
			return $release;
		}

		$release['version']   = $version;
		$release['package']   = esc_url_raw( $package );
		$release['url']       = ! empty( $body['html_url'] ) ? esc_url_raw( $body['html_url'] ) : 'https://github.com/' . $repository;
		$release['published'] = ! empty( $body['published_at'] ) ? sanitize_text_field( $body['published_at'] ) : '';
		$release['notes']     = ! empty( $body['body'] ) ? wp_kses_post( $body['body'] ) : '';

		return $release;
	}

	/**
	 * Refuse packages that do not come from GitHub over HTTPS.
	 *
	 * @param string $url Package URL.
	 * @return bool
	 */
	protected static function is_allowed_package( $url ) {
		$parts = wp_parse_url( (string) $url );

		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		return in_array( strtolower( $parts['host'] ), self::$allowed_hosts, true );
	}

	/**
	 * Tell WordPress about a newer release.
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$release = self::get_release();

		if ( ! $release ) {
			return $transient;
		}

		$item = (object) array(
			'id'          => 'github.com/' . $release['repository'],
			'slug'        => dirname( WPPDF_BASENAME ),
			'plugin'      => WPPDF_BASENAME,
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
			'tested'      => get_bloginfo( 'version' ),
			'icons'       => array(),
			'banners'     => array(),
		);

		if ( version_compare( $release['version'], WPPDF_VERSION, '>' ) ) {
			$transient->response[ WPPDF_BASENAME ] = $item;
			unset( $transient->no_update[ WPPDF_BASENAME ] );
		} else {
			$transient->no_update[ WPPDF_BASENAME ] = $item;
			unset( $transient->response[ WPPDF_BASENAME ] );
		}

		return $transient;
	}

	/**
	 * Fill the "View details" modal.
	 *
	 * @param mixed  $result Result so far.
	 * @param string $action Requested action.
	 * @param object $args   Request arguments.
	 * @return mixed
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( empty( $args->slug ) || dirname( WPPDF_BASENAME ) !== $args->slug ) {
			return $result;
		}

		$release = self::get_release();

		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'WP PDF Reader',
			'slug'          => dirname( WPPDF_BASENAME ),
			'version'       => $release['version'],
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'tested'        => get_bloginfo( 'version' ),
			'last_updated'  => $release['published'],
			'homepage'      => $release['url'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => esc_html__( 'PDF document library with a bundled PDF.js reader and per-language files.', 'wp-pdf-reader' ),
				'changelog'   => $release['notes'] ? wpautop( $release['notes'] ) : esc_html__( 'See the release on GitHub.', 'wp-pdf-reader' ),
			),
		);
	}

	/**
	 * Normalise the extracted folder name.
	 *
	 * A GitHub source zipball unpacks to owner-repo-hash, which would install
	 * the plugin as a new directory instead of updating this one.
	 *
	 * @param string $source        Unpacked source directory.
	 * @param string $remote_source Temporary directory.
	 * @param object $upgrader      Upgrader instance.
	 * @param array  $hook_extra    Extra arguments.
	 * @return string|WP_Error
	 */
	public function rename_source( $source, $remote_source, $upgrader = null, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || WPPDF_BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}

		if ( ! $wp_filesystem ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . dirname( WPPDF_BASENAME );

		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		if ( $wp_filesystem->exists( $desired ) ) {
			$wp_filesystem->delete( $desired, true );
		}

		if ( ! $wp_filesystem->move( untrailingslashit( $source ), $desired ) ) {
			return new WP_Error( 'wppdf_rename_failed', __( 'The downloaded package could not be prepared for installation.', 'wp-pdf-reader' ) );
		}

		return trailingslashit( $desired );
	}
}

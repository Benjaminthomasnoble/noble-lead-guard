<?php
defined( 'ABSPATH' ) || exit;

/**
 * Private updates from GitHub releases.
 *
 * Pushing a version bump to the repo's main branch makes a GitHub Action build the zip and
 * publish it as a release. Every site checks the latest release twice a day (WordPress's
 * normal update schedule) and updates like any wordpress.org plugin.
 */
class NLG_Updater {

	const REPO      = 'Benjaminthomasnoble/noble-lead-guard';
	const ASSET     = 'noble-lead-guard.zip';
	const SLUG      = 'noble-lead-guard';
	const CACHE_KEY = 'nlg_update_info';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ) );
		// "Check again" under Dashboard > Updates asks GitHub fresh. WordPress refreshes plugin updates on this hook at priority 10.
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_force_check' ), 9 );
	}

	public static function maybe_force_check() {
		if ( ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only clears a cache.
			self::clear_cache();
		}
	}

	public static function basename() {
		return plugin_basename( NLG_FILE );
	}

	/**
	 * A small JSON file attached to every release. Reading it through github.com's release
	 * downloads avoids api.github.com, which allows only 60 requests an hour per server IP
	 * and runs out on busy shared hosts.
	 */
	public static function info_url() {
		return 'https://github.com/' . self::REPO . '/releases/latest/download/update.json';
	}

	public static function repo_url() {
		return 'https://github.com/' . self::REPO;
	}

	/** Latest release from GitHub, cached 12 hours (15 minutes after a failed check). */
	public static function remote_info() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached['version'] ? $cached : null;
		}

		$info     = null;
		$error    = '';
		$response = wp_remote_get(
			self::info_url(),
			array(
				'timeout'     => 8,
				'redirection' => 5,
				'headers'     => array( 'User-Agent' => 'NobleLeadGuard/' . NLG_VERSION ),
			)
		);
		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();
		} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$error = 'GitHub answered ' . (int) wp_remote_retrieve_response_code( $response );
		} else {
			$data    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$version = isset( $data['version'] ) ? (string) $data['version'] : '';
			$package = isset( $data['package'] ) ? (string) $data['package'] : '';
			if ( preg_match( '/^\d+(\.\d+)*$/', $version ) && 0 === strpos( $package, 'https://github.com/' . self::REPO . '/releases/download/' ) ) {
				$info = array(
					'version' => $version,
					'package' => $package,
					'notes'   => isset( $data['notes'] ) ? mb_substr( (string) $data['notes'], 0, 4000 ) : '',
				);
			} else {
				$error = 'The release info could not be read';
			}
		}

		update_option(
			'nlg_update_status',
			array(
				'time'    => time(),
				'version' => $info ? $info['version'] : '',
				'error'   => $error,
			),
			false
		);
		set_site_transient( self::CACHE_KEY, $info ? $info : array( 'version' => '' ), $info ? 12 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
		return $info;
	}

	/** One line for the settings page: when updates were last checked and what was found. */
	public static function status_text() {
		$status = get_option( 'nlg_update_status' );
		if ( ! is_array( $status ) || empty( $status['time'] ) ) {
			return 'Not checked yet.';
		}
		$when = wp_date( 'M j, g:i a', (int) $status['time'] );
		if ( '' !== $status['error'] ) {
			return 'Last check failed (' . $status['error'] . ', ' . $when . '). It retries within 15 minutes.';
		}
		if ( version_compare( $status['version'], NLG_VERSION, '>' ) ) {
			return 'Version ' . $status['version'] . ' is available (checked ' . $when . ').';
		}
		return 'Up to date (checked ' . $when . ').';
	}

	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}
		$info = self::remote_info();
		if ( ! $info ) {
			return $transient;
		}

		$item = (object) array(
			'id'          => self::SLUG,
			'slug'        => self::SLUG,
			'plugin'      => self::basename(),
			'new_version' => $info['version'],
			'package'     => $info['package'],
			'url'         => self::repo_url(),
		);

		// Listing the plugin either way is what makes the "Enable auto-updates" link appear for it.
		if ( version_compare( $info['version'], NLG_VERSION, '>' ) ) {
			$transient->response[ self::basename() ] = $item;
		} else {
			$item->new_version                        = NLG_VERSION;
			$transient->no_update[ self::basename() ] = $item;
		}
		return $transient;
	}

	/** Fills the "View details" popup on the Plugins page. */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$info  = self::remote_info();
		$notes = $info && '' !== $info['notes'] ? '<pre style="white-space:pre-wrap">' . esc_html( $info['notes'] ) . '</pre>' : 'No release notes.';
		return (object) array(
			'name'          => 'Noble Lead Guard',
			'slug'          => self::SLUG,
			'version'       => $info ? $info['version'] : NLG_VERSION,
			'author'        => '<a href="https://nobledigital.tech/">Noble Digital</a>',
			'homepage'      => 'https://nobledigital.tech/',
			'download_link' => $info ? $info['package'] : '',
			'sections'      => array(
				'description' => 'Uses AI to hold back spam and sales pitches sent through Gravity Forms before the notification email goes out. Updates are published by Noble Digital.',
				'changelog'   => $notes,
			),
		);
	}

	public static function clear_cache() {
		delete_site_transient( self::CACHE_KEY );
	}
}

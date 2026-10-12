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
	}

	public static function basename() {
		return plugin_basename( NLG_FILE );
	}

	public static function info_url() {
		return 'https://api.github.com/repos/' . self::REPO . '/releases/latest';
	}

	public static function repo_url() {
		return 'https://github.com/' . self::REPO;
	}

	/** Latest release from GitHub, cached 12 hours (1 hour after a failed check). */
	public static function remote_info() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached['version'] ? $cached : null;
		}

		$info     = null;
		$response = wp_remote_get(
			self::info_url(),
			array(
				'timeout' => 8,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'NobleLeadGuard/' . NLG_VERSION,
				),
			)
		);
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$version = isset( $data['tag_name'] ) ? ltrim( (string) $data['tag_name'], 'vV' ) : '';
			$package = '';
			foreach ( (array) ( isset( $data['assets'] ) ? $data['assets'] : array() ) as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::ASSET === $asset['name'] ) {
					$package = (string) $asset['browser_download_url'];
				}
			}
			if ( preg_match( '/^\d+(\.\d+)*$/', $version ) && 0 === strpos( $package, 'https://github.com/' . self::REPO . '/releases/download/' ) ) {
				$info = array(
					'version' => $version,
					'package' => $package,
					'notes'   => isset( $data['body'] ) ? mb_substr( (string) $data['body'], 0, 4000 ) : '',
				);
			}
		}

		set_site_transient( self::CACHE_KEY, $info ? $info : array( 'version' => '' ), $info ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $info;
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

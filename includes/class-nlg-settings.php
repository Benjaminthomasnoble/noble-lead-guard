<?php
defined( 'ABSPATH' ) || exit;

/**
 * Settings live in one option. The API key is its own option (not autoloaded),
 * unless it is defined in wp-config.php.
 */
class NLG_Settings {

	const OPTION        = 'nlg_settings';
	const KEY_OPTION    = 'nlg_api_key';
	const KEY_MASK      = 'nlg-saved-key-unchanged-0000';
	const DEFAULT_MODEL = 'claude-haiku-5-5';

	public static function defaults() {
		return array(
			'mode'               => 'test', // off | test | on
			'profile'            => '',
			'hold_solicitations' => 1,
			'min_confidence'     => 'high', // high | medium
			'timeout'            => 5,
			'digest_to'          => '',
			'skip_forms'         => '',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		// 0.1.2 briefly used a single strictness level; map it back onto the two settings.
		if ( isset( $saved['strictness'] ) && ! isset( $saved['hold_solicitations'] ) ) {
			$saved['hold_solicitations'] = 'relaxed' === $saved['strictness'] ? 0 : 1;
			$saved['min_confidence']     = 'strict' === $saved['strictness'] ? 'medium' : 'high';
		}
		unset( $saved['strictness'] );

		return array_merge( self::defaults(), $saved );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		$mode        = isset( $input['mode'] ) ? (string) $input['mode'] : 'test';
		$out['mode'] = in_array( $mode, array( 'off', 'test', 'on' ), true ) ? $mode : 'test';

		$out['profile']            = isset( $input['profile'] ) ? sanitize_textarea_field( $input['profile'] ) : '';
		$out['hold_solicitations'] = empty( $input['hold_solicitations'] ) ? 0 : 1;

		$min                   = isset( $input['min_confidence'] ) ? (string) $input['min_confidence'] : 'high';
		$out['min_confidence'] = in_array( $min, array( 'high', 'medium' ), true ) ? $min : 'high';

		$timeout        = isset( $input['timeout'] ) ? (int) $input['timeout'] : 5;
		$out['timeout'] = max( 2, min( 15, $timeout ) );

		$out['digest_to'] = isset( $input['digest_to'] ) ? sanitize_email( $input['digest_to'] ) : '';

		$ids               = isset( $input['skip_forms'] ) ? preg_split( '/[^0-9]+/', (string) $input['skip_forms'] ) : array();
		$ids               = array_filter( array_map( 'intval', $ids ) );
		$out['skip_forms'] = implode( ', ', array_unique( $ids ) );

		return $out;
	}

	public static function key_in_config() {
		return defined( 'NOBLE_LEAD_GUARD_API_KEY' ) && NOBLE_LEAD_GUARD_API_KEY;
	}

	/** A key in wp-config.php wins over one saved on the settings page. */
	public static function api_key() {
		if ( self::key_in_config() ) {
			return (string) NOBLE_LEAD_GUARD_API_KEY;
		}
		return (string) get_option( self::KEY_OPTION, '' );
	}

	/**
	 * The settings page shows a saved key only as dots (a stand-in value, never the key),
	 * so the stand-in or a blank box means "keep it".
	 */
	public static function sanitize_key( $input ) {
		$current = (string) get_option( self::KEY_OPTION, '' );

		if ( ! empty( $_POST['nlg_api_key_remove'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php checked the nonce.
			return '';
		}

		$input = trim( (string) $input );
		if ( '' === $input || self::KEY_MASK === $input ) {
			return $current;
		}
		if ( ! preg_match( '/^sk-ant-[A-Za-z0-9_\-]{20,}$/', $input ) ) {
			add_settings_error( self::KEY_OPTION, 'nlg_bad_key', 'That doesn\'t look like an Anthropic API key (they start with sk-ant-). The saved key was not changed.' );
			return $current;
		}
		return $input;
	}

	public static function model() {
		return ( defined( 'NOBLE_LEAD_GUARD_MODEL' ) && NOBLE_LEAD_GUARD_MODEL ) ? (string) NOBLE_LEAD_GUARD_MODEL : self::DEFAULT_MODEL;
	}

	public static function digest_to() {
		$to = self::get( 'digest_to' );
		if ( ! $to && defined( 'NOBLE_LEAD_GUARD_DIGEST_TO' ) ) {
			$to = NOBLE_LEAD_GUARD_DIGEST_TO;
		}
		return $to ? $to : get_option( 'admin_email' );
	}

	public static function is_form_skipped( $form_id ) {
		$ids = array_map( 'intval', explode( ',', (string) self::get( 'skip_forms' ) ) );
		return in_array( (int) $form_id, $ids, true );
	}
}

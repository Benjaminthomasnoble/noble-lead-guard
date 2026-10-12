<?php
defined( 'ABSPATH' ) || exit;

/**
 * Hooks into Gravity Forms' spam check, which runs before notifications are sent.
 * Entries marked as spam keep their data in the Spam folder but send no notifications.
 */
class NLG_Gravity {

	const FILTER_NAME = 'Noble Lead Guard';

	/** Field types that carry no message content. */
	const SKIP_TYPES = array( 'html', 'section', 'page', 'captcha', 'turnstile', 'fileupload', 'post_image', 'password', 'hidden', 'consent', 'signature' );

	/** Field types that mean the form takes payments; those forms are never filtered. */
	const PAYMENT_TYPES = array( 'product', 'option', 'shipping', 'total', 'creditcard', 'stripe_creditcard', 'square_creditcard', 'paypal_checkout' );

	const BLOCKING_ACTIONS = array( 'blocked', 'would_block' );

	/** @var array form_id => decision waiting for its entry id. */
	private static $pending = array();

	/** @var array hash => decision, so a repeated spam check in one request doesn't call the API twice. */
	private static $seen = array();

	public static function init() {
		// Runs after Gravity Forms' own checks (honeypot, Akismet, etc.), which use lower priorities.
		add_filter( 'gform_entry_is_spam', array( __CLASS__, 'filter_is_spam' ), 50, 3 );
		add_action( 'gform_entry_created', array( __CLASS__, 'entry_created' ), 10, 2 );
	}

	public static function filter_is_spam( $is_spam, $form, $entry ) {
		if ( $is_spam ) {
			return $is_spam; // Already caught by a free check, so skip the AI call.
		}
		$decision = self::decide( $form, $entry );
		if ( $decision && 'blocked' === $decision['action'] ) {
			if ( method_exists( 'GFCommon', 'set_spam_filter' ) ) {
				GFCommon::set_spam_filter( self::form_id( $form ), self::FILTER_NAME, self::describe( $decision ) );
			}
			return true;
		}
		return $is_spam;
	}

	/**
	 * Checks one submission and logs the result. Returns null when the submission isn't checked.
	 */
	public static function decide( $form, $entry ) {
		$mode = NLG_Settings::get( 'mode' );
		if ( 'off' === $mode || ! NLG_Settings::api_key() ) {
			return null;
		}

		$form_id = self::form_id( $form );
		if ( NLG_Settings::is_form_skipped( $form_id ) || self::takes_payments( $form ) ) {
			return null;
		}

		$built = self::build_text( $form, $entry );
		if ( '' === $built['text'] ) {
			return null;
		}

		$hash = md5( $form_id . '|' . $built['text'] );
		if ( isset( self::$seen[ $hash ] ) ) {
			return self::$seen[ $hash ];
		}

		$result = NLG_Classifier::classify( $built['text'], NLG_Settings::get( 'profile' ), NLG_Settings::get( 'timeout' ) );
		$action = self::action_for( $result, $mode );

		$decision = array(
			'mode'       => $mode,
			'action'     => $action,
			'verdict'    => $result['verdict'],
			'confidence' => $result['confidence'],
			'reason'     => $result['reason'],
			'error'      => $result['error'],
		);

		$decision['log_id'] = NLG_Log::insert(
			array(
				'form_id'    => $form_id,
				'mode'       => $mode,
				'verdict'    => $result['verdict'],
				'confidence' => $result['confidence'],
				'action'     => $action,
				'reason'     => $result['reason'],
				'snippet'    => $built['snippet'],
				'latency_ms' => $result['latency_ms'],
				'error'      => $result['error'],
			)
		);

		self::$seen[ $hash ]       = $decision;
		self::$pending[ $form_id ] = $decision;
		return $decision;
	}

	/**
	 * Anything other than a confident spam or solicitation verdict goes through.
	 * That includes API errors, timeouts and refusals, so an outage never costs a lead.
	 */
	public static function action_for( array $result, $mode ) {
		if ( ! $result['ok'] ) {
			return 'passed_error';
		}

		$holds = array( 'spam' );
		if ( NLG_Settings::get( 'hold_solicitations' ) ) {
			$holds[] = 'solicitation';
		}

		$allowed = 'medium' === NLG_Settings::get( 'min_confidence' ) ? array( 'high', 'medium' ) : array( 'high' );

		if ( in_array( $result['verdict'], $holds, true ) && in_array( $result['confidence'], $allowed, true ) ) {
			return 'on' === $mode ? 'blocked' : 'would_block';
		}
		return 'passed';
	}

	/**
	 * Fires once the entry is saved, before notifications. Links the log row to the entry
	 * and leaves a note on it, so test-mode verdicts can be reviewed next to each entry.
	 */
	public static function entry_created( $entry, $form ) {
		$form_id = self::form_id( $form );
		if ( empty( self::$pending[ $form_id ] ) || empty( $entry['id'] ) ) {
			return;
		}
		$decision = self::$pending[ $form_id ];
		unset( self::$pending[ $form_id ] );

		NLG_Log::set_entry( $decision['log_id'], $entry['id'] );

		// Blocked entries already get Gravity Forms' own spam note from set_spam_filter().
		if ( 'blocked' !== $decision['action'] ) {
			self::add_note( $entry['id'], self::describe( $decision ) );
		}
	}

	public static function describe( array $decision ) {
		switch ( $decision['action'] ) {
			case 'blocked':
				$lead = 'Held back as ' . $decision['verdict'];
				break;
			case 'would_block':
				$lead = 'Test mode: would have held this back as ' . $decision['verdict'];
				break;
			case 'passed_error':
				return 'Lead Guard could not check this entry, so it was sent as normal (' . $decision['error'] . ').';
			default:
				$lead = 'Checked: ' . $decision['verdict'];
		}
		return $lead . ' (' . $decision['confidence'] . ' confidence). ' . $decision['reason'];
	}

	/**
	 * Turns the entry into "Label: value" lines and picks the longest value as a preview.
	 */
	public static function build_text( $form, $entry ) {
		$lines              = array();
		$snippet            = '';
		$snippet_is_message = false;
		$fields             =isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();

		foreach ( $fields as $field ) {
			$type = isset( $field->type ) ? (string) $field->type : '';
			if ( in_array( $type, self::SKIP_TYPES, true ) ) {
				continue;
			}
			if ( isset( $field->visibility ) && 'administrative' === $field->visibility ) {
				continue;
			}

			if ( method_exists( $field, 'get_value_export' ) ) {
				$value = $field->get_value_export( $entry );
			} else {
				$value = isset( $entry[ (string) $field->id ] ) ? $entry[ (string) $field->id ] : '';
			}
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( array_map( 'strval', $value ) ) );
			}
			$value = trim( wp_strip_all_tags( (string) $value ) );
			if ( '' === $value ) {
				continue;
			}

			$label   = ! empty( $field->adminLabel ) ? $field->adminLabel : ( isset( $field->label ) ? $field->label : 'Field ' . $field->id );
			$lines[] = $label . ': ' . mb_substr( $value, 0, 2000 );

			// Preview the message box when there is one, otherwise the longest answer.
			$is_message = 'textarea' === $type;
			if ( ( $is_message && ! $snippet_is_message ) || ( $is_message === $snippet_is_message && mb_strlen( $value ) > mb_strlen( $snippet ) ) ) {
				$snippet            = $value;
				$snippet_is_message = $is_message;
			}
		}

		if ( ! $lines ) {
			return array(
				'text'    => '',
				'snippet' => '',
			);
		}

		$text = implode( "\n", $lines );
		if ( ! empty( $entry['source_url'] ) ) {
			$text = 'Submitted from page: ' . $entry['source_url'] . "\n" . $text;
		}

		return array(
			'text'    => mb_substr( $text, 0, 6000 ),
			'snippet' => mb_substr( preg_replace( '/\s+/', ' ', $snippet ), 0, 140 ),
		);
	}

	public static function takes_payments( $form ) {
		$fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();
		foreach ( $fields as $field ) {
			$type = isset( $field->type ) ? (string) $field->type : '';
			if ( in_array( $type, self::PAYMENT_TYPES, true ) ) {
				return true;
			}
			if ( method_exists( 'GFCommon', 'is_product_field' ) && GFCommon::is_product_field( $type ) ) {
				return true;
			}
		}
		return false;
	}

	private static function add_note( $entry_id, $note ) {
		if ( method_exists( 'GFAPI', 'add_note' ) ) {
			GFAPI::add_note( $entry_id, 0, self::FILTER_NAME, $note );
		} elseif ( method_exists( 'GFFormsModel', 'add_note' ) ) {
			GFFormsModel::add_note( $entry_id, 0, self::FILTER_NAME, $note );
		}
	}

	private static function form_id( $form ) {
		return isset( $form['id'] ) ? (int) $form['id'] : 0;
	}

	/** Lets tests start each case clean. */
	public static function reset() {
		self::$pending = array();
		self::$seen    = array();
	}
}

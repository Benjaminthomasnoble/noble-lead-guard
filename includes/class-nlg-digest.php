<?php
defined( 'ABSPATH' ) || exit;

/**
 * Weekly email listing what was held back (or would have been, in test mode),
 * so a real lead caught by mistake gets noticed.
 */
class NLG_Digest {

	const HOOK = 'nlg_weekly_digest';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'send' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * @return bool Whether an email went out. Nothing is sent for a week with no checks.
	 */
	public static function send() {
		NLG_Log::prune( 90 );
		$rows = NLG_Log::since( gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) );
		if ( ! $rows ) {
			return false;
		}
		$email = self::build( $rows, NLG_Settings::get( 'mode' ) );
		return (bool) wp_mail( NLG_Settings::digest_to(), $email['subject'], $email['body'] );
	}

	public static function build( array $rows, $mode ) {
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$test    = 'on' !== $mode;
		$counts  = array(
			'held'   => 0,
			'passed' => 0,
			'error'  => 0,
		);
		$held    = array();
		$border  = array();
		$errors  = array();

		foreach ( $rows as $row ) {
			if ( in_array( $row['action'], NLG_Gravity::BLOCKING_ACTIONS, true ) ) {
				$counts['held']++;
				$held[] = $row;
			} elseif ( 'passed_error' === $row['action'] ) {
				$counts['error']++;
				$errors[ $row['error'] ] = isset( $errors[ $row['error'] ] ) ? $errors[ $row['error'] ] + 1 : 1;
			} else {
				$counts['passed']++;
				if ( 'lead' !== $row['verdict'] ) {
					$border[] = $row;
				}
			}
		}

		$held_label = $test ? 'Would have been held back' : 'Held back';
		$subject    = sprintf( '[Lead Guard] %s: %d %s this week', $site, $counts['held'], $test ? 'would have been held back' : 'held back' );

		$out   = array();
		$out[] = sprintf( 'Lead Guard weekly report for %s (%s)', $site, home_url( '/' ) );
		$out[] = 'Mode: ' . ( $test ? 'Test mode. Nothing is being blocked yet; every email still went out.' : 'On. Held-back messages did not email the client.' );
		$out[] = '';
		$out[] = sprintf( 'Last 7 days: %d submissions checked.', count( $rows ) );
		$out[] = sprintf( '- %s: %d', $held_label, $counts['held'] );
		$out[] = sprintf( '- Sent through: %d', $counts['passed'] );
		$out[] = sprintf( '- Could not check, sent through as normal: %d', $counts['error'] );

		if ( $held ) {
			$out[] = '';
			$out[] = strtoupper( $held_label );
			foreach ( $held as $row ) {
				$out = array_merge( $out, self::row_lines( $row ) );
			}
			$out[] = '';
			$out[] = $test
				? 'If any of these is a real lead, the filter needs tuning before it is turned on. Reply with the entry link.'
				: 'If one of these is a real lead: open the entry, click "Not Spam", then "Resend Notifications".';
		}

		if ( $border ) {
			$out[] = '';
			$out[] = 'SENT THROUGH, BUT NOT RATED AS A LEAD (check these for spam that got past)';
			foreach ( $border as $row ) {
				$out = array_merge( $out, self::row_lines( $row ) );
			}
		}

		if ( $errors ) {
			$out[] = '';
			$out[] = 'ERRORS';
			foreach ( $errors as $message => $count ) {
				$out[] = sprintf( '- %s (%dx)', $message, $count );
			}
		}

		return array(
			'subject' => $subject,
			'body'    => implode( "\n", $out ) . "\n",
		);
	}

	private static function row_lines( array $row ) {
		$lines   = array();
		$lines[] = '';
		$lines[] = sprintf(
			'%s | %s | %s (%s)',
			get_date_from_gmt( $row['created_at'], 'M j, g:i a' ),
			self::form_title( (int) $row['form_id'] ),
			$row['verdict'],
			$row['confidence']
		);
		if ( $row['reason'] ) {
			$lines[] = $row['reason'];
		}
		if ( $row['snippet'] ) {
			$lines[] = '"' . $row['snippet'] . '"';
		}
		$lines[] = self::entry_link( $row );
		return $lines;
	}

	public static function entry_link( array $row ) {
		if ( ! empty( $row['entry_id'] ) ) {
			return admin_url( sprintf( 'admin.php?page=gf_entries&view=entry&id=%d&lid=%d', $row['form_id'], $row['entry_id'] ) );
		}
		return admin_url( sprintf( 'admin.php?page=gf_entries&id=%d&filter=spam', $row['form_id'] ) );
	}

	public static function form_title( $form_id ) {
		if ( class_exists( 'GFAPI' ) ) {
			$form = GFAPI::get_form( $form_id );
			if ( is_array( $form ) && ! empty( $form['title'] ) ) {
				return $form['title'];
			}
		}
		return 'Form ' . $form_id;
	}
}

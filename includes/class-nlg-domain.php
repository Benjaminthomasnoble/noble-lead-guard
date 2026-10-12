<?php
defined( 'ABSPATH' ) || exit;

/**
 * Looks up the website behind a business email address (title and description),
 * so the AI can tell when "jen@somename.com" is really a marketing or software vendor.
 */
class NLG_Domain {

	const CACHE_PREFIX = 'nlg_dom_';
	const TIMEOUT      = 3;

	/** Personal email providers. Their homepages say nothing about the sender. */
	const FREE_PROVIDERS = array(
		'gmail.com', 'googlemail.com', 'yahoo.com', 'ymail.com', 'rocketmail.com', 'hotmail.com', 'outlook.com',
		'live.com', 'msn.com', 'aol.com', 'icloud.com', 'me.com', 'mac.com', 'protonmail.com', 'proton.me',
		'pm.me', 'gmx.com', 'gmx.us', 'mail.com', 'zoho.com', 'yandex.com', 'hey.com', 'fastmail.com',
		'comcast.net', 'verizon.net', 'att.net', 'sbcglobal.net', 'bellsouth.net', 'charter.net', 'spectrum.net',
		'cox.net', 'earthlink.net', 'frontier.com', 'frontiernet.net', 'windstream.net', 'centurylink.net',
		'embarqmail.com', 'ptd.net', 'optonline.net', 'juno.com', 'netzero.net', 'q.com', 'twc.com', 'rr.com',
	);

	/** First email address in the text, or ''. */
	public static function find_email( $text ) {
		return preg_match( '/[A-Za-z0-9._%+\-]+@([A-Za-z0-9\-]+\.)+[A-Za-z]{2,}/', (string) $text, $m ) ? strtolower( $m[0] ) : '';
	}

	/** The domain worth looking up for this email, or '' for free providers and this site's own domain. */
	public static function business_domain( $email ) {
		$at = strrpos( (string) $email, '@' );
		if ( false === $at ) {
			return '';
		}
		$domain = strtolower( trim( substr( $email, $at + 1 ), " .\t\n\r" ) );
		if ( ! preg_match( '/^([a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain ) ) {
			return '';
		}
		if ( in_array( $domain, self::FREE_PROVIDERS, true ) ) {
			return '';
		}
		$own = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$own = preg_replace( '/^www\./', '', $own );
		if ( $domain === $own ) {
			return '';
		}
		return $domain;
	}

	/**
	 * One line describing the domain's website, e.g.
	 * "vasdirect.com: VAs Direct | Virtual Assistants for Small Business. Hire a trained ...".
	 * Cached for 30 days (failures for 1 day). Returns '' when nothing useful was found.
	 */
	public static function describe( $domain ) {
		if ( '' === $domain ) {
			return '';
		}
		$key    = self::CACHE_PREFIX . md5( $domain );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$summary = self::fetch( $domain );
		set_transient( $key, $summary, '' === $summary ? DAY_IN_SECONDS : 30 * DAY_IN_SECONDS );
		return $summary;
	}

	private static function fetch( $domain ) {
		// wp_safe_remote_get refuses private and local addresses, so a crafted email can't point the server inward.
		$response = wp_safe_remote_get(
			'https://' . $domain . '/',
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 3,
				'limit_response_size' => 300000,
				'user-agent'          => 'Mozilla/5.0 (compatible; NobleLeadGuard/' . NLG_VERSION . '; +' . home_url( '/' ) . ')',
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$html  = (string) wp_remote_retrieve_body( $response );
		$title = self::match( '/<title[^>]*>(.*?)<\/title>/is', $html );
		$desc  = self::meta( $html, 'description' );
		if ( '' === $desc ) {
			$desc = self::meta( $html, 'og:description' );
		}
		if ( '' === $title && '' === $desc ) {
			return '';
		}

		$label = $domain;
		$final = self::final_host( $response );
		if ( $final && $final !== $domain && 'www.' . $domain !== $final ) {
			$label .= ' (redirects to ' . $final . ')';
		}

		$text = trim( $title . ( $title && $desc ? '. ' : '' ) . $desc );
		return $label . ': ' . mb_substr( $text, 0, 300 );
	}

	private static function meta( $html, $name ) {
		$name = preg_quote( $name, '/' );
		$val  = self::match( '/<meta[^>]+(?:name|property)=["\']' . $name . '["\'][^>]*content=["\']([^"\']*)["\']/is', $html );
		if ( '' === $val ) {
			$val = self::match( '/<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:name|property)=["\']' . $name . '["\']/is', $html );
		}
		return $val;
	}

	private static function match( $pattern, $html ) {
		if ( ! preg_match( $pattern, $html, $m ) ) {
			return '';
		}
		$text = html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	private static function final_host( $response ) {
		if ( isset( $response['http_response'] ) && is_object( $response['http_response'] ) && method_exists( $response['http_response'], 'get_response_object' ) ) {
			$obj = $response['http_response']->get_response_object();
			if ( isset( $obj->url ) ) {
				return strtolower( (string) wp_parse_url( $obj->url, PHP_URL_HOST ) );
			}
		}
		return '';
	}
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * One row per checked submission, so test mode can be compared against real outcomes.
 */
class NLG_Log {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'nlg_log';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			form_id int(10) unsigned NOT NULL DEFAULT 0,
			entry_id bigint(20) unsigned DEFAULT NULL,
			mode varchar(10) NOT NULL DEFAULT '',
			verdict varchar(20) NOT NULL DEFAULT '',
			confidence varchar(10) NOT NULL DEFAULT '',
			action varchar(20) NOT NULL DEFAULT '',
			reason text NULL,
			snippet varchar(255) NOT NULL DEFAULT '',
			latency_ms int(10) unsigned NOT NULL DEFAULT 0,
			error varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );
		add_option( NLG_Settings::KEY_OPTION, '', '', false ); // Not autoloaded on every page view.
		update_option( 'nlg_db_version', NLG_VERSION );
	}

	public static function insert( array $row ) {
		global $wpdb;
		$row = array_merge(
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'form_id'    => 0,
				'mode'       => '',
				'verdict'    => '',
				'confidence' => '',
				'action'     => '',
				'reason'     => '',
				'snippet'    => '',
				'latency_ms' => 0,
				'error'      => '',
			),
			$row
		);
		$row['snippet'] = mb_substr( (string) $row['snippet'], 0, 250 );
		$row['error']   = mb_substr( (string) $row['error'], 0, 250 );

		$wpdb->insert( self::table(), $row );
		return (int) $wpdb->insert_id;
	}

	public static function set_entry( $log_id, $entry_id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'entry_id' => (int) $entry_id ), array( 'id' => (int) $log_id ) );
	}

	public static function recent( $limit = 50 ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limit ), ARRAY_A );
	}

	public static function since( $gmt_datetime ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s ORDER BY id ASC", $gmt_datetime ), ARRAY_A );
	}

	public static function prune( $days = 90 ) {
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}
}

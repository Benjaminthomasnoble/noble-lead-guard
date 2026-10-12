<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nlg_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_option( 'nlg_settings' );
delete_option( 'nlg_api_key' );
delete_option( 'nlg_db_version' );
delete_site_transient( 'nlg_update_info' );
wp_clear_scheduled_hook( 'nlg_weekly_digest' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_nlg\\_dom\\_%' OR option_name LIKE '\\_transient\\_timeout\\_nlg\\_dom\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

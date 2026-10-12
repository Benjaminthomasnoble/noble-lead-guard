<?php
/**
 * Plugin Name:       Noble Lead Guard
 * Description:       Uses AI to hold back spam and sales pitches sent through Gravity Forms before the notification email goes out.
 * Version:           0.2.0
 * Author:            Noble Digital
 * Author URI:        https://nobledigital.tech
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       noble-lead-guard
 *
 * Setup: paste a connection code from the Lead Guard relay (or an Anthropic API key) under Settings > Lead Guard.
 * Updates: published as GitHub releases of Benjaminthomasnoble/noble-lead-guard.
 * Optional wp-config.php overrides:
 *     define( 'NOBLE_LEAD_GUARD_API_KEY', 'nlg1_...' ); // connection code, or an sk-ant-... key
 *     define( 'NOBLE_LEAD_GUARD_DIGEST_TO', 'you@example.com' );
 *     define( 'NOBLE_LEAD_GUARD_MODEL', 'claude-haiku-5-5' );
 */

defined( 'ABSPATH' ) || exit;

define( 'NLG_VERSION', '0.2.0' );
define( 'NLG_FILE', __FILE__ );
define( 'NLG_DIR', __DIR__ . '/' );

require_once NLG_DIR . 'includes/class-nlg-settings.php';
require_once NLG_DIR . 'includes/class-nlg-log.php';
require_once NLG_DIR . 'includes/class-nlg-domain.php';
require_once NLG_DIR . 'includes/class-nlg-classifier.php';
require_once NLG_DIR . 'includes/class-nlg-profile.php';
require_once NLG_DIR . 'includes/class-nlg-gravity.php';
require_once NLG_DIR . 'includes/class-nlg-digest.php';
require_once NLG_DIR . 'includes/class-nlg-admin.php';
require_once NLG_DIR . 'includes/class-nlg-updater.php';

register_activation_hook( __FILE__, 'nlg_activate' );
register_deactivation_hook( __FILE__, 'nlg_deactivate' );

function nlg_activate() {
	NLG_Log::install();
	NLG_Digest::schedule();
}

function nlg_deactivate() {
	NLG_Digest::unschedule();
}

add_action( 'plugins_loaded', 'nlg_boot' );

function nlg_boot() {
	if ( get_option( 'nlg_db_version' ) !== NLG_VERSION ) {
		NLG_Log::install();
	}
	NLG_Gravity::init();
	NLG_Digest::init();
	NLG_Updater::init();
	if ( is_admin() ) {
		NLG_Admin::init();
	}
}

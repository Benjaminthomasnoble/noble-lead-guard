<?php
defined( 'ABSPATH' ) || exit;

/**
 * Settings > Lead Guard: settings, a box to try a sample message, and recent checks.
 */
class NLG_Admin {

	const SLUG = 'noble-lead-guard';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_nlg_try', array( __CLASS__, 'handle_try' ) );
		add_action( 'admin_post_nlg_digest', array( __CLASS__, 'handle_digest' ) );
		add_action( 'admin_post_nlg_profile', array( __CLASS__, 'handle_profile' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NLG_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_options_page( 'Lead Guard', 'Lead Guard', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function register() {
		register_setting(
			'nlg',
			NLG_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'NLG_Settings', 'sanitize' ),
				'default'           => NLG_Settings::defaults(),
			)
		);
		register_setting(
			'nlg',
			NLG_Settings::KEY_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( 'NLG_Settings', 'sanitize_key' ),
				'default'           => '',
			)
		);
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">Settings</a>' );
		return $links;
	}

	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) || 'off' === NLG_Settings::get( 'mode' ) ) {
			return;
		}
		if ( ! NLG_Settings::api_key() ) {
			echo '<div class="notice notice-warning"><p><strong>Lead Guard</strong> is not checking forms yet: add your API key under <a href="' . esc_url( self::page_url() ) . '">Settings &gt; Lead Guard</a>.</p></div>';
		} elseif ( ! class_exists( 'GFForms' ) ) {
			echo '<div class="notice notice-warning"><p><strong>Lead Guard</strong> needs Gravity Forms, which is not active on this site.</p></div>';
		}
	}

	public static function handle_try() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'nlg_try' );

		$sample = isset( $_POST['nlg_sample'] ) ? sanitize_textarea_field( wp_unslash( $_POST['nlg_sample'] ) ) : '';
		if ( '' !== trim( $sample ) ) {
			$result           = NLG_Classifier::classify( $sample, NLG_Settings::get( 'profile' ), NLG_Settings::get( 'timeout' ) );
			$result['sample'] = $sample;
			set_transient( 'nlg_try_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( self::page_url() . '#try' );
		exit;
	}

	public static function handle_digest() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'nlg_digest' );
		$sent = NLG_Digest::send();
		wp_safe_redirect( add_query_arg( 'nlg_digest', $sent ? 'sent' : 'empty', self::page_url() ) );
		exit;
	}

	/** "Write it for me": drafts the business description from the site's pages. Nothing is saved here. */
	public static function handle_profile() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'nlg_profile', 'nlg_profile_nonce' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- the AI call can take longer than some hosts' 30 second default.
		}
		set_transient( 'nlg_profile_' . get_current_user_id(), NLG_Profile::draft(), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::page_url() . '#nlg-profile' );
		exit;
	}

	private static function page_url() {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s   = NLG_Settings::all();
		$key = NLG_Settings::api_key();
		$opt = NLG_Settings::OPTION;
		?>
		<div class="wrap">
			<h1>Lead Guard</h1>
			<p>Checks every Gravity Forms submission with AI and holds back spam and sales pitches before the notification email goes out. Held-back entries stay in the form's Spam folder. If the check fails or times out, the email goes out as normal.</p>

			<?php if ( isset( $_GET['nlg_digest'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-info"><p>
					<?php echo 'sent' === $_GET['nlg_digest'] ? 'Report sent to ' . esc_html( NLG_Settings::digest_to() ) . '.' : 'Nothing was checked in the last 7 days, so no report was sent.'; // phpcs:ignore ?>
				</p></div>
			<?php endif; ?>

			<table class="widefat striped" style="max-width:720px;margin:1em 0">
				<tbody>
					<tr><th>API key</th><td><?php echo $key ? 'Saved' . ( NLG_Settings::key_in_config() ? ' in wp-config.php' : '' ) . ' (ends in ' . esc_html( substr( $key, -4 ) ) . ')' : '<strong>Missing.</strong> Paste it in the API key box below.'; ?></td></tr>
					<tr><th>Gravity Forms</th><td><?php echo class_exists( 'GFForms' ) ? 'Active' : '<strong>Not active</strong>'; ?></td></tr>
					<tr><th>Model</th><td><code><?php echo esc_html( NLG_Settings::model() ); ?></code></td></tr>
					<tr><th>Weekly report goes to</th><td><?php echo esc_html( NLG_Settings::digest_to() ); ?></td></tr>
				</tbody>
			</table>

			<form method="post" action="options.php">
				<?php settings_fields( 'nlg' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nlg-key">API key</label></th>
						<td>
							<?php if ( NLG_Settings::key_in_config() ) : ?>
								Set in wp-config.php, which overrides this box.
							<?php else : ?>
								<input id="nlg-key" type="password" name="<?php echo esc_attr( NLG_Settings::KEY_OPTION ); ?>" value="<?php echo $key ? esc_attr( NLG_Settings::KEY_MASK ) : ''; ?>" class="regular-text" autocomplete="new-password" placeholder="sk-ant-..." onfocus="this.select()">
								<?php if ( $key ) : ?>
									<label style="margin-left:8px"><input type="checkbox" name="nlg_api_key_remove" value="1"> Remove saved key</label>
								<?php endif; ?>
								<p class="description"><?php echo $key ? 'Key saved, ends in ' . esc_html( substr( $key, -4 ) ) . '. To replace it, click the box and paste a new key.' : 'From console.anthropic.com. The key is never shown again after you save it.'; ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Mode</th>
						<td>
							<?php
							$modes = array(
								'test' => 'Test: check and log every submission, block nothing',
								'on'   => 'On: hold back spam and sales pitches',
								'off'  => 'Off: no checks',
							);
							foreach ( $modes as $value => $label ) {
								printf(
									'<label style="display:block;margin-bottom:4px"><input type="radio" name="%s[mode]" value="%s" %s> %s</label>',
									esc_attr( $opt ),
									esc_attr( $value ),
									checked( $s['mode'], $value, false ),
									esc_html( $label )
								);
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nlg-profile">About this business</label></th>
						<td>
							<?php
							$draft_key = 'nlg_profile_' . get_current_user_id();
							$draft     = get_transient( $draft_key );
							$profile   = $s['profile'];
							if ( is_array( $draft ) ) {
								delete_transient( $draft_key );
								if ( $draft['ok'] ) {
									$profile = $draft['profile'];
									echo '<div class="notice notice-success inline"><p><strong>Draft written from this site\'s pages.</strong> Read it, fix anything that\'s off, then click Save Changes. The old description stays in place until you save.</p></div>';
								} else {
									echo '<div class="notice notice-error inline"><p>' . esc_html( $draft['error'] ) . '</p></div>';
								}
							}
							?>
							<textarea id="nlg-profile" name="<?php echo esc_attr( $opt ); ?>[profile]" rows="5" class="large-text"><?php echo esc_textarea( $profile ); ?></textarea>
							<p>
								<?php wp_nonce_field( 'nlg_profile', 'nlg_profile_nonce', false ); ?>
								<button type="submit" class="button" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" name="action" value="nlg_profile" <?php disabled( ! $key ); ?> onclick="var n=this.nextElementSibling;setTimeout(function(){n.style.display='inline'},0)">Write it for me</button>
								<span style="display:none;margin-left:8px"><span class="spinner is-active" style="float:none;margin:0 4px 0 0"></span>Reading the site and writing a draft. This takes about 15 seconds.</span>
							</p>
							<p class="description">What the business does, where, and what real inquiries ask about. "Write it for me" reads this site's pages and drafts it for you (unsaved changes elsewhere on this page are lost, so save first).</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Sales pitches</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[hold_solicitations]" value="1" <?php checked( $s['hold_solicitations'], 1 ); ?>> Also hold back people selling to the business (SEO, marketing, web design, loans)</label></td>
					</tr>
					<tr>
						<th scope="row">Hold back when the AI is</th>
						<td>
							<select name="<?php echo esc_attr( $opt ); ?>[min_confidence]">
								<option value="high" <?php selected( $s['min_confidence'], 'high' ); ?>>Sure (safest)</option>
								<option value="medium" <?php selected( $s['min_confidence'], 'medium' ); ?>>Sure or fairly sure (catches more)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nlg-timeout">Time limit</label></th>
						<td><input id="nlg-timeout" type="number" min="2" max="15" name="<?php echo esc_attr( $opt ); ?>[timeout]" value="<?php echo esc_attr( $s['timeout'] ); ?>" class="small-text"> seconds. After this the submission goes through unchecked.</td>
					</tr>
					<tr>
						<th scope="row"><label for="nlg-digest">Weekly report email</label></th>
						<td><input id="nlg-digest" type="email" name="<?php echo esc_attr( $opt ); ?>[digest_to]" value="<?php echo esc_attr( $s['digest_to'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( NLG_Settings::digest_to() ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="nlg-skip">Skip these form IDs</label></th>
						<td><input id="nlg-skip" type="text" name="<?php echo esc_attr( $opt ); ?>[skip_forms]" value="<?php echo esc_attr( $s['skip_forms'] ); ?>" class="regular-text" placeholder="e.g. 3, 7"><p class="description">Forms with payment fields are always skipped.</p></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2 id="try">Try a message</h2>
			<?php self::render_try_result(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="nlg_try">
				<?php wp_nonce_field( 'nlg_try' ); ?>
				<textarea name="nlg_sample" rows="5" class="large-text" placeholder="Paste a form submission here, for example:&#10;Name: Sam&#10;Message: Hi, we noticed your site isn't ranking on Google..."></textarea>
				<?php submit_button( 'Check this message', 'secondary' ); ?>
			</form>

			<h2>Recent checks</h2>
			<?php self::render_log(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="nlg_digest">
				<?php wp_nonce_field( 'nlg_digest' ); ?>
				<?php submit_button( 'Send the weekly report now', 'secondary' ); ?>
			</form>

			<?php self::render_how_it_decides(); ?>
		</div>
		<?php
	}

	/** Plain-language explanation, so anyone looking at a held entry can see why. */
	private static function render_how_it_decides() {
		$holds = NLG_Settings::get( 'hold_solicitations' ) ? 'spam and sales pitches' : 'spam (sales pitches are still emailed)';
		$sure  = 'medium' === NLG_Settings::get( 'min_confidence' ) ? 'sure or fairly sure' : 'sure';
		?>
		<h2 id="how">How it decides</h2>
		<ol style="max-width:760px">
			<li>Gravity Forms' own spam checks run first. Anything they catch never reaches the AI.</li>
			<li>Forms that take payments, forms on the skip list, and empty submissions are never checked.</li>
			<li>If the sender uses a business email address (not Gmail, Yahoo, Outlook and the like), Lead Guard looks up that company's website title and description, so it can tell when the sender sells marketing, software, staffing or similar services.</li>
			<li>The AI reads the submission, the "About this business" line and that website info, then rates it as a <strong>lead</strong>, <strong>unclear</strong>, a <strong>sales pitch</strong> or <strong>spam</strong>, and says how sure it is.</li>
			<li>With the current settings, it holds back <?php echo esc_html( $holds ); ?> only when the AI is <?php echo esc_html( $sure ); ?>. Everything else is emailed as normal, including anything it couldn't check because of a timeout or outage.</li>
		</ol>
		<p style="max-width:760px">Held-back entries are never deleted. They sit in the form's Spam folder with a note saying why. To rescue one, open it, click <strong>Not Spam</strong>, then <strong>Resend Notifications</strong>. In Test mode nothing is held back; each entry just gets a note with what the AI would have done.</p>
		<p style="max-width:760px">It's built to let things through when in doubt: a short, vague or misspelled message from someone who could be a customer always counts as a lead. If something keeps getting rated wrong, add a sentence about it to "About this business" (for example, "Subcontractors and job applicants are leads").</p>
		<?php
	}

	private static function render_try_result() {
		$key    = 'nlg_try_' . get_current_user_id();
		$result = get_transient( $key );
		if ( ! $result ) {
			return;
		}
		delete_transient( $key );

		if ( ! $result['ok'] ) {
			echo '<div class="notice notice-error inline"><p>Check failed: ' . esc_html( $result['error'] ) . '</p></div>';
			return;
		}
		$action = NLG_Gravity::action_for( $result, 'on' );
		printf(
			'<div class="notice notice-%s inline"><p><strong>%s</strong> (%s confidence, %d ms). %s<br>%s%s</p></div>',
			'blocked' === $action ? 'warning' : 'success',
			esc_html( ucfirst( $result['verdict'] ) ),
			esc_html( $result['confidence'] ),
			(int) $result['latency_ms'],
			esc_html( $result['reason'] ),
			'blocked' === $action ? 'With Lead Guard on, this would be held back.' : 'With Lead Guard on, this would be emailed as normal.',
			! empty( $result['site'] ) ? '<br><em>Sender\'s website: ' . esc_html( $result['site'] ) . '</em>' : ''
		);
	}

	private static function render_log() {
		$rows = NLG_Log::recent( 50 );
		if ( ! $rows ) {
			echo '<p>No submissions checked yet.</p>';
			return;
		}
		$labels = array(
			'blocked'      => 'Held back',
			'would_block'  => 'Would hold back (test)',
			'passed'       => 'Sent',
			'passed_error' => 'Sent (check failed)',
		);
		echo '<table class="widefat striped"><thead><tr><th>When</th><th>Form</th><th>Result</th><th>Verdict</th><th>Why</th><th>Message</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$why = $row['error'] ? $row['error'] : $row['reason'];
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s">Entry</a></td></tr>',
				esc_html( get_date_from_gmt( $row['created_at'], 'M j, g:i a' ) ),
				esc_html( NLG_Digest::form_title( (int) $row['form_id'] ) ),
				esc_html( isset( $labels[ $row['action'] ] ) ? $labels[ $row['action'] ] : $row['action'] ),
				esc_html( $row['verdict'] ? $row['verdict'] . ' (' . $row['confidence'] . ')' : '' ),
				esc_html( $why ),
				esc_html( $row['snippet'] ),
				esc_url( NLG_Digest::entry_link( $row ) )
			);
		}
		echo '</tbody></table>';
	}
}

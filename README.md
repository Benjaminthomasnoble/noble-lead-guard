# Noble Lead Guard 0.2.0

Checks every Gravity Forms submission with Claude and holds back spam and sales pitches before the notification email goes out. Held-back entries stay in the form's Spam folder with a note saying why. Anything the AI isn't sure about, and anything it can't check (timeout, API outage), is emailed as normal.

## Install on nobledigital.tech (test mode)

1. **Get an API key.** At console.anthropic.com, add a little prepaid credit (leave auto-reload off so it caps spending), then Create key with **Scope** set to a workspace ("Default workspace" is fine). An "Organization" scoped key is rejected by the API. Don't paste the key in chat.
2. **Upload the plugin.** WordPress admin > Plugins > Add New > Upload Plugin > `noble-lead-guard.zip` > Activate.
3. **Paste the key** into the API key box under Settings > Lead Guard and save. After that the box shows dots and the last 4 characters, never the key itself.
4. **On the same page,** leave Mode on **Test**. Under "About this business", click **Write it for me**: it reads the site's own pages (homepage, services, about, contact) and drafts the description in about 15 seconds. Read it, fix anything that's off, and click Save Changes. Each draft costs about 3 cents.
5. **Try it.** Paste a spam message you got recently into "Try a message" and check the verdict. Then paste a real lead.
6. **Wait two weeks.** Every new entry gets a Lead Guard note, the Recent checks table fills up, and, if you ticked the weekly report box, a report email arrives each week. Once nothing real has been flagged, switch Mode to **On**.

## How it decides

Two settings on the settings page:

- **Sales pitches** (on by default): hold back sales pitches as well as spam.
- **Hold back when the AI is**: "Sure (safest)" by default, or "Sure or fairly sure" to catch more.

Messages rated lead or unclear are always emailed, and so is anything that couldn't be checked (timeout, API error). In Test mode nothing is held back; the verdict is logged and noted on the entry.

**Sender's email domain.** When the sender uses a business email (not Gmail, Yahoo, Outlook, Comcast and similar), Lead Guard reads that domain's homepage title and description and gives it to the AI, so "jen@vasdirect.com" is recognized as a virtual-assistant company even when the message is only a phone number. The lookup is capped at 3 seconds, cached for 30 days, and skipped if the site can't be reached. There's nothing to set up.

Forms with payment fields are never checked. Submissions another filter already caught (Gravity Forms' honeypot, Akismet and similar) aren't sent to the AI.

## Connection codes (no key on client sites)

Client sites don't need the API key. The Lead Guard relay (see [relay/README.md](relay/README.md)) holds the one key on Cloudflare, and each site gets its own connection code from the relay's `/admin` page. Paste the code where the key would go. A site with its own `sk-ant-` key still talks to Anthropic directly.

## Updates

Updates come from the GitHub repo `Benjaminthomasnoble/noble-lead-guard`. Every site running 0.1.5 or later checks its latest release twice a day and shows "Update available" like any other plugin.

- **To ship an update:** bump the version in `noble-lead-guard.php` (both the `Version:` line and `NLG_VERSION`) and push to `main`. The Release action builds the zip and publishes it as a GitHub release. Pushes that don't change the version publish nothing, so work in progress never reaches client sites.
- **To make a site update by itself:** on that site's Plugins page, click **Enable auto-updates** next to Lead Guard.
- Each site keeps its own API key, settings and log through updates.
- Sites on 0.1.4 or older need 0.1.5 installed by hand once.

## If a real lead gets held back

Open the entry from the report link, click **Not Spam**, then **Resend Notifications**. The visitor saw Gravity Forms' default thank-you message instead of the form's own confirmation.

## Notes

- The API key is saved in its own database option, not loaded on every page view. To keep it out of the database instead, add `define( 'NOBLE_LEAD_GUARD_API_KEY', 'nlg1_...' );` (a connection code or an `sk-ant-` key) to wp-config.php, which overrides the settings box.
- Weekly report: off unless the box under Weekly report is ticked, and the box needs an email address (on the settings page, or `define( 'NOBLE_LEAD_GUARD_DIGEST_TO', '...' );`). Sites updating from 0.1.x keep the report only if they had typed in an address.
- Default model: `claude-haiku-5-5` (about $0.0002 per check). Override with `define( 'NOBLE_LEAD_GUARD_MODEL', '...' );`.
- Log rows older than 90 days are deleted. Uninstalling removes the log table and settings.
- Gravity Forms 2.7+ is needed for the "why it was flagged" spam note. Older versions still block, without the note.

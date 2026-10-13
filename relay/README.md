# Lead Guard relay

A small Cloudflare Worker that holds the one Anthropic API key, so client sites never have it.
Each site gets a **connection code** instead of the key. The site sends its form checks to the
relay with that code; the relay checks the code, makes sure the request is a Lead Guard check,
adds the key and passes it on to Anthropic. Nothing is stored on the relay.

- The key lives only in Cloudflare's encrypted secrets. It is not in this repo, the plugin zip, or any client site.
- A code only works for Lead Guard's own two requests (form checks and "Write it for me"), at most 30 a minute per site, so a leaked code can't be used as a free AI account.
- To cut off one site, add its domain to the `BLOCKED_SITES` secret. Changing `SITE_SECRET` disconnects every site.
- If the relay is ever down, the plugin's normal fail-open rule applies: the email goes out unchecked.
- Cloudflare's free plan allows 100,000 requests a day. Waiting on the AI doesn't count against it.

## One-time setup

1. **Cloudflare account.** Sign up free at dash.cloudflare.com. Open **Workers & Pages** once and accept the free `workers.dev` subdomain it offers.
2. **Connect the repo.** **Workers & Pages** > **Create application** > **Import a repository** > GitHub > `noble-lead-guard`. Set the project name to `noble-lead-guard-relay`, the root directory to `relay`, leave the build command empty and the deploy command as `npx wrangler deploy`. Cloudflare then redeploys the relay on its own whenever `relay/` changes on main. No API token is needed.
3. **Add the secrets.** **Workers & Pages** > `noble-lead-guard-relay` > **Settings** > **Variables and Secrets** > **Add**, type **Secret**, for each of:
   - `ANTHROPIC_API_KEY`: the key (create a fresh one in the Anthropic Console with a monthly spend limit).
   - `SITE_SECRET`: a long random string. The relay's `/admin` page shows one you can copy until this is set.
   - `ADMIN_PASSWORD`: a password you'll use to make connection codes.

## Connecting a site

1. Open `https://noble-lead-guard-relay.<your-subdomain>.workers.dev/admin`.
2. Enter the site's domain (like `terrafirmaconcrete.com`) and the admin password, then **Make connection code**.
3. On that site: **Settings > Lead Guard**, paste the code in the **API key or connection code** box, and save. Or put it in wp-config.php: `define( 'NOBLE_LEAD_GUARD_API_KEY', 'nlg1_...' );`

The settings page then says "Connected through the Lead Guard relay". A site can still use its own `sk-ant-` key instead; it then talks to Anthropic directly.

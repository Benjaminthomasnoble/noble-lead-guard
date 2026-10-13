/**
 * Lead Guard relay: holds the one Anthropic API key so client sites never do.
 *
 * Client sites send their checks here with a connection code instead of a key. The relay
 * checks the code, makes sure the request is a Lead Guard check (not general AI use),
 * adds the key and passes it to Anthropic. Nothing is stored.
 *
 * Secrets (Cloudflare dashboard > Workers > noble-lead-guard-relay > Settings > Variables and Secrets):
 *   ANTHROPIC_API_KEY  the key every site uses
 *   SITE_SECRET        any long random string; signs connection codes (changing it disconnects every site)
 *   ADMIN_PASSWORD     opens /admin, where connection codes are made
 *   BLOCKED_SITES      optional: comma-separated domains to switch off, e.g. "oldclient.com, test.com"
 */

const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
const MODELS = ['claude-haiku-5-5', 'claude-opus-5-5'];
const MAX_BODY_BYTES = 64 * 1024;
const MAX_TOKENS = 4096;
// The first words of the plugin's two system prompts: the form check and "Write it for me".
const PROMPT_STARTS = [
  'You screen contact form submissions for a small business website',
  'You write the business description used by a contact-form spam filter',
];

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    try {
      if (url.pathname === '/v1/messages') {
        return await relay(request, env);
      }
      if (url.pathname === '/admin') {
        return await admin(request, env, url);
      }
      if (url.pathname === '/') {
        return text('Lead Guard relay is running.');
      }
      return json({ error: { type: 'not_found', message: 'Not found' } }, 404);
    } catch (e) {
      return json({ error: { type: 'relay_error', message: 'Relay error' } }, 500);
    }
  },
};

async function relay(request, env) {
  if (request.method !== 'POST') {
    return json({ error: { type: 'invalid_request_error', message: 'Use POST' } }, 405);
  }
  if (!env.ANTHROPIC_API_KEY || !env.SITE_SECRET) {
    return json({ error: { type: 'relay_error', message: 'The relay is not set up yet (missing secrets)' } }, 503);
  }

  const auth = request.headers.get('authorization') || '';
  const code = auth.startsWith('Bearer ') ? auth.slice(7).trim() : '';
  const site = await verifyCode(code, env.SITE_SECRET);
  if (!site) {
    return json({ error: { type: 'authentication_error', message: 'Connection code not recognized' } }, 401);
  }
  if (blockedSites(env).includes(site)) {
    return json({ error: { type: 'permission_error', message: 'This site has been switched off on the relay' } }, 403);
  }

  if (env.SITE_LIMITER) {
    const { success } = await env.SITE_LIMITER.limit({ key: site });
    if (!success) {
      return json({ error: { type: 'rate_limit_error', message: 'Too many checks from this site; try again in a minute' } }, 429);
    }
  }

  const raw = await request.text();
  if (raw.length > MAX_BODY_BYTES) {
    return json({ error: { type: 'invalid_request_error', message: 'Request too large' } }, 413);
  }
  let body;
  try {
    body = JSON.parse(raw);
  } catch (e) {
    return json({ error: { type: 'invalid_request_error', message: 'Body is not JSON' } }, 400);
  }
  const problem = checkBody(body);
  if (problem) {
    return json({ error: { type: 'invalid_request_error', message: problem } }, 400);
  }

  const upstream = await fetch(ANTHROPIC_URL, {
    method: 'POST',
    headers: {
      'x-api-key': env.ANTHROPIC_API_KEY,
      'anthropic-version': '2023-06-01',
      'content-type': 'application/json',
    },
    body: raw,
  });
  return new Response(upstream.body, {
    status: upstream.status,
    headers: { 'content-type': upstream.headers.get('content-type') || 'application/json' },
  });
}

/** Only Lead Guard's own two requests get through, so a leaked code can't be used as a free AI account. */
export function checkBody(body) {
  if (!body || typeof body !== 'object' || Array.isArray(body)) return 'Body must be an object';
  const allowed = ['model', 'max_tokens', 'output_config', 'system', 'messages'];
  for (const key of Object.keys(body)) {
    if (!allowed.includes(key)) return `Field not allowed: ${key}`;
  }
  if (!MODELS.includes(body.model)) return 'Model not allowed';
  if (!Number.isInteger(body.max_tokens) || body.max_tokens < 1 || body.max_tokens > MAX_TOKENS) return 'max_tokens not allowed';
  if (typeof body.system !== 'string' || !PROMPT_STARTS.some((start) => body.system.startsWith(start))) return 'Not a Lead Guard request';
  if (!Array.isArray(body.messages) || body.messages.length !== 1) return 'Exactly one message is allowed';
  const message = body.messages[0];
  if (!message || message.role !== 'user' || typeof message.content !== 'string') return 'Message must be plain user text';
  return '';
}

// Connection codes look like nlg1_<relay>.<site>.<signature>, each part base64url. The relay part
// tells the plugin where to send checks; the signature proves the code was made with SITE_SECRET.
export async function makeCode(relayOrigin, site, secret) {
  return `nlg1_${b64(relayOrigin)}.${b64(site)}.${await sign(site, secret)}`;
}

export async function verifyCode(code, secret) {
  const match = /^nlg1_([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/.exec(code || '');
  if (!match) return '';
  let site;
  try {
    site = unb64(match[2]);
  } catch (e) {
    return '';
  }
  const expected = await sign(site, secret);
  return (await sameText(expected, match[3], secret)) ? site : '';
}

export function cleanSite(input) {
  let site = String(input || '').trim().toLowerCase();
  site = site.replace(/^https?:\/\//, '').replace(/\/.*$/, '').replace(/:\d+$/, '').replace(/^www\./, '');
  return /^[a-z0-9.-]+\.[a-z]{2,}$/.test(site) ? site : '';
}

function blockedSites(env) {
  return String(env.BLOCKED_SITES || '')
    .split(',')
    .map(cleanSite)
    .filter(Boolean);
}

/**
 * The page where connection codes are made. A POST with "Accept: application/json" (the plugin's
 * "Connect this site" button) gets {site, code} or {error: {message}} back instead of the page.
 */
async function admin(request, env, url) {
  const wantsJson = request.method === 'POST' && (request.headers.get('accept') || '').includes('application/json');
  if (!env.ADMIN_PASSWORD || !env.SITE_SECRET) {
    if (wantsJson) return json({ error: { message: 'The relay is not set up yet (missing secrets)' } }, 503);
    return html(page('<p>Add the ADMIN_PASSWORD and SITE_SECRET secrets in Cloudflare first.</p>' + secretHint()));
  }
  if (request.method !== 'POST') {
    return html(page(form('')));
  }

  if (env.ADMIN_LIMITER) {
    const ip = request.headers.get('cf-connecting-ip') || 'unknown';
    const { success } = await env.ADMIN_LIMITER.limit({ key: ip });
    if (!success) {
      if (wantsJson) return json({ error: { message: 'Too many tries. Wait a minute.' } }, 429);
      return html(page('<p>Too many tries. Wait a minute.</p>'), 429);
    }
  }

  const data = await request.formData();
  const password = String(data.get('password') || '');
  const site = cleanSite(data.get('site'));
  if (!(await sameText(password, env.ADMIN_PASSWORD, env.SITE_SECRET))) {
    if (wantsJson) return json({ error: { message: 'Wrong password.' } }, 401);
    return html(page('<p class="err">Wrong password.</p>' + form(data.get('site') || '')), 401);
  }
  if (!site) {
    if (wantsJson) return json({ error: { message: 'The relay could not read the site\'s domain, like terrafirmaconcrete.com.' } }, 400);
    return html(page('<p class="err">Enter the site\'s domain, like terrafirmaconcrete.com.</p>' + form('')));
  }
  const code = await makeCode(url.origin, site, env.SITE_SECRET);
  if (wantsJson) return json({ site, code });
  return html(
    page(
      `<p>Connection code for <strong>${escape(site)}</strong>:</p>
      <textarea readonly rows="3" onclick="this.select()">${escape(code)}</textarea>
      <p>Paste it into the API key box under Settings &gt; Lead Guard on that site (or put it in wp-config.php as NOBLE_LEAD_GUARD_API_KEY) and save.</p>
      <p>To switch the site off later, add ${escape(site)} to the BLOCKED_SITES secret.</p>` + form('')
    )
  );
}

function form(site) {
  return `<form method="post">
    <label>Site domain<input name="site" value="${escape(String(site))}" placeholder="terrafirmaconcrete.com" required></label>
    <label>Admin password<input name="password" type="password" required></label>
    <button>Make connection code</button>
  </form>`;
}

function secretHint() {
  const bytes = crypto.getRandomValues(new Uint8Array(32));
  return `<p>Here is a random value you can use for SITE_SECRET (it changes every time this page loads):</p><textarea readonly rows="2" onclick="this.select()">${b64bytes(bytes)}</textarea>`;
}

function page(content) {
  return `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
  <title>Lead Guard relay</title>
  <style>body{font:16px/1.5 system-ui,sans-serif;max-width:560px;margin:40px auto;padding:0 16px;color:#1d2327}label{display:block;margin:12px 0}input,textarea{display:block;width:100%;box-sizing:border-box;padding:8px;font:inherit;margin-top:4px}button{padding:8px 16px;font:inherit}.err{color:#b32d2e}</style>
  <h1>Lead Guard relay</h1>${content}`;
}

async function sign(site, secret) {
  const key = await crypto.subtle.importKey('raw', enc(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const mac = await crypto.subtle.sign('HMAC', key, enc(`nlg1|${site}`));
  return b64bytes(new Uint8Array(mac));
}

/** Compares two strings without leaking where they differ, by comparing their HMACs. */
async function sameText(a, b, secret) {
  const key = await crypto.subtle.importKey('raw', enc(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const [x, y] = await Promise.all([crypto.subtle.sign('HMAC', key, enc(a)), crypto.subtle.sign('HMAC', key, enc(b))]);
  const ax = new Uint8Array(x);
  const ay = new Uint8Array(y);
  let diff = 0;
  for (let i = 0; i < ax.length; i++) diff |= ax[i] ^ ay[i];
  return diff === 0;
}

function enc(s) {
  return new TextEncoder().encode(String(s));
}
function b64(s) {
  return b64bytes(enc(s));
}
function b64bytes(bytes) {
  let bin = '';
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
function unb64(s) {
  const bin = atob(s.replace(/-/g, '+').replace(/_/g, '/'));
  return new TextDecoder().decode(Uint8Array.from(bin, (c) => c.charCodeAt(0)));
}
function escape(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}
function json(data, status = 200) {
  return new Response(JSON.stringify(data), { status, headers: { 'content-type': 'application/json' } });
}
function text(s) {
  return new Response(s, { headers: { 'content-type': 'text/plain' } });
}
function html(s, status = 200) {
  return new Response(s, { status, headers: { 'content-type': 'text/html; charset=utf-8', 'cache-control': 'no-store' } });
}

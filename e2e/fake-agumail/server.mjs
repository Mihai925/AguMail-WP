// Fake AguMail for the WooCommerce plugin e2e stack. Node only, no dependencies.
//
// Speaks the /api/v1 contracts the plugin uses (see the plan's contracts file), serves a
// fake one-click connect page and stub browser scripts, and records every request.
//
// Control (never recorded, never affected by modes):
//   GET  /__health            {ok:true}
//   GET  /__requests          the log. Filters: ?path=<prefix>&method=POST&since=<seq>
//   POST /__reset             clears the log and the fake data, mode back to ok, drops hung sockets
//   GET  /__state             current mode and fake data counts
//   POST /__control           {mode, ...} or any of the settings below
//
// Modes (apply to /api/v1/** only; the connect page and scripts always work):
//   ok                         normal answers
//   hang                       accept the request and never answer
//   slow        {delayMs}      answer after delayMs
//   429         {retryAfter}   429, Retry-After header and {ok:false,error,retryAfter}
//   500                        500 {ok:false,error}
//   400-at-index {index}|{match}  400 zod-style "<collection>.<i>.email: Invalid email" naming the record
//                              at `index`, or the record whose email/externalId equals `match`
//                              (answers normally when the batch has no such record); a signup is
//                              matched by its email or its form builder ("elementor", "cf7", "wpforms")
//   down                       destroy the socket as soon as the request arrives (connection reset)
// Extra control fields:
//   paths: ['/api/v1/orders']  only these path prefixes get the mode (default: all /api/v1)
//   times: N                   apply the mode to the next N matching requests, then back to ok
//   integrationStatus          'connected' | 'disconnected' | 'error' | null (ping answer)
//   forms: [...]               what GET /api/v1/forms lists
//   statuses: {email: status}  what POST /api/v1/contacts/status answers for these emails
//   apiKey: 'agm_...'          when set, only this key is accepted (default: any agm_ key)
//   connectPage: 'approve' | 'show' | 'deny'   the fake app's connect page behaviour
//   codeTtlMs: N               how long a connect code lives (default 10 minutes; negative issues expired codes)
//   siteScript: 'stub' | 'real'  what /p/_/v1/site.js serves (default 'stub', the recording stub below).
//                              'real' serves AguMail's own site runtime from the state folder
//                              (site-runtime.js, written by helpers/siteRuntime.ts), so a spec sees the
//                              product views a shopper's browser really sends.
//   storeSync: true | false    whether the account's plan has store sync (default true). Without it,
//                              like the real server, connecting a store and sending orders, carts and
//                              products answer 402 {code: PLAN_FEATURE, feature: storeSync} before the
//                              request is read; contacts, signups, forms and ping work on every plan.
//
// Ping answers about a store only when asked (?platform=woocommerce); a site connected with a
// key alone asks with no platform and gets integration: null, as from the real server.

import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const PORT = Number(process.env.PORT || 8090);
const PUBLIC_URL = (process.env.PUBLIC_URL || `http://localhost:${PORT}`).replace(/\/$/, '');
const LOG_FILE = process.env.LOG_FILE || '';
// The folder shared with the host (.state), where a spec leaves files for the fake to serve.
const STATE_DIR = process.env.STATE_DIR || '/e2e/state';
const TRACKING_SECRET = process.env.TRACKING_SECRET || 'agumail-e2e-tracking-secret';
const CODE_SECRET = process.env.CODE_SECRET || 'agumail-e2e-connect-secret';
const SITE_KEY = process.env.SITE_KEY || 'site_e2e0000000000000000';
const SHOP = { userId: 'user_e2e', shopName: 'AguMail E2E Shop' };
const INTEGRATION_ID = 'int_e2e_woocommerce';

const b64url = (buf) => Buffer.from(buf).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const hmac = (key, data) => crypto.createHmac('sha256', key).update(data).digest();
const identifySecret = b64url(hmac(TRACKING_SECRET, 'site-identify:' + SITE_KEY));

// ── State ────────────────────────────────────────────────────────────────────

let seq = 0;
let log = [];
let hung = new Set();
let control;
let data;

function freshControl() {
  return {
    mode: 'ok',
    delayMs: 0,
    retryAfter: 30,
    index: 0,
    match: null,
    paths: null,
    times: null,
    integrationStatus: 'connected',
    storeUrl: null,
    forms: [
      { slug: 'newsletter', name: 'Newsletter', doubleOptIn: true, publishedAt: '2026-09-01T10:00:00.000Z' },
      { slug: 'welcome-popup', name: 'Welcome popup', doubleOptIn: false, publishedAt: '2026-09-02T10:00:00.000Z' },
    ],
    statuses: {},
    apiKey: null,
    connectPage: 'approve',
    codeTtlMs: 10 * 60 * 1000,
    storeSync: true,
    siteScript: 'stub',
  };
}

const STORE_SYNC_REFUSAL = {
  ok: false,
  error: 'Connecting a store, and sending its orders, carts and products, is on the Commerce plans. Contacts, signups and events work on every plan.',
  code: 'PLAN_FEATURE',
  feature: 'storeSync',
};
const STORE_PATHS = ['/api/v1/connect', '/api/v1/orders', '/api/v1/carts', '/api/v1/products'];

function freshData() {
  return {
    contacts: new Map(), // email -> { record (last one sent), status }
    orders: new Map(), // externalId -> last record
    products: new Map(),
    carts: new Map(),
    signups: [], // every signup record received, in order
    events: 0,
    usedCodes: new Set(),
    issuedKeys: [],
  };
}

control = freshControl();
data = freshData();

function ensureLogDir() {
  if (!LOG_FILE) return;
  fs.mkdirSync(path.dirname(LOG_FILE), { recursive: true });
}

function resetLogFile() {
  if (!LOG_FILE) return;
  try {
    ensureLogDir();
    fs.writeFileSync(LOG_FILE, '');
  } catch (err) {
    console.error('[fake-agumail] cannot reset log file', err.message);
  }
}

function appendLog(entry) {
  log.push(entry);
  if (!LOG_FILE) return;
  try {
    fs.appendFileSync(LOG_FILE, JSON.stringify(entry) + '\n');
  } catch (err) {
    console.error('[fake-agumail] cannot write log file', err.message);
  }
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function cors(res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-AguMail-E2E');
}

function send(res, status, body, headers = {}) {
  if (res.destroyed || res.writableEnded) return;
  const text = typeof body === 'string' ? body : JSON.stringify(body);
  cors(res);
  res.writeHead(status, {
    'Content-Type': typeof body === 'string' ? headers['Content-Type'] || 'text/plain; charset=utf-8' : 'application/json',
    ...headers,
  });
  res.end(text);
}

function redactHeaders(headers) {
  const out = { ...headers };
  if (out.authorization) {
    const m = /^Bearer\s+(agm_)?(\S*)$/i.exec(out.authorization);
    out.authorization = m ? `Bearer ${m[1] || ''}[redacted:${(m[2] || '').length} chars]` : '[redacted]';
  }
  if (out.cookie) out.cookie = '[redacted]';
  return out;
}

function parseBody(raw, contentType) {
  if (!raw) return null;
  if ((contentType || '').includes('application/json') || /^[\[{]/.test(raw.trim())) {
    try {
      return JSON.parse(raw);
    } catch {
      return raw;
    }
  }
  if ((contentType || '').includes('application/x-www-form-urlencoded')) {
    return Object.fromEntries(new URLSearchParams(raw));
  }
  return raw;
}

function recordCount(body) {
  if (!body || typeof body !== 'object') return null;
  for (const key of ['contacts', 'orders', 'products', 'carts', 'events', 'signups', 'emails']) {
    if (Array.isArray(body[key])) return { collection: key, count: body[key].length };
  }
  return null;
}

function authOk(req) {
  const header = req.headers.authorization || '';
  const m = /^Bearer\s+(agm_[A-Za-z0-9]+)$/.exec(header);
  if (!m) return false;
  if (control.apiKey && m[1] !== control.apiKey) return false;
  return true;
}

function config() {
  return {
    siteKey: SITE_KEY,
    siteScriptUrl: `${PUBLIC_URL}/p/_/v1/site.js?v=e2e`,
    siteEndpoint: `${PUBLIC_URL}/api/public/site/${SITE_KEY}/events`,
    formsLoaderUrl: `${PUBLIC_URL}/embed/agumail-forms.js`,
    identifySecret,
  };
}

function modeApplies(pathname) {
  if (!pathname.startsWith('/api/v1')) return false;
  if (control.mode === 'ok') return false;
  if (Array.isArray(control.paths) && control.paths.length) {
    return control.paths.some((p) => pathname.startsWith(p));
  }
  return true;
}

function consumeTimes() {
  if (typeof control.times === 'number') {
    control.times -= 1;
    if (control.times <= 0) {
      control.mode = 'ok';
      control.times = null;
      control.paths = null;
    }
  }
}

function badIndexError(body) {
  const rc = recordCount(body);
  if (!rc) return null;
  const records = body[rc.collection];
  let index = -1;
  if (control.match !== null && control.match !== undefined) {
    index = records.findIndex((r) =>
      r && typeof r === 'object'
        ? r.email === control.match || String(r.externalId) === String(control.match) || (r.form && r.form.builder === control.match)
        : r === control.match,
    );
  } else if (Number.isInteger(control.index) && control.index < records.length) {
    index = control.index;
  }
  if (index < 0) return null;
  const field = ['contacts', 'orders', 'signups'].includes(rc.collection) ? 'email' : 'externalId';
  return `${rc.collection}.${index}.${field}: Invalid ${field === 'email' ? 'email' : 'input'}`;
}

// ── API ──────────────────────────────────────────────────────────────────────

function upsert(map, key, record) {
  const existed = map.has(key);
  map.set(key, record);
  return existed ? 'updated' : 'created';
}

function tooMany(res, collection, body) {
  const list = body && Array.isArray(body[collection]) ? body[collection] : null;
  if (!list || list.length < 1) {
    send(res, 400, { ok: false, error: `${collection}: Required` });
    return true;
  }
  if (list.length > 500) {
    send(res, 400, { ok: false, error: `${collection}: Array must contain at most 500 element(s)` });
    return true;
  }
  return false;
}

function handleApi(req, res, url, body) {
  const p = url.pathname;

  if (p === '/api/v1/connect/exchange' && req.method === 'POST') {
    return exchange(res, body);
  }

  if (!authOk(req)) {
    return send(res, 401, { ok: false, error: 'Missing or malformed API key (Authorization: Bearer agm_…).' });
  }

  if (p === '/api/v1/ping' && req.method === 'GET') {
    const asked = url.searchParams.get('platform') === 'woocommerce';
    const integration = asked && control.integrationStatus
      ? { status: control.integrationStatus, storeUrl: control.storeUrl || 'localhost:8089' }
      : null;
    return send(res, 200, { ok: true, shop: SHOP, integration, config: config() });
  }

  if (!control.storeSync && STORE_PATHS.includes(p) && req.method === 'POST') {
    return send(res, 402, STORE_SYNC_REFUSAL);
  }

  if (p === '/api/v1/connect' && req.method === 'POST') {
    control.integrationStatus = 'connected';
    control.storeUrl = body && body.storeUrl ? String(body.storeUrl).replace(/^https?:\/\//, '') : control.storeUrl;
    return send(res, 200, { ok: true, integrationId: INTEGRATION_ID, userId: SHOP.userId, shopName: SHOP.shopName, config: config() });
  }

  if (p === '/api/v1/contacts/status' && req.method === 'POST') {
    const emails = body && Array.isArray(body.emails) ? body.emails : null;
    if (!emails || emails.length < 1 || emails.length > 500) {
      return send(res, 400, { ok: false, error: 'emails: Array must contain between 1 and 500 element(s)' });
    }
    const contacts = emails.map((raw) => {
      const email = String(raw).trim().toLowerCase();
      let status = control.statuses[email] ?? null;
      if (!status && data.contacts.has(email)) status = data.contacts.get(email).status;
      return { email, status, subscribed: status === 'active' };
    });
    return send(res, 200, { ok: true, contacts });
  }

  if (p === '/api/v1/contacts' && req.method === 'POST') {
    if (tooMany(res, 'contacts', body)) return;
    let created = 0;
    let updated = 0;
    for (const c of body.contacts) {
      const email = String(c && c.email ? c.email : '').trim().toLowerCase();
      if (!email) continue;
      // Like the real server: consent only ever moves a contact up, an unsubscribe always wins.
      const prev = data.contacts.get(email);
      let status = prev ? prev.status : 'nonsubscribed';
      if (c.unsubscribed) status = 'unsubscribed';
      else if (c.consent && c.consent.optedIn && status !== 'unsubscribed') status = 'active';
      if (upsert(data.contacts, email, { record: c, status }) === 'created') created++;
      else updated++;
    }
    return send(res, 200, { ok: true, created, updated, suppressedSkipped: 0, overCapacity: 0 });
  }

  if (p === '/api/v1/orders' && req.method === 'POST') {
    if (tooMany(res, 'orders', body)) return;
    let created = 0;
    let updated = 0;
    for (const o of body.orders) {
      if (upsert(data.orders, String(o && o.externalId), o) === 'created') created++;
      else updated++;
    }
    return send(res, 200, { ok: true, created, updated, duplicates: 0 });
  }

  if (p === '/api/v1/products' && req.method === 'POST') {
    if (tooMany(res, 'products', body)) return;
    for (const pr of body.products) upsert(data.products, String(pr && pr.externalId), pr);
    return send(res, 200, { ok: true, upserted: body.products.length });
  }

  if (p === '/api/v1/carts' && req.method === 'POST') {
    if (tooMany(res, 'carts', body)) return;
    for (const c of body.carts) upsert(data.carts, String(c && c.externalId), c);
    return send(res, 200, { ok: true, upserted: body.carts.length });
  }

  if (p === '/api/v1/signups' && req.method === 'POST') {
    if (tooMany(res, 'signups', body)) return;
    // Like the real server for a new or not subscribed address: double opt-in waits for the confirm
    // click (unconfirmed), single opt-in subscribes. An active contact stays active.
    for (const s of body.signups) {
      data.signups.push(s);
      const email = String(s && s.email ? s.email : '').trim().toLowerCase();
      if (!email) continue;
      const prev = data.contacts.get(email);
      const status = prev && prev.status === 'active' ? 'active' : s.doubleOptIn ? 'unconfirmed' : 'active';
      data.contacts.set(email, { record: prev ? prev.record : { email }, status });
    }
    return send(res, 200, { ok: true, received: body.signups.length, overCapacity: 0, limited: 0 });
  }

  if (p === '/api/v1/events' && req.method === 'POST') {
    if (tooMany(res, 'events', body)) return;
    data.events += body.events.length;
    return send(res, 200, { ok: true, created: body.events.length, duplicates: 0, unknownContacts: 0 });
  }

  if (p === '/api/v1/forms' && req.method === 'GET') {
    return send(res, 200, { ok: true, loaderUrl: config().formsLoaderUrl, forms: control.forms });
  }

  return send(res, 404, { ok: false, error: 'Not found.' });
}

// ── One-click connect ────────────────────────────────────────────────────────

function signCode(payload) {
  const encoded = b64url(JSON.stringify(payload));
  return `c1.${encoded}.${b64url(hmac(CODE_SECRET, encoded))}`;
}

function readCode(code) {
  const m = /^c1\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/.exec(code || '');
  if (!m) return null;
  const expected = b64url(hmac(CODE_SECRET, m[1]));
  if (expected.length !== m[2].length || !crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(m[2]))) return null;
  try {
    return JSON.parse(Buffer.from(m[1].replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString('utf8'));
  } catch {
    return null;
  }
}

function hostOf(u) {
  try {
    return new URL(u).host.toLowerCase();
  } catch {
    return null;
  }
}

const EXPIRED = 'This connection link has expired. Start again from WordPress.';

function exchange(res, body) {
  const payload = readCode(body && body.code);
  if (!payload || payload.exp < Date.now() || data.usedCodes.has(body.code)) {
    return send(res, 400, { ok: false, error: EXPIRED });
  }
  const verifier = String((body && body.verifier) || '');
  if (verifier.length < 43 || verifier.length > 128 || b64url(crypto.createHash('sha256').update(verifier).digest()) !== payload.challenge) {
    return send(res, 400, { ok: false, error: EXPIRED });
  }
  if (hostOf(body.storeUrl) !== hostOf(payload.store)) {
    return send(res, 400, { ok: false, error: EXPIRED });
  }
  // The real exchange checks the plan again: a store connects only with store sync.
  if (!control.storeSync) {
    return send(res, 402, STORE_SYNC_REFUSAL);
  }
  data.usedCodes.add(body.code);
  const apiKey = 'agm_' + crypto.randomBytes(20).toString('hex');
  data.issuedKeys.push(apiKey);
  control.integrationStatus = 'connected';
  control.storeUrl = hostOf(body.storeUrl);
  return send(res, 200, {
    ok: true,
    apiKey,
    userId: SHOP.userId,
    shopName: SHOP.shopName,
    integrationId: INTEGRATION_ID,
    config: config(),
  });
}

function withParams(ret, params) {
  const u = new URL(ret);
  for (const [k, v] of Object.entries(params)) u.searchParams.set(k, v);
  return u.toString();
}

function connectPage(req, res, url) {
  const store = url.searchParams.get('store') || '';
  const ret = url.searchParams.get('return') || '';
  const state = url.searchParams.get('state') || '';
  const challenge = url.searchParams.get('challenge') || '';
  if (!store || !ret || !state || !challenge || hostOf(ret) === null || hostOf(ret) !== hostOf(store)) {
    return send(res, 400, '<!doctype html><title>AguMail</title><p id="agumail-connect-error">This connection link is not valid. Start again from WordPress.</p>', {
      'Content-Type': 'text/html; charset=utf-8',
    });
  }
  const code = signCode({ store, challenge, exp: Date.now() + Number(control.codeTtlMs), n: crypto.randomBytes(6).toString('hex') });
  const approveUrl = withParams(ret, { code, state });
  const denyUrl = withParams(ret, { error: 'access_denied', state });
  if (control.connectPage === 'deny') {
    res.writeHead(302, { Location: denyUrl });
    return res.end();
  }
  if (control.connectPage === 'show') {
    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    return send(
      res,
      200,
      `<!doctype html><meta charset="utf-8"><title>Connect WooCommerce - AguMail</title>
<h1>Connect your store</h1>
<p>Store: <strong id="agumail-connect-store">${esc(hostOf(store))}</strong></p>
<p><a id="agumail-connect-approve" href="${esc(approveUrl)}">Approve</a> <a id="agumail-connect-cancel" href="${esc(denyUrl)}">Cancel</a></p>`,
      { 'Content-Type': 'text/html; charset=utf-8' },
    );
  }
  res.writeHead(302, { Location: approveUrl });
  return res.end();
}

// ── Stub browser scripts ─────────────────────────────────────────────────────
// Both stubs report what they were asked to do to POST /stub/calls, which is recorded like any request.

const SITE_JS = `(function(){
  if (window.agumail && window.agumail.__fake) return;
  var tag = document.currentScript;
  var origin = ${JSON.stringify(PUBLIC_URL)};
  var endpoint = (tag && tag.getAttribute('data-endpoint')) || ${JSON.stringify(config().siteEndpoint)};
  var site = (tag && tag.getAttribute('data-site')) || null;
  function report(fn, args){
    var body = JSON.stringify({script:'site', fn: fn, args: args, site: site, page: location.href});
    try { navigator.sendBeacon(origin + '/stub/calls', new Blob([body], {type:'text/plain'})); } catch (e) {}
  }
  function event(payload){
    try { navigator.sendBeacon(endpoint, new Blob([JSON.stringify(payload)], {type:'text/plain'})); } catch (e) {}
  }
  var queue = (window.agumail && window.agumail.q) || [];
  window.agumail = {
    __fake: true,
    calls: [],
    identify: function(email, sig){ this.calls.push(['identify', email, sig || null]); report('identify', [email, sig || null]); event({type:'identify', email: email, esig: sig || null, url: location.href}); },
    track: function(name, props){ this.calls.push(['track', name, props || null]); report('track', [name, props || null]); event({type: name, props: props || null, url: location.href}); }
  };
  report('load', []);
  event({type:'page_view', url: location.href});
  queue.forEach(function(c){ var fn = window.agumail[c[0]]; if (fn) fn.apply(window.agumail, c.slice(1)); });
})();`;

/** The stub, or AguMail's real site runtime when a spec asked for it. */
function siteScript() {
  if (control.siteScript !== 'real') return SITE_JS;
  const file = path.join(STATE_DIR, 'site-runtime.js');
  return fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : SITE_JS;
}

const FORMS_JS = `(function(){
  if (window.AguMailForms) return;
  var origin = ${JSON.stringify(PUBLIC_URL)};
  function report(fn, args){
    var body = JSON.stringify({script:'forms', fn: fn, args: args, page: location.href});
    try { navigator.sendBeacon(origin + '/stub/calls', new Blob([body], {type:'text/plain'})); } catch (e) {}
  }
  var rendered = [];
  function slugOf(el){
    return el.getAttribute('data-agumail-form') || el.getAttribute('data-slug') || el.getAttribute('data-form') || '';
  }
  function scan(){
    var nodes = document.querySelectorAll('[data-agumail-form],.agumail-form[data-slug],[data-agumail-slug]');
    Array.prototype.forEach.call(nodes, function(el){
      if (el.getAttribute('data-agumail-fake-rendered')) return;
      el.setAttribute('data-agumail-fake-rendered', '1');
      var slug = slugOf(el) || el.getAttribute('data-agumail-slug') || '';
      var attrs = {};
      Array.prototype.forEach.call(el.attributes, function(a){ if (a.name.indexOf('data-') === 0) attrs[a.name] = a.value; });
      var box = document.createElement('div');
      box.className = 'agumail-fake-form';
      box.setAttribute('data-slug', slug);
      box.textContent = 'AguMail fake form: ' + slug;
      el.appendChild(box);
      rendered.push({slug: slug, attrs: attrs});
      report('render', [slug, attrs]);
    });
  }
  window.AguMailForms = { __fake: true, rendered: rendered, scan: scan };
  report('load', []);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan); else scan();
})();`;

// ── Server ───────────────────────────────────────────────────────────────────

function controlRoute(req, res, url, body) {
  const p = url.pathname;
  if (p === '/__health') return send(res, 200, { ok: true });
  if (p === '/__requests') {
    const prefix = url.searchParams.get('path');
    const method = url.searchParams.get('method');
    const since = Number(url.searchParams.get('since') || 0);
    const out = log.filter(
      (e) => e.seq > since && (!prefix || e.path.startsWith(prefix)) && (!method || e.method === method.toUpperCase()),
    );
    return send(res, 200, out);
  }
  if (p === '/__reset' && req.method === 'POST') {
    for (const r of hung) r.destroy();
    hung = new Set();
    log = [];
    control = freshControl();
    data = freshData();
    resetLogFile();
    return send(res, 200, { ok: true });
  }
  if (p === '/__state') {
    return send(res, 200, {
      ok: true,
      control,
      seq,
      hung: hung.size,
      counts: {
        contacts: data.contacts.size,
        orders: data.orders.size,
        products: data.products.size,
        carts: data.carts.size,
        events: data.events,
        signups: data.signups.length,
      },
      publicUrl: PUBLIC_URL,
      issuedKeys: data.issuedKeys.length,
      lastIssuedKey: data.issuedKeys.length ? data.issuedKeys[data.issuedKeys.length - 1] : null,
      identifySecret,
      siteKey: SITE_KEY,
    });
  }
  if (p === '/__control' && req.method === 'POST') {
    const b = body && typeof body === 'object' ? body : {};
    const modes = ['ok', 'hang', 'slow', '429', '500', '400-at-index', 'down'];
    if (b.mode !== undefined && !modes.includes(String(b.mode))) {
      return send(res, 400, { ok: false, error: `mode must be one of ${modes.join(', ')}` });
    }
    if (b.mode !== undefined) {
      // A new mode starts clean: its own paths/times/index unless given.
      control.mode = String(b.mode);
      control.paths = null;
      control.times = null;
      control.match = null;
    }
    for (const k of ['delayMs', 'retryAfter', 'index', 'match', 'paths', 'times', 'integrationStatus', 'storeUrl', 'forms', 'apiKey', 'connectPage', 'codeTtlMs', 'storeSync', 'siteScript']) {
      if (b[k] !== undefined) control[k] = b[k];
    }
    if (b.statuses && typeof b.statuses === 'object') {
      for (const [email, status] of Object.entries(b.statuses)) control.statuses[email.toLowerCase()] = status;
    }
    if (b.releaseHung) {
      for (const r of hung) r.destroy();
      hung = new Set();
    }
    return send(res, 200, { ok: true, control });
  }
  return send(res, 404, { ok: false, error: 'Unknown control route.' });
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, PUBLIC_URL);
  const chunks = [];
  req.on('data', (c) => chunks.push(c));
  req.on('error', () => {});
  req.on('end', () => {
    const raw = Buffer.concat(chunks).toString('utf8');
    const body = parseBody(raw, req.headers['content-type']);

    if (req.method === 'OPTIONS') {
      cors(res);
      res.writeHead(204);
      return res.end();
    }
    if (url.pathname.startsWith('/__')) return controlRoute(req, res, url, body);

    const applies = modeApplies(url.pathname);
    const mode = applies ? control.mode : 'ok';
    const entry = {
      seq: ++seq,
      time: new Date().toISOString(),
      method: req.method,
      path: url.pathname,
      query: Object.fromEntries(url.searchParams),
      headers: redactHeaders(req.headers),
      body,
      records: recordCount(body),
      mode,
      status: null,
    };
    appendLog(entry);
    if (applies) consumeTimes();

    const finish = (status) => {
      entry.status = status;
    };
    res.on('finish', () => finish(res.statusCode));

    switch (mode) {
      case 'hang':
        hung.add(req.socket);
        req.socket.on('close', () => hung.delete(req.socket));
        entry.status = 'hung';
        return;
      case 'down':
        entry.status = 'reset';
        return req.socket.destroy();
      case '429':
        return send(
          res,
          429,
          { ok: false, error: 'Rate limit: 120 requests/minute per key.', retryAfter: Number(control.retryAfter) },
          { 'Retry-After': String(Number(control.retryAfter)) },
        );
      case '500':
        return send(res, 500, { ok: false, error: 'Internal server error.' });
      case '400-at-index': {
        const error = badIndexError(body);
        if (error) return send(res, 400, { ok: false, error });
        break;
      }
      case 'slow':
        return setTimeout(() => route(req, res, url, body), Number(control.delayMs) || 0);
      default:
        break;
    }
    return route(req, res, url, body);
  });
});

function route(req, res, url, body) {
  const p = url.pathname;
  if (p.startsWith('/api/v1/')) return handleApi(req, res, url, body);
  if (p === '/connect/woocommerce' && req.method === 'GET') return connectPage(req, res, url);
  if (p === '/p/_/v1/site.js') return send(res, 200, siteScript(), { 'Content-Type': 'application/javascript; charset=utf-8' });
  if (p === '/embed/agumail-forms.js') return send(res, 200, FORMS_JS, { 'Content-Type': 'application/javascript; charset=utf-8' });
  if (/^\/api\/public\/site\/[^/]+\/events$/.test(p) && req.method === 'POST') return send(res, 200, { ok: true });
  if (p === '/stub/calls' && req.method === 'POST') return send(res, 200, { ok: true });
  return send(res, 404, { ok: false, error: 'Not found.' });
}

server.keepAliveTimeout = 5000;
server.headersTimeout = 0;
server.requestTimeout = 0;

resetLogFile();
server.listen(PORT, '0.0.0.0', () => {
  console.log(`[fake-agumail] listening on :${PORT}, public ${PUBLIC_URL}, log ${LOG_FILE || '(memory only)'}`);
});

for (const sig of ['SIGINT', 'SIGTERM']) {
  process.on(sig, () => {
    for (const r of hung) r.destroy();
    server.close(() => process.exit(0));
    setTimeout(() => process.exit(0), 1000).unref();
  });
}

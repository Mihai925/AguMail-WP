# AguMail plugin: real WooCommerce end to end

A real WordPress + WooCommerce store in Docker, a fake AguMail that records everything the plugin sends, an audit mu-plugin that logs every outbound HTTP call with the request that made it, and Playwright specs on top.

Everything here is test tooling. Nothing in this folder ships with the plugin.

## What runs

| Service | Image | Reach it at |
| --- | --- | --- |
| WordPress 7.1 on PHP 8.3 (Apache), WooCommerce 10.7.0, Storefront | `wordpress:php8.3-apache` (`PHP_TAG=php7.4` for PHP 7.4) | http://localhost:8089 (admin / admin) |
| Fake AguMail | `node:22-alpine` running `fake-agumail/server.mjs` | http://localhost:8090 from the host, `http://fake-agumail:8090` from WordPress |
| MariaDB 11.4 | `mariadb:11.4` | not published |
| wp-cli (long running, uid 33) | `wordpress:cli-php8.3` | `./wp-setup.sh wp ...` |

The compose project is always `agumail-woo-e2e` (set in `.env` and passed by every script), so it never collides with another stack. Apache listens on 8089 inside the container too, so `http://localhost:8089` also works for WordPress's own loopback requests.

Mounted into WordPress, read-only:

- `../agumail` as `wp-content/plugins/agumail` (the plugin under development, "v2")
- `fixtures/agumail-v1` as `wp-content/plugins/agumail-v1` (the released 1.2.0, extracted from git HEAD, kept for the "v1 slows checkout" proof)
- CheckoutWC from `CHECKOUTWC_DIR` (default: Mihai's pixel-woo copy) as `wp-content/plugins/checkout-for-woocommerce`. Local testing only, never copied into the repo. Fallback: point `CHECKOUTWC_DIR` in `.env` at `.../plugins/checkoutwc-lite`.
- Elementor 4.0.3 and Elementor Pro 4.0.3 from `ELEMENTOR_DIR` and `ELEMENTOR_PRO_DIR` (defaults: the pixel-woo copies) as `wp-content/plugins/elementor` and `elementor-pro`, the same way. Elementor Pro runs its form widget and actions without a licence key. Both stay inactive unless a spec switches them on.
- Gravity Forms 3.2.3.1 from `GRAVITYFORMS_DIR` (default `~/Projects/gravity-forms/gravityforms`, unpacked from its zip) as `wp-content/plugins/gravityforms`, the same way. It is commercial, so it is never copied into the repo. Forms, entries, the honeypot and add-on settings tabs all run without a licence key (wp-admin shows an "unregistered" notice). Inactive unless a spec switches it on.
- `mu-plugins/` (the audit and environment mu-plugins)
- `wp/` (setup PHP, `wp-config-e2e.php`, `php.ini`)

`.state/` (gitignored) is shared by the host, WordPress, wp-cli and the fake server: switches, logs, and the files the specs read.

## Start

```bash
cd e2e
npm install
npx playwright install chromium   # only if the browser is missing
./wp-setup.sh up                  # compose up, install and configure the store (idempotent)
./wp-setup.sh seed                # 1,200 shoppers with paid orders (about 5 minutes the first time)
```

`./wp-setup.sh setup` can be re-run at any time; it converges the store to the same state.

What setup gives you:

- Store in USD, US/CA base address, taxes off, guest checkout on, "coming soon" off, onboarding skipped.
- Payments: cash on delivery, check payments and direct bank transfer. Shipping: flat rate 5.00 everywhere.
- Pages: WooCommerce's own block `/checkout/` and `/cart/`, plus `/checkout-classic/` (`[woocommerce_checkout]`) and `/cart-classic/` (`[woocommerce_cart]`).
- Catalogue (with generated images, SKUs and categories): Linen Tote `AGM-TOTE`, Ceramic Mug `AGM-MUG`, Beeswax Candle `AGM-CANDLE` (on sale), Dot Grid Notebook `AGM-NOTE`, Organic Tee `AGM-TEE` (Size S/M/L), Wool Beanie `AGM-BEANIE` (Colour Grey/Navy).
- `.state/site.json`: page URLs and ids, product and variation ids. Specs read it through `readSite()`.

### Seed data

`./wp-setup.sh seed [count]` (default 1,200) creates one paid (completed) order for each of `count` distinct shoppers, dated evenly from 730 days ago to 1 day ago:

- shopper `i` is `shopper%04d@e2e.agumail.test`
- 5 in 6 shoppers are registered customers (1,000), the rest guests (200)
- every 4th shopper opted in: user meta `_agumail_optin=yes` for registered (200), order meta `_agumail_optin=yes` for guests (100)
- an opted-in guest's order is `created_via=checkout` (the plugin trusts the box only on an order a checkout created); every other seeded order is `created_via=agumail-e2e-seed`. Re-running `seed` converges an older store to this
- the newest couple of orders fall inside 72 h (useful for the "backfill flag, not age" rule)

Exact counts are written to `.state/seed.json` (re-run `seed` to refresh `ordersWithin72h`, which moves with the clock). The seed deactivates the AguMail plugins first so no live hook fires for seeded orders. Other specs add their own guest orders, so a bulk-sync spec should count paid orders at run time (or read `seed.json` and add what it placed itself).

## Switches

```bash
./wp-setup.sh checkout classic      # [woocommerce_checkout] page is the checkout, CheckoutWC off
./wp-setup.sh checkout block        # WooCommerce's block checkout, CheckoutWC off
./wp-setup.sh checkout checkoutwc   # classic pages with CheckoutWC active and its templates on

./wp-setup.sh plugin none|v1|v2     # which AguMail plugin is active (v2 = agumail)
./wp-setup.sh woo on|off            # WooCommerce on, or off for a plain WordPress site (CheckoutWC goes off with it)
./wp-setup.sh connect [apiUrl]      # API key + URL (default http://fake-agumail:8090)
./wp-setup.sh cron on|off           # real WP-Cron and Action Scheduler async runner (default off)
./wp-setup.sh queue                 # wp action-scheduler run --group=agumail
./wp-setup.sh builders install      # Contact Form 7 and WPForms Lite from wordpress.org, left inactive (idempotent)
./wp-setup.sh builders on|off       # the five form plugins on (and their signup form pages, below) or off
./wp-setup.sh status                # checkout type, config.json, plugin list
./wp-setup.sh wp <anything>         # any wp-cli command

WC_VERSION=11.1.2 ./wp-setup.sh setup   # another WooCommerce (installed with --force); WC_VERSION=10.7.0 ./wp-setup.sh setup goes back
PHP_TAG=php7.4 ./wp-setup.sh up         # the same site and database on PHP 7.4 (containers recreated, volumes kept); ./wp-setup.sh up goes back to 8.3
```

Form plugins: `builders on` activates Elementor, Elementor Pro, Contact Form 7, WPForms Lite and Gravity Forms and runs `wp/builders.php`, which (re)creates the signup forms with AguMail switched on, each on its own page, and writes `.state/builders.json` (page URLs and ids, form ids). They are inactive by default so the timing specs never load them; `builder-signups.spec.ts` switches them on in `beforeAll` and off in `afterAll`.

- Elementor Pro: page `/agumail-e2e-elementor/`, a form widget "AguMail popup" (element `a9e2e01`) with Name, Email and an optional acceptance box "Send me offers and news"; Actions After Submit = AguMail, tags `popup, e2e`, double opt-in on, nothing mapped (every field is detected).
- Contact Form 7: form "AguMail e2e contact" on `/agumail-e2e-cf7/`: `your-name`, `your-email` and an optional `[acceptance newsletter optional consent_for:agumail]`; AguMail on, tags `contact, e2e`, double opt-in off.
- WPForms Lite: form "AguMail e2e newsletter" (AJAX, spam checks off) on `/agumail-e2e-wpforms/`: a first and last Name field, Email and a Newsletter checkbox mapped as the consent; AguMail on, tags `news, e2e`, double opt-in on.
- Gravity Forms: form "AguMail e2e guide" on `/agumail-e2e-gravityforms-guide/`, the shape of the pilot store's lead form: First Name, Last Name, Email and Phone as separate required fields, Gravity Forms' honeypot (marks spam), and a Redirect confirmation to `wp-content/uploads/agumail-e2e-guide.pdf`; AguMail on, tags `Headshot Guide Requests`, single opt-in, nothing mapped. And form "AguMail e2e gf newsletter" on `/agumail-e2e-gravityforms/`, sent by Gravity Forms' own AJAX (`agumail-e2e-env.php` sets `submission_method` for the form named in the option `agumail_e2e_gf_ajax_form`; a shortcode's `ajax="true"` would be the older iframe): a Name field, Email and an optional Consent field "Send me offers and news"; AguMail on, tags `news, e2e`, double opt-in on, a text confirmation. `builders.json` has the newsletter form under `gravityforms` and the guide under `gravityforms.guide`.

Action Scheduler: a fresh WooCommerce starts it in its hybrid (posts + tables) store, and it goes back there on every plugin deactivation (`ActionScheduler_DataController` marks its migration incomplete). Only its own queue runner, which is off here, finishes the move, and until then every `action-scheduler run --group=agumail` fails with `The group "agumail" does not exist`. `setup` runs `wp action-scheduler migrate`, and `runQueue()` does it itself when it meets that error while the group exists.

How the API URL reaches the plugin: `wp-config.php` includes `wp/wp-config-e2e.php`, which reads `.state/config.json` on every request and defines `AGUMAIL_API_URL` (default `http://fake-agumail:8090`) and `AGUMAIL_APP_URL` (default `http://localhost:8090`, the browser-facing fake app for one-click connect). v2 reads the constants; v1 only knows the `agumail_api_url` option, so `connect` sets both.

Cron: by default `DISABLE_WP_CRON` is true and the Action Scheduler async runner is filtered off, so background work only runs when a spec calls `runQueue()` (or `./wp-setup.sh queue`). Playwright sets this at the start of every run; `AGUMAIL_E2E_REAL_CRON=1 npx playwright test ...` runs with real cron instead.

## Specs

```bash
npx playwright test specs/smoke.spec.ts                                   # the stack itself, plugin inactive
AGUMAIL_E2E_PLUGIN=v1 npx playwright test specs/checkout-timing.spec.ts   # the red proof against 1.2.0
npx playwright test specs/checkout-timing.spec.ts                         # v2 (default)
AGUMAIL_E2E_SAMPLES=3 npx playwright test specs/checkout-timing.spec.ts   # quick plumbing check
AGUMAIL_E2E_PLUGIN=none npx playwright test specs/checkout-timing.spec.ts # A/A: the environment's own noise
npx playwright test                                                       # everything, v2 (about 16 minutes)
npx playwright show-report                                                # HTML report
```

One worker, always: every spec shares one store, one fake server and one set of logs.

- `smoke.spec.ts`: store and fake up; a product page renders; classic, block and CheckoutWC checkouts each place a cash on delivery order that lands in `processing`; nothing reached the fake while no plugin was active.
- `checkout-timing.spec.ts`: verification point 4 of the plan. Two scenarios, each with the plugin under test active and connected: its API at the non-routable `http://10.255.255.1:81`, and the fake accepting and never answering. In each, 15 classic + 15 block guest checkouts with the plugin and 15 + 15 baseline checkouts without it (plus one unmeasured warm-up of each), alternating ABBA. Per checkout type: median server time at most 75 ms over baseline, max at most baseline max + 250 ms; and zero AguMail calls from any shopper request. Then (v2 only) email capture p95 at most 150 ms, and the queue delivers every order placed with the plugin once the fake answers again, leaving none of them in the outbox. Results land in `.state/timing/*.json`; the drain test reads the scenario files, so run the whole spec (or the scenarios first).

The v2 specs below each start from v2 active and connected to the fake, the fake, the audit logs and the plugin's outbox and carts empty (`startV2()`), and each ends by asserting that no shopper request called AguMail (`expectNoShopperCalls()`).

- `capture-classic.spec.ts`, `capture-blocks.spec.ts`, `capture-checkoutwc.spec.ts` (one suite, `helpers/capture-suite.ts`): typing the email and leaving the field posts only `email` and `context` to `?wc-ajax=agumail_capture` and, after the queue runs, reaches the fake as a contact with no `consent` plus an open cart with a restore link, and no order; a ticked box that reached the server (a refreshed order review, or the block checkout's draft save) but was never submitted sends no consent and saves no answer; placing the order with the box ticked sends the contact opted in (`source: checkout`, `consentedAt`) before the order. The block suite also checks the box is WooCommerce's own additional field in the order step (`#order-agumail-optin`), unticked. CheckoutWC is skipped when it is not mounted.
- `restore.spec.ts`: a captured cart's restore link, opened in a fresh browser, answers one 302 to checkout without `agumail_cart`/`agumail_token` and with `agm_cid` and `utm_*` kept; the cart comes back (quantities, the variation), the email is filled in and the `agm_cid` cookie set. A wrong token only drops its own two params and rebuilds nothing.
- `orders.spec.ts`: cash on delivery orders (ticked and unticked) are sent by the next queue run, dated when placed, with the answer to the box, contact first, and marked synced; their carts close as completed; a bank transfer on hold is not sent (its cart still closes) until the merchant marks it processing in the order screen, and is then dated from that moment; a partial refund sends the order again with the new total and no contact.
- `products.spec.ts`: the product screen, quick edit and the REST API (an application password) each send the new price and SKU; a stock change alone, by the REST API or by an order, sends nothing.
- `bulk-sync.spec.ts`: removes every order but the 1,200 seeded ones (plugins off), re-runs the seed, connects in one click, presses Sync store data now and runs the queue until the sync is done. Every contacts/orders/products request says `backfill: true`; every contact has `customerSince` (at least a day old); `consentedAt` and `consent` only on the 300 opted-in shoppers, all of them before anyone else; the settings page's counts equal what the fake received (1,200 contacts, 1,200 orders, the published products) and every order is marked synced.
- `connect.spec.ts`: one click through the fake approval page (store, return, state, challenge checked; the code traded once; the key and config kept; Sync store data now offered); Cancel (`error=access_denied`); an expired code (`codeTtlMs` < 0) and a callback replayed after use; Advanced with a refused key (restored) and the right one.
- `forms.spec.ts`: Refresh fills the forms cache; the shortcode and the block print the inert placeholders with their triggers and load the forms loader once, in the footer; the site-wide popup shows on a product page, not a second time on a page that already places the form, and never on either checkout's cart and checkout pages or My Account.
- `builder-signups.spec.ts`: for Elementor Pro, Contact Form 7, WPForms and Gravity Forms (its AJAX newsletter form), a visitor's submission succeeds with the fake hanging, queues one `signup` row and names the visitor to the site script with a valid signature (tracking on); once the fake answers, the queue sends the record to `/api/v1/signups` (email, names, tags, double opt-in, form builder/id/name, page, consent words, address) and nothing to `/api/v1/contacts`. An unticked consent box queues nothing on every builder. A signup the fake refuses (`400-at-index`) is parked, shows under Status as "Form signups" with who and which form, and can be discarded. The pilot's Gravity Forms lead form still answers with its 302 to the PDF (the browser downloads it) while the fake hangs, and its signup is queued and later sent with the phone and tag; an entry Gravity Forms' honeypot marks as spam is kept as spam and never queued; with WP Armour (`honeypot`, installed from wordpress.org and switched off again) active, the lead form still redirects and queues. The settings page's Form plugins section, the CF7 AguMail tab, the WPForms Settings > AguMail section and the Gravity Forms Settings > AguMail tab save (its selects list only whole fields of their kind); the Elementor editor lists the AguMail action and fills its field map with five rows, each listing only the form's fields of its kind.
- `wordpress-only.spec.ts`: with WooCommerce off, activating AguMail lands on its own top-level page with no store sections (no Checkout, no Store data) and a free account offered; the site connects with a pasted API key checked by `GET /api/v1/ping` with no platform (nothing posted to `/api/v1/connect`), and the jobs run on the Action Scheduler bundled in `vendor/woocommerce/action-scheduler`; a `[agumail_form]` page prints its placeholder and a Contact Form 7 signup reaches `/api/v1/signups`. Then WooCommerce goes on: the page says the store is connected for forms and signups, Sync your store connects it (`POST /api/v1/connect`, platform `woocommerce`) and offers the store data sync, and a checkout's order reaches the fake. Ends with WooCommerce on and the store connected.
- `plan-lapse.spec.ts`: the fake's `storeSync: false` (a plan without store sync, as after the trial) refuses orders with 402 PLAN_FEATURE; the order waits in the outbox with no attempt counted, the shopper who ticked the box still reaches `/api/v1/contacts`, and the page says store sync is paused. With `storeSync: true` again and the hourly check due, the waiting order goes.
- `admin-status.spec.ts`: the Status section shows the connection, the queue by type with its oldest item, the last successful sync, a 429 pause with its end time, and an order AguMail refused (400 naming it) as failed; Retry, Discard and Retry all work from the page.

Server time is the checkout request's own PHP time, logged at shutdown by the audit mu-plugin and matched to its sample by the `agumail_e2e_tag` cookie.

Why the baseline is interleaved rather than measured first: on this WSL2 + Docker Desktop host, the medians of two back-to-back batches of identical no-plugin checkouts differed by 15 to 160 ms, and single requests occasionally take 2 s. A baseline measured minutes earlier would book that drift against the plugin. A baseline shopper carries the cookie `agumail_e2e_plugins_off=1`, and the env mu-plugin drops every `agumail*` plugin from the active list for that shopper's requests, which is the same as the plugin being inactive for them, while the plugin stays active for everyone else. `AGUMAIL_E2E_PLUGIN=none` runs both arms without a plugin, to see how much of a result is the machine.

Machine stalls: about once per 50 to 100 checkouts this host freezes every PHP request and MariaDB for roughly 1.7 s at once (with `saveQueries` on, a trivial `SELECT meta_id FROM wp_postmeta ...` took 1,613 ms; other stalled requests ran no query at all). So a sample more than 1,000 ms over the baseline median is placed again in the same arm, at most twice per arm and checkout type. The original, the new sample and the stalled request's checkpoints (`slow.jsonl`) are kept in the scenario's `remeasured` list and printed. A plugin that is slow every time is slow on the second try too and still fails (v1 does); a plugin that calls out from a shopper request fails the audit assertion regardless. A full A/A run (15 + 15 per arm, both scenarios) passed with one stall re-measured in each scenario; an earlier A/A run without the re-measure failed both scenarios on one stall each.

Diagnostics: `"saveQueries": true` in `.state/config.json` turns on `SAVEQUERIES` and adds the five slowest queries to each `slow.jsonl` line. It roughly doubles request times here, so leave it off for measurements.

### Results (2026-09-28)

Checkout server time, median / max in ms, 15 samples per arm (baseline = same minutes, plugin unloaded for that shopper):

| Scenario | Checkout | Baseline | v1 1.2.0 | v2 2.0.0 (WC 10.7, PHP 8.3) |
| --- | --- | --- | --- | --- |
| API unreachable | classic | 294 / 1,933 (v1 run), 298 / 336 (v2 run) | 21,039 / 21,245 | 308 / 328 |
| API unreachable | block | 325 / 376, 296 / 369 | 20,810 / 21,384 | 315 / 435 |
| fake hangs | classic | 266 / 311, 306 / 361 | 30,550 / 30,899 | 296 / 325 |
| fake hangs | block | 344 / 571, 308 / 321 | 30,614 / 30,854 | 308 / 430 |

v1: 64 AguMail calls from shopper requests per scenario, and 0 of 60 orders delivered after the outage. v2: 0 calls, 60 of 60 delivered, capture p95 136 ms. The same specs pass on WooCommerce 11.1.2 (PHP 8.3 and PHP 7.4; one PHP 7.4 hang run had a 745 ms classic sample against a 687 ms budget and passed on the re-run). Per-run files: `.state/timing/v2-final-wc107`, `v2-wc111`, `v2-php74-wc111`, `v1-full-run`.

### Helpers for new specs

- `helpers/wp.ts`: `wp(...args)`, `wpEval(php)`, `setCheckout(type)`, `activatePlugin('none'|'v1'|'v2')`, `connectPlugin(apiUrl)`, `setApiUrl(url)`, `runQueue({ dueNow })` (only due actions, like WP-Cron; `dueNow` first pulls pending agumail actions to now), `drainQueue()` (runs until no outbox row is pending and no cart is dirty), `outboxDueNow()` (past a retry delay or a 429), `setRealCron(on)`, `orderStatus(id)`, `checkoutWcAvailable()`.
- `helpers/agumail.ts`: `startV2({ checkout })`, `resetPluginQueue()`, `expectNoShopperCalls()`, `drainAll()`, what the fake received with the request that carried it (`contactsFor(email)`, `cartsFor(email)`, `ordersFor(id)`, `productsFor(id)`, `sentRecords(endpoint, collection)`), `orderFacts(id)`, `outboxRows()`, `orderEditUrl(id)`, `fakeSeq()`.
- `helpers/admin.ts`: `adminContext(browser)` (signed in, session kept in `.state/admin-storage.json`), `openSettings(page)`, `section(page, id)`, `settingsNotice(page)`.
- `helpers/checkout.ts`: `placeGuestOrder(page, { checkout, productId, email, optIn, paymentMethod, tag })` for classic, block and CheckoutWC; `addToCart`, `tagRequests(page, tag)`, `withoutAguMail(page)`, `checkoutServerTime(order)`, `uniqueEmail()`.
- `helpers/fake.ts`: `fakeReset()`, `fakeMode(mode, extra)`, `fakeControl({...})`, `fakeRequests({ path, method, since })`, `fakeRecords('/api/v1/orders')`, `fakeState()`.
- `helpers/audit.ts`: `resetAudit()`, `readHttpAudit()`, `shopperAguMailCalls()`, `describeCalls()`, `readRequestTimings()`, `readSlowRequests()`, `readMailAudit()`.
- `helpers/builders.ts`: `buildersOn()` (installs what is missing, activates, reseeds, returns `.state/builders.json`), `buildersOff()`, `reseedBuilders()`, `readBuilders()`, the `SignupRecord` type.
- `helpers/env.ts`: URLs, `readSite()`, `readSeed()`, `pluginUnderTest()` (`AGUMAIL_E2E_PLUGIN`: `v1`, `v2`, or `none`), `writeStateConfig()`.

Use a fresh `browser.newContext()` per shopper. The block checkout drops a Place order click that also blurs a field; `placeGuestOrder` blurs and waits for the Store API to settle first, and only re-clicks when no checkout request started (it never places a second order).

## Fake AguMail

`fake-agumail/server.mjs`, no dependencies. Speaks the `/api/v1` contracts from the plan: `ping` (with `integration` and `config`), `connect`, `connect/exchange` (real PKCE check, signed single-use codes, store host must match), `contacts`, `orders`, `products`, `carts`, `events`, `forms`, `contacts/status` (answers from what it was sent: opted in means `active`, unsubscribed wins, otherwise `nonsubscribed`), `signups` (keeps every record; a double opt-in signup makes the address `unconfirmed` unless it is already `active`, a single opt-in one `active`; answers `{ ok, received, overCapacity: 0, limited: 0 }`). Any `agm_` key is accepted unless `apiKey` is set.

It also serves:

- `GET /connect/woocommerce?store&return&state&challenge`: the fake app page. Auto-approves by redirecting to `return` with `code` and `state` (`connectPage: 'show'` renders Approve/Cancel links instead, `'deny'` returns `error=access_denied`).
- `/p/_/v1/site.js` and `/embed/agumail-forms.js`: stub scripts. `window.agumail.identify(email, sig)` and `track()` post to the site events endpoint; the forms stub renders a marker into `[data-agumail-form]`, `.agumail-form[data-slug]` and `[data-agumail-slug]` elements. Both report every call to `POST /stub/calls`, which is recorded like any request.

Control (never recorded):

| Route | Does |
| --- | --- |
| `GET /__requests?path=&method=&since=` | the log: method, path, query, headers (auth value redacted), parsed body, record count, mode, status |
| `POST /__reset` | clear log and data, mode back to ok, drop hung sockets |
| `GET /__state` | mode, counts (contacts, orders, products, carts, events, signups), `identifySecret`, `siteKey`, `issuedKeys` and `lastIssuedKey` (keys minted by `connect/exchange`) |
| `POST /__control` | `{ mode, delayMs, retryAfter, index, match, paths, times, integrationStatus, forms, statuses, apiKey, connectPage, codeTtlMs, releaseHung }` (`codeTtlMs` < 0 issues connect codes that are already expired) |

Modes apply to `/api/v1/**` only: `ok`, `hang` (never answers), `slow` (`delayMs`), `429` (`Retry-After: retryAfter`), `500`, `400-at-index` (zod-style `"orders.3.email: Invalid email"` for the record at `index`, or the record whose email or externalId equals `match`; a signup also matches by its form builder, such as `cf7`), `down` (socket destroyed on arrival). `paths: ['/api/v1/orders']` limits a mode to some endpoints; `times: N` applies it to the next N matching requests and then returns to ok.

The log is also appended to `.state/fake-agumail/requests.jsonl` (arrival order; `/__requests` has the final status).

## Audit logs

`mu-plugins/agumail-e2e-audit.php` writes JSON lines to `.state/audit/`:

- `http.jsonl`: every outbound HTTP request WordPress makes. A `start` line (`pre_http_request`) with URL, host, timeout, whether it is an AguMail call, and the context of the request that made it: `uri`, `cron`, `admin`, `ajax`, `rest`, `cli`, `wcAjax`, the running Action Scheduler hook, user and `manage_woocommerce`, the tag, and `background` (cron, WP-CLI, an Action Scheduler callback, a wp-admin screen, or admin-ajax by a store manager). An `end` line (`http_api_debug`) with duration and status or error. A call that never returns still has its start line.
- `requests.jsonl`: every PHP request's server time at shutdown.
- `mail.jsonl`: mail WordPress tried to send (short-circuited; there is no mail server).
- `slow.jsonl`: requests slower than 1 s, with checkpoints on the main WordPress and WooCommerce hooks (ms since start, queries so far), to see where the time went.

`mu-plugins/agumail-e2e-env.php` turns off the Action Scheduler async runner unless real cron is on, captures mail, keeps WooCommerce from phoning home, lets CheckoutWC accept the `.test` shopper domains (it checks MX records), and unloads the AguMail plugins for a shopper carrying `agumail_e2e_plugins_off=1` (`withoutAguMail(page)`).

## Stop

```bash
./wp-setup.sh down      # stop the containers, keep the data (./wp-setup.sh up resumes)
./wp-setup.sh destroy   # remove containers and volumes (store, database)
rm -rf .state           # logs and switches (recreated on the next up)
```

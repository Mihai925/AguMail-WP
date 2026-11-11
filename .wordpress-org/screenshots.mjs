// Captures the wp.org screenshots (screenshot-1.png ... screenshot-6.png) from
// the e2e store, so they show the plugin as it is rather than a mock-up.
//
//   (cd ../e2e && ./wp-setup.sh up)   # the store and the fake AguMail
//   node screenshots.mjs
//
// It changes the store while it runs: a store name, the forms list, a popup, a
// draft page, the form plugins switched on, and a disconnect for the first
// shot. So it exports the database first and always imports it back at the
// end. Captions live under == Screenshots == in agumail/readme.txt, in this
// order.

import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { readFileSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const E2E = join(HERE, '..', 'e2e');
const SITE = 'http://localhost:8089';
const VIEWPORT = { width: 1280, height: 960 };
const SCALE = 1.5; // 1920x1440 files

// A made-up store in place of the e2e one. The host is reserved for examples.
const STORE = { name: 'Linden & Co.', host: 'shop.example.com' };
const RENAME = [
  ['AguMail E2E Shop', STORE.name],
  ['localhost:8089', STORE.host],
];

const FORMS = [
  { slug: 'newsletter', name: 'Newsletter', doubleOptIn: true, publishedAt: '2026-10-01T10:00:00.000Z' },
  { slug: 'welcome-offer', name: 'Welcome offer', doubleOptIn: false, publishedAt: '2026-10-02T10:00:00.000Z' },
  { slug: 'footer-signup', name: 'Footer signup', doubleOptIn: true, publishedAt: '2026-10-03T10:00:00.000Z' },
];
const POPUP = { slug: 'welcome-offer', trigger: 'delay', delay: 8, scroll: 50, exitIntent: false, frequencyDays: 14 };

const BACKUP = 'wporg-screenshots-backup.sql';

function wpSetup(...args) {
  return execFileSync(join(E2E, 'wp-setup.sh'), args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] }).trim();
}
const wp = (...args) => wpSetup('wp', ...args);
const json = (value) => JSON.stringify(value);

// ─── Page helpers ────────────────────────────────────────────────────────────

/** Core update nags and counters are not the plugin; the e2e store's name and host become STORE's. */
async function tidy(page) {
  await page.addStyleTag({
    content: `.update-nag, #wp-admin-bar-updates, #footer-upgrade, .update-plugins, .plugin-count,
      #wp-admin-bar-comments { display:none !important; }`,
  });
  await page.evaluate((pairs) => {
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
      for (const [from, to] of pairs) {
        if (node.nodeValue.includes(from)) node.nodeValue = node.nodeValue.split(from).join(to);
      }
    }
    document.title = document.title.split(pairs[0][0]).join(pairs[0][1]);
  }, RENAME);
}

/** Scrolls so the AguMail settings section `id` starts just under the admin bar. */
async function scrollToSection(page, id) {
  await page.evaluate((id) => {
    const card = document.getElementById(`agumail-section-${id}`);
    if (!card) throw new Error(`No AguMail section ${id}`);
    window.scrollTo(0, card.getBoundingClientRect().top + window.scrollY - 52);
  }, id);
  await page.waitForTimeout(300);
}

async function shoot(page, name) {
  await page.mouse.move(0, VIEWPORT.height - 1);
  await page.screenshot({ path: join(HERE, name) });
  console.log(name);
}

async function signIn(browser) {
  const context = await browser.newContext({ viewport: VIEWPORT, deviceScaleFactor: SCALE });
  const page = await context.newPage();
  await page.goto(`${SITE}/wp-login.php`);
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'admin');
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
  return { context, page };
}

// ─── The shots ───────────────────────────────────────────────────────────────

/** 1. Not connected yet: the welcome panel and one-click connect. */
async function connectScreen(browser) {
  const key = wp('option', 'get', 'agumail_api_key');
  wp('option', 'delete', 'agumail_api_key');
  try {
    const { context, page } = await signIn(browser);
    await page.goto(`${SITE}/wp-admin/admin.php?page=agumail`, { waitUntil: 'networkidle' });
    await tidy(page);
    await shoot(page, 'screenshot-1.png');
    await context.close();
  } finally {
    wp('option', 'update', 'agumail_api_key', key);
  }
}

/** 2. Connected: the account, and the store's history synced. */
async function overview(browser) {
  const { context, page } = await signIn(browser);
  await page.goto(`${SITE}/wp-admin/admin.php?page=agumail`, { waitUntil: 'networkidle' });
  await tidy(page);
  await shoot(page, 'screenshot-2.png');
  await context.close();
}

/** 3. Forms from AguMail, their shortcodes, and the site-wide popup. */
async function forms(browser) {
  const { context, page } = await signIn(browser);
  await page.goto(`${SITE}/wp-admin/admin.php?page=agumail`, { waitUntil: 'networkidle' });
  await tidy(page);
  await scrollToSection(page, 'forms');
  await shoot(page, 'screenshot-3.png');
  await context.close();
}

/** 4. The AguMail form block in the editor, with its settings. */
async function block(browser) {
  const content = [
    '<!-- wp:heading --><h2 class="wp-block-heading">Join the list</h2><!-- /wp:heading -->',
    '<!-- wp:paragraph --><p>New arrivals, restocks and the occasional offer, about twice a month.</p><!-- /wp:paragraph -->',
    '<!-- wp:agumail/form {"slug":"newsletter"} /-->',
    '<!-- wp:paragraph {"fontSize":"small"} --><p class="has-small-font-size">Unsubscribe from any email in one click.</p><!-- /wp:paragraph -->',
  ].join('\n');
  const id = wp('post', 'create', '--post_type=page', '--post_status=draft', '--post_title=Newsletter', `--post_content=${content}`, '--porcelain');

  const { context, page } = await signIn(browser);
  // The editor polls (heartbeat, autosave), so it never goes network-idle.
  await page.goto(`${SITE}/wp-admin/post.php?post=${id}&action=edit`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => window.wp?.data?.select('core/editor')?.getCurrentPostId?.(), null, { timeout: 60_000 });
  await page.evaluate(() => {
    const prefs = window.wp.data.dispatch('core/preferences');
    prefs.set('core/edit-post', 'welcomeGuide', false);
    // The block toolbar sits in the top bar instead of floating over the text above the block.
    prefs.set('core', 'fixedToolbar', true);
  });
  const guide = page.getByRole('dialog');
  if (await guide.isVisible().catch(() => false)) await page.keyboard.press('Escape');

  const canvas = page.frameLocator('iframe[name="editor-canvas"]');
  const formBlock = canvas.locator('[data-type="agumail/form"]');
  await formBlock.waitFor({ timeout: 60_000 });
  await formBlock.click({ position: { x: 20, y: 20 } });
  await page.evaluate(() => {
    const editor = window.wp.data.dispatch('core/editor') || window.wp.data.dispatch('core/edit-post');
    if (editor.openGeneralSidebar) editor.openGeneralSidebar('edit-post/block');
  });
  await page.getByText('Form settings').first().waitFor();
  await tidy(page);
  await shoot(page, 'screenshot-4.png');
  await context.close();
}

/**
 * 5. A shopper at the block checkout. The plugin registers its checkbox in the
 * checkout's order area, just above Place order, and the order summary stays
 * at the top of the page, so the frame is the checkout column from Shipping
 * options down: 960x720 of the page, at 2x to come out the same 1920x1440.
 */
async function checkout(browser) {
  const FRAME = { width: 960, height: 720 };
  // Wider than the other shots so the frame can centre the column: Storefront caps the column's width, so it only gains margin.
  const context = await browser.newContext({ viewport: { width: 1600, height: VIEWPORT.height }, deviceScaleFactor: (VIEWPORT.width * SCALE) / FRAME.width });
  const page = await context.newPage();
  await page.goto(`${SITE}/?add-to-cart=13`, { waitUntil: 'networkidle' });
  await page.goto(`${SITE}/?add-to-cart=15`, { waitUntil: 'networkidle' });
  await page.goto(`${SITE}/checkout/`, { waitUntil: 'networkidle' });
  await page.locator('.wc-block-components-order-summary-item').first().waitFor();

  await page.getByLabel('Email address').fill('jamie@example.com');
  for (const [field, value] of [['first_name', 'Jamie'], ['last_name', 'Rivera'], ['address_1', '214 Alder Street'],
    ['city', 'San Diego'], ['postcode', '92101']]) {
    await page.locator(`#shipping-${field}`).fill(value);
  }
  const optIn = page.getByLabel('Email me about new products and offers');
  await optIn.check();
  await page.getByRole('heading', { name: 'Payment options' }).click();
  await page.waitForLoadState('networkidle');

  await page.mouse.move(0, 0);
  await tidy(page);
  const box = await page.evaluate(() => {
    const page = (el) => { const r = el.getBoundingClientRect(); return { left: r.left + scrollX, top: r.top + scrollY, right: r.right + scrollX, bottom: r.bottom + scrollY }; };
    const heading = [...document.querySelectorAll('h2')].find((h) => h.textContent.trim() === 'Shipping options');
    return {
      from: page(heading),
      to: page(document.querySelector('.wc-block-components-checkout-place-order-button')),
    };
  });
  // Centred on what is drawn, from the headings' left edge to Place order's right edge.
  const clip = {
    x: box.from.left - (FRAME.width - (box.to.right - box.from.left)) / 2,
    y: box.from.top - 48,
    ...FRAME,
  };
  if (clip.x < 0 || box.to.bottom > clip.y + clip.height) throw new Error(`The checkout does not fit the frame: ${json({ clip, box })}`);
  await page.screenshot({ path: join(HERE, 'screenshot-5.png'), clip, fullPage: true });
  console.log('screenshot-5.png');
  await context.close();
}

/** 6. A form plugin sending its signups to AguMail: Contact Form 7's AguMail tab. */
async function formPlugins(browser) {
  wpSetup('builders', 'on');
  // Only Contact Form 7 stays on: the others fill wp-admin with their own notices and pointers.
  wp('plugin', 'deactivate', 'elementor-pro', 'elementor', 'wpforms-lite', '--quiet');
  const builders = JSON.parse(readFileSync(join(E2E, '.state', 'builders.json'), 'utf8'));
  const formId = builders.cf7?.formId;
  if (!formId) throw new Error(`No Contact Form 7 form in builders.json: ${json(builders.cf7)}`);
  // A store's own form rather than the e2e fixture: its title and its AguMail tag.
  wp('eval', `$f = wpcf7_contact_form(${formId}); $f->set_title('Contact us');
    $s = (array) $f->prop(AGUMAIL_CF7_PROPERTY); $s['tags'] = 'contact-form';
    $f->set_properties(array(AGUMAIL_CF7_PROPERTY => $s)); $f->save();`);

  const { context, page } = await signIn(browser);
  // A plugin activated a moment ago sends the next wp-admin page to its welcome screen; let it be this one.
  await page.goto(`${SITE}/wp-admin/`, { waitUntil: 'networkidle' });
  await page.goto(`${SITE}/wp-admin/admin.php?page=wpcf7&post=${formId}&action=edit`, { waitUntil: 'networkidle' });
  await page.getByRole('tab', { name: 'AguMail' }).click();
  await page.locator('#agumail-panel').waitFor();
  await tidy(page);
  await shoot(page, 'screenshot-6.png');
  await context.close();
}

// ─── Run ─────────────────────────────────────────────────────────────────────

wp('db', 'export', `/e2e/state/${BACKUP}`, '--quiet');
const browser = await chromium.launch();
try {
  wp('option', 'update', 'blogname', STORE.name);
  wp('option', 'update', 'agumail_forms_cache', json({
    loaderUrl: 'http://localhost:8090/embed/agumail-forms.js',
    forms: FORMS,
    fetchedAt: Math.floor(Date.now() / 1000) - 3 * 3600,
  }), '--format=json');
  wp('option', 'update', 'agumail_popup', json(POPUP), '--format=json');

  for (const shot of [connectScreen, overview, forms, block, checkout, formPlugins]) {
    await shot(browser);
  }
} finally {
  await browser.close();
  wp('db', 'import', `/e2e/state/${BACKUP}`, '--quiet');
  rmSync(join(E2E, '.state', BACKUP), { force: true });
  console.log('Store restored.');
}

import { expect, test, type Page } from '@playwright/test';
import { adminContext, SETTINGS_URL, settingsNotice } from '../helpers/admin';
import { lastLine, startV2 } from '../helpers/agumail';
import { SITE_URL } from '../helpers/env';
import { fakeControl } from '../helpers/fake';
import { wp, wpEval } from '../helpers/wp';

// From activating the plugin to a connected store, as a merchant meets it:
//   - activating AguMail from the Plugins screen lands on the AguMail page, once;
//   - until the store is connected, the Plugins row leads with Connect, and a notice on the Dashboard and the
//     Plugins screen offers "Connect to AguMail", which starts the one-click connect;
//   - once connected, the row leads with Settings and the notice is gone; Dismiss hides it for good;
//   - website tracking starts off, and once connected a notice offers to switch it on.
// The store ends connected to the fake again, as every other spec expects.

test.describe.configure({ mode: 'serial' });

const PLUGIN_ROW = 'tr[data-plugin="agumail/agumail.php"]';
const NOTICE_TEXT = 'Connect AguMail to start syncing your customers, orders and carts.';

async function disconnect(): Promise<void> {
  await wpEval(
    "foreach (array('agumail_api_key', 'agumail_config', 'agumail_connection', 'agumail_connected_at', 'agumail_forms_cache', 'agumail_connect_notice_dismissed', 'agumail_tracking_enabled') as $o) delete_option($o); delete_transient('agumail_ping_retry'); delete_transient('agumail_activation_redirect');",
  );
}

function notice(page: Page) {
  return page.locator('.agumail-connect-notice');
}

/** The first link in the AguMail row of the Plugins screen. */
async function firstRowLink(page: Page) {
  await page.goto(`${SITE_URL}/wp-admin/plugins.php`);
  return page.locator(`${PLUGIN_ROW} .row-actions a`).first();
}

test.beforeAll(async () => {
  await startV2();
  await fakeControl({ connectPage: 'approve', codeTtlMs: 10 * 60 * 1000, apiKey: null });
  await disconnect();
});

test.afterAll(async () => {
  await wpEval("delete_option('agumail_connect_notice_dismissed'); delete_transient('agumail_activation_redirect');");
  // Leave v2 active and connected to the fake for the specs after this one.
  await startV2();
});

test('activating AguMail from the Plugins screen lands on its settings page, once, and the row leads with Connect', async ({ browser }) => {
  await wp('plugin', 'deactivate', 'agumail');
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await page.goto(`${SITE_URL}/wp-admin/plugins.php`);
    await page.locator(`${PLUGIN_ROW} .row-actions .activate a`).click();
    await page.waitForURL(/\/wp-admin\/admin\.php\?page=agumail$/);
    await expect(page.locator('.agumail-settings h1')).toHaveText('AguMail');

    // Once: the Plugins screen stays the Plugins screen now.
    const link = await firstRowLink(page);
    expect(page.url()).toContain('/wp-admin/plugins.php');
    await expect(link).toHaveText('Connect');
    expect(await link.getAttribute('href')).toBe(SETTINGS_URL);
  } finally {
    await admin.close();
  }
  expect(lastLine(await wpEval("echo var_export(get_transient('agumail_activation_redirect'), true);"))).toBe('false');
});

test('the connect notice shows on the Dashboard and Plugins screens only, and its button connects', async ({ browser }) => {
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    for (const path of ['index.php', 'plugins.php']) {
      await page.goto(`${SITE_URL}/wp-admin/${path}`);
      await expect(notice(page), `the notice on ${path}`).toContainText(NOTICE_TEXT);
    }
    for (const path of ['edit.php?post_type=product', 'admin.php?page=wc-settings', 'admin.php?page=agumail', 'edit.php', 'options-general.php']) {
      await page.goto(`${SITE_URL}/wp-admin/${path}`);
      await expect(notice(page), `a notice on ${path}`).toHaveCount(0);
    }

    // The button starts the one-click connect straight away: the fake AguMail approves and sends the merchant back.
    await page.goto(`${SITE_URL}/wp-admin/index.php`);
    const connect = notice(page).getByRole('link', { name: 'Connect to AguMail', exact: true });
    expect(await connect.getAttribute('href')).toContain('agumail_action=connect_start');
    await connect.click();
    await page.waitForURL(/page=agumail/);
    expect(await settingsNotice(page)).toBe('Connected to AguMail as AguMail E2E Shop.');

    await page.goto(`${SITE_URL}/wp-admin/index.php`);
    await expect(notice(page)).toHaveCount(0);
    const link = await firstRowLink(page);
    await expect(link).toHaveText('Settings');
    expect(await link.getAttribute('href')).toBe(SETTINGS_URL);
  } finally {
    await admin.close();
  }
});

test('once connected, a notice says website tracking is off and its button switches it on', async ({ browser }) => {
  expect(lastLine(await wpEval("echo var_export(get_option('agumail_tracking_enabled', false), true);"))).toBe('false');
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  const tracking = page.locator('.agumail-tracking-notice');
  try {
    for (const path of ['index.php', 'plugins.php', 'admin.php?page=agumail']) {
      await page.goto(`${SITE_URL}/wp-admin/${path}`);
      await expect(tracking, `the tracking notice on ${path}`).toContainText('AguMail website tracking is off.');
    }
    for (const path of ['edit.php', 'admin.php?page=wc-settings']) {
      await page.goto(`${SITE_URL}/wp-admin/${path}`);
      await expect(tracking, `a tracking notice on ${path}`).toHaveCount(0);
    }

    await page.goto(`${SITE_URL}/wp-admin/index.php`);
    await tracking.getByRole('link', { name: 'Turn on website tracking', exact: true }).click();
    await page.waitForURL(/page=agumail/);
    expect(await settingsNotice(page)).toBe('Website tracking is on.');
    await expect(tracking).toHaveCount(0);
    await expect(page.locator('#agumail_tracking_enabled')).toBeChecked();
  } finally {
    await admin.close();
  }
  expect(lastLine(await wpEval("echo get_option('agumail_tracking_enabled');"))).toBe('yes');
});

test('Dismiss hides the notice for good and goes back to the page it was on', async ({ browser }) => {
  await disconnect();
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await page.goto(`${SITE_URL}/wp-admin/plugins.php`);
    await expect(notice(page)).toContainText(NOTICE_TEXT);
    await notice(page).getByRole('link', { name: 'Dismiss', exact: true }).click();
    await page.waitForURL(/\/wp-admin\/plugins\.php/);
    await expect(notice(page)).toHaveCount(0);
    await page.goto(`${SITE_URL}/wp-admin/index.php`);
    await expect(notice(page)).toHaveCount(0);
    // Still not connected: the row keeps leading with Connect.
    await expect(await firstRowLink(page)).toHaveText('Connect');
  } finally {
    await admin.close();
  }
  expect(lastLine(await wpEval("echo get_option('agumail_connect_notice_dismissed');"))).toBe('yes');
});

import crypto from 'node:crypto';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { adminContext, openSettings, section } from '../helpers/admin';
import { expectNoShopperCalls, lastLine, outboxRows, sentRecords, startV2 } from '../helpers/agumail';
import { buildersOff, buildersOn, reseedBuilders, type Builders, type SignupRecord } from '../helpers/builders';
import { tagRequests, uniqueEmail, uniqueTag } from '../helpers/checkout';
import { SITE_URL } from '../helpers/env';
import { fakeControl, fakeMode, fakeRequests, fakeState } from '../helpers/fake';
import { drainQueue, outboxDueNow, runQueue, wp, wpEval } from '../helpers/wp';

// Signups from the store's own form plugins: Elementor Pro's AguMail action, Contact Form 7's AguMail
// tab, WPForms' Settings > AguMail and Gravity Forms' Settings > AguMail. A visitor's submission queues
// the signup (one outbox row) and never waits for AguMail; the queue sends it to /api/v1/signups with
// the form, the page, the consent words and the form's tags. With tracking on, the page then tells the
// AguMail script who signed up.

test.describe.configure({ mode: 'serial' });

let builders: Builders;

type Builder = 'elementor' | 'cf7' | 'wpforms' | 'gravityforms';

/** Fills and submits one builder's seeded form as a visitor, and waits for the form's own success message. */
async function submit(page: Page, builder: Builder, person: { email: string; first: string; last: string; consent: boolean }): Promise<void> {
  await tagRequests(page, uniqueTag(builder));
  if (builder === 'elementor') {
    await page.goto(builders.elementor.url);
    const form = page.locator('form.elementor-form');
    await form.locator('[name="form_fields[name]"]').fill(`${person.first} ${person.last}`);
    await form.locator('[name="form_fields[email]"]').fill(person.email);
    if (person.consent) await form.locator('[name="form_fields[consent]"]').check();
    await form.locator('button[type="submit"]').click();
    await expect(form.locator('.elementor-message-success')).toBeVisible();
    return;
  }
  if (builder === 'cf7') {
    await page.goto(builders.cf7.url);
    const form = page.locator('form.wpcf7-form');
    await form.locator('[name="your-name"]').fill(`${person.first} ${person.last}`);
    await form.locator('[name="your-email"]').fill(person.email);
    if (person.consent) await form.locator('[name="newsletter"]').check();
    await form.locator('[type="submit"]').click();
    // Sent, or its mail failed: both went through (the store has no mail server behind wp_mail).
    await expect(form).toHaveAttribute('data-status', /^(sent|failed)$/);
    return;
  }
  if (builder === 'gravityforms') {
    // The newsletter form, sent by Gravity Forms' own AJAX.
    const id = builders.gravityforms.formId;
    await page.goto(builders.gravityforms.url);
    await page.locator(`#input_${id}_1_3`).fill(person.first);
    await page.locator(`#input_${id}_1_6`).fill(person.last);
    await page.locator(`#input_${id}_2`).fill(person.email);
    if (person.consent) await page.locator(`#input_${id}_4_1`).check();
    await page.locator(`#gform_submit_button_${id}`).click();
    await expect(page.locator(`#gform_confirmation_message_${id}`)).toContainText('Thanks for signing up!');
    return;
  }
  const id = builders.wpforms.formId;
  await page.goto(builders.wpforms.url);
  await page.locator(`#wpforms-${id}-field_1`).fill(person.first);
  await page.locator(`#wpforms-${id}-field_1-last`).fill(person.last);
  await page.locator(`#wpforms-${id}-field_2`).fill(person.email);
  if (person.consent) await page.locator(`#wpforms-${id}-field_3_1`).check();
  await page.locator(`#wpforms-submit-${id}`).click();
  await expect(page.locator('.wpforms-confirmation-container-full')).toContainText('Thanks for signing up!');
}

async function asVisitor<T>(browser: Browser, fn: (page: Page) => Promise<T>): Promise<T> {
  const context = await browser.newContext();
  const page = await context.newPage();
  try {
    return await fn(page);
  } finally {
    await context.close();
  }
}

async function signupsFor(email: string) {
  return sentRecords<SignupRecord>('/api/v1/signups', 'signups', (s) => s.email === email.toLowerCase());
}

async function queuedSignups() {
  return (await outboxRows()).filter((r) => r.type === 'signup');
}

const b64url = (buf: Buffer) => buf.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

/** The identify calls the AguMail script stub reported, with the signature it was given. */
async function identifyCalls(email: string): Promise<{ email: string; sig: string | null }[]> {
  return (await fakeRequests({ path: '/stub/calls' }))
    .map((r) => r.body as { script?: string; fn?: string; args?: [string, string | null] } | null)
    .filter((b) => b && b.script === 'site' && b.fn === 'identify' && b.args && b.args[0] === email.toLowerCase())
    .map((b) => ({ email: b!.args![0], sig: b!.args![1] }));
}

test.beforeAll(async () => {
  await startV2({ checkout: 'classic' });
  builders = await buildersOn();
});

test.afterAll(async () => {
  await fakeMode('ok', { releaseHung: true });
  await fakeControl({ match: null });
  await buildersOff();
});

const expected: Record<Builder, (email: string) => Partial<SignupRecord>> = {
  elementor: (email) => ({
    email,
    firstName: 'Ada',
    lastName: 'Lovelace',
    tags: ['popup', 'e2e'],
    doubleOptIn: true,
    form: { builder: 'elementor', id: builders.elementor.formId, name: 'AguMail popup' },
    pageUrl: builders.elementor.url,
    consentText: 'Send me offers and news',
  }),
  cf7: (email) => ({
    email,
    firstName: 'Ada',
    lastName: 'Lovelace',
    tags: ['contact', 'e2e'],
    doubleOptIn: false,
    form: { builder: 'cf7', id: String(builders.cf7.formId), name: 'AguMail e2e contact' },
    pageUrl: builders.cf7.url,
    consentText: 'Send me offers and news',
  }),
  wpforms: (email) => ({
    email,
    firstName: 'Ada',
    lastName: 'Lovelace',
    tags: ['news', 'e2e'],
    doubleOptIn: true,
    form: { builder: 'wpforms', id: String(builders.wpforms.formId), name: 'AguMail e2e newsletter' },
    pageUrl: builders.wpforms.url,
    consentText: 'Send me offers and news',
  }),
  gravityforms: (email) => ({
    email,
    firstName: 'Ada',
    lastName: 'Lovelace',
    tags: ['news', 'e2e'],
    doubleOptIn: true,
    form: { builder: 'gravityforms', id: String(builders.gravityforms.formId), name: 'AguMail e2e gf newsletter' },
    pageUrl: builders.gravityforms.url,
    consentText: 'Send me offers and news',
  }),
};

for (const builder of ['elementor', 'cf7', 'wpforms', 'gravityforms'] as const) {
  test(`${builder}: the visitor signs up while AguMail hangs, and the next queue run sends the signup`, async ({ browser }) => {
    const email = uniqueEmail(builder);
    await fakeMode('hang');
    await asVisitor(browser, async (page) => {
      await submit(page, builder, { email, first: 'Ada', last: 'Lovelace', consent: true });
      // With tracking on, the page named the visitor to the AguMail script, signed.
      const { identifySecret } = await fakeState();
      const sig = b64url(crypto.createHmac('sha256', identifySecret).update(email.toLowerCase()).digest());
      await expect.poll(() => identifyCalls(email)).toEqual([{ email: email.toLowerCase(), sig }]);
    });
    const queued = await queuedSignups();
    expect(queued.map((r) => r.object_key)).toContain(`${expected[builder](email).form!.builder}:${expected[builder](email).form!.id}:${email.toLowerCase()}`);
    expect(await signupsFor(email), 'nothing is sent while the visitor is on the page').toEqual([]);

    await fakeMode('ok', { releaseHung: true });
    await outboxDueNow();
    await drainQueue();
    const sent = await signupsFor(email);
    expect(sent.length).toBe(1);
    expect(sent[0].record).toMatchObject(expected[builder](email.toLowerCase()));
    expect(sent[0].record.ip, 'the visitor address is sent for the per-visitor limit').toMatch(/^[0-9a-f.:]+$/i);
    expect(Date.parse(sent[0].record.occurredAt)).toBeGreaterThan(Date.now() - 10 * 60_000);
    expect(await queuedSignups()).toEqual([]);
    // A signup never goes through the contacts sync.
    expect(await sentRecords('/api/v1/contacts', 'contacts', (c: { email: string }) => c.email === email.toLowerCase())).toEqual([]);
    expectNoShopperCalls();
  });
}

test('an unticked consent box signs nobody up, on every builder', async ({ browser }) => {
  for (const builder of ['elementor', 'cf7', 'wpforms', 'gravityforms'] as const) {
    const email = uniqueEmail(`${builder}-no`);
    await asVisitor(browser, (page) => submit(page, builder, { email, first: 'Bea', last: 'Stone', consent: false }));
    expect((await queuedSignups()).filter((r) => r.object_key.endsWith(`:${email.toLowerCase()}`)), `${builder} queued a signup without consent`).toEqual([]);
    expect(await identifyCalls(email), `${builder} named a visitor who did not sign up`).toEqual([]);
  }
  expectNoShopperCalls();
});

test('a signup AguMail refuses is parked and shown under Status', async ({ browser }) => {
  const email = uniqueEmail('cf7-refused');
  await asVisitor(browser, (page) => submit(page, 'cf7', { email, first: 'Cy', last: 'Ray', consent: true }));
  await fakeMode('400-at-index', { match: email.toLowerCase(), paths: ['/api/v1/signups'] });
  await outboxDueNow();
  await runQueue({ dueNow: true });
  await fakeMode('ok');
  const row = (await queuedSignups()).find((r) => r.object_key.endsWith(`:${email.toLowerCase()}`))!;
  expect(row.status).toBe('failed');
  expect(row.last_error).toMatch(/^signups\.\d+\.email: Invalid email$/);

  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await openSettings(page);
    const status = section(page, 'status');
    await expect(status.locator('table.agumail-status-queue tr', { hasText: 'Form signups' }).locator('td').nth(2)).toHaveText('1');
    const failed = status.locator('table.agumail-status-failed tr', { hasText: `${email.toLowerCase()} from AguMail e2e contact` });
    await expect(failed).toContainText(/signups\.\d+\.email: Invalid email/);
    await failed.getByRole('link', { name: 'Discard' }).click();
    await page.waitForURL(/page=agumail/);
  } finally {
    await admin.close();
  }
  expect((await queuedSignups()).filter((r) => r.status === 'failed')).toEqual([]);
  expectNoShopperCalls();
});

test('the settings page says where to switch AguMail on in each form plugin', async ({ browser }) => {
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await openSettings(page);
    const plugins = section(page, 'signups');
    await expect(plugins.locator('h2')).toHaveText('Form plugins');
    await expect(plugins).toContainText('Elementor Pro: edit the form, add AguMail under Actions After Submit');
    await expect(plugins).toContainText('Contact Form 7: edit the form, open the AguMail tab and tick Send signups to AguMail.');
    await expect(plugins).toContainText('WPForms: edit the form, open Settings > AguMail and switch on Send signups to AguMail.');
    await expect(plugins).toContainText('Gravity Forms: edit the form, open Settings > AguMail and switch on Send signups to AguMail.');
  } finally {
    await admin.close();
  }
});

test('the Contact Form 7 AguMail tab saves', async ({ browser }) => {
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=wpcf7&post=${builders.cf7.formId}&action=edit`);
    await page.locator('#contact-form-editor-tabs').getByRole('tab', { name: 'AguMail' }).click();
    const panel = page.locator('#agumail-panel');
    await expect(panel.locator('[name="agumail-signup[enabled]"]')).toBeChecked();
    await panel.locator('#agumail-signup-tags').fill('contact, e2e, saved');
    await panel.locator('[name="agumail-signup[double_opt_in]"]').check();
    await expect(panel.locator('#agumail-signup-email option')).toHaveText(['Detect automatically', 'your-name', 'your-email', 'newsletter']);
    await panel.locator('#agumail-signup-email').selectOption('your-email');
    await page.locator('#wpcf7-admin-form-element [name="wpcf7-save"]:visible').first().click();
    await page.waitForLoadState('load');
    const saved = JSON.parse(lastLine(await wpEval(`echo wp_json_encode(wpcf7_contact_form(${builders.cf7.formId})->prop('agumail_signup'));`)));
    expect(saved).toEqual({
      enabled: '1',
      tags: 'contact, e2e, saved',
      double_opt_in: '1',
      fields: { email: 'your-email', first_name: '', last_name: '', full_name: '', phone: '', consent: '' },
    });
  } finally {
    await admin.close();
    await reseedBuilders();
  }
});

test('the WPForms AguMail section saves', async ({ browser }) => {
  const id = builders.wpforms.formId;
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=wpforms-builder&view=settings&form_id=${id}`);
    await page.locator('.wpforms-panel-sidebar-section-agumail').click();
    const content = page.locator('.wpforms-panel-content-section-agumail');
    await expect(content).toBeVisible();
    await expect(content.locator('#wpforms-panel-field-settings-agumail_enable')).toBeChecked();
    await content.locator('#wpforms-panel-field-settings-agumail_tags').fill('news, e2e, saved');
    // Double opt-in off: its toggle, then the email field picked by hand.
    await content.locator('#wpforms-panel-field-settings-agumail_double_opt_in-wrap .wpforms-toggle-control-icon').click();
    await expect(content.locator('#wpforms-panel-field-settings-agumail_field_email option')).toHaveText(['Detect automatically', 'Email']);
    await content.locator('#wpforms-panel-field-settings-agumail_field_email').selectOption('2');
    await page.locator('#wpforms-save').click();
    await expect(page.locator('#wpforms-save')).not.toHaveClass(/wpforms-save-in-progress|disabled/, { timeout: 30_000 });
    await expect
      .poll(async () => JSON.parse(lastLine(await wpEval(`$f = get_post(${id}); $d = json_decode($f->post_content, true); echo wp_json_encode(array_intersect_key($d['settings'], array_flip(array('agumail_enable', 'agumail_tags', 'agumail_double_opt_in', 'agumail_field_email', 'agumail_field_consent'))));`))))
      .toEqual({ agumail_enable: '1', agumail_tags: 'news, e2e, saved', agumail_field_email: '2', agumail_field_consent: '3' });
  } finally {
    await admin.close();
    await reseedBuilders();
  }
});

// ── Gravity Forms: the pilot's lead form ──
// Separate First Name, Last Name, Email and Phone fields, nothing mapped, single opt-in, Gravity Forms'
// honeypot, and a Redirect confirmation to a PDF that must keep working with AguMail on.

/**
 * Fills the guide form as a visitor and submits it. Resolves with Gravity Forms' answer to the post and,
 * for a submission that is not spam, where the browser went next: headless Chromium downloads a PDF
 * rather than show it, so that is the address of the download the redirect started.
 */
async function submitGuide(page: Page, person: { email: string; first: string; last: string; phone: string }, honeypot = '') {
  const guide = builders.gravityforms.guide;
  await tagRequests(page, uniqueTag('gravityforms-guide'));
  await page.goto(guide.url);
  await page.locator(`#input_${guide.formId}_1`).fill(person.first);
  await page.locator(`#input_${guide.formId}_3`).fill(person.last);
  await page.locator(`#input_${guide.formId}_4`).fill(person.email);
  await page.locator(`#input_${guide.formId}_5`).fill(person.phone);
  if (honeypot) {
    // A bot fills every input, the hidden honeypot too.
    await page.locator(`#gform_${guide.formId} .gform_validation_container input`).evaluate((el, value) => {
      (el as HTMLInputElement).value = value;
    }, honeypot);
  }
  const answer = page.waitForResponse((r) => r.request().method() === 'POST' && r.url() === guide.url);
  const followed = honeypot ? Promise.resolve('') : page.waitForEvent('download').then((download) => download.url());
  await page.locator(`#gform_submit_button_${guide.formId}`).click();
  return { response: await answer, followed: await followed };
}

test('gravityforms: the pilot\'s lead form still redirects to its PDF, and the signup is queued and sent', async ({ browser }) => {
  const email = uniqueEmail('gravityforms-guide');
  const guide = builders.gravityforms.guide;
  await fakeMode('hang');
  await asVisitor(browser, async (page) => {
    const { response, followed } = await submitGuide(page, { email, first: 'Ada', last: 'Lovelace', phone: '+1 555 010 0100' });
    expect(response.status(), 'Gravity Forms answers the post with its redirect').toBe(302);
    expect(response.headers()['location']).toBe(guide.pdfUrl);
    expect(followed, 'the visitor did not get the PDF').toBe(guide.pdfUrl);
  });
  const queued = await queuedSignups();
  expect(queued.map((r) => r.object_key)).toContain(`gravityforms:${guide.formId}:${email.toLowerCase()}`);
  expect(await signupsFor(email), 'nothing is sent while the visitor is on the page').toEqual([]);

  await fakeMode('ok', { releaseHung: true });
  await outboxDueNow();
  await drainQueue();
  const sent = await signupsFor(email);
  expect(sent.length).toBe(1);
  expect(sent[0].record).toMatchObject({
    email: email.toLowerCase(),
    firstName: 'Ada',
    lastName: 'Lovelace',
    phone: '+1 555 010 0100',
    tags: ['Headshot Guide Requests'],
    doubleOptIn: false,
    form: { builder: 'gravityforms', id: String(guide.formId), name: 'AguMail e2e guide' },
    pageUrl: guide.url,
  });
  expect(sent[0].record.consentText, 'a form without a consent box sends no consent words').toBeUndefined();
  expectNoShopperCalls();
});

test('gravityforms: an entry its honeypot marks as spam is never queued', async ({ browser }) => {
  const email = uniqueEmail('gravityforms-spam');
  const guide = builders.gravityforms.guide;
  await asVisitor(browser, async (page) => {
    await submitGuide(page, { email, first: 'Bot', last: 'Bot', phone: '+1 555 010 0199' }, 'http://spam.test');
    // A spam entry gets Gravity Forms' default message, not the form's redirect.
    await expect(page.locator(`#gform_confirmation_message_${guide.formId}`)).toBeVisible();
  });
  // Gravity Forms kept the entry, as spam.
  const status = lastLine(await wpEval(`$e = GFAPI::get_entries(${guide.formId}, array('status' => 'spam', 'field_filters' => array(array('key' => '4', 'value' => '${email}')))); echo count($e);`));
  expect(status, 'Gravity Forms did not mark the entry as spam').toBe('1');
  expect((await queuedSignups()).filter((r) => r.object_key.endsWith(`:${email.toLowerCase()}`)), 'a spam entry was queued').toEqual([]);
  expectNoShopperCalls();
});

test('gravityforms: with WP Armour active, the lead form still redirects and queues the signup', async ({ browser }) => {
  const installed = await wp('plugin', 'is-installed', 'honeypot').then(
    () => true,
    () => false,
  );
  if (!installed) await wp('plugin', 'install', 'honeypot', '--quiet');
  await wp('plugin', 'activate', 'honeypot');
  try {
    const email = uniqueEmail('gravityforms-wparmour');
    await asVisitor(browser, async (page) => {
      const { response, followed } = await submitGuide(page, { email, first: 'Cy', last: 'Ray', phone: '+1 555 010 0177' });
      expect(response.status()).toBe(302);
      expect(followed, 'the visitor did not get the PDF').toBe(builders.gravityforms.guide.pdfUrl);
    });
    expect((await queuedSignups()).map((r) => r.object_key)).toContain(`gravityforms:${builders.gravityforms.guide.formId}:${email.toLowerCase()}`);
  } finally {
    await wp('plugin', 'deactivate', 'honeypot');
  }
});

test('the Gravity Forms AguMail tab saves', async ({ browser }) => {
  const id = builders.gravityforms.formId;
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await page.goto(`${SITE_URL}/wp-admin/admin.php?page=gf_edit_forms&view=settings&subview=agumail&id=${id}`);
    await expect(page.locator('#_gform_setting_enabled')).toBeChecked();
    await page.locator('[name="_gform_setting_tags"]').fill('news, e2e, saved');
    // Double opt-in off: its toggle, then the email field picked by hand.
    await page.locator('label[for="_gform_setting_double_opt_in"]').click();
    // Each select lists only whole fields of its kind, after "Detect automatically".
    await expect(page.locator('[name="_gform_setting_field_email"] option')).toHaveText(['Detect automatically', 'Email']);
    await expect(page.locator('[name="_gform_setting_field_name"] option')).toHaveText(['Detect automatically', 'Name']);
    await expect(page.locator('[name="_gform_setting_field_consent"] option')).toHaveText(['Detect automatically', 'Consent']);
    await page.locator('[name="_gform_setting_field_email"]').selectOption('2');
    await page.locator('#gform-settings-save').click();
    await page.waitForLoadState('load');
    // A toggle switched off saves as empty, which the adapter reads as off.
    await expect
      .poll(async () => JSON.parse(lastLine(await wpEval(`$f = GFAPI::get_form(${id}); echo wp_json_encode(array_intersect_key($f['agumail'], array_flip(array('enabled', 'tags', 'double_opt_in', 'field_email', 'field_consent'))));`))))
      .toEqual({ enabled: '1', tags: 'news, e2e, saved', double_opt_in: '', field_email: '2', field_consent: '' });
    // Gravity Forms lists the add-on's tab among the form's settings.
    await expect(page.locator('.gform-settings__navigation a', { hasText: 'AguMail' })).toBeVisible();
  } finally {
    await admin.close();
    await reseedBuilders();
  }
});

test('the Elementor editor lists the AguMail action and its field map', async ({ browser }) => {
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await page.goto(`${SITE_URL}/wp-admin/post.php?post=${builders.elementor.pageId}&action=elementor`, { timeout: 120_000 });
    const preview = page.frameLocator('#elementor-preview-iframe');
    const widget = preview.locator(`.elementor-element-${builders.elementor.formElementId}`);
    await expect(widget).toBeVisible({ timeout: 120_000 });
    // Select the form widget, as a click on it does, which opens its settings in the panel.
    await page.evaluate((id) => {
      const w = window as unknown as { $e: { run: (command: string, args: object) => void }; elementor: { getContainer: (id: string) => unknown } };
      w.$e.run('document/elements/select', { container: w.elementor.getContainer(id) });
    }, builders.elementor.formElementId);
    const panel = page.locator('#elementor-panel');
    // Actions After Submit offers AguMail, and the form has it.
    await panel.locator('.elementor-control-section_integration').click();
    const actions = panel.locator('.elementor-control-submit_actions select');
    await expect(actions.locator('option[value="agumail"]')).toHaveText('AguMail');
    expect(await actions.evaluate((el) => Array.from((el as HTMLSelectElement).selectedOptions).map((o) => o.value))).toContain('agumail');
    // The AguMail section: tags, double opt-in and the five rows of the field map.
    await panel.locator('.elementor-control-section_agumail').click();
    await expect(panel.locator('.elementor-control-agumail_tags input')).toHaveValue('popup, e2e');
    const map = panel.locator('.elementor-control-agumail_fields_map');
    await expect(map).toBeVisible();
    await expect(map.locator('.elementor-repeater-fields')).toHaveCount(5);
    await expect(map.locator('.elementor-control-title')).toContainText(['Email', 'First name', 'Last name', 'Phone', 'Consent']);
    // Each row lists the form's fields of its kind: only the email field for Email, only the box for Consent.
    const rows = map.locator('.elementor-repeater-fields');
    await expect(rows.nth(0).locator('select option')).toHaveText(['- None -', 'Email']);
    await expect(rows.nth(4).locator('select option')).toHaveText(['- None -', 'Consent']);
  } finally {
    await admin.close();
  }
});

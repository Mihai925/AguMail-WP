import { expect, test, type Browser, type Page, type Request } from '@playwright/test';
import { contactsFor, cartsFor, drainAll, expectNoShopperCalls, lastLine, orderFacts, ordersFor, php, sentRecords, startV2, type OrderRecord } from './agumail';
import { addToCart, checkoutPageUrl, placeGuestOrder, tagRequests, uniqueEmail, uniqueTag, V1_CONSENT_LABEL } from './checkout';
import { readSite, type CheckoutType } from './env';
import { checkoutWcAvailable, setCheckout, wpEval } from './wp';

// Email capture on one checkout (classic, block or CheckoutWC), as a shopper does it:
//   1. typing the email and leaving the field reaches AguMail (once the queue runs) as a contact with
//      no consent at all, plus an open cart with a restore link, and no order;
//   2. ticking the marketing box without placing the order is no consent;
//   3. placing the order with the box ticked is consent.
// Nothing here may call AguMail from a shopper's request.

const EMAIL_FIELD: Record<CheckoutType, string> = {
  classic: '#billing_email',
  checkoutwc: '#billing_email',
  block: '#email',
};

/** The marketing checkbox on this checkout. */
export function optInBox(page: Page, checkout: CheckoutType) {
  return checkout === 'block' ? page.locator('input[type="checkbox"][id*="agumail"]') : page.locator('#agumail_optin');
}

async function openCheckoutWithCart(browser: Browser, checkout: CheckoutType, tag: string) {
  const context = await browser.newContext();
  const page = await context.newPage();
  await tagRequests(page, tag);
  await addToCart(page, readSite().products.find((p) => p.sku === 'AGM-MUG')!.id);
  await page.goto(checkoutPageUrl(checkout));
  return { context, page };
}

/** Types the email and leaves the field; resolves with the capture endpoint's answer. */
async function typeEmailAndLeave(page: Page, checkout: CheckoutType, email: string) {
  const field = page.locator(EMAIL_FIELD[checkout]).first();
  await field.waitFor({ state: 'visible' });
  const answer = page.waitForResponse((r) => r.url().includes('wc-ajax=agumail_capture') && r.request().method() === 'POST', { timeout: 20_000 });
  await field.fill(email);
  await field.press('Tab');
  const response = await answer;
  expect(response.status()).toBe(200);
  const body = (await response.json()) as { ok: boolean; sig?: string; email?: string };
  expect(body.ok).toBe(true);
  // Posted as the email only, with the page's nonce: the checkbox is never read before the order is submitted.
  const posted = new URLSearchParams(response.request().postData() ?? '');
  expect([...posted.keys()].sort()).toEqual(['context', 'email', 'nonce']);
  expect(posted.get('nonce')).toMatch(/^[0-9a-f]{10}$/);
  expect(posted.get('email')).toBe(email.toLowerCase());
  return body;
}

/** Nothing about the box was saved: no order for the email carries an answer. */
async function answersSavedFor(email: string): Promise<string[]> {
  const out = await wpEval(
    `$ids = wc_get_orders(array('billing_email' => ${php(email)}, 'status' => 'any', 'limit' => -1, 'return' => 'ids')); $a = array(); foreach ($ids as $id) { $v = (string) wc_get_order($id)->get_meta('_agumail_optin'); if ($v !== '') $a[] = $v; } echo wp_json_encode($a);`,
  );
  return JSON.parse(lastLine(out));
}

export function captureSuite(checkout: CheckoutType): void {
  test.describe(`email capture on the ${checkout} checkout`, () => {
    test.describe.configure({ mode: 'serial' });
    let email = '';

    test.beforeAll(async () => {
      if (checkout === 'checkoutwc' && !(await checkoutWcAvailable())) return;
      await startV2({ checkout });
    });

    test.afterAll(async () => {
      if (checkout === 'checkoutwc') await setCheckout('classic');
    });

    test.beforeEach(async () => {
      test.skip(checkout === 'checkoutwc' && !(await checkoutWcAvailable()), 'CheckoutWC is not mounted (CHECKOUTWC_DIR)');
    });

    test('leaving the email field sends a contact with no consent and a cart with a restore link, and no order', async ({ browser }) => {
      email = uniqueEmail(`capture-${checkout}`);
      const { context, page } = await openCheckoutWithCart(browser, checkout, uniqueTag(`capture-${checkout}`));
      try {
        const answer = await typeEmailAndLeave(page, checkout, email);
        // Signed for the site script's identify, with the address as the server kept it.
        expect(answer.email).toBe(email.toLowerCase());
        expect(answer.sig).toMatch(/^[A-Za-z0-9_-]{43}$/);
      } finally {
        await context.close();
      }

      await drainAll();
      const contacts = await contactsFor(email);
      expect(contacts.length, 'the captured contact reached AguMail').toBeGreaterThan(0);
      for (const { record } of contacts) {
        expect(record.consent, 'a captured email claims no consent').toBeUndefined();
        expect(record.consentedAt).toBeUndefined();
      }
      const carts = await cartsFor(email);
      expect(carts.length, 'the cart reached AguMail').toBeGreaterThan(0);
      const cart = carts[carts.length - 1].record;
      expect(cart.status).toBe('open');
      expect(cart.items.map((i) => i.sku)).toEqual(['AGM-MUG']);
      expect(cart.checkoutUrl).toMatch(/[?&]agumail_cart=wc-[0-9a-f-]{36}&agumail_token=[0-9a-f]{32}$/);
      // The cart's contact went first, in its own request.
      expect(contacts[0].request.seq).toBeLessThan(carts[0].request.seq);
      const orders = await sentRecords<OrderRecord>('/api/v1/orders', 'orders', (o) => o.email === email.toLowerCase());
      expect(orders, 'no order was placed').toEqual([]);
      expectNoShopperCalls();
    });

    test('a ticked box on a checkout that is never submitted is no consent', async ({ browser }) => {
      const { context, page } = await openCheckoutWithCart(browser, checkout, uniqueTag(`tick-${checkout}`));
      try {
        await typeEmailAndLeave(page, checkout, email);
        if (checkout === 'checkoutwc') {
          await goToCheckoutWcPayment(page);
        }
        const box = optInBox(page, checkout).first();
        await box.waitFor({ state: 'visible' });
        if (checkout === 'block') {
          // The block checkout saves additional fields to its draft order as they change: a PUT, sent by
          // apiFetch as a POST with X-HTTP-Method-Override.
          const isDraftSave = (r: Request) =>
            r.url().includes('/wc/store/v1/checkout') && (r.method() === 'PUT' || r.headers()['x-http-method-override'] === 'PUT');
          const saved = page.waitForResponse((r) => isDraftSave(r.request()), { timeout: 10_000 });
          await box.check();
          // The tick reached the server with the draft order, which must not take it as an answer.
          const response = await saved;
          expect(response.ok()).toBe(true);
          expect(response.request().postDataJSON()).toMatchObject({ additional_fields: { 'agumail/optin': true } });
        } else {
          await box.check();
          // A refreshed order review posts the whole form, box included, to the server.
          const review = page.waitForResponse((r) => r.url().includes('wc-ajax=update_order_review'), { timeout: 15_000 });
          await page.evaluate(() => (window as unknown as { jQuery?: (s: unknown) => { trigger: (e: string) => void } }).jQuery?.(document.body).trigger('update_checkout'));
          const posted = new URLSearchParams((await review).request().postData() ?? '');
          // The server did see the tick (the redrawn box keeps it), and must not take it as an answer.
          expect(new URLSearchParams(posted.get('post_data') ?? '').get('agumail_optin')).toBe('1');
        }
        await expect(box).toBeChecked();
      } finally {
        await context.close();
      }

      await drainAll();
      for (const { record } of await contactsFor(email)) {
        expect(record.consent, 'a tick that was never submitted claims nothing').toBeUndefined();
      }
      expect(await answersSavedFor(email), 'no order carries an answer').toEqual([]);
      expectNoShopperCalls();
    });

    test('placing the order with the box ticked sends consent before the order', async ({ browser }) => {
      const context = await browser.newContext();
      const page = await context.newPage();
      let orderId = 0;
      try {
        if (checkout === 'block') {
          await tagRequests(page, uniqueTag('block-box'));
          await addToCart(page, readSite().products.find((p) => p.sku === 'AGM-MUG')!.id);
          await page.goto(checkoutPageUrl('block'));
          // The box is WooCommerce's own additional field, in the order information step, unticked.
          const box = optInBox(page, 'block');
          await expect(box).toHaveCount(1);
          await expect(box).not.toBeChecked();
          await expect(page.getByLabel(V1_CONSENT_LABEL)).toHaveCount(1);
          expect(await box.getAttribute('id')).toMatch(/^order-/);
        }
        const order = await placeGuestOrder(page, { checkout, email, optIn: true, productId: readSite().products.find((p) => p.sku === 'AGM-MUG')!.id });
        orderId = order.orderId;
      } finally {
        await context.close();
      }
      const facts = await orderFacts(orderId);
      expect(facts.optin).toBe('yes');
      expect(['checkout', 'store-api']).toContain(facts.createdVia);

      await drainAll();
      const contacts = await contactsFor(email);
      const opted = contacts.filter((c) => c.record.consent?.optedIn === true);
      expect(opted.length, 'the ticked order sent the contact as opted in').toBe(1);
      expect(opted[0].record.consent?.source).toBe('checkout');
      expect(opted[0].record.consentedAt).toBeTruthy();
      expect(opted[0].record.customerSince).toBeTruthy();
      const orders = await ordersFor(orderId);
      expect(orders.length).toBeGreaterThan(0);
      expect(opted[0].request.seq, 'the contact goes before its order').toBeLessThan(orders[0].request.seq);
      expectNoShopperCalls();
    });
  });
}

/** CheckoutWC keeps the checkbox on its payment step. */
async function goToCheckoutWcPayment(page: Page): Promise<void> {
  const shopper = { first: 'Guest', last: 'Shopper', address: '10 Market Street', city: 'San Francisco', postcode: '94110', phone: '5550100' };
  for (const prefix of ['shipping', 'billing']) {
    for (const [field, value] of [
      ['first_name', shopper.first],
      ['last_name', shopper.last],
      ['address_1', shopper.address],
      ['city', shopper.city],
      ['postcode', shopper.postcode],
      ['phone', shopper.phone],
    ]) {
      const input = page.locator(`#${prefix}_${field}`);
      if ((await input.count()) && (await input.first().isVisible())) await input.first().fill(value);
    }
  }
  const toShipping = page.locator('.cfw-continue-to-shipping-btn:visible');
  if (await toShipping.count()) {
    await toShipping.first().click();
    await page.locator('#cfw-shipping-method').waitFor({ state: 'visible' });
  }
  const toPayment = page.locator('.cfw-continue-to-payment-btn:visible');
  if (await toPayment.count()) await toPayment.first().click();
  await page.locator('#cfw-payment-method').waitFor({ state: 'visible' });
  await page.waitForFunction(() => document.querySelectorAll('.blockUI.blockOverlay').length === 0);
}

import { expect, test, type Browser, type Page } from '@playwright/test';
import { drainAll, productsFor, startV2 } from '../helpers/agumail';
import { SITE_URL, readSite } from '../helpers/env';
import { fakeRequests } from '../helpers/fake';
import { siteEvents, useRealSiteScript } from '../helpers/siteRuntime';
import { wp, wpEval } from '../helpers/wp';

// What a shopper's browser records on a product page, with AguMail's real site
// runtime and the plugin's footer element naming the product.
//
// A product with options opens as the product, with nothing picked. The view
// of the product waits a moment, because WooCommerce applies a default option
// or one the link names just after the page loads. Each option the shopper
// settles on is recorded once, so comparing sizes never sends a view per click.

test.describe.configure({ mode: 'serial' });

const SHOPPER = 'shopper0001@e2e.agumail.test';
const tee = () => readSite().products.find((p) => p.sku === 'AGM-TEE')!;
const tote = () => readSite().products.find((p) => p.sku === 'AGM-TOTE')!;
const option = (value: string) => tee().variations!.find((v) => v.value === value)!;

async function lastSeq(): Promise<number> {
  return (await fakeRequests()).reduce((m, r) => Math.max(m, r.seq), 0);
}

/** A guest the site already knows, as a browser that clicked through from an email last week is. */
async function shopperPage(browser: Browser): Promise<Page> {
  const context = await browser.newContext();
  await context.addCookies([{ name: 'agm_e', value: SHOPPER, url: SITE_URL }]);
  return context.newPage();
}

const productViews = async (since: number) => (await siteEvents(since, 'viewed_product')).map((e) => e.fields);

/** Lets every wait the runtime holds run out, so a view it was going to send has been sent. */
const settle = (page: Page) => page.waitForTimeout(2500);

async function pick(page: Page, value: string): Promise<void> {
  const select = page.locator('select[name="attribute_size"]');
  if (await select.count()) {
    await select.selectOption(value);
  } else {
    await page.getByRole('button', { name: value, exact: true }).first().click();
  }
}

test.beforeAll(async () => {
  await startV2();
  await wpEval("update_option('agumail_tracking_enabled', 'yes');");
  await useRealSiteScript();
});

test.afterAll(async () => {
  await wp('theme', 'activate', 'storefront');
});

test('a product without options is recorded once, as WooCommerce describes it', async ({ browser }) => {
  const since = await lastSeq();
  const page = await shopperPage(browser);
  await page.goto(tote().url);
  await settle(page);
  const views = await productViews(since);
  expect(views).toHaveLength(1);
  expect(views[0]).toMatchObject({ productId: String(tote().id), sku: 'AGM-TOTE', title: tote().name, price: String(Number(tote().price)), email: SHOPPER });
  expect(views[0]).not.toHaveProperty('optionId');
  await page.context().close();
});

test('a product with options left as it opened is recorded as the product, once', async ({ browser }) => {
  const since = await lastSeq();
  const page = await shopperPage(browser);
  await page.goto(tee().url);
  await settle(page);
  const views = await productViews(since);
  expect(views).toHaveLength(1);
  expect(views[0]).toMatchObject({ productId: String(tee().id) });
  expect(views[0]).not.toHaveProperty('optionId');
  await page.context().close();
});

test('the option the shopper picks is recorded with its own price and link, and not the product as well', async ({ browser }) => {
  const since = await lastSeq();
  const page = await shopperPage(browser);
  await page.goto(tee().url);
  await pick(page, 'L');
  await settle(page);
  const views = await productViews(since);
  expect(views).toHaveLength(1);
  expect(views[0]).toMatchObject({
    productId: String(tee().id),
    optionId: String(option('L').id),
    option: 'L',
    sku: option('L').sku,
    price: String(Number(option('L').price)),
  });
  expect(views[0].optionUrl).toContain('attribute_size=L');
  await page.context().close();
});

test('comparing options records each one once', async ({ browser }) => {
  const since = await lastSeq();
  const page = await shopperPage(browser);
  await page.goto(tee().url);
  for (const value of ['S', 'M', 'S', 'L', 'M']) {
    await pick(page, value);
    await page.waitForTimeout(300);
  }
  await settle(page);
  expect((await productViews(since)).map((v) => v.optionId)).toEqual(['S', 'M', 'L'].map((v) => String(option(v).id)));
  await page.context().close();
});

test('a link that names an option records only that option', async ({ browser }) => {
  const since = await lastSeq();
  const page = await shopperPage(browser);
  await page.goto(`${tee().url}${tee().url.includes('?') ? '&' : '?'}attribute_size=M`);
  await settle(page);
  expect((await productViews(since)).map((v) => v.optionId)).toEqual([String(option('M').id)]);
  await page.context().close();
});

test('the catalogue sync carries each option, as WooCommerce names and links it', async () => {
  // What a view named only by an address is checked against (siteApi.ts).
  await wpEval(`agumail_product_queue(${tee().id});`);
  await drainAll();
  const sent = await productsFor(String(tee().id));
  const options = sent[sent.length - 1]?.record.options ?? [];
  expect(options.map((o) => [o.externalId, o.title, o.sku, o.price])).toEqual(
    tee().variations!.map((v) => [String(v.id), v.value, v.sku, Number(v.price)]),
  );
  for (const o of options) expect(o.productUrl).toContain(`attribute_size=${o.title}`);
});

test('a block theme records the option the shopper picks too', async ({ browser }) => {
  await wp('theme', 'activate', 'twentytwentyfive');
  try {
    const since = await lastSeq();
    const page = await shopperPage(browser);
    await page.goto(tee().url);
    await pick(page, 'M');
    await settle(page);
    expect((await productViews(since)).map((v) => v.optionId)).toEqual([String(option('M').id)]);
    await page.context().close();
  } finally {
    await wp('theme', 'activate', 'storefront');
  }
});

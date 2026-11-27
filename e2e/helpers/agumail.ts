import { expect } from '@playwright/test';
import { describeCalls, resetAudit, shopperAguMailCalls } from './audit';
import { FAKE_INTERNAL_URL, type CheckoutType } from './env';
import { fakeRequests, fakeReset, type FakeRequest } from './fake';
import { activatePlugin, connectPlugin, drainQueue, setCheckout, wpEval } from './wp';

// Shared set-up and reads for the v2 specs. Each spec starts from: v2 active and connected to the fake,
// the fake and the audit logs empty, the plugin's outbox and carts empty.

export type ContactRecord = {
  email: string;
  firstName?: string;
  lastName?: string;
  tags?: string[];
  consent?: { optedIn: boolean; source?: string };
  unsubscribed?: boolean;
  customerSince?: string;
  consentedAt?: string;
};

export type CartRecord = {
  externalId: string;
  email: string;
  total: number;
  items: { title: string; qty: number; price: number; productExternalId: string; sku?: string }[];
  status: 'open' | 'completed';
  checkoutUrl?: string;
  lastActivityAt?: string;
};

export type OrderRecord = {
  externalId: string;
  email: string;
  total: number;
  currency?: string;
  items?: { externalId?: string; sku?: string; title: string; qty: number; price: number }[];
  campaignRef?: string;
  occurredAt: string;
  source: string;
};

export type ProductRecord = {
  externalId: string;
  title: string;
  price?: number;
  sku?: string;
  status: 'active' | 'archived';
  imageUrl?: string;
  productUrl?: string;
  categories?: string[];
  options?: { externalId: string; title: string; sku?: string; price?: number; imageUrl?: string; productUrl?: string }[];
};

/** A record with the request that carried it. */
export type Sent<T> = { record: T; request: FakeRequest };

/** Empties the plugin's outbox and carts tables and forgets its backoff and batch sizes. */
export async function resetPluginQueue(): Promise<void> {
  await wpEval(
    "global $wpdb; $wpdb->query(\"DELETE FROM {$wpdb->prefix}agumail_outbox\"); $wpdb->query(\"DELETE FROM {$wpdb->prefix}agumail_carts\"); delete_option('agumail_backoff_until'); delete_option('agumail_batch_sizes'); delete_option('agumail_drain_lock');",
  );
}

/** v2 active, connected to the fake, the checkout type set, everything empty. */
export async function startV2(options: { checkout?: CheckoutType } = {}): Promise<void> {
  await activatePlugin('v2');
  await connectPlugin(FAKE_INTERNAL_URL);
  if (options.checkout) await setCheckout(options.checkout);
  await resetPluginQueue();
  await fakeReset();
  resetAudit();
}

/** The spec's own guarantee, every time: no request a shopper made called AguMail. */
export function expectNoShopperCalls(): void {
  const calls = shopperAguMailCalls();
  expect(calls, `AguMail calls from shopper requests:\n${describeCalls(calls)}`).toEqual([]);
}

/** Every record the fake received at a collection endpoint (POST), with its request, in arrival order. */
export async function sentRecords<T>(endpoint: string, collection: string, filter: (r: T) => boolean = () => true): Promise<Sent<T>[]> {
  const out: Sent<T>[] = [];
  for (const request of await fakeRequests({ path: endpoint, method: 'POST' })) {
    if (request.path !== endpoint || request.status !== 200) continue;
    const body = request.body as Record<string, unknown> | null;
    const list = body && Array.isArray(body[collection]) ? (body[collection] as T[]) : [];
    for (const record of list) if (filter(record)) out.push({ record, request });
  }
  return out;
}

export function contactsFor(email: string): Promise<Sent<ContactRecord>[]> {
  const e = email.toLowerCase();
  return sentRecords<ContactRecord>('/api/v1/contacts', 'contacts', (c) => c.email === e);
}

export function cartsFor(email: string): Promise<Sent<CartRecord>[]> {
  const e = email.toLowerCase();
  return sentRecords<CartRecord>('/api/v1/carts', 'carts', (c) => c.email === e);
}

export function ordersFor(orderId: number | string): Promise<Sent<OrderRecord>[]> {
  return sentRecords<OrderRecord>('/api/v1/orders', 'orders', (o) => String(o.externalId) === String(orderId));
}

export function productsFor(productId: number | string): Promise<Sent<ProductRecord>[]> {
  return sentRecords<ProductRecord>('/api/v1/products', 'products', (p) => String(p.externalId) === String(productId));
}

/** Runs the queue until nothing is waiting (the drain moves carts first, so a cart needs a run of its own). */
export async function drainAll(rounds = 6): Promise<void> {
  await drainQueue({ rounds });
}

/** A PHP single-quoted string. */
export function php(value: string): string {
  return "'" + value.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
}

/** The last line wp eval printed (wp-cli can print notices first). */
export function lastLine(out: string): string {
  return out.split('\n').pop() ?? '';
}

/** Order meta and dates, read from WooCommerce. */
export async function orderFacts(orderId: number): Promise<{
  status: string;
  createdVia: string;
  created: number;
  paid: number | null;
  total: number;
  optin: string;
  cartId: string;
  synced: string;
  cid: string;
}> {
  const out = await wpEval(
    `$o = wc_get_order(${Number(orderId)}); echo wp_json_encode(array('status' => $o->get_status(), 'createdVia' => $o->get_created_via(), 'created' => $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0, 'paid' => $o->get_date_paid() ? $o->get_date_paid()->getTimestamp() : null, 'total' => (float) $o->get_total(), 'optin' => (string) $o->get_meta('_agumail_optin'), 'cartId' => (string) $o->get_meta('_agumail_cart_id'), 'synced' => (string) $o->get_meta('_agumail_synced'), 'cid' => (string) $o->get_meta('_agumail_cid')));`,
  );
  return JSON.parse(lastLine(out));
}

/** Outbox rows, for status and failure checks. */
export async function outboxRows(): Promise<{ id: number; type: string; object_key: string; status: string; attempts: number; version: number; last_error: string | null }[]> {
  const out = await wpEval(
    "global $wpdb; echo wp_json_encode($wpdb->get_results(\"SELECT id, type, object_key, status, attempts, version, last_error FROM {$wpdb->prefix}agumail_outbox ORDER BY id\", ARRAY_A));",
  );
  return (JSON.parse(lastLine(out)) as Record<string, string>[]).map((r) => ({
    id: Number(r.id),
    type: r.type,
    object_key: r.object_key,
    status: r.status,
    attempts: Number(r.attempts),
    version: Number(r.version),
    last_error: r.last_error,
  }));
}

/** The order's edit screen: the orders page under HPOS, else the post editor. */
export async function orderEditUrl(orderId: number): Promise<string> {
  const hpos = lastLine(
    await wpEval("echo \\Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled() ? 'yes' : 'no';"),
  );
  return hpos === 'yes'
    ? `http://localhost:8089/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`
    : `http://localhost:8089/wp-admin/post.php?post=${orderId}&action=edit`;
}

/** The fake's latest request number: requests after it are this step's. */
export async function fakeSeq(): Promise<number> {
  return (await (await import('./fake')).fakeRequests()).reduce((m, r) => Math.max(m, r.seq), 0);
}

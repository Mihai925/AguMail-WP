import { FAKE_URL } from './env';

export type FakeMode = 'ok' | 'hang' | 'slow' | '429' | '500' | '400-at-index' | 'down';

export type FakeControl = {
  mode?: FakeMode;
  delayMs?: number;
  retryAfter?: number;
  index?: number;
  match?: string | null;
  paths?: string[] | null;
  times?: number | null;
  integrationStatus?: 'connected' | 'disconnected' | 'error' | null;
  storeUrl?: string | null;
  forms?: { slug: string; name: string; doubleOptIn: boolean; publishedAt?: string }[];
  statuses?: Record<string, string | null>;
  apiKey?: string | null;
  connectPage?: 'approve' | 'show' | 'deny';
  /** How long a connect code lives; negative issues codes that are already expired. */
  codeTtlMs?: number;
  /** Whether the account's plan has store sync (default true); without it stores connect for forms and signups only. */
  storeSync?: boolean;
  /** What /p/_/v1/site.js serves: the recording stub (default) or AguMail's real site runtime (helpers/siteRuntime.ts). */
  siteScript?: 'stub' | 'real';
  releaseHung?: boolean;
};

export type FakeRequest = {
  seq: number;
  time: string;
  method: string;
  path: string;
  query: Record<string, string>;
  headers: Record<string, string>;
  body: unknown;
  records: { collection: string; count: number } | null;
  mode: FakeMode;
  status: number | 'hung' | 'reset' | null;
};

async function call<T>(path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(`${FAKE_URL}${path}`, init);
  if (!res.ok) throw new Error(`fake-agumail ${path}: HTTP ${res.status} ${await res.text()}`);
  return (await res.json()) as T;
}

/** Clears the request log and the fake's data; mode back to ok. */
export function fakeReset(): Promise<unknown> {
  return call('/__reset', { method: 'POST' });
}

export function fakeControl(control: FakeControl): Promise<unknown> {
  return call('/__control', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(control) });
}

export function fakeMode(mode: FakeMode, extra: Omit<FakeControl, 'mode'> = {}): Promise<unknown> {
  return fakeControl({ mode, ...extra });
}

export function fakeRequests(filter: { path?: string; method?: string; since?: number } = {}): Promise<FakeRequest[]> {
  const q = new URLSearchParams();
  if (filter.path) q.set('path', filter.path);
  if (filter.method) q.set('method', filter.method);
  if (filter.since) q.set('since', String(filter.since));
  return call(`/__requests?${q}`);
}

export function fakeState(): Promise<{
  control: FakeControl;
  seq: number;
  hung: number;
  counts: { contacts: number; orders: number; products: number; carts: number; events: number; signups: number };
  publicUrl: string;
  issuedKeys: number;
  lastIssuedKey: string | null;
  identifySecret: string;
  siteKey: string;
}> {
  return call('/__state');
}

/** Every record the plugin sent to one /api/v1 collection endpoint, flattened in arrival order. */
export async function fakeRecords<T = Record<string, unknown>>(endpoint: string): Promise<T[]> {
  const requests = await fakeRequests({ path: endpoint, method: 'POST' });
  const out: T[] = [];
  for (const r of requests) {
    const body = r.body as Record<string, unknown> | null;
    const collection = r.records?.collection;
    if (body && collection && Array.isArray(body[collection])) out.push(...(body[collection] as T[]));
  }
  return out;
}

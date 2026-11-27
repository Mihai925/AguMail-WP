import fs from 'node:fs';
import path from 'node:path';
import { E2E_DIR, STATE_DIR } from './env';
import { fakeControl, fakeRequests } from './fake';

/**
 * AguMail's own site runtime, read from an AguMail checkout: AGUMAIL_APP_DIR,
 * or the app folder of an AguMail checkout next to this repo.
 *
 * The runtime is one template literal (app/src/tracking/siteRuntime.ts) that
 * holds no backslash and no interpolation, by that file's own rule, so its text
 * between the backticks is the script exactly. Either one appearing means the
 * rule changed, and this refuses rather than serve something else.
 */
export function agumailSiteRuntime(): string {
  const appDir = process.env.AGUMAIL_APP_DIR || path.resolve(E2E_DIR, '../../AguMail/app');
  const file = path.join(appDir, 'src/tracking/siteRuntime.ts');
  if (!fs.existsSync(file)) throw new Error(`No AguMail site runtime at ${file}: set AGUMAIL_APP_DIR to an AguMail checkout's app folder.`);
  const text = fs.readFileSync(file, 'utf8');
  const start = text.indexOf('const SOURCE = `');
  const end = text.indexOf('\n`;', start);
  if (start === -1 || end === -1) throw new Error(`Could not find the runtime's SOURCE literal in ${file}.`);
  const source = text.slice(start + 'const SOURCE = `'.length, end + 1);
  if (source.includes('\\') || source.includes('${')) throw new Error(`The runtime in ${file} now holds an escape or an interpolation; read it another way.`);
  return source;
}

/** The fake serves AguMail's real site runtime from now on (until the next fakeReset()). */
export async function useRealSiteScript(): Promise<void> {
  fs.mkdirSync(STATE_DIR, { recursive: true });
  fs.writeFileSync(path.join(STATE_DIR, 'site-runtime.js'), agumailSiteRuntime());
  await fakeControl({ siteScript: 'real' });
}

export type SiteEvent = { seq: number; fields: Record<string, string> };

/** What the runtime posted to the site events endpoint after `since`, in arrival order. */
export async function siteEvents(since = 0, metric?: string): Promise<SiteEvent[]> {
  const out: SiteEvent[] = [];
  for (const request of await fakeRequests({ path: '/api/public/site/', method: 'POST', since })) {
    const body = request.body;
    if (!body || typeof body !== 'object' || Array.isArray(body)) continue;
    const fields = body as Record<string, string>;
    if (metric && fields.metric !== metric) continue;
    out.push({ seq: request.seq, fields });
  }
  return out;
}

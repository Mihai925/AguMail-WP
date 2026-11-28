import fs from 'node:fs';
import path from 'node:path';
import { STATE_DIR } from './env';
import { wp } from './wp';

// The form plugins of the signup specs: Elementor, Elementor Pro and Gravity Forms (mounted from
// ELEMENTOR_DIR, ELEMENTOR_PRO_DIR and GRAVITYFORMS_DIR), Contact Form 7 and WPForms Lite (from
// wordpress.org). They stay inactive unless a spec switches them on, so no other spec loads them.

export const BUILDER_PLUGINS = ['elementor', 'elementor-pro', 'contact-form-7', 'wpforms-lite', 'gravityforms'];

export type Builders = {
  elementor: { pageId: number; url: string; formElementId: string; formName: string; formId: string };
  cf7: { formId: number; title: string; pageId: number; url: string };
  wpforms: { formId: number; title: string; pageId: number; url: string };
  gravityforms: {
    formId: number;
    title: string;
    pageId: number;
    url: string;
    guide: { formId: number; title: string; pageId: number; url: string; pdfUrl: string };
  };
};

export type SignupRecord = {
  email: string;
  firstName?: string;
  lastName?: string;
  phone?: string;
  tags: string[];
  doubleOptIn: boolean;
  form: { builder: 'elementor' | 'cf7' | 'wpforms' | 'gravityforms'; id: string; name: string };
  pageUrl?: string;
  ip?: string;
  consentText?: string;
  occurredAt: string;
};

/** .state/builders.json, written by wp/builders.php. */
export function readBuilders(): Builders {
  return JSON.parse(fs.readFileSync(path.join(STATE_DIR, 'builders.json'), 'utf8')) as Builders;
}

/** Installs what is missing, switches the five plugins on and (re)seeds their signup form pages. */
export async function buildersOn(): Promise<Builders> {
  for (const slug of ['contact-form-7', 'wpforms-lite']) {
    const installed = await wp('plugin', 'is-installed', slug).then(
      () => true,
      () => false,
    );
    if (!installed) await wp('plugin', 'install', slug, '--quiet');
  }
  await wp('plugin', 'activate', ...BUILDER_PLUGINS);
  await reseedBuilders();
  return readBuilders();
}

/** Puts the forms and their AguMail settings back as wp/builders.php seeds them. */
export async function reseedBuilders(): Promise<void> {
  await wp('eval-file', '/e2e/wp/builders.php');
}

export async function buildersOff(): Promise<void> {
  await wp('plugin', 'deactivate', ...BUILDER_PLUGINS);
}

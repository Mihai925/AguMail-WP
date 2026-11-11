// Renders the wp.org listing images into this folder:
//   banner-1544x500.png, banner-772x250.png, icon-256x256.png, icon-128x128.png
//
// Same approach as AguMail/scripts/og-image: plain HTML in the brand's tokens,
// set in the real Bricolage Grotesque and Figtree from Google Fonts, screenshot
// by headless Chromium at 2x and drawn down with sharp.
//
//   npm install && node render.mjs
//
// Only the PNGs reach SVN /assets (.github/scripts/stage-wporg-assets.sh picks
// them out), so this script, package.json and the README stay in git.

import { chromium } from 'playwright';
import sharp from 'sharp';
import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const OUT = dirname(fileURLToPath(import.meta.url));

// Copied from AguMail/app/tailwind.config.js. A standalone browser never loads
// the app's Tailwind build, so the hexes have to be spelled out here.
const C = {
  cream: '#F7EFDF',
  creamLit: '#FCEEDA',
  sand: '#FCFBF9',
  ink: '#1C1917',
  inkSoft: '#57534E',
  inkMuted: '#6B655E',
  inkFaint: '#8A837B',
  amber700: '#b45309',
  amberInk: '#7C3A06',
  line: '#EBE2D2',
  lineInput: '#988E7E',
  positive: '#0E7A4F',
};

const FONTS = `
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet" />`;

// lucide's `Mail` (ISC), path data verbatim from lucide-react, as Logo.tsx and
// the og card draw it.
function envelope(stroke = '#ffffff') {
  return `<svg viewBox="0 0 24 24" fill="none" stroke="${stroke}" stroke-width="2"
      stroke-linecap="round" stroke-linejoin="round" style="width:56%;height:56%">
    <path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7" />
    <rect x="2" y="4" width="20" height="16" rx="2" />
  </svg>`;
}

// LogoMark: the envelope in a Burnt Honey tile, corner at Logo.tsx's ratio.
function mark(size) {
  return `<span style="display:flex;align-items:center;justify-content:center;flex-shrink:0;
      width:${size}px;height:${size}px;border-radius:${Math.round(size * 0.3)}px;
      background:${C.amber700};box-shadow:0 1px 2px rgba(33,23,16,0.05)">${envelope()}</span>`;
}

// The icon is the mark, full-bleed. wp.org and wp-admin show it on white at
// 128px and some surfaces round the corners themselves, so it carries no
// corner or transparency of its own to get rounded twice.
function iconHtml(size) {
  return `<!doctype html><html><head><meta charset="utf-8" /><style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { width:${size}px; height:${size}px; overflow:hidden; display:flex;
      align-items:center; justify-content:center; background:${C.amber700}; }
  </style></head><body>${envelope()}</body></html>`;
}

// The banner is the og card's composition at banner proportions: cream to sand
// ramp, the GoldenHour peach glow high on the right, the lockup and the plugin's
// own promise on the left. On the right, the thing most sites install it for, a
// signup popup, and the toast that says where the signup went.
//
// wp-admin's plugin details modal shows the banner at 772x250 and lays the
// plugin name over it in a dark band (wp-admin/css/common.css,
// #plugin-information-title.with-banner h2): 26px in, 174px down, 50px tall and
// as wide as the name, 558px for ours in Helvetica and 584px in Arial. So the
// left block ends above it, the popup is short enough to end above it too, and
// the toast sits right of it. TITLE_BAND is that box with a little room, and
// the render refuses a banner that puts anything in it.
const TITLE_BAND = { left: 26, top: 174, right: 592, bottom: 224 };

function bannerHtml() {
  return `<!doctype html><html><head><meta charset="utf-8" />${FONTS}<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body {
      width:772px; height:250px; overflow:hidden; position:relative;
      font-family:'Figtree', system-ui, sans-serif; -webkit-font-smoothing:antialiased;
      background:linear-gradient(to bottom, ${C.cream} 0%, ${C.creamLit} 45%, ${C.sand} 100%);
    }
    .sun {
      position:absolute; top:-230px; right:-170px; width:620px; height:620px; border-radius:9999px;
      background:radial-gradient(closest-side, rgba(246,201,154,0.55), transparent);
      filter:blur(30px); opacity:0.75;
    }
    .left { position:absolute; left:46px; top:30px; }
    .row { display:flex; align-items:center; gap:11px; }
    .wordmark { font-family:'Bricolage Grotesque', sans-serif; font-weight:800; font-size:22px;
      letter-spacing:-0.02em; color:${C.ink}; }
    h1 { margin-top:16px; font-family:'Bricolage Grotesque', sans-serif; font-weight:800;
      font-size:44px; line-height:1.02; letter-spacing:-0.03em; color:${C.ink}; }
    h1 .accent { color:${C.amber700}; }

    .popup {
      position:absolute; right:38px; top:34px; width:306px; padding:17px 18px 18px;
      background:#ffffff; border:1px solid ${C.line}; border-radius:16px;
      box-shadow:0 1px 2px rgba(33,23,16,0.05), 0 24px 48px -20px rgba(33,23,16,0.28);
      transform:rotate(-2deg);
    }
    .close { position:absolute; top:14px; right:16px; width:10px; height:10px; }
    .close::before, .close::after { content:''; position:absolute; left:4px; top:-1px; width:1.6px;
      height:12px; border-radius:2px; background:${C.inkFaint}; }
    .close::before { transform:rotate(45deg); } .close::after { transform:rotate(-45deg); }
    .kicker { font-size:10px; font-weight:700; letter-spacing:0.09em; text-transform:uppercase;
      color:${C.amberInk}; }
    .title { margin-top:5px; font-family:'Bricolage Grotesque', sans-serif; font-weight:700;
      font-size:21px; line-height:1.1; letter-spacing:-0.02em; color:${C.ink}; }
    .form { margin-top:13px; display:flex; gap:8px; }
    .field { flex:1; height:36px; display:flex; align-items:center; padding:0 12px;
      border:1px solid ${C.lineInput}; border-radius:10px; font-size:13px; color:${C.inkMuted}; }
    .button { height:36px; padding:0 15px; display:flex; align-items:center;
      border-radius:10px; background:${C.amber700}; color:#ffffff; font-size:13.5px; font-weight:600; }

    .toast {
      position:absolute; right:30px; top:180px; display:flex; align-items:center; gap:9px;
      padding:8px 14px 8px 9px; background:#ffffff; border:1px solid ${C.line}; border-radius:9999px;
      box-shadow:0 1px 2px rgba(33,23,16,0.05), 0 12px 32px -12px rgba(33,23,16,0.22);
      font-size:13px; font-weight:600; color:${C.ink};
    }
    .toast .tick { width:20px; height:20px; border-radius:9999px; background:${C.positive};
      display:flex; align-items:center; justify-content:center; }
  </style></head><body>
    <div class="sun"></div>
    <div class="left">
      <div class="row">${mark(34)}<span class="wordmark">AguMail</span></div>
      <h1>Email marketing<br /><span class="accent">made easy.</span></h1>
    </div>
    <div class="popup">
      <span class="close"></span>
      <div class="kicker">Join the list</div>
      <div class="title">Get 10% off your first order</div>
      <div class="form">
        <div class="field">you@example.com</div>
        <div class="button">Subscribe</div>
      </div>
    </div>
    <div class="toast">
      <span class="tick"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="#ffffff"
        stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg></span>
      New subscriber
    </div>
  </body></html>`;
}

const JOBS = [
  {
    html: bannerHtml(),
    width: 772,
    height: 250,
    fonts: true,
    keepClear: { selector: '.row, h1, .popup, .toast', band: TITLE_BAND },
    outputs: [
      ['banner-1544x500.png', 1544, 500],
      ['banner-772x250.png', 772, 250],
    ],
  },
  {
    html: iconHtml(256),
    width: 256,
    height: 256,
    fonts: false,
    outputs: [
      ['icon-256x256.png', 256, 256],
      ['icon-128x128.png', 128, 128],
    ],
  },
];

const browser = await chromium.launch();
try {
  for (const job of JOBS) {
    const page = await browser.newPage({
      viewport: { width: job.width, height: job.height },
      deviceScaleFactor: 2,
    });
    await page.setContent(job.html, { waitUntil: 'networkidle', timeout: 30_000 });
    await page.evaluate(() => document.fonts.ready);
    if (job.fonts) {
      const loaded = await page.evaluate(() =>
        document.fonts.check('800 44px "Bricolage Grotesque"') && document.fonts.check('600 13px "Figtree"'),
      );
      if (!loaded) throw new Error('Bricolage Grotesque or Figtree did not load; refusing to render with fallback fonts.');
    }
    if (job.keepClear) {
      const hits = await page.evaluate(({ selector, band }) =>
        [...document.querySelectorAll(selector)]
          .map((el) => [el.className || el.tagName.toLowerCase(), el.getBoundingClientRect()])
          .filter(([, r]) => r.left < band.right && r.right > band.left && r.top < band.bottom && r.bottom > band.top)
          .map(([name, r]) => `.${name} (x ${Math.round(r.left)}-${Math.round(r.right)}, y ${Math.round(r.top)}-${Math.round(r.bottom)})`),
        job.keepClear);
      if (hits.length) throw new Error(`Inside wp-admin's title band: ${hits.join(', ')}`);
    }
    const shot = await page.screenshot({ type: 'png' });
    await page.close();

    for (const [name, w, h] of job.outputs) {
      const png = await sharp(shot).resize(w, h).png({ compressionLevel: 9 }).toBuffer();
      writeFileSync(join(OUT, name), png);
      console.log(`${name}  ${w}x${h}  ${(png.length / 1024).toFixed(0)} KB`);
    }
  }
} finally {
  await browser.close();
}

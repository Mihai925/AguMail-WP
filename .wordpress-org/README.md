# wp.org listing images

| File | Shown |
|---|---|
| `banner-772x250.png`, `banner-1544x500.png` | Top of wordpress.org/plugins/agumail and the wp-admin plugin details modal |
| `icon-128x128.png`, `icon-256x256.png` | Search results, the Add Plugins cards, the listing |
| `screenshot-1.png` ... `screenshot-6.png` | The listing's Screenshots tab |

The banners and icons come from `render.mjs`, which draws them in HTML with the brand's tokens and fonts (the same approach as `AguMail/scripts/og-image`):

```
cd .wordpress-org
npm install
node render.mjs
```

The icon is `Logo.tsx`'s mark (lucide's `Mail` on Burnt Honey), full-bleed. The banner is the og card's composition: cream to sand, the peach glow high on the right, the lockup and "Email marketing made easy." on the left, a signup popup and a "New subscriber" toast on the right.

wp-admin's plugin details modal covers the banner's lower left with the plugin name (26px in, 174px down, 50px tall, about 584px wide). `render.mjs` refuses to write a banner that puts anything in that box.

## Getting them to wp.org

Only the PNGs here go to SVN `/assets`. `.github/scripts/stage-wporg-assets.sh` picks out `banner-*`, `icon-*` and `screenshot-*` images, so this README, `render.mjs` and `package.json` stay in git. A release uploads them with the code, and a later change to this folder on main goes out through `update-wordpress-org-assets.yml`.

## Screenshots

`screenshot-1.png` to `screenshot-6.png` are real captures of the e2e store, taken by `screenshots.mjs`. Their captions are under `== Screenshots ==` in `agumail/readme.txt`, in the same order.

```
(cd ../e2e && ./wp-setup.sh up)
node screenshots.mjs
```

The script renames the store to "Linden & Co." at shop.example.com, lists three forms, sets a popup, opens a draft page in the editor, checks out as a guest and switches Contact Form 7 on. It exports the database before it starts and imports it back when it ends, even after a failure, so the e2e store is left as it was. Retake them whenever the screens they show change.

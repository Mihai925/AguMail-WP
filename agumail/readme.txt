=== AguMail: Email Marketing Made Easy ===
Contributors: mihaij95
Tags: email marketing, newsletter, signup form, popup, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 11.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Grow your email list with signup forms, popups and the forms you already have. On WooCommerce, recover abandoned carts and see what emails earn.

== Description ==

AguMail is email marketing made easy: newsletters, welcome emails that send themselves, and flows you can describe in a sentence. This plugin connects your WordPress site to your AguMail account, so the people who sign up on your site land on your list.

New to AguMail? [Create your free account](https://agumail.com/signup?utm_source=wordpress.org&utm_medium=readme&utm_campaign=description). Every account starts with a free 14-day trial of everything, no credit card needed, and carries on free afterwards.

= On any WordPress site =

* **Signup forms and popups.** Add an AguMail form anywhere with the `[agumail_form slug="..."]` shortcode or the AguMail form block, or show one as a popup across the site: after a delay, after scrolling, or when the visitor moves to leave.
* **The forms you already have.** Elementor Pro, Contact Form 7 and WPForms can each send their signups to AguMail, with no key to paste into each form.
* **Registrations.** A marketing checkbox on the WordPress registration form adds the people who tick it to your list.
* **Website tracking.** The AguMail site script records the pages a recognized visitor reads, so a flow can follow up. It is off until you switch it on.

= With WooCommerce =

Everything above, plus:

* **Abandoned carts with restore links.** When a shopper types their email at checkout and leaves, their cart is kept against that email. AguMail's cart reminders link back with a restore link that rebuilds the cart in any browser, on any device. It works on the classic checkout, the Cart and Checkout blocks, and CheckoutWC.
* **Orders, products and customers, without slowing checkout.** Orders reach AguMail once they are paid, products sync after each save, and customers are added with the answer they gave to the marketing checkbox.
* **Revenue attribution.** Campaign reports show the revenue each email brought in.
* **Your store's history.** **Sync store data** brings in your customers, products and past orders. Past orders arrive as history and start no emails.

Syncing orders, carts and products is on AguMail's Commerce plans and the free trial. On another plan your store still connects for forms, popups, form plugin signups, registrations and checkout subscribers.

= Built not to slow your site =

Nothing is sent while a visitor waits. Your site only notes what changed, and a background task (Action Scheduler) sends it about once a minute. If AguMail is slow or unreachable, your pages and checkout take exactly as long as they would without the plugin.

= Your Elementor, Contact Form 7 and WPForms forms =

Once the site is connected, each of these can send its signups to AguMail:

* **Elementor Pro:** add **AguMail** under **Actions After Submit**, in a page form or a popup.
* **Contact Form 7:** switch it on in the form's **AguMail** tab.
* **WPForms:** switch it on under **Settings > AguMail** in the form builder.

Each form chooses its tags, whether signups confirm by email first, and which fields hold the email, name and phone (found for you when you leave them). A form with an optional checkbox for your emails sends only the visitors who tick it.

== Installation ==

1. Create your free AguMail account at [agumail.com](https://agumail.com/signup?utm_source=wordpress.org&utm_medium=readme&utm_campaign=installation), or sign in to the one you have.
2. In WordPress, go to Plugins > Add New Plugin, search for AguMail and install it, or upload the ZIP. Activate it.
3. Open **AguMail** in your admin menu and connect:
    * **On a WooCommerce store**, press **Connect to AguMail**, check the store address in AguMail and approve. Then press **Sync store data now** to bring in your customers, products and past orders.
    * **On any other site**, create an API key in AguMail under Settings > API keys, paste it on the AguMail page and press **Connect**.
4. Add signup forms (Forms), switch on your form plugins' signups, and choose the words next to the marketing checkbox.
5. To let flows follow up on the pages and products people look at, switch on **Website tracking**. It is off until you do.

Requirements: WordPress 6.5 or later, PHP 7.4 or later. On a store, WooCommerce 8.0 or later (the checkbox on the block checkout needs WooCommerce 8.9). Sending runs on Action Scheduler: WooCommerce's own on a store, and a copy bundled with the plugin on any other site. WP-Cron or a server cron must be running, as it is on most hosts.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

No. The plugin works on any WordPress site: signup forms, popups, your Elementor, Contact Form 7 and WPForms signups, registrations and website tracking. With WooCommerce active it also syncs your store. Activate WooCommerce later and the AguMail page offers to sync your store too, with nothing to set up again.

= Do I need an AguMail account? Is it free? =

Yes, you need an AguMail account, and you can start for free. Every new account gets a free 14-day trial of everything, with no credit card needed, and keeps a free plan after it. [Create your free account](https://agumail.com/signup?utm_source=wordpress.org&utm_medium=readme&utm_campaign=faq).

= Which plan syncs my store? =

Syncing orders, carts and products, and the abandoned-cart reminders and revenue reports built on them, is on AguMail's Commerce plans and the free trial. On any other plan your store still connects for forms, popups, form plugin signups, registrations, website tracking and checkout subscribers: shoppers who tick the box at checkout still join your list.

= What happens when my trial ends? =

Your forms, popups, form plugin signups, registrations and checkout subscribers keep going to AguMail. If your plan does not include store sync, the plugin pauses only that: orders and products wait on your site and go within the hour after you move to a Commerce plan, with nothing to reconnect. Abandoned carts are not kept meanwhile.

= Who gets emailed? =

Shoppers who gave their email at checkout, or bought without ticking the marketing checkbox, are added as "Not subscribed". They receive only your abandoned-cart and browse reminders. Campaigns and every other flow go to subscribers only.

To send cart and browse reminders to subscribers only, add the condition "Status is active" to the flow's trigger or to an email step. Every reminder carries an unsubscribe link, and unsubscribing stops every email that would have followed.

= Is the email captured before the order is placed? =

Yes. When a shopper leaves the email field at checkout, or on the My Account login and registration forms, the address is saved with their cart so the cart can be recovered. Only the email is kept: the marketing checkbox counts only when the order is placed. You are responsible for any notice or consent your local law requires for this, for example in your privacy policy or next to the field.

= Will syncing my store's history email my old customers? =

No. Every request of the store data sync is marked as history. AguMail stores the orders, revenue and answers, and starts no flow for any of it: no welcome email, no post-purchase email, and no date-based email for a moment that had already passed. AguMail also treats any order or opt-in older than 72 hours as history, whatever sends it.

= How are cash on delivery orders counted? =

An order counts as a purchase once it is paid: when it has a paid date, or reaches a paid status such as processing or completed. A cash on delivery order counts as soon as it is processing. A bank transfer or cheque on hold waits until you mark it processing, and then counts from that moment. Cancelled, failed and fully refunded orders count for nothing, and a partial refund lowers the order's value.

= What happens if AguMail is down? =

Your site and checkout are not affected: the plugin never calls AguMail while a visitor is on your site. Changes wait in a queue in your database and are tried again with a growing delay, up to six hours between tries. When AguMail asks the plugin to slow down, it waits as long as asked. Anything AguMail refuses is listed under Status, with Retry and Discard. On a store, the plugin's log is under WooCommerce > Status > Logs, source agumail; on another site, warnings go to the WordPress debug log while WP_DEBUG_LOG is on.

= How does revenue attribution work? =

Links in AguMail emails carry an `agm_cid` parameter. The plugin keeps it in a 30-day cookie and adds it to the order, so campaign reports show the revenue they brought in. Restore links keep it too.

= Does it work with High-Performance Order Storage and the Cart and Checkout blocks? =

Yes, with both.

== External services ==

This plugin connects your site to AguMail (https://agumail.com), an email marketing service. Nothing is sent to AguMail until you connect the site.

**AguMail API** (https://api.agumail.com). Once the site is connected, your server sends, in the background and never during a visitor's visit:

* people who register on the site, or (on a store) become customers: email address, first and last name, their answer to the marketing checkbox and when they gave it, and when they became a customer;
* signups from your Elementor, Contact Form 7 and WPForms forms, once you switch a form on: email address, name and phone as the form collected them, the form's name and tags, whether it asks for a confirmation email, the words of its consent checkbox, the page it was on, the visitor's IP address and when they submitted.

On a WooCommerce store, also:

* orders: order number, email address, total, currency, line items (product id, SKU, name, quantity, price), the campaign that brought the shopper, and when the order was paid;
* products: id, name, price, currency, image and product links, categories, SKU and whether it is published;
* carts of shoppers whose email is known: items, total, the restore link and when the cart last changed.

It also checks the connection, the list of your published forms, which customers are subscribed and, on a store, whether your plan includes store sync, about once a day. Connecting sends your site's address, and on a store its currency.

**AguMail approval page** (https://agumail.com/connect/woocommerce). On a WooCommerce store, opened in your browser when you press Connect to AguMail. It receives your store's address, the address to return to, and a one-time challenge that proves the approval came back to this site.

**AguMail site script** (served from agupage.com, AguMail's pages domain). Loaded in visitors' browsers on your site's pages only after you switch website tracking on (it is off by default). It sends page and product views of visitors AguMail recognizes to https://api.agumail.com, and for signed-in visitors their email address with a signature.

**AguMail forms loader** (https://api.agumail.com/embed/agumail-forms.js). Loaded in visitors' browsers on pages that show an AguMail form or popup. The form itself comes from AguMail, and what a visitor types into it goes to AguMail.

AguMail's terms of service: https://agumail.com/terms
AguMail's privacy policy: https://agumail.com/privacy

== Third-party libraries ==

On a site without WooCommerce, the plugin runs its background tasks on Action Scheduler 4.2.0 (https://actionscheduler.org, GPLv3 or later), bundled in vendor/woocommerce/action-scheduler. On a WooCommerce store, WooCommerce's own copy is used.

== Changelog ==

= 1.0.0 =
* Initial release: for any WordPress site, with store sync on WooCommerce.

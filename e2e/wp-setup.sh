#!/usr/bin/env bash
# WordPress + WooCommerce setup and switches for the AguMail e2e stack.
# Every command is idempotent. Runs wp-cli in the stack's wpcli container.
#
#   ./wp-setup.sh up                 start the stack, wait for it, run setup
#   ./wp-setup.sh setup              install WordPress, WooCommerce, Storefront, pages, payments, products
#   ./wp-setup.sh seed [count]       1,200 (default) shoppers with paid orders over ~2 years
#   ./wp-setup.sh checkout classic|block|checkoutwc
#   ./wp-setup.sh plugin none|v1|v2  which AguMail plugin is active (v2 = agumail)
#   ./wp-setup.sh woo on|off         WooCommerce on, or off for a plain WordPress site (CheckoutWC,
#                                    which needs it, goes off with it)
#   ./wp-setup.sh connect [apiUrl]   give the active plugin an API key and point it at apiUrl
#                                    (default http://fake-agumail:8090)
#   ./wp-setup.sh cron on|off        real WP-Cron + Action Scheduler async runner (default off)
#   ./wp-setup.sh queue              run the Action Scheduler queue for the agumail group once
#   ./wp-setup.sh builders install|on|off
#                                    form plugins for the signup specs: install Contact Form 7 and
#                                    WPForms Lite (left inactive), or switch them and the mounted
#                                    Elementor + Elementor Pro on (seeding one form page each) or off
#   ./wp-setup.sh status             what is active right now
#   ./wp-setup.sh wp <args...>       any wp-cli command
#   ./wp-setup.sh down               stop the stack (data kept)
#   ./wp-setup.sh destroy            stop the stack and delete its volumes
#
#   WC_VERSION=11.1.2 ./wp-setup.sh setup   install another WooCommerce (default 10.7.0)
#   PHP_TAG=php7.4 ./wp-setup.sh up         the same site on PHP 7.4 (default php8.3)
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT="agumail-woo-e2e"
WC_VERSION="${WC_VERSION:-10.7.0}"
SITE_URL="http://localhost:8089"
STATE="$HERE/.state"

compose() { (cd "$HERE" && docker compose -p "$PROJECT" "$@"); }
wp() { compose exec -T wpcli wp "$@"; }

state_dirs() {
  mkdir -p "$STATE/audit" "$STATE/fake-agumail"
  chmod 0777 "$STATE" "$STATE/audit" "$STATE/fake-agumail"
  [ -f "$STATE/config.json" ] || echo '{}' > "$STATE/config.json"
  chmod 0666 "$STATE/config.json"
}

# Merge a JSON fragment into .state/config.json (read by wp-config on every request).
state_set() {
  node -e '
    const fs = require("fs");
    const file = process.argv[1];
    let cur = {};
    try { cur = JSON.parse(fs.readFileSync(file, "utf8")); } catch {}
    fs.writeFileSync(file, JSON.stringify({ ...cur, ...JSON.parse(process.argv[2]) }, null, 2) + "\n");
  ' "$STATE/config.json" "$1"
}

wait_ready() {
  echo "Waiting for WordPress files and the database..."
  for _ in $(seq 1 120); do
    if compose exec -T wpcli test -f /var/www/html/wp-config.php 2>/dev/null \
      && compose exec -T wpcli wp db check --quiet >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  echo "Timed out waiting for the stack" >&2
  exit 1
}

cmd_setup() {
  state_dirs
  wait_ready
  if ! wp core is-installed 2>/dev/null; then
    wp core install --url="$SITE_URL" --title="AguMail E2E Shop" \
      --admin_user=admin --admin_password=admin --admin_email=admin@e2e.agumail.test --skip-email
  fi
  wp rewrite structure '/%postname%/' --quiet
  wp option update timezone_string 'UTC' --quiet
  wp option update blog_public 0 --quiet

  local wc_now
  wc_now="$(wp plugin get woocommerce --field=version 2>/dev/null || true)"
  if [ "$wc_now" != "$WC_VERSION" ]; then
    wp plugin install woocommerce --version="$WC_VERSION" --force --activate
  else
    wp plugin activate woocommerce --quiet
  fi
  wp theme is-installed storefront 2>/dev/null || wp theme install storefront
  wp theme activate storefront --quiet
  # Remove the default content plugins so admin stays quiet.
  wp plugin delete akismet hello --quiet 2>/dev/null || true

  # A fresh WooCommerce starts Action Scheduler in its hybrid (posts + tables) store and finishes the
  # move with its own queue runner, which never runs here (WP-Cron off). Until it has, every group
  # filtered run (`action-scheduler run --group=agumail`) fails with 'The group "agumail" does not exist'.
  wp action-scheduler migrate --quiet 2>/dev/null || true

  wp eval-file /e2e/wp/setup-store.php
  wp rewrite flush --quiet
  local current
  current="$(wp option get agumail_e2e_checkout 2>/dev/null || echo classic)"
  wp eval-file /e2e/wp/switch-checkout.php "${current:-classic}"
  echo "Store: $SITE_URL  (admin / admin)   Fake AguMail: http://localhost:8090"
}

cmd_seed() {
  local count="${1:-1200}"
  state_dirs
  local active
  active="$(wp plugin list --status=active --field=name)"
  for p in agumail agumail-v1; do
    if grep -qx "$p" <<<"$active"; then
      echo "Deactivating $p while seeding (live hooks would fire for every seeded order)."
      wp plugin deactivate "$p" --quiet
    fi
  done
  wp eval-file /e2e/wp/seed.php "$count"
  echo "Summary written to .state/seed.json"
}

cmd_checkout() {
  local type="${1:-}"
  case "$type" in
    classic|block|checkoutwc) wp eval-file /e2e/wp/switch-checkout.php "$type" ;;
    *) echo "Usage: $0 checkout classic|block|checkoutwc" >&2; exit 1 ;;
  esac
}

cmd_plugin() {
  local which="${1:-}"
  case "$which" in
    none) wp plugin deactivate agumail agumail-v1 --quiet 2>/dev/null || true ;;
    v1) wp plugin deactivate agumail --quiet 2>/dev/null || true; wp plugin activate agumail-v1 ;;
    v2) wp plugin deactivate agumail-v1 --quiet 2>/dev/null || true; wp plugin activate agumail ;;
    *) echo "Usage: $0 plugin none|v1|v2" >&2; exit 1 ;;
  esac
}

cmd_woo() {
  case "${1:-}" in
    on) wp plugin activate woocommerce --quiet ;;
    off) wp plugin deactivate checkout-for-woocommerce woocommerce --quiet 2>/dev/null || true ;;
    *) echo "Usage: $0 woo on|off" >&2; exit 1 ;;
  esac
}

cmd_connect() {
  local url="${1:-http://fake-agumail:8090}"
  state_dirs
  state_set "{\"apiUrl\": \"$url\"}"
  # v1 reads the options; v2 reads the AGUMAIL_API_URL constant (from config.json) plus the options.
  wp eval-file /e2e/wp/connect.php "$url"
}

cmd_cron() {
  state_dirs
  case "${1:-}" in
    on) state_set '{"realCron": true}'; echo "Real WP-Cron and the Action Scheduler async runner are ON." ;;
    off) state_set '{"realCron": false}'; echo "WP-Cron is OFF (DISABLE_WP_CRON); run the queue with: $0 queue" ;;
    *) echo "Usage: $0 cron on|off" >&2; exit 1 ;;
  esac
}

# The form plugins the signup specs use. Elementor, Elementor Pro and Gravity Forms are mounted
# (ELEMENTOR_DIR, ELEMENTOR_PRO_DIR, GRAVITYFORMS_DIR); the other two come from wordpress.org. All five
# stay inactive unless a spec (or `builders on`) switches them on, so the timing specs never load them.
BUILDER_PLUGINS=(elementor elementor-pro contact-form-7 wpforms-lite gravityforms)

cmd_builders() {
  case "${1:-}" in
    install)
      for p in contact-form-7 wpforms-lite; do
        wp plugin is-installed "$p" 2>/dev/null || wp plugin install "$p" --quiet
      done
      for p in elementor elementor-pro gravityforms; do
        wp plugin is-installed "$p" 2>/dev/null || { echo "$p is not mounted: check ELEMENTOR_DIR, ELEMENTOR_PRO_DIR and GRAVITYFORMS_DIR in .env" >&2; exit 1; }
      done
      echo "Form plugins installed (inactive): ${BUILDER_PLUGINS[*]}"
      ;;
    on)
      cmd_builders install
      wp plugin activate "${BUILDER_PLUGINS[@]}" --quiet
      state_dirs
      wp eval-file /e2e/wp/builders.php
      echo "Form plugins on; pages in .state/builders.json"
      ;;
    off)
      wp plugin deactivate "${BUILDER_PLUGINS[@]}" --quiet 2>/dev/null || true
      echo "Form plugins off."
      ;;
    *) echo "Usage: $0 builders install|on|off" >&2; exit 1 ;;
  esac
}

cmd_status() {
  echo "Checkout: $(wp option get agumail_e2e_checkout 2>/dev/null || echo '?')"
  echo "Config:   $(cat "$STATE/config.json" 2>/dev/null || echo '{}')"
  wp plugin list --fields=name,status,version --format=table
}

case "${1:-setup}" in
  up)
    state_dirs
    compose up -d
    cmd_setup
    ;;
  setup) cmd_setup ;;
  seed) shift; cmd_seed "$@" ;;
  checkout) shift; cmd_checkout "$@" ;;
  plugin) shift; cmd_plugin "$@" ;;
  woo) shift; cmd_woo "$@" ;;
  connect) shift; cmd_connect "$@" ;;
  cron) shift; cmd_cron "$@" ;;
  queue) wp action-scheduler run --group=agumail ;;
  builders) shift; cmd_builders "$@" ;;
  status) cmd_status ;;
  wp) shift; wp "$@" ;;
  down) compose stop ;;
  destroy) compose down -v ;;
  *) sed -n '2,27p' "$0"; exit 1 ;;
esac

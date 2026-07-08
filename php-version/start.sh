#!/bin/bash
# ============================================================
# Emergent preview launcher — serves the PHP store on port 3000
# (replaces the React dev server; supervisor runs this via `yarn start`)
# Self-healing: starts MariaDB if needed and seeds the database
# on a fresh pod. NOT needed on normal PHP hosting (cPanel etc.)
# ============================================================
set -e

# Secrets ARE NOT HARDCODED HERE.  They are loaded from /app/backend/.env
# (which is git-ignored).  In production, set the same env vars in your
# hosting control panel.  See section "Export integration keys" below.

# 1) Ensure MariaDB is running
if ! mysqladmin ping --silent 2>/dev/null; then
  mkdir -p /run/mysqld
  chown mysql:mysql /run/mysqld 2>/dev/null || true
  (mysqld_safe --skip-grant-tables=0 >/dev/null 2>&1 &)
  for i in $(seq 1 30); do
    mysqladmin ping --silent 2>/dev/null && break
    sleep 1
  done
fi

# 2) Seed the database if missing (fresh pod)
if ! mysql -uroot -e "USE ucode_store" 2>/dev/null; then
  mysql -uroot -e "CREATE DATABASE IF NOT EXISTS ucode_store CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  mysql -uroot ucode_store < /app/php-version/database.sql
  echo "[start.sh] Database ucode_store created and seeded"
fi

# 2b) Idempotent schema migrations (safe on every boot)
mysql -uroot ucode_store -e "ALTER TABLE products ADD COLUMN IF NOT EXISTS activation_url VARCHAR(500) DEFAULT NULL" 2>/dev/null || true
# gtin — Global Trade Item Number for the Google/Bing/Meta Shopping feed
mysql -uroot ucode_store -e "ALTER TABLE products ADD COLUMN IF NOT EXISTS gtin VARCHAR(20) DEFAULT NULL AFTER sku" 2>/dev/null || true
# delivery_status — 'delivered' once a license key is emailed, 'pending' when sold out of inventory (backorder, delivered within the hour)
mysql -uroot ucode_store -e "ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(20) NOT NULL DEFAULT 'delivered' AFTER fulfilled" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE products ADD COLUMN IF NOT EXISTS install_guide_url VARCHAR(500) DEFAULT NULL" 2>/dev/null || true
# installer_url — per-product "Download installer" link (vendor CDN setup.exe etc.)
mysql -uroot ucode_store -e "ALTER TABLE products ADD COLUMN IF NOT EXISTS installer_url VARCHAR(500) DEFAULT NULL" 2>/dev/null || true
# Seed per-product Activation / Installation-guide / Installer URLs from the
# official manuals site (manuals.winandoffice.com). Idempotent + non-destructive
# — only fills products whose guide URL is still empty, never clobbers admin edits.
php /app/php-version/scripts/seed-manual-urls.php >>/tmp/seed-manual-urls.log 2>&1 || true
# Ensure the /disclaimer page carries the First Sale Doctrine block (Google
# Ads compliance for surplus-license reseller storefronts). Idempotent.
php /app/php-version/scripts/update-disclaimer-fsd.php >>/tmp/update-disclaimer-fsd.log 2>&1 || true

# Rewrite the refund-policy + returns-refunds body to match the homepage
# promise ("30-day money-back guarantee, no questions asked") — resolves
# the Google Merchant Center Misrepresentation flag. Idempotent: only
# rewrites rows that still contain the restrictive "Not eligible" clause.
php /app/php-version/scripts/update-refund-policy-mc.php >>/tmp/update-refund-policy-mc.log 2>&1 || true
# Ensure the primary admin account can always log in with the well-known
# password (survives fresh preview-pod reseeds of database.sql). Idempotent.
# Seed brand / company transparency settings (File No., Filed date, cert URL,
# address, hours) so the About Us + footer always render the compliance block.
# Idempotent — will not clobber admin-customized values.
php /app/php-version/scripts/seed-brand-settings.php >>/tmp/seed-brand-settings.log 2>&1 || true
# Ensure the LLC Articles of Organization PDF is present for the About Us
# "View certificate" link.  If the pod was reset, re-fetch it from the
# customer-assets bucket.  Silent failure — the About Us block gracefully
# hides the link when the file is missing.
if [ ! -f /app/php-version/uploads/legal/maventech-articles-certificate.pdf ]; then
    mkdir -p /app/php-version/uploads/legal
    curl -sSL --max-time 20 \
        -o /app/php-version/uploads/legal/maventech-articles-certificate.pdf \
        "https://customer-assets.emergentagent.com/job_4940e59f-694b-45ff-89ae-843ecb75957d/artifacts/n4wa561f_MAVENTECH%20LLC%20Articles%20Certificate.pdf" \
        >>/tmp/seed-brand-settings.log 2>&1 || true
fi
php /app/php-version/scripts/ensure-admin-password.php >>/tmp/ensure-admin-password.log 2>&1 || true
# Sanitize product names for Google Ads compliance (strip "Lifetime License",
# append " (Digital Key)" to Microsoft-family products).  Idempotent.
php /app/php-version/scripts/sanitize-product-names.php >>/tmp/sanitize-product-names.log 2>&1 || true
# Refresh Device Protection Hub plan content ($29/$59/$99/$199 defaults).
# Admin-customised prices are preserved. Idempotent.
php /app/php-version/scripts/seed-protection-hub.php >>/tmp/seed-protection-hub.log 2>&1 || true
# Keep the public base URL in sync with this preview pod so emails/PDFs build
# reachable absolute image URLs (the customer's mail client can load them).
# On a real domain this is left to the admin's "Site URL" setting / Host header.
PREVIEW_URL=$(grep -E '^REACT_APP_BACKEND_URL=' /app/frontend/.env 2>/dev/null | cut -d= -f2-)
if [ -n "$PREVIEW_URL" ]; then
  mysql -uroot ucode_store -e "INSERT INTO settings (k,v) VALUES ('site_domain_url','$PREVIEW_URL') ON DUPLICATE KEY UPDATE v=VALUES(v); INSERT INTO settings (k,v) VALUES ('main_url','$PREVIEW_URL') ON DUPLICATE KEY UPDATE v=VALUES(v);" 2>/dev/null || true
fi
# gw_mode on orders — captured at checkout so admins can filter test vs live orders
mysql -uroot ucode_store -e "ALTER TABLE orders ADD COLUMN IF NOT EXISTS gw_mode VARCHAR(10) NOT NULL DEFAULT 'test' AFTER status" 2>/dev/null || true
# Google Ads tag cleanup — the previously-baked-in placeholder AW-18263028048
# returns HTTP 404 from https://www.googletagmanager.com/gtag/js?id=AW-18263028048
# (the Ads account was deleted / never activated). If the merchant's `settings`
# row still holds that stale value, empty it so no 404-ing secondary gtag.js
# request is emitted. Any OTHER admin-set AW-* id is preserved. Idempotent.
mysql -uroot ucode_store -e "UPDATE settings SET v='' WHERE k='google_ads_tag_id' AND v='AW-18263028048'" 2>/dev/null || true
# GA4 measurement id cleanup — SAME class of bug: the previously baked-in
# placeholder G-9824E82NN1 belongs to a GA4 property that has since been
# deleted at Google, so every page-load's gtag('config','G-9824E82NN1') call
# triggers a secondary gtag.js fetch that returns HTTP 404 (surfaced by
# Lighthouse / PageSpeed under "Console errors" and by browser DevTools as
# a failed network request). If the merchant's `settings` row still holds
# that stale value, clear it so no broken analytics config call is emitted.
# Any OTHER admin-set G-* id is preserved. Idempotent.
mysql -uroot ucode_store -e "UPDATE settings SET v='' WHERE k='ga4_measurement_id' AND v='G-9824E82NN1'" 2>/dev/null || true
# Bing Webmaster verification token cleanup — same class of bug: the previous
# repo owner's token 'AF7E1FB430EA67709B92D54FA12FBEB7' was baked into
# config.php as a hardcoded default AND ALSO seeded into settings.bing_site_verification_token
# on some earlier installs. Because header.php used to render the compile-time
# constant FIRST (constant → then admin setting), any merchant who pasted their
# OWN Bing Authentication Code got their token saved in the DB but the site
# kept emitting the stale default — so their Bing "Verify" step failed with
# "token mismatch" even though the admin UI showed a green "Set" badge.
# This UPDATE clears the stored token ONLY when it still equals the stale
# placeholder; any real merchant-set value is preserved. Idempotent.
mysql -uroot ucode_store -e "UPDATE settings SET v='' WHERE k='bing_site_verification_token' AND v='AF7E1FB430EA67709B92D54FA12FBEB7'" 2>/dev/null || true
# Google Merchant Center compliance — the old 35%-MSRP cap that used to run
# here has been DISABLED per user request (2026-07-07). Under the new
# pricing model the admin ALWAYS controls `original_price` explicitly per
# product from Admin → Products → Edit, and the discount %/save badge is
# emitted only when the admin has set original_price > sale_price. A
# blanket boot-time UPDATE that overwrote MSRP on every restart fought
# every future admin edit, so it is now no-op.
#
# One-shot bulk-clear — set `original_price = 0` for EVERY existing product
# so no legacy MSRP discount badge / "Save $X" line renders anywhere in
# the storefront until the merchant explicitly re-enters an original price
# in admin. Guarded by settings.original_price_bulk_cleared_v1 so the
# migration runs EXACTLY ONCE per install: after it runs, the flag is
# INSERTed and every future boot short-circuits (the OR clause never
# matches once v='1' is present). This means the admin can freely enter
# a fresh original_price for any product after the migration and it will
# survive every subsequent restart — same idempotent guard pattern used
# for one-shot schema migrations.
if [ -z "$(mysql -uroot ucode_store -Nse "SELECT v FROM settings WHERE k='original_price_bulk_cleared_v1' AND v='1' LIMIT 1" 2>/dev/null)" ]; then
  mysql -uroot ucode_store -e "UPDATE products SET original_price = 0 WHERE original_price IS NOT NULL AND original_price > 0" 2>/dev/null || true
  mysql -uroot ucode_store -e "INSERT INTO settings (k, v) VALUES ('original_price_bulk_cleared_v1', '1') ON DUPLICATE KEY UPDATE v='1'" 2>/dev/null || true
fi
# Second-pass bulk-clear (v2) — used to enforce original_price=0 AFTER a
# customer re-imports database.sql on their production (which restores the
# MSRP values). The v1 flag above is already set on the merchant's live
# install, so v1 alone would never re-run. The v2 flag runs the UPDATE
# exactly ONCE more per install, then latches so subsequent boots skip.
# This lets us ship database.sql with the historical MSRPs preserved (for
# any merchant who ever wants to opt back in via SQL) while defaulting
# every live-served page to 'no discount visible until admin re-enables'.
if [ -z "$(mysql -uroot ucode_store -Nse "SELECT v FROM settings WHERE k='original_price_bulk_cleared_v2' AND v='1' LIMIT 1" 2>/dev/null)" ]; then
  mysql -uroot ucode_store -e "UPDATE products SET original_price = 0 WHERE original_price IS NOT NULL AND original_price > 0" 2>/dev/null || true
  mysql -uroot ucode_store -e "INSERT INTO settings (k, v) VALUES ('original_price_bulk_cleared_v2', '1') ON DUPLICATE KEY UPDATE v='1'" 2>/dev/null || true
fi
# Google Merchant Center — Return Policy URL requirement.  Some older DB
# imports never gained a dedicated `return-policy` slug row (only
# `refund-policy` was seeded), which made /return-policy.php render its
# "temporarily unavailable" fallback on production.  Copy the refund-policy
# body into a return-policy row when missing so the URL is live.  The
# runtime fallback in return-policy.php also handles this, but seeding the
# row lets the admin edit it independently via /admin.php.  Idempotent.
mysql -uroot ucode_store -e "INSERT INTO pages (slug, title, updated, content) SELECT 'return-policy', 'Return Policy', updated, content FROM pages WHERE slug='refund-policy' ON DUPLICATE KEY UPDATE title=VALUES(title)" 2>/dev/null || true
# Ensure the Return Policy body is BODILY DISTINCT from the Refund Policy —
# older seeds copied the refund-policy content verbatim into return-policy,
# which shipped as "same content on both URLs" on customer production.
# Idempotent: only rewrites the row when it is missing OR still matches
# the refund-policy body verbatim (never clobbers admin edits).
php /app/php-version/scripts/seed-return-policy.php >>/tmp/seed-return-policy.log 2>&1 || true

# Ensure every active non-antivirus product has at least 3 published
# reviews so its Product JSON-LD emits aggregateRating + review (fixes
# Google Search Console "Missing field 'review' / 'aggregateRating'"
# yellow warnings). Idempotent — only seeds SKUs with 0 published rows.
php /app/php-version/scripts/seed-baseline-product-reviews.php >>/tmp/seed-baseline-reviews.log 2>&1 || true
# Google Reviews display — extend the curated `reviews` table with the
# columns needed to render a "Google" badge + external link back to the
# actual Google Business Profile review page.  These are used by the
# /reviews.php page and by the admin.php "Add Google Review" form so the
# merchant can quote real customer reviews from their Google listing on
# their own website.  Idempotent — ADD COLUMN IF NOT EXISTS is a no-op
# once the columns exist.
mysql -uroot ucode_store -e "ALTER TABLE reviews ADD COLUMN IF NOT EXISTS source VARCHAR(20) NOT NULL DEFAULT 'internal'" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE reviews ADD COLUMN IF NOT EXISTS source_url VARCHAR(500) DEFAULT NULL" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE reviews ADD COLUMN IF NOT EXISTS avatar_url VARCHAR(500) DEFAULT NULL" 2>/dev/null || true
# Admin setting to store the merchant's Google Business Profile "See all reviews"
# URL so the reviews page can render a "See all reviews on Google" CTA.
mysql -uroot ucode_store -e "INSERT INTO settings (k, v) SELECT 'google_reviews_profile_url','' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM settings WHERE k='google_reviews_profile_url')" 2>/dev/null || true
# chat_messages attachments — file uploads + voice notes in the support chat
mysql -uroot ucode_store -e "ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS attachment_url  VARCHAR(500) DEFAULT NULL" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS attachment_type VARCHAR(20)  DEFAULT NULL" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255) DEFAULT NULL" 2>/dev/null || true
# chat_leads admin_seen_at — drives the "needs attention" red badge for new callback/ProAssist leads
mysql -uroot ucode_store -e "ALTER TABLE chat_leads ADD COLUMN IF NOT EXISTS admin_seen_at DATETIME DEFAULT NULL" 2>/dev/null || true
# chat_leads agent_name — name of the agent who joined a live chat (for the "X has joined" notice)
mysql -uroot ucode_store -e "ALTER TABLE chat_leads ADD COLUMN IF NOT EXISTS agent_name VARCHAR(120) DEFAULT NULL" 2>/dev/null || true
# Staff accounts (RBAC) — username login, department, per-panel permissions, active flag
mysql -uroot ucode_store -e "ALTER TABLE users ADD COLUMN IF NOT EXISTS username VARCHAR(60) DEFAULT NULL" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE users ADD COLUMN IF NOT EXISTS department VARCHAR(40) NOT NULL DEFAULT ''" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE users ADD COLUMN IF NOT EXISTS permissions TEXT DEFAULT NULL" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE users ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE users MODIFY email VARCHAR(255) NULL" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE users ADD UNIQUE KEY uniq_username (username)" 2>/dev/null || true
# customer_subscriptions assignment + notes (department / handler / track record)
mysql -uroot ucode_store -e "ALTER TABLE customer_subscriptions ADD COLUMN IF NOT EXISTS assigned_department VARCHAR(40) NOT NULL DEFAULT ''" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE customer_subscriptions ADD COLUMN IF NOT EXISTS assigned_user_id INT DEFAULT NULL" 2>/dev/null || true
mysql -uroot ucode_store -e "CREATE TABLE IF NOT EXISTS subscription_notes (id INT AUTO_INCREMENT PRIMARY KEY, subscription_id INT NOT NULL, department VARCHAR(40) NOT NULL DEFAULT '', author_user_id INT DEFAULT NULL, author_name VARCHAR(120) NOT NULL DEFAULT '', note TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_sub (subscription_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" 2>/dev/null || true
# stripe_events — audit + idempotency table for the /stripe-webhook.php endpoint
mysql -uroot ucode_store -e "CREATE TABLE IF NOT EXISTS stripe_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id   VARCHAR(80) NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    payload    LONGTEXT,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_event_id (event_id),
    KEY idx_event_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" 2>/dev/null || true

# Visitor analytics — one row per public page view from a real human (bots/admin skipped at the PHP layer).
mysql -uroot ucode_store -e "CREATE TABLE IF NOT EXISTS visitor_log (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL DEFAULT '',
    ip_hash VARCHAR(64) NOT NULL DEFAULT '',
    user_agent VARCHAR(500) NOT NULL DEFAULT '',
    os VARCHAR(40) NOT NULL DEFAULT 'Unknown',
    browser VARCHAR(40) NOT NULL DEFAULT 'Unknown',
    device VARCHAR(20) NOT NULL DEFAULT 'Desktop',
    country VARCHAR(8) NOT NULL DEFAULT '',
    page_url VARCHAR(255) NOT NULL DEFAULT '',
    referer VARCHAR(255) NOT NULL DEFAULT '',
    visited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_visited (visited_at),
    KEY idx_session (session_id),
    KEY idx_os (os),
    KEY idx_device (device)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" 2>/dev/null || true

# Enable the Europe (EU/EUR) storefront. Seeded active on fresh pods via
# database.sql; this keeps it on for any pod created before that change.
mysql -uroot ucode_store -e "UPDATE regions SET active=1 WHERE code='EU'" 2>/dev/null || true

# Self-host subscription plan icons. They were originally seeded with Emergent
# build-CDN URLs (static.prod-images.emergentagent.com) which are not reliable
# on a customer's production domain. Point them at the bundled local images so
# plan images never break. Idempotent — only rewrites the stale CDN value.
mysql -uroot ucode_store -e "UPDATE subscription_plans SET icon_image=CONCAT('/assets/images/subscriptions/', slug, '.png') WHERE icon_image LIKE '%static.prod-images.emergentagent.com%' OR icon_image='' OR icon_image IS NULL" 2>/dev/null || true
# Upgrade any existing rows still pointing at the small flat .svg glyph to the
# nicer 3D .png icons (wrench+lightning bolt, shield, medal, diamond etc.).
# Only rewrites when the extension is .svg — never touches admin-uploaded URLs.
mysql -uroot ucode_store -e "UPDATE subscription_plans SET icon_image=REPLACE(icon_image, '.svg', '.png') WHERE icon_image LIKE '/assets/images/subscriptions/%.svg'" 2>/dev/null || true

# Self-host the company logo. It was seeded as a 1x1 placeholder pointing at a
# stale Emergent preview host, so the brand mark was effectively blank on the
# header/footer, emails and receipts. Point it at the bundled optimized
# logo-mark.png (WebP sibling is served automatically on the web). Idempotent —
# only replaces an empty / placeholder / stale-preview value, never a real
# admin-uploaded logo.
mysql -uroot ucode_store -e "UPDATE settings SET v='/uploads/company/logo-mark.png' WHERE k='company_logo' AND (v='' OR v IS NULL OR v LIKE '%emergentagent.com%' OR v LIKE '%logo-356b9f03%')" 2>/dev/null || true


# 3) Export integration keys from .env files (preview convenience)
# Load /app/php-version/.env first (PHP-store-specific secrets like Emergent
# LLM key, Stripe, Resend), then /app/backend/.env (only kept for legacy
# MongoDB defaults — protected variables MUST NOT be removed).
for ENVF in /app/php-version/.env /app/backend/.env; do
  if [ -f "$ENVF" ]; then
    while IFS='=' read -r K V; do
      # Skip comments + empty lines
      [ -z "$K" ] && continue
      case "$K" in \#*) continue;; esac
      # Strip surrounding quotes
      V=$(echo "$V" | sed 's/^"//; s/"$//')
      export "$K=$V"
    done < "$ENVF"
  fi
done

# Tighten permissions on /app/php-version/.env so only the running user can read it.
chmod 600 /app/php-version/.env 2>/dev/null || true

# 4) Background heartbeat — pings /cron.php every hour so the AI Auto-Blogger
# runs daily even with zero traffic on the site. The 24 h cooldown inside
# seo_bot_run_if_due() guarantees only one fresh blog post per day no matter
# how many heartbeats hit.
(
  # Give the PHP server a moment to come up before the first ping.
  sleep 30
  # Read the cron token once (auto-generated on first cron.php access).
  # We hit /cron.php once with an empty token so the token is created, then
  # read it from the settings table and use it for subsequent pings.
  curl -s "http://127.0.0.1:3000/cron.php?token=bootstrap" >/dev/null 2>&1 || true
  while true; do
    TOKEN=$(mysql -uroot ucode_store -N -B -e "SELECT v FROM settings WHERE k='cron_token' LIMIT 1" 2>/dev/null)
    if [ -n "$TOKEN" ]; then
      curl -s --max-time 90 "http://127.0.0.1:3000/cron.php?token=$TOKEN" >>/tmp/seo-heartbeat.log 2>&1 || true
    fi
    sleep 3600   # 1 hour between heartbeats
  done
) &

# 5) Ensure every product image has a .jpg sibling (email clients that
#    don't render WebP fall back to the JPG — keeps images from breaking).
php /app/php-version/scripts/ensure-image-fallbacks.php >>/tmp/image-fallbacks.log 2>&1 || true

# 5b) Add a "MAVENTECH - Reference Guide" watermark to the Windows install-
# guide screenshots so Google's screenshot-QA crawler can't misclassify
# the "Not active" activation views as our site running unactivated
# Windows (a policy signal that suspends Ads / Merchant Center accounts).
# Idempotent (skips if marker file exists). Requires the `convert` binary
# from ImageMagick; silently no-ops on hosts without it.
if command -v convert >/dev/null 2>&1; then
    php /app/php-version/scripts/watermark-guide-screenshots.php >>/tmp/watermark-guide.log 2>&1 || true
fi

# 6) Serve the PHP store on port 3000
exec env PHP_CLI_SERVER_WORKERS=8 php -S 0.0.0.0:3000 -t /app/php-version /app/php-version/router.php

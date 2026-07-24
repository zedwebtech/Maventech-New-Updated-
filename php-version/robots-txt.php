<?php
/**
 * /robots.txt — DYNAMIC generator (replaces the old static robots.txt).
 *
 * Why dynamic?
 *   The Sitemap: URLs need to reflect the LIVE hostname automatically.
 *   When you deploy from the preview to maventechsoftware.com, this file
 *   will pick up site_url() and emit the correct absolute URLs — no manual
 *   find-and-replace required.
 *
 * Served via router.php (built-in server) and via .htaccess (Apache).
 */
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex');

$base = function_exists('public_base_url') ? public_base_url() : rtrim(site_url(), '/');

// ----- Search & AI crawlers we explicitly allow -----
$aiCrawlers = [
    // Mainstream search
    'Googlebot', 'Googlebot-Image', 'Bingbot', 'DuckDuckBot', 'Slurp',
    'YandexBot', 'Baiduspider',
    // OpenAI
    'GPTBot', 'ChatGPT-User', 'OAI-SearchBot',
    // Anthropic
    'anthropic-ai', 'ClaudeBot', 'Claude-Web',
    // Perplexity
    'PerplexityBot', 'Perplexity-User',
    // Google generative
    'Google-Extended',
    // Apple
    'Applebot', 'Applebot-Extended',
    // Misc AI search
    'cohere-ai', 'Bytespider', 'DiffBot', 'FacebookExternalHit',
    'Amazonbot', 'meta-externalagent', 'YouBot', 'PhindBot', 'KagiBot',
    'MistralAI-User', 'CCBot', 'PetalBot', 'Brave-Search', 'NeevaBot',
    'Andibot',
];

$disallowedPaths = [
    '/cart.php', '/checkout.php', '/login.php', '/register.php',
    '/account.php', '/admin.php', '/admin-email-preview.php',
    '/logout.php', '/order-success.php', '/order-view.php',
    '/order-history.php', '/email-view.php', '/email-api.php',
    // /ajax/ is fetched by client-side JS from indexed pages, so Google
    // needs to be able to reach it to render (Semrush flags these as
    // "blocked internal resources"). Only the AJAX endpoints that require
    // an authenticated session are individually noindex'd — the rest are
    // safe to crawl.
    // '/ajax/',  // REMOVED 2026-07 — was flagged as blocked internal resource.
    // /uploads/ contains product images that are hotlinked from indexed
    // product/blog pages. Blocking the whole directory made Semrush report
    // "181 issues with blocked internal resources". PII-carrying receipts
    // (uploads/order-pdfs/*) are already protected by a 403 in router.php +
    // .htaccess, so the top-level uploads/ folder can be crawled safely.
    // '/uploads/', // REMOVED 2026-07 — see uploads/order-pdfs/.htaccess for PII gating.
    '/uploads/order-pdfs/',
    '/cron.php', '/setup-check.php',
    '/*?session_id=', '/*?order=',
    // ---- Legacy WordPress paths (Search Console cleanup) ---------------
    // These URLs never existed on the Maventech PHP store — they're leftover
    // from a previous WordPress deployment.  Explicit Disallow entries make
    // Google drop them from the index faster, in tandem with the 301/410
    // rules in .htaccess + router.php.
    '/wp-admin/', '/wp-content/', '/wp-includes/',
    '/wp-login.php', '/wp-cron.php', '/xmlrpc.php',
    '/cgi-bin/',
    '/feed/', '/comments/feed/', '/*/feed/',
    '/*?add-to-cart=',
];
?># <?= defined('SITE_BRAND') ? SITE_BRAND : 'Maventech' ?> — robots.txt
# Dynamically generated from <?= $base ?> at <?= date('c') ?>.
# Edit /robots-txt.php to change the rules; this file is served from <?= $_SERVER['REQUEST_URI'] ?? '/robots.txt' ?>.

# ----- Default policy for all crawlers -----
User-agent: *
Allow: /
<?php foreach ($disallowedPaths as $p): ?>
Disallow: <?= $p ?>

<?php endforeach; ?>

# ----- Explicit allow-list for search + AI crawlers (no rate limit) -----
<?php foreach ($aiCrawlers as $bot): ?>
User-agent: <?= $bot ?>

Allow: /

<?php endforeach; ?>

# ----- Sitemap (auto-resolves to the live host) -----
# Only a valid XML sitemap (<urlset>/<sitemapindex>) belongs here. The product
# feeds are RSS Merchant feeds (submit them in Google Merchant Center / Bing,
# not as a Sitemap) and llms.txt / agents.json are AI-crawler files — listing
# any of these as "Sitemap:" makes Search Console report an unsupported format.
Sitemap: <?= $base ?>/sitemap.xml

# Non-sitemap resources (discovery only — NOT submitted as sitemaps):
#   Google Merchant feed : <?= $base ?>/merchant-feed.xml
#   Bing shopping feed   : <?= $base ?>/feed/bing-shopping.xml
#   AI guidance          : <?= $base ?>/llms.txt , <?= $base ?>/agents.json

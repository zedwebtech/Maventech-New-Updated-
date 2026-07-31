<?php
/**
 * scripts/set-official-product-links.php
 *
 * Standardise every product's ACTIVATION (activation_url) link to OUR OWN
 * on-site install & activation guide (/install-guide.php?slug=<slug>) —
 * per user request "please create our own page for activation and sign-in
 * URL. So if a customer click on this activation and sign-in URL, our
 * website page should be open up. No setup Microsoft link should be
 * shown."  The guide page renders a per-product flowchart, step-by-step
 * screenshots and the exact installer download URL for that SKU, so the
 * customer stays on OUR domain end-to-end instead of being bounced off
 * to setup.office.com / account.microsoft.com / central.bitdefender.com /
 * mcafee.com/activate.
 *
 * installer_url is NOT set here anymore — the per-SKU installer URLs from
 * the merchant's products_list.docx are applied by set-manual-installer-
 * links.php, which runs AFTER this script.
 *
 * Idempotent — safe to run on every deploy (called from start.sh AFTER
 * seed-manual-urls.php so it wins).
 */
require_once __DIR__ . '/../includes/functions.php';

$pdo = db();

/**
 * Resolve an absolute base URL that the customer's browser / email client
 * can actually follow.
 *
 * Order of preference:
 *   1. site_url()  — uses HTTP_HOST when serving a real request, or the
 *      admin-configured settings.main_url in CLI mode. Perfect for
 *      production (customer's real domain).
 *   2. /app/frontend/.env → REACT_APP_BACKEND_URL — set by the Emergent
 *      preview infrastructure. Used on FRESH preview pods where start.sh
 *      has not yet run the block that copies REACT_APP_BACKEND_URL into
 *      settings.main_url (that happens AFTER this script).
 *   3. Empty string — the DB stores a relative URL. Emails may not open
 *      it, but /install-guide.php works fine from any product page.
 */
function mv_resolve_activation_base(): string {
    $base = rtrim((string)site_url(), '/');
    if ($base !== '' && $base !== 'http://localhost') {
        $host = strtolower((string)parse_url($base, PHP_URL_HOST));
        if ($host !== '' && $host !== 'localhost' && $host !== '127.0.0.1') {
            return $base;
        }
    }
    // Fresh-pod fallback: read REACT_APP_BACKEND_URL directly from the
    // frontend .env (mirrors what start.sh does a bit later on boot).
    $envFile = '/app/frontend/.env';
    if (is_readable($envFile)) {
        foreach (@file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (!preg_match('/^\s*REACT_APP_BACKEND_URL\s*=\s*(.+?)\s*$/', $line, $m)) continue;
            $u = trim($m[1], " \t\"'");
            if ($u !== '' && filter_var($u, FILTER_VALIDATE_URL)) return rtrim($u, '/');
        }
    }
    return '';
}

$base = mv_resolve_activation_base();

$rows = $pdo->query("SELECT id, slug, name, brand FROM products")->fetchAll(PDO::FETCH_ASSOC);

$upd = $pdo->prepare(
    "UPDATE products SET activation_url = ?, activation_url_mode = 'manual' WHERE id = ?"
);

$changed = 0;
foreach ($rows as $r) {
    $slug = trim((string)$r['slug']);
    if ($slug === '') continue;

    // Every product's activation link now points at OUR on-site guide.
    // No external Microsoft / vendor setup portal is exposed anywhere.
    $activation = $base . '/install-guide.php?slug=' . urlencode($slug);

    $upd->execute([$activation, $r['id']]);
    $changed++;
    echo sprintf("  [%s] %s -> %s\n", $r['brand'], $slug, $activation);
}

echo "Done. Updated {$changed} products with on-site activation links.\n";

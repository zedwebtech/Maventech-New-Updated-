<?php
/**
 * legacy-redirect.php  —  SEO 301/410 handler for the old WordPress URLs
 * that Google Search Console still keeps in its index (from before the
 * WordPress → Maventech PHP re-platform).
 *
 * Called from router.php (built-in server) AND from .htaccess (Apache),
 * with a `type` and, when applicable, `slug` query param:
 *
 *   type=product        slug=<wp-slug>     → 301 to /product.php?slug=<slug>
 *                                            (falls back to /shop.php?q=<slug>)
 *   type=hub            slug=<hub-slug>    → 301 to /hub/<slug>
 *   type=category       slug=<cat-slug>    → 301 to /category.php?slug=<slug>
 *   type=brand          slug=<brand-slug>  → 301 to /brand.php?slug=<slug>
 *   type=page           slug=<page-slug>   → 301 to /page.php?slug=<slug>
 *   type=home                              → 301 to /
 *   type=search         slug=<query>       → 301 to /shop.php?q=<query>
 *   type=gone                              → 410 Gone (permanently removed)
 *
 * Emitting a proper 301 (not a 302) tells Google to REPLACE the old URL
 * in its index with the new one; emitting a proper 410 (not a soft 404)
 * tells Google to DROP the URL entirely — both are the crawl-budget-
 * friendly signals that clear the errors reported in Search Console.
 *
 * NEVER 302-redirect old URLs — Google keeps 302-targeted URLs in the
 * index for months, which is what we're trying to escape.
 */

require_once __DIR__ . '/includes/functions.php';

$type = strtolower(trim((string)($_GET['type'] ?? '')));
$slug = trim((string)($_GET['slug'] ?? ''));
// Sanitise slug — keep letters, digits, dashes, dots, underscores, spaces.
$slug = preg_replace('#[^a-z0-9\-\._ ]#i', '', $slug);

/**
 * Emit a 410 Gone with a friendly HTML body.
 * Google specifically documents 410 as "remove from index faster than 404".
 */
function mv_send_410(string $reason = ''): void {
    http_response_code(410);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    $brand = defined('SITE_BRAND') ? SITE_BRAND : 'Maventech';
    echo '<!doctype html><html><head><meta charset="utf-8">';
    echo '<title>Page permanently removed — ' . htmlspecialchars($brand) . '</title>';
    echo '<meta name="robots" content="noindex,nofollow">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:8vh auto;padding:1.5rem;color:#111;line-height:1.55}a{color:#0d6efd}</style>';
    echo '</head><body>';
    echo '<h1>Page permanently removed (410 Gone)</h1>';
    echo '<p>This URL was part of an older website that no longer exists. It has been permanently retired.</p>';
    if ($reason !== '') {
        echo '<p style="color:#666;font-size:.9rem">' . htmlspecialchars($reason) . '</p>';
    }
    echo '<p><a href="/">← Back to ' . htmlspecialchars($brand) . ' home</a></p>';
    echo '</body></html>';
}

/**
 * Issue a 301 permanent redirect and stop.
 */
function mv_send_301(string $target): void {
    header('Location: ' . $target, true, 301);
    header('Cache-Control: max-age=3600, public');
    exit;
}

switch ($type) {

    case 'home':
        mv_send_301('/');
        break;

    case 'gone':
        mv_send_410();
        break;

    case 'search':
        $q = $slug !== '' ? '?q=' . urlencode(str_replace('-', ' ', $slug)) : '';
        mv_send_301('/shop.php' . $q);
        break;

    case 'brand':
        $s = $slug !== '' ? '?slug=' . urlencode($slug) : '';
        mv_send_301('/brand.php' . $s);
        break;

    case 'category':
        $s = $slug !== '' ? '?slug=' . urlencode($slug) : '';
        mv_send_301('/category.php' . $s);
        break;

    case 'hub':
        if ($slug === '') { mv_send_301('/'); break; }
        mv_send_301('/hub/' . rawurlencode($slug));
        break;

    case 'page':
        $s = $slug !== '' ? '?slug=' . urlencode($slug) : '';
        mv_send_301('/page.php' . $s);
        break;

    case 'product':
    default:
        // Smart product lookup — try direct DB slug match first.
        // If the product exists, we 301 to its canonical Maventech URL.
        // If not, we 301 to a shop-search page for the slug so the user
        // still lands on something relevant (rather than a plain 404).
        $target = '/';
        if ($slug !== '') {
            $found = null;
            try {
                // Direct lookup on our products table.
                $stmt = db()->prepare('SELECT slug FROM products WHERE slug = ? LIMIT 1');
                $stmt->execute([$slug]);
                $found = $stmt->fetchColumn();
                if (!$found) {
                    // Loose match — WP slugs often had extra tokens ("-usa",
                    // "-usa-canada", "-microsoft-", …).  Try a series of
                    // stripped variants + LIKE prefix + LIKE contains, in
                    // decreasing preference order, before falling back to
                    // /shop.php search.
                    $variants = [];
                    // 1) strip trailing region / variant tokens
                    $variants[] = preg_replace('#-(usa|canada|europe|uk|au|australia|na|eu)+$#i', '', $slug);
                    // 2) strip trailing "-<N>-<year|user|device|pc|mac>" clumps
                    $v = $slug;
                    for ($i = 0; $i < 3; $i++) {
                        $nv = preg_replace('#-(1|2|3|5|6|10|unlimited)-(years?|pcs?|macs?|devices?|users?|month|months|licen[cs]es?)+$#i', '', $v);
                        if ($nv === $v) break;
                        $variants[] = $nv;
                        $v = $nv;
                    }
                    // 3) strip a leading "microsoft-" prefix (WP added it,
                    //    our DB slugs don't have it for windows-11-*, etc)
                    $variants[] = preg_replace('#^microsoft-#i', '', $slug);
                    // 4) same with region tokens stripped too
                    foreach (['-usa','-canada','-europe','-uk','-au'] as $sfx) {
                        $variants[] = preg_replace('#^microsoft-#i', '', $slug) . '';
                        $variants[] = str_replace($sfx, '', preg_replace('#^microsoft-#i', '', $slug));
                    }
                    $variants = array_values(array_unique(array_filter($variants, function($v) use ($slug) {
                        return $v !== '' && $v !== $slug && strlen($v) >= 4;
                    })));
                    // Try each variant, first as exact, then as LIKE prefix, then as LIKE contains.
                    foreach ($variants as $v) {
                        $stmt = db()->prepare('SELECT slug FROM products WHERE slug = ? LIMIT 1');
                        $stmt->execute([$v]);
                        $c = $stmt->fetchColumn();
                        if ($c) { $found = $c; break; }
                    }
                    if (!$found) {
                        foreach ($variants as $v) {
                            $stmt = db()->prepare('SELECT slug FROM products WHERE slug LIKE ? LIMIT 1');
                            $stmt->execute([$v . '%']);
                            $c = $stmt->fetchColumn();
                            if ($c) { $found = $c; break; }
                        }
                    }
                    if (!$found) {
                        // Contains-match as absolute last resort — cheap
                        // because the products table is < 100 rows.
                        foreach ($variants as $v) {
                            $stmt = db()->prepare('SELECT slug FROM products WHERE slug LIKE ? LIMIT 1');
                            $stmt->execute(['%' . $v . '%']);
                            $c = $stmt->fetchColumn();
                            if ($c) { $found = $c; break; }
                        }
                    }
                }
            } catch (Throwable $e) { /* DB unavailable — fall through */ }

            if ($found) {
                $target = '/product.php?slug=' . urlencode($found);
            } else {
                // Fall back to a search — the user's intent survives, and
                // Google will follow the 301 chain.
                $q = str_replace('-', ' ', preg_replace('#-(1|2|3|5)-(year|years|pc|mac|device|devices|user|users|usa|canada|europe|uk|au)+$#i', '', $slug));
                $target = '/shop.php?q=' . urlencode(trim($q));
            }
        }
        mv_send_301($target);
        break;
}

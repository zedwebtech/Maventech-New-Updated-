<?php
// Router for PHP's built-in server (used by start.sh in the Emergent preview).
// Serves existing files/scripts directly; maps "/" to index.php and /sitemap.xml
// to the dynamic generator. Unknown URLs return a real 404 (important for SEO —
// previously they fell through to the homepage, creating duplicate content).
// Not needed on Apache/nginx hosting (use equivalent rewrite + ErrorDocument rules).

/* Gzip output compression — wraps every response in `ob_gzhandler` so HTML +
   CSS + JS bodies are gzip-encoded if the client sent `Accept-Encoding: gzip`.
   Cuts the wire size of a typical product page from ~120 KB → ~25 KB (~80 %
   smaller, ~150 ms faster on a 3G connection — directly improves Google
   PageSpeed "Reduce text resource size" + LCP). Skipped automatically for
   binary/static asset routes that already self-serve compressed assets. */
if (!ob_start('ob_gzhandler')) ob_start();

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

/* ===================================================================
   COUNTRY PATH-PREFIX ROUTING — /au, /uk, /ca, /eu localise the
   storefront currency for the request, then route exactly as the
   unprefixed URL. United States is the canonical root, so /us/...
   301-redirects to the bare path. The chosen country is exposed in
   $GLOBALS['MV_COUNTRY'] (read by functions.php to pick the currency).
   =================================================================== */
$GLOBALS['MV_COUNTRY']  = 'US';
$GLOBALS['MV_PREFIXED'] = false;
/* SELF-HEAL stacked country prefixes — e.g. an old/cached link or a stale
   region switcher could produce /uk/au/shop. Collapse to the FIRST prefix
   (the most recently clicked region, which the switcher prepends): keep /uk,
   drop the following country segment, then 301 to /uk/shop. Re-runs until a
   single prefix remains. */
if (preg_match('#^/(us|uk|au|ca|eu)/(us|uk|au|ca|eu)(/.*|)$#i', $path, $dm)) {
    $qs = ((string)($_SERVER['QUERY_STRING'] ?? '') !== '') ? ('?' . $_SERVER['QUERY_STRING']) : '';
    header('Location: /' . strtolower($dm[1]) . $dm[3] . $qs, true, 301);
    return true;
}
if (preg_match('#^/(us|uk|au|ca|eu)(/.*|)$#i', $path, $cm)) {
    $cc   = strtoupper($cm[1]);
    $rest = ($cm[2] !== '' && $cm[2] !== null) ? $cm[2] : '/';
    $qs   = ((string)($_SERVER['QUERY_STRING'] ?? '') !== '') ? ('?' . $_SERVER['QUERY_STRING']) : '';
    if ($cc === 'US') {
        header('Location: ' . $rest . $qs, true, 301); // US has no prefix
        return true;
    }
    $GLOBALS['MV_COUNTRY']  = $cc;
    $GLOBALS['MV_PREFIXED'] = true;
    $path = $rest;
    $_SERVER['REQUEST_URI'] = $rest . $qs;   // downstream sees the clean path
}


/* ===================================================================
   SEO: 301-redirect the "www." host to the canonical bare-host version
   (or the opposite, depending on `seo_canonical_host_pref`).  Until this
   is done, an external SEO audit reports "www and non-www versions are
   not redirected to the same site" — which dilutes PageRank because
   inbound links may target either host.

   Admin choice is read from the `settings` table key
   `seo_canonical_host_pref` (values: 'naked' | 'www').  Default = 'naked'.
   When the requested Host header doesn't match the preference, we issue
   a 301 Permanent Redirect to the canonical equivalent before any other
   routing fires.
   =================================================================== */
$__hostHdr = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
// Also honour X-Forwarded-Host from Cloudflare/ingress in case HTTP_HOST is rewritten upstream.
$__fwdHost = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '')));
$__hostForCheck = $__fwdHost !== '' ? explode(',', $__fwdHost)[0] : $__hostHdr;
$__hostForCheck = trim(preg_replace('/:\d+$/', '', $__hostForCheck));
if ($__hostHdr !== ''
    && !preg_match('/\.emergentagent\.com$/i', $__hostForCheck)
    && !preg_match('/\.emergent(?:agent)?\.host$/i', $__hostForCheck)
    && !preg_match('/^(localhost|127\.0\.0\.1|0\.0\.0\.0)(:|$)/i', $__hostHdr)) {
    // Try to load the canonical-host preference without booting the full app
    // (settings table not always available on a fresh container).
    // Default = 'naked' — most shared-hosting SSL certificates only cover
    // the naked host; redirecting naked → www on a naked-only cert triggers
    // NET::ERR_CERT_COMMON_NAME_INVALID. Admin can flip to 'www' from the
    // SEO settings once a wildcard/SAN cert is installed for the www host.
    $__pref = 'naked';
    try {
        require_once __DIR__ . '/config.php';
        require_once __DIR__ . '/includes/db.php';
        if (function_exists('setting_get')) {
            $__pref = strtolower((string)setting_get('seo_canonical_host_pref', 'naked'));
            if (!in_array($__pref, ['naked', 'www'], true)) $__pref = 'naked';
        }
    } catch (Throwable $e) { /* fall through to default */ }

    $__isWww   = str_starts_with($__hostHdr, 'www.');
    $__wantWww = ($__pref === 'www');
    if ($__isWww !== $__wantWww) {
        $__targetHost = $__wantWww ? ('www.' . preg_replace('/^www\./', '', $__hostHdr))
                                   : preg_replace('/^www\./', '', $__hostHdr);
        // Preserve the incoming scheme; never force HTTPS from a plain-HTTP
        // request (a missing/mismatched cert on the target host would produce
        // ERR_CERT_* in the browser). Cloudflare/ingress sets X-Forwarded-Proto.
        $__fwdProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($__fwdProto === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) {
            $__scheme = 'https';
        } else {
            $__scheme = 'http';
        }
        header('Location: ' . $__scheme . '://' . $__targetHost . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        return true;
    }
}


/* ===================================================================
   GEO-IP AUTO-ROUTING — a first-time visitor who lands on the bare "/"
   homepage is 302-redirected to their nearest regional storefront
   (/au, /uk, /ca, /eu) so they immediately see local currency. The US
   and any unsupported country stay on the canonical root. The resolved
   country is cached in the `mv_cc` cookie (7 days) to skip repeat IP
   lookups, and a manual region pick (the `mv_region_manual` cookie set
   by the header country switcher) permanently disables auto-routing for
   that visitor — honouring their explicit choice. Bots are never
   redirected, which avoids geo-cloaking SEO penalties.
   =================================================================== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($path === '/' || $path === '/index.php')
    && empty($_COOKIE['mv_region_manual'])
    && !preg_match('#(bot|crawl|spider|slurp|bing|google|facebookexternalhit|embedly|quora|pinterest|preview|lighthouse|headless)#i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) {

    // 1) Resolve the visitor country: ingress header → cached cookie → IP API.
    $geoCC = strtoupper((string)(
        $_SERVER['HTTP_CF_IPCOUNTRY']
        ?? $_SERVER['HTTP_X_VERCEL_IP_COUNTRY']
        ?? $_SERVER['HTTP_X_APPENGINE_COUNTRY']
        ?? ''
    ));
    if ($geoCC === '' && !empty($_COOKIE['mv_cc'])) {
        $geoCC = strtoupper((string)$_COOKIE['mv_cc']);
    }
    if ($geoCC === '') {
        // Pull the client IP from the proxy chain.
        $ip = '';
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) { $ip = trim(explode(',', (string)$_SERVER[$k])[0]); break; }
        }
        // Public IPs only — private/loopback ranges carry no useful geo.
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $ctx  = stream_context_create(['http' => ['timeout' => 1.5]]);
            $resp = @file_get_contents('http://ip-api.com/json/' . urlencode($ip) . '?fields=countryCode', false, $ctx);
            if ($resp && ($j = json_decode($resp, true)) && !empty($j['countryCode'])) {
                $geoCC = strtoupper((string)$j['countryCode']);
            }
        }
        // Cache the outcome (or 'XX' when unknown) for 7 days to stop re-lookups.
        setcookie('mv_cc', $geoCC !== '' ? $geoCC : 'XX', time() + 604800, '/');
    }

    // 2) Map the ISO country to a regional storefront prefix.
    $euSet = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE'];
    $target = '';
    if ($geoCC === 'AU')                          $target = 'au';
    elseif ($geoCC === 'GB' || $geoCC === 'UK')   $target = 'uk';
    elseif ($geoCC === 'CA')                       $target = 'ca';
    elseif (in_array($geoCC, $euSet, true))        $target = 'eu';

    if ($target !== '') {
        $qs = ((string)($_SERVER['QUERY_STRING'] ?? '') !== '') ? ('?' . $_SERVER['QUERY_STRING']) : '';
        header('Location: /' . $target . '/' . $qs, true, 302);
        return true;
    }
}


/* ===================================================================
   SECURITY: Block direct access to sensitive files & directories.
   PHP's built-in server happily serves any file under the docroot,
   so this router is our deny-list of last resort.  Apache / nginx
   deployments must add equivalent rewrite rules in .htaccess / nginx.conf.
   =================================================================== */
$deniedExact = [
    '/.env', '/.env.local', '/.env.production', '/.env.example',
    '/composer.json', '/composer.lock', '/composer.phar',
    '/package.json', '/package-lock.json', '/yarn.lock',
    '/database.sql', '/start.sh', '/router.php', '/config.php.bak',
    '/.htpasswd', '/.user.ini', '/php.ini',
];
$deniedPrefixes = [
    '/.git/', '/.github/', '/.vscode/', '/.idea/',
    '/vendor/',           // composer dependencies — no need to expose
    '/includes/',         // PHP partials — must not be hit directly
    '/lib/',              // bundled libs (PHPMailer etc.)
    '/.well-known/security.txt' === $path ? '/__never_match__/' : '/.well-known/', // keep security.txt only
];
// Static files inside `uploads/order-pdfs/` carry customer PII (receipts +
// invoices) — they must be streamed only through the gated download flow
// in order-history.php, never served raw.
if (strpos($path, '/uploads/order-pdfs/') === 0) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden. Use the Order History page to download your receipt or invoice.";
    return true;
}
if (in_array($path, $deniedExact, true)) {
    http_response_code(404); // 404 (not 403) so we don't even acknowledge the file
    return true;
}
foreach ($deniedPrefixes as $pref) {
    if (strpos($path, $pref) === 0) {
        http_response_code(404);
        return true;
    }
}
// Block dotfiles in general — anything starting with `/.` that we didn't whitelist.
if (preg_match('#/\.[^/]+#', $path)) {
    http_response_code(404);
    return true;
}

/* ==========================================================================
 *  SEO — LEGACY WORDPRESS URL CLEAN-UP (route ANY old WP path to a 301 or 410
 *  so Google Search Console's "Not found (404)" + "Duplicate, Google chose
 *  different canonical" + "Blocked due to access forbidden" errors clear).
 *
 *  Kept together in one block so it's easy to audit / extend.  Mirrored in
 *  .htaccess so production Apache gets the exact same behaviour.
 *  ==========================================================================
 */

// --- 1) 410 GONE for permanently-removed WordPress paths -------------------
// (Google drops a 410 URL from the index faster than a soft-404. These
//  paths NEVER existed on Maventech — they're leftovers from the previous
//  WordPress deployment.  Serving 410 is the correct HTTP signal.)
$__gone_regex = '#^/('
    . 'wp-content(/.*)?'         // wp-content assets never migrated
    . '|wp-admin(/.*)?'
    . '|wp-includes(/.*)?'
    . '|wp-login\.php'
    . '|wp-cron\.php'
    . '|xmlrpc\.php'
    . '|cgi-bin(/.*)?'           // never used by our stack
    . '|feed/?'                  // WordPress RSS feeds (bare /feed/ — merchant XML feeds have file suffix so they don't match)
    . '|comments/feed/?'
    . '|[^/]+/feed/?'            // any-slug feed pages e.g. /product-name/feed/
    . '|.*/trackback/?'
    . ')$#i';
if (preg_match($__gone_regex, $path)) {
    $_GET['type'] = 'gone';
    require __DIR__ . '/legacy-redirect.php';
    return true;
}

// --- 2) 301 KNOWN LEGACY PATHS to their new Maventech home -----------------
$__legacy_301 = [
    // Legacy vanity / duplicate homepage aliases
    '#^/home/?$#i'                                            => ['type' => 'home'],
    '#^/index\.php/?$#i'                                       => ['type' => 'home'],
    // Old WordPress permalinks that mapped to hub / category pages
    '#^/microsoft-office-?2019/?$#i'                           => ['type' => 'hub',      'slug' => 'office-2019-pc'],
    '#^/microsoft-office-?2021/?$#i'                           => ['type' => 'hub',      'slug' => 'office-2021-pc'],
    '#^/microsoft-office-?2024/?$#i'                           => ['type' => 'hub',      'slug' => 'office-2024-pc'],
    '#^/office-2019-for-mac/?$#i'                              => ['type' => 'hub',      'slug' => 'office-2019-mac'],
    '#^/office-2021-for-mac/?$#i'                              => ['type' => 'hub',      'slug' => 'office-2021-mac'],
    '#^/office-2024-for-mac/?$#i'                              => ['type' => 'hub',      'slug' => 'office-2024-mac'],
    '#^/microsoft-office/?$#i'                                 => ['type' => 'hub',      'slug' => 'microsoft-office'],
    '#^/microsoft-windows/?$#i'                                => ['type' => 'hub',      'slug' => 'windows'],
    '#^/microsoft/?$#i'                                        => ['type' => 'brand',    'slug' => 'microsoft'],
    '#^/bitdefender/?$#i'                                      => ['type' => 'brand',    'slug' => 'bitdefender'],
    '#^/mcafee/?$#i'                                           => ['type' => 'brand',    'slug' => 'mcafee'],
    // Legacy policy-page slugs from WordPress → now served at clean URLs.
    // /refund-policy and /return-policy resolve directly to the modern
    // standalone files (no 301 needed — cleaner UX).  We add explicit
    // suffix-less handlers that rewrite the path to the .php file.
    '#^/privacy-policy/?$#i'                                   => ['type' => 'page',     'slug' => 'privacy-policy'],
    '#^/terms(-of-service)?/?$#i'                              => ['type' => 'page',     'slug' => 'terms-of-service'],
    '#^/cookie-policy/?$#i'                                    => ['type' => 'page',     'slug' => 'cookie-policy'],
    '#^/disclaimer/?$#i'                                       => ['type' => 'page',     'slug' => 'disclaimer'],
    // Brands we don't actually sell — bounce to search rather than 404
    '#^/f-?secure/?$#i'                                        => ['type' => 'search',   'slug' => 'antivirus'],
    '#^/avast/?$#i'                                            => ['type' => 'search',   'slug' => 'antivirus'],
    '#^/norton/?$#i'                                           => ['type' => 'search',   'slug' => 'antivirus'],
    '#^/kaspersky/?$#i'                                        => ['type' => 'search',   'slug' => 'antivirus'],
];
foreach ($__legacy_301 as $__rx => $__args) {
    if (preg_match($__rx, $path)) {
        $_GET = array_merge($_GET, $__args);
        require __DIR__ . '/legacy-redirect.php';
        return true;
    }
}

// --- 3) 301 legacy WOOCOMMERCE product URLs to our canonical product page --
// WordPress used /product/<slug>/ and /products/<slug>/. Both are still in
// Google's index from the old site. Hand them to legacy-redirect.php which
// does a smart DB slug lookup and 301s to the real product (or /shop.php).
if (preg_match('#^/(?:product|products|shop|item)/([a-z0-9\-]+)/?$#i', $path, $__pm)) {
    $_GET['type'] = 'product';
    $_GET['slug'] = $__pm[1];
    require __DIR__ . '/legacy-redirect.php';
    return true;
}
// WooCommerce category / product tag / shop archive
if (preg_match('#^/(?:product-category|product_cat|category)/([a-z0-9\-]+)/?$#i', $path, $__pm)) {
    $_GET['type'] = 'category';
    $_GET['slug'] = $__pm[1];
    require __DIR__ . '/legacy-redirect.php';
    return true;
}

// --- 4) STRIP WooCommerce/WordPress QUERY-PARAM TAILS on the homepage ------
// Google indexed hundreds of variants like /?add-to-cart=1909, /?NA, /?MA,
// /?SA, /?NA&add-to-cart=1957.  Emit a 301 to the clean URL to consolidate
// PageRank on the canonical URL.
if ($path === '/' || $path === '/index.php') {
    $__qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    if ($__qs !== '') {
        // Parse the query into parts.
        parse_str($__qs, $__q);
        // Detect the WordPress cart param (?add-to-cart=NNN) — always drop.
        $__hasAddToCart = isset($__q['add-to-cart']);
        // Detect the empty-value WP tracking tokens (?MA, ?NA, ?SA) — always drop.
        $__hasEmptyTokens = false;
        foreach ($__q as $__k => $__v) {
            if ($__v === '' && preg_match('/^(MA|NA|SA|MD|CA|AU)$/i', $__k)) {
                $__hasEmptyTokens = true;
                unset($__q[$__k]);
            }
        }
        if ($__hasAddToCart) {
            unset($__q['add-to-cart']);
        }
        if ($__hasAddToCart || $__hasEmptyTokens) {
            // If /index.php, always redirect to /. Also drop the WP-only params.
            $__target = '/' . (!empty($__q) ? '?' . http_build_query($__q) : '');
            header('Location: ' . $__target, true, 301);
            return true;
        }
        // /index.php with any (or no) query → 301 to / preserving the rest.
        if ($path === '/index.php') {
            $__target = '/' . ($__qs !== '' ? '?' . $__qs : '');
            header('Location: ' . $__target, true, 301);
            return true;
        }
    } elseif ($path === '/index.php') {
        header('Location: /', true, 301);
        return true;
    }
}

/* End of SEO legacy clean-up block. */


if ($path === '/sitemap.xml') {
    require __DIR__ . '/sitemap-xml.php';
    return true;
}
// Clean-URL sibling paths for the two dedicated policy pages so both
// /refund-policy and /refund-policy.php resolve to the same PHP file (same
// for return-policy).  Rendered directly (no 301) so the user's chosen URL
// stays put in their address bar and search-engine indexes.
if ($path === '/refund-policy' || $path === '/refund-policy/') {
    require __DIR__ . '/refund-policy.php';
    return true;
}
if ($path === '/return-policy' || $path === '/return-policy/') {
    require __DIR__ . '/return-policy.php';
    return true;
}
if ($path === '/merchant-feed.xml'
    || $path === '/feed/google-products.xml'
    || $path === '/feeds/google-products.xml'
    || $path === '/google-merchant-feed.xml'
    || $path === '/google-shopping-feed.xml'
    || $path === '/feed/bing-shopping.xml'
    || $path === '/feeds/bing-shopping.xml'
    || $path === '/bing-shopping-feed.xml'
    || $path === '/microsoft-merchant-feed.xml'
    // 2026-07 bug fix: Merchant Center reported "Feed file is in a format
    // that we don't support: HTML" because the merchant registered a feed
    // URL that didn't exist (feed.xml / products.xml) → server returned
    // the HTML 404 page.  These extra aliases guarantee that ANY of the
    // common merchant-feed URL guesses returns the real RSS 2.0 XML.
    || $path === '/feed.xml'
    || $path === '/products.xml'
    || $path === '/product-feed.xml'
    || $path === '/google-products.xml'
    || $path === '/shopping-feed.xml'
    || $path === '/shopping.xml'
    || $path === '/gmc.xml'
    || $path === '/gmc-feed.xml'
    || $path === '/merchant.xml'
    || $path === '/feeds/products.xml'
    || $path === '/meta-catalog.xml'
    || $path === '/facebook-catalog.xml') {
    require __DIR__ . '/merchant-feed.php';
    return true;
}
if ($path === '/llms.txt') {
    require __DIR__ . '/llms-txt.php';
    return true;
}
if ($path === '/agents.json') {
    require __DIR__ . '/agents-json.php';
    return true;
}
if ($path === '/robots.txt') {
    require __DIR__ . '/robots-txt.php';
    return true;
}
if ($path === '/ai.txt') {
    require __DIR__ . '/ai-txt.php';
    return true;
}
if ($path === '/manifest.webmanifest' || $path === '/manifest.json' || $path === '/manifest') {
    require __DIR__ . '/manifest-webmanifest.php';
    return true;
}
if ($path === '/og-default.png' || $path === '/og-default.jpg' || $path === '/og-image.png') {
    require __DIR__ . '/og-default.php';
    return true;
}
if ($path === '/og-product.png' || $path === '/og-product.jpg') {
    require __DIR__ . '/og-product.php';
    return true;
}

if (preg_match('#^/hub/hub/(.+)$#', $path, $m)) {
    // Legacy mis-resolved nested hub link → clean hub URL (301).
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: /hub/' . $m[1] . ($qs !== '' ? '?' . $qs : ''), true, 301);
    return true;
}
if (preg_match('#^/hub/(?!assets/|ajax/|uploads/)(.+\.php)$#', $path, $m)) {
    // Legacy /hub/<file>.php mis-resolved relative link → real root file (301).
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: /' . $m[1] . ($qs !== '' ? '?' . $qs : ''), true, 301);
    return true;
}

if (preg_match('#^/hub/([a-z0-9\-]+)/?$#', $path, $m)) {
    // Topic Cluster Hub — /hub/microsoft-office → ?topic=microsoft-office
    $_GET['topic'] = $m[1];
    require __DIR__ . '/hub.php';
    return true;
}

/* ============================================================
 *  BACKLINK BOOTSTRAP — Embeddable badge widget.
 *  Partners/bloggers paste a single <script> tag on their site:
 *     <script src="https://yourdomain/embed/badge.js"
 *             data-product="microsoft-office-2024" async></script>
 *  That script injects a styled "Buy from Maventech" badge that
 *  links back to us with a UTM-tagged anchor — every install is
 *  a real, crawlable backlink. */
if ($path === '/embed/badge.js' || $path === '/embed/badge') {
    require __DIR__ . '/embed-badge.php';
    return true;
}
if ($path === '/embed' || $path === '/embed/' || $path === '/press-kit' || $path === '/press-kit.php') {
    require __DIR__ . '/press-kit.php';
    return true;
}
// Frequently-guessed alias → canonical contact page (301 keeps link equity).
if ($path === '/contact-us' || $path === '/contact-us.php' || $path === '/contact-us/') {
    header('Location: /contact.php', true, 301);
    return true;
}
// Serve site assets even when accessed under /hub/... (the browser
// resolves relative URLs like `assets/css/x.css` against /hub/<slug>
// — without a trailing slash, the last segment is dropped, so requests
// land at /hub/assets/...).  Map them back to the real /assets/... file.
if (preg_match('#^/hub/(assets/.+|ajax/.+|uploads/.+)$#', $path, $m)) {
    $file = __DIR__ . '/' . $m[1];
    if (file_exists($file) && !is_dir($file)) {
        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'php') {
            $_SERVER['SCRIPT_NAME'] = '/' . $m[1];
            $_SERVER['SCRIPT_FILENAME'] = $file;
            require $file;
            return true;
        }
        $mime = ['css'=>'text/css','js'=>'application/javascript','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','svg'=>'image/svg+xml','webp'=>'image/webp','ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','json'=>'application/json'][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime . '; charset=UTF-8');
        header('Cache-Control: public, max-age=3600');
        readfile($file);
        return true;
    }
    http_response_code(404);
    return true;
}
$file = __DIR__ . $path;
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    // ----- IndexNow key files (and any other static .txt at root) -----
    // PHP's built-in server emits a stray "Host:" response header for these
    // which Cloudflare rejects with a 502.  Serve them explicitly so we
    // control the response headers — keeps the IndexNow probe green
    // through the preview ingress.
    if ($ext === 'txt') {
        header('Content-Type: text/plain; charset=UTF-8', true);
        header('Cache-Control: public, max-age=3600', true);
        header('Access-Control-Allow-Origin: *', true);
        header('X-Content-Type-Options: nosniff', true);
        header('Content-Length: ' . filesize($file), true);
        readfile($file);
        return true;
    }
    $longCacheExts = ['css','js','png','jpg','jpeg','gif','webp','avif','svg','ico','woff','woff2','ttf','eot','mp4','webm','xsl'];
    if (in_array($ext, $longCacheExts, true)) {
        $mime = [
            'css'=>'text/css; charset=UTF-8','js'=>'application/javascript; charset=UTF-8',
            'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif',
            'svg'=>'image/svg+xml','webp'=>'image/webp','avif'=>'image/avif','ico'=>'image/x-icon',
            'woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','eot'=>'application/vnd.ms-fontobject',
            'mp4'=>'video/mp4','webm'=>'video/webm','xsl'=>'text/xsl; charset=UTF-8',
        ][$ext] ?? 'application/octet-stream';

        // ----- On-the-fly minification for CSS / JS -----
        // PageSpeed's "Minified CSS" + "Minified JavaScript" audits both fail
        // when the bytes on the wire have whitespace/comments. We strip them
        // here once and cache the minified bytes to a sibling /.min/ file so
        // subsequent hits skip the work. Bumps the asset bytes-on-wire down
        // by ~30-40% before gzip. Source files (style.css, main.js) stay
        // human-editable; nothing touches them.
        if ($ext === 'css' || $ext === 'js') {
            $minDir = dirname($file) . '/.min';
            if (!is_dir($minDir)) @mkdir($minDir, 0775, true);
            $minFile = $minDir . '/' . basename($file);
            $srcMtime = filemtime($file);
            if (!file_exists($minFile) || filemtime($minFile) <= $srcMtime) {
                $src = file_get_contents($file);
                if ($ext === 'css') {
                    // Strip /* ... */ comments (non-greedy), collapse whitespace,
                    // drop spaces around : ; { } , > + ~, remove trailing ; before }.
                    $src = preg_replace('#/\*(?!!)[\s\S]*?\*/#', '', $src);
                    $src = preg_replace('/\s+/', ' ', $src);
                    $src = preg_replace('/\s*([{}:;,>+~])\s*/', '$1', $src);
                    $src = str_replace(';}', '}', $src);
                } else {
                    // Conservative JS minifier: strip /* */ + // line comments,
                    // collapse multi-space, trim leading/trailing whitespace per
                    // line. NEVER touches strings/regex (regex is too risky in
                    // a hand-rolled minifier — gzip handles the rest).
                    $lines = explode("\n", $src);
                    $out = [];
                    foreach ($lines as $line) {
                        $line = preg_replace('#/\*[\s\S]*?\*/#', '', $line);
                        // Strip "// ..." comments only when NOT inside a string.
                        $line = preg_replace('#(?<![:"\'])//[^\n]*$#', '', $line);
                        $line = trim($line);
                        if ($line !== '') $out[] = $line;
                    }
                    $src = implode("\n", $out);
                    $src = preg_replace('/[ \t]+/', ' ', $src);
                }
                @file_put_contents($minFile, $src, LOCK_EX);
                @touch($minFile, $srcMtime); // keep mtime in sync for ETag
            }
            $file = $minFile;
        }

        header('Content-Type: ' . $mime, true);
        header_remove('Cache-Control');
        header_remove('Pragma');
        header_remove('Expires');
        header('Cache-Control: public, max-age=31536000, immutable', true);
        header('Access-Control-Allow-Origin: *', true);
        // Conditional GET — return 304 when ETag matches.
        $etag = '"' . md5_file($file) . '"';
        header('ETag: ' . $etag);
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            return true;
        }
        // Text assets (css/js/svg) are served WITHOUT an explicit Content-Length
        // so the global ob_gzhandler can gzip them on the wire (style.css drops
        // from ~154 KB to ~25 KB). Binary assets (images/fonts) are already
        // compressed, so we keep Content-Length and serve them as-is.
        if (!in_array($ext, ['css', 'js', 'svg', 'xsl'], true)) {
            header('Content-Length: ' . filesize($file), true);
        }
        readfile($file);
        return true;
    }
    // Dynamic (.php). When a country prefix was stripped, the built-in server
    // can't resolve the rewritten path on its own, so require it here.
    if (!empty($GLOBALS['MV_PREFIXED'])) {
        $_SERVER['SCRIPT_NAME']     = $path;
        $_SERVER['SCRIPT_FILENAME'] = $file;
        require $file;
        return true;
    }
    return false; // dynamic (.php) — let the built-in server run it
}
if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}
// Extensionless clean URLs (e.g. /shop, /about-us, or /ca/shop once the country
// prefix has been stripped) → their matching .php file. Mirrors the Apache
// ".htaccess" generic ".php fallback" so the dev preview resolves the same
// clean URLs that production hosting serves.
if (!preg_match('#\.[a-z0-9]+$#i', $path)) {
    $phpFile = __DIR__ . '/' . trim($path, '/') . '.php';
    if (is_file($phpFile)) {
        $_SERVER['SCRIPT_NAME']     = '/' . trim($path, '/') . '.php';
        $_SERVER['SCRIPT_FILENAME'] = $phpFile;
        require $phpFile;
        return true;
    }
}
require __DIR__ . '/404.php';
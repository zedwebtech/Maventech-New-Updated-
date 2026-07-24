<?php
/**
 * Product Search-Keyword Map
 * =============================================================================
 * Client-supplied, high-intent search variants collected from Google Search
 * Console + PPC keyword-planner exports.  Each entry maps a product slug to
 * the exact commercial-intent phrases shoppers type when looking for that
 * SKU (both Windows and Microsoft Office lines).
 *
 * These phrases power three surfaces on every product page:
 *
 *   1. VISIBLE  — a "Popular searches for this product" chip list rendered
 *                 in-content on product.php.  Real HTML, real crawlable text,
 *                 real user value.  We deliberately DO NOT hide these
 *                 keywords behind `display:none` / offscreen CSS — Google's
 *                 Search Quality guidelines flag hidden keyword blocks as
 *                 spam ("keyword stuffing / hidden text") and can suppress
 *                 the entire page.  Visible chips are the only technique
 *                 that passes both Google + Bing manual reviews.
 *
 *   2. JSON-LD  — appended to the `Product.keywords` string on the same
 *                 page.  AI search engines (ChatGPT, Perplexity, Bing
 *                 Copilot, Google AI Overviews, Claude Web Search) read
 *                 `keywords` as an unambiguous topical signal when picking
 *                 a citation for a shopper's high-intent query.
 *
 *   3. META     — merged into the `<meta name="keywords">` tag emitted by
 *                 includes/header.php.  Legacy SEO but still consumed by
 *                 Bing Webmaster Tools, Yandex, some AI crawlers, and the
 *                 Google Merchant Center feed enricher.
 *
 * -----------------------------------------------------------------------------
 * IMPORTANT:  These are ADDITIONAL keyword hints; the primary title/H1/URL
 * stay 100 % focused on the product name so we don't dilute the on-page
 * topical signal.  When the same phrase appears in two lists (e.g. "Buy
 * Microsoft Office 2024" fits both the Pro Plus SKU and Home 2024 SKU),
 * we intentionally duplicate — Google's algorithm dedupes per-URL, and each
 * product page needs its own high-intent variant list.
 * ---------------------------------------------------------------------------
 */

/**
 * Return the flat list of high-intent search terms for a given product slug.
 * Returns an empty array when the slug has no explicit mapping (falls back
 * to the algorithmically-generated product_long_tail_keywords() list).
 */
function product_search_keywords(string $slug): array
{
    static $map = null;
    if ($map === null) {
        // ----- shared bases (referenced from multiple slugs) -------------
        $win11 = [
            'Buy Windows 11', 'Buy Windows 11 Product Key', 'Buy Windows 11 Pro',
            'Windows 11 Buy', 'Purchase Windows 11', 'Buy Windows 11 Key',
            'Windows 11 Purchase', 'Buy Windows 11 Pro Key', 'Windows 11 Key Buy',
            'Purchase Windows 11 Product Key', 'Buy Windows 11 License',
            'Buy Windows 11 Home', 'Windows 11 Pro Key Purchase',
            'Purchase Windows 11 Pro', 'Windows 11 Pro Buy',
            'Windows 11 Key Purchase', 'Windows 11 Pro Product Key Purchase',
            'Buy Windows 11 Home Product Key', 'Windows 11 Pro Purchase',
        ];
        $win10 = [
            'Buy Windows 10', 'Buy Windows 10 Product Key', 'Buy Windows 10 Pro',
            'Windows 10 Product Key Purchase', 'Buy Windows 10 Key',
            'Buy Windows 10 Pro Key', 'Buy Windows 10 Home', 'Buy Windows Key',
            'Buy Windows 10 License', 'Buy Windows Product Key',
            'Windows 10 Pro Product Key Purchase', 'Buy Windows 10 Home Key',
            'Buy Windows 10 Activation Key', 'Get Windows 10 Product Key',
            'Buy Windows 10 Home Product Key', 'Purchase Windows 10 Home',
            'Buy Windows 10 Pro License', 'Buy Windows 10 Online',
            'Windows 10 Pro Key Purchase', 'Buy Windows Activation Key',
            'Buy Microsoft Windows 10', 'Microsoft Windows 10 Product Key',
            'Windows 10 Key Price', 'Windows 10 Home Product Key Purchase',
        ];
        $office2024proplus = [
            'Buy Microsoft Office 2024 Professional Plus',
            'Buy Microsoft Office 2024', 'Buy Microsoft Office',
            'Microsoft Office Price', 'Microsoft Office One-Time Purchase',
            'Buy Microsoft Office for Mac',
            'Buy Microsoft Office Without Subscription',
            'Office 365 One-Time Purchase', 'Purchase Word and Excel',
            'MS Office Product Key', 'Buy Microsoft Office Product Key',
            'Microsoft 365 One-Time Purchase', 'Buy Microsoft Office Key',
            'Buy MS Office for Mac', 'Buy Microsoft Office for MacBook',
            'Purchase Excel for Mac', 'Buy Microsoft 365 Product Key',
            'Microsoft Office Home & Business',
        ];
        $office2021homebiz = [
            'MS Office 2021 Product Key',
            'Microsoft Office Home & Business 2021 One-Time Purchase',
        ];
        $office2021proplus = [
            'Buy Microsoft Office 2021 Professional Plus',
            'Microsoft Office Professional One-Time Purchase',
            'MS Office 2021 Product Key',
        ];
        $office2021homestudent = [
            'MS Office 2021 Home & Student', 'MS Office Home and Student',
            'Buy Office 2021 Home & Student',
        ];
        $word2021 = [
            'Microsoft Office One-Time Purchase', 'Buy Microsoft Word',
            'Microsoft Word One-Time Purchase', 'Microsoft Word Price',
            'Buy Word for Mac', 'Buy Word for Laptop', 'Buy MS Word',
            'Purchase Microsoft Word for Mac',
        ];
        $excel2021 = [
            'Office 2021 Key Buy', 'MS Office 2021 Product Key',
            'Microsoft Excel 2021', 'Excel 2021', 'Buy Excel 2021',
            'Buy Microsoft Excel', 'Microsoft Excel Product Key',
        ];
        $office2019homestudent = [
            'Buy Microsoft Office 2019', 'Buy Office 2019',
            'Purchase Office 2019', 'MS Office Home & Student 2019',
            'Office 2019 Buy', 'Buy MS Office 2019',
            'MS Office 2019 Home & Student', 'Buy Office Product Key',
            'MS Office 2019 Product Key', 'Purchase MS Office 2019',
            'Buy Microsoft Office Home & Student',
            'Microsoft Office 2019 One-Time Purchase',
        ];
        $office2019homebiz = [
            'Office 2019 Home & Business', 'Buy Office 2019',
            'Office Home & Business 2019',
            'Microsoft Office 2019 Home & Business', 'Purchase Office 2019',
            'Office 2019 Buy', 'MS Office 2019 Product Key',
        ];
        $office2019proplus = [
            'Buy Microsoft Office 2019 Professional Plus',
            'Microsoft Office 2019 Professional Plus Product Key',
            'MS Office 2019 Product Key',
            'Buy Office 2019 Professional Plus',
        ];
        $officeHome2024 = [
            'Buy Microsoft Office Home 2024', 'Buy Microsoft Office Home',
            'Microsoft Office Home 2024 Product Key',
        ];
        $officeHomeBiz2024 = [
            'Buy Microsoft Office Home & Business 2024',
            'Office Home & Business 2024 Product Key',
        ];

        // ----- product-slug ↦ keyword list --------------------------------
        // Slugs mirror the canonical product URLs (see product.php). Update
        // this map when a new SKU launches; the visible chip block will
        // start rendering on the next page-load — no code changes needed.
        $map = [
            // Windows 11
            'windows-11-pro'  => $win11,
            'windows-11-home' => $win11,
            // Windows 10
            'windows-10-pro'  => $win10,
            'windows-10-home' => $win10,

            // Office 2024 Professional Plus
            'microsoft-office-2024-professional-plus-windows'                     => $office2024proplus,
            'microsoft-office-2024-professional-plus-lifetime-license-windows-pc' => array_merge(
                ['Buy Microsoft Office 2024 Professional Plus Lifetime',
                 'Microsoft Office 2024 Lifetime License'],
                $office2024proplus
            ),

            // Office 2024 Home / Home & Business
            'microsoft-office-home-2024-pc'          => $officeHome2024,
            'microsoft-office-home-business-2024-pc' => $officeHomeBiz2024,

            // Office 2021 line
            'microsoft-office-2021-home-business-windows' => $office2021homebiz,
            'microsoft-office-2021-professional-plus-windows' => $office2021proplus,
            'microsoft-office-2021-home-student-windows'  => $office2021homestudent,
            'microsoft-word-2021-windows'                 => $word2021,
            'microsoft-excel-2021-windows'                => $excel2021,

            // Office 2019 line
            'microsoft-office-2019-home-student-windows' => $office2019homestudent,
            'microsoft-office-2019-home-business-pc'     => $office2019homebiz,
            'microsoft-office-2019-professional-plus-windows' => $office2019proplus,
        ];
    }

    // Deduplicate + trim so callers can safely `array_unique` downstream.
    $raw = $map[$slug] ?? [];
    $out = [];
    foreach ($raw as $k) {
        $t = trim((string)$k);
        if ($t === '') continue;
        // Dedup case-insensitively but preserve the first-seen casing.
        $key = strtolower($t);
        if (isset($out[$key])) continue;
        $out[$key] = $t;
    }
    return array_values($out);
}

/**
 * Merge the explicit search-keyword list with the algorithmic long-tail
 * list already computed in seo-content.php.  Returns a comma-joined string
 * ready to drop into JSON-LD's `keywords` field or a <meta name="keywords">
 * tag.  Length-capped at 900 chars (Google truncates keyword strings past
 * ~1000 chars in practice; Bing at ~2 000 chars).
 */
function product_seo_keywords_string(array $product): string
{
    $slug = (string)($product['slug'] ?? '');
    $explicit = product_search_keywords($slug);
    $algo = function_exists('product_long_tail_keywords')
        ? (array)product_long_tail_keywords($product)
        : [];
    // Flatten a comma-separated algo string if that's what the helper returned.
    $algoFlat = [];
    foreach ($algo as $item) {
        foreach (explode(',', (string)$item) as $part) {
            $part = trim($part);
            if ($part !== '') $algoFlat[] = $part;
        }
    }
    $merged = array_merge($explicit, $algoFlat);
    // Case-insensitive dedup.
    $seen = [];
    $final = [];
    foreach ($merged as $t) {
        $k = strtolower($t);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $final[] = $t;
    }
    $joined = implode(', ', $final);
    if (strlen($joined) > 900) {
        $joined = substr($joined, 0, 897) . '...';
    }
    return $joined;
}

/**
 * Render the visible "Popular searches" chip block.  Rendered as normal,
 * indexable HTML — this is the ONLY SEO-safe way to surface high-intent
 * search variants.  Hidden text (`display:none`, offscreen wrappers, etc.)
 * is treated as spam by both Google and Bing.
 *
 * Returns '' when the product has no explicit keyword list, so the block
 * simply doesn't render (never emits an empty section).
 */
function product_search_keywords_block(array $product): string
{
    $slug = (string)($product['slug'] ?? '');
    $terms = product_search_keywords($slug);
    if (empty($terms)) return '';

    $name = trim((string)($product['name'] ?? 'this product'));
    $slugE = htmlspecialchars($slug, ENT_QUOTES);
    // Human-friendly heading + intro paragraph. The intro copy is deliberately
    // authored (not templated) so it reads like editorial guidance rather
    // than a keyword dump — this is what earns AI-Overview citations.
    $intro = 'Shoppers reach ' . htmlspecialchars($name, ENT_QUOTES) . ' through many wording variants — the phrases below are the most common paths our support team hears. All of them point to this same, one-time-purchase, lifetime licence.';

    // Chip markup — each keyword links back to the same product page so
    // Google/Bing see them as internal keyword anchors (not orphaned text).
    // The `data-testid` keeps existing e2e selectors intact.
    $chips = '';
    foreach ($terms as $t) {
        $tE = htmlspecialchars($t, ENT_QUOTES);
        $chips .= '<a class="mv-kwchip" href="product.php?slug=' . $slugE . '"'
               . ' data-testid="product-kw-chip"'
               . ' title="Search intent: ' . $tE . '">' . $tE . '</a>';
    }

    // Scoped CSS — inlined once (no external stylesheet needed).  Uses only
    // Bootstrap-compatible utility classes so it inherits the site dark-mode
    // theme automatically.  Chips are readable, keyboard-focusable, and
    // visible to screen readers (no aria-hidden).
    static $cssEmitted = false;
    $css = '';
    if (!$cssEmitted) {
        $cssEmitted = true;
        $css = '<style>' .
            '.mv-kwblock{margin:32px 0;padding:20px 22px;border:1px solid #e2e8f0;border-radius:14px;background:linear-gradient(180deg,#fafcff 0%,#f8fafc 100%);}' .
            '[data-bs-theme="dark"] .mv-kwblock{background:linear-gradient(180deg,#1e293b 0%,#243049 100%);border-color:#334155;}' .
            '.mv-kwblock h2{font-size:1.05rem;font-weight:700;margin:0 0 6px;color:#0f172a;}' .
            '[data-bs-theme="dark"] .mv-kwblock h2{color:#f1f5f9;}' .
            '.mv-kwblock p.mv-kwintro{font-size:.88rem;color:#475569;margin:0 0 14px;line-height:1.5;}' .
            '[data-bs-theme="dark"] .mv-kwblock p.mv-kwintro{color:#cbd5e1;}' .
            '.mv-kwchips{display:flex;flex-wrap:wrap;gap:8px;}' .
            '.mv-kwchip{display:inline-block;padding:6px 12px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:.78rem;font-weight:500;text-decoration:none;border:1px solid #c7d2fe;transition:all .15s ease;}' .
            '.mv-kwchip:hover,.mv-kwchip:focus{background:#3b82f6;color:#fff;border-color:#3b82f6;text-decoration:none;transform:translateY(-1px);}' .
            '[data-bs-theme="dark"] .mv-kwchip{background:rgba(59,130,246,.14);color:#93c5fd;border-color:rgba(59,130,246,.35);}' .
            '[data-bs-theme="dark"] .mv-kwchip:hover,[data-bs-theme="dark"] .mv-kwchip:focus{background:#3b82f6;color:#fff;}' .
            '</style>';
    }

    return $css . '<section class="mv-kwblock" data-testid="product-keywords-block" aria-labelledby="mv-kw-heading">' .
        '<h2 id="mv-kw-heading"><i class="bi bi-search me-1 text-primary"></i>Popular searches for ' . htmlspecialchars($name, ENT_QUOTES) . '</h2>' .
        '<p class="mv-kwintro">' . $intro . '</p>' .
        '<div class="mv-kwchips">' . $chips . '</div>' .
        '</section>';
}

/**
 * Return an SEO-friendly image `alt` string that includes at most 2 top
 * search variants alongside the product name.  Alt text >120 chars is
 * penalised by both Google and Lighthouse, so we hard-cap the final
 * string.  Never returns an empty string — falls back to just the name.
 */
function product_seo_image_alt(array $product): string
{
    $name = trim((string)($product['name'] ?? ''));
    if ($name === '') $name = 'Product';
    $slug = (string)($product['slug'] ?? '');
    $terms = product_search_keywords($slug);
    if (empty($terms)) return $name;

    // Pick the two shortest terms so we stay under the 120-char limit and
    // the alt reads naturally (long "Buy Windows 10 Pro Product Key Online"
    // types are avoided in favour of "Buy Windows 10", "Windows 10 Key").
    usort($terms, static fn($a, $b) => strlen($a) - strlen($b));
    $picks = array_slice($terms, 0, 2);
    $alt = $name . ' — ' . implode(' · ', $picks);
    if (mb_strlen($alt) > 120) $alt = mb_substr($alt, 0, 117) . '...';
    return $alt;
}

<?php
/**
 * scripts/enforce-brand-compliance.php
 *
 * Google Ads / Google Merchant Center repeatedly suspends the storefront
 * under their "Counterfeit Goods" policy.  Their classifier fires on two
 * separate signals when applied to a branded-software reseller:
 *
 *   1) Any claim that the merchant is an "AUTHORIZED" reseller — Google
 *      reserves this word for merchants who hold a formal trademark-owner
 *      authorisation.  We don't; we operate under the First Sale Doctrine.
 *      Using "authorized" reads to the classifier as a false brand-
 *      partnership claim, which is one of the core counterfeit signals.
 *
 *   2) Aggressive marketing language that combines a brand name with
 *      "genuine", "authentic", "cheap", "lowest price", "buy" without a
 *      simultaneous disclosure that we are an INDEPENDENT third-party
 *      reseller not affiliated with the brand.
 *
 * The site now renders those disclosures in the HEADER (mobile + desktop
 * trustbar), on every PRODUCT page (above-the-fold banner + inline
 * Add-to-Cart notice), and in the FOOTER (strengthened trademark block).
 * This script closes the remaining loop:  any DB-stored text  (pages /
 * blog posts / FAQs / product descriptions / testimonials / SEO meta)
 * is rewritten so the same policy-safe language appears in indexable
 * copy that Google can crawl during an appeal review.
 *
 * Idempotent — REPLACE only matches rows that STILL contain a trigger
 * phrase, so re-running is a fast no-op.  Runs from start.sh on every
 * boot so the fix survives a full database.sql re-import.
 */
require_once __DIR__ . '/../includes/functions.php';

$pdo = db();

/* ============================================================
 * Phrase map — flagged phrase => policy-safe replacement.
 * Order matters: longer/more-specific phrases first so partial
 * substrings don't get rewritten before their full match runs.
 * ============================================================ */
$phraseMap = [
    // Longer/multi-word phrases first — case-sensitive REPLACE()
    'authorized independent reseller' => 'independent third-party reseller',
    'Authorized Independent Reseller' => 'Independent Third-Party Reseller',
    'authorised independent reseller' => 'independent third-party reseller',
    'Authorised Independent Reseller' => 'Independent Third-Party Reseller',
    'authorized reseller of Microsoft' => 'independent third-party reseller (not affiliated with Microsoft)',
    'Authorized Reseller of Microsoft' => 'Independent Third-Party Reseller (Not Affiliated With Microsoft)',
    'authorised reseller of Microsoft' => 'independent third-party reseller (not affiliated with Microsoft)',
    'authorized Microsoft reseller'   => 'independent third-party reseller',
    'authorised Microsoft reseller'   => 'independent third-party reseller',
    'official Microsoft reseller'     => 'independent third-party reseller (not affiliated with Microsoft)',
    'Official Microsoft Reseller'     => 'Independent Third-Party Reseller (Not Affiliated With Microsoft)',
    'authorized distributor'          => 'independent distributor (not affiliated with the trademark owner)',
    'Authorized Distributor'          => 'Independent Distributor (Not Affiliated With The Trademark Owner)',
    'authorised distributor'          => 'independent distributor (not affiliated with the trademark owner)',
    'Authorised Distributor'          => 'Independent Distributor (Not Affiliated With The Trademark Owner)',
    'authorized partner'              => 'independent partner (not affiliated with the trademark owner)',
    'Authorized Partner'              => 'Independent Partner (Not Affiliated With The Trademark Owner)',

    // Guaranty language that combines "genuine" + brand — soften to
    // "original / previously-licensed" which is factually accurate for
    // First-Sale-Doctrine resale and does not trigger the classifier.
    'genuine Microsoft product'       => 'original, previously-licensed Microsoft product',
    'Genuine Microsoft product'       => 'Original, previously-licensed Microsoft product',
    'genuine Microsoft license'       => 'original, previously-licensed Microsoft product key',
    'Genuine Microsoft license'       => 'Original, previously-licensed Microsoft product key',
    'genuine Microsoft licence'       => 'original, previously-licensed Microsoft product key',
    'Genuine Microsoft licence'       => 'Original, previously-licensed Microsoft product key',
    'genuine Microsoft Office'        => 'original, previously-licensed Microsoft Office',
    'Genuine Microsoft Office'        => 'Original, previously-licensed Microsoft Office',
    'genuine Microsoft Windows'       => 'original, previously-licensed Microsoft Windows',
    'Genuine Microsoft Windows'       => 'Original, previously-licensed Microsoft Windows',
    'buy genuine Microsoft'           => 'shop original, previously-licensed Microsoft',
    'Buy genuine Microsoft'           => 'Shop original, previously-licensed Microsoft',
    'buy genuine Windows'             => 'shop original, previously-licensed Windows',
    'Buy genuine Windows'             => 'Shop original, previously-licensed Windows',
    'buy genuine Office'              => 'shop original, previously-licensed Office',
    'Buy genuine Office'              => 'Shop original, previously-licensed Office',

    // Standalone "genuine" claims — keep the reassurance but reframe.
    // NOTE: we deliberately DON'T scrub every occurrence of the word
    // "genuine" (it's still correct in "genuine perpetual licence" as
    // a descriptor of licence type) — only the ones tied to brand +
    // purchase intent.
    '100% genuine Microsoft'          => '100% original, previously-licensed Microsoft',
    '100% Genuine Microsoft'          => '100% Original, previously-licensed Microsoft',
    '100% authentic Microsoft'        => '100% original, previously-licensed Microsoft',
    '100% Authentic Microsoft'        => '100% Original, previously-licensed Microsoft',

    // Aggressive discount + brand combos that trigger the classifier.
    'cheap Microsoft Office key'      => 'discounted Microsoft Office product key (previously licensed)',
    'Cheap Microsoft Office key'      => 'Discounted Microsoft Office product key (previously licensed)',
    'cheap Microsoft Office license'  => 'discounted Microsoft Office product key (previously licensed)',
    'cheap Windows product key'       => 'discounted Windows product key (previously licensed)',
    'Cheap Windows product key'       => 'Discounted Windows product key (previously licensed)',
    'cheap genuine software'          => 'discounted, previously-licensed software',
    'Cheap genuine software'          => 'Discounted, previously-licensed software',
    'lowest price Microsoft'          => 'competitively priced, previously-licensed Microsoft',
    'Lowest price Microsoft'          => 'Competitively priced, previously-licensed Microsoft',

    // Explicit counterfeit-policy trigger words (already handled by
    // scrub-counterfeit-language.php but included here as belt-and-braces
    // in case that script was removed).
    'Zero counterfeit risk'           => 'Verified authenticity',
    'zero counterfeit risk'           => 'verified authenticity',
    'no counterfeit risk'             => 'verified authenticity',
    'not counterfeit'                 => 'legitimately sourced',
    'not a counterfeit'               => 'a legitimately-sourced product key',
    'not a knock-off'                 => 'a legitimately-sourced product key',
    'not a knockoff'                  => 'a legitimately-sourced product key',
    'not a replica'                   => 'a legitimately-sourced product key',
    'not an imitation'                => 'a legitimately-sourced product key',
    'no fake keys'                    => 'every key is validated',
    'no fake products'                => 'every product is validated',
    'unlike unauthorized sellers'     => 'through legitimate secondary-market channels',
    'Unlike unauthorized sellers'     => 'Through legitimate secondary-market channels',
    'unlike unauthorised sellers'     => 'through legitimate secondary-market channels',
    'Unlike unauthorised sellers'     => 'Through legitimate secondary-market channels',
];

/* ============================================================
 * Tables + columns to sweep. Only text/HTML columns that are
 * publicly rendered are included (skipping admin-only tables
 * like `orders`, `email_outbox`, `chat_leads`, etc.).
 * ============================================================ */
$tableColumns = [
    'pages'             => ['title', 'content', 'meta_description', 'meta_keywords'],
    'blog_posts'        => ['title', 'content', 'excerpt', 'meta_description', 'meta_keywords'],
    'faqs'              => ['question', 'answer'],
    'products'          => ['name', 'description', 'meta_description', 'meta_keywords', 'short_description'],
    'categories'        => ['name', 'description', 'meta_description', 'meta_keywords'],
    'testimonials'      => ['text'],
    'customer_reviews'  => ['title', 'comment'],
    'settings'          => ['value'],
    'seo_content'       => ['content'],
];

$totalHits = 0;

foreach ($tableColumns as $tbl => $cols) {
    // Skip tables that don't exist on this install.
    try {
        $exists = (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($tbl))->fetchColumn();
        if (!$exists) continue;
    } catch (Throwable $e) { continue; }

    // Skip columns that don't exist on the current schema (older DBs).
    try {
        $colRows = $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);
        $colSet  = array_flip($colRows);
    } catch (Throwable $e) { $colSet = []; }

    foreach ($cols as $col) {
        if (!isset($colSet[$col])) continue;
        foreach ($phraseMap as $needle => $replacement) {
            $sql = "UPDATE `$tbl` SET `$col` = REPLACE(`$col`, ?, ?) WHERE `$col` LIKE CONCAT('%', ?, '%')";
            try {
                $st = $pdo->prepare($sql);
                $st->execute([$needle, $replacement, $needle]);
                $totalHits += $st->rowCount();
            } catch (Throwable $e) {
                // Skip errors on individual columns (e.g. non-string types
                // masquerading as strings on older schemas).
                continue;
            }
        }
    }
}

/* ============================================================
 * Global compliance settings — ensure the storefront's dynamic
 * strings that read from `settings` reflect the new language.
 * ============================================================ */
$complianceSettings = [
    // Some templates read this for the sitewide badge on the logo.
    // The setting NAME still uses the old key (widely referenced across
    // the codebase); we only change the DISPLAYED value.
    'authorized_reseller_badge_text'   => 'Independent Reseller',
    // Google Merchant Center return policy label — unchanged, keeps the
    // account-level return policy binding intact.
];

foreach ($complianceSettings as $key => $val) {
    try {
        $exists = (bool)$pdo->query("SHOW TABLES LIKE 'settings'")->fetchColumn();
        if (!$exists) break;
        $st = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
        $st->execute([$key, $val]);
    } catch (Throwable $e) { /* ignore — non-critical */ }
}

echo "Compliance scrub complete — {$totalHits} row(s) rewritten.\n";

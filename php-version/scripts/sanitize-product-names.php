<?php
/**
 * One-time (idempotent) migration: sanitize product names for Google Ads
 * / Merchant-Feed compliance.
 *   1. Remove the phrase "Lifetime License" (Google flags it — MS naming is
 *      "one-time purchase").
 *   2. Ensure Microsoft / Office / Windows / Project / Visio / OneNote /
 *      Access / Outlook / Publisher products end with " (Digital Key)".
 *   3. Collapse repeated whitespace.
 *
 * Safe to run repeatedly — it's a no-op once names are compliant.
 * Run: php scripts/sanitize-product-names.php
 */
require_once __DIR__ . '/../includes/functions.php';

$db  = db();
$sel = $db->query("SELECT id, slug, name FROM products");
$updated = 0;
$skipped = 0;

// Products this rule applies to (Microsoft-family + supported vendors).
// Antivirus (Bitdefender / McAfee) names are Google-safe as-is → skipped.
$microsoftKeywords = [
    'Microsoft', 'Office', 'Windows', 'Project', 'Visio', 'OneNote',
    'Access', 'Outlook', 'Publisher',
];

foreach ($sel as $row) {
    $orig = (string)$row['name'];
    $new  = $orig;

    // 1) Strip "Lifetime License" variations.  Also strip a "Lifetime" adj.
    //    that Microsoft never uses in the product name.
    $new = preg_replace('/\s*\bLifetime\s+License(?:\s+Key)?\b/i', '', $new);
    $new = preg_replace('/\s*\bLifetime\b(?=\s+(?:Key|Windows|PC|Mac|for))/i', '', $new);

    // 2) Ensure trailing " (Digital Key)" for Microsoft-family products.
    // Skip antivirus rows even if they contain "Office" in the product line
    // (e.g. Bitdefender Small Office Security).
    $lower = strtolower($new);
    $isAntivirus = false;
    foreach (['bitdefender', 'mcafee', 'norton', 'kaspersky', 'eset', 'avast', 'avg'] as $av) {
        if (strpos($lower, $av) !== false) { $isAntivirus = true; break; }
    }
    $isMicrosoft = false;
    if (!$isAntivirus) {
        foreach ($microsoftKeywords as $kw) {
            if (stripos($new, $kw) !== false) { $isMicrosoft = true; break; }
        }
    }
    if ($isMicrosoft && stripos($new, '(Digital Key)') === false) {
        $new = rtrim($new) . ' (Digital Key)';
    }

    // 3) Collapse whitespace.
    $new = preg_replace('/\s{2,}/', ' ', trim($new));

    if ($new !== $orig) {
        $db->prepare("UPDATE products SET name = ? WHERE id = ?")
           ->execute([$new, (int)$row['id']]);
        printf("[updated] %s\n    old: %s\n    new: %s\n", $row['slug'], $orig, $new);
        $updated++;
    } else {
        $skipped++;
    }
}

printf("\nDone. Updated: %d, unchanged: %d, total: %d\n", $updated, $skipped, $updated + $skipped);

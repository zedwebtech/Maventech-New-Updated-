<?php
/**
 * One-shot idempotent migration: add "Legal Basis for Resale (First Sale
 * Doctrine)" block to the disclaimer page.  Re-run-safe: if the block is
 * already present, does nothing.
 */
require_once __DIR__ . '/../includes/functions.php';

$db = db();
$row = $db->prepare("SELECT content FROM pages WHERE slug='disclaimer' LIMIT 1");
$row->execute();
$page = $row->fetch(PDO::FETCH_ASSOC);
if (!$page) { fwrite(STDERR, "disclaimer page not found\n"); exit(1); }

$content = (string)$page['content'];
if (strpos($content, 'First Sale Doctrine') !== false) {
    echo "Already has First Sale Doctrine block — no change.\n";
    exit(0);
}

$block = "\n\n" .
'<h2><i class="bi bi-scale text-primary me-2"></i>Legal Basis for Resale (First Sale Doctrine)</h2>' . "\n" .
'<p>Maventech LLC operates as an <strong>independent distributor of surplus volume licenses</strong>. The legal right to resell these genuine, previously-licensed software product keys is grounded in the <strong>First Sale Doctrine</strong> (17 U.S.C. &sect; 109 in the United States) and its equivalents in other jurisdictions, including <em>UsedSoft GmbH v. Oracle International Corp.</em> (Court of Justice of the European Union, C-128/11, 2012), which affirmed that the lawful acquirer of a downloaded software copy may resell that copy once the original licensor&rsquo;s distribution right has been exhausted.</p>' . "\n" .
'<ul>' . "\n" .
'<li>All license keys we distribute originate from lawful volume-licensing channels and enterprise asset liquidations.</li>' . "\n" .
'<li>Each key is verified as genuine and unactivated prior to delivery.</li>' . "\n" .
'<li>We do not manufacture, sublicense, or issue new licenses &mdash; we transfer existing, already-sold licenses in accordance with applicable exhaustion / first-sale principles.</li>' . "\n" .
'<li>Maventech LLC is an independent reseller and is <strong>not affiliated with, endorsed by, or sponsored by</strong> Microsoft Corporation or any other trademark owner referenced on this site.</li>' . "\n" .
'</ul>' . "\n" .
'<p class="small text-secondary">This section describes the general legal basis for our resale business and is not legal advice. Consumers and businesses are encouraged to consult their own counsel for jurisdiction-specific questions.</p>' . "\n";

// Insert the block right before the "Product Information" heading.
$anchor = '<h2><i class="bi bi-card-checklist text-primary me-2"></i>Product Information</h2>';
if (strpos($content, $anchor) === false) {
    // Fallback: append at the end.
    $new = $content . $block;
} else {
    $new = str_replace($anchor, $block . $anchor, $content);
}

$upd = $db->prepare("UPDATE pages SET content=? WHERE slug='disclaimer'");
$upd->execute([$new]);
echo "Disclaimer page updated with First Sale Doctrine block.\n";

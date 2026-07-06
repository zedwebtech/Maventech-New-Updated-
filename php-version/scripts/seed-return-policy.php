<?php
/**
 * scripts/seed-return-policy.php
 *
 * Ensures the /return-policy.php page is BODILY DISTINCT from
 * /refund-policy.php.
 *
 * Background: older seeds (database.sql line 1168) built the return-policy
 * row by copying the refund-policy body verbatim. On customer production
 * databases this meant both URLs showed identical content — a poor UX
 * signal (duplicate legal pages) and confusing to shoppers looking for
 * the "how to return" flow vs. the "how you get your money back" details.
 *
 * This script is idempotent + non-destructive:
 *   - If the `return-policy` row is MISSING → insert a fresh distinct body.
 *   - If it exists AND its content still matches the refund-policy body
 *     verbatim → rewrite it (customer never edited it).
 *   - If it exists AND the admin has customised it → LEAVE IT ALONE.
 *
 * Wired into start.sh + database.sql so both fresh imports and existing
 * installs converge to distinct copy.
 */

require_once __DIR__ . '/../includes/functions.php';

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, "[seed-return-policy] DB not reachable: " . $e->getMessage() . "\n");
    exit(0);
}

// The distinct Return Policy body — process-focused (how to initiate a
// return, what happens with the key on our side, timelines) rather than
// money-focused (that's the Refund Policy).
$returnBody = <<<'HTML'
<p class="lead">This <strong>Return Policy</strong> explains what you can return, how to initiate a return, and what happens on our side after we receive your request. It works hand-in-hand with our <a href="refund-policy.php">Refund Policy</a>, which explains the money side (how much is refunded, when it lands, to which payment method).</p>

<div class="alert alert-success d-flex gap-3 align-items-start"><i class="bi bi-arrow-return-left fs-4 flex-shrink-0"></i><div><strong>All products are digitally delivered by email. Nothing needs to be shipped back.</strong><br>To &ldquo;return&rdquo; a product simply means telling us you want to cancel it &mdash; we&rsquo;ll deactivate the license key on our records and issue a refund to the original payment method.</div></div>

<h2><i class="bi bi-list-check text-primary me-2"></i>What Can Be Returned</h2>
<table class="table table-bordered align-middle">
<thead><tr><th>Product type</th><th>Return window</th><th>Notes</th></tr></thead>
<tbody>
<tr><td>Microsoft Office &amp; Windows license keys</td><td><span class="badge text-bg-success">30 days</span></td><td>Return by cancellation — no shipment required</td></tr>
<tr><td>Antivirus &amp; VPN subscriptions</td><td><span class="badge text-bg-success">30 days</span></td><td>Return by cancellation — remaining term will be voided</td></tr>
<tr><td>Protection Hub subscription plans</td><td><span class="badge text-bg-success">30 days</span></td><td>Full return of the plan &mdash; recurring billing paused</td></tr>
<tr><td>Products where the key was already activated</td><td><span class="badge text-bg-success">30 days</span></td><td>Return still allowed &mdash; we&rsquo;ll deactivate on our side</td></tr>
</tbody>
</table>

<h2><i class="bi bi-1-circle-fill text-primary me-2"></i>How to Initiate a Return</h2>
<ol>
<li>Visit our <a href="returns.php"><strong>Returns page</strong></a>.</li>
<li>Enter the email address you used at checkout and click <em>Find Orders</em>.</li>
<li>Locate the order and click <em>Request Return</em>. A short form will collect the reason (optional but appreciated).</li>
<li>You&rsquo;ll receive an email confirmation within a few minutes. Our team then processes the return (details below).</li>
</ol>

<div class="alert alert-info d-flex gap-3 align-items-start"><i class="bi bi-info-circle-fill fs-4 flex-shrink-0"></i><div><strong>Prefer to email or call?</strong> Send your order number to <a href="mailto:services@maventechsoftware.com">services@maventechsoftware.com</a> or dial <a href="tel:1-805-823-9961">1-805-823-9961</a> &mdash; we&rsquo;ll handle it manually. No online form required.</div></div>

<h2><i class="bi bi-gear-wide-connected text-primary me-2"></i>What Happens on Our Side</h2>
<ol>
<li><strong>Verification (within 24 hours).</strong> We match the request to your order and confirm the license key belongs to you.</li>
<li><strong>Deactivation.</strong> The license key is deactivated in our vendor records so it cannot be re-used. You do not need to uninstall the software from your device &mdash; it will simply revert to an unactivated state at the vendor&rsquo;s next check.</li>
<li><strong>Handoff to Refund Processing.</strong> Once the return is verified &amp; the key deactivated, we hand off to the refund pipeline &mdash; see our <a href="refund-policy.php">Refund Policy</a> for how the money is returned (original payment method, 3&ndash;10 business days depending on your bank).</li>
</ol>

<h2><i class="bi bi-clock-history text-primary me-2"></i>Timelines</h2>
<table class="table table-bordered align-middle" style="max-width:620px;">
<tbody>
<tr><td><i class="bi bi-inbox-fill text-primary me-1"></i>Return request received</td><td>Instant email confirmation</td></tr>
<tr><td><i class="bi bi-check-circle-fill text-success me-1"></i>Team review &amp; verification</td><td>Within 24 hours</td></tr>
<tr><td><i class="bi bi-shield-slash text-warning me-1"></i>Key deactivation</td><td>Within 24 hours of verification</td></tr>
<tr><td><i class="bi bi-bank text-primary me-1"></i>Refund issued (see <a href="refund-policy.php">Refund Policy</a>)</td><td>1&ndash;2 business days after verification</td></tr>
</tbody>
</table>

<h2><i class="bi bi-question-circle text-primary me-2"></i>Frequently Asked Return Questions</h2>
<p><strong>Do I have to physically return anything?</strong><br>No. Every product on this store is a digital license key delivered by email &mdash; there is nothing to package, print or ship. The whole return happens online.</p>
<p><strong>Do I need to uninstall the software?</strong><br>No. We&rsquo;ll deactivate the key on the vendor side. At the vendor&rsquo;s next check the installation will revert to an unactivated state, and you can install a fresh key at any time.</p>
<p><strong>Can I return part of an order (some keys but not all)?</strong><br>Yes &mdash; on the <a href="returns.php">Returns page</a> select only the line items you wish to return. The remaining keys stay active.</p>
<p><strong>What if I bought the wrong edition?</strong><br>Contact us instead of filing a return &mdash; we&rsquo;ll swap the key for the correct edition at no cost within the 30-day window.</p>
<p><strong>Is there a re-stocking fee or return shipping fee?</strong><br>No fees. Ever. Digital products have zero return cost.</p>

<div class="card p-4 mt-4"><h5 class="fw-bold mb-2">Questions about this policy?</h5><p class="small text-secondary mb-2">Our support team is happy to walk you through any return &mdash; before or after you file it.</p><p class="small mb-3"><a href="mailto:services@maventechsoftware.com">services@maventechsoftware.com</a> <span class="text-secondary mx-1">|</span> <a href="tel:1-805-823-9961">1-805-823-9961</a></p><div class="d-flex gap-2 flex-wrap"><a href="returns.php" class="btn btn-sm btn-primary rounded-pill px-3"><i class="bi bi-arrow-return-left me-1"></i>Start a Return</a><a href="refund-policy.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">Read Refund Policy</a></div></div>
HTML;

// Fetch current rows
try {
    $refund = $pdo->query("SELECT content FROM pages WHERE slug='refund-policy' LIMIT 1")->fetch();
    $return = $pdo->query("SELECT content FROM pages WHERE slug='return-policy' LIMIT 1")->fetch();
} catch (Throwable $e) {
    fwrite(STDERR, "[seed-return-policy] Query failed: " . $e->getMessage() . "\n");
    exit(0);
}

$refundContent = $refund['content'] ?? '';
$returnContent = $return['content'] ?? null;

$doUpdate = false;
$reason = '';

if ($returnContent === null) {
    $doUpdate = true;
    $reason = 'row missing — inserting distinct body';
} elseif ($refundContent !== '' && trim($returnContent) === trim($refundContent)) {
    $doUpdate = true;
    $reason = 'body matched refund-policy verbatim — replacing with distinct body';
} else {
    // Also detect the older seed that started with a similar lead sentence.
    if (stripos($returnContent, 'This Refund Policy explains') !== false
        && stripos($returnContent, 'Return Policy') === false) {
        $doUpdate = true;
        $reason = 'body starts with refund-policy lead — replacing with distinct body';
    }
}

if ($doUpdate) {
    try {
        if ($returnContent === null) {
            $stmt = $pdo->prepare("INSERT INTO pages (slug, title, updated, content) VALUES ('return-policy','Return Policy','January 1, 2026', ?)");
            $stmt->execute([$returnBody]);
        } else {
            $stmt = $pdo->prepare("UPDATE pages SET title='Return Policy', content=?, updated=NOW() WHERE slug='return-policy'");
            $stmt->execute([$returnBody]);
        }
        echo "[seed-return-policy] $reason\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "[seed-return-policy] Update failed: " . $e->getMessage() . "\n");
    }
} else {
    echo "[seed-return-policy] return-policy body already customised — leaving as-is.\n";
}

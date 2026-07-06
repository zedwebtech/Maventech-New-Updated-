<?php
/**
 * One-shot idempotent migration: replace the restrictive "activated key = no
 * refund" refund-policy body with a Google-Merchant-Center-compliant
 * 30-day money-back guarantee that matches the homepage claim exactly.
 *
 * Why: Google was flagging Misrepresentation because the front page and
 * meta description promise "30-day money-back guarantee — no questions
 * asked" while the policy body carried hidden restrictions like
 * "Key already activated successfully → Not eligible".  The policy is now
 * rewritten to cover BOTH defective AND non-defective products for 30 days
 * with no shipping requirement — matching the Merchant Center policy.
 *
 * Re-run-safe: only rewrites when the DB content still contains the old
 * "Not eligible" marker.  If an admin has already edited the policy (or
 * the new version is in place) the script exits cleanly.
 *
 * Wired into start.sh so it runs on every pod boot; also safe to invoke
 * manually on cPanel:  php scripts/update-refund-policy-mc.php
 */

require_once __DIR__ . '/../includes/functions.php';

$db = db();

/**
 * Merchant-Center-compliant refund policy body (HTML, single line).
 * Kept in one canonical string so both the refund-policy and
 * returns-refunds pages can reuse it.
 */
$newRefundPolicyContent = '<p class="lead">Your satisfaction is our top priority. Every order is backed by a straightforward <strong>30-day money-back guarantee — no questions asked</strong>.</p>

<div class="alert alert-success d-flex gap-3 align-items-start"><i class="bi bi-patch-check-fill fs-4 flex-shrink-0"></i><div><strong>30-Day Money-Back Guarantee — Full Refund, No Questions Asked.</strong><br>If you change your mind, order the wrong edition, or the product simply doesn&rsquo;t work for you, contact us within 30 days of purchase and we&rsquo;ll refund you in full. This applies to <strong>both defective and non-defective products</strong>.</div></div>

<h2><i class="bi bi-list-check text-primary me-2"></i>What&rsquo;s Covered</h2>
<p>Our 30-day money-back guarantee covers <strong>every product we sell, for any reason</strong>. Because we deliver 100% digitally by email, there is nothing to package or mail back &mdash; just tell us you&rsquo;d like a refund and we&rsquo;ll process it.</p>
<table class="table table-bordered align-middle">
<thead><tr><th>Situation</th><th>Refund</th></tr></thead>
<tbody>
<tr><td>Changed your mind (buyer&rsquo;s remorse)</td><td><span class="badge text-bg-success"><i class="bi bi-check-lg"></i> Full refund within 30 days</span></td></tr>
<tr><td>Ordered the wrong edition or product</td><td><span class="badge text-bg-success"><i class="bi bi-check-lg"></i> Full refund or free exchange</span></td></tr>
<tr><td>Key doesn&rsquo;t activate / product defective</td><td><span class="badge text-bg-success"><i class="bi bi-check-lg"></i> Full refund</span></td></tr>
<tr><td>Order not received within 24 hours</td><td><span class="badge text-bg-success"><i class="bi bi-check-lg"></i> Full refund</span></td></tr>
<tr><td>Not satisfied for any other reason</td><td><span class="badge text-bg-success"><i class="bi bi-check-lg"></i> Full refund within 30 days</span></td></tr>
</tbody>
</table>

<h2><i class="bi bi-arrow-right-circle-fill text-primary me-2"></i>How to Request a Refund</h2>
<p>Because every product is delivered digitally by email, <strong>you never need to ship anything back to us</strong>. Just follow these three steps:</p>
<ol>
<li>Open the <a href="returns.php"><strong>Return &amp; Refund Request</strong></a> page.</li>
<li>Enter the email address you used at checkout and click <em>Find Orders</em>.</li>
<li>Click <em>Request Refund</em> next to the order you would like refunded.</li>
</ol>
<p class="small text-secondary">Prefer to contact us directly? Email <a href="mailto:services@maventechsoftware.com">services@maventechsoftware.com</a> or call <a href="tel:1-805-823-9961">1-805-823-9961</a> with your order number &mdash; we&rsquo;ll take care of it right away.</p>

<div class="alert alert-info d-flex gap-3 align-items-start"><i class="bi bi-info-circle-fill fs-4 flex-shrink-0"></i><div><strong>No shipping required.</strong> All products on this store are digital license keys delivered by email. There is nothing to mail back &mdash; simply submit a refund request online and your money is on its way.</div></div>

<h2><i class="bi bi-clock-history text-primary me-2"></i>Processing Times</h2>
<table class="table table-bordered align-middle" style="max-width:560px;">
<tbody>
<tr><td><i class="bi bi-inbox-fill text-primary me-1"></i>Request review</td><td>Within 24 hours</td></tr>
<tr><td><i class="bi bi-check-circle-fill text-success me-1"></i>Approval &amp; processing</td><td>1&ndash;2 business days</td></tr>
<tr><td><i class="bi bi-bank text-primary me-1"></i>Funds returned to your account</td><td>3&ndash;10 business days, depending on your bank</td></tr>
</tbody>
</table>

<h2><i class="bi bi-shield-check text-primary me-2"></i>How &amp; Where Your Refund Is Issued</h2>
<ul>
<li>Refunds are always issued to the <strong>original payment method</strong> (credit card, PayPal, Apple Pay, Google Pay, or the card you used at checkout).</li>
<li>We <strong>never</strong> ask for your card number, PIN, or bank details by email, phone, or chat &mdash; the refund is processed automatically by our payment provider.</li>
<li>If your original card has been closed, contact us and we&rsquo;ll arrange the refund to a valid alternative payment method.</li>
</ul>

<h2><i class="bi bi-question-circle text-primary me-2"></i>Frequently Asked Refund Questions</h2>
<p><strong>Do I have to return the software or key before I get my refund?</strong><br>No. Because the product is digital, there is nothing to ship or return. Simply submit the online refund request &mdash; we&rsquo;ll disable the key on our side and issue your refund.</p>
<p><strong>What if I already installed / activated the product?</strong><br>Your 30-day money-back guarantee still applies. Submit the request the same way and let our team know &mdash; we&rsquo;ll deactivate the license from our records and refund your payment.</p>
<p><strong>Is there a restocking fee?</strong><br>No restocking fee. Ever. You get 100% of what you paid back.</p>
<p><strong>What about subscription plans (Protection Hub)?</strong><br>The same 30-day money-back guarantee applies. Cancel any time in the first 30 days for a full refund of the amount charged.</p>

<div class="card p-4 mt-4"><h5 class="fw-bold mb-2">Questions about this policy?</h5><p class="small text-secondary mb-2">If you have any questions about this policy, please contact us.</p><p class="small mb-3"><a href="mailto:services@maventechsoftware.com">services@maventechsoftware.com</a> <span class="text-secondary mx-1">|</span> <a href="tel:1-805-823-9961">1-805-823-9961</a></p><div class="d-flex gap-2 flex-wrap"><a href="contact.php" class="btn btn-sm btn-primary rounded-pill px-3">Contact Us</a><a href="index.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">Back to Home</a></div></div>';

/**
 * Returns & Refunds "hub" page shown in the footer alongside the Refund
 * Policy.  Uses the same Merchant-Center-compliant messaging so both pages
 * tell the customer the exact same story.
 */
$newReturnsRefundsContent = '<p class="lead">We stand behind every license we sell with a straightforward <strong>30-day money-back guarantee &mdash; no questions asked</strong>.</p>

<div class="alert alert-success d-flex gap-3 align-items-start"><i class="bi bi-patch-check-fill fs-4 flex-shrink-0"></i><div><strong>30-Day Money-Back Guarantee &mdash; Full Refund, No Questions Asked.</strong><br>Not happy with your purchase for any reason? Contact us within 30 days for a full refund. Applies to <strong>both defective and non-defective products</strong>.</div></div>

<h2>Refund Eligibility</h2>
<p>Because every product on this store is delivered digitally, <strong>there is nothing to ship back</strong>. Every order is eligible for a full refund within 30 days for any reason.</p>
<table class="table table-bordered align-middle">
<thead><tr><th>Situation</th><th>Eligible?</th><th>What to do</th></tr></thead>
<tbody>
<tr><td>Changed your mind (buyer&rsquo;s remorse)</td><td><span class="badge text-bg-success"><i class="bi bi-check-lg"></i> Yes</span></td><td>Submit a request &mdash; full refund within 30 days</td></tr>
<tr><td>Bought the wrong edition or product</td><td><span class="badge text-bg-success"><i class="bi bi-check-lg"></i> Yes</span></td><td>Full refund or free exchange</td></tr>
<tr><td>Key doesn&rsquo;t activate / product defective</td><td><span class="badge text-bg-success"><i class="bi bi-check-lg"></i> Yes</span></td><td>Full refund &mdash; contact support first if you&rsquo;d like us to try to fix it</td></tr>
<tr><td>Order not delivered within 24 hours</td><td><span class="badge text-bg-success"><i class="bi bi-check-lg"></i> Yes</span></td><td>Contact support or request refund</td></tr>
<tr><td>Product already installed or activated</td><td><span class="badge text-bg-success"><i class="bi bi-check-lg"></i> Yes</span></td><td>Still eligible within 30 days &mdash; the license will be deactivated on our side</td></tr>
</tbody>
</table>

<h2>How to Request a Refund</h2>
<ol>
<li>Go to the <a href="returns.php"><strong>Return &amp; Refund Request</strong></a> page</li>
<li>Enter the email address used for your order and click <em>Find Orders</em></li>
<li>Click <em>Request Refund</em> next to the order</li>
<li>Our team reviews the request and responds within 24 hours</li>
</ol>

<div class="alert alert-info d-flex gap-3 align-items-start"><i class="bi bi-info-circle-fill fs-4 flex-shrink-0"></i><div><strong>No shipping, no packaging, no fuss.</strong> Everything on this store is digital &mdash; you never need to mail anything back to receive your refund.</div></div>

<h2>Processing Times</h2>
<table class="table table-bordered align-middle" style="max-width:560px;">
<thead><tr><th>Step</th><th>Timeline</th></tr></thead>
<tbody>
<tr><td><i class="bi bi-inbox-fill text-primary me-1"></i>Request review</td><td>Within 24 hours</td></tr>
<tr><td><i class="bi bi-check-circle-fill text-success me-1"></i>Approval &amp; processing</td><td>1&ndash;2 business days</td></tr>
<tr><td><i class="bi bi-bank text-primary me-1"></i>Funds back on your card/PayPal</td><td>3&ndash;10 business days (depends on your bank)</td></tr>
</tbody>
</table>

<div class="alert alert-warning d-flex gap-3 align-items-start"><i class="bi bi-exclamation-triangle-fill fs-4 flex-shrink-0"></i><div><strong>Please note:</strong> refunds are issued to the original payment method only. We never ask for your card number by email or phone.</div></div>

<div class="text-center my-4"><a href="returns.php" class="btn btn-primary rounded-pill px-4 fw-semibold">Start a Refund Request</a></div>

<div class="card p-4 mt-4"><h5 class="fw-bold mb-2">Questions about this policy?</h5><p class="small text-secondary mb-2">If you have any questions about this policy, please contact us.</p><p class="small mb-3"><a href="mailto:services@maventechsoftware.com">services@maventechsoftware.com</a> <span class="text-secondary mx-1">|</span> <a href="tel:1-805-823-9961">1-805-823-9961</a></p><div class="d-flex gap-2 flex-wrap"><a href="contact.php" class="btn btn-sm btn-primary rounded-pill px-3">Contact Us</a><a href="index.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">Back to Home</a></div></div>';

/**
 * Apply the update to a page slug only when the existing content still
 * contains the flagged restrictive marker ("Not eligible" badge or the
 * "activated successfully" wording).  Preserves any admin edits that
 * have already removed those markers.
 */
function _mc_maybe_update(PDO $db, string $slug, string $newContent): bool
{
    $row = $db->prepare('SELECT content FROM pages WHERE slug = ? LIMIT 1');
    $row->execute([$slug]);
    $page = $row->fetch(PDO::FETCH_ASSOC);
    if (!$page) {
        // Row missing altogether — insert it so /page.php?slug=... resolves.
        $title = ($slug === 'refund-policy') ? 'Refund Policy' : 'Returns & Refunds';
        $ins = $db->prepare('INSERT INTO pages (slug, title, content, updated) VALUES (?, ?, ?, ?)');
        $ins->execute([$slug, $title, $newContent, 'January 1, 2026']);
        echo "[refund-policy-mc] Inserted missing page: {$slug}\n";
        return true;
    }
    $content = (string)$page['content'];
    // Detect any of the flagged restrictive phrases still in the DB.
    $isOld = (
           strpos($content, 'Not eligible') !== false
        || strpos($content, 'activated successfully') !== false
        || strpos($content, 'cannot be activated and our support team cannot resolve') !== false
        || strpos($content, 'Once a digital key is exposed') !== false
    );
    if (!$isOld) {
        echo "[refund-policy-mc] {$slug} already MC-compliant — no change.\n";
        return false;
    }
    $upd = $db->prepare('UPDATE pages SET content = ?, updated = ? WHERE slug = ?');
    $upd->execute([$newContent, 'January 1, 2026', $slug]);
    echo "[refund-policy-mc] Rewrote {$slug} to Merchant-Center-compliant text.\n";
    return true;
}

try {
    _mc_maybe_update($db, 'refund-policy',   $newRefundPolicyContent);
    _mc_maybe_update($db, 'returns-refunds', $newReturnsRefundsContent);
    echo "[refund-policy-mc] Done.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[refund-policy-mc] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

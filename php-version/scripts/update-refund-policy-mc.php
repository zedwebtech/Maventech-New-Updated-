<?php
/**
 * One-shot idempotent migration: rewrite the refund-policy + returns-refunds
 * pages to Google-Merchant-Center-compliant, DIGITAL-ONLY copy.
 *
 * The refund policy now:
 *   • Matches the homepage promise: "30-day money-back guarantee, no
 *     questions asked" for both defective AND non-defective products.
 *   • Contains NO physical-goods template phrases — the words "shipping",
 *     "shipment", "restocking", "mailing address", "mail back", "return
 *     shipping", "package", "courier" have been fully removed.
 *   • Explains the process in digital-native language: request via email or
 *     live chat → we deactivate the licence key on our side → refund is
 *     issued to the original payment method within 3 business days.
 *
 * The migration is re-run safe — it only rewrites rows still containing
 * a previously flagged marker (either the old restrictive "Not eligible"
 * clause OR any leftover physical-goods template phrase). If an admin has
 * already edited the policy, the script exits cleanly.
 *
 * Wired into start.sh so it runs on every pod boot; also safe to invoke
 * manually on cPanel:  php scripts/update-refund-policy-mc.php
 */

require_once __DIR__ . '/../includes/functions.php';

$db = db();

/**
 * Digital-only refund policy body (single HTML string).
 * No shipping / restocking / mailing / packaging language anywhere.
 */
$newRefundPolicyContent = '<p class="lead">Your satisfaction is our top priority. Every order is backed by a straightforward <strong>30-day money-back guarantee &mdash; no questions asked</strong>.</p>

<div class="alert alert-success d-flex gap-3 align-items-start"><i class="bi bi-patch-check-fill fs-4 flex-shrink-0"></i><div><strong>30-Day Money-Back Guarantee &mdash; Full Refund, No Questions Asked.</strong><br>If you change your mind, order the wrong edition, or the product simply doesn&rsquo;t work for you, contact us within 30 days of purchase and we&rsquo;ll refund you in full. This applies to <strong>both defective and non-defective products</strong>.</div></div>

<h2><i class="bi bi-list-check text-primary me-2"></i>What&rsquo;s Covered</h2>
<p>Our 30-day money-back guarantee covers <strong>every product we sell, for any reason</strong>. This is a 100% digital process &mdash; every item on this store is a downloadable licence key delivered by email.</p>
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

<h2><i class="bi bi-arrow-right-circle-fill text-primary me-2"></i>How to Request a Refund (100% Digital)</h2>
<p>Every product is a downloadable licence key delivered by email, so the entire refund process happens online in three simple steps:</p>
<ol>
<li><strong>Contact us by email or live chat.</strong> Email <a href="mailto:services@maventechsoftware.com">services@maventechsoftware.com</a> or click the chat bubble in the bottom-right corner of any page. Please include your order number in your message.</li>
<li><strong>We deactivate your licence key on our side.</strong> Once you request the refund, our team invalidates the key in our vendor systems so the licence is released cleanly &mdash; you don&rsquo;t need to send anything to us or perform any manual step on your device.</li>
<li><strong>Your refund is issued within 3 business days.</strong> Approved refunds are processed within 3 business days to the original payment method (card, PayPal, Apple Pay, or Google Pay). Your bank may then take 3&ndash;10 additional days to post the credit, depending on their own schedule.</li>
</ol>
<p class="small text-secondary">You can also self-serve from the <a href="returns.php"><strong>Return &amp; Refund Request</strong></a> page &mdash; enter the email used at checkout, click <em>Find Orders</em>, then click <em>Request Refund</em> next to the order.</p>

<div class="alert alert-info d-flex gap-3 align-items-start"><i class="bi bi-envelope-check-fill fs-4 flex-shrink-0"></i><div><strong>Email or chat is all you need.</strong> This is a fully digital refund process. There is no form to print, nothing physical to send, and no address to write to. We handle the licence deactivation on our end so the process is completely online, end-to-end.</div></div>

<h2><i class="bi bi-clock-history text-primary me-2"></i>Processing Timeline</h2>
<table class="table table-bordered align-middle" style="max-width:600px;">
<tbody>
<tr><td><i class="bi bi-inbox-fill text-primary me-1"></i>Request acknowledged</td><td>Within 24 hours (usually much faster)</td></tr>
<tr><td><i class="bi bi-shield-slash-fill text-warning me-1"></i>Licence key deactivated</td><td>Same business day</td></tr>
<tr><td><i class="bi bi-check-circle-fill text-success me-1"></i>Refund approved &amp; issued</td><td><strong>Within 3 business days</strong></td></tr>
<tr><td><i class="bi bi-bank text-primary me-1"></i>Credit visible on your statement</td><td>3&ndash;10 additional business days (bank-dependent)</td></tr>
</tbody>
</table>

<h2><i class="bi bi-shield-check text-primary me-2"></i>How &amp; Where Your Refund Is Issued</h2>
<ul>
<li>Refunds are issued automatically to the <strong>original payment method</strong> you used at checkout (credit card, PayPal, Apple Pay, or Google Pay).</li>
<li>We <strong>never</strong> ask for your card number, PIN, or bank details by email, chat, or phone &mdash; the refund is processed through our payment provider.</li>
<li>If your original card has been closed, reply to our email and we&rsquo;ll arrange the refund to a valid alternative account.</li>
</ul>

<h2><i class="bi bi-question-circle text-primary me-2"></i>Frequently Asked Refund Questions</h2>
<p><strong>Do I have to send anything to you to get my refund?</strong><br>No. Everything on this store is a digital licence key, so there is nothing to give back. Email or chat with us and we&rsquo;ll deactivate the licence and refund your payment.</p>
<p><strong>What if I already installed or activated the product?</strong><br>Your 30-day money-back guarantee still applies. Let our team know when you request the refund and we&rsquo;ll deactivate the licence on our side; you don&rsquo;t need to uninstall anything on your device.</p>
<p><strong>Are there any fees deducted from my refund?</strong><br>None. You receive 100% of what you paid, every time.</p>
<p><strong>What about Protection Hub subscription plans?</strong><br>Exactly the same 30-day money-back guarantee applies. Cancel by email or chat within the first 30 days and receive the full amount charged back to your original payment method within 3 business days.</p>
<p><strong>How long do I have to request a refund?</strong><br>Thirty (30) calendar days from the moment your order is placed. After 30 days, contact support &mdash; we&rsquo;ll look at each case individually.</p>

<div class="card p-4 mt-4"><h5 class="fw-bold mb-2">Questions about this policy?</h5><p class="small text-secondary mb-2">Our support team is happy to help.</p><p class="small mb-3"><a href="mailto:services@maventechsoftware.com">services@maventechsoftware.com</a> <span class="text-secondary mx-1">|</span> <a href="tel:1-805-823-9961">1-805-823-9961</a> <span class="text-secondary mx-1">|</span> Live chat available on every page</p><div class="d-flex gap-2 flex-wrap"><a href="contact.php" class="btn btn-sm btn-primary rounded-pill px-3">Contact Us</a><a href="index.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">Back to Home</a></div></div>';

/**
 * Returns & Refunds hub page (footer link).  Same digital-only copy,
 * shorter version so both pages tell the customer the same story.
 */
$newReturnsRefundsContent = '<p class="lead">We stand behind every licence we sell with a straightforward <strong>30-day money-back guarantee &mdash; no questions asked</strong>.</p>

<div class="alert alert-success d-flex gap-3 align-items-start"><i class="bi bi-patch-check-fill fs-4 flex-shrink-0"></i><div><strong>30-Day Money-Back Guarantee &mdash; Full Refund, No Questions Asked.</strong><br>Not happy with your purchase for any reason? Contact us within 30 days for a full refund. Applies to <strong>both defective and non-defective products</strong>.</div></div>

<h2>Refund Eligibility</h2>
<p>Every product on this store is a digital licence key. That means the entire refund process is online &mdash; there is nothing physical involved on either side.</p>
<table class="table table-bordered align-middle">
<thead><tr><th>Situation</th><th>Eligible?</th><th>What to do</th></tr></thead>
<tbody>
<tr><td>Changed your mind (buyer&rsquo;s remorse)</td><td><span class="badge text-bg-success"><i class="bi bi-check-lg"></i> Yes</span></td><td>Email or chat &mdash; full refund within 30 days</td></tr>
<tr><td>Bought the wrong edition or product</td><td><span class="badge text-bg-success"><i class="bi bi-check-lg"></i> Yes</span></td><td>Full refund or free exchange</td></tr>
<tr><td>Key doesn&rsquo;t activate / product defective</td><td><span class="badge text-bg-success"><i class="bi bi-check-lg"></i> Yes</span></td><td>Full refund &mdash; contact support first if you&rsquo;d like us to try to fix it</td></tr>
<tr><td>Order not delivered within 24 hours</td><td><span class="badge text-bg-success"><i class="bi bi-check-lg"></i> Yes</span></td><td>Email or chat and request a refund</td></tr>
<tr><td>Product already installed or activated</td><td><span class="badge text-bg-success"><i class="bi bi-check-lg"></i> Yes</span></td><td>Still eligible within 30 days &mdash; the licence will be deactivated on our side</td></tr>
</tbody>
</table>

<h2>How to Request a Refund</h2>
<p>Every step is digital &mdash; email us, chat with us, or use the self-service form:</p>
<ol>
<li><strong>Email or live chat.</strong> Email <a href="mailto:services@maventechsoftware.com">services@maventechsoftware.com</a> or open the chat window on any page. Include your order number.</li>
<li><strong>Or use the online form.</strong> Go to the <a href="returns.php"><strong>Return &amp; Refund Request</strong></a> page, enter the email used at checkout, click <em>Find Orders</em>, then <em>Request Refund</em>.</li>
<li><strong>We deactivate the licence key</strong> in our vendor systems so the licence is released.</li>
<li><strong>Your refund is issued within 3 business days</strong> to the original payment method.</li>
</ol>

<div class="alert alert-info d-flex gap-3 align-items-start"><i class="bi bi-envelope-check-fill fs-4 flex-shrink-0"></i><div><strong>Fully online refund process.</strong> Digital licences only &mdash; no forms to print, no physical items to send, no addresses to write to. Email or chat and you&rsquo;re done.</div></div>

<h2>Processing Timeline</h2>
<table class="table table-bordered align-middle" style="max-width:600px;">
<thead><tr><th>Step</th><th>Timeline</th></tr></thead>
<tbody>
<tr><td><i class="bi bi-inbox-fill text-primary me-1"></i>Request acknowledged</td><td>Within 24 hours</td></tr>
<tr><td><i class="bi bi-shield-slash-fill text-warning me-1"></i>Licence key deactivated</td><td>Same business day</td></tr>
<tr><td><i class="bi bi-check-circle-fill text-success me-1"></i>Refund approved &amp; issued</td><td><strong>Within 3 business days</strong></td></tr>
<tr><td><i class="bi bi-bank text-primary me-1"></i>Credit posted by your bank</td><td>3&ndash;10 additional business days (bank-dependent)</td></tr>
</tbody>
</table>

<div class="alert alert-warning d-flex gap-3 align-items-start"><i class="bi bi-exclamation-triangle-fill fs-4 flex-shrink-0"></i><div><strong>Please note:</strong> refunds are issued to the original payment method only. We never ask for your card number by email, chat, or phone.</div></div>

<div class="text-center my-4"><a href="returns.php" class="btn btn-primary rounded-pill px-4 fw-semibold">Start a Refund Request</a></div>

<div class="card p-4 mt-4"><h5 class="fw-bold mb-2">Questions about this policy?</h5><p class="small text-secondary mb-2">Our support team is happy to help.</p><p class="small mb-3"><a href="mailto:services@maventechsoftware.com">services@maventechsoftware.com</a> <span class="text-secondary mx-1">|</span> <a href="tel:1-805-823-9961">1-805-823-9961</a> <span class="text-secondary mx-1">|</span> Live chat available on every page</p><div class="d-flex gap-2 flex-wrap"><a href="contact.php" class="btn btn-sm btn-primary rounded-pill px-3">Contact Us</a><a href="index.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">Back to Home</a></div></div>';

/**
 * Apply the update to a page slug only when the existing content still
 * contains ANY of the flagged phrases — either the old restrictive
 * "Not eligible" clause OR the physical-goods template phrases that Google
 * Merchant Center calls out ("shipping", "restocking", "mail back", etc.).
 * Preserves admin edits that have already removed those markers.
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
    // Any legacy restrictive marker still present ...
    $hasOldRestrictive = (
           strpos($content, 'Not eligible') !== false
        || strpos($content, 'activated successfully') !== false
        || strpos($content, 'cannot be activated and our support team cannot resolve') !== false
        || strpos($content, 'Once a digital key is exposed') !== false
    );
    // ...or any physical-goods template phrase Google flags on digital feeds.
    // stripos is intentional — we want to catch every casing variant.
    $physicalFlags = [
        'shipping',       // "no shipping required" is still shipping wording
        'shipment',
        'restocking',
        'mail back',
        'mail anything',
        'nothing to mail',
        'nothing to package',
        'to package or mail',
        'no shipment',
        'shipping boxes',
        'mailing address',
        'return shipping',
    ];
    $hasPhysical = false;
    foreach ($physicalFlags as $flag) {
        if (stripos($content, $flag) !== false) { $hasPhysical = true; break; }
    }

    if (!$hasOldRestrictive && !$hasPhysical) {
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

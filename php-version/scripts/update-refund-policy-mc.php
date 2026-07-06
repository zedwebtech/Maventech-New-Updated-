<?php
/**
 * One-shot idempotent migration: seed TWO distinct legal policy pages that
 * satisfy Google Merchant Center's "no template phrases / no contradictory
 * rules" audit, WITHOUT duplicating each other.
 *
 *   refund-policy   →   MONEY-focused: how much is refunded, refund
 *                       method (original payment), 3-day processing,
 *                       currency, partial refunds, chargebacks, fraud
 *                       reversals, escalations.  Read by both
 *                       /page.php?slug=refund-policy and the clean-URL
 *                       /refund-policy.php.
 *
 *   return-policy   →   PROCESS-focused: what can be returned, the
 *                       30-day return window, how to initiate a return,
 *                       what our team does with the licence key on
 *                       receipt, eligibility, exchanges, subscription
 *                       cancellations, ineligible items, fraud refusal.
 *                       Read by /return-policy.php and (as a fallback)
 *                       /page.php?slug=return-policy.
 *
 * Both pages:
 *   - Contain ZERO physical-goods template phrases ("shipping boxes",
 *     "restocking physical items", "mailing address", "mail back", etc.)
 *   - Describe a 100% digital process (email/chat request → licence
 *     deactivation → refund via original payment method).
 *   - Match the homepage promise: 30-day money-back guarantee, no
 *     questions asked, applies to both defective AND non-defective.
 *   - Cross-reference each other so a customer landing on one policy
 *     always finds the other.
 *
 * The legacy `returns-refunds` slug (the old blended page) is also kept
 * MC-compliant here for backwards compatibility, but the primary
 * customer-facing URLs are now /refund-policy.php and /return-policy.php.
 *
 * Re-run safe — only rewrites rows that still carry a previously flagged
 * marker (old restrictive "Not eligible" clause OR any lingering
 * physical-goods template phrase).  Admin edits that already removed
 * those markers are preserved.
 *
 * Wired into start.sh so it runs on every pod boot; safe to invoke
 * manually on cPanel:  php scripts/update-refund-policy-mc.php
 */

require_once __DIR__ . '/../includes/functions.php';

$db = db();

/*
 * ============================================================================
 * REFUND POLICY — MONEY / REFUND-focused legal copy
 * ============================================================================
 * Answers: how much is refunded, how, in what currency, over what timeline,
 * and what happens with partial refunds, chargebacks and fraud reversals.
 * DOES NOT describe the return process (that lives in the Return Policy).
 */
$newRefundPolicyContent = '<p class="lead">This Refund Policy explains how, when and in what form monetary refunds are issued for orders placed on this store. It applies to every product and every Protection Hub subscription plan sold by Maventech LLC. The complementary process for initiating a return &mdash; and what happens on our side once a return request is received &mdash; is set out in our separate <a href="return-policy.php"><strong>Return Policy</strong></a>.</p>

<div class="alert alert-success d-flex gap-3 align-items-start"><i class="bi bi-cash-coin fs-4 flex-shrink-0"></i><div><strong>30-Day Money-Back Guarantee &mdash; Full Refund.</strong><br>Every order placed with Maventech LLC is refundable in full within 30 calendar days of purchase, for any reason. No fees are deducted, no percentage is withheld, and no minimum-purchase conditions attach.</div></div>

<h2><i class="bi bi-cash-stack text-primary me-2"></i>1. Refund Amount</h2>
<p>Approved refunds are issued for <strong>100% of the amount you paid</strong>, including any sales tax, VAT or checkout fees added to your order. Maventech LLC does not withhold cancellation fees, processing charges or administrative fees under any circumstances. Where a promotional discount was applied at checkout, the refund is the actual amount charged to your payment method (i.e. after the discount).</p>

<h2><i class="bi bi-credit-card-2-back text-primary me-2"></i>2. Refund Method</h2>
<ul>
<li>Refunds are issued <strong>only to the original payment method</strong> used at checkout &mdash; the credit or debit card, PayPal account, Apple Pay wallet, Google Pay wallet or bank account you paid with.</li>
<li>Maventech LLC does not, and will never, request your card number, PIN, CVV, expiry date, one-time password or bank credentials by email, phone, live chat or SMS. Refunds are processed automatically through our regulated payment provider, without any input required from you.</li>
<li>If the original card has been closed or reported lost, reply to our refund-confirmation email and we will arrange the refund to a valid alternative account you nominate, subject to identity verification.</li>
</ul>

<h2><i class="bi bi-currency-exchange text-primary me-2"></i>3. Refund Currency</h2>
<p>Refunds are issued in the same currency in which the payment was charged at checkout. Where your card issuer or wallet provider applied a currency conversion between our merchant currency and your billing currency, the reverse conversion is applied by that same provider on the return leg. Maventech LLC does not bear, offset or reimburse currency-conversion differences created by third-party banks, card networks or wallet providers.</p>

<h2><i class="bi bi-clock-history text-primary me-2"></i>4. Refund Processing Timeline</h2>
<table class="table table-bordered align-middle" style="max-width:640px;">
<thead><tr><th>Stage</th><th>Timeline</th></tr></thead>
<tbody>
<tr><td><i class="bi bi-inbox-fill text-primary me-1"></i>Refund request acknowledged</td><td>Within 24 hours of receipt</td></tr>
<tr><td><i class="bi bi-check-circle-fill text-success me-1"></i>Refund approved &amp; released to our payment processor</td><td><strong>Within 3 business days</strong></td></tr>
<tr><td><i class="bi bi-bank text-primary me-1"></i>Credit visible on your statement / wallet</td><td>3&ndash;10 additional business days (bank &amp; card-network dependent)</td></tr>
</tbody>
</table>

<h2><i class="bi bi-percent text-primary me-2"></i>5. Partial Refunds</h2>
<p>Where a single order contains multiple items and you elect to return only a subset, we issue a partial refund equal to the price paid for the returned items plus their proportional share of any tax collected at checkout. No partial-refund penalty is applied.</p>

<h2><i class="bi bi-shield-exclamation text-primary me-2"></i>6. Chargebacks &amp; Payment Disputes</h2>
<p>Before initiating a chargeback or payment dispute with your card issuer, please contact <a href="mailto:services@maventechsoftware.com">services@maventechsoftware.com</a> so we can process the refund directly. Chargebacks bypass our standard three-business-day refund timeline, generally take 30&ndash;90 days to resolve through the card scheme, and may affect the availability of your Maventech LLC account for future purchases. Bona-fide chargeback claims are handled transparently and in full cooperation with our payment provider.</p>

<h2><i class="bi bi-shield-slash text-primary me-2"></i>7. Fraud &amp; Refund Reversal</h2>
<p>Maventech LLC reserves the right to reverse a previously-issued refund where the underlying transaction is subsequently determined to be fraudulent, unauthorised, or in breach of our <a href="page.php?slug=terms-of-service">Terms of Service</a>. Refund reversals are applied only where the payment provider issues a chargeback ruling in our favour or where verifiable evidence of fraud is established. This clause does not affect your statutory consumer-protection rights under applicable law.</p>

<h2><i class="bi bi-headset text-primary me-2"></i>8. Escalations &amp; Contact</h2>
<p>If your refund has not been processed within the timelines set out in Section 4 &mdash; or if you have any other question about a refund &mdash; contact <a href="mailto:services@maventechsoftware.com">services@maventechsoftware.com</a>, open a live chat, or call <a href="tel:1-805-823-9961">1-805-823-9961</a>, quoting your order number. Escalations are acknowledged within 24 hours, Monday to Saturday, 9 AM &ndash; 6 PM EST.</p>

<h2><i class="bi bi-info-circle text-primary me-2"></i>9. Governing Law</h2>
<p>This Refund Policy is governed by the laws of the State of California, United States, without regard to conflict-of-law principles, and forms part of the wider agreement between you and Maventech LLC set out in our <a href="page.php?slug=terms-of-service">Terms of Service</a>. Where this policy conflicts with any statutory consumer right that applies to your purchase, the statutory right prevails.</p>

<div class="card p-4 mt-4"><h5 class="fw-bold mb-2">Related policies</h5><p class="small text-secondary mb-2">The return process itself &mdash; how to initiate a return and what our team does with your licence key &mdash; is documented separately.</p><div class="d-flex gap-2 flex-wrap"><a href="return-policy.php" class="btn btn-sm btn-primary rounded-pill px-3">Read the Return Policy</a><a href="contact.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">Contact Support</a></div></div>';

/*
 * ============================================================================
 * RETURN POLICY — PROCESS-focused legal copy
 * ============================================================================
 * Answers: what can be returned, how to initiate a return, the 30-day window,
 * what our team does on receipt (licence-key deactivation), eligibility,
 * exchanges, subscription cancellations, ineligible items and fraud refusal.
 * DOES NOT describe how much/when the money is returned (that lives in the
 * Refund Policy).
 */
$newReturnPolicyContent = '<p class="lead">This Return Policy explains what you can return, how to initiate a return, and what happens on our side when we receive a return request. It applies to all products and Protection Hub subscription plans sold by Maventech LLC. The complementary rules covering the amount refunded and how quickly funds land back on your card &mdash; are set out in our separate <a href="refund-policy.php"><strong>Refund Policy</strong></a>.</p>

<div class="alert alert-success d-flex gap-3 align-items-start"><i class="bi bi-arrow-counterclockwise fs-4 flex-shrink-0"></i><div><strong>30-Day Return Window &mdash; No Questions Asked.</strong><br>Every product sold on this store is returnable within 30 calendar days of purchase, for any reason. The process is 100% digital &mdash; no forms to print, nothing to send anywhere.</div></div>

<h2><i class="bi bi-box-seam text-primary me-2"></i>1. What You Can Return</h2>
<p>Every product sold on this store is a downloadable digital licence key. All products are returnable, without exception, provided the return request is submitted within the 30-day return window described in Section 2. Physical goods are never sold on this store, so no product carries a &ldquo;non-returnable&rdquo; classification.</p>

<h2><i class="bi bi-calendar-week text-primary me-2"></i>2. Return Window</h2>
<p>You may submit a return request at any time within <strong>30 calendar days</strong> of the date the order was placed. Requests received after the 30-day window are reviewed on a case-by-case basis by our support team; contact us and we will consider your specific circumstances (for example, extended activation issues that we were investigating during the 30-day window).</p>

<h2><i class="bi bi-arrow-right-circle-fill text-primary me-2"></i>3. How to Initiate a Return</h2>
<p>Because every product is delivered digitally by email, the return process is entirely online. Choose whichever channel is easiest for you:</p>
<ol>
<li><strong>Email us</strong> at <a href="mailto:services@maventechsoftware.com">services@maventechsoftware.com</a>, quoting your order number and a short note.</li>
<li><strong>Live chat</strong> &mdash; open the chat window from the bubble in the bottom-right corner of any page on the store.</li>
<li><strong>Self-service form</strong> &mdash; visit the <a href="returns.php"><strong>Return &amp; Refund Request</strong></a> page, enter the email used at checkout, click <em>Find Orders</em>, then click <em>Request Refund</em> next to the relevant order.</li>
</ol>
<p class="small text-secondary">You do not need to provide a reason for your return, but a short note helps our team improve the store for future customers.</p>

<h2><i class="bi bi-gear-fill text-primary me-2"></i>4. Our Return Process</h2>
<p>When we receive your return request, our team:</p>
<ol>
<li>Acknowledges receipt of the request within 24 hours.</li>
<li>Deactivates the licence key in our vendor systems so the licence is cleanly released back to inventory. You do not need to uninstall the software from your device or take any action on your side.</li>
<li>Confirms the completed return by email, along with the refund reference number that will appear on your statement.</li>
</ol>
<p>The monetary refund is then issued in accordance with our separate <a href="refund-policy.php">Refund Policy</a> &mdash; approved and released to our payment processor within 3 business days.</p>

<h2><i class="bi bi-cloud-download text-primary me-2"></i>5. Zero Physical Component</h2>
<p>Every product sold on this store is an <strong>intangible digital licence key</strong> delivered by email at the time of purchase. There is no physical delivery, no packaging, no courier and no return leg to arrange. You never need to send anything to us; you are never charged a return-processing cost; and the entire return experience takes place through email, live chat or the online form described in Section 3.</p>

<h2><i class="bi bi-list-check text-primary me-2"></i>6. Eligibility for Return</h2>
<p>All of the following scenarios are eligible for a full return within the 30-day return window &mdash; no additional conditions attach:</p>
<ul>
<li>You changed your mind (buyer&rsquo;s remorse).</li>
<li>You ordered the wrong edition or product.</li>
<li>The product does not activate or fails to function as advertised.</li>
<li>Your order was not delivered within 24 hours.</li>
<li>You are not satisfied for any other reason.</li>
</ul>
<p>The 30-day return window applies equally to products that have been installed or activated. Where a licence key has been activated, our team deactivates the licence on our side as part of the return &mdash; you do not need to reverse the installation yourself.</p>

<h2><i class="bi bi-arrow-left-right text-primary me-2"></i>7. Exchanges</h2>
<p>If you purchased the wrong product and would prefer an exchange rather than a monetary refund, tell us so when you initiate the return. We will credit the value of the returned order against the correct product, free of charge, and issue any residual amount according to the <a href="refund-policy.php">Refund Policy</a>.</p>

<h2><i class="bi bi-shield-shaded text-primary me-2"></i>8. Protection Hub Subscription Cancellations</h2>
<p>Protection Hub subscription plans follow the identical 30-day return window. Cancel by email, live chat or the online form within the first 30 days of purchase and we will deactivate the plan and issue a full refund to your original payment method.</p>

<h2><i class="bi bi-slash-circle text-primary me-2"></i>9. Ineligible Items</h2>
<p>No product sold on this store is exempted from this Return Policy. All items are covered by the 30-day return window.</p>

<h2><i class="bi bi-shield-exclamation text-primary me-2"></i>10. Refusal of Fraudulent Returns</h2>
<p>Maventech LLC reserves the right to refuse a return request where the underlying order is identified as fraudulent, was placed with an unauthorised payment method, or where the same customer has repeatedly abused the return process across multiple prior orders in a manner inconsistent with genuine consumer use. Refusal of a fraudulent return does not affect statutory consumer-protection rights that apply under the law of your jurisdiction.</p>

<h2><i class="bi bi-headset text-primary me-2"></i>11. Contact</h2>
<p>Questions about a specific return, or about this Return Policy generally, can be sent to <a href="mailto:services@maventechsoftware.com">services@maventechsoftware.com</a> or raised on live chat. Phone: <a href="tel:1-805-823-9961">1-805-823-9961</a> (Mon &ndash; Sat, 9 AM &ndash; 6 PM EST).</p>

<h2><i class="bi bi-info-circle text-primary me-2"></i>12. Governing Law</h2>
<p>This Return Policy is governed by the laws of the State of California, United States, without regard to conflict-of-law principles, and forms part of the wider agreement between you and Maventech LLC set out in our <a href="page.php?slug=terms-of-service">Terms of Service</a>. Where this policy conflicts with any statutory consumer right that applies to your purchase, the statutory right prevails.</p>

<div class="card p-4 mt-4"><h5 class="fw-bold mb-2">Related policies</h5><p class="small text-secondary mb-2">The monetary side of a return &mdash; refund amount, method, currency and timeline &mdash; is documented separately.</p><div class="d-flex gap-2 flex-wrap"><a href="refund-policy.php" class="btn btn-sm btn-primary rounded-pill px-3">Read the Refund Policy</a><a href="contact.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">Contact Support</a></div></div>';

/*
 * Legacy "Returns & Refunds" hub — kept MC-compliant so older links / SEO
 * indexes don't 404, but with content that redirects readers to the two
 * new dedicated pages rather than duplicating them a third time.
 */
$newReturnsRefundsContent = '<p class="lead">Our returns and refunds are documented in two dedicated legal pages so each topic gets full, unambiguous coverage.</p>

<div class="row g-3 my-4">
<div class="col-md-6"><div class="card h-100 p-4"><i class="bi bi-arrow-counterclockwise fs-3 text-primary mb-2"></i><h3 class="h5 fw-bold">Return Policy</h3><p class="small text-secondary">The return process, 30-day window, eligibility, digital licence-key deactivation, exchanges and subscription cancellations.</p><a href="return-policy.php" class="btn btn-primary rounded-pill px-3">Read the Return Policy</a></div></div>
<div class="col-md-6"><div class="card h-100 p-4"><i class="bi bi-cash-coin fs-3 text-success mb-2"></i><h3 class="h5 fw-bold">Refund Policy</h3><p class="small text-secondary">The refund amount, method (original payment), currency, three-business-day processing timeline, partial refunds and chargebacks.</p><a href="refund-policy.php" class="btn btn-success rounded-pill px-3">Read the Refund Policy</a></div></div>
</div>

<div class="alert alert-success d-flex gap-3 align-items-start"><i class="bi bi-patch-check-fill fs-4 flex-shrink-0"></i><div><strong>30-Day Money-Back Guarantee &mdash; Full Refund, No Questions Asked.</strong><br>Every order placed with Maventech LLC is returnable within 30 days of purchase for any reason, with a full refund issued to the original payment method within 3 business days. Applies to both defective and non-defective products.</div></div>

<div class="text-center my-4"><a href="returns.php" class="btn btn-primary rounded-pill px-4 fw-semibold">Start a Return / Refund Request</a></div>

<div class="card p-4 mt-4"><h5 class="fw-bold mb-2">Questions?</h5><p class="small text-secondary mb-2">Our support team is happy to help.</p><p class="small mb-3"><a href="mailto:services@maventechsoftware.com">services@maventechsoftware.com</a> <span class="text-secondary mx-1">|</span> <a href="tel:1-805-823-9961">1-805-823-9961</a> <span class="text-secondary mx-1">|</span> Live chat available on every page</p></div>';

/**
 * Apply the update to a page slug only when the existing content still
 * contains ANY of the flagged phrases (legacy restrictive language OR
 * physical-goods template phrases OR the previous single-body policy
 * that mixed the refund + return topics).  Preserves admin edits that
 * have already removed those markers AND that don't accidentally match
 * the migration's own detection keywords.
 */
function _mc_maybe_update(PDO $db, string $slug, string $newContent, string $title): bool
{
    $row = $db->prepare('SELECT content FROM pages WHERE slug = ? LIMIT 1');
    $row->execute([$slug]);
    $page = $row->fetch(PDO::FETCH_ASSOC);
    if (!$page) {
        $ins = $db->prepare('INSERT INTO pages (slug, title, content, updated) VALUES (?, ?, ?, ?)');
        $ins->execute([$slug, $title, $newContent, 'January 1, 2026']);
        echo "[refund-policy-mc] Inserted missing page: {$slug}\n";
        return true;
    }
    $content = (string)$page['content'];

    // Legacy restrictive markers.
    $hasOldRestrictive = (
           strpos($content, 'Not eligible') !== false
        || strpos($content, 'activated successfully') !== false
        || strpos($content, 'cannot be activated and our support team cannot resolve') !== false
        || strpos($content, 'Once a digital key is exposed') !== false
    );
    // Physical-goods template phrases (Google MC flags these on digital feeds).
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
    // The previous (pre-split) merged policy that mixed refund + return
    // language on the same page — detect by presence of the previous lead
    // sentence.  Once the DB carries the new split content this returns
    // false and the migration exits cleanly.
    $isPreSplit = (
           strpos($content, 'Your satisfaction is our top priority. Every order is backed by a straightforward') !== false
        || strpos($content, 'We stand behind every licence we sell with a straightforward') !== false
        || strpos($content, 'We stand behind every license we sell with a straightforward') !== false
    );

    if (!$hasOldRestrictive && !$hasPhysical && !$isPreSplit) {
        echo "[refund-policy-mc] {$slug} already MC-compliant & split — no change.\n";
        return false;
    }
    $upd = $db->prepare('UPDATE pages SET content = ?, updated = ? WHERE slug = ?');
    $upd->execute([$newContent, 'January 1, 2026', $slug]);
    echo "[refund-policy-mc] Rewrote {$slug} to Merchant-Center-compliant split copy.\n";
    return true;
}

try {
    _mc_maybe_update($db, 'refund-policy',    $newRefundPolicyContent,    'Refund Policy');
    _mc_maybe_update($db, 'return-policy',    $newReturnPolicyContent,    'Return Policy');
    _mc_maybe_update($db, 'returns-refunds',  $newReturnsRefundsContent,  'Returns & Refunds');
    echo "[refund-policy-mc] Done.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[refund-policy-mc] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

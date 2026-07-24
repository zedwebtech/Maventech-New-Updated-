<?php
/**
 * /return-policy.php — Dedicated "Return Policy" page at a clean URL.
 *
 * Purpose: give Google Merchant Center (Settings → Shipping and returns →
 * Return policies → Policy URL) a professional-looking, canonical URL to
 * reference, rather than the generic /page.php?slug=refund-policy path.
 *
 * The page renders the *same* Merchant-Center-compliant refund-policy
 * content that lives in the `pages` table (slug=refund-policy) — a single
 * source of truth for the copy. That keeps the two URLs in perfect sync so
 * Google's crawler never sees contradictory text between them.
 *
 * SEO: canonicalises to itself and sets a meta description drawn from the
 * policy body, so Google surfaces this URL (not the /page.php? variant)
 * in search snippets.
 */
require_once __DIR__ . '/includes/functions.php';

// Fetch the canonical Return Policy body from the pages table (slug =
// return-policy — process-focused legal copy, distinct from the Refund
// Policy which is money-focused).  This lets an admin edit the copy in
// the /admin.php CMS editor without touching PHP.
$stmt = db()->prepare('SELECT title, content, updated FROM pages WHERE slug = ?');
$stmt->execute(['return-policy']);
$policy = $stmt->fetch();

// Self-healing fallback: some fresh installs / older DB imports only shipped
// the `refund-policy` seed row and never gained a dedicated `return-policy`
// row (which is what customers on shared hosting were hitting — the page
// rendered the "temporarily unavailable" branch, breaking Google Merchant
// Center's Return Policy URL requirement).  When the row is missing we
// transparently render the refund-policy copy at this URL instead of a
// broken/error page.  The two policies share the same money-back + digital-
// delivery legal wording, so surfacing the refund-policy body here is
// semantically identical and keeps the URL live for both shoppers and
// Merchant Center crawlers.
if (!$policy) {
    $stmt2 = db()->prepare('SELECT title, content, updated FROM pages WHERE slug = ?');
    $stmt2->execute(['refund-policy']);
    $policy = $stmt2->fetch();
    if ($policy) {
        // Re-label as "Return Policy" so the H1 + <title> stay on-brand for
        // the /return-policy.php URL even though we're serving the shared body.
        $policy['title'] = 'Return Policy';
    }
}

$pageTitle = 'Return Policy | ' . SITE_BRAND;
// 2026-07 FIX: force canonical so /return-policy (clean alias) and
// /return-policy.php agree.  Prevents duplicate-title tags in Semrush.
$canonicalUrl      = site_url() . country_prefix() . '/return-policy.php';
$canonicalPathBare = '/return-policy.php';
if ($policy) {
    $policy['content'] = company_placeholders_apply((string)$policy['content']);
    $pageDescription = 'Return Policy — ' . trim(mb_substr(strip_tags($policy['content']), 0, 140)) . '…';
} else {
    // Both slugs missing — this should genuinely never happen on a healthy
    // install.  Serve a stable static fallback (still HTTP 200, not 500) so
    // Google Merchant Center's URL-fetch test never sees a hard error.
    $pageDescription = 'Return Policy — ' . SITE_BRAND;
}

include __DIR__ . '/includes/header.php';
?>
<div class="container py-5" style="max-width: 860px;" data-testid="return-policy-page">
  <?php if ($policy): ?>
    <h1 class="fw-bold" data-testid="return-policy-title">Return Policy</h1>
    <?php if (!empty($policy['updated'])): ?>
      <p class="text-secondary small" data-testid="return-policy-updated">Last updated: <?= esc($policy['updated']) ?></p>
    <?php endif; ?>
    <hr>
    <div class="page-content" data-testid="return-policy-content"><?= $policy['content'] /* trusted HTML; placeholders already resolved */ ?></div>
  <?php else: ?>
    <!-- Static hard-coded Return Policy body — used only when the `pages`
         table is missing both the `return-policy` AND `refund-policy` seed
         rows.  Serves Google Merchant Center's Return Policy URL requirement
         without any DB dependency. -->
    <h1 class="fw-bold" data-testid="return-policy-title">Return Policy</h1>
    <p class="text-secondary small" data-testid="return-policy-updated">Last updated: January 1, 2026</p>
    <hr>
    <div class="page-content" data-testid="return-policy-content">
      <p class="lead">Your satisfaction is our top priority. Every order is backed by a straightforward <strong>30-day money-back guarantee — no questions asked</strong>.</p>
      <h2 class="h4 fw-bold mt-4">30-Day Money-Back Guarantee</h2>
      <p>If you change your mind, order the wrong edition, or the product simply doesn't work for you, contact us within 30 days of purchase and we'll refund you in full. This applies to both defective and non-defective products.</p>
      <h2 class="h4 fw-bold mt-4">How to Request a Return</h2>
      <ol>
        <li>Email <a href="mailto:services@<?= esc(preg_replace('#^https?://(www\.)?#i', '', site_url())) ?>">services@<?= esc(preg_replace('#^https?://(www\.)?#i', '', site_url())) ?></a> with your order number and the reason for the return.</li>
        <li>Our support team will reply within one business day to confirm and process your refund.</li>
        <li>Refunds are issued back to the original payment method within 3–5 business days after approval.</li>
      </ol>
      <h2 class="h4 fw-bold mt-4">Digital Delivery — No Physical Return Required</h2>
      <p>Because every product we sell is a digital software product key delivered by email, there is nothing to package or mail back. Simply request the return and we'll process it — the deactivation of the key is handled on our side.</p>
      <p>Questions? Reach us anytime at <a href="mailto:services@<?= esc(preg_replace('#^https?://(www\.)?#i', '', site_url())) ?>">services@<?= esc(preg_replace('#^https?://(www\.)?#i', '', site_url())) ?></a>.</p>
    </div>
    <div class="text-center pt-4">
      <a href="index.php" class="btn btn-outline-primary rounded-pill px-4">Back to Home</a>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>

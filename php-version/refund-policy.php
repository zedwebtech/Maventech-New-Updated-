<?php
/**
 * /refund-policy.php — Dedicated "Refund Policy" page at a clean URL.
 *
 * Money-focused legal page — how much is refunded, refund method
 * (original payment), currency, 3-business-day processing timeline,
 * partial refunds, chargebacks and fraud reversals.
 *
 * The complementary process-focused page (how to initiate a return, what
 * our team does with the licence key on receipt) lives at
 * /return-policy.php and is a distinct legal document with its own body.
 *
 * URL parity — introducing /refund-policy.php + /return-policy.php as
 * clean sibling URLs so the two most important legal pages have a
 * consistent structure (no more ?slug= for the customer-facing policies).
 * Older /page.php?slug=refund-policy links still work (unchanged) — this
 * is an additional, cleaner alias, not a replacement.
 */
require_once __DIR__ . '/includes/functions.php';

// Fetch the canonical Refund Policy body from the pages table (slug =
// refund-policy — money-focused legal copy, distinct from the Return
// Policy which is process-focused).  This lets an admin edit the copy in
// the /admin.php CMS editor without touching PHP.
$stmt = db()->prepare('SELECT title, content, updated FROM pages WHERE slug = ?');
$stmt->execute(['refund-policy']);
$policy = $stmt->fetch();

$pageTitle = 'Refund Policy | ' . SITE_BRAND;
if ($policy) {
    $policy['content'] = company_placeholders_apply((string)$policy['content']);
    $pageDescription = 'Refund Policy — ' . trim(mb_substr(strip_tags($policy['content']), 0, 140)) . '…';
} else {
    // Should never happen (the seed script guarantees the row exists), but
    // gate the render so the page doesn't crash if the DB was manually
    // truncated.
    http_response_code(500);
    $pageDescription = 'Refund Policy — ' . SITE_BRAND;
}

include __DIR__ . '/includes/header.php';
?>
<div class="container py-5" style="max-width: 860px;" data-testid="refund-policy-page">
  <?php if ($policy): ?>
    <h1 class="fw-bold" data-testid="refund-policy-title">Refund Policy</h1>
    <?php if (!empty($policy['updated'])): ?>
      <p class="text-secondary small" data-testid="refund-policy-updated">Last updated: <?= esc($policy['updated']) ?></p>
    <?php endif; ?>
    <hr>
    <div class="page-content" data-testid="refund-policy-content"><?= $policy['content'] /* trusted HTML; placeholders already resolved */ ?></div>
  <?php else: ?>
    <div class="text-center py-5">
      <h1 class="fw-bold">Refund Policy</h1>
      <p class="text-secondary">Our refund policy is temporarily unavailable. Please contact <a href="mailto:services@maventechsoftware.com">services@maventechsoftware.com</a> for details.</p>
      <a href="index.php" class="btn btn-primary rounded-pill px-4 mt-3">Back to Home</a>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>

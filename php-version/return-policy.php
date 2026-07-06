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

// Fetch the canonical refund policy body from the pages table so this URL
// stays in perfect sync with /page.php?slug=refund-policy — the same
// content, just at a cleaner path.
$stmt = db()->prepare('SELECT title, content, updated FROM pages WHERE slug = ?');
$stmt->execute(['refund-policy']);
$policy = $stmt->fetch();

$pageTitle = 'Return Policy | ' . SITE_BRAND;
if ($policy) {
    $policy['content'] = company_placeholders_apply((string)$policy['content']);
    $pageDescription = 'Return Policy — ' . trim(mb_substr(strip_tags($policy['content']), 0, 140)) . '…';
} else {
    // Should never happen (the seed script guarantees the row exists), but
    // gate the render so the page doesn't crash if the DB was manually
    // truncated.
    http_response_code(500);
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
    <div class="text-center py-5">
      <h1 class="fw-bold">Return Policy</h1>
      <p class="text-secondary">Our return policy is temporarily unavailable. Please contact <a href="mailto:services@maventechsoftware.com">services@maventechsoftware.com</a> for details.</p>
      <a href="index.php" class="btn btn-primary rounded-pill px-4 mt-3">Back to Home</a>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>

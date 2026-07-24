<?php
/**
 * /unsubscribe.php — one-click opt-out from follow-up emails.
 *
 * URL form:  /unsubscribe.php?e=<email>&t=<hmac>
 *
 *   • Verifies the HMAC token so an attacker can't unsubscribe a
 *     random customer.  Token is deterministic per email, generated
 *     by unsubscribe_token_for() in includes/followup-emails.php.
 *   • Inserts (or upserts) into email_unsubscribes.
 *   • Also flips any pending follow-up rows to status="skipped" so the
 *     cron worker never sends the next touch after opt-out.
 *   • Sends a `List-Unsubscribe: <URL>` friendly landing page — GET is
 *     enough because mail clients (Gmail, Outlook, iCloud) pre-fetch
 *     the URL when a user clicks the tray-icon unsubscribe.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/followup-emails.php';

$emailIn = strtolower(trim((string)($_GET['e'] ?? $_POST['e'] ?? '')));
$tokenIn = (string)($_GET['t'] ?? $_POST['t'] ?? '');

$state = 'error';   // 'ok' | 'already' | 'error' | 'confirm'
$message = 'This unsubscribe link is invalid or has expired.';

if ($emailIn !== '' && filter_var($emailIn, FILTER_VALIDATE_EMAIL)
    && $tokenIn !== '' && verify_unsubscribe_token($emailIn, $tokenIn)) {

    if (is_email_unsubscribed($emailIn)) {
        $state = 'already';
        $message = 'You are already unsubscribed. No further follow-up emails will be sent to this address.';
    } else {
        // For GET requests we still honour it (Gmail/Outlook pre-fetch)
        // but also render a confirmation.  POST/HEAD from a real mail
        // client (RFC 8058 List-Unsubscribe-Post) is auto-accepted.
        unsubscribe_email($emailIn, '', 'link');
        $state = 'ok';
        $message = 'You have been unsubscribed from Maventech follow-up emails. If this was a mistake, just reply to any of your previous order emails and our team will re-enable them.';
    }
}

$brand = defined('SITE_BRAND') ? SITE_BRAND : 'Maventech';
$pageTitle = 'Unsubscribe | ' . $brand;
$pageDescription = 'Unsubscribe from Maventech customer follow-up emails.';
$noIndex = true;
include __DIR__ . '/includes/header.php';
?>
<div class="container py-5" style="min-height:60vh;max-width:640px;">
  <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5" data-testid="unsubscribe-card">
    <div class="text-center mb-3">
      <?php if ($state === 'ok'): ?>
        <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
             style="width:64px;height:64px;background:#dcfce7;color:#15803d;">
          <i class="bi bi-check-lg" style="font-size:32px;"></i>
        </div>
        <h1 class="h4 fw-bold" data-testid="unsub-title-ok">You&rsquo;re unsubscribed</h1>
      <?php elseif ($state === 'already'): ?>
        <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
             style="width:64px;height:64px;background:#e0e7ff;color:#4338ca;">
          <i class="bi bi-info-lg" style="font-size:32px;"></i>
        </div>
        <h1 class="h4 fw-bold" data-testid="unsub-title-already">Already unsubscribed</h1>
      <?php else: ?>
        <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
             style="width:64px;height:64px;background:#fee2e2;color:#b91c1c;">
          <i class="bi bi-exclamation-lg" style="font-size:32px;"></i>
        </div>
        <h1 class="h4 fw-bold" data-testid="unsub-title-error">Unsubscribe link invalid</h1>
      <?php endif; ?>
    </div>
    <p class="text-secondary text-center mb-4" data-testid="unsub-message"><?= esc($message) ?></p>
    <?php if ($emailIn !== '' && $state !== 'error'): ?>
      <p class="text-secondary text-center small">
        Address: <strong><?= esc($emailIn) ?></strong>
      </p>
    <?php endif; ?>
    <div class="text-center mt-4">
      <a href="/" class="btn btn-primary rounded-pill px-4" data-testid="unsub-home-link">Back to Home</a>
      <a href="/support.php" class="btn btn-outline-secondary rounded-pill px-4 ms-2" data-testid="unsub-support-link">Contact Support</a>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>

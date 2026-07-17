<?php
// Manual trigger for bounce reconciliation from the admin panel.
// Reads the mailbox (IMAP) for delivery failures and marks matching
// Email Activity rows as BOUNCED.
//
// Two calling modes:
//   • JSON   — default, used by any XHR caller.
//   • Redirect — when ?redirect=1 is present (the "Sync bounces now" button
//                on admin.php?tab=emails uses this), we run the sync, bust
//                the throttle timestamp so a fresh full run happens, then
//                bounce back to the referring page with a flash message
//                embedded in the query string.
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
$admin = require_admin_json();

$wantRedirect = isset($_GET['redirect']) && $_GET['redirect'] === '1';
$back         = (string)($_GET['back'] ?? 'admin.php?tab=emails');
// Only allow redirects back inside our own admin surface — prevents an
// open-redirect abuse (?back=https://evil.com).
if (!preg_match('#^(admin\.php|/admin\.php)#', $back)) {
    $back = 'admin.php?tab=emails';
}

try {
    $res = email_sync_bounces();
    // Bust the throttle so the admin's next natural visit to the emails
    // tab picks up the latest server state without waiting 3 min.
    if (function_exists('setting_set')) setting_set('emails_last_bounce_sync', (string)time());
    if ($wantRedirect) {
        $sep = strpos($back, '?') === false ? '?' : '&';
        $qs  = 'synced=1&checked=' . (int)($res['checked'] ?? 0)
             . '&bounced=' . (int)($res['bounced'] ?? 0);
        if (!empty($res['error'])) $qs .= '&err=' . urlencode(substr((string)$res['error'], 0, 180));
        header('Location: ' . $back . $sep . $qs);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($res, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($wantRedirect) {
        $sep = strpos($back, '?') === false ? '?' : '&';
        header('Location: ' . $back . $sep . 'synced=1&err=' . urlencode(substr($e->getMessage(), 0, 180)));
        exit;
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

<?php
// Manual trigger for bounce reconciliation from the admin panel.
// Reads the mailbox (IMAP) for delivery failures and marks matching
// Email Activity rows as BOUNCED. Returns JSON.
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
$admin = require_admin_json();
header('Content-Type: application/json; charset=utf-8');

try {
    $res = email_sync_bounces();
    echo json_encode($res, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

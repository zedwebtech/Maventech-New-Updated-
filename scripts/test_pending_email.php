<?php
// Test — verify mv_send_abandoned_cart_email() produces a valid pending-payment
// email (items list + Continue Checkout link) for a pending order.
require_once __DIR__ . '/../php-version/config.php';
require_once __DIR__ . '/../php-version/includes/db.php';
require_once __DIR__ . '/../php-version/includes/functions.php';
require_once __DIR__ . '/../php-version/includes/settings.php';
require_once __DIR__ . '/../php-version/includes/mailer.php';
require_once __DIR__ . '/../php-version/includes/email.php';
require_once __DIR__ . '/../php-version/includes/recovery.php';

$pdo = db();
$ord = $pdo->prepare('SELECT * FROM orders WHERE order_number=?');
$ord->execute(['MV260704ABCD']);
$ord = $ord->fetch(PDO::FETCH_ASSOC);
if (!$ord) { echo "no order\n"; exit(1); }

// Capture outbox count before
$before = (int)$pdo->query('SELECT COUNT(*) FROM email_outbox WHERE order_id='.(int)$ord['id'])->fetchColumn();
echo "outbox rows for this order BEFORE = $before\n";

$ok = mv_send_abandoned_cart_email($ord);
echo "mv_send_abandoned_cart_email returned: " . ($ok ? 'true' : 'false') . "\n";

$after = (int)$pdo->query('SELECT COUNT(*) FROM email_outbox WHERE order_id='.(int)$ord['id'])->fetchColumn();
echo "outbox rows for this order AFTER  = $after (delta = " . ($after - $before) . ")\n";

// Fetch the queued email
$q = $pdo->prepare('SELECT id, recipient, subject, status, template_code, LENGTH(html) AS bytes, html AS snip FROM email_outbox WHERE order_id=? ORDER BY id DESC LIMIT 1');
$q->execute([(int)$ord['id']]);
$row = $q->fetch(PDO::FETCH_ASSOC);
if ($row) {
    echo "\n--- newest outbox row ---\n";
    echo "id={$row['id']}  status={$row['status']}  template={$row['template_code']}  bytes={$row['bytes']}\n";
    echo "to={$row['recipient']}\n";
    echo "subject={$row['subject']}\n";

    // Does the HTML contain a checkout resume link + product name?
    $hasCheckoutLink = strpos($row['snip'], '/checkout.php?resume=') !== false;
    $hasResumeSig    = strpos($row['snip'], '&sig=') !== false || strpos($row['snip'], '&amp;sig=') !== false;
    $hasProductName  = strpos($row['snip'], 'Microsoft Office 2024 Professional Plus') !== false;
    $hasCTA          = stripos($row['snip'], 'Continue Checkout') !== false || stripos($row['snip'], 'Complete') !== false;
    echo "has checkout.php?resume link: " . ($hasCheckoutLink ? 'YES' : 'NO') . "\n";
    echo "has signature (sig=):         " . ($hasResumeSig ? 'YES' : 'NO') . "\n";
    echo "has product name in HTML:     " . ($hasProductName ? 'YES' : 'NO') . "\n";
    echo "has CTA (Continue/Complete):  " . ($hasCTA ? 'YES' : 'NO') . "\n";

    if (!$hasProductName || !$hasCheckoutLink) {
        echo "\n--- HTML SNIPPET (first 800 chars) ---\n" . $row['snip'] . "\n";
    }
} else {
    echo "no outbox row found — email NOT queued\n";
}

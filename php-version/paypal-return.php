<?php
/**
 * paypal-return.php
 *
 * Buyer lands here after approving (or cancelling) a PayPal payment.
 * We capture the approved order via the PayPal Orders API and mark our order
 * paid ONLY when the capture status is COMPLETED. Never trust the redirect
 * alone — the capture call is the source of truth.
 *
 * Query: ?order=<our order_number>&token=<paypal order id>&PayerID=<...>
 *        ?paypal=cancel                                  (buyer cancelled)
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gateways/paypal-api.php';

$orderNumber   = trim((string)($_GET['order'] ?? ''));
$paypalOrderId = trim((string)($_GET['token'] ?? ''));

function pp_bounce(string $url): void { header('Location: ' . $url); exit; }

// Buyer cancelled on PayPal.
if (($_GET['paypal'] ?? '') === 'cancel' || $orderNumber === '') {
    pp_bounce('checkout.php?paypal=cancel');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? LIMIT 1');
$stmt->execute([$orderNumber]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) { pp_bounce('checkout.php?paypal=error'); }

$orderId = (int)$order['id'];

// Already paid? (e.g. double click / refresh) — just show success.
if (in_array((string)($order['payment_status'] ?? ''), ['succeeded', 'paid'], true)
    || (string)($order['status'] ?? '') === 'paid') {
    pp_bounce('order-success.php?order=' . urlencode($orderNumber));
}

// Fall back to the PayPal order id we stashed on the order (PP:xxxx).
if ($paypalOrderId === '') {
    $stored = (string)($order['transaction_id'] ?? '');
    if (str_starts_with($stored, 'PP:')) $paypalOrderId = substr($stored, 3);
}
if ($paypalOrderId === '') {
    if (function_exists('mv_mark_payment_failed')) {
        mv_mark_payment_failed($order, ['code'=>'paypal_no_token', 'message'=>'Missing PayPal order token on return.']);
    }
    pp_bounce('checkout.php?paypal=error');
}

// Mode: use the mode the order was created in, else the current gateway mode.
$mode = (string)($order['mode'] ?? '') ?: (setting_get('gw_mode', 'test') === 'live' ? 'live' : 'test');
$mode = ($mode === 'live') ? 'live' : 'test';

try {
    $cap = paypal_capture_order($paypalOrderId, $mode);
} catch (Throwable $e) {
    @error_log('[paypal-return capture] ' . $e->getMessage());
    $cap = ['ok'=>false, 'status'=>'ERROR', 'capture_id'=>'', 'error'=>'PayPal capture failed.'];
}

$total    = (float)($order['total'] ?? 0);
$currency = (string)($order['currency'] ?? (current_currency()['code'] ?? 'USD'));

if (!empty($cap['ok'])) {
    $capId = (string)($cap['capture_id'] ?? $paypalOrderId);
    $pdo->prepare('UPDATE orders SET status = "paid", payment_status = "succeeded", transaction_id = ?, last_activity_at = NOW() WHERE id = ?')
        ->execute([$capId, $orderId]);
    try {
        $pdo->prepare('INSERT INTO transaction_logs (order_id, gateway, transaction_id, amount, currency, status, raw_response) VALUES (?,?,?,?,?,?,?)')
            ->execute([$orderId, 'paypal', $capId, $total, $currency, 'succeeded',
                       json_encode(['status'=>$cap['status'] ?? 'COMPLETED'], JSON_UNESCAPED_SLASHES)]);
    } catch (Throwable $e) { /* logging is best-effort */ }
    unset($_SESSION['mv_resume_order_id']);
    if (function_exists('fulfill_order')) fulfill_order($orderId);
    pp_bounce('order-success.php?order=' . urlencode($orderNumber));
}

// Capture failed / not completed — keep the order resumable and inform the buyer.
try {
    $pdo->prepare('INSERT INTO transaction_logs (order_id, gateway, transaction_id, amount, currency, status, raw_response) VALUES (?,?,?,?,?,?,?)')
        ->execute([$orderId, 'paypal', $paypalOrderId, $total, $currency, 'failed',
                   json_encode(['status'=>$cap['status'] ?? 'FAILED'], JSON_UNESCAPED_SLASHES)]);
} catch (Throwable $e) { /* best-effort */ }
if (function_exists('mv_mark_payment_failed')) {
    mv_mark_payment_failed($order, ['code'=>'paypal_capture_failed', 'message'=>(string)($cap['error'] ?? 'PayPal capture failed.'), 'transaction_id'=>$paypalOrderId]);
}
$_SESSION['mv_resume_order_id'] = $orderId;
pp_bounce('checkout.php?paypal=error');

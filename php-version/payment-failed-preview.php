<?php
/**
 * payment-failed-preview.php — RENDER-ONLY preview of the customer
 * "Payment failed" email (the one produced by mv_send_payment_failed_email()
 * in includes/recovery.php).
 *
 * ▸ Purpose: gives admins / support agents a way to eyeball exactly what the
 *   customer sees when a card is declined, WITHOUT having to place a real
 *   failed order + open the mail client.
 *
 * ▸ Access: admin-only (require_admin), so it can safely live at a public
 *   URL — a logged-out visitor is bounced to /admin.php.
 *
 * ▸ Query params:
 *      ?scenario=card_declined      (default)
 *      ?scenario=insufficient_funds
 *      ?scenario=do_not_honor
 *      ?scenario=authentication_required
 *      ?scenario=expired_card
 *      ?scenario=incorrect_cvc
 *      ?scenario=lost_card
 *      ?scenario=paypal_declined
 *      ?scenario=generic
 *   or ?code=<any>&msg=<free-text> to try an arbitrary combo.
 *
 * ▸ Nothing is inserted into the DB, no email is sent, no order is created.
 *
 * This file does NOT need to be listed in robots.txt — /admin.php already
 * is Disallowed and this route is only reachable behind an admin session.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/recovery.php';

// Only admins may see the raw email HTML.
require_admin();

// Preset scenarios — kept short so the picker fits nicely at the top.
$scenarios = [
    'card_declined'           => ['label' => 'Card declined (generic)',           'code' => 'card_declined',           'msg' => 'Your card was declined by your bank.'],
    'do_not_honor'            => ['label' => 'Do Not Honor (bank block)',         'code' => 'do_not_honor',            'msg' => 'Your bank declined the transaction (do-not-honor). Contact your bank or try a different card.'],
    'insufficient_funds'      => ['label' => 'Insufficient funds',                'code' => 'insufficient_funds',      'msg' => 'Insufficient funds on the card.'],
    'authentication_required' => ['label' => '3-D Secure required',               'code' => 'authentication_required', 'msg' => 'Your bank requires additional authentication (3-D Secure). Please try again and complete the challenge.'],
    'expired_card'            => ['label' => 'Expired card',                      'code' => 'expired_card',            'msg' => 'Your card has expired.'],
    'incorrect_cvc'           => ['label' => 'Incorrect CVC / CVV',               'code' => 'incorrect_cvc',           'msg' => 'The CVC / CVV security code is incorrect.'],
    'lost_card'               => ['label' => 'Lost card',                         'code' => 'lost_card',               'msg' => 'The card was reported lost. Please use another card.'],
    'paypal_declined'         => ['label' => 'PayPal declined',                   'code' => 'paypal_declined',         'msg' => 'PayPal was unable to complete this payment. Please try a different funding source.'],
    'generic'                 => ['label' => 'Unknown / generic decline',         'code' => 'processing_error',        'msg' => 'The bank could not process the payment. Please try again.'],
];

$key = strtolower(trim((string)($_GET['scenario'] ?? 'card_declined')));
if (!isset($scenarios[$key])) $key = 'card_declined';

$code = trim((string)($_GET['code'] ?? $scenarios[$key]['code']));
$msg  = trim((string)($_GET['msg']  ?? $scenarios[$key]['msg']));

// Try to source a real recent failed order so the preview is dressed with
// real customer data (safer for demoing to stakeholders). Fall back to a
// synthetic "MVT-DEMO-FAIL" order otherwise.
$demoOrder = null;
try {
    $st = db()->prepare("SELECT * FROM orders WHERE payment_status = 'failed' ORDER BY id DESC LIMIT 1");
    $st->execute();
    $demoOrder = $st->fetch();
} catch (Throwable $e) { /* ignore */ }

if (!$demoOrder) {
    $demoOrder = [
        'id'                => 0,
        'order_number'      => 'MVT-DEMO-FAIL',
        'email'             => 'preview.customer@example.com',
        'first_name'        => 'Alex',
        'last_name'         => 'Doe',
        'currency'          => 'USD',
        'total'             => 149.99,
        'payment_status'    => 'failed',
        'payment_attempts'  => 1,
        'payment_error_code'    => $code,
        'payment_error_message' => $msg,
    ];
}
// Force the requested code/msg into the preview order so the tip box renders
// the correct advice regardless of which real order we pulled.
$demoOrder['payment_error_code']    = $code;
$demoOrder['payment_error_message'] = $msg;
if ((int)($demoOrder['payment_attempts'] ?? 0) <= 0) {
    $demoOrder['payment_attempts'] = 1;
}

// If we don't have any items in DB for this order, inject synthetic ones so
// the "Your order" table isn't empty.
$hasItems = false;
try {
    if ((int)($demoOrder['id'] ?? 0) > 0) {
        $ic = db()->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = ?');
        $ic->execute([(int)$demoOrder['id']]);
        $hasItems = ((int)$ic->fetchColumn() > 0);
    }
} catch (Throwable $e) { /* ignore */ }

// Instead of mutating email_outbox / DB, capture the email HTML by wrapping
// the mv_send_payment_failed_email() render path: we call a small local
// clone of it here that returns the HTML string instead of dispatching to
// send_email().  This keeps this preview 100% read-only.

$__previewHtml = mv_render_payment_failed_email($demoOrder, $code, $msg, $hasItems);

// -----------------------------------------------------------------------------
// Chrome (picker + preview iframe wrapper)
// -----------------------------------------------------------------------------
if (!isset($_GET['raw'])) {
    header('Content-Type: text/html; charset=UTF-8');
    ?><!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Preview — Payment failed email · <?= htmlspecialchars($scenarios[$key]['label']) ?></title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
      body{margin:0;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh}
      .pfp-shell{max-width:1180px;margin:0 auto;padding:22px 20px 40px}
      .pfp-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px}
      .pfp-head h1{font-size:20px;font-weight:800;margin:0;color:#fff}
      .pfp-head .pfp-crumbs{font-size:12.5px;color:#94a3b8}
      .pfp-picker{display:flex;flex-wrap:wrap;gap:8px;background:#1e293b;border-radius:14px;padding:12px;margin-bottom:16px;border:1px solid #334155}
      .pfp-picker a{display:inline-block;padding:8px 14px;background:#0f172a;color:#cbd5e1;border-radius:999px;text-decoration:none;font-size:13px;font-weight:600;border:1px solid #334155;transition:.15s}
      .pfp-picker a:hover{border-color:#60a5fa;color:#fff}
      .pfp-picker a.on{background:linear-gradient(135deg,#3b82f6,#1d4ed8);border-color:#1d4ed8;color:#fff;box-shadow:0 6px 18px rgba(59,130,246,.35)}
      .pfp-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;background:#1e293b;border:1px solid #334155;border-radius:14px;padding:14px;margin-bottom:16px;font-size:12.5px}
      .pfp-meta strong{display:block;color:#94a3b8;font-size:11px;text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px;font-weight:700}
      .pfp-meta span{color:#e2e8f0;font-family:'SF Mono',Menlo,monospace;word-break:break-all}
      .pfp-frame-wrap{background:linear-gradient(180deg,#334155,#1e293b);padding:16px;border-radius:16px;border:1px solid #334155;position:relative}
      .pfp-frame-wrap::before{content:'inbox preview';position:absolute;top:-10px;left:16px;background:#0f172a;color:#94a3b8;padding:2px 10px;border-radius:999px;font-size:10.5px;letter-spacing:1px;font-weight:700;border:1px solid #334155}
      iframe.pfp-frame{width:100%;height:1200px;background:#fff;border-radius:12px;border:0;box-shadow:0 10px 30px rgba(0,0,0,.35)}
      .pfp-actions{margin-top:12px;display:flex;gap:8px;flex-wrap:wrap}
      .pfp-actions a{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;background:#0f172a;color:#cbd5e1;text-decoration:none;border-radius:8px;font-size:13px;font-weight:600;border:1px solid #334155}
      .pfp-actions a:hover{color:#fff;border-color:#60a5fa}
      code.tag{background:#0f172a;color:#93c5fd;padding:1px 8px;border-radius:6px;font-family:'SF Mono',Menlo,monospace;font-size:12px}
    </style>
</head>
<body>
    <div class="pfp-shell">
        <div class="pfp-head">
            <div>
                <div class="pfp-crumbs">Admin · Email previews ›</div>
                <h1>Payment failed — customer email preview</h1>
            </div>
            <div style="font-size:12.5px;color:#94a3b8">Read-only · no email is sent, no DB is touched</div>
        </div>

        <div class="pfp-picker" data-testid="pfp-scenarios">
            <?php foreach ($scenarios as $k => $s): ?>
                <a href="?scenario=<?= htmlspecialchars($k) ?>" class="<?= $k === $key ? 'on' : '' ?>" data-testid="pfp-scenario-<?= htmlspecialchars($k) ?>">
                    <?= htmlspecialchars($s['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="pfp-meta" data-testid="pfp-meta">
            <div><strong>Scenario</strong><span><?= htmlspecialchars($scenarios[$key]['label']) ?></span></div>
            <div><strong>Gateway code</strong><span><code class="tag"><?= htmlspecialchars($code) ?></code></span></div>
            <div><strong>Gateway message</strong><span><?= htmlspecialchars($msg) ?></span></div>
            <div><strong>Template</strong><span>mv_send_payment_failed_email()</span></div>
        </div>

        <div class="pfp-frame-wrap">
            <iframe class="pfp-frame" data-testid="pfp-email-frame" srcdoc="<?= htmlspecialchars($__previewHtml, ENT_QUOTES) ?>"></iframe>
        </div>
        <div class="pfp-actions">
            <a href="?scenario=<?= htmlspecialchars($key) ?>&raw=1" target="_blank" data-testid="pfp-open-raw">↗ Open raw email HTML</a>
            <a href="/admin.php?tab=emails" data-testid="pfp-back">‹ Back to admin</a>
        </div>
    </div>
</body>
</html><?php
    exit;
}

// -----------------------------------------------------------------------------
// Raw email HTML (?raw=1) — useful for copy/paste debugging
// -----------------------------------------------------------------------------
header('Content-Type: text/html; charset=UTF-8');
echo $__previewHtml;

// -----------------------------------------------------------------------------
// Local render — a mirror of mv_send_payment_failed_email() that returns
// the HTML string instead of dispatching send_email().  Any change to the
// template MUST be mirrored in both places.  Test coverage in
// tests/preview-payment-failed-email.sh ensures they stay in sync.
// -----------------------------------------------------------------------------
function mv_render_payment_failed_email(array $order, string $code, string $msg, bool $hasRealItems): string
{
    $brand   = defined('SITE_BRAND') ? SITE_BRAND : 'Maventech';
    $support = trim((string)setting_get('company_support_email', defined('SITE_EMAIL') ? SITE_EMAIL : 'support@example.com'));
    $phone   = trim((string)setting_get('company_phone', ''));
    $currency = (string)($order['currency'] ?? 'USD');
    $total    = number_format((float)($order['total'] ?? 0), 2);
    $orderNum = (string)($order['order_number'] ?? 'MVT-DEMO-FAIL');
    $retry    = function_exists('mv_build_resume_link') && (int)($order['id'] ?? 0) > 0
                ? mv_build_resume_link($order)
                : (rtrim(site_url(), '/') . '/checkout.php');
    $attempts   = (int)($order['payment_attempts'] ?? 1);
    $attemptTxt = $attempts > 1 ? " (attempt {$attempts})" : '';

    // Items — either the real order rows, or synthetic demo lines.
    $items = [];
    try {
        if ($hasRealItems && (int)$order['id'] > 0) {
            $st = db()->prepare('SELECT name, price, qty FROM order_items WHERE order_id = ?');
            $st->execute([(int)$order['id']]);
            $items = $st->fetchAll() ?: [];
        }
    } catch (Throwable $e) { /* ignore */ }
    if (!$items) {
        $items = [
            ['name' => 'Microsoft Office Professional Plus 2024 (Digital Key)', 'price' => 89.99, 'qty' => 1],
            ['name' => 'Windows 11 Pro (Digital Key)',                                    'price' => 59.99, 'qty' => 1],
        ];
    }

    $rowsHtml = '';
    foreach ($items as $it) {
        $rowsHtml .= '<tr>'
            . '<td style="padding:12px 0;font-size:14px;color:#0f172a;">' . htmlspecialchars((string)$it['name']) . ' <span style="color:#64748b;">×' . (int)$it['qty'] . '</span></td>'
            . '<td style="padding:12px 0;font-size:14px;color:#0f172a;text-align:right;font-variant-numeric:tabular-nums;">' . htmlspecialchars($currency) . ' ' . number_format((float)$it['price'] * (int)$it['qty'], 2) . '</td>'
            . '</tr>';
    }

    $safeMsg = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    $bank    = mv_payment_failed_action_advice($code, $msg);
    $bankTitle  = htmlspecialchars($bank['title'], ENT_QUOTES, 'UTF-8');
    $bankBody   = htmlspecialchars($bank['body'],  ENT_QUOTES, 'UTF-8');
    $bankColor  = $bank['tone'] === 'primary' ? '#1d4ed8' : '#9a3412';
    $bankBg     = $bank['tone'] === 'primary' ? '#eff6ff' : '#fff7ed';
    $bankBorder = $bank['tone'] === 'primary' ? '#bfdbfe' : '#fed7aa';
    $bankIcon   = $bank['tone'] === 'primary' ? '🏦' : '💳';

    $phoneHtml = $phone !== '' ? ' · ' . htmlspecialchars($phone) : '';

    return <<<HTML
<!doctype html><html><body style="margin:0;padding:0;background:#f5f7fa;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#0f172a;">
<div style="max-width:560px;margin:0 auto;padding:32px 20px;">
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
    <div style="background:#fef2f2;padding:22px 28px;border-bottom:1px solid #fecaca;">
      <div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#b91c1c;font-weight:700;">Payment issue</div>
      <div style="font-size:20px;font-weight:700;color:#7f1d1d;margin-top:6px;">We couldn't process your payment{$attemptTxt}</div>
    </div>
    <div style="padding:26px 28px 6px;font-size:14px;line-height:1.6;color:#334155;">
      <p style="margin:0 0 14px 0;">Your bank returned the following reason for order <strong>{$orderNum}</strong>:</p>
      <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:12px 14px;color:#9a3412;font-size:13.5px;margin:0 0 18px 0;font-family:'SF Mono',Menlo,monospace;">{$safeMsg}</div>

      <div style="background:{$bankBg};border:1px solid {$bankBorder};border-radius:10px;padding:14px 16px;margin:0 0 20px 0;">
        <div style="font-weight:700;color:{$bankColor};font-size:14px;margin-bottom:4px;">
          {$bankIcon} {$bankTitle}
        </div>
        <div style="font-size:13.5px;color:#334155;line-height:1.55;">{$bankBody}</div>
      </div>

      <p style="margin:0 0 6px 0;color:#64748b;font-size:12.5px;text-transform:uppercase;letter-spacing:.08em;">Your order</p>
      <table style="width:100%;border-collapse:collapse;margin:0 0 18px 0;">
        {$rowsHtml}
        <tr><td style="padding:14px 0 0;border-top:1px solid #e2e8f0;font-size:15px;font-weight:700;color:#0f172a;">Total due</td>
        <td style="padding:14px 0 0;border-top:1px solid #e2e8f0;font-size:15px;font-weight:700;text-align:right;font-variant-numeric:tabular-nums;">{$currency} {$total}</td></tr>
      </table>
      <p style="margin:0 0 6px 0;font-size:13.5px;color:#334155;"><strong>No charge was made.</strong> Your cart is safe — click below to retry with the same or a different card.</p>
    </div>
    <div style="padding:8px 28px 28px;text-align:center;">
      <a href="{$retry}" style="display:inline-block;background:#0B5CFF;color:#fff;text-decoration:none;padding:14px 30px;border-radius:10px;font-weight:700;font-size:15px;">Retry Payment Now →</a>
      <p style="margin:16px 0 0;font-size:12px;color:#64748b;">Or copy this link: <br><a href="{$retry}" style="color:#0B5CFF;word-break:break-all;">{$retry}</a></p>
    </div>
    <div style="background:#f8fafc;padding:18px 28px;border-top:1px solid #f1f5f9;font-size:12px;color:#64748b;line-height:1.6;">
      Need help? Reply to this email or contact
      <a href="mailto:{$support}" style="color:#0B5CFF;text-decoration:none;">{$support}</a>{$phoneHtml}
      <br><span style="color:#94a3b8;">© {$brand}</span>
    </div>
  </div>
</div>
</body></html>
HTML;
}

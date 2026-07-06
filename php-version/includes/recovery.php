<?php
/**
 * Payment failure / recovery helpers.
 *
 * All the moving parts for the Checkout Payment & License Delivery
 * Hardening patch live here so future edits are localised:
 *
 *   · mv_resume_secret()             — HMAC secret (auto-generated once)
 *   · mv_build_resume_link($order)   — /checkout.php?resume=<order#>&sig=<hmac>
 *   · mv_verify_resume_signature()   — constant-time HMAC verifier
 *   · mv_mark_payment_failed()       — record decline reason + fire admin/customer emails
 *   · mv_mark_payment_succeeded()    — flip DB + append transaction log
 *   · mv_send_payment_failed_email() — customer-facing failure email
 *   · mv_send_admin_failure_email()  — internal ops notification
 *   · mv_send_abandoned_cart_email() — Paddle-style "left behind" email
 *   · mv_abandoned_cart_sweep()      — cron worker; single-shot per order
 *
 * Design contract:
 *   1. LICENSE KEYS ARE NEVER RELEASED HERE. All state changes are
 *      payment/status metadata + notifications.  Only fulfill_order()
 *      in includes/email.php touches license_keys.
 *   2. The retry link is a stateful, deterministic HMAC over
 *      order_number using a persistent server secret. It NEVER expires
 *      (spec choice) — invalidation is via orders.admin_cancelled=1.
 *   3. Abandoned emails fire ONCE per order (recovery_email_sent flag).
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/admin-notify.php';

/**
 * Persistent HMAC secret used to sign retry links.  Auto-generated on
 * first read so it survives across requests and shared-hosting deploys.
 */
function mv_resume_secret(): string
{
    $s = trim((string)setting_get('resume_secret', ''));
    if ($s === '') {
        try {
            $s = bin2hex(random_bytes(32));
            setting_set('resume_secret', $s);
        } catch (Throwable $e) {
            // Fallback — deterministic per-install but not cryptographically random.
            $s = hash('sha256', (defined('SITE_EMAIL') ? SITE_EMAIL : 'maventech') . '|' . __FILE__);
        }
    }
    return $s;
}

/**
 * Compute the HMAC signature for a given order number.
 */
function mv_sign_order_number(string $orderNumber): string
{
    return hash_hmac('sha256', $orderNumber, mv_resume_secret());
}

/**
 * Constant-time verifier used by /checkout.php?resume=…&sig=….
 */
function mv_verify_resume_signature(string $orderNumber, string $sig): bool
{
    if ($orderNumber === '' || $sig === '') return false;
    return hash_equals(mv_sign_order_number($orderNumber), (string)$sig);
}

/**
 * Absolute URL a customer can click to resume checkout.
 */
function mv_build_resume_link(array $order): string
{
    $base = function_exists('site_url') ? rtrim(site_url(), '/') : '';
    $qs   = http_build_query([
        'resume' => (string)$order['order_number'],
        'sig'    => mv_sign_order_number((string)$order['order_number']),
    ]);
    return $base . '/checkout.php?' . $qs;
}

/**
 * Record a payment failure on an order:
 *   · orders.payment_status='failed', payment_error_code/message,
 *     payment_attempts+=1, last_activity_at=NOW()
 *   · transaction_logs row (status='failed')
 *   · Admin bell + admin failure email + customer failure email
 * Idempotent: safe to call multiple times per order (each call is one attempt).
 *
 * $err = ['code'=>'card_declined','message'=>'Your card was declined.','transaction_id'=>'pi_…']
 */
function mv_mark_payment_failed(array $order, array $err): void
{
    if (empty($order['id'])) return;
    $pdo = db();
    $orderId = (int)$order['id'];
    $code = (string)($err['code'] ?? '');
    $msg  = (string)($err['message'] ?? '');
    if ($msg === '' && $code !== '' && function_exists('mv_humanize_stripe_error')) {
        $msg = mv_humanize_stripe_error($code);
    }
    if ($msg === '') $msg = 'Payment was declined. Please try again.';

    try {
        $pdo->prepare(
            'UPDATE orders
                SET payment_status = "failed",
                    payment_error_code = ?,
                    payment_error_message = ?,
                    payment_attempts = payment_attempts + 1,
                    last_activity_at = NOW()
              WHERE id = ?'
        )->execute([substr($code, 0, 80), substr($msg, 0, 500), $orderId]);
    } catch (Throwable $e) { @error_log('[mv_mark_payment_failed] ' . $e->getMessage()); }

    try {
        $pdo->prepare('INSERT INTO transaction_logs (order_id, gateway, transaction_id, amount, currency, status)
                       VALUES (?,?,?,?,?,?)')
            ->execute([
                $orderId,
                (string)($order['payment_method'] ?? 'card'),
                (string)($err['transaction_id'] ?? ''),
                (float)($order['total'] ?? 0),
                (string)($order['currency'] ?? 'USD'),
                'failed',
            ]);
    } catch (Throwable $e) { /* best-effort */ }

    // Admin bell.
    try {
        admin_notify(
            'payment_failed',
            'Payment failed — ' . (string)($order['order_number'] ?? ('#' . $orderId)),
            'Reason: ' . $msg . ' · ' . (string)($order['currency'] ?? 'USD') . ' ' . number_format((float)($order['total'] ?? 0), 2),
            '/admin.php?tab=orders&q=' . urlencode((string)($order['order_number'] ?? ''))
        );
    } catch (Throwable $e) { /* best-effort */ }

    // Refresh row (payment_attempts changed) before emails so template shows the right count.
    try {
        $row = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $row->execute([$orderId]);
        $fresh = $row->fetch();
        if ($fresh) $order = array_merge($order, $fresh);
    } catch (Throwable $e) { /* ignore */ }

    // Send customer + admin failure emails.
    try { mv_send_payment_failed_email($order, $code, $msg); } catch (Throwable $e) { @error_log('[mv_send_payment_failed_email] ' . $e->getMessage()); }
    try { mv_send_admin_failure_email($order, $code, $msg);  } catch (Throwable $e) { @error_log('[mv_send_admin_failure_email] '  . $e->getMessage()); }
}

/**
 * Mark a payment succeeded server-side.  Only bumps payment_status;
 * fulfil_order() remains the single source of key delivery and reads
 * orders.status='paid'.  This helper is safe to call from webhook +
 * order-success return path (idempotent).
 */
function mv_mark_payment_succeeded(int $orderId, string $transactionId = ''): void
{
    $pdo = db();
    try {
        $pdo->prepare(
            'UPDATE orders
                SET payment_status = "succeeded",
                    last_activity_at = NOW()
              WHERE id = ?'
        )->execute([$orderId]);
    } catch (Throwable $e) { @error_log('[mv_mark_payment_succeeded] ' . $e->getMessage()); }
}

// ============================================================================
// EMAIL: customer "payment failed" — clean, single CTA (Retry Payment Now)
// ============================================================================

/**
 * Map a gateway decline code / message into a customer-actionable advice
 * block used both in the failure email AND the on-page checkout banner so
 * the two surfaces stay in sync.  Returns:
 *   [
 *     'title' => 'Call your bank to authorize this payment',
 *     'body'  => 'Long-form explanation …',
 *     'tone'  => 'warning' | 'primary',
 *   ]
 * NB: We NEVER echo the raw gateway string here — it's already shown as
 * a monospace "reason" row above the tip.  This helper turns technical
 * codes into plain-English next-steps.
 */
function mv_payment_failed_action_advice(string $code, string $msg): array
{
    $code = strtolower(trim($code));
    $lm   = strtolower($msg);

    // Bank-declined family → tell them to authorize with their bank
    $bankDeclines = ['do_not_honor', 'card_declined', 'generic_decline',
                     'call_issuer', 'issuer_not_available', 'try_again_later',
                     'processing_error', 'fraudulent', 'security_violation',
                     'stop_payment_order', 'transaction_not_allowed',
                     'restricted_card', 'service_not_allowed'];
    if (in_array($code, $bankDeclines, true)
        || stripos($lm, 'do not honor') !== false
        || stripos($lm, 'declined') !== false
        || stripos($lm, 'call your bank') !== false
        || stripos($lm, 'contact your bank') !== false) {
        return [
            'title' => 'Please contact your bank to authorize this payment',
            'body'  => 'Your bank declined the transaction — usually because they don\'t recognize an online software purchase from a new merchant. '
                     . 'Call the phone number on the back of your card (or open your banking app) and either (a) authorize the transaction with '
                     . (function_exists('setting_get') ? htmlspecialchars((string)setting_get('company_name', 'Maventech')) : 'Maventech')
                     . ', or (b) approve any pending fraud-alert. Then return to this page and click Retry — the very same card will usually work on the second attempt.',
            'tone'  => 'warning',
        ];
    }

    // 3-D Secure / authentication → user needs to finish the challenge
    if (in_array($code, ['authentication_required', 'authentication_failed'], true)
        || stripos($lm, '3d secure') !== false
        || stripos($lm, '3-d secure') !== false
        || stripos($lm, 'authentication') !== false) {
        return [
            'title' => 'Your bank needs to verify this payment (3-D Secure)',
            'body'  => 'Your bank asked to confirm this purchase — most likely a one-time code sent to your phone, or an approval prompt in your banking app. '
                     . 'Click Retry below, complete the verification when your bank shows the popup, and the payment will go through.',
            'tone'  => 'primary',
        ];
    }

    // Insufficient funds → change card
    if ($code === 'insufficient_funds' || stripos($lm, 'insufficient funds') !== false) {
        return [
            'title' => 'Insufficient funds on the card',
            'body'  => 'The card was declined because there aren\'t enough available funds. Please try again with a different card, or top up your account first — no charge was made and your cart is preserved.',
            'tone'  => 'warning',
        ];
    }

    // Bad card details → user typo
    if (in_array($code, ['incorrect_number', 'incorrect_cvc', 'cvc_check_failed', 'invalid_expiry_month', 'invalid_expiry_year', 'expired_card', 'invalid_card'], true)) {
        return [
            'title' => 'Please double-check your card details',
            'body'  => 'The card number, expiry or CVV/CVC didn\'t match what your bank has on file. Click Retry and re-enter them carefully — or try a different card.',
            'tone'  => 'warning',
        ];
    }

    // Lost / stolen → hard block, tell them to use another card
    if (in_array($code, ['lost_card', 'stolen_card'], true)) {
        return [
            'title' => 'This card has been reported lost or stolen',
            'body'  => 'For your safety your bank has blocked this card. Please use a different card to complete the purchase.',
            'tone'  => 'warning',
        ];
    }

    // PayPal / other → generic bank message
    return [
        'title' => 'Please try again or contact your bank',
        'body'  => 'The payment couldn\'t be completed on the first attempt. Please retry below — either with the same card (after checking with your bank for any pending fraud alert) or with a different card. No charge was made.',
        'tone'  => 'warning',
    ];
}

function mv_send_payment_failed_email(array $order, string $code, string $msg): void
{
    $to = trim((string)($order['email'] ?? ''));
    if ($to === '') return;
    $pdo   = db();
    $items = [];
    try {
        $st = $pdo->prepare('SELECT product_slug, name, price, qty FROM order_items WHERE order_id = ?');
        $st->execute([(int)$order['id']]);
        $items = $st->fetchAll() ?: [];
    } catch (Throwable $e) { /* best-effort */ }

    $brand   = defined('SITE_BRAND') ? SITE_BRAND : 'Maventech';
    $support = trim((string)setting_get('company_support_email', defined('SITE_EMAIL') ? SITE_EMAIL : ''));
    $phone   = trim((string)setting_get('company_phone', ''));
    $currency = (string)($order['currency'] ?? 'USD');
    $total    = number_format((float)($order['total'] ?? 0), 2);
    $orderNum = (string)($order['order_number'] ?? '');
    $retry    = mv_build_resume_link($order);
    $attempts = (int)($order['payment_attempts'] ?? 0);
    $attemptTxt = $attempts > 1 ? " (attempt {$attempts})" : '';

    $rowsHtml = '';
    foreach ($items as $it) {
        $rowsHtml .= '<tr>'
            . '<td style="padding:12px 0;font-size:14px;color:#0f172a;">' . htmlspecialchars((string)$it['name']) . ' <span style="color:#64748b;">×' . (int)$it['qty'] . '</span></td>'
            . '<td style="padding:12px 0;font-size:14px;color:#0f172a;text-align:right;font-variant-numeric:tabular-nums;">' . htmlspecialchars($currency) . ' ' . number_format((float)$it['price'] * (int)$it['qty'], 2) . '</td>'
            . '</tr>';
    }
    if ($rowsHtml === '') $rowsHtml = '<tr><td style="padding:12px 0;font-size:13px;color:#64748b;">Your items</td><td></td></tr>';

    $subject = "We couldn't process your payment — Order {$orderNum}";
    $safeMsg = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');

    // ---- Decode the decline into a friendly action-message the customer can
    //      actually act on ("call your bank / authorize this payment") rather
    //      than the raw gateway string.  Keeps parity with the on-page banner
    //      shown in checkout.php.
    $bankAction = mv_payment_failed_action_advice($code, $msg);
    $bankTitle  = htmlspecialchars($bankAction['title'], ENT_QUOTES, 'UTF-8');
    $bankBody   = htmlspecialchars($bankAction['body'],  ENT_QUOTES, 'UTF-8');
    $bankColor  = $bankAction['tone'] === 'primary' ? '#1d4ed8' : '#9a3412';
    $bankBg     = $bankAction['tone'] === 'primary' ? '#eff6ff' : '#fff7ed';
    $bankBorder = $bankAction['tone'] === 'primary' ? '#bfdbfe' : '#fed7aa';
    $bankIcon   = $bankAction['tone'] === 'primary' ? '🏦' : '💳';

    $html = <<<HTML
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

      <!-- Bank-action tip box — tells the customer EXACTLY what to do next
           (call their bank / authorise the transaction in the mobile app) -->
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
      <a href="mailto:{$support}" style="color:#0B5CFF;text-decoration:none;">{$support}</a>__PHONE_HTML__
      <br><span style="color:#94a3b8;">© {$brand}</span>
    </div>
  </div>
</div>
</body></html>
HTML;
    $phoneHtml = $phone !== '' ? ' · ' . htmlspecialchars($phone) : '';
    $html = str_replace('__PHONE_HTML__', $phoneHtml, $html);

    send_email($to, $subject, $html, (int)$order['id'], 'payment_failed', 0);
}

// ============================================================================
// EMAIL: admin "failed transaction" internal notification
// ============================================================================

function mv_send_admin_failure_email(array $order, string $code, string $msg): void
{
    $adminEmail = trim((string)setting_get('company_support_email', ''));
    if ($adminEmail === '') $adminEmail = trim((string)setting_get('company_email', ''));
    if ($adminEmail === '' && defined('ADMIN_EMAIL')) $adminEmail = (string)ADMIN_EMAIL;
    if ($adminEmail === '') return;

    $brand   = defined('SITE_BRAND') ? SITE_BRAND : 'Maventech';
    $siteUrl = function_exists('site_url') ? rtrim(site_url(), '/') : '';
    $link    = $siteUrl . '/admin.php?tab=orders&q=' . urlencode((string)($order['order_number'] ?? ''));
    $orderNum = htmlspecialchars((string)($order['order_number'] ?? ''));
    $email    = htmlspecialchars((string)($order['email'] ?? ''));
    $curTxt   = htmlspecialchars((string)($order['currency'] ?? 'USD') . ' ' . number_format((float)($order['total'] ?? 0), 2));
    $reason   = htmlspecialchars($msg);
    $codeTxt  = htmlspecialchars($code !== '' ? $code : 'unknown');
    $attempts = (int)($order['payment_attempts'] ?? 0);

    $subject = "[{$brand}] Payment failed — order {$orderNum} ({$codeTxt})";
    $html = <<<HTML
<div style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;max-width:620px;margin:0 auto;color:#0f172a">
  <div style="background:#7f1d1d;padding:18px 22px;border-radius:10px 10px 0 0;">
    <div style="font-size:11px;letter-spacing:.12em;font-weight:800;text-transform:uppercase;color:#fecaca;">{$brand} — payment ops</div>
    <div style="font-size:20px;font-weight:800;color:#fff;margin-top:4px;">Payment failed — action optional</div>
  </div>
  <div style="background:#fff;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 10px 10px;padding:22px;line-height:1.55;font-size:13.5px;">
    <table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:13px;margin:0 0 18px 0;">
      <tr><td style="padding:7px 0;color:#64748b;width:170px;">Order</td><td style="padding:7px 0;font-weight:700;">#{$orderNum}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Customer</td><td style="padding:7px 0;"><a href="mailto:{$email}" style="color:#2563eb;text-decoration:none;">{$email}</a></td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Amount</td><td style="padding:7px 0;">{$curTxt}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Attempt #</td><td style="padding:7px 0;">{$attempts}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Gateway code</td><td style="padding:7px 0;"><code>{$codeTxt}</code></td></tr>
      <tr><td style="padding:7px 0;color:#64748b;vertical-align:top;">Message</td><td style="padding:7px 0;">{$reason}</td></tr>
    </table>
    <a href="{$link}" style="display:inline-block;background:#0f172a;color:#fff;text-decoration:none;padding:11px 22px;border-radius:8px;font-weight:700;font-size:14px;">Open in admin ›</a>
    <p style="margin:20px 0 0;font-size:12px;color:#64748b;">The customer has been emailed a retry link. No license key was released. Configure recipient in Admin → Company Info.</p>
  </div>
</div>
HTML;

    send_email($adminEmail, $subject, $html, (int)$order['id'], 'admin_payment_failed', 0);
}

// ============================================================================
// EMAIL: Paddle-style "Looks like you left something behind"
// ============================================================================

function mv_send_abandoned_cart_email(array $order): bool
{
    $to = trim((string)($order['email'] ?? ''));
    if ($to === '') return false;

    $pdo   = db();
    $items = [];
    try {
        $st = $pdo->prepare('SELECT oi.product_slug, oi.name, oi.price, oi.qty, p.image
                             FROM order_items oi LEFT JOIN products p ON p.slug = oi.product_slug
                             WHERE oi.order_id = ?');
        $st->execute([(int)$order['id']]);
        $items = $st->fetchAll() ?: [];
    } catch (Throwable $e) { /* best-effort */ }
    if (!$items) return false;

    $brand   = defined('SITE_BRAND') ? SITE_BRAND : 'Maventech';
    $support = trim((string)setting_get('company_support_email', defined('SITE_EMAIL') ? SITE_EMAIL : ''));
    $currency = (string)($order['currency'] ?? 'USD');
    $orderNum = (string)($order['order_number'] ?? '');
    $total    = number_format((float)($order['total'] ?? 0), 2);
    $resume   = mv_build_resume_link($order);

    $rowsHtml = '';
    foreach ($items as $it) {
        $img = trim((string)($it['image'] ?? ''));
        $imgTag = '';
        if ($img !== '') {
            $absImg = function_exists('email_absolute_url') ? email_absolute_url($img) : $img;
            $imgTag = '<img src="' . htmlspecialchars($absImg) . '" width="56" height="56" alt="" style="width:56px;height:56px;border-radius:8px;object-fit:cover;display:block;">';
        }
        $rowsHtml .= '<tr>'
            . '<td style="padding:12px 12px 12px 0;width:56px;vertical-align:middle;">' . $imgTag . '</td>'
            . '<td style="padding:12px 0;vertical-align:middle;font-size:14px;color:#0f172a;">'
            . htmlspecialchars((string)$it['name']) . ' <span style="color:#64748b;">×' . (int)$it['qty'] . '</span></td>'
            . '<td style="padding:12px 0;vertical-align:middle;font-size:14px;color:#0f172a;text-align:right;font-variant-numeric:tabular-nums;">'
            . htmlspecialchars($currency) . ' ' . number_format((float)$it['price'] * (int)$it['qty'], 2) . '</td>'
            . '</tr>';
    }

    $subject = "Looks like you left something behind — Order {$orderNum}";
    $html = <<<HTML
<!doctype html><html><body style="margin:0;padding:0;background:#f5f7fa;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#0f172a;">
<div style="max-width:560px;margin:0 auto;padding:32px 20px;">
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
    <div style="background:linear-gradient(135deg,#0B5CFF,#4480FF);padding:26px 28px;color:#fff;">
      <div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;font-weight:700;opacity:.9;">{$brand}</div>
      <div style="font-size:22px;font-weight:700;margin-top:6px;">Looks like you left something behind!</div>
    </div>
    <div style="padding:26px 28px 8px;font-size:14px;line-height:1.6;color:#334155;">
      <p style="margin:0 0 16px 0;">We noticed you didn't finish checking out — your cart is still saved and ready when you are. It only takes a minute to complete.</p>
      <table style="width:100%;border-collapse:collapse;margin:0 0 18px 0;">
        {$rowsHtml}
        <tr><td colspan="2" style="padding:14px 0 0;border-top:1px solid #e2e8f0;font-size:15px;font-weight:700;color:#0f172a;">Total</td>
        <td style="padding:14px 0 0;border-top:1px solid #e2e8f0;font-size:15px;font-weight:700;text-align:right;font-variant-numeric:tabular-nums;">{$currency} {$total}</td></tr>
      </table>
    </div>
    <div style="padding:6px 28px 30px;text-align:center;">
      <a href="{$resume}" style="display:inline-block;background:#0B5CFF;color:#fff;text-decoration:none;padding:14px 32px;border-radius:10px;font-weight:700;font-size:15px;">Continue Checkout →</a>
      <p style="margin:14px 0 0;font-size:12px;color:#64748b;">Your product keys are delivered by email after your payment is verified.</p>
    </div>
    <div style="background:#f8fafc;padding:16px 28px;border-top:1px solid #f1f5f9;font-size:12px;color:#64748b;line-height:1.6;text-align:center;">
      Questions? <a href="mailto:{$support}" style="color:#0B5CFF;text-decoration:none;">{$support}</a>
      <br><span style="color:#94a3b8;">© {$brand} · You're receiving this once because you started a checkout with us.</span>
    </div>
  </div>
</div>
</body></html>
HTML;

    send_email($to, $subject, $html, (int)$order['id'], 'abandoned_cart', 0);
    return true;
}

// ============================================================================
// CRON WORKER: mv_abandoned_cart_sweep()
// ============================================================================

/**
 * Scan for orders where the customer clicked Pay but never completed a
 * successful payment, and 30+ minutes have elapsed since the last activity.
 * Sends the abandoned-cart email ONCE per order, then marks
 * recovery_email_sent=1 so it never re-fires.
 *
 * Called from /cron.php (fires ~every minute in preview + shared hosting cron).
 * Returns ['scanned'=>N, 'sent'=>M, 'errors'=>K].
 */
function mv_abandoned_cart_sweep(int $batch = 50, int $minAgeMinutes = 30, int $maxAgeDays = 30): array
{
    $out = ['scanned' => 0, 'sent' => 0, 'errors' => 0];
    $pdo = db();
    try {
        $sql = "SELECT * FROM orders
                 WHERE recovery_email_sent = 0
                   AND admin_cancelled = 0
                   AND fulfilled = 0
                   AND status IN ('pending','cancelled')
                   AND (payment_status IS NULL OR payment_status IN ('pending','failed','abandoned'))
                   AND email <> ''
                   AND created_at > (NOW() - INTERVAL {$maxAgeDays} DAY)
                   AND (
                        (last_activity_at IS NOT NULL AND last_activity_at < (NOW() - INTERVAL {$minAgeMinutes} MINUTE))
                        OR
                        (last_activity_at IS NULL AND created_at < (NOW() - INTERVAL {$minAgeMinutes} MINUTE))
                   )
                 ORDER BY id ASC
                 LIMIT " . max(1, (int)$batch);
        $rows = $pdo->query($sql)->fetchAll() ?: [];
    } catch (Throwable $e) {
        @error_log('[mv_abandoned_cart_sweep] query failed: ' . $e->getMessage());
        return $out;
    }

    foreach ($rows as $order) {
        $out['scanned']++;
        try {
            $ok = mv_send_abandoned_cart_email($order);
            if ($ok) {
                $pdo->prepare('UPDATE orders SET recovery_email_sent = 1, payment_status = COALESCE(payment_status,"abandoned") WHERE id = ?')
                    ->execute([(int)$order['id']]);
                $out['sent']++;
                try {
                    admin_notify(
                        'abandoned_cart',
                        'Abandoned cart recovered — ' . (string)$order['order_number'],
                        'Recovery email sent to ' . (string)$order['email'] . ' · ' . (string)$order['currency'] . ' ' . number_format((float)$order['total'], 2),
                        '/admin.php?tab=orders&q=' . urlencode((string)$order['order_number'])
                    );
                } catch (Throwable $e) { /* best-effort */ }
            }
        } catch (Throwable $e) {
            $out['errors']++;
            @error_log('[mv_abandoned_cart_sweep] order #' . (int)$order['id'] . ': ' . $e->getMessage());
        }
    }
    return $out;
}

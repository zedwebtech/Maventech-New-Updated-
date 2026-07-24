<?php
/**
 * Follow-up Emails Module (2026-07)
 * ----------------------------------------------------------------------
 * Sends a friendly 2-touch follow-up to every customer after a paid
 * order:
 *   Stage 1  — 7 days after purchase
 *   Stage 2  — 30 days after purchase
 * Each email contains the customer's order number, our support phone
 * and email, and a one-click unsubscribe link.  Unsubscribed emails
 * are stored in `email_unsubscribes` and never re-contacted.
 *
 * Public API:
 *   schedule_order_followups(int $orderId, string $email)
 *   send_pending_followups(int $limit = 25): int  → sent count
 *   is_email_unsubscribed(string $email): bool
 *   unsubscribe_email(string $email, string $reason=''): void
 *   unsubscribe_token_for(string $email): string
 *   verify_unsubscribe_token(string $email, string $token): bool
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/email.php';

/**
 * Idempotent schema migration for the follow-up tables.  Called from
 * ensure_db_schema() and (defensively) from schedule_order_followups()
 * so admins can drop this file onto an older install without a manual
 * migration.
 */
function ensure_followup_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS email_followup_schedule (
            id            BIGINT      NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id      INT         NOT NULL,
            email         VARCHAR(190) NOT NULL,
            stage         TINYINT     NOT NULL DEFAULT 1,
            scheduled_at  DATETIME    NOT NULL,
            sent_at       DATETIME    NULL DEFAULT NULL,
            status        ENUM('pending','sent','skipped','failed') NOT NULL DEFAULT 'pending',
            note          VARCHAR(255) NOT NULL DEFAULT '',
            created_at    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_order_stage (order_id, stage),
            KEY idx_status_time (status, scheduled_at),
            KEY idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS email_unsubscribes (
            id             INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email          VARCHAR(190) NOT NULL,
            reason         VARCHAR(120) NOT NULL DEFAULT '',
            source         VARCHAR(40)  NOT NULL DEFAULT 'link',
            unsubscribed_at TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        @error_log('[followup schema] ' . $e->getMessage());
    }
}

/**
 * Schedule the two follow-up rows for an order.  Called from
 * fulfill_order() right after the delivery email is queued.
 * UNIQUE(order_id, stage) makes this safe to call multiple times.
 */
function schedule_order_followups(int $orderId, string $email): void
{
    ensure_followup_schema();
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    if (is_email_unsubscribed($email)) return;

    try {
        $pdo = db();
        $stmt = $pdo->prepare('INSERT IGNORE INTO email_followup_schedule
            (order_id, email, stage, scheduled_at, status)
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), "pending")');
        // Stage 1: +7 days, Stage 2: +30 days.  These intervals are the
        // "How is your software going?" and "Anything we can help with?"
        // touches described in the product brief.
        $stmt->execute([$orderId, $email, 1, 7]);
        $stmt->execute([$orderId, $email, 2, 30]);
    } catch (Throwable $e) {
        @error_log('[schedule_order_followups] ' . $e->getMessage());
    }
}

/**
 * Cron worker — pulls up to $limit due follow-ups, sends them, marks
 * them 'sent' / 'skipped' / 'failed', and returns the count actually
 * emailed.  Safe to run once a minute.
 */
function send_pending_followups(int $limit = 25): int
{
    ensure_followup_schema();
    $pdo = db();
    $limit = max(1, min(200, $limit));
    $rows = $pdo->query(
        'SELECT s.id, s.order_id, s.email, s.stage
         FROM email_followup_schedule s
         WHERE s.status = "pending"
           AND s.scheduled_at <= NOW()
         ORDER BY s.scheduled_at ASC
         LIMIT ' . $limit
    )->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) return 0;

    $sent = 0;
    foreach ($rows as $r) {
        $email = strtolower(trim((string)$r['email']));
        $sid   = (int)$r['id'];
        $oid   = (int)$r['order_id'];
        $stage = (int)$r['stage'];

        if (is_email_unsubscribed($email)) {
            $pdo->prepare('UPDATE email_followup_schedule SET status="skipped", sent_at=NOW(), note="unsubscribed" WHERE id=?')
                ->execute([$sid]);
            continue;
        }

        // Load order for personalisation.
        try {
            $stmt = $pdo->prepare('SELECT id, order_number, email, first_name, last_name, total, currency, created_at
                                   FROM orders WHERE id = ? LIMIT 1');
            $stmt->execute([$oid]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $order = null; }
        if (!$order) {
            $pdo->prepare('UPDATE email_followup_schedule SET status="skipped", sent_at=NOW(), note="order missing" WHERE id=?')
                ->execute([$sid]);
            continue;
        }

        try {
            [$subject, $html] = build_followup_email($order, $stage);
            // send_email() → queues into email_outbox → smtp_process_queue()
            // will dispatch it. Passing the templateCode threads it into
            // the admin's Email log.
            send_email(
                $email,
                $subject,
                $html,
                $oid,
                'followup_' . $stage,
                0
            );
            $pdo->prepare('UPDATE email_followup_schedule SET status="sent", sent_at=NOW() WHERE id=?')
                ->execute([$sid]);
            $sent++;
        } catch (Throwable $e) {
            @error_log('[followup send] ' . $e->getMessage());
            $pdo->prepare('UPDATE email_followup_schedule SET status="failed", sent_at=NOW(), note=? WHERE id=?')
                ->execute([substr($e->getMessage(), 0, 240), $sid]);
        }
    }
    return $sent;
}

function is_email_unsubscribed(string $email): bool
{
    $email = strtolower(trim($email));
    if ($email === '') return false;
    ensure_followup_schema();
    try {
        $stmt = db()->prepare('SELECT 1 FROM email_unsubscribes WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function unsubscribe_email(string $email, string $reason = '', string $source = 'link'): void
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    ensure_followup_schema();
    try {
        $stmt = db()->prepare('INSERT INTO email_unsubscribes (email, reason, source)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE reason = VALUES(reason), source = VALUES(source), unsubscribed_at = NOW()');
        $stmt->execute([$email, substr($reason, 0, 120), substr($source, 0, 40)]);
        // Also cancel any pending follow-ups for this email.
        db()->prepare('UPDATE email_followup_schedule SET status="skipped", sent_at=NOW(), note="unsubscribed"
            WHERE email = ? AND status = "pending"')->execute([$email]);
    } catch (Throwable $e) {
        @error_log('[unsubscribe_email] ' . $e->getMessage());
    }
}

/**
 * Deterministic HMAC token for the unsubscribe link.  Uses the cron
 * token (or SITE_HMAC_SECRET if defined) as the signing key so the URL
 * cannot be guessed without the server-side secret.
 */
function unsubscribe_token_for(string $email): string
{
    $email = strtolower(trim($email));
    $key = defined('SITE_HMAC_SECRET') ? SITE_HMAC_SECRET
        : (string)(function_exists('setting_get') ? setting_get('cron_token', '') : '');
    if ($key === '') $key = 'maventech-unsub-fallback-key-2026';
    return substr(hash_hmac('sha256', 'unsub:' . $email, $key), 0, 32);
}

function verify_unsubscribe_token(string $email, string $token): bool
{
    $expected = unsubscribe_token_for($email);
    return hash_equals($expected, $token);
}

/**
 * Build the follow-up email (subject + html).  Two stages:
 *   1  = +7 days   — check-in / offer help with activation
 *   2  = +30 days  — 30-day satisfaction / any lingering issues
 */
function build_followup_email(array $order, int $stage): array
{
    $co = function_exists('company_info') ? company_info() : [];
    $brand   = (string)($co['name']  ?? (defined('SITE_BRAND') ? SITE_BRAND : 'Maventech'));
    $phone   = (string)($co['phone'] ?? (defined('SITE_PHONE') ? SITE_PHONE : ''));
    $support = (string)($co['email'] ?? (defined('SITE_EMAIL') ? SITE_EMAIL : ''));
    $base    = function_exists('public_base_url') ? public_base_url()
             : (defined('SITE_URL') ? SITE_URL : 'https://maventechsoftware.com');
    $base    = rtrim($base, '/');

    $orderNumber = (string)($order['order_number'] ?? '#' . $order['id']);
    $firstName   = trim((string)($order['first_name'] ?? '')) ?: 'there';
    $email       = strtolower(trim((string)($order['email'] ?? '')));

    $unsubUrl = $base . '/unsubscribe.php?e=' . urlencode($email)
              . '&t=' . unsubscribe_token_for($email);
    $supportUrl = $base . '/support.php';
    $orderUrl   = $base . '/order-history.php';

    if ($stage === 1) {
        $subject = "How's your software going, {$firstName}? — Order #{$orderNumber}";
        $intro   = "It has been a week since your purchase from {$brand}, and we wanted to check in. If you're still setting up your software or have hit any snags with activation or installation, we're right here to help.";
        $stageBadge = "7-day check-in";
    } else {
        $subject = "Anything we can help with, {$firstName}? — Order #{$orderNumber}";
        $intro   = "It has been a month since your order from {$brand}. If everything is working perfectly — brilliant! If you need help with a re-install, activation on a new device, or a licence question, our team is one message away.";
        $stageBadge = "30-day check-in";
    }

    $phoneHtml = $phone !== ''
        ? '<a href="tel:' . esc('+' . preg_replace('/\D/', '', $phone)) . '" style="color:#0f172a;text-decoration:none;font-weight:600;">' . esc($phone) . '</a>'
        : '';
    $emailHtml = $support !== ''
        ? '<a href="mailto:' . esc($support) . '" style="color:#0f172a;text-decoration:none;font-weight:600;">' . esc($support) . '</a>'
        : '';

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
          . '<meta name="viewport" content="width=device-width,initial-scale=1">'
          . '<title>' . esc($subject) . '</title></head>'
          . '<body style="margin:0;padding:0;background:#f5f7fb;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">'
          . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fb;padding:24px 0;"><tr><td align="center">'
          . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 22px rgba(15,23,42,0.08);">'
          // ---- Header ----
          . '<tr><td style="padding:22px 28px 18px 28px;border-bottom:1px solid #eef2f7;">'
          . '<div style="display:inline-block;padding:4px 10px;background:#eef2ff;color:#4338ca;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;">' . esc($stageBadge) . '</div>'
          . '<div style="margin-top:10px;font-size:20px;font-weight:800;color:#0f172a;">' . esc($brand) . '</div>'
          . '</td></tr>'
          // ---- Body ----
          . '<tr><td style="padding:26px 28px 4px 28px;">'
          . '<h1 style="margin:0 0 12px 0;font-size:22px;font-weight:800;line-height:1.25;color:#0f172a;">Hi ' . esc($firstName) . ',</h1>'
          . '<p style="margin:0 0 16px 0;font-size:15px;line-height:1.55;color:#334155;">' . esc($intro) . '</p>'

          . '<div style="margin:18px 0;padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">'
          . '<div style="font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:#64748b;font-weight:600;">Your order</div>'
          . '<div style="margin-top:4px;font-size:15px;font-weight:700;color:#0f172a;">#' . esc($orderNumber) . '</div>'
          . '<div style="margin-top:8px;font-size:13px;color:#475569;">'
          . '<a href="' . esc($orderUrl) . '" style="color:#4338ca;text-decoration:none;font-weight:600;">View your order history &rarr;</a>'
          . '</div>'
          . '</div>'

          . '<h2 style="margin:22px 0 8px 0;font-size:16px;font-weight:700;color:#0f172a;">Need help with anything?</h2>'
          . '<p style="margin:0 0 12px 0;font-size:14px;line-height:1.55;color:#475569;">'
          . 'If you are running into any software or computer-related issue — installation, activation, moving your licence to a new PC/Mac, or an unrelated Windows/Office question — just get in touch and our team will walk you through it.'
          . '</p>'
          . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:14px 0;background:#f1f5f9;border-radius:10px;">'
          . ($phoneHtml !== '' ? '<tr><td style="padding:12px 16px;border-bottom:1px solid #e2e8f0;font-size:13px;color:#64748b;">Support phone</td><td style="padding:12px 16px;border-bottom:1px solid #e2e8f0;text-align:right;font-size:14px;">' . $phoneHtml . '</td></tr>' : '')
          . ($emailHtml !== '' ? '<tr><td style="padding:12px 16px;font-size:13px;color:#64748b;">Support email</td><td style="padding:12px 16px;text-align:right;font-size:14px;">' . $emailHtml . '</td></tr>' : '')
          . '</table>'

          . '<div style="margin:22px 0 8px 0;text-align:center;">'
          . '<a href="' . esc($supportUrl) . '" style="display:inline-block;padding:12px 26px;background:#4338ca;color:#ffffff;border-radius:999px;font-size:14px;font-weight:700;text-decoration:none;">Contact Support</a>'
          . '</div>'
          . '<p style="margin:16px 0 0 0;font-size:14px;line-height:1.55;color:#475569;">Thanks for choosing ' . esc($brand) . ' — we appreciate you.</p>'
          . '<p style="margin:8px 0 26px 0;font-size:14px;color:#334155;">— The ' . esc($brand) . ' Team</p>'
          . '</td></tr>'
          // ---- Footer / Unsubscribe ----
          . '<tr><td style="padding:16px 28px 20px 28px;background:#f8fafc;border-top:1px solid #eef2f7;font-size:12px;line-height:1.55;color:#64748b;text-align:center;">'
          . 'You are receiving this follow-up because you purchased from ' . esc($brand) . '.<br>'
          . 'No longer want these check-ins? '
          . '<a href="' . esc($unsubUrl) . '" style="color:#4338ca;text-decoration:underline;font-weight:600;">Unsubscribe with one click</a>.'
          . '</td></tr>'
          . '</table></td></tr></table></body></html>';

    return [$subject, $html];
}

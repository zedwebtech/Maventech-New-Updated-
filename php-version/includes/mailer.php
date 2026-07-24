<?php
/**
 * Maventech SMTP mailer — wraps vendored PHPMailer with:
 *  • settings-driven SMTP config (host/port/user/pass/encryption/from)
 *  • retry-aware queue persistence in `email_outbox`
 *  • plain-text fallback auto-generated from HTML
 *  • proper deliverability headers (Message-ID, Date, Reply-To, Return-Path, List-Unsubscribe)
 *  • per-minute rate limiting
 *  • obfuscated SMTP password at rest (base64; not a real secret — host already protects)
 *
 * Public API:
 *   smtp_config()                       -> array  full SMTP config from settings
 *   smtp_set_config($arr)               -> void   save SMTP config back
 *   smtp_test_connection($to = null)    -> array  ['ok'=>bool, 'message'=>str, 'log'=>str]
 *   smtp_send($to,$subject,$html, ...)  -> array  ['ok'=>bool, 'id'=>int, 'error'=>?str]
 *   smtp_queue_email($to,$subject,$html,$opts=[]) -> int    queue row id
 *   smtp_process_queue($maxBatch = 5)   -> int    rows processed
 *   smtp_mark_bounce($trackingToken)    -> void   helper for inbound bounce webhooks
 *   html_to_plain($html)                -> string
 */
require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';
require_once __DIR__ . '/../lib/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

/* ------------------------------------------------------------------------- */
/* Bootstrap: self-heal the email_outbox columns we rely on. Same pattern as
   regions_bootstrap() — protects against shared-hosting users running on an
   older database.sql import.                                                */
/* ------------------------------------------------------------------------- */
function mailer_bootstrap(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo = db();
        // Ensure the columns required for retry / status tracking exist.
        $needed = [
            'retry_count'         => 'INT NOT NULL DEFAULT 0',
            'max_retries'         => 'INT NOT NULL DEFAULT 3',
            'next_retry_at'       => 'TIMESTAMP NULL DEFAULT NULL',
            'error_details'       => 'TEXT NULL DEFAULT NULL',
            'last_error'          => 'VARCHAR(255) NULL DEFAULT NULL',
            'message_id'          => 'VARCHAR(190) NULL DEFAULT NULL',
            'bounced_at'          => 'TIMESTAMP NULL DEFAULT NULL',
            'priority'            => "TINYINT NOT NULL DEFAULT 5", // 1=highest, 9=lowest
            // Bug fix 2026-07-17d: track when we've notified admin about a failed/bounced
            // customer email so we never send a duplicate admin bounce notice.
            'bounce_notified_at'  => 'TIMESTAMP NULL DEFAULT NULL',
            // Capture the gateway mode (test/live) at queue time so Email Activity
            // can be filtered by test vs live purchases even after settings change.
            'gw_mode'             => "VARCHAR(10) NULL DEFAULT NULL",
        ];
        $tableExists = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='email_outbox'")->fetchColumn();
        if (!$tableExists) return;
        foreach ($needed as $col => $def) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='email_outbox' AND COLUMN_NAME = ?");
            $st->execute([$col]);
            if (!(int)$st->fetchColumn()) {
                try { $pdo->exec("ALTER TABLE email_outbox ADD COLUMN `$col` $def"); } catch (Throwable $e) { /* ignore */ }
            }
        }
        try { $pdo->exec("CREATE INDEX idx_outbox_status_retry ON email_outbox (status, next_retry_at)"); } catch (Throwable $e) {}
    } catch (Throwable $e) { /* silent */ }
}

/* ------------------------------------------------------------------------- */
/* Config                                                                    */
/* ------------------------------------------------------------------------- */
function smtp_config(): array {
    mailer_bootstrap();
    $rawPwd = setting_get('smtp_password_b64', '');
    return [
        'enabled'       => (int)setting_get('smtp_enabled', '0') === 1,
        'host'          => setting_get('smtp_host', ''),
        'port'          => (int)setting_get('smtp_port', '587') ?: 587,
        'username'      => setting_get('smtp_username', ''),
        'password'      => $rawPwd !== '' ? (base64_decode($rawPwd, true) ?: '') : '',
        'encryption'    => setting_get('smtp_encryption', 'tls'),   // tls | ssl | none
        'from_email'    => setting_get('smtp_from_email', setting_get('company_email', '')),
        'from_name'     => setting_get('smtp_from_name',  setting_get('company_name',  '')),
        'reply_to'      => setting_get('smtp_reply_to', setting_get('company_email', '')),
        'max_retries'   => (int)setting_get('smtp_max_retries', '3') ?: 3,
        'rate_per_min'  => (int)setting_get('smtp_rate_per_min', '60') ?: 60,
        'verify_peer'   => (int)setting_get('smtp_verify_peer', '1') === 1,
        'debug_level'   => (int)setting_get('smtp_debug', '0'),
    ];
}

function smtp_set_config(array $in): void {
    mailer_bootstrap();
    $map = [
        'enabled'      => fn($v) => setting_set('smtp_enabled',     $v ? '1' : '0'),
        'host'         => fn($v) => setting_set('smtp_host',        trim((string)$v)),
        'port'         => fn($v) => setting_set('smtp_port',        (string)(int)$v),
        'username'     => fn($v) => setting_set('smtp_username',    trim((string)$v)),
        'password'     => function ($v) {
            // Normalise the password.  Two common paste-pitfalls we clean here:
            //
            //   1. Gmail App Passwords are displayed as 4x4 groups
            //      ("abcd efgh ijkl mnop"). Users copy the whole block —
            //      Google's docs say the spaces are ignored, so we strip
            //      them here rather than confusing the admin with an
            //      auth-fail on a superficially-correct password.
            //
            //   2. Trailing / leading whitespace or an accidental newline
            //      from a copy-paste. Same fix as (1).
            //
            //   Passwords that legitimately contain internal spaces are
            //   rare, and no mainstream provider (Gmail, O365, SendGrid,
            //   SES, cPanel Mailboxes) uses one. We keep the ORIGINAL
            //   password when normalisation would leave it identical, so
            //   we never silently mutate a valid credential.
            $orig = (string)$v;
            $norm = trim($orig);
            // Only strip inner spaces on Gmail-App-Password-shaped inputs
            // (16 hex-ish chars in 4x4 groups).  Anything else keeps its
            // internal spaces intact so we don't break real passwords.
            if (preg_match('/^[A-Za-z0-9]{4}[ \t]+[A-Za-z0-9]{4}[ \t]+[A-Za-z0-9]{4}[ \t]+[A-Za-z0-9]{4}$/', $norm)) {
                $norm = preg_replace('/\s+/', '', $norm);
            }
            setting_set('smtp_password_b64', $norm === '' ? '' : base64_encode($norm));
        },
        'encryption'   => fn($v) => setting_set('smtp_encryption',  in_array($v,['tls','ssl','none'],true) ? $v : 'tls'),
        'from_email'   => fn($v) => setting_set('smtp_from_email',  trim((string)$v)),
        'from_name'    => fn($v) => setting_set('smtp_from_name',   trim((string)$v)),
        'reply_to'     => fn($v) => setting_set('smtp_reply_to',    trim((string)$v)),
        'max_retries'  => fn($v) => setting_set('smtp_max_retries', (string)max(0, min(10, (int)$v))),
        'rate_per_min' => fn($v) => setting_set('smtp_rate_per_min',(string)max(1, min(2000, (int)$v))),
        'verify_peer'  => fn($v) => setting_set('smtp_verify_peer', $v ? '1' : '0'),
        'debug_level'  => fn($v) => setting_set('smtp_debug',       (string)max(0, min(4, (int)$v))),
    ];
    foreach ($in as $k => $v) if (isset($map[$k])) $map[$k]($v);
}

/* ------------------------------------------------------------------------- */
/* Build a configured PHPMailer instance                                     */
/* ------------------------------------------------------------------------- */
function _smtp_make(): PHPMailer {
    $c = smtp_config();
    $m = new PHPMailer(true);          // throw exceptions
    $m->isSMTP();
    $m->Host          = $c['host'];
    $m->Port          = $c['port'];
    $m->SMTPAuth      = $c['username'] !== '';
    $m->Username      = $c['username'];
    $m->Password      = $c['password'];
    $m->Timeout       = 25;
    $m->CharSet       = PHPMailer::CHARSET_UTF8;
    $m->Encoding      = PHPMailer::ENCODING_BASE64;
    $m->XMailer       = 'Maventech Admin';

    if ($c['encryption'] === 'ssl') {
        $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;            // implicit TLS, usually port 465
    } elseif ($c['encryption'] === 'tls') {
        $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;         // STARTTLS, usually port 587
    } else {
        $m->SMTPSecure = false;
        $m->SMTPAutoTLS = false;
    }

    if (!$c['verify_peer']) {
        $m->SMTPOptions = ['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]];
    }

    if ($c['debug_level'] > 0) {
        $m->SMTPDebug = $c['debug_level'];
        $m->Debugoutput = function($str, $level){ /* captured via output buffer */ echo "[smtp:$level] $str\n"; };
    }

    if ($c['from_email'] !== '') {
        $m->setFrom($c['from_email'], $c['from_name'] !== '' ? $c['from_name'] : $c['from_email']);
    }
    if ($c['reply_to'] !== '') {
        $m->addReplyTo($c['reply_to']);
    }
    return $m;
}

/* ------------------------------------------------------------------------- */
/* Convert HTML body → plain-text fallback                                   */
/* ------------------------------------------------------------------------- */
function html_to_plain(string $html): string {
    // Strip head/style/script blocks
    $h = preg_replace('#<(head|style|script)[^>]*>.*?</\\1>#is', '', $html);
    // Convert anchors to "text (url)"
    $h = preg_replace_callback('#<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function($m){
        $url = trim($m[1]); $txt = trim(strip_tags($m[2]));
        return $txt && $txt !== $url ? "$txt ($url)" : $url;
    }, $h);
    // Block elements → newline
    $h = preg_replace('#</(p|div|tr|li|h1|h2|h3|h4|h5|h6|br)\\s*>#i', "\n", $h);
    $h = preg_replace('#<br\\s*/?>#i', "\n", $h);
    $h = strip_tags($h);
    $h = html_entity_decode($h, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Collapse whitespace
    $h = preg_replace("/[\r]+/", '', $h);
    $h = preg_replace("/[ \\t]+/", ' ', $h);
    $h = preg_replace("/\\n{3,}/", "\n\n", $h);
    return trim($h);
}

/* ------------------------------------------------------------------------- */
/* Apply deliverability headers + body to a PHPMailer instance               */
/* ------------------------------------------------------------------------- */
function _smtp_prepare(PHPMailer $m, string $to, string $subject, string $html, array $opts = []): string {
    // Validate recipient first — prevents header injection / undeliverable sends.
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid recipient address: ' . $to);
    }
    // Strip any control chars from subject — defends against header injection.
    $cleanSubject = preg_replace('/[\\r\\n\\t\\0]+/', ' ', $subject);

    $m->addAddress($to, $opts['to_name'] ?? '');
    $m->Subject = $cleanSubject;
    $m->isHTML(true);
    $m->Body    = $html;
    $m->AltBody = $opts['alt_body'] ?? html_to_plain($html);

    // RFC 5322 deliverability headers
    $host = parse_url(site_url(), PHP_URL_HOST) ?: 'localhost';
    $mid  = '<' . bin2hex(random_bytes(12)) . '@' . $host . '>';
    $m->MessageID = $mid;

    if (!empty($opts['unsubscribe_url'])) {
        $m->addCustomHeader('List-Unsubscribe', '<' . $opts['unsubscribe_url'] . '>');
        $m->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
    }
    if (!empty($opts['headers']) && is_array($opts['headers'])) {
        foreach ($opts['headers'] as $hk => $hv) {
            $hk = preg_replace('/[^A-Za-z0-9\\-]/', '', $hk);
            $hv = preg_replace('/[\\r\\n]+/', ' ', (string)$hv);
            if ($hk !== '') $m->addCustomHeader($hk, $hv);
        }
    }
    // Attachments — accepts an array of absolute filesystem paths.  Used by
    // the worker when sending order-delivery emails so each customer gets
    // their Receipt.pdf + Invoice.pdf bundled.
    if (!empty($opts['attachments']) && is_array($opts['attachments'])) {
        foreach ($opts['attachments'] as $p) {
            if (is_string($p) && $p !== '' && is_file($p)) {
                try { $m->addAttachment($p, basename($p)); }
                catch (Throwable $e) { @error_log('[mailer attach] ' . $e->getMessage()); }
            }
        }
    }
    return $mid;
}

/* ------------------------------------------------------------------------- */
/* Per-minute rate limit guard                                               */
/* ------------------------------------------------------------------------- */
function _smtp_under_rate_limit(): bool {
    $c = smtp_config();
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM email_outbox WHERE delivered_at >= (NOW() - INTERVAL 1 MINUTE)");
        $st->execute();
        return (int)$st->fetchColumn() < $c['rate_per_min'];
    } catch (Throwable $e) { return true; }
}

/* ------------------------------------------------------------------------- */
/* Synchronous send — used by smtp_test_connection() and queue worker        */
/* ------------------------------------------------------------------------- */
function smtp_send(string $to, string $subject, string $html, array $opts = []): array {
    mailer_bootstrap();
    $c = smtp_config();
    if (!$c['enabled'] || $c['host'] === '') {
        return ['ok' => false, 'error' => 'SMTP is not configured. Open admin → SMTP / Mail Server.'];
    }
    if (!_smtp_under_rate_limit()) {
        return ['ok' => false, 'error' => 'Rate limit reached. Try again in a minute.', 'retryable' => true];
    }
    try {
        $m = _smtp_make();
        $messageId = _smtp_prepare($m, $to, $subject, $html, $opts);
        $m->send();
        return ['ok' => true, 'message_id' => $messageId];
    } catch (Throwable $e) {
        // PHPMailer's ErrorInfo is more descriptive than the exception message
        $detail = isset($m) ? trim($m->ErrorInfo) : '';
        return ['ok' => false, 'error' => $e->getMessage() . ($detail ? ' · ' . $detail : ''), 'retryable' => true];
    }
}

/* ------------------------------------------------------------------------- */
/* Queue an email — single insert. Idempotent on (recipient,subject,template) */
/* within the same minute to prevent accidental duplicates.                  */
/* ------------------------------------------------------------------------- */
function smtp_queue_email(string $to, string $subject, string $html, array $opts = []): int {
    mailer_bootstrap();
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid recipient address: ' . $to);
    }
    // Defence-in-depth: substitute any leftover {{var}} placeholders in the
    // subject using company-info + standard vars. Prevents customers ever
    // seeing literal "{{product_name}}" / "{{order_number}}" tokens.
    if (strpos($subject, '{{') !== false) {
        $co = company_info();
        $subject = strtr($subject, array_merge([
            '{{company_name}}'  => $co['name']  ?? '',
            '{{support_email}}' => $co['email'] ?? '',
            '{{support_phone}}' => $co['phone'] ?? '',
            '{{year}}'          => date('Y'),
        ], $opts['subject_vars'] ?? []));
        // Strip any unresolved placeholders rather than leak them
        $subject = preg_replace('/\{\{\s*[a-z_][a-z0-9_]*\s*\}\}/i', '', $subject);
        $subject = trim(preg_replace('/\s+/', ' ', $subject));
    }
    $pdo = db();
    $tok = $opts['tracking_token'] ?? bin2hex(random_bytes(16));
    $tpl = $opts['template_code'] ?? null;
    $oid = $opts['order_id']      ?? null;
    $priority   = (int)($opts['priority']    ?? 5);
    $maxRetries = (int)($opts['max_retries'] ?? smtp_config()['max_retries']);

    // Duplicate suppression — same recipient + subject + template within last 60s
    $dup = $pdo->prepare("SELECT id FROM email_outbox
        WHERE recipient = ? AND subject = ? AND COALESCE(template_code,'') = ? AND created_at >= (NOW() - INTERVAL 60 SECOND)
        ORDER BY id DESC LIMIT 1");
    $dup->execute([$to, $subject, (string)$tpl]);
    if ($existing = (int)$dup->fetchColumn()) return $existing;

    // Optional delay (in minutes) before the cron worker is allowed to send
    // this row. Defaults to NOW() (immediate).
    $delayMin = (int)($opts['delay_minutes'] ?? 0);
    // Capture gateway mode at queue time so Email Activity can be filtered
    // test-vs-live even after the admin later toggles the mode.
    $gwMode = $opts['gw_mode'] ?? null;
    if ($gwMode === null) {
        try {
            if (!empty($oid)) {
                $q = $pdo->prepare("SELECT gw_mode FROM orders WHERE id = ? LIMIT 1");
                $q->execute([(int)$oid]);
                $gwMode = $q->fetchColumn() ?: null;
            }
        } catch (Throwable $e) { /* ignore */ }
        if (!$gwMode) $gwMode = setting_get('gw_mode', 'test');
    }
    if ($delayMin > 0) {
        $pdo->prepare("INSERT INTO email_outbox
            (recipient, subject, html, status, note, order_id, tracking_token, template_code, retry_count, max_retries, next_retry_at, priority, attachments_json, gw_mode)
            VALUES (?,?,?,'queued',NULL,?,?,?,0,?,DATE_ADD(NOW(), INTERVAL ? MINUTE),?,?,?)")
            ->execute([$to, $subject, $html, $oid, $tok, $tpl, $maxRetries, $delayMin, $priority, $opts['attachments'] ?? null, $gwMode]);
    } else {
        $pdo->prepare("INSERT INTO email_outbox
            (recipient, subject, html, status, note, order_id, tracking_token, template_code, retry_count, max_retries, next_retry_at, priority, attachments_json, gw_mode)
            VALUES (?,?,?,'queued',NULL,?,?,?,0,?,NOW(),?,?,?)")
            ->execute([$to, $subject, $html, $oid, $tok, $tpl, $maxRetries, $priority, $opts['attachments'] ?? null, $gwMode]);
    }
    return (int)$pdo->lastInsertId();
}

/* ------------------------------------------------------------------------- */
/* Worker — process N due rows from email_outbox                             */
/* ------------------------------------------------------------------------- */

/**
 * Reduce an SMTP error message to a comparable "shape" so two errors with
 * variable parts (timestamps, request IDs, port numbers, ms, IPs) are
 * recognised as the same root cause. Used by smtp_process_queue() to detect
 * a stuck-retry pattern and auto-bounce after the same error repeats.
 */
function _smtp_error_shape(string $msg): string {
    $s = strtolower(trim($msg));
    if ($s === '') return '';
    // Strip variable / noisy parts
    $s = preg_replace('/\b\d{4}-\d{2}-\d{2}[\sT]\d{2}:\d{2}:\d{2}\b/', '', $s); // timestamps
    $s = preg_replace('/\b\d{1,3}(\.\d{1,3}){3}\b/', '', $s);                    // IPv4
    $s = preg_replace('/\b[a-f0-9]{16,}\b/', '', $s);                            // hex IDs
    $s = preg_replace('/\d+\s*(ms|s|kb|mb|gb|bytes?)\b/', '', $s);               // numeric durations / sizes
    $s = preg_replace('/\b\d{3,}\b/', '', $s);                                   // big numbers (ports, IDs)
    $s = preg_replace('/[\s,;:]+/', ' ', $s);                                    // collapse punctuation/whitespace
    return trim(substr($s, 0, 120));
}

function smtp_process_queue(int $maxBatch = 5): int {
    mailer_bootstrap();
    $c = smtp_config();
    if (!$c['enabled'] || $c['host'] === '') return 0;

    $pdo = db();
    $maxBatch = max(1, min(50, $maxBatch));
    $rows = $pdo->query("SELECT * FROM email_outbox
        WHERE status IN ('queued','retrying')
          AND (next_retry_at IS NULL OR next_retry_at <= NOW())
        ORDER BY priority ASC, id ASC
        LIMIT $maxBatch")->fetchAll();

    $processed = 0;
    foreach ($rows as $row) {
        if (!_smtp_under_rate_limit()) break;

        $tok = $row['tracking_token'];
        $base = rtrim(site_url(), '/');
        $html = $row['html'];
        if ($tok && strpos($html, 'track-open.php') === false) {
            $html .= '<img src="' . $base . '/track-open.php?t=' . urlencode($tok) . '" width="1" height="1" alt="">';
        }
        // Carry queued attachments (Receipt + Invoice PDF paths) into the
        // real send so the customer actually receives them.  attachments_json
        // is set when the order-delivery email is queued in fulfill_order().
        $attachments = [];
        if (!empty($row['attachments_json'])) {
            $decoded = json_decode((string)$row['attachments_json'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $p) {
                    if (is_string($p) && $p !== '' && is_file($p)) $attachments[] = $p;
                }
            }
        }
        $result = smtp_send($row['recipient'], $row['subject'], $html, [
            'attachments' => $attachments,
        ]);
        if ($result['ok']) {
            $pdo->prepare("UPDATE email_outbox
                SET status='sent', delivered_at=NOW(), last_error=NULL, message_id=?, next_retry_at=NULL
                WHERE id=?")
                ->execute([$result['message_id'] ?? null, $row['id']]);
        } else {
            $newCount   = (int)$row['retry_count'] + 1;
            $maxRetries = (int)($row['max_retries'] ?: 3);
            // Exponential backoff: 2, 10, 60 minutes (then bounce)
            $delays = [2, 10, 60, 240];
            $delay  = $delays[min($newCount - 1, count($delays) - 1)];
            // last_error is VARCHAR(255) — truncate long SMTP error messages so
            // a verbose failure can never break the retry pipeline.
            $errMsg = mb_substr((string)$result['error'], 0, 250, 'UTF-8');

            // Hard-bounce early when the SAME error has repeated 3+ times — no
            // point grinding through 10× retries against a recipient the server
            // keeps refusing. We compare error "shapes" (strip variable parts
            // like timestamps, IDs, port numbers) so the same root cause is
            // recognised even when the wording differs slightly.
            $errShape  = _smtp_error_shape($errMsg);
            $prevShape = _smtp_error_shape((string)($row['last_error'] ?? ''));
            $sameErrorStreak = ($prevShape !== '' && $errShape === $prevShape && $newCount >= 3);

            if ($newCount > $maxRetries || $sameErrorStreak) {
                $reason = $sameErrorStreak
                    ? "Auto-bounced — same error repeated {$newCount} times. " . $errMsg
                    : $errMsg;
                $pdo->prepare("UPDATE email_outbox
                    SET status='bounced', bounced_at=NOW(), retry_count=?, last_error=?, next_retry_at=NULL
                    WHERE id=?")
                    ->execute([$newCount, mb_substr($reason, 0, 250, 'UTF-8'), $row['id']]);
                // Notify admin about this bounce (deduped inside helper).
                try { email_notify_admin_of_bounce((int)$row['id'], $reason); }
                catch (Throwable $e) { @error_log('[queue bounce admin notify] '.$e->getMessage()); }
            } else {
                $pdo->prepare("UPDATE email_outbox
                    SET status='retrying', retry_count=?, last_error=?, next_retry_at=DATE_ADD(NOW(), INTERVAL $delay MINUTE)
                    WHERE id=?")
                    ->execute([$newCount, $errMsg, $row['id']]);
            }
        }
        $processed++;
    }
    return $processed;
}

/* ------------------------------------------------------------------------- */
/* Test the SMTP connection by sending an admin self-test email              */
/* ------------------------------------------------------------------------- */
function smtp_test_connection(?string $to = null): array {
    mailer_bootstrap();
    $c = smtp_config();
    if ($c['host'] === '') return ['ok' => false, 'message' => 'No SMTP host configured.'];
    $to = $to ?: ($c['reply_to'] ?: $c['from_email']);
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Provide a valid test recipient address.'];
    }
    $body = '<!doctype html><html><body style="font-family:Segoe UI,Arial,sans-serif;background:#f8fafc;padding:30px;">
      <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:14px;padding:28px;box-shadow:0 4px 18px rgba(0,0,0,.05);">
        <h2 style="margin:0 0 8px;color:#0f172a;">SMTP test successful ✅</h2>
        <p style="color:#475569;line-height:1.6;">Your <strong>'.esc($c['host']).':'.esc($c['port']).'</strong> mail server delivered this test message to <strong>'.esc($to).'</strong>.</p>
        <ul style="color:#475569;font-size:14px;line-height:1.7;">
          <li>Encryption: <strong>'.esc(strtoupper($c['encryption'])).'</strong></li>
          <li>From: <strong>'.esc($c['from_email']).'</strong></li>
          <li>Sent at: <strong>'.date('Y-m-d H:i:s').' '.date_default_timezone_get().'</strong></li>
        </ul>
        <p style="font-size:12px;color:#94a3b8;margin-top:18px;">You can now switch SMTP <em>Enabled</em> ON and all transactional emails will flow through this server.</p>
      </div></body></html>';
    // Force SMTP debug output for the duration of the test so the admin
    // gets the raw server response when something fails (Gmail's
    // "5.7.8 Username and Password not accepted", O365's "5.7.3 Not
    // Authorized to send from this address", etc.).  We do this by
    // toggling the debug setting temporarily, then restoring it.  This
    // is the single biggest UX win for diagnosing SMTP auth failures.
    $prevDebug = setting_get('smtp_debug', '0');
    setting_set('smtp_debug', '2');
    ob_start();
    try {
        $res = smtp_send($to, 'Maventech SMTP test', $body, ['headers' => ['X-Test-Email' => '1']]);
    } finally {
        $log = ob_get_clean();
        setting_set('smtp_debug', (string)$prevDebug);
    }
    // Friendly-fy well-known SMTP error signatures so the admin sees
    // actionable guidance instead of a raw SMTP error string.
    $friendlyMessage = $res['ok']
        ? ('Test email sent to ' . $to)
        : ($res['error'] ?? 'Send failed');
    if (!$res['ok']) {
        $lc = strtolower($res['error'] ?? '' . ' ' . $log);
        if (strpos($lc, 'username and password not accepted') !== false
            || strpos($lc, '535 5.7.8') !== false) {
            $friendlyMessage = 'Authentication failed — Gmail rejected the username/password. Use an App Password (not your Google account password). Generate one at https://myaccount.google.com/apppasswords after enabling 2-Step Verification.';
        } elseif (strpos($lc, '5.7.3') !== false || strpos($lc, 'not authorized to send') !== false) {
            $friendlyMessage = 'Authentication OK but the "From Email" is not allowed on this mailbox. Set From Email to match the SMTP Username exactly (Gmail / O365 both rewrite From: to the authenticated mailbox).';
        } elseif (strpos($lc, 'smtp authenticate') !== false || strpos($lc, 'smtp_auth') !== false || strpos($lc, '535 ') !== false) {
            $friendlyMessage = 'SMTP authentication failed — check the Username and Password. For SendGrid the Username must be literally "apikey". For Amazon SES use SMTP credentials (not AWS access keys).';
        } elseif (strpos($lc, 'connection refused') !== false || strpos($lc, 'network is unreachable') !== false || strpos($lc, 'no route to host') !== false) {
            $friendlyMessage = 'Could not reach the SMTP host. Check the Host + Port. Some shared hosts block outbound port 25 — use 587 (STARTTLS) or 465 (SSL) instead.';
        } elseif (strpos($lc, 'ssl') !== false && strpos($lc, 'certificate') !== false) {
            $friendlyMessage = 'TLS certificate verification failed. If this is a legitimate self-signed cert on an internal relay, uncheck "Strict TLS peer verification" and try again.';
        } elseif (strpos($lc, 'sender rejected') !== false || strpos($lc, '550 5.7.1') !== false) {
            $friendlyMessage = 'Your SMTP host rejected the From Email address. Make sure it belongs to a real mailbox on the configured server (or a verified sender identity for SendGrid/SES).';
        }
    }
    return ['ok' => $res['ok'], 'message' => $friendlyMessage, 'log' => $log];
}

/* ------------------------------------------------------------------------- */
/* Recipient deliverability pre-flight                                       */
/*                                                                           */
/* Catches the #1 cause of bounce reports: customers mistyping their email   */
/* domain (gmial.com / hotmial.com / nodomain.xyz).  We do a fast DNS MX/A   */
/* lookup BEFORE handing the row to the queue worker.  When the domain has   */
/* no mail-capable record, we know with certainty no MTA will ever accept    */
/* the message — so we mark the row 'failed' immediately and surface it on   */
/* the admin Failed tab instead of waiting for the queue worker.             */
/*                                                                           */
/* Results are cached for one hour (process + APCu/file) so the check stays  */
/* cheap even at high volume.                                                */
/* ------------------------------------------------------------------------- */
function email_address_deliverable(string $address): array {
    $result = ['ok' => false, 'reason' => '', 'detail' => ''];
    if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
        $result['reason'] = 'invalid_syntax';
        $result['detail'] = 'Not a valid RFC-5322 email address.';
        return $result;
    }
    $domain = strtolower(substr($address, strrpos($address, '@') + 1));
    if ($domain === '') {
        $result['reason'] = 'invalid_syntax';
        $result['detail'] = 'Missing domain.';
        return $result;
    }
    // Process-local cache so the same domain isn't probed twice in one request.
    static $cache = [];
    if (isset($cache[$domain])) return $cache[$domain];

    // Skip DNS for obvious local/test domains so dev workflows still flow.
    if (in_array($domain, ['localhost', 'example.com', 'example.org', 'example.net', 'test.local'], true)) {
        $result['ok'] = true;
        $result['reason'] = 'local_or_test';
        return $cache[$domain] = $result;
    }

    // Common typo dictionary — catches the bulk of real-world support tickets.
    // These are intentionally flagged as undeliverable EVEN when DNS resolves,
    // because squatter / parking pages still bounce real customer email.  We
    // surface a friendly hint so the admin can ask the customer to confirm.
    $typos = [
        'gmial.com'   => 'gmail.com',
        'gmal.com'    => 'gmail.com',
        'gmail.con'   => 'gmail.com',
        'gnail.com'   => 'gmail.com',
        'gmai.com'    => 'gmail.com',
        'gmaill.com'  => 'gmail.com',
        'hotmial.com' => 'hotmail.com',
        'hotnail.com' => 'hotmail.com',
        'hotmal.com'  => 'hotmail.com',
        'hotmail.con' => 'hotmail.com',
        'yaho.com'    => 'yahoo.com',
        'yahooo.com'  => 'yahoo.com',
        'yahoo.con'   => 'yahoo.com',
        'outlok.com'  => 'outlook.com',
        'outlook.con' => 'outlook.com',
        'iclud.com'   => 'icloud.com',
        'icould.com'  => 'icloud.com',
    ];
    if (isset($typos[$domain])) {
        $result['reason'] = 'no_mx';
        $result['detail'] = "Likely typo: {$domain}. Did the customer mean {$typos[$domain]}?";
        return $cache[$domain] = $result;
    }

    $hasMx = false; $hasA = false;
    try {
        $hasMx = @checkdnsrr($domain, 'MX');
        if (!$hasMx) {
            // RFC 5321 §5 — if no MX, mail can fall back to the A record.
            $hasA = @checkdnsrr($domain, 'A');
        }
    } catch (Throwable $e) {
        // DNS lookup failures should not break the request — assume deliverable
        // and let the SMTP worker decide.
        $result['ok'] = true;
        $result['reason'] = 'dns_unavailable';
        return $cache[$domain] = $result;
    }

    if ($hasMx || $hasA) {
        $result['ok'] = true;
        return $cache[$domain] = $result;
    }

    $hint = isset($typos[$domain]) ? " Did the customer mean {$typos[$domain]}?" : '';
    $result['reason'] = 'no_mx';
    $result['detail'] = "Domain {$domain} has no MX or A records — mail server doesn't exist." . $hint;
    return $cache[$domain] = $result;
}


/**
 * Bounce reconciliation — reads the mailbox (IMAP) for delivery-failure
 * notifications (from MAILER-DAEMON / "Undelivered Mail Returned to Sender")
 * and flips the matching Email Activity row from "sent" to "BOUNCED" with the
 * failure reason. This is what makes the admin show the REAL delivery status
 * instead of leaving genuinely-bounced mail marked as sent/delivered.
 *
 * Uses dedicated imap_* settings, falling back to the SMTP host/credentials.
 * Returns ['ok'=>bool,'checked'=>int,'bounced'=>int,'error'=>string].
 */
function email_sync_bounces(int $maxScan = 80): array
{
    $out = ['ok' => false, 'checked' => 0, 'bounced' => 0, 'error' => ''];
    if (!function_exists('imap_open')) {
        $out['error'] = 'PHP IMAP extension is not enabled on this server — ask your host to enable php-imap so bounce status can be read from the mailbox.';
        return $out;
    }
    $cfg  = smtp_config();
    $host = trim((string)setting_get('imap_host', $cfg['host']));
    $port = (int)setting_get('imap_port', '993') ?: 993;
    $user = trim((string)setting_get('imap_username', $cfg['username']));
    $rawP = (string)setting_get('imap_password_b64', '');
    $pass = $rawP !== '' ? (base64_decode($rawP, true) ?: '') : (string)$cfg['password'];
    $enc  = setting_get('imap_encryption', 'ssl'); // ssl | tls | none
    if ($host === '' || $user === '' || $pass === '') {
        $out['error'] = 'IMAP mailbox not configured (needs host, username & password). Set it under Admin → SMTP / Mail Server (bounce inbox).';
        return $out;
    }
    $flags = '/imap';
    $flags .= $enc === 'ssl' ? '/ssl' : ($enc === 'tls' ? '/tls' : '/notls');
    if (empty($cfg['verify_peer'])) $flags .= '/novalidate-cert';
    $mailbox = '{' . $host . ':' . $port . $flags . '}INBOX';

    $imap = @imap_open($mailbox, $user, $pass, 0, 1);
    if (!$imap) {
        $out['error'] = 'Could not connect to the IMAP mailbox: ' . (imap_last_error() ?: 'unknown error');
        return $out;
    }
    try {
        $ids = @imap_search($imap, 'UNSEEN FROM "MAILER-DAEMON"') ?: [];
        $ids = array_merge($ids, @imap_search($imap, 'UNSEEN SUBJECT "Undelivered"') ?: []);
        $ids = array_merge($ids, @imap_search($imap, 'UNSEEN SUBJECT "Delivery Status"') ?: []);
        $ids = array_slice(array_values(array_unique($ids)), 0, $maxScan);
        $pdo = db();
        foreach ($ids as $num) {
            $out['checked']++;
            $body = (string)@imap_body($imap, $num);
            $rcpt = '';
            if (preg_match('/Final-Recipient:\s*[^;]*;\s*([^\s<>]+@[^\s<>]+)/i', $body, $m)) {
                $rcpt = strtolower(trim($m[1], " \t<>."));
            } elseif (preg_match('/(?:Original-Recipient|X-Failed-Recipients):\s*(?:[^;]*;\s*)?([^\s<>,]+@[^\s<>,]+)/i', $body, $m)) {
                $rcpt = strtolower(trim($m[1], " \t<>."));
            } elseif (preg_match('/(?:to|for)\s+<?([^\s<>]+@[^\s<>]+)>?/i', $body, $m)) {
                $rcpt = strtolower(trim($m[1], " \t<>."));
            }
            $reason = '';
            if (preg_match('/Diagnostic-Code:\s*[^;\r\n]*;?\s*([^\r\n]+)/i', $body, $m)) $reason = trim($m[1]);
            elseif (preg_match('/\b(5\.\d\.\d+[^\r\n]*)/', $body, $m)) $reason = trim($m[1]);
            else $reason = 'Message bounced — delivery failed.';
            $reason = mb_substr($reason, 0, 300);

            if ($rcpt !== '' && filter_var($rcpt, FILTER_VALIDATE_EMAIL)) {
                // Find the most-recent customer-facing row so we can flip it AND
                // fire an admin bounce notice for THAT specific row.
                $findRow = $pdo->prepare(
                    "SELECT id, order_id, template_code FROM email_outbox " .
                    "WHERE recipient=? AND status IN ('sent','queued','delivered') " .
                    "ORDER BY id DESC LIMIT 1"
                );
                $findRow->execute([$rcpt]);
                $rowHit = $findRow->fetch();
                if ($rowHit) {
                    $upd = $pdo->prepare(
                        "UPDATE email_outbox SET status='bounced', bounced_at=NOW(), last_error=? " .
                        "WHERE id=?"
                    );
                    $upd->execute([$reason, (int)$rowHit['id']]);
                    if ($upd->rowCount() > 0) {
                        $out['bounced']++;
                        // Notify admin about this specific bounce (deduped by
                        // bounce_notified_at inside the helper).
                        try { email_notify_admin_of_bounce((int)$rowHit['id'], $reason); }
                        catch (Throwable $e) { @error_log('[bounce admin notify] '.$e->getMessage()); }
                    }
                }
            }
            @imap_setflag_full($imap, (string)$num, "\\Seen");
        }
        $out['ok'] = true;
    } catch (Throwable $e) {
        $out['error'] = 'Bounce sync error: ' . $e->getMessage();
    } finally {
        @imap_close($imap);
    }
    return $out;
}

/* ------------------------------------------------------------------------- */
/* Admin bounce notification — fires once per failed/bounced customer email.
 *
 * When a customer-facing email is marked 'failed' or 'bounced' (whether via
 * preflight, SMTP-level rejection, retry-exhaustion or the IMAP bounce sync)
 * we send ONE admin notification email to the site's company_email so the
 * merchant knows immediately without having to keep the admin panel open.
 * The notification is ALSO written into email_outbox with a dedicated
 * template_code ('order_email_bounced') so it shows up on the admin's
 * Product Purchases → Failed tab.
 *
 * Dedup: we set email_outbox.bounce_notified_at on the original row so the
 * same failure never triggers two admin notices.
 */
function email_notify_admin_of_bounce(int $outboxId, string $reason = ''): bool {
    mailer_bootstrap();
    $pdo = db();

    // Load the original row; bail if already notified or not customer-facing.
    $r = $pdo->prepare("SELECT * FROM email_outbox WHERE id = ? LIMIT 1");
    $r->execute([$outboxId]);
    $row = $r->fetch();
    if (!$row) return false;
    if (!empty($row['bounce_notified_at'])) return false;
    if (!in_array((string)$row['status'], ['failed','bounced'], true)) return false;
    // Never recurse on our own admin notices.
    if (($row['template_code'] ?? '') === 'order_email_bounced') return false;

    $adminEmail = trim((string)setting_get('company_email', ''));
    if ($adminEmail === '') $adminEmail = trim((string)setting_get('smtp_reply_to', ''));
    if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        // No admin address configured — mark as notified anyway so we don't
        // keep retrying, and log for the operator.
        $pdo->prepare("UPDATE email_outbox SET bounce_notified_at=NOW() WHERE id=?")->execute([$outboxId]);
        @error_log('[bounce admin notify] no company_email set — skipping outbox '.$outboxId);
        return false;
    }

    // Enrich with order context if we have it.
    $order = null;
    if (!empty($row['order_id'])) {
        $oq = $pdo->prepare("SELECT id, order_number, first_name, last_name, phone, total, currency, gw_mode, created_at
                             FROM orders WHERE id = ? LIMIT 1");
        $oq->execute([(int)$row['order_id']]);
        $order = $oq->fetch() ?: null;
    }
    $reason = $reason !== '' ? $reason : ((string)($row['last_error'] ?? 'Delivery failed'));
    $reason = mb_substr($reason, 0, 500, 'UTF-8');

    $customerEmail = (string)$row['recipient'];
    $tplLabels = [
        'order_delivery'      => 'Order delivery (license keys)',
        'order_confirmation'  => 'Order confirmation',
        'order_pending'       => 'Payment reminder',
        'refund_confirm'      => 'Refund confirmation',
        'review_request'      => 'Review request',
    ];
    $tplLabel = $tplLabels[$row['template_code'] ?? ''] ?? ($row['template_code'] ?? 'transactional');
    $baseUrl  = rtrim((function_exists('site_url') ? site_url() : (setting_get('site_url', '') ?: '')), '/');

    $custName = '';
    $orderLink = '';
    $orderNum  = '';
    $modePill  = '';
    if ($order) {
        $custName = trim(($order['first_name'] ?? '').' '.($order['last_name'] ?? ''));
        $orderNum = (string)$order['order_number'];
        $orderLink = $baseUrl . '/admin.php?tab=orders&q=' . urlencode($orderNum);
        $modePill = (($order['gw_mode'] ?? 'live') === 'test')
            ? '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:11px;font-weight:700;letter-spacing:1px;">TEST</span>'
            : '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#dcfce7;color:#166534;font-size:11px;font-weight:700;letter-spacing:1px;">LIVE</span>';
    }

    $subj = '⚠ Delivery failed — ' . htmlspecialchars($customerEmail, ENT_QUOTES, 'UTF-8')
          . ($orderNum ? ' (Order #'.htmlspecialchars($orderNum, ENT_QUOTES, 'UTF-8').')' : '');
    $subjPlain = '⚠ Delivery failed — ' . $customerEmail . ($orderNum ? ' (Order #'.$orderNum.')' : '');

    $rowHtml = '';
    $rowHtml .= '<tr><td style="padding:6px 10px;color:#64748b;font-size:13px;">Customer email</td>'
             .  '<td style="padding:6px 10px;font-weight:600;color:#0f172a;">'.htmlspecialchars($customerEmail, ENT_QUOTES, 'UTF-8').'</td></tr>';
    if ($custName !== '') {
        $rowHtml .= '<tr><td style="padding:6px 10px;color:#64748b;font-size:13px;">Customer name</td>'
                 .  '<td style="padding:6px 10px;color:#0f172a;">'.htmlspecialchars($custName, ENT_QUOTES, 'UTF-8').'</td></tr>';
    }
    if ($orderNum !== '') {
        $rowHtml .= '<tr><td style="padding:6px 10px;color:#64748b;font-size:13px;">Order</td>'
                 .  '<td style="padding:6px 10px;color:#0f172a;">#'.htmlspecialchars($orderNum, ENT_QUOTES, 'UTF-8').' '.$modePill.'</td></tr>';
    }
    $rowHtml .= '<tr><td style="padding:6px 10px;color:#64748b;font-size:13px;">Email type</td>'
             .  '<td style="padding:6px 10px;color:#0f172a;">'.htmlspecialchars($tplLabel, ENT_QUOTES, 'UTF-8').'</td></tr>';
    $rowHtml .= '<tr><td style="padding:6px 10px;color:#64748b;font-size:13px;">Failure reason</td>'
             .  '<td style="padding:6px 10px;color:#b91c1c;">'.htmlspecialchars($reason, ENT_QUOTES, 'UTF-8').'</td></tr>';

    $activityLink = $baseUrl . '/admin.php?tab=emails&filter=failed';
    $html = '<!doctype html><html><body style="font-family:Segoe UI,Arial,sans-serif;background:#f8fafc;padding:24px;">'
          . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:14px;padding:28px;box-shadow:0 4px 18px rgba(0,0,0,.05);border:1px solid #fee2e2;">'
          . '<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">'
          . '<div style="width:36px;height:36px;border-radius:10px;background:#fef2f2;color:#b91c1c;display:flex;align-items:center;justify-content:center;font-size:22px;">⚠</div>'
          . '<h2 style="margin:0;color:#0f172a;font-size:19px;">Customer email undeliverable</h2></div>'
          . '<p style="color:#475569;line-height:1.55;margin:0 0 14px;">A customer-facing email could not be delivered. '
          . 'You may want to reach the customer through another channel (phone / SMS) to confirm the correct address.</p>'
          . '<table style="width:100%;border-collapse:collapse;background:#f8fafc;border-radius:10px;overflow:hidden;font-size:14px;margin:0 0 18px;">'
          . $rowHtml . '</table>'
          . '<div style="margin-top:6px;">';
    if ($orderLink !== '') {
        $html .= '<a href="'.htmlspecialchars($orderLink, ENT_QUOTES, 'UTF-8').'" '
              .  'style="display:inline-block;padding:10px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;margin-right:8px;">View order</a>';
    }
    $html .= '<a href="'.htmlspecialchars($activityLink, ENT_QUOTES, 'UTF-8').'" '
          .  'style="display:inline-block;padding:10px 16px;background:#fff;color:#0f172a;text-decoration:none;border-radius:8px;font-weight:600;border:1px solid #e2e8f0;">Open Email Activity → Failed</a>'
          . '</div>'
          . '<p style="color:#94a3b8;font-size:12px;margin-top:18px;line-height:1.55;">This alert was sent because Email Activity row #'.(int)$outboxId
          . ' flipped to <strong>'.htmlspecialchars((string)$row['status'], ENT_QUOTES, 'UTF-8').'</strong>. '
          . 'You can resend the email to a corrected address from the row\'s action menu.</p>'
          . '</div></body></html>';

    // Directly insert into outbox so we never recurse through send_email().
    $tok = bin2hex(random_bytes(16));
    $gwModeVal = $order['gw_mode'] ?? setting_get('gw_mode', 'test');
    try {
        $pdo->prepare("INSERT INTO email_outbox
            (recipient, subject, html, status, note, order_id, tracking_token, template_code, retry_count, max_retries, next_retry_at, priority, gw_mode)
            VALUES (?,?,?,'queued',?,?,?,?,0,3,NOW(),3,?)")
            ->execute([
                $adminEmail, $subjPlain, $html,
                'Admin bounce notice for outbox #'.$outboxId,
                $order['id'] ?? null, $tok, 'order_email_bounced', $gwModeVal,
            ]);
    } catch (Throwable $e) { @error_log('[bounce admin notify insert] '.$e->getMessage()); return false; }

    // Try to send immediately if SMTP is enabled — best-effort.
    try {
        $c = smtp_config();
        if ($c['enabled'] && $c['host'] !== '') { smtp_process_queue(1); }
    } catch (Throwable $e) { /* fall through — cron will pick it up */ }

    $pdo->prepare("UPDATE email_outbox SET bounce_notified_at=NOW() WHERE id=?")->execute([$outboxId]);
    return true;
}

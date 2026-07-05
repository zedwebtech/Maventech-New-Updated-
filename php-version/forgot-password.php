<?php
/**
 * Forgot Password — Passcode flow.
 *
 * Three steps on a single page (state = session):
 *   1) send   — Admin clicks "Send passcode". A 6-digit numeric code is
 *               generated, SHA-256 hashed into `password_resets` (15-min TTL)
 *               and emailed to the registered company email address
 *               (default: advisoryservice@avintexsoftware.com).
 *   2) verify — Admin enters the 6-digit passcode. On match the reset row's
 *               id is stored in `$_SESSION['pwreset_verified_id']` and the
 *               user is redirected to `reset-password.php`.
 *   3) reset  — Handled by reset-password.php (new password + confirm).
 *
 * Anti-abuse: max 5 wrong passcode attempts before the code is burned and
 * a fresh one must be requested.  A short cooldown prevents mail spamming.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email.php';
ensure_admin();

$pageTitle = 'Forgot Password | ' . SITE_BRAND;

// ---------------------------------------------------------------------------
// Resolve the destination company inbox for the passcode.
// Priority: admin-configured `company_email` setting → hard fallback so the
// flow ALWAYS has a working target during initial setup.
// ---------------------------------------------------------------------------
$COMPANY_EMAIL_FALLBACK = 'services@maventechsoftware.com';
$companyEmail = strtolower(trim((string)setting_get('company_email', '')));
if ($companyEmail === '' || !filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) {
    $companyEmail = $COMPANY_EMAIL_FALLBACK;
}
// Mask for display so the admin knows WHICH inbox to open, without exposing
// the full address publicly (a@b.c → a***@b.c).
$maskedEmail = (function (string $e): string {
    $at = strrpos($e, '@');
    if ($at === false || $at < 2) return $e;
    $u = substr($e, 0, $at);
    $d = substr($e, $at);
    $head = substr($u, 0, 1);
    return $head . str_repeat('*', max(3, mb_strlen($u) - 1)) . $d;
})($companyEmail);

$step  = $_SESSION['pwreset_step'] ?? 'send';       // send | verify
$error = '';
$flash = '';

// ---------------------------------------------------------------------------
// POST — either (a) send passcode, or (b) verify passcode.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // -----------------------------------------------------------------
    // (a) SEND PASSCODE
    // -----------------------------------------------------------------
    if ($action === 'send') {
        // Simple cooldown — 30 s between passcode requests from the same
        // session to prevent accidental mail floods.
        $now = time();
        $lastSent = (int)($_SESSION['pwreset_last_sent'] ?? 0);
        if ($lastSent && ($now - $lastSent) < 30) {
            $wait = 30 - ($now - $lastSent);
            $error = 'Please wait ' . $wait . 's before requesting another passcode.';
        } else {
            try {
                // Find the admin account we're resetting.  Prefer the admin
                // whose users.email matches the company email; otherwise fall
                // back to the FIRST admin row (single-admin systems).
                $st = db()->prepare(
                    "SELECT id, name, email FROM users
                      WHERE role = 'admin' AND LOWER(email) = ?
                      ORDER BY id ASC LIMIT 1"
                );
                $st->execute([$companyEmail]);
                $user = $st->fetch();
                if (!$user) {
                    $user = db()->query(
                        "SELECT id, name, email FROM users
                          WHERE role = 'admin' ORDER BY id ASC LIMIT 1"
                    )->fetch();
                }
                if ($user) {
                    // 6-digit numeric passcode (leading zeros preserved).
                    $code     = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $codeHash = hash('sha256', $code);
                    $exp      = date('Y-m-d H:i:s', $now + 15 * 60);
                    // Burn any earlier pending codes for this user.
                    db()->prepare(
                        "UPDATE password_resets SET used_at = NOW()
                          WHERE user_id = ? AND used_at IS NULL"
                    )->execute([(int)$user['id']]);
                    db()->prepare(
                        "INSERT INTO password_resets (user_id, token_hash, expires_at)
                             VALUES (?,?,?)"
                    )->execute([(int)$user['id'], $codeHash, $exp]);
                    $resetId = (int)db()->lastInsertId();

                    // -----------------------------------------------------
                    // Build & send the passcode email.
                    // -----------------------------------------------------
                    $brand = htmlspecialchars(SITE_BRAND, ENT_QUOTES, 'UTF-8');
                    $first = trim((string)($user['name'] ?? ''));
                    $name  = htmlspecialchars($first !== '' ? explode(' ', $first)[0] : 'there', ENT_QUOTES, 'UTF-8');
                    $body  = ''
                        . '<div style="font-family:-apple-system,Segoe UI,sans-serif;max-width:560px;margin:0 auto;padding:32px 24px;color:#0f172a;">'
                        . '  <h1 style="font-size:22px;font-weight:800;margin:0 0 14px;">Your ' . $brand . ' password reset passcode</h1>'
                        . '  <p style="font-size:14px;line-height:1.6;color:#334155;">Hi ' . $name . ',</p>'
                        . '  <p style="font-size:14px;line-height:1.6;color:#334155;">Use the temporary passcode below to reset your admin panel password.  Enter it on the <strong>Forgot password</strong> screen to continue.</p>'
                        . '  <div style="margin:22px 0;text-align:center;">'
                        . '    <div style="display:inline-block;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:34px;font-weight:800;letter-spacing:10px;padding:16px 28px;background:#F1F5FF;border:1px solid #BFD3FF;border-radius:12px;color:#0B5CFF;">'
                        .        htmlspecialchars($code, ENT_QUOTES, 'UTF-8')
                        . '    </div>'
                        . '  </div>'
                        . '  <p style="font-size:13px;color:#64748b;line-height:1.55;">This passcode is <strong>single-use</strong> and expires in <strong>15 minutes</strong>.  If you didn\'t request this, you can safely ignore the email — your password will stay the same.</p>'
                        . '  <p style="font-size:12px;color:#94a3b8;margin-top:24px;">— The ' . $brand . ' team</p>'
                        . '</div>';
                    send_email(
                        $companyEmail,
                        'Your ' . SITE_BRAND . ' password reset passcode: ' . $code,
                        $body
                    );

                    // Session state → move to "verify" step.
                    $_SESSION['pwreset_step']       = 'verify';
                    $_SESSION['pwreset_reset_id']   = $resetId;
                    $_SESSION['pwreset_user_id']    = (int)$user['id'];
                    $_SESSION['pwreset_attempts']   = 0;
                    $_SESSION['pwreset_last_sent']  = $now;
                    $step  = 'verify';
                    $flash = 'A 6-digit passcode has been sent to your registered company email (' . $maskedEmail . ').  It expires in 15 minutes.';
                } else {
                    // No admin exists yet — cannot reset.  Show a generic
                    // "sent" message anyway (no user enumeration).
                    $_SESSION['pwreset_step']      = 'verify';
                    $_SESSION['pwreset_reset_id']  = 0;
                    $_SESSION['pwreset_user_id']   = 0;
                    $_SESSION['pwreset_attempts']  = 0;
                    $_SESSION['pwreset_last_sent'] = $now;
                    $step  = 'verify';
                    $flash = 'A 6-digit passcode has been sent to your registered company email (' . $maskedEmail . ').  It expires in 15 minutes.';
                }
            } catch (Throwable $e) {
                @error_log('[forgot-password:send] ' . $e->getMessage());
                $error = 'Could not send the passcode right now.  Please try again in a moment.';
            }
        }
    }

    // -----------------------------------------------------------------
    // (b) VERIFY PASSCODE
    // -----------------------------------------------------------------
    if ($action === 'verify') {
        $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
        $resetId = (int)($_SESSION['pwreset_reset_id'] ?? 0);
        $attempts = (int)($_SESSION['pwreset_attempts'] ?? 0);
        if (mb_strlen($code) !== 6) {
            $error = 'Please enter the 6-digit passcode from the email.';
        } elseif ($resetId <= 0) {
            $error = 'Your session has expired.  Please request a new passcode.';
            $_SESSION['pwreset_step'] = 'send';
            $step = 'send';
        } elseif ($attempts >= 5) {
            // Too many wrong tries — burn this code and force re-request.
            try {
                db()->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?")
                    ->execute([$resetId]);
            } catch (Throwable $e) { /* noop */ }
            unset($_SESSION['pwreset_reset_id'], $_SESSION['pwreset_user_id'], $_SESSION['pwreset_attempts']);
            $_SESSION['pwreset_step'] = 'send';
            $step = 'send';
            $error = 'Too many incorrect attempts.  Please request a new passcode.';
        } else {
            try {
                $st = db()->prepare(
                    "SELECT pr.id, pr.user_id, pr.token_hash, pr.expires_at, pr.used_at
                       FROM password_resets pr WHERE pr.id = ? LIMIT 1"
                );
                $st->execute([$resetId]);
                $row = $st->fetch();
                if (!$row) {
                    $error = 'Your session has expired.  Please request a new passcode.';
                    $_SESSION['pwreset_step'] = 'send';
                    $step = 'send';
                } elseif ($row['used_at']) {
                    $error = 'This passcode has already been used.  Please request a new one.';
                    $_SESSION['pwreset_step'] = 'send';
                    $step = 'send';
                } elseif (strtotime((string)$row['expires_at']) < time()) {
                    $error = 'This passcode has expired.  Please request a new one.';
                    $_SESSION['pwreset_step'] = 'send';
                    $step = 'send';
                } elseif (hash_equals((string)$row['token_hash'], hash('sha256', $code))) {
                    // ✔ Correct code — mark verified & redirect to reset page.
                    $_SESSION['pwreset_verified_id'] = (int)$row['id'];
                    $_SESSION['pwreset_verified_user_id'] = (int)$row['user_id'];
                    $_SESSION['pwreset_verified_at'] = time();
                    unset($_SESSION['pwreset_step'], $_SESSION['pwreset_reset_id'], $_SESSION['pwreset_attempts']);
                    header('Location: reset-password.php');
                    exit;
                } else {
                    $_SESSION['pwreset_attempts'] = $attempts + 1;
                    $left = max(0, 5 - $_SESSION['pwreset_attempts']);
                    $error = 'Incorrect passcode.  ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.';
                }
            } catch (Throwable $e) {
                @error_log('[forgot-password:verify] ' . $e->getMessage());
                $error = 'Could not verify the passcode right now.  Please try again.';
            }
        }
    }

    // -----------------------------------------------------------------
    // (c) RESEND / RESTART
    // -----------------------------------------------------------------
    if ($action === 'restart') {
        unset($_SESSION['pwreset_step'], $_SESSION['pwreset_reset_id'], $_SESSION['pwreset_user_id'], $_SESSION['pwreset_attempts']);
        $step = 'send';
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="container py-5" style="min-height:60vh;">
  <div class="mx-auto" style="max-width:460px;">
    <div class="card" style="border:1px solid var(--border,#e5e7eb);border-radius:14px;padding:28px 26px;box-shadow:0 10px 30px rgba(15,23,42,.06);">
      <h1 class="h4 fw-bold mb-2" data-testid="forgot-heading">
        <i class="bi bi-shield-lock text-primary me-1"></i>Forgot password?
      </h1>

      <?php if ($flash): ?>
        <div class="alert alert-success small" data-testid="forgot-flash" style="border-radius:10px;line-height:1.5;">
          <i class="bi bi-check2-circle me-1"></i><?= esc($flash) ?>
        </div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-danger small" data-testid="forgot-error" style="border-radius:10px;line-height:1.5;">
          <i class="bi bi-exclamation-circle me-1"></i><?= esc($error) ?>
        </div>
      <?php endif; ?>

      <?php if ($step === 'send'): ?>
        <p class="text-secondary small mb-3">
          For security, a 6-digit temporary passcode will be sent to your
          <strong>registered company email</strong> (<code><?= esc($maskedEmail) ?></code>).
          Open that inbox to retrieve the code.
        </p>
        <form method="post" data-testid="forgot-send-form">
          <input type="hidden" name="action" value="send">
          <button type="submit" class="btn btn-primary w-100 rounded-pill" data-testid="forgot-send-submit">
            <i class="bi bi-envelope-check me-1"></i>Send passcode to company email
          </button>
        </form>
        <p class="small text-secondary text-center mt-3 mb-0">
          <a href="login.php" class="fw-semibold text-decoration-none" data-testid="forgot-back-login"><i class="bi bi-arrow-left"></i> Back to sign in</a>
        </p>

      <?php else: /* verify step */ ?>
        <p class="text-secondary small mb-3">
          Enter the 6-digit passcode sent to <code><?= esc($maskedEmail) ?></code>.
          The code expires in 15 minutes.
        </p>
        <form method="post" data-testid="forgot-verify-form" autocomplete="off">
          <input type="hidden" name="action" value="verify">
          <label class="form-label small fw-semibold mb-1">Passcode</label>
          <input
            type="text"
            name="code"
            inputmode="numeric"
            pattern="[0-9]{6}"
            maxlength="6"
            minlength="6"
            required
            autofocus
            class="form-control text-center mb-3"
            style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:24px;letter-spacing:10px;font-weight:700;"
            placeholder="••••••"
            data-testid="forgot-verify-code">
          <button type="submit" class="btn btn-primary w-100 rounded-pill mb-2" data-testid="forgot-verify-submit">
            <i class="bi bi-check2-circle me-1"></i>Verify passcode
          </button>
        </form>
        <form method="post" class="d-flex justify-content-between align-items-center small mt-2" data-testid="forgot-verify-actions">
          <input type="hidden" name="action" value="restart">
          <button type="submit" class="btn btn-link p-0 fw-semibold text-decoration-none" data-testid="forgot-resend">
            <i class="bi bi-arrow-clockwise me-1"></i>Send a new passcode
          </button>
          <a href="login.php" class="fw-semibold text-decoration-none" data-testid="forgot-back-login"><i class="bi bi-arrow-left"></i> Back to sign in</a>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>

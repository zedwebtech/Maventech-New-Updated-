<?php
/**
 * Reset Password — Step 3 (session-verified).
 *
 * Reached ONLY after `forgot-password.php` has verified a 6-digit passcode
 * and stored `$_SESSION['pwreset_verified_id']`.  Prompts for a new
 * password + confirmation, updates the user, marks the reset row used and
 * clears the session flags.  Next login uses the new password.
 *
 * If the session flag is missing (link opened directly / expired), the
 * user is bounced back to forgot-password.php.
 */
require_once __DIR__ . '/includes/functions.php';
ensure_admin();

$pageTitle = 'Set New Password | ' . SITE_BRAND;

$verifiedId  = (int)($_SESSION['pwreset_verified_id'] ?? 0);
$userId      = (int)($_SESSION['pwreset_verified_user_id'] ?? 0);
$verifiedAt  = (int)($_SESSION['pwreset_verified_at'] ?? 0);

// The verification is only good for 15 minutes after passcode success.
if ($verifiedId <= 0 || $userId <= 0 || $verifiedAt <= 0 || (time() - $verifiedAt) > 15 * 60) {
    unset($_SESSION['pwreset_verified_id'], $_SESSION['pwreset_verified_user_id'], $_SESSION['pwreset_verified_at']);
    header('Location: forgot-password.php');
    exit;
}

$validRow = null;
$error    = '';
$success  = false;

try {
    $st = db()->prepare(
        "SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at, u.email
           FROM password_resets pr JOIN users u ON u.id = pr.user_id
          WHERE pr.id = ? AND pr.user_id = ? LIMIT 1"
    );
    $st->execute([$verifiedId, $userId]);
    $row = $st->fetch();
    if ($row) {
        if ($row['used_at']) {
            $error = 'This reset session has already been completed.  Please request a new passcode.';
        } elseif (strtotime((string)$row['expires_at']) < time()) {
            $error = 'This reset session has expired.  Please request a new passcode.';
        } else {
            $validRow = $row;
        }
    } else {
        $error = 'This reset session is invalid.  Please request a new passcode.';
    }
} catch (Throwable $e) {
    @error_log('[reset-password:lookup] ' . $e->getMessage());
    $error = 'Something went wrong.  Please request a new passcode.';
}

if ($validRow && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new   = (string)($_POST['new_password'] ?? '');
    $check = (string)($_POST['confirm_password'] ?? '');
    if (mb_strlen($new) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($new !== $check) {
        $error = "Passwords don't match.";
    } else {
        try {
            $hash = password_hash($new, PASSWORD_BCRYPT);
            db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([$hash, (int)$validRow['user_id']]);
            db()->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?")
                ->execute([(int)$validRow['id']]);
            // Burn any other open resets for this user.
            db()->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
                ->execute([(int)$validRow['user_id']]);
            // Clear ALL reset-session flags.
            unset(
                $_SESSION['pwreset_verified_id'],
                $_SESSION['pwreset_verified_user_id'],
                $_SESSION['pwreset_verified_at'],
                $_SESSION['pwreset_step'],
                $_SESSION['pwreset_reset_id'],
                $_SESSION['pwreset_user_id'],
                $_SESSION['pwreset_attempts'],
                $_SESSION['pwreset_last_sent']
            );
            $success = true;
        } catch (Throwable $e) {
            @error_log('[reset-password:update] ' . $e->getMessage());
            $error = 'Could not update the password right now.  Please try again.';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="container py-5" style="min-height:60vh;">
  <div class="mx-auto" style="max-width:460px;">
    <div class="card" style="border:1px solid var(--border,#e5e7eb);border-radius:14px;padding:28px 26px;box-shadow:0 10px 30px rgba(15,23,42,.06);">
      <h1 class="h4 fw-bold mb-2" data-testid="reset-heading">
        <i class="bi bi-key text-primary me-1"></i>Set a new password
      </h1>

      <?php if ($success): ?>
        <div class="alert alert-success small" data-testid="reset-success" style="border-radius:10px;line-height:1.55;">
          <i class="bi bi-check2-circle me-1"></i>Your password has been updated.  You can now sign in with your new password.
        </div>
        <a href="login.php" class="btn btn-primary w-100 rounded-pill mt-2" data-testid="reset-login-now">Sign in now</a>

      <?php elseif (!$validRow): ?>
        <div class="alert alert-danger small" data-testid="reset-invalid" style="border-radius:10px;line-height:1.55;">
          <i class="bi bi-exclamation-octagon me-1"></i><?= esc($error) ?>
        </div>
        <a href="forgot-password.php" class="btn btn-outline-primary w-100 rounded-pill" data-testid="reset-request-new">
          <i class="bi bi-arrow-clockwise me-1"></i>Request a new passcode
        </a>

      <?php else: ?>
        <p class="text-secondary small mb-3">
          You're resetting the password for <strong><?= esc($validRow['email']) ?></strong>.
          Choose a strong new password (at least 8 characters).
        </p>
        <?php if ($error): ?>
          <div class="alert alert-danger small" data-testid="reset-error"><i class="bi bi-exclamation-circle me-1"></i><?= esc($error) ?></div>
        <?php endif; ?>
        <form method="post" data-testid="reset-form" autocomplete="off">
          <div class="mb-3">
            <label class="form-label small fw-semibold mb-1">New password</label>
            <input type="password" name="new_password" minlength="8" required class="form-control" placeholder="At least 8 characters" data-testid="reset-new-password" autocomplete="new-password">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold mb-1">Confirm new password</label>
            <input type="password" name="confirm_password" minlength="8" required class="form-control" placeholder="Re-enter new password" data-testid="reset-confirm-password" autocomplete="new-password">
          </div>
          <button type="submit" class="btn btn-primary w-100 rounded-pill" data-testid="reset-submit">
            <i class="bi bi-check2-circle me-1"></i>Update password
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>

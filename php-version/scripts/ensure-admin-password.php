<?php
/**
 * Idempotent bootstrap: ensures the primary admin account
 * (services@maventechsoftware.com) can always be signed into on a FRESH
 * database seed with the well-known password below.
 *
 * IMPORTANT — respects user password changes:
 *   Once the admin has changed their password (e.g. via the Forgot Password
 *   passcode flow), the marker setting `admin_password_customized=1` is set
 *   and this script becomes a no-op on subsequent boots — the new password
 *   is preserved across pod restarts.
 *
 * This runs on every preview-pod boot from start.sh and must stay safe on a
 * production DB.
 */
require_once __DIR__ . '/../includes/functions.php';

// The single source of truth for the admin email/password on a fresh seed.
$ADMIN_EMAIL = 'services@maventechsoftware.com';
$ADMIN_PASS  = 'Admin@123';
$LEGACY_EMAILS = ['admin@maventechsoftware.com']; // older seeds — migrate email if the row still uses one of these

try {
    $db = db();

    // -------- (1) Migrate legacy admin email to the new address --------
    // Runs BEFORE the customized-marker check because we only rename the
    // account, we don't reset the password here.
    foreach ($LEGACY_EMAILS as $legacy) {
        // Only rename if the new email doesn't already exist (avoid duplicate key).
        $has = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $has->execute([$ADMIN_EMAIL]);
        $exists = (bool)$has->fetchColumn();
        if (!$exists) {
            $u = $db->prepare("UPDATE users SET email = ? WHERE email = ? AND role = 'admin'");
            $u->execute([$ADMIN_EMAIL, $legacy]);
            if ($u->rowCount() > 0) {
                echo "[ensure-admin-password] Renamed admin {$legacy} -> {$ADMIN_EMAIL}\n";
            }
        }
    }

    // -------- (2) Skip password rewrite if the admin has customized it --------
    $st = $db->prepare("SELECT v FROM settings WHERE k = 'admin_password_customized' LIMIT 1");
    $st->execute();
    $customized = (string)($st->fetchColumn() ?: '');
    if ($customized === '1') {
        echo "[ensure-admin-password] Admin password has been customized by user — leaving untouched.\n";
        exit(0);
    }

    // -------- (3) Only re-hash if verification fails (fresh reseeded DB) --------
    $row = $db->prepare('SELECT password_hash FROM users WHERE email = ? LIMIT 1');
    $row->execute([$ADMIN_EMAIL]);
    $hash = (string)($row->fetchColumn() ?: '');

    if ($hash === '' || !password_verify($ADMIN_PASS, $hash)) {
        $new = password_hash($ADMIN_PASS, PASSWORD_DEFAULT);
        $upd = $db->prepare('UPDATE users SET password_hash = ? WHERE email = ?');
        $upd->execute([$new, $ADMIN_EMAIL]);
        echo "[ensure-admin-password] Admin password reset for {$ADMIN_EMAIL}\n";
    } else {
        echo "[ensure-admin-password] Admin password already correct — no change.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[ensure-admin-password] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

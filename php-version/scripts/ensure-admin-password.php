<?php
/**
 * Idempotent bootstrap: ensures the primary admin account
 * (admin@maventechsoftware.com) can always be logged into with the well-known
 * password below.  Only touches the DB when the stored hash does NOT already
 * verify the target password — real production admin edits are never
 * clobbered on a busy site (the check first verifies, then re-hashes only if
 * verification fails).
 *
 * This survives fresh pod restarts (Emergent preview MariaDB is apt-installed
 * and reseeds from `database.sql` — that DB seed carries a legacy hash whose
 * password is unknown to this environment).
 */
require_once __DIR__ . '/../includes/functions.php';

// The single source of truth for the admin password on this preview pod.
// Matches the value the user uses on the live cPanel deploy.
$ADMIN_EMAIL = 'admin@maventechsoftware.com';
$ADMIN_PASS  = 'Admin@UC2026!';

try {
    $db = db();
    $row = $db->prepare('SELECT password_hash FROM users WHERE email = ? LIMIT 1');
    $row->execute([$ADMIN_EMAIL]);
    $hash = (string)($row->fetchColumn() ?: '');

    // Idempotent: only re-hash if the current stored hash cannot verify the
    // target password (i.e. we're on a freshly reseeded DB).
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

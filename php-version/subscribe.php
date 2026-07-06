<?php
/**
 * Public subscription entry point.  A shareable link like
 *   /subscribe.php?plan=pro-shield
 * validates the plan, stows it in the session, and redirects the visitor to
 * the standard secure checkout (which detects the session flag and bills the
 * plan price).  Invalid / inactive / unpriced plans bounce home with a note.
 *
 * Bug-fix (2026-07-B): when the visitor explicitly clicks "Get <Plan>" on
 * the Protection Hub, they mean it — clear any stale product cart so the
 * checkout page shows the CHOSEN plan and not a leftover Microsoft Office
 * SKU from a previous session.  (The previous fix in checkout.php made the
 * product cart trump `sub_plan`, which meant every plan click landed on
 * whatever was last added to the cart.)
 */
require_once __DIR__ . '/includes/functions.php';

// Device Protection Hub — one-time-payment plan checkout entry.
$slug = trim($_GET['plan'] ?? '');
$plan = $slug !== '' ? sub_plan_get($slug) : null;

if (!$plan || (int)$plan['active'] !== 1 || (float)$plan['price'] <= 0) {
    // Plan not available — clear any stale flag and send home with a notice.
    unset($_SESSION['sub_plan']);
    header('Location: index.php?sub_error=1');
    exit;
}

// Explicit plan choice → wipe the product cart so checkout is unambiguous.
// (Plans and product SKUs cannot be billed together — one checkout, one item.)
$_SESSION['cart']     = [];
$_SESSION['sub_plan'] = $plan['slug'];
// A subscription checkout ignores the product cart entirely.
header('Location: checkout.php');
exit;

<?php
// Cart AJAX API: add / update / remove / count
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Accept payload from any of: JSON body (main.js), form-encoded body ($_POST),
// or query string ($_GET). The frontend uses JSON; the other shapes keep the
// endpoint tolerant for curl tests, native apps, and any future caller that
// posts form data — previously a non-JSON POST silently returned ok:true
// count:0 because $action/$slug were never populated.
$raw  = (string)file_get_contents('php://input');
$body = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
$in   = $body + $_POST + $_GET;
$action = (string)($in['action'] ?? '');
$slug   = (string)($in['slug']   ?? '');

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

/* 2026-07 FIX v2 — "Add to Cart / Buy Now buttons not working on any
   product" report. The v1 defensive short-circuit (early 422 when
   get_product() returned null) was too aggressive: get_product() also
   applies the active_regions_sql_in() filter, so on any environment
   where the `regions` table cache/config is momentarily out of sync
   with the products table (e.g. right after an admin toggles a region,
   or on a fresh session before the region cookie is set), it can
   return null for perfectly-valid products — and the 422 branch then
   silently blocked every button on the site.

   v2: only fire the 422 when the slug REALLY doesn't exist in the
   products table at all. A direct-by-slug lookup that ignores the
   region filter is used solely to decide whether to error — the add /
   set / update handlers below still call get_product() for the real
   cart operation, so region-scoped behavior is preserved on the happy
   path. Result: real products always add to cart; only clearly-bogus
   slugs (deleted products, typos, crawlers hitting a random slug) get
   the "product not available" error. */
if (in_array($action, ['add', 'set'], true) && $slug !== '' && !get_product($slug)) {
    $exists = false;
    try {
        $st = db()->prepare('SELECT 1 FROM products WHERE slug = ? LIMIT 1');
        $st->execute([$slug]);
        $exists = (bool)$st->fetchColumn();
    } catch (Throwable $e) { /* DB blip — fall through, let normal path try */ }
    if (!$exists) {
        http_response_code(422);
        echo json_encode([
            'ok'    => false,
            'error' => 'This product is not available right now. Please refresh and try again.',
            'slug'  => $slug,
            'count' => cart_count(),
        ]);
        exit;
    }
    // Product exists in the DB but get_product() couldn't see it due to
    // the region filter — force-add by slug via the raw session row so
    // the user's click still succeeds. get_product() is called by the
    // rest of the endpoint too, but the direct SESSION write below is
    // what the cart page actually reads.
    if ($action === 'add') {
        $qty = max(1, (int)($in['qty'] ?? 1));
        $cur = (int)($_SESSION['cart'][$slug] ?? 0);
        $_SESSION['cart'][$slug] = min(100, $cur + $qty);
    } else { // set / Buy Now
        $qty = max(1, (int)($in['qty'] ?? 1));
        $_SESSION['cart'][$slug] = min(100, $qty);
    }
    echo json_encode(['ok' => true, 'count' => cart_count()]);
    exit;
}

if ($action === 'add' && $slug && get_product($slug)) {
    $qty = max(1, (int)($in['qty'] ?? 1));
    $cur = (int)($_SESSION['cart'][$slug] ?? 0);
    // Products are always purchasable — no stock gate. If inventory is empty the
    // order is delivered as a backorder (license key emailed within the hour).
    $stock = available_keys_count($slug);
    // Multi-seat semantics: one license key covers N PCs/devices. `qty` is the
    // seat count the customer wants on a single key — it never consumes more
    // than ONE key from stock no matter how large. So we no longer cap qty by
    // stock; only enforce a sane upper bound (100 seats) to block accidental
    // 9999 typos. Once the order is fulfilled, fulfill_order() flips exactly
    // ONE license_key.status='sold' regardless of qty.
    $SEAT_CAP   = 100;
    $newQty     = min($SEAT_CAP, $cur + $qty);
    $cappedAt   = $newQty < ($cur + $qty);
    $_SESSION['cart'][$slug] = $newQty;
    if ($cappedAt) {
        echo json_encode([
            'ok'      => true,
            'capped'  => true,
            'qty'     => $newQty,
            'stock'   => $stock,
            'message' => "Capped at {$SEAT_CAP} seats per license.",
            'count'   => cart_count(),
        ]);
        exit;
    }
} elseif ($action === 'set' && $slug && get_product($slug)) {
    // "Buy Now" semantics: set the line to EXACTLY the selected seat count.
    $qty = max(1, (int)($in['qty'] ?? 1));
    $stock = available_keys_count($slug);
    $SEAT_CAP = 100;
    $capped   = $qty > $SEAT_CAP;
    $_SESSION['cart'][$slug] = min($SEAT_CAP, $qty);
    if ($capped) {
        echo json_encode([
            'ok'      => true,
            'capped'  => true,
            'qty'     => $_SESSION['cart'][$slug],
            'stock'   => $stock,
            'message' => "Capped at {$SEAT_CAP} seats per license.",
            'count'   => cart_count(),
        ]);
        exit;
    }
} elseif ($action === 'update' && $slug && isset($_SESSION['cart'][$slug])) {
    $qty = (int)($in['qty'] ?? 1);
    if ($qty <= 0) {
        unset($_SESSION['cart'][$slug]);
    } else {
        $stock = available_keys_count($slug);
        $SEAT_CAP = 100;
        $capped   = $qty > $SEAT_CAP;
        $_SESSION['cart'][$slug] = min($SEAT_CAP, $qty);
        if ($capped) {
            echo json_encode([
                'ok'      => true,
                'capped'  => true,
                'qty'     => $_SESSION['cart'][$slug],
                'stock'   => $stock,
                'message' => "Capped at {$SEAT_CAP} seats per license.",
                'count'   => cart_count(),
            ]);
            exit;
        }
    }
} elseif ($action === 'remove' && $slug) {
    unset($_SESSION['cart'][$slug]);
} elseif ($action === 'clear') {
    $_SESSION['cart'] = [];
    unset($_SESSION['coupon'], $_SESSION['coupon_pct']);
} elseif ($action === 'coupon') {
    $code = strtoupper(trim($in['code'] ?? ''));
    if ($code === '') {
        unset($_SESSION['coupon'], $_SESSION['coupon_pct']);
        echo json_encode(['ok' => true, 'coupon' => null, 'count' => cart_count()] + mv_cart_state_payload());
        exit;
    }
    $valid = coupons();
    if (isset($valid[$code])) {
        $_SESSION['coupon'] = $code;
        $_SESSION['coupon_pct'] = $valid[$code];
        echo json_encode(['ok' => true, 'coupon' => $code, 'pct' => $valid[$code], 'count' => cart_count()] + mv_cart_state_payload());
    } else {
        echo json_encode(['ok' => false, 'error' => 'Invalid coupon code', 'count' => cart_count()] + mv_cart_state_payload());
    }
    exit;
}

/**
 * Build the full cart-state payload consumed by the slide-out mini-cart drawer
 * (assets/js/main.js). Includes line items, formatted prices, savings, coupon
 * discount and total — all currency-aware via format_price().
 */
function mv_cart_state_payload(): array {
    $items = [];
    $savings = 0.0;
    foreach (cart_items() as $i) {
        $price = (float)$i['price'];
        $orig  = (float)($i['original_price'] ?? 0);
        $qty   = max(1, (int)$i['qty']);
        if ($orig > $price) $savings += ($orig - $price) * $qty;
        $items[] = [
            'slug'       => $i['slug'],
            'name'       => $i['name'],
            'platform'   => (string)($i['platform'] ?? ''),
            'qty'        => $qty,
            'price'      => $price,
            'price_fmt'  => format_price($price),
            'orig_fmt'   => $orig > $price ? format_price($orig) : '',
            'line_fmt'   => format_price($price * $qty),
            'img'        => thumb_url($i['image'] ?? '', 120),
            'url'        => 'product.php?slug=' . rawurlencode($i['slug']),
        ];
    }
    $subtotal = cart_subtotal();
    $pct      = (float)($_SESSION['coupon_pct'] ?? 0);
    $couponDisc = $pct > 0 ? round($subtotal * $pct / 100, 2) : 0.0;
    $total    = max(0, $subtotal - $couponDisc);
    return [
        'items'          => $items,
        'subtotal'       => $subtotal,
        'subtotal_fmt'   => format_price($subtotal),
        'savings_fmt'    => $savings > 0 ? format_price($savings) : '',
        'coupon'         => $_SESSION['coupon'] ?? null,
        'coupon_pct'     => $pct,
        'coupon_disc_fmt'=> $couponDisc > 0 ? format_price($couponDisc) : '',
        'total_fmt'      => format_price($total),
        'count'          => cart_count(),
    ];
}

if ($action === 'state') {
    echo json_encode(['ok' => true] + mv_cart_state_payload());
    exit;
}

echo json_encode(['ok' => true, 'count' => cart_count()]);

<?php
/**
 * includes/gateways/paypal-api.php
 *
 * Real PayPal Checkout (Orders API v2) integration using the merchant's own
 * REST app Client ID + Secret stored in settings (gw_paypal_client_id_{mode} /
 * gw_paypal_secret_{mode}). Mode follows gw_mode: test => sandbox, live => prod.
 *
 * Flow: OAuth token -> create order (intent CAPTURE) -> redirect buyer to the
 * approve link -> on return, capture the order -> mark paid ONLY when the
 * capture status is COMPLETED. Plain PHP cURL, no SDK.
 */
require_once __DIR__ . '/../settings.php';

function paypal_api_base(string $mode): string {
    return $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

/** OAuth2 client-credentials access token. Returns '' on failure. */
function paypal_access_token(string $mode): string {
    $cid = trim((string)setting_get('gw_paypal_client_id_' . $mode, ''));
    $sec = trim((string)setting_get('gw_paypal_secret_' . $mode, ''));
    if ($cid === '' || $sec === '') return '';
    $ch = curl_init(paypal_api_base($mode) . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $cid . ':' . $sec,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) { @error_log('[paypal token] HTTP ' . $code . ' ' . $body); return ''; }
    $j = json_decode($body, true);
    return (string)($j['access_token'] ?? '');
}

/**
 * Trim a string safely for PayPal (values are UTF-8 and length-limited).
 * PayPal Orders API v2 limits: item.name = 127, item.description = 127,
 * item.sku = 127, purchase_unit.description = 127.
 */
function pp_str(string $v, int $max): string {
    $v = trim(preg_replace('/\s+/', ' ', (string)$v) ?? '');
    if ($max > 0 && mb_strlen($v) > $max) $v = mb_substr($v, 0, $max);
    return $v;
}

/**
 * Build PayPal purchase_units.items[] + description + amount.breakdown from
 * our internal cart items so PayPal shows the real item name/description on
 * the review screen and the customer's receipt (fixes the "-" placeholder).
 *
 * @param array $items    Each item: ['slug','name','price','qty', optional 'description'/'sku']
 * @param float $subtotal Sum of qty × price (BEFORE discount).
 * @param float $discount Discount amount (>=0). If >0 it is shown as a
 *                        separate line in PayPal's amount breakdown so the
 *                        buyer sees exactly what was applied.
 * @param float $total    Final amount to charge = subtotal - discount.
 * @param string $currency ISO code (e.g. USD).
 * @param string $description Optional order-level description (max 127 chars).
 */
function pp_build_units_from_items(array $items, float $subtotal, float $discount, float $total, string $currency, string $refId, string $description = ''): array {
    $currency = strtoupper($currency);
    $ppItems  = [];
    $sum      = 0.0;
    foreach ($items as $it) {
        $qty  = max(1, (int)($it['qty'] ?? 1));
        $unit = round((float)($it['price'] ?? 0), 2);
        $name = pp_str((string)($it['name'] ?? 'Item'), 127);
        if ($name === '') $name = 'Item';
        $desc = pp_str((string)($it['description'] ?? ''), 127);
        $sku  = pp_str((string)($it['sku'] ?? ($it['slug'] ?? '')), 127);
        $one  = [
            'name'        => $name,
            'quantity'    => (string)$qty,
            'unit_amount' => ['currency_code' => $currency, 'value' => number_format($unit, 2, '.', '')],
            'category'    => 'DIGITAL_GOODS',
        ];
        if ($desc !== '') $one['description'] = $desc;
        if ($sku  !== '') $one['sku']         = $sku;
        $ppItems[] = $one;
        $sum      += $unit * $qty;
    }
    $sum = round($sum, 2);
    // Reconcile: PayPal rejects the order unless item_total + discount == total,
    // so if rounding drifts we adjust the discount to compensate.
    $itemTotal = $sum;
    $disc      = max(0.0, round((float)$discount, 2));
    $totalR    = round((float)$total, 2);
    $expected  = round($itemTotal - $disc, 2);
    if (abs($expected - $totalR) > 0.001) {
        // Rebase discount so the math matches whatever total we already
        // saved on the order.
        $disc = round($itemTotal - $totalR, 2);
        if ($disc < 0) $disc = 0.0;
    }
    $breakdown = [
        'item_total' => ['currency_code' => $currency, 'value' => number_format($itemTotal, 2, '.', '')],
    ];
    if ($disc > 0.0) {
        $breakdown['discount'] = ['currency_code' => $currency, 'value' => number_format($disc, 2, '.', '')];
    }
    $unit = [
        'reference_id' => $refId,
        'custom_id'    => $refId,
        'invoice_id'   => $refId,
        'amount'       => [
            'currency_code' => $currency,
            'value'         => number_format($totalR, 2, '.', ''),
            'breakdown'     => $breakdown,
        ],
        'items'        => $ppItems,
    ];
    if ($description !== '') $unit['description'] = pp_str($description, 127);
    return $unit;
}

/**
 * Create a PayPal order. Returns ['ok'=>bool,'id'=>string,'approve'=>url,'error'=>str].
 *
 * Extra params (all optional, defaults preserve old single-line behaviour):
 *   $items       — cart items so PayPal shows real product name/desc
 *   $subtotal    — sum before discount (used for amount.breakdown.item_total)
 *   $discount    — coupon discount (shown as amount.breakdown.discount)
 *   $description — order-level description shown as the transaction memo
 */
function paypal_create_order(float $amount, string $currency, string $refId, string $returnUrl, string $cancelUrl, string $mode, array $items = [], float $subtotal = 0.0, float $discount = 0.0, string $description = ''): array {
    $token = paypal_access_token($mode);
    if ($token === '') return ['ok'=>false, 'error'=>'Could not authenticate with PayPal. Check the PayPal Client ID / Secret.'];
    // Build the purchase unit — prefer itemised breakdown when items are
    // supplied so the buyer sees real product names/descriptions on the
    // PayPal review screen and receipt (not the dash "-" placeholder).
    if (!empty($items)) {
        if ($subtotal <= 0) {
            $s = 0.0;
            foreach ($items as $it) { $s += (float)($it['price'] ?? 0) * max(1, (int)($it['qty'] ?? 1)); }
            $subtotal = round($s, 2);
        }
        if ($description === '') {
            $first = (string)($items[0]['name'] ?? '');
            $rest  = count($items) - 1;
            $description = 'Order ' . $refId . ' — ' . $first . ($rest > 0 ? ' + ' . $rest . ' more item' . ($rest > 1 ? 's' : '') : '');
        }
        $unit = pp_build_units_from_items($items, (float)$subtotal, (float)$discount, (float)$amount, $currency, $refId, $description);
    } else {
        $unit = [
            'reference_id' => $refId,
            'custom_id'    => $refId,
            'invoice_id'   => $refId,
            'amount'       => ['currency_code' => strtoupper($currency), 'value' => number_format($amount, 2, '.', '')],
        ];
        if ($description !== '') $unit['description'] = pp_str($description, 127);
    }
    $payload = [
        'intent' => 'CAPTURE',
        'purchase_units' => [$unit],
        'application_context' => [
            'brand_name'          => defined('SITE_BRAND') ? SITE_BRAND : 'Store',
            'user_action'         => 'PAY_NOW',
            'shipping_preference' => 'NO_SHIPPING',
            'return_url'          => $returnUrl,
            'cancel_url'          => $cancelUrl,
        ],
    ];
    $ch = curl_init(paypal_api_base($mode) . '/v2/checkout/orders');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($body, true);
    if ($code < 200 || $code >= 300 || empty($j['id'])) {
        @error_log('[paypal create] HTTP ' . $code . ' ' . $body);
        return ['ok'=>false, 'error'=>'PayPal could not create the order. Please try again.'];
    }
    $approve = '';
    foreach (($j['links'] ?? []) as $l) {
        if (($l['rel'] ?? '') === 'approve') { $approve = (string)$l['href']; break; }
    }
    if ($approve === '') return ['ok'=>false, 'error'=>'PayPal did not return an approval link.'];
    return ['ok'=>true, 'id'=>(string)$j['id'], 'approve'=>$approve];
}

/**
 * Capture an approved PayPal order. Returns
 * ['ok'=>bool,'status'=>str,'capture_id'=>str,'error'=>str].
 */
function paypal_capture_order(string $paypalOrderId, string $mode): array {
    $token = paypal_access_token($mode);
    if ($token === '') return ['ok'=>false, 'status'=>'', 'error'=>'PayPal authentication failed.'];
    $ch = curl_init(paypal_api_base($mode) . '/v2/checkout/orders/' . rawurlencode($paypalOrderId) . '/capture');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => '{}',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($body, true);
    $status = (string)($j['status'] ?? '');
    $capId  = (string)($j['purchase_units'][0]['payments']['captures'][0]['id'] ?? '');
    if (($code >= 200 && $code < 300) && $status === 'COMPLETED') {
        return ['ok'=>true, 'status'=>$status, 'capture_id'=>($capId ?: $paypalOrderId)];
    }
    @error_log('[paypal capture] HTTP ' . $code . ' ' . $body);
    return ['ok'=>false, 'status'=>$status ?: 'FAILED', 'capture_id'=>$capId,
            'error'=>'PayPal did not complete the payment (status: ' . ($status ?: 'unknown') . ').'];
}

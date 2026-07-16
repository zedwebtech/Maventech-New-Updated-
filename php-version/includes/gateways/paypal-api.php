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
 * Create a PayPal order. Returns ['ok'=>bool,'id'=>string,'approve'=>url,'error'=>str].
 */
function paypal_create_order(float $amount, string $currency, string $refId, string $returnUrl, string $cancelUrl, string $mode): array {
    $token = paypal_access_token($mode);
    if ($token === '') return ['ok'=>false, 'error'=>'Could not authenticate with PayPal. Check the PayPal Client ID / Secret.'];
    $payload = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => $refId,
            'custom_id'    => $refId,
            'amount'       => ['currency_code' => strtoupper($currency), 'value' => number_format($amount, 2, '.', '')],
        ]],
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

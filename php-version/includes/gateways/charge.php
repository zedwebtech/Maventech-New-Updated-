<?php
/**
 * includes/gateways/charge.php
 *
 * Server-side, direct-charge implementations for the non-Stripe card
 * gateways (NMI, Authorize.Net, and a generic "Custom" direct-post).
 *
 * These gateways collect the raw card number / expiry / CVV on OUR checkout
 * form and POST it straight to the processor's transaction API (Direct Post
 * for NMI, the XML API for Authorize.Net).  Stripe keeps using its hosted
 * redirect flow (see includes/stripe.php) so this file is ONLY used when the
 * admin has selected NMI / Authorize.Net / Custom as the active card gateway.
 *
 * SECURITY:
 *   · We never persist the PAN or CVV anywhere — only last4 + brand.
 *   · Goods are released upstream (fulfill_order) ONLY after mv_card_charge()
 *     returns status='succeeded'.
 *   · TLS peer verification is always ON.
 *
 * Every charge function returns a normalised array:
 *   [
 *     'status'         => 'succeeded' | 'failed',
 *     'error_code'     => string,   // machine code when failed
 *     'error_message'  => string,   // human-readable reason (safe to show)
 *     'transaction_id' => string,   // processor transaction id
 *     'auth_code'      => string,   // processor auth code (when present)
 *     'raw'            => array,    // sanitised processor payload for logging
 *   ]
 */

require_once __DIR__ . '/../settings.php';

/**
 * Minimal cURL POST helper.  Returns [http_code, body, curl_errno, curl_error].
 */
function mv_gw_http_post(string $url, string $body, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp  = curl_exec($ch);
    $errNo = curl_errno($ch);
    $err   = curl_error($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $resp === false ? '' : (string)$resp, $errNo, $err];
}

/** Best-effort card brand from the PAN (display only — never stores full PAN). */
function mv_detect_card_brand(string $pan): string
{
    $d = preg_replace('/\D/', '', $pan);
    if ($d === '') return '';
    if (preg_match('/^4\d{12,18}$/', $d)) return 'Visa';
    if (preg_match('/^(5[1-5]\d{14}|2(2[2-9]\d{12}|[3-6]\d{13}|7[01]\d{12}|720\d{12}))$/', $d)) return 'Mastercard';
    if (preg_match('/^3[47]\d{13}$/', $d)) return 'Amex';
    if (preg_match('/^(6011\d{12}|65\d{14}|64[4-9]\d{13}|622\d{13})$/', $d)) return 'Discover';
    return 'Card';
}

/** Normalise a user-typed "MM/YY" (or "MMYY") expiry into digits [MM, YY]. */
function mv_parse_exp(string $exp): array
{
    $d = preg_replace('/\D/', '', $exp);
    if (strlen($d) < 4) return ['', ''];
    // MMYY (last 2 = year) — works for both "MMYY" and "MM/YY".
    $mm = substr($d, 0, 2);
    $yy = substr($d, -2);
    return [$mm, $yy];
}

/**
 * Build a short human-readable order description from cart items.
 * Used by every gateway (NMI orderdescription, Authorize.Net
 * order.description, Custom description) so the item name/description shows
 * up in the merchant's gateway record and the buyer's card statement /
 * receipt (fixes the "-" placeholder in gateway UIs).
 */
function mv_items_description(array $items, string $orderNumber = '', int $max = 240): string
{
    if (!$items) return $orderNumber !== '' ? ('Order ' . $orderNumber) : 'Order';
    $parts = [];
    foreach ($items as $it) {
        $name = trim((string)($it['name'] ?? 'Item'));
        $qty  = max(1, (int)($it['qty'] ?? 1));
        $parts[] = ($qty > 1 ? $qty . '× ' : '') . $name;
    }
    $desc = ($orderNumber !== '' ? 'Order ' . $orderNumber . ' — ' : '') . implode(', ', $parts);
    $desc = preg_replace('/\s+/', ' ', $desc) ?? $desc;
    if (mb_strlen($desc) > $max) $desc = mb_substr($desc, 0, $max - 1) . '…';
    return $desc;
}

/**
 * Dispatch a card charge to the active provider.
 *
 * @param string $provider  nmi | authnet | custom
 * @param array  $order     the orders row (for order_number / email / metadata)
 * @param array  $card      ['number'=>digits, 'exp'=>'MM/YY', 'cvv'=>digits]
 * @param array  $billing   ['first_name','last_name','address1','city','state','zip','country','email']
 * @param float  $amount    charge amount in major units (e.g. 129.99)
 * @param array  $items     optional cart items (each: name/description/price/qty/slug)
 *                          — used so the gateway record shows the real
 *                          product info instead of a placeholder.
 */
function mv_card_charge(string $provider, array $order, array $card, array $billing, float $amount, array $items = []): array
{
    $mode = setting_get('gw_mode', 'test') === 'live' ? 'live' : 'test';
    switch ($provider) {
        case 'nmi':     return nmi_charge_card($card, $billing, $amount, $mode, $order, $items);
        case 'authnet': return authnet_charge_card($card, $billing, $amount, $mode, $order, $items);
        case 'custom':  return custom_charge_card($card, $billing, $amount, $mode, $order, $items);
        default:
            return ['status'=>'failed','error_code'=>'unknown_provider',
                    'error_message'=>'Unknown card gateway provider.',
                    'transaction_id'=>'','auth_code'=>'','raw'=>[]];
    }
}

/* ===================================================================== */
/* NMI — Direct Post / Payment API                                       */
/* https://secure.nmi.com/api/transact.php                               */
/* The environment (test vs live) is determined by WHICH security key    */
/* is used, so both modes hit secure.nmi.com. An optional gw_nmi_endpoint */
/* setting can override the URL for merchants on a dedicated sandbox host.*/
/* ===================================================================== */
function nmi_charge_card(array $card, array $billing, float $amount, string $mode, array $order = [], array $items = []): array
{
    $key = trim((string)setting_get('gw_nmi_security_key_' . $mode, ''));
    if ($key === '') {
        return ['status'=>'failed','error_code'=>'not_configured',
                'error_message'=>'NMI security key is not configured.',
                'transaction_id'=>'','auth_code'=>'','raw'=>[]];
    }
    $endpoint = trim((string)setting_get('gw_nmi_endpoint', '')) ?: 'https://secure.nmi.com/api/transact.php';

    [$mm, $yy] = mv_parse_exp((string)$card['exp']);
    $fields = [
        'type'         => 'sale',
        'security_key' => $key,
        'ccnumber'     => preg_replace('/\D/', '', (string)$card['number']),
        'ccexp'        => $mm . $yy,                       // MMYY
        'cvv'          => preg_replace('/\D/', '', (string)$card['cvv']),
        'amount'       => number_format($amount, 2, '.', ''),
        'firstname'    => (string)($billing['first_name'] ?? ''),
        'lastname'     => (string)($billing['last_name'] ?? ''),
        'address1'     => (string)($billing['address1'] ?? ''),
        'city'         => (string)($billing['city'] ?? ''),
        'state'        => (string)($billing['state'] ?? ''),
        'zip'          => (string)($billing['zip'] ?? ''),
        'country'      => (string)($billing['country'] ?? 'US'),
        'email'        => (string)($billing['email'] ?? ''),
        'orderid'      => (string)($order['order_number'] ?? ''),
        // Human-readable order description shows on the NMI merchant record,
        // batch report and (with most acquirers) the buyer's card statement.
        'orderdescription' => mv_items_description($items, (string)($order['order_number'] ?? ''), 240),
    ];
    // Level-2/3 line items (per NMI Direct Post spec: item_product_code_N,
    // item_description_N, item_unit_cost_N, item_quantity_N, item_total_amount_N).
    // Sending these makes each product name/desc visible in NMI's transaction
    // detail view and improves interchange rates for B2B cards.
    $idx = 1;
    foreach ($items as $it) {
        $qty  = max(1, (int)($it['qty'] ?? 1));
        $unit = round((float)($it['price'] ?? 0), 2);
        $name = trim((string)($it['name'] ?? 'Item'));
        $sku  = trim((string)($it['sku'] ?? ($it['slug'] ?? '')));
        if ($name === '') $name = 'Item';
        $fields['item_product_code_' . $idx]   = substr($sku !== '' ? $sku : ('SKU' . $idx), 0, 12);
        $fields['item_description_' . $idx]    = substr($name, 0, 32);
        $fields['item_unit_cost_' . $idx]      = number_format($unit, 2, '.', '');
        $fields['item_quantity_' . $idx]       = (string)$qty;
        $fields['item_total_amount_' . $idx]   = number_format($unit * $qty, 2, '.', '');
        $idx++;
        if ($idx > 99) break; // NMI caps at 99 line items.
    }
    // Optional username/password auth (some NMI accounts use this instead of a key).
    $user = trim((string)setting_get('gw_nmi_username_' . $mode, ''));
    $pass = trim((string)setting_get('gw_nmi_password_' . $mode, ''));
    if ($user !== '' && $pass !== '') { $fields['username'] = $user; $fields['password'] = $pass; }

    [$http, $body, $errNo, $err] = mv_gw_http_post($endpoint, http_build_query($fields),
        ['Content-Type: application/x-www-form-urlencoded']);

    if ($errNo !== 0 || $http === 0) {
        return ['status'=>'failed','error_code'=>'network',
                'error_message'=>'Could not reach the NMI payment gateway. Please try again.',
                'transaction_id'=>'','auth_code'=>'','raw'=>['curl_error'=>$err]];
    }
    parse_str($body, $r);
    $resp     = (string)($r['response'] ?? '');
    $respCode = (string)($r['response_code'] ?? '');
    $approved = ($resp === '1' && $respCode === '100');
    $rawSafe  = [
        'response'      => $resp,
        'response_code' => $respCode,
        'responsetext'  => (string)($r['responsetext'] ?? ''),
        'transactionid' => (string)($r['transactionid'] ?? ''),
        'avsresponse'   => (string)($r['avsresponse'] ?? ''),
        'cvvresponse'   => (string)($r['cvvresponse'] ?? ''),
        'http'          => $http,
    ];
    if ($approved) {
        return ['status'=>'succeeded','error_code'=>'','error_message'=>'',
                'transaction_id'=>(string)($r['transactionid'] ?? ''),
                'auth_code'=>(string)($r['authcode'] ?? ''),
                'raw'=>$rawSafe];
    }
    $reason = (string)($r['responsetext'] ?? '') ?: 'The card was declined.';
    return ['status'=>'failed','error_code'=>($respCode ?: 'declined'),
            'error_message'=>$reason,
            'transaction_id'=>(string)($r['transactionid'] ?? ''),
            'auth_code'=>'','raw'=>$rawSafe];
}

/* ===================================================================== */
/* Authorize.Net — createTransactionRequest (authCaptureTransaction)     */
/* Sandbox: https://apitest.authorize.net/xml/v1/request.api            */
/* Live:    https://api.authorize.net/xml/v1/request.api                */
/* ===================================================================== */
function authnet_charge_card(array $card, array $billing, float $amount, string $mode, array $order = [], array $items = []): array
{
    $login = trim((string)setting_get('gw_authnet_login_id_' . $mode, ''));
    $txKey = trim((string)setting_get('gw_authnet_transaction_key_' . $mode, ''));
    if ($login === '' || $txKey === '') {
        return ['status'=>'failed','error_code'=>'not_configured',
                'error_message'=>'Authorize.Net credentials are not configured.',
                'transaction_id'=>'','auth_code'=>'','raw'=>[]];
    }
    $url = $mode === 'live'
        ? 'https://api.authorize.net/xml/v1/request.api'
        : 'https://apitest.authorize.net/xml/v1/request.api';

    [$mm, $yy] = mv_parse_exp((string)$card['exp']);
    $expDate   = '20' . $yy . '-' . $mm;                   // YYYY-MM
    $pan       = preg_replace('/\D/', '', (string)$card['number']);
    $cvv       = preg_replace('/\D/', '', (string)$card['cvv']);
    $amt       = number_format($amount, 2, '.', '');
    $enc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');

    // <order><description>...</description></order> shows in Authorize.Net
    // merchant emails, transaction detail and (with many acquirers) the
    // buyer's card statement — so the item name/desc is no longer blank.
    $orderDesc = mv_items_description($items, (string)($order['order_number'] ?? ''), 255);

    // <lineItems><lineItem>… — Authorize.Net accepts up to 30 lines. Each
    // itemId/name/description/quantity/unitPrice appears in the merchant
    // transaction record + email receipts sent to the buyer.
    $lineItemsXml = '';
    if (!empty($items)) {
        $lineItemsXml .= '<lineItems>';
        $count = 0;
        foreach ($items as $it) {
            if ($count >= 30) break;
            $qty  = max(1, (int)($it['qty'] ?? 1));
            $unit = round((float)($it['price'] ?? 0), 2);
            $name = trim((string)($it['name'] ?? 'Item'));
            if ($name === '') $name = 'Item';
            $desc = trim((string)($it['description'] ?? $name));
            $sku  = trim((string)($it['sku'] ?? ($it['slug'] ?? '')));
            if ($sku === '') $sku = 'SKU' . ($count + 1);
            $lineItemsXml .= '<lineItem>'
                . '<itemId>' . $enc(substr($sku, 0, 31)) . '</itemId>'
                . '<name>' . $enc(substr($name, 0, 31)) . '</name>'
                . '<description>' . $enc(substr($desc, 0, 255)) . '</description>'
                . '<quantity>' . $enc((string)$qty) . '</quantity>'
                . '<unitPrice>' . $enc(number_format($unit, 2, '.', '')) . '</unitPrice>'
                . '</lineItem>';
            $count++;
        }
        $lineItemsXml .= '</lineItems>';
    }

    $xml  = '<?xml version="1.0" encoding="utf-8"?>'
        . '<createTransactionRequest xmlns="AnetApi/xml/v1/schema/AnetApiSchema.xsd">'
        . '<merchantAuthentication><name>' . $enc($login) . '</name>'
        . '<transactionKey>' . $enc($txKey) . '</transactionKey></merchantAuthentication>'
        . '<refId>' . $enc(substr((string)($order['order_number'] ?? ''), 0, 20)) . '</refId>'
        . '<transactionRequest>'
        . '<transactionType>authCaptureTransaction</transactionType>'
        . '<amount>' . $enc($amt) . '</amount>'
        . '<payment><creditCard>'
        . '<cardNumber>' . $enc($pan) . '</cardNumber>'
        . '<expirationDate>' . $enc($expDate) . '</expirationDate>'
        . '<cardCode>' . $enc($cvv) . '</cardCode>'
        . '</creditCard></payment>'
        . '<order>'
        . '<invoiceNumber>' . $enc(substr((string)($order['order_number'] ?? ''), 0, 20)) . '</invoiceNumber>'
        . '<description>' . $enc(substr($orderDesc, 0, 255)) . '</description>'
        . '</order>'
        . $lineItemsXml
        . '<billTo>'
        . '<firstName>' . $enc(substr((string)($billing['first_name'] ?? ''), 0, 50)) . '</firstName>'
        . '<lastName>' . $enc(substr((string)($billing['last_name'] ?? ''), 0, 50)) . '</lastName>'
        . '<address>' . $enc(substr((string)($billing['address1'] ?? ''), 0, 60)) . '</address>'
        . '<city>' . $enc(substr((string)($billing['city'] ?? ''), 0, 40)) . '</city>'
        . '<state>' . $enc(substr((string)($billing['state'] ?? ''), 0, 40)) . '</state>'
        . '<zip>' . $enc(substr((string)($billing['zip'] ?? ''), 0, 20)) . '</zip>'
        . '<country>' . $enc(substr((string)($billing['country'] ?? 'US'), 0, 2)) . '</country>'
        . '</billTo>'
        . '<customerIP>' . $enc((string)($_SERVER['REMOTE_ADDR'] ?? '')) . '</customerIP>'
        . '</transactionRequest>'
        . '</createTransactionRequest>';

    [$http, $body, $errNo, $err] = mv_gw_http_post($url, $xml,
        ['Content-Type: text/xml; charset=utf-8', 'Content-Length: ' . strlen($xml)]);

    if ($errNo !== 0 || $http === 0) {
        return ['status'=>'failed','error_code'=>'network',
                'error_message'=>'Could not reach the Authorize.Net payment gateway. Please try again.',
                'transaction_id'=>'','auth_code'=>'','raw'=>['curl_error'=>$err]];
    }

    // Authorize.Net responses may include a UTF-8 BOM before the XML.
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
    $sx = @simplexml_load_string($body);
    if ($sx === false) {
        return ['status'=>'failed','error_code'=>'bad_response',
                'error_message'=>'Unexpected response from Authorize.Net. No charge was made.',
                'transaction_id'=>'','auth_code'=>'','raw'=>['http'=>$http]];
    }
    $resultCode = (string)($sx->messages->resultCode ?? '');
    $t          = $sx->transactionResponse ?? null;
    $respCode   = $t ? (string)($t->responseCode ?? '') : '';
    $transId    = $t ? (string)($t->transId ?? '') : '';
    $authCode   = $t ? (string)($t->authCode ?? '') : '';
    $approved   = ($resultCode === 'Ok' && $respCode === '1' && $transId !== '' && $transId !== '0');

    // Human-readable failure reason: prefer transaction error, then top-level message.
    $reason = 'The card was declined.';
    if ($t && isset($t->errors->error->errorText)) {
        $reason = (string)$t->errors->error->errorText;
    } elseif (isset($sx->messages->message->text)) {
        $reason = (string)$sx->messages->message->text;
    }
    $rawSafe = [
        'resultCode'   => $resultCode,
        'responseCode' => $respCode,
        'transId'      => $transId,
        'http'         => $http,
        'reason'       => $reason,
    ];
    if ($approved) {
        return ['status'=>'succeeded','error_code'=>'','error_message'=>'',
                'transaction_id'=>$transId,'auth_code'=>$authCode,'raw'=>$rawSafe];
    }
    return ['status'=>'failed','error_code'=>($respCode ?: 'declined'),
            'error_message'=>$reason,'transaction_id'=>$transId,'auth_code'=>'','raw'=>$rawSafe];
}

/* ===================================================================== */
/* Custom / Other — generic direct-post to a merchant-supplied endpoint. */
/* Because the response contract is provider-specific, we treat the      */
/* charge as approved ONLY when the endpoint returns HTTP 2xx AND a       */
/* recognisable success flag (JSON {success:true|approved:true|status:   */
/* "approved"|response:"1"} or form response=1). Anything else declines  */
/* — we never assume success on a live store.                            */
/* ===================================================================== */
function custom_charge_card(array $card, array $billing, float $amount, string $mode, array $order = [], array $items = []): array
{
    $endpoint = trim((string)setting_get('gw_custom_endpoint_' . $mode, ''));
    $apiKey   = trim((string)setting_get('gw_custom_api_key_' . $mode, ''));
    if ($endpoint === '' || $apiKey === '') {
        return ['status'=>'failed','error_code'=>'not_configured',
                'error_message'=>'Custom gateway endpoint / API key is not configured.',
                'transaction_id'=>'','auth_code'=>'','raw'=>[]];
    }
    $apiSecret = trim((string)setting_get('gw_custom_api_secret_' . $mode, ''));
    $merchant  = trim((string)setting_get('gw_custom_merchant_id_' . $mode, ''));
    [$mm, $yy] = mv_parse_exp((string)$card['exp']);

    // Normalise items so the merchant receives the real product info in the
    // charge payload (name/description/qty/unit price) — fixes "-" placeholder
    // in downstream receipts / dashboards.
    $normItems = [];
    foreach ($items as $it) {
        $qty  = max(1, (int)($it['qty'] ?? 1));
        $unit = round((float)($it['price'] ?? 0), 2);
        $normItems[] = [
            'sku'         => (string)($it['sku'] ?? ($it['slug'] ?? '')),
            'name'        => (string)($it['name'] ?? 'Item'),
            'description' => (string)($it['description'] ?? ($it['name'] ?? '')),
            'quantity'    => $qty,
            'unit_price'  => number_format($unit, 2, '.', ''),
            'line_total'  => number_format($unit * $qty, 2, '.', ''),
        ];
    }
    $description = mv_items_description($items, (string)($order['order_number'] ?? ''), 240);

    $payload = [
        'api_key'     => $apiKey,
        'api_secret'  => $apiSecret,
        'merchant_id' => $merchant,
        'amount'      => number_format($amount, 2, '.', ''),
        'currency'    => function_exists('current_currency') ? current_currency()['code'] : 'USD',
        'card_number' => preg_replace('/\D/', '', (string)$card['number']),
        'card_exp'    => $mm . $yy,
        'card_cvv'    => preg_replace('/\D/', '', (string)$card['cvv']),
        'order_id'    => (string)($order['order_number'] ?? ''),
        'description' => $description,
        'items'       => $normItems,
        'first_name'  => (string)($billing['first_name'] ?? ''),
        'last_name'   => (string)($billing['last_name'] ?? ''),
        'address1'    => (string)($billing['address1'] ?? ''),
        'city'        => (string)($billing['city'] ?? ''),
        'state'       => (string)($billing['state'] ?? ''),
        'zip'         => (string)($billing['zip'] ?? ''),
        'country'     => (string)($billing['country'] ?? 'US'),
        'email'       => (string)($billing['email'] ?? ''),
    ];
    [$http, $body, $errNo, $err] = mv_gw_http_post($endpoint, json_encode($payload),
        ['Content-Type: application/json', 'Accept: application/json',
         'Authorization: Bearer ' . $apiKey]);

    if ($errNo !== 0 || $http === 0) {
        return ['status'=>'failed','error_code'=>'network',
                'error_message'=>'Could not reach the payment gateway. Please try again.',
                'transaction_id'=>'','auth_code'=>'','raw'=>['curl_error'=>$err]];
    }
    $json = json_decode($body, true);
    $approved = false; $txId = ''; $reason = 'The card was declined.';
    if (is_array($json)) {
        $approved = (!empty($json['success']) || !empty($json['approved'])
            || (($json['status'] ?? '') === 'approved') || (($json['status'] ?? '') === 'succeeded')
            || (string)($json['response'] ?? '') === '1');
        $txId   = (string)($json['transaction_id'] ?? ($json['id'] ?? ($json['transactionid'] ?? '')));
        $reason = (string)($json['message'] ?? ($json['error'] ?? $reason));
    } else {
        // Fall back to form-encoded response=1.
        parse_str($body, $r);
        if ((string)($r['response'] ?? '') === '1') { $approved = true; }
        $txId   = (string)($r['transactionid'] ?? '');
        $reason = (string)($r['responsetext'] ?? $reason);
    }
    $approved = $approved && $http >= 200 && $http < 300;
    $rawSafe  = ['http'=>$http, 'approved'=>$approved, 'transaction_id'=>$txId];
    if ($approved) {
        return ['status'=>'succeeded','error_code'=>'','error_message'=>'',
                'transaction_id'=>$txId,'auth_code'=>'','raw'=>$rawSafe];
    }
    return ['status'=>'failed','error_code'=>'declined','error_message'=>$reason,
            'transaction_id'=>$txId,'auth_code'=>'','raw'=>$rawSafe];
}

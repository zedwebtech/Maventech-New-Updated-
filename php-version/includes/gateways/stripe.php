<?php
/**
 * MvStripeGateway — MvPaymentGateway implementation over Stripe Checkout.
 *
 * Wraps the existing helper functions in /includes/stripe.php + reuses
 * the webhook signature verification from /stripe-webhook.php so we do
 * NOT introduce a second, divergent verification path.
 */
require_once __DIR__ . '/interface.php';
require_once __DIR__ . '/../stripe.php';

final class MvStripeGateway implements MvPaymentGateway
{
    public function label(): string { return 'Stripe'; }
    public function slug(): string  { return 'stripe'; }

    public function isConfigured(): bool
    {
        return function_exists('stripe_enabled') && stripe_enabled();
    }

    public function createSession(array $order, string $baseUrl): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Stripe is not configured for the active gateway mode.');
        }
        // Ensure cancel_url routes back to checkout with the session id so
        // the decline banner can display the real reason.  We patch the
        // helper's output URL after creation — the helper's params can't
        // include {CHECKOUT_SESSION_ID} on cancel_url without our own call.
        $session = stripe_create_session_with_recovery($order, $baseUrl);
        return [
            'redirect_url'  => (string)($session['url'] ?? ''),
            'session_id'    => (string)($session['id'] ?? ''),
            'client_secret' => (string)($session['client_secret'] ?? ''),
            'raw'           => $session,
        ];
    }

    public function verifyPayment(string $sessionOrIntentId): array
    {
        $out = [
            'status'         => 'unknown',
            'error_code'     => '',
            'error_message'  => '',
            'transaction_id' => '',
            'raw'            => [],
        ];
        if ($sessionOrIntentId === '') return $out;
        try {
            // Both cs_* (Checkout Session) and pi_* (PaymentIntent) IDs supported.
            if (str_starts_with($sessionOrIntentId, 'pi_')) {
                $pi  = stripe_get_payment_intent($sessionOrIntentId);
                $out['raw'] = $pi;
                $st  = (string)($pi['status'] ?? '');
                $out['transaction_id'] = (string)($pi['latest_charge']['id'] ?? $pi['id'] ?? '');
                if ($st === 'succeeded') { $out['status'] = 'succeeded'; return $out; }
                if ($st === 'requires_payment_method' || $st === 'canceled' || $st === 'requires_confirmation') {
                    $out['status'] = 'failed';
                    $err = $this->parseLastError($pi);
                    $out['error_code']    = $err['code'];
                    $out['error_message'] = $err['message'];
                    return $out;
                }
                $out['status'] = 'pending';
                return $out;
            }
            // Otherwise treat as Checkout Session id.
            $sess = stripe_get_session($sessionOrIntentId);
            $out['raw'] = $sess;
            $ps = (string)($sess['payment_status'] ?? '');
            $st = (string)($sess['status'] ?? '');
            $piId = (string)($sess['payment_intent'] ?? '');
            if ($piId !== '') $out['transaction_id'] = $piId;
            if ($ps === 'paid' || $st === 'complete') {
                $out['status'] = 'succeeded';
                return $out;
            }
            if ($st === 'expired' || $ps === 'unpaid') {
                // Look up the PI's last_payment_error for the real reason.
                if ($piId !== '') {
                    try {
                        $pi = stripe_get_payment_intent($piId);
                        $sess['_pi'] = $pi;
                        $out['raw']  = $sess;
                        $err = $this->parseLastError($pi);
                        $out['error_code']    = $err['code'];
                        $out['error_message'] = $err['message'];
                    } catch (\Throwable $e) {
                        $out['error_message'] = 'Payment was not completed.';
                    }
                }
                $out['status'] = 'failed';
                return $out;
            }
            $out['status'] = 'pending';
            return $out;
        } catch (\Throwable $e) {
            $out['status'] = 'unknown';
            $out['error_message'] = $e->getMessage();
            return $out;
        }
    }

    public function handleWebhook(string $rawBody, array $headers): array
    {
        $sig = (string)($headers['stripe-signature'] ?? $headers['Stripe-Signature'] ?? $headers['HTTP_STRIPE_SIGNATURE'] ?? '');
        $secret = (string)setting_get('gw_card_webhook_secret', '');
        if ($secret === '' || !function_exists('sw_verify_stripe_signature') || !sw_verify_stripe_signature($rawBody, $sig, $secret)) {
            return ['ok' => false, 'reason' => 'invalid_signature'];
        }
        $event = json_decode($rawBody, true);
        if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
            return ['ok' => false, 'reason' => 'malformed'];
        }
        return [
            'ok'         => true,
            'event_id'   => (string)$event['id'],
            'event_type' => (string)$event['type'],
            'payload'    => $event,
        ];
    }

    public function parseLastError(array $raw): array
    {
        // $raw is a PaymentIntent or Session (which may nest 'last_payment_error').
        $lpe = $raw['last_payment_error']
            ?? ($raw['_pi']['last_payment_error'] ?? null);
        $code = '';
        $msg  = '';
        if (is_array($lpe)) {
            $code = (string)($lpe['code'] ?? ($lpe['decline_code'] ?? ($lpe['type'] ?? '')));
            $msg  = (string)($lpe['message'] ?? '');
        }
        return [
            'code'    => $code,
            'message' => $msg !== '' ? $msg : mv_humanize_stripe_error($code),
        ];
    }
}

/**
 * Human-readable mapping for the most common Stripe error codes.
 * Falls back to a friendly generic when the code is unknown.
 * Kept as a plain function so admin.php "Recent failures" can reuse it.
 */
function mv_humanize_stripe_error(string $code): string
{
    $code = strtolower(trim($code));
    $map = [
        'card_declined'          => 'Your card was declined by your bank.',
        'generic_decline'        => 'Your card was declined by your bank.',
        'insufficient_funds'     => 'Insufficient funds on the card.',
        'lost_card'              => 'The card was reported lost. Please use another card.',
        'stolen_card'            => 'The card was reported stolen. Please use another card.',
        'expired_card'           => 'Your card has expired.',
        'incorrect_cvc'          => 'The CVC / CVV security code is incorrect.',
        'cvc_check_failed'       => 'The CVC / CVV security code check failed.',
        'incorrect_number'       => 'The card number is invalid.',
        'invalid_expiry_month'   => 'The card expiration month is invalid.',
        'invalid_expiry_year'    => 'The card expiration year is invalid.',
        'processing_error'       => 'The bank could not process the payment. Please try again.',
        'authentication_required'=> 'Your bank requires additional authentication (3-D Secure). Please try again and complete the challenge.',
        'authentication_failed'  => 'Bank authentication (3-D Secure) failed. Please try again.',
        'do_not_honor'           => 'Your bank declined the transaction (do-not-honor). Contact your bank or try a different card.',
        'try_again_later'        => 'Temporary bank issue — please try again in a moment.',
        'currency_not_supported' => 'Your card does not support this currency.',
        'fraudulent'             => 'The payment was flagged as suspicious and declined.',
    ];
    if (isset($map[$code])) return $map[$code];
    return 'Payment could not be completed. Please try a different card or contact your bank.';
}

/**
 * Stripe Checkout session with recovery-aware cancel_url.
 * On decline / cancel Stripe redirects to /checkout.php?cancel=1&session_id={CHECKOUT_SESSION_ID}
 * so we can look up the exact last_payment_error and render the inline banner.
 */
function stripe_create_session_with_recovery(array $order, string $baseUrl): array
{
    $cents = (int)round((float)$order['total'] * 100);
    $mode  = stripe_active_mode();
    $label = ($mode === 'test' ? '[TEST] ' : '')
           . 'Order #' . $order['order_number'] . ' — ' . (defined('SITE_LEGAL') ? SITE_LEGAL : 'Order');
    return stripe_request('POST', 'checkout/sessions', [
        'mode' => 'payment',
        'line_items[0][price_data][currency]' => 'usd',
        'line_items[0][price_data][product_data][name]' => $label,
        'line_items[0][price_data][unit_amount]' => $cents,
        'line_items[0][quantity]' => 1,
        'customer_email' => $order['email'],
        'metadata[order_number]' => $order['order_number'],
        'metadata[gw_mode]' => $mode,
        // Include session_id on BOTH success and cancel so we can always
        // look up the definitive server-side status.
        'success_url' => $baseUrl . 'order-success.php?order=' . urlencode($order['order_number']) . '&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => $baseUrl . 'checkout.php?cancel=1&session_id={CHECKOUT_SESSION_ID}',
    ]);
}

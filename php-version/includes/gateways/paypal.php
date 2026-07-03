<?php
/**
 * MvPayPalGateway — pluggable stub.
 *
 * PayPal is currently exposed as a payment_method toggle in the checkout
 * form + admin gateway toggles, but the actual funds flow still routes
 * through Stripe (which supports PayPal-as-source in many regions).
 *
 * The public-facing methods below are wired to the same helpers Stripe
 * uses so the store keeps working end-to-end.  A future patch swapping
 * this for a real PayPal Orders v2 integration only touches this file.
 */
require_once __DIR__ . '/interface.php';
require_once __DIR__ . '/stripe.php';

final class MvPayPalGateway implements MvPaymentGateway
{
    public function label(): string { return 'PayPal'; }
    public function slug(): string  { return 'paypal'; }

    public function isConfigured(): bool
    {
        // Reuse Stripe rails for now; real PayPal wire-up would check
        // gw_paypal_client_id / gw_paypal_secret_* here.
        return function_exists('paypal_enabled') ? paypal_enabled() : false;
    }

    public function createSession(array $order, string $baseUrl): array
    {
        $g = new MvStripeGateway();
        return $g->createSession($order, $baseUrl);
    }

    public function verifyPayment(string $sessionOrIntentId): array
    {
        $g = new MvStripeGateway();
        return $g->verifyPayment($sessionOrIntentId);
    }

    public function handleWebhook(string $rawBody, array $headers): array
    {
        // Placeholder — real PayPal uses HMAC-SHA256 with the paypal-transmission-*
        // headers.  Left as an explicit failure so we never trust unsigned events.
        return ['ok' => false, 'reason' => 'paypal_webhook_not_configured'];
    }

    public function parseLastError(array $raw): array
    {
        $msg = (string)($raw['message'] ?? ($raw['error']['message'] ?? 'PayPal declined the payment.'));
        return [
            'code'    => (string)($raw['name'] ?? $raw['error']['code'] ?? 'paypal_declined'),
            'message' => $msg,
        ];
    }
}

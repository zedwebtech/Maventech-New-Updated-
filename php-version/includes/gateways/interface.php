<?php
/**
 * MvPaymentGateway — pluggable payment-gateway interface.
 *
 * Every payment provider (Stripe, PayPal, NMI, Authorize.net) implements
 * these four methods so the checkout flow, webhook handler, and admin UI
 * never have to branch on gateway name.  Adding a new gateway is:
 *   1. Drop a new adapter in /includes/gateways/<name>.php implementing
 *      this interface.
 *   2. Register it in factory.php mv_gateway_registry().
 *   3. Add its enable/config settings to Admin → API / Payment Gateway.
 *
 * IMPORTANT contract for all adapters:
 *   · No license key must ever be released until verify_payment() has
 *     returned status='succeeded'.  This is enforced upstream in
 *     fulfill_order() but adapters MUST NOT lie.
 *   · handle_webhook() must strictly verify the provider's signature
 *     against the raw request body BEFORE trusting anything in it.
 *   · parse_last_error() returns human-readable error info so the
 *     checkout page can render an inline banner with the real reason.
 */
interface MvPaymentGateway
{
    /** Human-friendly name shown to admins ("Stripe", "PayPal", …). */
    public function label(): string;

    /** Machine slug ("stripe", "paypal", "nmi", "authnet"). */
    public function slug(): string;

    /** True if this gateway is fully configured (has keys) for the active mode. */
    public function isConfigured(): bool;

    /**
     * Create a payment session/intent for an order.
     * Returns an assoc array with at least:
     *   · redirect_url  (string, empty when embedded)
     *   · session_id    (string, provider-specific id)
     *   · client_secret (string, optional for embedded flows)
     * Throws \RuntimeException on failure.
     */
    public function createSession(array $order, string $baseUrl): array;

    /**
     * Verify the true payment state for a session/intent by calling the
     * provider's API.  Returns:
     *   [
     *     'status'         => 'succeeded' | 'failed' | 'pending' | 'unknown',
     *     'error_code'     => string,   // machine code when failed
     *     'error_message'  => string,   // human-readable reason
     *     'transaction_id' => string,   // provider txn id
     *     'raw'            => array,    // provider payload for logging
     *   ]
     */
    public function verifyPayment(string $sessionOrIntentId): array;

    /**
     * Handle an incoming webhook.  Adapters must:
     *   1. Verify signature against $rawBody + provider secret.
     *   2. Return an assoc array {ok:bool, event_id, event_type, order_ref}
     *      so the router can route succeeded/failed events uniformly.
     * NEVER trust webhook payload without signature verification.
     */
    public function handleWebhook(string $rawBody, array $headers): array;

    /**
     * Turn a raw provider error blob into a normalised human message.
     * Returns ['code'=>string, 'message'=>string].
     */
    public function parseLastError(array $raw): array;
}

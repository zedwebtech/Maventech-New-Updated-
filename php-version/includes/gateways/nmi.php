<?php
/**
 * MvNmiGateway — architected stub only.
 *
 * When wire-up is requested, an admin will need to paste:
 *   · Security Key (Merchant Portal → Options → Security Keys)
 *   · Optional public API key for hosted collect.
 * Stored under settings: gw_nmi_security_key_(test|live).
 *
 * Endpoint: https://secure.nmi.com/api/transact.php (Direct Post).
 * All methods throw NotConfigured until the wire-up patch lands.
 */
require_once __DIR__ . '/interface.php';

final class MvNmiGateway implements MvPaymentGateway
{
    public function label(): string { return 'NMI'; }
    public function slug(): string  { return 'nmi'; }
    public function isConfigured(): bool { return trim((string)setting_get('gw_nmi_security_key_' . (setting_get('gw_mode', 'test') === 'live' ? 'live' : 'test'), '')) !== ''; }

    public function createSession(array $order, string $baseUrl): array
    { throw new \RuntimeException('NMI adapter architected but not wired — configure Admin → API / Payment Gateway → NMI to enable.'); }

    public function verifyPayment(string $sessionOrIntentId): array
    { return ['status'=>'unknown','error_code'=>'nmi_not_wired','error_message'=>'NMI adapter architected but not wired.','transaction_id'=>'','raw'=>[]]; }

    public function handleWebhook(string $rawBody, array $headers): array
    { return ['ok' => false, 'reason' => 'nmi_not_wired']; }

    public function parseLastError(array $raw): array
    { return ['code' => (string)($raw['responsetext'] ?? 'nmi_declined'), 'message' => (string)($raw['responsetext'] ?? 'NMI declined the payment.')]; }
}

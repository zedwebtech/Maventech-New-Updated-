<?php
/**
 * MvAuthnetGateway — architected stub only.
 *
 * When wire-up is requested, an admin will need to paste:
 *   · API Login ID  (Authorize.net → Account → API credentials)
 *   · Transaction Key
 *   · Optional signature key for webhook verification.
 * Stored under settings: gw_authnet_login_(test|live), gw_authnet_txn_key_(test|live),
 * gw_authnet_signature_(test|live).
 */
require_once __DIR__ . '/interface.php';

final class MvAuthnetGateway implements MvPaymentGateway
{
    public function label(): string { return 'Authorize.net'; }
    public function slug(): string  { return 'authnet'; }
    public function isConfigured(): bool
    {
        $mode = setting_get('gw_mode', 'test') === 'live' ? 'live' : 'test';
        return trim((string)setting_get('gw_authnet_login_' . $mode, '')) !== ''
            && trim((string)setting_get('gw_authnet_txn_key_' . $mode, '')) !== '';
    }

    public function createSession(array $order, string $baseUrl): array
    { throw new \RuntimeException('Authorize.net adapter architected but not wired — configure Admin → API / Payment Gateway → Authorize.net to enable.'); }

    public function verifyPayment(string $sessionOrIntentId): array
    { return ['status'=>'unknown','error_code'=>'authnet_not_wired','error_message'=>'Authorize.net adapter architected but not wired.','transaction_id'=>'','raw'=>[]]; }

    public function handleWebhook(string $rawBody, array $headers): array
    { return ['ok' => false, 'reason' => 'authnet_not_wired']; }

    public function parseLastError(array $raw): array
    { return ['code' => (string)($raw['errorCode'] ?? 'authnet_declined'), 'message' => (string)($raw['errorText'] ?? 'Authorize.net declined the payment.')]; }
}

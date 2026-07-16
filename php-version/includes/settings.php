<?php
// Settings helpers — used by admin template editor + email sender + checkout.
function setting_get(string $key, string $default = '', bool $raw = false): string {
    static $cache = null;
    static $reqMarker = null;
    // Detect a new HTTP request — PHP-CLI workers reuse the same process so
    // we can't rely on a static initialiser firing per-request.  REQUEST_TIME
    // is constant within a request and changes between them.
    $cur = $_SERVER['REQUEST_TIME_FLOAT'] ?? 0;
    if ($reqMarker !== $cur) { $cache = null; $reqMarker = $cur; }
    if ($cache === null) {
        try {
            $rows = db()->query('SELECT k,v FROM settings')->fetchAll();
            $cache = [];
            foreach ($rows as $r) $cache[$r['k']] = $r['v'];
        } catch (Throwable $e) { $cache = []; }
    }
    if ($key === '__flush__') { $cache = null; return ''; }
    $val = $cache[$key] ?? $default;
    // Raw read: exact stored value, no preview-host rewrite (used when building
    // absolute email/PDF URLs from CLI where the rewrite would strip the host).
    if ($raw) return (string)$val;

    // Auto-heal stale Emergent preview URLs that were saved while the
    // codebase was being built on Emergent. After upload to a real domain
    // (e.g. maventechsoftware.com), absolute URLs cached in settings like
    // `main_url`, `site_domain_url` and `company_logo` would otherwise leak
    // the dev hostname into the production site — QR codes on receipts,
    // <img src> for the logo, every absolute link in emails, etc. We swap
    // the host transparently on read so the operator doesn't need to touch
    // phpMyAdmin to clean things up.
    //
    // Only applies to URL-shaped settings; non-URL fields are untouched.
    // Only rewrites when the CURRENT request is on a non-preview host
    // (so previews keep working while you're building).
    static $urlKeys = [
        'main_url' => 1, 'site_domain_url' => 1, 'company_logo' => 1,
        'site_url' => 1, 'public_url' => 1, 'base_url' => 1,
    ];
    if (isset($urlKeys[$key]) && is_string($val) && $val !== ''
        && function_exists('to_public_url')
        && preg_match('~^https?://[^/]*\.(?:preview\.emergentagent\.com|preview\.emergentcf\.cloud|emergent\.host)\b~i', $val)) {
        $rewritten = to_public_url($val);
        if ($rewritten !== null && $rewritten !== $val) return $rewritten;
    }
    return $val;
}

function setting_set(string $key, string $val): void {
    // Belt-and-suspenders: if we're saving a URL-shaped setting on a real
    // production host (not a preview), strip any embedded preview hostnames
    // before writing so a stale preview URL never lands in the DB even when
    // the admin pastes one by mistake.  Read-time auto-heal still applies
    // as a safety net.
    static $urlKeys = [
        'main_url' => 1, 'site_domain_url' => 1, 'company_logo' => 1,
        'site_url' => 1, 'public_url' => 1, 'base_url' => 1,
    ];
    if (isset($urlKeys[$key]) && PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        $hostNow = strtolower((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST']));
        $hostNow = trim(explode(',', $hostNow)[0]);
        $isPreviewNow = (bool)preg_match(
            '~(?:^|\.)(?:preview\.emergentagent\.com|preview\.emergentcf\.cloud|emergent\.host)$~i',
            $hostNow
        );
        if (!$isPreviewNow && function_exists('to_public_url')) {
            $clean = to_public_url($val);
            if ($clean !== null && $clean !== $val) $val = $clean;
        }
    }
    db()->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$key,$val]);
    // Drop the in-process static cache so the next setting_get() re-reads
    // the freshly-written row.  Critical for PHP-CLI persistent workers.
    setting_get('__flush__');
}

function paypal_enabled(): bool {
    // PayPal is shown when the admin toggles it ON in API Management → Update
    // Gateway. `gw_paypal_status` is the single source of truth (matches the
    // pattern used by `card_enabled()`). The legacy `paypal_enabled=1` flag is
    // still honored for backwards compatibility.
    if (setting_get('gw_paypal_status', 'inactive') === 'active') return true;
    return setting_get('paypal_enabled', '0') === '1';
}

function card_enabled(): bool {
    // Card checkout is ON unless the admin explicitly switches the API → Card
    // gateway to "inactive". `gw_card_status` is the source of truth set by the
    // Admin → API Management → Card form.
    return setting_get('gw_card_status', 'active') === 'active';
}

/**
 * The active card gateway PROVIDER the admin selected in
 * Admin → API / Payment Gateway → Card ("Choose Card Gateway Provider").
 * One of: stripe | authnet | nmi | custom.  This is the gateway that
 * actually PROCESSES card charges — selecting it activates it.
 */
function card_active_provider(): string {
    $p = strtolower(trim(setting_get('gw_card_provider_type', 'stripe')));
    return in_array($p, ['stripe','authnet','nmi','custom'], true) ? $p : 'stripe';
}

/**
 * True when a SPECIFIC provider has the credentials it needs for the given
 * mode ('test' or 'live').  Used both by checkout (to decide whether to
 * process a real charge) and by the admin UI (to render a "Configured ✓"
 * badge for whichever gateway the operator selected).
 */
function card_provider_configured(string $provider, string $mode): bool {
    $mode = ($mode === 'live') ? 'live' : 'test';
    switch ($provider) {
        case 'stripe':
            // A mode-specific secret key, a legacy single key, or the env key.
            $k = trim(setting_get('gw_card_secret_key_' . $mode, ''))
               ?: trim(setting_get('gw_card_secret_key', ''))
               ?: (string)(defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '');
            return $k !== '';
        case 'nmi':
            return trim(setting_get('gw_nmi_security_key_' . $mode, '')) !== '';
        case 'authnet':
            return trim(setting_get('gw_authnet_login_id_' . $mode, '')) !== ''
                && trim(setting_get('gw_authnet_transaction_key_' . $mode, '')) !== '';
        case 'custom':
            return trim(setting_get('gw_custom_endpoint_' . $mode, '')) !== ''
                && trim(setting_get('gw_custom_api_key_' . $mode, '')) !== '';
        default:
            return false;
    }
}

/**
 * True when the ACTIVE card gateway is fully configured for the CURRENT
 * gateway mode (gw_mode).  This is the provider-aware replacement for the
 * old Stripe-only `stripe_enabled()` check used across checkout, the admin
 * panel and the go-live health check.
 */
function card_gateway_configured(): bool {
    $mode = setting_get('gw_mode', 'test') === 'live' ? 'live' : 'test';
    return card_provider_configured(card_active_provider(), $mode);
}

function statement_name_for(string $payment_method): string {
    // Source of truth for company / merchant name = API Management section
    // (gw_card_merchant_name / gw_paypal_account_name). Falls back to the
    // legacy Settings-tab keys, then to SITE_LEGAL.
    if ($payment_method === 'paypal') {
        $v = setting_get('gw_paypal_account_name', '');
        if ($v === '') $v = setting_get('statement_name_paypal', '');
        return $v !== '' ? $v : SITE_LEGAL;
    }
    $v = setting_get('gw_card_merchant_name', '');
    if ($v === '') $v = setting_get('statement_name_card', '');
    return $v !== '' ? $v : SITE_LEGAL;
}

/**
 * Single source of truth for company branding shown across emails.
 * Reads from the Dashboard → "Company Info" card; falls back to the
 * SITE_BRAND / SITE_EMAIL / SITE_PHONE constants when not customised.
 */
/**
 * Normalise a stored company-logo value to a host-relative path so it always
 * resolves on the current request host (preview, staging or production).
 * Legacy values saved as absolute preview/staging URLs are healed by stripping
 * everything up to and including the host. External logo URLs (a different
 * domain / CDN) and data URIs are left untouched.
 */
function normalize_company_logo(string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    if (stripos($v, 'data:') === 0) return $v;
    if (preg_match('#^(https?:)?//#i', $v)) {
        // Our own uploaded logo saved with a stale host → keep the relative part.
        if (preg_match('#/(uploads/[^\s"\']+)$#i', $v, $m)) return $m[1];
        return $v; // genuinely external logo URL
    }
    return ltrim($v, '/');
}

function company_info(): array {
    // Trading brand (visible in nav/logo/header) vs. legal entity (footer
    // copyright, printable Receipt/Invoice PDFs, court-of-law-safe blocks)
    // are separate concerns.  `legal_name` defaults to `company_name` + " LLC"
    // when unset, so existing installs continue to render sensibly without
    // requiring the admin to touch the field.
    $trading      = setting_get('company_name', defined('SITE_BRAND') ? SITE_BRAND : '');
    $legalDefault = $trading !== '' ? ($trading . ' LLC') : '';
    return [
        'name'       => $trading,
        'legal_name' => setting_get('company_legal_name', $legalDefault),
        'email'      => setting_get('company_email',   defined('SITE_EMAIL') ? SITE_EMAIL : ''),
        'phone'      => setting_get('company_phone',   defined('SITE_PHONE') ? SITE_PHONE : ''),
        'address'    => setting_get('company_address', ''),
        // Official corporate registration number (e.g. California LLC file no.)
        // shown in the footer + About Us for transparency / Google Ads trust.
        // Hard-coded fallback so the block renders on cPanel/shared hosts where
        // the DB seed script never ran (only Emergent-pod start.sh runs it).
        'reg_number'      => setting_get('company_reg_number', '202463711253'),
        // Date the LLC was filed with the Secretary of State (ISO YYYY-MM-DD).
        'reg_date_filed'  => setting_get('company_reg_date_filed', '2024-09-03'),
        // Path (or URL) to the LLC Articles of Organization certificate PDF —
        // rendered as a "View certificate" link on About Us / checkout.
        'reg_certificate_url' => setting_get('company_reg_certificate_url', '/uploads/legal/maventech-articles-certificate.pdf'),
        // Human-readable jurisdiction ("California, USA", "Delaware, USA" etc.)
        'reg_jurisdiction'    => setting_get('company_reg_jurisdiction', 'California, USA'),
        'logo'       => normalize_company_logo(setting_get('company_logo', '')),
        // Prefix for generated subscription customer IDs (e.g. MVN → MVNUS00001).
        'id_prefix' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', setting_get('company_id_prefix', 'MVN')) ?: 'MVN'),
        // Social profile URLs — pulled by header.php into the Organization
        // JSON-LD `sameAs` array.  Any non-empty value here replaces the
        // /about-us.php fallback.  Falsy values (empty string) get filtered
        // out by header.php's array_filter() chain before serialisation.
        'twitter'   => setting_get('twitter',   ''),
        'facebook'  => setting_get('facebook',  ''),
        'linkedin'  => setting_get('linkedin',  ''),
        'instagram' => setting_get('instagram', ''),
    ];
}

// --- Legacy SITE_* constants resolved from admin → Company Info ---------------
// Defined here (not in config.php) so the DB-backed settings are available.
// Any change to Company Info in the admin panel now propagates to every place
// that still references these constants (footer, contact, emails, PDFs, schema).
if (!defined('SITE_PHONE')) {
    $__ci = company_info();
    define('SITE_PHONE',   $__ci['phone']   !== '' ? $__ci['phone']   : '1-805-823-9961');
    define('SITE_EMAIL',   $__ci['email']   !== '' ? $__ci['email']   : 'services@maventechsoftware.com');
    define('SITE_ADDRESS', $__ci['address'] !== '' ? $__ci['address'] : '135 Carolina St G2, Vallejo, CA 94590, USA');
    define('SITE_HOURS',   setting_get('company_hours', 'Mon-Sat, 9 AM - 6 PM EST'));
    unset($__ci);
}

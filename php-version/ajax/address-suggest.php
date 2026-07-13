<?php
/*
 * /ajax/address-suggest.php — proxied Nominatim (OpenStreetMap) address
 * autocomplete for the checkout form.
 *
 *   POST/GET { q, country }   → JSON { ok, suggestions: [ { display, address:{road, house_number, city, state, postcode, country_code}, ... } ] }
 *
 * We proxy through the server (rather than calling Nominatim directly from
 * the browser) so we can:
 *   • Set a distinct, contactable User-Agent header (required by Nominatim's
 *     usage policy — anonymous browser requests would be rate-limited hard).
 *   • Cache responses in `address_suggest_cache` for 24 h so repeated queries
 *     from the same country don't hammer the free public server.
 *   • Rate-limit each source IP to 6 req / 10 s (nice, but responsive).
 */
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$q       = trim((string)($_REQUEST['q'] ?? ''));
$country = strtolower(trim((string)($_REQUEST['country'] ?? '')));
// Map internal "UK" region code → ISO2 "gb" for Nominatim.
$countryMap = ['uk' => 'gb', 'us' => 'us', 'ca' => 'ca', 'au' => 'au'];
if (isset($countryMap[$country])) $country = $countryMap[$country];
if (strlen($country) !== 2) $country = '';

if (mb_strlen($q, 'UTF-8') < 3) {
    echo json_encode(['ok' => true, 'suggestions' => []]);
    exit;
}
if (mb_strlen($q, 'UTF-8') > 120) {
    echo json_encode(['ok' => false, 'error' => 'Query too long.']);
    exit;
}

$pdo = db();

// Bootstrap cache table on first hit — schema-lite so we don't touch the
// central migration file.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS address_suggest_cache (
        cache_key VARCHAR(96) PRIMARY KEY,
        payload   LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS address_suggest_rate (
        ip VARCHAR(64) NOT NULL,
        ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (ip, ts)
    ) DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { /* best-effort */ }

// Per-IP rate limit: 6 hits / 10 s.
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
try {
    $rl = $pdo->prepare("SELECT COUNT(*) FROM address_suggest_rate WHERE ip=? AND ts > (NOW() - INTERVAL 10 SECOND)");
    $rl->execute([$ip]);
    if ((int)$rl->fetchColumn() >= 6) {
        echo json_encode(['ok' => false, 'error' => 'Too many requests — try again in a moment.']);
        exit;
    }
    $pdo->prepare("INSERT INTO address_suggest_rate (ip) VALUES (?)")->execute([$ip]);
    // Opportunistic cleanup — drop old rate rows once in a while.
    if (random_int(1, 40) === 1) $pdo->exec("DELETE FROM address_suggest_rate WHERE ts < (NOW() - INTERVAL 5 MINUTE)");
} catch (Throwable $e) {}

// 24h cache lookup (case-insensitive, country-scoped).
$cacheKey = 'v1:' . strtolower($q) . '|' . $country;
try {
    $cs = $pdo->prepare("SELECT payload FROM address_suggest_cache WHERE cache_key=? AND created_at > (NOW() - INTERVAL 24 HOUR)");
    $cs->execute([$cacheKey]);
    if ($row = $cs->fetch(PDO::FETCH_ASSOC)) {
        echo $row['payload'];
        exit;
    }
} catch (Throwable $e) {}

$params = [
    'q'              => $q,
    'format'         => 'jsonv2',
    'addressdetails' => 1,
    'limit'          => 6,
    'accept-language' => 'en',
];
if ($country !== '') $params['countrycodes'] = $country;
$url = 'https://nominatim.openstreetmap.org/search?' . http_build_query($params);

$ch = curl_init($url);
$brand = defined('SITE_BRAND') ? SITE_BRAND : 'Maventech';
$email = function_exists('company_info') ? (company_info()['email'] ?? 'ops@maventechsoftware.com') : 'ops@maventechsoftware.com';
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_HTTPHEADER     => [
        'User-Agent: ' . $brand . 'Checkout/1.0 (' . $email . ')',
        'Accept: application/json',
    ],
]);
$raw    = curl_exec($ch);
$httpRc = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$err    = curl_error($ch);
curl_close($ch);

if ($err || $httpRc >= 400 || !$raw) {
    @error_log('[address-suggest] Nominatim call failed: ' . ($err ?: ('HTTP ' . $httpRc)));
    echo json_encode(['ok' => false, 'error' => "Address service isn't available right now — please type the address by hand."]);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    echo json_encode(['ok' => true, 'suggestions' => []]);
    exit;
}

$out = [];
foreach ($data as $r) {
    $addr = (array)($r['address'] ?? []);
    // Build the line-1 street address from Nominatim's inconsistent fields.
    $house = trim((string)($addr['house_number'] ?? ''));
    $road  = trim((string)($addr['road'] ?? $addr['pedestrian'] ?? $addr['footway'] ?? $addr['path'] ?? ''));
    $city  = trim((string)($addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['hamlet'] ?? $addr['municipality'] ?? $addr['suburb'] ?? ''));
    $state = trim((string)($addr['state'] ?? $addr['province'] ?? $addr['region'] ?? ''));
    // Nominatim also carries the ISO 3166-2 subdivision code (e.g. "US-CA") — use it
    // as the definitive state code so the checkout picker maps cleanly.
    $stateCode = '';
    if (!empty($addr['ISO3166-2-lvl4'])) $stateCode = strtoupper(substr((string)$addr['ISO3166-2-lvl4'], strrpos((string)$addr['ISO3166-2-lvl4'], '-') + 1));
    $postcode  = trim((string)($addr['postcode'] ?? ''));
    $ccode     = strtoupper(trim((string)($addr['country_code'] ?? '')));
    // UK → the storefront uses "UK", not "GB".
    if ($ccode === 'GB') $ccode = 'UK';

    $line1 = trim($house . ' ' . $road);
    if ($line1 === '' && !empty($r['display_name'])) $line1 = trim(explode(',', (string)$r['display_name'])[0]);
    if ($line1 === '') continue;

    $out[] = [
        'display'    => (string)($r['display_name'] ?? ''),
        'line1'      => $line1,
        'city'       => $city,
        'state'      => $state,
        'state_code' => $stateCode,
        'postcode'   => $postcode,
        'country'    => $ccode,
    ];
}

$json = json_encode(['ok' => true, 'suggestions' => $out]);
try {
    $pdo->prepare("REPLACE INTO address_suggest_cache (cache_key, payload) VALUES (?, ?)")->execute([$cacheKey, $json]);
} catch (Throwable $e) {}

echo $json;

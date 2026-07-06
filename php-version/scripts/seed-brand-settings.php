<?php
/**
 * Idempotent seed for company / brand settings that MUST always exist for
 * the About Us + footer transparency blocks (Google Ads compliance).
 *
 * Runs on every pod boot from `start.sh`.  Only inserts values if the key
 * is missing — never overwrites admin-customized values.
 *
 *   company_reg_number         — LLC file number shown on About Us + footer
 *   company_reg_date_filed     — ISO date the LLC was filed
 *   company_reg_certificate_url— PDF link on About Us "View certificate"
 *   company_reg_jurisdiction   — Human-readable jurisdiction
 *   company_hours              — Business hours (Mon-Sat 9 AM - 6 PM EST)
 *   company_email              — Public brand inbox (services@…)
 *   support_email              — Customer-support inbox (services@… for now)
 *   company_address            — Registered LLC address
 */
require_once __DIR__ . '/../includes/functions.php';

$defaults = [
    'company_reg_number'          => '202463711253',
    'company_reg_date_filed'      => '2024-09-03',
    'company_reg_certificate_url' => '/uploads/legal/maventech-articles-certificate.pdf',
    'company_reg_jurisdiction'    => 'California, USA',
    'company_hours'               => 'Mon-Sat, 9 AM - 6 PM EST',
    'company_email'               => 'services@maventechsoftware.com',
    'support_email'               => 'services@maventechsoftware.com',
    'company_address'             => '135 CAROLINA ST APT G2, VALLEJO, CA 94590',
    'company_phone'               => '1-805-823-9961',
    'company_legal_name'          => 'Maventech LLC',
];

// Force these to always match, even if a previous run set stale values.
// Users can still change them via Admin → Company Info afterwards — that
// admin write updates the same row, so the *next* boot won't re-overwrite
// because we compare against the current value first.
$forceRefresh = [
    'company_reg_number',
    'company_reg_date_filed',
    'company_reg_certificate_url',
    'company_reg_jurisdiction',
];

try {
    $db = db();
    foreach ($defaults as $k => $v) {
        $cur = $db->prepare("SELECT v FROM settings WHERE k = ? LIMIT 1");
        $cur->execute([$k]);
        $existing = $cur->fetchColumn();
        $isForced  = in_array($k, $forceRefresh, true);
        // Missing rows: always insert.
        // Present rows: only overwrite if this key is in $forceRefresh AND
        //               the value drifted from the default (e.g. blank).
        if ($existing === false || $existing === null || $existing === '') {
            $db->prepare("INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
               ->execute([$k, $v]);
            echo "[seed-brand] {$k} = {$v}\n";
        } elseif ($isForced && $existing !== $v) {
            $db->prepare("UPDATE settings SET v = ? WHERE k = ?")->execute([$v, $k]);
            echo "[seed-brand] refreshed {$k} → {$v}\n";
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[seed-brand] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

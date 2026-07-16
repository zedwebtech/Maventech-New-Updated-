<?php
/**
 * scripts/set-official-product-links.php
 *
 * Standardise every product's DOWNLOAD (installer_url) and ACTIVATION
 * (activation_url) links to the official Microsoft / vendor destinations
 * that gosoftwarebuy uses ("download directly from Microsoft"), instead of
 * third-party mirrors. Idempotent — safe to run on every deploy (called from
 * start.sh AFTER seed-manual-urls.php so it wins).
 *
 * Rules:
 *   Office family (Office suites, Word/Excel/PowerPoint/Outlook/Access/
 *   Publisher, Project, Visio — PC & Mac) -> setup.office.com for BOTH
 *   download & activation (Microsoft's own redeem+download portal).
 *   Windows 11 -> download microsoft.com/software-download/windows11,
 *                 activate account.microsoft.com
 *   Windows 10 -> download microsoft.com/software-download/windows10,
 *                 activate account.microsoft.com
 *   Bitdefender -> central.bitdefender.com
 *   McAfee      -> mcafee.com/activate
 * The per-product install GUIDE (install_guide_url) is left untouched.
 */
require_once __DIR__ . '/../includes/functions.php';

$SETUP   = 'https://setup.office.com';
$MSACCT  = 'https://account.microsoft.com';
$WIN11   = 'https://www.microsoft.com/software-download/windows11';
$WIN10   = 'https://www.microsoft.com/software-download/windows10';
$BITDEF  = 'https://central.bitdefender.com';
$MCAFEE  = 'https://www.mcafee.com/activate';

$pdo = db();
$rows = $pdo->query("SELECT id, slug, name, brand, category, platform FROM products")->fetchAll(PDO::FETCH_ASSOC);

$upd = $pdo->prepare(
    "UPDATE products SET installer_url = ?, activation_url = ?, install_url_mode = 'manual', activation_url_mode = 'manual' WHERE id = ?"
);

$changed = 0;
foreach ($rows as $r) {
    $cat   = strtolower((string)$r['category']);
    $name  = strtolower((string)$r['name']);
    $brand = strtolower((string)$r['brand']);

    $installer = null; $activation = null;

    if (str_contains($cat, 'windows-11') || str_contains($name, 'windows 11')) {
        $installer = $WIN11;  $activation = $MSACCT;
    } elseif (str_contains($cat, 'windows-10') || str_contains($name, 'windows 10')) {
        $installer = $WIN10;  $activation = $MSACCT;
    } elseif ($brand === 'bitdefender') {
        $installer = $BITDEF; $activation = $BITDEF;
    } elseif ($brand === 'mcafee') {
        $installer = $MCAFEE; $activation = $MCAFEE;
    } elseif ($brand === 'microsoft' || str_starts_with($cat, 'office-')
              || str_contains($cat, 'project') || str_contains($cat, 'visio')
              || preg_match('/\b(office|word|excel|powerpoint|outlook|access|publisher|project|visio|microsoft 365)\b/', $name)) {
        // Office / Project / Visio (PC & Mac) — Microsoft's own portal.
        $installer = $SETUP;  $activation = $SETUP;
    } else {
        continue; // unknown brand — leave links untouched
    }

    $upd->execute([$installer, $activation, $r['id']]);
    $changed++;
    echo sprintf("  [%s] %s -> dl=%s | act=%s\n", $r['brand'], $r['slug'], $installer, $activation);
}

echo "Done. Updated {$changed} products with official download/activation links.\n";

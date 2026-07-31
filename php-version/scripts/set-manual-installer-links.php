<?php
/**
 * scripts/set-manual-installer-links.php
 *
 * Sets each product's DOWNLOAD button (installer_url) to the exact
 * installer URL supplied in the merchant's official product-installer
 * spreadsheet (products_list.docx, July 2026).  Each Microsoft SKU
 * has its own dedicated installer link — Office 2024 Pro Plus points
 * at the c2rsetup CDN, Office 2024 Home/H&B (PC) at std.mscdn2024,
 * Office (Mac) editions at mac.mscdn2024, Office 2021/2019 + Project
 * + Visio at Microsoft's own setup.office.com portal, and Windows
 * 10/11 at Microsoft's own software-download pages.
 *
 * Idempotent — safe to run on every deploy.  Runs from start.sh
 * AFTER set-official-product-links.php so these per-SKU links win
 * (set-official-product-links.php sets a generic fallback per brand;
 * this script overrides it with the exact installer for each SKU).
 *
 * Antivirus SKUs (Bitdefender / McAfee) are intentionally NOT in the
 * map because the Word doc did not provide installer URLs for them —
 * their existing brand-level defaults from set-official-product-links
 * (central.bitdefender.com / mcafee.com/activate) remain in place.
 */
require_once __DIR__ . '/../includes/functions.php';

// Per-SKU installer URLs from the merchant's products_list.docx
// (slug => installer_url). Exact matches only.
$map = [
    // ── Office 2024 Professional Plus (Windows) ──────────────────────
    'microsoft-office-2024-professional-plus-windows'
        => 'https://c2rsetup-officeapps.b-cdn.net/Office%202024%20Pro%20Plus.exe',
    'microsoft-office-2024-professional-plus-lifetime-license-windows-pc'
        => 'https://c2rsetup-officeapps.b-cdn.net/Office%202024%20Pro%20Plus.exe',

    // ── Office 2024 Home / Home & Business (PC) ──────────────────────
    'microsoft-office-home-2024-pc'
        => 'https://std.mscdn2024.com/Office_2024_EN_standard_64Bits.exe',
    'microsoft-office-home-business-2024-pc'
        => 'https://std.mscdn2024.com/Office_2024_EN_standard_64Bits.exe',

    // ── Office 2021 (Windows) — full suites + single-app editions ────
    'microsoft-office-2021-home-business-windows'
        => 'https://setup.office.com/?ms.officeurl=setup',
    'microsoft-office-2021-professional-plus-windows'
        => 'https://setup.office.com/?ms.officeurl=setup',
    'microsoft-office-2021-home-student-windows'
        => 'https://setup.office.com/?ms.officeurl=setup',
    'microsoft-word-2021-windows'
        => 'https://setup.office.com/?ms.officeurl=setup',
    'microsoft-excel-2021-windows'
        => 'https://setup.office.com/?ms.officeurl=setup',

    // ── Office 2019 (Windows) ────────────────────────────────────────
    'microsoft-office-2019-home-student-windows'
        => 'https://setup.office.com/?ms.officeurl=setup',
    'microsoft-office-2019-home-business-pc'
        => 'https://setup.office.com/?ms.officeurl=setup',
    'microsoft-office-2019-professional-plus-windows'
        => 'https://setup.office.com/?ms.officeurl=setup',

    // ── Office 2024 / 2021 (Mac) — including single-app Mac editions ─
    'microsoft-office-home-business-2024-mac'
        => 'https://mac.mscdn2024.com/',
    'microsoft-office-home-2024-mac'
        => 'https://mac.mscdn2024.com/',
    'microsoft-office-2021-home-student-mac'
        => 'https://mac.mscdn2024.com/',
    'microsoft-office-2021-home-business-mac'
        => 'https://mac.mscdn2024.com/',
    'microsoft-word-2021-mac-lifetime-license-no-subscription'
        => 'https://mac.mscdn2024.com/',
    'microsoft-excel-2021-mac-lifetime-license-no-subscription'
        => 'https://mac.mscdn2024.com/',

    // ── Office 2019 (Mac) ────────────────────────────────────────────
    'microsoft-office-home-and-business-2019-mac'
        => 'https://setup.office.com',
    'microsoft-office-home-and-student-2019-mac'
        => 'https://setup.office.com',

    // ── Windows 11 ───────────────────────────────────────────────────
    'windows-11-home'
        => 'https://go.microsoft.com/fwlink/?linkid=2171764',
    'windows-11-pro'
        => 'https://go.microsoft.com/fwlink/?linkid=2171764',

    // ── Windows 10 ───────────────────────────────────────────────────
    'windows-10-home'
        => 'https://www.microsoft.com/en-in/software-download/windows10',
    'windows-10-pro'
        => 'https://www.microsoft.com/en-in/software-download/windows10',

    // ── Microsoft Project (PC) ───────────────────────────────────────
    'microsoft-project-2024-professional-pc'
        => 'https://setup.office.com',
    'microsoft-project-professional-2021-pc'
        => 'https://setup.office.com',
    'ms-project-professional-2019-pc'
        => 'https://setup.office.com',

    // ── Microsoft Visio (PC) ─────────────────────────────────────────
    'microsoft-visio-2024-professional-windows-pc'
        => 'https://setup.office.com',
    'microsoft-visio-2021-professional-windows-pc'
        => 'https://setup.office.com',
    'ms-visio-professional-2019-pc'
        => 'https://setup.office.com',
];

$pdo = db();
$upd = $pdo->prepare(
    "UPDATE products SET installer_url = ?, install_url_mode = 'manual' WHERE slug = ?"
);

$changed = 0;
foreach ($map as $slug => $url) {
    $upd->execute([$url, $slug]);
    if ($upd->rowCount() > 0) {
        $changed++;
        echo sprintf("  %s -> %s\n", $slug, $url);
    }
}

echo "Done. Set per-SKU installer_url on {$changed} matched products.\n";

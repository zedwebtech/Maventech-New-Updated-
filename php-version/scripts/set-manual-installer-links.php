<?php
/**
 * scripts/set-manual-installer-links.php
 *
 * Sets each product's DOWNLOAD button (installer_url) to the matching
 * installation manual / download page on manuals.winandoffice.com.
 *
 * Only EXACT slug->manual matches are applied. Products without an exact
 * manual on the source site (and all antivirus products) are left untouched.
 *
 * Idempotent — safe to run on every deploy. Must run in start.sh AFTER
 * set-official-product-links.php so these manual links win for matched slugs.
 */
require_once __DIR__ . '/../includes/functions.php';

$BASE = 'https://manuals.winandoffice.com/manuals/';

// slug => manuals.winandoffice.com path (exact matches only)
$map = [
    // Office 2024
    'microsoft-office-2024-professional-plus-windows'                    => 'o24pp',
    'microsoft-office-2024-professional-plus-lifetime-license-windows-pc' => 'o24pp',
    // Office 2021 (PC)
    'microsoft-office-2021-professional-plus-windows'                    => 'o21pp',
    'microsoft-office-2021-home-business-windows'                        => 'ms-office-2021-hb-retail',
    'microsoft-office-2021-home-student-windows'                         => 'ms-office-2021-hs-retail',
    // Office 2021 (Mac)
    'microsoft-office-2021-home-business-mac'                            => 'o21hbmac',
    // Office 2019 (PC)
    'microsoft-office-2019-professional-plus-windows'                    => 'o19pp',
    // Office 2019 (Mac)
    'microsoft-office-home-and-business-2019-mac'                        => 'o19hbmac',
    // Project
    'microsoft-project-2024-professional-pc'                             => 'p24p',
    'microsoft-project-professional-2021-pc'                             => 'p21p',
    'ms-project-professional-2019-pc'                                    => 'p19p',
    // Visio
    'microsoft-visio-2024-professional-windows-pc'                       => 'v24p',
    'microsoft-visio-2021-professional-windows-pc'                       => 'v21p',
    'ms-visio-professional-2019-pc'                                      => 'v19p',
    // Windows Desktop
    'windows-11-pro'                                                     => 'w11p',
    'windows-11-home'                                                    => 'w11h',
    'windows-10-pro'                                                     => 'w10p',
    'windows-10-home'                                                    => 'w10h',
];

$pdo = db();
$upd = $pdo->prepare(
    "UPDATE products SET installer_url = ?, install_url_mode = 'manual' WHERE slug = ?"
);

$changed = 0;
foreach ($map as $slug => $path) {
    $url = $BASE . $path;
    $upd->execute([$url, $slug]);
    if ($upd->rowCount() >= 0) {
        $changed++;
        echo sprintf("  %s -> %s\n", $slug, $url);
    }
}

echo "Done. Set manual installer_url on {$changed} matched products.\n";

<?php
/* ============================================================================
 *  Seed per-product Activation / Installation-guide / Installer URLs
 *  sourced from the official manuals site  https://manuals.winandoffice.com
 *
 *  For every catalog product that has a matching (or closest) manual page we
 *  store three links so they show up automatically in:
 *    • the order-delivery email   (build_installation_guide_cards)
 *    • the product page           ("Download, install & activate" block)
 *    • the order-success page     (per-product install buttons)
 *
 *  Each entry sets:
 *    install_guide_url  → the manual page on manuals.winandoffice.com
 *    installer_url      → the one-click English 64-bit installer (when one
 *                         exists; Office-for-Mac is downloaded after sign-in,
 *                         so it stays NULL)
 *    activation_url     → setup.office.com (Office/Project/Visio) or
 *                         account.microsoft.com (Windows)
 *  …and flips activation_url_mode / install_url_mode to 'manual' so the store
 *  uses these exact links instead of the AI-derived fallback.
 *
 *  IDEMPOTENT + NON-DESTRUCTIVE:
 *    A product is only updated while its install_guide_url is still empty.
 *    Once seeded (or once an admin edits it in the panel) re-runs skip it, so
 *    this is safe to call on every boot (wired into start.sh) and never
 *    clobbers a manual admin change.
 *
 *  Antivirus products (Bitdefender, McAfee) are intentionally NOT mapped —
 *  winandoffice.com has no installation manuals for them.
 *
 *  Run:  php /app/php-version/scripts/seed-manual-urls.php
 *  ========================================================================== */
require_once __DIR__ . '/../includes/functions.php';

$MAN = 'https://manuals.winandoffice.com';   // manual-page base
$DL  = 'https://download.winandoffice.com';   // installer CDN base
$OFFICE_ACT  = 'https://setup.office.com';            // MS Office / Project / Visio
$WINDOWS_ACT = 'https://account.microsoft.com/account'; // Windows

/* product slug => [ guide_slug, installer_url|null, activation_url ] */
$MAP = [
    // ── Microsoft Office — PC ────────────────────────────────────────────
    'microsoft-office-2024-professional-plus-windows'
        => ['o24pp', "$DL/Volume/office/2024/EN/Office_2024_EN_64Bits.exe", $OFFICE_ACT],
    'microsoft-office-2024-professional-plus-lifetime-license-windows-pc'
        => ['o24pp', "$DL/Volume/office/2024/EN/Office_2024_EN_64Bits.exe", $OFFICE_ACT],
    'microsoft-office-home-2024-pc'                       // closest: Office 2024 Standard
        => ['o24s', "$DL/Volume/office/2024/EN/Office_2024_EN_standard_64Bits.exe", $OFFICE_ACT],
    'microsoft-office-home-business-2024-pc'              // closest: Office 2024 Standard
        => ['o24s', "$DL/Volume/office/2024/EN/Office_2024_EN_standard_64Bits.exe", $OFFICE_ACT],
    'microsoft-office-2021-home-business-windows'
        => ['ms-office-2021-hb-retail', "$DL/Retail/Office/EN/HomeBusiness2021Retail.iso", $OFFICE_ACT],
    'microsoft-office-2021-professional-plus-windows'
        => ['o21pp', "$DL/Volume/office/2021/EN/Office_2021_EN_64Bits.exe", $OFFICE_ACT],
    'microsoft-office-2021-home-student-windows'
        => ['ms-office-2021-hs-retail', "$DL/Retail/Office/EN/HomeStudent2021Retail.iso", $OFFICE_ACT],
    'microsoft-word-2021-windows'                         // closest: Office 2021 Pro Plus suite
        => ['o21pp', "$DL/Volume/office/2021/EN/Office_2021_EN_64Bits.exe", $OFFICE_ACT],
    'microsoft-excel-2021-windows'                        // closest: Office 2021 Pro Plus suite
        => ['o21pp', "$DL/Volume/office/2021/EN/Office_2021_EN_64Bits.exe", $OFFICE_ACT],
    'microsoft-office-2019-home-student-windows'          // closest: Office 2019 Standard
        => ['o19s', "$DL/Volume/office/2019/EN/Office_2019_EN_standard_64Bits.exe", $OFFICE_ACT],
    'microsoft-office-2019-home-business-pc'              // closest: Office 2019 Standard
        => ['o19s', "$DL/Volume/office/2019/EN/Office_2019_EN_standard_64Bits.exe", $OFFICE_ACT],
    'microsoft-office-2019-professional-plus-windows'
        => ['o19pp', "$DL/Volume/office/2019/EN/Office_2019_EN_64Bits.exe", $OFFICE_ACT],

    // ── Microsoft Office — Mac (installer obtained after sign-in → no exe) ─
    'microsoft-office-home-business-2024-mac'             // closest Mac guide: 2021 H&B Mac
        => ['o21hbmac', null, $OFFICE_ACT],
    'microsoft-office-home-2024-mac'
        => ['o21hbmac', null, $OFFICE_ACT],
    'microsoft-office-2021-home-student-mac'
        => ['o21hbmac', null, $OFFICE_ACT],
    'microsoft-office-2021-home-business-mac'
        => ['o21hbmac', null, $OFFICE_ACT],
    'microsoft-word-2021-mac-lifetime-license-no-subscription'
        => ['o21hbmac', null, $OFFICE_ACT],
    'microsoft-excel-2021-mac-lifetime-license-no-subscription'
        => ['o21hbmac', null, $OFFICE_ACT],
    'microsoft-office-home-and-business-2019-mac'
        => ['o19hbmac', null, $OFFICE_ACT],
    'microsoft-office-home-and-student-2019-mac'          // closest Mac guide: 2019 H&B Mac
        => ['o19hbmac', null, $OFFICE_ACT],

    // ── Windows Desktop (one-click Media Creation Tool) ──────────────────
    'windows-11-home' => ['w11h', "$DL/Retail/Desktop/MediaCreationTool.exe",     $WINDOWS_ACT],
    'windows-11-pro'  => ['w11p', "$DL/Retail/Desktop/MediaCreationTool.exe",     $WINDOWS_ACT],
    'windows-10-home' => ['w10h', "$DL/Retail/Desktop/MediaCreationTool22H2.exe", $WINDOWS_ACT],
    'windows-10-pro'  => ['w10p', "$DL/Retail/Desktop/MediaCreationTool22H2.exe", $WINDOWS_ACT],

    // ── Microsoft Project ────────────────────────────────────────────────
    'microsoft-project-2024-professional-pc'
        => ['p24p', "$DL/Volume/project/2024/EN/project_2024_EN_64Bits.exe", $OFFICE_ACT],
    'microsoft-project-professional-2021-pc'
        => ['p21p', "$DL/Volume/project/2021/EN/project_2021_EN_64Bits.exe", $OFFICE_ACT],
    'ms-project-professional-2019-pc'
        => ['p19p', "$DL/Volume/project/2019/EN/project_2019_EN_64Bits.exe", $OFFICE_ACT],

    // ── Microsoft Visio ──────────────────────────────────────────────────
    'microsoft-visio-2024-professional-windows-pc'
        => ['v24p', "$DL/Volume/visio/2024/EN/visio_2024_EN_64Bits.exe", $OFFICE_ACT],
    'microsoft-visio-2021-professional-windows-pc'
        => ['v21p', "$DL/Volume/visio/2021/EN/visio_2021_EN_pro_64Bits.exe", $OFFICE_ACT],
    'ms-visio-professional-2019-pc'
        => ['v19p', "$DL/Volume/visio/2019/EN/visio_2019_EN_64Bits.exe", $OFFICE_ACT],
];

$db = db();

// Only seed rows that haven't been given a guide URL yet (NULL/empty) — keeps
// this idempotent and never overwrites a value an admin set in the panel.
$upd = $db->prepare(
    "UPDATE products
        SET install_guide_url   = :guide,
            installer_url        = :installer,
            activation_url       = :activation,
            install_url_mode     = 'manual',
            activation_url_mode  = 'manual'
      WHERE slug = :slug
        AND (install_guide_url IS NULL OR install_guide_url = '')"
);

$seeded = 0; $skipped = 0; $missing = 0;
foreach ($MAP as $slug => [$guideSlug, $installer, $activation]) {
    // Confirm the product exists in this catalog before touching it.
    $exists = $db->prepare('SELECT id FROM products WHERE slug = ? LIMIT 1');
    $exists->execute([$slug]);
    if (!$exists->fetchColumn()) {
        echo sprintf("  [skip] no product row: %s\n", $slug);
        $missing++;
        continue;
    }
    $upd->execute([
        ':guide'      => "$MAN/$guideSlug/",
        ':installer'  => $installer,        // null stays NULL for Mac
        ':activation' => $activation,
        ':slug'       => $slug,
    ]);
    if ($upd->rowCount() > 0) {
        echo sprintf("  [seed] %-58s guide=%s/%s  installer=%s\n",
            $slug, 'manuals', $guideSlug, $installer ? 'yes' : '— (sign-in)');
        $seeded++;
    } else {
        $skipped++;   // already had a guide URL — left untouched
    }
}

echo sprintf(
    "[seed-manual-urls] done — %d seeded, %d already set (skipped), %d not in catalog.\n",
    $seeded, $skipped, $missing
);

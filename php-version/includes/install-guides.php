<?php
/* ============================================================================
 *  Maventech native installation-guide content.
 *
 *  We host our OWN step-by-step install/activation guides (instead of linking
 *  out to a third-party manuals site). The catalog is grouped into four
 *  install "flows" that cover every Microsoft product we sell — each guide is
 *  personalised at render time with the specific product's name + its own
 *  one-click installer link. Screenshots are served locally from
 *  /uploads/guides/<flow>/.
 *
 *  All copy below is original. Antivirus products are not mapped (no guide).
 *  ========================================================================== */

if (!function_exists('mv_guide_template_for_slug')) {

/** Map a product slug → install-flow template key (or null when none). */
function mv_guide_template_for_slug(string $slug): ?string
{
    static $map = [
        // ── Office / Project / Visio — Windows, product-key activation ──────
        'microsoft-office-2024-professional-plus-windows'                    => 'office_key',
        'microsoft-office-2024-professional-plus-lifetime-license-windows-pc'=> 'office_key',
        'microsoft-office-home-2024-pc'                                      => 'office_key',
        'microsoft-office-home-business-2024-pc'                             => 'office_key',
        'microsoft-office-2021-professional-plus-windows'                    => 'office_key',
        'microsoft-word-2021-windows'                                        => 'office_key',
        'microsoft-excel-2021-windows'                                       => 'office_key',
        'microsoft-office-2019-home-student-windows'                         => 'office_key',
        'microsoft-office-2019-home-business-pc'                             => 'office_key',
        'microsoft-office-2019-professional-plus-windows'                    => 'office_key',
        'microsoft-project-2024-professional-pc'                             => 'office_key',
        'microsoft-project-professional-2021-pc'                            => 'office_key',
        'ms-project-professional-2019-pc'                                    => 'office_key',
        'microsoft-visio-2024-professional-windows-pc'                       => 'office_key',
        'microsoft-visio-2021-professional-windows-pc'                       => 'office_key',
        'ms-visio-professional-2019-pc'                                      => 'office_key',
        // ── Office Retail (ISO image + sign-in) ─────────────────────────────
        'microsoft-office-2021-home-business-windows'                        => 'office_retail',
        'microsoft-office-2021-home-student-windows'                         => 'office_retail',
        // ── Office for Mac ──────────────────────────────────────────────────
        'microsoft-office-home-business-2024-mac'                            => 'office_mac',
        'microsoft-office-home-2024-mac'                                     => 'office_mac',
        'microsoft-office-2021-home-student-mac'                             => 'office_mac',
        'microsoft-office-2021-home-business-mac'                            => 'office_mac',
        'microsoft-word-2021-mac-lifetime-license-no-subscription'           => 'office_mac',
        'microsoft-excel-2021-mac-lifetime-license-no-subscription'          => 'office_mac',
        'microsoft-office-home-and-business-2019-mac'                        => 'office_mac',
        'microsoft-office-home-and-student-2019-mac'                         => 'office_mac',
        // ── Windows desktop ─────────────────────────────────────────────────
        'windows-11-home' => 'windows',
        'windows-11-pro'  => 'windows',
        'windows-10-home' => 'windows',
        'windows-10-pro'  => 'windows',
    ];
    return $map[$slug] ?? null;
}

/** True when the product has a native on-site install guide. */
function mv_product_has_guide(?array $product): bool
{
    return $product && !empty($product['slug']) && mv_guide_template_for_slug((string)$product['slug']) !== null;
}

/** Relative path to the native guide page for a slug. */
function mv_guide_path(string $slug): string
{
    return '/install-guide.php?slug=' . urlencode($slug);
}

/**
 * Absolute guide URL (for emails / PDFs that can't resolve relative paths).
 * Uses site_url() so it always resolves to the current public host.
 */
function mv_guide_abs_url(string $slug): string
{
    $base = function_exists('site_url') ? rtrim((string)site_url(), '/') : '';
    return $base . mv_guide_path($slug);
}

/**
 * The four install-flow templates. Each: label, platform, flow[] (flowchart
 * pills), system[] (requirements), steps[] (numbered, optional screenshot),
 * activation (HTML). {{installer}} / {{activation}} placeholders in the copy
 * are filled with the product's own links by the page.
 */
function mv_install_guides(): array
{
    return [

    /* ───────────────────────── Office / Project / Visio (key) ───────────── */
    'office_key' => [
        'label'    => 'Microsoft Office, Project & Visio — Windows',
        'platform' => 'Windows 10 / 11',
        'flow' => [
            ['icon' => 'bi-download',      'label' => 'Download'],
            ['icon' => 'bi-shield-lock',   'label' => 'Run as admin'],
            ['icon' => 'bi-gear-wide-connected', 'label' => 'Install'],
            ['icon' => 'bi-window',        'label' => 'Open an app'],
            ['icon' => 'bi-key',           'label' => 'Change key'],
            ['icon' => 'bi-patch-check',   'label' => 'Activated'],
        ],
        'system' => [
            '1 GHz or faster, 2-core 32-bit (x86) or 64-bit (x64) processor',
            '4 GB RAM (2 GB minimum on 32-bit)',
            '4 GB of free hard-disk space',
            'Windows 10 or Windows 11',
            'An internet connection to download Office files during setup',
        ],
        'steps' => [
            ['title' => 'Download the official installer',
             'html'  => 'Click <strong>Download installer</strong> below to get the genuine Microsoft setup file. Save it somewhere easy to find &mdash; usually your <em>Downloads</em> folder. Need a different language or the 32-bit build? Just ask our support team.',
             'img'   => null],
            ['title' => 'Run the installer as administrator',
             'html'  => 'Right-click the downloaded file and choose <strong>&ldquo;Run as administrator&rdquo;</strong>. When Windows asks whether to allow the app to make changes, click <strong>Yes</strong>. Keep your PC online &mdash; the installer pulls the latest files straight from Microsoft.',
             'img'   => 'office/step-run.jpg'],
            ['title' => 'Let Office install',
             'html'  => 'Installation runs automatically and takes a few minutes depending on your connection. You don&rsquo;t need to enter anything yet &mdash; just wait for it to finish.',
             'img'   => 'office/step-install.jpg'],
            ['title' => 'Open any Office app',
             'html'  => 'Once setup completes, open the Start menu and launch any installed app, for example <strong>Word</strong> or <strong>Excel</strong>. You can pin it to the taskbar for faster access later.',
             'img'   => 'office/step-open.jpg'],
            ['title' => 'Go to Account → Change Product Key',
             'html'  => 'Inside the app, open <strong>File ▸ Account</strong> (bottom-left). Click <strong>Change Product Key</strong> below the Office icons to open the activation box.',
             'img'   => 'office/step-changekey.jpg'],
            ['title' => 'Enter your 25-character key',
             'html'  => 'Type or paste the product key from your order email and confirm. Your copy of Office is now genuinely activated &mdash; the key is yours for life on this device.',
             'img'   => 'office/step-enterkey.jpg'],
        ],
        'activation' => 'Activation happens directly inside the Office app using the <strong>Change Product Key</strong> box shown above &mdash; no third-party tools are ever needed. If you also want to manage the licence online, sign in with a free Microsoft account at <a href="{{activation}}" target="_blank" rel="noopener">{{activation_host}}</a>. Tip: remove any pre-installed trial version of Office first and restart the PC to avoid conflicts.',
    ],

    /* ───────────────────────── Office Retail (ISO) ──────────────────────── */
    'office_retail' => [
        'label'    => 'Microsoft Office Home editions — Windows (disc image)',
        'platform' => 'Windows 10 / 11',
        'flow' => [
            ['icon' => 'bi-download',    'label' => 'Download ISO'],
            ['icon' => 'bi-disc',        'label' => 'Mount image'],
            ['icon' => 'bi-gear-wide-connected', 'label' => 'Run setup'],
            ['icon' => 'bi-window',      'label' => 'Open an app'],
            ['icon' => 'bi-key',         'label' => 'Enter key'],
            ['icon' => 'bi-patch-check', 'label' => 'Activated'],
        ],
        'system' => [
            '1 GHz or faster, 2-core processor (x86/x64)',
            '4 GB RAM',
            '4 GB of free hard-disk space',
            'Windows 10 or Windows 11',
            'An internet connection during setup',
        ],
        'steps' => [
            ['title' => 'Download the disc image (.iso)',
             'html'  => 'Click <strong>Download installer</strong> below to download the official Office disc image (an <em>.iso</em> file). Save it to your <em>Downloads</em> folder.',
             'img'   => null],
            ['title' => 'Mount the ISO',
             'html'  => 'Right-click the downloaded <em>.iso</em> file and choose <strong>Mount</strong>. Windows opens it as a virtual DVD drive in File Explorer.',
             'img'   => 'retail/step-mount.png'],
            ['title' => 'Run Setup',
             'html'  => 'Open the new drive and double-click <strong>Setup</strong> (use <em>Setup64</em> for the 64-bit version). Approve the Windows prompt and let Office install.',
             'img'   => 'retail/step-setup.png'],
            ['title' => 'Open any Office app',
             'html'  => 'When installation finishes, launch an app such as <strong>Word</strong> from the Start menu.',
             'img'   => 'office/step-open.jpg'],
            ['title' => 'Activate with your key',
             'html'  => 'Open <strong>File ▸ Account ▸ Change Product Key</strong> and enter the 25-character key from your order email. (Home editions can also be linked to a Microsoft account at sign-in.)',
             'img'   => 'office/step-changekey.jpg'],
            ['title' => 'Done — Office is activated',
             'html'  => 'Confirm the key and your Office suite is activated for life on this PC.',
             'img'   => 'office/step-enterkey.jpg'],
        ],
        'activation' => 'After installing, enter your key via <strong>File ▸ Account ▸ Change Product Key</strong>, or sign in / redeem online with a free Microsoft account at <a href="{{activation}}" target="_blank" rel="noopener">{{activation_host}}</a>. Remove any older pre-installed Office trial and restart before activating.',
    ],

    /* ───────────────────────── Office for Mac ───────────────────────────── */
    'office_mac' => [
        'label'    => 'Microsoft Office for Mac',
        'platform' => 'macOS',
        'flow' => [
            ['icon' => 'bi-box-arrow-up-right', 'label' => 'Sign in'],
            ['icon' => 'bi-download',    'label' => 'Download'],
            ['icon' => 'bi-box-seam',    'label' => 'Open .pkg'],
            ['icon' => 'bi-file-earmark-text', 'label' => 'Accept licence'],
            ['icon' => 'bi-gear-wide-connected', 'label' => 'Install'],
            ['icon' => 'bi-patch-check', 'label' => 'Activated'],
        ],
        'system' => [
            'One of the three most recent versions of macOS',
            'Apple silicon or Intel processor',
            '10 GB of available disk space',
            '4 GB RAM',
            'An internet connection and a Microsoft account',
        ],
        'steps' => [
            ['title' => 'Sign in and redeem your key',
             'html'  => 'Office for Mac is downloaded <em>after</em> you redeem your licence. Click <strong>Activate / Sign in</strong> below to open the official Microsoft setup page, sign in with a free Microsoft account (or create one), then type the 25-character product key from your order email exactly as shown. This permanently links the licence to your account, so future re-installs need no key.',
             'img'   => null],
            ['title' => 'Download the installer package',
             'html'  => 'Once the key is redeemed, the Office for Mac installer (a <em>.pkg</em> file, roughly 2&ndash;3&nbsp;GB) downloads automatically. Open <strong>Finder ▸ Downloads</strong> and double-click the package to launch the installer. If your Mac warns it was downloaded from the internet, choose <strong>Open</strong>.',
             'img'   => 'mac/mac-1.jpg'],
            ['title' => 'Start the installer',
             'html'  => 'The <strong>Install Microsoft Office</strong> wizard opens on the Introduction screen. Read the welcome note and click <strong>Continue</strong> in the bottom-right corner.',
             'img'   => 'mac/mac-2.png'],
            ['title' => 'Read the software licence',
             'html'  => 'The Software Licence Agreement appears. Scroll through the terms (you can print or save a copy if you wish) and click <strong>Continue</strong>.',
             'img'   => 'mac/mac-3.png'],
            ['title' => 'Agree to the terms',
             'html'  => 'A confirmation sheet drops down. Click <strong>Agree</strong> to accept the licence terms and move on to the installation options.',
             'img'   => 'mac/mac-4.png'],
            ['title' => 'Choose where to install, then click Install',
             'html'  => 'Keep the default location (your <em>Applications</em> folder) for a standard setup &mdash; this is recommended for almost everyone. The installer shows how much disk space is needed (about 10&nbsp;GB). Click <strong>Install</strong> to begin copying the apps.',
             'img'   => 'mac/mac-6.png'],
            ['title' => 'Authenticate to allow the install',
             'html'  => 'macOS asks for permission to install new software. Enter your <strong>Mac login password</strong> (or use Touch ID) and click <strong>Install Software</strong>. The progress bar then runs for a few minutes &mdash; you can leave it to finish.',
             'img'   => 'mac/mac-7.png'],
            ['title' => 'Finish and activate',
             'html'  => 'When the <strong>&ldquo;The installation was successful&rdquo;</strong> screen appears, click <strong>Close</strong>. Open <strong>Word</strong> (or any Office app) from Launchpad, click <strong>Sign In</strong> and use the <em>same</em> Microsoft account you redeemed the key with. The apps activate instantly &mdash; no product-key box is shown on Mac. You can move the installer package to Trash afterwards.',
             'img'   => 'mac/mac-8.png'],
        ],
        'activation' => 'On Mac, the licence is tied to your Microsoft account. Sign in and redeem your key at <a href="{{activation}}" target="_blank" rel="noopener">{{activation_host}}</a>, then sign in with the same account inside any Office app to activate. No product-key box is needed once the key is redeemed online.',
    ],

    /* ───────────────────────── Windows desktop ──────────────────────────── */
    'windows' => [
        'label'    => 'Microsoft Windows',
        'platform' => 'PC',
        'flow' => [
            ['icon' => 'bi-download',    'label' => 'Download tool'],
            ['icon' => 'bi-usb-drive',   'label' => 'Create media'],
            ['icon' => 'bi-gear-wide-connected', 'label' => 'Settings ▸ Activation'],
            ['icon' => 'bi-key',         'label' => 'Change key'],
            ['icon' => 'bi-input-cursor-text', 'label' => 'Enter key'],
            ['icon' => 'bi-patch-check', 'label' => 'Activated'],
        ],
        'system' => [
            '1 GHz or faster compatible 64-bit processor (Windows 11 needs TPM 2.0)',
            '4 GB RAM or more',
            '64 GB or larger storage device',
            'A blank USB drive (8 GB+) or DVD if you create installation media',
            'A stable internet connection',
        ],
        'steps' => [
            ['title' => 'Download the Media Creation Tool',
             'html'  => 'Click <strong>Download installer</strong> below to get the official Media Creation Tool. This is used to install or upgrade Windows from your existing PC.',
             'img'   => 'windows/step-media.png'],
            ['title' => 'Run the tool to install or upgrade Windows',
             'html'  => 'Launch the tool and follow the prompts to either <strong>upgrade this PC now</strong> or <strong>create installation media</strong> (USB/DVD) for a clean install. Accept the licence terms and let it complete.',
             'img'   => null],
            ['title' => 'Open Settings → Activation',
             'html'  => 'Once Windows is installed, go to <strong>Settings ▸ System ▸ Activation</strong> (on Windows 10: <em>Settings ▸ Update &amp; Security ▸ Activation</em>).',
             'img'   => 'windows/step-settings.jpg'],
            ['title' => 'Click "Change product key"',
             'html'  => 'Under the activation panel, choose <strong>Change product key</strong> to open the key-entry box.',
             'img'   => 'windows/step-change.jpg'],
            ['title' => 'Enter your 25-character key',
             'html'  => 'Type the product key from your order email and click <strong>Next</strong>, then <strong>Activate</strong>.',
             'img'   => 'windows/step-key.jpg'],
            ['title' => 'Windows is activated',
             'html'  => 'You&rsquo;ll see &ldquo;Windows is activated&rdquo;. That&rsquo;s it &mdash; your licence is permanent on this device.',
             'img'   => 'windows/step-activated.jpg'],
        ],
        'activation' => 'Activate from <strong>Settings ▸ System ▸ Activation ▸ Change product key</strong> as shown above. To make re-installs effortless, sign in with a free Microsoft account at <a href="{{activation}}" target="_blank" rel="noopener">{{activation_host}}</a> so your digital licence is linked to your account.',
    ],

    ];
}

}

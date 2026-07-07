<?php
/**
 * Subscription engine — plans catalogue, customer subscriptions, unique
 * customer-ID generation, fulfilment (record + receipt/certificate PDFs +
 * confirmation email).  Included from functions.php so the schema self-heals
 * on boot (cheap, statically guarded).
 *
 * Plans come from the "sub maven.pdf" spec: Quick Fix (one-time), Starter
 * Care (1 yr), Pro Shield (3 yr), Lifetime Elite (10 yr).  Admin sets the
 * USD price for each in Admin → Subscription.
 */

if (!function_exists('sub_migrate')) {

function sub_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(64) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            tagline VARCHAR(255) NOT NULL DEFAULT '',
            tenure_label VARCHAR(64) NOT NULL DEFAULT '',
            duration_months INT NOT NULL DEFAULT 0,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            devices VARCHAR(40) NOT NULL DEFAULT '',
            features_json TEXT,
            sort_order INT NOT NULL DEFAULT 100,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS customer_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id VARCHAR(32) NOT NULL DEFAULT '',
            order_id INT NULL,
            order_number VARCHAR(40) NOT NULL DEFAULT '',
            plan_slug VARCHAR(64) NOT NULL DEFAULT '',
            plan_name VARCHAR(120) NOT NULL DEFAULT '',
            customer_name VARCHAR(160) NOT NULL DEFAULT '',
            email VARCHAR(190) NOT NULL DEFAULT '',
            phone VARCHAR(48) NOT NULL DEFAULT '',
            country VARCHAR(8) NOT NULL DEFAULT 'US',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(8) NOT NULL DEFAULT 'USD',
            gateway VARCHAR(20) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            start_date DATE NULL,
            end_date DATE NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_email (email), KEY idx_plan (plan_slug),
            KEY idx_cust (customer_id), KEY idx_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // orders.subscription_plan — set during a subscription checkout so
        // fulfil_order knows to run the subscription path instead of keys.
        try { $pdo->exec("ALTER TABLE orders ADD COLUMN subscription_plan VARCHAR(64) DEFAULT NULL"); }
        catch (Throwable $e) { /* already exists */ }

        // Assignment + notes (department / handler / running note log).
        try { $pdo->exec("ALTER TABLE customer_subscriptions ADD COLUMN assigned_department VARCHAR(40) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE customer_subscriptions ADD COLUMN assigned_user_id INT DEFAULT NULL"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE subscription_plans ADD COLUMN icon_image VARCHAR(500) DEFAULT NULL"); } catch (Throwable $e) {}
        $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subscription_id INT NOT NULL,
            department VARCHAR(40) NOT NULL DEFAULT '',
            author_user_id INT DEFAULT NULL,
            author_name VARCHAR(120) NOT NULL DEFAULT '',
            note TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sub (subscription_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Seed the four plans once (prices left at 0.00 for the admin to set).
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM subscription_plans")->fetchColumn();
        if ($cnt === 0) {
            $seed = sub_seed_data();
            $ins = $pdo->prepare("INSERT INTO subscription_plans
                (slug, name, tagline, tenure_label, duration_months, price, devices, features_json, sort_order, active)
                VALUES (?,?,?,?,?,?,?,?,?,1)");
            foreach ($seed as $i => $p) {
                $ins->execute([
                    $p['slug'], $p['name'], $p['tagline'], $p['tenure_label'],
                    $p['duration_months'], 0.00, $p['devices'],
                    json_encode($p['features']), ($i + 1) * 10,
                ]);
            }
        }

        // Backfill subscription tier icons (cloud-hosted URLs so they survive
        // pod restarts / reseeds).  Only sets a row's icon when it's missing.
        $iconUpd = $pdo->prepare("UPDATE subscription_plans SET icon_image=? WHERE slug=? AND (icon_image IS NULL OR icon_image='')");
        foreach (sub_seed_data() as $sp) {
            if (!empty($sp['icon_image'])) $iconUpd->execute([$sp['icon_image'], $sp['slug']]);
        }
    } catch (Throwable $e) { /* fresh-install timing — retry next boot */ }
}

/** Canonical seed definitions for the four plans (features from the PDF). */
function sub_seed_data(): array
{
    return [
        [
            'slug' => 'quick-fix', 'name' => 'Quick Fix',
            'icon_image' => '/assets/images/subscriptions/plan-1.png',
            'tagline' => 'One-time service · single session',
            'tenure_label' => 'One-Time Service', 'duration_months' => 0, 'devices' => '1 Device',
            'features' => [
                'Immediate issue resolution', 'Virus and malware removal', 'PC performance optimization',
                'Software installation and setup', 'Printer and peripheral configuration',
                'Email setup and troubleshooting', 'Internet and Wi-Fi troubleshooting',
                'Operating system error fixes', 'Driver updates', 'Browser issues and cleanup',
                'Basic data backup assistance', 'Microsoft Office troubleshooting', 'One-time security health check',
            ],
        ],
        [
            'slug' => 'starter-care', 'name' => 'Starter Care',
            'icon_image' => '/assets/images/subscriptions/plan-2.png',
            'tagline' => 'Unlimited remote support for 1 year',
            'tenure_label' => '1 Year', 'duration_months' => 12, 'devices' => '1 Device',
            'features' => [
                'Unlimited remote support for 1 year', 'Unlimited software troubleshooting',
                'Operating system support', 'Email and account assistance', 'Security and antivirus support',
                'Device health checks', 'Performance tune-ups', 'Software updates assistance',
                'Printer and scanner support', 'Browser and application support',
                'New software installation assistance', 'Data backup guidance', 'Monthly maintenance recommendations',
            ],
        ],
        [
            'slug' => 'pro-shield', 'name' => 'Pro Shield',
            'icon_image' => '/assets/images/subscriptions/plan-3.png',
            'tagline' => 'Transferable protection · up to 3 devices',
            'tenure_label' => '3 Years', 'duration_months' => 36, 'devices' => 'Up to 3 Devices',
            'features' => [
                'Transferable device protection', 'Device replacement enrollment',
                'Advanced malware and security support', 'Network and Wi-Fi optimization',
                'Multi-device maintenance', 'Priority support queue', 'Annual security audits',
                'Cloud storage setup assistance', 'Advanced software troubleshooting',
                'Device migration support', 'Operating system upgrade assistance', 'Productivity software support',
            ],
        ],
        [
            'slug' => 'lifetime-elite', 'name' => 'Lifetime Elite',
            'icon_image' => '/assets/images/subscriptions/plan-4.png',
            'tagline' => '10 years support · unlimited devices',
            'tenure_label' => '10 Years Support', 'duration_months' => 120, 'devices' => 'Unlimited Devices',
            'features' => [
                'Unlimited device coverage', 'Unlimited device transfers', 'Premium priority support',
                'Dedicated support specialists', 'Comprehensive security assistance',
                'Advanced malware and ransomware guidance', 'New device onboarding assistance',
                'Device replacement support', 'Remote setup for computers, printers, and peripherals',
                'Cloud account support', 'Data migration assistance', 'System optimization services',
                'Annual technology health reviews', 'Personalized technical guidance',
                'Priority scheduling', 'Family and business device support',
            ],
        ],
    ];
}
sub_migrate();

/** All plans (optionally only active), ordered. */
function sub_plans(bool $activeOnly = false): array
{
    try {
        $sql = "SELECT * FROM subscription_plans " . ($activeOnly ? "WHERE active=1 " : "") . "ORDER BY sort_order ASC, id ASC";
        $rows = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) { $r['features'] = json_decode((string)$r['features_json'], true) ?: []; }
        return $rows;
    } catch (Throwable $e) { return []; }
}

/** One plan by slug. */
function sub_plan_get(string $slug): ?array
{
    try {
        $st = db()->prepare("SELECT * FROM subscription_plans WHERE slug=? LIMIT 1");
        $st->execute([$slug]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['features'] = json_decode((string)$r['features_json'], true) ?: [];
        return $r;
    } catch (Throwable $e) { return null; }
}

/** Map an order to the MVN country code (US / CA / UK / AU / EU).
 *  Prefers the customer's chosen billing country, then the store region. */
function sub_country_code(array $order): string
{
    $map = ['GB' => 'UK', 'EN' => 'UK', 'AUS' => 'AU', 'USA' => 'US', 'CAN' => 'CA'];
    foreach ([(string)($order['country'] ?? ''), (string)($order['region'] ?? '')] as $raw) {
        $cc = strtoupper(trim($raw));
        if ($cc === '') continue;
        $cc = $map[$cc] ?? $cc;
        if (in_array($cc, ['US', 'CA', 'UK', 'AU', 'EU'], true)) return $cc;
    }
    return 'US';
}

/**
 * Create the customer_subscriptions record for a paid subscription order and
 * generate the unique customer ID (MVN + country + zero-padded sequence,
 * e.g. MVNUS00001).  Returns the row, or an existing one if already created.
 */
function sub_record_for_order(array $order): ?array
{
    $pdo = db();
    $orderId = (int)($order['id'] ?? 0);
    if ($orderId) {
        $ex = $pdo->prepare("SELECT * FROM customer_subscriptions WHERE order_id=? LIMIT 1");
        $ex->execute([$orderId]);
        if ($row = $ex->fetch(PDO::FETCH_ASSOC)) return $row;
    }
    $plan = sub_plan_get((string)($order['subscription_plan'] ?? ''));
    if (!$plan) return null;

    $cc        = sub_country_code($order);
    $start     = date('Y-m-d');
    $months    = (int)$plan['duration_months'];
    $end       = $months > 0 ? date('Y-m-d', strtotime("+{$months} months")) : null;
    $name      = trim((string)($order['first_name'] ?? '') . ' ' . (string)($order['last_name'] ?? ''));
    $gateway   = (string)($order['payment_method'] ?? '');

    $ins = $pdo->prepare("INSERT INTO customer_subscriptions
        (order_id, order_number, plan_slug, plan_name, customer_name, email, phone, country,
         amount, currency, gateway, status, start_date, end_date)
        VALUES (?,?,?,?,?,?,?,?,?,?,?, 'active', ?, ?)");
    $ins->execute([
        $orderId, (string)($order['order_number'] ?? ''), $plan['slug'], $plan['name'],
        $name, (string)($order['email'] ?? ''), (string)($order['phone'] ?? ''), $cc,
        (float)($order['total'] ?? $plan['price']), (string)($order['currency'] ?? 'USD'),
        $gateway, $start, $end,
    ]);
    $id = (int)$pdo->lastInsertId();
    $prefix = strtoupper((string)(company_info()['id_prefix'] ?? 'MVN')) ?: 'MVN';
    $customerId = $prefix . $cc . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
    $pdo->prepare("UPDATE customer_subscriptions SET customer_id=? WHERE id=?")->execute([$customerId, $id]);

    $st = $pdo->prepare("SELECT * FROM customer_subscriptions WHERE id=?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Build a one-line tenure / dates string for emails + PDFs. */
function sub_tenure_text(array $sub, array $plan): string
{
    if ((int)$plan['duration_months'] === 0) {
        return $plan['tenure_label'] . ' (single session on ' . date('F j, Y', strtotime((string)$sub['start_date'])) . ')';
    }
    return $plan['tenure_label'] . ' · ' . date('F j, Y', strtotime((string)$sub['start_date']))
         . ' → ' . date('F j, Y', strtotime((string)$sub['end_date']));
}

/**
 * Generate the Receipt + Subscription Certificate PDFs for a subscription
 * order and return their file paths (for email attachment).
 */
/** "What's included" description block appended to the subscription receipt PDF.
 *
 *  Layout is deliberately compact so the receipt fits on a SINGLE PDF page
 *  even when a plan has 13-14 feature bullets (Quick Fix): a 2-column
 *  features grid + tight leading + a small "Your subscription" summary
 *  strip.  Previously this block used a single-column list which pushed
 *  Quick Fix / Lifetime Elite receipts onto page 2 (see customer PDF
 *  screenshot 2026-07-07). */
function sub_receipt_extra_html(array $sub, array $plan): string
{
    // Split features into TWO columns so long "What's included" lists
    // (Quick Fix has 13 bullets; Lifetime Elite similar) fit inside one page.
    $features = array_values(array_filter(array_map('strval', (array)($plan['features'] ?? []))));
    $n = count($features);
    if ($n === 0) {
        $featCols = '';
    } else {
        $mid = (int)ceil($n / 2);
        $rowsFor = function (array $subset): string {
            $out = '';
            foreach ($subset as $f) {
                $out .= '<tr><td style="padding:1.5pt 0;color:#059669;width:12pt;font-size:8pt;">&#10003;</td>'
                     .  '<td style="padding:1.5pt 0;color:#334155;font-size:8pt;line-height:1.35;">'
                     .  htmlspecialchars($f, ENT_QUOTES, 'UTF-8')
                     .  '</td></tr>';
            }
            return $out;
        };
        $left  = $rowsFor(array_slice($features, 0, $mid));
        $right = $rowsFor(array_slice($features, $mid));
        $featCols = '<table style="width:100%;border-collapse:collapse;margin-top:3pt;">'
                  . '<tr>'
                  .   '<td style="vertical-align:top;width:50%;padding-right:8pt;">'
                  .     '<table style="width:100%;border-collapse:collapse;">' . $left . '</table>'
                  .   '</td>'
                  .   '<td style="vertical-align:top;width:50%;padding-left:8pt;border-left:1px solid #eef2f7;">'
                  .     '<table style="width:100%;border-collapse:collapse;">' . $right . '</table>'
                  .   '</td>'
                  . '</tr></table>';
    }

    $tenure = htmlspecialchars(sub_tenure_text($sub, $plan), ENT_QUOTES, 'UTF-8');
    $custId = htmlspecialchars((string)($sub['customer_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    $devices= htmlspecialchars((string)($plan['devices'] ?? ''), ENT_QUOTES, 'UTF-8');
    $planNm = htmlspecialchars((string)($plan['name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $tag    = htmlspecialchars((string)($plan['tagline'] ?? ''), ENT_QUOTES, 'UTF-8');

    return '<div style="margin-top:10pt;padding:10pt 12pt;border:1px solid #e2e8f0;border-radius:8pt;background:#f8fafc;">'
         . '<table style="width:100%;border-collapse:collapse;margin-bottom:5pt;">'
         .   '<tr>'
         .     '<td style="vertical-align:top;">'
         .       '<div style="font-weight:800;color:#0f172a;font-size:10.5pt;letter-spacing:.02em;">' . $planNm . '</div>'
         .       '<div style="font-size:8.5pt;color:#64748b;margin-top:1pt;">' . $tag . '</div>'
         .     '</td>'
         .     '<td style="vertical-align:top;text-align:right;font-size:8pt;color:#334155;line-height:1.55;white-space:nowrap;">'
         .       '<span style="color:#64748b;">Customer&nbsp;ID</span>&nbsp;&nbsp;<strong style="color:#0f172a;font-family:ui-monospace,Menlo,monospace;">' . $custId . '</strong><br>'
         .       '<span style="color:#64748b;">Coverage</span>&nbsp;&nbsp;<strong style="color:#0f172a;">' . $devices . '</strong><br>'
         .       '<span style="color:#64748b;">Tenure</span>&nbsp;&nbsp;<strong style="color:#0f172a;">' . $tenure . '</strong>'
         .     '</td>'
         .   '</tr>'
         . '</table>'
         . ($featCols !== ''
             ? '<div style="font-weight:700;color:#0f172a;font-size:8.5pt;letter-spacing:.05em;text-transform:uppercase;margin-top:6pt;padding-top:6pt;border-top:1px dashed #cbd5e1;">What&#39;s included</div>' . $featCols
             : '')
         . '</div>';
}

function sub_pdf_paths(array $order, array $sub, array $plan): array
{
    require_once __DIR__ . '/pdf.php';
    $dir = __DIR__ . '/../uploads/order-pdfs/' . (int)($order['id'] ?? 0);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $paths = [];

    // Receipt line-item name — clean "Plan — Tenure" format so the receipt
    // shows "Quick Fix — One-Time Service" / "Starter Care — 30-day Care" /
    // "Pro Shield — Annual" / "Lifetime Elite — Lifetime" cleanly, instead
    // of the older awkward "<Name> Subscription (<Tenure>)" wrapping.
    // Uses an en-dash (—, U+2014) so the line reads as a proper subtitle.
    $__planItemName = trim((string)$plan['name']);
    $__planTenure   = trim((string)($plan['tenure_label'] ?? ''));
    if ($__planTenure !== '' && stripos($__planItemName, $__planTenure) === false) {
        $__planItemName .= ' — ' . $__planTenure;
    }
    $items = [[
        'name'       => $__planItemName,
        'unit_price' => (float)($sub['amount'] ?? $plan['price']),
        'quantity'   => 1,
        'price'      => (float)($sub['amount'] ?? $plan['price']),
        'qty'        => 1,
    ]];

    // 1) Receipt — standard branded receipt + the full plan description block.
    try {
        $payment = [
            'method' => ucfirst((string)($sub['gateway'] ?: 'card')),
            'date'   => date('F j, Y', strtotime((string)($order['created_at'] ?? 'now'))),
        ];
        $rPath = $dir . '/Receipt-' . (string)($order['order_number'] ?? 'SUB') . '.pdf';
        @file_put_contents($rPath, generate_receipt_pdf($order, $items, $payment, sub_receipt_extra_html($sub, $plan)));
        if (is_file($rPath)) $paths[] = $rPath;
    } catch (Throwable $e) { @error_log('[sub receipt pdf] ' . $e->getMessage()); }

    // 2) Invoice — itemised tax invoice for the subscription.
    try {
        $iPath = $dir . '/Invoice-' . (string)($order['order_number'] ?? 'SUB') . '.pdf';
        @file_put_contents($iPath, generate_invoice_pdf($order, $items));
        if (is_file($iPath)) $paths[] = $iPath;
    } catch (Throwable $e) { @error_log('[sub invoice pdf] ' . $e->getMessage()); }

    // 3) Subscription details certificate.
    try {
        $cPath = $dir . '/Subscription-Details-' . (string)($sub['customer_id'] ?? 'MVN') . '.pdf';
        @file_put_contents($cPath, sub_generate_certificate_pdf($order, $sub, $plan));
        if (is_file($cPath)) $paths[] = $cPath;
    } catch (Throwable $e) { @error_log('[sub certificate pdf] ' . $e->getMessage()); }

    return $paths;
}

/** Subscription certificate PDF (binary string) — compact single-page layout
 *  with the plan logo + name at the top, followed by the details table and
 *  a features grid.  Uses its own stand-alone HTML (not _pdf_shell) so we
 *  can guarantee the whole document fits on one letter page.
 */
function sub_generate_certificate_pdf(array $order, array $sub, array $plan): string
{
    require_once __DIR__ . '/pdf.php';
    $e   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $co  = function_exists('company_info') ? company_info() : ['name' => 'Maventech'];
    $cur = (string)($sub['currency'] ?? 'USD');

    // Resolve the plan icon path — Dompdf accepts absolute filesystem paths.
    // Falls back to the company logo when the plan icon is missing/broken so
    // the header never renders blank.
    $planIconUrl = (string)($plan['icon_image'] ?? '');
    $planIconAbs = '';
    if ($planIconUrl !== '') {
        $rel = ltrim(preg_replace('#^https?://[^/]+#i', '', $planIconUrl) ?: '', '/');
        $candidate = __DIR__ . '/../' . $rel;
        if (is_file($candidate)) $planIconAbs = $candidate;
    }
    if ($planIconAbs === '') {
        $companyLogo = _pdf_company_logo_path();
        if ($companyLogo && file_exists($companyLogo)) $planIconAbs = $companyLogo;
    }
    $planLogoTag = $planIconAbs
        ? '<img src="' . $e($planIconAbs) . '" alt="' . $e($plan['name']) . '" style="width:74px;height:74px;object-fit:contain;display:block;">'
        : '<div style="width:74px;height:74px;background:linear-gradient(135deg,#dbeafe,#bfdbfe);border-radius:14px;display:inline-block;"></div>';

    // Feature list — two-column layout keeps the block compact so the whole
    // document fits on a single letter page even with 10+ bullets.
    $features = array_values(array_filter((array)($plan['features'] ?? []), fn($f) => trim((string)$f) !== ''));
    $half = (int)ceil(count($features) / 2);
    $col1 = array_slice($features, 0, $half);
    $col2 = array_slice($features, $half);
    $renderCol = function(array $items) use ($e) {
        $out = '';
        foreach ($items as $f) {
            $out .= '<tr><td style="padding:2px 0;color:#047857;width:16px;font-size:9pt;vertical-align:top;">&#10003;</td>'
                  . '<td style="padding:2px 4px 2px 0;color:#334155;font-size:9pt;line-height:1.35;">' . $e($f) . '</td></tr>';
        }
        return $out;
    };
    $col1Html = $renderCol($col1);
    $col2Html = $renderCol($col2);

    // Details table — one canonical block, tightly spaced.
    $rows = [
        ['Customer ID',    (string)$sub['customer_id']],
        ['Plan',           $plan['name']],
        ['Coverage',       (string)$plan['devices']],
        ['Tenure',         sub_tenure_text($sub, $plan)],
        ['Order number',   (string)($order['order_number'] ?? '')],
        ['Amount paid',    _pdf_money((float)($sub['amount'] ?? 0), $cur)],
        ['Payment method', ucfirst((string)($sub['gateway'] ?: 'card'))],
        ['Status',         ucfirst((string)($sub['status'] ?? 'active'))],
    ];
    // Two-column details grid: left labels + right values, then repeat.
    $halfR = (int)ceil(count($rows) / 2);
    $detL = array_slice($rows, 0, $halfR);
    $detR = array_slice($rows, $halfR);
    $renderDet = function(array $rs) use ($e) {
        $out = '';
        foreach ($rs as $r) {
            $out .= '<tr>'
                  . '<td style="padding:4px 6px 4px 0;color:#64748b;font-size:9pt;white-space:nowrap;">' . $e($r[0]) . '</td>'
                  . '<td style="padding:4px 0;color:#0f172a;font-weight:700;font-size:9.5pt;">' . $e($r[1]) . '</td>'
                  . '</tr>';
        }
        return $out;
    };
    $detLHtml = $renderDet($detL);
    $detRHtml = $renderDet($detR);

    // Company / support block — one row of contact chips.
    $cName = $e((string)($co['name']    ?? 'Maventech'));
    $cAddr = $e((string)($co['address'] ?? ''));
    $cPh   = $e((string)(function_exists('company_phone_for_country') ? company_phone_for_country($order['country'] ?? null) : ($co['phone'] ?? (defined('SITE_PHONE') ? SITE_PHONE : ''))));
    $cEm   = $e((string)($co['email']   ?? ''));

    $planName    = $e((string)$plan['name']);
    $planTagline = $e((string)($plan['tagline'] ?? ''));
    $custId      = $e((string)$sub['customer_id']);
    $amountBig   = $e(_pdf_money((float)($sub['amount'] ?? 0), $cur));
    $startDate   = $e(date('F j, Y', strtotime((string)($sub['start_date'] ?? 'now'))));
    $orderNo     = $e((string)($order['order_number'] ?? ''));
    $billName    = $e(trim((string)($order['first_name'] ?? '') . ' ' . (string)($order['last_name'] ?? '')) ?: (string)$sub['customer_name']);
    $billEmail   = $e((string)($order['email'] ?? $sub['email'] ?? ''));
    $companyLogoAbs = _pdf_company_logo_path();
    $companyLogoTag = ($companyLogoAbs && file_exists($companyLogoAbs))
        ? '<img src="' . $e($companyLogoAbs) . '" alt="' . $cName . '" style="height:30px;width:auto;">'
        : '<div style="font-size:14px;font-weight:800;color:#06b6d4;">' . $cName . '</div>';

    $html = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
  @page { margin: 30px 36px; size: letter portrait; }
  body { font-family:'DejaVu Sans',Helvetica,Arial,sans-serif; font-size:10pt; color:#1f2937; margin:0; padding:0; }

  /* Top brand strip — company logo left, "Subscription Certificate" tag right */
  .top-strip { width:100%; border-collapse:collapse; margin-bottom:10px; }
  .top-strip td { vertical-align:middle; }
  .top-strip .tag { text-align:right; font-size:8pt; letter-spacing:2.4px; font-weight:800; color:#0369a1; text-transform:uppercase; }

  /* Plan hero — big card at the top with the plan logo + name + tagline */
  .plan-hero { background:linear-gradient(135deg,#eff6ff,#dbeafe); border:1px solid #bfdbfe; border-radius:16px; padding:14px 18px; margin-bottom:12px; }
  .plan-hero-table { width:100%; border-collapse:collapse; }
  .plan-hero-table td { vertical-align:middle; }
  .plan-hero-logo { width:90px; text-align:center; }
  .plan-hero-logo .logo-box { display:inline-block; background:#ffffff; border:1px solid #dbeafe; border-radius:14px; padding:6px; box-shadow:0 1px 2px rgba(30,64,175,.08); }
  .plan-hero-txt { padding-left:16px; }
  .plan-hero-name { font-size:20pt; font-weight:800; color:#0f172a; line-height:1.1; letter-spacing:.2px; }
  .plan-hero-sub  { font-size:9.5pt; color:#1e3a8a; margin-top:3px; }
  .plan-hero-badge {
    display:inline-block; background:#059669; color:#fff; font-size:7.5pt; font-weight:800;
    letter-spacing:1.4px; text-transform:uppercase; padding:2px 8px; border-radius:999px; margin-top:6px;
  }

  /* Customer ID + amount banner */
  .snap { width:100%; border-collapse:collapse; margin-bottom:12px; }
  .snap td { width:50%; padding:0 6px; vertical-align:top; }
  .snap td:first-child { padding-left:0; }
  .snap td:last-child  { padding-right:0; }
  .snap-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:10px 14px; }
  .snap-card .k { font-size:7.5pt; text-transform:uppercase; letter-spacing:1.2px; color:#94a3b8; font-weight:700; margin-bottom:2px; }
  .snap-card .v { font-size:14pt; font-weight:800; color:#0f172a; letter-spacing:.3px; font-family:'DejaVu Sans Mono',monospace; }
  .snap-card .v.amt { color:#047857; font-family:'DejaVu Sans',sans-serif; }

  /* Section label */
  .sec { font-size:8pt; letter-spacing:1.4px; text-transform:uppercase; color:#94a3b8; font-weight:800; margin:0 0 5px; }

  /* Details grid — two columns of label/value pairs */
  .grid { width:100%; border-collapse:collapse; margin-bottom:12px; }
  .grid > tbody > tr > td { width:50%; padding:0 8px; vertical-align:top; }
  .grid > tbody > tr > td:first-child { padding-left:0; }
  .grid > tbody > tr > td:last-child { padding-right:0; }
  .grid table { width:100%; border-collapse:collapse; }

  /* Features */
  .feat { width:100%; border-collapse:collapse; margin-bottom:10px; }
  .feat > tbody > tr > td { width:50%; padding:0 10px; vertical-align:top; }
  .feat > tbody > tr > td:first-child { padding-left:0; }
  .feat > tbody > tr > td:last-child  { padding-right:0; }
  .feat table { width:100%; border-collapse:collapse; }

  /* Contact footer strip */
  .contact { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:9px 14px; font-size:8.5pt; color:#334155; margin-bottom:6px; }
  .contact .lbl { color:#64748b; font-weight:700; margin-right:3px; }
  .contact .chip { display:inline-block; margin-right:14px; }

  .foot { font-size:7pt; color:#94a3b8; text-align:center; margin-top:6px; }
</style></head><body>

  <table class="top-strip"><tr>
    <td>{$companyLogoTag}</td>
    <td class="tag">Subscription Certificate</td>
  </tr></table>

  <div class="plan-hero">
    <table class="plan-hero-table"><tr>
      <td class="plan-hero-logo"><span class="logo-box">{$planLogoTag}</span></td>
      <td class="plan-hero-txt">
        <div class="plan-hero-name">{$planName}</div>
        <div class="plan-hero-sub">{$planTagline}</div>
        <span class="plan-hero-badge">Active Subscription</span>
      </td>
    </tr></table>
  </div>

  <table class="snap"><tr>
    <td>
      <div class="snap-card">
        <div class="k">Customer ID</div>
        <div class="v">{$custId}</div>
      </div>
    </td>
    <td>
      <div class="snap-card">
        <div class="k">Amount paid</div>
        <div class="v amt">{$amountBig}</div>
      </div>
    </td>
  </tr></table>

  <div class="sec">Subscription details</div>
  <table class="grid"><tr>
    <td><table>{$detLHtml}</table></td>
    <td><table>{$detRHtml}</table></td>
  </tr></table>

  <div class="sec">What&#39;s included in your {$planName} plan</div>
  <table class="feat"><tr>
    <td><table>{$col1Html}</table></td>
    <td><table>{$col2Html}</table></td>
  </tr></table>

  <div class="contact">
    <span class="chip"><span class="lbl">Billed to:</span>{$billName} &lt;{$billEmail}&gt;</span>
    <br>
    <span class="chip"><span class="lbl">Support:</span>{$cPh}</span>
    <span class="chip"><span class="lbl">Email:</span>{$cEm}</span>
    <br>
    <span class="chip"><span class="lbl">{$cName}</span>{$cAddr}</span>
  </div>

  <div class="foot">Quote your Customer ID <strong>{$custId}</strong> whenever you contact support. Issued {$startDate} · Order {$orderNo}.</div>

</body></html>
HTML;

    $dompdf = _pdf_dompdf();
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

/** Send the subscription confirmation email with both PDFs attached. */
function sub_send_confirmation(array $order, array $sub, array $plan): void
{
    $co     = function_exists('company_info') ? company_info() : [];
    $brand  = $co['name']  ?? (defined('SITE_BRAND') ? SITE_BRAND : 'Maventech');
    $phone  = (function_exists('company_phone_for_country') ? company_phone_for_country($order['country'] ?? null) : ($co['phone'] ?? '')) ?: (defined('SITE_PHONE') ? SITE_PHONE : '');
    $email  = $co['email'] ?? '';
    $cur    = (string)($sub['currency'] ?? 'USD');
    $first  = htmlspecialchars((string)($order['first_name'] ?? '') ?: 'there', ENT_QUOTES, 'UTF-8');
    $custId = htmlspecialchars((string)$sub['customer_id'], ENT_QUOTES, 'UTF-8');
    $tenure = htmlspecialchars(sub_tenure_text($sub, $plan), ENT_QUOTES, 'UTF-8');
    $amount = function_exists('_pdf_money') ? _pdf_money((float)$sub['amount'], $cur) : ('$' . number_format((float)$sub['amount'], 2));
    $planNm = htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8');
    $devices= htmlspecialchars((string)$plan['devices'], ENT_QUOTES, 'UTF-8');
    $gw     = htmlspecialchars(ucfirst((string)($sub['gateway'] ?: 'card')), ENT_QUOTES, 'UTF-8');
    $ordNo  = htmlspecialchars((string)($order['order_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    $brandE = htmlspecialchars($brand, ENT_QUOTES, 'UTF-8');
    $phoneE = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
    $emailE = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

    // Bill-To (customer billing address from the order)
    $billName = htmlspecialchars(trim(((string)($order['first_name'] ?? '')) . ' ' . ((string)($order['last_name'] ?? ''))) ?: (string)$sub['customer_name'], ENT_QUOTES, 'UTF-8');
    $billLines = array_filter([
        trim((string)($order['address'] ?? '')),
        trim(implode(', ', array_filter([(string)($order['city'] ?? ''), (string)($order['state'] ?? ''), (string)($order['zip'] ?? '')]))),
        trim((string)($order['country'] ?? '')),
    ]);
    $billHtml = $billName . ($billLines ? '<br>' . implode('<br>', array_map(fn($l) => htmlspecialchars($l, ENT_QUOTES, 'UTF-8'), $billLines)) : '');
    $billEmail = htmlspecialchars((string)($order['email'] ?? $sub['email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $coAddr = htmlspecialchars((string)($co['address'] ?? ''), ENT_QUOTES, 'UTF-8');

    // Payment statement descriptor (what shows on the card statement)
    $gwRaw = strtolower((string)($sub['gateway'] ?: 'card'));
    $descriptor = trim((string)($order['card_statement_name'] ?? ''));
    if ($descriptor === '') $descriptor = $gwRaw === 'paypal' ? (string)setting_get('statement_name_paypal', '') : (string)setting_get('statement_name_card', '');
    if ($descriptor === '') $descriptor = $brand;
    $descriptorE = htmlspecialchars($descriptor, ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<div style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;max-width:620px;margin:0 auto;color:#0f172a">
  <div style="background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:22px 26px;border-radius:12px 12px 0 0;color:#fff;">
    <div style="font-size:11px;letter-spacing:.14em;font-weight:800;text-transform:uppercase;opacity:.85;">{$brandE} — Subscription</div>
    <div style="font-size:22px;font-weight:800;margin-top:4px;">You're all set, {$first}! 🎉</div>
  </div>
  <div style="background:#fff;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 12px 12px;padding:26px;line-height:1.55;">
    <p style="margin:0 0 16px;font-size:14px;">Thank you for subscribing to <strong>{$planNm}</strong>. Your subscription is now active. Your <strong>paid invoice</strong>, receipt and subscription certificate are attached as PDFs.</p>
    <table style="width:100%;border-collapse:collapse;font-size:13px;margin:4px 0 18px;">
      <tr><td style="padding:7px 0;color:#64748b;width:150px;">Your Customer ID</td><td style="padding:7px 0;font-weight:800;color:#1e3a8a;font-family:ui-monospace,Menlo,monospace;">{$custId}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Plan</td><td style="padding:7px 0;font-weight:700;">{$planNm}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Coverage</td><td style="padding:7px 0;">{$devices}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Subscription period</td><td style="padding:7px 0;">{$tenure}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Order number</td><td style="padding:7px 0;">{$ordNo}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Amount paid</td><td style="padding:7px 0;font-weight:700;">{$amount}</td></tr>
      <tr><td style="padding:7px 0;color:#64748b;">Payment method</td><td style="padding:7px 0;">{$gw}</td></tr>
    </table>
    <div style="display:flex;gap:14px;flex-wrap:wrap;margin:0 0 16px;">
      <div style="flex:1;min-width:210px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;">
        <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;font-weight:700;margin-bottom:5px;">Billed to</div>
        <div style="font-size:13px;color:#0f172a;line-height:1.5;">{$billHtml}<br><span style="color:#64748b;">{$billEmail}</span></div>
      </div>
      <div style="flex:1;min-width:210px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;">
        <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;font-weight:700;margin-bottom:5px;">From</div>
        <div style="font-size:13px;color:#0f172a;line-height:1.5;">{$brandE}<br><span style="color:#64748b;">{$coAddr}</span></div>
      </div>
    </div>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:11px 14px;margin:0 0 16px;font-size:13px;color:#166534;">
      <span style="display:inline-block;background:#16a34a;color:#fff;font-weight:800;font-size:11px;padding:2px 9px;border-radius:5px;letter-spacing:.06em;margin-right:8px;">PAID</span>
      {$amount} via {$gw} · Charge appears as <strong>{$descriptorE}</strong> on your statement.
    </div>
    <div style="background:#fff7ed;border:1px solid #fdba74;border-left:4px solid #f59e0b;border-radius:10px;padding:11px 15px;margin:0 0 16px;font-size:13px;color:#7c2d12;">
      <strong style="color:#7c2d12;">Billing note:</strong> this charge appears as <strong style="background:#fde68a;color:#9a3412;padding:2px 8px;border-radius:5px;">{$descriptorE}</strong> on your card statement.
    </div>
    <div style="background:#eff6ff;border-left:4px solid #2563eb;border-radius:8px;padding:12px 16px;font-size:13px;color:#1e3a8a;">
      Need help? Our support team is here for you. Call <strong>{$phoneE}</strong> or email <a href="mailto:{$emailE}" style="color:#1d4ed8;">{$emailE}</a> and quote your Customer ID <strong>{$custId}</strong>.
    </div>
    <p style="margin:20px 0 0;font-size:12px;color:#94a3b8;">{$brandE}<br>{$coAddr}<br>This is an automated confirmation for your subscription purchase.</p>
  </div>
</div>
HTML;

    $subject = $brand . ' — ' . $plan['name'] . ' subscription confirmed (' . (string)$sub['customer_id'] . ')';
    $pdfPaths = sub_pdf_paths($order, $sub, $plan);
    send_email((string)$order['email'], $subject, $html, (int)($order['id'] ?? 0) ?: null, 'subscription_confirm', 0, $pdfPaths);
}

/**
 * Fulfilment entry point for a subscription order — called from
 * fulfill_order().  Creates the record, generates the customer ID, emails
 * the confirmation + PDFs, notifies the admin, and marks the order fulfilled.
 */
function sub_fulfill_order(array $order): void
{
    $pdo = db();
    $sub = sub_record_for_order($order);
    if (!$sub) return;
    $plan = sub_plan_get((string)$sub['plan_slug']);
    if (!$plan) return;

    try { sub_send_confirmation($order, $sub, $plan); }
    catch (Throwable $e) { @error_log('[sub confirmation email] ' . $e->getMessage()); }

    // Notify the COMPANY (Company Info email) of the subscription sale, with the
    // Receipt + Invoice + Subscription Details PDFs attached.
    try {
        if (function_exists('notify_company_of_sale')) {
            $pdfPaths = sub_pdf_paths($order, $sub, $plan);
            $__notifyName = trim((string)$plan['name']);
            $__notifyTenure = trim((string)($plan['tenure_label'] ?? ''));
            if ($__notifyTenure !== '' && stripos($__notifyName, $__notifyTenure) === false) {
                $__notifyName .= ' — ' . $__notifyTenure;
            }
            $items = [[
                'name'  => $__notifyName,
                'qty'   => 1,
                'price' => (float)($sub['amount'] ?? $plan['price']),
            ]];
            $co = array_merge($order, [
                'order_number'   => (string)($sub['order_number'] ?? $order['order_number'] ?? ''),
                'currency'       => (string)($sub['currency'] ?? 'USD'),
                'total'          => (float)($sub['amount'] ?? 0),
                'payment_method' => (string)($sub['gateway'] ?? $order['payment_method'] ?? 'card'),
                'customer_name'  => (string)$sub['customer_name'],
                'email'          => (string)$sub['email'],
                'phone'          => (string)$sub['phone'],
            ]);
            notify_company_of_sale($co, $items, $pdfPaths, 'subscription');
        }
    } catch (Throwable $e) { @error_log('[sub company notify] ' . $e->getMessage()); }

    // Admin bell — new subscription sale.
    try {
        admin_notify(
            'order',
            'New subscription — ' . $plan['name'],
            trim((string)$sub['customer_name']) . ' · ' . (string)$sub['customer_id']
                . ' · ' . (function_exists('_pdf_money') ? _pdf_money((float)$sub['amount'], (string)$sub['currency']) : (string)$sub['amount']),
            '/admin.php?tab=subscription&sub=subscribers&view=' . (int)$sub['id']
        );
    } catch (Throwable $e) { /* best-effort */ }

    $pdo->prepare('UPDATE orders SET fulfilled = 1, status = IF(status<>"paid","paid",status) WHERE id = ?')
        ->execute([(int)$order['id']]);
}

} // function_exists guard

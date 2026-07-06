<?php
/*
 * Receipt + Invoice PDF generators — used by send_email() to attach
 * proper, professionally-formatted PDFs to every paid order email.
 *
 * Layout closely mirrors the reference Emergent receipt / invoice style
 * the product owner provided: clean sans-serif, two-column header with
 * company info on the left + brand logo / receipt number on the right,
 * "Bill to" customer block, single line-items table with right-aligned
 * currency, summary totals, payment-history table (for the receipt
 * variant only), and a clear statement-name line so the customer knows
 * what to look for on their bank statement.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/functions.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Resolve the company logo to a local file path that Dompdf can embed.
 * Falls back to the bundled email logo if no company logo is configured
 * (or the configured URL points to an external host — Dompdf is sandboxed
 * to the local filesystem via isRemoteEnabled=false).
 */
function _pdf_company_logo_path(): string
{
    $fallback = __DIR__ . '/../assets/images/brand/email-logo.gif';
    $logoSetting = function_exists('company_info') ? (string)(company_info()['logo'] ?? '') : '';
    if ($logoSetting === '') return $fallback;

    // Already an absolute filesystem path?
    if ($logoSetting[0] === '/' && file_exists($logoSetting)) return $logoSetting;

    // Strip the site URL prefix if the operator pasted a full URL.
    $rel = $logoSetting;
    $candidates = [
        function_exists('site_url') ? rtrim((string)site_url(), '/') : '',
        function_exists('base_url') ? rtrim((string)base_url(), '/') : '',
    ];
    foreach (array_filter($candidates) as $c) {
        if (str_starts_with($rel, $c)) { $rel = substr($rel, strlen($c)); break; }
    }
    $rel = ltrim((string)$rel, '/');
    $abs = __DIR__ . '/../' . $rel;
    return (file_exists($abs) && !is_dir($abs)) ? $abs : $fallback;
}

/**
 * Build a Dompdf instance with sane defaults for our receipts/invoices.
 */
function _pdf_dompdf(): Dompdf
{
    $o = new Options();
    $o->set('defaultFont',           'DejaVu Sans');   // ships with Dompdf
    $o->set('isHtml5ParserEnabled',  true);
    $o->set('isRemoteEnabled',       false);           // we never load remote assets
    $o->set('chroot',                __DIR__ . '/..'); // keep file access local
    return new Dompdf($o);
}

/**
 * Number → currency formatter that matches what we show on the site
 * (uses the symbol of the order's currency, not the active session one).
 */
function _pdf_money(float $amount, string $cur = 'USD'): string
{
    $sym = ['USD'=>'$','GBP'=>'£','EUR'=>'€','CAD'=>'CA$','AUD'=>'A$','INR'=>'₹','AED'=>'د.إ'][$cur] ?? '';
    return $sym . number_format($amount, 2);
}

/**
 * Detect the dominant brand for an order from its line items.
 * Used to pick the right watermark on the Receipt / Invoice.
 *
 * Order of precedence:
 *   1) products.brand column (DB-authoritative)
 *   2) keyword scan of the product name
 *   3) generic 'maventech' fallback (our own brand mark)
 */
function _pdf_brand_for_items(array $items): string
{
    if (empty($items)) return 'maventech';
    // Honour an explicit `brand` field if the caller passes it.
    foreach ($items as $it) {
        $b = strtolower(trim((string)($it['brand'] ?? '')));
        if ($b !== '') {
            foreach (['microsoft','bitdefender','mcafee','norton','kaspersky','eset','adobe','autodesk','corel','parallels'] as $known) {
                if (str_contains($b, $known)) return $known;
            }
        }
    }
    // Fall back to keyword scan on the first item's name.
    $name = strtolower(trim((string)($items[0]['name'] ?? $items[0]['product_name'] ?? '')));
    foreach ([
        'bitdefender' => ['bitdefender'],
        'mcafee'      => ['mcafee'],
        'norton'      => ['norton'],
        'kaspersky'   => ['kaspersky'],
        'eset'        => ['eset'],
        'adobe'       => ['adobe', 'acrobat', 'photoshop'],
        'autodesk'    => ['autocad', 'autodesk'],
        'corel'       => ['corel'],
        'parallels'   => ['parallels'],
        'microsoft'   => ['microsoft', 'office', 'windows', 'visio', 'project', 'excel', 'word', 'powerpoint', 'outlook'],
    ] as $brandKey => $needles) {
        foreach ($needles as $n) {
            if (str_contains($name, $n)) return $brandKey;
        }
    }
    return 'maventech';
}

/**
 * Build the HTML for a colourful product-icon "scatter" watermark.
 *
 * For Microsoft orders we tile actual app icons (Word/Excel/PowerPoint/
 * Outlook/OneNote/Teams/Windows) in their real brand colours at ~14%
 * opacity, rotated lightly and positioned at deterministic but
 * scattered-looking coordinates across the page.  Feels like a Microsoft
 * marketing piece rather than a sterile invoice.
 *
 * For non-Microsoft brands we fall back to repeating the single brand
 * silhouette (already pre-rendered in /assets/images/brand-watermarks/)
 * in a softer scatter — still adds the "brand feel" but without 7 different
 * logos that don't exist for non-Microsoft brands.
 */
function _pdf_brand_scatter_html(string $brandKey): string
{
    $brandKey = strtolower($brandKey);
    // Deterministic scatter positions (top, left in %).  Picked by hand to
    // look balanced across the page — slight rotation per piece adds life.
    // Format: [top_pct, left_pct, size_px, rotate_deg, icon_filename].
    if ($brandKey === 'microsoft') {
        $dir = __DIR__ . '/../assets/images/brand-watermarks/microsoft-suite';
        $scatter = [
            // Top band
            [ 7,  6, 56, -10, 'word.png'],
            [12, 78, 68,   6, 'excel.png'],
            // Upper-middle
            [22, 32, 60,  12, 'outlook.png'],
            [27, 64, 54,  -8, 'powerpoint.png'],
            // Middle band
            [38, 12, 64,  -4, 'teams.png'],
            [42, 50, 70,  18, 'windows.png'],
            [48, 84, 56,  -6, 'onenote.png'],
            // Lower-middle
            [60, 22, 58,   8, 'access.png'],
            [64, 70, 62, -14, 'word.png'],
            // Bottom band
            [78, 10, 54,  10, 'excel.png'],
            [82, 46, 60,  -3, 'powerpoint.png'],
            [86, 78, 56,  16, 'outlook.png'],
        ];
        $items = [];
        foreach ($scatter as $s) {
            [$top, $left, $size, $rot, $icon] = $s;
            $path = $dir . '/' . $icon;
            if (!is_file($path)) continue;
            $items[] = sprintf(
                '<img class="scatter-icon" style="top:%d%%;left:%d%%;width:%dpx;height:%dpx;transform:rotate(%ddeg);" src="%s" alt="">',
                $top, $left, $size, $size, $rot, $path
            );
        }
        return '<div class="scatter-wrap">' . implode('', $items) . '</div>';
    }

    // Non-Microsoft brands — softer 5-icon scatter using the single brand
    // silhouette.  Still adds branded feel; no need to manufacture fake
    // sub-brand icons.
    $path = _pdf_brand_watermark_path($brandKey);
    if (!is_file($path)) return '';
    $scatter = [
        [10, 12, 72, -10],
        [22, 70, 84,  12],
        [44, 32, 96,  -5],
        [62, 76, 80,  16],
        [78, 18, 76,  -8],
    ];
    $items = [];
    foreach ($scatter as $s) {
        [$top, $left, $size, $rot] = $s;
        $items[] = sprintf(
            '<img class="scatter-icon" style="top:%d%%;left:%d%%;width:%dpx;height:%dpx;transform:rotate(%ddeg);" src="%s" alt="">',
            $top, $left, $size, $size, $rot, $path
        );
    }
    return '<div class="scatter-wrap">' . implode('', $items) . '</div>';
}

/**
 * Return the absolute filesystem path to the brand-watermark PNG for the
 * given brand key.  All watermarks are 600×600 dark-grey silhouettes
 * pre-rendered into /assets/images/brand-watermarks/ so Dompdf can embed
 * them locally (we keep `isRemoteEnabled` false).  Unknown brand keys
 * fall back to the Maventech "M" mark.
 */
function _pdf_brand_watermark_path(string $brandKey): string
{
    $dir = __DIR__ . '/../assets/images/brand-watermarks';
    $key = strtolower(trim($brandKey));
    $path = $dir . '/' . $key . '.png';
    if (is_file($path)) return $path;
    // Fallback to our own brand mark for any unknown / missing brand.
    return $dir . '/maventech.png';
}

/**
 * Generate a QR-code PNG (base64-encoded data URI) that links to the
 * customer's Order History entry pre-filled with their email + order
 * number.  Returned as a `data:image/png;base64,...` URI so Dompdf can
 * embed it directly without a remote fetch (and without writing yet
 * another tmp file on disk).  Returns '' if encoding fails — caller
 * gracefully omits the QR in that case.
 */
function _pdf_order_history_qr(array $order): string
{
    if (empty($order['email']) || empty($order['order_number'])) return '';
    if (!class_exists(\chillerlan\QRCode\QRCode::class)) return '';
    $url = rtrim(function_exists('site_url') ? site_url() : '', '/')
         . '/order-history.php?email=' . rawurlencode((string)$order['email'])
         . '&order=' . rawurlencode((string)$order['order_number']);
    try {
        $opts = new \chillerlan\QRCode\QROptions([
            // Auto-size QR to fit any URL length — version 1..10 covers up
            // to a few hundred chars at ECC level M, plenty for our URL.
            'versionMin'          => 5,
            'versionMax'          => 10,
            'eccLevel'            => \chillerlan\QRCode\Common\EccLevel::M,
            'scale'               => 4,
            'imageBase64'         => true,
            'imageTransparent'    => false,
            // PNG via GD — yields a `data:image/png;base64,...` URI that
            // Dompdf embeds without needing remote-fetch.
            'outputInterface'     => \chillerlan\QRCode\Output\QRGdImagePNG::class,
        ]);
        return (new \chillerlan\QRCode\QRCode($opts))->render($url);
    } catch (Throwable $e) {
        error_log('[pdf-qr] ' . $e->getMessage());
        return '';
    }
}

/**
 * Shared HTML head + brand header used by both Receipt and Invoice.
 * Variant: 'receipt' or 'invoice' — only the title + sub-line change.
 */
function _pdf_shell(array $ctx, string $bodyHtml): string
{
    $co       = $ctx['co'];
    $brand    = htmlspecialchars($co['name']    ?? 'Maventech', ENT_QUOTES, 'UTF-8');
    $brandAddr= nl2br(htmlspecialchars($co['address'] ?? '',             ENT_QUOTES, 'UTF-8'));
    $brandEm  = htmlspecialchars($co['email']   ?? '',                   ENT_QUOTES, 'UTF-8');
    $logoUrl  = $ctx['logo']  ?? '';   // local file path is fine for Dompdf
    $docTitle = htmlspecialchars($ctx['title'] ?? 'Document',            ENT_QUOTES, 'UTF-8');
    $invNo    = htmlspecialchars($ctx['invoice_number'] ?? '',           ENT_QUOTES, 'UTF-8');
    // Brand-specific colourful scattered watermark (Microsoft suite icons
    // for Microsoft orders; soft repeated brand mark for other brands).
    $brandKey  = $ctx['brand_key'] ?? 'maventech';
    $scatterHtml = _pdf_brand_scatter_html($brandKey);
    // Personalised greeting at the top of the document — pulled from the
    // customer's first name.  Adds a human touch without changing any
    // structural layout.
    $firstName = trim((string)($ctx['first_name'] ?? ''));
    $greetHtml = '';
    if ($firstName !== '') {
        $greetHtml = '<div class="thank-you">Thank you, '
                   . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8')
                   . '!</div>';
    }
    // Diagonal "PAID" / "INVOICE" / "DUE" stamp under the brand logo —
    // accounting-software vibe.  Caller passes `stamp_text` + `stamp_color`
    // (defaults to dark grey; green for PAID, amber for DUE).
    $stampText  = trim((string)($ctx['stamp_text']  ?? ''));
    $stampColor = (string)($ctx['stamp_color'] ?? '#1f2937');
    $stampHtml  = '';
    if ($stampText !== '') {
        $stampHtml = '<div class="stamp" style="color:' . htmlspecialchars($stampColor, ENT_QUOTES, 'UTF-8') . ';border-color:' . htmlspecialchars($stampColor, ENT_QUOTES, 'UTF-8') . ';">'
                   . htmlspecialchars($stampText, ENT_QUOTES, 'UTF-8')
                   . '</div>';
    }
    // Bottom-right QR — links to the customer's Order History entry with
    // email + order number pre-filled.  Anyone holding the printed copy
    // (accountant, auditor, finance team) can scan and get a fresh PDF
    // on the spot — no need to email support.
    $qrDataUri = (string)($ctx['qr_data_uri'] ?? '');
    $qrHtml    = '';
    if ($qrDataUri !== '') {
        $qrHtml = '<div class="qr-stamp">'
                . '  <img src="' . $qrDataUri . '" alt="QR code">'
                . '  <div class="qr-label">Scan to re-download<br>Receipt &amp; Invoice</div>'
                . '</div>';
    }
    // Active vibe-schedule promo banner — admin-defined label + optional
    // logo upload.  Renders as a thin red bar at the top of every PDF
    // generated while the schedule is live (e.g. "BLACK FRIDAY SALE").
    $promoBarHtml = '';
    if (function_exists('active_vibe_promo')) {
        $promo = active_vibe_promo();
        if ($promo && trim((string)$promo['label']) !== '') {
            $promoLabel = htmlspecialchars((string)$promo['label'], ENT_QUOTES, 'UTF-8');
            $promoLogo  = '';
            $promoLogoFile = (string)($promo['logo_file'] ?? '');
            if ($promoLogoFile !== '' && is_file($promoLogoFile) && !preg_match('/\.svg$/i', $promoLogoFile)) {
                $promoLogo = '<img src="' . htmlspecialchars($promoLogoFile, ENT_QUOTES, 'UTF-8') . '" alt="" style="height:22px;width:auto;vertical-align:middle;background:#fff;border-radius:4px;padding:2px;margin-right:8px;">';
            }
            $promoCoupon = '';
            $code = strtoupper(trim((string)($promo['coupon_code'] ?? '')));
            $pct  = (int)($promo['coupon_percent'] ?? 0);
            if ($code !== '' && $pct > 0) {
                $promoCoupon = '<span style="display:inline-block;margin-left:12px;font-size:10pt;font-weight:600;letter-spacing:.4px;text-transform:none;color:#fcd34d;">Use <span style="background:#fbbf24;color:#0f172a;padding:1px 6px;border-radius:4px;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</span> · ' . $pct . '% off</span>';
            }
            $promoBarHtml = '<div class="promo-bar" style="background:#0f172a;color:#fff;padding:8px 14px;border-radius:8px;text-align:center;font-weight:800;letter-spacing:.6px;font-size:11pt;margin:0 0 14px;text-transform:uppercase;border-left:3px solid #fbbf24;">'
                          . $promoLogo . $promoLabel . $promoCoupon . '</div>';
        }
    }
    $secondRow= '';
    if (!empty($ctx['receipt_number'])) {
        $secondRow .= '<tr><td>Receipt number</td><td class="r">' . htmlspecialchars($ctx['receipt_number'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    if (!empty($ctx['date_paid'])) {
        $secondRow .= '<tr><td>Date paid</td><td class="r">' . htmlspecialchars($ctx['date_paid'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    if (!empty($ctx['date_issued'])) {
        $secondRow .= '<tr><td>Date of issue</td><td class="r">' . htmlspecialchars($ctx['date_issued'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    if (!empty($ctx['date_due'])) {
        $secondRow .= '<tr><td>Date due</td><td class="r">'  . htmlspecialchars($ctx['date_due'], ENT_QUOTES, 'UTF-8')  . '</td></tr>';
    }
    $billLines = '';
    foreach ((array)($ctx['bill_to'] ?? []) as $line) {
        $billLines .= '<div>' . htmlspecialchars((string)$line, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    $logoTag = $logoUrl && file_exists($logoUrl)
        ? '<img src="' . $logoUrl . '" alt="' . $brand . '" style="height:44px;width:auto;vertical-align:top;">'
        : '<div style="font-size:18px;font-weight:800;color:#06b6d4;letter-spacing:.5px;">' . $brand . '</div>';

    return <<<HTML
<!doctype html>
<html><head><meta charset="utf-8">
<style>
  @page { margin: 56px 48px; }
  body  { font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif; font-size: 10.5pt; color: #1f2937; }
  h1    { font-size: 22pt; font-weight: 700; margin: 0 0 14px; color: #0f172a; letter-spacing: .3px; }
  .head-grid { width: 100%; border-collapse: collapse; margin-bottom: 26px; }
  .head-grid td { vertical-align: top; }
  .head-meta { width: 50%; }
  .head-meta table { width: 100%; border-collapse: collapse; font-size: 9.5pt; color: #475569; }
  .head-meta table td { padding: 2px 0; }
  .head-meta table td.r { text-align: right; color: #0f172a; font-weight: 600; }
  .head-brand { width: 50%; text-align: right; }
  .head-brand .brand-line { margin-top: 6px; font-size: 9pt; color: #64748b; line-height: 1.45; }

  .from-bill { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
  .from-bill td { vertical-align: top; width: 50%; padding-right: 12px; font-size: 9.5pt; color: #1f2937; }
  .from-bill .label { font-size: 8pt; text-transform: uppercase; letter-spacing: 1.2px; color: #94a3b8; font-weight: 700; margin-bottom: 4px; }
  .from-bill .bold  { color: #0f172a; font-weight: 700; }

  .amount-banner { background: #f8fafc; border-left: 4px solid #06b6d4; padding: 14px 16px; margin-bottom: 22px; }
  .amount-banner .amt { font-size: 18pt; font-weight: 700; color: #0f172a; }
  .amount-banner .sub { font-size: 9pt; color: #64748b; margin-top: 2px; }

  table.items, table.payhist { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
  table.items th, table.items td, table.payhist th, table.payhist td { padding: 9px 4px; font-size: 9.5pt; }
  table.items thead, table.payhist thead { border-bottom: 2px solid #0f172a; }
  table.items th, table.payhist th { text-align: left; font-weight: 700; color: #0f172a; font-size: 9pt; text-transform: uppercase; letter-spacing: .5px; }
  table.items td, table.payhist td { border-bottom: 1px solid #e2e8f0; }
  table.items td.num, table.items th.num, table.payhist td.num, table.payhist th.num { text-align: right; }
  .totals { width: 50%; margin-left: 50%; border-collapse: collapse; font-size: 10pt; }
  .totals td { padding: 5px 4px; }
  .totals td.label { color: #475569; }
  .totals td.value { text-align: right; color: #0f172a; font-weight: 600; }
  .totals tr.total-row td { border-top: 2px solid #0f172a; padding-top: 9px; font-size: 11.5pt; font-weight: 700; color: #0f172a; }
  .totals tr.amount-paid td { padding-top: 9px; color: #047857; font-weight: 700; }
  .totals tr.amount-due td { padding-top: 9px; color: #b91c1c; font-weight: 700; }

  .statement {
    background: #fff7ed; border: 1px solid #fdba74; border-left: 4px solid #f59e0b;
    padding: 10px 14px; border-radius: 10px; margin: 22px 0; font-size: 9.5pt; color: #7c2d12;
  }
  .statement .lbl { font-weight: 700; color: #7c2d12; }
  .statement .hl { background: #fde68a; color: #9a3412; font-weight: 700; padding: 1px 7px; border-radius: 5px; }

  .footer {
    margin-top: 14px; padding-top: 10px; border-top: 1px solid #e2e8f0;
    font-size: 8pt; color: #94a3b8; line-height: 1.6;
  }

  /* Colourful scattered brand watermark — Microsoft suite icons (or the
     single brand silhouette for non-Microsoft) tiled at low opacity behind
     the content.  Position:absolute on each <img> with deterministic top/
     left % values gives a "scattered marketing piece" feel without hurting
     readability.  Light opacity (≈14%) keeps text fully legible. */
  .scatter-wrap {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    width: 100%; height: 100%;
  }
  .scatter-icon {
    position: absolute;
    opacity: 0.14;
  }

  /* Personalised greeting above the doc title — friendly, premium touch. */
  .thank-you {
    font-size: 11pt;
    font-weight: 600;
    color: #0f766e;        /* teal — picks up the brand accent */
    margin: 0 0 4px;
    letter-spacing: .2px;
  }

  /* Diagonal "PAID" / "INVOICE" / "DUE" stamp sitting underneath the
     brand watermark — accounting-software vibe.  Subtle (12% opacity)
     and rotated -22° so it reads naturally without screaming.  Border +
     padding form the classic rubber-stamp look. */
  .stamp {
    position: absolute;
    top: 480px; left: 50%;
    margin-left: -130px;
    width: 260px;
    text-align: center;
    font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif;
    font-weight: 900;
    font-size: 44pt;
    letter-spacing: 6px;
    padding: 10px 16px;
    border: 6px solid #1f2937;
    border-radius: 12px;
    opacity: 0.12;
    transform: rotate(-22deg);
  }
  /* QR — sits in its own block at the very bottom of the document so a
     printed copy can be scanned back to the customer's Order History
     (email + order# pre-filled).  Right-aligned via a single-cell
     table so Dompdf (which is finicky with floats) renders it reliably. */
  /* QR — sits in the empty right cell next to the "Bill to" block so a
     printed copy can be scanned back to the customer's Order History
     (email + order# pre-filled).  This keeps it inside the existing
     2-column header layout and guarantees it fits on page 1. */
  .from-bill td.qr-cell { text-align: right; vertical-align: top; }
  .qr-stamp {
    display: inline-block;
    width: 90px;
    text-align: center;
    font-family: Helvetica, Arial, sans-serif;
  }
  .qr-stamp img {
    width: 78px; height: 78px;
    display: block; margin: 0 auto;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 2px;
    background: #ffffff;
  }
  .qr-stamp .qr-label {
    margin-top: 4px;
    font-size: 6.5pt;
    color: #64748b;
    line-height: 1.25;
    letter-spacing: .2px;
  }
</style>
</head>
<body>
  {$scatterHtml}
  {$promoBarHtml}
  {$stampHtml}
  {$greetHtml}
  <h1>{$docTitle}</h1>
  <table class="head-grid"><tr>
    <td class="head-meta">
      <table>
        <tr><td>Invoice number</td><td class="r">{$invNo}</td></tr>
        {$secondRow}
      </table>
    </td>
    <td class="head-brand">
      {$logoTag}
      <div class="brand-line">
        <strong style="color:#0f172a;">{$brand}</strong><br>
        {$brandAddr}<br>
        {$brandEm}
      </div>
    </td>
  </tr></table>

  <table class="from-bill"><tr>
    <td><div class="label">Bill to</div>{$billLines}</td>
    <td class="qr-cell">{$qrHtml}</td>
  </tr></table>

  {$bodyHtml}

  <div class="footer">
    Questions? Reply to this email or visit our support page. Thanks for choosing {$brand}.
  </div>
</body></html>
HTML;
}

/**
 * Active vibe-schedule promo banner (shared by Receipt + Invoice).
 * Renders a thin coloured bar at the very top of the document when a
 * promo schedule is live.  Returns '' when there is none.
 */
function _pdf_promo_bar_html(): string
{
    if (!function_exists('active_vibe_promo')) return '';
    $promo = active_vibe_promo();
    if (!$promo || trim((string)$promo['label']) === '') return '';
    $promoLabel = htmlspecialchars((string)$promo['label'], ENT_QUOTES, 'UTF-8');
    $promoLogo  = '';
    $promoLogoFile = (string)($promo['logo_file'] ?? '');
    if ($promoLogoFile !== '' && is_file($promoLogoFile) && !preg_match('/\.svg$/i', $promoLogoFile)) {
        $promoLogo = '<img src="' . htmlspecialchars($promoLogoFile, ENT_QUOTES, 'UTF-8') . '" alt="" style="height:20px;width:auto;vertical-align:middle;background:#fff;border-radius:4px;padding:2px;margin-right:8px;">';
    }
    $promoCoupon = '';
    $code = strtoupper(trim((string)($promo['coupon_code'] ?? '')));
    $pct  = (int)($promo['coupon_percent'] ?? 0);
    if ($code !== '' && $pct > 0) {
        $promoCoupon = '<span style="display:inline-block;margin-left:12px;font-size:9.5pt;font-weight:600;color:#fcd34d;">Use <span style="background:#fbbf24;color:#0f172a;padding:1px 6px;border-radius:4px;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</span> · ' . $pct . '% off</span>';
    }
    return '<div style="background:#0f172a;color:#fff;padding:8px 14px;border-radius:8px;text-align:center;font-weight:800;letter-spacing:.6px;font-size:10.5pt;margin:0 0 14px;text-transform:uppercase;border-left:3px solid #fbbf24;">'
         . $promoLogo . $promoLabel . $promoCoupon . '</div>';
}

/**
 * Resolve a human "payment method" label + processing gateway for an order.
 * Returns [methodLabel, gatewayName].
 */
function _pdf_payment_method(array $order, ?array $payment = null): array
{
    $pmRaw   = strtolower(trim((string)($order['payment_method'] ?? 'card')));
    $gateway = $pmRaw === 'paypal'
        ? (setting_get('gw_paypal_provider', 'PayPal') ?: 'PayPal')
        : (setting_get('gw_card_provider', 'Stripe') ?: 'Stripe');
    if ($payment && !empty($payment['method'])) {
        $label = (string)$payment['method'];
    } elseif (!empty($order['card_brand'])) {
        $label = (string)$order['card_brand'] . (!empty($order['card_last4']) ? ' ····' . $order['card_last4'] : '');
    } elseif ($pmRaw === 'paypal') {
        $label = 'PayPal';
    } else {
        $label = 'Card' . (!empty($order['card_last4']) ? ' ····' . $order['card_last4'] : '');
    }
    return [$label, $gateway];
}

/**
 * Generate a Receipt PDF (paid orders) — a PAYMENT CONFIRMATION document,
 * intentionally styled completely differently from the tax Invoice: an
 * emerald "paid in full" hero, a payment-details card, and a light purchase
 * summary.  Returns the binary PDF string.  Throws on rendering failure.
 */
function generate_receipt_pdf(array $order, array $items, ?array $payment = null, string $extraBodyHtml = ''): string
{
    $e   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $co  = function_exists('company_info') ? company_info() : ['name' => 'Maventech'];
    $co['phone'] = function_exists('company_phone_for_country') ? company_phone_for_country($order['country'] ?? null) : ($co['phone'] ?? '');
    $cur = (string)($order['currency'] ?? 'USD');

    $orderNo   = (string)($order['order_number'] ?? '');
    $invoiceNo = function_exists('mv_invoice_number') ? mv_invoice_number($order) : $orderNo;
    $receiptNo = function_exists('mv_receipt_number') ? mv_receipt_number($order) : $orderNo;

    $datePaidRaw = $order['paid_at'] ?? $order['created_at'] ?? date('Y-m-d H:i:s');
    $datePaid    = date('F j, Y', strtotime($datePaidRaw));

    $custName = trim((string)($order['first_name'] ?? '') . ' ' . (string)($order['last_name'] ?? ''));
    $first    = trim((string)($order['first_name'] ?? ''));

    // Bill-to lines.
    $billTo = array_filter([
        $custName,
        (string)($order['email'] ?? ''),
        trim(((string)($order['address']  ?? '')) . (empty($order['address2']) ? '' : ', ' . $order['address2'])),
        trim(((string)($order['city']     ?? '')) . ', ' . ((string)($order['state'] ?? '')) . ' ' . ((string)($order['zip'] ?? ''))),
        (string)($order['country'] ?? ''),
    ], fn($l) => trim((string)$l) !== '');

    $stmtName = !empty($order['card_statement_name'])
        ? (string)$order['card_statement_name']
        : (function_exists('statement_name_for')
            ? (string)statement_name_for((string)($order['payment_method'] ?? 'card'))
            : (string)($co['name'] ?? 'Maventech'));

    [$payLabel, $gateway] = _pdf_payment_method($order, $payment);

    // Light purchase summary (chip rows, NOT a formal ledger).
    $subtotal = 0.0;
    $summaryRows = '';
    foreach ($items as $it) {
        $qty  = (int)($it['quantity'] ?? $it['qty'] ?? 1);
        $unit = (float)($it['unit_price'] ?? $it['price'] ?? 0);
        $amt  = $qty * $unit;
        $subtotal += $amt;
        $summaryRows .= '<tr>'
            . '<td class="ps-name">' . $e($it['name'] ?? $it['product_name'] ?? '—')
            . ' <span class="ps-qty">×' . $qty . '</span></td>'
            . '<td class="ps-amt">' . _pdf_money($amt, $cur) . '</td>'
            . '</tr>';
    }
    $total = (float)($order['total'] ?? $subtotal);
    $rcDiscount = max(0, $subtotal - $total);
    $rcTotalsExtra = '';
    if ($rcDiscount > 0.009) {
        $rcTotalsExtra = '<tr class="ps-t-min"><td class="lbl2">Subtotal</td><td class="val2">' . _pdf_money($subtotal, $cur) . '</td></tr>'
                       . '<tr class="ps-t-min"><td class="lbl2">Discount</td><td class="val2" style="color:#16a34a;">−' . _pdf_money($rcDiscount, $cur) . '</td></tr>';
    }

    $logoPath = _pdf_company_logo_path();
    $logoTag  = ($logoPath && file_exists($logoPath))
        ? '<img src="' . $logoPath . '" alt="' . $e($co['name'] ?? '') . '" style="height:40px;width:auto;">'
        : '<div style="font-size:17px;font-weight:800;color:#059669;">' . $e($co['name'] ?? 'Maventech') . '</div>';

    $promoBar   = _pdf_promo_bar_html();
    $scatter    = _pdf_brand_scatter_html(_pdf_brand_for_items($items));
    $qr         = _pdf_order_history_qr($order);
    $qrHtml     = $qr !== '' ? '<div class="rc-qr"><img src="' . $qr . '" alt="QR"><div class="rc-qr-lbl">Scan to view<br>your order online</div></div>' : '';

    $brandName = $e($co['name'] ?? 'Maventech');
    $brandAddr = nl2br($e($co['address'] ?? ''));
    $brandEm   = $e($co['email'] ?? '');
    $money     = fn($v) => _pdf_money($v, $cur);
    $amtBig    = $money($total);
    $billHtml  = implode('<br>', array_map($e, $billTo));

    $html = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
  @page { margin: 22px 36px; size: letter portrait; }
  body { font-family:'DejaVu Sans',Helvetica,Arial,sans-serif; font-size:9.5pt; color:#1f2937; }
  .scatter-wrap { position:absolute; top:0; left:0; right:0; bottom:0; width:100%; height:100%; }
  .scatter-icon { position:absolute; opacity:0.09; }
  .rc-top { width:100%; border-collapse:collapse; margin-bottom:8px; }
  .rc-top td { vertical-align:top; }
  .rc-top .rc-co { font-size:7.5pt; color:#64748b; line-height:1.4; margin-top:3px; }
  .rc-tag { text-align:right; }
  .rc-tag .lbl { font-size:8pt; letter-spacing:2px; font-weight:700; color:#059669; text-transform:uppercase; }
  .rc-hero { background:#ecfdf5; border:1px solid #a7f3d0; border-radius:12px; padding:9px 18px; margin-bottom:8px; text-align:center; }
  .rc-check { width:30px; height:30px; line-height:28px; border-radius:50%; background:#059669; color:#fff; font-size:15pt; font-weight:700; margin:0 auto 3px; }
  .rc-badge { display:inline-block; background:#059669; color:#fff; font-size:7pt; font-weight:700; letter-spacing:1.4px; text-transform:uppercase; padding:2px 9px; border-radius:999px; }
  .rc-amt { font-size:20pt; font-weight:800; color:#047857; margin:5px 0 2px; letter-spacing:.3px; }
  .rc-amt-sub { font-size:8.5pt; color:#059669; }
  .rc-card { border:1px solid #e2e8f0; border-radius:10px; padding:0; margin-bottom:8px; }
  .rc-card table { width:100%; border-collapse:collapse; }
  .rc-card td { padding:4px 12px; font-size:8.5pt; vertical-align:top; border-bottom:1px solid #eef2f7; }
  .rc-card tr:last-child td { border-bottom:0; }
  .rc-card .k { color:#64748b; width:38%; }
  .rc-card .v { color:#0f172a; font-weight:700; text-align:right; }
  .rc-card .v.mono { font-family:'DejaVu Sans Mono',monospace; letter-spacing:.3px; }
  .sec-label { font-size:7pt; letter-spacing:1.4px; text-transform:uppercase; color:#94a3b8; font-weight:700; margin:0 0 3px; }
  .ps { width:100%; border-collapse:collapse; margin-bottom:3px; }
  .ps td { padding:4px 2px; font-size:8.5pt; border-bottom:1px dashed #e2e8f0; }
  .ps .ps-name { color:#1f2937; }
  .ps .ps-qty { color:#94a3b8; font-size:7.5pt; }
  .ps .ps-amt { text-align:right; color:#0f172a; font-weight:700; white-space:nowrap; }
  .ps-total { width:100%; border-collapse:collapse; }
  .ps-total td { padding:4px 2px; font-size:10.5pt; font-weight:800; }
  .ps-total .lbl { color:#065f46; }
  .ps-total .val { text-align:right; color:#047857; }
  .ps-total .ps-t-min td { padding:1px 2px; font-size:8.5pt; font-weight:600; }
  .ps-total .ps-t-min .lbl2 { color:#64748b; }
  .ps-total .ps-t-min .val2 { text-align:right; color:#334155; }
  .rc-note { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:5px 11px; font-size:8pt; color:#166534; margin:6px 0; }
  .rc-note .hl { background:#dcfce7; color:#14532d; font-weight:700; padding:1px 6px; border-radius:4px; }
  .rc-cols { width:100%; border-collapse:collapse; margin-top:5px; }
  .rc-cols td { vertical-align:top; width:50%; padding-right:12px; font-size:8pt; color:#334155; line-height:1.35; }
  .rc-qr { text-align:right; }
  .rc-qr img { width:54px; height:54px; border:1px solid #e2e8f0; border-radius:6px; padding:2px; background:#fff; }
  .rc-qr-lbl { font-size:5.5pt; color:#64748b; margin-top:2px; line-height:1.2; }
  .rc-footer { margin-top:6px; padding-top:5px; border-top:1px solid #e2e8f0; font-size:7pt; color:#94a3b8; line-height:1.4; }
</style></head><body>
  {$scatter}
  {$promoBar}
  <table class="rc-top"><tr>
    <td>{$logoTag}<div class="rc-co"><strong style="color:#0f172a;">{$brandName}</strong><br>{$brandAddr}<br>{$brandEm}</div></td>
    <td class="rc-tag"><div class="lbl">Payment Receipt</div></td>
  </tr></table>

  <div class="rc-hero">
    <div class="rc-check">&#10003;</div>
    <div class="rc-badge">Paid in full</div>
    <div class="rc-amt">{$amtBig}</div>
    <div class="rc-amt-sub">Paid on {$datePaid} · Thank you, {$first}!</div>
  </div>

  <div class="rc-card"><table>
    <tr><td class="k">Receipt number</td><td class="v mono">{$receiptNo}</td></tr>
    <tr><td class="k">Order number</td><td class="v mono">{$orderNo}</td></tr>
    <tr><td class="k">Invoice reference</td><td class="v mono">{$invoiceNo}</td></tr>
    <tr><td class="k">Payment method</td><td class="v">{$payLabel} · via {$gateway}</td></tr>
    <tr><td class="k">Date paid</td><td class="v">{$datePaid}</td></tr>
    <tr><td class="k">Amount paid</td><td class="v" style="color:#047857;">{$amtBig}</td></tr>
  </table></div>

  <div class="sec-label">What you paid for</div>
  <table class="ps"><tbody>{$summaryRows}</tbody></table>
  <table class="ps-total">{$rcTotalsExtra}<tr><td class="lbl">Total paid</td><td class="val">{$amtBig}</td></tr></table>

  <div class="rc-note"><strong>Billing note:</strong> this charge appears as <span class="hl">{$stmtName}</span> on your card statement, processed securely via {$gateway}.</div>

  <table class="rc-cols"><tr>
    <td><div class="sec-label">Billed to</div>{$billHtml}</td>
    <td>{$qrHtml}</td>
  </tr></table>

  <div class="rc-footer">This receipt confirms payment has been received in full. A detailed tax invoice is attached separately. Questions? Reply to this email or contact {$brandEm}. — {$brandName}</div>
  {$extraBodyHtml}
</body></html>
HTML;

    $dompdf = _pdf_dompdf();
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

/**
 * Generate an Invoice PDF — a formal TAX INVOICE (issued at order time,
 * works for both paid and pending orders).  Deliberately different from the
 * Receipt: a document-style header, a bordered reference/meta box (Invoice #,
 * Order #, dates, status), a From/Bill-To split, a full itemised ledger with
 * a dark header, and terms.  Returns the binary PDF string.
 */
function generate_invoice_pdf(array $order, array $items): string
{
    $e   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $co  = function_exists('company_info') ? company_info() : ['name' => 'Maventech'];
    $co['phone'] = function_exists('company_phone_for_country') ? company_phone_for_country($order['country'] ?? null) : ($co['phone'] ?? '');
    $cur = (string)($order['currency'] ?? 'USD');

    $orderNo   = (string)($order['order_number'] ?? '');
    $invoiceNo = function_exists('mv_invoice_number') ? mv_invoice_number($order) : $orderNo;

    $dateIssued = date('F j, Y', strtotime((string)($order['created_at'] ?? 'now')));
    $dateDue    = $dateIssued;  // Digital goods — due on issue.
    $isPaid     = (string)($order['status'] ?? '') === 'paid';

    $billTo = array_filter([
        trim((string)($order['first_name'] ?? '') . ' ' . (string)($order['last_name'] ?? '')),
        (string)($order['email'] ?? ''),
        trim(((string)($order['address']  ?? '')) . (empty($order['address2']) ? '' : ', ' . $order['address2'])),
        trim(((string)($order['city']     ?? '')) . ', ' . ((string)($order['state'] ?? '')) . ' ' . ((string)($order['zip'] ?? ''))),
        (string)($order['country'] ?? ''),
    ], fn($l) => trim((string)$l) !== '');
    $billHtml = implode('<br>', array_map($e, $billTo));

    $stmtName = !empty($order['card_statement_name'])
        ? (string)$order['card_statement_name']
        : (function_exists('statement_name_for')
            ? (string)statement_name_for((string)($order['payment_method'] ?? 'card'))
            : (string)($co['name'] ?? 'Maventech'));

    $itemsHtml = '';
    $subtotal = 0.0;
    foreach ($items as $it) {
        $qty  = (int)($it['quantity'] ?? $it['qty'] ?? 1);
        $unit = (float)($it['unit_price'] ?? $it['price'] ?? 0);
        $amt  = $qty * $unit;
        $subtotal += $amt;
        $itemsHtml .= '<tr><td>' . $e($it['name'] ?? $it['product_name'] ?? '—')
                   . '</td><td class="num">' . $qty
                   . '</td><td class="num">' . _pdf_money($unit, $cur)
                   . '</td><td class="num">' . _pdf_money($amt, $cur) . '</td></tr>';
    }
    $total = (float)($order['total'] ?? $subtotal);
    $discount = max(0, $subtotal - $total);
    $discountRow = $discount > 0.009
        ? '<tr><td class="label">Discount</td><td class="value" style="color:#16a34a;">−' . _pdf_money($discount, $cur) . '</td></tr>'
        : '';

    $logoPath = _pdf_company_logo_path();
    $logoTag  = ($logoPath && file_exists($logoPath))
        ? '<img src="' . $logoPath . '" alt="' . $e($co['name'] ?? '') . '" style="height:42px;width:auto;">'
        : '<div style="font-size:17px;font-weight:800;color:#4f46e5;">' . $e($co['name'] ?? 'Maventech') . '</div>';

    $promoBar = _pdf_promo_bar_html();
    $scatter  = _pdf_brand_scatter_html(_pdf_brand_for_items($items));
    $qr       = _pdf_order_history_qr($order);
    $qrHtml   = $qr !== '' ? '<div class="inv-qr"><img src="' . $qr . '" alt="QR"><div class="inv-qr-lbl">Scan to re-download<br>this invoice</div></div>' : '';

    $brandName = $e($co['name'] ?? 'Maventech');
    $brandAddr = nl2br($e($co['address'] ?? ''));
    $brandEm   = $e($co['email'] ?? '');
    $brandPh   = $e($co['phone'] ?? '');
    $subTotalS = _pdf_money($subtotal, $cur);
    $totalS    = _pdf_money($total, $cur);
    $curE      = $e($cur);
    $stmtE     = $e($stmtName);

    $statusBadge = $isPaid
        ? '<span style="background:#dcfce7;color:#166534;font-weight:800;font-size:8.5pt;padding:3px 12px;border-radius:999px;letter-spacing:1px;">PAID</span>'
        : '<span style="background:#fee2e2;color:#991b1b;font-weight:800;font-size:8.5pt;padding:3px 12px;border-radius:999px;letter-spacing:1px;">DUE</span>';
    $amountRow = $isPaid
        ? '<tr class="amount-paid"><td class="label">Amount paid</td><td class="value">' . $totalS . ' ' . $curE . '</td></tr>'
        : '<tr class="amount-due"><td class="label">Amount due</td><td class="value">' . $totalS . ' ' . $curE . '</td></tr>';
    $stampText  = $isPaid ? 'PAID' : 'DUE';
    $stampColor = $isPaid ? '#16a34a' : '#dc2626';
    $termsLine  = $isPaid
        ? 'This invoice has been settled in full — no payment is due. Keep it for your records. The charge appears as <strong>' . $stmtE . '</strong> on your statement.'
        : 'Payment is due on receipt. Your license keys are delivered once payment is confirmed. The charge will appear as <strong>' . $stmtE . '</strong> on your statement.';

    $html = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
  @page { margin: 28px 40px; size: letter portrait; }
  body { font-family:'DejaVu Sans',Helvetica,Arial,sans-serif; font-size:10pt; color:#1f2937; }
  .scatter-wrap { position:absolute; top:0; left:0; right:0; bottom:0; width:100%; height:100%; }
  .scatter-icon { position:absolute; opacity:0.08; }
  .inv-stamp { position:absolute; top:400px; left:50%; margin-left:-120px; width:240px; text-align:center;
      font-weight:900; font-size:38pt; letter-spacing:7px; padding:6px 12px; border:6px solid {$stampColor};
      color:{$stampColor}; border-radius:12px; opacity:0.11; transform:rotate(-20deg); }
  .inv-head { width:100%; border-collapse:collapse; margin-bottom:12px; }
  .inv-head td { vertical-align:top; }
  .inv-title { font-size:22pt; font-weight:800; color:#312e81; letter-spacing:1px; line-height:1; }
  .inv-sub { font-size:8pt; letter-spacing:2.5px; color:#6366f1; font-weight:700; text-transform:uppercase; margin-top:2px; }
  .inv-brand { text-align:right; }
  .inv-brand .co { font-size:8pt; color:#64748b; line-height:1.45; margin-top:4px; }
  .inv-meta { width:100%; border-collapse:collapse; border:1px solid #e0e7ff; border-radius:10px; margin-bottom:12px; background:#f5f3ff; }
  .inv-meta td { padding:5px 14px; font-size:8.5pt; border-bottom:1px solid #e0e7ff; }
  .inv-meta tr:last-child td { border-bottom:0; }
  .inv-meta .k { color:#64748b; }
  .inv-meta .v { text-align:right; color:#0f172a; font-weight:700; }
  .inv-meta .v.mono { font-family:'DejaVu Sans Mono',monospace; }
  .parties { width:100%; border-collapse:collapse; margin-bottom:12px; }
  .parties td { vertical-align:top; width:50%; padding-right:14px; font-size:8.5pt; color:#334155; line-height:1.4; }
  .parties .label { font-size:7.5pt; text-transform:uppercase; letter-spacing:1.2px; color:#94a3b8; font-weight:700; margin-bottom:3px; }
  .parties .bold { color:#0f172a; font-weight:700; }
  .parties .inv-qr { text-align:left; margin-top:4px; }
  .parties .inv-qr img { width:55px; height:55px; border:1px solid #e2e8f0; border-radius:6px; padding:2px; background:#fff; }
  .parties .inv-qr-lbl { font-size:6pt; color:#64748b; margin-top:2px; line-height:1.2; }
  table.items { width:100%; border-collapse:collapse; margin-bottom:10px; }
  table.items th, table.items td { padding:6px 6px; font-size:9pt; }
  table.items thead th { background:#312e81; color:#fff; text-align:left; font-weight:700; font-size:8pt; text-transform:uppercase; letter-spacing:.5px; }
  table.items thead th:first-child { border-top-left-radius:6px; }
  table.items thead th:last-child { border-top-right-radius:6px; }
  table.items td { border-bottom:1px solid #e2e8f0; }
  table.items td.num, table.items th.num { text-align:right; }
  .totals { width:52%; margin-left:48%; border-collapse:collapse; font-size:9.5pt; }
  .totals td { padding:4px 6px; }
  .totals td.label { color:#475569; }
  .totals td.value { text-align:right; color:#0f172a; font-weight:600; }
  .totals tr.total-row td { border-top:2px solid #312e81; padding-top:6px; font-size:11pt; font-weight:800; color:#312e81; }
  .totals tr.amount-paid td { padding-top:5px; color:#047857; font-weight:800; }
  .totals tr.amount-due td { padding-top:5px; color:#b91c1c; font-weight:800; }
  .inv-terms { margin-top:10px; background:#eef2ff; border:1px solid #c7d2fe; border-radius:8px; padding:7px 12px; font-size:8.5pt; color:#3730a3; }
  .inv-footer { margin-top:8px; padding-top:6px; border-top:1px solid #e2e8f0; font-size:7.5pt; color:#94a3b8; line-height:1.5; }
</style></head><body>
  {$scatter}
  {$promoBar}
  <div class="inv-stamp">{$stampText}</div>
  <table class="inv-head"><tr>
    <td><div class="inv-title">INVOICE</div><div class="inv-sub">Tax Invoice</div></td>
    <td class="inv-brand">{$logoTag}<div class="co"><strong style="color:#0f172a;">{$brandName}</strong><br>{$brandAddr}<br>{$brandEm}<br>{$brandPh}</div></td>
  </tr></table>

  <table class="inv-meta">
    <tr><td class="k">Invoice number</td><td class="v mono">{$invoiceNo}</td></tr>
    <tr><td class="k">Order number</td><td class="v mono">{$orderNo}</td></tr>
    <tr><td class="k">Date of issue</td><td class="v">{$dateIssued}</td></tr>
    <tr><td class="k">Date due</td><td class="v">{$dateDue}</td></tr>
    <tr><td class="k">Status</td><td class="v">{$statusBadge}</td></tr>
  </table>

  <table class="parties"><tr>
    <td><div class="label">From</div><span class="bold">{$brandName}</span><br>{$brandAddr}<br>{$brandEm}</td>
    <td><div class="label">Bill to</div>{$billHtml}{$qrHtml}</td>
  </tr></table>

  <table class="items">
    <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
    <tbody>{$itemsHtml}</tbody>
  </table>

  <table class="totals">
    <tr><td class="label">Subtotal</td><td class="value">{$subTotalS}</td></tr>
    {$discountRow}
    <tr class="total-row"><td class="label">Total</td><td class="value">{$totalS} {$curE}</td></tr>
    {$amountRow}
  </table>

  <div class="inv-terms"><strong>Terms:</strong> {$termsLine}</div>
  <div class="inv-footer">This is a computer-generated tax invoice for order {$orderNo}. A separate payment receipt confirms funds received. Questions? Contact {$brandEm}. — {$brandName}</div>
</body></html>
HTML;

    $dompdf = _pdf_dompdf();
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

/**
 * Save both PDFs to /uploads/order-pdfs/{order_id}/ and return their
 * absolute paths so send_email() can attach them.  Idempotent — overwrites
 * existing files if called repeatedly for the same order.
 */
function generate_order_pdfs(array $order, array $items): array
{
    $dir = __DIR__ . '/../uploads/order-pdfs/' . (int)($order['id'] ?? 0);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $rcptPath = $dir . '/Receipt-'   . (string)($order['order_number'] ?? 'X') . '.pdf';
    $invPath  = $dir . '/Invoice-'   . (string)($order['order_number'] ?? 'X') . '.pdf';
    try {
        @file_put_contents($rcptPath, generate_receipt_pdf($order, $items));
    } catch (Throwable $e) { @error_log('[pdf receipt] ' . $e->getMessage()); $rcptPath = ''; }
    try {
        @file_put_contents($invPath,  generate_invoice_pdf($order, $items));
    } catch (Throwable $e) { @error_log('[pdf invoice] ' . $e->getMessage()); $invPath  = ''; }
    return array_values(array_filter([$rcptPath, $invPath]));
}

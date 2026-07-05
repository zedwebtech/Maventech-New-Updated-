<?php
/* ============================================================================
 *  install-guide.php  —  Maventech's own on-site installation & activation
 *  guide for a single product (?slug=<product-slug>).
 *
 *  Renders a branded, step-by-step walkthrough with a visual flowchart, real
 *  screenshots (served locally from /uploads/guides/), system requirements and
 *  an activation section. Personalised with the product's name + its own
 *  one-click installer / activation links pulled from the products table.
 *  Adds HowTo JSON-LD so the steps are eligible for Google rich results.
 *  ========================================================================== */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/install-guides.php';

$slug     = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
$product  = $slug !== '' ? get_product($slug) : null;
$template = $product ? mv_guide_template_for_slug((string)$product['slug']) : null;

/* ---- Graceful fallback when there's no native guide for this product ---- */
if (!$product || !$template) {
    http_response_code($product ? 200 : 404);
    $pageTitle       = 'Installation Guide | ' . (defined('SITE_BRAND') ? SITE_BRAND : 'Maventech');
    $pageDescription = 'Step-by-step installation and activation guides for genuine Microsoft software.';
    $noIndex = true;
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="container py-5 text-center" style="max-width:720px;">
      <i class="bi bi-journal-text text-primary" style="font-size:3rem;"></i>
      <h1 class="h3 fw-bold mt-3">Installation guide</h1>
      <p class="text-secondary">We don&rsquo;t have a step-by-step guide for this item yet. Our team is happy to walk you through installation &mdash; just reach out and we&rsquo;ll help right away.</p>
      <div class="d-flex flex-wrap gap-2 justify-content-center mt-4">
        <a href="shop.php" class="btn btn-primary rounded-pill px-4">Browse products</a>
        <a href="contact.php" class="btn btn-outline-primary rounded-pill px-4">Contact support</a>
      </div>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$guides   = mv_install_guides();
$g        = $guides[$template];
$name     = (string)$product['name'];
$installer = trim((string)($product['installer_url']  ?? ''));
$activation= trim((string)($product['activation_url'] ?? ''));
$actHost   = $activation !== '' ? (parse_url($activation, PHP_URL_HOST) ?: $activation) : '';

/* Fill activation copy placeholders with the product's own links. */
$activationCopy = strtr($g['activation'], [
    '{{activation}}'      => esc($activation !== '' ? $activation : '#'),
    '{{activation_host}}' => esc($actHost !== '' ? $actHost : 'the official site'),
    '{{installer}}'       => esc($installer !== '' ? $installer : '#'),
]);

/* ---- SEO ---- */
$pageTitle       = 'How to Install ' . $name . ' — Step-by-step Guide';
$pageDescription = 'Install and activate ' . $name . ' in minutes: download the official installer, follow the illustrated steps, and enter your key. Free activation support from Maventech.';
$ogImage         = !empty($product['image']) ? to_public_url((string)$product['image']) : null;

/* ---- HowTo JSON-LD (built from the same steps shown below) ---- */
$howToSteps = [];
$pos = 0;
foreach ($g['steps'] as $st) {
    $pos++;
    $entry = [
        '@type'    => 'HowToStep',
        'position' => $pos,
        'name'     => $st['title'],
        'text'     => trim(strip_tags($st['html'])),
        'url'      => mv_guide_abs_url($slug) . '#step-' . $pos,
    ];
    if (!empty($st['img'])) {
        $entry['image'] = rtrim((string)site_url(), '/') . '/uploads/guides/' . $st['img'];
    }
    $howToSteps[] = $entry;
}
$howToJson = [
    '@context'    => 'https://schema.org',
    '@type'       => 'HowTo',
    'name'        => 'How to install and activate ' . $name,
    'description' => $pageDescription,
    'totalTime'   => 'PT10M',
    'tool'        => [['@type' => 'HowToTool', 'name' => $name . ' license key']],
    'step'        => $howToSteps,
];

include __DIR__ . '/includes/header.php';
?>
<script type="application/ld+json"><?= json_encode($howToJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>

<style>
.mv-guide{max-width:980px;margin:0 auto;}
.mv-guide .mv-hero{background:linear-gradient(135deg,#eff6ff,#f5f3ff);border:1px solid #e0e7ff;border-radius:20px;padding:28px 28px;}
.mv-guide .mv-hero h1{font-size:1.6rem;line-height:1.25;}
.mv-guide .mv-actions .btn{font-weight:600;}
/* Flowchart / stepper */
.mv-stepper{display:flex;flex-wrap:wrap;gap:8px 0;margin:6px 0 0;}
.mv-stepper .mv-step{flex:1 1 0;min-width:120px;text-align:center;position:relative;padding:0 4px;}
.mv-stepper .mv-step::before{content:"";position:absolute;top:23px;left:calc(-50% + 23px);width:calc(100% - 46px);height:3px;background:#cbd5e1;z-index:0;}
.mv-stepper .mv-step:first-child::before{display:none;}
.mv-stepper .circle{position:relative;z-index:1;width:48px;height:48px;border-radius:50%;background:#fff;border:2px solid #2563eb;color:#2563eb;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;font-size:1.15rem;box-shadow:0 4px 12px rgba(37,99,235,.12);}
.mv-stepper .mv-step .lbl{font-size:.78rem;font-weight:600;color:#334155;line-height:1.25;}
.mv-stepper .mv-step .sn{display:block;font-size:.68rem;color:#94a3b8;font-weight:700;letter-spacing:.04em;}
/* Step cards */
.mv-step-card{display:flex;gap:18px;padding:20px 0;border-top:1px solid #eef2f7;}
.mv-step-card:first-of-type{border-top:0;}
.mv-step-card .num{flex:0 0 auto;width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;font-weight:800;display:flex;align-items:center;justify-content:center;}
.mv-step-card .body{flex:1 1 auto;min-width:0;}
.mv-step-card h3{font-size:1.05rem;font-weight:700;margin:2px 0 6px;}
.mv-step-card .shot{margin-top:12px;}
.mv-step-card .shot img{max-width:100%;width:520px;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 6px 22px rgba(15,23,42,.08);}
.mv-sysreq li{margin-bottom:6px;}
@media (max-width:575px){.mv-stepper .mv-step{flex:1 1 33%;}.mv-step-card{gap:12px;}}
</style>

<div class="container py-4 py-md-5">
  <nav aria-label="breadcrumb" class="small mb-3" data-testid="guide-breadcrumb">
    <ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="index.php">Home</a></li>
      <li class="breadcrumb-item"><a href="<?= esc('product.php?slug=' . urlencode($slug)) ?>"><?= esc($name) ?></a></li>
      <li class="breadcrumb-item active" aria-current="page">Installation guide</li>
    </ol>
  </nav>

  <div class="mv-guide" data-testid="install-guide" data-template="<?= esc($template) ?>">

    <!-- Hero -->
    <div class="mv-hero mb-4">
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div style="min-width:0;">
          <div class="text-uppercase fw-bold text-primary small mb-1" style="letter-spacing:.08em;"><i class="bi bi-journal-text me-1"></i>Installation &amp; Activation Guide</div>
          <h1 class="fw-bold mb-2">How to install &amp; activate <?= esc($name) ?></h1>
          <p class="text-secondary mb-0">Follow these simple, illustrated steps to get <?= esc($name) ?> up and running on your <?= esc($g['platform']) ?> device. Takes about 10 minutes.</p>
        </div>
      </div>
      <div class="mv-actions d-flex flex-wrap gap-2 mt-3">
        <?php if ($installer !== ''): ?>
          <a href="<?= esc($installer) ?>" target="_blank" rel="nofollow noopener" class="btn rounded-pill px-4" data-testid="guide-download-btn" style="background:linear-gradient(135deg,#16a34a,#15803d) !important;color:#fff !important;border:0;"><i class="bi bi-box-arrow-down me-2"></i>Download installer</a>
        <?php endif; ?>
        <?php if ($activation !== ''): ?>
          <a href="<?= esc($activation) ?>" target="_blank" rel="nofollow noopener" class="btn btn-outline-primary rounded-pill px-4" data-testid="guide-activate-btn"><i class="bi bi-key me-2"></i>Activate / Sign in</a>
        <?php endif; ?>
        <a href="<?= esc('product.php?slug=' . urlencode($slug)) ?>" class="btn btn-link text-decoration-none px-2" data-testid="guide-back-btn"><i class="bi bi-arrow-left me-1"></i>Back to product</a>
      </div>
    </div>

    <!-- Flowchart -->
    <h2 class="h5 fw-bold mb-3"><i class="bi bi-diagram-3 text-primary me-2"></i>The installation at a glance</h2>
    <div class="card border-0 shadow-sm rounded-4 mb-4">
      <div class="card-body p-4">
        <div class="mv-stepper" data-testid="guide-flowchart">
          <?php foreach ($g['flow'] as $i => $f): ?>
            <div class="mv-step">
              <div class="circle"><i class="bi <?= esc($f['icon']) ?>"></i></div>
              <span class="sn">STEP <?= $i + 1 ?></span>
              <span class="lbl"><?= esc($f['label']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Step-by-step -->
    <h2 class="h5 fw-bold mb-2" id="steps"><i class="bi bi-list-check text-primary me-2"></i>Step-by-step instructions</h2>
    <div class="card border-0 shadow-sm rounded-4 mb-4">
      <div class="card-body p-4">
        <?php $n = 0; foreach ($g['steps'] as $st): $n++; ?>
          <div class="mv-step-card" id="step-<?= $n ?>" data-testid="guide-step-<?= $n ?>">
            <div class="num"><?= $n ?></div>
            <div class="body">
              <h3><?= esc($st['title']) ?></h3>
              <div class="text-secondary"><?= $st['html'] /* trusted original copy */ ?></div>
              <?php if (!empty($st['img'])): ?>
                <div class="shot">
                  <img src="<?= esc('/uploads/guides/' . $st['img']) ?>" alt="<?= esc($name . ' install step ' . $n . ': ' . $st['title']) ?>" loading="lazy" decoding="async">
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="row g-4">
      <!-- System requirements -->
      <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-4 h-100">
          <div class="card-body p-4">
            <h2 class="h6 fw-bold mb-3"><i class="bi bi-pc-display text-primary me-2"></i>System requirements</h2>
            <ul class="small text-secondary mv-sysreq mb-0">
              <?php foreach ($g['system'] as $req): ?>
                <li><?= esc($req) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
      <!-- License & activation -->
      <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-4 h-100">
          <div class="card-body p-4">
            <h2 class="h6 fw-bold mb-3"><i class="bi bi-shield-check text-primary me-2"></i>License &amp; activation</h2>
            <p class="small text-secondary mb-0"><?= $activationCopy /* trusted: links escaped above */ ?></p>
          </div>
        </div>
      </div>
    </div>

    <!-- Need help -->
    <div class="card border-0 shadow-sm rounded-4 mt-4" style="background:linear-gradient(135deg,#f0fdf4,#eff6ff);">
      <div class="card-body p-4 d-flex flex-wrap align-items-center gap-3">
        <div class="flex-grow-1">
          <h2 class="h6 fw-bold mb-1"><i class="bi bi-headset text-success me-2"></i>Stuck on a step?</h2>
          <p class="small text-secondary mb-0">Our order team can help with license-key delivery and activation questions. Reach us at <a href="mailto:<?= esc($brandEmail) ?>"><?= esc($brandEmail) ?></a><?php if (!empty($brandPhone)): ?> or call <strong><?= esc($brandPhone) ?></strong><?php endif; ?>.</p>
        </div>
        <a href="contact.php" class="btn btn-success rounded-pill px-4" data-testid="guide-help-btn">Get help</a>
      </div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
/**
 * includes/office-landing.php
 *
 * Shared, data-driven landing page for the "Microsoft Office <YEAR> Lifetime
 * License" pages. Modelled on the reference layout: hero with version imagery,
 * an edition selector (Home / Home & Business / Professional Plus), a
 * "What You Get" feature grid, an Office-vs-365 comparison, a 3-step
 * "How it works", trust cards, FAQ and a final CTA.
 *
 * Each route file (office-2024-lifetime-license.php, …2021…, …2019…) sets
 * $OFFICE_VERSION then requires this file.  Prices, names and box images are
 * pulled live from the products table so the page always matches the store.
 *
 * Buy buttons reuse the storefront's `.buy-now-btn` / `.add-to-cart-btn`
 * handlers (assets/js/main.js) via data-slug, so checkout works end-to-end.
 */
require_once __DIR__ . '/functions.php';

$VER = in_array(($OFFICE_VERSION ?? '2024'), ['2024', '2021', '2019'], true) ? $OFFICE_VERSION : '2024';

/* -------------------------------------------------------------------- */
/* Per-version configuration: editions map to REAL product slugs.       */
/* msrp = "retail" compare-at price used to compute the SAVE % badge.    */
/* -------------------------------------------------------------------- */
$CONFIG = [
    '2024' => [
        'intro' => 'Office 2024 is the latest one-time-purchase release — classic Word, Excel, PowerPoint and more, with no subscription and free lifetime updates.',
        'editions' => [
            ['slug'=>'microsoft-office-home-2024-pc',                'label'=>'Home',              'tagline'=>'Essential productivity apps',   'apps'=>'Word + Excel + PowerPoint',                       'msrp'=>199.99, 'badge'=>'Best Seller',   'platform'=>'Windows PC & macOS', 'pc_only'=>false],
            ['slug'=>'microsoft-office-home-business-2024-pc',       'label'=>'Home & Business',   'tagline'=>'Includes Outlook email',         'apps'=>'Word + Excel + PowerPoint + Outlook',             'msrp'=>249.99, 'badge'=>'Best Value',    'platform'=>'Windows PC & macOS', 'pc_only'=>false],
            ['slug'=>'microsoft-office-2024-professional-plus-windows','label'=>'Professional Plus','tagline'=>'All 5 apps + Access database',  'apps'=>'Word + Excel + PowerPoint + Outlook + Access',    'msrp'=>499.99, 'badge'=>'Most Powerful', 'platform'=>'Windows PC Only',    'pc_only'=>true],
        ],
    ],
    '2021' => [
        'intro' => 'Office 2021 delivers the apps you rely on as a single one-time purchase — perfect for a modern Windows PC with no recurring fees.',
        'editions' => [
            ['slug'=>'microsoft-office-2021-home-student-windows',   'label'=>'Home & Student',    'tagline'=>'Essential productivity apps',   'apps'=>'Word + Excel + PowerPoint',                       'msrp'=>149.99, 'badge'=>'Best Seller',   'platform'=>'Windows PC & macOS', 'pc_only'=>false],
            ['slug'=>'microsoft-office-2021-home-business-windows',  'label'=>'Home & Business',   'tagline'=>'Includes Outlook email',         'apps'=>'Word + Excel + PowerPoint + Outlook',             'msrp'=>279.99, 'badge'=>'Best Value',    'platform'=>'Windows PC & macOS', 'pc_only'=>false],
            ['slug'=>'microsoft-office-2021-professional-plus-windows','label'=>'Professional Plus','tagline'=>'All 5 apps + Access database',  'apps'=>'Word + Excel + PowerPoint + Outlook + Access',    'msrp'=>439.99, 'badge'=>'Most Powerful', 'platform'=>'Windows PC Only',    'pc_only'=>true],
        ],
    ],
    '2019' => [
        'intro' => 'Office 2019 is the proven one-time-purchase suite for Windows — own it forever with a genuine perpetual license and no subscription.',
        'editions' => [
            ['slug'=>'microsoft-office-2019-home-student-windows',   'label'=>'Home & Student',    'tagline'=>'Essential productivity apps',   'apps'=>'Word + Excel + PowerPoint',                       'msrp'=>129.99, 'badge'=>'Best Seller',   'platform'=>'Windows PC & macOS', 'pc_only'=>false],
            ['slug'=>'microsoft-office-2019-home-business-pc',       'label'=>'Home & Business',   'tagline'=>'Includes Outlook email',         'apps'=>'Word + Excel + PowerPoint + Outlook',             'msrp'=>229.99, 'badge'=>'Best Value',    'platform'=>'Windows PC & macOS', 'pc_only'=>false],
            ['slug'=>'microsoft-office-2019-professional-plus-windows','label'=>'Professional Plus','tagline'=>'All 5 apps + Access database',  'apps'=>'Word + Excel + PowerPoint + Outlook + Access',    'msrp'=>379.99, 'badge'=>'Most Powerful', 'platform'=>'Windows PC Only',    'pc_only'=>true],
        ],
    ],
];

$cfg = $CONFIG[$VER];

/* Hydrate each edition with the live product row (price, name, image). */
$editions = [];
foreach ($cfg['editions'] as $e) {
    $p = get_product($e['slug']);
    if (!$p || (int)($p['is_active'] ?? 1) !== 1) continue;
    $e['product']  = $p;
    $e['price']    = (float)$p['price'];
    $e['image']    = $p['image'] ?: '/uploads/products/' . $e['slug'] . '.webp';
    $e['save_pct'] = ($e['msrp'] > 0 && $e['price'] < $e['msrp'])
        ? (int)round((($e['msrp'] - $e['price']) / $e['msrp']) * 100) : 0;
    $editions[] = $e;
}

if (!$editions) { http_response_code(404); require __DIR__ . '/../404.php'; return; }

$minPrice   = min(array_map(fn($e) => $e['price'], $editions));
$maxMsrp    = max(array_map(fn($e) => $e['msrp'],  $editions));
$topSavePct = ($maxMsrp > 0) ? (int)round((($maxMsrp - $minPrice) / $maxMsrp) * 100) : 0;
$heroImg    = end($editions)['image'];   // Professional Plus box = hero
reset($editions);

/* Standard per-edition selling points. */
$stdBullets = [
    'Genuine Microsoft License Key',
    'One-Time Purchase — Own It Forever',
    'Free Lifetime Updates',
    'Instant Email Delivery',
    'Free Installation Support Included',
];

/* Shared feature-section imagery (sourced via vision expert). */
$featureImgs = [
    'https://images.unsplash.com/photo-1496181133206-80ce9b88a853?auto=format&fit=crop&w=900&q=70',
    'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=900&q=70',
    'https://images.pexels.com/photos/6694560/pexels-photo-6694560.jpeg?auto=compress&cs=tinysrgb&w=900',
    'https://images.unsplash.com/photo-1608222351212-18fe0ec7b13b?auto=format&fit=crop&w=900&q=70',
];

/* Cross-links to the other version landing pages. */
$otherVersions = array_values(array_filter(['2024','2021','2019'], fn($v) => $v !== $VER));

$pageTitle       = 'Buy Microsoft Office ' . $VER . ' — Genuine Lifetime License | ' . SITE_BRAND;
$pageDescription = 'Buy Microsoft Office ' . $VER . ' online — genuine lifetime license, one-time purchase, instant digital delivery. Word, Excel, PowerPoint and more. From ' . format_price($minPrice) . '.';
$curSym          = current_currency()['symbol'] ?? '$';

include __DIR__ . '/header.php';
?>
<div class="office-lp" data-testid="office-landing-<?= $VER ?>">

  <!-- ============================ HERO ============================ -->
  <section class="olp-hero">
    <div class="container">
      <div class="row align-items-center g-4">
        <div class="col-lg-6 order-lg-1 order-2">
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="olp-brand-badge"><i class="bi bi-microsoft me-1"></i>Microsoft</span>
            <span class="olp-pill"><i class="bi bi-patch-check-fill me-1"></i>100% Genuine</span>
          </div>
          <h1 class="olp-title">Microsoft Office <?= esc($VER) ?></h1>
          <p class="olp-subtitle">Lifetime License — One-Time Purchase</p>
          <div class="d-flex align-items-end gap-3 flex-wrap mb-3">
            <div>
              <div class="olp-retail">Retail price: <?= format_price($maxMsrp) ?></div>
              <div class="olp-price">From <?= format_price($minPrice) ?></div>
            </div>
            <?php if ($topSavePct > 0): ?><span class="olp-save">SAVE <?= $topSavePct ?>%</span><?php endif; ?>
          </div>
          <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="#editions" class="btn btn-orange-solid btn-lg rounded-pill px-4 fw-bold" data-testid="hero-buy-now"><i class="bi bi-cart-plus me-2"></i>Buy Now — From <?= format_price($minPrice) ?></a>
            <a href="#editions" class="btn btn-orange-outline btn-lg rounded-pill px-4">Compare Editions</a>
          </div>
          <ul class="olp-trust-row list-unstyled">
            <li><i class="bi bi-check-circle-fill"></i> 100% Genuine License</li>
            <li><i class="bi bi-envelope-check"></i> Instant Email Delivery</li>
            <li><i class="bi bi-shield-check"></i> 30-Day Money-Back</li>
            <li><i class="bi bi-infinity"></i> No Subscription Ever</li>
          </ul>
          <div class="olp-rating"><span class="olp-stars">★★★★★</span> 4.6/5 <span class="text-muted">(5,686+ verified reviews)</span></div>
        </div>

        <div class="col-lg-6 order-lg-2 order-1">
          <div class="olp-hero-media">
            <img id="olpHeroImg" src="<?= esc($heroImg) ?>" alt="Microsoft Office <?= esc($VER) ?> lifetime license" class="olp-hero-img" loading="eager" width="640" height="480">
            <div class="olp-thumbs" role="tablist" aria-label="Office <?= esc($VER) ?> editions">
              <?php foreach ($editions as $i => $e): ?>
                <button type="button" class="olp-thumb<?= $i === count($editions)-1 ? ' active' : '' ?>" data-img="<?= esc($e['image']) ?>" data-label="<?= esc('Office ' . $VER . ' ' . $e['label']) ?>" aria-label="Show <?= esc($e['label']) ?> image">
                  <img src="<?= esc($e['image']) ?>" alt="<?= esc('Office ' . $VER . ' ' . $e['label']) ?>" loading="lazy" width="90" height="70">
                </button>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="olp-countdown" data-testid="olp-countdown">
            <span class="me-2 fw-semibold"><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Sale ends in:</span>
            <span id="olpCd" class="olp-cd">--:--:--</span>
          </div>
        </div>
      </div>

      <!-- trust stat strip -->
      <div class="row g-3 mt-2 olp-stats">
        <div class="col-6 col-md-3"><div class="olp-stat"><div class="olp-stat-n">5,686+</div><div class="olp-stat-l">Happy Customers</div></div></div>
        <div class="col-6 col-md-3"><div class="olp-stat"><div class="olp-stat-n">4.6/5</div><div class="olp-stat-l">Average Rating</div></div></div>
        <div class="col-6 col-md-3"><div class="olp-stat"><div class="olp-stat-n">98%+</div><div class="olp-stat-l">Activation Success</div></div></div>
        <div class="col-6 col-md-3"><div class="olp-stat"><div class="olp-stat-n">&lt;30min</div><div class="olp-stat-l">Avg. Delivery Time</div></div></div>
      </div>
    </div>
  </section>

  <!-- ======================= EDITION SELECTOR ======================= -->
  <section class="olp-section" id="editions">
    <div class="container">
      <div class="text-center mb-4">
        <span class="olp-eyebrow">Limited Time Pricing</span>
        <h2 class="olp-h2">Choose Your Office <?= esc($VER) ?> Edition</h2>
        <p class="olp-lead"><?= esc($cfg['intro']) ?> Select the edition that fits you — all include lifetime access, free updates and instant digital delivery.</p>
      </div>
      <div class="row g-3 justify-content-center" id="olpEditionGrid">
        <?php foreach ($editions as $i => $e): ?>
          <div class="col-lg-4 col-md-6">
            <div class="olp-edition<?= $i === 0 ? ' selected' : '' ?>" data-slug="<?= esc($e['slug']) ?>" data-price="<?= esc((string)$e['price']) ?>" data-img="<?= esc($e['image']) ?>" data-testid="olp-edition-<?= $i ?>" role="button" tabindex="0" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>">
              <?php if (!empty($e['badge'])): ?><span class="olp-edition-badge"><?= esc($e['badge']) ?></span><?php endif; ?>
              <div class="olp-edition-imgwrap"><img src="<?= esc($e['image']) ?>" alt="<?= esc($e['product']['name']) ?>" loading="lazy" width="220" height="170"></div>
              <h3 class="olp-edition-name"><?= esc($e['label']) ?></h3>
              <div class="olp-edition-tag"><?= esc($e['tagline']) ?></div>
              <div class="olp-edition-apps"><?= esc($e['apps']) ?></div>
              <div class="olp-edition-pricing">
                <?php if ($e['msrp'] > $e['price']): ?><span class="olp-edition-msrp"><?= format_price($e['msrp']) ?></span><?php endif; ?>
                <span class="olp-edition-price"><?= format_price($e['price']) ?></span>
              </div>
              <?php if ($e['save_pct'] > 0): ?><div class="olp-edition-save">Save <?= $e['save_pct'] ?>% — You save <?= format_price($e['msrp'] - $e['price']) ?></div><?php endif; ?>
              <ul class="olp-edition-list list-unstyled">
                <?php foreach ($stdBullets as $b): ?><li><i class="bi bi-check2"></i> <?= esc($b) ?></li><?php endforeach; ?>
                <li><i class="bi bi-check2"></i> Available for <?= esc($e['platform']) ?></li>
              </ul>
              <button class="btn btn-orange-solid w-100 rounded-pill fw-bold buy-now-btn" data-slug="<?= esc($e['slug']) ?>" data-testid="olp-buy-<?= $i ?>"><i class="bi bi-lightning-charge-fill me-1"></i>Buy Now — <?= format_price($e['price']) ?></button>
              <div class="olp-edition-secure"><i class="bi bi-lock-fill me-1"></i>Secure checkout with 256-bit encryption</div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ========================= PC OR MAC ========================= -->
  <section class="olp-section olp-alt">
    <div class="container">
      <div class="row align-items-center g-4">
        <div class="col-lg-6">
          <span class="olp-eyebrow">Genuine · Perpetual</span>
          <h2 class="olp-h2">One License. Own It Forever.</h2>
          <p class="olp-lead">Office <?= esc($VER) ?> is a one-time purchase — pay once and use it for life. Choose your edition above and receive your genuine product key instantly via email.</p>
          <div class="row g-3">
            <?php
              $pcmac = [
                ['bi-key-fill','Genuine product key delivered instantly','Receive your activation key via email within minutes.'],
                ['bi-window-stack','Word, Excel & PowerPoint included','All the essential apps you use every day.'],
                ['bi-infinity','One-time purchase — no subscription','Pay once and own it forever, no recurring fees.'],
                ['bi-headset','Free download & installation support','Our team guides you through the entire setup.'],
              ];
              foreach ($pcmac as $f): ?>
              <div class="col-sm-6"><div class="olp-mini"><i class="bi <?= $f[0] ?>"></i><div><strong><?= esc($f[1]) ?></strong><span><?= esc($f[2]) ?></span></div></div></div>
            <?php endforeach; ?>
          </div>
          <a href="#editions" class="btn btn-orange-outline rounded-pill px-4 mt-3">View All Editions</a>
        </div>
        <div class="col-lg-6 text-center">
          <img src="<?= esc($editions[0]['image']) ?>" alt="Microsoft Office <?= esc($VER) ?>" class="olp-round-img" loading="lazy" width="520" height="400">
        </div>
      </div>
    </div>
  </section>

  <!-- ======================= WHAT YOU GET ======================= -->
  <section class="olp-section">
    <div class="container">
      <div class="text-center mb-4">
        <h2 class="olp-h2">What You Get with Office <?= esc($VER) ?></h2>
        <p class="olp-lead">Powerful productivity tools designed for modern work.</p>
      </div>
      <?php
        $feats = [
          ['Familiar Apps, One-Time Purchase','Install classic Office apps on your PC for a single payment — no recurring fees ever.'],
          ['Faster Multi-Tasking','Work with multiple documents and spreadsheets open simultaneously with improved performance.'],
          ['Enhanced Productivity','New tools and templates help you create better, more polished documents in less time.'],
          ['Focused Inbox & Email','Outlook sorts your most important emails automatically so you never miss what matters.'],
        ];
      ?>
      <div class="row g-3">
        <?php foreach ($feats as $i => $f): ?>
          <div class="col-md-6 col-lg-3">
            <div class="olp-feature">
              <div class="olp-feature-img"><img src="<?= esc($featureImgs[$i] ?? '') ?>" alt="<?= esc($f[0]) ?>" loading="lazy" width="400" height="260"></div>
              <h3 class="olp-feature-title"><?= esc($f[0]) ?></h3>
              <p class="olp-feature-text"><?= esc($f[1]) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ==================== OFFICE vs MICROSOFT 365 ==================== -->
  <section class="olp-section olp-alt">
    <div class="container">
      <div class="text-center mb-4">
        <h2 class="olp-h2">Office <?= esc($VER) ?> vs Microsoft 365</h2>
        <p class="olp-lead">See why a one-time purchase saves you money.</p>
      </div>
      <div class="table-responsive olp-cmp-wrap">
        <table class="table olp-cmp align-middle mb-0">
          <thead><tr><th></th><th class="olp-cmp-hi">Office <?= esc($VER) ?></th><th>Microsoft 365</th></tr></thead>
          <tbody>
            <tr><td>Payment</td><td class="olp-cmp-hi">One-time</td><td>$99.99/year</td></tr>
            <tr><td>Total cost (3 years)</td><td class="olp-cmp-hi">From <?= format_price($minPrice) ?></td><td><?= format_price(299.97) ?></td></tr>
            <tr><td>Ownership</td><td class="olp-cmp-hi">Own forever</td><td>Subscription</td></tr>
            <tr><td>Updates</td><td class="olp-cmp-hi">Free lifetime</td><td>Included</td></tr>
            <tr><td>Internet required</td><td class="olp-cmp-hi">No</td><td>Yes (periodic)</td></tr>
            <tr><td>Auto-renewal fees</td><td class="olp-cmp-hi">Never</td><td>Annually</td></tr>
          </tbody>
        </table>
      </div>
      <div class="text-center mt-3"><a href="#editions" class="btn btn-orange-solid rounded-pill px-4 fw-bold">Get Office <?= esc($VER) ?> Now</a></div>
    </div>
  </section>

  <!-- ========================= HOW IT WORKS ========================= -->
  <section class="olp-section">
    <div class="container">
      <div class="text-center mb-4">
        <h2 class="olp-h2">How It Works</h2>
        <p class="olp-lead">Get started in 3 simple steps — it takes less than 30 minutes.</p>
      </div>
      <div class="row g-3">
        <?php
          $steps = [
            ['Choose &amp; Buy','Select your edition, complete secure checkout with card or PayPal.'],
            ['Receive Your Key','Your genuine product key arrives in your email inbox within minutes.'],
            ['Download &amp; Activate','Visit setup.office.com, enter your key, download and start working.'],
          ];
          foreach ($steps as $i => $s): ?>
          <div class="col-md-4"><div class="olp-step"><span class="olp-step-n"><?= $i+1 ?></span><h3 class="olp-step-title"><?= $s[0] ?></h3><p class="olp-feature-text"><?= $s[1] ?></p></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- =========================== WHY BUY =========================== -->
  <section class="olp-section olp-alt">
    <div class="container">
      <div class="text-center mb-4"><h2 class="olp-h2">Why Buy from <?= esc(SITE_BRAND) ?>?</h2></div>
      <div class="row g-3">
        <?php
          $why = [
            ['bi-patch-check-fill','Genuine Licenses','All product keys are authentic Microsoft licenses with full activation guarantee.'],
            ['bi-shield-fill-check','30-Day Guarantee','If your key does not work, we replace it or give you a full refund. No questions asked.'],
            ['bi-lightning-charge-fill','Instant Delivery','Receive your product key via email in minutes, not days. Available 24/7.'],
            ['bi-headset','Free Support','Our expert team provides free installation and activation support with every purchase.'],
          ];
          foreach ($why as $w): ?>
          <div class="col-md-6 col-lg-3"><div class="olp-why"><i class="bi <?= $w[0] ?>"></i><h3 class="olp-feature-title"><?= esc($w[1]) ?></h3><p class="olp-feature-text"><?= esc($w[2]) ?></p></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ============================= FAQ ============================= -->
  <section class="olp-section">
    <div class="container" style="max-width:820px;">
      <div class="text-center mb-4"><h2 class="olp-h2">Frequently Asked Questions</h2></div>
      <div class="accordion olp-faq" id="olpFaq">
        <?php
          $faqs = [
            ['Is this a genuine Microsoft license?','Yes. Every key is an authentic, genuine Microsoft license backed by a full activation guarantee.'],
            ['How quickly will I receive my product key?','Your key is emailed automatically, usually within 15–30 minutes of a successful order — 24/7.'],
            ['What is the difference between Office ' . $VER . ' and Microsoft 365?','Office ' . $VER . ' is a one-time purchase you own forever with free lifetime updates and no subscription. Microsoft 365 is a recurring annual subscription.'],
            ['Which edition should I choose?','Home for Word/Excel/PowerPoint, Home & Business if you also need Outlook, or Professional Plus for all five apps plus Access.'],
            ['What if the license does not activate?','Contact our support team — we will replace the key or issue a full refund within the guarantee period.'],
            ['Do you provide installation support?','Yes, free installation and activation support is included with every purchase.'],
            ['Is there a subscription or recurring fee?','No. This is a one-time purchase — pay once and own it forever, with no recurring fees.'],
            ['What payment methods do you accept?','We accept all major credit/debit cards and PayPal through a secure, encrypted checkout.'],
          ];
          foreach ($faqs as $i => $q):
        ?>
          <div class="accordion-item">
            <h3 class="accordion-header" id="olpFaqH<?= $i ?>">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#olpFaqC<?= $i ?>" aria-expanded="false" aria-controls="olpFaqC<?= $i ?>"><?= esc($q[0]) ?></button>
            </h3>
            <div id="olpFaqC<?= $i ?>" class="accordion-collapse collapse" aria-labelledby="olpFaqH<?= $i ?>" data-bs-parent="#olpFaq">
              <div class="accordion-body"><?= esc($q[1]) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ===================== OTHER VERSIONS + CTA ===================== -->
  <section class="olp-section olp-cta">
    <div class="container text-center">
      <h2 class="olp-h2 text-white">Ready to Get Microsoft Office <?= esc($VER) ?>?</h2>
      <p class="olp-lead text-white-50">Join 5,686+ satisfied customers. Genuine license, instant delivery, lifetime access — no subscription ever.</p>
      <a href="#editions" class="btn btn-orange-solid btn-lg rounded-pill px-4 fw-bold mb-4"><i class="bi bi-cart-plus me-2"></i>Shop Now — From <?= format_price($minPrice) ?></a>
      <div class="olp-otherver">
        <span class="text-white-50 me-2">Looking for another year?</span>
        <?php foreach ($otherVersions as $ov): ?>
          <a href="/office-<?= $ov ?>-lifetime-license" class="olp-verlink" data-testid="olp-link-<?= $ov ?>">Office <?= $ov ?> Lifetime License</a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</div>

<style>
.office-lp{--olp-orange:#f97316;--olp-ink:#0f172a;}
.office-lp .olp-hero{padding:2.4rem 0 1.2rem;background:linear-gradient(180deg,rgba(249,115,22,.06),transparent);}
.office-lp .olp-brand-badge{display:inline-flex;align-items:center;background:#0f172a;color:#fff;font-weight:700;font-size:.8rem;padding:.3rem .7rem;border-radius:999px;}
.office-lp .olp-pill{display:inline-flex;align-items:center;background:rgba(16,185,129,.12);color:#059669;font-weight:700;font-size:.78rem;padding:.3rem .7rem;border-radius:999px;}
.office-lp .olp-title{font-size:2.6rem;font-weight:800;line-height:1.05;margin:.2rem 0 .1rem;}
.office-lp .olp-subtitle{font-size:1.05rem;color:#64748b;font-weight:600;margin-bottom:1rem;}
.office-lp .olp-retail{color:#94a3b8;text-decoration:line-through;font-size:.95rem;}
.office-lp .olp-price{font-size:2rem;font-weight:800;color:var(--olp-orange);line-height:1;}
.office-lp .olp-save{background:#fee2e2;color:#dc2626;font-weight:800;font-size:.85rem;padding:.35rem .7rem;border-radius:8px;}
.office-lp .olp-trust-row{display:flex;flex-wrap:wrap;gap:.4rem 1.2rem;margin:.6rem 0;}
.office-lp .olp-trust-row li{font-size:.86rem;font-weight:600;color:#334155;}
.office-lp .olp-trust-row i{color:#059669;margin-right:.25rem;}
.office-lp .olp-rating{font-weight:700;color:#334155;font-size:.9rem;}
.office-lp .olp-stars{color:#f59e0b;letter-spacing:1px;}
.office-lp .olp-hero-media{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:1.2rem;box-shadow:0 18px 40px -24px rgba(2,6,23,.35);text-align:center;}
.office-lp .olp-hero-img{max-width:100%;height:auto;max-height:340px;object-fit:contain;}
.office-lp .olp-thumbs{display:flex;gap:.5rem;justify-content:center;margin-top:.9rem;flex-wrap:wrap;}
.office-lp .olp-thumb{border:2px solid #e2e8f0;background:#fff;border-radius:10px;padding:.25rem;cursor:pointer;transition:.15s;}
.office-lp .olp-thumb.active,.office-lp .olp-thumb:hover{border-color:var(--olp-orange);}
.office-lp .olp-thumb img{width:64px;height:52px;object-fit:contain;}
.office-lp .olp-countdown{margin-top:.9rem;text-align:center;font-size:.92rem;color:#334155;}
.office-lp .olp-cd{font-variant-numeric:tabular-nums;font-weight:800;background:#0f172a;color:#fff;padding:.25rem .6rem;border-radius:8px;letter-spacing:1px;}
.office-lp .olp-stats{margin-top:1.4rem;}
.office-lp .olp-stat{text-align:center;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:.9rem .5rem;}
.office-lp .olp-stat-n{font-size:1.35rem;font-weight:800;color:var(--olp-orange);}
.office-lp .olp-stat-l{font-size:.78rem;color:#64748b;font-weight:600;}
.office-lp .olp-section{padding:2.6rem 0;}
.office-lp .olp-alt{background:#f8fafc;}
.office-lp .olp-eyebrow{display:inline-block;background:rgba(249,115,22,.12);color:var(--olp-orange);font-weight:800;font-size:.72rem;letter-spacing:1.4px;text-transform:uppercase;padding:.3rem .7rem;border-radius:999px;margin-bottom:.5rem;}
.office-lp .olp-h2{font-size:1.9rem;font-weight:800;}
.office-lp .olp-lead{color:#64748b;max-width:720px;margin:.4rem auto 0;}
.office-lp .olp-edition{position:relative;background:#fff;border:2px solid #e2e8f0;border-radius:16px;padding:1.4rem 1.2rem 1.2rem;height:100%;transition:.18s;text-align:center;}
.office-lp .olp-edition:hover{box-shadow:0 16px 34px -22px rgba(2,6,23,.4);transform:translateY(-3px);}
.office-lp .olp-edition.selected{border-color:var(--olp-orange);box-shadow:0 0 0 3px rgba(249,115,22,.15);}
.office-lp .olp-edition-badge{position:absolute;top:-11px;left:50%;transform:translateX(-50%);background:var(--olp-orange);color:#fff;font-weight:800;font-size:.7rem;letter-spacing:.5px;padding:.25rem .7rem;border-radius:999px;white-space:nowrap;}
.office-lp .olp-edition-imgwrap{height:130px;display:flex;align-items:center;justify-content:center;margin-bottom:.6rem;}
.office-lp .olp-edition-imgwrap img{max-height:130px;max-width:100%;object-fit:contain;}
.office-lp .olp-edition-name{font-size:1.25rem;font-weight:800;margin-bottom:.15rem;}
.office-lp .olp-edition-tag{color:#64748b;font-size:.85rem;}
.office-lp .olp-edition-apps{font-size:.8rem;color:#334155;font-weight:600;margin:.3rem 0 .6rem;}
.office-lp .olp-edition-msrp{color:#94a3b8;text-decoration:line-through;margin-right:.4rem;}
.office-lp .olp-edition-price{font-size:1.7rem;font-weight:800;color:var(--olp-ink);}
.office-lp .olp-edition-save{color:#059669;font-weight:700;font-size:.8rem;margin:.2rem 0 .6rem;}
.office-lp .olp-edition-list{text-align:left;margin:.7rem 0;font-size:.83rem;}
.office-lp .olp-edition-list li{margin-bottom:.28rem;color:#334155;}
.office-lp .olp-edition-list i{color:#059669;margin-right:.3rem;}
.office-lp .olp-edition-secure{font-size:.72rem;color:#94a3b8;margin-top:.5rem;}
.office-lp .olp-mini{display:flex;gap:.6rem;align-items:flex-start;}
.office-lp .olp-mini i{color:var(--olp-orange);font-size:1.2rem;margin-top:.1rem;}
.office-lp .olp-mini strong{display:block;font-size:.9rem;}
.office-lp .olp-mini span{font-size:.8rem;color:#64748b;}
.office-lp .olp-round-img{max-width:100%;height:auto;border-radius:18px;box-shadow:0 18px 40px -24px rgba(2,6,23,.4);}
.office-lp .olp-feature,.office-lp .olp-why,.office-lp .olp-step{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:1.1rem;height:100%;}
.office-lp .olp-feature-img{border-radius:10px;overflow:hidden;margin-bottom:.7rem;aspect-ratio:16/10;}
.office-lp .olp-feature-img img{width:100%;height:100%;object-fit:cover;}
.office-lp .olp-feature-title{font-size:1.02rem;font-weight:800;margin-bottom:.3rem;}
.office-lp .olp-feature-text{color:#64748b;font-size:.85rem;margin:0;}
.office-lp .olp-why{text-align:center;}
.office-lp .olp-why i{font-size:1.7rem;color:var(--olp-orange);}
.office-lp .olp-step{text-align:center;}
.office-lp .olp-step-n{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:50%;background:var(--olp-orange);color:#fff;font-weight:800;font-size:1.1rem;margin-bottom:.5rem;}
.office-lp .olp-cmp-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;}
.office-lp .olp-cmp th,.office-lp .olp-cmp td{padding:.8rem 1rem;font-size:.9rem;}
.office-lp .olp-cmp thead th{background:#0f172a;color:#fff;font-weight:700;}
.office-lp .olp-cmp .olp-cmp-hi{background:rgba(249,115,22,.06);font-weight:700;color:var(--olp-ink);}
.office-lp .olp-faq .accordion-button{font-weight:700;font-size:.95rem;}
.office-lp .olp-faq .accordion-button:not(.collapsed){color:var(--olp-orange);background:rgba(249,115,22,.06);box-shadow:none;}
.office-lp .olp-cta{background:linear-gradient(135deg,#1e293b,#0f172a);}
.office-lp .olp-otherver{margin-top:.6rem;display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap;align-items:center;}
.office-lp .olp-verlink{color:#fff;font-weight:700;text-decoration:underline;text-underline-offset:3px;}
.office-lp .olp-verlink:hover{color:var(--olp-orange);}
[data-bs-theme="dark"] .office-lp .olp-hero-media,[data-bs-theme="dark"] .office-lp .olp-edition,[data-bs-theme="dark"] .office-lp .olp-feature,[data-bs-theme="dark"] .office-lp .olp-why,[data-bs-theme="dark"] .office-lp .olp-step,[data-bs-theme="dark"] .office-lp .olp-stat,[data-bs-theme="dark"] .office-lp .olp-cmp-wrap{background:#111827;border-color:#1f2937;}
[data-bs-theme="dark"] .office-lp .olp-alt{background:#0b1220;}
[data-bs-theme="dark"] .office-lp .olp-title,[data-bs-theme="dark"] .office-lp .olp-h2,[data-bs-theme="dark"] .office-lp .olp-edition-name,[data-bs-theme="dark"] .office-lp .olp-edition-price,[data-bs-theme="dark"] .office-lp .olp-feature-title{color:#e2e8f0;}
@media(max-width:576px){.office-lp .olp-title{font-size:2rem;}}
</style>

<script>
(function(){
  // Hero thumbnail → main image swap.
  var hero = document.getElementById('olpHeroImg');
  document.querySelectorAll('.office-lp .olp-thumb').forEach(function(t){
    t.addEventListener('click', function(){
      if(hero){ hero.src = t.getAttribute('data-img'); hero.alt = t.getAttribute('data-label') || hero.alt; }
      document.querySelectorAll('.office-lp .olp-thumb').forEach(function(x){x.classList.remove('active');});
      t.classList.add('active');
    });
  });

  // Edition card selection (visual "which version" selector) — also swaps hero.
  function selectEdition(card){
    document.querySelectorAll('.office-lp .olp-edition').forEach(function(c){ c.classList.remove('selected'); c.setAttribute('aria-pressed','false'); });
    card.classList.add('selected'); card.setAttribute('aria-pressed','true');
    var img = card.getAttribute('data-img');
    if(hero && img){ hero.src = img; }
  }
  document.querySelectorAll('.office-lp .olp-edition').forEach(function(card){
    card.addEventListener('click', function(e){
      if(e.target.closest('.buy-now-btn')) return; // let Buy Now do its thing
      selectEdition(card);
    });
    card.addEventListener('keydown', function(e){
      if(e.key === 'Enter' || e.key === ' '){ e.preventDefault(); selectEdition(card); }
    });
  });

  // Countdown timer — always resets to end of the current day.
  var cd = document.getElementById('olpCd');
  if(cd){
    function tick(){
      var now = new Date();
      var end = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 59);
      var s = Math.max(0, Math.floor((end - now)/1000));
      var h = String(Math.floor(s/3600)).padStart(2,'0');
      var m = String(Math.floor((s%3600)/60)).padStart(2,'0');
      var ss = String(s%60).padStart(2,'0');
      cd.textContent = h+':'+m+':'+ss;
    }
    tick(); setInterval(tick, 1000);
  }
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>

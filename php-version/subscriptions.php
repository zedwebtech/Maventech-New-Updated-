<?php
/**
 * Device Protection Hub — public marketing / pricing page for the four
 * one-time-payment protection plans (Quick Fix / Starter Care / Pro Shield /
 * Lifetime Elite).  Users can compare features, review inclusions and jump
 * straight into checkout via /subscribe.php?plan=<slug>.  Prices are pulled
 * live from the `subscription_plans` DB table so the admin can adjust them
 * from Admin &rarr; Device Protection Hub &rarr; Plans without any code
 * deploy.
 */
require_once __DIR__ . '/includes/functions.php';

$plans = sub_plans(true);
$co    = function_exists('company_info') ? company_info() : [];
$phone = (function_exists('company_phone_for_country') ? company_phone_for_country() : ($co['phone'] ?? '')) ?: (defined('SITE_PHONE') ? SITE_PHONE : '');

// ── Feature-comparison matrix — mirrors the 10-row "Care Plans Feature
//    Comparison Table" the client circulated.  true / false / string values
//    render via the $cell closure below.
$matrix = [
    'Activation Support'        => ['quick-fix' => true,  'starter-care' => true,  'pro-shield' => true,  'lifetime-elite' => true],
    'OS Compatibility Check'    => ['quick-fix' => true,  'starter-care' => true,  'pro-shield' => true,  'lifetime-elite' => true],
    '1-Year Key Recovery'       => ['quick-fix' => false, 'starter-care' => true,  'pro-shield' => true,  'lifetime-elite' => true],
    'Remote Troubleshooting'    => ['quick-fix' => false, 'starter-care' => true,  'pro-shield' => true,  'lifetime-elite' => true],
    'Hardware Key Transfer'     => ['quick-fix' => false, 'starter-care' => false, 'pro-shield' => true,  'lifetime-elite' => true],
    'System Bloatware Tuning'   => ['quick-fix' => false, 'starter-care' => false, 'pro-shield' => true,  'lifetime-elite' => true],
    'Multi-Device Coverage'     => ['quick-fix' => false, 'starter-care' => false, 'pro-shield' => true,  'lifetime-elite' => true],
    'Malware &amp; Virus Removal'   => ['quick-fix' => false, 'starter-care' => false, 'pro-shield' => false, 'lifetime-elite' => true],
    'Dedicated VIP Manager'     => ['quick-fix' => false, 'starter-care' => false, 'pro-shield' => false, 'lifetime-elite' => true],
    '10-Year Support Window'    => ['quick-fix' => false, 'starter-care' => false, 'pro-shield' => false, 'lifetime-elite' => true],
];
$cell = function ($v) {
    if ($v === true)  return '<span class="ph-check"><i class="bi bi-check-lg"></i></span>';
    if ($v === false) return '<span class="ph-cross"><i class="bi bi-x-lg"></i></span>';
    return '<span class="fw-semibold">' . esc((string)$v) . '</span>';
};

// ── Per-plan aesthetic-watermark configuration.  Rendered as a soft
//    inline-SVG icon (single glyph, no square container) at the top of each
//    plan card + faintly behind the header of each column in the comparison
//    table.  Colours align to the plan's visual "temperature".
$planLogos = [
    'quick-fix'      => ['icon' => 'bi-lightning-charge-fill', 'color' => '#f59e0b'],
    'starter-care'   => ['icon' => 'bi-shield-check',          'color' => '#22c55e'],
    'pro-shield'     => ['icon' => 'bi-shield-shaded',         'color' => '#3b82f6'],
    'lifetime-elite' => ['icon' => 'bi-gem',                   'color' => '#a855f7'],
];

$title       = 'Protection Hub — Genuine Support Plans | ' . SITE_BRAND;
$description = 'Choose the right level of hands-on support for your Windows &amp; Office licences. One-time payment plans from $29 (Quick Fix) to $199 (Lifetime Elite) — no recurring billing, no hidden fees.';
include __DIR__ . '/includes/header.php';
?>

<style>
/* Device Protection Hub — scoped styles */
.ph-hero {
  background: linear-gradient(135deg, #eef4ff 0%, #ede9fe 100%);
  padding: 56px 0 40px;
}
html[data-bs-theme="dark"] .ph-hero {
  background: linear-gradient(135deg, rgba(37,99,235,.10) 0%, rgba(168,85,247,.10) 100%);
}
.ph-hero .badge { font-size: .72rem; padding: 6px 12px; letter-spacing: .04em; }
.ph-card {
  position: relative;
  overflow: hidden;
  border-radius: 20px;
  transition: transform .2s ease, box-shadow .2s ease;
  border: 1px solid var(--bs-border-color, #e5e7eb);
  background: var(--bs-body-bg, #fff);
}
.ph-card:hover { transform: translateY(-4px); box-shadow: 0 20px 40px rgba(0,0,0,.08); }
.ph-card.featured { border: 2px solid #3b82f6; box-shadow: 0 12px 32px rgba(59,130,246,.15); }
.ph-card .ph-badge {
  position: absolute; top: 12px; right: 12px;
  background: linear-gradient(135deg, #3b82f6, #a855f7);
  color: #fff; font-size: .68rem; font-weight: 700;
  padding: 4px 12px; border-radius: 999px; letter-spacing: .04em;
  text-transform: uppercase;
}
/* Aesthetic watermark logo — subtle glyph, NOT a square container. */
.ph-logo-mark {
  position: absolute;
  top: -16px; right: -16px;
  font-size: 8.5rem;
  line-height: 1;
  opacity: .09;
  pointer-events: none;
  transform: rotate(-12deg);
}
.ph-logo-inline {
  font-size: 2.6rem;
  line-height: 1;
  margin-bottom: 10px;
  display: block;
  text-align: center;
}
.ph-card-head { text-align: center; }
.ph-card-head h3 { text-align: center; }
.ph-card-head .tagline { text-align: center; min-height: 40px; }
.ph-price-row { text-align: center; }
.ph-price-row .d-flex { justify-content: center; }
.ph-price-row .price {
  font-size: 2.4rem; font-weight: 800; line-height: 1;
  background: linear-gradient(135deg, #1e40af, #3b82f6);
  -webkit-background-clip: text; background-clip: text;
  -webkit-text-fill-color: transparent; color: #1e40af;
}
html[data-bs-theme="dark"] .ph-price-row .price {
  background: linear-gradient(135deg, #93c5fd, #c4b5fd);
  -webkit-background-clip: text; background-clip: text;
  -webkit-text-fill-color: transparent;
}
.ph-price-row .price-unit { font-size: .8rem; color: var(--bs-secondary-color); font-weight: 600; }
.ph-features li { padding: 5px 0; font-size: .87rem; }
.ph-features li i { color: #10b981; margin-right: 6px; flex-shrink: 0; }
.ph-compare-table {
  border-collapse: separate; border-spacing: 0;
  width: 100%; background: var(--bs-body-bg);
  border-radius: 16px; overflow: hidden;
  border: 1px solid var(--bs-border-color);
}
.ph-compare-table thead th {
  background: var(--bs-tertiary-bg, #f8fafc);
  padding: 18px 12px; text-align: center;
  font-weight: 700; font-size: .95rem;
  border-bottom: 2px solid var(--bs-border-color);
  position: relative;
}
.ph-compare-table thead th:first-child { text-align: left; padding-left: 20px; }
.ph-compare-table thead th .ph-col-icon {
  font-size: 1.5rem; display: block; margin-bottom: 2px; line-height: 1;
}
.ph-compare-table tbody td {
  padding: 14px 12px; text-align: center; font-size: .9rem;
  border-bottom: 1px solid var(--bs-border-color);
}
.ph-compare-table tbody td:first-child { text-align: left; font-weight: 600; padding-left: 20px; }
.ph-compare-table tbody tr:last-child td { border-bottom: 0; }
.ph-check {
  display: inline-flex; width: 26px; height: 26px; border-radius: 8px;
  background: rgba(16,185,129,.14); color: #10b981; align-items: center;
  justify-content: center; font-size: .95rem;
}
.ph-cross {
  display: inline-flex; width: 26px; height: 26px; border-radius: 8px;
  background: rgba(239,68,68,.12); color: #ef4444; align-items: center;
  justify-content: center; font-size: .9rem;
}
.ph-ideal-row {
  background: var(--bs-tertiary-bg, #f8fafc);
  font-weight: 700; font-size: .82rem; text-transform: uppercase;
  letter-spacing: .05em; color: var(--bs-secondary-color);
}
</style>

<section class="ph-hero">
  <div class="container text-center" style="max-width: 900px;">
    <span class="badge rounded-pill text-bg-primary mb-2" data-testid="ph-page-badge">
      <i class="bi bi-shield-shaded me-1"></i> Protection Hub
    </span>
    <h1 class="fw-bold display-5 mb-2" data-testid="ph-page-title">Choose the plan that keeps every device covered</h1>
    <p class="text-secondary fs-5 mb-3">Hands-on remote setup, licence recovery and priority support &mdash; from a single-session rescue to a dedicated tier-3 specialist for a decade.</p>
    <p class="small text-secondary mb-0"><i class="bi bi-check2-circle text-success me-1"></i>One-time payment &nbsp;·&nbsp; <i class="bi bi-check2-circle text-success me-1"></i>No recurring billing &nbsp;·&nbsp; <i class="bi bi-check2-circle text-success me-1"></i>30-day money-back guarantee</p>
  </div>
</section>

<?php if (empty($plans)): ?>
<section class="py-5"><div class="container"><div class="alert alert-info">Our Protection Hub plans are being finalised. Please check back soon<?= $phone ? ' or call <strong>' . esc($phone) . '</strong>' : '' ?>.</div></div></section>
<?php else: ?>

<section class="py-5">
  <div class="container">
    <div class="row g-4 justify-content-center" data-testid="ph-plan-cards">
      <?php foreach ($plans as $p):
        $priced   = (float)$p['price'] > 0;
        $featured = $p['slug'] === 'pro-shield';
        $logo     = $planLogos[$p['slug']] ?? ['icon' => 'bi-shield-check', 'color' => '#3b82f6'];
      ?>
        <div class="col-md-6 col-xl-3">
          <div class="ph-card h-100 p-4 <?= $featured ? 'featured' : '' ?>" data-testid="ph-card-<?= esc($p['slug']) ?>">
            <?php if ($featured): ?><span class="ph-badge" data-testid="ph-badge-popular">Most popular</span><?php endif; ?>
            <!-- Aesthetic watermark logo — soft & rotated, NOT a square. -->
            <i class="bi <?= esc($logo['icon']) ?> ph-logo-mark" style="color: <?= esc($logo['color']) ?>;" aria-hidden="true" data-testid="ph-logo-mark-<?= esc($p['slug']) ?>"></i>

            <div class="position-relative">
              <div class="ph-card-head">
                <i class="bi <?= esc($logo['icon']) ?> ph-logo-inline" style="color: <?= esc($logo['color']) ?>;" aria-hidden="true"></i>
                <h3 class="h5 fw-bold mb-1" data-testid="ph-name-<?= esc($p['slug']) ?>"><?= esc($p['name']) ?></h3>
                <p class="text-secondary small mb-3 tagline" data-testid="ph-tagline-<?= esc($p['slug']) ?>"><?= esc($p['tagline']) ?></p>
              </div>

              <div class="ph-price-row mb-3" data-testid="ph-price-<?= esc($p['slug']) ?>">
                <?php if ($priced): ?>
                  <div class="d-flex align-items-baseline gap-2">
                    <span class="price"><?= esc(format_price((float)$p['price'])) ?></span>
                    <span class="price-unit">one-time</span>
                  </div>
                  <div class="small text-secondary mt-1"><?= esc($p['tenure_label']) ?> &middot; <?= esc($p['devices']) ?></div>
                <?php else: ?>
                  <span class="h5 fw-bold text-secondary">Contact us</span>
                  <div class="small text-secondary"><?= esc($p['tenure_label']) ?> &middot; <?= esc($p['devices']) ?></div>
                <?php endif; ?>
              </div>

              <ul class="list-unstyled ph-features mb-4">
                <?php foreach ($p['features'] as $f): ?>
                  <li class="d-flex align-items-start"><i class="bi bi-check2-circle mt-1"></i><span><?= esc($f) ?></span></li>
                <?php endforeach; ?>
              </ul>

              <?php if ($priced): ?>
                <a href="subscribe.php?plan=<?= esc($p['slug']) ?>" class="btn <?= $featured ? 'btn-primary' : 'btn-outline-primary' ?> w-100 rounded-pill fw-semibold" data-testid="ph-buy-<?= esc($p['slug']) ?>">
                  <i class="bi bi-cart-check me-1"></i>Get <?= esc($p['name']) ?>
                </a>
              <?php else: ?>
                <a href="contact.php" class="btn btn-outline-secondary w-100 rounded-pill" data-testid="ph-contact-<?= esc($p['slug']) ?>">Contact us</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pb-5">
  <div class="container">
    <div class="text-center mb-4">
      <h2 class="fw-bold h3 mb-2">Care Plans Feature Comparison Table</h2>
      <p class="text-secondary">Everything included at each tier, side-by-side.</p>
    </div>
    <div class="table-responsive">
      <table class="ph-compare-table" data-testid="ph-compare-table">
        <thead>
          <tr>
            <th style="width: 260px;">Service Feature</th>
            <?php foreach ($plans as $p):
              $logo = $planLogos[$p['slug']] ?? ['icon' => 'bi-shield-check', 'color' => '#3b82f6']; ?>
              <th data-testid="ph-compare-head-<?= esc($p['slug']) ?>">
                <span class="ph-col-icon" style="color: <?= esc($logo['color']) ?>;"><i class="bi <?= esc($logo['icon']) ?>"></i></span>
                <?= esc($p['name']) ?>
                <?php if ((float)$p['price'] > 0): ?>
                  <div class="small fw-normal text-secondary mt-1"><?= esc(format_price((float)$p['price'])) ?> one-time</div>
                <?php endif; ?>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <tr class="ph-ideal-row">
            <td>Ideal for</td>
            <td>Urgent single errors</td>
            <td>Individual users</td>
            <td>Multi-device homes</td>
            <td>Power users / Businesses</td>
          </tr>
          <?php foreach ($matrix as $label => $vals): ?>
            <tr>
              <td><?= $label ?></td>
              <?php foreach ($plans as $p): ?>
                <td><?= $cell($vals[$p['slug']] ?? false) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          <tr>
            <td></td>
            <?php foreach ($plans as $p): ?>
              <td>
                <?php if ((float)$p['price'] > 0): ?>
                  <a href="subscribe.php?plan=<?= esc($p['slug']) ?>" class="btn btn-sm btn-primary rounded-pill px-3" data-testid="ph-compare-buy-<?= esc($p['slug']) ?>">Get Plan</a>
                <?php else: ?>
                  <a href="contact.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3">Contact</a>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        </tbody>
      </table>
    </div>
    <?php if ($phone): ?>
      <p class="text-center text-secondary mt-4">Questions about a plan? Call us at <a href="tel:<?= esc(tel_e164($phone)) ?>" class="fw-semibold text-decoration-none"><?= esc($phone) ?></a> — we're happy to help.</p>
    <?php endif; ?>
  </div>
</section>

<!-- Frequently Asked Questions — accordion styled to match the rest of
     the hub. Each answer aligns with Google Ads compliance (one-time
     payment, independent-reseller status, no recurring billing). -->
<section class="pb-5" id="protection-hub-faq">
  <div class="container" style="max-width: 900px;">
    <div class="text-center mb-4">
      <span class="badge rounded-pill text-bg-primary mb-2" data-testid="ph-faq-badge"><i class="bi bi-patch-question me-1"></i>Frequently Asked Questions</span>
      <h2 class="fw-bold h3 mb-2">Everything you need to know before you buy</h2>
      <p class="text-secondary">Straight answers on billing, delivery, refunds and coverage — no fine print.</p>
    </div>
    <?php
      $faqs = [
        [
          'q' => 'Is this a subscription that renews automatically?',
          'a' => 'No. Every Protection Hub plan is a <strong>one-time payment</strong>. You are billed once for the duration you select (single session for Quick Fix, 1 / 3 / 10 years for the other tiers). Nothing renews automatically, and you will never be charged again for the same plan.',
        ],
        [
          'q' => 'How do I access support after purchase?',
          'a' => 'Within 15&ndash;30 minutes of checkout, you will receive a confirmation email containing (a) your unique Customer ID, (b) a dedicated support email address, and (c) the priority phone / chat access instructions that apply to your specific tier. Save this email &mdash; it is your proof of active coverage.',
        ],
        [
          'q' => 'Which plan should I pick?',
          'a' => '<strong>Quick Fix</strong> is for a single urgent issue (installation, activation, one-time error). <strong>Starter Care</strong> covers one device for 1 year of unlimited chat support. <strong>Pro Shield</strong> covers up to 3 devices for 3 years with VIP phone support &mdash; the sweet spot for most households. <strong>Lifetime Elite</strong> gives you a dedicated specialist and covers unlimited devices for a decade &mdash; best for power users, freelancers and small businesses.',
        ],
        [
          'q' => 'Can I transfer my licence key to a new PC later?',
          'a' => 'Yes &mdash; Pro Shield and Lifetime Elite both include our <em>Hardware-to-Hardware Key Transfer</em> service. If you upgrade or replace your PC, our support team will help you reactivate your genuine Microsoft key on the new machine at no extra cost, without needing to re-purchase.',
        ],
        [
          'q' => 'What is your refund policy?',
          'a' => 'Every plan is backed by our <strong>30-day money-back guarantee</strong>. If you have not consumed a service session and are not satisfied, email us within 30 days of purchase and we will refund your payment in full. See our <a href="return-policy.php" class="text-decoration-none">Return Policy</a> for full details.',
        ],
        [
          'q' => 'Is Maventech affiliated with Microsoft?',
          'a' => '<strong>No.</strong> Maventech LLC is an <em>independent</em> reseller of genuine software licenses and support services. We are not affiliated with, endorsed by, or sponsored by Microsoft Corporation. All product names, logos, and brands are the property of their respective trademark owners and are used strictly for identification purposes.',
        ],
        [
          'q' => 'How do you deliver support &mdash; remote access, phone, or chat?',
          'a' => 'It depends on the tier. <strong>Quick Fix</strong> is a single remote-desktop session. <strong>Starter Care</strong> uses priority live chat. <strong>Pro Shield</strong> adds VIP phone support with priority queue routing. <strong>Lifetime Elite</strong> assigns you a dedicated tier-3 specialist reachable by phone, chat, or scheduled remote session with a guaranteed 15-minute response window.',
        ],
        [
          'q' => 'Can I upgrade my plan later?',
          'a' => 'Yes. If you start with Quick Fix or Starter Care and want to upgrade to Pro Shield or Lifetime Elite, contact our support team and we will apply <strong>full credit</strong> for what you already paid toward the higher tier. No repurchase penalty.',
        ],
      ];
    ?>
    <div class="accordion" id="ph-faq-accordion" data-testid="ph-faq-accordion">
      <?php foreach ($faqs as $i => $f): ?>
        <div class="accordion-item mb-2" style="border-radius: 12px; overflow: hidden;">
          <h3 class="accordion-header">
            <button class="accordion-button <?= $i === 0 ? '' : 'collapsed' ?> fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#ph-faq-<?= $i ?>" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>" aria-controls="ph-faq-<?= $i ?>" data-testid="ph-faq-q-<?= $i ?>">
              <?= $f['q'] ?>
            </button>
          </h3>
          <div id="ph-faq-<?= $i ?>" class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>" data-bs-parent="#ph-faq-accordion">
            <div class="accordion-body text-secondary" style="line-height: 1.65;" data-testid="ph-faq-a-<?= $i ?>">
              <?= $f['a'] ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- FAQPage JSON-LD for rich results in Google -->
    <script type="application/ld+json">
    <?php
      echo json_encode([
        '@context' => 'https://schema.org',
        '@type'    => 'FAQPage',
        'mainEntity' => array_map(fn($f) => [
          '@type' => 'Question',
          'name'  => strip_tags($f['q']),
          'acceptedAnswer' => [
            '@type' => 'Answer',
            'text'  => strip_tags($f['a']),
          ],
        ], $faqs),
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    ?>
    </script>
  </div>
</section>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

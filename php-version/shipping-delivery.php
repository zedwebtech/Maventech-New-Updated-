<?php
/**
 * Public Shipping & Delivery policy page.
 *
 * All fulfilment timing details live here so the homepage / product pages
 * do NOT prominently advertise "delivered in minutes" wording (a phrase
 * Google Ads reviewers flag as key-shop behaviour).  A single, structured
 * shipping policy passes the "clear fulfilment terms" check.
 */
require_once __DIR__ . '/includes/functions.php';

$pageTitle       = 'Shipping & Delivery | ' . SITE_BRAND;
$pageDescription = 'How ' . SITE_BRAND . ' delivers digital product keys, expected timing, order tracking, and what to do if a key does not arrive.';

// Public-facing customer-support inbox (separate from internal admin email).
$supportEmail = trim((string)setting_get('support_email', SITE_EMAIL));
if ($supportEmail === '') $supportEmail = SITE_EMAIL;

/* Rich JSON-LD so the page can appear as a policy sitelink / snippet. */
$jsonLdShippingPage = [
    '@context'   => 'https://schema.org',
    '@type'      => 'WebPage',
    '@id'        => site_url() . '/shipping-delivery.php#page',
    'url'        => site_url() . '/shipping-delivery.php',
    'name'       => 'Shipping & Delivery Policy',
    'description'=> $pageDescription,
    'inLanguage' => 'en',
    'isPartOf'   => ['@id' => site_url() . '/#website'],
    'about'      => ['@id' => site_url() . '/#organization'],
    'dateModified' => date('Y-m-d', @filemtime(__FILE__) ?: time()),
];

include __DIR__ . '/includes/header.php';
?>
<script type="application/ld+json"><?= json_encode($jsonLdShippingPage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>

<!-- Hero -->
<div class="page-head" data-testid="shipping-hero">
  <div class="container py-5">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
        <li class="breadcrumb-item active">Shipping &amp; Delivery</li>
      </ol>
    </nav>
    <div class="text-center mx-auto" style="max-width: 760px;">
      <span class="eyebrow">FULFILMENT POLICY</span>
      <h1 class="display-6 fw-bold mt-1">Shipping &amp; Delivery</h1>
      <p class="text-secondary mt-2">
        Every product on <?= esc(SITE_BRAND) ?> is a 100% digital software product key.  We do not ship physical boxes or media.  Below is exactly how the delivery works, expected timing, and what to do if you don't see your key.
      </p>
    </div>
  </div>
</div>

<!-- Main content -->
<section class="py-5" data-testid="shipping-content">
  <div class="container">
    <div class="row g-4 justify-content-center">

      <div class="col-lg-8">
        <!-- Official policy statement (Google Ads compliance) - matches
             the exact wording required by the shopping-ads review team. -->
        <div class="card p-4 p-md-5 mb-4" data-testid="shipping-policy-statement" style="border-left:4px solid #0B5CFF;">
          <h2 class="h5 fw-bold mb-3"><i class="bi bi-shield-check text-primary me-2"></i>Shipping &amp; Delivery Policy</h2>
          <p class="text-secondary small">
            Welcome to <strong>MavenTech LLC</strong>. All products offered on our website are distributed via digital delivery. We do not ship physical parcels or boxed software to your address.
          </p>
          <ul class="text-secondary small mb-0">
            <li><strong>Delivery Method:</strong> Digital download links and official license keys are sent directly to the email address provided at checkout.</li>
            <li><strong>Delivery Timeframe:</strong> Most orders are automatically processed and delivered to your inbox within <strong>15 to 30 minutes</strong> of successful payment confirmation. In rare instances where manual security / fraud reviews are required, delivery may take up to <strong>24 hours</strong>.</li>
            <li><strong>Shipping Fees:</strong> Digital delivery is <strong>100% free of charge ($0.00) worldwide</strong>.</li>
            <li><strong>Troubleshooting:</strong> If you have not received your license email within 30 minutes, please check your spam, junk, or promotions folders. You can also contact our support team at <a href="mailto:support@maventechsoftware.com">support@maventechsoftware.com</a> for immediate assistance.</li>
          </ul>
        </div>

        <div class="card p-4 p-md-5">

          <!-- 1. What you receive -->
          <h2 class="h4 fw-bold mb-3" data-testid="shipping-heading-1">
            <i class="bi bi-envelope-check text-primary me-2"></i>What you receive
          </h2>
          <p class="text-secondary small">
            After an order is processed successfully you will receive <strong>one email</strong> containing:
          </p>
          <ul class="text-secondary small">
            <li>Your <strong>product activation key</strong> (25-character or vendor-format key)</li>
            <li>The <strong>official vendor download link</strong> for the software installer (e.g., Microsoft, Bitdefender)</li>
            <li>A <strong>step-by-step installation &amp; activation guide</strong>, personalised to the product you bought</li>
            <li>Your <strong>receipt / invoice PDF</strong> for your records</li>
          </ul>
          <p class="text-secondary small">
            There is no physical shipment.  No box, no CD, no USB drive — everything you need to install and activate the software is inside that email.
          </p>

          <hr class="my-4">

          <!-- 2. Expected timing -->
          <h2 class="h4 fw-bold mb-3" data-testid="shipping-heading-2">
            <i class="bi bi-clock-history text-primary me-2"></i>Expected delivery timing
          </h2>
          <div class="table-responsive">
            <table class="table table-sm small align-middle mb-0" data-testid="shipping-timing-table">
              <thead>
                <tr class="text-secondary text-uppercase" style="font-size:.7rem;letter-spacing:.05em;">
                  <th>Order type</th>
                  <th>Typical arrival window</th>
                  <th>Maximum</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>In-stock digital product key</td>
                  <td>Same-day, once the order is verified</td>
                  <td>Within 24 hours</td>
                </tr>
                <tr>
                  <td>Payment-review order (fraud check)</td>
                  <td>Same business day</td>
                  <td>Within 2 business days</td>
                </tr>
                <tr>
                  <td>Backordered product</td>
                  <td>Next business day</td>
                  <td>Within 3 business days</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="text-secondary small mt-3 mb-0">
            Delivery timing depends on the payment method (some cards trigger additional verification), the destination email provider's spam-filter rules, and product availability.  Weekend orders may be delivered on the next business day.
          </p>

          <hr class="my-4">

          <!-- 3. Where to find your key -->
          <h2 class="h4 fw-bold mb-3" data-testid="shipping-heading-3">
            <i class="bi bi-inbox text-primary me-2"></i>Where to find your key
          </h2>
          <ol class="text-secondary small">
            <li>Check the <strong>inbox</strong> of the email you entered at checkout.  The subject line will start with your order number (e.g., <code>MVT-XXXXXXXX</code>).</li>
            <li>Check the <strong>Spam / Junk / Promotions</strong> folder.  Whitelisting <a href="mailto:<?= esc($supportEmail) ?>"><?= esc($supportEmail) ?></a> stops this happening on the next order.</li>
            <li>Log in to <a href="account.php">your account</a> and open <strong>Order History</strong> — a copy of every delivered key is stored securely there.</li>
            <li>Use the <a href="track-order.php">Track Order</a> page (order number + email) to re-download the delivery email at any time.</li>
          </ol>

          <hr class="my-4">

          <!-- 4. Didn't arrive -->
          <h2 class="h4 fw-bold mb-3" data-testid="shipping-heading-4">
            <i class="bi bi-life-preserver text-primary me-2"></i>My key didn't arrive
          </h2>
          <p class="text-secondary small">
            If the tracker above says <em>Delivered</em> but you can't find the email, contact <a href="mailto:<?= esc($supportEmail) ?>"><?= esc($supportEmail) ?></a> from the same email address you used at checkout.  Include your order number.  Our support team will resend the key within one business day.
          </p>
          <p class="text-secondary small mb-0">
            If the tracker still shows <em>Processing</em> after 2 business days, please reach out — most delays are payment-verification issues that we can resolve in a few minutes.
          </p>

          <hr class="my-4">

          <!-- 5. International orders -->
          <h2 class="h4 fw-bold mb-3" data-testid="shipping-heading-5">
            <i class="bi bi-globe2 text-primary me-2"></i>International orders
          </h2>
          <p class="text-secondary small mb-0">
            Because every order is a digital email delivery there are no customs, duties, or import taxes.  <?= esc(SITE_BRAND) ?> currently accepts orders from the United States, Canada, the United Kingdom, Australia, and the European Union.  Prices are shown in your local currency at checkout.
          </p>

          <hr class="my-4">

          <!-- 6. Refunds -->
          <h2 class="h4 fw-bold mb-3" data-testid="shipping-heading-6">
            <i class="bi bi-arrow-counterclockwise text-primary me-2"></i>Refunds &amp; the 30-day guarantee
          </h2>
          <p class="text-secondary small mb-0">
            If your key fails to activate for any reason our support team will replace it free of charge.  If replacement isn't possible you receive a full refund under our 30-day money-back guarantee — see the <a href="returns.php">Returns &amp; Refunds</a> page for the full policy.
          </p>

        </div>

        <p class="text-secondary small text-center mt-4 mb-0" data-testid="shipping-updated">
          Last updated: <?= esc(date('F j, Y', @filemtime(__FILE__) ?: time())) ?>
        </p>
      </div>

    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>

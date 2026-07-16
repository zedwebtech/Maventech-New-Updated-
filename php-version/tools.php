<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/tools-common.php';
$pageTitle = 'Free Microsoft Office & Windows Tools | ' . SITE_BRAND;
$pageDescription = 'Free, no-signup tools for Microsoft Office and Windows: check your Office version, validate a Windows product key format, check compatibility, and calculate licensing for your team.';
include __DIR__ . '/includes/header.php';
tools_styles();
$tools = [
  ['bi-search',        'Office Version Checker',       'Find out exactly which Microsoft Office version you have and whether it’s still supported by Microsoft.', '/tools/office-version-checker'],
  ['bi-key',           'Windows Product Key Checker',  'Check the format of a Windows product key and learn how to find your key and confirm activation.',           '/tools/windows-product-key-checker'],
  ['bi-cpu',           'Office Compatibility Checker', 'See which version of Office works with your Windows or Mac, based on Microsoft’s system requirements.',    '/tools/office-compatibility-checker'],
  ['bi-calculator',    'Office Deployment Calculator', 'Work out how many licenses your team needs and the total cost, using real prices from our catalog.',         '/tools/office-deployment-calculator'],
];
?>
<div class="mv-tool" data-testid="tools-index">
  <?php tools_hero('Free Microsoft Tools', 'Handy, free tools for Microsoft Office and Windows. No sign-up, no email — just clear, honest answers.', false); ?>
  <section class="mvt-section">
    <div class="container">
      <div class="row g-3">
        <?php foreach ($tools as $t): ?>
          <div class="col-md-6 col-lg-3">
            <a class="mvt-toolcard" href="<?= esc($t[3]) ?>" data-testid="tool-card">
              <i class="bi <?= $t[0] ?>"></i>
              <h3><?= esc($t[1]) ?></h3>
              <p><?= esc($t[2]) ?></p>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tools-common.php';
$baseHref = rtrim(site_url(), '/') . '/';
$pageTitle = 'Microsoft Office Version Checker | ' . SITE_BRAND;
$pageDescription = 'Find out which version of Microsoft Office you have and whether it’s still supported by Microsoft. Step-by-step guide for Windows and Mac, plus a free support-status checker.';
include __DIR__ . '/../includes/header.php';
tools_styles();
$rows = [
  ['Microsoft 365 (subscription)', 'Ongoing',        'While subscribed',   'Current',      'current'],
  ['Office 2024 (one-time purchase)','October 2024',  'October 9, 2029',    'Supported',    'supported'],
  ['Office 2021 (one-time purchase)','October 2021',  'October 13, 2026',   'Ending soon',  'ending'],
  ['Office 2019',                  'September 2018',  'October 14, 2025',   'Ended',        'ended'],
  ['Office 2016',                  'September 2015',  'October 14, 2025',   'Ended',        'ended'],
  ['Office 2013',                  'January 2013',    'April 11, 2023',     'Ended',        'ended'],
];
?>
<div class="mv-tool" data-testid="tool-office-version-checker">
  <?php tools_hero('Microsoft Office Version Checker', 'Find out which version of Office you’re running and whether Microsoft still supports it — no download or sign-up needed.'); ?>
  <section class="mvt-section"><div class="container">
    <div class="row g-3">
      <div class="col-lg-6"><div class="mvt-card h-100">
        <h2 class="mvt-h2">Step 1 — Find your Office version</h2>
        <p class="mvt-lead">Open any Office app (Word, Excel, PowerPoint or Outlook), then follow the steps for your device.</p>
        <h3 style="font-size:1rem;font-weight:700;">On Windows</h3>
        <ol style="font-size:.9rem;color:#475569;"><li>Open Word, Excel or another Office app.</li><li>Click <strong>File</strong> in the top-left.</li><li>Select <strong>Account</strong> (or Office Account).</li><li>Look under <strong>Product Information</strong> for the product name.</li><li>Click <strong>About Word/Excel</strong> to see the full version and build number.</li></ol>
        <h3 style="font-size:1rem;font-weight:700;">On Mac</h3>
        <ol style="font-size:.9rem;color:#475569;"><li>Open Word, Excel or another Office app.</li><li>Click the app name in the menu bar (e.g. <strong>Word</strong>).</li><li>Select <strong>About Word</strong>.</li><li>The version and build number appear in the window that opens.</li></ol>
      </div></div>
      <div class="col-lg-6"><div class="mvt-card h-100">
        <h2 class="mvt-h2">Step 2 — Check if your version is still supported</h2>
        <p class="mvt-lead">Pick the version you found above to see its current support status, based on Microsoft’s official lifecycle dates.</p>
        <label class="mvt-label" for="ovcSelect">Select your Office version</label>
        <select id="ovcSelect" class="form-select" data-testid="ovc-select">
          <option value="">Select your Office version…</option>
          <option value="m365">Microsoft 365 (subscription)</option>
          <option value="2024">Office 2024</option>
          <option value="2021">Office 2021</option>
          <option value="2019">Office 2019</option>
          <option value="2016">Office 2016</option>
          <option value="2013">Office 2013</option>
        </select>
        <div id="ovcResult" class="mvt-result" data-testid="ovc-result"></div>
      </div></div>
    </div>
    <div class="mvt-card mt-3">
      <h2 class="mvt-h2">Office version reference &amp; support dates</h2>
      <p class="mvt-lead">Official Microsoft end-of-support dates for recent Office releases.</p>
      <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Version</th><th>Released</th><th>End of support</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr><td><?= esc($r[0]) ?></td><td><?= esc($r[1]) ?></td><td><?= esc($r[2]) ?></td><td><span class="mvt-status <?= $r[4] ?>"><?= esc($r[3]) ?></span></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <div class="mvt-src">Source: <a href="https://learn.microsoft.com/en-us/lifecycle/products/" target="_blank" rel="noopener">Microsoft Product Lifecycle</a>. Dates are subject to Microsoft’s policy — always confirm on Microsoft’s site.</div>
    </div>
  </div></section>
  <?php tools_faq([
    ['How do I find out which version of Microsoft Office I have?','Open any Office app (like Word or Excel), go to File > Account (on Windows) or the app menu > About (on Mac). You’ll see the product name and the exact version and build number.'],
    ['Is my version of Office still supported?','Office 2016 and 2019 reached end of support on October 14, 2025. Office 2021 is supported until October 13, 2026, and Office 2024 until October 9, 2029. Microsoft 365 stays supported while your subscription is active.'],
    ['What happens when my Office version is no longer supported?','Your apps keep working, but Microsoft stops security updates, bug fixes and technical support. Running unsupported software is a security risk, so upgrading is recommended.'],
    ['What’s the difference between the version and the build number?','The version (e.g. “Version 2406”) tells you the release cadence; the build number identifies the exact update installed. Both are shown under File > Account > About.'],
  ]); ?>
</div>
<script>
(function(){
  var data={
    m365:['ok','Microsoft 365 — Current','Your subscription includes the latest features and security updates for as long as it stays active.'],
    '2024':['ok','Office 2024 — Supported','Supported by Microsoft until October 9, 2029. You’re on the latest one-time-purchase release.'],
    '2021':['warn','Office 2021 — Ending soon','Supported until October 13, 2026. Consider upgrading to Office 2024 before support ends.'],
    '2019':['bad','Office 2019 — Ended','Reached end of support on October 14, 2025. Upgrade to a supported version to keep getting security updates.'],
    '2016':['bad','Office 2016 — Ended','Reached end of support on October 14, 2025. Upgrade to a supported version to keep getting security updates.'],
    '2013':['bad','Office 2013 — Ended','Reached end of support on April 11, 2023. Upgrade to a supported version as soon as possible.'],
  };
  var sel=document.getElementById('ovcSelect'),out=document.getElementById('ovcResult');
  sel.addEventListener('change',function(){
    var d=data[sel.value];
    if(!d){out.className='mvt-result';return;}
    out.className='mvt-result show mvt-'+d[0];
    out.innerHTML='<strong>'+d[1]+'</strong><br><span style=\"font-weight:400;\">'+d[2]+'</span>';
  });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>

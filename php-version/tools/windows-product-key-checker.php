<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tools-common.php';
$baseHref = rtrim(site_url(), '/') . '/';
$pageTitle = 'Windows Product Key Checker | ' . SITE_BRAND;
$pageDescription = 'Check the format of a Windows product key and learn how to find your key and confirm Windows activation. Free, instant, and no sign-up.';
/* 2026-07 FIX — served under /tools/windows-product-key-checker via router.php. */
$canonicalUrl      = site_url() . country_prefix() . '/tools/windows-product-key-checker';
$canonicalPathBare = '/tools/windows-product-key-checker';
include __DIR__ . '/../includes/header.php';
tools_styles();
$cmds = [
  ['Find the key stored in your PC’s firmware (OEM key)','wmic path softwarelicensingservice get OA3xOriginalProductKey','Run in Command Prompt. Works on PCs that came with Windows pre-installed. Returns nothing if no firmware key exists.'],
  ['Same, using PowerShell','(Get-WmiObject -query \'select * from SoftwareLicensingService\').OA3xOriginalProductKey','Run in Windows PowerShell.'],
  ['Check whether Windows is activated','slmgr /xpr','Shows a pop-up telling you if Windows is permanently activated.'],
  ['See detailed license / activation info','slmgr /dlv','Shows the license status, partial product key, and activation details.'],
];
?>
<div class="mv-tool" data-testid="tool-windows-key-checker">
  <?php tools_hero('Windows Product Key Checker', 'Validate the format of a Windows product key and learn exactly how to find your key and confirm activation.'); ?>
  <section class="mvt-section"><div class="container">
    <div class="mvt-note mb-3"><strong>This tool checks the format only.</strong> It cannot tell you whether a key is genuine, unused, or able to activate Windows — only Microsoft’s activation servers can do that. Use it to spot typos and confirm a key has the correct structure.</div>
    <div class="row g-3">
      <div class="col-lg-6"><div class="mvt-card h-100">
        <h2 class="mvt-h2">Check a key’s format</h2>
        <p class="mvt-lead">Paste a product key below. We’ll clean it up and confirm whether it has the correct 25-character structure.</p>
        <label class="mvt-label" for="wpkInput">Product key</label>
        <input id="wpkInput" class="form-control text-uppercase" placeholder="XXXXX-XXXXX-XXXXX-XXXXX-XXXXX" autocomplete="off" data-testid="wpk-input">
        <button id="wpkBtn" class="btn btn-orange-solid rounded-pill fw-bold mt-3" data-testid="wpk-check">Check format</button>
        <div id="wpkResult" class="mvt-result" data-testid="wpk-result"></div>
      </div></div>
      <div class="col-lg-6"><div class="mvt-card h-100">
        <h2 class="mvt-h2">Find your key &amp; check activation</h2>
        <p class="mvt-lead">Use these built-in Windows commands. Open Command Prompt or PowerShell as administrator, then paste a command.</p>
        <?php foreach ($cmds as $c): ?>
          <div class="mb-3"><div style="font-weight:700;font-size:.86rem;margin-bottom:.3rem;"><?= esc($c[0]) ?></div>
          <div class="mvt-cmd"><button class="mvt-copy" type="button" data-cmd="<?= esc($c[1]) ?>">Copy</button><?= esc($c[1]) ?></div>
          <div style="font-size:.78rem;color:#94a3b8;margin-top:.25rem;"><?= esc($c[2]) ?></div></div>
        <?php endforeach; ?>
      </div></div>
    </div>
    <div class="mvt-card mt-3 text-center">
      <h2 class="mvt-h2">Need a genuine Windows or Office key?</h2>
      <p class="mvt-lead mb-2">We sell genuine Microsoft licenses with instant delivery and lifetime validity.</p>
      <a href="/shop" class="btn btn-orange-solid rounded-pill fw-bold px-4">Browse genuine licenses</a>
    </div>
  </div></section>
  <?php tools_faq([
    ['Can this tool tell me if my Windows key is genuine?','No. Only Microsoft’s activation servers can confirm whether a key is genuine and unused. This tool only checks the standard 25-character format. To confirm a key is genuine, enter it during Windows activation under Settings > System > Activation.'],
    ['What does a valid Windows product key look like?','A Windows product key is 25 characters long, shown as five groups of five separated by dashes, for example: XXXXX-XXXXX-XXXXX-XXXXX-XXXXX. It contains only letters and numbers.'],
    ['How do I find my Windows product key?','If your PC came with Windows pre-installed, the key is usually in firmware — run “wmic path softwarelicensingservice get OA3xOriginalProductKey” in Command Prompt. If you bought a key separately, check your confirmation email or the card it came on.'],
    ['How do I check if Windows is activated?','Open Settings > System > Activation, or run “slmgr /xpr” in Command Prompt. You’ll see whether Windows is permanently activated.'],
  ]); ?>
</div>
<script>
(function(){
  var inp=document.getElementById('wpkInput'),btn=document.getElementById('wpkBtn'),out=document.getElementById('wpkResult');
  function check(){
    var raw=(inp.value||'').toUpperCase().replace(/[^A-Z0-9]/g,'');
    if(raw.length===0){out.className='mvt-result show mvt-warn';out.innerHTML='Please paste a product key to check.';return;}
    if(raw.length===25){
      var formatted=raw.match(/.{1,5}/g).join('-');
      out.className='mvt-result show mvt-ok';
      out.innerHTML='<strong>Valid format ✓</strong><br><span style=\"font-weight:400;\">Your key has the correct 25-character structure: <code>'+formatted+'</code>. Note: format only — activation still requires Microsoft.</span>';
    } else {
      out.className='mvt-result show mvt-bad';
      out.innerHTML='<strong>Incorrect format ✗</strong><br><span style=\"font-weight:400;\">A Windows product key must be exactly 25 letters/numbers (5 groups of 5). You entered '+raw.length+' character'+(raw.length===1?'':'s')+'. Check for typos or missing characters.</span>';
    }
  }
  btn.addEventListener('click',check);
  inp.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();check();}});
  document.querySelectorAll('.mvt-copy').forEach(function(b){b.addEventListener('click',function(){navigator.clipboard&&navigator.clipboard.writeText(b.getAttribute('data-cmd'));var t=b.textContent;b.textContent='Copied!';setTimeout(function(){b.textContent=t;},1200);});});
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tools-common.php';
$baseHref = rtrim(site_url(), '/') . '/';
$pageTitle = 'Office Compatibility Checker | ' . SITE_BRAND;
$pageDescription = 'Check whether a version of Microsoft Office is compatible with your Windows or Mac, based on Microsoft official system requirements. Free instant compatibility checker.';
include __DIR__ . '/../includes/header.php';
tools_styles();
?>
<div class="mv-tool" data-testid="tool-compatibility-checker">
  <?php tools_hero('Office Compatibility Checker', 'Check whether a version of Microsoft Office will run on your computer, based on Microsoft official system requirements.'); ?>
  <section class="mvt-section"><div class="container">
    <div class="row g-3">
      <div class="col-lg-6"><div class="mvt-card h-100">
        <h2 class="mvt-h2">Check compatibility</h2>
        <p class="mvt-lead">Choose your operating system and the version of Office you want to use.</p>
        <label class="mvt-label" for="occOs">Your operating system</label>
        <select id="occOs" class="form-select mb-3" data-testid="occ-os">
          <option value="">Select your OS&hellip;</option>
          <option value="win11">Windows 11</option>
          <option value="win10">Windows 10</option>
          <option value="win8">Windows 8.1 or older</option>
          <option value="macnew">macOS (one of the 3 most recent)</option>
          <option value="macold">macOS (older than 3 recent)</option>
        </select>
        <label class="mvt-label" for="occVer">Office version you want</label>
        <select id="occVer" class="form-select" data-testid="occ-ver">
          <option value="">Select Office version&hellip;</option>
          <option value="m365">Microsoft 365</option>
          <option value="2024">Office 2024</option>
          <option value="2021">Office 2021</option>
          <option value="2019">Office 2019</option>
          <option value="2016">Office 2016</option>
        </select>
        <div id="occResult" class="mvt-result" data-testid="occ-result"></div>
      </div></div>
      <div class="col-lg-6"><div class="mvt-card h-100">
        <h2 class="mvt-h2">Microsoft Office system requirements</h2>
        <h3 style="font-size:1rem;font-weight:700;margin-top:.5rem;">Windows</h3>
        <ul style="font-size:.88rem;color:#475569;"><li>Windows 10 or Windows 11</li><li>1.6 GHz or faster, 2-core processor</li><li>4 GB RAM (2 GB for 32-bit)</li><li>4 GB available disk space</li><li>1280 &times; 768 screen resolution</li></ul>
        <h3 style="font-size:1rem;font-weight:700;">Mac</h3>
        <ul style="font-size:.88rem;color:#475569;"><li>One of the three most recent versions of macOS</li><li>Intel or Apple silicon processor</li><li>4 GB RAM</li><li>10 GB available disk space</li><li>1280 &times; 800 screen resolution</li></ul>
        <div class="mvt-src">Source: <a href="https://www.microsoft.com/en-us/microsoft-365/microsoft-365-and-office-resources" target="_blank" rel="noopener">Microsoft 365 &amp; Office system requirements</a>. Always confirm the full requirements on Microsoft site before purchasing.</div>
      </div></div>
    </div>
  </div></section>
  <?php tools_faq([
    ['Which version of Office works with Windows 11?','Microsoft 365, Office 2024, and Office 2021 are all officially supported on Windows 11 (and Windows 10). Older versions like Office 2019 and 2016 were built for Windows 10 and have reached end of support.'],
    ['Does Office work on Mac?','Yes. Microsoft 365, Office 2024, and Office 2021 for Mac are supported on one of the three most recent versions of macOS. Keep macOS reasonably up to date for the best compatibility.'],
    ['What are the system requirements for Microsoft Office?','On Windows: Windows 10 or 11, a 1.6 GHz or faster 2-core processor, 4 GB RAM (2 GB for 32-bit), 4 GB free disk space, and a 1280x768 screen. On Mac: one of the three most recent versions of macOS, 4 GB RAM, 10 GB free disk, and a 1280x800 screen.'],
    ['Can I install the latest Office on Windows 8.1?','No. Current versions of Office require Windows 10 or Windows 11. Windows 8.1 has also reached end of support, so upgrading your operating system is recommended.'],
  ]); ?>
</div>
<script>
(function(){
  var os=document.getElementById('occOs'),ver=document.getElementById('occVer'),out=document.getElementById('occResult');
  // matrix[os][ver] = true(compatible)/false. Windows-only vs mac.
  var winModern={m365:1,'2024':1,'2021':1,'2019':1,'2016':1};
  var macModern={m365:1,'2024':1,'2021':1,'2019':0,'2016':0};
  function evaluate(){
    if(!os.value||!ver.value){out.className='mvt-result';return;}
    var o=os.value,v=ver.value,ok=false,msg='';
    var names={m365:'Microsoft 365','2024':'Office 2024','2021':'Office 2021','2019':'Office 2019','2016':'Office 2016'};
    if(o==='win8'){ok=false;msg='Current Office versions require Windows 10 or 11. Windows 8.1 is not supported and has itself reached end of support \u2014 upgrade your OS first.';}
    else if(o==='macold'){ok=false;msg=names[v]+' requires one of the three most recent versions of macOS. Update macOS, or choose a Mac running a recent release.';}
    else if(o==='win11'||o==='win10'){ok=!!winModern[v];msg=ok?names[v]+' is compatible with your version of Windows and meets Microsoft system requirements.':names[v]+' is not recommended \u2014 it has reached end of support. Choose Office 2021 or Office 2024 instead.';}
    else if(o==='macnew'){ok=!!macModern[v];msg=ok?names[v]+' for Mac is compatible with a recent version of macOS.':names[v]+' is not available/supported for Mac. On Mac, choose Microsoft 365, Office 2024 or Office 2021.';}
    out.className='mvt-result show '+(ok?'mvt-ok':'mvt-bad');
    out.innerHTML='<strong>'+(ok?'Compatible \u2713':'Not recommended \u2717')+'</strong><br><span style=\"font-weight:400;\">'+msg+'</span>'+(ok?' <a href=\"/shop\" style=\"font-weight:700;\">Shop now &rarr;</a>':'');
  }
  os.addEventListener('change',evaluate);ver.addEventListener('change',evaluate);
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>

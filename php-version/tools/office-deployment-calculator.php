<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tools-common.php';
$baseHref = rtrim(site_url(), '/') . '/';
$pageTitle = 'Office Deployment Calculator | ' . SITE_BRAND;
$pageDescription = 'Work out how many Microsoft Office licenses your team needs and the total cost. Uses real, live catalog prices - free, instant, and no sign-up required.';
/* 2026-07 FIX — served under /tools/office-deployment-calculator via router.php.
   Explicit canonical prevents the header defaulting to
   /office-deployment-calculator.php (wrong URL → Semrush "non-canonical sitemap
   URL" and "no self-referencing hreflang" errors). */
$canonicalUrl      = site_url() . country_prefix() . '/tools/office-deployment-calculator';
$canonicalPathBare = '/tools/office-deployment-calculator';
include __DIR__ . '/../includes/header.php';
tools_styles();
$cur = current_currency();
$calcProducts = [];
try {
    $st = db()->query("SELECT slug,name,price FROM products WHERE brand='Microsoft' AND (name LIKE '%Office%' OR slug LIKE '%office%') AND is_active=1 ORDER BY price ASC");
    $calcProducts = $st->fetchAll();
} catch (Throwable $e) { $calcProducts = []; }
?>
<div class="mv-tool" data-testid="tool-deployment-calculator">
  <?php tools_hero('Office Deployment Calculator', 'See how many licenses your team needs and the total cost - calculated from real, live prices in our catalog.'); ?>
  <section class="mvt-section"><div class="container">
    <div class="mvt-card" style="max-width:720px;margin:0 auto;">
      <h2 class="mvt-h2">Calculate your licensing</h2>
      <p class="mvt-lead">Enter how many computers need Office and choose a product. One-time Office licenses are per device.</p>
      <div class="row g-3">
        <div class="col-md-5">
          <label class="mvt-label" for="calcQty">Number of computers / devices</label>
          <input id="calcQty" type="number" min="1" max="100000" value="1" class="form-control" data-testid="calc-qty">
        </div>
        <div class="col-md-7">
          <label class="mvt-label" for="calcProd">Office product</label>
          <select id="calcProd" class="form-select" data-testid="calc-product">
            <option value="">Select a product&hellip;</option>
            <?php foreach ($calcProducts as $p): $conv = (float)$p['price'] * (float)$cur['rate']; ?>
              <option value="<?= esc($p['slug']) ?>" data-price="<?= esc(number_format($conv, 2, '.', '')) ?>"><?= esc($p['name']) ?> &mdash; <?= esc($cur['symbol'] . number_format($conv, 2)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div id="calcBox" class="mvt-totalbox" style="display:none;" data-testid="calc-result">
        <div style="font-size:.85rem;color:#64748b;"><span id="calcQtyL">1</span> &times; <span id="calcUnit"></span> license</div>
        <div class="mvt-total" id="calcTotal"></div>
        <a id="calcBuy" href="#" class="btn btn-orange-solid rounded-pill fw-bold px-4 mt-2 buy-now-btn" data-slug="" data-qty="1" data-testid="calc-buy">Add <span id="calcBuyN">1</span> to cart</a>
      </div>
      <p class="mvt-src mt-3">Prices are pulled live from our catalog in your selected currency (<?= esc($cur['code']) ?>). One-time-purchase Office licenses cover a single device each. For volume licensing or invoicing, <a href="/contact">contact our team</a>.</p>
    </div>
  </div></section>
  <?php tools_faq([
    ['How many Office licenses do I need for my team?','One-time-purchase versions of Microsoft Office (such as Office 2021 and Office 2024) are licensed per device - one installation per computer. If you have 10 computers that each need Office, you need 10 licenses, regardless of how many people use them.'],
    ['How does this calculator work out the total cost?','It multiplies the number of devices by the live price of the Office product you select from our catalog. The prices shown are real catalog prices in your selected currency - nothing is estimated.'],
    ['Do you offer discounts for larger orders?','For bulk and business orders, get in touch with our team and we will help you find the right licensing and pricing for your organization.'],
    ['Is one Office license enough for multiple computers?','No. A one-time-purchase Office license covers a single PC or Mac. Each additional computer needs its own license.'],
  ]); ?>
</div>
<script>
(function(){
  var qty=document.getElementById('calcQty'),prod=document.getElementById('calcProd'),box=document.getElementById('calcBox');
  var sym=<?= json_encode($cur['symbol']) ?>;
  function fmt(n){return sym+n.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}
  function update(){
    var opt=prod.options[prod.selectedIndex];
    var price=opt?parseFloat(opt.getAttribute('data-price')||'0'):0;
    var q=Math.max(1,parseInt(qty.value||'1',10));
    if(!prod.value||!price){box.style.display='none';return;}
    box.style.display='block';
    document.getElementById('calcQtyL').textContent=q;
    document.getElementById('calcUnit').textContent=fmt(price);
    document.getElementById('calcTotal').textContent=fmt(price*q);
    var buy=document.getElementById('calcBuy');
    buy.setAttribute('data-slug',prod.value);buy.setAttribute('data-qty',q);
    document.getElementById('calcBuyN').textContent=q;
  }
  qty.addEventListener('input',update);prod.addEventListener('change',update);
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>

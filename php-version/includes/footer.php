<?php /* Footer + chat widget + scripts */ ?>
</main><!-- /#main-content (opened in header.php) -->
<footer class="footer-dark pt-0 pb-4 mt-5">

  <!-- Newsletter band -->
  <div class="border-bottom border-secondary-subtle" style="border-color: rgba(255,255,255,.12) !important;">
    <div class="container text-center py-5">
      <h2 class="text-white fw-bold fs-2">Join our list for the latest deals &amp; product updates</h2>
      <p class="small mb-4">Subscribe and receive exclusive weekly deals straight to your inbox!</p>
      <form class="d-flex gap-2 mx-auto" style="max-width: 420px;" onsubmit="subscribeNewsletter(event)">
        <input type="email" required class="form-control rounded-pill px-3" placeholder="Enter your email" data-testid="newsletter-email">
        <button class="btn btn-primary rounded-pill px-4 fw-semibold" type="submit" data-testid="newsletter-join">Join</button>
      </form>
      <div class="d-flex justify-content-center gap-4 flex-wrap small mt-4">
        <span><i class="bi bi-patch-check-fill text-success me-1"></i>Genuine Products</span>
        <span><i class="bi bi-shield-check text-primary me-1"></i>30-Day Guarantee</span>
        <span><i class="bi bi-headset text-primary me-1"></i>Expert Support</span>
      </div>
      <!-- Secure Payments block — moved here from the bottom of the footer so
           the trust-signal + accepted-card icons sit right next to the newsletter
           CTA where shoppers are most likely to look before subscribing. -->
      <div class="mt-4 pt-3" data-testid="footer-secure-payments">
        <div class="text-white small fw-bold mb-2"><i class="bi bi-lock-fill text-success me-1"></i>Secure Payments</div>
        <div class="d-flex gap-3 small mb-3 flex-wrap justify-content-center">
          <span><i class="bi bi-lock-fill text-success me-1"></i>SSL Encrypted Checkout</span>
          <span><i class="bi bi-shield-fill-check text-info me-1"></i>Secure Encrypted Transactions</span>
        </div>
        <div class="d-flex gap-2 flex-wrap justify-content-center" data-testid="footer-pay-icons">
          <?= render_payment_icons() ?>
        </div>
      </div>
    </div>
  </div>

  <div class="container pt-5">
    <div class="row g-4">
      <!-- Brand column -->
      <div class="col-lg-4">
        <div class="d-flex align-items-center gap-2 mb-3">
          <?php if (!empty($brandLogo)): ?>
            <?= brand_logo_html(42, 'width="140" height="42" loading="lazy" decoding="async"') ?>
          <?php else: ?>
            <?= render_logo(42) ?>
          <?php endif; ?>
          <span>
            <?php
              $bnParts = preg_split('/\s+/', trim($brandName));
              $bnLast  = array_pop($bnParts) ?: '';
              $bnHead  = implode(' ', $bnParts);
            ?>
            <span class="brand-text d-block lh-1 text-white"><?= esc($bnHead) ?><?php if ($bnHead !== ''): ?> <?php endif; ?><span class="brand-grad"><?= esc($bnLast) ?></span></span>
            <?php if (setting_get('show_authorized_reseller_badge', '0') === '1'): ?>
            <small class="brand-tag" data-testid="brand-tag-authorized-reseller-footer">GENUINE LICENSES</small>
            <?php endif; ?>
          </span>
        </div>
        <p class="small mb-3" style="line-height:1.55;">Your trusted source for genuine Microsoft Office licenses at competitive prices. One-time purchase — no recurring fees.</p>

        <!-- Contact block: tight vertical rhythm, aligned icons -->
        <ul class="list-unstyled small mb-3 footer-contact-list">
          <li><i class="bi bi-telephone-fill text-info"></i><a href="tel:<?= esc(tel_e164($brandPhone)) ?>"><?= esc($brandPhone) ?></a></li>
          <li><i class="bi bi-envelope-fill text-info"></i><a href="mailto:<?= esc($brandEmail) ?>"><?= esc($brandEmail) ?></a></li>
          <li><i class="bi bi-geo-alt-fill text-info"></i><?= esc($brandAddress) ?></li>
          <li><i class="bi bi-clock-fill text-info"></i><?= SITE_HOURS ?></li>
          <?php if ($brandRegNumber): ?><li data-testid="footer-reg-number"><i class="bi bi-patch-check-fill text-info"></i>Company Registration Number: <?= esc($brandRegNumber) ?><?php if (!empty($brandRegDateFiled)): ?> · Filed <?= esc(date('n/j/Y', strtotime((string)$brandRegDateFiled))) ?><?php endif; ?></li><?php endif; ?>
        </ul>

        <!-- Google Maps + socials on the same visual row for compactness -->
        <div class="d-flex align-items-center flex-wrap gap-3 mb-3">
          <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($brandAddress) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-light rounded-pill gmap-btn" data-testid="footer-gmap-btn">
            <span class="gmap-pin"><i class="bi bi-geo-alt-fill"></i></span>View on Google Maps
          </a>
          <div class="d-flex gap-2">
            <?php foreach ([['Facebook', 'bi-facebook'], ['Twitter', 'bi-twitter-x'], ['LinkedIn', 'bi-linkedin'], ['Instagram', 'bi-instagram']] as [$sn, $si]): ?>
              <a href="#top" aria-label="<?= $sn ?>" class="social-circle"><i class="bi <?= $si ?>"></i></a>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Compliance disclaimer -->
        <p class="small mb-0 fst-italic" style="line-height:1.5;color:#94a3b8;font-size:.78rem;" data-testid="footer-brand-disclaimer">
          <strong>Disclaimer:</strong> <?= esc($brandLegalName) ?> is an independent reseller of authentic software licenses. All product names, logos, and brands are property of their respective owners.
        </p>
      </div>

      <!-- Products -->
      <div class="col-lg-2 col-md-4 col-6">
        <h3 class="h6 text-white fw-bold mb-3">Products</h3>
        <ul class="list-unstyled small d-grid gap-2">
          <li><a href="category.php?slug=office-2024-pc">Microsoft Office 2024</a></li>
          <li><a href="category.php?slug=office-2021-pc">Microsoft Office 2021</a></li>
          <li><a href="category.php?slug=office-2019-pc">Microsoft Office 2019</a></li>
          <li><a href="category.php?slug=microsoft-project">Microsoft Project</a></li>
          <li><a href="category.php?slug=microsoft-visio">Microsoft Visio</a></li>
          <li><a href="category.php?slug=office-mac">Office for Mac</a></li>
          <li><a href="category.php?slug=windows">Windows OS</a></li>
        </ul>
      </div>

      <!-- Support -->
      <div class="col-lg-3 col-md-4 col-6">
        <h3 class="h6 text-white fw-bold mb-3">Support</h3>
        <ul class="list-unstyled small d-grid gap-2">
          <li><a href="account.php">My Account</a></li>
          <li><a href="track-order.php" data-testid="footer-order-history-link">Track Order &amp; Receipts</a></li>
          <li><a href="support.php">Support Center</a></li>
          <li><a href="page.php?slug=help-center">Help Center</a></li>
          <li><a href="page.php?slug=installation-guide">Installation Guide</a></li>
          <li><a href="page.php?slug=activation-help">Activation Help</a></li>
          <li><a href="page.php?slug=faqs">FAQs</a></li>
          <li><a href="contact.php">Contact Us</a></li>
          <li><a href="return-policy.php">Return Policy</a></li>
          <li><a href="protection-hub.php" data-testid="footer-protection-hub"><i class="bi bi-shield-shaded me-1"></i>Protection Hub</a></li>
        </ul>
      </div>

      <!-- Company -->
      <div class="col-lg-3 col-md-4 col-6">
        <h3 class="h6 text-white fw-bold mb-3">Company</h3>
        <ul class="list-unstyled small d-grid gap-2">
          <li><a href="about-us.php">About Us</a></li>
          <li><a href="page.php?slug=why-choose-us">Why Choose Us</a></li>
          <li><a href="reviews.php">Customer Reviews</a></li>
          <li><a href="blog.php">Blog</a></li>
          <?php
            // Auto-render a Brands sub-menu so users can reach each brand
            // profile (Microsoft, Bitdefender, McAfee...) and its dedicated
            // Articles tab from any page.
            try {
                $allBrands = db()->query("SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand != '' AND is_active = 1 ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);
            } catch (Throwable $e) { $allBrands = []; }
            foreach ($allBrands as $bn):
                $bSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$bn));
          ?>
            <li><a href="brand.php?slug=<?= esc($bSlug) ?>" data-testid="footer-brand-<?= esc($bSlug) ?>"><?= esc($bn) ?> Hub</a></li>
          <?php endforeach; ?>
          <li><a href="press-kit.php" data-testid="footer-press-kit">Press Kit &amp; Embeds</a></li>
          <li><a href="sitemap.php" data-testid="footer-company-sitemap">Site Map</a></li>
        </ul>
      </div>
    </div>

    <!-- Trademark + legal -->
    <hr class="border-secondary my-4">

    <p class="small text-center mx-auto" style="max-width: 820px;">Maventech LLC is an independent marketplace provider of genuine, surplus product keys. We are not an official partner, authorized distributor, franchise, or direct affiliate of Microsoft Corporation, Bitdefender, or McAfee. All product names, logos, and brands are the property of their respective trademark owners and are used strictly for identification purposes.</p>

    <div class="d-flex justify-content-center flex-wrap gap-2 small mb-3">
      <?php
      $legal = [
          ['Privacy Policy', 'page.php?slug=privacy-policy'], ['Terms of Service', 'page.php?slug=terms-of-service'],
          ['Refund Policy', 'refund-policy.php'], ['Return Policy', 'return-policy.php'],
          ['Shipping & Delivery', 'shipping-delivery.php'],
          ['Payment Policy', 'page.php?slug=payment-policy'], ['Cookie Policy', 'page.php?slug=cookie-policy'],
          ['Do Not Sell My Info', 'page.php?slug=do-not-sell'], ['Legal Disclaimer', 'page.php?slug=disclaimer'], ['Sitemap', 'sitemap.php'],
      ];
      foreach ($legal as $idx => [$ll, $lh]): ?>
        <a href="<?= $lh ?>"><?= $ll ?></a><?= $idx < count($legal) - 1 ? '<span aria-hidden="true" style="color:#94a3b8;">|</span>' : '' ?>
      <?php endforeach; ?>
    </div>
    <div class="text-center small footer-copyright" data-testid="footer-copyright">
      <span class="footer-copyright-main">© <?= date('Y') ?> <?= esc($brandLegalName) ?>. All rights reserved.</span>
      <?php if ($brandRegNumber): ?>
        <span class="footer-copyright-sep" aria-hidden="true"> · </span>
        <span class="footer-copyright-reg" data-testid="footer-copyright-reg">Company Registration Number: <?= esc($brandRegNumber) ?></span>
      <?php endif; ?>
    </div>
  </div>
</footer>

<?php
/* Google Customer Reviews — seller-rating badge (optional, site-wide).
   Renders only when a Merchant Center ID is configured.  Position defaults
   to BOTTOM_LEFT so it never collides with the bottom-right chat bubble. */
$gcrMid = trim((string)setting_get('google_merchant_id', defined('GOOGLE_MERCHANT_ID') ? GOOGLE_MERCHANT_ID : ''));
$gcrPos = trim((string)setting_get('gcr_badge_position', 'BOTTOM_LEFT'));
if ($gcrMid !== '' && ctype_digit($gcrMid)):
?>
<!-- Google Customer Reviews badge — deferred until first interaction / idle
     (same gate as GTM). Rationale (PageSpeed Mob 2026-07-08): the widget
     runs a 57 ms forced reflow inside its internal init, so leaving it
     with a plain `defer` still ate into LCP. Delaying to the __mvTrk
     interaction/3 s-post-load gate removes it from the initial critical
     path entirely — no visible UX regression (the badge still appears
     within seconds for any real user who scrolls / clicks). -->
<script>
  window.__mvTrk = window.__mvTrk || [];
  window.__mvTrk.push(function () {
    var s = document.createElement('script');
    s.src = 'https://www.gstatic.com/shopping/merchant/merchantwidget.js';
    s.async = true;
    s.id = 'merchantWidgetScript';
    s.onload = function () {
      if (typeof merchantwidget === 'undefined') return;
      merchantwidget.start({
        merchant_id: <?= (int)$gcrMid ?>,
        position: "<?= esc($gcrPos) ?>"
      });
      var _mwT = setInterval(function () {
        var f = document.getElementById('merchantwidgetiframe');
        if (f) { f.setAttribute('title', 'Google Customer Reviews'); clearInterval(_mwT); }
      }, 400);
      setTimeout(function () { clearInterval(_mwT); }, 12000);
    };
    document.head.appendChild(s);
  });
</script>
<!-- end Google Customer Reviews badge -->
<?php endif; ?>

<!-- ============================================================
     Global "Ask AI" modal — real Claude-Haiku-powered Q&A about the store.
     Triggered by the header "Ask AI" button and the hero teaser "Try it" pill.
     Independent from the live-chat widget (which is the human-support flow).
     ============================================================ -->
<div id="ask-ai-modal" class="ask-ai-modal" role="dialog" aria-modal="true" aria-labelledby="ask-ai-modal-title" data-testid="ask-ai-modal">
  <div class="ask-ai-modal-backdrop" onclick="closeAskAiModal()"></div>
  <div class="ask-ai-modal-card">
    <div class="ask-ai-modal-head">
      <div class="ask-ai-modal-avatar"><i class="bi bi-stars"></i></div>
      <div class="ask-ai-modal-meta">
        <div class="ask-ai-modal-title" id="ask-ai-modal-title">Ask <?= esc(defined('SITE_BRAND') ? SITE_BRAND : 'Maventech') ?> AI</div>
        <div class="ask-ai-modal-sub"><span class="ask-ai-dot"></span>Powered by Claude · Instant answers about delivery, licences, refunds &amp; more</div>
      </div>
      <button type="button" class="ask-ai-modal-close" onclick="closeAskAiModal()" aria-label="Close" data-testid="ask-ai-modal-close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="ask-ai-modal-chips" data-testid="ask-ai-modal-chips">
      <button type="button" class="ask-ai-modal-chip" data-q="Which Office is right for my Mac?">Which Office for Mac?</button>
      <button type="button" class="ask-ai-modal-chip" data-q="How long does delivery take?">Delivery time?</button>
      <button type="button" class="ask-ai-modal-chip" data-q="What is your refund policy?">Refund policy?</button>
      <button type="button" class="ask-ai-modal-chip" data-q="Is this a one-time purchase or subscription?">One-time or subscription?</button>
    </div>
    <div id="ask-ai-modal-thread" class="ask-ai-modal-thread" data-testid="ask-ai-modal-thread"></div>
    <form id="ask-ai-modal-form" class="ask-ai-modal-form" onsubmit="askAiModalSubmit(event)" autocomplete="off">
      <input type="text" id="ask-ai-modal-input" name="ask_ai_question" class="form-control" placeholder="Ask anything about our store…" maxlength="500" autocomplete="off" data-testid="ask-ai-modal-input" required>
      <button type="submit" class="ask-ai-modal-send" data-testid="ask-ai-modal-send" aria-label="Send"><i class="bi bi-send-fill"></i></button>
    </form>
    <div class="ask-ai-modal-footer">
      <i class="bi bi-info-circle me-1"></i>For order-specific help, use the <a href="#" onclick="closeAskAiModal(); toggleChat(); return false;">live chat</a>.
    </div>
  </div>
</div>

<style>
.ask-ai-modal {
  position: fixed; inset: 0; z-index: 1090;
  display: none; align-items: flex-end; justify-content: center;
}
.ask-ai-modal.open { display: flex; }
@media (min-width: 768px) { .ask-ai-modal { align-items: center; } }
.ask-ai-modal-backdrop {
  position: absolute; inset: 0;
  background: rgba(2,6,23,.55); backdrop-filter: blur(4px);
  animation: aaFade .25s ease;
}
@keyframes aaFade { from { opacity: 0; } to { opacity: 1; } }
.ask-ai-modal-card {
  position: relative; z-index: 1;
  width: 100%; max-width: 560px;
  max-height: 90vh; display: flex; flex-direction: column;
  background: var(--bs-body-bg, #fff); color: var(--bs-body-color);
  border: 1px solid var(--bs-border-color);
  border-radius: 22px 22px 0 0;
  box-shadow: 0 -20px 60px rgba(2,6,23,.35);
  animation: aaSlide .3s cubic-bezier(.22,.61,.36,1);
  overflow: hidden;
}
@media (min-width: 768px) { .ask-ai-modal-card { border-radius: 22px; margin: 1rem; } }
@keyframes aaSlide { from { transform: translateY(24px); opacity: 0; } to { transform: none; opacity: 1; } }
.ask-ai-modal-head {
  display: flex; align-items: center; gap: .8rem;
  padding: 1rem 1.1rem;
  background: linear-gradient(135deg, rgba(6,182,212,.10), rgba(45,212,191,.06));
  border-bottom: 1px solid var(--bs-border-color);
}
.ask-ai-modal-avatar {
  width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center;
  color: #fff; font-size: 1.2rem;
  background: linear-gradient(135deg, #06b6d4, #2dd4bf);
  box-shadow: 0 6px 14px rgba(6,182,212,.35);
}
.ask-ai-modal-meta { flex: 1 1 auto; min-width: 0; line-height: 1.25; }
.ask-ai-modal-title { font-weight: 700; font-size: 1.02rem; }
.ask-ai-modal-sub { font-size: .74rem; color: var(--bs-secondary-color); }
.ask-ai-dot {
  display: inline-block; width: 7px; height: 7px; border-radius: 50%;
  background: #10b981; margin-right: 5px; vertical-align: 1px;
  box-shadow: 0 0 0 3px rgba(16,185,129,.18);
  animation: aaPulse 1.8s ease-in-out infinite;
}
@keyframes aaPulse { 0%,100%{transform:scale(1);opacity:1} 50%{transform:scale(1.25);opacity:.7} }
.ask-ai-modal-close {
  background: transparent; border: 0; color: var(--bs-secondary-color);
  font-size: 1.05rem; padding: .3rem .55rem; border-radius: 8px;
  transition: background-color .15s ease, color .15s ease;
}
.ask-ai-modal-close:hover { background: var(--bs-tertiary-bg); color: var(--bs-body-color); }
.ask-ai-modal-chips {
  display: flex; gap: .4rem; padding: .75rem 1.1rem;
  border-bottom: 1px solid var(--bs-border-color);
  overflow-x: auto; scrollbar-width: thin;
}
.ask-ai-modal-chip {
  flex-shrink: 0; font-size: .76rem; font-weight: 600;
  padding: .35rem .8rem; border-radius: 999px;
  background: var(--bs-tertiary-bg); border: 1px solid var(--bs-border-color);
  color: var(--bs-body-color); cursor: pointer;
  transition: background-color .15s ease, border-color .15s ease, color .15s ease;
}
.ask-ai-modal-chip:hover { background: rgba(6,182,212,.1); border-color: rgba(6,182,212,.4); color: #0891b2; }
.ask-ai-modal-thread {
  flex: 1 1 auto; overflow-y: auto; padding: 1rem 1.1rem;
  display: flex; flex-direction: column; gap: .7rem;
  min-height: 180px; max-height: 45vh;
}
.ask-ai-msg {
  display: flex; gap: .55rem; align-items: flex-start;
  animation: aaMsgIn .3s cubic-bezier(.22,.61,.36,1);
}
@keyframes aaMsgIn { from { transform: translateY(6px); opacity: 0; } to { transform: none; opacity: 1; } }
.ask-ai-msg-avatar {
  width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: .8rem; color: #fff;
}
.ask-ai-msg.you { flex-direction: row-reverse; }
.ask-ai-msg.you .ask-ai-msg-avatar { background: linear-gradient(135deg, #64748b, #94a3b8); }
.ask-ai-msg.ai .ask-ai-msg-avatar { background: linear-gradient(135deg, #06b6d4, #2dd4bf); }
.ask-ai-msg-bubble {
  max-width: 78%; padding: .55rem .85rem; border-radius: 14px;
  font-size: .87rem; line-height: 1.5;
  background: var(--bs-tertiary-bg); border: 1px solid var(--bs-border-color);
  white-space: pre-wrap;
}
.ask-ai-msg.you .ask-ai-msg-bubble {
  background: linear-gradient(135deg, #0891b2, #06b6d4); color: #fff; border-color: transparent;
}
.ask-ai-msg.error .ask-ai-msg-bubble {
  background: rgba(239,68,68,.08); border-color: rgba(239,68,68,.35); color: #b91c1c;
}
[data-bs-theme="dark"] .ask-ai-msg.error .ask-ai-msg-bubble { color: #fca5a5; }
.ask-ai-typing { display: inline-flex; gap: 4px; }
.ask-ai-typing span {
  width: 6px; height: 6px; border-radius: 50%; background: #06b6d4;
  animation: aaTypingDot 1.2s ease-in-out infinite;
}
.ask-ai-typing span:nth-child(2) { animation-delay: .15s; }
.ask-ai-typing span:nth-child(3) { animation-delay: .3s; }
@keyframes aaTypingDot { 0%,60%,100%{transform:translateY(0);opacity:.55} 30%{transform:translateY(-5px);opacity:1} }
.ask-ai-modal-form {
  display: flex; gap: .5rem; padding: .8rem 1.1rem;
  border-top: 1px solid var(--bs-border-color);
}
.ask-ai-modal-form .form-control {
  border-radius: 12px; padding: .6rem .9rem; font-size: .92rem;
}
.ask-ai-modal-send {
  border: 0; background: linear-gradient(135deg, #06b6d4, #2dd4bf);
  color: #fff; width: 44px; height: 44px; flex-shrink: 0; border-radius: 12px;
  display: inline-flex; align-items: center; justify-content: center;
  box-shadow: 0 6px 14px rgba(6,182,212,.35);
  transition: transform .15s ease, box-shadow .15s ease;
}
.ask-ai-modal-send:hover { transform: translateY(-1px); box-shadow: 0 10px 22px rgba(6,182,212,.45); }
.ask-ai-modal-send:disabled { opacity: .5; cursor: not-allowed; transform: none; }
.ask-ai-modal-footer {
  padding: .6rem 1.1rem; font-size: .74rem; color: var(--bs-secondary-color);
  border-top: 1px solid var(--bs-border-color); background: var(--bs-tertiary-bg);
}
</style>

<script>
(function () {
  // ============================================================
  // Global Ask AI modal wiring — real LLM Q&A (not the live chat).
  // ============================================================
  var __askAiHasGreeted = false;

  function openAskAiModal(seedQ) {
    var m = document.getElementById('ask-ai-modal');
    if (!m) return;
    m.classList.add('open');
    document.body.style.overflow = 'hidden';
    if (!__askAiHasGreeted) {
      __appendAskAiMsg('ai', "Hi! I'm the store assistant. Ask me anything about products, delivery, licences or refunds — I'll answer instantly.");
      __askAiHasGreeted = true;
    }
    setTimeout(function () {
      var i = document.getElementById('ask-ai-modal-input');
      if (i) i.focus();
      if (seedQ) { i.value = seedQ; __askAiModalSend(seedQ); }
    }, 100);
  }
  window.openAskAiModal = openAskAiModal;
  function closeAskAiModal() {
    var m = document.getElementById('ask-ai-modal');
    if (!m) return;
    m.classList.remove('open');
    document.body.style.overflow = '';
  }
  window.closeAskAiModal = closeAskAiModal;

  function __appendAskAiMsg(who, text, cls) {
    var t = document.getElementById('ask-ai-modal-thread');
    if (!t) return null;
    var wrap = document.createElement('div');
    wrap.className = 'ask-ai-msg ' + who + (cls ? ' ' + cls : '');
    var av  = document.createElement('div');
    av.className = 'ask-ai-msg-avatar';
    av.innerHTML = who === 'you' ? '<i class="bi bi-person-fill"></i>' : '<i class="bi bi-stars"></i>';
    var bub = document.createElement('div');
    bub.className = 'ask-ai-msg-bubble';
    bub.textContent = text;
    wrap.appendChild(av); wrap.appendChild(bub);
    t.appendChild(wrap);
    t.scrollTop = t.scrollHeight;
    return bub;
  }

  async function __askAiModalSend(question) {
    var inp = document.getElementById('ask-ai-modal-input');
    var btn = document.querySelector('.ask-ai-modal-send');
    __appendAskAiMsg('you', question);
    if (inp) inp.value = '';
    if (btn) btn.disabled = true;
    var typing = __appendAskAiMsg('ai', '');
    if (typing) typing.innerHTML = '<span class="ask-ai-typing"><span></span><span></span><span></span></span>';
    try {
      var r = await fetch('ajax/ask-ai-general.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ question: question }),
      });
      var j = await r.json();
      if (typing && typing.parentElement) typing.parentElement.remove();
      if (j.ok && j.answer) {
        __appendAskAiMsg('ai', j.answer);
      } else {
        __appendAskAiMsg('ai', j.error || "Sorry — I couldn't answer that. Please try live chat.", 'error');
      }
    } catch (err) {
      if (typing && typing.parentElement) typing.parentElement.remove();
      __appendAskAiMsg('ai', 'Network hiccup — please try again in a moment.', 'error');
    }
    if (btn) btn.disabled = false;
    if (inp) inp.focus();
  }

  window.askAiModalSubmit = function (ev) {
    ev.preventDefault();
    var inp = document.getElementById('ask-ai-modal-input');
    if (!inp) return false;
    var q = (inp.value || '').trim();
    if (!q) return false;
    __askAiModalSend(q);
    return false;
  };

  // Chip clicks -> seed the input & send.
  document.addEventListener('click', function (e) {
    var chip = e.target.closest('.ask-ai-modal-chip');
    if (chip) {
      var q = chip.getAttribute('data-q') || chip.textContent.trim();
      if (q) __askAiModalSend(q);
    }
  });

  // ESC key closes the modal.
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      var m = document.getElementById('ask-ai-modal');
      if (m && m.classList.contains('open')) closeAskAiModal();
    }
  });

  // Convenience: expose openAskAiModal via a data attribute for buttons that
  // used to call toggleChat().
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-open-ask-ai]');
    if (t) { e.preventDefault(); openAskAiModal(t.getAttribute('data-open-ask-ai') || ''); }
  });
})();
</script>

<!-- AI chat widget -->
<button id="chat-bubble" onclick="toggleChat()" aria-label="Open chat" data-testid="chat-bubble">
  <i class="bi bi-chat-dots"></i>
  <!-- Tiny bell + unread count overlay; surfaces the moment an admin replies
       while the panel is closed.  Disappears once the customer opens chat
       or starts typing a reply. -->
  <span id="chat-bell" class="chat-bell" style="display:none;" data-testid="chat-bell" aria-hidden="true">
    <i class="bi bi-bell-fill"></i>
    <span id="chat-bell-count" class="chat-bell-count" data-testid="chat-bell-count">1</span>
  </span>
</button>

<script>
/* ============================================================
   Draggable chat bubble — the customer can drag the "Chat" bubble to any
   edge of the viewport and it snaps to the nearest side. Position is
   persisted per browser via localStorage. Works with both mouse + touch.
   Small movements (< 5px) are treated as clicks (open chat), so accidental
   drag doesn't hijack the click handler.
   ============================================================ */
(function () {
  var bubble = document.getElementById('chat-bubble');
  if (!bubble) return;

  var STORAGE_KEY = 'mv_chat_bubble_pos';
  var MARGIN      = 18;   // min px from any viewport edge
  var CLICK_SLOP  = 5;    // px moved before it's a "drag" not a click

  function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }
  function applyPos(x, y) {
    // Snap so the bubble stays fully on-screen.
    var w = bubble.offsetWidth, h = bubble.offsetHeight;
    x = clamp(x, MARGIN, window.innerWidth  - w - MARGIN);
    y = clamp(y, MARGIN, window.innerHeight - h - MARGIN);
    bubble.style.left   = x + 'px';
    bubble.style.top    = y + 'px';
    bubble.style.right  = 'auto';
    bubble.style.bottom = 'auto';
  }
  function savePos() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({
        left: parseFloat(bubble.style.left) || 0,
        top:  parseFloat(bubble.style.top)  || 0,
      }));
    } catch (_) {}
  }
  function restorePos() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return;
      var p = JSON.parse(raw);
      if (p && typeof p.left === 'number' && typeof p.top === 'number') applyPos(p.left, p.top);
    } catch (_) {}
  }
  restorePos();

  var dragging = false, moved = false;
  var startPX = 0, startPY = 0, startBX = 0, startBY = 0;

  function onDown(ev) {
    var t = (ev.touches && ev.touches[0]) || ev;
    dragging = true; moved = false;
    startPX = t.clientX; startPY = t.clientY;
    var r = bubble.getBoundingClientRect();
    startBX = r.left; startBY = r.top;
    bubble.classList.add('is-dragging');
    // Prevent text selection while dragging.
    document.body.style.userSelect = 'none';
  }
  function onMove(ev) {
    if (!dragging) return;
    var t = (ev.touches && ev.touches[0]) || ev;
    var dx = t.clientX - startPX, dy = t.clientY - startPY;
    if (!moved && (Math.abs(dx) > CLICK_SLOP || Math.abs(dy) > CLICK_SLOP)) moved = true;
    if (moved) {
      applyPos(startBX + dx, startBY + dy);
      if (ev.preventDefault) ev.preventDefault();
    }
  }
  function onUp(ev) {
    if (!dragging) return;
    dragging = false;
    bubble.classList.remove('is-dragging');
    document.body.style.userSelect = '';
    if (moved) {
      savePos();
      // Consume the click that would otherwise fire (Safari/iOS especially).
      if (ev && ev.type === 'mouseup') {
        var kill = function (e) { e.stopPropagation(); e.preventDefault(); bubble.removeEventListener('click', kill, true); };
        bubble.addEventListener('click', kill, true);
      }
    }
  }

  bubble.addEventListener('mousedown',  onDown);
  bubble.addEventListener('touchstart', onDown, { passive: true });
  document.addEventListener('mousemove', onMove, { passive: false });
  document.addEventListener('touchmove', onMove, { passive: false });
  document.addEventListener('mouseup',   onUp);
  document.addEventListener('touchend',  onUp);
  document.addEventListener('touchcancel', onUp);
  // Keep on-screen on window resize / orientation change.
  window.addEventListener('resize', function () {
    var r = bubble.getBoundingClientRect();
    if (r.left < 0 || r.top < 0 || r.right > window.innerWidth || r.bottom > window.innerHeight) {
      applyPos(r.left, r.top);
      savePos();
    }
  });
})();
</script>
<style>
/* Give the chat bubble a subtle visual affordance for drag: the cursor
   changes to grab, and there's a nicer pressed state during a drag. */
#chat-bubble { cursor: grab; touch-action: none; user-select: none; }
#chat-bubble.is-dragging { cursor: grabbing; transform: scale(1.08); box-shadow: 0 14px 32px rgba(6,182,212,.55); transition: none; }
</style>
<!-- Messenger-style admin-reply preview — slides in to the LEFT of the
     chat bubble whenever an admin reply lands while the panel is closed,
     so the customer can see what the agent said before opening chat.
     Clicking it opens the chat immediately.  Auto-fades when the chat
     opens or the customer starts replying. -->
<div id="chat-msg-preview" class="chat-msg-preview" style="display:none;" onclick="openChatFromPreview()" data-testid="chat-msg-preview" role="button" tabindex="0">
  <div class="chat-msg-preview-head">
    <span class="chat-msg-preview-avatar"><i class="bi bi-headset"></i></span>
    <div class="chat-msg-preview-meta">
      <div class="chat-msg-preview-name">Maventech Support</div>
      <div class="chat-msg-preview-sub"><span class="chat-online-dot"></span>just now</div>
    </div>
    <button class="chat-msg-preview-close" type="button" onclick="event.stopPropagation(); hideChatMsgPreview();" aria-label="Dismiss preview" data-testid="chat-msg-preview-close"><i class="bi bi-x"></i></button>
  </div>
  <div class="chat-msg-preview-body" id="chat-msg-preview-body" data-testid="chat-msg-preview-body">—</div>
  <div class="chat-msg-preview-cta">Tap to reply →</div>
</div>
<div id="chat-panel" data-testid="chat-panel">
  <div id="chat-head" class="d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
      <button type="button" class="chat-head-btn chat-head-back" onclick="toggleChat()" aria-label="Minimize chat" data-testid="chat-back"><i class="bi bi-chevron-left"></i></button>
      <span class="chat-avatar chat-avatar-photo"><img src="https://images.pexels.com/photos/7709255/pexels-photo-7709255.jpeg?auto=compress&cs=tinysrgb&w=160&h=160&fit=crop" alt="Addie" loading="lazy" decoding="async"></span>
      <div class="lh-sm">
        <div class="chat-head-name" data-testid="chat-head-name">Addie</div>
        <small class="chat-head-sub">The team can also help</small>
      </div>
    </div>
    <div class="d-flex align-items-center gap-1">
      <button type="button" class="chat-head-btn" onclick="toggleChat()" aria-label="More options" data-testid="chat-menu"><i class="bi bi-three-dots"></i></button>
      <button type="button" class="chat-head-btn" onclick="toggleChat()" aria-label="Close chat" data-testid="chat-close"><i class="bi bi-x-lg"></i></button>
    </div>
  </div>
  <div id="chat-body">
    <!-- Addie greeting — always shown at the top of the thread.  Mirrors the
         friendly first-touch message; the 3-field contact form sits right
         below it on first open. -->
    <div class="chat-addie-greeting" id="chat-addie-greeting" data-testid="chat-addie-greeting">
      <div class="chat-msg bot chat-addie-bubble"><strong>Need help with your order? 👋</strong><br>Chat with an order specialist about your <?= esc($brandName) ?> order or license delivery.</div>
      <div class="chat-addie-meta">Addie • Just now</div>
    </div>
    <!-- AI welcome + quick chips kept in markup for ProAssist auto-open flows
         but hidden by default until JS detects the customer is already
         identified (proLeadId, returning lead, etc.). -->
    <div class="chat-msg bot" id="chat-welcome-msg" data-testid="chat-default-message" style="display:none;">Need help with your order or license delivery? Chat with an order specialist and we'll help with products, pricing, delivery or activation.</div>
    <div class="chat-chips" id="chat-chips" data-testid="chat-chips" style="display:none;">
      <button class="chat-chip" onclick="quickAsk('Which Office is right for my Mac?')" data-testid="chat-chip-mac"><i class="bi bi-apple me-1"></i>Office for Mac</button>
      <button class="chat-chip" onclick="quickAsk('What is the best deal on Office 2024 right now?')" data-testid="chat-chip-deal"><i class="bi bi-tags me-1"></i>Best deals on Office 2024</button>
      <button class="chat-chip" onclick="quickAsk('How do I activate my license key after purchase?')" data-testid="chat-chip-activate"><i class="bi bi-key me-1"></i>Activation help</button>
      <button class="chat-chip" onclick="quickAsk('Do your licenses expire or need a subscription?')" data-testid="chat-chip-license"><i class="bi bi-infinity me-1"></i>License validity</button>
    </div>

    <!-- ====================================================================
         INITIAL VIEW (iteration 20): chat opens straight to the contact
         form — just 3 fields (full name, email, phone) and ONE blue send
         arrow button.  No "type a message" box yet.  Once the customer
         submits, this card is hidden and we reveal:
           (a) a "Thanks for contacting the support team" agent greeting
           (b) the message input box (chat-input-row below)
         The customer's real question is then routed straight to admin
         lead management — no AI auto-replies in between.
         ==================================================================== -->
    <div id="chat-lead-form" class="chat-lead-card" style="display:block;" data-testid="chat-lead-form">
      <div class="chat-lead-title" data-testid="chat-lead-title">Tell us how to reach you, and a support agent will get back in a few minutes.</div>
      <div class="chat-lead-field-row">
        <input id="lead-name"  class="form-control form-control-sm chat-lead-input" placeholder="Full name"      data-testid="lead-name"  autocomplete="name">
      </div>
      <div class="chat-lead-field-row">
        <input id="lead-email" type="email" class="form-control form-control-sm chat-lead-input" placeholder="Email address" data-testid="lead-email" autocomplete="email">
      </div>
      <div class="chat-lead-field-row chat-lead-row-send">
        <input id="lead-phone" class="form-control form-control-sm chat-lead-input" placeholder="Phone number"   data-testid="lead-phone" autocomplete="tel">
        <button type="button"
                class="chat-lead-send-btn"
                onclick="submitLead('chat')"
                data-testid="lead-send-btn"
                aria-label="Send to support">
          <i class="bi bi-send-fill"></i>
        </button>
      </div>
      <div id="chat-lead-error" class="chat-lead-error" style="display:none;" data-testid="chat-lead-error"></div>
      <!-- Backwards-compat hidden button so older test scripts that click
           [data-testid=lead-chat-btn] still trigger submitLead('chat'). -->
      <button type="button" class="d-none" onclick="submitLead('chat')" data-testid="lead-chat-btn"></button>
    </div>
    <!-- ProAssist install-call scheduler — shown when JS detects a ProAssist
         purchaser with no booking yet.  Customer picks timezone → date → time.
         Bookings convert to IST in the admin panel. -->
    <div id="pa-sched-card" class="pa-sched-card" style="display:none;" data-testid="pa-sched-card">
      <div class="pa-sched-header">
        <i class="bi bi-calendar-check"></i>
        <div>
          <div class="pa-sched-title" data-testid="pa-sched-title">Schedule a callback</div>
          <div class="pa-sched-sub" data-testid="pa-sched-sub">Pick a time that works for you</div>
        </div>
      </div>
      <div class="pa-sched-step">
        <div class="pa-sched-step-label">Your time zone</div>
        <select id="pa-sched-tz" class="pa-sched-tz-select" data-testid="pa-sched-tz-select" aria-label="Time zone"></select>
      </div>
      <div class="pa-sched-step">
        <div class="pa-sched-step-label">Select a date</div>
        <div class="pa-sched-dates" id="pa-sched-dates" data-testid="pa-sched-dates"></div>
      </div>
      <div class="pa-sched-step" id="pa-sched-times-step" style="display:none;">
        <div class="pa-sched-step-label">Available times <span class="pa-sched-tz" id="pa-sched-times-tz"></span></div>
        <div class="pa-sched-times" id="pa-sched-times" data-testid="pa-sched-times"></div>
        <button type="button" class="pa-sched-back" onclick="paSchedBackToDates()" data-testid="pa-sched-back">&larr; Back to dates</button>
      </div>
      <div class="pa-sched-error" id="pa-sched-error" style="display:none;" data-testid="pa-sched-error"></div>
    </div>
    <!-- Confirmed-booking card (shown after a successful book / on reopen). -->
    <div id="pa-sched-confirm" class="pa-sched-confirm" style="display:none;" data-testid="pa-sched-confirm"></div>
  </div>
  <div id="chat-typing" class="chat-typing" style="display:none;" data-testid="chat-admin-typing">
    <div class="chat-typing-bubble">
      <span class="chat-typing-dot"></span>
      <span class="chat-typing-dot"></span>
      <span class="chat-typing-dot"></span>
      <span class="chat-typing-text">Live agent is typing…</span>
    </div>
  </div>
  <form id="chat-input-row" class="chat-input-row d-none p-2" onsubmit="sendChat(event)" data-testid="chat-input-row">
    <div class="chat-composer">
      <input id="chat-input" class="chat-input" placeholder="Ask a question…" autocomplete="off" data-testid="chat-input">
      <div class="chat-composer-tools">
        <button type="button" class="chat-tool-btn" id="chat-attach-btn" onclick="chatAttachClick()" aria-label="Attach a file" title="Attach a file" data-testid="chat-attach-btn"><i class="bi bi-paperclip"></i></button>
        <button type="button" class="chat-tool-btn chat-mic-tip" id="chat-mic-btn" onclick="chatToggleVoice()" aria-label="Voice to text (speak in any language, replies appear in English)" title="🎙️ Speak in any language — replies appear in English" data-tip="Speak in any language — replies appear in English" data-testid="chat-mic-btn"><i class="bi bi-mic-fill"></i></button>
        <button type="button" class="chat-tool-btn" id="chat-emoji-btn" onclick="chatToggleEmoji(event)" aria-label="Insert emoji" title="Insert emoji" data-testid="chat-emoji-btn"><i class="bi bi-emoji-smile"></i></button>
        <span class="chat-voice-timer" id="chat-voice-timer" style="display:none;" data-testid="chat-voice-timer"><span class="chat-voice-rec-dot"></span><span id="chat-voice-time">0:00</span></span>
        <button class="chat-send-btn ms-auto" type="submit" aria-label="Send" data-testid="chat-send"><i class="bi bi-arrow-up"></i></button>
      </div>
    </div>
    <input type="file" id="chat-file-input" class="d-none" data-testid="chat-file-input" accept="image/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip">
    <div class="chat-emoji-panel" id="chat-emoji-panel" style="display:none;" data-testid="chat-emoji-panel"></div>
    <div class="chat-attach-status" id="chat-attach-status" style="display:none;" data-testid="chat-attach-status"></div>
  </form>
  <div class="chat-talk-band" data-testid="chat-talk-band">Prefer to talk?<a href="tel:<?= esc(tel_e164($brandPhone)) ?>" class="chat-talk-phone" data-testid="chat-talk-phone"><i class="bi bi-telephone-fill chat-talk-phone-ring"></i><?= esc($brandPhone) ?></a></div>
</div>

<!--
   Defer non-critical JS so the browser can render the hero + nav before
   parsing/executing.  `defer` keeps the original execution order
   (Bootstrap → main.js) and waits until DOMContentLoaded — same behaviour
   as the previous blocking <script> but with no parser pause.  This is
   the single biggest Core-Web-Vitals win for a server-rendered site.
-->
<script defer src="assets/vendor/bootstrap.bundle.min.js?v=<?= esc(@filemtime(__DIR__ . '/../assets/vendor/bootstrap.bundle.min.js')) ?>"></script>
<script defer src="assets/js/main.js?v=<?= esc(@filemtime(__DIR__ . '/../assets/js/main.js')) ?>"></script>
<!-- 3D scroll effects (reveal-on-scroll + subtle pointer tilt; reduced-motion aware) -->
<!-- `defer` added 2026-07-07 — PageSpeed Insights (desktop + mobile) flagged
     this script as render-blocking (~590 ms savings). scroll3d.js only wires
     up IntersectionObservers + mouseenter handlers, none of which need to
     run before DOMContentLoaded, so `defer` is fully safe here. Also
     eliminates a script-eval spike that was contributing to Total Blocking
     Time (was 550 ms). -->
<script defer src="assets/js/scroll3d.js?v=<?= esc(@filemtime(__DIR__ . '/../assets/js/scroll3d.js')) ?>"></script>

<!--
   Lazy-load + async-decode every image that's not already in the initial
   viewport.  Saves bandwidth on long product pages and improves LCP for
   the few images that DO need to load eagerly above the fold.  Runs once
   on DOMContentLoaded; the IntersectionObserver branch upgrades the
   "lazy" attribute to a real observer for browsers that need it.
-->
<script>
(function(){
  function applyLazy(){
    var vh = window.innerHeight || 800;
    document.querySelectorAll('img:not([loading]):not([data-eager])').forEach(function(img){
      var rect = img.getBoundingClientRect();
      // First-viewport images stay eager (LCP candidates); everything else
      // is marked lazy + async-decode so the main thread doesn't block.
      if (rect.top > vh) {
        img.loading = 'lazy';
        img.decoding = 'async';
      } else {
        // Hint to the browser this is high-priority LCP material.
        img.fetchPriority = 'high';
      }
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyLazy, { once: true });
  } else {
    applyLazy();
  }
})();
</script>
</body>
</html>

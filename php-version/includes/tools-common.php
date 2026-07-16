<?php
/**
 * includes/tools-common.php
 * Shared hero, styles and FAQ helpers for the free /tools/* pages.
 * Each tool page requires this after functions.php and sets $baseHref so
 * relative assets resolve against the site root (pages live under /tools/).
 */

/** Emit the shared tools stylesheet exactly once per request. */
function tools_styles(): void {
    static $done = false; if ($done) return; $done = true; ?>
<style>
.mv-tool{--t-accent:#2563eb;--t-orange:#f97316;}
.mv-tool .mvt-hero{padding:2.4rem 0 1.4rem;background:linear-gradient(180deg,rgba(37,99,235,.06),transparent);}
.mv-tool .mvt-crumb{font-size:.8rem;color:#64748b;margin-bottom:.5rem;}
.mv-tool .mvt-crumb a{color:#64748b;text-decoration:none;}
.mv-tool .mvt-crumb a:hover{color:var(--t-accent);}
.mv-tool .mvt-badge{display:inline-flex;align-items:center;gap:.3rem;background:rgba(37,99,235,.1);color:var(--t-accent);font-weight:700;font-size:.74rem;letter-spacing:1px;text-transform:uppercase;padding:.3rem .7rem;border-radius:999px;margin-bottom:.6rem;}
.mv-tool .mvt-title{font-size:2.1rem;font-weight:800;line-height:1.1;margin-bottom:.4rem;}
.mv-tool .mvt-sub{color:#64748b;max-width:760px;font-size:1.02rem;}
.mv-tool .mvt-section{padding:1.4rem 0;}
.mv-tool .mvt-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:1.4rem;}
.mv-tool .mvt-h2{font-size:1.3rem;font-weight:800;margin-bottom:.3rem;}
.mv-tool .mvt-lead{color:#64748b;font-size:.92rem;margin-bottom:1rem;}
.mv-tool .mvt-label{font-weight:700;font-size:.85rem;margin-bottom:.3rem;display:block;}
.mv-tool .form-control,.mv-tool .form-select{border-radius:10px;}
.mv-tool .mvt-note{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:10px;padding:.7rem .9rem;font-size:.85rem;}
.mv-tool .mvt-result{border-radius:12px;padding:1rem 1.1rem;font-weight:600;margin-top:1rem;display:none;}
.mv-tool .mvt-result.show{display:block;}
.mv-tool .mvt-ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;}
.mv-tool .mvt-warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;}
.mv-tool .mvt-bad{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
.mv-tool .mvt-cmd{background:#0f172a;color:#e2e8f0;border-radius:10px;padding:.7rem .9rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.82rem;position:relative;overflow-x:auto;}
.mv-tool .mvt-cmd .mvt-copy{position:absolute;top:.4rem;right:.4rem;background:#334155;color:#fff;border:0;border-radius:6px;font-size:.7rem;padding:.2rem .5rem;cursor:pointer;}
.mv-tool .table{font-size:.88rem;}
.mv-tool .mvt-status{font-weight:700;padding:.1rem .5rem;border-radius:6px;font-size:.78rem;}
.mv-tool .mvt-status.current{background:#dbeafe;color:#1e40af;}
.mv-tool .mvt-status.supported{background:#dcfce7;color:#166534;}
.mv-tool .mvt-status.ending{background:#fef9c3;color:#854d0e;}
.mv-tool .mvt-status.ended{background:#fee2e2;color:#991b1b;}
.mv-tool .mvt-totalbox{background:#f8fafc;border:1px dashed #cbd5e1;border-radius:12px;padding:1rem;text-align:center;margin-top:1rem;}
.mv-tool .mvt-total{font-size:2rem;font-weight:800;color:var(--t-orange);}
.mv-tool .mvt-toolcard{display:block;height:100%;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:1.3rem;text-decoration:none;color:inherit;transition:.18s;}
.mv-tool .mvt-toolcard:hover{border-color:var(--t-accent);box-shadow:0 16px 34px -22px rgba(2,6,23,.4);transform:translateY(-3px);}
.mv-tool .mvt-toolcard i{font-size:1.7rem;color:var(--t-accent);}
.mv-tool .mvt-toolcard h3{font-size:1.1rem;font-weight:800;margin:.5rem 0 .3rem;}
.mv-tool .mvt-toolcard p{color:#64748b;font-size:.86rem;margin:0;}
.mv-tool .mvt-faq .accordion-button{font-weight:700;font-size:.92rem;}
.mv-tool .mvt-faq .accordion-button:not(.collapsed){color:var(--t-accent);background:rgba(37,99,235,.06);box-shadow:none;}
.mv-tool .mvt-src{font-size:.78rem;color:#94a3b8;margin-top:.6rem;}
[data-bs-theme="dark"] .mv-tool .mvt-card,[data-bs-theme="dark"] .mv-tool .mvt-toolcard{background:#111827;border-color:#1f2937;}
[data-bs-theme="dark"] .mv-tool .mvt-title,[data-bs-theme="dark"] .mv-tool .mvt-h2,[data-bs-theme="dark"] .mv-tool .mvt-toolcard h3{color:#e2e8f0;}
[data-bs-theme="dark"] .mv-tool .mvt-totalbox{background:#0b1220;border-color:#334155;}
</style>
<?php }

/** Render the shared tool hero header. */
function tools_hero(string $title, string $sub, bool $withCrumb = true): void { ?>
  <section class="mvt-hero">
    <div class="container">
      <?php if ($withCrumb): ?>
      <div class="mvt-crumb"><a href="/tools">Free Tools</a> <i class="bi bi-chevron-right" style="font-size:.7rem;"></i> <span><?= esc($title) ?></span></div>
      <?php endif; ?>
      <span class="mvt-badge"><i class="bi bi-tools"></i> Free Tool · No sign-up</span>
      <h1 class="mvt-title"><?= esc($title) ?></h1>
      <p class="mvt-sub"><?= esc($sub) ?></p>
    </div>
  </section>
<?php }

/** Render a Bootstrap FAQ accordion. $faqs = [[q,a], ...] */
function tools_faq(array $faqs, string $id = 'mvtFaq'): void { ?>
  <section class="mvt-section">
    <div class="container" style="max-width:820px;">
      <h2 class="mvt-h2 text-center mb-3">Frequently Asked Questions</h2>
      <div class="accordion mvt-faq" id="<?= esc($id) ?>">
        <?php foreach ($faqs as $i => $f): ?>
        <div class="accordion-item">
          <h3 class="accordion-header" id="<?= esc($id) ?>H<?= $i ?>">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= esc($id) ?>C<?= $i ?>" aria-expanded="false"><?= esc($f[0]) ?></button>
          </h3>
          <div id="<?= esc($id) ?>C<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#<?= esc($id) ?>">
            <div class="accordion-body" style="font-size:.9rem;color:#475569;"><?= esc($f[1]) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php }

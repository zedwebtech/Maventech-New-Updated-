<?php
/**
 * normalize-company-phone.php
 * ============================================================
 * One-shot idempotent migration that replaces every occurrence of
 * the OLD Maventech toll-free number with the CURRENT one across
 * the entire database.
 *
 * Why this script exists:
 * When a company phone number changes, it can leak on dozens of
 * surfaces — settings rows, static pages content, blog posts,
 * FAQs, email templates, previously-queued email_outbox rows,
 * customer subscription notes, chat transcripts and anywhere
 * else the number was ever rendered as plain text.  Fixing them
 * one-by-one from the admin panel is slow and error-prone; this
 * script does them all in a single pass.
 *
 * It is guarded by settings.phone_normalized_current so it only
 * runs once per (old→new) pair.  When the merchant changes the
 * phone AGAIN later, bump the constant NEW_PHONE_DISPLAY and
 * the guard resets itself.
 *
 * Boot-safe: called from start.sh on every restart; short-
 * circuits in <1ms once the guard is set.
 * ============================================================
 */

require_once __DIR__ . '/../includes/functions.php';

/* ─── Configure the phone numbers here ────────────────────── */
// New target number — canonical display, no country code
const NEW_PHONE_DISPLAY   = '(888) 812-0952';
// tel: href form (E.164, no formatting)
const NEW_PHONE_E164      = '+18888120952';
// With country code, human-readable
const NEW_PHONE_WITH_CC   = '+1 (888) 812-0952';

// Every variant of the OLD number that ever shipped in this
// codebase.  ORDER MATTERS — longest / most-specific variants
// come first so shorter matches inside them don't fire early.
$REPLACEMENTS = [
    // Previous generation (888) 632-9902 — still present on some pods.
    '+18886329902'      => NEW_PHONE_E164,
    '+1 888-632-9902'   => NEW_PHONE_WITH_CC,
    '1-888-632-9902'    => NEW_PHONE_DISPLAY,
    '(888) 632-9902'    => NEW_PHONE_DISPLAY,
    '888-632-9902'      => NEW_PHONE_DISPLAY,
    // Immediate-previous number (805) 294-1524 — every seed row, email
    // template, chat greeting, DB page and admin note still points at
    // this one.  ORDER: E.164 first so the digit-only match doesn't
    // eat the leading '+1'.
    '+18052941524'      => NEW_PHONE_E164,
    '+1 (805) 294-1524' => NEW_PHONE_WITH_CC,
    '+1 805-294-1524'   => NEW_PHONE_WITH_CC,
    '1-805-294-1524'    => NEW_PHONE_DISPLAY,
    '(805) 294-1524'    => NEW_PHONE_DISPLAY,
    '805-294-1524'      => NEW_PHONE_DISPLAY,
    '805.294.1524'      => NEW_PHONE_DISPLAY,
    '8052941524'        => str_replace([' ', '(', ')', '-'], '', NEW_PHONE_DISPLAY),
    // Older legacy 1-805-823-9961 — still baked into some pod DBs,
    // llms.txt, seed scripts and refund-policy HTML.  Sweep so the
    // whole stack (rendered pages, AI-manifests, admin notes) lands
    // on the current toll-free number in one pass.
    '+18058239961'      => NEW_PHONE_E164,
    '+1 805-823-9961'   => NEW_PHONE_WITH_CC,
    '1-805-823-9961'    => NEW_PHONE_DISPLAY,
    '(805) 823-9961'    => NEW_PHONE_DISPLAY,
    '805-823-9961'      => NEW_PHONE_DISPLAY,
    '8058239961'        => str_replace([' ', '(', ')', '-'], '', NEW_PHONE_DISPLAY),
];

/* ─── Idempotency guard ───────────────────────────────────── */
$guardKey   = 'phone_normalized_current';
$guardValue = NEW_PHONE_E164;
try {
    $st = db()->prepare("SELECT v FROM settings WHERE k = ? LIMIT 1");
    $st->execute([$guardKey]);
    $current = (string)($st->fetchColumn() ?: '');
    if ($current === $guardValue) {
        // Already normalized for this target number — nothing to do.
        exit(0);
    }
} catch (Throwable $e) {
    // If the settings table isn't there yet the boot script will
    // seed it before running us again; just exit quietly.
    exit(0);
}

echo "[normalize-company-phone] Rewriting old phone → " . NEW_PHONE_DISPLAY . "\n";

/* ─── 1. Overwrite the canonical phone settings rows ──────── */
$phoneSettings = [
    // Primary display number (used by header, footer, checkout,
    // Need Assistance card, order emails).
    'company_phone'        => NEW_PHONE_DISPLAY,
    'contact_phone'        => NEW_PHONE_DISPLAY,
    'support_phone'        => NEW_PHONE_DISPLAY,
    'toll_free_phone'      => NEW_PHONE_DISPLAY,
];
foreach ($phoneSettings as $k => $v) {
    try {
        db()->prepare("INSERT INTO settings (k, v) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE v = VALUES(v)")
            ->execute([$k, $v]);
    } catch (Throwable $e) { /* ignore individual failures */ }
}

/* ─── 2. Sweep every text/HTML column across the schema ──── *
 * We enumerate the content-carrying tables + columns explicitly
 * rather than crawling INFORMATION_SCHEMA blindly, so we never
 * accidentally rewrite a numeric column (e.g. order.amount) that
 * could contain the digits '8886329902' by coincidence.
 */
$sweepTargets = [
    // [ table, [ text-column-1, text-column-2, ... ] ]
    ['settings',              ['v']],
    ['pages',                 ['content']],
    ['blog_posts',            ['content', 'excerpt', 'seo_meta_description']],
    ['email_templates',       ['html', 'body', 'subject']],
    ['email_outbox',          ['html', 'subject']],
    ['faqs',                  ['question', 'answer']],
    ['category_faqs',         ['question', 'answer']],
    ['product_faqs',          ['question', 'answer']],
    ['reviews',               ['comment']],
    ['customer_reviews',      ['comment']],
    ['chat_messages',         ['body']],
    ['subscription_notes',    ['note']],
    ['support_tickets',       ['message', 'admin_reply']],
    ['hubs',                  ['content', 'intro']],
    ['orders',                ['note', 'admin_note']],
];

$totalRewrites = 0;
foreach ($sweepTargets as [$table, $cols]) {
    // Skip missing tables silently — schemas evolve.
    try {
        db()->query("SELECT 1 FROM `$table` LIMIT 1");
    } catch (Throwable $e) {
        continue;
    }
    foreach ($cols as $col) {
        // Verify the column exists on this table before running
        // the UPDATE (again, safe against schema drift).
        try {
            db()->query("SELECT `$col` FROM `$table` LIMIT 1");
        } catch (Throwable $e) {
            continue;
        }
        foreach ($REPLACEMENTS as $oldVal => $newVal) {
            try {
                $st = db()->prepare(
                    "UPDATE `$table`
                        SET `$col` = REPLACE(`$col`, ?, ?)
                      WHERE `$col` LIKE ?"
                );
                $st->execute([$oldVal, $newVal, '%' . $oldVal . '%']);
                $n = (int)$st->rowCount();
                if ($n > 0) {
                    echo "  · rewrote " . $n . " row(s) in $table.$col (variant: $oldVal)\n";
                    $totalRewrites += $n;
                }
            } catch (Throwable $e) {
                echo "  ! failed on $table.$col: " . $e->getMessage() . "\n";
            }
        }
    }
}

echo "[normalize-company-phone] Total rows rewritten: $totalRewrites\n";

/* ─── 3. Latch the idempotency guard ──────────────────────── */
try {
    db()->prepare("INSERT INTO settings (k, v) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE v = VALUES(v)")
        ->execute([$guardKey, $guardValue]);
    echo "[normalize-company-phone] Guard set. Future boots will skip this script.\n";
} catch (Throwable $e) {
    echo "[normalize-company-phone] WARN: could not set guard row: " . $e->getMessage() . "\n";
}

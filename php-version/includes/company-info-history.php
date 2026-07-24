<?php
/**
 * Company-Info History & Sweep (2026-07)
 * ----------------------------------------------------------------------
 * Whenever an admin saves the Company Info form, the OLD value for
 * name / legal_name / phone (per-country too) / email / address is
 * archived in `company_info_history`.  When the same save happens we
 * ALSO sweep the free-text content columns in the site's data tables,
 * replacing every occurrence of the old value with the new one.
 *
 * The output-buffer branding filter (apply_company_branding) additionally
 * remaps every archived historic value to the CURRENT value on each
 * page render — that catches anything the sweep can't (constants baked
 * into cached HTML fragments, pre-generated PDFs, third-party embeds,
 * etc.).
 *
 * Public API:
 *   ensure_company_info_history_schema(): void
 *   record_company_info_change(string $key, string $old, string $new): void
 *   sweep_company_info_across_db(string $old, string $new, string $key): int
 *   company_info_historic_values(): array   // key => [old_values...]
 */

require_once __DIR__ . '/functions.php';

/**
 * Idempotent schema — auto-created on first save.
 */
function ensure_company_info_history_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS company_info_history (
            id           BIGINT      NOT NULL AUTO_INCREMENT PRIMARY KEY,
            field_key    VARCHAR(60) NOT NULL,
            old_value    MEDIUMTEXT  NOT NULL,
            new_value    MEDIUMTEXT  NOT NULL,
            changed_by   VARCHAR(120) NOT NULL DEFAULT 'admin',
            sweep_count  INT         NOT NULL DEFAULT 0,
            changed_at   TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_field_key (field_key),
            KEY idx_changed_at (changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        @error_log('[company_info_history schema] ' . $e->getMessage());
    }
}

/**
 * Record a change AND sweep the DB.
 *
 * Called by the admin `save_company_info` handler right after the new
 * value is written to `settings`.  `$old` is the value that was there
 * BEFORE the setting was overwritten; if `$old` is empty or identical
 * to `$new` we still write the history row (audit trail) but skip the
 * sweep.
 *
 * Returns: number of rows affected by the sweep (across all tables).
 */
function record_company_info_change(string $key, string $old, string $new, string $changedBy = 'admin'): int
{
    ensure_company_info_history_schema();
    $old = trim($old);
    $new = trim($new);
    $sweepCount = 0;

    // Nothing to do if the value didn't actually change.
    if ($old === $new) return 0;

    if ($old !== '' && $old !== $new) {
        // Sweep first so the sweep_count is accurate on the history row.
        $sweepCount = sweep_company_info_across_db($old, $new, $key);
    }

    try {
        db()->prepare('INSERT INTO company_info_history
            (field_key, old_value, new_value, changed_by, sweep_count)
            VALUES (?, ?, ?, ?, ?)')
            ->execute([$key, $old, $new, substr($changedBy, 0, 120), $sweepCount]);
    } catch (Throwable $e) {
        @error_log('[record_company_info_change] ' . $e->getMessage());
    }
    // Bust the apply_company_branding cache so the next request picks
    // up the new + historic mapping without a full restart.
    if (function_exists('company_branding_reset')) company_branding_reset();
    return $sweepCount;
}

/**
 * Replace every occurrence of $old with $new across the free-text
 * columns where company contact details could realistically appear.
 *
 * We are deliberately conservative:
 *   - Brand name ($key === 'company_name') is only swept in HTML/text
 *     columns and only when the old value is at least 3 characters,
 *     to avoid nuking a common noun.  Blog/page content is NOT swept
 *     for the brand name — writers deliberately mention the brand and
 *     rewriting every occurrence would corrupt SEO copy.
 *   - Phone/email/address ARE swept everywhere they can appear —
 *     they're unique enough that false positives are essentially zero.
 */
function sweep_company_info_across_db(string $old, string $new, string $key): int
{
    if ($old === '' || $old === $new || strlen($old) < 3) return 0;
    $pdo = db();
    $total = 0;

    // Columns to scan.  Format:  [ table, column, allow_brand ]
    // `allow_brand` = whether it's safe to also rewrite `company_name`
    // in this column.  Blog/page content is off-limits for the brand
    // name — see comment above.
    $targets = [
        ['settings',         'v',       true],
        ['email_templates',  'html',    true],
        ['email_templates',  'subject', true],
        ['pages',            'content', false],
        ['blog_posts',       'content', false],
        ['blog_posts',       'lead',    false],
        ['blog_posts',       'title',   false],
    ];
    // Pending outbox emails should be re-written too so we don't send
    // a queued email with the stale phone.  Sent emails are historical
    // records — leave them intact.
    $targets[] = ['email_outbox', 'html',    true];
    $targets[] = ['email_outbox', 'subject', true];

    $isBrand = ($key === 'company_name');

    foreach ($targets as [$tbl, $col, $allowBrand]) {
        if ($isBrand && !$allowBrand) continue;
        try {
            // Detect table existence — some deployments may not have
            // blog_posts / pages seeded yet.
            $exists = $pdo->query("SHOW TABLES LIKE '" . addslashes($tbl) . "'")->fetchColumn();
            if (!$exists) continue;
            // Guard the UPDATE with INSTR() so we only rewrite rows that
            // actually contain the old value.  Outbox additionally
            // requires status to be non-terminal (never touch a sent
            // record).
            $where = "INSTR(`{$col}`, ?) > 0";
            $params = [$old, $new, $old];   // REPLACE(old, new), WHERE INSTR(old)
            if ($tbl === 'email_outbox') {
                $where = "status IN ('queued','retry','failed') AND " . $where;
            }
            $sql = "UPDATE `{$tbl}` SET `{$col}` = REPLACE(`{$col}`, ?, ?) WHERE {$where}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $total += $stmt->rowCount();
        } catch (Throwable $e) {
            @error_log("[sweep {$tbl}.{$col}] " . $e->getMessage());
        }
    }
    return $total;
}

/**
 * Returns every historic (old_value → CURRENT VALUE) mapping for the
 * four public-facing fields.  Used by apply_company_branding to remap
 * anything that still leaks the previous value on output.
 *
 * Format:  [ 'old string' => 'current string', ... ]
 *
 * The mapping is keyed by old value so multiple different historic
 * phones all collapse to the SAME current phone.
 */
function company_info_historic_values(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    ensure_company_info_history_schema();
    $out = [];
    try {
        // Fields we care about (must be non-empty user-facing values).
        // Country-specific phones (company_phone_ca, _uk, _au, _eu) are
        // included so a switch on any of them also cleans the whole
        // site up.
        $fields = ['company_name', 'company_legal_name',
                   'company_email', 'company_phone',
                   'company_phone_ca', 'company_phone_uk',
                   'company_phone_au', 'company_phone_eu',
                   'company_address'];
        $placeholders = implode(',', array_fill(0, count($fields), '?'));

        // Pull every historic old_value for the interesting fields.
        $stmt = db()->prepare("SELECT field_key, old_value
            FROM company_info_history
            WHERE field_key IN ({$placeholders})
              AND old_value <> ''
            ORDER BY changed_at ASC");
        $stmt->execute($fields);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) { $cache = []; return $cache; }

        // Current value per key.
        $current = [];
        foreach ($fields as $k) $current[$k] = (string)setting_get($k, '');

        foreach ($rows as $r) {
            $k   = (string)$r['field_key'];
            $old = trim((string)$r['old_value']);
            $now = trim((string)($current[$k] ?? ''));
            if ($old === '' || $now === '' || $old === $now) continue;
            // Last one wins if the same old-value ever mapped twice.
            $out[$old] = $now;

            // Phone numbers appear in tel: links too — remap those.
            if (in_array($k, ['company_phone', 'company_phone_ca', 'company_phone_uk',
                              'company_phone_au', 'company_phone_eu'], true)) {
                $oldTel = '+' . preg_replace('/\D/', '', $old);
                $newTel = '+' . preg_replace('/\D/', '', $now);
                if (strlen($oldTel) > 4 && $oldTel !== $newTel) {
                    $out[$oldTel] = $newTel;
                }
            }
        }
    } catch (Throwable $e) {
        @error_log('[company_info_historic_values] ' . $e->getMessage());
    }
    $cache = $out;
    return $out;
}

/**
 * Manual reset helper — called after we archive a new change so the
 * next output pass picks up the fresh mapping.  apply_company_branding
 * caches its map in a static, this reaches inside via a companion
 * companion function it exposes.
 */
function company_branding_reset(): void
{
    // Toggle a settings flag so apply_company_branding notices and reloads.
    setting_set('company_branding_generation', (string)time());
}

<?php
// Region helpers — multi-region inventory + per-region pricing/tax/currency.

/**
 * Auto-bootstrap the `regions` and `settings` tables on first request.
 * Runs at most once per PHP process. Protects against partial/legacy
 * database.sql imports on shared hosting (cPanel/phpMyAdmin) where the
 * admin uploaded an older dump that didn't include these tables.
 */
function regions_bootstrap(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo = db();
        // settings (Company Info, statement names, active_region, etc.)
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(80) NOT NULL PRIMARY KEY,
            v MEDIUMTEXT NOT NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // regions
        $pdo->exec("CREATE TABLE IF NOT EXISTS regions (
            code VARCHAR(8) NOT NULL PRIMARY KEY,
            name VARCHAR(60) NOT NULL,
            currency VARCHAR(8) NOT NULL,
            currency_symbol VARCHAR(4) NOT NULL,
            tax_rate DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
            active TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Seed default regions if the table is empty
        $count = (int)$pdo->query("SELECT COUNT(*) FROM regions")->fetchColumn();
        if ($count === 0) {
            $pdo->exec("INSERT INTO regions (code, name, currency, currency_symbol, tax_rate, active) VALUES
                ('US', 'United States',  'USD', '$',  0.0000, 1),
                ('UK', 'United Kingdom', 'GBP', '£',  0.2000, 1),
                ('CA', 'Canada',         'CAD', 'C$', 0.1300, 1),
                ('AU', 'Australia',      'AUD', 'A$', 0.1000, 1),
                ('EU', 'Europe',         'EUR', '€',  0.2000, 1)");
        }
        // Idempotent guarantee: the store sells to 5 countries, each with its
        // OWN license-key pool (US, UK, Canada, Australia, Europe). Make sure
        // every one of those regions exists — INSERT IGNORE never clobbers an
        // admin's existing row (name/tax/currency edits are preserved).
        $pdo->exec("INSERT IGNORE INTO regions (code, name, currency, currency_symbol, tax_rate, active) VALUES
            ('US', 'United States',  'USD', '$',  0.0000, 1),
            ('UK', 'United Kingdom', 'GBP', '£',  0.2000, 1),
            ('CA', 'Canada',         'CAD', 'C$', 0.1300, 1),
            ('AU', 'Australia',      'AUD', 'A$', 0.1000, 1),
            ('EU', 'Europe',         'EUR', '€',  0.2000, 1)");
        // Australia + Europe are live sales segments for this store, so make
        // sure they're active on legacy installs (older seeds shipped EU
        // inactive). This is a ONE-TIME migration — after it runs once we
        // set `regions_au_eu_activated_v1=1` in settings so subsequent runs
        // skip it. Without this guard the migration would keep re-activating
        // AU/EU on every page load and override an admin who deliberately
        // deactivated a region from Admin → Regions.
        try {
            if (function_exists('setting_get') && function_exists('setting_set')) {
                if ((string)setting_get('regions_au_eu_activated_v1', '') !== '1') {
                    $pdo->exec("UPDATE regions SET active = 1 WHERE code IN ('AU','EU') AND active = 0");
                    setting_set('regions_au_eu_activated_v1', '1');
                }
            }
        } catch (Throwable $e) { /* setting helpers not yet loaded — safe to skip */ }

        // products / orders / license_keys / customer_reviews need a `region` column.
        // Detect via INFORMATION_SCHEMA so this works on MySQL 5.6 / 5.7 / 8 / MariaDB.
        $needs = [
            'products'     => "VARCHAR(8) NOT NULL DEFAULT 'US'",
            'orders'       => "VARCHAR(8) NOT NULL DEFAULT 'US'",
            'license_keys' => "VARCHAR(8) NOT NULL DEFAULT 'US'",
        ];
        foreach ($needs as $table => $colDef) {
            try {
                // Skip if the table itself doesn't exist on this host.
                $tableExists = $pdo->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
                );
                $tableExists->execute([$table]);
                if (!(int)$tableExists->fetchColumn()) continue;

                $colExists = $pdo->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'region'"
                );
                $colExists->execute([$table]);
                if (!(int)$colExists->fetchColumn()) {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `region` $colDef");
                }
            } catch (Throwable $e) { /* ignore — keep going */ }
        }

        // Stock-notification subscribers (back-in-stock alerts)
        $pdo->exec("CREATE TABLE IF NOT EXISTS stock_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_slug VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL,
            region VARCHAR(8) NOT NULL DEFAULT 'US',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            notified_at DATETIME NULL DEFAULT NULL,
            KEY idx_pending (product_slug, region, notified_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (Throwable $e) {
        // DB not reachable yet or insufficient privileges — silently skip,
        // the page will surface the underlying error normally.
    }
}
regions_bootstrap();

function active_region_code(): string {
    if (isset($_GET['region'])) {
        $r = strtoupper(preg_replace('/[^A-Z]/i', '', $_GET['region']));
        if ($r) {
            $_SESSION['region'] = $r;
            setting_set('active_region', $r);
        }
    }
    return $_SESSION['region'] ?? setting_get('active_region', 'US');
}

function active_region(): array {
    $code = active_region_code();
    $row = db()->prepare('SELECT * FROM regions WHERE code = ? AND active = 1');
    $row->execute([$code]);
    $r = $row->fetch();
    if ($r) return $r;
    // Session region was deactivated — fall back to first available active region
    $fb = db()->query('SELECT * FROM regions WHERE active = 1 ORDER BY code LIMIT 1')->fetch();
    if ($fb) {
        $_SESSION['region'] = $fb['code'];
        return $fb;
    }
    return ['code'=>'US','name'=>'United States','currency'=>'USD','currency_symbol'=>'$','tax_rate'=>0,'active'=>1];
}

function all_regions(): array {
    return db()->query('SELECT * FROM regions WHERE active=1 ORDER BY code')->fetchAll();
}

/** SQL snippet that limits a query to products belonging to currently-active regions.
 *  Use it inside any public-facing product query, e.g.
 *      SELECT * FROM products WHERE region IN (SELECT code FROM regions WHERE active=1)
 *  When no region is active (edge case), the helper returns a clause that yields 0 rows
 *  so deactivated regions never leak through.
 */
function active_regions_sql_in(string $column = 'region'): string {
    return "$column IN (SELECT code FROM regions WHERE active=1)";
}

function region_money(float $amount): string {
    $r = active_region();
    return $r['currency_symbol'] . number_format($amount, 2);
}

function region_filter_sql(string $alias = ''): string {
    $pre = $alias === '' ? '' : ($alias . '.');
    return $pre . "region = " . db()->quote(active_region_code());
}

/** Static FX map (USD base). For production wire to live FX API. */
function region_rates(): array {
    return ['US' => 1.00, 'UK' => 0.79, 'CA' => 1.37, 'AU' => 1.52, 'EU' => 0.92];
}

/** Convert a USD-stored price into the active region's currency value. */
function region_price(float $usd): float {
    $rates = region_rates();
    return $usd * ($rates[active_region_code()] ?? 1.0);
}

/** Format an originally-USD price into the active region's currency string. */
function region_money_from_usd(float $usd): string {
    return region_money(region_price($usd));
}

/**
 * The 5 country segments this store sells to. Each has its OWN separate
 * license-key inventory pool (a US key never fulfils a UK/AU/CA/EU order).
 */
function mv_sales_regions(): array {
    return ['US', 'UK', 'CA', 'AU', 'EU'];
}

/**
 * Normalise any country/region string to one of the 5 sales-region codes.
 * Accepts common aliases (GB→UK, USA→US, AUS→AU, CAN→CA) and falls back to
 * 'US' for anything unrecognised so a key is always pulled from a real pool.
 */
function mv_normalize_region(?string $code): string {
    $c = strtoupper(preg_replace('/[^A-Z]/i', '', (string)$code));
    $aliases = ['GB' => 'UK', 'GBR' => 'UK', 'USA' => 'US', 'CAN' => 'CA', 'AUS' => 'AU', 'EUR' => 'EU'];
    if (isset($aliases[$c])) $c = $aliases[$c];
    return in_array($c, mv_sales_regions(), true) ? $c : 'US';
}

/** Human label for a region code, e.g. 'AU' → 'Australia'. */
function mv_region_label(string $code): string {
    $labels = ['US' => 'United States', 'UK' => 'United Kingdom', 'CA' => 'Canada', 'AU' => 'Australia', 'EU' => 'Europe'];
    return $labels[mv_normalize_region($code)] ?? $code;
}


<?php
/**
 * Idempotent seed: refresh the four Device Protection Hub plans with the
 * canonical marketing copy + $29 / $59 / $99 / $199 one-time price points.
 *
 * Called from start.sh on every pod boot.  Safe to re-run — always overwrites
 * name/tagline/features/duration and only sets `price` when the admin has not
 * customised it (i.e. the stored price is 0.00 — meaning it's still the seed
 * default).  Live-set prices from the admin panel are never clobbered.
 */
require_once __DIR__ . '/../includes/functions.php';

$db = db();

$plans = [
  [
    'slug' => 'quick-fix',
    'name' => 'Quick Fix',
    'tagline' => 'One-time remote session · single-device rescue',
    'tenure_label' => 'One-Time Service',
    'devices' => '1 Device',
    'duration_months' => 0,
    'price' => 29.00,
    'icon_image' => '/assets/images/subscriptions/quick-fix.svg',
    'sort_order' => 10,
    'features' => [
      '1 remote setup session',
      'OS compatibility assessment',
      'Official Microsoft ISO verification',
      'Digital key entry support',
      'Basic success verification',
    ],
  ],
  [
    'slug' => 'starter-care',
    'name' => 'Starter Care',
    'tagline' => 'Priority chat support for a full year',
    'tenure_label' => '1 Year Plan',
    'devices' => '1 Device',
    'duration_months' => 12,
    'price' => 59.00,
    'icon_image' => '/assets/images/subscriptions/starter-care.svg',
    'sort_order' => 20,
    'features' => [
      '12 months priority live-chat support',
      'Unlimited key recovery assistance',
      'Microsoft account linking support',
      'Error code troubleshooting',
      '1 emergency registry fix',
      'Coverage for 1 standalone device',
    ],
  ],
  [
    'slug' => 'pro-shield',
    'name' => 'Pro Shield',
    'tagline' => 'VIP phone support · up to 3 devices · 3 years',
    'tenure_label' => '3 Year Plan',
    'devices' => 'Up to 3 Devices',
    'duration_months' => 36,
    'price' => 99.00,
    'icon_image' => '/assets/images/subscriptions/pro-shield.svg',
    'sort_order' => 30,
    'features' => [
      '36 months VIP phone support',
      'Hardware-to-hardware key transfer help',
      'Bloatware removal & system tuning',
      'Windows Update repair assistance',
      '1 scheduled malware health scan',
      'Priority support queue routing',
      'Coverage for up to 3 devices',
    ],
  ],
  [
    'slug' => 'lifetime-elite',
    'name' => 'Lifetime Elite',
    'tagline' => 'Dedicated tier-3 specialist · 10 years · unlimited devices',
    'tenure_label' => 'Long-Term Plan',
    'devices' => 'Unlimited Devices',
    'duration_months' => 120,
    'price' => 199.00,
    'icon_image' => '/assets/images/subscriptions/lifetime-elite.svg',
    'sort_order' => 40,
    'features' => [
      '10 years dedicated tier-3 support',
      'Unlimited license transfer assistance',
      'Complete annual system tune-up',
      'Deep malware & virus removal',
      'Office configuration custom optimization',
      'Guaranteed 15-minute response window',
      'Dedicated personal support specialist',
      'Coverage for unlimited personal devices',
    ],
  ],
];

foreach ($plans as $p) {
    $features_json = json_encode($p['features'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Look up the current stored price — if it's 0.00 (never set OR previous
    // compliance zero-out), we set our default; otherwise we leave admin's
    // custom price alone.
    $existing = $db->prepare('SELECT price FROM subscription_plans WHERE slug = ?');
    $existing->execute([$p['slug']]);
    $currentPrice = $existing->fetchColumn();

    $newPrice = ($currentPrice === false || (float)$currentPrice == 0.0) ? $p['price'] : (float)$currentPrice;

    if ($currentPrice === false) {
        // Insert new row
        $ins = $db->prepare(
            'INSERT INTO subscription_plans (slug, name, tagline, tenure_label, devices, duration_months, price, features_json, sort_order, active, icon_image) '
          . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
        );
        $ins->execute([
            $p['slug'], $p['name'], $p['tagline'], $p['tenure_label'], $p['devices'],
            $p['duration_months'], $newPrice, $features_json, $p['sort_order'], $p['icon_image'],
        ]);
        echo "[protection-hub] INSERT {$p['slug']} @ \${$newPrice}\n";
    } else {
        // Refresh content (name/tagline/features/duration/sort/icon) + price only if
        // still the seed default.
        $upd = $db->prepare(
            'UPDATE subscription_plans '
          . 'SET name = ?, tagline = ?, tenure_label = ?, devices = ?, duration_months = ?, '
          . '    price = ?, features_json = ?, sort_order = ?, active = 1, icon_image = ? '
          . 'WHERE slug = ?'
        );
        $upd->execute([
            $p['name'], $p['tagline'], $p['tenure_label'], $p['devices'],
            $p['duration_months'], $newPrice, $features_json, $p['sort_order'], $p['icon_image'],
            $p['slug'],
        ]);
        echo "[protection-hub] UPDATE {$p['slug']} @ \${$newPrice}" . ($currentPrice != $newPrice ? " (was \${$currentPrice})" : '') . "\n";
    }
}

echo "[protection-hub] Done.\n";

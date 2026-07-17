<?php
/**
 * STEP 1: PayPal payload shape verification
 * Tests pp_build_units_from_items() against the exact JSON spec provided by user
 */

require_once '/app/php-version/includes/gateways/paypal-api.php';

$results = [];
$passed = 0;
$failed = 0;

function assert_equal($actual, $expected, $label) {
    global $results, $passed, $failed;
    $pass = ($actual === $expected);
    if ($pass) {
        $passed++;
        $results[] = "✅ PASS: $label";
    } else {
        $failed++;
        $results[] = "❌ FAIL: $label\n   Expected: " . var_export($expected, true) . "\n   Got: " . var_export($actual, true);
    }
    return $pass;
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "STEP 1: PayPal Payload Shape Verification\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// TEST A: Single item $1.00 cart (matches user's exact spec)
echo "TEST A: Single item \$1.00 cart\n";
echo "─────────────────────────────────────────────────────────────\n";

$items = [
    [
        'name' => 'Your Product Name Here',
        'description' => 'Optional product description',
        'price' => 1.00,
        'qty' => 1,
        'sku' => 'PROD-001',
        'slug' => 'your-product'
    ]
];

$unit = pp_build_units_from_items($items, 1.00, 0.00, 1.00, 'USD', 'MV123', 'Order MV123');

// Verify structure matches user's exact spec
assert_equal($unit['amount']['currency_code'], 'USD', 'amount.currency_code == USD');
assert_equal($unit['amount']['value'], '1.00', 'amount.value == "1.00" (string)');
assert_equal($unit['amount']['breakdown']['item_total']['currency_code'], 'USD', 'amount.breakdown.item_total.currency_code == USD');
assert_equal($unit['amount']['breakdown']['item_total']['value'], '1.00', 'amount.breakdown.item_total.value == "1.00" (string)');

// Verify NO discount breakdown when discount = 0
$hasDiscount = isset($unit['amount']['breakdown']['discount']);
assert_equal($hasDiscount, false, 'NO discount breakdown when discount = 0');

// Verify items array
assert_equal(is_array($unit['items']), true, 'items is an ARRAY');
assert_equal(count($unit['items']), 1, 'items array length == 1');

// Verify item[0] structure matches user's spec EXACTLY
assert_equal($unit['items'][0]['name'], 'Your Product Name Here', 'items[0].name == "Your Product Name Here"');
assert_equal($unit['items'][0]['description'], 'Optional product description', 'items[0].description == "Optional product description"');
assert_equal($unit['items'][0]['quantity'], '1', 'items[0].quantity == "1" (STRING not int)');
assert_equal($unit['items'][0]['unit_amount']['currency_code'], 'USD', 'items[0].unit_amount.currency_code == USD');
assert_equal($unit['items'][0]['unit_amount']['value'], '1.00', 'items[0].unit_amount.value == "1.00"');

echo "\n";

// TEST B: 2-item cart with $10 discount
echo "TEST B: 2-item cart with \$10 discount\n";
echo "─────────────────────────────────────────────────────────────\n";

$items2 = [
    [
        'name' => 'Microsoft Office 2024 Professional Plus',
        'description' => 'Lifetime license for Windows',
        'price' => 209.99,
        'qty' => 1,
        'sku' => 'MS-OFF-2024-PRO',
        'slug' => 'microsoft-office-2024-professional-plus-windows'
    ],
    [
        'name' => 'Windows 11 Home',
        'description' => 'Digital license key',
        'price' => 119.99,
        'qty' => 2,
        'sku' => 'WIN-11-HOME',
        'slug' => 'windows-11-home'
    ]
];

$subtotal2 = 209.99 + (119.99 * 2); // 449.97
$discount2 = 10.00;
$total2 = $subtotal2 - $discount2; // 439.97

$unit2 = pp_build_units_from_items($items2, $subtotal2, $discount2, $total2, 'USD', 'MV456', 'Order MV456');

// Verify items present
assert_equal(count($unit2['items']), 2, 'items array has 2 items');
assert_equal($unit2['items'][0]['name'], 'Microsoft Office 2024 Professional Plus', 'items[0].name correct');
assert_equal($unit2['items'][0]['description'], 'Lifetime license for Windows', 'items[0].description correct');
assert_equal($unit2['items'][1]['name'], 'Windows 11 Home', 'items[1].name correct');
assert_equal($unit2['items'][1]['quantity'], '2', 'items[1].quantity == "2" (string)');

// Verify breakdown
assert_equal($unit2['amount']['breakdown']['item_total']['value'], '449.97', 'amount.breakdown.item_total.value == subtotal');
assert_equal($unit2['amount']['breakdown']['discount']['value'], '10.00', 'amount.breakdown.discount.value == "10.00"');

// Verify final amount = item_total - discount
$finalAmount = (float)$unit2['amount']['value'];
$itemTotal = (float)$unit2['amount']['breakdown']['item_total']['value'];
$discountAmt = (float)$unit2['amount']['breakdown']['discount']['value'];
$expectedFinal = round($itemTotal - $discountAmt, 2);
assert_equal($finalAmount, $expectedFinal, 'amount.value == item_total - discount (reconciled)');

// Verify all amounts are strings with 2 decimals
assert_equal(is_string($unit2['amount']['value']), true, 'amount.value is STRING');
assert_equal(preg_match('/^\d+\.\d{2}$/', $unit2['amount']['value']), 1, 'amount.value has exactly 2 decimals');
assert_equal(preg_match('/^\d+\.\d{2}$/', $unit2['items'][0]['unit_amount']['value']), 1, 'items[0].unit_amount.value has 2 decimals');

echo "\n";

// TEST C: Reconciliation test (rounding drift)
echo "TEST C: Reconciliation test (rounding causes drift)\n";
echo "─────────────────────────────────────────────────────────────\n";

$items3 = [
    [
        'name' => 'Test Product',
        'description' => 'Test',
        'price' => 210.00,
        'qty' => 1,
        'sku' => 'TEST-001',
        'slug' => 'test-product'
    ]
];

// Simulate rounding drift: items sum to $210.00 but total is $209.99
$unit3 = pp_build_units_from_items($items3, 210.00, 0.00, 209.99, 'USD', 'MV789', 'Order MV789');

// The helper should rebase discount to $0.01 so item_total - discount = amount.value
$itemTotal3 = (float)$unit3['amount']['breakdown']['item_total']['value'];
$discount3 = isset($unit3['amount']['breakdown']['discount']) ? (float)$unit3['amount']['breakdown']['discount']['value'] : 0.0;
$finalAmount3 = (float)$unit3['amount']['value'];

assert_equal($itemTotal3, 210.00, 'item_total == 210.00');
assert_equal($discount3, 0.01, 'discount rebased to 0.01 to reconcile');
assert_equal($finalAmount3, 209.99, 'amount.value == 209.99');
assert_equal(round($itemTotal3 - $discount3, 2), $finalAmount3, 'item_total - discount == amount.value (PayPal requirement)');

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "STEP 1 RESULTS: $passed passed, $failed failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

foreach ($results as $r) {
    echo $r . "\n";
}

exit($failed > 0 ? 1 : 0);

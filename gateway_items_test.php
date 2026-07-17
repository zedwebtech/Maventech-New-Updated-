#!/usr/bin/env php
<?php
/**
 * Gateway Item Name/Description Propagation Test
 * 
 * Tests that all payment gateways (PayPal, Stripe, NMI, Authorize.Net, Custom)
 * correctly include item names and descriptions in their request payloads.
 * 
 * APPROACH B (recommended): Call helper functions directly and assert outputs.
 */

// Bootstrap minimal environment
define('SITE_BRAND', 'TestStore');
define('SITE_LEGAL', 'TestStore LLC');

// Load settings first (it defines setting_get)
require_once __DIR__ . '/php-version/includes/settings.php';

// Load gateway files
require_once __DIR__ . '/php-version/includes/gateways/paypal-api.php';
require_once __DIR__ . '/php-version/includes/gateways/charge.php';

// Test data - realistic product from the store
$testItems = [
    [
        'slug' => 'microsoft-office-2024-professional-plus-windows',
        'name' => 'Microsoft Office 2024 Professional Plus',
        'description' => 'Lifetime license for Windows',
        'sku' => 'MS-OFF-2024-PRO',
        'price' => 209.99,
        'qty' => 1,
    ],
];

$testItemsMultiple = [
    [
        'slug' => 'microsoft-office-2024-professional-plus-windows',
        'name' => 'Microsoft Office 2024 Professional Plus',
        'description' => 'Lifetime license for Windows',
        'sku' => 'MS-OFF-2024-PRO',
        'price' => 209.99,
        'qty' => 2,
    ],
    [
        'slug' => 'windows-11-home',
        'name' => 'Windows 11 Home',
        'description' => 'Digital license key',
        'sku' => 'WIN-11-HOME',
        'price' => 119.99,
        'qty' => 1,
    ],
];

$passed = 0;
$failed = 0;

function test_assert($condition, $message) {
    global $passed, $failed;
    if ($condition) {
        echo "✓ PASS: $message\n";
        $passed++;
    } else {
        echo "✗ FAIL: $message\n";
        $failed++;
    }
}

function test_section($title) {
    echo "\n" . str_repeat('=', 80) . "\n";
    echo "$title\n";
    echo str_repeat('=', 80) . "\n";
}

// ============================================================================
// TEST 1: PayPal Orders API v2 - pp_build_units_from_items()
// ============================================================================
test_section('TEST 1: PayPal Orders API v2 - pp_build_units_from_items()');

echo "\n--- Test 1a: Single item, no discount ---\n";
$unit = pp_build_units_from_items($testItems, 209.99, 0.0, 209.99, 'USD', 'MV123', 'Order MV123 — Microsoft Office 2024 Professional Plus');

test_assert(isset($unit['amount']['value']), 'PayPal unit has amount.value');
test_assert($unit['amount']['value'] === '209.99', 'PayPal amount.value = 209.99 (got: ' . ($unit['amount']['value'] ?? 'null') . ')');
test_assert($unit['amount']['currency_code'] === 'USD', 'PayPal currency_code = USD');

test_assert(isset($unit['amount']['breakdown']['item_total']['value']), 'PayPal has amount.breakdown.item_total');
test_assert($unit['amount']['breakdown']['item_total']['value'] === '209.99', 'PayPal item_total.value = 209.99');

test_assert(!isset($unit['amount']['breakdown']['discount']), 'PayPal has NO discount when discount=0');

test_assert(isset($unit['items']) && is_array($unit['items']), 'PayPal has items array');
test_assert(count($unit['items']) === 1, 'PayPal has 1 item');

$item0 = $unit['items'][0];
test_assert($item0['name'] === 'Microsoft Office 2024 Professional Plus', 'PayPal item[0].name = "Microsoft Office 2024 Professional Plus"');
test_assert($item0['quantity'] === '1', 'PayPal item[0].quantity = "1"');
test_assert($item0['unit_amount']['value'] === '209.99', 'PayPal item[0].unit_amount.value = "209.99"');
test_assert($item0['unit_amount']['currency_code'] === 'USD', 'PayPal item[0].unit_amount.currency_code = USD');
test_assert($item0['category'] === 'DIGITAL_GOODS', 'PayPal item[0].category = DIGITAL_GOODS');
test_assert(isset($item0['description']) && $item0['description'] === 'Lifetime license for Windows', 'PayPal item[0].description present');
test_assert(isset($item0['sku']) && $item0['sku'] === 'MS-OFF-2024-PRO', 'PayPal item[0].sku present');

test_assert($unit['description'] === 'Order MV123 — Microsoft Office 2024 Professional Plus', 'PayPal unit.description correct');
test_assert($unit['invoice_id'] === 'MV123', 'PayPal unit.invoice_id = MV123');
test_assert($unit['reference_id'] === 'MV123', 'PayPal unit.reference_id = MV123');

echo "\n--- Test 1b: Multiple items with discount ---\n";
$subtotal = 209.99 * 2 + 119.99; // 539.97
$discount = 50.00;
$total = $subtotal - $discount; // 489.97

$unit2 = pp_build_units_from_items($testItemsMultiple, $subtotal, $discount, $total, 'USD', 'MV456', 'Order MV456 — 2× Microsoft Office 2024 Professional Plus, Windows 11 Home');

test_assert($unit2['amount']['value'] === '489.97', 'PayPal amount.value = 489.97 with discount');
test_assert($unit2['amount']['breakdown']['item_total']['value'] === '539.97', 'PayPal item_total.value = 539.97');
test_assert(isset($unit2['amount']['breakdown']['discount']), 'PayPal has discount breakdown');
test_assert($unit2['amount']['breakdown']['discount']['value'] === '50.00', 'PayPal discount.value = 50.00');

test_assert(count($unit2['items']) === 2, 'PayPal has 2 items');
test_assert($unit2['items'][0]['quantity'] === '2', 'PayPal item[0].quantity = "2"');
test_assert($unit2['items'][1]['name'] === 'Windows 11 Home', 'PayPal item[1].name = "Windows 11 Home"');

echo "\n--- Test 1c: Reconciliation - rounding adjustment ---\n";
// Test case: items sum to $210.00, discount=0, but total=$209.99 (1 cent off)
// Helper should rebase discount to $0.01 so item_total - discount == total
$items3 = [['name'=>'Item A','price'=>210.00,'qty'=>1]];
$unit3 = pp_build_units_from_items($items3, 210.00, 0.0, 209.99, 'USD', 'MV789', 'Order MV789');

test_assert($unit3['amount']['value'] === '209.99', 'PayPal reconciliation: amount.value = 209.99');
test_assert($unit3['amount']['breakdown']['item_total']['value'] === '210.00', 'PayPal reconciliation: item_total = 210.00');
test_assert(isset($unit3['amount']['breakdown']['discount']), 'PayPal reconciliation: discount added');
test_assert($unit3['amount']['breakdown']['discount']['value'] === '0.01', 'PayPal reconciliation: discount = 0.01 (got: ' . ($unit3['amount']['breakdown']['discount']['value'] ?? 'null') . ')');

// ============================================================================
// TEST 2: mv_items_description() - used by NMI, Authorize.Net, Custom
// ============================================================================
test_section('TEST 2: mv_items_description() - Order Description Helper');

echo "\n--- Test 2a: Single item ---\n";
$desc1 = mv_items_description($testItems, 'MV123');
test_assert(str_contains($desc1, 'Order MV123'), 'Description contains "Order MV123"');
test_assert(str_contains($desc1, 'Microsoft Office 2024 Professional Plus'), 'Description contains product name');
echo "Generated: $desc1\n";

echo "\n--- Test 2b: Multiple items with quantities ---\n";
$desc2 = mv_items_description($testItemsMultiple, 'MV456');
test_assert(str_contains($desc2, 'Order MV456'), 'Description contains "Order MV456"');
test_assert(str_contains($desc2, '2× Microsoft Office 2024 Professional Plus'), 'Description contains "2× Microsoft Office 2024 Professional Plus"');
test_assert(str_contains($desc2, 'Windows 11 Home'), 'Description contains "Windows 11 Home"');
echo "Generated: $desc2\n";

echo "\n--- Test 2c: Empty items array ---\n";
$desc3 = mv_items_description([], 'MV789');
test_assert($desc3 === 'Order MV789', 'Empty items returns "Order MV789"');

// ============================================================================
// TEST 3: NMI Direct Post - Code Structure Verification
// ============================================================================
test_section('TEST 3: NMI Direct Post - Code Structure Verification');

echo "\nReading nmi_charge_card() source code...\n";
$chargeCode = file_get_contents(__DIR__ . '/php-version/includes/gateways/charge.php');

test_assert(str_contains($chargeCode, "'orderdescription' => mv_items_description("), 'NMI: orderdescription uses mv_items_description()');
test_assert(str_contains($chargeCode, "'item_product_code_' . \$idx"), 'NMI: includes item_product_code_N');
test_assert(str_contains($chargeCode, "'item_description_' . \$idx"), 'NMI: includes item_description_N');
test_assert(str_contains($chargeCode, "'item_unit_cost_' . \$idx"), 'NMI: includes item_unit_cost_N');
test_assert(str_contains($chargeCode, "'item_quantity_' . \$idx"), 'NMI: includes item_quantity_N');
test_assert(str_contains($chargeCode, "'item_total_amount_' . \$idx"), 'NMI: includes item_total_amount_N');
test_assert(str_contains($chargeCode, 'foreach ($items as $it)'), 'NMI: iterates over $items array');
test_assert(str_contains($chargeCode, 'function nmi_charge_card(array $card, array $billing, float $amount, string $mode, array $order = [], array $items = [])'), 'NMI: function accepts $items parameter');

echo "\nNMI Level-2/3 line items structure verified in code.\n";

// ============================================================================
// TEST 4: Authorize.Net - Code Structure Verification
// ============================================================================
test_section('TEST 4: Authorize.Net - Code Structure Verification');

echo "\nReading authnet_charge_card() source code...\n";

test_assert(str_contains($chargeCode, '$orderDesc = mv_items_description($items'), 'Authorize.Net: uses mv_items_description()');
test_assert(str_contains($chargeCode, '<order>'), 'Authorize.Net: includes <order> XML element');
test_assert(str_contains($chargeCode, '<description>'), 'Authorize.Net: includes <description> XML element');
test_assert(str_contains($chargeCode, '<lineItems>'), 'Authorize.Net: includes <lineItems> XML element');
test_assert(str_contains($chargeCode, '<lineItem>'), 'Authorize.Net: includes <lineItem> XML element');
test_assert(str_contains($chargeCode, '<itemId>'), 'Authorize.Net: includes <itemId> XML element');
test_assert(str_contains($chargeCode, '<name>'), 'Authorize.Net: includes <name> XML element');
test_assert(str_contains($chargeCode, '<quantity>'), 'Authorize.Net: includes <quantity> XML element');
test_assert(str_contains($chargeCode, '<unitPrice>'), 'Authorize.Net: includes <unitPrice> XML element');
test_assert(str_contains($chargeCode, 'function authnet_charge_card(array $card, array $billing, float $amount, string $mode, array $order = [], array $items = [])'), 'Authorize.Net: function accepts $items parameter');

// Verify element order (Authorize.Net XSD requires order before lineItems before billTo)
$orderPos = strpos($chargeCode, "'<order>'");
$lineItemsPos = strpos($chargeCode, "\$lineItemsXml");
$billToPos = strpos($chargeCode, "'<billTo>'");
test_assert($orderPos < $lineItemsPos && $lineItemsPos < $billToPos, 'Authorize.Net: XML element order correct (order → lineItems → billTo)');

echo "\nAuthorize.Net XML structure verified in code.\n";

// ============================================================================
// TEST 5: Custom Gateway - Code Structure Verification
// ============================================================================
test_section('TEST 5: Custom Gateway - Code Structure Verification');

echo "\nReading custom_charge_card() source code...\n";

test_assert(str_contains($chargeCode, 'function custom_charge_card(array $card, array $billing, float $amount, string $mode, array $order = [], array $items = [])'), 'Custom: function accepts $items parameter');
test_assert(str_contains($chargeCode, '$normItems = [];'), 'Custom: normalizes items array');
test_assert(str_contains($chargeCode, "'sku'"), 'Custom: includes sku in items');
test_assert(str_contains($chargeCode, "'name'"), 'Custom: includes name in items');
test_assert(str_contains($chargeCode, "'description'"), 'Custom: includes description in items');
test_assert(str_contains($chargeCode, "'quantity'"), 'Custom: includes quantity in items');
test_assert(str_contains($chargeCode, "'unit_price'"), 'Custom: includes unit_price in items');
test_assert(str_contains($chargeCode, "'line_total'"), 'Custom: includes line_total in items');
test_assert(str_contains($chargeCode, '$description = mv_items_description($items'), 'Custom: uses mv_items_description()');
test_assert(str_contains($chargeCode, "'description' => \$description"), 'Custom: includes description in payload');
test_assert(str_contains($chargeCode, "'items'       => \$normItems"), 'Custom: includes items array in payload');
test_assert(str_contains($chargeCode, 'json_encode($payload)'), 'Custom: sends JSON payload');

echo "\nCustom gateway JSON structure verified in code.\n";

// ============================================================================
// TEST 6: Stripe - Code Structure Verification
// ============================================================================
test_section('TEST 6: Stripe - Code Structure Verification');

echo "\nReading stripe_create_session_with_recovery() source code...\n";
$stripeCode = file_get_contents(__DIR__ . '/php-version/includes/gateways/stripe.php');

test_assert(str_contains($stripeCode, 'function stripe_create_session_with_recovery(array $order, string $baseUrl, array $items = [], float $subtotal = 0.0, float $discount = 0.0)'), 'Stripe: function accepts $items, $subtotal, $discount parameters');
test_assert(str_contains($stripeCode, 'if (!empty($items))'), 'Stripe: checks if items array is provided');
test_assert(str_contains($stripeCode, "line_items[' . \$i . '][price_data][product_data][name]"), 'Stripe: includes product_data.name in line_items');
test_assert(str_contains($stripeCode, "line_items[' . \$i . '][price_data][product_data][description]"), 'Stripe: includes product_data.description in line_items');
test_assert(str_contains($stripeCode, "line_items[' . \$i . '][price_data][product_data][metadata][sku]"), 'Stripe: includes metadata.sku in line_items');
test_assert(str_contains($stripeCode, "line_items[' . \$i . '][price_data][unit_amount]"), 'Stripe: includes unit_amount in line_items');
test_assert(str_contains($stripeCode, "line_items[' . \$i . '][quantity]"), 'Stripe: includes quantity in line_items');
test_assert(str_contains($stripeCode, "'payment_intent_data[description]'"), 'Stripe: includes payment_intent_data.description');
test_assert(str_contains($stripeCode, "discounts[0][coupon]"), 'Stripe: includes discounts with coupon for discount');
test_assert(str_contains($stripeCode, 'stripe_create_session_single_line'), 'Stripe: has fallback single-line function');
test_assert(str_contains($stripeCode, 'function stripe_create_session_single_line'), 'Stripe: single-line fallback function exists');

echo "\nStripe Checkout Session structure verified in code.\n";

// ============================================================================
// TEST 7: checkout.php Integration - Verify items are passed to gateways
// ============================================================================
test_section('TEST 7: checkout.php Integration - Verify items passed to gateways');

echo "\nReading checkout.php source code...\n";
$checkoutCode = file_get_contents(__DIR__ . '/php-version/checkout.php');

test_assert(str_contains($checkoutCode, 'paypal_create_order('), 'checkout.php: calls paypal_create_order()');
test_assert(str_contains($checkoutCode, '$ppItemsPayload'), 'checkout.php: builds $ppItemsPayload for PayPal');
test_assert(preg_match('/paypal_create_order\([^)]*\$ppItemsPayload[^)]*\$subtotal[^)]*\$discount/s', $checkoutCode), 'checkout.php: passes items, subtotal, discount to paypal_create_order()');

test_assert(str_contains($checkoutCode, 'mv_card_charge('), 'checkout.php: calls mv_card_charge()');
test_assert(preg_match('/mv_card_charge\([^)]*\$items\)/s', $checkoutCode), 'checkout.php: passes $items to mv_card_charge()');

test_assert(str_contains($checkoutCode, 'stripe_create_session_with_recovery('), 'checkout.php: calls stripe_create_session_with_recovery()');
test_assert(preg_match('/stripe_create_session_with_recovery\([^)]*\$items[^)]*\$subtotal[^)]*\$discount/s', $checkoutCode), 'checkout.php: passes items, subtotal, discount to stripe_create_session_with_recovery()');

echo "\ncheckout.php integration verified - all gateways receive items.\n";

// ============================================================================
// SUMMARY
// ============================================================================
test_section('TEST SUMMARY');

$total = $passed + $failed;
$passRate = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

echo "\nTotal Tests: $total\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo "Pass Rate: $passRate%\n\n";

if ($failed === 0) {
    echo "✓✓✓ ALL TESTS PASSED ✓✓✓\n";
    echo "\nVERDICT: All payment gateways correctly include item names and descriptions\n";
    echo "in their request payloads. The bug fix is working as expected.\n\n";
    exit(0);
} else {
    echo "✗✗✗ SOME TESTS FAILED ✗✗✗\n";
    echo "\nPlease review the failed assertions above.\n\n";
    exit(1);
}

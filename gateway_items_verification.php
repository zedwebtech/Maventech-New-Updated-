<?php
/**
 * STEP 2: Stripe, NMI, Authorize.Net, Custom gateway item verification
 * Verifies each gateway includes item name, description, quantity, unit price
 */

require_once '/app/php-version/includes/gateways/stripe.php';
require_once '/app/php-version/includes/gateways/charge.php';

$results = [];
$passed = 0;
$failed = 0;

function assert_contains($haystack, $needle, $label) {
    global $results, $passed, $failed;
    $pass = (strpos($haystack, $needle) !== false);
    if ($pass) {
        $passed++;
        $results[] = "✅ PASS: $label";
    } else {
        $failed++;
        $results[] = "❌ FAIL: $label\n   Expected to find: $needle\n   In: " . substr($haystack, 0, 200) . "...";
    }
    return $pass;
}

function assert_true($condition, $label) {
    global $results, $passed, $failed;
    if ($condition) {
        $passed++;
        $results[] = "✅ PASS: $label";
    } else {
        $failed++;
        $results[] = "❌ FAIL: $label";
    }
    return $condition;
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "STEP 2: Gateway Item Data Verification\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Test items (2-item cart)
$testItems = [
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

// ═══════════════════════════════════════════════════════════════
// STRIPE: Verify line_items structure
// ═══════════════════════════════════════════════════════════════
echo "STRIPE: Verify line_items[] structure\n";
echo "─────────────────────────────────────────────────────────────\n";

// Read stripe.php source to verify the key format strings exist
$stripeSource = file_get_contents('/app/php-version/includes/gateways/stripe.php');

assert_contains($stripeSource, 'line_items[', 'Stripe builds line_items[] array');
assert_contains($stripeSource, '[price_data][currency]', 'Stripe includes currency');
assert_contains($stripeSource, '[price_data][product_data][name]', 'Stripe includes product name');
assert_contains($stripeSource, '[price_data][product_data][description]', 'Stripe includes product description');
assert_contains($stripeSource, '[price_data][unit_amount]', 'Stripe includes unit_amount');
assert_contains($stripeSource, '[quantity]', 'Stripe includes quantity');
assert_contains($stripeSource, 'payment_intent_data[description]', 'Stripe includes payment_intent description');
assert_contains($stripeSource, 'discounts[0][coupon]', 'Stripe supports discount via coupon');

// Verify function signature accepts items
assert_contains($stripeSource, 'function stripe_create_session_with_recovery(array $order, string $baseUrl, array $items = []', 'stripe_create_session_with_recovery accepts $items parameter');

echo "\n";

// ═══════════════════════════════════════════════════════════════
// NMI: Verify Level-2/3 line items
// ═══════════════════════════════════════════════════════════════
echo "NMI: Verify Level-2/3 line items\n";
echo "─────────────────────────────────────────────────────────────\n";

$chargeSource = file_get_contents('/app/php-version/includes/gateways/charge.php');

assert_contains($chargeSource, 'function nmi_charge_card(array $card, array $billing, float $amount, string $mode, array $order = [], array $items = [])', 'nmi_charge_card accepts $items parameter');
assert_contains($chargeSource, 'orderdescription', 'NMI includes orderdescription field');
assert_contains($chargeSource, 'item_product_code_', 'NMI includes item_product_code_N');
assert_contains($chargeSource, 'item_description_', 'NMI includes item_description_N');
assert_contains($chargeSource, 'item_unit_cost_', 'NMI includes item_unit_cost_N');
assert_contains($chargeSource, 'item_quantity_', 'NMI includes item_quantity_N');
assert_contains($chargeSource, 'item_total_amount_', 'NMI includes item_total_amount_N');
assert_contains($chargeSource, 'mv_items_description', 'NMI uses mv_items_description helper');

// Test mv_items_description helper
$desc1 = mv_items_description($testItems, 'MV123', 240);
assert_contains($desc1, 'Order MV123', 'mv_items_description includes order number');
assert_contains($desc1, 'Microsoft Office 2024 Professional Plus', 'mv_items_description includes product name');
assert_contains($desc1, '2×', 'mv_items_description includes quantity prefix for qty > 1');

echo "\n";

// ═══════════════════════════════════════════════════════════════
// AUTHORIZE.NET: Verify XML lineItems
// ═══════════════════════════════════════════════════════════════
echo "AUTHORIZE.NET: Verify XML lineItems\n";
echo "─────────────────────────────────────────────────────────────\n";

assert_contains($chargeSource, 'function authnet_charge_card(array $card, array $billing, float $amount, string $mode, array $order = [], array $items = [])', 'authnet_charge_card accepts $items parameter');
assert_contains($chargeSource, '<lineItems>', 'Authorize.Net builds <lineItems> XML');
assert_contains($chargeSource, '<lineItem>', 'Authorize.Net builds <lineItem> elements');
assert_contains($chargeSource, '<itemId>', 'Authorize.Net includes <itemId> (SKU)');
assert_contains($chargeSource, '<name>', 'Authorize.Net includes <name>');
assert_contains($chargeSource, '<description>', 'Authorize.Net includes <description>');
assert_contains($chargeSource, '<quantity>', 'Authorize.Net includes <quantity>');
assert_contains($chargeSource, '<unitPrice>', 'Authorize.Net includes <unitPrice>');
assert_contains($chargeSource, '<order>', 'Authorize.Net includes <order> element');

// Verify XML element order (order → lineItems → billTo per Authorize.Net XSD)
$orderPos = strpos($chargeSource, '. \'<order>\'');
$lineItemsPos = strpos($chargeSource, '. $lineItemsXml');
$billToPos = strpos($chargeSource, '. \'<billTo>\'');
assert_true($orderPos < $lineItemsPos && $lineItemsPos < $billToPos, 'XML element order correct: <order> → lineItems → <billTo>');

echo "\n";

// ═══════════════════════════════════════════════════════════════
// CUSTOM: Verify JSON items[] array
// ═══════════════════════════════════════════════════════════════
echo "CUSTOM: Verify JSON items[] array\n";
echo "─────────────────────────────────────────────────────────────\n";

assert_contains($chargeSource, 'function custom_charge_card(array $card, array $billing, float $amount, string $mode, array $order = [], array $items = [])', 'custom_charge_card accepts $items parameter');
assert_contains($chargeSource, '\'items\'       => $normItems', 'Custom gateway includes items[] in JSON payload');
assert_contains($chargeSource, '\'sku\'', 'Custom items include sku');
assert_contains($chargeSource, '\'name\'', 'Custom items include name');
assert_contains($chargeSource, '\'description\'', 'Custom items include description');
assert_contains($chargeSource, '\'quantity\'', 'Custom items include quantity');
assert_contains($chargeSource, '\'unit_price\'', 'Custom items include unit_price');
assert_contains($chargeSource, '\'line_total\'', 'Custom items include line_total');
assert_contains($chargeSource, '\'description\' => $description', 'Custom includes order-level description');

echo "\n";

// ═══════════════════════════════════════════════════════════════
// CHECKOUT.PHP: Verify integration
// ═══════════════════════════════════════════════════════════════
echo "CHECKOUT.PHP: Verify integration\n";
echo "─────────────────────────────────────────────────────────────\n";

$checkoutSource = file_get_contents('/app/php-version/checkout.php');

// PayPal integration
assert_contains($checkoutSource, 'paypal_create_order(', 'checkout.php calls paypal_create_order');
assert_contains($checkoutSource, '$ppItemsPayload', 'checkout.php builds $ppItemsPayload for PayPal');
assert_contains($checkoutSource, '(float)$subtotal', 'checkout.php passes subtotal to PayPal');
assert_contains($checkoutSource, '(float)$discount', 'checkout.php passes discount to PayPal');

// Stripe integration
assert_contains($checkoutSource, 'stripe_create_session_with_recovery($orderRow, $baseUrl, $items', 'checkout.php passes $items to Stripe');

// Card gateway integration (NMI/Authnet/Custom)
assert_contains($checkoutSource, 'mv_card_charge($cardProvider, $orderRow,', 'checkout.php calls mv_card_charge');
assert_contains($checkoutSource, ', $items)', 'checkout.php passes $items to mv_card_charge');

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "STEP 2 RESULTS: $passed passed, $failed failed\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

foreach ($results as $r) {
    echo $r . "\n";
}

exit($failed > 0 ? 1 : 0);

<?php
// Test harness — generate receipt + invoice PDFs for a real order + report page counts.
require_once __DIR__ . '/../php-version/config.php';
require_once __DIR__ . '/../php-version/includes/db.php';
require_once __DIR__ . '/../php-version/includes/functions.php';
require_once __DIR__ . '/../php-version/includes/settings.php';
require_once __DIR__ . '/../php-version/includes/pdf.php';

$pdo = db();
$orderNumber = $argv[1] ?? 'MVT-DEMO-002';
$order = $pdo->prepare("SELECT * FROM orders WHERE order_number=?");
$order->execute([$orderNumber]);
$order = $order->fetch(PDO::FETCH_ASSOC);
if (!$order) { fwrite(STDERR, "No such order: $orderNumber\n"); exit(1); }

$s = $pdo->prepare("SELECT * FROM order_items WHERE order_id=?");
$s->execute([$order['id']]);
$items = $s->fetchAll(PDO::FETCH_ASSOC);

$pdfItems = [];
foreach ($items as $it) {
    $pdfItems[] = array_merge($it, [
        'unit_price' => $it['price']       ?? $it['unit_price'] ?? 0,
        'quantity'   => $it['qty']         ?? $it['quantity']   ?? 1,
        'name'       => $it['product_name']?? $it['name'] ?? 'Product',
    ]);
}

$rcpt = generate_receipt_pdf($order, $pdfItems);
$inv  = generate_invoice_pdf($order, $pdfItems);
file_put_contents('/tmp/test_receipt.pdf', $rcpt);
file_put_contents('/tmp/test_invoice.pdf', $inv);

function count_pages($bin) {
    // Count /Type /Page objects, but exclude /Pages (the container).
    $n = preg_match_all('#/Type\s*/Page(?!s)#', $bin, $m);
    return $n;
}
echo "Order: {$order['order_number']} · items: " . count($pdfItems) . "\n";
echo "Receipt PDF: " . strlen($rcpt) . " bytes · pages=" . count_pages($rcpt) . "\n";
echo "Invoice PDF: " . strlen($inv)  . " bytes · pages=" . count_pages($inv)  . "\n";

<?php
// Stress test — vary the number of line items to make sure both PDFs
// stay on ONE page for typical small orders (1..8 items).
require_once __DIR__ . '/../php-version/config.php';
require_once __DIR__ . '/../php-version/includes/db.php';
require_once __DIR__ . '/../php-version/includes/functions.php';
require_once __DIR__ . '/../php-version/includes/settings.php';
require_once __DIR__ . '/../php-version/includes/pdf.php';

$pdo = db();
$order = $pdo->query("SELECT * FROM orders WHERE order_number='MVT-DEMO-002'")->fetch(PDO::FETCH_ASSOC);

function count_pages(string $bin): int {
    return preg_match_all('#/Type\s*/Page(?!s)#', $bin, $m);
}

$names = [
    'Microsoft Office 2024 Professional Plus (Windows)',
    'Microsoft Office Home & Business 2024 (PC)',
    'Windows 11 Pro',
    'Microsoft Project 2024 Professional (PC)',
    'Microsoft Visio 2021 Professional (Windows PC)',
    'Microsoft Office Home & Business 2024 (Mac)',
    'Microsoft Office 2021 Home & Business (Windows)',
    'Microsoft Office 2019 Professional Plus (PC)',
];

for ($n = 1; $n <= 8; $n++) {
    $items = [];
    $total = 0;
    for ($i = 0; $i < $n; $i++) {
        $unit = 79.99 + $i;
        $items[] = [
            'name'       => $names[$i % count($names)],
            'quantity'   => 1,
            'unit_price' => $unit,
            'price'      => $unit,
            'qty'        => 1,
        ];
        $total += $unit;
    }
    $order['total'] = $total;
    $rcpt = generate_receipt_pdf($order, $items);
    $inv  = generate_invoice_pdf($order, $items);
    printf("%d items → receipt %d p, invoice %d p (%d + %d bytes)\n",
        $n, count_pages($rcpt), count_pages($inv), strlen($rcpt), strlen($inv));
}

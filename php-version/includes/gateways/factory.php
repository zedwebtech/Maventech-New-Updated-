<?php
/**
 * mv_gateway($slug) — resolve a payment gateway adapter by slug.
 * Returns an instance of MvPaymentGateway or null if unknown.
 *
 * Registry lives inline so adding a gateway is one line + a new adapter file.
 * All adapters implement includes/gateways/interface.php.
 */
require_once __DIR__ . '/interface.php';

function mv_gateway_registry(): array
{
    return [
        'stripe'  => __DIR__ . '/stripe.php',
        'paypal'  => __DIR__ . '/paypal.php',
        'nmi'     => __DIR__ . '/nmi.php',
        'authnet' => __DIR__ . '/authnet.php',
    ];
}

function mv_gateway(string $slug): ?MvPaymentGateway
{
    $slug = strtolower(trim($slug));
    $reg  = mv_gateway_registry();
    if (!isset($reg[$slug])) return null;
    require_once $reg[$slug];
    $class = 'Mv' . ucfirst($slug) . 'Gateway';
    // Special-case aliases where the class name diverges from the slug ucfirst.
    if ($slug === 'authnet') $class = 'MvAuthnetGateway';
    if ($slug === 'nmi')     $class = 'MvNmiGateway';
    if ($slug === 'paypal')  $class = 'MvPayPalGateway';
    if ($slug === 'stripe')  $class = 'MvStripeGateway';
    if (!class_exists($class)) return null;
    return new $class();
}

/**
 * Which slug the checkout should currently route through.
 * Right now: PayPal opt-in falls back to Stripe rails (see paypal.php).
 */
function mv_active_gateway_for_method(string $method): MvPaymentGateway
{
    $slug = $method === 'paypal' ? 'paypal' : 'stripe';
    $gw   = mv_gateway($slug);
    if (!$gw) throw new \RuntimeException('Payment gateway not registered: ' . $slug);
    return $gw;
}

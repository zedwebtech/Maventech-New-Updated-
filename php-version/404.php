<?php
require_once __DIR__ . '/includes/functions.php';

// --- Bare-slug rescue (SEO): old / external links sometimes point at a bare
//     /<slug> with no product.php?slug= wrapper (e.g. /windows-10-home).
//     Before serving a 404, 301 to the real URL when the single path segment
//     matches a known product / category / brand slug. Kills those soft-404s
//     for good, no matter where the link came from. ---
$rescue = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
$rescue = preg_replace('#^(us|uk|au|ca|eu)/#', '', $rescue); // drop region prefix
if ($rescue !== '' && strpos($rescue, '/') === false && strpos($rescue, '.') === false
    && preg_match('#^[a-z0-9][a-z0-9\-]*$#', $rescue)) {
    try {
        $pdo = db();
        $q = $pdo->prepare('SELECT 1 FROM products WHERE slug = ? AND is_active = 1 LIMIT 1');
        $q->execute([$rescue]);
        if ($q->fetchColumn()) { header('Location: /product.php?slug=' . rawurlencode($rescue), true, 301); exit; }
        $q = $pdo->prepare('SELECT 1 FROM products WHERE category = ? AND is_active = 1 LIMIT 1');
        $q->execute([$rescue]);
        if ($q->fetchColumn()) { header('Location: /category.php?slug=' . rawurlencode($rescue), true, 301); exit; }
        $q = $pdo->prepare('SELECT 1 FROM products WHERE brand = ? AND is_active = 1 LIMIT 1');
        $q->execute([$rescue]);
        if ($q->fetchColumn()) { header('Location: /brand.php?slug=' . rawurlencode($rescue), true, 301); exit; }
    } catch (Throwable $e) { /* fall through to 404 */ }
}

http_response_code(404);
$pageTitle = 'Page Not Found | ' . SITE_BRAND;
$noIndex = true;
include __DIR__ . '/includes/header.php';
?>
<div class="container py-5 text-center" style="max-width: 560px;">
  <div class="display-1 fw-bold brand-grad">404</div>
  <h1 class="h4 fw-bold mt-2" data-testid="notfound-title">Page not found</h1>
  <p class="text-secondary">The page you're looking for doesn't exist or has moved. Try one of these instead:</p>
  <div class="d-flex gap-2 justify-content-center flex-wrap mt-3">
    <a href="/" class="btn btn-primary rounded-pill px-4" data-testid="notfound-home">Home</a>
    <a href="shop.php" class="btn btn-outline-primary rounded-pill px-4">Shop All Products</a>
    <a href="contact.php" class="btn btn-outline-secondary rounded-pill px-4">Contact Us</a>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>

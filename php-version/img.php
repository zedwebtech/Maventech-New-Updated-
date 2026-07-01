<?php
/**
 * img.php — cached on-the-fly image thumbnailer for local product/asset images.
 *
 *   /img.php?s=uploads/products/windows-11-pro.webp&w=240
 *
 * Downscales the source to the requested width (preserving aspect ratio),
 * outputs WebP, and caches the result under uploads/cache/thumbs/{w}/.
 *
 * Safe by design: if GD is unavailable, the file is missing/invalid, or the
 * source is already smaller than requested, it simply 302-redirects to the
 * original — so it can never break image display on any host.
 */

$root = __DIR__;

function img_bail_to_original(string $rel): void {
    // Redirect to the untouched original (leading slash = site-absolute).
    header('Location: /' . ltrim($rel, '/'), true, 302);
    exit;
}

$rel = (string)($_GET['s'] ?? '');
$w   = (int)($_GET['w'] ?? 0);

// --- validate the requested path (local uploads/ or assets/ raster images) ---
$rel = str_replace('\\', '/', $rel);
if ($rel === '' || strpos($rel, "\0") !== false || strpos($rel, '..') !== false
    || !preg_match('#^/?(uploads|assets)/.+\.(webp|png|jpe?g)$#i', $rel)) {
    http_response_code(400); exit('bad request');
}
$rel  = ltrim($rel, '/');
$src  = $root . '/' . $rel;
$real = realpath($src);
if ($real === false || strpos($real, $root . '/') !== 0 || !is_file($real)) {
    http_response_code(404); exit('not found');
}

// Clamp width to a sane whitelist of sizes we actually use.
$allowed = [64, 72, 96, 128, 144, 200, 220, 240, 320, 440, 480, 640];
if (!in_array($w, $allowed, true)) { img_bail_to_original($rel); }

// No GD? Serve the original untouched.
if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) { img_bail_to_original($rel); }

// --- cache lookup ---
$cacheDir = $root . '/uploads/cache/thumbs/' . $w;
$cacheKey = md5($rel . '|' . @filemtime($real)) . '.webp';
$cacheFile = $cacheDir . '/' . $cacheKey;

function img_serve(string $file): void {
    header('Content-Type: image/webp');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

if (is_file($cacheFile)) { img_serve($cacheFile); }

// --- load source ---
$info = @getimagesize($real);
if ($info === false) { img_bail_to_original($rel); }
[$sw, $sh] = $info;
$mime = $info['mime'] ?? '';

// Never upscale — if the source is already at/under the target width, use original.
if ($sw <= $w) { img_bail_to_original($rel); }

switch ($mime) {
    case 'image/webp': $imgSrc = @imagecreatefromwebp($real); break;
    case 'image/png':  $imgSrc = @imagecreatefrompng($real);  break;
    case 'image/jpeg': $imgSrc = @imagecreatefromjpeg($real); break;
    default: img_bail_to_original($rel);
}
if (!$imgSrc) { img_bail_to_original($rel); }

$tw = $w;
$th = (int)round($sh * ($tw / $sw));
$dst = imagecreatetruecolor($tw, $th);
// Preserve transparency (png/webp)
imagealphablending($dst, false);
imagesavealpha($dst, true);
$transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
imagefilledrectangle($dst, 0, 0, $tw, $th, $transparent);
imagecopyresampled($dst, $imgSrc, 0, 0, 0, 0, $tw, $th, $sw, $sh);

if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0775, true); }
$ok = @imagewebp($dst, $cacheFile, 82);
imagedestroy($imgSrc);
imagedestroy($dst);

if ($ok && is_file($cacheFile)) { img_serve($cacheFile); }

// Generation failed for any reason → original.
img_bail_to_original($rel);

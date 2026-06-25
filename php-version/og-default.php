<?php
/* ===========================================================================
 *  DEFAULT OG IMAGE GENERATOR  —  /og-default.png
 *  ---------------------------------------------------------------------------
 *  Builds the 1200×630 social-share card used as the fallback for every page
 *  that doesn't override $ogImage (WhatsApp, LinkedIn, X/Twitter, Facebook,
 *  Slack, iMessage…). Composition matches the brand mark (blue gradient
 *  rounded-square "M" + accent dot) so shared links look polished and on-brand.
 *
 *  Spec: 1200×630 PNG, <300 KB, brand-safe centred composition.
 *  Cached to disk (keyed by brand + design version) so repeated social-bot
 *  crawls are served instantly instead of re-rendering every hit.
 *  =========================================================================== */

require_once __DIR__ . '/includes/functions.php';

if (!function_exists('imagecreatetruecolor')) {
    http_response_code(500);
    echo 'GD not available';
    exit;
}

$brand   = function_exists('company_info') ? (string)(company_info()['name'] ?? SITE_BRAND) : SITE_BRAND;
$OG_VERSION = 'v3-brandmark';

/* ---- Disk cache --------------------------------------------------------- */
$cacheDir = __DIR__ . '/uploads/og';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$cacheFile = $cacheDir . '/og-default-' . md5($brand . '|' . $OG_VERSION) . '.png';
if (is_file($cacheFile) && filesize($cacheFile) > 0) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . filesize($cacheFile));
    readfile($cacheFile);
    exit;
}

/* ---- Fonts -------------------------------------------------------------- */
$fontBold = '';
foreach ([
    '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
] as $_f) { if (is_file($_f)) { $fontBold = $_f; break; } }

$W = 1200; $H = 630;
$im = imagecreatetruecolor($W, $H);
imagealphablending($im, true);
imagesavealpha($im, false);

/* ---- Background: deep navy with a soft diagonal blue wash --------------- */
$bg0 = [0x0A, 0x10, 0x1F]; // top-left navy
$bg1 = [0x0E, 0x21, 0x47]; // bottom-right deeper blue
for ($y = 0; $y < $H; $y++) {
    $ty = $y / ($H - 1);
    for ($x = 0; $x < $W; $x += 2) {
        $t = ($ty + ($x / ($W - 1))) / 2;
        $r = (int)round($bg0[0] + ($bg1[0] - $bg0[0]) * $t);
        $g = (int)round($bg0[1] + ($bg1[1] - $bg0[1]) * $t);
        $b = (int)round($bg0[2] + ($bg1[2] - $bg0[2]) * $t);
        $col = imagecolorallocate($im, $r, $g, $b);
        imagesetpixel($im, $x, $y, $col);
        imagesetpixel($im, $x + 1, $y, $col);
    }
}

/* ---- Soft glow behind the logo mark (subtle halo) ---------------------- */
$gcx = 250; $gcy = 315;
for ($rad = 230; $rad > 0; $rad -= 3) {
    $alpha = 126 - (int)((230 - $rad) * 0.13);
    if ($alpha < 96) $alpha = 96; if ($alpha > 127) $alpha = 127;
    $col = imagecolorallocatealpha($im, 0x2B, 0x6B, 0xFF, $alpha);
    imagefilledellipse($im, $gcx, $gcy, $rad * 2, $rad * 2, $col);
}

/* ---- Brand mark: blue gradient rounded square + white "M" + accent dot -- */
$markSize = 280; $mx0 = 110; $my0 = 175;     // top-left of the mark
$radius   = (int)round($markSize * 0.22);
$mg0 = [0x0B, 0x5C, 0xFF]; $mg1 = [0x08, 0x48, 0xCC];
imagealphablending($im, false);
for ($yy = 0; $yy < $markSize; $yy++) {
    for ($xx = 0; $xx < $markSize; $xx++) {
        // rounded-rect inside test
        $minx = $radius; $maxx = $markSize - 1 - $radius;
        $miny = $radius; $maxy = $markSize - 1 - $radius;
        $in = false;
        if ($xx >= $minx && $xx <= $maxx) $in = true;
        elseif ($yy >= $miny && $yy <= $maxy) $in = true;
        else {
            $ccx = ($xx < $minx) ? $minx : (($xx > $maxx) ? $maxx : $xx);
            $ccy = ($yy < $miny) ? $miny : (($yy > $maxy) ? $maxy : $yy);
            $dx = $xx - $ccx; $dy = $yy - $ccy; $in = ($dx * $dx + $dy * $dy) <= ($radius * $radius);
        }
        if (!$in) continue;
        $t = ($xx + $yy) / (2 * ($markSize - 1));
        $r = (int)round($mg0[0] + ($mg1[0] - $mg0[0]) * $t);
        $g = (int)round($mg0[1] + ($mg1[1] - $mg0[1]) * $t);
        $b = (int)round($mg0[2] + ($mg1[2] - $mg0[2]) * $t);
        imagesetpixel($im, $mx0 + $xx, $my0 + $yy, imagecolorallocate($im, $r, $g, $b));
    }
}
imagealphablending($im, true);
$white = imagecolorallocate($im, 255, 255, 255);
// accent dot (bottom-right of the mark)
$accDot = imagecolorallocatealpha($im, 0x9C, 0xBF, 0xFF, 18);
imagefilledellipse($im, $mx0 + (int)round($markSize * 0.835), $my0 + (int)round($markSize * 0.79), 30, 30, $accDot);
// "M"
if ($fontBold) {
    $fs = $markSize * 0.58;
    $bb = imagettfbbox($fs, 0, $fontBold, 'M');
    $tw = $bb[2] - $bb[0]; $th = $bb[1] - $bb[7];
    $tx = (int)round($mx0 + ($markSize - $tw) / 2 - $bb[0]);
    $ty = (int)round($my0 + ($markSize - $th) / 2 - $bb[7]) - 6;
    imagettftext($im, $fs, 0, $tx, $ty, $white, $fontBold, 'M');
}

/* ---- Right column: brand name + tagline + CTA -------------------------- */
$colX = 470;
$maxW = $W - $colX - 56;   // keep everything inside the safe right margin

/** Largest font size (<= $start) at which $text fits within $maxW px. */
$fitSize = static function (string $text, string $font, float $start, float $min = 20.0) use ($maxW): float {
    for ($fs = $start; $fs >= $min; $fs -= 1.0) {
        $bb = imagettfbbox($fs, 0, $font, $text);
        if (($bb[2] - $bb[0]) <= $maxW) return $fs;
    }
    return $min;
};

if ($fontBold) {
    // Brand name — auto-fit so long names never clip the canvas edge.
    $nameFs = $fitSize($brand, $fontBold, 62.0, 30.0);
    imagettftext($im, $nameFs, 0, $colX, 218, $white, $fontBold, $brand);
    imagefilledrectangle($im, $colX, 242, $colX + 170, 249, imagecolorallocate($im, 0x4D, 0x8C, 0xFF));

    // Tagline — two fixed lines, auto-fit each to the safe width.
    $tag   = imagecolorallocate($im, 0xCB, 0xE2, 0xFF);
    $line1 = 'Genuine Microsoft Office';
    $line2 = '& Windows 11 License Keys';
    $tFs   = min($fitSize($line1, $fontBold, 44.0, 22.0), $fitSize($line2, $fontBold, 44.0, 22.0));
    imagettftext($im, $tFs, 0, $colX, 332, $tag, $fontBold, $line1);
    imagettftext($im, $tFs, 0, $colX, 332 + (int)round($tFs * 1.45), $tag, $fontBold, $line2);

    // CTA pill (green) — width fitted to its text.
    $ctaText = 'Instant delivery  -  One-time purchase';
    $ctaFs   = 24.0;
    $cbb     = imagettfbbox($ctaFs, 0, $fontBold, $ctaText);
    $ctaW    = $cbb[2] - $cbb[0];
    $py1 = 498; $py2 = 566; $rp = (int)(($py2 - $py1) / 2);
    $px1 = $colX; $px2 = $colX + $ctaW + 68;
    $green = imagecolorallocate($im, 0x22, 0xC5, 0x5E);
    imagefilledrectangle($im, $px1 + $rp, $py1, $px2 - $rp, $py2, $green);
    imagefilledellipse($im, $px1 + $rp, ($py1 + $py2) / 2, ($py2 - $py1), ($py2 - $py1), $green);
    imagefilledellipse($im, $px2 - $rp, ($py1 + $py2) / 2, ($py2 - $py1), ($py2 - $py1), $green);
    imagettftext($im, $ctaFs, 0, $px1 + 34, $py1 + 45, $white, $fontBold, $ctaText);
} else {
    imagestring($im, 5, $colX, 200, $brand, $white);
}

/* ---- Save to disk cache + stream --------------------------------------- */
@imagepng($im, $cacheFile, 6);
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
imagepng($im, null, 6);
imagedestroy($im);

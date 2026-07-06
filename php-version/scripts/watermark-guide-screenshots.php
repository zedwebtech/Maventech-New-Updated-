<?php
/**
 * scripts/watermark-guide-screenshots.php
 *
 * Add a "MAVENTECH – Reference Guide (for illustration only)" banner + a
 * large diagonal "MAVENTECH REFERENCE" watermark to each Windows install-
 * guide screenshot.
 *
 * WHY: Google Ads / Merchant Center run automated full-page screenshot
 * QA on every product / landing page. Our install-guide screenshots
 * show real Windows-Settings > Activation views — some with the state
 * "Not active" while the customer is being walked through the activation
 * flow. Without a clear "this is a reference image" label, the crawler's
 * OCR pipeline can classify it as "site is running an unactivated copy
 * of Windows" — a policy signal that suspends selling accounts.
 *
 * The watermark makes it unambiguous the image is educational content,
 * not a live screenshot of the site running unactivated Windows.
 *
 * Idempotent — a hash marker in /app/php-version/uploads/guides/.watermarked
 * lets us skip re-processing on subsequent boots. Originals kept in
 * uploads/guides/_originals/ so we can regenerate if needed.
 */

$guideDir  = __DIR__ . '/../uploads/guides/windows';
$origDir   = __DIR__ . '/../uploads/guides/_originals';
$markerV   = __DIR__ . '/../uploads/guides/.watermarked-v2';

if (file_exists($markerV)) {
    // Already applied this version.
    exit(0);
}
if (!is_dir($guideDir)) exit(0);
@mkdir($origDir, 0755, true);

$targets = ['step-activated.jpg', 'step-change.jpg', 'step-key.jpg', 'step-settings.jpg'];

foreach ($targets as $name) {
    $src = "$guideDir/$name";
    if (!file_exists($src)) continue;

    // Back up the original if we haven't already.
    $origBackup = "$origDir/$name";
    if (!file_exists($origBackup)) {
        @copy($src, $origBackup);
    }

    // Prefer the backed-up original as the source (so re-runs are stable).
    $sourceFile = file_exists($origBackup) ? $origBackup : $src;

    // Get dimensions
    $sizeCmd = "identify -format '%w %h' " . escapeshellarg($sourceFile) . " 2>/dev/null";
    $dims = trim((string)@shell_exec($sizeCmd));
    if ($dims === '') continue;
    [$w, $h] = array_map('intval', explode(' ', $dims));
    if ($w < 100 || $h < 100) continue;

    $tmp = "$src.wm.tmp.jpg";
    $cmd = sprintf(
        'convert %s '
        . '-fill "rgba(37,99,235,0.92)" -draw "rectangle 0,0 %d,42" '
        . '-fill white -pointsize 20 -gravity NorthWest -annotate +14+10 "MAVENTECH - Reference Guide (for illustration only)" '
        . '\\( -size %dx%d xc:none -fill "rgba(37,99,235,0.14)" -pointsize 60 -gravity center -annotate 340 "MAVENTECH REFERENCE" \\) '
        . '-composite '
        . '%s 2>&1',
        escapeshellarg($sourceFile),
        $w, $w, $h,
        escapeshellarg($tmp)
    );
    @shell_exec($cmd);
    if (file_exists($tmp) && filesize($tmp) > 1000) {
        @rename($tmp, $src);
        // Regenerate .webp sibling
        $webp = preg_replace('/\.jpg$/', '.webp', $src);
        @shell_exec('convert ' . escapeshellarg($src) . ' ' . escapeshellarg($webp) . ' 2>/dev/null');
        echo "[watermark-guide] $name marked\n";
    }
}

@file_put_contents($markerV, date('c') . "\n");

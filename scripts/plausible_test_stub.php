<?php
/**
 * Minimal renderer that reproduces the EXACT Plausible block from
 * /app/php-version/includes/header.php so we can verify manual-mode +
 * full-URL pageview tracking with a headless browser.
 *
 * The bulk PHP app requires MySQL / config bootstrapping we don't have
 * in this sandbox, so this stub is the smallest thing that exercises
 * only the code under test.  If header.php ever drifts, the block
 * pulled in here drifts with it because we extract it dynamically.
 */

/* ---- Mock the two Company-Info settings the header reads ---- */
$MOCK = [
    'plausible_enabled' => '1',
    // Simulate an admin paste of the default plausible.io snippet.  Real
    // plausible.io traffic will be intercepted by playwright before it
    // reaches the network, so the domain is intentionally set to the
    // production domain for a realistic pageview payload.
    'plausible_script'  => '<script defer data-domain="maventechsoftware.com" src="https://plausible.io/js/script.js"></script>',
];
function setting_get(string $k, $default = '') { global $MOCK; return $MOCK[$k] ?? $default; }

/* ---- Extract the Plausible block from header.php verbatim ---- */
$headerPath = __DIR__ . '/../php-version/includes/header.php';
$src        = file_get_contents($headerPath);
// Capture the ENTIRE Plausible block — from the opening PHP comment
// header that owns the $plausibleEnabled setting resolution down to
// the closing endif tag right after "End Plausible Analytics" HTML
// comment.  This keeps mixed php+html chunks well-formed for eval below.
if (!preg_match(
    '~(<\?php\s*\n\s*/\* =+\s+Plausible Analytics.*?End Plausible Analytics -->\s*<\?php\s+endif;\s*\?\>)~s',
    $src, $m
)) {
    http_response_code(500);
    echo "Could not locate Plausible block in header.php";
    exit;
}
$block = $m[1];
?><!doctype html>
<html><head>
<meta charset="utf-8">
<title>Plausible Rewrite Test Stub</title>
<?php
    // Evaluate the extracted block in the current scope so it renders
    // exactly as header.php would.  We wrap in <?php … so the mixed
    // php+html chunks execute; eval requires the string to look like
    // PHP source, and the block does begin with a php statement.
    eval('?>' . $block);
?>
</head>
<body>
<h1>Plausible tracking stub</h1>
<p>Path: <code id="path"></code></p>
<p>Query: <code id="query"></code></p>
<script>
  document.getElementById('path').textContent = window.location.pathname;
  document.getElementById('query').textContent = window.location.search || '(none)';
  // Expose the queued pageview args so a headless browser can read them
  // even if the async plausible script hasn't loaded yet (or is blocked).
  window.__plQ = window.plausible && window.plausible.q ? window.plausible.q : [];
</script>
</body></html>

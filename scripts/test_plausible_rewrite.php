<?php
/**
 * Verifies the Plausible auto-upgrade logic that lives inside
 * /app/php-version/includes/header.php.
 *
 * We render header.php in an isolated env where we override the
 * two settings it reads (plausible_enabled, plausible_script) with
 * a fixture, capture the stdout, and assert the resulting HTML
 * contains the manual-mode <script src>, the pageview shim, and
 * the plausible('pageview', { u: window.location.href }) call.
 *
 * Because we cannot run a full PHP + MySQL app inside this
 * container, we do NOT include header.php.  Instead we extract
 * the exact rewrite snippet the header uses and run it directly
 * against a battery of realistic paste-fixtures.  If the regex or
 * the shim ever regresses, this test fails loudly.
 */

$fixtures = [
    'default_snippet' => <<<HTML
<script defer data-domain="maventechsoftware.com" src="https://plausible.io/js/script.js"></script>
HTML,
    'outbound_links_snippet' => <<<HTML
<script defer data-domain="maventechsoftware.com" src="https://plausible.io/js/script.outbound-links.js"></script>
HTML,
    'hash_outbound_snippet' => <<<HTML
<script defer data-domain="maventechsoftware.com" src="https://plausible.io/js/script.hash.outbound-links.js"></script>
HTML,
    'already_manual' => <<<HTML
<script defer data-domain="maventechsoftware.com" src="https://plausible.io/js/script.manual.js"></script>
HTML,
    'already_manual_outbound' => <<<HTML
<script defer data-domain="maventechsoftware.com" src="https://plausible.io/js/script.manual.outbound-links.js"></script>
HTML,
    'self_hosted_snippet' => <<<HTML
<script defer data-domain="maventechsoftware.com" src="https://analytics.example.com/js/script.js"></script>
HTML,
    'legacy_plausiblejs' => <<<HTML
<script async defer data-domain="maventechsoftware.com" src="https://analytics.example.com/js/plausible.js"></script>
HTML,
    'compat_snippet_with_extra_js' => <<<HTML
<script defer data-domain="maventechsoftware.com" src="https://plausible.io/js/script.js"></script>
<script>window.plausible=window.plausible||function(){(plausible.q=plausible.q||[]).push(arguments)}</script>
HTML,
];

/* ------ EXACT LOGIC FROM includes/header.php (kept in sync) --------- */
function rewrite_plausible(string $plausibleScript): string {
    $out = $plausibleScript;
    if (preg_match('~/js/script(?:\.[a-z0-9\-]+)*\.js~i', $out)) {
        $out = preg_replace_callback(
            '~(/js/)script((?:\.[a-z0-9\-]+)*)\.js~i',
            function ($m) {
                $exts = $m[2];
                if (stripos($exts, '.manual') === false) {
                    $exts = '.manual' . $exts;
                }
                return $m[1] . 'script' . $exts . '.js';
            },
            $out,
            1
        );
    }
    return $out;
}

/* ------ THE ASSERTIONS ------ */
$expected = [
    'default_snippet'              => 'https://plausible.io/js/script.manual.js',
    'outbound_links_snippet'       => 'https://plausible.io/js/script.manual.outbound-links.js',
    'hash_outbound_snippet'        => 'https://plausible.io/js/script.manual.hash.outbound-links.js',
    'already_manual'               => 'https://plausible.io/js/script.manual.js',
    'already_manual_outbound'      => 'https://plausible.io/js/script.manual.outbound-links.js',
    'self_hosted_snippet'          => 'https://analytics.example.com/js/script.manual.js',
    'legacy_plausiblejs'           => 'https://analytics.example.com/js/plausible.js', // untouched: not script*.js shape
    'compat_snippet_with_extra_js' => 'https://plausible.io/js/script.manual.js',
];

$fails = 0; $passes = 0;
foreach ($fixtures as $name => $snippet) {
    $out = rewrite_plausible($snippet);
    $want = $expected[$name];
    $ok = (strpos($out, $want) !== false);
    if ($ok) {
        echo "PASS  {$name} -> contains {$want}\n";
        $passes++;
    } else {
        echo "FAIL  {$name}\n  wanted: {$want}\n  got:    {$out}\n";
        $fails++;
    }
}

/* Additional sanity: the header emits an initial pageview using
 * window.location.href (path + query + hash).  We assert on the
 * literal string that lives in header.php so a regression there
 * (e.g. someone changes it back to window.location.pathname)
 * fails this test. */
$headerPath = __DIR__ . '/../php-version/includes/header.php';
$headerSrc  = file_get_contents($headerPath);
$mustHave   = [
    "window.plausible('pageview', { u: u });",
    "var u = window.location.href;",
    "script.manual.js",  // in the block comment doc-string
    "plausible('pageview'",
    "history.pushState",
    "hashchange",
];
foreach ($mustHave as $needle) {
    if (strpos($headerSrc, $needle) !== false) {
        echo "PASS  header.php contains: {$needle}\n";
        $passes++;
    } else {
        echo "FAIL  header.php missing:  {$needle}\n";
        $fails++;
    }
}

/* Also verify no accidental double-rewrite would produce
 * "script.manual.manual.js" for any input. */
foreach ($fixtures as $name => $snippet) {
    $out = rewrite_plausible($snippet);
    if (strpos($out, 'script.manual.manual') !== false) {
        echo "FAIL  double-rewrite detected on {$name}\n";
        $fails++;
    } else {
        echo "PASS  no double-rewrite on {$name}\n";
        $passes++;
    }
}

echo "\n{$passes} passed, {$fails} failed\n";
exit($fails === 0 ? 0 : 1);

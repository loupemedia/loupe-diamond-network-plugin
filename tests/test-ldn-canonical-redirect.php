<?php
/**
 * Canonical trailing-slash redirect tests.
 *
 * Test intent: a routed price or size page has exactly one URL form that serves
 * 200 — the one user_trailingslashit() produces — so the other form must resolve
 * to a redirect target and the canonical form must not redirect at all.
 * Would fail if: canonical_request_uri() returned a target for an already-canonical
 * URL (infinite redirect loop), returned null for the non-canonical form (both
 * forms keep serving 200), or appended a slash to a sitemap `.xml` path.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-canonical-redirect.php
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');

// Default permalink convention across the network: trailing slashes on.
$GLOBALS['ldn_test_use_trailing_slashes'] = true;
if (!function_exists('user_trailingslashit')) {
    function user_trailingslashit($path) {
        return !empty($GLOBALS['ldn_test_use_trailing_slashes'])
            ? rtrim((string) $path, '/') . '/'
            : rtrim((string) $path, '/');
    }
}

require_once __DIR__ . '/../includes/class-ldn-canonical-redirect.php';

$checks = 0;
function check($cond, $msg) {
    global $checks;
    ++$checks;
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}

$target = array('LDN_Canonical_Redirect', 'canonical_request_uri');

// --- Trailing slashes on (network default) -------------------------------
check(
    $target('/diamond-prices/natural/1-carat/round') === '/diamond-prices/natural/1-carat/round/',
    'unslashed price URL redirects to slashed form'
);
check(
    $target('/diamond-prices/natural/1-carat/round/') === null,
    'already-canonical price URL does not redirect'
);
check(
    $target('/diamond-size/round/1-carat') === '/diamond-size/round/1-carat/',
    'unslashed size URL redirects to slashed form'
);
check(
    $target('/diamond-prices/natural/1-carat/round?utm_source=x')
        === '/diamond-prices/natural/1-carat/round/?utm_source=x',
    'query string preserved through redirect'
);
check(
    $target('/diamond-prices/natural/1.5-carat/round') === '/diamond-prices/natural/1.5-carat/round/',
    'decimal carat slug still gets a trailing slash'
);

// --- File-like paths must never be slashed ------------------------------
check($target('/us/diamond-prices/sitemap.xml') === null, 'sitemap.xml not slashed');
check($target('/diamond-size/sitemap.xml') === null, 'size sitemap.xml not slashed');
check($target('/llms.txt') === null, 'llms.txt not slashed');

// --- Degenerate inputs ---------------------------------------------------
check($target('/') === null, 'site root not redirected');
check($target('') === null, 'empty request uri ignored');
check($target('https://evil.example/x') === null, 'non-path request uri ignored');

// --- Trailing slashes off: the redirect must follow the same convention --
// as the canonical tag, i.e. strip rather than append.
$GLOBALS['ldn_test_use_trailing_slashes'] = false;
check(
    $target('/diamond-prices/natural/1-carat/round/') === '/diamond-prices/natural/1-carat/round',
    'slashed URL redirects to unslashed form when permalinks omit trailing slashes'
);
check(
    $target('/diamond-prices/natural/1-carat/round') === null,
    'unslashed URL is canonical when permalinks omit trailing slashes'
);

echo "OK: {$checks} checks passed\n";

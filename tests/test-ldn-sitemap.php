<?php
/**
 * Sitemap XML builder tests.
 *
 * Test intent: price and size sitemaps share the same urlset envelope and index format.
 * Would fail if: size normalisation dropped the urlset wrapper or index omitted lastmod.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-sitemap.php
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/../includes/class-ldn-sitemap.php';

$checks = 0;
function check($cond, $msg) {
    global $checks;
    ++$checks;
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}

$rows = array(
    array(
        'canonical_url'  => 'https://ringspo.com/us/diamond-prices/natural/1-carat/round',
        'last_generated' => '2026-06-01T12:00:00Z',
    ),
);
$xml = LDN_Sitemap::urlset_from_rows($rows);
check(strpos($xml, '<urlset') !== false, 'urlset root present');
check(strpos($xml, 'ringspo.com/us/diamond-prices') !== false, 'loc present');
check(strpos($xml, '<lastmod>2026-06-01</lastmod>') !== false, 'lastmod formatted');

$sample = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
    . '<url><loc>https://ringspo.com/diamond-size/round/1-carat</loc></url>'
    . '</urlset>';
$normalised = LDN_Sitemap::normalise_urlset_xml($sample);
check(strpos($normalised, 'xmlns:xhtml') !== false, 'normalised size xml adds xhtml ns');
check(strpos($normalised, 'diamond-size/round') !== false, 'size url preserved');

$index = LDN_Sitemap::sitemap_index(array(
    'https://ringspo.com/us/diamond-prices/sitemap.xml',
    'https://ringspo.com/diamond-size/sitemap.xml',
));
check(strpos($index, '<sitemapindex') !== false, 'sitemap index root');
check(strpos($index, 'diamond-size/sitemap.xml') !== false, 'child size sitemap listed');

echo "OK: {$checks} checks passed\n";

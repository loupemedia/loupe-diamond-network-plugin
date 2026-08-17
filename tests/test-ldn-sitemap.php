<?php
/**
 * Sitemap XML builder tests.
 *
 * Test intent: price sitemaps include C4.5 shape-leaf rows (registry
 * hierarchy_level 5). Price and size sitemaps share the same urlset envelope.
 * Would fail if: fetch capped at 4 (empty sitemap / 404 on every money page).
 *
 * Run: php loupe-diamond-network/tests/test-ldn-sitemap.php
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

require_once __DIR__ . '/../includes/class-ldn-sitemap.php';
require_once __DIR__ . '/../includes/class-ldn-page-registry.php';

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

check(
    LDN_Page_Registry::PRICE_SITEMAP_MAX_HIERARCHY === 5,
    'price sitemap cap is registry 5 so C4.5 shape leaves are included'
);
check(
    LDN_Page_Registry::price_sitemap_includes_level(5),
    'shape leaf (registry 5 / product Level 4) is in the price sitemap'
);
check(
    LDN_Page_Registry::price_sitemap_includes_level(4),
    'carat hub (registry 4) is in the price sitemap when present'
);
check(
    !LDN_Page_Registry::price_sitemap_includes_level(6),
    'future cert/listing leaves (registry 6+) stay out of the price sitemap'
);

echo "OK: {$checks} checks passed\n";

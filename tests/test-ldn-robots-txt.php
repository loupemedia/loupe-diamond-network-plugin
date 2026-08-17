<?php
/**
 * Standalone tests for LDN_Robots_Txt.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-robots-txt.php
 *
 * Test intent: /robots.txt is generated from the shipped template with DOMAIN
 * replaced from the site origin. Sitemap lines point at the WP index and the
 * LDN network index, never a hardcoded size sitemap.
 * Would fail if: generate() left DOMAIN in Sitemap lines, or listed
 * /diamond-size/sitemap.xml (404 on Loupe).
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');

if (!function_exists('home_url')) {
    function home_url($path = '/') {
        return 'https://ringspo.com' . $path;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') {
        return $text;
    }
}

require_once __DIR__ . '/../includes/class-ldn-robots-txt.php';

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$robots = new LDN_Robots_Txt();
check(is_readable($robots->template_path()), 'plugin ships robots-consumer-site.txt');

$text = $robots->generate('https://ringspo.com/');
check(strpos($text, 'Sitemap: https://ringspo.com/sitemap_index.xml') !== false,
    'WP post sitemap uses the site host');
check(strpos($text, 'Sitemap: https://ringspo.com/sitemaps/network.xml') !== false,
    'LDN network sitemap uses the site host');
check(strpos($text, 'DOMAIN') === false, 'DOMAIN placeholder is replaced');
$sitemap_lines = array();
foreach (preg_split('/\R/', $text) as $line) {
    if (stripos(ltrim($line), 'Sitemap:') === 0) {
        $sitemap_lines[] = $line;
    }
}
$joined = implode("\n", $sitemap_lines);
check(strpos($joined, 'diamond-size/sitemap.xml') === false,
    'must not hardcode a size sitemap that 404s on Loupe');
check(strpos($text, 'User-agent: Googlebot') !== false, 'Tier 1 Googlebot present');
check(strpos($text, 'User-agent: CCBot') !== false, 'Tier 3 CCBot present');

$mj = $robots->generate('https://modernjeweler.com/');
check(strpos($mj, 'Sitemap: https://modernjeweler.com/sitemaps/network.xml') !== false,
    'Modern Jeweler host is substituted independently');

$private = $robots->filter_robots_txt("User-agent: *\nDisallow: /\n", false);
check(strpos($private, 'Disallow: /') !== false, 'private sites keep core disallow-all');
check(strpos($private, 'sitemaps/network.xml') === false,
    'private sites do not advertise the network sitemap');

$total = $GLOBALS['__tests'];
$fails = $GLOBALS['__fails'];
if ($fails === 0) {
    fwrite(STDOUT, "OK: {$total} assertions passed\n");
    exit(0);
}
fwrite(STDERR, "{$fails}/{$total} assertions FAILED\n");
exit(1);

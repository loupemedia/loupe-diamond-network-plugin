<?php
/**
 * Standalone tests for LDN_Llms_Txt.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-llms-txt.php
 *
 * Test intent: /llms.txt is generated from site config (brand, domain, URL structure),
 * not hand-written. Would fail if: generate() omits brand, sample URLs, or daily data note.
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');

if (!function_exists('user_trailingslashit')) {
    function user_trailingslashit($string) {
        return rtrim($string, '/') . '/';
    }
}
if (!function_exists('home_url')) {
    function home_url($path = '/') {
        return 'https://modernjeweler.com' . $path;
    }
}

require_once __DIR__ . '/../includes/class-ldn-llms-txt.php';

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$config = new class {
    public function get_site($site_id) {
        return array(
            'brand_name' => 'Modern Jeweler',
            'domain'     => 'modernjeweler.com',
            'countries'  => array(array('code' => 'us', 'full_name' => 'United States', 'locale' => 'en-US')),
        );
    }
    public function get_url_structure($site_id) {
        return array(
            'level_1'      => '/diamond-prices',
            'level_2'      => '/diamond-prices/{type}',
            'level_4'      => '/diamond-prices/{type}/{carat}/{shape}',
            'type_natural' => 'natural',
            'carat_format' => '{value}-carat',
        );
    }
};

$llms = new LDN_Llms_Txt('modernjeweler', $config);
$text = $llms->generate();

check(strpos($text, '# Modern Jeweler') === 0, 'must start with brand heading');
check(strpos($text, 'United States') !== false, 'must mention country from site config');
check(strpos($text, 'Updated daily') !== false, 'must note daily refresh');
check(strpos($text, 'https://modernjeweler.com/diamond-prices/natural/1-carat/round/') !== false,
    'must include example shape URL from url_structure');

$urls = $llms->sample_page_urls('https://modernjeweler.com');
check(isset($urls['Diamond prices (overview)']), 'sample URLs must include level_1');

$total = $GLOBALS['__tests'];
$fails = $GLOBALS['__fails'];
if ($fails === 0) {
    fwrite(STDOUT, "OK: {$total} assertions passed\n");
    exit(0);
}
fwrite(STDERR, "{$fails}/{$total} assertions FAILED\n");
exit(1);

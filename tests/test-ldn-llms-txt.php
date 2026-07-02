<?php
/**
 * Standalone tests for LDN_Llms_Txt.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-llms-txt.php
 *
 * Test intent: /llms.txt is generated from site config (brand, domain, URL structure),
 * not hand-written. Sites entitled to the size module also document /diamond-size/ URLs.
 * Would fail if: generate() omits brand, sample URLs, daily data note, or size section when entitled.
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
check(strpos($text, '## Diamond size analysis') === false, 'sites without size paths omit size section');

$ringspo_config = new class {
    public function get_site($site_id) {
        return array(
            'brand_name' => 'Ringspo',
            'domain'     => 'ringspo.com',
            'countries'  => array(array('code' => 'us', 'full_name' => 'United States')),
        );
    }
    public function get_url_structure($site_id) {
        return array(
            'level_1'            => '/{country}/diamond-prices',
            'level_4'            => '/{country}/diamond-prices/{type}/{carat}/{shape}',
            'type_natural'       => 'natural',
            'carat_format'       => '{value}-carat',
            'size_level_1'       => '/diamond-size',
            'size_level_2'       => '/diamond-size/{shape}',
            'size_level_3'       => '/diamond-size/{shape}/{carat}',
            'size_level_compare' => '/diamond-size/compare/{compare}',
            'size_level_sitemap' => '/diamond-size/sitemap.xml',
        );
    }
    public function shape_to_s3_slug($shape) {
        return $shape === 'princess' ? 'princess-cut' : (string) $shape;
    }
};
$ringspo_artefacts = new class {
    public function site_entitled_to_artefact($site_id, $artefact_id) {
        return $site_id === 'ringspo' && $artefact_id === 'size_summary_json';
    }
};
$llms_ringspo = new LDN_Llms_Txt('ringspo', $ringspo_config, $ringspo_artefacts);
$ringspo_text = $llms_ringspo->generate();
check(strpos($ringspo_text, '## Diamond size analysis') !== false, 'entitled site includes size section');
check(strpos($ringspo_text, 'https://ringspo.com/diamond-size/') !== false, 'size mega hub URL documented');
check(strpos($ringspo_text, 'https://ringspo.com/diamond-size/round/1-carat/') !== false,
    'example individual size URL documented');
check(strpos($ringspo_text, 'https://ringspo.com/diamond-size/sitemap.xml') !== false,
    'size XML sitemap URL documented');
check(strpos($ringspo_text, 'round-1-carat-vs-princess-cut-1-carat') !== false,
    'example comparison URL uses S3 shape slugs');

$total = $GLOBALS['__tests'];
$fails = $GLOBALS['__fails'];
if ($fails === 0) {
    fwrite(STDOUT, "OK: {$total} assertions passed\n");
    exit(0);
}
fwrite(STDERR, "{$fails}/{$total} assertions FAILED\n");
exit(1);

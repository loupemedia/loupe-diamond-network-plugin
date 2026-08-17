<?php
/**
 * Standalone tests for LDN_Hreflang.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-hreflang.php
 *
 * Test intent: Loupe/DPE hreflang lists only siblings whose price module is
 * hub-ON. A single live site emits no cluster tags.
 * Would fail if: cluster_alternates still included every profile domain
 * (Modern Jeweler would advertise Jewellery Monthly 404s).
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');

if (!function_exists('user_trailingslashit')) {
    function user_trailingslashit($string) {
        return rtrim($string, '/') . '/';
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url) {
        return $url;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}

require_once __DIR__ . '/../includes/class-ldn-hreflang.php';

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
        $sites = array(
            'modernjeweler' => array(
                'domain'                    => 'modernjeweler.com',
                'content_profile'           => 'config/content_profiles/loupe_sites.yaml',
                'canonical_source_country'  => 'us',
                'countries'                 => array(array('code' => 'us', 'locale' => 'en-US')),
            ),
            'jewellerymonthly' => array(
                'domain'                    => 'jewellerymonthly.co.uk',
                'content_profile'           => 'config/content_profiles/loupe_sites.yaml',
                'canonical_source_country'  => 'uk',
                'countries'                 => array(array('code' => 'uk', 'locale' => 'en-GB')),
            ),
            'jewelleryaustralia' => array(
                'domain'                    => 'jewellery.org.au',
                'content_profile'           => 'config/content_profiles/loupe_sites.yaml',
                'canonical_source_country'  => 'au',
                'countries'                 => array(array('code' => 'au', 'locale' => 'en-AU')),
            ),
        );
        return isset($sites[$site_id]) ? $sites[$site_id] : array();
    }
    public function sites_for_profile($profile_name) {
        if ($profile_name !== 'loupe_sites') {
            return array();
        }
        return array('modernjeweler', 'jewellerymonthly', 'jewelleryaustralia');
    }
};

$mj_only = new class {
    public function is_site_enabled($site_id, $country, $module) {
        return $site_id === 'modernjeweler' && $country === 'us' && $module === 'price';
    }
};

$hreflang_mj = new LDN_Hreflang($config, $mj_only);
$alts_mj = $hreflang_mj->cluster_alternates('loupe_sites', 'diamond-prices/natural/1-carat/round');
check(count($alts_mj) === 1, 'MJ-only rollout emits one alternate');
check(isset($alts_mj['en-US']), 'live MJ locale is present');
check(!isset($alts_mj['en-GB']), 'Jewellery Monthly stays out while hub-OFF');
check(
    strpos(implode(' ', $alts_mj), 'jewellerymonthly.co.uk') === false,
    'OFF sibling domain is not listed'
);

$two_live = new class {
    public function is_site_enabled($site_id, $country, $module) {
        if ($module !== 'price') {
            return false;
        }
        return ($site_id === 'modernjeweler' && $country === 'us')
            || ($site_id === 'jewellerymonthly' && $country === 'uk');
    }
};
$hreflang_two = new LDN_Hreflang($config, $two_live);
$alts_two = $hreflang_two->cluster_alternates('loupe_sites', '/diamond-prices/natural/1-carat/round/');
check(count($alts_two) === 2, 'two hub-ON siblings both listed');
check(isset($alts_two['en-US']) && isset($alts_two['en-GB']), 'US and GB locales present');
check(
    strpos($alts_two['en-GB'], 'https://jewellerymonthly.co.uk/diamond-prices/natural/1-carat/round/') !== false,
    'UK alternate uses the live domain and path'
);
check(
    $hreflang_two->x_default_url('loupe_sites', $alts_two) === $alts_two['en-US'],
    'x-default is Modern Jeweler when that locale is live'
);

$hreflang_none = new LDN_Hreflang($config, null);
$alts_none = $hreflang_none->cluster_alternates('loupe_sites', 'diamond-prices');
check($alts_none === array(), 'no rollout means no cluster tags');

$total = $GLOBALS['__tests'];
$fails = $GLOBALS['__fails'];
if ($fails === 0) {
    fwrite(STDOUT, "OK: {$total} assertions passed\n");
    exit(0);
}
fwrite(STDERR, "{$fails}/{$total} assertions FAILED\n");
exit(1);

<?php
/**
 * GTM injection and container map (measurement first test).
 *
 * Test intent: LDN injects GTM only for a site with a valid GTM- id, only in
 * production, never in the LDN preview harness; Ringspo and Loupe can point at
 * different containers.
 * Would fail if: an empty id still printed gtm.js, or staging/preview polluted
 * the live GA4 property, or every site shared one hardcoded container.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-analytics.php
 */

error_reporting(E_ALL);

define('ABSPATH', __DIR__ . '/');
define('LDN_PLUGIN_DIR', dirname(__DIR__) . '/');
define('LDN_INCLUDES_DIR', LDN_PLUGIN_DIR . 'includes/');
define('LDN_VERSION', 'test');

function esc_js($text) {
    return addcslashes((string) $text, "\n\r\"'/");
}
function esc_url($url) {
    return (string) $url;
}
function wp_json_encode($data) {
    return json_encode($data);
}

require_once LDN_PLUGIN_DIR . 'includes/class-ldn-analytics.php';

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$map = array(
    'containers' => array(
        'ringspo' => array(
            'gtm_container_id' => 'GTM-RING1',
            'site_ids' => array('ringspo'),
        ),
        'loupe' => array(
            'gtm_container_id' => 'GTM-LOUPE1',
            'site_ids' => array('modernjeweler', 'jewelleryaustralia'),
        ),
    ),
);

check(
    LDN_Analytics::parse_container_id($map, 'ringspo') === 'GTM-RING1',
    'Ringspo resolves its own container'
);
check(
    LDN_Analytics::parse_container_id($map, 'modernjeweler') === 'GTM-LOUPE1',
    'a Loupe site resolves the Loupe container'
);
check(
    LDN_Analytics::parse_container_id($map, 'diamondadvisors') === '',
    'an unlisted site does not inherit a container'
);
check(
    LDN_Analytics::parse_container_id(
        array('containers' => array('ringspo' => array(
            'gtm_container_id' => '',
            'site_ids' => array('ringspo'),
        ))),
        'ringspo'
    ) === '',
    'an empty id is off'
);
check(
    LDN_Analytics::normalise_container_id('gtm-abc12') === 'GTM-ABC12',
    'ids are normalised to GTM- uppercase'
);
check(
    LDN_Analytics::normalise_container_id('UA-123') === '',
    'a Universal Analytics id is refused'
);

check(
    LDN_Analytics::should_inject('GTM-RING1', true, false) === true,
    'production with an id injects'
);
check(
    LDN_Analytics::should_inject('GTM-RING1', false, false) === false,
    'staging does not inject'
);
check(
    LDN_Analytics::should_inject('GTM-RING1', true, true) === false,
    'LDN preview does not inject'
);
check(
    LDN_Analytics::should_inject('', true, false) === false,
    'production with no id does not inject'
);

$head = LDN_Analytics::head_snippet('GTM-RING1', array(
    'site_id' => 'ringspo',
    'country' => 'us',
    'page_type' => 'calculator',
));
check(
    strpos($head, 'GTM-RING1') !== false
        && strpos($head, 'googletagmanager.com/gtm.js') !== false
        && strpos($head, '"site_id":"ringspo"') !== false
        && strpos($head, 'gtag(') === false,
    'head snippet is GTM plus dataLayer params, not a raw gtag call'
);
check(
    LDN_Analytics::head_snippet('', array('site_id' => 'ringspo')) === '',
    'head snippet is empty without a container'
);

$noscript = LDN_Analytics::noscript_snippet('GTM-RING1');
check(
    strpos($noscript, 'googletagmanager.com/ns.html?id=GTM-RING1') !== false,
    'noscript iframe uses the same container'
);

$pass = $GLOBALS['__tests'] - $GLOBALS['__fails'];
fwrite(STDOUT, "{$pass} of {$GLOBALS['__tests']} checks passed\n");
exit($GLOBALS['__fails'] === 0 ? 0 : 1);

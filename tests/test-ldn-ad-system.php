<?php
/**
 * Ad resolver targeting and diamond-type rules (CP 55).
 *
 * Test intent: ads.json candidates match site/country/diamond_type from page
 *              context — not hardcoded to a single site.
 * Would fail if: UK-targeted ad rendered on US page because country filter broke.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-ad-system.php
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
define('LDN_PLUGIN_DIR', dirname(__DIR__) . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);

$GLOBALS['ldn_transients'] = array();

function get_transient($key) {
    return isset($GLOBALS['ldn_transients'][$key]) ? $GLOBALS['ldn_transients'][$key] : false;
}
function set_transient($key, $value, $ttl) {
    $GLOBALS['ldn_transients'][$key] = $value;
}
function delete_transient($key) {
    unset($GLOBALS['ldn_transients'][$key]);
}
function wp_remote_get($url, $args = array()) {
    return $GLOBALS['ldn_ads_remote_response'];
}
function is_wp_error($thing) {
    return $thing instanceof LDN_Test_WP_Error;
}
function wp_remote_retrieve_response_code($response) {
    return isset($response['code']) ? $response['code'] : 0;
}
function wp_remote_retrieve_body($response) {
    return isset($response['body']) ? $response['body'] : '';
}
function wp_remote_post($url, $args = array()) {
    $GLOBALS['ldn_track_posts'][] = array('url' => $url, 'args' => $args);
    return array('code' => 200);
}
function wp_json_encode($data) {
    return json_encode($data);
}
function home_url($path = '') {
    return 'https://ringspo.com' . $path;
}
function esc_attr($text) {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
function esc_url($url) {
    return (string) $url;
}
function esc_html($text) {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
function esc_html__($text, $domain = 'default') {
    return $text;
}
function apply_filters($hook, $value) {
    return $value;
}

class LDN_Test_WP_Error {
    public $message;
    public function __construct($message) {
        $this->message = $message;
    }
    public function get_error_message() {
        return $this->message;
    }
}

class LDN_Plugin {
    public static function instance() {
        return new self();
    }
    public function ad_system() {
        return null;
    }
    public static function debug_log($component, $message) {}
}

class LDN_Environment {
    public static function is_production() {
        return isset($GLOBALS['ldn_is_production']) ? $GLOBALS['ldn_is_production'] : true;
    }
}

require_once dirname(__DIR__) . '/includes/class-ldn-page-context.php';
require_once dirname(__DIR__) . '/includes/class-ldn-config.php';
require_once dirname(__DIR__) . '/includes/class-ldn-ad-manifest.php';
require_once dirname(__DIR__) . '/includes/class-ldn-ad-resolver.php';
require_once dirname(__DIR__) . '/includes/class-ldn-ad-tracker.php';
require_once dirname(__DIR__) . '/includes/class-ldn-ad-system.php';

function check($condition, $label) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "OK: {$label}\n";
}

/**
 * Inject bundle into LDN_Config singleton for isolated tests.
 *
 * @param array $overrides Keys merged onto the deployed config bundle.
 * @return LDN_Config
 */
function ldn_test_config_with_bundle(array $overrides) {
    $path = LDN_PLUGIN_DIR . 'data/config-bundle.json';
    $raw = file_get_contents($path);
    $bundle = is_string($raw) ? json_decode($raw, true) : array();
    if (!is_array($bundle)) {
        $bundle = array();
    }
    $bundle = array_merge($bundle, $overrides);
    $bundle['_mtime'] = time();

    $config = LDN_Config::instance();
    $ref = new ReflectionClass($config);
    $prop = $ref->getProperty('bundle');
    $prop->setAccessible(true);
    $prop->setValue($config, $bundle);

    return $config;
}

$sample_manifest = array(
    'advertisers' => array(
        'blue-nile' => array(
            'name' => 'Blue Nile',
            'click_url' => 'https://example.com/bn?aff=1',
            'ads' => array(
                'us-natural' => array(
                    'name' => 'BN US Natural',
                    'slot_type' => 'retailer',
                    'diamond_type' => 'natural',
                    'active' => true,
                    'images' => array('horizontal' => 'https://cdn.example/bn.jpg'),
                    'targeting' => array(
                        'sites' => array('ringspo'),
                        'countries' => array('us'),
                        'page_types' => array('natural'),
                    ),
                    'slots' => array('horizontal_mid_content'),
                    'priority' => 20,
                ),
            ),
        ),
        'uk-jeweller' => array(
            'name' => 'UK Jeweller',
            'click_url' => 'https://example.com/uk',
            'ads' => array(
                'uk-natural' => array(
                    'name' => 'UK Natural',
                    'slot_type' => 'retailer',
                    'diamond_type' => 'natural',
                    'active' => true,
                    'images' => array('horizontal' => 'https://cdn.example/uk.jpg'),
                    'targeting' => array(
                        'sites' => array('ringspo'),
                        'countries' => array('uk'),
                        'page_types' => array('natural'),
                    ),
                    'slots' => array('horizontal_mid_content'),
                    'priority' => 10,
                ),
            ),
        ),
    ),
);

$GLOBALS['ldn_ads_remote_response'] = array(
    'code' => 200,
    'body' => json_encode($sample_manifest),
);

$config = ldn_test_config_with_bundle(array(
    'network_consumer' => array(
        'ads' => array(
            'manifest_url' => 'https://loupemedia-ads.s3.amazonaws.com/ads.json',
            'track_base_url' => 'https://loupemedianetwork.com/wp-json/ldn-ops/v1',
        ),
    ),
));

$manifest = new LDN_Ad_Manifest($config);
$resolver = new LDN_Ad_Resolver($manifest, $config);

$us_ctx = new LDN_Page_Context('ringspo', 'shape', 'us', 'natural', '1', 'round');
$uk_ctx = new LDN_Page_Context('ringspo', 'shape', 'uk', 'natural', '1', 'round');

$us_resolved = $resolver->resolve_for_layout_slot($us_ctx, 'horizontal_mid_content');
check(count($us_resolved) === 1, 'US resolves one ad');
check($us_resolved[0]['ad']['advertiser_slug'] === 'blue-nile', 'US gets US-targeted advertiser');

$uk_resolved = $resolver->resolve_for_layout_slot($uk_ctx, 'horizontal_mid_content');
check(count($uk_resolved) === 1, 'UK resolves one ad');
check($uk_resolved[0]['ad']['advertiser_slug'] === 'uk-jeweller', 'UK gets UK-targeted advertiser');

$GLOBALS['ldn_is_production'] = true;
$GLOBALS['ldn_track_posts'] = array();
$ad_system = new LDN_Ad_System($config, $manifest);
$html = $ad_system->render_layout_slot($us_ctx, 'horizontal_mid_content');
check(strpos($html, 'blue-nile/us-natural') !== false, 'rendered HTML includes ad id');
check(strpos($html, 'data-ldn-ad-track') !== false, 'rendered HTML includes track marker');
check(count($GLOBALS['ldn_track_posts']) === 1, 'impression tracked on production');

$GLOBALS['ldn_is_production'] = false;
$GLOBALS['ldn_track_posts'] = array();
$ad_system->render_layout_slot($us_ctx, 'horizontal_mid_content');
check(count($GLOBALS['ldn_track_posts']) === 0, 'staging suppresses impression tracking');

echo "All ad system tests passed.\n";

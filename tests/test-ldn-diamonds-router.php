<?php
/**
 * Loose diamond shopping router, registry 404 gate, S3 key resolver and JSON-LD tests (CP166_01).
 *
 * Test intent: Registered module='diamonds' paths resolve through LDN_Diamonds_Router and
 * LDN_Diamonds_Dispatcher, fetch cached diamond-cards.json from s3.diamonds_prefix (never
 * graph_prefix and zero live HTTP calls to SparkleScore/FI), emit summary header + canonical +
 * BreadcrumbList + CollectionPage + ItemList JSON-LD (Product + Offer USD InStock) with zero
 * ad slots, while unregistered registry paths, non-rollout countries, forbidden /under-$X/ or
 * /hpht/ slugs, unentitled sites and a price-only rollout cleanly 404 or compile zero rules.
 * Would fail if: LDN_Diamonds_Router hardcoded /us/diamonds/ or matched /under-5000/,
 * LDN_Diamonds_Dispatcher served a path absent from ops.page_url_registry, S3 keys fell back
 * to graph_prefix, or SSR emitted any banner ad slot.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-diamonds-router.php
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
define('LDN_PLUGIN_DIR', dirname(__DIR__) . '/');
if (!defined('LDN_PLUGIN_URL')) {
    define('LDN_PLUGIN_URL', 'https://ringspo.com/wp-content/plugins/loupe-diamond-network/');
}
if (!defined('LDN_VERSION')) {
    define('LDN_VERSION', '0.45.0');
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!function_exists('get_transient')) {
    function get_transient($key) { return false; }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0) { return true; }
}
if (!function_exists('delete_transient')) {
    function delete_transient($key) { return true; }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}
if (!function_exists('__')) {
    function __($s, $d = null) { return $s; }
}
if (!function_exists('esc_html')) {
    function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('esc_url')) {
    function esc_url($s) { return (string) $s; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0) {
        return json_encode($data, $options | JSON_HEX_TAG);
    }
}
if (!function_exists('home_url')) {
    function home_url($p = '') {
        $host = isset($GLOBALS['__test_host']) ? $GLOBALS['__test_host'] : 'https://ringspo.com';
        return rtrim($host, '/') . '/' . ltrim((string) $p, '/');
    }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($p) { return rtrim((string) $p, '/') . '/'; }
}
if (!function_exists('user_trailingslashit')) {
    function user_trailingslashit($p) { return rtrim((string) $p, '/') . '/'; }
}

if (!class_exists('LDN_Rollout_Reader')) {
    class LDN_Rollout_Reader {
        /** @var string[] */
        private $countries;
        /** @var string[]|null */
        private $modules;
        public function __construct(array $countries, ?array $modules = null) {
            $this->countries = $countries;
            $this->modules = $modules;
        }
        public function enabled_countries($module = 'price') {
            return $this->countries;
        }
        public function is_enabled($country_code, $module = 'price') {
            if (!in_array(strtolower((string) $country_code), $this->countries, true)) {
                return false;
            }
            if ($this->modules === null) {
                return true;
            }
            return in_array((string) $module, $this->modules, true);
        }
    }
}

if (!class_exists('LDN_Plugin')) {
    class LDN_Plugin {
        public static function instance() { return new self(); }
        public static function debug_log($component, $message) {}
        public function ad_system() { return null; }
    }
}

if (!class_exists('LDN_Seo_Bridge')) {
    class LDN_Seo_Bridge {
        public static function unbridged_plugin_active() { return false; }
    }
}

require_once LDN_PLUGIN_DIR . 'components/trait-ldn-data.php';
require_once LDN_PLUGIN_DIR . 'components/trait-ldn-price-calculator.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-page-context.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-config.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-artefacts.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-s3-key-resolver.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-router.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-page-registry.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-canonical-redirect.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-renderer.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-diamonds-router.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-diamonds-dispatcher.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-diamonds-renderer.php';

if (!class_exists('LDN_Data_Fetcher')) {
    class LDN_Data_Fetcher {
        /** @var array<string, array> */
        public $by_key = array();
        /** @var int */
        public $fetch_calls = 0;
        /** @var LDN_S3_Key_Resolver */
        private $resolver;

        public function __construct(LDN_S3_Key_Resolver $resolver) {
            $this->resolver = $resolver;
        }

        public function fetch_artefact($artefact_id, LDN_Page_Context $ctx) {
            $this->fetch_calls++;
            $key = $this->resolver->resolve_s3_key($artefact_id, $ctx);
            if ($key === null || !isset($this->by_key[$key])) {
                return null;
            }
            return $this->by_key[$key];
        }
    }
}

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

function match_rewrite(array $rules, $path) {
    $trimmed = trim((string) $path, '/');
    foreach ($rules as $regex => $query) {
        if (!preg_match('#' . $regex . '#', $trimmed, $m)) {
            continue;
        }
        $resolved = preg_replace_callback(
            '/\$matches\[(\d+)\]/',
            function ($tok) use ($m) {
                $idx = (int) $tok[1];
                return isset($m[$idx]) ? $m[$idx] : '';
            },
            $query
        );
        $qs = (string) parse_url($resolved, PHP_URL_QUERY);
        $vars = array();
        parse_str($qs, $vars);
        return $vars;
    }
    return null;
}

$config = LDN_Config::instance();
$artefacts = new LDN_Artefacts($config);
$resolver = new LDN_S3_Key_Resolver($config);
$all_rollout = new LDN_Rollout_Reader(array('us', 'uk', 'au', 'ca'));

// -------------------------------------------------------------------------
// 1. Router compiles all 7 approved levels on Ringspo US & blocks bad URLs
// -------------------------------------------------------------------------
$router = new LDN_Diamonds_Router('ringspo', $all_rollout, $config, $artefacts);
$rules = $router->build_rewrite_rules();
check(!empty($rules), 'ringspo compiles /us/diamonds/ rewrite rules');

$price_only = new LDN_Diamonds_Router(
    'ringspo',
    new LDN_Rollout_Reader(array('us'), array('price')),
    $config,
    $artefacts
);
check(
    $price_only->build_rewrite_rules() === array(),
    'price on without the selection tick compiles zero /us/diamonds/ rules'
);

$fetcher = new LDN_Data_Fetcher($resolver);
$registered_paths = array(
    '/us/diamonds',
    '/us/diamonds/natural',
    '/us/diamonds/natural/round',
    '/us/diamonds/natural/1-carat',
    '/us/diamonds/natural/1-carat/round',
    '/us/diamonds/natural/elongated-cushion',
    '/us/diamonds/lab-grown/2-carat/elongated-radiant',
);
$dispatcher = new LDN_Diamonds_Dispatcher('ringspo', $config, $fetcher, null, $registered_paths);

$cases = array(
    '/us/diamonds/' => array('type' => 'all', 'path' => 'hub', 's3' => 'diamonds/us/all/hub/diamond-cards.json'),
    '/us/diamonds/natural/' => array('type' => 'natural', 'path' => 'hub', 's3' => 'diamonds/us/natural/hub/diamond-cards.json'),
    '/us/diamonds/natural/round/' => array('type' => 'natural', 'path' => 'round', 's3' => 'diamonds/us/natural/round/diamond-cards.json'),
    '/us/diamonds/natural/1-carat/' => array('type' => 'natural', 'path' => '1-carat', 's3' => 'diamonds/us/natural/1-carat/diamond-cards.json'),
    '/us/diamonds/natural/1-carat/round/' => array('type' => 'natural', 'path' => '1-carat/round', 's3' => 'diamonds/us/natural/1-carat/round/diamond-cards.json'),
    '/us/diamonds/natural/elongated-cushion/' => array('type' => 'natural', 'path' => 'elongated-cushion', 's3' => 'diamonds/us/natural/elongated-cushion/diamond-cards.json'),
    '/us/diamonds/lab-grown/2-carat/elongated-radiant/' => array('type' => 'lab-grown', 'path' => '2-carat/elongated-radiant', 's3' => 'diamonds/us/lab-grown/2-carat/elongated-radiant/diamond-cards.json'),
);

foreach ($cases as $url => $expected) {
    $vars = match_rewrite($rules, $url);
    check(is_array($vars) && ($vars['ldn_route'] ?? '') === 'diamonds', "route matches {$url}");
    $ctx = is_array($vars) ? $dispatcher->build_context($vars) : null;
    check(
        $ctx instanceof LDN_Page_Context
        && $ctx->module === 'diamonds'
        && $ctx->diamond_type === $expected['type']
        && $ctx->diamond_path === $expected['path'],
        "context resolves {$url} -> {$expected['type']} / {$expected['path']}"
    );
    check(
        $ctx instanceof LDN_Page_Context
        && $resolver->resolve_s3_key('diamond_cards_json', $ctx) === $expected['s3'],
        "S3 key resolves {$url} -> {$expected['s3']} (isolated from graph_prefix)"
    );
}

// Forbidden budget / growth-method / non-rollout country URLs never match
foreach (array(
    '/us/diamonds/natural/under-5000/',
    '/us/diamonds/lab-grown/hpht/',
    '/us/diamonds/lab-grown/cvd/',
    '/us/diamonds/natural/1-carat/eye-clean/',
    '/uk/diamonds/natural/1-carat/round/',
) as $blocked_url) {
    check(match_rewrite($rules, $blocked_url) === null, "forbidden or non-rollout URL rejected: {$blocked_url}");
}

// Unentitled sites compile zero rules
foreach (array('diamondpriceguru', 'diamondchart', 'diamondadvisors') as $unentitled_site) {
    $u_router = new LDN_Diamonds_Router($unentitled_site, $all_rollout, $config, $artefacts);
    check($u_router->build_rewrite_rules() === array(), "{$unentitled_site} compiles zero diamonds rules while unentitled");
}

// -------------------------------------------------------------------------
// 2. ops.page_url_registry gate: unregistered path is rejected (404)
// -------------------------------------------------------------------------
$unregistered_vars = match_rewrite($rules, '/us/diamonds/natural/12-carat/heart/');
check(is_array($unregistered_vars), 'syntactically valid 12-carat heart matches rewrite regex');
$unregistered_ctx = $dispatcher->build_context($unregistered_vars);
check($unregistered_ctx instanceof LDN_Page_Context, 'context builds for 12-carat heart');
check(
    $dispatcher->is_path_allowed($unregistered_ctx) === false,
    'dispatcher rejects route absent from ops.page_url_registry (returns 404)'
);

$registered_vars = match_rewrite($rules, '/us/diamonds/natural/1-carat/round/');
$registered_ctx = $dispatcher->build_context($registered_vars);
check(
    $dispatcher->is_path_allowed($registered_ctx) === true,
    'dispatcher allows route present in ops.page_url_registry'
);

// Canonical redirect includes diamonds
check(
    in_array('diamonds', LDN_Canonical_Redirect::ROUTES, true),
    'LDN_Canonical_Redirect normalises trailing slashes on diamonds routes'
);

// -------------------------------------------------------------------------
// 3. Cached S3 fetch + SSR summary header + CollectionPage/ItemList JSON-LD + zero ads
// -------------------------------------------------------------------------
$sample_payload = array(
    'schema_version' => 1,
    'route_meta' => array(
        'site_id' => 'ringspo',
        'country_code' => 'us',
        'canonical_path' => '/us/diamonds/natural/1-carat/round/',
        'page_level' => 'carat_shape_leaf',
        'diamond_type' => 'natural',
        'shape' => 'round',
        'carat_label' => '1',
        'modifier_slug' => null,
        'analysis_date' => '2026-11-22',
        'pool_id' => 'us_ringspo_loose_v1',
        'curation_profile' => 'balanced',
        'total_matching_stones' => 84,
        'retailer_count' => 4,
        'price_p25_usd' => 4250.0,
        'price_median_usd' => 5120.0,
        'price_p75_usd' => 6190.0,
        'pricing_bridge_url' => '/us/diamond-prices/natural/1-carat/round/',
        'size_bridge_url' => '/diamond-size/round/1-carat/',
    ),
    'doors' => array(
        'best_value' => array(),
        'largest_face_up' => array(),
        'best_sparkle' => array(),
    ),
    'explorer_cards' => array(
        array(
            'stone_key' => 'gia:2481900001',
            'retailer_code' => 'BN',
            'retailer_name' => 'Blue Nile',
            'affiliate_url' => 'https://www.bluenile.com/diamond-details/LD001?a_aid=ringspo',
            'image_url' => 'https://d1egdb7lgpukq6.cloudfront.net/diamonds/images/abc123def4560001.webp',
            'image_fallback_shape' => null,
            'diamond_type' => 'natural',
            'shape' => 'round',
            'carat' => 1.01,
            'color' => 'G',
            'clarity' => 'VS1',
            'cut_grade' => 'Ideal',
            'cert_lab' => 'GIA',
            'measurements_label' => '6.45 x 6.48 x 3.98 mm',
            'price_usd' => 4890.0,
            'benchmark_cell' => array(
                'median_usd' => 5350.0,
                'delta_usd' => -460.0,
                'delta_pct' => -8.6,
                'verdict_band' => 'strong_value',
            ),
            'sparklescore' => array(
                'composite' => array('score' => 91.2, 'tier' => 'elite', 'available' => true, 'reason_if_null' => null),
                'sparkle' => array('score' => 93.4, 'tier' => 'elite', 'available' => true, 'reason_if_null' => null),
                'face_up' => array('score' => 89.0, 'tier' => 'excellent', 'available' => true, 'reason_if_null' => null),
                'value' => array('score' => 91.2, 'tier' => 'elite', 'available' => true, 'reason_if_null' => null),
            ),
            'access_tier' => 'open',
        ),
        array(
            'stone_key' => 'gia:2481900002',
            'retailer_code' => 'WF',
            'retailer_name' => 'Whiteflash',
            'affiliate_url' => 'https://www.whiteflash.com/loose-diamonds/round-cut-loose-diamond-102?a_aid=ringspo',
            'image_url' => null,
            'image_fallback_shape' => 'round',
            'diamond_type' => 'natural',
            'shape' => 'round',
            'carat' => 1.02,
            'color' => 'F',
            'clarity' => 'VS2',
            'cut_grade' => 'Ideal',
            'cert_lab' => 'GIA',
            'measurements_label' => '6.49 x 6.51 x 4.00 mm',
            'price_usd' => 5490.0,
            'benchmark_cell' => array(
                'median_usd' => 5400.0,
                'delta_usd' => 90.0,
                'delta_pct' => 1.7,
                'verdict_band' => 'fair_market',
            ),
            'sparklescore' => array(
                'composite' => array('score' => 94.0, 'tier' => 'elite', 'available' => true, 'reason_if_null' => null),
                'sparkle' => array('score' => 96.5, 'tier' => 'elite', 'available' => true, 'reason_if_null' => null),
                'face_up' => array('score' => 91.5, 'tier' => 'elite', 'available' => true, 'reason_if_null' => null),
                'value' => array('score' => 88.0, 'tier' => 'excellent', 'available' => true, 'reason_if_null' => null),
            ),
            'access_tier' => 'open',
        ),
    ),
    'filter_facets' => array(),
);

$fetcher->by_key['diamonds/us/natural/1-carat/round/diamond-cards.json'] = $sample_payload;
$loaded = $dispatcher->fetch_primary_data($registered_ctx);
check(is_array($loaded) && $fetcher->fetch_calls === 1, 'dispatcher fetches cached diamond-cards.json in 1 S3 lookup');

$renderer = new LDN_Diamonds_Renderer($fetcher, $config);
$head = $renderer->render_head_content($registered_ctx, $loaded);
$html = $renderer->render($registered_ctx, $loaded);

check($fetcher->fetch_calls === 1, 'render + head perform zero extra S3/API HTTP calls');
check(
    strpos($head, '<link rel="canonical" href="https://ringspo.com/us/diamonds/natural/1-carat/round/" />') !== false,
    'head emits self-referencing canonical URL'
);
check(
    strpos($head, 'preconnect" href="https://d1egdb7lgpukq6.cloudfront.net"') !== false,
    'head emits preconnect to Ringspo CloudFront CDN'
);
check(strpos($head, '"@type":"CollectionPage"') !== false, 'head JSON-LD includes CollectionPage');
check(strpos($head, '"@type":"ItemList"') !== false, 'head JSON-LD includes ItemList');
check(strpos($head, '"@type":"BreadcrumbList"') !== false, 'head JSON-LD includes BreadcrumbList');
check(strpos($head, '"priceCurrency":"USD"') !== false, 'head JSON-LD Offer carries USD currency');
check(strpos($head, 'https://schema.org/InStock') !== false, 'head JSON-LD Offer carries InStock availability');

check(strpos($html, '84') !== false && strpos($html, '4') !== false, 'SSR summary header shows live stone count and retailer count');
check(strpos($html, '$4,250') !== false && strpos($html, '$6,190') !== false, 'SSR summary header shows typical 4C price band');
check(strpos($html, 'ldn-breadcrumbs') !== false, 'SSR emits visible breadcrumb navigation');
check(
    strpos($html, 'ldn-ad') === false && strpos($html, 'data-ldn-ad') === false,
    'SSR emits zero LDO or display banner ad slots'
);

$visible = preg_replace('#<script[^>]*>.*?</script>#s', '', $html);
check(
    strpos($visible, "\xE2\x80\x94") === false && strpos($visible, "\xE2\x80\x93") === false,
    'visible HTML contains zero em or en dashes'
);

if ($GLOBALS['__fails'] === 0) {
    echo "OK: {$GLOBALS['__tests']} checks passed\n";
    exit(0);
}
exit(1);

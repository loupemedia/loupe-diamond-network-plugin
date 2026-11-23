<?php
/**
 * Jewellery router, S3 key resolver and US rollout gating tests (CP135).
 *
 * Test intent: US natural and lab-grown jewellery paths resolve through LDN_Jewellery_Router
 * and LDN_Jewellery_Dispatcher without colliding with Diamond Advisors flat_leaf loose-diamond
 * routes, while non-US countries, unentitled sites and a price-only rollout compile zero jewellery rewrite rules.
 * Would fail if: lab-grown rules registered after natural rules, category regex matched
 * loose-diamond slugs (/us/round-cut/, /us/1-carat/), or non-US countries matched in v1.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-jewellery-router.php
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
define('LDN_PLUGIN_DIR', dirname(__DIR__) . '/');
if (!defined('LDN_VERSION')) {
    define('LDN_VERSION', '0.44.0');
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
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}
if (!function_exists('home_url')) {
    function home_url($p = '') { return 'https://example.test/' . ltrim((string) $p, '/'); }
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

require_once LDN_PLUGIN_DIR . 'includes/class-ldn-page-context.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-config.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-artefacts.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-s3-key-resolver.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-router.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-jewellery-router.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-jewellery-dispatcher.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-page-registry.php';

if (!class_exists('LDN_Data_Fetcher')) {
    class LDN_Data_Fetcher {
        public function fetch_artefact($artefact_id, $ctx = null) { return null; }
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
$all_rollout = new LDN_Rollout_Reader(array('us', 'uk', 'au', 'ca'));

// -------------------------------------------------------------------------
// 1. Ringspo US natural + lab-grown + non-US gating
// -------------------------------------------------------------------------
$rs_jwl_router = new LDN_Jewellery_Router('ringspo', $all_rollout, $config, $artefacts);
$rs_rules = $rs_jwl_router->build_rewrite_rules();
$rs_dispatcher = new LDN_Jewellery_Dispatcher('ringspo', $config, new LDN_Data_Fetcher());

check(!empty($rs_rules), 'ringspo compiles jewellery rewrite rules');

$price_only = new LDN_Jewellery_Router(
    'ringspo',
    new LDN_Rollout_Reader(array('us'), array('price')),
    $config,
    $artefacts
);
check(
    $price_only->build_rewrite_rules() === array(),
    'price on without the jewellery tick compiles zero jewellery rules'
);
$jewellery_on = new LDN_Jewellery_Router(
    'ringspo',
    new LDN_Rollout_Reader(array('us'), array('jewellery')),
    $config,
    $artefacts
);
check(!empty($jewellery_on->build_rewrite_rules()), 'jewellery tick compiles ringspo rules');

$rs_hub_vars = match_rewrite($rs_rules, '/us/diamond-jewelry-prices/');
$rs_hub_ctx = is_array($rs_hub_vars) ? $rs_dispatcher->build_context($rs_hub_vars) : null;
check(
    $rs_hub_ctx !== null
    && $rs_hub_ctx->module === 'jewellery'
    && $rs_hub_ctx->country_code === 'us'
    && $rs_hub_ctx->diamond_type === 'natural'
    && $rs_hub_ctx->jewelry_path === 'hub',
    'ringspo /us/diamond-jewelry-prices/ resolves to natural hub context'
);

$rs_lab_hub_vars = match_rewrite($rs_rules, '/us/diamond-jewelry-prices/lab-grown/');
$rs_lab_hub_ctx = is_array($rs_lab_hub_vars) ? $rs_dispatcher->build_context($rs_lab_hub_vars) : null;
check(
    $rs_lab_hub_ctx !== null
    && $rs_lab_hub_ctx->diamond_type === 'lab-grown'
    && $rs_lab_hub_ctx->jewelry_path === 'hub',
    'ringspo /us/diamond-jewelry-prices/lab-grown/ resolves to lab-grown hub (not subpath=lab-grown)'
);

$rs_studs_vars = match_rewrite($rs_rules, '/us/diamond-jewelry-prices/earrings/studs/1-carat/');
$rs_studs_ctx = is_array($rs_studs_vars) ? $rs_dispatcher->build_context($rs_studs_vars) : null;
check(
    $rs_studs_ctx !== null
    && $rs_studs_ctx->diamond_type === 'natural'
    && $rs_studs_ctx->jewelry_path === 'earrings/studs/1-carat',
    'ringspo /us/diamond-jewelry-prices/earrings/studs/1-carat/ resolves to natural leaf'
);

$rs_lab_studs_vars = match_rewrite($rs_rules, '/us/diamond-jewelry-prices/lab-grown/earrings/studs/1-carat/');
$rs_lab_studs_ctx = is_array($rs_lab_studs_vars) ? $rs_dispatcher->build_context($rs_lab_studs_vars) : null;
check(
    $rs_lab_studs_ctx !== null
    && $rs_lab_studs_ctx->diamond_type === 'lab-grown'
    && $rs_lab_studs_ctx->jewelry_path === 'earrings/studs/1-carat',
    'ringspo /us/diamond-jewelry-prices/lab-grown/earrings/studs/1-carat/ resolves to lab-grown leaf'
);

$rs_uk = match_rewrite($rs_rules, '/uk/diamond-jewelry-prices/earrings/studs/1-carat/');
check($rs_uk === null, 'ringspo UK path does not match jewellery rules in US-only v1');

// -------------------------------------------------------------------------
// 2. Diamond Advisors jewellery + zero collision with flat_leaf loose-diamond routes
// -------------------------------------------------------------------------
$da_jwl_router = new LDN_Jewellery_Router('diamondadvisors', $all_rollout, $config, $artefacts);
$da_price_router = new LDN_Router('diamondadvisors', $all_rollout, $config);
$da_dispatcher = new LDN_Jewellery_Dispatcher('diamondadvisors', $config, new LDN_Data_Fetcher());
$da_combined_rules = $da_jwl_router->build_rewrite_rules() + $da_price_router->build_rewrite_rules();

$da_hub_vars = match_rewrite($da_combined_rules, '/us/jewelry/');
$da_hub_ctx = is_array($da_hub_vars) && ($da_hub_vars['ldn_route'] ?? '') === 'jewellery'
    ? $da_dispatcher->build_context($da_hub_vars)
    : null;
check(
    $da_hub_ctx !== null
    && $da_hub_ctx->diamond_type === 'natural'
    && $da_hub_ctx->jewelry_path === 'hub',
    'diamondadvisors /us/jewelry/ resolves to jewellery natural hub ahead of flat_leaf'
);

$da_lab_hub_vars = match_rewrite($da_combined_rules, '/us/lab-grown/jewelry/');
$da_lab_hub_ctx = is_array($da_lab_hub_vars) && ($da_lab_hub_vars['ldn_route'] ?? '') === 'jewellery'
    ? $da_dispatcher->build_context($da_lab_hub_vars)
    : null;
check(
    $da_lab_hub_ctx !== null
    && $da_lab_hub_ctx->diamond_type === 'lab-grown'
    && $da_lab_hub_ctx->jewelry_path === 'hub',
    'diamondadvisors /us/lab-grown/jewelry/ resolves to jewellery lab-grown hub'
);

$da_tennis_vars = match_rewrite($da_combined_rules, '/us/bracelets/tennis/3-carat/');
$da_tennis_ctx = is_array($da_tennis_vars) && ($da_tennis_vars['ldn_route'] ?? '') === 'jewellery'
    ? $da_dispatcher->build_context($da_tennis_vars)
    : null;
check(
    $da_tennis_ctx !== null
    && $da_tennis_ctx->diamond_type === 'natural'
    && $da_tennis_ctx->jewelry_path === 'bracelets/tennis/3-carat',
    'diamondadvisors /us/bracelets/tennis/3-carat/ resolves to jewellery natural leaf'
);

$da_lab_tennis_vars = match_rewrite($da_combined_rules, '/us/lab-grown/bracelets/tennis/3-carat/');
$da_lab_tennis_ctx = is_array($da_lab_tennis_vars) && ($da_lab_tennis_vars['ldn_route'] ?? '') === 'jewellery'
    ? $da_dispatcher->build_context($da_lab_tennis_vars)
    : null;
check(
    $da_lab_tennis_ctx !== null
    && $da_lab_tennis_ctx->diamond_type === 'lab-grown'
    && $da_lab_tennis_ctx->jewelry_path === 'bracelets/tennis/3-carat',
    'diamondadvisors /us/lab-grown/bracelets/tennis/3-carat/ resolves to jewellery lab-grown leaf'
);

foreach (array(
    '/us/diamonds/',
    '/us/round-cut/',
    '/us/1-carat/',
    '/us/1-carat-natural-round-cut-diamond-price/',
) as $loose_path) {
    $matched = match_rewrite($da_combined_rules, $loose_path);
    check(
        is_array($matched) && ($matched['ldn_route'] ?? 'price') !== 'jewellery',
        "diamondadvisors loose-diamond path {$loose_path} still routes to price module"
    );
}

$da_au = match_rewrite($da_jwl_router->build_rewrite_rules(), '/au/earrings/studs/1-carat/');
check($da_au === null, 'diamondadvisors AU path does not match jewellery rules in US-only v1');

// -------------------------------------------------------------------------
// 3. Unentitled sites emit zero rules
// -------------------------------------------------------------------------
foreach (array('diamondpriceguru', 'diamondchart') as $unentitled_site) {
    $u_router = new LDN_Jewellery_Router($unentitled_site, $all_rollout, $config, $artefacts);
    check($u_router->build_rewrite_rules() === array(), "{$unentitled_site} compiles zero jewellery rules");
}

// -------------------------------------------------------------------------
// 4. Dispatcher rollout refusal + S3 key resolver + sitemap paths
// -------------------------------------------------------------------------
$ctx_bad = $rs_dispatcher->build_context(array(
    'ldn_country' => 'uk',
    'ldn_jewellery_type' => 'natural',
    'ldn_jewellery_kind' => 'hub',
));
check($ctx_bad === null, 'dispatcher refuses non-rollout country uk');

$resolver = new LDN_S3_Key_Resolver($config);
check(
    $resolver->resolve_s3_key('jewellery_summary_json', $rs_hub_ctx) === 'jewellery/us/natural/hub/jewellery-summary.json',
    'S3 key resolver formats hub summary key'
);
check(
    $resolver->resolve_s3_key('jewellery_explorer_json', $rs_studs_ctx) === 'jewellery/us/natural/earrings/studs/1-carat/jewellery-explorer.json',
    'S3 key resolver formats natural leaf explorer key'
);
check(
    $resolver->resolve_s3_key('jewellery_summary_json', $rs_lab_studs_ctx) === 'jewellery/us/lab-grown/earrings/studs/1-carat/jewellery-summary.json',
    'S3 key resolver formats lab-grown leaf summary key'
);

check(
    $config->jewellery_sitemap_path('ringspo') === '/{country}/diamond-jewelry-prices/sitemap.xml',
    'ringspo jewellery sitemap path matches url_structures.yaml'
);
check(
    $config->jewellery_sitemap_path('diamondadvisors') === '/{country}/jewelry/sitemap.xml',
    'diamondadvisors jewellery sitemap path matches url_structures.yaml'
);
check(
    LDN_Page_Registry::JEWELLERY_SITEMAP_MODULES === array('jewellery'),
    'page registry scopes jewellery sitemap to module=jewellery'
);

if ($GLOBALS['__fails'] === 0) {
    echo "OK: {$GLOBALS['__tests']} checks passed\n";
    exit(0);
}
exit(1);

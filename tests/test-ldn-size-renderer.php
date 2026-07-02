<?php
/**
 * Size renderer unit checks (CP106).
 *
 * Test intent: size individual pages emit a four-level breadcrumb trail, adjacent
 * carat neighbours from CARAT_BANDS, and JSON-LD containing Dataset + FAQPage nodes.
 * Would fail if: breadcrumb omitted the shape hub level or JSON-LD was title-only.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-size-renderer.php
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');

if (!function_exists('__')) {
    function __($s, $d = null) { return $s; }
}
if (!function_exists('esc_html')) {
    function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__($s, $d = null) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('esc_html__')) {
    function esc_html__($s, $d = null) { return htmlspecialchars((string) $s, ENT_QUOTES); }
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
    function home_url($p = '') { return 'https://ringspo.test/' . ltrim((string) $p, '/'); }
}
if (!function_exists('user_trailingslashit')) {
    function user_trailingslashit($p) { return rtrim((string) $p, '/') . '/'; }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($p) { return rtrim((string) $p, '/') . '/'; }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}

require_once __DIR__ . '/../includes/class-ldn-page-context.php';
require_once __DIR__ . '/../includes/class-ldn-test-combos.php';

if (!class_exists('LDN_Data_Fetcher')) {
    class LDN_Data_Fetcher {
        public function fetch_artefact($id, $ctx) {
            return array();
        }
    }
}
if (!class_exists('LDN_Config')) {
    class LDN_Config {
        public function get_content_profile($site_id) {
            return array('schema_features' => array('faq', 'breadcrumb'));
        }
        public function get_url_structure($site_id) {
            return array(
                'size_level_1' => '/diamond-size',
                'size_level_2' => '/diamond-size/{shape}',
                'size_level_3' => '/diamond-size/{shape}/{carat}',
            );
        }
        public function shape_to_s3_slug($shape) {
            $map = array('emerald' => 'emerald-cut', 'asscher' => 'asscher-cut', 'princess' => 'princess-cut');
            $key = strtolower(trim((string) $shape));
            return $map[$key] ?? str_replace(' ', '-', $key);
        }
        public function slug_to_shape($slug, $site_id) {
            return str_replace('-', ' ', strtolower((string) $slug));
        }
        public function get_site($site_id) {
            return array('domain' => 'ringspo.com', 'brand_name' => 'Ringspo');
        }
    }
}

require_once __DIR__ . '/../includes/class-ldn-schema.php';
require_once __DIR__ . '/../includes/class-ldn-size-renderer.php';

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$config = new LDN_Config();
$renderer = new LDN_Size_Renderer(new LDN_Data_Fetcher(), $config);

$ctx = new LDN_Page_Context('ringspo', 'size-individual', 'us', null, '1', 'round', 'size');
$summary = array(
    'shape' => 'round',
    'carat_band' => '1',
    'source' => 'real',
    'n' => 12000,
    'retailer_count' => 3,
    'pct_excluded' => 2.1,
    'depth_faceup_corr' => -0.42,
    'dimensions_mm' => array(
        'length' => array('median' => 6.37, 'p10' => 6.2, 'p90' => 6.6),
        'width' => array('median' => 6.41, 'p10' => 6.2, 'p90' => 6.6),
    ),
    'faceup_area_mm2' => array('median' => 32.07, 'p10' => 31.0, 'p90' => 34.2),
    'lw_ratio' => array('median' => 0.994, 'p25' => 0.99, 'p75' => 1.01),
    'depth_percent' => array('mean' => 61.5),
    'ideal' => array(
        'faceup_area_mm2' => 33.18,
        'length_mm' => 6.5,
        'width_mm' => 6.5,
    ),
);

$canonical = $renderer->build_size_individual_url('ringspo', 'round', '1');
$trail = $renderer->breadcrumb_trail($ctx, $canonical);
check(count($trail) === 4, 'individual breadcrumb has Home + Diamond Size + shape + carat');
check(strpos($trail[1]['name'], 'Diamond Size') !== false, 'breadcrumb level 2 is Diamond Size');

$adj = $renderer->adjacent_carat_bands('1');
check($adj === array('0.9', '1.5'), 'adjacent carats for 1ct are 0.9 and 1.5');

$comps = $renderer->comparison_link_specs('round', '1');
check(count($comps) >= 3, 'round 1ct offers multiple shape comparison links');

$copy = array(
    'plain_text' => 'A 1 carat round measures about 6.37 x 6.41 mm.',
    'faq' => array(
        array('question' => 'How big?', 'answer' => 'About 6.4 mm.'),
    ),
);
$json_ld = $renderer->json_ld_script($ctx, $summary, $copy, $canonical, 'Title', 'Desc');
check(strpos($json_ld, 'application/ld+json') !== false, 'JSON-LD script emitted');
check(strpos($json_ld, 'Dataset') !== false, 'JSON-LD includes Dataset');
check(strpos($json_ld, 'FAQPage') !== false, 'JSON-LD includes FAQPage');
check(strpos($json_ld, 'BreadcrumbList') !== false, 'JSON-LD includes BreadcrumbList');

check(strpos($renderer->methodology_html($summary), '12,000') !== false, 'methodology shows sample size');

$parsed = $renderer->parse_compare_slug('round-1-carat-vs-princess-1-carat', 'ringspo');
check($parsed !== null && $parsed['a']['shape'] === 'round' && $parsed['b']['shape'] === 'princess', 'parse_compare_slug splits round vs princess');

$ind_a = array(
    'shape' => 'round', 'carat_band' => '1', 'source' => 'real',
    'dimensions_mm' => array(
        'length' => array('median' => 6.37),
        'width' => array('median' => 6.41),
    ),
    'faceup_area_mm2' => array('median' => 32.0),
);
$ind_b = array(
    'shape' => 'princess', 'carat_band' => '1', 'source' => 'real',
    'dimensions_mm' => array(
        'length' => array('median' => 5.5),
        'width' => array('median' => 5.5),
    ),
    'faceup_area_mm2' => array('median' => 28.0),
);
$comp = $renderer->build_comparison_summary($ind_a, $ind_b);
check(isset($comp['type']) && $comp['type'] === 'comparison' && $comp['bigger'] === 'a', 'build_comparison_summary marks larger face-up stone');

$ctx_cmp = new LDN_Page_Context('ringspo', 'size-comparison', 'us', null, null, null, 'size', 'round-1-carat-vs-princess-1-carat');
$head_longtail = $renderer->render_head_content($ctx_cmp, $comp, false);
check(strpos($head_longtail, 'noindex') !== false, 'long-tail comparison emits noindex');

$hub_summary = array(
    'type' => 'mega_hub',
    'rows' => array(
        array('shape' => 'round', 'carat' => '1', 'length_mm' => 6.4, 'width_mm' => 6.4,
              'faceup_area_mm2' => 32, 'lw_ratio' => 1.0, 'lw_low' => 0.99, 'lw_high' => 1.01,
              'depth_pct' => 61.5, 'faceup_delta_pct' => -3.5),
        array('shape' => 'oval', 'carat' => '1', 'length_mm' => 7.0, 'width_mm' => 5.2,
              'faceup_area_mm2' => 30, 'lw_ratio' => 1.35),
    ),
);
$ctx_mega = new LDN_Page_Context('ringspo', 'size-mega-hub', 'us', null, null, null, 'size');
$selector = $renderer->shape_selector_html('ringspo', $hub_summary);
check(strpos($selector, 'ldn-size-shape-tile') !== false, 'mega hub shape selector has crawlable tiles');
check(strpos($selector, '/diamond-size/round/') !== false, 'shape selector links to shape hub URL');

$hub_table = $renderer->hub_table_html($ctx_mega, $hub_summary);
check(strpos($hub_table, 'L/W ratio') !== false, 'hub table includes L/W column');
check(strpos($hub_table, 'vs ideal face-up') !== false, 'hub table includes ideal delta column');

exit($GLOBALS['__fails'] > 0 ? 1 : 0);

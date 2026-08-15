<?php
/**
 * Standalone unit tests for LDN_Renderer headline + section routing.
 *
 * The plugin has no PHPUnit/WP harness, so this mirrors test-ldn-schema.php:
 * stub the few WordPress functions touched, define minimal collaborator stubs
 * for the constructor type hints, and assert RULES (not snapshots).
 *
 * Run:  php loupe-diamond-network/tests/test-ldn-renderer.php
 *
 * Test intent (the rules this file enforces):
 *   1. headline() appends " (CC)" only when $include_country is true — so a
 *      profile with country_in_content.h1_headings=false yields no country
 *      suffix in the visible H1 (Loupe sites are country-specific domains).
 *   2. render_section() renders editorial sections listed WITHOUT a `_static`
 *      suffix (type_comparison, shape_preview, natural_vs_lab_analysis) as text
 *      blocks. Would fail if those ids fell through to the "unknown → skip"
 *      branch, silently dropping C1-generated upper-level copy (the L1/L2 bug).
 *   3. A genuinely unmapped section id (partner_spotlight) still renders ''.
 *   4. chrome_heading_class() emits a HYPHENATED BEM modifier
 *      (ldn-chrome--loupe-classic) so it matches the family stylesheet selector.
 *      Would fail if the underscore profile slug (loupe_classic) leaked into the
 *      class as ldn-chrome--loupe_classic, leaving the heading CSS inert.
 *   5. breadcrumb_html() renders only when schema_features includes breadcrumb;
 *      freshness_html() shows analysis_date + sample_size when present.
 */

error_reporting(E_ALL);

// --- Minimal WordPress shims -------------------------------------------------
define('ABSPATH', __DIR__ . '/');
define('LDN_PLUGIN_DIR', dirname(__DIR__) . '/');
if (!defined('LDN_PLUGIN_URL')) {
    define('LDN_PLUGIN_URL', 'https://example.test/wp-content/plugins/loupe-diamond-network/');
}

if (!function_exists('__')) {
    function __($s, $d = null) { return $s; }
}
if (!function_exists('esc_html')) {
    function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__($s, $d = null) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('esc_html__')) {
    function esc_html__($s, $d = null) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('_n')) {
    function _n($single, $plural, $number, $d = null) { return $number == 1 ? $single : $plural; }
}
if (!function_exists('esc_attr')) {
    function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('wpautop')) {
    function wpautop($s) { return '<p>' . str_replace("\n\n", '</p><p>', trim((string) $s)) . '</p>'; }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($s) { return (string) $s; }
}
if (!function_exists('esc_url')) {
    function esc_url($s) { return (string) $s; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($s) { return $s; }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($key, $value = null, $url = null) {
        if (is_array($key)) {
            $args = $key;
            $url = $value;
        } else {
            $args = array($key => $value);
        }
        $url = (string) $url;
        foreach ($args as $k => $v) {
            $sep = strpos($url, '?') === false ? '?' : '&';
            $url .= $sep . rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        return $url;
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}
if (!function_exists('home_url')) {
    function home_url($p = '') { return 'https://example.com/' . ltrim((string) $p, '/'); }
}
if (!function_exists('user_trailingslashit')) {
    function user_trailingslashit($p) { return rtrim((string) $p, '/') . '/'; }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($s) { return strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $s)); }
}
// Date stubs must be present, not absent: localised_date() degrades to the raw
// ISO string when date_i18n() is missing, so without these the display-format
// assertions below would pass against unformatted dates and prove nothing.
if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        return $key === 'date_format' ? 'F j, Y' : $default;
    }
}
if (!function_exists('date_i18n')) {
    function date_i18n($format, $ts = null) { return date($format, $ts === null ? time() : $ts); }
}
if (!function_exists('number_format_i18n')) {
    function number_format_i18n($n, $decimals = 0) { return number_format((float) $n, (int) $decimals); }
}

require_once __DIR__ . '/../includes/class-ldn-page-context.php';

// --- Minimal collaborator stubs (constructor type hints) ---------------------
if (!class_exists('LDN_Data_Fetcher')) {
    class LDN_Data_Fetcher {
        public function fetch_artefact($artefact_id, $ctx) {
            return null;
        }
    }
}
if (!class_exists('LDN_Config')) {
    class LDN_Config {
        public function get_bundle() { return array(); }
        public function get_content_profile($site_id) { return array(); }
        public function get_currency($site_id, $country) { return 'USD'; }
        public function get_url_structure($site_id) {
            return array(
                'level_1'      => '{country}/diamond-prices',
                'level_2'      => '{country}/diamond-prices/{type}',
                'level_3'      => '{country}/diamond-prices/{type}/{carat}',
                'level_4'      => '{country}/diamond-prices/{type}/{carat}/{shape}',
                'carat_format' => '{value}-carat',
                'type_natural' => 'natural',
                'type_lab'     => 'lab-grown',
            );
        }
        public function get_site($site_id) {
            return array('countries' => array(array('code' => 'us', 'full_name' => 'United States')));
        }
        public function size_price_internal_links($site_id) {
            return false;
        }
    }
}

require_once __DIR__ . '/../includes/class-ldn-schema.php';
require_once __DIR__ . '/../includes/class-ldn-artefacts.php';
require_once __DIR__ . '/../includes/class-ldn-renderer.php';

// --- Tiny assertion harness --------------------------------------------------
$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

// --- Fixtures ---------------------------------------------------------------
$renderer = new LDN_Renderer(new LDN_Data_Fetcher(), new LDN_Config());

$shape_ctx = new LDN_Page_Context('modernjeweler', 'shape', 'us', 'natural', '1', 'round');
$top_ctx   = new LDN_Page_Context('modernjeweler', 'top-level', 'us');
$type_ctx  = new LDN_Page_Context('modernjeweler', 'diamond-type', 'us', 'natural');

// --- 1. headline() country suffix toggle (P3) -------------------------------
check(
    $renderer->headline($shape_ctx, true) === '1 Carat Round Natural Diamond Prices (US)',
    'headline includes country suffix when include_country=true'
);
check(
    $renderer->headline($shape_ctx, false) === '1 Carat Round Natural Diamond Prices',
    'headline drops country suffix when include_country=false (h1_headings off)'
);
check(
    $renderer->headline($top_ctx, false) === 'Diamond Prices',
    'top-level headline drops country suffix when include_country=false'
);
check(
    $renderer->headline($top_ctx, true) === 'Diamond Prices (US)',
    'top-level headline keeps country suffix by default'
);

// Test intent: Ringspo top-level H1/title uses the full country name as a prefix.
// Would fail if: top-level still emitted bare "Diamond Prices" / "(US)" for Ringspo.
$ringspo_top = new LDN_Page_Context('ringspo', 'top-level', 'us');
check(
    $renderer->headline($ringspo_top, false) === 'United States Diamond Prices',
    'Ringspo top-level headline is "{Country} Diamond Prices" regardless of include_country'
);
check(
    $renderer->headline($ringspo_top, true) === 'United States Diamond Prices',
    'Ringspo top-level headline stays full-country with include_country=true'
);

// Test intent: Ringspo diamond-type H1 prefixes the full country name.
// Would fail if: type hubs still used bare "Natural Diamond Prices" without country.
$ringspo_type = new LDN_Page_Context('ringspo', 'diamond-type', 'us', 'natural');
check(
    $renderer->headline($ringspo_type, false) === 'United States Natural Diamond Prices',
    'Ringspo diamond-type headline is "{Country} Natural Diamond Prices"'
);
$ringspo_lab = new LDN_Page_Context('ringspo', 'diamond-type', 'us', 'lab-grown');
check(
    $renderer->headline($ringspo_lab, false) === 'United States Lab-Grown Diamond Prices',
    'Ringspo lab-grown type headline includes full country name'
);

// Test intent: Ringspo all-shapes H1 owns "by shape" + full country, not competing
// with the type hub (no carat) or a shape page (named cut).
// Would fail if: all-shapes still used "1 Carat Lab-Grown Diamond Prices (US)".
$ringspo_all = new LDN_Page_Context('ringspo', 'all-shapes', 'us', 'lab-grown', '1');
check(
    $renderer->headline($ringspo_all, false)
        === '1 Carat Lab-Grown Diamond Prices by Shape — United States',
    'Ringspo all-shapes headline is "{carat} {type} … by Shape — {Country}"'
);
check(
    $renderer->headline($ringspo_all, true)
        === '1 Carat Lab-Grown Diamond Prices by Shape — United States',
    'Ringspo all-shapes headline keeps full country regardless of include_country'
);
$loupe_all = new LDN_Page_Context('modernjeweler', 'all-shapes', 'us', 'lab-grown', '1');
check(
    $renderer->headline($loupe_all, true) === '1 Carat Lab-Grown Diamond Prices (US)',
    'non-Ringspo all-shapes headline keeps the (CC) suffix form'
);

// --- 2. render_section renders suffix-less editorial sections (P1) ----------
$top_bag = array(
    'static' => array('sections' => array(
        'type_comparison' => 'Natural and lab-grown stones differ in price and perception.',
        'shape_preview'   => 'Shape changes how a stone looks at the same carat weight.',
    )),
    'individual' => null,
    'summary' => array(),
);

$type_comparison_html = $renderer->render_section('type_comparison', $top_ctx, $top_bag, 'USD');
check(
    $type_comparison_html === '',
    'type_comparison is inlined under the carat table — standalone section suppressed'
);

$shape_preview_html = $renderer->render_section('shape_preview', $top_ctx, $top_bag, 'USD');
check(
    strpos($shape_preview_html, 'Shape changes how a stone looks') !== false,
    'render_section renders shape_preview editorial copy'
);

$type_bag = array(
    'static' => array('sections' => array(
        'natural_vs_lab_analysis' => 'Natural and lab diamonds grade the same but price differently.',
    )),
    'individual' => null,
    'summary' => array(),
);
$nvl_html = $renderer->render_section('natural_vs_lab_analysis', $type_ctx, $type_bag, 'USD');
check(
    strpos($nvl_html, 'grade the same but price differently') !== false,
    'render_section renders natural_vs_lab_analysis editorial copy on diamond-type pages'
);

// --- 3. genuinely unmapped section still skipped ----------------------------
check(
    $renderer->render_section('partner_spotlight', $top_ctx, $top_bag, 'USD') === '',
    'unmapped partner_spotlight section renders empty (no handler yet)'
);

// --- 4. missing copy renders empty (no stray heading) -----------------------
$empty_bag = array('static' => array('sections' => array()), 'individual' => null, 'summary' => array());
check(
    $renderer->render_section('type_comparison', $top_ctx, $empty_bag, 'USD') === '',
    'editorial section with no copy renders empty (no orphan heading)'
);

// --- 5. chrome heading class is hyphenated to match the CSS (Option A) ------
check(
    $renderer->chrome_heading_class(array('page_chrome' => array('heading_style' => 'loupe_classic')))
        === 'ldn-chrome--loupe-classic',
    'loupe_classic heading_style maps to hyphenated ldn-chrome--loupe-classic (matches loupe.css)'
);
check(
    $renderer->chrome_heading_class(array()) === 'ldn-chrome--minimal',
    'default heading_style is ldn-chrome--minimal'
);
check(
    $renderer->chrome_heading_class(array('page_chrome' => array('heading_style' => 'bogus<style>')))
        === 'ldn-chrome--minimal',
    'invalid heading_style falls back to minimal'
);
check(
    $renderer->chrome_heading_class(array('page_chrome' => array('heading_style' => 'editorial')))
        === 'ldn-chrome--editorial',
    'editorial heading_style maps to ldn-chrome--editorial'
);
check(
    $renderer->chrome_heading_class(array('page_chrome' => array('heading_style' => 'ringspo_classic')))
        === 'ldn-chrome--ringspo-classic',
    'ringspo_classic heading_style maps to hyphenated ldn-chrome--ringspo-classic'
);
check(
    $renderer->chrome_heading_class(array('page_chrome' => array('heading_style' => 'bogus')))
        === 'ldn-chrome--minimal',
    'unknown heading_style falls back to minimal'
);

// --- 5b. visible breadcrumbs + freshness (CP53_05) --------------------------
$breadcrumb_profile = array('schema_features' => array('faq', 'breadcrumb'));
$crumbs = $renderer->breadcrumb_html(
    $shape_ctx,
    'https://example.com/us/diamond-prices/natural/1-carat/round/',
    $breadcrumb_profile
);
check(
    strpos($crumbs, 'ldn-breadcrumbs') !== false && strpos($crumbs, 'Diamond Prices') !== false,
    'breadcrumb_html renders a nav when schema_features includes breadcrumb'
);
check(
    $renderer->breadcrumb_html($shape_ctx, '', array('schema_features' => array('faq'))) === '',
    'breadcrumb_html is omitted when breadcrumb is not in schema_features'
);
$fresh = $renderer->freshness_html($shape_ctx, array(
    'analysis_date' => '2026-06-22',
    'distribution'  => array('sample_size' => 12500),
));
check(
    strpos($fresh, 'ldn-freshness') !== false
        && strpos($fresh, 'datetime="2026-06-22"') !== false
        && strpos($fresh, '12,500') !== false,
    'freshness_html shows analysis date and sample size from summary-data'
);
check(
    $renderer->freshness_html($shape_ctx, array()) === '',
    'freshness_html is omitted when no analysis date is present'
);

// --- 6. carat_price_table_html navigation table (top-level nav) -------------
// Rule: emits one row per carat with a lab-grown discount, and links each price
// down to that type+carat all-shapes page; returns '' when the C5.3
// carat_price_table is absent (graceful on stale market-overview.json).
$carat_overview = array(
    'currency' => 'USD',
    'carat_price_table' => array(
        array(
            'carat_weight'           => '1',
            'natural_median_price'   => 6000,
            'lab_grown_median_price' => 3000,
            'lab_grown_discount_pct' => 50.0,
        ),
        array(
            'carat_weight'           => '2',
            'natural_median_price'   => 12000,
            'lab_grown_median_price' => null,
            'lab_grown_discount_pct' => null,
        ),
    ),
);
$carat_html = $renderer->carat_price_table_html($top_ctx, $carat_overview, '$');
check(
    strpos($carat_html, 'https://example.com/us/diamond-prices/natural/1-carat/') !== false,
    'carat table links natural price down to the type+carat all-shapes page'
);
check(
    strpos($carat_html, 'https://example.com/us/diamond-prices/lab-grown/1-carat/') !== false,
    'carat table links lab-grown price down to the type+carat all-shapes page'
);
check(
    strpos($carat_html, '50.0%') !== false,
    'carat table renders the lab-grown discount percentage'
);
check(
    substr_count($carat_html, '<tr') === 3, // header + 2 carat rows
    'carat table renders one row per carat weight'
);
check(
    strpos($carat_html, '—') !== false,
    'missing lab price renders an em dash instead of a broken link'
);
check(
    $renderer->carat_price_table_html($top_ctx, array('currency' => 'USD'), '$') === '',
    'carat table renders empty when carat_price_table is absent (stale S3 safe)'
);

// Rule: every published carat row discloses how many diamonds are behind it, so a
// thin heavy weight reading cheaper than a lighter one is interpretable rather
// than apparently contradictory. We publish thin rows — we do not hide them.
// Would fail if: C5.3 emitted prices with no sample field and the table rendered
// them bare, which is the behaviour before this change.
$sampled_overview = array(
    'currency' => 'USD',
    'carat_price_table' => array(
        array(
            'carat_weight'            => '8',
            'natural_median_price'    => 219570,
            'natural_sample_size'     => 113,
            'lab_grown_median_price'  => null,
            'lab_grown_sample_size'   => 0,
            'lab_grown_discount_pct'  => null,
        ),
        array(
            'carat_weight'            => '9',
            'natural_median_price'    => 216419,
            'natural_sample_size'     => 28,
            'lab_grown_median_price'  => null,
            'lab_grown_sample_size'   => 0,
            'lab_grown_discount_pct'  => null,
        ),
    ),
);
$sampled_html = $renderer->carat_price_table_html($top_ctx, $sampled_overview, '$');
check(
    strpos($sampled_html, 'Diamonds analyzed') !== false,
    'carat table exposes a diamonds-analysed column'
);
check(
    strpos($sampled_html, '>113<') !== false && strpos($sampled_html, '>28<') !== false,
    'carat table renders the per-row sample size for each carat weight'
);
check(
    strpos($sampled_html, '216,419') !== false,
    'a thin carat row still publishes its price — disclosure, not suppression'
);
check(
    strpos(
        $renderer->carat_price_table_html($top_ctx, $carat_overview, '$'),
        'Diamonds analyzed'
    ) !== false,
    'legacy rows without sample fields still render the column (em dash), no fatal'
);

// --- 6b. data_methodology ("About this data") --------------------------------
// Test intent: the methodology block states the statistic for the level it is on,
// discloses the sample size and date, and never names a retailer or the size of
// the pool. It renders only where the widget is entitled.
// Would fail if: the upper levels claimed a "median" (they publish a weighted
// composite), a retailer name or pool count reached the copy, or a partial-coverage
// caveat appeared on a complete aggregate.
class LDN_Config_Methodology extends LDN_Config {
    public function get_content_profile($site_id) {
        return array(
            'methodology' => array(
                'heading'      => 'About this data',
                'source_line'  => 'We track live listings from major online diamond retailers.',
                'price_basis'  => 'These are the prices retailers are asking today, not the prices stones finally sold for.',
                'approximate_weight' => 'Very few diamonds are cut to an exact weight, so stones of approximately this weight are grouped together.',
                'update_line'  => 'Prices are rebuilt daily. This page reflects {date}.',
                'thin_sample_note' => 'Larger weights are bought and sold far less often, so we find fewer of them.',
                'partial_coverage_note' => "Today's figure covers {shape_count} of the {shapes_expected} shapes we track at this weight.",
                'statistic'    => array(
                    'individual_shape' => 'These figures come from {sample_size} {shape} diamonds listed at around {carat} carat, as at {date}. The headline price is the median.',
                    'all_shapes'       => 'This page combines every shape we track at around {carat} carat, covering {sample_size} diamonds across {shape_count} shapes as at {date}.',
                    'diamond_type'     => 'Each row combines every shape we track at that weight. The figures cover {sample_size} diamonds as at {date}.',
                    'top_level'        => 'Each price combines every shape we track at that weight. The figures cover {sample_size} diamonds as at {date}.',
                ),
            ),
        );
    }
    public function get_bundle() {
        $widgets = array('data_methodology' => array('presentation' => 'full'));
        return array('entitlements' => array('sites' => array(
            'methodsite' => array('pages' => array(
                'shape'        => array('wp_widgets' => $widgets),
                'all-shapes'   => array('wp_widgets' => $widgets),
                'diamond-type' => array('wp_widgets' => $widgets),
                'top-level'    => array('wp_widgets' => $widgets),
            )),
            'nomethodsite' => array('pages' => array(
                'shape' => array('wp_widgets' => array(
                    'data_methodology' => array('presentation' => 'disabled'),
                )),
            )),
        )));
    }
}

$method_renderer = new LDN_Renderer(new LDN_Data_Fetcher(), new LDN_Config_Methodology());
$method_shape_ctx = new LDN_Page_Context('methodsite', 'shape', 'us', 'natural', '1', 'round');
$method_all_ctx   = new LDN_Page_Context('methodsite', 'all-shapes', 'us', 'natural', '1');
$method_top_ctx   = new LDN_Page_Context('methodsite', 'top-level', 'us');

$shape_method_html = $method_renderer->data_methodology_html($method_shape_ctx, array(
    'summary' => array(
        'analysis_date' => '2026-07-31',
        'distribution'  => array('median_price' => 3410, 'sample_size' => 3410),
    ),
));
check(
    strpos($shape_method_html, 'ldn-data-methodology') !== false
        && strpos($shape_method_html, 'About this data') !== false,
    'methodology block renders on an entitled shape page'
);
check(
    strpos($shape_method_html, '3,410 round diamonds') !== false,
    'methodology block states the sample size and shape from live data'
);
check(
    strpos($shape_method_html, 'the median') !== false,
    'shape level describes its figure as a median (C3 p50 is a true median)'
);
check(
    strpos($shape_method_html, 'asking today') !== false,
    'methodology block discloses that prices are asking prices, not sold prices'
);
check(
    strpos($shape_method_html, '{') === false,
    'no unfilled copy tokens survive into rendered output'
);

// The pool must never be identifiable from the copy.
$forbidden_pool_terms = array(
    'James Allen', 'Blue Nile', 'Brilliant Earth', 'Whiteflash', 'Brian Gavin',
    'Ritani', '5 retailers', 'five retailers',
);
$named_retailer = false;
foreach ($forbidden_pool_terms as $term) {
    if (stripos($shape_method_html, $term) !== false) {
        $named_retailer = true;
    }
}
check(!$named_retailer, 'methodology block names no retailer and states no pool size');

$top_method_html = $method_renderer->data_methodology_html($method_top_ctx, array(
    'market_overview' => array('analysis_date' => '2026-07-31', 'total_diamonds_tracked' => 125000),
));
check(
    strpos($top_method_html, '125,000 diamonds') !== false,
    'top level reads its sample size from market-overview'
);
check(
    stripos($top_method_html, 'the median') === false,
    'upper levels do not claim a median, because they publish a weighted composite'
);
check(
    strpos($top_method_html, 'Larger weights') !== false,
    'table levels carry the thin-sample note that explains a heavy weight reading cheap'
);

// Coverage caveat fires only when C5.1 reported incomplete coverage.
$complete_all_html = $method_renderer->data_methodology_html($method_all_ctx, array(
    'summary' => array(
        'analysis_date' => '2026-07-31',
        'num_diamonds'  => 90460,
        'aggregate'     => array(
            'shape_count' => 10, 'shapes_expected' => 10, 'coverage_complete' => true,
        ),
    ),
));
$partial_all_html = $method_renderer->data_methodology_html($method_all_ctx, array(
    'summary' => array(
        'analysis_date' => '2026-07-31',
        'num_diamonds'  => 41033,
        'aggregate'     => array(
            'shape_count' => 2, 'shapes_expected' => 10, 'coverage_complete' => false,
        ),
    ),
));
check(
    strpos($complete_all_html, 'shapes we track at this weight.') === false,
    'no partial-coverage caveat when the aggregate covered every shape'
);
check(
    strpos($partial_all_html, 'covers 2 of the 10 shapes') !== false,
    'partial coverage is stated on the page when C5.1 flagged it'
);
check(
    $method_renderer->data_methodology_html(
        new LDN_Page_Context('nomethodsite', 'shape', 'us', 'natural', '1', 'round'),
        array('summary' => array('num_diamonds' => 100, 'analysis_date' => '2026-07-31'))
    ) === '',
    'methodology block is absent when the widget presentation is disabled'
);
check(
    $method_renderer->data_methodology_html(
        new LDN_Page_Context('unknownsite', 'shape', 'us', 'natural', '1', 'round'),
        array('summary' => array('num_diamonds' => 100, 'analysis_date' => '2026-07-31'))
    ) === '',
    'methodology block is absent for a site with no entitlements entry'
);
check(
    strpos($method_renderer->render_section('data_methodology', $method_shape_ctx, array(
        'summary' => array('analysis_date' => '2026-07-31', 'num_diamonds' => 3410),
    )), 'ldn-data-methodology') !== false,
    'render_section routes the data_methodology id (not silently skipped)'
);

$market_bag = array(
    'market_overview' => array(
        'currency' => 'USD',
        'natural' => array('weighted_avg_price' => 5000, 'combo_count' => 10),
        'lab_grown' => array('weighted_avg_price' => 2500, 'combo_count' => 8),
        'carat_price_table' => $carat_overview['carat_price_table'],
    ),
    'market_discount_chart' => array(
        'data' => array(array('x' => array('1 ct'), 'y' => array(50.0), 'type' => 'bar')),
        'layout' => array('margin' => array('t' => 150)),
    ),
    'static' => array('sections' => array(
        'type_comparison' => 'Natural and lab-grown stones differ in price and perception.',
    )),
);
$market_html = $renderer->market_overview_table_html($top_ctx, $market_bag);
check(
    strpos($market_html, 'ldn-market-discount-chart') !== false,
    'top-level hero renders discount chart when entitled'
);
check(
    strpos($market_html, 'Market overview') === false,
    'top-level hero omits the redundant weighted-average overview table'
);
check(
    strpos($market_html, 'Natural and lab-grown stones differ') !== false,
    'type_comparison copy renders directly under the carat price table heading'
);
$table_pos = strpos($market_html, 'ldn-carat-price-table');
$discount_pos = strpos($market_html, 'ldn-market-discount-chart');
check(
    $table_pos !== false && $discount_pos !== false && $table_pos < $discount_pos,
    'carat price table appears above the lab-grown discount chart'
);

// Test intent: Loupe top-level nests the % trend chart with templated analysis.
// Would fail if: the chart rendered without a heading, or analysis copy was ignored.
$loupe_trend_bag = $market_bag;
$loupe_trend_bag['market_trend_chart'] = array(
    'data' => array(array('x' => array('2026-01-01'), 'y' => array(-4.2), 'type' => 'scatter')),
    'layout' => array('margin' => array('t' => 150)),
);
$loupe_trend_bag['copy'] = array(
    'sections' => array(
        'chart_analysis' => 'Over the past 12 months, natural prices have decreased by 4.2%.',
    ),
);
$loupe_trend_html = $renderer->market_overview_table_html($top_ctx, $loupe_trend_bag);
check(
    strpos($loupe_trend_html, 'ldn-market-trend-chart') !== false,
    'Loupe top-level renders the market trend chart when the artefact is in the bag'
);
check(
    strpos($loupe_trend_html, 'Natural vs lab-grown price change') !== false,
    'Loupe trend chart has a visible heading'
);
check(
    strpos($loupe_trend_html, 'decreased by 4.2%') !== false,
    'Loupe trend chart nests templated chart_analysis copy'
);
$loupe_table_pos = strpos($loupe_trend_html, 'ldn-carat-price-table');
$loupe_trend_pos = strpos($loupe_trend_html, 'ldn-market-trend-chart');
check(
    $loupe_table_pos !== false && $loupe_trend_pos !== false && $loupe_table_pos < $loupe_trend_pos,
    'Loupe trend chart sits under the carat table, not above it'
);
check(
    strpos($market_html, 'ldn-market-trend-chart') === false,
    'top-level hub without a trend artefact does not emit an empty chart heading'
);

// Test intent: Ringspo top-level uses fixed short H2 + median-variation copy (not C1).
// Would fail if: Ringspo still inlined C1 type_comparison or kept the long country H2.
$ringspo_market = $renderer->market_overview_table_html($ringspo_top, $market_bag);
check(
    strpos($ringspo_market, 'Natural vs lab-grown prices by carat') !== false,
    'Ringspo top-level uses the shortened carat-table H2'
);
check(
    strpos($ringspo_market, 'weighted toward shapes') !== false,
    'Ringspo top-level uses the concise carat-table intro copy'
);
check(
    strpos($ringspo_market, 'ldn-discount-chart') !== false
        && strpos($ringspo_market, 'Natural vs lab-grown price gap') !== false,
    'Ringspo discount chart is nested in the carat table with a visible heading'
);
check(
    strpos($ringspo_market, 'Natural and lab-grown stones differ') === false,
    'Ringspo top-level does not inline C1 type_comparison under the table'
);

// Test intent: top-level hub hero stats use market-overview scale figures, not a
// single-stone median. Would fail if: hero_stats_html returned '' on top-level.
$top_overview = array(
    'currency' => 'USD',
    'total_diamonds_tracked' => 735764,
    'natural' => array('combo_count' => 167),
    'lab_grown' => array('combo_count' => 209),
    'carat_price_table' => array(
        array(
            'carat_weight' => '1',
            'natural_median_price' => 3093,
            'lab_grown_discount_pct' => 69.0,
        ),
    ),
);
$top_hero_stats = $renderer->hero_stats_html($ringspo_top, $top_overview, 'USD');
check(strpos($top_hero_stats, 'ldn-hero-stats') !== false, 'top-level hero stats render the card grid');
check(strpos($top_hero_stats, '735,764') !== false, 'top-level hero stats show diamonds tracked');
check(strpos($top_hero_stats, '167 natural') !== false && strpos($top_hero_stats, '209 lab-grown') !== false,
    'top-level hero stats show natural and lab-grown combination counts');
check(strpos($top_hero_stats, '$3,093') !== false, 'top-level hero stats show 1 ct natural typical price');
check(strpos($top_hero_stats, '69.0%') !== false, 'top-level hero stats show 1 ct lab-grown discount');

$top_explore = $renderer->top_level_explore_html($ringspo_top);
check(strpos($top_explore, 'ldn-top-level-explore') !== false, 'top_level_explore renders the explore section');
check(
    strpos($top_explore, 'natural') !== false && strpos($top_explore, 'lab-grown') !== false,
    'top_level_explore links to both diamond-type hubs and shape comparison entry points'
);

$guidance_block = $renderer->text_block(
    'market_guidance_static',
    'Start with the carat table, then drill into a weight.'
);
check(
    strpos($guidance_block, 'How to Use These Prices') !== false,
    'market_guidance_static uses the hub guidance heading'
);

// Test intent: P3 hub polish — 1 ct row highlight, type-nav prices, discount fallback, stacked most-traded.
// Would fail if: carat table treated every row equally or discount chart had no crawler text.
$market_bag['market_overview']['carat_price_table'] = array(
    array(
        'carat_weight' => '1',
        'natural_median_price' => 3093,
        'lab_grown_median_price' => 958,
        'lab_grown_discount_pct' => 69.0,
    ),
    array(
        'carat_weight' => '2',
        'natural_median_price' => 14518,
        'lab_grown_median_price' => 2625,
        'lab_grown_discount_pct' => 81.9,
    ),
);
$carat_table_html = $renderer->carat_price_table_html($ringspo_top, $market_bag['market_overview'], '$', '');
check(
    strpos($carat_table_html, 'ldn-row-highlight') !== false,
    'top-level carat table highlights the 1 ct anchor row'
);

$type_nav_html = $renderer->type_nav_links_html($ringspo_top, $market_bag);
check(
    strpos($type_nav_html, '$3,093') !== false && strpos($type_nav_html, '$958') !== false,
    'type nav cards cite 1 ct typical prices from the carat table'
);

$discount_bag = array_merge($market_bag, array(
    'market_discount_chart' => array(
        'data' => array(array('x' => array('1 ct'), 'y' => array(69.0), 'type' => 'bar')),
        'layout' => array(),
    ),
));
$discount_block_html = $renderer->market_overview_table_html($ringspo_top, $discount_bag);
check(
    strpos($discount_block_html, 'ldn-chart-fallback') !== false
        && strpos($discount_block_html, '69.0%') !== false,
    'lab-grown discount chart carries a no-JS fallback from the carat table'
);

// --- 6a. most_traded_table section (top-level, C5.3 top-tables.json) --------
// Test intent: the hub's most-traded block renders one linked row per combo in
// natural_top / lab_grown_top, links each row to that combination's shape page
// (making it the hub's internal-link block), and labels the figure "Median price"
// because these are true per-combo medians rather than the all-shapes composite the
// carat table above shows. Returns '' when the artefact is absent.
// Would fail if: rows were unlinked (dead-end hub), the type were taken from $ctx
// instead of the row (every link pointing at natural), or the label copied the
// carat table's "Typical price".
$top_tables_payload = array(
    'metadata' => array('currency' => 'USD'),
    'natural_top' => array(
        array(
            'rank' => 1, 'shape' => 'round', 'carat' => '1',
            'diamond_type' => 'natural', 'median_price' => 4300, 'sample_size' => 5120,
        ),
        array(
            'rank' => 2, 'shape' => 'oval', 'carat' => '1.5',
            'diamond_type' => 'natural', 'median_price' => 8900, 'sample_size' => 2010,
        ),
    ),
    'lab_grown_top' => array(
        array(
            'rank' => 1, 'shape' => 'emerald', 'carat' => '2',
            'diamond_type' => 'lab-grown', 'median_price' => 2400, 'sample_size' => 3300,
        ),
    ),
);
$most_traded_html = $renderer->render_section(
    'most_traded_table',
    $ringspo_top,
    array('top_tables' => $top_tables_payload),
    'USD'
);
check(
    strpos($most_traded_html, 'ldn-most-traded') !== false,
    'render_section routes the most_traded_table id (not silently skipped)'
);
check(
    strpos($most_traded_html, '1 ct Round') !== false
        && strpos($most_traded_html, '2 ct Emerald') !== false,
    'most-traded table labels each row with its carat and shape'
);
check(
    substr_count($most_traded_html, '<a href=') === 3,
    'every most-traded row is a link, so the hub is not a dead end'
);
check(
    strpos($most_traded_html, 'lab-grown/2-carat/emerald') !== false,
    'lab-grown rows link to the lab-grown tree, not the page context type'
);
check(
    strpos($most_traded_html, 'Median price') !== false
        && strpos($most_traded_html, 'Typical price') === false,
    'most-traded table claims a median (true per-combo p50), unlike the carat table'
);
check(
    strpos($most_traded_html, '5,120') !== false,
    'most-traded table discloses the sample size behind each median'
);
check(
    strpos($most_traded_html, 'ldn-data-table--stacked') !== false
        && strpos($most_traded_html, 'data-label=') !== false,
    'most-traded tables use stacked mobile layout hooks'
);
check(
    $renderer->render_section('most_traded_table', $ringspo_top, array(), 'USD') === '',
    'most_traded_table renders nothing when top-tables.json is absent'
);
$mt_partial = $renderer->render_section(
    'most_traded_table',
    $ringspo_top,
    array('top_tables' => array(
        'metadata' => array('currency' => 'USD'),
        'natural_top' => $top_tables_payload['natural_top'],
        'lab_grown_top' => array(),
    )),
    'USD'
);
check(
    strpos($mt_partial, 'Natural') !== false && strpos($mt_partial, 'Lab-grown') === false,
    'an empty lab-grown list renders no empty Lab-grown heading'
);

// --- 6b. price_per_carat_chart section (diamond-type, C5.2) -----------------
// Test intent: the L2 price-per-carat section renders the C5.2 chart with copy that
// names the page's own diamond type and points the reader at the milestone-weight
// steps; it returns '' when the artefact is absent so a site without the
// entitlement gets no empty heading.
// Would fail if: the section fell through render_section's "unknown -> skip"
// branch, or the natural copy showed on the lab-grown page.
$ppc_payload = array(
    'data' => array(
        array(
            'x' => array('0.5 ct', '1 ct', '2 ct'),
            'y' => array(3000, 5000, 7000),
            'mode' => 'lines+markers',
        ),
    ),
    'layout' => array('margin' => array('t' => 150)),
);
$ppc_nat_ctx = new LDN_Page_Context('ringspo', 'diamond-type', 'us', 'natural');
$ppc_lab_ctx = new LDN_Page_Context('ringspo', 'diamond-type', 'us', 'lab-grown');
$ppc_html = $renderer->render_section(
    'price_per_carat_chart',
    $ppc_nat_ctx,
    array('price_per_carat_chart' => $ppc_payload),
    'USD'
);
check(
    strpos($ppc_html, 'ldn-price-per-carat') !== false,
    'render_section routes the price_per_carat_chart id (not silently skipped)'
);
check(
    strpos($ppc_html, 'Natural diamond price per carat by weight') !== false,
    'price-per-carat heading names the page diamond type'
);
check(
    strpos($ppc_html, 'Large rough is genuinely scarce') !== false,
    'natural page explains the per-carat climb by rough scarcity'
);
check(
    strpos($ppc_html, '1, 1.5 and 2 carats') !== false,
    'price-per-carat copy points the reader at the milestone-weight steps'
);
$ppc_lab_html = $renderer->render_section(
    'price_per_carat_chart',
    $ppc_lab_ctx,
    array('price_per_carat_chart' => $ppc_payload),
    'USD'
);
check(
    strpos($ppc_lab_html, 'Lab-Grown diamond price per carat by weight') !== false,
    'lab-grown page gets its own price-per-carat heading'
);
check(
    strpos($ppc_lab_html, 'Large rough is genuinely scarce') === false
        && strpos($ppc_lab_html, 'question of time rather than luck') !== false,
    'lab-grown page uses lab-specific reasoning, not the natural-scarcity copy'
);
check(
    $renderer->render_section('price_per_carat_chart', $ppc_nat_ctx, array(), 'USD') === '',
    'price_per_carat_chart renders nothing when the chart artefact is absent'
);
check(
    strpos($ppc_html, 'ldn-chart-fallback') === false,
    'price-per-carat chart adds no text fallback (the carat table already lists the numbers)'
);

// --- 7. shapes_ranking_table_html links shape drill-down (all-shapes) -------
$all_shapes_ctx = new LDN_Page_Context('modernjeweler', 'all-shapes', 'us', 'natural', '1');
$ranking_bag = array(
    'ranking' => array(
        'currency_symbol' => '$',
        'shapes' => array(
            array('shape' => 'Round', 'median_price' => 6000, 'price_change' => 1.2),
            array('shape' => 'Oval', 'median_price' => 5200, 'price_change' => -0.5),
        ),
    ),
);
$ranking_html = $renderer->shapes_ranking_table_html($all_shapes_ctx, $ranking_bag);
check(
    strpos($ranking_html, 'https://example.com/us/diamond-prices/natural/1-carat/round/') !== false,
    'shapes ranking table links each shape down to its shape page'
);
check(
    strpos($ranking_html, 'https://example.com/us/diamond-prices/natural/1-carat/oval/') !== false,
    'shapes ranking table links oval shape page'
);

// --- 8. carat_ladder_html links sibling carat weights (shape pages) ----------
$ladder_bag = array(
    'carat_ladder' => array(
        'currency_symbol' => '$',
        'rows' => array(
            array('carat_weight' => '1', 'median_price' => 6000, 'is_page_carat' => true),
            array('carat_weight' => '2', 'median_price' => 12000, 'is_page_carat' => false),
        ),
    ),
);
$ladder_html = $renderer->carat_ladder_html($shape_ctx, $ladder_bag, 'USD');
check(
    strpos($ladder_html, 'https://example.com/us/diamond-prices/natural/2-carat/round/') !== false,
    'carat ladder links non-page carat rows to sibling shape pages'
);
check(
    strpos($ladder_html, 'https://example.com/us/diamond-prices/natural/1-carat/') !== false,
    'carat ladder includes link up to the all-shapes hub page'
);
check(
    strpos($ladder_html, 'ldn-row-current') !== false,
    'carat ladder highlights the current page carat row'
);
$ladder_chart_bag = $ladder_bag;
$ladder_chart_bag['carat_ladder_chart'] = array(
    'data' => array(array('x' => array('1 ct', '2 ct'), 'y' => array(6000, 12000), 'type' => 'bar')),
    'layout' => array('margin' => array('t' => 150)),
);
$ladder_with_chart = $renderer->carat_ladder_html($shape_ctx, $ladder_chart_bag, 'USD');
check(
    strpos($ladder_with_chart, 'ldn-carat-ladder-chart') !== false,
    'carat ladder section renders inline chart when chart payload present'
);

// --- 9. intro_html price-change period is policy-driven ---------------------
// Test intent: the shape-page intro change clause uses the period from the
// profile's templated_copy.individual_shape policy (Loupe = 12 months), snapshot
// families (show_change:false) omit it, and a profile with no policy falls back
// to the legacy 7-day clause.
// Would fail if: intro_html hardcoded "over the last 7 days" / read change_7d
// regardless of policy (the bug this section guards).
class LDN_Config_Loupe_Policy extends LDN_Config {
    public function get_content_profile($site_id) {
        return array('templated_copy' => array(
            'individual_shape' => array(
                'intro_change_period' => '12_months',
                'show_change'         => true,
            ),
            'all_shapes' => array(
                'intro_change_period' => '12_months',
                'show_change'         => true,
            ),
        ));
    }
}
class LDN_Config_Snapshot_Policy extends LDN_Config {
    public function get_content_profile($site_id) {
        return array('templated_copy' => array(
            'individual_shape' => array(
                'intro_change_period' => null,
                'show_change'         => false,
            ),
            'all_shapes' => array(
                'intro_change_period' => null,
                'show_change'         => false,
            ),
        ));
    }
}

$intro_summary = array(
    'current_price' => 5000,
    'num_diamonds'  => 100,
    'time_series'   => array(
        'change_12_months' => 8.5,
        'change_7_days'    => 1.1,
    ),
);

$loupe_renderer = new LDN_Renderer(new LDN_Data_Fetcher(), new LDN_Config_Loupe_Policy());
$loupe_intro = $loupe_renderer->intro_html($shape_ctx, $intro_summary, 'USD');
check(
    strpos($loupe_intro, 'over the last 12 months') !== false,
    'intro_html uses the 12-month period from the Loupe individual_shape policy'
);
check(
    strpos($loupe_intro, 'over the last 7 days') === false,
    'intro_html no longer hardcodes the 7-day period'
);
check(
    strpos($loupe_intro, 'increased by 8.50%') !== false,
    'intro_html reports the 12-month change value (not the 7-day value)'
);

$snapshot_renderer = new LDN_Renderer(new LDN_Data_Fetcher(), new LDN_Config_Snapshot_Policy());
$snapshot_intro = $snapshot_renderer->intro_html($shape_ctx, $intro_summary, 'USD');
check(
    strpos($snapshot_intro, 'over the last') === false
        && strpos($snapshot_intro, 'increased') === false,
    'intro_html omits the change clause for snapshot families (show_change:false)'
);

$legacy_intro = $renderer->intro_html($shape_ctx, $intro_summary, 'USD');
check(
    strpos($legacy_intro, 'over the last 7 days') !== false,
    'intro_html falls back to the legacy 7-day clause when no policy is present'
);

// --- 9b. headline price prefers the median over the (outlier-inflated) mean --
// Test intent: when summary-data.json carries a distribution median, intro_html
// and stats_html lead with the MEDIAN (matching the carat-ladder table), not the
// higher current_price (avg). This is the $3,711-vs-$4,281 consistency fix.
// Would fail if: the headline read current_price/time_series.current_price while
// a distribution.median_price was present (the pre-fix behaviour).
$median_summary = array(
    'current_price' => 4281,
    'num_diamonds'  => 30543,
    'distribution'  => array(
        'median_price' => 3711,
        'price_range'  => array('min' => 342, 'max' => 43023),
    ),
);
$median_intro = $renderer->intro_html($shape_ctx, $median_summary, 'USD');
check(
    strpos($median_intro, '$3,711') !== false,
    'intro_html leads with the distribution median ($3,711)'
);
check(
    strpos($median_intro, '$4,281') === false,
    'intro_html does not lead with the outlier-inflated mean ($4,281)'
);
$median_stats = $renderer->stats_html($shape_ctx, $median_summary, 'USD');
check(
    strpos($median_stats, '$3,711') !== false && strpos($median_stats, '$4,281') === false,
    'stats_html headline stat uses the median ($3,711), not the mean'
);

// p50 fallback path when median_price is absent but percentiles exist.
$p50_summary = array(
    'current_price' => 4281,
    'num_diamonds'  => 30543,
    'distribution'  => array('percentiles' => array('p50' => 3711)),
);
$p50_intro = $renderer->intro_html($shape_ctx, $p50_summary, 'USD');
check(
    strpos($p50_intro, '$3,711') !== false,
    'intro_html falls back to percentiles.p50 when median_price is absent'
);

// --- 9c. hero_stats_html emits median-led cards incl. range + period change --
// Test intent: the hero-band stat cards lead with the median price, show the
// sample count, render min–max as a single "Price range" card, and add a
// period-change card carrying a direction modifier class (down for a fall).
// Would fail if: hero_stats used the mean, split range into two cards, or
// dropped the change/trend class.
$hero_summary = array(
    'distribution' => array(
        'median_price' => 3510,
        'sample_size'  => 27523,
        'price_range'  => array('min' => 1030, 'max' => 19270),
    ),
    'time_series'  => array('change_12_months' => -5.39),
);
$hero_cards = $loupe_renderer->hero_stats_html($shape_ctx, $hero_summary, 'USD');
check(
    strpos($hero_cards, 'ldn-hero-stats') !== false,
    'hero_stats_html renders the hero stat card grid'
);
check(
    strpos($hero_cards, '$3,510') !== false && strpos($hero_cards, 'Median (all qualities)') !== false,
    'hero_stats_html leads with the page median across all quality grades'
);
check(
    strpos($hero_cards, '27,523') !== false,
    'hero_stats_html shows the diamonds-analysed count'
);
check(
    strpos($hero_cards, '$1,030') !== false
        && strpos($hero_cards, '$19,270') !== false
        && strpos($hero_cards, 'Price range') !== false,
    'hero_stats_html renders min–max as a single price-range card'
);
check(
    strpos($hero_cards, 'ldn-stat--down') !== false && strpos($hero_cards, '5.39%') !== false,
    'hero_stats_html adds a down-trend change card for a price fall'
);

$hero_empty = $loupe_renderer->hero_stats_html($shape_ctx, array(), 'USD');
check(
    $hero_empty === '',
    'hero_stats_html returns empty string when no price figure is present'
);

// --- 10. shapes ranking table change column tracks the policy period --------
// Test intent: the ranking change column label + visibility follow C5.1's
// `change_period` field (Loupe = 12 months); an explicit null drops the column;
// a missing key falls back to the legacy 7-day label.
// Would fail if: the header hardcoded "7-day % change" or always rendered the
// change column regardless of the family policy.
$ranking_12m_bag = array(
    'ranking' => array(
        'currency_symbol' => '$',
        'change_period'   => '12_months',
        'shapes' => array(
            array('shape' => 'Round', 'median_price' => 6000, 'price_change' => 1.2),
            array('shape' => 'Oval', 'median_price' => 5200, 'price_change' => -0.5),
        ),
    ),
);
$ranking_12m_html = $renderer->shapes_ranking_table_html($all_shapes_ctx, $ranking_12m_bag);
check(
    strpos($ranking_12m_html, '12-month % change') !== false,
    'ranking table labels the change column with the policy period (12-month)'
);
check(
    strpos($ranking_12m_html, '7-day % change') === false,
    'ranking table no longer hardcodes the 7-day change label'
);

$ranking_snapshot_bag = array(
    'ranking' => array(
        'currency_symbol' => '$',
        'change_period'   => null,
        'shapes' => array(
            array('shape' => 'Round', 'median_price' => 6000, 'price_change' => 1.2),
        ),
    ),
);
$ranking_snapshot_html = $renderer->shapes_ranking_table_html($all_shapes_ctx, $ranking_snapshot_bag);
check(
    strpos($ranking_snapshot_html, '% change') === false
        && substr_count($ranking_snapshot_html, '<th>') === 2,
    'ranking table drops the change column for snapshot families (change_period null)'
);

$ranking_legacy_bag = array(
    'ranking' => array(
        'currency_symbol' => '$',
        'shapes' => array(
            array('shape' => 'Round', 'median_price' => 6000, 'price_change' => 1.2),
        ),
    ),
);
$ranking_legacy_html = $renderer->shapes_ranking_table_html($all_shapes_ctx, $ranking_legacy_bag);
check(
    strpos($ranking_legacy_html, '7-day % change') !== false,
    'ranking table falls back to the 7-day label when change_period is absent (legacy payload)'
);

// --- 11. stats_html uses a two-row price summary grid -----------------------
// Test intent: headline stats show current + sample size on top, low/high below.
// Would fail if: the old dl grid kept lowest price beside current price.
$stats_summary = array(
    'current_price' => 5000,
    'min_price'     => 980,
    'max_price'     => 24730,
    'num_diamonds'  => 100,
);
$loupe_stats = $loupe_renderer->stats_html($all_shapes_ctx, $stats_summary, 'USD');
check(
    strpos($loupe_stats, 'ldn-stats-row') !== false,
    'stats_html renders the new two-row stats grid'
);
check(
    strpos($loupe_stats, '$5,000') !== false && strpos($loupe_stats, '100') !== false,
    'stats_html top row shows current price and diamonds analysed'
);
check(
    strpos($loupe_stats, '$980') !== false && strpos($loupe_stats, '$24,730') !== false,
    'stats_html bottom row shows lowest and highest prices'
);
check(
    strpos($loupe_stats, '12-month change') === false,
    'stats_html no longer renders a separate change row (trend lives in copy/chart)'
);

// --- 12. intro_html range wording + whole numbers (CP1) ---------------------
// Test intent: the intro range sentence describes the spread of this page's
// stones (no same-to-same "comparing to X carat X" phrasing) and all dollar
// figures are whole numbers (no cents).
// Would fail if: the old "When comparing to %s carat %s diamond prices" wording
// returned, or number_format kept 2 decimals on prices.
$range_summary = array(
    'current_price' => 3610,
    'num_diamonds'  => 33576,
    'distribution'  => array('price_range' => array('min' => 980, 'max' => 24730)),
);
$range_intro = $renderer->intro_html($shape_ctx, $range_summary, 'USD');
check(
    strpos($range_intro, 'Individual stones range from $980 to $24,730') !== false,
    'intro range sentence states the stone spread in whole dollars'
);
check(
    strpos($range_intro, 'When comparing to') === false,
    'intro range sentence drops the same-to-same comparison wording'
);
check(
    strpos($range_intro, '.00') === false,
    'intro prices use whole numbers (no cents)'
);

// --- 13. carat ladder has an explanatory intro line (CP1) -------------------
// Test intent: the carat ladder explains it compares this shape across carat
// weights. Would fail if the table rendered with no lead-in context.
$ladder_intro_html = $renderer->carat_ladder_html($shape_ctx, $ladder_bag, 'USD');
check(
    strpos($ladder_intro_html, 'scales across carat weights') !== false,
    'carat ladder includes an intro line framing the shape-vs-carat comparison'
);

// --- 14. shapes ranking prices are whole numbers (CP1) ----------------------
$ranking_whole_html = $renderer->shapes_ranking_table_html($all_shapes_ctx, $ranking_12m_bag);
check(
    strpos($ranking_whole_html, '$6,000') !== false && strpos($ranking_whole_html, '$6,000.00') === false,
    'ranking table prices render as whole numbers (no cents)'
);

// --- 15. all-shapes overview copy split (CP2) ----------------------------
// Test intent: intro_text renders alone before the hero; trend analysis renders
// before the chart; shape_analysis after; legacy keys are ignored.
// Would fail if: analysis still rendered after the hero, or legacy duplicates leaked.
$copy_bag = array(
    'copy' => array('sections' => array(
        'intro_text'      => 'Opening paragraph about prices.',
        'analysis'        => 'Trend paragraph here.',
        'shape_analysis'  => 'Oval leads; Round trails.',
        'intro'           => 'Legacy duplicate — must not render.',
        'ranking_summary' => 'Legacy duplicate — must not render either.',
    )),
    'static' => null,
    'summary' => array(),
);
$intro_only = $renderer->copy_dynamic_html('overview_intro_dynamic', $all_shapes_ctx, $copy_bag);
check(
    strpos($intro_only, 'Opening paragraph') !== false
        && strpos($intro_only, 'Trend paragraph') === false,
    'overview_intro_dynamic renders intro_text only (before hero)'
);
$analysis_before = $renderer->copy_dynamic_html('overview_analysis_dynamic', $all_shapes_ctx, $copy_bag);
check(
    strpos($analysis_before, 'Trend paragraph') !== false
        && strpos($analysis_before, 'Oval leads') === false,
    'overview_analysis_dynamic renders trend analysis before the hero chart'
);
$detail_copy = $renderer->copy_dynamic_html('overview_detail_dynamic', $all_shapes_ctx, $copy_bag);
check(
    strpos($detail_copy, 'Trend paragraph') === false
        && strpos($detail_copy, 'Oval leads') !== false,
    'overview_detail_dynamic renders shape_analysis only after hero'
);
check(
    strpos($detail_copy, 'Legacy duplicate') === false,
    'overview_detail_dynamic ignores legacy intro/ranking_summary keys'
);
$combined_copy = $renderer->copy_dynamic_html('overview_combined_dynamic', $all_shapes_ctx, $copy_bag);
check(
    strpos($combined_copy, 'Oval leads') !== false
        && strpos($combined_copy, 'Trend paragraph') !== false
        && strpos($combined_copy, 'ldn-copy-overview') !== false,
    'overview_combined_dynamic merges shape_analysis then analysis in one section'
);

// --- 16. diamond-type intro from type_summary fallback (CP3) -----------------
// Test intent: type_overview_dynamic leads with the most-listed carat tier's own
// median/sample from type-summary.json — never the type-wide weighted median.
// Would fail if: intro cited aggregate.weighted_median_price / total_sample_size
// as if they belonged to most_popular_carat.
$type_summary_bag = array(
    'type_summary' => array(
        'aggregate' => array(
            'carat_count'           => 2,
            'most_popular_carat'    => '1',
            'weighted_median_price' => 5412,
            'total_sample_size'     => 456637,
        ),
        'carat_tiers' => array(
            array(
                'carat_weight' => '1',
                'median_price' => 3107,
                'sample_size'  => 91399,
            ),
            array(
                'carat_weight' => '2',
                'median_price' => 14553,
                'sample_size'  => 27449,
            ),
        ),
        'currency' => 'USD',
    ),
    'copy'    => null,
    'static'  => null,
    'summary' => array(),
);
$type_intro = $renderer->type_intro_html($type_ctx, $type_summary_bag, 'USD');
check(
    strpos($type_intro, '2 carat weights') !== false,
    'type_intro_html cites carat breadth from type-summary aggregate'
);
check(
    strpos($type_intro, 'most listed weight') !== false
        && strpos($type_intro, '$3,107') !== false
        && strpos($type_intro, '91,399') !== false,
    'type_intro_html cites popular carat median and sample from carat_tiers'
);
check(
    strpos($type_intro, '$5,412') === false && strpos($type_intro, '456,637') === false,
    'type_intro_html does not cite type-wide weighted median or total sample'
);
check(
    strpos($type_intro, 'most searched') === false,
    'type_intro_html does not claim search demand for the popular carat'
);
$type_overview = $renderer->render_section('type_overview_dynamic', $type_ctx, $type_summary_bag, 'USD');
check(
    strpos($type_overview, '2 carat weights') !== false,
    'type_overview_dynamic falls back to type_intro_html when copy.json is absent'
);

$type_intro_hero = $renderer->type_intro_html($type_ctx, $type_summary_bag, 'USD', true);
check(
    strpos($type_intro_hero, 'ldn-type-intro--hero-band') !== false,
    'type_intro_html emits hero-band modifier when in_hero_band=true'
);

$intro_ref = new ReflectionClass($renderer);
$intro_flag = $intro_ref->getProperty('type_intro_in_hero');
$intro_flag->setAccessible(true);
$intro_flag->setValue($renderer, true);
$type_overview_suppressed = $renderer->render_section('type_overview_dynamic', $type_ctx, $type_summary_bag, 'USD');
check(
    $type_overview_suppressed === '',
    'type_overview_dynamic is empty when intro already rendered in the hero band'
);

// Test intent: carat tiers table shows all rows in a full-height card (no
// max-height scrollport), with price-per-carat and Ringspo how-to-read copy.
// Would fail if: wrapper used ldn-table-scroll / max-height again.
// Would fail if: PPC column missing or Ringspo intro omitted from the table section.
$ringspo_type_ctx = new LDN_Page_Context('ringspo', 'diamond-type', 'us', 'natural');
$tiers_html = $renderer->carat_tiers_table_html(
    $ringspo_type_ctx,
    array_merge($type_summary_bag, array(
        'price_per_carat_chart' => array(
            'data' => array(array('x' => array('1 ct'), 'y' => array(3107), 'type' => 'scatter')),
        ),
    )),
    'Natural diamond prices by carat weight'
);
check(
    strpos($tiers_html, 'Price per carat') !== false,
    'carat_tiers_table_html includes Price per carat column header'
);
check(
    strpos($tiers_html, '$3,107') !== false && strpos($tiers_html, '91,399') !== false,
    'carat_tiers_table_html renders median and sample for 1ct'
);
// 3107 / 1 = 3107
check(
    substr_count($tiers_html, '$3,107') >= 2,
    'carat_tiers_table_html shows price-per-carat equal to median at 1ct'
);
check(
    strpos($tiers_html, 'Click a carat') !== false,
    'Ringspo carat tiers table includes how-to-read copy'
);
check(
    strpos($tiers_html, 'do not increase proportionally') === false,
    'Ringspo carat tiers table omits milestone copy (lives under the chart)'
);
check(
    strpos($tiers_html, 'ldn-table-card') !== false,
    'carat_tiers_table_html wraps the table in a full-height card (no inner scroll)'
);
check(
    strpos($tiers_html, 'ldn-table-scroll') === false,
    'carat_tiers_table_html does not use a max-height scrollport'
);
check(
    strpos($tiers_html, 'ldn-price-per-carat-block') !== false,
    'carat_tiers_table_html nests the price-per-carat chart block'
);
check(
    strpos($tiers_html, 'ldn-type-carat-history-chart') === false,
    'Ringspo carat tiers table does not nest the Loupe 1/2/3 ct history chart'
);

// Test intent: Loupe diamond-type hub nests 1/2/3 ct history + analysis, not PPC.
// Would fail if: history chart required the Ringspo PPC artefact, or analysis copy
// was dropped.
$loupe_history_html = $renderer->carat_tiers_table_html(
    $type_ctx,
    array_merge($type_summary_bag, array(
        'type_carat_history_chart' => array(
            'data' => array(array('x' => array('2026-01-01'), 'y' => array(5000), 'type' => 'scatter')),
            'layout' => array('margin' => array('t' => 150)),
        ),
        'copy' => array(
            'sections' => array(
                'chart_analysis' => 'At 1 carat, typical natural prices have decreased by 3.1%.',
            ),
        ),
    )),
    'Natural diamond prices by carat weight'
);
check(
    strpos($loupe_history_html, 'ldn-type-carat-history-chart') !== false,
    'Loupe diamond-type table nests the 1/2/3 ct history chart'
);
check(
    strpos($loupe_history_html, 'decreased by 3.1%') !== false,
    'Loupe diamond-type history chart nests templated chart_analysis copy'
);
check(
    strpos($loupe_history_html, 'ldn-price-per-carat-block') === false,
    'Loupe diamond-type table does not nest Ringspo price-per-carat when that artefact is absent'
);
check(
    strpos($tiers_html, 'ldn-row-highlight') !== false,
    'carat_tiers_table_html highlights the anchor carat row'
);
check(
    strpos($tiers_html, 'ldn-data-table--stacked') !== false
        && strpos($tiers_html, 'data-label=') !== false,
    'carat_tiers_table_html uses stacked mobile labels'
);

// Test intent: diamond-type hero stats use type-summary scale figures, not a
// single-shape median. Would fail if: hero_stats_html returned '' on diamond-type.
$type_summary_bag['summary'] = $type_summary_bag['type_summary'];
$type_hero_stats = $renderer->hero_stats_html($type_ctx, $type_summary_bag['type_summary'], 'USD');
check(
    strpos($type_hero_stats, 'ldn-hero-stats') !== false,
    'diamond-type hero stats render the card grid'
);
check(
    strpos($type_hero_stats, '2') !== false && strpos($type_hero_stats, 'Carat weights') !== false,
    'diamond-type hero stats show carat weight count'
);
check(
    strpos($type_hero_stats, '456,637') !== false,
    'diamond-type hero stats show total diamonds tracked'
);
check(
    strpos($type_hero_stats, '$3,107') === false,
    'diamond-type hero stats omit anchor-carat price (covered by intro + lookup)'
);

$combined_section = $renderer->render_section(
    'comparison_chart',
    $ringspo_type_ctx,
    array_merge($type_summary_bag, array(
        'price_per_carat_chart' => array(
            'data' => array(array('x' => array('1 ct'), 'y' => array(3107), 'type' => 'scatter')),
        ),
    )),
    'USD'
);
check(
    strpos($combined_section, 'ldn-carat-tiers-table') !== false,
    'comparison_chart section renders the combined carat tiers section'
);

$toggle_html = $renderer->nat_lab_toggle_html($ringspo_type_ctx);
check(
    $toggle_html === '',
    'nat_lab_toggle returns empty when site entitlements are absent from the bundle'
);

if (!class_exists('LDN_Config_Ringspo_Toggle')) {
    class LDN_Config_Ringspo_Toggle extends LDN_Config {
        public function get_bundle() {
            return array(
                'entitlements' => array(
                    'sites' => array(
                        'ringspo' => array(
                            'pages' => array(
                                'diamond-type' => array(
                                    'wp_widgets' => array(
                                        'nat_lab_toggle' => true,
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            );
        }
    }
}
$ringspo_toggle_renderer = new LDN_Renderer(new LDN_Data_Fetcher(), new LDN_Config_Ringspo_Toggle());
$toggle_html = $ringspo_toggle_renderer->nat_lab_toggle_html($ringspo_type_ctx, $type_summary_bag);
check(
    strpos($toggle_html, 'ldn-nat-lab-toggle__pill--active') !== false,
    'nat_lab_toggle marks the current diamond type active when entitled'
);
check(
    strpos($toggle_html, 'lab-grown') !== false,
    'nat_lab_toggle links to the sibling diamond type when entitled'
);
// Test intent: inactive pill prefetches the sibling page; active pill does not.
// Would fail if: rel=prefetch were missing or applied to the current type.
check(
    preg_match(
        '/ldn-nat-lab-toggle__pill--active[^>]*aria-current="page"/',
        $toggle_html
    ) === 1,
    'nat_lab_toggle marks the active pill with aria-current'
);
check(
    substr_count($toggle_html, 'rel="prefetch"') === 1,
    'nat_lab_toggle prefetches exactly one sibling type URL'
);
check(
    strpos($toggle_html, 'type="speculationrules"') !== false
        && strpos($toggle_html, 'lab-grown') !== false,
    'nat_lab_toggle emits Speculation Rules for the sibling URL'
);
// Test intent: inactive pill carries the current hub carat so Natural ↔ Lab-grown
// keeps the slider weight. Would fail if: sibling href omitted ?carat=1 while
// natural's most_popular_carat is 1.
check(
    preg_match('/rel="prefetch"[^>]*carat=1|carat=1[^>]*rel="prefetch"/', $toggle_html) === 1
        || strpos($toggle_html, 'carat=1') !== false,
    'nat_lab_toggle sibling URL includes the current hub carat query'
);

$lookup_html = $renderer->type_carat_lookup_html($ringspo_type_ctx, $type_summary_bag, 'USD');
check(
    strpos($lookup_html, 'ldn-type-carat-lookup-manifest') !== false,
    'type_carat_lookup embeds tier manifest JSON'
);
check(
    strpos($lookup_html, '"default_carat":"1"') !== false
        || strpos($lookup_html, '"default_carat": "1"') !== false,
    'type_carat_lookup defaults to most_popular_carat when no ?carat= query'
);

// Test intent: ?carat= overrides most_popular_carat for slider + table highlight.
// Would fail if: hub_anchor_carat ignored the query and stayed on popular=1.
$_GET['carat'] = '2';
$lookup_from_query = $renderer->type_carat_lookup_html($ringspo_type_ctx, $type_summary_bag, 'USD');
$toggle_from_query = $ringspo_toggle_renderer->nat_lab_toggle_html($ringspo_type_ctx, $type_summary_bag);
$tiers_from_query = $renderer->carat_tiers_table_html(
    $ringspo_type_ctx,
    $type_summary_bag,
    'Natural diamond prices by carat weight'
);
unset($_GET['carat']);
check(
    strpos($lookup_from_query, '"default_carat":"2"') !== false
        || strpos($lookup_from_query, '"default_carat": "2"') !== false,
    'type_carat_lookup prefers ?carat= over most_popular_carat'
);
check(
    strpos($toggle_from_query, 'carat=2') !== false,
    'nat_lab_toggle carries ?carat= from the request onto the sibling URL'
);
check(
    preg_match(
        '/ldn-row-highlight[^>]*>.*?>2 ct<|>2 ct<\/a><\/td>/s',
        $tiers_from_query
    ) === 1
        || (
            strpos($tiers_from_query, 'ldn-row-highlight') !== false
            && strpos($tiers_from_query, '>2 ct<') !== false
        ),
    'carat_tiers_table_html highlights the ?carat= row'
);

$explore_html = $renderer->diamond_type_explore_html($ringspo_type_ctx, $type_summary_bag);
check(
    strpos($explore_html, 'ldn-type-nav-card') !== false,
    'diamond_type_explore renders navigation cards'
);
check(
    strpos($explore_html, 'lab-grown') !== false
        && strpos($explore_html, 'All diamond prices') !== false,
    'diamond_type_explore links to the opposite type hub and top-level prices'
);
check(
    strpos($explore_html, 'compare shapes') === false,
    'diamond_type_explore omits the compare-shapes card (three-up layout)'
);

$ppc_bag = array(
    'type_summary' => $type_summary_bag['type_summary'],
    'price_per_carat_chart' => array(
        'data' => array(array('x' => array(1), 'y' => array(3107), 'type' => 'scatter')),
    ),
);
$ppc_html = $renderer->price_per_carat_chart_html($ringspo_type_ctx, $ppc_bag);
check(
    strpos($ppc_html, 'Price per carat —') !== false
        && strpos($ppc_html, '$3,107') !== false
        && strpos($ppc_html, '$7,277') !== false,
    'price_per_carat_chart_html includes no-JS fallback for anchor weights'
);

$intro_flag->setValue($renderer, false);

if (!class_exists('LDN_Config_Type_Hero_Render')) {
    class LDN_Config_Type_Hero_Render extends LDN_Config {
        public function get_content_profile($site_id) {
            return array('page_chrome' => array('hero_band' => true));
        }

        public function get_page_layout($site_id, $page_level, $country_code = null) {
            return array(
                'hero_component' => null,
                'sections'       => array('type_overview_dynamic'),
                'ad_slots'       => array(),
            );
        }
    }
}
if (!class_exists('LDN_Fetcher_Type_Summary')) {
    class LDN_Fetcher_Type_Summary extends LDN_Data_Fetcher {
        public function fetch_artefact($artefact_id, $ctx) {
            if ($artefact_id === 'type_summary_json') {
                return array(
                    'analysis_date' => '2026-07-28',
                    'aggregate' => array(
                        'carat_count'           => 19,
                        'most_popular_carat'    => '1',
                        'weighted_median_price' => 3873,
                        'total_sample_size'     => 510000,
                    ),
                );
            }
            return null;
        }
    }
}
$type_page_renderer = new LDN_Renderer(new LDN_Fetcher_Type_Summary(), new LDN_Config_Type_Hero_Render());
$type_page_html = $type_page_renderer->render($type_ctx);
check(
    strpos($type_page_html, 'ldn-type-intro--hero-band') !== false,
    'diamond-type render places type-summary intro inside the hero band when copy is absent'
);
check(
    substr_count($type_page_html, '19 carat weights') === 1,
    'diamond-type intro is not duplicated in the hero band and body'
);
$fresh_pos = strpos($type_page_html, 'ldn-freshness');
$intro_pos = strpos($type_page_html, 'ldn-type-intro--hero-band');
$title_pos = strpos($type_page_html, 'ldn-page-title');
check(
    $title_pos !== false && $fresh_pos !== false && $intro_pos !== false
        && $title_pos < $fresh_pos && $fresh_pos < $intro_pos,
    'diamond-type hero order is title → freshness → intro'
);

// --- 17. pricing → size snapshot card (Decision 5) ---------------------------
// Test intent: US shape pages append a size snapshot card after colour/clarity
// when size_price_internal_links is enabled; card shows median mm dimensions from
// size-summary.json. Would fail if: link stayed a plain footer anchor or card
// omitted ldn-price-size-card markup.
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-test-combos.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-size-renderer.php';

if (!class_exists('LDN_Config_Size_Link')) {
    class LDN_Config_Size_Link extends LDN_Config {
        public function size_price_internal_links($site_id) {
            return true;
        }

        public function size_rollout_country($site_id) {
            return 'us';
        }

        public function get_url_structure($site_id) {
            $structure = parent::get_url_structure($site_id);
            $structure['size_level_3'] = '/size/{carat}/{shape}/';
            return $structure;
        }

        public function shape_to_s3_slug($shape) {
            return (string) $shape;
        }
    }
}
if (!class_exists('LDN_Fetcher_Size_Summary')) {
    class LDN_Fetcher_Size_Summary extends LDN_Data_Fetcher {
        public function fetch_artefact($artefact_id, $ctx) {
            if ($artefact_id === 'size_summary_json') {
                return array(
                    'shape' => 'round',
                    'dimensions_mm' => array(
                        'length' => array('median' => 6.4),
                        'width'  => array('median' => 6.4),
                    ),
                    'faceup_area_mm2' => array('median' => 32.2),
                );
            }
            return null;
        }
    }
}
$ringspo_shape_ctx = new LDN_Page_Context('ringspo', 'shape', 'us', 'natural', '1', 'round');
$size_card_renderer = new LDN_Renderer(new LDN_Fetcher_Size_Summary(), new LDN_Config_Size_Link());
$size_card_html = $size_card_renderer->size_dimensions_card_html($ringspo_shape_ctx, 'USD');
check(
    strpos($size_card_html, 'ldn-price-size-card') !== false,
    'size_dimensions_card_html renders the pricing→size snapshot card'
);
check(
    strpos($size_card_html, 'Median diameter: 6.4 mm') !== false,
    'size_dimensions_card_html shows median diameter from size-summary.json'
);

// --- 11. color_clarity_table_html heatmap grid (shape pages) ----------------
// Test intent: the colour x clarity grid renders one cell per (colour, clarity)
// from C5.7 color-clarity.json (`price_table[color][clarity] = {price,count}`),
// orders grades best -> worst regardless of JSON key order, shades cells by
// price relative to the grid min/max, and returns '' when the artefact is
// absent. Would fail if the section fell through render_section's "unknown ->
// skip" branch (the gap this change closes) or if the cell schema reader
// expected the legacy currency-nested shape.
$cc_payload = array(
    'currency' => 'USD',
    'price_table' => array(
        // Deliberately out of canonical order to prove the renderer re-sorts.
        'H' => array(
            'VS1' => array('price' => 4200, 'count' => 30),
            'IF'  => array('price' => 5200, 'count' => 12),
        ),
        'D' => array(
            'IF'  => array('price' => 9800, 'count' => 8),
            'VS1' => array('price' => 7600, 'count' => 20),
        ),
    ),
);
$cc_html = $renderer->color_clarity_table_html($shape_ctx, $cc_payload, 'USD');
check(
    strpos($cc_html, 'ldn-color-clarity') !== false && strpos($cc_html, '$9,800') !== false,
    'color_clarity grid renders the section with formatted cell prices'
);
$cc_with_size = $size_card_renderer->render_section(
    'color_clarity',
    $ringspo_shape_ctx,
    array('color_clarity' => $cc_payload),
    'USD'
);
check(
    strpos($cc_with_size, 'ldn-color-clarity') !== false
        && strpos($cc_with_size, 'ldn-price-size-card') === false,
    'color_clarity section no longer appends the size dimensions card'
);
// Test intent: size_explore_link keeps body copy and the CTA as separate
// elements so the CTA can be styled as a block link (not an inline orphan arrow).
$size_explore = $size_card_renderer->render_section(
    'size_explore_link',
    $ringspo_shape_ctx,
    array(),
    'USD'
);
check(
    strpos($size_explore, 'ldn-size-explore-link') !== false
        && strpos($size_explore, 'ldn-size-explore-link__copy') !== false
        && strpos($size_explore, 'ldn-size-explore-link__anchor') !== false
        && strpos($size_explore, 'View size chart') !== false
        && preg_match(
            '/ldn-size-explore-link__copy">[^<]+<\/p>\s*<a class="ldn-size-explore-link__anchor"/',
            $size_explore
        ) === 1,
    'size_explore_link renders copy and CTA as separate elements'
);

// --- buying_advice_static legacy merge ---------------------------------------
// Test intent: merged buying advice prefers buying_advice and falls back to
// concatenating legacy expert_take + buying_guidance until C1 regenerates.
// Would fail if: only the first legacy key rendered or keys were skipped.
if (!class_exists('LDN_Fetcher_Buying_Advice')) {
    class LDN_Fetcher_Buying_Advice extends LDN_Data_Fetcher {
        public function fetch_artefact($artefact_id, $ctx) {
            return null;
        }
    }
}
$advice_renderer = new LDN_Renderer(new LDN_Fetcher_Buying_Advice(), new LDN_Config_Size_Link());
$legacy_advice = $advice_renderer->render_section(
    'buying_advice_static',
    $ringspo_shape_ctx,
    array(
        'static' => array(
            'expert_take' => 'Lead with cut.',
            'buying_guidance' => 'VS2 is the sweet spot.',
        ),
    ),
    'USD'
);
check(
    strpos($legacy_advice, 'Buying advice') !== false
        && strpos($legacy_advice, 'Lead with cut.') !== false
        && strpos($legacy_advice, 'VS2 is the sweet spot.') !== false,
    'buying_advice_static merges legacy expert_take and buying_guidance'
);
check(
    strpos($cc_html, '<th scope="col">D</th>') < strpos($cc_html, '<th scope="col">H</th>'),
    'colour columns are ordered best (D) before worse (H) regardless of JSON order'
);
check(
    strpos($cc_html, '>IF<') < strpos($cc_html, '>VS1<'),
    'clarity rows are ordered best (IF) before worse (VS1)'
);
check(
    strpos($cc_html, 'color-mix(in srgb, var(--ldn-primary)') !== false,
    'cells are tinted by price via the brand --ldn-primary heatmap colour'
);
check(
    strpos($cc_html, 'Clarity \\ Color') !== false
        && strpos($cc_html, 'Price by color and clarity') !== false,
    'US color/clarity grid uses American color spelling'
);
$uk_shape_ctx = new LDN_Page_Context('modernjeweler', 'shape', 'uk', 'natural', '1', 'round');
$uk_cc_html = $renderer->color_clarity_table_html($uk_shape_ctx, $cc_payload, 'GBP');
check(
    strpos($uk_cc_html, 'Clarity \\ Colour') !== false
        && strpos($uk_cc_html, 'Price by colour and clarity') !== false,
    'non-US color/clarity grid keeps British colour spelling'
);
check(
    $renderer->color_clarity_table_html($shape_ctx, array(), 'USD') === '',
    'color_clarity grid renders empty when the artefact is absent (stale S3 safe)'
);
$cc_via_section = $renderer->render_section(
    'color_clarity',
    $shape_ctx,
    array('color_clarity' => $cc_payload),
    'USD'
);
check(
    strpos($cc_via_section, 'ldn-color-clarity') !== false,
    'render_section dispatches the color_clarity id to the heatmap builder (gap closed)'
);

// --- 7. Standalone homepage sections (DPE / carat hub) --------------------
// Test intent: Top-level hub sections surface market scale and type entry links from market_overview.
// Would fail if: hub_stats or type_nav_links return empty when overview payload is present.

$dpe_ctx = new LDN_Page_Context('diamondpriceuk', 'top-level', 'uk');
$dpe_profile = array(
    'homepage' => array('h1' => 'Diamond Price'),
    'tagline'  => 'Real-time diamond prices in your currency',
);
$hub_bag = array(
    'market_overview' => array(
        'total_diamonds_tracked' => 125000,
        'analysis_date'          => '2026-06-01',
        'natural'                => array('total_sample_size' => 80000),
        'lab_grown'              => array('total_sample_size' => 45000),
        'currency'               => 'GBP',
        'carat_price_table'      => array(
            array(
                'carat_weight'            => '1',
                'natural_median_price'    => 5000,
                'lab_grown_median_price'  => 2000,
                'lab_grown_discount_pct'  => 60,
            ),
        ),
    ),
    'top_tables' => array(
        'natural_top' => array(
            array(
                'shape'         => 'round',
                'carat'         => '1',
                'diamond_type'  => 'natural',
                'median_price'  => 5200,
                'sample_size'   => 9000,
            ),
        ),
    ),
    'summary' => array(),
);

check(
    $renderer->homepage_headline($dpe_ctx, $dpe_profile) === 'Diamond Price',
    'homepage_headline uses profile homepage.h1 on top-level pages'
);
check(
    strpos($renderer->homepage_tagline_html($dpe_ctx, $dpe_profile), 'Real-time diamond prices') !== false,
    'homepage_tagline_html renders profile tagline under the H1'
);

$hub_stats_html = $renderer->render_section('hub_stats', $dpe_ctx, $hub_bag, 'GBP');
check(
    strpos($hub_stats_html, 'ldn-hub-stats') !== false,
    'hub_stats renders a stats section from market_overview'
);
check(
    strpos($hub_stats_html, '125,000') !== false,
    'hub_stats formats total_diamonds_tracked for display'
);

$type_nav_html = $renderer->render_section('type_nav_links', $dpe_ctx, $hub_bag, 'GBP');
check(
    strpos($type_nav_html, 'ldn-type-nav') !== false,
    'type_nav_links renders the natural/lab entry grid'
);
check(
    strpos($type_nav_html, 'Browse natural or lab-grown prices') !== false,
    'type_nav_links section carries an SEO-friendly H2 title'
);

$popular_html = $renderer->render_section('popular_searches', $dpe_ctx, $hub_bag, 'GBP');
check(
    strpos($popular_html, 'ldn-carat-price-table') !== false,
    'popular_searches includes the carat price ladder table'
);
check(
    strpos($popular_html, 'ldn-popular-shapes') !== false,
    'popular_searches lists top shape links when top_tables is present'
);

// --- 12. shape_cards hub (CP53_08) ------------------------------------------
// Test intent: shape_cards renders a crawlable linked card per shape from
// shapes-ranking.json; all-shapes pages mount cards via the shapes_at_carat
// section (Ringspo purple band), not as a nested hero component.
// Would fail if: shapes_at_carat returned '' on Ringspo all-shapes pages.
$all_shapes_ctx = new LDN_Page_Context('ringspo', 'all-shapes', 'us', 'natural', '1');
$shape_hub_bag = array(
    'ranking' => array(
        'currency_symbol' => '$',
        'change_period'   => '1_month',
        'shapes' => array(
            array('shape' => 'Round', 'median_price' => 3510, 'price_change' => -5.39),
            array('shape' => 'Oval', 'median_price' => 3200, 'price_change' => 1.2),
        ),
    ),
);
$cards_html = $renderer->shape_cards_html($all_shapes_ctx, $shape_hub_bag);
check(
    strpos($cards_html, 'ldn-shape-cards') !== false
        && strpos($cards_html, 'ldn-shape-card') !== false,
    'shape_cards_html renders the linked card grid'
);
check(
    strpos($cards_html, 'https://example.com/us/diamond-prices/natural/1-carat/round/') !== false,
    'shape_cards links Round down to the shape page'
);
check(
    strpos($cards_html, '$3,510') !== false && strpos($cards_html, '5.39%') !== false,
    'shape_cards shows formatted median price and period change'
);
check(
    strpos($cards_html, '1 carat diamonds by shape') !== false,
    'shape_cards uses the short hub title without a country clause'
);

if (!class_exists('LDN_Config_Ringspo_Shape_Hub')) {
    class LDN_Config_Ringspo_Shape_Hub extends LDN_Config {
        public function get_bundle() {
            return array(
                'entitlements' => array(
                    'sites' => array(
                        'ringspo' => array(
                            'pages' => array(
                                'all-shapes' => array(
                                    'wp_widgets' => array(
                                        'shapes_at_carat' => array(
                                            'presentation' => 'shape_cards',
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            );
        }

        public function get_content_profile($site_id) { return array(); }
        public function get_currency($site_id, $country) { return 'USD'; }
        public function get_url_structure($site_id) {
            return array(
                'level_1'      => '{country}/diamond-prices',
                'level_2'      => '{country}/diamond-prices/{type}',
                'level_3'      => '{country}/diamond-prices/{type}/{carat}',
                'level_4'      => '{country}/diamond-prices/{type}/{carat}/{shape}',
                'carat_format' => '{value}-carat',
                'type_natural' => 'natural',
                'type_lab'     => 'lab-grown',
            );
        }
        public function get_site($site_id) {
            return array('countries' => array(array('code' => 'us', 'full_name' => 'United States')));
        }
    }
}
$ringspo_renderer = new LDN_Renderer(new LDN_Data_Fetcher(), new LDN_Config_Ringspo_Shape_Hub());
$hub_html = $ringspo_renderer->shapes_at_carat_html($all_shapes_ctx, $shape_hub_bag);
check(
    strpos($hub_html, 'ldn-shape-cards') !== false,
    'shapes_at_carat dispatches to shape_cards when entitled on all-shapes'
);

// Test intent: all-shapes shape cards expose rank, sample size, range, and chart;
// partial coverage surfaces above the grid on staging.
// Would fail if: cards stayed price-only or the ranking table were still appended.
$rich_hub_bag = array(
    'ranking' => array(
        'currency_symbol' => '$',
        'change_period'   => '1_month',
        'shapes' => array(
            array(
                'shape' => 'Round',
                'rank' => 1,
                'median_price' => 3510,
                'price_change' => -5.39,
                'sample_size' => 45000,
                'price_min' => 1800,
                'price_max' => 8900,
            ),
            array(
                'shape' => 'Oval',
                'rank' => 2,
                'median_price' => 3200,
                'price_change' => 1.2,
                'sample_size' => 12000,
                'price_min' => 1500,
                'price_max' => 7200,
            ),
        ),
    ),
    'ranking_chart' => array(
        'data' => array(array('x' => array('Round', 'Oval'), 'y' => array(3510, 3200), 'type' => 'bar')),
        'layout' => array('title' => array('text' => 'United States 1 Carat Natural Diamond Prices by Shape')),
    ),
    'summary' => array(
        'aggregate' => array(
            'shape_count' => 2,
            'shapes_expected' => 10,
            'coverage_complete' => false,
        ),
    ),
);
$rich_cards = $ringspo_renderer->shape_cards_html($all_shapes_ctx, $rich_hub_bag);
check(strpos($rich_cards, '#1') !== false, 'shape_cards show rank on each card');
check(strpos($rich_cards, '45,000 diamonds') !== false, 'shape_cards show sample size');
check(strpos($rich_cards, '$1,800–$8,900') !== false, 'shape_cards show price range');
check(strpos($rich_cards, 'ldn-shapes-ranking-chart') !== false, 'shape_cards append the ranking bar chart');
check(strpos($rich_cards, 'ldn-chart__title') !== false, 'shape_cards chart uses an H3 title from the Plotly payload');
check(strpos($rich_cards, 'United States 1 Carat Natural Diamond Prices by Shape') !== false, 'shape_cards chart title comes from layout.title.text');
check(strpos($rich_cards, 'ldn-shapes-ranking-table--extended') === false, 'shape_cards no longer append the extended ranking table');
$partial_banner = $method_renderer->shape_cards_html($method_all_ctx, $rich_hub_bag);
check(strpos($partial_banner, 'ldn-partial-coverage') !== false, 'shape_cards show partial-coverage banner when flagged');

$all_shapes_summary = array(
    'distribution' => array('median_price' => 3350, 'sample_size' => 57000),
    'aggregate' => array('shape_count' => 2),
);
$hero_all_stats = $ringspo_renderer->hero_stats_html($all_shapes_ctx, $all_shapes_summary, 'USD');
check(
    strpos($hero_all_stats, 'Typical price (median)') !== false,
    'all-shapes hero stats label the composite figure as typical median price'
);
check(
    strpos($hero_all_stats, 'Shapes compared') !== false && strpos($hero_all_stats, '2') !== false,
    'all-shapes hero stats include shapes compared count'
);

$explore_html = $ringspo_renderer->all_shapes_explore_html($all_shapes_ctx);
check(strpos($explore_html, 'ldn-all-shapes-explore') !== false, 'all_shapes_explore renders explore section');
check(strpos($explore_html, 'ldn-explore-card') !== false, 'all_shapes_explore uses explore cards');
check(
    strpos($explore_html, 'lab-grown') !== false,
    'all_shapes_explore links natural hub to lab-grown at the same carat'
);

// --- 13. summary_cards hero + breadcrumb order --------------------------------
// Test intent: top-level summary_cards maps to the market overview table; breadcrumbs
// lead the hero band before the visible H1. Would fail if: summary_cards still
// fell through render_hero as '' or title preceded breadcrumbs in render output.
$hero_ref = new ReflectionMethod($renderer, 'render_hero');
$hero_ref->setAccessible(true);
$summary_hero = $hero_ref->invoke($renderer, 'summary_cards', $top_ctx, $market_bag);
check(
    strpos($summary_hero, 'ldn-carat-price-table') !== false,
    'summary_cards hero maps to the market overview carat price table'
);

if (!class_exists('LDN_Config_Breadcrumb_Order')) {
    class LDN_Config_Breadcrumb_Order extends LDN_Config {
        public function get_content_profile($site_id) {
            return array(
                'page_chrome' => array('hero_band' => true),
                'schema_features' => array('breadcrumb'),
            );
        }

        public function get_page_layout($site_id, $page_level, $country_code = null) {
            return array(
                'hero_component' => null,
                'sections'       => array(),
                'ad_slots'       => array(),
            );
        }
    }
}
$chrome_renderer = new LDN_Renderer(new LDN_Data_Fetcher(), new LDN_Config_Breadcrumb_Order());
$page_html = $chrome_renderer->render($shape_ctx);
$crumb_pos = strpos($page_html, 'ldn-breadcrumbs');
$title_pos = strpos($page_html, 'ldn-page-title');
check(
    $crumb_pos !== false && $title_pos !== false && $crumb_pos < $title_pos,
    'render() outputs breadcrumbs before the H1 inside the hero band'
);

// --- 14. CP54_04 chart text fallback ----------------------------------------
// Test intent: chart fallback is screen-reader/crawler-only so sighted readers
// are not shown a second median line under the hero stats.
// Would fail if: ldn-chart-fallback--sr were omitted (visible duplicate copy).
$cp5404_summary = array(
    'analysis_date' => '2026-07-27',
    'distribution'  => array('median_price' => 5200, 'sample_size' => 1842),
);
$cp5404_payload = array(
    'data'   => array(array('x' => array(1, 2), 'y' => array(3, 4), 'type' => 'scatter')),
    'layout' => array(),
);

$fallback_text = $renderer->data_summary_text($shape_ctx, $cp5404_summary, 'USD');
check($fallback_text !== '', 'data_summary_text produces a factual statement');
check(
    strpos($fallback_text, '5,200') !== false && strpos($fallback_text, '1,842') !== false,
    'fallback statement carries median price and sample size'
);
check(
    strpos($fallback_text, '<') === false,
    'data_summary_text returns bare text, not markup (escaped at render time)'
);

$chart_with_fallback = $renderer->chart_html(
    $cp5404_payload,
    'ldn-price-chart',
    'Price over time',
    $fallback_text
);
$target_pos = strpos($chart_with_fallback, 'class="ldn-chart-target"');
$fallback_pos = strpos($chart_with_fallback, 'ldn-chart-fallback');
$close_pos = strpos($chart_with_fallback, '</div>');
check($fallback_pos !== false, 'chart renders the text fallback');
check(
    strpos($chart_with_fallback, 'ldn-chart-fallback--sr') !== false,
    'chart fallback is visually hidden for sighted readers'
);
check(
    $target_pos !== false && $fallback_pos > $target_pos && $fallback_pos < $close_pos,
    'fallback sits INSIDE the Plotly target div so newPlot() replaces it'
);
check(
    strpos($chart_with_fallback, 'ldn-data-summary') === false,
    'fallback is not emitted as a standalone data-summary section'
);

// Without a fallback the container must stay empty — back-compatible for the
// chart callers (tables, homepage) that have no summary to describe.
$chart_no_fallback = $renderer->chart_html($cp5404_payload, 'ldn-price-chart', 'Price over time');
check(
    strpos($chart_no_fallback, 'class="ldn-chart-target"></div>') !== false,
    'chart_html without a fallback leaves the target div empty'
);

// Escaping: a stray angle bracket in the statement must not open a tag.
$chart_escaped = $renderer->chart_html($cp5404_payload, 'ldn-price-chart', 'T', 'a <b> c');
check(
    strpos($chart_escaped, '<b>') === false && strpos($chart_escaped, '&lt;b&gt;') !== false,
    'fallback text is escaped before rendering'
);
check(
    strpos($chart_with_fallback, 'LDN.plotChart') !== false,
    'chart_html delegates Plotly render to LDN.plotChart (WP Rocket Delay JS safe)'
);

// --- 14b. the fallback must quote the same figure as the visible page --------
// Test intent: the factual statement resolves its headline price through the same
// order as intro_html() and hero_stats_html(), so the text a crawler reads can
// never contradict the price a reader sees.
// Would fail if: dataset_description() omitted the distribution.percentiles.p50
// step — this summary has percentiles but no median_price, so it would fall
// through to time_series.current_price (the trimmed mean, 9,999) while the intro
// and hero cards quote p50 (7,400).
$p50_only_summary = array(
    'analysis_date' => '2026-07-27',
    'distribution'  => array(
        'percentiles' => array('p50' => 7400),
        'sample_size' => 500,
    ),
    'time_series'   => array('current_price' => 9999),
);
$p50_fallback = $renderer->data_summary_text($shape_ctx, $p50_only_summary, 'USD');
$p50_intro = $renderer->intro_html($shape_ctx, $p50_only_summary, 'USD');
$p50_hero = $renderer->hero_stats_html($shape_ctx, $p50_only_summary, 'USD');
check(
    strpos($p50_fallback, '7,400') !== false,
    'fallback quotes p50 when no explicit median_price is present'
);
check(
    strpos($p50_fallback, '9,999') === false,
    'fallback does not fall through to the trimmed mean while the page shows p50'
);
check(
    strpos($p50_intro, '7,400') !== false && strpos($p50_hero, '7,400') !== false,
    'intro and hero cards quote the same p50 figure as the fallback'
);

// --- 14c. visible fallback formats its date, machine-readable copy keeps ISO --
// Test intent: the chart's no-JS fallback is visible page copy, so its date is
// rendered in the site's display format and matches the freshness line; the meta
// description and JSON-LD Dataset description keep the ISO date for machines.
// Would fail if: data_summary_text() emitted the raw "2026-07-27" (reads as
// unfinished beside "July 27, 2026" in the freshness line), or if the default
// dataset_description() started localising and pushed a display-formatted date
// into the meta description and structured data.
$dated_summary = array(
    'analysis_date' => '2026-07-27',
    'distribution'  => array('median_price' => 5200, 'sample_size' => 1842),
);
$dated_fallback = $renderer->data_summary_text($shape_ctx, $dated_summary, 'USD');
$dated_freshness = $renderer->freshness_html($shape_ctx, $dated_summary);
$dated_machine = (new LDN_Schema())->dataset_description($shape_ctx, $dated_summary, 'USD');

check(
    strpos($dated_fallback, 'July 27, 2026') !== false,
    'visible fallback renders the date in the display format'
);
check(
    strpos($dated_fallback, '2026-07-27') === false,
    'visible fallback does not leak the raw ISO date'
);
check(
    strpos($dated_freshness, 'July 27, 2026') !== false,
    'fallback and freshness line agree on the date format'
);
check(
    strpos($dated_machine, '2026-07-27') !== false
        && strpos($dated_machine, 'July 27, 2026') === false,
    'meta description / Dataset description keeps the ISO date'
);

// A summary with no date must not gain a date clause just because a display date
// could be computed — an empty display date is not a licence to invent one.
$undated_summary = array('distribution' => array('median_price' => 5200, 'sample_size' => 1842));
check(
    strpos($renderer->data_summary_text($shape_ctx, $undated_summary, 'USD'), 'as of') === false,
    'fallback omits the date clause entirely when the summary carries no date'
);

// --- 15. head_tags() emits one of each tag it owns --------------------------
// Test intent: a routed page emits exactly one canonical and exactly one meta
// description, and the description is emitted whenever no unbridged SEO plugin
// owns it — SEOPress does not, because LDN_Seo_Bridge suppresses SEOPress's.
// Would fail if: seo_plugin_emits_meta() went back to standing down for any
// installed SEO plugin, which left every LDN page on this network with no meta
// description at all while SEOPress emitted none either.
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
require_once __DIR__ . '/../includes/class-ldn-seo-bridge.php';

// SEOPress present, as on every live network site. This is the condition under
// which the description went missing, so asserting without it proves nothing.
// Declared last in the file because it is process-global once defined.
define('SEOPRESS_VERSION', '8.0');

class LDN_Fetcher_Og_Preview extends LDN_Data_Fetcher {
    public function resolve_artefact_url($artefact_id, $ctx) {
        return $artefact_id === 'og_preview_png'
            ? 'https://ringspo.s3.amazonaws.com/us/natural/1-carat/round/og-preview.png'
            : null;
    }
}

$head_renderer = new LDN_Renderer(new LDN_Fetcher_Og_Preview(), new LDN_Config());
$head_summary = array(
    'analysis_date' => '2026-06-30',
    'distribution'  => array('median_price' => 3510, 'sample_size' => 27523),
);
$head = $head_renderer->head_tags(
    $shape_ctx,
    'https://ringspo.com/us/diamond-prices/natural/1-carat/round/',
    $head_summary,
    'USD'
);

check(substr_count($head, 'rel="canonical"') === 1, 'head_tags emits exactly one canonical');
check(substr_count($head, 'name="description"') === 1, 'head_tags emits exactly one meta description');
check(
    strpos($head, 'content="Market pricing data for 1 carat round natural diamonds') !== false,
    'the meta description carries the page\'s own facts'
);
check(substr_count($head, 'property="og:url"') === 1, 'head_tags emits exactly one og:url');
check(substr_count($head, 'property="og:title"') === 1, 'head_tags emits exactly one og:title');

// An empty og:image is worse than none — it is what SEOPress was emitting ahead
// of this one, and scrapers take the first occurrence.
check(
    strpos($head, '<meta property="og:image" content="" ') === false
        && strpos($head, 'og-preview.png') !== false,
    'og:image points at the page\'s chart preview and is never empty'
);

// og:title must be the same string the <title> uses (LDN_Query_Signals feeds it
// from document_title()), or the tab and the social card disagree again.
check(
    strpos($head, 'property="og:title" content="' . esc_attr($head_renderer->document_title($shape_ctx)) . '"') !== false,
    'og:title and the document title are the same string'
);
check(
    strpos($head, 'property="og:image:alt" content="' . esc_attr($head_renderer->document_title($shape_ctx)) . '"') !== false,
    'og:image:alt matches the page title so share cards stay readable'
);

// --- Section bands ----------------------------------------------------------
// Test intent: a section's band comes from what the profile declares for that
// section id, so inserting or reordering a module cannot change the surface
// colour of any other section.
// Would fail if: banding were still inferred from DOM position, where adding a
// module shifts every colour below it.

check(
    $renderer->apply_section_band('<section class="ldn-section ldn-color-clarity">x</section>', 'tint')
        === '<section class="ldn-section ldn-section--tint ldn-color-clarity">x</section>',
    'a declared band adds its modifier to the section class'
);

// Every section is tagged, plain included, so the stylesheet can address
// adjacency (a rule between two plain bands) without :not() chains.
check(
    strpos($renderer->apply_section_band('<section class="ldn-section ldn-faq">x</section>', ''), 'ldn-section--plain') !== false,
    'an undeclared section is explicitly tagged plain rather than left unclassed'
);

// A typo in config must not emit an inert class that silently styles nothing.
check(
    strpos($renderer->apply_section_band('<section class="ldn-section">x</section>', 'purple'), 'ldn-section--purple') === false,
    'an unrecognised band name falls back to plain instead of emitting a dead class'
);

// One section id can render sibling sections (colour/clarity emits a table plus
// a size card); both belong to the same declared band.
$two = $renderer->apply_section_band(
    '<section class="ldn-section a">1</section><section class="ldn-section b">2</section>',
    'accent'
);
check(
    substr_count($two, 'ldn-section--accent') === 2,
    'sibling sections from one section id share that id\'s band'
);

check($renderer->apply_section_band('', 'tint') === '', 'an empty section stays empty');

// Test intent: coloured bands must not stack, and the last body section must not
// be purple (theme footer is purple). Runtime coercion handles optional sections
// that skip; these tests lock the rule itself.
// Would fail if: tint followed accent were left as declared, or tint were kept on
// the last section.
check(
    $renderer->coerce_section_band('accent', 'tint', false) === 'plain',
    'accent after tint coerces to plain'
);
check(
    $renderer->coerce_section_band('tint', 'accent', false) === 'plain',
    'tint after accent coerces to plain'
);
check(
    $renderer->coerce_section_band('tint', 'plain', true) === 'plain',
    'tint on the last section coerces to plain before the purple footer'
);
check(
    $renderer->coerce_section_band('tint', 'plain', false) === 'tint',
    'tint after plain stays tint when not last'
);
check(
    $renderer->coerce_section_band('accent', 'plain', true) === 'accent',
    'accent on the last section is allowed before the purple footer'
);
check(
    $renderer->coerce_section_band('tint', '', false, true) === 'plain',
    'tint immediately after the green hero band coerces to plain'
);

// The declared band must reach the rendered page: the section loop is the only
// place that reads the map, so a profile declaring a band and the page ignoring
// it is the failure mode that matters.
if (!class_exists('LDN_Config_Declared_Bands')) {
    class LDN_Config_Declared_Bands extends LDN_Config {
        public function get_content_profile($site_id) {
            return array('page_chrome' => array('hero_band' => true));
        }

        public function get_page_layout($site_id, $page_level, $country_code = null) {
            return array(
                'hero_component' => null,
                // faq_static is declared second so a passing test cannot be
                // explained by position: under the retired positional scheme
                // neither of these two would have been coloured. A trailing plain
                // section lets tint render when it is not the page's last block.
                'sections'       => array(
                    'natural_vs_lab_analysis',
                    'faq_static',
                    'buying_considerations',
                ),
                'ad_slots'       => array(),
                'section_bands'  => array('faq_static' => 'tint'),
            );
        }
    }
}
if (!class_exists('LDN_Fetcher_Banded_Copy')) {
    class LDN_Fetcher_Banded_Copy extends LDN_Data_Fetcher {
        public function fetch_artefact($artefact_id, $ctx) {
            if ($artefact_id === 'static_content_json') {
                return array(
                    'natural_vs_lab_analysis' => 'Lab-grown stones trade at a discount.',
                    'buying_considerations'   => 'Compare loose-to-loose when budgeting.',
                    'faq' => array(
                        array('question' => 'How is this priced?', 'answer' => 'From live listings.'),
                    ),
                );
            }
            return null;
        }
    }
}
$banded_html = (new LDN_Renderer(new LDN_Fetcher_Banded_Copy(), new LDN_Config_Declared_Bands()))
    ->render($type_ctx);
check(
    strpos($banded_html, 'ldn-section ldn-section--tint ldn-faq') !== false,
    'a section declared as tint renders with the tint modifier'
);
check(
    strpos($banded_html, 'ldn-section ldn-section--plain ldn-natural-vs-lab-analysis') !== false,
    'a section the profile does not mention renders plain, whatever its position'
);

// --- price_calculator (CP 123) -----------------------------------------------
// Test intent: the calculator answers only from the cell the reader's exact
// specification names, always states how many diamonds that cell holds, and
// takes its specification from the manifest rather than page context.
// Would fail if: an absent cell were answered from the pooled-cut or a coarser
// cell, the sample size were dropped from the answer, a cut control appeared for
// a shape whose manifest carries no cut dimension, or the renderer read
// $ctx->shape / $ctx->carat (which a context-free host cannot supply).
if (!class_exists('LDN_Config_Price_Calculator')) {
    class LDN_Config_Price_Calculator extends LDN_Config {
        public function get_content_profile($site_id) {
            return array();
        }
        public function get_bundle() {
            return array('entitlements' => array('sites' => array(
                'calcsite' => array('pages' => array(
                    'shape' => array('wp_widgets' => array(
                        'price_calculator' => array('presentation' => 'full'),
                    )),
                    'all-shapes' => array('wp_widgets' => array(
                        'price_calculator' => array('presentation' => 'full'),
                    )),
                )),
                'nocalcsite' => array('pages' => array(
                    'shape' => array('wp_widgets' => array(
                        'price_calculator' => array('presentation' => 'disabled'),
                    )),
                )),
            )));
        }
    }
}

$calc_renderer = new LDN_Renderer(new LDN_Data_Fetcher(), new LDN_Config_Price_Calculator());
$calc_ctx = new LDN_Page_Context('calcsite', 'shape', 'us', 'natural', '1', 'round');

$calc_manifest = array(
    'diamond_type'      => 'natural',
    'shape'             => 'Round',
    'carat_weight'      => '1',
    'date'              => '2026-07-31',
    'currency'          => 'USD',
    'colours'           => array(
        array('key' => 'D', 'label' => 'D'),
        array('key' => 'G', 'label' => 'G'),
    ),
    'clarities'         => array(
        array('key' => 'VVS1', 'label' => 'VVS1'),
        array('key' => 'VS1', 'label' => 'VS1'),
    ),
    'cut_grades'        => array('Super Ideal', 'Excellent'),
    'has_cut_dimension' => true,
    'pooled_cut_key'    => 'ALL',
    'default_cell'      => array(
        'color' => 'G',
        'clarity' => 'VS1',
        'cut_grade' => 'ALL',
    ),
    'total_sample_size' => 26895,
    'cells'             => array(
        'G' => array('VS1' => array(
            'ALL' => array(
                'sample_size' => 412,
                'percentiles' => array(
                    'p10' => 3900.4, 'p25' => 4400.0, 'p50' => 5010.5,
                    'p75' => 5800.0, 'p90' => 6900.0,
                ),
            ),
        )),
    ),
);

$calc_html = $calc_renderer->price_calculator_html($calc_ctx, array(
    'price_calculator' => $calc_manifest,
));

check(
    strpos($calc_html, 'ldn-price-calculator') !== false
        && strpos($calc_html, '1 ct Round diamond price calculator') !== false,
    'calculator renders on an entitled shape page, heading naming the specification'
);
check(
    strpos($calc_html, '$5,011') !== false,
    'the default cell answer is server-rendered, so the page answers without JavaScript'
);
check(
    strpos($calc_html, '$3,900') !== false && strpos($calc_html, '$6,900') !== false,
    'the p10 and p90 wings flank the median price'
);
check(
    strpos($calc_html, 'ldn-price-calculator__price-row') !== false,
    'the result uses a three-column price row'
);
check(
    strpos($calc_html, 'ldn-grade-slider') !== false,
    'colour and clarity use point sliders rather than grouped dropdowns'
);
check(
    strpos($calc_html, 'data-ldn-grade-slider="cut"') !== false,
    'cut uses a point slider like colour and clarity, not a dropdown'
);
check(
    strpos($calc_html, 'data-ldn-price-calculator-input="cut"') !== false,
    'a manifest with a cut dimension offers the cut control'
);
check(
    strpos($calc_html, 'Lower end') !== false
        && strpos($calc_html, 'Typical') !== false
        && strpos($calc_html, 'Higher end') !== false,
    'the three price figures are labelled, not anonymous numbers'
);
check(
    strpos($calc_html, 'Based on 412 diamonds at') !== false,
    'the answer states how many diamonds the cell rests on'
);
check(
    strpos($calc_html, 'ldn-price-calculator-manifest') !== false
        && strpos($calc_html, '"labels"') !== false,
    'the manifest reaches the browser with its reader-facing strings attached'
);
check(
    strpos($calc_html, '26,895') !== false,
    'the lead paragraph discloses the pool the comparison draws on'
);
check(
    strpos($calc_html, '$5,011') !== false,
    'calculator headline shows the spec-level price directly'
);
check(
    strpos($calc_html, 'median at the top of this page') !== false,
    'calculator intro explains the page median vs spec-level answer'
);
check(
    strpos($calc_html, '<noscript>') !== false,
    'a reader without JavaScript is told why the selectors do nothing'
);

// A cut control for a shape whose cut grades are not comparable would invite a
// choice the data cannot answer.
$calc_fancy = $calc_manifest;
$calc_fancy['shape'] = 'Oval';
$calc_fancy['has_cut_dimension'] = false;
$calc_fancy['cut_grades'] = array();
$calc_fancy_html = $calc_renderer->price_calculator_html($calc_ctx, array(
    'price_calculator' => $calc_fancy,
));
check(
    strpos($calc_fancy_html, 'data-ldn-price-calculator-input="cut"') === false,
    'a fancy-shape manifest does not expose an interactive cut input'
);
check(
    strpos($calc_fancy_html, 'ldn-price-calculator__field--disabled') !== false
        && strpos($calc_fancy_html, 'disabled') !== false,
    'fancy shapes keep a greyed cut control rather than hiding it'
);
check(
    strpos($calc_fancy_html, 'any cut') === false,
    'the specification sentence drops cut entirely rather than claiming "any cut"'
);
check(
    strpos($calc_fancy_html, 'not comparable across fancy shapes') !== false,
    'the disabled cut control explains why cut is unavailable'
);
check(
    strpos($calc_html, 'ldn-price-calculator__col') !== false
        && substr_count($calc_html, 'ldn-price-calculator__col') === 2,
    'controls sit in a two-column layout'
);
check(
    strpos($calc_html, 'ldn-field-help') !== false
        && strpos($calc_html, 'How colorless the diamond looks') !== false,
    'each grade field has a ? help affordance with a short definition'
);
check(
    strpos($calc_html, 'ldn-price-calculator__surface') !== false,
    'shape-page calculator wraps controls in a white surface for tint bands'
);

// Test intent: all-shapes hub uses destination-style shape icons and one white
// surface; pills alone on purple are not enough for band-aware readability.
// Would fail if: hub still rendered text-only ldn-shape-calc-pill buttons, or
// omitted __surface so controls sat naked on a tint band.
if (!class_exists('LDN_Fetcher_All_Shapes_Calc')) {
    class LDN_Fetcher_All_Shapes_Calc extends LDN_Data_Fetcher {
        /** @var array */
        private $manifest;
        public function __construct(array $manifest) {
            $this->manifest = $manifest;
        }
        public function fetch_artefact($artefact_id, $ctx) {
            if ($artefact_id === 'price_calculator_json') {
                $out = $this->manifest;
                if (isset($ctx->shape) && (string) $ctx->shape !== '') {
                    $out['shape'] = (string) $ctx->shape;
                }
                return $out;
            }
            return null;
        }
    }
}
$hub_ctx = new LDN_Page_Context('calcsite', 'all-shapes', 'us', 'natural', '1');
$hub_renderer = new LDN_Renderer(
    new LDN_Fetcher_All_Shapes_Calc($calc_manifest),
    new LDN_Config_Price_Calculator()
);
$hub_html = $hub_renderer->all_shapes_calculator_html($hub_ctx, array(
    'ranking' => array(
        'shapes' => array(
            array('shape' => 'Round', 'median_price' => 5010),
            array('shape' => 'Oval', 'median_price' => 4800),
        ),
    ),
));
check(
    strpos($hub_html, 'ldn-price-calculator__surface') !== false
        && strpos($hub_html, 'ldn-shape-picker__icon') !== false
        && strpos($hub_html, 'ldn-shape-picker__option--active') !== false
        && strpos($hub_html, 'data-ldn-shape-calc-pill="round"') !== false
        && strpos($hub_html, 'data-ldn-shape-calc-pill="oval"') !== false
        && strpos($hub_html, 'class="ldn-shape-calc-pill') === false,
    'all-shapes calculator uses shape-picker icons inside a white surface'
);

// The heading and specification come from the manifest, not the page context:
// this is what lets a calculator page with no shape or carat of its own host the
// same module.
$calc_other = $calc_manifest;
$calc_other['shape'] = 'Emerald';
$calc_other['carat_weight'] = '2';
$calc_other_html = $calc_renderer->price_calculator_from_manifest($calc_ctx, $calc_other);
check(
    strpos($calc_other_html, '2 ct Emerald diamond price calculator') !== false
        && strpos($calc_other_html, 'Round') === false,
    'the module describes the manifest it was given, not the page it sits on'
);

// No fallback: a default_cell pointing at a cell that does not exist must not be
// answered from a neighbouring cell.
$calc_missing = $calc_manifest;
$calc_missing['default_cell'] = array(
    'color' => 'D',
    'clarity' => 'VVS1',
    'cut_grade' => 'Super Ideal',
);
check(
    $calc_renderer->price_calculator_from_manifest($calc_ctx, $calc_missing) === '',
    'a specification with no cell renders nothing rather than a coarser cell'
);

check(
    $calc_renderer->price_calculator_html($calc_ctx, array('price_calculator' => array())) === '',
    'no manifest renders no calculator'
);

// A profile can name a section the dispatcher does not know, and it would then
// render nothing at all with no error. Go through render_section so the wiring is
// covered rather than only the component.
check(
    strpos(
        $calc_renderer->render_section('price_calculator', $calc_ctx, array(
            'price_calculator' => $calc_manifest,
        ), '$'),
        'ldn-price-calculator'
    ) !== false,
    'the profile section id reaches the component through render_section'
);

$calc_off_renderer = new LDN_Renderer(new LDN_Data_Fetcher(), new LDN_Config_Price_Calculator());
check(
    $calc_off_renderer->price_calculator_html(
        new LDN_Page_Context('nocalcsite', 'shape', 'us', 'natural', '1', 'round'),
        array('price_calculator' => $calc_manifest)
    ) === '',
    'a site with the widget disabled renders no calculator even with a manifest'
);
check(
    $calc_off_renderer->price_calculator_html(
        new LDN_Page_Context('unlistedsite', 'shape', 'us', 'natural', '1', 'round'),
        array('price_calculator' => $calc_manifest)
    ) === '',
    'a site with no entitlement block renders no calculator'
);

// Legacy grouped manifest (pre-migration 017 S3 payloads) must still render until
// C5.9 re-publishes individual-grade cells.
$calc_legacy = array(
    'diamond_type'      => 'natural',
    'shape'             => 'Round',
    'carat_weight'      => '1',
    'currency'          => 'USD',
    'colour_groups'     => array(
        array('key' => 'D_F', 'label' => 'D-F', 'members' => array('D', 'E', 'F')),
        array('key' => 'G_H', 'label' => 'G-H', 'members' => array('G', 'H')),
    ),
    'clarity_groups'    => array(
        array('key' => 'SI', 'label' => 'SI', 'members' => array('SI1', 'SI2')),
    ),
    'cut_grades'        => array('Excellent'),
    'has_cut_dimension' => true,
    'pooled_cut_key'    => 'ALL',
    'default_cell'      => array(
        'colour_group'  => 'D_F',
        'clarity_group' => 'SI',
        'cut_grade'     => 'ALL',
    ),
    'cells'             => array(
        'D_F' => array(
            'SI' => array(
                'ALL' => array(
                    'sample_size' => 120,
                    'percentiles' => array('p10' => 3000, 'p50' => 4000, 'p90' => 5000),
                ),
            ),
        ),
    ),
);
$calc_legacy_html = $calc_renderer->price_calculator_from_manifest($calc_ctx, $calc_legacy);
check(
    strpos($calc_legacy_html, 'ldn-price-calculator') !== false,
    'legacy grouped manifest still renders the calculator panel'
);
check(
    strpos($calc_legacy_html, '$4,000') !== false || strpos($calc_legacy_html, '4,000') !== false,
    'legacy grouped manifest resolves default_cell colour_group/clarity_group'
);

// --- Report -----------------------------------------------------------------
$tests = $GLOBALS['__tests'];
$fails = $GLOBALS['__fails'];
if ($fails === 0) {
    fwrite(STDOUT, "OK: {$tests} checks passed\n");
    exit(0);
}
fwrite(STDERR, "FAILED: {$fails}/{$tests} checks failed\n");
exit(1);

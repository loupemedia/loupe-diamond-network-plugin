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
    substr_count($carat_html, '<tr>') === 3, // header + 2 carat rows
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

// Test intent: Ringspo top-level uses fixed short H2 + median-variation copy (not C1).
// Would fail if: Ringspo still inlined C1 type_comparison or kept the long country H2.
$ringspo_market = $renderer->market_overview_table_html($ringspo_top, $market_bag);
check(
    strpos($ringspo_market, 'Natural vs lab-grown prices by carat') !== false,
    'Ringspo top-level uses the shortened carat-table H2'
);
check(
    strpos($ringspo_market, 'constantly changing') !== false,
    'Ringspo top-level uses the fixed median-variation comparison copy'
);
check(
    strpos($ringspo_market, 'Natural and lab-grown stones differ') === false,
    'Ringspo top-level does not inline C1 type_comparison under the table'
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
    'stats_html "Current price" uses the median ($3,711), not the mean'
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
    strpos($hero_cards, '$3,510') !== false && strpos($hero_cards, 'Current price') !== false,
    'hero_stats_html leads with the median current price'
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

// Test intent: carat tiers table shows price-per-carat and Ringspo how-to-read copy.
// Would fail if: PPC column missing or Ringspo intro omitted from the table section.
$ringspo_type_ctx = new LDN_Page_Context('ringspo', 'diamond-type', 'us', 'natural');
$tiers_html = $renderer->carat_tiers_table_html(
    $ringspo_type_ctx,
    $type_summary_bag,
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
    strpos($tiers_html, 'do not increase proportionally') !== false
        && strpos($tiers_html, 'Click a carat') !== false,
    'Ringspo carat tiers table includes how-to-read and PPC commentary'
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
        && strpos($cc_with_size, 'ldn-price-size-card') !== false,
    'color_clarity section appends the size dimensions card after the heatmap'
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
// Test intent: a summary-backed chart carries its factual statement INSIDE the
// Plotly target div, so the data is in the HTML source for anything that does not
// run JavaScript, and Plotly.newPlot() wipes it when the chart draws (no visible
// duplication of intro_html / freshness_html for ordinary readers).
// Would fail if: the fallback were emitted as a sibling of the target div or its
// own <section> (permanently visible, duplicating the intro), if it were omitted
// entirely (empty div — the CP54_04 gap), or if it were injected unescaped.
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

// --- Report -----------------------------------------------------------------
$tests = $GLOBALS['__tests'];
$fails = $GLOBALS['__fails'];
if ($fails === 0) {
    fwrite(STDOUT, "OK: {$tests} checks passed\n");
    exit(0);
}
fwrite(STDERR, "FAILED: {$fails}/{$tests} checks failed\n");
exit(1);

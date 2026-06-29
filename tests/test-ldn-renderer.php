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
 */

error_reporting(E_ALL);

// --- Minimal WordPress shims -------------------------------------------------
define('ABSPATH', __DIR__ . '/');

if (!function_exists('__')) {
    function __($s, $d = null) { return $s; }
}
if (!function_exists('esc_html')) {
    function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
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

require_once __DIR__ . '/../includes/class-ldn-page-context.php';

// --- Minimal collaborator stubs (constructor type hints) ---------------------
if (!class_exists('LDN_Data_Fetcher')) {
    class LDN_Data_Fetcher {}
}
if (!class_exists('LDN_Config')) {
    class LDN_Config {
        public function get_content_profile($site_id) { return array(); }
        public function get_currency($site_id, $country) { return 'USD'; }
        public function get_url_structure($site_id) {
            return array(
                'level_3'      => 'diamond-prices/{type}/{carat}',
                'level_4'      => 'diamond-prices/{type}/{carat}/{shape}',
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
    strpos($type_comparison_html, 'Natural and lab-grown stones differ') !== false,
    'render_section renders type_comparison editorial copy (was silently dropped)'
);
check(
    strpos($type_comparison_html, '<h2>Natural vs Lab-Grown</h2>') !== false,
    'type_comparison uses the friendly heading override'
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
    strpos($carat_html, 'https://example.com/diamond-prices/natural/1-carat/') !== false,
    'carat table links natural price down to the type+carat all-shapes page'
);
check(
    strpos($carat_html, 'https://example.com/diamond-prices/lab-grown/1-carat/') !== false,
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
);
$market_html = $renderer->market_overview_table_html($top_ctx, $market_bag);
check(
    strpos($market_html, 'ldn-market-discount-chart') !== false,
    'top-level hero renders discount chart before overview tables when entitled'
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
    strpos($ranking_html, 'https://example.com/diamond-prices/natural/1-carat/round/') !== false,
    'shapes ranking table links each shape down to its shape page'
);
check(
    strpos($ranking_html, 'https://example.com/diamond-prices/natural/1-carat/oval/') !== false,
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
    strpos($ladder_html, 'https://example.com/diamond-prices/natural/2-carat/round/') !== false,
    'carat ladder links non-page carat rows to sibling shape pages'
);
check(
    strpos($ladder_html, 'https://example.com/diamond-prices/natural/1-carat/') !== false,
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

// --- 11. stats_html change row tracks the all_shapes policy period -----------
// Test intent: the headline stats change row uses the aggregate intro period
// (Loupe = 12 months) and is omitted for snapshot families.
// Would fail if: stat_specs kept its hardcoded "7-day change" row.
$stats_summary = array(
    'current_price' => 5000,
    'num_diamonds'  => 100,
    'time_series'   => array(
        'change_12_months' => 8.5,
        'change_7_days'    => 1.1,
    ),
);
$loupe_stats = $loupe_renderer->stats_html($all_shapes_ctx, $stats_summary, '$');
check(
    strpos($loupe_stats, '12-month change') !== false,
    'stats_html renders a 12-month change row from the all_shapes policy'
);
check(
    strpos($loupe_stats, '7-day change') === false,
    'stats_html no longer renders the hardcoded 7-day change row'
);

$snapshot_stats = $snapshot_renderer->stats_html($all_shapes_ctx, $stats_summary, '$');
check(
    strpos($snapshot_stats, 'change</dt>') === false,
    'stats_html omits the change row for snapshot families (show_change:false)'
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
// Test intent: intro_text renders alone before the hero; analysis + consolidated
// shape_analysis render after; legacy intro/ranking_summary keys are ignored.
// Would fail if: overview_dynamic still concatenated all five keys, or legacy
// duplicate paragraphs leaked through overview_detail_dynamic.
$copy_bag = array(
    'copy' => array('sections' => array(
        'intro_text'      => 'Opening paragraph about prices.',
        'analysis'        => 'Trend paragraph here.',
        'shape_analysis'  => 'Oval leads; Round trails. We track 58,316 stones.',
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
$detail_copy = $renderer->copy_dynamic_html('overview_detail_dynamic', $all_shapes_ctx, $copy_bag);
check(
    strpos($detail_copy, 'Trend paragraph') !== false
        && strpos($detail_copy, 'Oval leads') !== false,
    'overview_detail_dynamic renders analysis + shape_analysis after hero'
);
check(
    strpos($detail_copy, 'Legacy duplicate') === false,
    'overview_detail_dynamic ignores legacy intro/ranking_summary keys'
);

// --- 16. diamond-type intro from type_summary fallback (CP3) -----------------
// Test intent: type_overview_dynamic leads with useful copy from type-summary.json
// when copy.json is absent; C5.8 Loupe template is the long-term source of truth.
// Would fail if: type_overview fell through to empty stats_html with no intro.
$type_summary_bag = array(
    'type_summary' => array(
        'aggregate' => array(
            'carat_count'           => 19,
            'most_popular_carat'    => '1',
            'weighted_median_price' => 3873,
            'total_sample_size'     => 510000,
        ),
    ),
    'copy'    => null,
    'static'  => null,
    'summary' => array(),
);
$type_intro = $renderer->type_intro_html($type_ctx, $type_summary_bag, 'USD');
check(
    strpos($type_intro, '19 carat weights') !== false,
    'type_intro_html cites carat breadth from type-summary aggregate'
);
check(
    strpos($type_intro, 'most searched weight') !== false && strpos($type_intro, '$3,873') !== false,
    'type_intro_html cites popular carat median in whole dollars'
);
$type_overview = $renderer->render_section('type_overview_dynamic', $type_ctx, $type_summary_bag, 'USD');
check(
    strpos($type_overview, '19 carat weights') !== false,
    'type_overview_dynamic falls back to type_intro_html when copy.json is absent'
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

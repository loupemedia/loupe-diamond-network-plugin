<?php
/**
 * Standalone unit tests for LDN_Schema (PRD-005 CP54).
 *
 * The plugin has no PHPUnit/WP test harness yet, so this is a self-contained
 * runner: it stubs the handful of WordPress functions LDN_Schema touches and
 * asserts the structured-data CONTRACT (rules, not a snapshot of current output).
 *
 * Run:  php loupe-diamond-network/tests/test-ldn-schema.php
 *
 * Test intent (the rules this file enforces):
 *   1. schema_type drives the primary node set:
 *        market_data          → Dataset (no Article)
 *        market_data_article  → Dataset + Article (only when 'article' feature on)
 *        hybrid/recommendation→ Dataset + ItemList of WebPages (not Product/Offer)
 *        educational_content  → Article (when 'article' on) instead of Dataset
 *   2. FAQPage is emitted ONLY when 'faq' is in schema_features AND pairs exist.
 *   3. BreadcrumbList is emitted ONLY with >= 2 crumbs.
 *   4. Dataset carries dateModified + spatialCoverage when date + country known,
 *      license, measurementTechnique, and a price PropertyValue with unitCode.
 *      WebPage.isPartOf references WebSite; WebPage.mainEntity references Dataset.
 *      Headline measure is Median price (not Current price / trimmed mean).
 *   5. variableMeasured resolves nested (C5) and legacy-flat summary shapes.
 *   6. Size-page helpers: keywords_for() is module-scoped (no price fallback),
 *      analysis_date reads generated_date, page_item_list_node is WebPage-only.
 *
 * Each test would fail for a concrete, documented reason — see asserts below.
 */

error_reporting(E_ALL);

// --- Minimal WordPress shims -------------------------------------------------
define('ABSPATH', __DIR__ . '/');

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
}
if (!function_exists('home_url')) {
    function home_url($path = '/') { return 'https://modernjeweler.com' . $path; }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($s) { return rtrim($s, '/') . '/'; }
}
if (!function_exists('__')) {
    function __($s, $d = null) { return $s; }
}

require_once __DIR__ . '/../includes/class-ldn-stat-specs.php';
require_once __DIR__ . '/../includes/class-ldn-page-context.php';
require_once __DIR__ . '/../includes/class-ldn-schema.php';

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

/** Find the first @graph node of a given @type. */
function node_of(array $graph, $type) {
    foreach ($graph as $node) {
        if (isset($node['@type']) && $node['@type'] === $type) {
            return $node;
        }
    }
    return null;
}

// --- Fixtures ---------------------------------------------------------------
$site = array(
    'brand_name' => 'Modern Jeweler',
    'domain'     => 'modernjeweler.com',
    'countries'  => array(array('code' => 'us', 'full_name' => 'United States', 'currency' => 'USD')),
);

// Nested (C5 contract) summary payload.
$summary_nested = array(
    'time_series'  => array('current_price' => 3730, 'analysis_date' => '2026-06-22'),
    'distribution' => array(
        'median_price' => 3700,
        'sample_size'  => 30155,
        'price_range'  => array('min' => 990, 'max' => 25292),
    ),
);

// Legacy-flat summary payload.
$summary_flat = array(
    'current_price' => 3730,
    'median_price'  => 3700,
    'num_diamonds'  => 30155,
    'min_price'     => 990,
    'max_price'     => 25292,
    'analysis_date' => '2026-06-22',
);

$ctx = new LDN_Page_Context('modernjeweler', 'shape', 'us', 'natural', '1', 'round');
$canonical = 'https://modernjeweler.com/diamond-prices/natural/1-carat/round/';
$breadcrumb = array(
    array('name' => 'Home', 'url' => 'https://modernjeweler.com/'),
    array('name' => 'Diamond Prices', 'url' => 'https://modernjeweler.com/diamond-prices/'),
    array('name' => 'Round', 'url' => $canonical),
);
$faq = array(array('question' => 'Is a 1 carat round expensive?', 'answer' => 'It depends on cut.'));

$schema = new LDN_Schema();

// === Rule 1: market_data → Dataset, no Article =============================
$profile_md = array('schema_type' => 'market_data', 'schema_features' => array('faq'),
    'schema_dataset_name_suffix' => ' - Local Market Pricing');
$g = $schema->build_graph($ctx, $summary_nested, $profile_md, $site, 'USD', $canonical, $breadcrumb, $faq);
$dataset = node_of($g, 'Dataset');
check($dataset !== null, 'market_data must emit a Dataset node');
check(node_of($g, 'Article') === null, 'market_data must NOT emit an Article node');
check(node_of($g, 'Organization') !== null, 'graph must include the publisher Organization node');

// === Rule 1b: top-level description must not double "diamond" ==============
$top_ctx = new LDN_Page_Context('modernjeweler', 'top-level', 'us');
$top_desc = $schema->dataset_description($top_ctx, $summary_nested, 'USD');
check(
    strpos($top_desc, 'diamond diamonds') === false,
    'top-level dataset description must not read "diamond diamonds"'
);
check(
    strpos($top_desc, 'Market pricing data for diamonds.') === 0,
    'top-level dataset description uses bare "diamonds" when no shape/type context'
);

// Test intent: top-level meta/Dataset description uses market-overview scale fields.
// Would fail if: hub still fell through to the generic "Market pricing data for diamonds."
$hub_overview = array(
    'analysis_date'          => '2026-07-29',
    'total_diamonds_tracked' => 781852,
    'natural'                => array('combo_count' => 167, 'total_sample_size' => 400000),
    'lab_grown'              => array('combo_count' => 209, 'total_sample_size' => 381852),
);
$hub_desc = $schema->dataset_description($top_ctx, $hub_overview, 'USD');
check(
    strpos($hub_desc, '781,852') !== false,
    'top-level Dataset description includes total_diamonds_tracked'
);
check(
    strpos($hub_desc, '167 natural') !== false && strpos($hub_desc, '209 lab-grown') !== false,
    'top-level Dataset description includes natural/lab combo counts'
);
check(
    strpos($hub_desc, 'Market pricing data for diamonds.') === false,
    'top-level Dataset description does not use the generic shape-page fallback when overview is present'
);

// Test intent: all-shapes meta/Dataset leads with "by shape" + country and does
// not use the generic "Market pricing data for…" shape-page pattern.
// Would fail if: all-shapes still fell through to the generic median template.
$all_ctx = new LDN_Page_Context('ringspo', 'all-shapes', 'us', 'lab-grown', '1');
$all_summary = array(
    'analysis_date' => '2026-07-29',
    'current_price' => 949,
    'num_diamonds'  => 10370,
    'aggregate'     => array('weighted_median_price' => 949),
);
$all_desc = $schema->dataset_description($all_ctx, $all_summary, 'USD', null, $site);
check(
    strpos($all_desc, 'by shape') !== false,
    'all-shapes Dataset description includes "by shape"'
);
check(
    strpos($all_desc, 'United States') !== false,
    'all-shapes Dataset description names the country in full'
);
check(
    strpos($all_desc, 'lab-grown') !== false,
    'all-shapes Dataset description includes diamond type'
);
check(
    strpos($all_desc, 'Market pricing data for') === false,
    'all-shapes Dataset description does not use the generic shape-page fallback'
);
check(
    strpos($all_desc, '949') !== false && strpos($all_desc, '10,370') !== false,
    'all-shapes Dataset description includes typical price and sample size'
);
check(
    strpos($all_desc, 'Typical price') !== false,
    'all-shapes Dataset description names the composite figure Typical price'
);
check(
    stripos($all_desc, 'Weighted median') === false && stripos($all_desc, 'Median price') === false,
    'all-shapes Dataset description does not claim a stone median'
);

// Test intent: Ringspo all-shapes Dataset.name matches the page H1.
// Would fail if: schema headline still said "Prices by Shape - United States".
check(
    $schema->headline($all_ctx, $site)
        === '1 Carat Lab-Grown Diamond in the United States',
    'Ringspo all-shapes schema headline is "{carat} {type} Diamond in the {Country}"'
);

// Test intent: all-shapes keywords differentiate from type (no bare type prices)
// and shape (no named cut) pages.
// Would fail if: keywords stayed ["1 carat diamond","diamond prices","diamond price chart"].
$profile_kw = array('schema_type' => 'hybrid', 'schema_features' => array());
$g_all = $schema->build_graph(
    $all_ctx, $all_summary, $profile_kw, $site, 'USD',
    'https://ringspo.com/us/diamond-prices/lab-grown/1-carat/',
    $breadcrumb, array()
);
$all_dataset = node_of($g_all, 'Dataset');
$all_kw = isset($all_dataset['keywords']) && is_array($all_dataset['keywords'])
    ? $all_dataset['keywords']
    : array();
$all_kw_joined = implode('|', $all_kw);
check(
    strpos($all_kw_joined, 'by shape') !== false,
    'all-shapes keywords include a "by shape" phrase'
);
check(
    strpos($all_kw_joined, 'lab-grown') !== false || strpos($all_kw_joined, 'lab grown') !== false,
    'all-shapes keywords include the diamond type'
);
check(
    !in_array('lab-grown diamond prices', $all_kw, true),
    'all-shapes keywords omit bare type-hub phrase "lab-grown diamond prices"'
);
check(
    !in_array('round diamond', $all_kw, true),
    'all-shapes keywords omit named-shape phrases that belong on shape pages'
);

// Test intent: all-shapes JSON-LD cites Typical price, not Median price, for the
// composite headline measure. Would fail if: measured_specs ignored page level.
$all_measured = isset($all_dataset['variableMeasured']) ? $all_dataset['variableMeasured'] : array();
$all_typical = null;
$all_names = array();
foreach ($all_measured as $pv) {
    $all_names[] = $pv['name'] ?? '';
    if (($pv['name'] ?? '') === 'Typical price') {
        $all_typical = $pv;
    }
}
check($all_typical !== null, 'all-shapes variableMeasured must include Typical price');
check(!in_array('Median price', $all_names, true),
    'all-shapes variableMeasured must not include Median price for the composite figure');
check(isset($all_typical['value']) && $all_typical['value'] === 949,
    'all-shapes Typical price value resolves from the composite summary field');

// === Rule 4: Dataset enrichment ============================================
check(isset($dataset['dateModified']) && $dataset['dateModified'] === '2026-06-22',
    'Dataset.dateModified must be the analysis date (freshness signal)');
check(isset($dataset['temporalCoverage']) && $dataset['temporalCoverage'] === '2026-06-22',
    'Dataset.temporalCoverage must be set from the analysis date');
check(isset($dataset['spatialCoverage']['name']) && $dataset['spatialCoverage']['name'] === 'United States',
    'Dataset.spatialCoverage must use the country full name');
check(isset($dataset['url']) && $dataset['url'] === $canonical, 'Dataset.url must be the canonical page URL');
check(isset($dataset['publisher']['@id']), 'Dataset.publisher must reference the Organization @id');
check(!isset($dataset['isPartOf']),
    'Dataset must not use isPartOf → WebSite (that relation belongs on WebPage)');
check(strpos((string) $dataset['name'], ' - Local Market Pricing') !== false,
    'Dataset.name must include the profile suffix');
check(isset($dataset['license']) && $dataset['license'] === LDN_Schema::LICENSE_URL,
    'Dataset.license must be the shared CC BY-NC URL');
check(
    isset($dataset['measurementTechnique'])
        && $dataset['measurementTechnique'] === LDN_Schema::MEASUREMENT_TECHNIQUE,
    'Dataset.measurementTechnique must declare the price-index method'
);

$webpage = node_of($g, 'WebPage');
check($webpage !== null, 'graph must include a WebPage node');
check(isset($webpage['isPartOf']['@id']) && strpos($webpage['isPartOf']['@id'], '#website') !== false,
    'WebPage.isPartOf must reference the site WebSite @id');
check(
    isset($webpage['mainEntity']['@id']) && $webpage['mainEntity']['@id'] === $canonical . '#dataset',
    'WebPage.mainEntity must point at the Dataset @id'
);

// Test intent: cited headline is Median price (p50), with ISO unitCode, not the
// trimmed-mean current_price series. Would fail if Current price were still emitted
// or Median read time_series.current_price (3730 vs 3700 in this fixture).
$measured = isset($dataset['variableMeasured']) ? $dataset['variableMeasured'] : array();
$median = null;
$names = array();
foreach ($measured as $pv) {
    $names[] = $pv['name'] ?? '';
    if (($pv['name'] ?? '') === 'Median price') { $median = $pv; }
}
check($median !== null, 'variableMeasured must include Median price');
check(!in_array('Current price', $names, true),
    'variableMeasured must not include Current price (trimmed mean)');
check(isset($median['unitCode']) && $median['unitCode'] === 'USD',
    'Median price PropertyValue must carry ISO currency in unitCode');
check(!isset($median['unitText']),
    'Median price PropertyValue must not also carry unitText');
check(isset($median['value']) && $median['value'] === 3700,
    'Median price value must come from distribution.median_price, not current_price');

// === Rule 2: FAQ gating ====================================================
check(node_of($g, 'FAQPage') !== null, "FAQPage must emit when 'faq' feature on and pairs present");

$profile_nofaq = array('schema_type' => 'market_data', 'schema_features' => array());
$g_nofaq = $schema->build_graph($ctx, $summary_nested, $profile_nofaq, $site, 'USD', $canonical, $breadcrumb, $faq);
check(node_of($g_nofaq, 'FAQPage') === null, "FAQPage must NOT emit when 'faq' not in schema_features");

$g_nopairs = $schema->build_graph($ctx, $summary_nested, $profile_md, $site, 'USD', $canonical, $breadcrumb, array());
check(node_of($g_nopairs, 'FAQPage') === null, 'FAQPage must NOT emit when there are no FAQ pairs');

// === Rule 3: breadcrumb gating ============================================
check(node_of($g, 'BreadcrumbList') !== null, 'BreadcrumbList must emit with >= 2 crumbs');
$g_onecrumb = $schema->build_graph($ctx, $summary_nested, $profile_md, $site, 'USD', $canonical,
    array(array('name' => 'Home', 'url' => 'https://modernjeweler.com/')), $faq);
check(node_of($g_onecrumb, 'BreadcrumbList') === null, 'BreadcrumbList must NOT emit with < 2 crumbs');

// === Rule 1: market_data_article → Dataset + Article ======================
$profile_article = array('schema_type' => 'market_data_article', 'schema_features' => array('article', 'faq'));
$g_art = $schema->build_graph($ctx, $summary_nested, $profile_article, $site, 'USD', $canonical, $breadcrumb, $faq);
check(node_of($g_art, 'Dataset') !== null, 'market_data_article must emit a Dataset');
$article = node_of($g_art, 'Article');
check($article !== null, "market_data_article must emit an Article when 'article' feature on");
check(isset($article['datePublished']) && $article['datePublished'] === '2026-06-22',
    'Article.datePublished must be the analysis date');

$profile_article_off = array('schema_type' => 'market_data_article', 'schema_features' => array());
$g_art_off = $schema->build_graph($ctx, $summary_nested, $profile_article_off, $site, 'USD', $canonical, $breadcrumb, array());
check(node_of($g_art_off, 'Article') === null, "Article must NOT emit when 'article' feature off");

// === Rule 1: hybrid → Dataset + ItemList of WebPages (not Product/Offer) ===
// Test intent: all-shapes ranking rows are child price pages, not buyable SKUs.
// Would fail if: item_list_node still emitted Product + Offer.
$profile_hybrid = array('schema_type' => 'hybrid', 'schema_features' => array());
$items = array(
    array('name' => 'Round', 'url' => $canonical . 'round/'),
    array('name' => 'Oval', 'url' => $canonical . 'oval/'),
);
$g_hy = $schema->build_graph($ctx, $summary_nested, $profile_hybrid, $site, 'USD', $canonical, $breadcrumb, array(), $items);
$list = node_of($g_hy, 'ItemList');
check($list !== null, 'hybrid must emit an ItemList when items are present');
check(isset($list['numberOfItems']) && $list['numberOfItems'] === 2, 'ItemList must count its items');
$hy_item = isset($list['itemListElement'][0]['item']) && is_array($list['itemListElement'][0]['item'])
    ? $list['itemListElement'][0]['item']
    : array();
check(($hy_item['@type'] ?? '') === 'WebPage', 'hybrid ItemList entries are WebPages, not Products');
check(!isset($hy_item['offers']), 'hybrid ItemList must not attach Offer');
check(($hy_item['url'] ?? '') === $canonical . 'round/', 'hybrid ItemList WebPage keeps the child URL');

$g_hy_noitems = $schema->build_graph($ctx, $summary_nested, $profile_hybrid, $site, 'USD', $canonical, $breadcrumb, array(), array());
check(node_of($g_hy_noitems, 'ItemList') === null, 'hybrid must NOT emit an empty ItemList');

// === Rule 1: educational_content → Article instead of Dataset =============
$profile_edu = array('schema_type' => 'educational_content', 'schema_features' => array('article'));
$g_edu = $schema->build_graph($ctx, $summary_nested, $profile_edu, $site, 'USD', $canonical, $breadcrumb, array());
check(node_of($g_edu, 'Article') !== null, "educational_content must emit an Article when 'article' feature on");
check(node_of($g_edu, 'Dataset') === null, 'educational_content (article) must not emit a Dataset');

// === Rule 5: legacy-flat summary resolves the same measures ===============
$g_flat = $schema->build_graph($ctx, $summary_flat, $profile_md, $site, 'USD', $canonical, $breadcrumb, $faq);
$dataset_flat = node_of($g_flat, 'Dataset');
check(isset($dataset_flat['dateModified']) && $dataset_flat['dateModified'] === '2026-06-22',
    'flat payload: dateModified must resolve from top-level analysis_date');
$has_sample = false;
foreach (($dataset_flat['variableMeasured'] ?? array()) as $pv) {
    if (($pv['name'] ?? '') === 'Diamonds analyzed' && ($pv['value'] ?? null) === 30155) { $has_sample = true; }
}
check($has_sample, 'flat payload: Diamonds analyzed must resolve from num_diamonds');

// Test intent: currency PropertyValue values are whole dollars (same as on-page
// format_stat). Would fail if value was left as a raw float (144865.88).
$summary_float = $summary_nested;
$summary_float['distribution']['price_range']['max'] = 144865.88;
$g_round = $schema->build_graph($ctx, $summary_float, $profile_md, $site, 'USD', $canonical, $breadcrumb, array());
$high = null;
foreach ((node_of($g_round, 'Dataset')['variableMeasured'] ?? array()) as $pv) {
    if (($pv['name'] ?? '') === 'Highest price') { $high = $pv; }
}
check($high !== null && $high['value'] === 144866,
    'Highest price must round to the nearest whole dollar');

// Test intent: educational Article pages still get a WebPage whose mainEntity
// is the Article, not a Dataset. Would fail if WebPage always pointed at #dataset.
$g_edu_page = $schema->build_graph($ctx, $summary_nested, $profile_edu, $site, 'USD', $canonical, $breadcrumb, array());
$edu_page = node_of($g_edu_page, 'WebPage');
check($edu_page !== null, 'educational_content still emits a WebPage');
check(
    isset($edu_page['mainEntity']['@id']) && strpos($edu_page['mainEntity']['@id'], '#article') !== false,
    'educational WebPage.mainEntity points at Article when there is no Dataset'
);

// === Renders valid JSON =====================================================
$html = $schema->render($ctx, $summary_nested, $profile_md, $site, 'USD', $canonical, $breadcrumb, $faq);
check(strpos($html, '<script type="application/ld+json">') === 0, 'render() must output an ld+json script tag');
$json = trim(str_replace(array('<script type="application/ld+json">', '</script>'), '', $html));
$decoded = json_decode($json, true);
check(json_last_error() === JSON_ERROR_NONE, 'render() output must be valid JSON');
check(isset($decoded['@context']) && isset($decoded['@graph']), 'render() must wrap nodes in @context + @graph');

// === Size helpers: keywords, generated_date, WebPage ItemList ==============
// Test intent: size pages get size-chart keywords (never the price fallback),
// analysis_date reads generated_date, and page_item_list_node emits WebPages.
// Would fail if: keywords() fell through to "diamond prices", DATE_PATHS
// skipped generated_date, or the list reused Product/Offer.
$size_kw_ctx = new LDN_Page_Context('ringspo', 'size-shape-hub', 'us', null, null, 'oval', 'size');
$size_kw = $schema->keywords_for($size_kw_ctx);
check(in_array('oval diamond size chart', $size_kw, true), 'size-shape-hub keywords include the chart phrase');
check(!in_array('diamond prices', $size_kw, true), 'size-shape-hub keywords must not include diamond prices');
$price_kw = $schema->keywords_for($ctx);
check(in_array('diamond prices', $price_kw, true), 'price shape keywords still include diamond prices');

check(
    $schema->analysis_date(array('generated_date' => '2026-08-18')) === '2026-08-18',
    'analysis_date reads size-artefact generated_date'
);
check(
    $schema->analysis_date(array('analysis_date' => '2026-06-22', 'generated_date' => '2026-08-18')) === '2026-06-22',
    'analysis_date prefers analysis_date over generated_date'
);

$page_list = $schema->page_item_list_node(
    'Oval Diamond Size Chart',
    array(
        array('name' => '1 carat Oval diamond size', 'url' => 'https://ringspo.test/diamond-size/oval/1-carat/'),
    ),
    'https://ringspo.test/diamond-size/oval/'
);
check($page_list !== null && ($page_list['@type'] ?? '') === 'ItemList', 'page_item_list_node emits ItemList');
check(
    isset($page_list['itemListElement'][0]['item']['@type'])
        && $page_list['itemListElement'][0]['item']['@type'] === 'WebPage',
    'page_item_list_node items are WebPages'
);
check(
    !isset($page_list['itemListElement'][0]['item']['offers']),
    'page_item_list_node must not attach Offer'
);
check($schema->page_item_list_node('Empty', array(), '') === null, 'page_item_list_node is null when there are no pages');

// Test intent: tool pages (calculator) emit WebPage.mainEntity → WebApplication
// with a Google category, a free Offer, slashed @id, and breadcrumbs. No Dataset.
// Would fail if: render_webapp still emitted only Organization + WebApplication,
// or @id used calculator#webapp without the trailing slash.
$calc_ctx = new LDN_Page_Context('ringspo', 'calculator', 'us', null, null, null, 'calculator');
$calc_html = $schema->render_webapp(
    $calc_ctx,
    $site,
    'Diamond Price Calculator',
    'Free diamond price calculator.',
    'https://modernjeweler.com/us/diamond-prices/calculator',
    'FinanceApplication',
    array(
        array('name' => 'Modern Jeweler', 'url' => 'https://modernjeweler.com/'),
        array('name' => 'Diamond Prices', 'url' => 'https://modernjeweler.com/us/diamond-prices/'),
        array('name' => 'Calculator', 'url' => 'https://modernjeweler.com/us/diamond-prices/calculator/'),
    )
);
$calc_json = trim(str_replace(array('<script type="application/ld+json">', '</script>'), '', $calc_html));
$calc_doc = json_decode($calc_json, true);
$calc_graph = (isset($calc_doc['@graph']) && is_array($calc_doc['@graph'])) ? $calc_doc['@graph'] : array();
$calc_page = node_of($calc_graph, 'WebPage');
$calc_app = node_of($calc_graph, 'WebApplication');
check($calc_page !== null && $calc_app !== null, 'calculator render_webapp emits WebPage and WebApplication');
check(node_of($calc_graph, 'Dataset') === null, 'calculator render_webapp does not emit Dataset');
check(
    isset($calc_app['@id']) && $calc_app['@id'] === 'https://modernjeweler.com/us/diamond-prices/calculator/#webapp',
    'calculator WebApplication @id uses the slashed canonical'
);
check(
    isset($calc_page['mainEntity']['@id']) && $calc_page['mainEntity']['@id'] === $calc_app['@id'],
    'calculator WebPage.mainEntity points at WebApplication'
);
check(
    isset($calc_app['offers']['price']) && (int) $calc_app['offers']['price'] === 0,
    'calculator WebApplication offers a free price'
);
check(node_of($calc_graph, 'BreadcrumbList') !== null, 'calculator render_webapp emits BreadcrumbList');

// --- Summary ----------------------------------------------------------------
$total = $GLOBALS['__tests'];
$fails = $GLOBALS['__fails'];
if ($fails === 0) {
    fwrite(STDOUT, "OK: {$total} assertions passed\n");
    exit(0);
}
fwrite(STDERR, "{$fails}/{$total} assertions FAILED\n");
exit(1);

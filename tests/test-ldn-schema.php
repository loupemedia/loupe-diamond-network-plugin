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
 *        hybrid/recommendation→ Dataset + ItemList (only when items present)
 *        educational_content  → Article (when 'article' on) instead of Dataset
 *   2. FAQPage is emitted ONLY when 'faq' is in schema_features AND pairs exist.
 *   3. BreadcrumbList is emitted ONLY with >= 2 crumbs.
 *   4. Dataset carries dateModified + spatialCoverage when date + country known,
 *      and a price PropertyValue carries the ISO currency in unitText.
 *   5. variableMeasured resolves nested (C5) and legacy-flat summary shapes.
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
    'schema_dataset_name_suffix' => '— Local Market Pricing');
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

// === Rule 4: Dataset enrichment ============================================
check(isset($dataset['dateModified']) && $dataset['dateModified'] === '2026-06-22',
    'Dataset.dateModified must be the analysis date (freshness signal)');
check(isset($dataset['temporalCoverage']) && $dataset['temporalCoverage'] === '2026-06-22',
    'Dataset.temporalCoverage must be set from the analysis date');
check(isset($dataset['spatialCoverage']['name']) && $dataset['spatialCoverage']['name'] === 'United States',
    'Dataset.spatialCoverage must use the country full name');
check(isset($dataset['url']) && $dataset['url'] === $canonical, 'Dataset.url must be the canonical page URL');
check(isset($dataset['publisher']['@id']), 'Dataset.publisher must reference the Organization @id');
check(isset($dataset['isPartOf']['@id']) && strpos($dataset['isPartOf']['@id'], '#website') !== false,
    'Dataset.isPartOf must reference the site WebSite @id');
check(strpos((string) $dataset['name'], '— Local Market Pricing') !== false,
    'Dataset.name must include the profile suffix');

// price PropertyValue carries ISO unitText
$measured = isset($dataset['variableMeasured']) ? $dataset['variableMeasured'] : array();
$current = null;
foreach ($measured as $pv) {
    if (($pv['name'] ?? '') === 'Current price') { $current = $pv; }
}
check($current !== null, 'variableMeasured must include Current price');
check(isset($current['unitText']) && $current['unitText'] === 'USD',
    'Current price PropertyValue must carry ISO currency in unitText');
check(isset($current['value']) && $current['value'] === 3730,
    'Current price value must come from time_series.current_price (nested)');

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

// === Rule 1: hybrid → Dataset + ItemList (only with items) ================
$profile_hybrid = array('schema_type' => 'hybrid', 'schema_features' => array());
$items = array(
    array('name' => 'Round', 'price' => 3700, 'currency' => 'USD', 'url' => $canonical),
    array('name' => 'Oval', 'price' => 3200, 'currency' => 'USD', 'url' => $canonical),
);
$g_hy = $schema->build_graph($ctx, $summary_nested, $profile_hybrid, $site, 'USD', $canonical, $breadcrumb, array(), $items);
$list = node_of($g_hy, 'ItemList');
check($list !== null, 'hybrid must emit an ItemList when items are present');
check(isset($list['numberOfItems']) && $list['numberOfItems'] === 2, 'ItemList must count its items');
check(isset($list['itemListElement'][0]['item']['offers']['priceCurrency']),
    'ItemList products must carry an Offer with priceCurrency');

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
    if (($pv['name'] ?? '') === 'Diamonds analysed' && ($pv['value'] ?? null) === 30155) { $has_sample = true; }
}
check($has_sample, 'flat payload: Diamonds analysed must resolve from num_diamonds');

// === Renders valid JSON =====================================================
$html = $schema->render($ctx, $summary_nested, $profile_md, $site, 'USD', $canonical, $breadcrumb, $faq);
check(strpos($html, '<script type="application/ld+json">') === 0, 'render() must output an ld+json script tag');
$json = trim(str_replace(array('<script type="application/ld+json">', '</script>'), '', $html));
$decoded = json_decode($json, true);
check(json_last_error() === JSON_ERROR_NONE, 'render() output must be valid JSON');
check(isset($decoded['@context']) && isset($decoded['@graph']), 'render() must wrap nodes in @context + @graph');

// --- Summary ----------------------------------------------------------------
$total = $GLOBALS['__tests'];
$fails = $GLOBALS['__fails'];
if ($fails === 0) {
    fwrite(STDOUT, "OK: {$total} assertions passed\n");
    exit(0);
}
fwrite(STDERR, "{$fails}/{$total} assertions FAILED\n");
exit(1);

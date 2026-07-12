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
define('LDN_PLUGIN_DIR', dirname(__DIR__) . '/');

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
if (!function_exists('selected')) {
    function selected($selected, $current, $echo = true) {
        $result = ((string) $selected === (string) $current) ? ' selected="selected"' : '';
        if ($echo) {
            echo $result;
        }
        return $result;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}
if (!function_exists('wpautop')) {
    function wpautop($s) { return '<p>' . str_replace("\n\n", '</p><p>', trim((string) $s)) . '</p>'; }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($s) { return (string) $s; }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($s) { return strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $s)); }
}

require_once __DIR__ . '/../includes/class-ldn-page-context.php';
require_once __DIR__ . '/../includes/class-ldn-test-combos.php';

if (!class_exists('LDN_Data_Fetcher')) {
    class LDN_Data_Fetcher {
        /** @var array|null Price summary served for price-module summary_data_json fetches. */
        public $price_summary = null;

        public function fetch_artefact($id, $ctx) {
            if ($id === 'summary_data_json' && isset($ctx->module) && $ctx->module === 'price') {
                return is_array($this->price_summary) ? $this->price_summary : array();
            }
            return array();
        }
        public function fetch_artefact_html($id, $ctx) {
            return '';
        }
    }
}
if (!class_exists('LDN_Config')) {
    class LDN_Config {
        public function get_content_profile($site_id) {
            return array('schema_features' => array('faq', 'breadcrumb'));
        }
        public function get_currency($site_id, $country) {
            return 'USD';
        }
        public function size_price_internal_links($site_id) {
            return true;
        }
        public function size_rollout_country($site_id) {
            return 'us';
        }
        public function get_url_structure($site_id) {
            return array(
                'size_level_1' => '/diamond-size',
                'size_level_2' => '/diamond-size/{shape}',
                'size_level_3' => '/diamond-size/{shape}/{carat}',
                'size_level_compare' => '/diamond-size/compare/{compare}',
                'size_level_methodology' => '/diamond-size/methodology',
                'level_1'      => '{country}/diamond-prices',
                'level_2'      => '{country}/diamond-prices/{type}',
                'level_3'      => '{country}/diamond-prices/{type}/{carat}',
                'level_4'      => '{country}/diamond-prices/{type}/{carat}/{shape}',
                'carat_format' => '{value}-carat',
                'type_natural' => 'natural',
                'type_lab'     => 'lab-grown',
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
require_once __DIR__ . '/../includes/class-ldn-renderer.php';
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
// Test intent: size-page JSON-LD is a connected graph — WebSite + WebPage (with
// dateModified from the artefact's generated_date) + enriched Dataset
// (measurementTechnique, license) + BreadcrumbList + FAQPage.
// Would fail if: the graph regressed to Dataset-only or dropped dateModified.
$summary_dated = $summary;
$summary_dated['generated_date'] = '2026-07-11';
$json_ld = $renderer->json_ld_script($ctx, $summary_dated, $copy, $canonical, 'Title', 'Desc');
check(strpos($json_ld, 'application/ld+json') !== false, 'JSON-LD script emitted');
check(strpos($json_ld, 'Dataset') !== false, 'JSON-LD includes Dataset');
check(strpos($json_ld, 'FAQPage') !== false, 'JSON-LD includes FAQPage');
check(strpos($json_ld, 'BreadcrumbList') !== false, 'JSON-LD includes BreadcrumbList');
check(strpos($json_ld, 'WebSite') !== false, 'JSON-LD includes WebSite node');
check(strpos($json_ld, 'WebPage') !== false, 'JSON-LD includes WebPage node');
check(strpos($json_ld, '2026-07-11') !== false, 'JSON-LD carries dateModified from generated_date');
check(strpos($json_ld, 'measurementTechnique') !== false, 'Dataset declares measurementTechnique');

// Test intent: the size checker tool page adds a WebApplication node.
// Would fail if: the tool page emitted only Dataset/WebPage.
$ctx_tool_schema = new LDN_Page_Context('ringspo', 'size-comparison-tool', 'us', null, null, null, 'size');
$json_ld_tool = $renderer->json_ld_script(
    $ctx_tool_schema,
    array('type' => 'size_checker'),
    $copy,
    $renderer->build_comparison_tool_url('ringspo'),
    'Diamond Size Checker',
    'Desc'
);
check(strpos($json_ld_tool, 'WebApplication') !== false, 'size checker JSON-LD includes WebApplication');

check(strpos($renderer->methodology_html($summary), '12,000') !== false, 'methodology shows sample size');

// Test intent: retailer count is only disclosed when it is a credible breadth
// signal (>= RETAILER_DISCLOSURE_THRESHOLD). A small count (3) is unimpressive,
// so it must be omitted; a large count must be shown.
// Would fail if: a low retailer count still rendered "from N retailers".
check(strpos($renderer->methodology_html($summary), 'retailer') === false, 'methodology hides small retailer count (3)');
$summary_broad = $summary;
$summary_broad['retailer_count'] = 22;
check(strpos($renderer->methodology_html($summary_broad), 'from 22 retailers') !== false, 'methodology shows large retailer count (22)');

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

// Test intent: the mega hub renders the matrix table (one row per shape ×
// anchor carat columns, sticky-ready classes, every cell linking to its
// individual page) and falls back to the flat ladder when no matrix payload
// exists (pre-migration artefacts).
// Would fail if: cells stopped linking, or the fallback path disappeared.
$hub_summary = array(
    'type' => 'mega_hub',
    'rows' => array(
        array('shape' => 'round', 'carat' => '1', 'length_mm' => 6.4, 'width_mm' => 6.4,
              'faceup_area_mm2' => 32, 'lw_ratio' => 1.0, 'lw_low' => 0.99, 'lw_high' => 1.01,
              'depth_pct' => 61.5, 'faceup_delta_pct' => -3.5,
              'outline_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="20"></svg>'),
        array('shape' => 'oval', 'carat' => '1', 'length_mm' => 7.0, 'width_mm' => 5.2,
              'faceup_area_mm2' => 30, 'lw_ratio' => 1.35),
    ),
    'matrix' => array(
        'carats' => array('1', '2'),
        'rows' => array(
            array(
                'shape' => 'round',
                'label' => 'Round',
                'cells' => array(
                    '1' => array('length_mm' => 6.4, 'width_mm' => 6.4, 'source' => 'real',
                                 'outline_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="45px"></svg>'),
                ),
            ),
        ),
    ),
);
$ctx_mega = new LDN_Page_Context('ringspo', 'size-mega-hub', 'us', null, null, null, 'size');
$matrix_html = $renderer->mega_matrix_table_html($ctx_mega, $hub_summary);
check(strpos($matrix_html, 'ldn-size-matrix') !== false, 'mega matrix table renders');
check(strpos($matrix_html, '/diamond-size/round/1-carat/') !== false, 'matrix cell links to individual page');
check(strpos($matrix_html, '/diamond-size/round/') !== false, 'matrix shape column links to shape hub');
check(strpos($matrix_html, 'ldn-size-matrix__cell--empty') !== false, 'matrix marks missing cells');
check(strpos($matrix_html, '6.4 × 6.4 mm') !== false, 'matrix cell shows L × W mm');

$hub_body = $renderer->hub_body_html($ctx_mega, $hub_summary);
check(strpos($hub_body, 'ldn-size-matrix') !== false, 'mega hub body uses the matrix');
check(strpos($hub_body, 'ldn-size-checker-cta') !== false, 'mega hub body includes size checker CTA');

$hub_summary_no_matrix = $hub_summary;
unset($hub_summary_no_matrix['matrix']);
$hub_body_fallback = $renderer->hub_body_html($ctx_mega, $hub_summary_no_matrix);
check(strpos($hub_body_fallback, 'ldn-size-hub-table') !== false, 'mega hub falls back to ladder table without matrix');

// Test intent: hub tables drop the consumer-hostile "vs chart ideal" column
// but keep L/W and the thumbnail; the shape hub heading names the shape.
// Would fail if: faceup_delta_pct rendered again as a column.
$hub_table = $renderer->hub_table_html($ctx_mega, $hub_summary);
check(strpos($hub_table, 'L/W ratio') !== false, 'hub table includes L/W column');
check(strpos($hub_table, 'vs chart ideal') === false, 'hub table omits the chart-ideal delta column');
check(strpos($hub_table, 'ldn-size-table-thumb') !== false, 'hub table includes size thumbnail column');

$ctx_shape_hub = new LDN_Page_Context('ringspo', 'size-shape-hub', 'us', null, null, 'round', 'size');
$shape_table = $renderer->hub_table_html($ctx_shape_hub, $hub_summary);
check(strpos($shape_table, 'Round diamond size chart by carat weight') !== false, 'shape hub table heading names the shape');

$cta = $renderer->size_checker_cta_html('ringspo');
check(strpos($cta, 'ldn-size-checker-cta') !== false, 'size checker CTA block renders');
check(strpos($cta, '/diamond-size/compare/') !== false, 'CTA links to the size checker URL');

$comp_summary = array(
    'type' => 'comparison',
    'a' => array('shape' => 'oval', 'carat' => '1', 'length_mm' => 8.12, 'width_mm' => 5.41, 'faceup_area_mm2' => 32.22),
    'b' => array('shape' => 'round', 'carat' => '1', 'length_mm' => 6.5, 'width_mm' => 6.5, 'faceup_area_mm2' => 30.18),
    'deltas' => array('faceup_area_pct' => 6.8),
    'bigger' => 'a',
);
$callout = $renderer->comparison_callout_html($comp_summary);
check(strpos($callout, 'ldn-faceup-callout') !== false, 'comparison callout renders');
check(strpos($callout, 'larger') !== false, 'comparison callout names winner');

$bars = $renderer->comparison_faceup_bars_html($comp_summary);
check(strpos($bars, 'ldn-faceup-bars') !== false, 'comparison face-up bars render');
check(strpos($bars, '32.22') !== false, 'comparison bars show mm² values');

$tool_url = $renderer->build_comparison_tool_url('ringspo');
check(strpos($tool_url, '/diamond-size/compare/') !== false, 'size checker URL resolves');

// Test intent: the merged Diamond Size Checker renders one always-on panel with
// reference/manual modes, an opt-in second panel, a submit button, and a
// separate (initially hidden) results section; the manifest is embedded once.
// Would fail if: results rendered inline under the inputs again, or panel B
// showed by default.
$checker_summary = array(
    'type' => 'size_checker',
    'shapes' => array('oval', 'round'),
    'carat_bands' => array('1', '1.5'),
    'carat_band_ranges' => array(array('label' => '1', 'min' => 1.0, 'max' => 1.05)),
    'geometry' => array('fill_factors' => array('oval' => 0.7854), 'proportion_threshold' => 1.1, 'split_shapes' => array()),
    'entries' => array(
        'oval|1' => array(
            'shape' => 'oval',
            'carat_band' => '1',
            'length_mm' => array('median' => 8.2),
            'width_mm' => array('median' => 5.5),
            'faceup_area_mm2' => array('p10' => 28, 'median' => 32, 'p90' => 36),
        ),
    ),
    'default_a' => array('shape' => 'oval', 'carat' => '1.1', 'length_mm' => 8.2, 'width_mm' => 5.5),
    'default_b' => array('shape' => 'oval', 'carat' => '1.3', 'length_mm' => 7.8, 'width_mm' => 5.4),
    'popular' => array(
        array('slug' => 'round-1-carat-vs-oval-1-carat', 'label' => '1 ct round vs 1 ct oval'),
    ),
);
$ctx_checker = new LDN_Page_Context('ringspo', 'size-comparison-tool', 'us', null, null, null, 'size');
$checker_html = $renderer->size_checker_body_html($ctx_checker, $checker_summary);
check(strpos($checker_html, 'id="ldn-size-checker"') !== false, 'size checker shell renders');
check(strpos($checker_html, 'ldn-size-checker-manifest') !== false, 'size checker embeds manifest JSON');
check(strpos($checker_html, 'mode_a') !== false, 'panel A has reference/manual mode radios');
check(strpos($checker_html, 'ldn-checker-enable-b') !== false, 'second diamond is opt-in via checkbox');
check(strpos($checker_html, 'id="ldn-checker-panel-b-wrap" hidden') !== false, 'panel B is hidden until enabled');
check(strpos($checker_html, 'ldn-checker-submit') !== false, 'checker has an explicit Check button');
check(strpos($checker_html, 'id="ldn-size-checker-results" aria-live="polite" hidden') !== false, 'results section is separate and initially hidden');
check(strpos($checker_html, 'ldn-checker-depth-a') !== false, 'manual entry offers optional depth input');
check(strpos($checker_html, 'ldn-size-compare-popular') !== false, 'full tool lists crawlable popular comparisons');

// Widget mode: compact heading + link to the full tool, no popular list.
$widget_renderer = new LDN_Size_Renderer(new LDN_Data_Fetcher(), $config);
$widget_html = $widget_renderer->size_checker_body_html($ctx_checker, $checker_summary, true);
check(strpos($widget_html, 'ldn-size-checker--widget') !== false, 'widget variant renders compact class');
check(strpos($widget_html, 'ldn-size-checker-full-link') !== false, 'widget links to the full checker');
check(strpos($widget_html, 'ldn-size-compare-popular') === false, 'widget omits the popular comparisons list');

// Test intent: methodology page renders dataset stat cards and templated
// sections, and its URL resolves from url_structures.
// Would fail if: total_n stopped rendering or the route helper broke.
$methodology_url = $renderer->build_methodology_url('ringspo');
check(strpos($methodology_url, '/diamond-size/methodology/') !== false, 'methodology URL resolves');

$ctx_meth = new LDN_Page_Context('ringspo', 'size-methodology', 'us', null, null, null, 'size');
$meth_summary = array(
    'type' => 'methodology',
    'stats' => array('total_n' => 2400000, 'shape_count' => 10, 'band_count' => 21, 'retailer_count' => 3),
);
$meth_copy = array(
    'sections' => array(
        array('id' => 'why-real', 'heading' => 'Why we measure real diamonds',
              'paragraphs' => array('Most size charts calculate from ideal proportions.')),
    ),
);
$meth_html = $renderer->methodology_body_html($ctx_meth, $meth_summary, $meth_copy);
check(strpos($meth_html, '2,400,000') !== false, 'methodology stats show total sample size');
check(strpos($meth_html, 'Why we measure real diamonds') !== false, 'methodology renders templated sections');
check(strpos($meth_html, 'Retailers sampled') === false, 'methodology hides small retailer count (3)');
check(strpos($meth_html, '/diamond-size/compare/') !== false, 'methodology links to the size checker');

// Individual pages link to the methodology page from the About-this-data strip.
check(strpos($renderer->methodology_html($summary, 'ringspo'), '/diamond-size/methodology/') !== false,
    'about-this-data strip links to the methodology page');

// --- Individual-page polish (2026-07) ----------------------------------------

// Test intent: the Key dimensions table identifies the stone — shape and carat
// rows lead the table so the block is self-describing out of page context.
// Would fail if: the table only listed anonymous mm metrics.
$dims = $renderer->dimensions_table($summary);
check(strpos($dims, 'Shape') !== false && strpos($dims, 'Round') !== false, 'key dimensions names the shape');
check(strpos($dims, 'Carat weight') !== false && strpos($dims, '1 ct') !== false, 'key dimensions names the carat weight');

// Test intent: figure captions and tier labels are HTML rendered by the plugin,
// never <text> inside the mm-scaled SVGs (which renders at unpredictable size).
// Would fail if: the caption/labels moved back into the SVG payload.
$fig = $renderer->scale_figure_html('<svg xmlns="http://www.w3.org/2000/svg"></svg>', $summary);
check(strpos($fig, 'figcaption') !== false && strpos($fig, 'Relative actual size') !== false, 'scale figure carries HTML caption');
$labels = $renderer->spread_labels_html($summary);
check(substr_count($labels, '<div class="ldn-size-spread-label">') === 3, 'spread labels render three tiers');
check(strpos($labels, "\xC3\x98 6.39 mm") !== false, 'spread labels show median diameter for near-round');
check(strpos($labels, 'face-up') !== false, 'spread labels show face-up areas');

// Test intent: the ideal-vs-real callout and depth↔face-up narrative merge into
// ONE section with a single heading (Chart numbers vs real stones).
// Would fail if: the two blocks rendered as separate sections again.
$merged = $renderer->chart_vs_real_html($summary);
check(strpos($merged, 'Chart numbers vs real stones') !== false, 'merged section has the combined heading');
check(strpos($merged, 'ldn-size-ideal-real') !== false && strpos($merged, 'ldn-size-proportions') !== false,
    'merged section contains both the ideal callout and the depth narrative');
check(substr_count($merged, '<h2>') === 1, 'merged section has exactly one h2');
check(substr_count($merged, '<section') === 1, 'merged block is a single section');

// Test intent: the price block embeds live figures from the pricing summary
// artefact (price, range, sample count) and links to the pricing page; when no
// price data resolves it falls back to plain links rather than vanishing.
// Would fail if: the section reverted to text-only links while data exists.
$fetcher_price = new LDN_Data_Fetcher();
$fetcher_price->price_summary = array(
    'distribution' => array(
        'median_price' => 3510,
        'sample_size'  => 27523,
        'price_range'  => array('min' => 1030, 'max' => 19270),
    ),
);
$renderer_price = new LDN_Size_Renderer($fetcher_price, $config);
$price_html = $renderer_price->price_links_html($ctx);
check(strpos($price_html, '$3,510') !== false, 'price block embeds the live median price');
check(strpos($price_html, '27,523') !== false, 'price block embeds the sample count');
check(strpos($price_html, '$1,030') !== false && strpos($price_html, '$19,270') !== false, 'price block embeds the price range');
check(strpos($price_html, 'diamond-prices/natural/1-carat/round') !== false, 'price card links to the pricing page');

$renderer_fallback = new LDN_Size_Renderer(new LDN_Data_Fetcher(), $config);
$fallback_html = $renderer_fallback->price_links_html($ctx);
check(strpos($fallback_html, 'Natural prices') !== false, 'price block falls back to plain links without data');

// Test intent: round individual pages render a cut-grade table when cut_segments
// are present in size-summary.json; fancy shapes omit it.
// Would fail if: the section rendered for emerald or vanished for round.
$summary_with_cut = $summary;
$summary_with_cut['cut_segments'] = array(
    array(
        'id' => 'excellent',
        'label' => 'Excellent',
        'n' => 90000,
        'share_pct' => 80.0,
        'diameter_mm' => array('median' => 6.41),
        'faceup_area_mm2' => array('median' => 32.5),
        'depth_percent' => array('median' => 62.0),
    ),
);
$cut_html = $renderer->cut_grade_html($summary_with_cut);
check(strpos($cut_html, 'How does cut grade affect size?') !== false, 'cut-grade section heading renders');
check(strpos($cut_html, 'Excellent') !== false && strpos($cut_html, '6.41') !== false, 'cut-grade table shows segment stats');
check($renderer->cut_grade_html(array_merge($summary, array('shape' => 'emerald'))) === '', 'cut-grade omitted for fancy shapes');

exit($GLOBALS['__fails'] > 0 ? 1 : 0);

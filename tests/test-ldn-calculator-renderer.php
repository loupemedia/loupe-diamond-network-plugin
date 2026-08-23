<?php
/**
 * Calculator destination page composition.
 *
 * Test intent: the calculator page's content blocks — and their order — come from
 * `page_structure.calculator.sections` in the site's content profile; a section id
 * the renderer does not implement is skipped rather than guessed at.
 *
 * Would fail if: render() called calculator_destination_html() directly instead of
 * dispatching the profile's section list (a profile listing only an unimplemented
 * section would then still emit the tool), or if band coercion let a `tint` section
 * sit last on the page (the theme footer is already purple).
 *
 * Run: php loupe-diamond-network/tests/test-ldn-calculator-renderer.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABSPATH', __DIR__ . '/');
define('LDN_PLUGIN_DIR', dirname(__DIR__) . '/');
define('LDN_PLUGIN_URL', 'https://example.test/wp-content/plugins/loupe-diamond-network/');
define('LDN_VERSION', '0.0.0-test');

require_once dirname(__DIR__, 2) . '/scripts/ldn_preview/preview-shims.php';

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

// --- Collaborator stubs ------------------------------------------------------

/**
 * Layout is the thing under test, so it is injected rather than read from the
 * real bundle: these assertions must not change when Ringspo's profile does.
 */
final class LDN_Config {
    /** @var array<string, mixed> */
    public static $layout = array();

    public function get_page_layout($site_id, $page_level, $country_code = null) {
        return array_merge(
            array('hero_component' => null, 'sections' => array(), 'section_bands' => array(), 'ad_slots' => array()),
            self::$layout
        );
    }

    public function get_url_structure($site_id) {
        return array('type_natural' => 'natural', 'type_lab' => 'lab-grown');
    }

    public function get_content_profile($site_id) {
        // Brand tokens so render() can prove the page shell emits CSS vars;
        // empty profile would hide a regression where theme_style_block is skipped.
        return array(
            'brand_tokens' => array(
                'primary' => '#706cc8',
                'accent'  => '#9590e8',
            ),
        );
    }

    public function consumer_advisory_enabled($site_id, $country_code) {
        return false;
    }

    public function get_site($site_id) {
        return array('brand_name' => 'Ringspo', 'domain' => 'ringspo.com');
    }
}

final class LDN_Artefacts {
    public function __construct($config = null) {}

    public function site_has_wp_widget($site_id, $widget_id, $page_level = 'shape') {
        return $widget_id === 'price_calculator' && $page_level === 'calculator';
    }

    public function wp_widget_presentation($site_id, $widget_id, $page_level = 'shape') {
        return 'destination';
    }
}

final class LDN_Data_Fetcher {
    public function fetch_artefact($artefact_id, $ctx) {
        if ($artefact_id === 'market_overview_json') {
            return array(
                'analysis_date'           => '2026-08-07',
                'total_diamonds_tracked'  => 735764,
            );
        }
        return array(
            'currency'          => 'USD',
            'carat_weight'      => '1',
            'shape'             => 'Round',
            'date'              => '2026-08-07',
            'total_sample_size' => 412,
            'colours'           => array(
                array('key' => 'F', 'label' => 'F'),
                array('key' => 'G', 'label' => 'G'),
            ),
            'clarities'         => array(
                array('key' => 'VS2', 'label' => 'VS2'),
                array('key' => 'VS1', 'label' => 'VS1'),
            ),
            'cut_grades'        => array('Super Ideal', 'Excellent', 'Very Good'),
            'has_cut_dimension' => true,
            'pooled_cut_key'    => 'ALL',
            'default_cell'      => array('color' => 'G', 'clarity' => 'VS1', 'cut_grade' => 'ALL'),
            'cells'             => array(
                'F' => array(
                    'VS1' => array('ALL' => array(
                        'sample_size' => 380,
                        'percentiles' => array('p10' => 3200, 'p50' => 4500, 'p90' => 5900),
                    )),
                ),
                'G' => array(
                    'VS2' => array('ALL' => array(
                        'sample_size' => 395,
                        'percentiles' => array('p10' => 2800, 'p50' => 3900, 'p90' => 5200),
                    )),
                    'VS1' => array(
                        'ALL' => array(
                            'sample_size' => 412,
                            'percentiles' => array('p10' => 3000, 'p50' => 4200, 'p90' => 5600),
                        ),
                        'Excellent' => array(
                            'sample_size' => 360,
                            'percentiles' => array('p10' => 3300, 'p50' => 4700, 'p90' => 6100),
                        ),
                    ),
                ),
            ),
        );
    }
}

require_once LDN_PLUGIN_DIR . 'includes/class-ldn-page-context.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-schema.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-calculator-renderer.php';

$ctx = new LDN_Page_Context('ringspo', 'calculator', 'us', null, null, null, 'calculator');

function calculator_render(array $layout, LDN_Page_Context $ctx) {
    LDN_Config::$layout = $layout;
    $renderer = new LDN_Calculator_Renderer(new LDN_Data_Fetcher(), new LDN_Config());
    return $renderer->render($ctx);
}

// --- The profile's section list drives the page ------------------------------

$declared = calculator_render(array('sections' => array(
    'calculator_tool',
    'how_it_works',
    'what_drives_price',
)), $ctx);
check(
    strpos($declared, 'ldn-calculator-destination') !== false,
    'a profile listing calculator_tool renders the tool'
);
check(
    strpos($declared, 'ldn-page-shell') !== false
        && strpos($declared, '--ldn-primary:#706cc8') !== false,
    'destination page emits the brand CSS vars so grade sliders are not black'
);
check(
    strpos($declared, 'ldn-price-calculator__field--quote') !== false,
    'quoted price uses the constrained field class'
);
check(
    preg_match_all('/ldn-grade-slider__stop[^>]*style="left:\d+(?:\.\d+)?%"/', $declared) > 0
        && strpos($declared, 'style="left:100%"') !== false
        && strpos($declared, 'style="left:0%"') !== false,
    'grade stop labels are positioned at range-thumb percentages, not equal flex cells'
);
check(
    strpos($declared, 'ldn-brand-range') !== false,
    'grade and carat ranges use the shared brand-range control'
);
check(
    preg_match(
        '/ldn-calculator-destination__lead">[^<]*Based on 735,764 diamonds\. Prices last updated/',
        $declared
    ) === 1,
    'destination lead cites country-wide market overview total, not the shape-page sample'
);
check(
    strpos($declared, 'See where your quote sits in this range.') !== false
        && preg_match(
            '/data-ldn-price-calculator-quote="1"[^>]*>\s*<p class="ldn-price-calculator__quote-hint"/',
            $declared
        ) === 1,
    'quote hint is short and sits below the quote input'
);
check(
    strpos($declared, '>Color<') !== false
        && strpos($declared, '>Colour<') === false,
    'US calculator pages use Color spelling, not Colour'
);
check(
    strpos($declared, '"quoteAnalysis"') !== false
        && strpos($declared, '"p50_p75_1"') !== false
        && strpos($declared, '"driverIncrease"') !== false
        && strpos($declared, '%alt%') !== false
        && strpos($declared, 'dearer side of typical') === false,
    'manifest ships hybrid quote analysis and driver templates, not hardcoded JS copy'
);
check(
    strpos($declared, 'ldn-calculator-how') !== false
        && strpos($declared, 'How it works') !== false,
    'calculator page renders the how_it_works section when listed in the profile'
);
check(
    // Test intent: how_it_works / what_drives_price must be ldn-section so band
    // padding separates them — bare divs sat flush under How it works copy.
    strpos($declared, 'class="ldn-section ldn-section--plain ldn-calculator-how"') !== false
        && strpos($declared, 'class="ldn-section ldn-section--plain ldn-calculator-drivers"') !== false,
    'how_it_works and what_drives_price wrap in ldn-section for band spacing'
);
check(
    strpos($declared, 'ldn-calculator-drivers') !== false
        && strpos($declared, 'What drives the price?') !== false,
    'calculator page renders what_drives_price when listed in the profile'
);
check(
    strpos($declared, 'median at the top of this page') === false,
    'destination page does not inherit the shape-page intro about the page median'
);
check(
    strpos($declared, 'Based on 412 diamonds at') !== false
        && strpos($declared, 'Lower end') !== false,
    'the answer surface labels prices and cites the cell sample size'
);
check(
    strpos($declared, 'data-ldn-grade-slider="cut"') !== false,
    'destination cut control is a point slider'
);
check(
    strpos($declared, 'ldn-price-calculator__col') !== false
        && strpos($declared, 'ldn-field-help') !== false,
    'destination panel uses the two-column control layout with field help'
);
check(
    strpos($declared, 'ldn-seg-toggle') !== false
        && preg_match_all('/data-ldn-calculator-type="1"/', $declared) === 2,
    'diamond type is a two-option segmented control, not a dropdown'
);
check(
    strpos($declared, '<select data-ldn-calculator-type') === false,
    'diamond type is no longer a <select>'
);
check(
    strpos($declared, 'data-ldn-calculator-carat-slider') !== false
        && strpos($declared, 'data-ldn-calculator-carat="1"') !== false,
    'carat is a slider paired with a numeric input'
);
check(
    strpos($declared, 'min="0.3"') !== false && strpos($declared, 'max="12"') !== false,
    'carat bounds match the calculator band floor/ceiling (0.3–12)'
);
check(
    strpos($declared, 'aria-label="Excellent"') !== false
        && strpos($declared, '>Ex</button>') !== false
        && strpos($declared, 'aria-label="Very Good"') !== false
        && strpos($declared, '>VG</button>') !== false
        && strpos($declared, 'aria-label="Super Ideal"') !== false
        && strpos($declared, '>Super Ideal</button>') !== false,
    'cut stops keep Super Ideal in full; shorter grades still compress (VG / Ex)'
);

// The discriminating case: if render() emitted the tool regardless of config, this
// would contain it. Honouring the list means the page has nothing to show.
$unimplemented = calculator_render(array('sections' => array('price_history_chart')), $ctx);
check(
    $unimplemented === '',
    'a profile listing only sections the renderer does not implement yields no page'
);

$absent = calculator_render(array(), $ctx);
check(
    strpos($absent, 'ldn-calculator-destination') !== false,
    'a profile with no calculator section list falls back to the tool, not a blank page'
);

// --- Band rules -------------------------------------------------------------

$banded = calculator_render(
    array('sections' => array('calculator_tool'), 'section_bands' => array('calculator_tool' => 'tint')),
    $ctx
);
check(
    strpos($banded, 'ldn-section--plain') !== false && strpos($banded, 'ldn-section--tint') === false,
    'a tint band declared on the last section downgrades to plain (theme footer is purple)'
);

// --- Section routing contract ------------------------------------------------

LDN_Config::$layout = array('sections' => array('calculator_tool'));
$renderer = new LDN_Calculator_Renderer(new LDN_Data_Fetcher(), new LDN_Config());
check(
    $renderer->render_calculator_section('not_a_section', $ctx, array()) === '',
    'an unmapped section id renders empty rather than falling through to another block'
);
check(
    strpos($renderer->render_calculator_section('calculator_tool', $ctx, array(
        'manifest' => (new LDN_Data_Fetcher())->fetch_artefact('price_calculator_json', $ctx),
    )), 'ldn-price-calculator') !== false,
    'calculator_tool dispatches to the calculator widget'
);
check(
    strpos($renderer->render_calculator_section('how_it_works', $ctx, array()), 'ldn-calculator-how') !== false,
    'how_it_works dispatches to the explainer block'
);
check(
    strpos($renderer->render_calculator_section('what_drives_price', $ctx, array(
        'manifest' => (new LDN_Data_Fetcher())->fetch_artefact('price_calculator_json', $ctx),
    )), 'ldn-calculator-drivers') !== false,
    'what_drives_price dispatches to the price-driver block when manifest has cells'
);
check(
    $renderer->render_calculator_section('consumer_guidance', $ctx, array()) === '',
    'consumer_guidance is empty until consumer_features_live and calculator widget are enabled'
);

// --- Shape chooser -----------------------------------------------------------
//
// Test intent: every shape the calculator offers is selectable as a radio with
// shipped artwork, and exactly one is pre-selected so the control has a defined
// state before any JS runs.
//
// Would fail if: the picker regressed to a <select> (the JS reads :checked), if a
// shape were added to price_calculator_destination_shapes() without generating its
// icon (silently blank button), or if nothing/everything came back checked.

$picker = calculator_render(array('sections' => array('calculator_tool')), $ctx);

$shape_slugs = array(
    'round', 'princess', 'oval', 'cushion', 'emerald',
    'asscher', 'radiant', 'pear', 'marquise', 'heart',
);

check(
    preg_match_all('/name="ldn-calculator-shape"/', $picker) === count($shape_slugs),
    'one radio per offered shape'
);
check(
    preg_match_all('/name="ldn-calculator-shape"[^>]*checked/', $picker) === 1,
    'exactly one shape is pre-selected'
);
check(
    preg_match('/value="round"[^>]*checked/', $picker) === 1,
    'the pre-selected shape is the one the server rendered the panel for'
);
check(
    strpos($picker, '<select data-ldn-calculator-shape') === false,
    'shape is no longer a dropdown (the JS reads the checked radio)'
);

foreach ($shape_slugs as $slug) {
    check(
        strpos($picker, 'assets/img/shapes/' . $slug . '.svg') !== false,
        "picker references the {$slug} icon"
    );
    check(
        is_file(LDN_PLUGIN_DIR . 'assets/img/shapes/' . $slug . '.svg'),
        "{$slug} icon is shipped in the plugin (run Sizing/build_shape_svgs.py)"
    );
}

$head_ctx = new LDN_Page_Context('ringspo', 'calculator', 'us', null, null, null, 'calculator');
$GLOBALS['wp'] = (object) array('request' => 'us/diamond-prices/calculator');
$head_renderer = new LDN_Calculator_Renderer(new LDN_Data_Fetcher(), new LDN_Config());
$head = $head_renderer->render_head_content($head_ctx);
check(strpos($head, 'rel="canonical"') !== false, 'calculator head emits canonical');
check(strpos($head, 'property="og:url"') !== false, 'calculator head emits og:url');
check(strpos($head, 'WebApplication') !== false, 'calculator head emits WebApplication JSON-LD');

// --- Report -----------------------------------------------------------------

if ($GLOBALS['__fails'] === 0) {
    fwrite(STDOUT, "OK: {$GLOBALS['__tests']} checks passed\n");
    exit(0);
}
fwrite(STDERR, "FAILED: {$GLOBALS['__fails']}/{$GLOBALS['__tests']} checks failed\n");
exit(1);

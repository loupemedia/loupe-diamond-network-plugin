<?php
/**
 * Editorial ring-guide inject (Ringspo legacy WP posts).
 *
 * Test intent: only configured permalinks receive the live price/size panel,
 * and on the ten shape guides those panels replace the stale price and size
 * H3 sections (well-priced / most-expensive / less-expensive, and the
 * buying-guide carat heading) the same way carat posts replace their Price/Size
 * H2 blocks.
 * Ringspo matches its /N-carat-…-diamond-ring/ and /{prefix}-engagement-rings/
 * patterns. Other sites declare exact paths under `paths` (Diamond Hunt's
 * /diamond-shapes/ and so on) rather than pretending those posts use the
 * Ringspo URL shape. UK guides use Colour and a UK price href, and omit the
 * size card when the site has no size tree. Injected reader copy follows
 * house style: no em/en dashes and no Oxford commas.
 *
 * Would fail if: match_path treated every /N-carat-*-diamond-ring/ URL as
 * in-scope, match_shape_page matched any /*-engagement-rings/ URL instead of
 * only shape_pages.prefixes, a declared path of unknown kind was guessed,
 * Diamond Hunt hrefs used Ringspo level_3/level_4, UK copy said Color or
 * Current US prices, include_size: false still rendered a US quarter, a
 * lead/caption used `—` or `, and`, or a carat hub omitted the by-shape
 * ranking chart (the shape OG PNG does not exist at all-shapes), or a
 * heading-section splice ate `$3,510` because preg_replace treated `$3` as
 * a backreference, or a shape guide with no "diamond prices" H2 prepended
 * both cards above the intro, or editorial CSS 50vw-broke GenerateBlocks
 * colour bands and split the teal behind the Alon card, or a carat
 * hub ranking chart had no visible title/date, or the size caption used a
 * smaller muted figcaption instead of body type, or a 4 ct cushion guide
 * embedded og-preview.png (the 8 ct social card) because bins.json had no
 * S3 basename, or the median pill used 7.5px per character and clipped
 * "Median: $63,260" on a 4 ct cushion histogram, or a shape hub fell back
 * to og-preview.png (a 2 ct oval social card) when 1 ct cushion bins were
 * missing, or ring-guide tables used a private cell style instead of
 * `.ldn-table-card` > `.ldn-data-table`.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-editorial-bridge.php
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
if (!function_exists('esc_url')) {
    function esc_url($s) { return (string) $s; }
}
if (!function_exists('home_url')) {
    function home_url($p = '') { return 'https://ringspo.test/' . ltrim((string) $p, '/'); }
}
if (!function_exists('user_trailingslashit')) {
    function user_trailingslashit($p) { return rtrim((string) $p, '/') . '/'; }
}

if (!class_exists('LDN_Config')) {
    class LDN_Config {
        public function editorial_ring_guides($site_id) {
            if ($site_id === 'diamondhuntuk') {
                return array(
                    'enabled' => true,
                    'default_country' => 'uk',
                    'default_type' => 'natural',
                    'include_size' => false,
                    'embed_og_preview' => true,
                    'price_hub_level' => 'level_3',
                    'price_shape_level' => 'level_4',
                    'shape_pages' => array(
                        'anchor_carat' => '1',
                        'ladder_carats' => array('0.5', '1', '2'),
                    ),
                    'paths' => array(
                        '/diamond-shapes/' => array(
                            'kind' => 'hub',
                            'carat' => '1',
                            'price_heading' => 'how shape affects price',
                        ),
                        '/diamond-carat/' => array(
                            'kind' => 'shape_hub',
                            'carat' => '1',
                            'shape' => 'round',
                            'price_heading' => 'how carat weight affects price',
                        ),
                        '/diamond-cut/' => array(
                            'kind' => 'shape',
                            'carat' => '1',
                            'shape' => 'round',
                            'price_heading' => 'how cut changes what you pay',
                        ),
                    ),
                );
            }
            return array(
                'enabled' => true,
                'default_country' => 'us',
                'default_type' => 'natural',
                'include_hubs' => true,
                'carats' => array('4'),
                'infixes' => array('cushion' => 'cushion-cut'),
                'embed_og_preview' => true,
                'replace_price_tables' => true,
                'shape_pages' => array(
                    'suffix' => 'engagement-rings',
                    'anchor_carat' => '1',
                    'prefixes' => array('cushion' => 'cushion-cut'),
                    'comparison_reference' => 'round',
                    'ladder_carats' => array('0.5', '1', '2'),
                    'size_carats' => array('0.5', '1', '2'),
                ),
            );
        }
        public function get_url_structure($site_id) {
            if ($site_id === 'diamondhuntuk') {
                return array(
                    'level_1' => '/prices',
                    'level_2' => '/{type}',
                    'level_3' => '/{type}/{carat}',
                    'level_4' => '/{type}/{carat}/{shape}',
                    'level_5' => '/{type}/{carat}/{shape}/{lab}/{cert}',
                    'type_natural' => 'mined-diamonds',
                    'carat_format' => '{value}-carat',
                );
            }
            return array(
                'level_3' => '/{country}/diamond-prices/{type}/{carat}',
                'level_4' => '/{country}/diamond-prices/{type}/{carat}/{shape}',
                'type_natural' => 'natural',
                'carat_format' => '{value}-carat',
                'size_level_1' => '/diamond-size',
                'size_level_2' => '/diamond-size/{shape}',
                'size_level_3' => '/diamond-size/{shape}/{carat}',
            );
        }
        public function get_currency($site_id, $country_code) {
            return strtolower((string) $country_code) === 'uk' ? 'GBP' : 'USD';
        }
        public function shape_to_s3_slug($shape) {
            return strtolower((string) $shape) === 'cushion' ? 'cushion-cut' : strtolower((string) $shape);
        }
        public function shape_to_url_slug($shape, $site_id, $country = null) {
            return strtolower((string) $shape);
        }
    }
}

if (!class_exists('LDN_Assets')) {
    class LDN_Assets {
        public static function us_quarter_image_url() {
            return 'https://ringspo.test/ldn/assets/img/us-quarter.png';
        }
    }
}

if (!class_exists('LDN_Data_Fetcher')) {
    class LDN_Data_Fetcher {
        /**
         * Live-shaped medians so the ladder has something to sort and link.
         * Dates differ across weights exactly as the freshness cadence makes
         * them differ in production.
         */
        private $medians = array(
            '0.5' => array(630.0, 2453, '2026-08-17'),
            '1'   => array(2450.0, 8649, '2026-08-18'),
            '2'   => array(13130.0, 4581, '2026-08-18'),
            '4'   => array(24500.0, 8649, '2026-08-20'),
        );

        public function resolve_artefact_url($artefact_id, $ctx) {
            return $artefact_id === 'og_preview_png'
                ? 'https://s3.example/us/natural/2-carat/oval/og-preview.png'
                : null;
        }
        public function fetch_artefact($artefact_id, $ctx) {
            if ($artefact_id === 'market_index_bins_json'
                && is_object($ctx)
                && isset($ctx->page_level)
                && $ctx->page_level === 'shape'
                && isset($ctx->shape)
                && strtolower((string) $ctx->shape) === 'cushion'
                && isset($ctx->carat)
                && isset($this->medians[(string) $ctx->carat])
            ) {
                return array(
                    'edges'  => array(15000, 18000, 21000, 24000, 27000, 30000, 33000),
                    'counts' => array(12, 48, 130, 95, 40, 8),
                );
            }
            if ($artefact_id === 'shapes_ranking_json') {
                return array(
                    'currency_symbol' => '$',
                    'analysis_date'   => '2026-08-18',
                    'shapes'          => array(
                        array('shape' => 'round', 'median_price' => 3510),
                        array('shape' => 'oval', 'median_price' => 2800),
                        array('shape' => 'cushion', 'median_price' => 2450),
                    ),
                );
            }
            if ($artefact_id === 'size_summary_json'
                && is_object($ctx)
                && isset($ctx->page_level)
                && $ctx->page_level === 'size-mega-hub'
            ) {
                return array(
                    'type' => 'mega_hub',
                    'matrix' => array(
                        'carats' => array('0.5', '1', '2', '4'),
                        'rows' => array(
                            array(
                                'shape' => 'round',
                                'label' => 'Round',
                                'cells' => array(
                                    '4' => array(
                                        'length_mm' => 10.32,
                                        'width_mm' => 10.32,
                                        'outline_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10.32 10.32" class="ldn-test-round"><circle cx="5.16" cy="5.16" r="5" fill="#1a1a2e"/></svg>',
                                    ),
                                ),
                            ),
                            array(
                                'shape' => 'cushion',
                                'label' => 'Cushion',
                                'cells' => array(
                                    '0.5' => array(
                                        'length_mm' => 4.91,
                                        'width_mm' => 4.23,
                                        'outline_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4.91 4.23" class="ldn-test-cushion-0-5"></svg>',
                                    ),
                                    '1' => array(
                                        'length_mm' => 5.83,
                                        'width_mm' => 5.83,
                                        'outline_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 5.83 5.83" class="ldn-test-cushion-1"></svg>',
                                    ),
                                    '2' => array(
                                        'length_mm' => 7.27,
                                        'width_mm' => 7.05,
                                        'outline_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 7.27 7.05" class="ldn-test-cushion-2"></svg>',
                                    ),
                                    '4' => array(
                                        'length_mm' => 9.25,
                                        'width_mm' => 9.25,
                                        'outline_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 9.25 9.25" class="ldn-test-cushion"></svg>',
                                    ),
                                ),
                            ),
                        ),
                    ),
                );
            }
            if ($artefact_id === 'summary_data_json'
                && is_object($ctx)
                && isset($ctx->page_level)
                && $ctx->page_level === 'all-shapes'
            ) {
                // Live shape ranking: cushion below round, so the replacement
                // asserts a saving the data actually supports.
                return array(
                    'analysis_date' => '2026-08-18',
                    'aggregate' => array('shape_count' => 10),
                    'ranking' => array(
                        array('rank' => 2, 'shape' => 'Round', 'median_price' => 3510.0, 'sample_size' => 26369),
                        array('rank' => 9, 'shape' => 'Cushion cut', 'median_price' => 2450.0, 'sample_size' => 8649),
                    ),
                );
            }
            if ($artefact_id === 'summary_data_json'
                && is_object($ctx)
                && isset($ctx->carat)
                && isset($this->medians[(string) $ctx->carat])
            ) {
                list($median, $sample, $date) = $this->medians[(string) $ctx->carat];
                // Mirrors production: C5 writes the date to time_series, and
                // `metadata` holds the JSON-LD/OG payload with no
                // `analysis_date` of its own. Kept free of any other date so a
                // regression in the time_series lookup cannot pass on a
                // fallback.
                return array(
                    'metadata' => array('open_graph' => array('og:title' => 'Cushion')),
                    'distribution' => array(
                        'median_price' => $median,
                        'percentiles' => array(
                            'p25' => $median * 0.85,
                            'p50' => $median,
                            'p75' => $median * 1.15,
                        ),
                    ),
                    'time_series' => array('num_diamonds' => $sample, 'analysis_date' => $date),
                );
            }
            return array('metadata' => array('analysis_date' => '2026-08-14'));
        }
    }
}

class LDN_Mismatched_Bins_Fetcher extends LDN_Data_Fetcher {
    public function fetch_artefact($artefact_id, $ctx) {
        if ($artefact_id === 'market_index_bins_json') {
            return array(
                'shape'  => 'oval',
                'carat'  => '2',
                'edges'  => array(5000, 10000, 15000, 20000, 25000),
                'counts' => array(10, 40, 80, 20),
            );
        }
        return parent::fetch_artefact($artefact_id, $ctx);
    }
}

require_once __DIR__ . '/../includes/class-ldn-page-context.php';
require_once __DIR__ . '/../includes/class-ldn-test-combos.php';
require_once __DIR__ . '/../includes/class-ldn-editorial-bridge.php';

$checks = 0;
function check($cond, $msg) {
    global $checks;
    ++$checks;
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}

function inject_html($html) {
    $found = array();
    if (preg_match_all('/<section class="ldn-ring-guide__card\b[^"]*"[^>]*>.*?<\/section>/s', $html, $m)) {
        $found = $m[0];
    }
    // The in-body price-table replacement is injected copy too, so house style
    // has to cover it even though it is not inside a card section.
    if (preg_match_all('/<div class="ldn-ring-guide__compare">.*?<\/div>/s', $html, $m2)) {
        $found = array_merge($found, $m2[0]);
    }
    return $found === array() ? '' : implode("\n", $found);
}

function check_house_style($html, $label) {
    $inject = inject_html($html);
    check($inject !== '', "$label inject HTML is present");
    check(strpos($inject, "\u{2014}") === false, "$label inject has no em dash");
    check(strpos($inject, "\u{2013}") === false, "$label inject has no en dash");
    check(!preg_match('/,\s+and\b/', $inject), "$label inject has no Oxford comma before and");
    check(!preg_match('/,\s+or\b/', $inject), "$label inject has no Oxford comma before or");
}

$guides = (new LDN_Config())->editorial_ring_guides('ringspo');

$cushion = LDN_Editorial_Bridge::match_path('/4-carat-cushion-cut-diamond-ring/', $guides);
check(is_array($cushion) && $cushion['kind'] === 'shape' && $cushion['shape'] === 'cushion',
    '4 ct cushion permalink matches as a shape guide');

$hub = LDN_Editorial_Bridge::match_path('/4-carat-diamond-ring/', $guides);
check(is_array($hub) && $hub['kind'] === 'hub' && $hub['carat'] === '4',
    '4 ct hub permalink matches');

check(LDN_Editorial_Bridge::match_path('/4-carat-round-diamond-ring/', $guides) === null,
    '4 ct round is out of the test slice');
check(LDN_Editorial_Bridge::match_path('/5-carat-cushion-cut-diamond-ring/', $guides) === null,
    '5 ct cushion is out of the test slice');
check(LDN_Editorial_Bridge::match_path('/us/diamond-prices/natural/4-carat/cushion/', $guides) === null,
    'LDN price URLs are not treated as ring guides');

$stripped = LDN_Editorial_Bridge::strip_ninja_tables(
    '<p>Before</p>[ninja_tables id="53973"]<p>After</p>'
);
check(strpos($stripped, 'ninja_tables') === false && strpos($stripped, 'Before') !== false,
    'Ninja Tables shortcode is stripped');

$dollar_html = LDN_Editorial_Bridge::splice_panels(
    '<h2>4 carat diamond ring price</h2><p>stale</p><h2>Next</h2>',
    array(
        'kind'   => 'hub',
        'carat'  => '4',
        'shape'  => null,
        'infix'  => '',
    ),
    array(
        'price'    => '<p>$3,510 median</p>',
        'size'     => '',
        'combined' => '<p>combined</p>',
    )
);
check(strpos($dollar_html, '$3,510') !== false,
    'heading splice keeps dollar amounts (preg_replace must not treat $3 as a backref)');

$cushion_html = file_get_contents(__DIR__ . '/fixtures/ring-guide-4ct-cushion.html');
$hub_html = file_get_contents(__DIR__ . '/fixtures/ring-guide-4ct-hub.html');
check(is_string($cushion_html) && is_string($hub_html), 'fixtures load');

$bridge = new LDN_Editorial_Bridge('ringspo', new LDN_Config(), new LDN_Data_Fetcher());

$out = $bridge->transform($cushion_html, '/4-carat-cushion-cut-diamond-ring/');
check(strpos($out, 'ninja_tables') === false, 'cushion transform strips the price table');
check(strpos($out, '$22,000') === false, 'cushion transform removes the stale price range');
check(strpos($out, '9.15mm') === false, 'cushion transform removes the stale millimetre line');
check(strpos($out, 'I color diamonds') !== false, 'cushion transform keeps colour advice');
check(strpos($out, 'SI1') !== false, 'cushion transform keeps clarity advice');
check(strpos($out, 'Solitaire settings') !== false, 'cushion transform keeps setting advice');
check(strpos($out, '/us/diamond-prices/natural/4-carat/cushion/') !== false,
    'cushion panel links to the live US price page');
check(strpos($out, '/us/diamond-prices/natural/4-carat/cushion-cut/') === false,
    'price href uses the public URL slug, not the S3 folder');
check(strpos($out, '/diamond-size/cushion/4-carat/') !== false,
    'cushion panel links to the live size page');
check(strpos($out, '/diamond-size/cushion-cut/') === false,
    'size href uses the public URL slug, not the S3 folder');
check(strpos($out, 'og-preview.png') === false, '4 ct cushion uses the inline distribution chart, not the OG PNG');
check(strpos($out, 'ldn-ring-guide__chart--distribution') !== false,
    '4 ct cushion price card embeds the readable distribution histogram');
check(strpos($out, 'Median: $24,500') !== false,
    'distribution chart labels the median in readable type');
check(
    preg_match(
        '/<rect x="(\d+)" y="8" width="(\d+)" height="22" rx="4" fill="#5249a3">/',
        $out,
        $badge
    ) === 1,
    'distribution chart draws a median badge rect'
);
$median_label = 'Median: $24,500';
$old_tight = (int) (strlen($median_label) * 7.5);
$comfort = (int) ceil(strlen($median_label) * 14 * 0.82) + 24;
check(
    (int) $badge[2] > $old_tight,
    'median badge is wider than the 7.5px/char formula that clipped $63,260'
);
check(
    (int) $badge[2] >= $comfort,
    'median badge includes 12px side padding so the last digit is not on the pill edge'
);
check(
    ((int) $badge[1] + (int) $badge[2]) <= 760,
    'median badge stays inside the 760px viewBox'
);
check(strpos($out, 'live price chart of 4 carat cushion cut diamonds') !== false,
    'price follow copy links the 4 ct cushion live chart in the sentence');
check(strpos($out, 'learn how color and clarity affect the price you\'ll pay') !== false
    || strpos($out, "learn how color and clarity affect the price you'll pay") !== false,
    'price follow copy explains color and clarity in the same sentence');
check(strpos($out, 'Live US asking prices as of') === false,
    '4 ct cushion does not keep the old dated figure note');
check(strpos($out, '4 carat Cushion diamond prices →') === false
    && strpos($out, '4 carat Cushion diamond prices <span') === false,
    '4 ct cushion does not keep the old trailing price CTA');
check(strpos($out, 'open the full chart') === false,
    'price follow copy does not use the old awkward caption wording');
check(strpos($out, 'Current US prices') !== false, 'replaced price heading is not the old exact-match H2');
check_house_style($out, 'cushion');

$hub_out = $bridge->transform($hub_html, '/4-carat-diamond-ring/');
check(strpos($hub_out, 'ninja_tables') === false, 'hub transform strips the price table');
check(strpos($hub_out, '$18,000') === false, 'hub transform removes the stale hub price range');
check(strpos($hub_out, '10.2mm') === false, 'hub transform removes the stale round millimetre');
check(strpos($hub_out, '2.5mm solitaire') !== false, 'hub transform keeps setting advice');
check(strpos($hub_out, '/us/diamond-prices/natural/4-carat/') !== false,
    'hub panel links to the all-shapes price page');
check(strpos($hub_out, '/diamond-size/') !== false, 'hub panel links to the size mega hub');
check(strpos($hub_out, 'og-preview.png') === false, 'hub does not embed the shape-level OG card');
check(strpos($hub_out, 'ldn-ring-guide__chart') !== false, 'hub price card embeds a by-shape ranking chart');
check(strpos($hub_out, '$3,510') !== false, 'hub ranking chart uses live all-shapes medians');
check(strpos($hub_out, 'Median US asking prices by shape for 4 carat diamonds') !== false,
    'hub ranking chart has a visible title');
check(strpos($hub_out, 'Last updated 18 August 2026') !== false,
    'hub ranking chart title includes the analysis date');
check(strpos($hub_out, 'See a live') !== false
    && strpos($hub_out, 'price chart of all 4 carat diamonds') !== false,
    'hub follow copy names the live all-shapes chart in the sentence');
check(strpos($hub_out, 'Open the live chart of prices by shape') === false,
    'hub does not keep the old stacked caption');
check(strpos($hub_out, '4 carat diamond prices by shape') === false,
    'hub does not keep the old trailing CTA as the whole link');
check(strpos($hub_out, 'ldn-ring-guide__chart--distribution') === false,
    'hub keeps the by-shape bar chart instead of the shape histogram');
check(strpos($hub_out, 'ldn-ring-guide__size-chart') !== false, 'hub size card renders the live comparison figure');
$_SERVER['REQUEST_URI'] = '/4-carat-diamond-ring/';
check(in_array('ldn-editorial-ring-guide', $bridge->body_class(array()), true),
    'ring-guide pages get a body class that scopes the inject stylesheet');
unset($_SERVER['REQUEST_URI']);

// Colour bands are GenerateBlocks. Would fail if: editorial CSS 50vw-broke
// `.gb-container` and split the teal behind the Alon card (as 0.39.5–0.42.10 did).
$band_css_path = dirname(__DIR__) . '/assets/css/editorial-ring-guide.css';
check(is_readable($band_css_path), 'editorial ring-guide stylesheet exists');
$band_css = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($band_css_path));
check(
    strpos($band_css, '50vw') === false,
    'editorial CSS does not full-bleed GenerateBlocks containers'
);
check(
    strpos($band_css, '.gb-container') === false,
    'editorial CSS does not select GenerateBlocks colour bands'
);
check(
    preg_match('/\.ldn-ring-guide__figure\s*\{[^}]*overflow:\s*hidden/', $band_css) === 1,
    'the price chart figure clips its own paint so it cannot cover the table heading'
);
check(
    strpos($band_css, 'caption-side') === false,
    'ring-guide tables do not use a clipped table caption'
);
check(
    !preg_match('/\.ldn-ring-guide__ladder\s+(th|td)\s*\{[^}]*border-bottom/', $band_css),
    'ring-guide CSS does not invent a second cell style for the price ladder'
);
check(strpos($hub_out, 'ldn-ring-guide__size-chart-kicker') !== false, 'hub size chart has an actual-size kicker');
check(strpos($hub_out, 'ldn-ring-guide__size-chart-shapes') !== false, 'hub size chart puts shapes in a two-row grid beside the quarter');
check(strpos($hub_out, 'data-shape="round"') !== false, 'hub size items carry a shape hook for stroke CSS');
check(strpos($hub_out, 'us-quarter.png') !== false, 'hub size chart includes the US quarter');
check(strpos($hub_out, 'ldn-test-round') !== false, 'hub size chart uses the mega-hub round outline');
check(strpos($hub_out, '10.32') !== false, 'hub size chart shows the matrix median millimetres');
check(strpos($hub_out, '/diamond-size/cushion/4-carat/') !== false, 'hub size chart links cushion to the live size page');
check(strpos($hub_out, 'Typical face-up size from real diamonds') !== false,
    'hub size caption talks about real diamonds, not live US listings');
check(strpos($hub_out, 'Learn more on our') !== false
    && strpos($hub_out, '4 carat diamond size chart') !== false,
    'hub size caption links 4 carat diamond size chart in the sentence');
check(strpos($hub_out, 'Diamond size chart (all shapes)') === false,
    'hub does not keep the old trailing size CTA');
check(
    preg_match(
        '/\.ldn-ring-guide__size-chart figcaption\s*\{[^}]*font-size:\s*inherit/',
        $band_css
    ) === 1,
    'size caption uses the page body type, not a smaller muted figcaption'
);
check(strpos($out, 'ldn-ring-guide__size-chart') !== false,
    '4 ct cushion size card renders the single-shape true-scale figure');
check(strpos($out, 'ldn-test-cushion') !== false,
    '4 ct cushion size figure uses the mega-hub outline for that carat');
check(strpos($out, '9.25 × 9.25 mm') !== false,
    '4 ct cushion size figure shows live matrix millimetres');
check(strpos($out, '4 carat cushion cut diamond size chart') !== false,
    '4 ct cushion size caption links the size chart in the sentence');
check(strpos($out, '4 carat Cushion diamond size →') === false
    && strpos($out, '4 carat Cushion diamond size <span') === false,
    '4 ct cushion does not keep the old trailing size CTA');
check_house_style($hub_out, 'hub');

// Carat-free shape guides (/{shape}-engagement-rings/).
$shape_hub = LDN_Editorial_Bridge::match_path('/cushion-cut-engagement-rings/', $guides);
check(is_array($shape_hub)
    && $shape_hub['kind'] === 'shape_hub'
    && $shape_hub['shape'] === 'cushion'
    && $shape_hub['carat'] === '1',
    'cushion shape guide matches as a shape hub anchored at the configured carat');
check(LDN_Editorial_Bridge::match_path('/oval-engagement-rings/', $guides) === null,
    'a shape guide whose prefix is not in shape_pages.prefixes is left alone');
check(LDN_Editorial_Bridge::match_path('/engagement-rings/', $guides) === null,
    'the bare engagement-rings path is not a shape guide');
check(LDN_Editorial_Bridge::match_path('/cushion-cut-engagement-ring/', $guides) === null,
    'a near-miss suffix does not match');

$shape_html = file_get_contents(__DIR__ . '/fixtures/ring-guide-cushion-shape.html');
check(is_string($shape_html), 'shape guide fixture loads');
$shape_out = $bridge->transform($shape_html, '/cushion-cut-engagement-rings/');

check(strpos($shape_out, 'ninja_tables') === false, 'shape guide strips the price table shortcode');
check(strpos($shape_out, 'ldn-ring-guide__ladder') !== false, 'shape guide gets a price ladder');
check(strpos($shape_out, '$2,450') !== false, 'ladder shows the live median at the anchor carat');
check(strpos($shape_out, '$13,130') !== false, 'ladder shows the live median at the top carat');
check(strpos($shape_out, '8,649') !== false, 'ladder shows the sample size behind each median');
check(strpos($shape_out, '/us/diamond-prices/natural/2-carat/cushion/') !== false,
    'each ladder row links to its own carat, not the anchor carat');
check(strpos($shape_out, '/us/diamond-prices/natural/0.5-carat/cushion/') !== false,
    'sub-carat ladder rows keep their decimal in the URL');
check(strpos($shape_out, 'Color, clarity and cut quality') !== false,
    'the price lead uses American spelling on US source strings');
check(strpos($shape_out, 'Colour, clarity and cut quality') === false,
    'the price lead does not use Commonwealth spelling in source copy');
check(strpos($shape_out, 'drawn at true scale next to a US quarter for reference') !== false,
    'the size caption is plain language, not size-tool jargon');
check(strpos($shape_out, 'Every outline shares one millimeter scale') === false,
    'the size caption does not reuse internal size-tool wording');
check(strpos($shape_out, 'ldn-ring-guide__compare-table') !== false, 'comparison table is rendered');
check(preg_match('/<table class="[^"]*ldn-ring-guide__compare-table[^"]*"[\s\S]*?<\/table>/', $shape_out, $compare_table)
    && strpos($compare_table[0], 'is-anchor') === false,
    'the comparison table does not highlight a row');
check(preg_match('/ldn-ring-guide__ladder-wrap[\s\S]*?<tr class="ldn-row-highlight"/', $shape_out),
    'the carat ladder still marks the anchor weight row');
check(strpos($shape_out, 'og-preview.png') === false,
    'shape hub never embeds og-preview.png even when the fetcher would return a 2 ct oval card');
check(strpos($shape_out, '2-carat/oval') === false, 'shape hub does not mention the oval 2 ct artefact');
check(strpos($shape_out, 'ldn-ring-guide__chart--distribution') !== false,
    'shape hub plots the 1 ct cushion histogram from bins.json');
check(strpos($shape_out, '1 carat cushion cut diamond prices') !== false,
    'shape hub histogram is titled for 1 ct cushion, not another combo');
check(strpos($shape_out, 'ldn-table-card') !== false && strpos($shape_out, 'ldn-data-table') !== false,
    'shape hub tables use the house .ldn-table-card > .ldn-data-table markup');
check(strpos($shape_out, '<caption>') === false,
    'shape hub tables put the title above the card, not in a clipped caption');

$wrong_bins = new LDN_Editorial_Bridge(
    'ringspo',
    new LDN_Config(),
    new LDN_Mismatched_Bins_Fetcher()
);
$wrong_out = $wrong_bins->transform($shape_html, '/cushion-cut-engagement-rings/');
check(strpos($wrong_out, 'ldn-ring-guide__chart--distribution') === false,
    'bins JSON stamped oval/2 ct is refused on the cushion shape hub');
check(strpos($wrong_out, 'og-preview.png') === false,
    'a refused histogram does not fall back to the oval OG card');

check(strpos($shape_out, 'ldn-ring-guide__size-chart') !== false, 'shape guide gets a true-scale size row');
check(strpos($shape_out, 'ldn-test-cushion-2') !== false,
    'size row draws each carat from the mega-hub matrix, not the empty per-carat cells');
check(strpos($shape_out, '7.27 × 7.05 mm') !== false, 'size row shows live matrix millimetres');
check(strpos($shape_out, '/diamond-size/cushion/0.5-carat/') !== false,
    'size row items link to their own per-carat size page');
check(strpos($shape_out, 'us-quarter.png') !== false, 'size row keeps the US quarter reference');
check(strpos($shape_out, 'ldn-test-round') === false, 'shape guide size row shows only its own shape');
check(strpos($shape_out, '/diamond-size/cushion/') !== false, 'shape guide CTA links to the cushion size hub');
check(strpos($shape_out, '/diamond-size/cushion-cut/') === false,
    'shape guide hrefs use the public URL slug, not the S3 folder');

$intro_at = strpos($shape_out, 'What is a cushion cut diamond?');
$price_at = strpos($shape_out, 'ldn-ring-guide__card--price');
$size_at = strpos($shape_out, 'ldn-ring-guide__card--size');
$compare_at = strpos($shape_out, 'ldn-ring-guide__compare');
$whats_good_at = strpos($shape_out, 'What&#8217;s good about cushion cut engagement rings?');
$practical_at = strpos($shape_out, 'Cushion cut diamonds are practical');
$certs_at = strpos($shape_out, 'Cushion cut diamond certification');
check($intro_at !== false && $price_at !== false && $size_at !== false,
    'shape guide still has intro, price card and size card');
check($price_at > $intro_at, 'price card is not dumped above the intro');
check(
    $whats_good_at !== false && $practical_at !== false
        && $price_at > $whats_good_at && $price_at < $practical_at,
    'price block sits where the well-priced section was'
);
check(strpos($shape_out, 'Cushion cut diamonds are well-priced') === false,
    'the stale well-priced heading is removed');
check(strpos($shape_out, 'lower demand') === false,
    'stale well-priced intro copy is removed with the price section');
check($compare_at !== false && $compare_at < $price_at,
    'the live comparison precedes the price card in the replaced block');
check($certs_at !== false && $size_at < $certs_at,
    'size card does not swallow certification');
check(strpos($shape_out, 'Cushion cut diamonds carat weight') === false,
    'the stale buying-guide carat heading is removed');
check(strpos($shape_out, 'overly hung-up on') === false,
    'stale carat-weight prose is removed with the size section');
check(strpos($shape_out, 'increase in width of just over 0.4mm') === false,
    'stale carat scaling copy is removed with the size section');
check(strpos($shape_out, 'Crushed ice') !== false, 'shape guide keeps the shape-specific editorial');
check(strpos($shape_out, 'Table %') !== false, 'shape guide keeps the recommended specs');

// The hand-built Gutenberg price table is replaced by live data.
check(strpos($shape_out, '4,109') === false, 'the stale single-retailer price is gone');
check(strpos($shape_out, '6,610') === false, 'the stale round comparison price is gone');
check(strpos($shape_out, 'ldn-ring-guide__compare') !== false, 'a live comparison replaces the stale table');
check(strpos($shape_out, '$2,450') !== false, 'comparison shows the live cushion median');
check(strpos($shape_out, '$3,510') !== false, 'comparison shows the live reference-shape median');
check(strpos($shape_out, 'costs about 30% less than Round') !== false,
    'the saving is computed from live medians, not the stale 38% claim');
check(strpos($shape_out, '9 of 10 shapes') !== false, 'comparison states the live rank at this weight');

// The proportion tables in the same post share the markup but not the subject.
check(strpos($shape_out, '55% &#8211; 68%') !== false, 'the depth percentage table survives');
check(strpos($shape_out, '54% &#8211; 64%') !== false, 'the table percentage table survives');
check(substr_count($shape_out, 'wp-block-table') === 2, 'only the pricing table was taken');

// The sentence that quoted the removed table's percentage goes with it.
check(strpos($shape_out, 'save yourself a huge 38%') === false,
    'the stale saving claim below the table is dropped');
check(strpos($shape_out, 'Here&#8217;s how they stack-up:') === false,
    'the stale table lead-in is removed with the price section');

// Real wording from the other nine guides: a caveat sharing the paragraph with
// a stale figure must survive on its own.
$emerald_tail = LDN_Editorial_Bridge::replace_price_tables(
    '<figure class="wp-block-table"><table><tbody><tr><td>SHAPE</td><td>PRICE (US$)</td></tr>'
    . '<tr><td>Round</td><td>6,610</td></tr></tbody></table></figure>'
    . '<p>There&#8217;s a massive 37% saving to be had by choosing a different shape to the most '
    . 'popular shape. The actual saving that you could make will depend on the carat, cut and '
    . 'clarity of the stone that you&#8217;re looking at.</p>',
    '<div class="ldn-ring-guide__compare">LIVE</div>'
);
check(strpos($emerald_tail, '37% saving') === false, 'the stale percentage sentence is dropped');
check(strpos($emerald_tail, 'The actual saving that you could make will depend') !== false,
    'the caveat sentence sharing that paragraph survives');
check(strpos($emerald_tail, 'LIVE') !== false, 'the live block still lands');

// A closing paragraph with no percentage is left alone: on the radiant guide it
// is an affiliate call to action, not a stale claim.
$radiant_tail = LDN_Editorial_Bridge::replace_price_tables(
    '<figure class="wp-block-table"><table><tbody><tr><td>SHAPE</td><td>PRICE (US$)</td></tr>'
    . '<tr><td>Round</td><td>6,610</td></tr></tbody></table></figure>'
    . '<p>Click here to check today&#8217;s prices.</p>',
    '<div class="ldn-ring-guide__compare">LIVE</div>'
);
check(strpos($radiant_tail, 'Click here to check') !== false,
    'a closing paragraph with no percentage is untouched');

// Freshness: the date is published, from the field production actually uses.
check(strpos(strip_tags($shape_out), 'Prices updated between 17 August 2026 and 18 August 2026') !== false,
    'the ladder spans the oldest and newest row dates');
check(strpos($shape_out, '<time datetime="2026-08-18">18 August 2026</time>') !== false,
    'dates are machine readable and reader formatted');
check(strpos($shape_out, 'live price chart of 1 carat cushion cut diamonds') === false
    && strpos($shape_out, '1 carat Cushion diamond prices') === false,
    'shape hub does not trail the ladder with a price-page CTA');
check(strpos($shape_out, 'cushion cut diamond sizing') !== false,
    'shape hub size caption links cushion cut diamond sizing');
check(strpos($shape_out, 'Typical face-up') !== false
    && strpos($shape_out, 'from real diamonds, drawn at true scale next to a US quarter for reference.') !== false,
    'shape hub size caption is one sentence around that link');
check(strpos($shape_out, 'Cushion diamond size chart') === false,
    'shape hub does not keep the old trailing size CTA');
check(strpos(strip_tags($shape_out), 'as of 18 August 2026') === false,
    'shape hub does not keep the old dated figure note');
check(strpos($shape_out, 'Open the live chart and color/clarity grid') === false,
    'the undated caption fallback is not used when the artefact carries a date');
check_house_style($shape_out, 'shape guide');

check(strpos($hub_out, 'ldn-ring-guide__ladder') === false, 'the carat hub does not get a shape ladder');
check(strpos($out, 'ldn-ring-guide__ladder') === false, 'the 4 ct cushion page does not get a shape ladder');

// A shape guide that does name its price/size sections has them replaced.
$headed = $bridge->transform(
    '<h2>Cushion cut diamond price</h2><p>Stale $4,109 line.</p>'
    . '<h2>Cushion cut diamond size</h2><p>Around 5.50mm x 5.50mm.</p>'
    . '<h2>Cushion cut diamond color</h2><p>Go to G colour as a minimum.</p>',
    '/cushion-cut-engagement-rings/'
);
check(strpos($headed, '4,109') === false, 'a carat-free price H2 section is replaced');
check(strpos($headed, '5.50mm') === false, 'a carat-free size H2 section is replaced');
check(strpos($headed, 'Go to G colour') !== false, 'the colour H2 is not swallowed by the price section');

// Live headings from the ten /{shape}-engagement-rings/ posts (Aug 2026).
$shape_hub_match = array(
    'kind'  => 'shape_hub',
    'carat' => '1',
    'shape' => 'cushion',
    'infix' => 'cushion-cut',
);
$stub_panels = array(
    'price'     => '<section class="ldn-ring-guide__card ldn-ring-guide__card--price">PRICE</section>',
    'size'      => '<section class="ldn-ring-guide__card ldn-ring-guide__card--size">SIZE</section>',
    'combined'  => '<aside class="ldn-ring-guide">COMBINED</aside>',
);

$price_headings = array(
    'Cushion cut diamonds are well-priced',
    'Princess cut diamonds are well-priced',
    'Asscher cut diamonds are well-priced',
    'Emerald cut diamonds are well-priced',
    'Heart-shaped diamonds are well-priced',
    'Marquise diamonds are well-priced',
    'Oval diamond rings are well-priced',
    'Radiant cut diamonds are well-priced',
    'Round brilliant diamonds are the most expensive shape',
    'Pro: Pear diamonds are less expensive',
);
foreach ($price_headings as $heading) {
    $html = '<p>Intro.</p><h3>' . $heading . '</h3><p>Why.</p>'
        . '<h3>Cushion cut diamond certification</h3><p>Lab copy.</p>';
    $spliced = LDN_Editorial_Bridge::splice_panels($html, $shape_hub_match, $stub_panels);
    check(strpos($spliced, 'COMBINED') === false, 'shape guide does not prepend for: ' . $heading);
    check(strpos($spliced, $heading) === false, 'price heading is removed for: ' . $heading);
    check(strpos($spliced, 'Why.') === false, 'stale price copy is removed for: ' . $heading);
    check(
        strpos($spliced, 'PRICE') !== false
            && strpos($spliced, 'PRICE') < strpos($spliced, 'Lab copy.'),
        'price panel replaces the live price heading: ' . $heading
    );
}

$size_headings = array(
    'Cushion cut diamonds carat weight',
    'Princess cut diamond carat',
    'Asscher diamond carat',
    'Emerald Cut Diamonds and Carat Weight',
    'Heart shaped diamond carat weight',
    'Marquise diamond carat weight',
    'Oval diamond carat',
    'Pear diamond carat weight',
    'Radiant cut diamond carat weight',
    'Round brilliant diamonds and Carat Weight',
);
foreach ($size_headings as $heading) {
    $html = '<p>Intro.</p><h3>Cushion cut diamonds are well-priced</h3><p>Why.</p>'
        . '<h3>' . $heading . '</h3><p>Hung-up.</p>';
    $spliced = LDN_Editorial_Bridge::splice_panels($html, $shape_hub_match, $stub_panels);
    check(strpos($spliced, $heading) === false, 'size heading is removed for: ' . $heading);
    check(strpos($spliced, 'Hung-up.') === false, 'stale size copy is removed for: ' . $heading);
    check(strpos($spliced, 'SIZE') !== false, 'size panel replaces the live carat heading: ' . $heading);
}

$pear = '<h3>Pro: Pear diamonds are less expensive</h3><p>Saving copy.</p>'
    . '<h3>Pear diamond pro: they look large for their carat weight</h3><p>Spread copy.</p>'
    . '<h3>Pear diamond carat weight</h3><p>Hung-up copy.</p>';
$pear_out = LDN_Editorial_Bridge::splice_panels($pear, $shape_hub_match, $stub_panels);
check(strpos($pear_out, 'COMBINED') === false, 'pear does not prepend');
check(strpos($pear_out, 'look large for their carat weight') !== false,
    'pear "look large" pro section is not swallowed by the size replace');
check(strpos($pear_out, 'Spread copy.') !== false,
    'pear spread copy survives outside the buying-guide carat section');
check(strpos($pear_out, 'Pear diamond carat weight') === false,
    'pear buying-guide carat heading is removed');
check(strpos($pear_out, 'Hung-up copy.') === false,
    'pear stale carat copy is removed with the size section');
check(strpos($pear_out, 'SIZE') !== false, 'pear size panel still lands');

$passthrough = $bridge->transform($cushion_html, '/3-carat-cushion-cut-diamond-ring/');
check($passthrough === $cushion_html, 'out-of-slice paths are left unchanged');
$shape_passthrough = $bridge->transform($shape_html, '/oval-engagement-rings/');
check($shape_passthrough === $shape_html, 'unconfigured shape guides are left unchanged');

$dh_guides = (new LDN_Config())->editorial_ring_guides('diamondhuntuk');
$dh_shapes = LDN_Editorial_Bridge::match_path('/diamond-shapes/', $dh_guides);
check(is_array($dh_shapes) && $dh_shapes['kind'] === 'hub' && $dh_shapes['carat'] === '1',
    'diamondhunt /diamond-shapes/ matches as a 1 ct hub');
$dh_carat = LDN_Editorial_Bridge::match_path('/diamond-carat/', $dh_guides);
check(is_array($dh_carat) && $dh_carat['kind'] === 'shape_hub' && $dh_carat['shape'] === 'round',
    'diamondhunt /diamond-carat/ matches as a round shape hub');
$dh_cut = LDN_Editorial_Bridge::match_path('/diamond-cut/', $dh_guides);
check(is_array($dh_cut) && $dh_cut['kind'] === 'shape' && $dh_cut['shape'] === 'round',
    'diamondhunt /diamond-cut/ matches as a 1 ct round shape guide');
check(LDN_Editorial_Bridge::match_path('/diamond-colour/', $dh_guides) === null,
    'an undeclared diamondhunt education URL is left alone');
check(LDN_Editorial_Bridge::match_path('/4-carat-diamond-ring/', $dh_guides) === null,
    'Ringspo ring-guide URLs do not match a paths-only site');

$dh = new LDN_Editorial_Bridge('diamondhuntuk', new LDN_Config(), new LDN_Data_Fetcher());
$dh_cut_out = $dh->transform(
    '<h2>How cut changes what you pay</h2><p>Placeholder until live UK prices land.</p>'
    . '<h2>How to choose a cut grade</h2><p>Keep cut first.</p>',
    '/diamond-cut/'
);
check(strpos($dh_cut_out, 'Placeholder until live UK prices land') === false,
    'the declared price H2 on /diamond-cut/ is replaced');
check(strpos($dh_cut_out, 'Keep cut first.') !== false,
    'the next H2 on /diamond-cut/ is not swallowed');
check(strpos($dh_cut_out, 'Current UK prices') !== false, 'diamondhunt inject says UK not US');
check(strpos($dh_cut_out, 'Current US prices') === false, 'diamondhunt inject does not keep the US heading');
check(strpos($dh_cut_out, 'Colour, clarity and cut quality') !== false,
    'diamondhunt inject uses British spelling');
check(strpos($dh_cut_out, 'Color, clarity and cut quality') === false,
    'diamondhunt inject does not use American spelling');
check(strpos($dh_cut_out, '/mined-diamonds/1-carat/round/') !== false,
    'diamondhunt shape href uses the UK URL structure, not Ringspo levels');
check(strpos($dh_cut_out, '/{lab}/') === false, 'leftover cert tokens are not left in the href');
check(strpos($dh_cut_out, 'ldn-ring-guide__size-chart') === false,
    'diamondhunt omits the size card when include_size is false');
check(strpos($dh_cut_out, 'learn how colour and clarity affect the price') !== false,
    'diamondhunt follow copy uses British spelling in the price sentence');
check(strpos($dh_cut_out, 'learn how color and clarity affect the price') === false,
    'diamondhunt follow copy does not use American spelling in the price sentence');

$dh_carat_out = $dh->transform(
    '<h2>How carat weight affects price</h2><p>Stale Blue Nile line.</p>',
    '/diamond-carat/'
);
check(strpos($dh_carat_out, 'ldn-ring-guide__ladder') !== false,
    'diamondhunt /diamond-carat/ gets the live carat ladder');
check(strpos($dh_carat_out, '£2,450') !== false, 'diamondhunt ladder formats GBP');
check(strpos($dh_carat_out, '/mined-diamonds/2-carat/round/') !== false,
    'diamondhunt ladder rows use the UK shape URL at each carat');

fwrite(STDOUT, "OK $checks checks\n");

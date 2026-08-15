<?php
/**
 * Editorial ring-guide inject (Ringspo legacy WP posts).
 *
 * Test intent: only configured permalinks (4 ct hub + 4 ct cushion in the
 * test slice) receive the live price/size panel; Ninja Tables are stripped;
 * Price and Size H2 sections are replaced so stale dollar/mm copy is gone
 * while colour/clarity/settings copy remains. Public hrefs use the site URL
 * slug (cushion), not the S3 folder slug (cushion-cut). The hub size card
 * draws a true-scale comparison from the size mega-hub matrix (live medians
 * + outline SVGs + US quarter), not a static infographic. Injected reader
 * copy follows house style: no em/en dashes and no Oxford commas.
 *
 * Would fail if: match_path treated every /N-carat-*-diamond-ring/ URL as
 * in-scope, splice_panels left the Ninja Table shortcode in place, the
 * colour H2 was swallowed by the price-section regex, price/size hrefs
 * used shape_to_s3_slug, the hub size card omitted the matrix outlines, or
 * a lead/caption used `—` or `, and`.
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
            return array(
                'enabled' => true,
                'default_country' => 'us',
                'default_type' => 'natural',
                'include_hubs' => true,
                'carats' => array('4'),
                'infixes' => array('cushion' => 'cushion-cut'),
                'embed_og_preview' => true,
            );
        }
        public function get_url_structure($site_id) {
            return array(
                'level_3' => '/{country}/diamond-prices/{type}/{carat}',
                'level_4' => '/{country}/diamond-prices/{type}/{carat}/{shape}',
                'type_natural' => 'natural',
                'carat_format' => '{value}-carat',
                'size_level_1' => '/diamond-size',
                'size_level_3' => '/diamond-size/{shape}/{carat}',
            );
        }
        public function shape_to_s3_slug($shape) {
            return strtolower((string) $shape) === 'cushion' ? 'cushion-cut' : strtolower((string) $shape);
        }
        public function shape_to_url_slug($shape, $site_id) {
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
        public function resolve_artefact_url($artefact_id, $ctx) {
            return $artefact_id === 'og_preview_png'
                ? 'https://s3.example/us/natural/4-carat/cushion/og-preview.png'
                : null;
        }
        public function fetch_artefact($artefact_id, $ctx) {
            if ($artefact_id === 'size_summary_json'
                && is_object($ctx)
                && isset($ctx->page_level)
                && $ctx->page_level === 'size-mega-hub'
            ) {
                return array(
                    'type' => 'mega_hub',
                    'matrix' => array(
                        'carats' => array('4'),
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
            return array('metadata' => array('analysis_date' => '2026-08-14'));
        }
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
    if (!preg_match_all('/<section class="ldn-ring-guide__card\b[^"]*"[^>]*>.*?<\/section>/s', $html, $m)) {
        return '';
    }
    return implode("\n", $m[0]);
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
check(strpos($out, 'og-preview.png') !== false, 'cushion panel embeds the existing OG preview PNG');
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
check(strpos($hub_out, 'ldn-ring-guide__size-chart') !== false, 'hub size card renders the live comparison figure');
check(strpos($hub_out, 'ldn-ring-guide__size-chart-kicker') !== false, 'hub size chart has an actual-size kicker');
check(strpos($hub_out, 'ldn-ring-guide__size-chart-shapes') !== false, 'hub size chart puts shapes in a two-row grid beside the quarter');
check(strpos($hub_out, 'data-shape="round"') !== false, 'hub size items carry a shape hook for stroke CSS');
check(strpos($hub_out, 'us-quarter.png') !== false, 'hub size chart includes the US quarter');
check(strpos($hub_out, 'ldn-test-round') !== false, 'hub size chart uses the mega-hub round outline');
check(strpos($hub_out, '10.32') !== false, 'hub size chart shows the matrix median millimetres');
check(strpos($hub_out, '/diamond-size/cushion/4-carat/') !== false, 'hub size chart links cushion to the live size page');
check(strpos($out, 'ldn-ring-guide__size-chart') === false, 'shape guide does not get the all-shapes size chart');
check_house_style($hub_out, 'hub');

$passthrough = $bridge->transform($cushion_html, '/3-carat-cushion-cut-diamond-ring/');
check($passthrough === $cushion_html, 'out-of-slice paths are left unchanged');

fwrite(STDOUT, "OK $checks checks\n");

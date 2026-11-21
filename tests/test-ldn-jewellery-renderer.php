<?php
/**
 * Jewellery renderer & JSON-LD unit tests (CP135).
 *
 * Test intent: Ringspo renders quality-led median/IQR explorer markup with 3x3 colour x clarity
 * table first, while Diamond Advisors renders metal-setting-led average/min-max 1280px atelier
 * lookbook markup with WG/YG/RG/PT stamps, US English labels, and no trend schema when too_new.
 * Would fail if: both sites emitted identical framing, Diamond Advisors led with colour x clarity,
 * UK spelling leaked into reader copy, or too_new trend data emitted Weekly price history JSON-LD.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-jewellery-renderer.php
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
define('LDN_PLUGIN_DIR', dirname(__DIR__) . '/');
if (!defined('LDN_PLUGIN_URL')) {
    define('LDN_PLUGIN_URL', 'https://ringspo.test/wp-content/plugins/loupe-diamond-network/');
}
if (!defined('LDN_VERSION')) {
    define('LDN_VERSION', '0.44.0');
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!function_exists('get_transient')) {
    function get_transient($key) { return false; }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0) { return true; }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}
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
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0) {
        return json_encode($data, $options | JSON_HEX_TAG);
    }
}
if (!function_exists('home_url')) {
    function home_url($p = '') {
        $host = isset($GLOBALS['__test_host']) ? $GLOBALS['__test_host'] : 'https://ringspo.com';
        return rtrim($host, '/') . '/' . ltrim((string) $p, '/');
    }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($p) { return rtrim((string) $p, '/') . '/'; }
}
if (!function_exists('user_trailingslashit')) {
    function user_trailingslashit($p) { return rtrim((string) $p, '/') . '/'; }
}

if (!class_exists('LDN_Plugin')) {
    class LDN_Plugin {
        public static function instance() { return new self(); }
        public function ad_system() { return null; }
    }
}
if (!class_exists('LDN_Seo_Bridge')) {
    class LDN_Seo_Bridge {
        public static function unbridged_plugin_active() { return false; }
    }
}

require_once LDN_PLUGIN_DIR . 'components/trait-ldn-data.php';
require_once LDN_PLUGIN_DIR . 'components/trait-ldn-price-calculator.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-page-context.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-config.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-artefacts.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-renderer.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-jewellery-renderer.php';

if (!class_exists('LDN_Data_Fetcher')) {
    class LDN_Data_Fetcher {
        /** @var array<string, mixed> */
        public $payloads = array();
        public function fetch_artefact($artefact_id, $ctx = null) {
            return isset($this->payloads[$artefact_id]) ? $this->payloads[$artefact_id] : null;
        }
    }
}

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$config = LDN_Config::instance();

function build_sample_cards() {
    return array(
        array(
            'retailer_code' => 'BN',
            'design_key' => 'stud-1',
            'product_title' => '1ct Platinum Four-Prong Stud Earrings',
            'price' => 2490,
            'image_url' => 'https://d1egdb7lgpukq6.cloudfront.net/jewellery/images/bn/1.webp',
            'product_url' => 'https://www.bluenile.com/item-1',
            'metals_available' => array('platinum', 'white_gold'),
            'settings_available' => array('four_prong'),
            'karats_available' => array('950 Platinum', '18K'),
            'carats_available' => array('1.0'),
            'price_position' => 'typical',
        ),
        array(
            'retailer_code' => 'JA',
            'design_key' => 'stud-2',
            'product_title' => '1ct 14K Yellow Gold Martini Stud Earrings',
            'price' => 1850,
            'image_url' => 'https://d1egdb7lgpukq6.cloudfront.net/jewellery/images/ja/2.webp',
            'product_url' => 'https://www.jamesallen.com/item-2',
            'metals_available' => array('yellow_gold', 'rose_gold'),
            'settings_available' => array('martini'),
            'karats_available' => array('14K'),
            'carats_available' => array('1.0'),
            'price_position' => 'below_typical',
        ),
        array(
            'retailer_code' => 'WF',
            'design_key' => 'stud-3',
            'product_title' => '1ct 18K White Gold Bezel Stud Earrings',
            'price' => 3150,
            'image_url' => 'https://d1egdb7lgpukq6.cloudfront.net/jewellery/images/wf/3.webp',
            'product_url' => 'https://www.whiteflash.com/item-3',
            'metals_available' => array('white_gold'),
            'settings_available' => array('bezel'),
            'karats_available' => array('18K'),
            'carats_available' => array('1.0'),
            'price_position' => 'above_typical',
        ),
    );
}

function build_sample_explorer() {
    return array(
        'initial_band' => '1.0',
        'band_order' => array('0.5', '1.0', '2.0'),
        'bands' => array(
            '0.5' => array(
                'stats' => array('median_price' => 980, 'mean_price' => 1040, 'p25_price' => 790, 'p75_price' => 1220, 'min_price' => 650, 'max_price' => 1650, 'design_count' => 18),
                'dots' => array(array('k' => 'BN|stud-1', 'p' => 980, 'm' => array('platinum'))),
            ),
            '1.0' => array(
                'stats' => array('median_price' => 2410, 'mean_price' => 2497, 'p25_price' => 1850, 'p75_price' => 2950, 'min_price' => 1490, 'max_price' => 4200, 'design_count' => 42),
                'dots' => array(
                    array('k' => 'BN|stud-1', 'p' => 2490, 'm' => array('platinum', 'white_gold')),
                    array('k' => 'JA|stud-2', 'p' => 1850, 'm' => array('yellow_gold', 'rose_gold')),
                    array('k' => 'WF|stud-3', 'p' => 3150, 'm' => array('white_gold')),
                ),
            ),
            '2.0' => array(
                'stats' => array('median_price' => 6200, 'mean_price' => 6450, 'p25_price' => 5100, 'p75_price' => 7600, 'min_price' => 4400, 'max_price' => 9800, 'design_count' => 24),
                'dots' => array(array('k' => 'BN|stud-1', 'p' => 6200, 'm' => array('platinum'))),
            ),
        ),
        'designs' => array(
            'BN|stud-1' => array(
                'variants' => array(
                    '1.0' => array(
                        'platinum' => array(
                            'price' => 2490,
                            'product_title' => '1ct Platinum Four-Prong Stud Earrings',
                            'image_url' => 'https://dcr52scuycepg.cloudfront.net/jewellery/images/bn/1.webp',
                            'product_url' => 'https://www.bluenile.com/item-1',
                        ),
                        'white_gold' => array(
                            'price' => 2290,
                            'product_title' => '1ct 18K White Gold Four-Prong Stud Earrings',
                            'image_url' => 'https://dcr52scuycepg.cloudfront.net/jewellery/images/bn/1-wg.webp',
                            'product_url' => 'https://www.bluenile.com/item-1-wg',
                        ),
                    ),
                ),
            ),
        ),
    );
}

// -------------------------------------------------------------------------
// 1. Ringspo quality_led + median_p25_p75
// -------------------------------------------------------------------------
$GLOBALS['__test_host'] = 'https://ringspo.com';
$rs_fetcher = new LDN_Data_Fetcher();
$rs_fetcher->payloads = array(
    'jewellery_quality_grid_json' => array(
        'lead_table' => 'color_clarity_bands',
        'tables' => array(
            array(
                'id' => 'color_clarity_bands',
                'role' => 'lead',
                'caption' => 'Color and clarity bands',
                'rows' => array(
                    array('color_band' => 'D-F', 'clarity_band' => 'VVS-VS', 'headline_price' => 3100, 'range_low' => 2650, 'range_high' => 3600, 'design_count' => 8),
                    array('color_band' => 'G-J', 'clarity_band' => 'SI', 'headline_price' => 2190, 'range_low' => 1790, 'range_high' => 2550, 'design_count' => 14),
                ),
            ),
            array(
                'id' => 'metal',
                'role' => 'secondary',
                'caption' => 'Price by metal',
                'rows' => array(
                    array('metal' => 'yellow_gold', 'headline_price' => 2150, 'range_low' => 1750, 'range_high' => 2600, 'design_count' => 14),
                    array('metal' => 'platinum', 'headline_price' => 2690, 'range_low' => 2200, 'range_high' => 3200, 'design_count' => 16),
                ),
            ),
            array(
                'id' => 'setting',
                'role' => 'secondary',
                'caption' => 'Price by setting',
                'rows' => array(
                    array('setting' => 'martini', 'headline_price' => 2190, 'range_low' => 1800, 'range_high' => 2650, 'design_count' => 12),
                    array('setting' => 'four_prong', 'headline_price' => 2450, 'range_low' => 1950, 'range_high' => 2990, 'design_count' => 20),
                ),
            ),
        ),
    ),
    'jewellery_lab_comparison_json' => array(
        'natural' => array('median_price' => 2410, 'p25_price' => 1850, 'p75_price' => 2950, 'design_count' => 42),
        'lab' => array('median_price' => 790, 'p25_price' => 590, 'p75_price' => 990, 'design_count' => 38),
    ),
    'jewellery_trend_json' => array('status' => 'too_new', 'points' => array()),
    'jewellery_copy_json' => array(
        'methodology' => array(
            'Checked daily across US jewelers with color and clarity bands normalised.',
        ),
    ),
    'jewellery_explorer_json' => build_sample_explorer(),
);

$rs_summary = array(
    'analysis_date' => '2026-11-20',
    'jewelry_category' => 'earrings',
    'jewelry_style' => 'stud',
    'hero' => array(
        'median_price' => 2410,
        'p25_price' => 1850,
        'p75_price' => 2950,
        'min_price' => 1490,
        'max_price' => 4200,
        'design_count' => 42,
        'retailer_count' => 3,
    ),
    'aggregate_offer' => array(
        'lowPrice' => 1490,
        'highPrice' => 4200,
        'offerCount' => 42,
        'priceCurrency' => 'USD',
    ),
    'evidence' => build_sample_cards(),
);

// LDN_Page_Context($site_id, $page_level, $country_code, $diamond_type, $carat, $shape, $module, $compare_slug, $jewelry_path)
$rs_ctx = new LDN_Page_Context('ringspo', 'jewellery', 'us', 'natural', null, null, 'jewellery', null, 'earrings/studs/1-carat');
$rs_renderer = new LDN_Jewellery_Renderer($rs_fetcher, $config);
$rs_html = $rs_renderer->render($rs_ctx, $rs_summary);
$rs_head = $rs_renderer->render_head_content($rs_ctx, $rs_summary);

check(strpos($rs_html, 'ldn-jewellery-page--explorer') !== false, 'Ringspo renders quality-led explorer wrapper');
check(strpos($rs_html, 'Typical price range:') !== false, 'Ringspo uses Typical price range (p25 to p75) hero answer');
check(strpos($rs_html, 'Middle price $2,410') !== false, 'Ringspo uses Middle price (median) framing');
check(strpos($rs_html, 'id="ldn-exp-spread"') !== false && strpos($rs_html, '<svg') !== false, 'Ringspo renders server-side interactive SVG dot spread');
check(strpos($rs_html, 'fetchpriority="high"') !== false, 'First two product cards carry fetchpriority="high"');
check(strpos($rs_html, 'width="400" height="400"') !== false, 'Product images carry intrinsic 400x400 dimensions for zero CLS');
check(strpos($rs_html, '/us/diamond-jewelry-prices/lab-grown/earrings/studs/1-carat/') !== false, 'Ringspo links to lab-grown counterpart URL');
check(strpos($rs_html, 'ldn-jwl-ad-slot') === false, 'Unentitled jewellery_ads emits zero ad containers');

$pos_grade = strpos($rs_html, 'Prices by diamond color and clarity');
$pos_metal = strpos($rs_html, 'Prices by precious metal');
check($pos_grade !== false && $pos_metal !== false && $pos_grade < $pos_metal, 'Ringspo renders 3x3 color and clarity table BEFORE metal table');

$rs_visible = preg_replace('#<script[^>]*>.*?</script>#s', '', $rs_html);
check(strpos($rs_visible, "\xE2\x80\x94") === false && strpos($rs_visible, "\xE2\x80\x93") === false, 'Ringspo visible HTML contains zero em or en dashes');
check(stripos($rs_visible, 'jewellers') === false && stripos($rs_visible, 'colour') === false, 'Ringspo visible HTML uses US English (jewelers, color)');

check(strpos($rs_head, 'BreadcrumbList') !== false, 'Head JSON-LD includes BreadcrumbList');
check(strpos($rs_head, 'AggregateOffer') !== false, 'Head JSON-LD includes AggregateOffer');
check(strpos($rs_head, 'Dataset') !== false, 'Head JSON-LD includes Dataset');
check(strpos($rs_head, 'Weekly price history') === false, 'Head JSON-LD omits Weekly price history when trend status is too_new');
check(strpos($rs_head, 'preconnect" href="https://d1egdb7lgpukq6.cloudfront.net"') !== false, 'Head emits preconnect to Ringspo CloudFront image CDN');

// -------------------------------------------------------------------------
// 2. Diamond Advisors metal_setting_led + mean_min_max + live-S3 backfill
// -------------------------------------------------------------------------
$GLOBALS['__test_host'] = 'https://thediamondadvisors.com';
$da_fetcher = new LDN_Data_Fetcher();
$da_fetcher->payloads = array(
    'jewellery_quality_grid_json' => array(
        'lead_table' => 'metal_setting',
        'tables' => array(
            array(
                'id' => 'metal_setting',
                'role' => 'lead',
                'caption' => 'Metal and setting',
                'rows' => array(
                    array('metal' => 'yellow_gold', 'setting' => 'martini', 'headline_price' => 1850, 'range_low' => 1650, 'range_high' => 2100, 'design_count' => 9),
                    array('metal' => 'platinum', 'setting' => 'four_prong', 'headline_price' => 2490, 'range_low' => 2150, 'range_high' => 2950, 'design_count' => 14),
                    array('metal' => 'white_gold', 'setting' => 'bezel', 'headline_price' => 3150, 'range_low' => 2700, 'range_high' => 3600, 'design_count' => 6),
                ),
            ),
            // Only 1 grade cell -> secondary table must suppress itself (< 2 rows)
            array(
                'id' => 'default_color_clarity',
                'role' => 'secondary',
                'caption' => 'Color and clarity for the default metal',
                'rows' => array(
                    array('color_band' => 'G-J', 'clarity_band' => 'VS', 'headline_price' => 2350, 'range_low' => 1950, 'range_high' => 2750, 'design_count' => 10),
                ),
            ),
        ),
    ),
    'jewellery_lab_comparison_json' => array(
        'natural' => array('design_count' => 42),
        'lab' => array('design_count' => 38),
    ),
    'jewellery_trend_json' => array(
        'status' => 'ok',
        'points' => array(
            array('analysis_date' => '2026-11-13', 'headline_price' => 2440),
            array('analysis_date' => '2026-11-20', 'headline_price' => 2497),
        ),
    ),
    'jewellery_copy_json' => null,
    'jewellery_explorer_json' => build_sample_explorer(),
);

// Deliberately omit carat_bands and card by_metal to test enrich_summary_from_explorer() backfill
$da_summary = array(
    'analysis_date' => '2026-11-20',
    'jewelry_category' => 'earrings',
    'jewelry_style' => 'stud',
    'hero' => array(
        'mean_price' => 2497,
        'median_price' => 2410,
        'min_price' => 1490,
        'max_price' => 4200,
        'design_count' => 42,
        'retailer_count' => 3,
    ),
    'aggregate_offer' => array(
        'lowPrice' => 1490,
        'highPrice' => 4200,
        'offerCount' => 42,
        'priceCurrency' => 'USD',
    ),
    'evidence' => build_sample_cards(),
);

$da_ctx = new LDN_Page_Context('diamondadvisors', 'jewellery', 'us', 'natural', null, null, 'jewellery', null, 'earrings/studs/1-carat');
$da_renderer = new LDN_Jewellery_Renderer($da_fetcher, $config);
$da_html = $da_renderer->render($da_ctx, $da_summary);
$da_head = $da_renderer->render_head_content($da_ctx, $da_summary);

check(strpos($da_html, 'ldn-jewellery-page--atelier') !== false, 'Diamond Advisors renders metal-setting-led atelier wrapper');
check(strpos($da_html, 'Average Price') !== false && strpos($da_html, '$2,497') !== false, 'Diamond Advisors uses Average Price (mean) framing');
check(strpos($da_html, 'Price Range') !== false && strpos($da_html, '$1,490 - $4,200') !== false, 'Diamond Advisors uses full min-max Price Range framing');
check(strpos($da_html, 'ldn-jwl-metal-stamp') !== false, 'Diamond Advisors renders circular WG/YG/RG/PT metal stamps');
check(strpos($da_html, 'ldn-jwl-carat-link') !== false, 'Diamond Advisors backfills carat_bands bar from explorer JSON when missing on summary');
check(strpos($da_html, 'Price by Metal &amp; Setting') !== false, 'Diamond Advisors leads with Price by Metal & Setting table');
check(strpos($da_html, 'Price by color &amp; clarity band') === false, 'Diamond Advisors hides secondary grade table when fewer than 2 cells are populated');
check(strpos($da_html, 'Blue Nile') !== false && strpos($da_html, 'James Allen') !== false, 'Retailer names resolve from config bundle without hardcoded literals');
check(strpos($da_html, '/us/lab-grown/earrings/studs/1-carat/') !== false, 'Diamond Advisors links to /us/lab-grown/ counterpart URL');

$da_visible = preg_replace('#<script[^>]*>.*?</script>#s', '', $da_html);
check(strpos($da_visible, "\xE2\x80\x94") === false && strpos($da_visible, "\xE2\x80\x93") === false, 'Diamond Advisors visible HTML contains zero em or en dashes');

check(strpos($da_head, 'Weekly price history') !== false, 'Head JSON-LD includes Weekly price history when trend status is ok');
check(strpos($da_head, 'preconnect" href="https://dcr52scuycepg.cloudfront.net"') !== false, 'Head emits preconnect to Diamond Advisors CloudFront image CDN');

if ($GLOBALS['__fails'] === 0) {
    echo "OK: {$GLOBALS['__tests']} checks passed\n";
    exit(0);
}
exit(1);

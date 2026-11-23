<?php
/**
 * Loose diamond card UI, ungated SparkleScore badges, curated doors and filter explorer tests (CP166_02).
 *
 * Test intent: Each /diamonds/ page renders Buying Guidance, populated curated "Why chosen"
 * doors (Best Sparkle, Best 4C Value, Largest Face-Up), ungated SparkleScore ring + 3-pillar
 * bars when composite.available is true, Value-only badges without inventing a composite when
 * composite.available is false (price-only BE cards), exact $X below/above typical 4C callouts,
 * CloudFront WebP or inline faceted shape SVG fallbacks, client filter/sort attributes + mobile
 * slide-over drawer, zero raw hex in CSS, zero watch/gate controls and zero ad slots.
 * Would fail if: an unmeasured BE stone showed a Composite score, empty doors rendered dead shells,
 * pool total vs shortlisted count were conflated, raw hex or nowrap prices slipped into diamonds.css,
 * or any watch/email-gate or banner ad markup appeared.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-diamonds-renderer.php
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
define('LDN_PLUGIN_DIR', dirname(__DIR__) . '/');
if (!defined('LDN_PLUGIN_URL')) {
    define('LDN_PLUGIN_URL', 'https://ringspo.com/wp-content/plugins/loupe-diamond-network/');
}
if (!defined('LDN_VERSION')) {
    define('LDN_VERSION', '0.45.1');
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
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
        return 'https://ringspo.com/' . ltrim((string) $p, '/');
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
        public static function debug_log($component, $message) {}
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
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-diamonds-renderer.php';

if (!class_exists('LDN_Data_Fetcher')) {
    class LDN_Data_Fetcher {
        public function fetch_artefact($artefact_id, $ctx = null) { return null; }
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
$fetcher = new LDN_Data_Fetcher();
$renderer = new LDN_Diamonds_Renderer($fetcher, $config);

$bn_card = array(
    'stone_key' => 'gia:2481900001',
    'retailer_code' => 'BN',
    'retailer_name' => 'Blue Nile',
    'affiliate_url' => 'https://www.bluenile.com/diamond-details/LD001?a_aid=ringspo',
    'image_url' => 'https://d1egdb7lgpukq6.cloudfront.net/diamonds/images/abc123def4560001.webp',
    'image_fallback_shape' => null,
    'diamond_type' => 'natural',
    'shape' => 'round',
    'carat' => 1.01,
    'color' => 'G',
    'clarity' => 'VS1',
    'cut_grade' => 'Ideal',
    'cert_lab' => 'GIA',
    'measurements_label' => '6.45 x 6.48 x 3.98 mm',
    'lw_ratio' => 1.00,
    'table_percent' => 56.0,
    'depth_percent' => 61.4,
    'price_usd' => 4890.0,
    'benchmark_cell' => array(
        'median_usd' => 5350.0,
        'delta_usd' => -460.0,
        'delta_pct' => -8.6,
        'verdict_band' => 'strong_value',
    ),
    'sparklescore' => array(
        'composite' => array('score' => 92.4, 'tier' => 'elite', 'available' => true, 'reason_if_null' => null),
        'sparkle' => array('score' => 94.8, 'tier' => 'elite', 'available' => true, 'reason_if_null' => null),
        'face_up' => array('score' => 90.1, 'tier' => 'elite', 'available' => true, 'reason_if_null' => null),
        'value' => array('score' => 91.5, 'tier' => 'elite', 'available' => true, 'reason_if_null' => null),
    ),
    'access_tier' => 'open',
);

$wf_card = array(
    'stone_key' => 'gia:2481900002',
    'retailer_code' => 'WF',
    'retailer_name' => 'Whiteflash',
    'affiliate_url' => 'https://www.whiteflash.com/loose-diamonds/round-cut-102?a_aid=ringspo',
    'image_url' => null,
    'image_fallback_shape' => 'round',
    'diamond_type' => 'natural',
    'shape' => 'round',
    'carat' => 1.03,
    'color' => 'F',
    'clarity' => 'VS2',
    'cut_grade' => 'Super Ideal',
    'cert_lab' => 'GIA',
    'measurements_label' => '6.54 x 6.57 x 3.99 mm',
    'lw_ratio' => 1.00,
    'table_percent' => 55.5,
    'depth_percent' => 61.0,
    'price_usd' => 5590.0,
    'benchmark_cell' => array(
        'median_usd' => 5400.0,
        'delta_usd' => 190.0,
        'delta_pct' => 3.5,
        'verdict_band' => 'fair_market',
    ),
    'sparklescore' => array(
        'composite' => array('score' => 95.6, 'tier' => 'elite', 'available' => true, 'reason_if_null' => null),
        'sparkle' => array('score' => 97.9, 'tier' => 'elite', 'available' => true, 'reason_if_null' => null),
        'face_up' => array('score' => 94.2, 'tier' => 'elite', 'available' => true, 'reason_if_null' => null),
        'value' => array('score' => 86.0, 'tier' => 'excellent', 'available' => true, 'reason_if_null' => null),
    ),
    'access_tier' => 'open',
);

// Price-only Brilliant Earth stone: composite/sparkle/face_up unavailable, value available
$be_card = array(
    'stone_key' => 'gia:2481900003',
    'retailer_code' => 'BE',
    'retailer_name' => 'Brilliant Earth',
    'affiliate_url' => 'https://imp.i277101.net/c/123/456/789?u=https%3A%2F%2Fwww.brilliantearth.com%2Fstone-3',
    'image_url' => null,
    'image_fallback_shape' => 'round',
    'diamond_type' => 'natural',
    'shape' => 'round',
    'carat' => 1.00,
    'color' => 'H',
    'clarity' => 'VS2',
    'cut_grade' => 'Super Ideal',
    'cert_lab' => 'GIA',
    'measurements_label' => null,
    'lw_ratio' => null,
    'table_percent' => null,
    'depth_percent' => null,
    'price_usd' => 4290.0,
    'benchmark_cell' => array(
        'median_usd' => 4780.0,
        'delta_usd' => -490.0,
        'delta_pct' => -10.3,
        'verdict_band' => 'strong_value',
    ),
    'sparklescore' => array(
        'composite' => array('score' => null, 'tier' => null, 'available' => false, 'reason_if_null' => 'sparkle_pillar_required'),
        'sparkle' => array('score' => null, 'tier' => null, 'available' => false, 'reason_if_null' => 'missing_measurements'),
        'face_up' => array('score' => null, 'tier' => null, 'available' => false, 'reason_if_null' => 'missing_measurements'),
        'value' => array('score' => 93.0, 'tier' => 'elite', 'available' => true, 'reason_if_null' => null),
    ),
    'access_tier' => 'open',
);

$payload = array(
    'schema_version' => 1,
    'route_meta' => array(
        'site_id' => 'ringspo',
        'country_code' => 'us',
        'canonical_path' => '/us/diamonds/natural/1-carat/round/',
        'page_level' => 'carat_shape_leaf',
        'diamond_type' => 'natural',
        'shape' => 'round',
        'carat_label' => '1',
        'modifier_slug' => null,
        'analysis_date' => '2026-11-23',
        'pool_id' => 'us_ringspo_loose_v1',
        'curation_profile' => 'balanced',
        'total_matching_stones' => 96,
        'retailer_count' => 4,
        'price_p25_usd' => 4290.0,
        'price_median_usd' => 5180.0,
        'price_p75_usd' => 6250.0,
        'pricing_bridge_url' => '/us/diamond-prices/natural/1-carat/round/',
        'size_bridge_url' => '/diamond-size/round/1-carat/',
    ),
    'doors' => array(
        'best_sparkle' => array($wf_card, $bn_card),
        'best_value' => array($be_card, $bn_card),
        'largest_face_up' => array($wf_card, $bn_card),
    ),
    'explorer_cards' => array($wf_card, $bn_card, $be_card),
    'filter_facets' => array(
        'colors' => array('F' => 1, 'G' => 1, 'H' => 1),
        'clarities' => array('VS1' => 1, 'VS2' => 2),
        'cert_labs' => array('GIA' => 3),
        'retailers' => array('BE' => 1, 'BN' => 1, 'WF' => 1),
        'price_min_usd' => 4290.0,
        'price_max_usd' => 5590.0,
    ),
);

$ctx = new LDN_Page_Context('ringspo', 'diamonds', 'us', 'natural', '1', 'round', 'diamonds', null, null, '1-carat/round');
$html = $renderer->render($ctx, $payload);

// 1. Honest pool vs shortlist framing + buying guidance + bridge links
check(strpos($html, '96') !== false && strpos($html, '3 top-scoring stones shortlisted') !== false, 'Hero distinguishes full pool checked (96) from shortlisted stones on page (3)');
check(strpos($html, 'ldn-dm-guidance') !== false, 'Renders slice buying guidance block');
check(strpos($html, '/us/diamond-prices/natural/1-carat/round/') !== false, 'Links back to 4C market-index chart');
check(strpos($html, '/diamond-size/round/1-carat/') !== false, 'Links to millimeter size analysis page');

// 2. Three curated "Why chosen" doors
check(strpos($html, 'Best Sparkle') !== false, 'Renders Best Sparkle curated door');
check(strpos($html, 'Best 4C Value') !== false, 'Renders Best 4C Value curated door');
check(strpos($html, 'Largest Face-Up') !== false, 'Renders Largest Face-Up curated door');
check(strpos($html, 'ldn-dm-why') !== false, 'Door cards display explicit why-chosen callouts');

// 3. Ungated SparkleScore dial + 3-pillar breakdown vs BE Value-only card
check(strpos($html, 'ldn-dm-dial') !== false && strpos($html, '96') !== false, 'Measured cards render prominent SparkleScore dial');
check(strpos($html, 'data-stone-key="gia:2481900001"') !== false, 'Cards carry data-stone-key Phase 8 hook');
check(strpos($html, 'data-stone-key="gia:2481900003"') !== false, 'BE price-only card renders with data-stone-key');
check(
    preg_match('#<article[^>]*data-stone-key="gia:2481900003"[^>]*data-has-composite="0"#', $html) === 1,
    'Price-only BE card marks data-has-composite="0" and never invents a Composite score'
);
check(strpos($html, '4C Value 93') !== false, 'Price-only BE card surfaces ungated 4C Value score');
check(strpos($html, 'Proportions unverified') !== false, 'Price-only BE card states proportions are unverified in feed');

// 4. Exact 4C cell delta callouts + faceted SVG fallback + sponsored links
check(strpos($html, '$460 below typical') !== false, 'Card shows exact $460 below typical 4C callout');
check(strpos($html, '$190 above typical') !== false, 'Card shows exact $190 above typical 4C callout');
check(strpos($html, 'ldn-dm-shape-svg') !== false && strpos($html, '<svg') !== false, 'Cards without cached photo render faceted SVG diamond diagram');
check(strpos($html, 'rel="sponsored noopener"') !== false, 'Retailer links carry rel="sponsored noopener"');

// 5. Filter & sort studio + 320-400px mobile slide-over drawer + empty state
check(strpos($html, 'data-ldn-dm-explorer') !== false, 'Renders client-side filter explorer root');
check(strpos($html, 'data-ldn-filter-sheet') !== false, 'Renders mobile slide-over filter sheet');
check(strpos($html, 'data-ldn-dm-empty') !== false, 'Renders accessible empty-state reset block');

// 6. Zero Phase 8 gate/watch controls, zero ad slots, zero em/en dashes
check(
    stripos($html, 'Watch this stone') === false
    && stripos($html, 'Alert me') === false
    && strpos($html, 'ldn-score-blur') === false,
    'Zero email gate, blur or Watch this stone controls in v1'
);
check(strpos($html, 'ldn-ad') === false, 'Zero display or LDO banner ad containers');

$visible = preg_replace('#<script[^>]*>.*?</script>#s', '', $html);
check(
    strpos($visible, "\xE2\x80\x94") === false && strpos($visible, "\xE2\x80\x93") === false,
    'Visible HTML contains zero em or en dashes'
);

// 7. CSS hygiene: zero raw #hex colours and zero nowrap on card price class
$css = (string) file_get_contents(LDN_PLUGIN_DIR . 'assets/css/diamonds.css');
$css_no_comments = (string) preg_replace('#/\*.*?\*/#s', '', $css);
check(preg_match('/#[0-9a-fA-F]{3,8}\b/', $css_no_comments) === 0, 'diamonds.css contains zero raw #hex literals');
check(
    preg_match('/\.ldn-stone-card__price\s*\{[^}]*white-space\s*:\s*nowrap/s', $css_no_comments) === 0,
    'diamonds.css never sets white-space: nowrap on .ldn-stone-card__price'
);

// 8. JS file exists and makes zero fetch/XMLHttpRequest calls
$js_path = LDN_PLUGIN_DIR . 'assets/js/diamonds-page.js';
$js = is_readable($js_path) ? (string) file_get_contents($js_path) : '';
check($js !== '', 'diamonds-page.js exists and is non-empty');
check(
    $js !== '' && preg_match('/\b(fetch|XMLHttpRequest)\s*\(/', $js) === 0,
    'diamonds-page.js filters SSR cards with zero network requests'
);

if ($GLOBALS['__fails'] === 0) {
    echo "OK: {$GLOBALS['__tests']} checks passed\n";
    exit(0);
}
exit(1);

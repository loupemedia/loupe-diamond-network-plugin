<?php
/**
 * Site-shell markup (PRD-018 CP125).
 *
 * Test intent: when site_shell.renderer is ldn, the plugin emits header and
 * footer with ldn-shell-* classes and no theme class names; a theme market
 * emits neither. Desktop mega-menu column headings render as non-anchor
 * <span class="ldn-nav-column-title"> labels (not duplicate <a href> links),
 * the mobile drawer uses the collapsed narrow item set within mobile_item_budget
 * with no nested disclosure button on short group headings, and the top-level
 * primary item matching the current request path carries ldn-nav-current.
 * Country flyout links have a hover fill, and the panel uses two columns once
 * eight live markets paint.
 *
 * Would fail if: the US header still used main-navigation / menu-item-has-children,
 * so GeneratePress doubled the chevrons; desktop column headings emitted <a href>
 * and hijacked first-link anchor text; header_html() copied the full desktop tree
 * into the mobile drawer; Japan received the US shell; Select Country items had no
 * hover rule; nine countries stacked in one 12rem column; or viewing a size/editorial
 * page failed to mark its owning top-level nav branch with ldn-nav-current.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-nav-shell.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABSPATH', __DIR__ . '/');
define('LDN_PLUGIN_DIR', dirname(__DIR__) . '/');
define('LDN_PLUGIN_FILE', LDN_PLUGIN_DIR . 'loupe-diamond-network.php');
define('LDN_INCLUDES_DIR', LDN_PLUGIN_DIR . 'includes/');
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('LDN_VERSION')) {
    define('LDN_VERSION', 'test');
}
if (!defined('LDN_PLUGIN_URL')) {
    define('LDN_PLUGIN_URL', 'https://example.test/wp-content/plugins/loupe-diamond-network/');
}

require_once dirname(__DIR__, 2) . '/scripts/ldn_preview/preview-shims.php';

if (!function_exists('sanitize_html_class')) {
    function sanitize_html_class($class, $fallback = '') {
        $class = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $class);
        return $class !== '' ? $class : $fallback;
    }
}
if (!function_exists('_x')) {
    function _x($text, $context, $domain = 'default') {
        return $text;
    }
}

require_once LDN_PLUGIN_DIR . 'includes/class-ldn-config.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-page-context.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-artefacts.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-s3-key-resolver.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-data-fetcher.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-test-combos.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-renderer.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-size-renderer.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-nav.php';

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

add_filter('ldn_nav_request_path', function ($path) {
    return isset($GLOBALS['__ldn_nav_path']) ? $GLOBALS['__ldn_nav_path'] : $path;
});

$config = LDN_Config::instance();
$fetcher = new LDN_Data_Fetcher(new LDN_S3_Key_Resolver($config), new LDN_Artefacts($config));
$rollout = new LDN_Fake_Rollout_Shell(array(
    '*/price' => true,
    '*/size' => true,
    '*/standard_pages' => true,
));

class LDN_Fake_Rollout_Shell {
    private $state;
    public function __construct(array $state) {
        $this->state = $state;
    }
    public function is_enabled($country, $module) {
        $module = strtolower($module);
        foreach (array(strtolower($country) . '/' . $module, '*/' . $module) as $key) {
            if (isset($this->state[$key])) {
                return (bool) $this->state[$key];
            }
        }
        return false;
    }
}

$GLOBALS['__ldn_nav_path'] = '/us/diamond-prices/';
$nav_us = new LDN_Nav('ringspo', $config, $fetcher, $rollout);
check($nav_us->owns_shell(), 'the US request owns the shell');

$header = $nav_us->header_html();
check($header !== '', 'the US header emits markup');
check(
    strpos($header, 'ldn-shell') !== false
        && strpos($header, 'ldn-shell-header') !== false
        && strpos($header, 'ldn-shell-utility') !== false
        && strpos($header, 'ldn-shell-drawer-toggle') !== false,
    'the US header uses shell regions, including a drawer toggle'
);
check(
    strpos($header, 'main-navigation') === false
        && strpos($header, 'menu-item-has-children') === false
        && strpos($header, 'gp-mega-menu') === false,
    'the US header does not use theme class names'
);
check(
    strpos($header, 'Natural and Lab') !== false
        && strpos($header, '/us/diamond-prices/natural/') !== false
        && strpos($header, '/us/diamond-prices/lab-grown/') !== false
        && strpos($header, 'Lab-Grown Prices') === false,
    'Diamond Prices mega has natural and lab type hubs, not a Tools lab-grown item'
);
check(
    strpos($header, 'Sizes by Carat') === false,
    'Diamond Sizes mega does not list carat pages pinned to round'
);
check(
    strpos($header, 'aria-expanded') !== false,
    'the drawer toggle exposes aria-expanded'
);

// Extract desktop primary list vs mobile drawer markup for targeted SEO & mobile checks.
$desktop_ul = '';
if (preg_match('#<ul class="ldn-nav-list ldn-shell-primary__list">(.*)</ul>\s*<div class="ldn-shell-drawer"#s', $header, $m_desk)) {
    $desktop_ul = $m_desk[1];
}
$drawer_div = '';
if (preg_match('#<div class="ldn-shell-drawer"[^>]*>(.*)</div>\s*</nav>#s', $header, $m_draw)) {
    $drawer_div = $m_draw[1];
}

check(
    $desktop_ul !== '' && $drawer_div !== '',
    'desktop primary list and mobile drawer regions can both be isolated'
);
check(
    strpos($desktop_ul, '<span class="ldn-nav-column-title">Prices by Carat</span>') !== false
        && strpos($desktop_ul, '<span class="ldn-nav-column-title">Sizes by Shape</span>') !== false
        && !preg_match('#<li[^>]*ldn-nav-column-heading[^>]*>\s*<a\s#', $desktop_ul),
    'desktop column headings render as <span class="ldn-nav-column-title">, never duplicate <a href> links'
);
check(
    strpos($desktop_ul, 'ldn-nav-column--split') !== false,
    'columns with 8+ items carry ldn-nav-column--split on desktop so 2/3-col panels can flow two subcolumns'
);

$desktop_link_count = substr_count($desktop_ul, '<a href=');
$drawer_link_count = substr_count($drawer_div, '<a href=');
check(
    $drawer_link_count > 0 && $drawer_link_count < $desktop_link_count,
    sprintf(
        'mobile drawer uses the collapsed narrow set + utility (%d links < %d desktop primary links)',
        $drawer_link_count,
        $desktop_link_count
    )
);
check(
    strpos($drawer_div, 'ldn-nav-narrow-hub') !== false
        && preg_match('#<li[^>]*ldn-nav-narrow-hub[^>]*>\s*<a href=#', $drawer_div) === 1,
    'collapsed fan-out columns in the mobile drawer remain real hub <a href> links'
);
check(
    !preg_match('#<li[^>]*ldn-nav-column-heading[^>]*>\s*(?:<a[^>]*>[^<]*</a>|<span[^>]*>[^<]*</span>)\s*<button[^>]*ldn-shell-disclose#s', $drawer_div),
    'column headings in the mobile drawer do not emit a nested + disclosure button (tools stay two-tap reachable)'
);
check(
    preg_match('#<li class="[^"]*ldn-nav-current[^"]*">\s*<a href="[^"]*/us/diamond-prices/" aria-current="page">Diamond Prices</a>#', $desktop_ul) === 1,
    'exact match on /us/diamond-prices/ marks Diamond Prices with ldn-nav-current and aria-current="page"'
);

$GLOBALS['__ldn_nav_path'] = '/diamond-size/round/1-carat/';
$nav_size_leaf = new LDN_Nav('ringspo', $config, $fetcher, $rollout);
$header_size_leaf = $nav_size_leaf->header_html();
check(
    preg_match('#<li class="[^"]*ldn-nav-current[^"]*">\s*<a href="[^"]*/diamond-size/">Diamond Sizes</a>#', $header_size_leaf) === 1
        && !preg_match('#<li class="[^"]*ldn-nav-current[^"]*">\s*<a href="[^"]*/us/diamond-prices/"#', $header_size_leaf),
    'a deep size leaf (/diamond-size/round/1-carat/) highlights the Diamond Sizes branch and not Diamond Prices'
);

$GLOBALS['__ldn_nav_path'] = '/diamond-cut/';
$nav_editorial_leaf = new LDN_Nav('ringspo', $config, $fetcher, $rollout);
$header_editorial_leaf = $nav_editorial_leaf->header_html();
check(
    preg_match('#<li class="[^"]*ldn-nav-current[^"]*">\s*<a href="[^"]*/diamond-engagement-rings/">Learn</a>#', $header_editorial_leaf) === 1
        && strpos($header_editorial_leaf, 'href="http://example.org/diamond-cut/" aria-current="page"') !== false
        || (
            preg_match('#<li class="[^"]*ldn-nav-current[^"]*">\s*<a href="[^"]*/diamond-engagement-rings/">Learn</a>#', $header_editorial_leaf) === 1
            && preg_match('#<a href="[^"]*/diamond-cut/" aria-current="page">Diamond Cut</a>#', $header_editorial_leaf) === 1
        ),
    'an editorial child post (/diamond-cut/) marks its item aria-current="page" and bubbles ldn-nav-current to Learn'
);

$prev_site_base = isset($GLOBALS['ldn_preview_site_base']) ? $GLOBALS['ldn_preview_site_base'] : '';
$GLOBALS['ldn_preview_site_base'] = 'http://localhost:8080/s/ringspo';
$GLOBALS['__ldn_nav_path'] = '/diamond-size/round/1-carat/';
$nav_preview_mount = new LDN_Nav('ringspo', $config, $fetcher, $rollout);
$header_preview_mount = $nav_preview_mount->header_html();
$GLOBALS['ldn_preview_site_base'] = $prev_site_base;
check(
    preg_match('#<li class="[^"]*ldn-nav-current[^"]*">\s*<a href="[^"]*/s/ringspo/diamond-size/">Diamond Sizes</a>#', $header_preview_mount) === 1,
    'active-branch detection strips a non-country preview home_url mount (/s/ringspo) so LDN preview shows the active underline'
);
$GLOBALS['__ldn_nav_path'] = '/us/diamond-prices/';

$css = file_get_contents(LDN_PLUGIN_DIR . 'assets/css/nav-chrome.css');
$code = preg_replace('#/\*.*?\*/#s', '', $css);
check(
    !preg_match('/#[0-9a-f]{3,8}\b/i', $code),
    'the shell stylesheet has no hex colours'
);
check(
    strpos($code, 'menu-item-has-children') === false
        && strpos($code, 'gp-mega-menu') === false,
    'the shell stylesheet does not reuse walker class names'
);
check(
    preg_match('/max-width:\s*768px/', $code)
        && preg_match('/max-width:\s*400px/', $code),
    'the shell stylesheet ships 768px and 400px layouts in the same file'
);
check(
    strpos($code, 'minmax(0, 1fr)') !== false,
    'footer columns can shrink'
);
check(
    preg_match('/\.ldn-shell-footer__col a\s*\{[^}]*font-size:\s*15px/', $code) === 1,
    'footer links are 15px so Intro to diamond rings stays on one line'
);
check(
    substr_count($code, 'min-height: 44px') >= 3,
    'tap targets are at least 44px on the toggle, discloses and footer links'
);
check(
    preg_match('/\.ldn-shell-utility \.ldn-shell-utility__list\s*>\s*li\s*>\s*a/', $code) === 1
        && strpos($code, 'padding: 0 10px') !== false,
    'utility top-level links have 10px horizontal padding so they do not run together'
);
check(
    preg_match('/\.ldn-shell-utility \.ldn-nav-sub a:hover/', $code) === 1
        && strpos($code, 'background: var(--ldn-shell-rule)') !== false,
    'country flyout links have a hover fill, not only a colour shift on the parent'
);
check(
    strpos($code, 'ldn-nav-countries-2-col') !== false
        && strpos($code, 'repeat(2, minmax(0, 1fr))') !== false,
    'the country flyout can paint two columns that shrink'
);
check(
    preg_match('/\.ldn-shell-header\s*\{[^}]*border-bottom:\s*1px solid var\(--ldn-shell-rule\)/s', $code) === 1
        && preg_match('/\.ldn-shell-primary\s*\{[^}]*align-self:\s*stretch/s', $code) === 1,
    'the header carries a bottom rule and stretches the primary nav to full header height so mega panels drop flush'
);
check(
    strpos($code, '.ldn-shell-primary__list > .ldn-nav-current > a') !== false
        && strpos($code, '.ldn-shell-primary__list > .ldn-nav-has-children > a::after') !== false,
    'top-level primary items show both an active-branch indicator and a dropdown chevron'
);
check(
    strpos($code, '.ldn-shell-drawer .ldn-nav-column-heading > .ldn-nav-sub') !== false,
    'short non-collapsed group headings (Tools) stay expanded inside the mobile drawer'
);
check(
    strpos($header, 'ldn-nav-countries-2-col') !== false,
    'nine live Ringspo markets put the two-column class on the US switcher'
);

$footer = $nav_us->shell_footer_html();
check($footer !== '', 'the US footer emits markup');
check(
    substr_count($footer, 'class="ldn-shell-footer__col"') === 4,
    'the US footer paints four content columns (brand is a separate track)'
);
check(
    strpos($footer, 'Intro to diamond rings') !== false
        && strpos($footer, 'Intro to diamond rings') < strpos($footer, 'Diamond Prices'),
    'column 1 leads with the ring intro, then prices'
);
check(
    strpos($footer, 'Where to sell') !== false
        && strpos($footer, 'Where to sell a diamond ring') === false
        && strpos($footer, 'Best place to buy') !== false,
    'sell and buy labels are the short footer forms'
);
check(
    strpos($footer, 'Contact') !== false
        && strpos($footer, 'About') !== false,
    'column 3 is identity (Contact, About)'
);
check(
    strpos($header, 'ringspo-logo.png') !== false
        && strpos($header, '<img') !== false,
    'the header paints the bundled Ringspo mark when WordPress has no custom logo'
);
check(
    strpos($footer, 'ringspo-logo-white.png') !== false
        && strpos($footer, '<img') !== false,
    'the footer paints the white mark on the purple band'
);

$GLOBALS['__ldn_nav_path'] = '/jp/daiyamondo-kakaku/';
$nav_jp = new LDN_Nav('ringspo', $config, $fetcher, $rollout);
check(!$nav_jp->owns_shell(), 'Japan stays on the theme renderer');
check($nav_jp->header_html() === '', 'Japan does not receive the US shell header');

// --- GeneratePress PHP suppression + footer hook outside .site-footer --------
// Test intent: when `owns_shell()` is true (Ringspo US), LDN unhooks GeneratePress's
// header, footer, footer-widgets and GP Premium secondary/mobile-header actions in PHP
// so hidden duplicate chrome/widgets are never emitted into raw HTML, AND skips
// `generate_after_footer_widgets` (which sits inside `.site-footer`, hidden by CSS)
// so `<footer class="ldn-shell-footer">` renders outside `.site-footer` with a clean
// `© {year} Ringspo` line. Theme markets (Japan) keep all GeneratePress hooks intact.
// Would fail if: `render_footer_menu()` ran on `generate_after_footer_widgets` for an
// `owns_shell()` request (trapping the LDN footer inside hidden `.site-footer`), or
// GeneratePress's `generate_header`/`generate_footer` actions stayed hooked on US.
check(
    strpos($footer, 'ldn-shell-footer__copyright') !== false
        && strpos($footer, '&copy; ' . gmdate('Y') . ' Ringspo') !== false
        && stripos($footer, 'GeneratePress') === false,
    'shell footer includes a clean copyright entity line with no GeneratePress credit'
);

$saved_actions = isset($GLOBALS['ldn_preview_actions']) ? $GLOBALS['ldn_preview_actions'] : array();
$saved_filters = isset($GLOBALS['ldn_preview_filters']) ? $GLOBALS['ldn_preview_filters'] : array();

$GLOBALS['ldn_preview_actions'] = array();
add_action('generate_header', function () { echo '<header class="site-header">GP header</header>'; });
add_action('generate_footer', function () { echo '<div class="footer-widgets">Latest deals: 25% off</div>'; });
add_action('generate_after_header', 'generate_menu_plus_mobile_header', 5);
add_action('wp_footer', 'generate_slideout_navigation', 0);
if (!function_exists('generate_menu_plus_mobile_header')) {
    function generate_menu_plus_mobile_header() { echo '<nav id="mobile-header">GP mobile</nav>'; }
}
if (!function_exists('generate_slideout_navigation')) {
    function generate_slideout_navigation() { echo '<nav id="generate-slideout-menu">GP slideout</nav>'; }
}

$GLOBALS['__ldn_nav_path'] = '/diamond-size/methodology/';
$nav_gp_us = new LDN_Nav('ringspo', $config, $fetcher, $rollout);
$nav_gp_us->register();
do_action('wp');

ob_start();
do_action('generate_header');
do_action('generate_after_header');
echo '<div class="site-footer">';
do_action('generate_footer');
do_action('generate_after_footer_widgets');
echo '</div>';
do_action('generate_after_footer');
do_action('wp_footer');
$gp_us_dom = ob_get_clean();

check(
    strpos($gp_us_dom, 'GP header') === false
        && strpos($gp_us_dom, 'Latest deals: 25% off') === false
        && strpos($gp_us_dom, 'GP mobile') === false
        && strpos($gp_us_dom, 'GP slideout') === false
        && apply_filters('generate_footer_widgets', 3) === 0
        && apply_filters('generate_secondary_navigation_location', 'above-header') === '',
    'owns_shell() strips GeneratePress header, footer widgets, secondary nav and mobile/slideout HTML in PHP'
);
check(
    strpos($gp_us_dom, '<div class="site-footer"></div>') !== false
        && substr_count($gp_us_dom, '<footer class="ldn-shell-footer">') === 1
        && strpos($gp_us_dom, '</div><footer class="ldn-shell-footer">') !== false,
    'owns_shell() skips generate_after_footer_widgets inside .site-footer and renders <footer class="ldn-shell-footer"> once outside it'
);

// Verify a `renderer: theme` market (Japan) leaves GeneratePress hooks untouched.
$GLOBALS['ldn_preview_actions'] = array();
$GLOBALS['ldn_preview_filters'] = $saved_filters;
add_action('generate_header', function () { echo '<header class="site-header">JP GP header</header>'; });
add_action('generate_footer', function () { echo '<div class="footer-widgets">JP GP footer</div>'; });
$GLOBALS['__ldn_nav_path'] = '/jp/daiyamondo-kakaku/';
$nav_gp_jp = new LDN_Nav('ringspo', $config, $fetcher, $rollout);
$nav_gp_jp->register();
do_action('wp');
check(
    has_action('generate_header') && has_action('generate_footer'),
    'theme-rendered markets (Japan) keep GeneratePress header and footer hooks intact'
);

$GLOBALS['ldn_preview_actions'] = $saved_actions;
$GLOBALS['ldn_preview_filters'] = $saved_filters;

$pass = $GLOBALS['__tests'] - $GLOBALS['__fails'];
fwrite(STDOUT, "{$pass} of {$GLOBALS['__tests']} checks passed\n");
exit($GLOBALS['__fails'] === 0 ? 0 : 1);

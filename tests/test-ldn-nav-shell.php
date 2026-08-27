<?php
/**
 * Site-shell markup (PRD-018 CP125).
 *
 * Test intent: when site_shell.renderer is ldn, the plugin emits header and
 * footer with ldn-shell-* classes and no theme class names; a theme market
 * emits neither. Country flyout links have a hover fill, and the panel uses
 * two columns once eight live markets paint.
 *
 * Would fail if: the US header still used main-navigation / menu-item-has-children,
 * so GeneratePress doubled the chevrons, or Japan received the US shell, or
 * Select Country items had no hover rule, or nine countries still stacked in one
 * 12rem column.
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

$pass = $GLOBALS['__tests'] - $GLOBALS['__fails'];
fwrite(STDOUT, "{$pass} of {$GLOBALS['__tests']} checks passed\n");
exit($GLOBALS['__fails'] === 0 ? 0 : 1);

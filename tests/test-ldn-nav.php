<?php
/**
 * Navigation site/country/locale resolution (PRD-018 CP125_01).
 *
 * Test intent: Navigation resolves a country and locale on ANY request, taking the
 * country from the URL path when the site has a {country} segment and falling back
 * to the site default otherwise, with no LDN_Page_Context and no multisite call.
 *
 * Would fail if: resolution depended on LDN_Page_Context, so every legacy editorial
 * post and every 404 rendered a header with no country; or if it read the multisite
 * blog, so a visitor on /fr/diamond-prices/ served from the US root subsite saw a
 * US menu above French prices.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-nav.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABSPATH', __DIR__ . '/');
define('LDN_PLUGIN_DIR', dirname(__DIR__) . '/');
define('LDN_PLUGIN_FILE', LDN_PLUGIN_DIR . 'loupe-diamond-network.php');
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

require_once dirname(__DIR__, 2) . '/scripts/ldn_preview/preview-shims.php';

require_once LDN_PLUGIN_DIR . 'includes/class-ldn-config.php';
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

/**
 * Drive resolution for one path, with no route query var set.
 */
function ldn_nav_scope_for($site_id, $path) {
    $GLOBALS['__ldn_nav_path'] = $path;
    $GLOBALS['ldn_preview_query_vars'] = array();
    $nav = new LDN_Nav($site_id, LDN_Config::instance());
    return $nav->resolve_request_scope();
}

// The path filter lets a test drive resolution without touching $_SERVER. Query
// vars go through the shim's own global, so get_query_var() behaves as it does in
// the preview harness rather than being redefined here.
add_filter('ldn_nav_request_path', function ($path) {
    return isset($GLOBALS['__ldn_nav_path']) ? $GLOBALS['__ldn_nav_path'] : $path;
});

/**
 * Source of a class with comments and whitespace removed.
 *
 * The guards below assert the module does not CALL certain APIs. Grepping raw
 * source would also match the docblocks that explain why those calls are absent,
 * so a correctly documented file would fail its own guard.
 */
function ldn_nav_code_only($file) {
    $stripped = php_strip_whitespace($file);
    return $stripped !== '' ? $stripped : file_get_contents($file);
}

$config = LDN_Config::instance();

// -----------------------------------------------------------------------------
// Country from the path
// -----------------------------------------------------------------------------

$scope = ldn_nav_scope_for('ringspo', '/fr/diamond-prices/natural/1-carat/');
check(
    $scope !== null && $scope['country_code'] === 'fr',
    'a French price path resolves country fr'
);
check(
    $scope !== null && $scope['locale'] === 'fr-FR',
    'a French price path resolves locale fr-FR'
);

$scope = ldn_nav_scope_for('ringspo', '/us/diamond-prices/');
check(
    $scope !== null && $scope['country_code'] === 'us',
    'a US price path resolves country us'
);

$scope = ldn_nav_scope_for('ringspo', '/uk/diamond-prices/lab-grown/2-carat/princess/');
check(
    $scope !== null && $scope['country_code'] === 'uk' && $scope['locale'] === 'en-GB',
    'a deep UK path resolves uk / en-GB'
);

// -----------------------------------------------------------------------------
// Requests with no country segment at all
// -----------------------------------------------------------------------------

$scope = ldn_nav_scope_for('ringspo', '/cushion-cut-engagement-rings/');
check(
    $scope !== null && $scope['country_code'] === 'us',
    'a legacy editorial post resolves the default country, not null'
);

$scope = ldn_nav_scope_for('ringspo', '/diamond-size/round/1-carat/');
check(
    $scope !== null && $scope['country_code'] === 'us',
    'a size path carries no country, so it resolves the site default'
);

$scope = ldn_nav_scope_for('ringspo', '/');
check(
    $scope !== null && $scope['country_code'] === 'us',
    'the site root resolves the default country'
);

// -----------------------------------------------------------------------------
// Unrecognised segments must not cost the page its header
// -----------------------------------------------------------------------------

$scope = ldn_nav_scope_for('ringspo', '/zz/something/');
check(
    $scope !== null && $scope['country_code'] === 'us',
    'an unrecognised country segment falls back to the default rather than throwing'
);

$scope = ldn_nav_scope_for('ringspo', '/404-this-does-not-exist/');
check(
    $scope !== null && $scope['country_code'] === 'us',
    'a 404 path still resolves a scope, so the header survives'
);

// -----------------------------------------------------------------------------
// The router's country always wins, so menu and page cannot disagree
// -----------------------------------------------------------------------------

$GLOBALS['__ldn_nav_path'] = '/us/diamond-prices/';
$GLOBALS['ldn_preview_query_vars'] = array('ldn_country' => 'ca');
$nav = new LDN_Nav('ringspo', $config);
$scope = $nav->resolve_request_scope();
check(
    $scope !== null && $scope['country_code'] === 'ca',
    'the ldn_country query var wins over the path, so nav matches the page below it'
);

$GLOBALS['ldn_preview_query_vars'] = array('ldn_country' => 'zz');
$nav = new LDN_Nav('ringspo', $config);
$scope = $nav->resolve_request_scope();
check(
    $scope !== null && $scope['country_code'] === 'us',
    'an undeclared ldn_country is ignored rather than trusted'
);

// -----------------------------------------------------------------------------
// Sites whose paths carry no country
// -----------------------------------------------------------------------------

$structures = $config->get_url_structures();
$no_country_site = null;
foreach (array('diamondpriceexact', 'loupe', 'diamondpriceguru') as $candidate) {
    $site = $config->get_site($candidate);
    if (is_array($site) && $site !== array() && !empty($site['countries'])) {
        $path_structure = isset($site['path_structure']) ? (string) $site['path_structure'] : '';
        if (strpos($path_structure, '{country}') === false) {
            $no_country_site = $candidate;
            break;
        }
    }
}
if ($no_country_site !== null) {
    $scope = ldn_nav_scope_for($no_country_site, '/diamond-prices/');
    check(
        $scope !== null && $scope['country_code'] !== '',
        sprintf('%s has no {country} in path_structure and still resolves a country', $no_country_site)
    );
} else {
    // Not a silent skip: record that the case went unexercised.
    fwrite(STDOUT, "NOTE: no country-free site found in the bundle; that case is unexercised\n");
}

// -----------------------------------------------------------------------------
// Memoisation — GP Premium renders the menu twice per page
// -----------------------------------------------------------------------------

$GLOBALS['__ldn_nav_path'] = '/ca/diamond-prices/';
$GLOBALS['ldn_preview_query_vars'] = array();
$nav = new LDN_Nav('ringspo', $config);
$first = $nav->resolve_request_scope();
$GLOBALS['__ldn_nav_path'] = '/fr/diamond-prices/';
$second = $nav->resolve_request_scope();
check(
    $first !== null && $second !== null && $second['country_code'] === 'ca',
    'a second call in one request hits the memo rather than re-reading the path'
);

$nav->reset_memo();
$third = $nav->resolve_request_scope();
check(
    $third !== null && $third['country_code'] === 'fr',
    'reset_memo() forces re-resolution, proving the memo was what held ca'
);

// -----------------------------------------------------------------------------
// Guard: no multisite API, and no page-context dependency
// -----------------------------------------------------------------------------

$source = ldn_nav_code_only(LDN_PLUGIN_DIR . 'includes/class-ldn-nav.php');

foreach (array(
    'switch_to_blog',
    'restore_current_blog',
    'get_current_blog_id',
    'get_blog_option',
    'is_multisite',
    'get_sites(',
) as $multisite_call) {
    check(
        strpos($source, $multisite_call) === false,
        sprintf(
            'LDN_Nav must not call %s: country is a property of the URL, and the '
                . 'plugin resolves site_id per blog already',
            $multisite_call
        )
    );
}

check(
    strpos($source, 'LDN_Page_Context') === false,
    'LDN_Nav must not reference LDN_Page_Context: the header renders where no route matched'
);

check(
    strpos($source, 'switch_for_context') === false,
    'LDN_Nav must not call LDN_Locale::switch_for_context(): it needs a context that does not exist here'
);

foreach (array('HTTP_ACCEPT_LANGUAGE', 'geoip', 'GeoIP', '$_COOKIE', '$_SESSION') as $impure) {
    check(
        strpos($source, $impure) === false,
        sprintf(
            'LDN_Nav must not read %s: page caches key on URL, so a non-URL input '
                . 'serves one country its neighbour\'s header',
            $impure
        )
    );
}

// -----------------------------------------------------------------------------

$total = $GLOBALS['__tests'];
$fails = $GLOBALS['__fails'];
if ($fails === 0) {
    fwrite(STDOUT, "OK: {$total} checks passed\n");
    exit(0);
}
fwrite(STDERR, "{$fails} of {$total} checks FAILED\n");
exit(1);

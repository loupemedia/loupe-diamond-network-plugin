<?php
/**
 * Navigation item construction and menu injection (PRD-018 CP125_02).
 *
 * Test intent: A generated pricing navigation entry resolves against the country in
 * the current request path, a size entry resolves without a country at all, and
 * injection targets the theme LOCATION rather than the menu's editorial name.
 *
 * Would fail if: the renderer read country from the site default rather than the
 * request, so a visitor on /fr/diamond-prices/ saw a menu linking to /us/; or if
 * injection matched on menu slug, which would silently no-op on the three Ringspo
 * subsites that named their menus `Aus Menu`, `Ireland Menu` and `HK Menu`.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-nav-items.php
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

require_once dirname(__DIR__, 2) . '/scripts/ldn_preview/preview-shims.php';

// Shims the preview harness does not need but menu rendering does.
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
/*
 * `get_nav_menu_locations` comes from preview-shims.php, required above, and reads
 * $GLOBALS['ldn_preview_menu_locations']. Do NOT redefine it here: the shim's
 * function_exists guard means a second definition is silently skipped, so the local
 * one would never run and the mapping below would be ignored.
 */

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

// URL building never touches the fetcher; it is only here because the renderers
// that own the builders take one.
$fetcher = new LDN_Data_Fetcher(new LDN_S3_Key_Resolver($config), new LDN_Artefacts($config));

/**
 * Build the primary menu's items for one request path.
 */
/**
 * Both modules live everywhere, for the tests that are about item BUILDING rather
 * than gating. Without this they would all assert against a fully gated-out menu.
 */
function ldn_rollout_all_on() {
    return new LDN_Fake_Rollout(array(
        '*/price' => true,
        '*/size' => true,
        '*/standard_pages' => true,
    ));
}

function ldn_items_for($path, $menu_name = 'primary') {
    global $config, $fetcher;
    $GLOBALS['__ldn_nav_path'] = $path;
    $GLOBALS['ldn_preview_query_vars'] = array();

    $nav = new LDN_Nav('ringspo', $config, $fetcher, ldn_rollout_all_on());
    $scope = $nav->resolve_request_scope();
    $navigation = $config->get_navigation('ringspo');
    return $nav->nav_items($scope, $navigation['menus'][$menu_name]);
}

function ldn_urls(array $items) {
    $urls = array();
    foreach ($items as $item) {
        $urls[] = $item->url;
    }
    return $urls;
}

function ldn_url_contains(array $items, $needle) {
    foreach ($items as $item) {
        if (strpos($item->url, $needle) !== false) {
            return true;
        }
    }
    return false;
}

// -----------------------------------------------------------------------------
// The config actually reached the plugin
// -----------------------------------------------------------------------------

$navigation = $config->get_navigation('ringspo');
check(
    is_array($navigation) && !empty($navigation['menus']['primary']['items']),
    'the navigation declaration is present in the shipped config bundle'
);
check(
    is_array($navigation) && $config->get_navigation('doesnotexist') === null,
    'a site with no navigation declaration returns null rather than an empty menu'
);

// -----------------------------------------------------------------------------
// Country comes from the request
// -----------------------------------------------------------------------------

$us = ldn_items_for('/us/diamond-prices/');
check(
    ldn_url_contains($us, '/us/diamond-prices/natural/1-carat/'),
    'on a US path, the by-carat column emits the /us/ level-3 form'
);

$fr = ldn_items_for('/fr/diamond-prices/');
check(
    ldn_url_contains($fr, '/fr/prix-diamants/naturel/1-carat/'),
    'on a French path, the same entry emits the localised /fr/prix-diamants/ form'
);
check(
    !ldn_url_contains($fr, '/us/diamond-prices/'),
    'a French request emits NO US price URL, which is the bug this guards'
);

$editorial = ldn_items_for('/cushion-cut-engagement-rings/');
check(
    ldn_url_contains($editorial, '/us/diamond-prices/'),
    'on a legacy editorial post, pricing entries fall back to the default country'
);

// -----------------------------------------------------------------------------
// Size entries carry no country segment
//
// This used to render on /fr/ and /jp/ as well, which CP125_05 made impossible: size
// is gated to `size_module.rollout_country`, so it is US-only in the menu now. The
// rule being checked was always the URL SHAPE, so it is checked where size is live,
// and the country-sensitivity half is checked on pricing URLs, which do carry one.
// Size families declaring no {country} is additionally enforced at build time by
// tests/unit/test_navigation_config.py::TestSizeFamiliesCarryNoCountry.
// -----------------------------------------------------------------------------

$us_items = ldn_items_for('/us/diamond-prices/');
$size_urls = array();
foreach (ldn_urls($us_items) as $url) {
    if (strpos($url, '/diamond-size/') !== false) {
        $size_urls[] = $url;
    }
}
check($size_urls !== array(), 'size entries are emitted where size is live');

$leaked = array();
foreach ($size_urls as $url) {
    // Any two-letter segment immediately before /diamond-size/ is a country leak.
    if (preg_match('#/[a-z]{2}/diamond-size/#', $url)) {
        $leaked[] = $url;
    }
}
check(
    $leaked === array(),
    'no size URL carries a country segment: ' . implode(', ', array_slice($leaked, 0, 3))
);

$ldn_price_hub = array(
    'us' => 'diamond-prices',
    'fr' => 'prix-diamants',
    'jp' => 'daiyamondo-kakaku',
);
foreach (array('us', 'fr', 'jp') as $country) {
    $hub = $ldn_price_hub[$country];
    $items = ldn_items_for('/' . $country . '/diamond-prices/');
    $price_urls = array();
    foreach (ldn_urls($items) as $url) {
        if (strpos($url, '/' . $hub . '/') !== false) {
            $price_urls[] = $url;
        }
    }
    $wrong_country = array();
    foreach ($price_urls as $url) {
        if (!preg_match('#/' . $country . '/' . preg_quote($hub, '#') . '/#', $url)) {
            $wrong_country[] = $url;
        }
    }
    check(
        $price_urls !== array() && $wrong_country === array(),
        sprintf(
            'every pricing URL on /%s/ carries that country (%d URLs, %d wrong)',
            $country,
            count($price_urls),
            count($wrong_country)
        )
    );
}

// -----------------------------------------------------------------------------
// Every href is real
// -----------------------------------------------------------------------------

$items = ldn_items_for('/us/diamond-prices/');
$bad = array();
foreach ($items as $item) {
    if (!is_string($item->url) || $item->url === '' || $item->url === '#') {
        $bad[] = isset($item->title) ? $item->title : '(untitled)';
    }
}
check(
    $bad === array(),
    'every emitted item has a real href, no empties and no "#" placeholders: ' . implode(', ', $bad)
);

$untitled = array();
foreach ($items as $item) {
    if (!is_string($item->title) || trim($item->title) === '') {
        $untitled[] = $item->url;
    }
}
check(
    $untitled === array(),
    'every emitted item has a label: ' . implode(', ', $untitled)
);

// -----------------------------------------------------------------------------
// Mega-menu classes and nesting
// -----------------------------------------------------------------------------

$prices = null;
foreach ($items as $item) {
    if (in_array('ldn-nav-diamond_prices', $item->classes, true)) {
        $prices = $item;
    }
}
check($prices !== null, 'the Diamond Prices parent item is emitted');
if ($prices !== null) {
    check(
        in_array('ldn-nav-mega-3-col', $prices->classes, true),
        'mega_menu_columns: 3 becomes a 3-column class on the parent'
    );
    check(
        in_array('menu-item-has-children', $prices->classes, true),
        'a mega parent is marked as having children so the theme renders a submenu'
    );

    $children = 0;
    foreach ($items as $item) {
        if ((int) $item->menu_item_parent === (int) $prices->ID) {
            $children++;
        }
    }
    check($children > 0, 'the Diamond Prices item has children parented to it');
}

$orders = array();
foreach ($items as $item) {
    $orders[] = (int) $item->menu_order;
}
check(
    $orders === array_values(array_unique($orders)),
    'every emitted item has a distinct menu_order, so ordering is deterministic'
);
check(
    $orders !== array() && $orders === array_values(array_filter($orders, function ($o) {
        return $o > 0;
    })),
    'menu_order is always positive, so injected items never sort above menu position 0'
);

// -----------------------------------------------------------------------------
// Level 4 is emitted only because it is anchored
// -----------------------------------------------------------------------------

$level_4 = array();
foreach (ldn_urls($items) as $url) {
    if (preg_match('#/us/diamond-prices/natural/[^/]+/[^/]+/#', $url)) {
        $level_4[] = $url;
    }
}
check(
    $level_4 !== array(),
    'the anchored by-shape column does emit level-4 shape URLs'
);
$all_anchored = true;
foreach ($level_4 as $url) {
    if (strpos($url, '/1-carat/') === false) {
        $all_anchored = false;
    }
}
check(
    $all_anchored,
    'every level-4 URL sits at the declared anchor carat, not some other weight'
);

// An unanchored level-4 entry must be refused outright.
$nav = new LDN_Nav('ringspo', $config, $fetcher, ldn_rollout_all_on());
$GLOBALS['__ldn_nav_path'] = '/us/diamond-prices/';
$scope = $nav->resolve_request_scope();
$unanchored = $nav->nav_items($scope, array(
    'items' => array(
        array(
            'id' => 'bad_shapes',
            'columns' => array(
                array(
                    'heading' => 'Shapes',
                    'entries' => array(
                        'kind' => 'generated',
                        'page_family' => 'price_individual_shape',
                        'diamond_type' => 'natural',
                        'shapes' => array('round', 'oval'),
                    ),
                ),
            ),
        ),
    ),
));
check(
    $unanchored === array(),
    'an unanchored level-4 fan-out emits nothing rather than guessing a carat'
);

// -----------------------------------------------------------------------------
// One bad entry must not cost the site its header
// -----------------------------------------------------------------------------

$mixed = $nav->nav_items($scope, array(
    'items' => array(
        array(
            'id' => 'bogus',
            'label' => 'Bogus',
            'target' => array('kind' => 'generated', 'page_family' => 'price_level_9'),
        ),
        array(
            'id' => 'sell',
            'label' => 'Sell jewelry',
            'target' => array('kind' => 'editorial', 'url' => '/sell-jewelry/'),
        ),
    ),
));
check(
    count($mixed) === 1 && strpos($mixed[0]->url, '/sell-jewelry/') !== false,
    'an unknown page_family is dropped while the rest of the menu still renders'
);

// -----------------------------------------------------------------------------
// Injection matches the theme LOCATION, not the menu name
// -----------------------------------------------------------------------------

$GLOBALS['__ldn_nav_path'] = '/us/diamond-prices/';
$GLOBALS['ldn_preview_query_vars'] = array();
$GLOBALS['ldn_preview_menu_locations'] = array('primary' => 77, 'secondary' => 78);

// A menu whose editorial name would produce the DOM id `menu-aus-menu`. This is
// the fixture that fails if anyone reintroduces slug matching.
$aus_menu = new stdClass();
$aus_menu->term_id = 77;
$aus_menu->name = 'Aus Menu';
$aus_menu->slug = 'aus-menu';

$existing = array();
$post = new stdClass();
$post->ID = 11;
$post->db_id = 11;
$post->title = 'Engagement Rings';
$post->url = 'https://ringspo.com/engagement-rings/';
$post->menu_item_parent = '0';
$post->menu_order = 1;
$post->classes = array();
$existing[] = $post;

$nav = new LDN_Nav('ringspo', $config, $fetcher, ldn_rollout_all_on());
$filtered = $nav->filter_menu_items($existing, $aus_menu, array());

check(
    count($filtered) > 1,
    'injection fires on a menu named "Aus Menu", proving it keys on location not slug'
);
$filtered_titles = array_map(function ($i) { return $i->title; }, $filtered);
check(
    !in_array('Engagement Rings', $filtered_titles, true),
    'replace mode drops the existing WordPress items rather than appending to them'
);
check(
    $filtered[0]->title === 'Diamond Prices',
    'replace mode starts from the declared menu, not the leftover WordPress item'
);

// -----------------------------------------------------------------------------
// Injection mode is a per-country claim
//
// Test intent: `replace` says "this file is the whole menu in this market", so it
// applies only where that market's own items have been extracted into config.
// A market that has not been migrated keeps its WordPress menu and merely gains
// the generated items.
// Would fail if: injection_mode were read as one site-wide string, as it was
// before LDN 0.37.0. Ringspo's nine subsites carry nine hand-built menus that
// share almost nothing - 168 items on us, 40 Japanese items on jp, five on ie -
// so a site-wide `replace` deletes eight markets' headers and leaves English
// labels pointing at US slugs that 404 under those mounts.
// -----------------------------------------------------------------------------

$navigation_modes = $config->get_navigation('ringspo');
check(
    is_array($navigation_modes['injection_mode']),
    'injection_mode ships as a per-country map, not a single string'
);
check(
    $navigation_modes['injection_mode']['us'] === 'replace'
        && $navigation_modes['injection_mode']['jp'] === 'augment',
    'the US is migrated and claims replace; Japan is not and stays on augment'
);

$GLOBALS['__ldn_nav_path'] = '/jp/daiyamondo-kakaku/';
$jp_existing = array();
$jp_post = new stdClass();
$jp_post->ID = 12;
$jp_post->db_id = 12;
$jp_post->title = '誕生石';
$jp_post->url = 'https://ringspo.com/jp/birthstones/';
$jp_post->menu_item_parent = '0';
$jp_post->menu_order = 1;
$jp_post->classes = array();
$jp_existing[] = $jp_post;

$nav_jp = new LDN_Nav('ringspo', $config, $fetcher, ldn_rollout_all_on());
$jp_filtered = $nav_jp->filter_menu_items($jp_existing, $aus_menu, array());
$jp_filtered_titles = array_map(function ($i) { return $i->title; }, $jp_filtered);

check(
    in_array('誕生石', $jp_filtered_titles, true),
    'an unmigrated market KEEPS its hand-built WordPress items, got: '
        . implode(', ', array_slice($jp_filtered_titles, 0, 5))
);
check(
    $jp_filtered[0]->title === '誕生石',
    'and keeps them first, so augmented items sort below what was hand-placed'
);
check(
    count($jp_filtered) > count($jp_existing),
    'while still gaining the generated pricing items it had no entry point for'
);
check(
    !in_array('Learn', $jp_filtered_titles, true)
        && !in_array('Sell jewelry', $jp_filtered_titles, true),
    'and does NOT inherit the US editorial items, which point at US slugs'
);

$GLOBALS['__ldn_nav_path'] = '/us/diamond-prices/';
check(
    ldn_url_contains($filtered, '/us/diamond-prices/'),
    'replace mode still includes the declared pricing items'
);

$appended_orders = array();
foreach ($filtered as $item) {
    if ((int) $item->ID >= 900000) {
        $appended_orders[] = (int) $item->menu_order;
    }
}
check(
    $appended_orders !== array() && min($appended_orders) > 1,
    'appended items sort after the existing item rather than colliding with it'
);

// A menu assigned to no location we own must be left completely alone.
$other_menu = new stdClass();
$other_menu->term_id = 999;
$other_menu->name = 'Footer Legal';
$other_menu->slug = 'footer-legal';

$nav = new LDN_Nav('ringspo', $config, $fetcher, ldn_rollout_all_on());
$untouched = $nav->filter_menu_items($existing, $other_menu, array());
check(
    $untouched === $existing,
    'a menu on no declared location is returned unchanged'
);

// -----------------------------------------------------------------------------
// Narrow variant (CP125_04)
//
// Rule: the slide-out gets a shallower declared set, not the desktop tree. Fan-out
// columns collapse to a heading that links to the hub still listing their contents;
// `list` columns stay whole because they hold the calculator and comparison tool,
// which have to remain two taps away.
// -----------------------------------------------------------------------------

function ldn_narrow_items($path = '/us/diamond-prices/') {
    global $config, $fetcher;
    $GLOBALS['__ldn_nav_path'] = $path;
    $GLOBALS['ldn_preview_query_vars'] = array();
    $nav = new LDN_Nav('ringspo', $config, $fetcher, ldn_rollout_all_on());
    $scope = $nav->resolve_request_scope();
    $navigation = $config->get_navigation('ringspo');
    return $nav->nav_items($scope, $navigation['menus']['primary'], 'narrow');
}

$desktop = ldn_items_for('/us/diamond-prices/');
$narrow = ldn_narrow_items();

check(
    count($narrow) < count($desktop),
    sprintf(
        'the narrow set (%d) is strictly smaller than the desktop set (%d)',
        count($narrow),
        count($desktop)
    )
);

$budget = (int) $navigation['menus']['primary']['mobile_item_budget'];
check($budget > 0, 'the primary menu declares a mobile_item_budget');
check(
    count($narrow) <= $budget,
    sprintf('the narrow set (%d) is within the declared budget (%d)', count($narrow), $budget)
);

// Depth: a fan-out column contributes its heading and nothing below it.
$narrow_by_id = array();
foreach ($narrow as $item) {
    $narrow_by_id[(int) $item->ID] = $item;
}
$depth_of = function ($item) use ($narrow_by_id) {
    $depth = 0;
    $parent = (int) $item->menu_item_parent;
    while ($parent !== 0 && isset($narrow_by_id[$parent])) {
        $depth++;
        $parent = (int) $narrow_by_id[$parent]->menu_item_parent;
    }
    return $depth;
};
$too_deep = array();
foreach ($narrow as $item) {
    if ($depth_of($item) > 2) {
        $too_deep[] = $item->title;
    }
}
check(
    $too_deep === array(),
    'no narrow item sits deeper than a column heading plus one tool leaf: '
        . implode(', ', $too_deep)
);

$collapsed = array();
foreach ($narrow as $item) {
    if (in_array('ldn-nav-narrow-hub', $item->classes, true)) {
        $collapsed[] = $item;
    }
}
check(
    $collapsed !== array(),
    'at least one fan-out column collapsed to a hub link'
);
foreach ($collapsed as $item) {
    check(
        $depth_of($item) === 1 && !isset($narrow_by_id[0]),
        sprintf('collapsed column "%s" sits directly under its top-level item', $item->title)
    );
    $has_children = false;
    foreach ($narrow as $other) {
        if ((int) $other->menu_item_parent === (int) $item->ID) {
            $has_children = true;
        }
    }
    check(
        !$has_children,
        sprintf('collapsed column "%s" contributes no children', $item->title)
    );
}

// Every href real, and no two rows in the same branch pointing at one page.
$narrow_bad = array();
foreach ($narrow as $item) {
    if (!is_string($item->url) || $item->url === '' || $item->url === '#') {
        $narrow_bad[] = $item->title;
    }
}
check(
    $narrow_bad === array(),
    'every narrow item has a real href, so no top-level item is a dead toggle: '
        . implode(', ', $narrow_bad)
);

$branch_urls = array();
$dupes = array();
foreach ($narrow as $item) {
    // Group by branch root, so "Prices by Carat" and "Prices by Shape" are compared
    // with each other and with their parent, but not with the size branch.
    $root = (int) $item->menu_item_parent === 0 ? (int) $item->ID : (int) $item->menu_item_parent;
    $key = $root . '|' . $item->url;
    if (isset($branch_urls[$key])) {
        $dupes[] = $item->url;
    }
    $branch_urls[$key] = true;
}
check(
    $dupes === array(),
    'no two rows in one branch share an href: ' . implode(', ', $dupes)
);

// Two taps: top-level item, then the tool itself.
$two_tap = array();
foreach ($narrow as $item) {
    if ($depth_of($item) <= 2) {
        $two_tap[] = $item->url;
    }
}
$calculator_reachable = false;
$comparison_reachable = false;
foreach ($two_tap as $url) {
    if (strpos($url, '/calculator/') !== false) {
        $calculator_reachable = true;
    }
    if (strpos($url, '/diamond-size/compare/') !== false) {
        $comparison_reachable = true;
    }
}
check($calculator_reachable, 'the price calculator is reachable within two taps in the slide-out');
check($comparison_reachable, 'the size comparison tool is reachable within two taps in the slide-out');

// -----------------------------------------------------------------------------
// Render detection: args, never a user agent or a viewport
// -----------------------------------------------------------------------------

$GLOBALS['ldn_preview_menu_locations'] = array('primary' => 77, 'secondary' => 78);
$GLOBALS['__ldn_nav_path'] = '/us/diamond-prices/';
$GLOBALS['ldn_preview_query_vars'] = array();

$nav = new LDN_Nav('ringspo', $config, $fetcher, ldn_rollout_all_on());
$injected = $nav->filter_menu_items($existing, $aus_menu, array());
$injected_count = count($injected);

// The desktop render must pass straight through the objects filter.
$desktop_args = new stdClass();
$desktop_args->theme_location = 'primary';
$desktop_args->menu_id = '';
$nav_desktop = new LDN_Nav('ringspo', $config, $fetcher, ldn_rollout_all_on());
$after_desktop = $nav_desktop->filter_menu_objects($injected, $desktop_args);
check(
    count($after_desktop) === $injected_count,
    'a desktop render is returned untouched by the narrow filter'
);

// The slide-out render must come back shorter.
$slideout_args = new stdClass();
$slideout_args->theme_location = 'primary';
$slideout_args->menu_id = 'generate-slideout-menu';
$nav_narrow = new LDN_Nav('ringspo', $config, $fetcher, ldn_rollout_all_on());
$after_narrow = $nav_narrow->filter_menu_objects($injected, $slideout_args);
check(
    count($after_narrow) < $injected_count,
    sprintf(
        'the slide-out render swaps in the narrow set (%d < %d)',
        count($after_narrow),
        $injected_count
    )
);
check(
    $after_narrow[0]->title === 'Diamond Prices',
    'replace mode drops leftover WordPress items in the slide-out as well as on desktop'
);

$narrow_titles = array_map(function ($i) { return $i->title; }, $after_narrow);
check(
    in_array('About', $narrow_titles, true)
        && in_array('Contact', $narrow_titles, true),
    'the US slide-out carries the secondary utility items, because GP hides that bar on a phone'
);
check(
    in_array('ldn-nav-utility-start', $after_narrow[0]->classes, true) === false,
    'the utility separator sits on the first secondary item, not on Diamond Prices'
);
$utility_marked = 0;
foreach ($after_narrow as $item) {
    if (in_array('ldn-nav-utility-start', $item->classes, true)) {
        $utility_marked++;
        check(
            $item->title === 'About',
            'the first utility item in the slide-out is About, got: ' . $item->title
        );
    }
}
check($utility_marked === 1, 'exactly one slide-out item is marked as the utility start');

$GLOBALS['__ldn_nav_path'] = '/jp/daiyamondo-kakaku/';
$nav_jp_slide = new LDN_Nav('ringspo', $config, $fetcher, ldn_rollout_all_on());
$jp_slide = $nav_jp_slide->filter_menu_objects($jp_filtered, $slideout_args);
$jp_slide_titles = array_map(function ($i) { return $i->title; }, $jp_slide);
check(
    in_array('誕生石', $jp_slide_titles, true)
        && !in_array('About', $jp_slide_titles, true)
        && !in_array('Contact', $jp_slide_titles, true),
    'an augment market does not receive the US utility items in the slide-out'
);
$GLOBALS['__ldn_nav_path'] = '/us/diamond-prices/';

$slideout_by_location = new stdClass();
$slideout_by_location->theme_location = 'slideout';
$slideout_by_location->menu_id = '';
$nav_loc = new LDN_Nav('ringspo', $config, $fetcher, ldn_rollout_all_on());
check(
    count($nav_loc->filter_menu_objects($injected, $slideout_by_location)) < $injected_count,
    'a site that gives the slide-out its own theme location is also detected'
);

// -----------------------------------------------------------------------------
// No user-agent or viewport sniffing anywhere in the module
// -----------------------------------------------------------------------------

$nav_source = php_strip_whitespace(LDN_PLUGIN_DIR . 'includes/class-ldn-nav.php');
foreach (array('HTTP_USER_AGENT', 'wp_is_mobile', 'HTTP_ACCEPT_LANGUAGE') as $sniff) {
    check(
        strpos($nav_source, $sniff) === false,
        sprintf(
            'narrow detection must not use %s: output would vary on a non-URL input '
                . 'and the page cache keys on URL',
            $sniff
        )
    );
}

// -----------------------------------------------------------------------------
// Rollout gating (CP125_05)
//
// Rule: an entry gated on a module is ABSENT from the item list for any site and
// country where that module is not live in the rollout hub. Gated-out entries are
// omitted, never rendered-and-hidden, or crawlers index pre-launch pages and the
// staged rollout leaks.
// -----------------------------------------------------------------------------

/**
 * Rollout reader stub. Only is_enabled() is consulted by the gate.
 *
 * Keys are `country/module`. `*` as the country means every country, which the tests
 * about item BUILDING use so they are not silently also testing gating.
 */
class LDN_Fake_Rollout {
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

/**
 * @param array  $state   e.g. array('us/price' => true, 'us/size' => false)
 * @param string $path
 * @return array<int, object>
 */
function ldn_gated_items(array $state, $path = '/us/diamond-prices/', $menu_name = 'primary') {
    global $config, $fetcher;
    $GLOBALS['__ldn_nav_path'] = $path;
    $GLOBALS['ldn_preview_query_vars'] = array();
    $nav = new LDN_Nav('ringspo', $config, $fetcher, new LDN_Fake_Rollout($state));
    $scope = $nav->resolve_request_scope();
    $navigation = $config->get_navigation('ringspo');
    return $nav->nav_items($scope, $navigation['menus'][$menu_name]);
}

function ldn_titles(array $items) {
    return array_map(function ($i) { return $i->title; }, $items);
}

// Both modules live: the full menu.
$both = ldn_gated_items(array('us/price' => true, 'us/size' => true));
$both_titles = ldn_titles($both);
check(in_array('Diamond Prices', $both_titles, true), 'with price live, the prices item is present');
check(in_array('Diamond Sizes', $both_titles, true), 'with size live, the sizes item is present');

// Price off: the whole prices branch goes, sizes untouched.
$no_price = ldn_gated_items(array('us/price' => false, 'us/size' => true));
$no_price_titles = ldn_titles($no_price);
check(
    !in_array('Diamond Prices', $no_price_titles, true),
    'with price OFF the prices item is absent from the item list entirely'
);
check(
    !in_array('Prices by Carat', $no_price_titles, true)
        && !in_array('Price Calculator', $no_price_titles, true),
    'with price OFF nothing beneath the prices item survives as an orphan'
);
check(
    in_array('Diamond Sizes', $no_price_titles, true),
    'with price OFF the rest of the menu is intact'
);
check(
    in_array('Sell jewelry', $no_price_titles, true),
    'an UNGATED entry is unaffected by another module being off'
);

// Size off: mirror image.
$no_size = ldn_gated_items(array('us/price' => true, 'us/size' => false));
$no_size_titles = ldn_titles($no_size);
check(
    !in_array('Diamond Sizes', $no_size_titles, true),
    'with size OFF the sizes item is absent'
);
check(
    !in_array('Sizes by Shape', $no_size_titles, true)
        && !in_array('Size Comparison', $no_size_titles, true),
    'with size OFF no size child survives'
);
check(
    in_array('Diamond Prices', $no_size_titles, true),
    'with size OFF the prices branch is intact'
);

// Nothing live: no gated items at all, and no empty mega-menu triggers.
$none = ldn_gated_items(array());
$none_titles = ldn_titles($none);
check(
    in_array('Sell jewelry', $none_titles, true)
        && in_array('Learn', $none_titles, true),
    'with NO module live the ungated editorial entries remain, got: ' . implode(', ', $none_titles)
);
check(
    !in_array('Diamond Prices', $none_titles, true)
        && !in_array('Diamond Sizes', $none_titles, true),
    'with NO module live no gated generated item remains'
);
$gated_triggers = 0;
foreach ($none as $item) {
    if (in_array('ldn-nav-diamond_prices', $item->classes, true)
        || in_array('ldn-nav-diamond_sizes', $item->classes, true)
    ) {
        $gated_triggers++;
    }
}
check(
    $gated_triggers === 0,
    'a top-level item whose every column was gated out leaves no mega-menu trigger'
);

// Absence, not CSS: nothing gated-out may appear carrying a "hidden" marker.
$hidden_markers = 0;
foreach ($none as $item) {
    foreach ($item->classes as $class) {
        if (stripos($class, 'hidden') !== false || stripos($class, 'gated') !== false) {
            $hidden_markers++;
        }
    }
}
check(
    $hidden_markers === 0,
    'gated entries are omitted, not emitted with a hiding class for CSS to deal with'
);

// Size has a second gate: its own rollout country, separate from the hub switch.
$gb = ldn_gated_items(array('uk/price' => true, 'uk/size' => true), '/uk/diamond-prices/');
$gb_titles = ldn_titles($gb);
check(
    in_array('Diamond Prices', $gb_titles, true),
    'price live for UK renders the prices item'
);
check(
    !in_array('Diamond Sizes', $gb_titles, true),
    'size is absent for UK even with the hub ON, because us is size_module.rollout_country'
);

// No reader at all must not mean everything-on.
$GLOBALS['__ldn_nav_path'] = '/us/diamond-prices/';
$nav_no_reader = new LDN_Nav('ringspo', $config, $fetcher, new LDN_Fake_Rollout(array()));
$scope_nr = $nav_no_reader->resolve_request_scope();
$no_reader_titles = ldn_titles(
    $nav_no_reader->nav_items($scope_nr, $navigation['menus']['primary'])
);
check(
    in_array('Sell jewelry', $no_reader_titles, true)
        && !in_array('Diamond Prices', $no_reader_titles, true),
    'an empty rollout state omits gated entries rather than defaulting them visible'
);

/*
 * Empty-parent collapse, with an UNGATED parent over GATED columns.
 *
 * Ringspo's own menu cannot exercise this: its top-level items carry the same gate as
 * their children, so the parent is dropped by its own gate and the collapse branch
 * never runs. A mutation that removed the collapse entirely still passed until this
 * case existed. Hence a purpose-built menu config.
 */
$parent_over_gated_columns = array(
    'items' => array(
        array(
            'id' => 'sizes_ungated_parent',
            'label' => 'Diamond Sizes',
            'target' => array('kind' => 'generated', 'page_family' => 'size_mega_hub'),
            'mega_menu_columns' => 2,
            'columns' => array(
                array(
                    'heading' => 'By shape',
                    'entries' => array(
                        'kind' => 'generated',
                        'page_family' => 'size_shape_hub',
                        'shapes' => array('round', 'oval'),
                        'gate' => array('module' => 'size'),
                    ),
                ),
                array(
                    'heading' => 'By carat',
                    'entries' => array(
                        'kind' => 'generated',
                        'page_family' => 'size_individual',
                        'anchor_shape' => 'round',
                        'carats' => array('1', '2'),
                        'gate' => array('module' => 'size'),
                    ),
                ),
            ),
        ),
    ),
);

$GLOBALS['__ldn_nav_path'] = '/us/diamond-prices/';
$nav_collapse = new LDN_Nav('ringspo', $config, $fetcher, new LDN_Fake_Rollout(array(
    'us/price' => true,
    'us/size' => false,
)));
$scope_c = $nav_collapse->resolve_request_scope();
$collapsed = $nav_collapse->nav_items($scope_c, $parent_over_gated_columns);
check(
    $collapsed === array(),
    sprintf(
        'an ungated parent whose every column is gated out is dropped too, got %d item(s): %s',
        count($collapsed),
        implode(', ', ldn_titles($collapsed))
    )
);

$nav_kept = new LDN_Nav('ringspo', $config, $fetcher, ldn_rollout_all_on());
$scope_k = $nav_kept->resolve_request_scope();
$kept = $nav_kept->nav_items($scope_k, $parent_over_gated_columns);
$kept_titles = ldn_titles($kept);
check(
    in_array('Diamond Sizes', $kept_titles, true) && in_array('By shape', $kept_titles, true),
    'the same parent and its columns survive when the module IS live: ' . implode(', ', $kept_titles)
);
check(
    count($kept) >= 5,
    sprintf('the live version emits the parent, both headings and the leaves (got %d)', count($kept))
);

// An unknown module is refused, not treated as always-on.
$nav_unknown = new LDN_Nav('ringspo', $config, $fetcher, new LDN_Fake_Rollout(array(
    'us/price' => true,
    'us/pricing' => true,
)));
$scope_u = $nav_unknown->resolve_request_scope();
check(
    $nav_unknown->entry_is_live(array('gate' => array('module' => 'pricing')), $scope_u) === false,
    'a hub synonym like "pricing" is refused at runtime, not silently always-on'
);
check(
    $nav_unknown->entry_is_live(array('gate' => array('module' => '')), $scope_u) === false,
    'a gate naming no module is refused'
);
check(
    $nav_unknown->entry_is_live(array(), $scope_u) === true,
    'an entry with no gate always passes'
);
check(
    in_array('price', LDN_Nav::GATE_MODULES, true)
        && in_array('size', LDN_Nav::GATE_MODULES, true)
        && in_array('standard_pages', LDN_Nav::GATE_MODULES, true)
        && count(LDN_Nav::GATE_MODULES) === 3,
    'the runtime gate vocabulary matches the rollout hub (price, size, standard_pages) exactly'
);

// -----------------------------------------------------------------------------
// Country switcher (CP126_01)
//
// Test intent: the switcher lists exactly the markets the register declares
// live, each linking to that market's own pricing hub as a root-relative path.
// Would fail if: the list were hardcoded, as the nine hand-built subsite copies
// were, so 21 of 30 configured markets stayed unreachable and nothing broke; or
// if a switcher link went through home_url(), which on the /us/ install turns
// /jp/... into /us/jp/... and sends every market switch to a 404.
// -----------------------------------------------------------------------------

/**
 * The switcher parent and its country children, from a rendered secondary menu.
 */
function ldn_switcher(array $items) {
    $parent = null;
    $children = array();
    foreach ($items as $item) {
        if (in_array('ldn-nav-country_switcher', $item->classes, true)) {
            $parent = $item;
            continue;
        }
        if ($parent !== null && (string) $item->menu_item_parent === (string) $parent->ID) {
            $children[] = $item;
        }
    }
    return array($parent, $children);
}

$secondary_us = ldn_items_for('/us/diamond-prices/', 'secondary');
list($switcher_us, $markets_us) = ldn_switcher($secondary_us);

check($switcher_us !== null, 'the secondary menu still carries a country switcher parent');
check(
    $switcher_us !== null && $switcher_us->url !== '' && $switcher_us->url !== '#',
    'the switcher parent has a real destination, not "#": on a phone it is what gets tapped'
);
check(
    $switcher_us !== null && in_array('menu-item-has-children', $switcher_us->classes, true),
    'the switcher parent is marked as having children so the theme renders a submenu'
);

$market_titles = array();
$market_urls = array();
foreach ($markets_us as $item) {
    $market_titles[] = $item->title;
    $market_urls[$item->title] = $item->url;
}

$navigation = $config->get_navigation('ringspo');
$switcher_entry = null;
foreach ($navigation['menus']['secondary']['items'] as $entry) {
    if (isset($entry['id']) && $entry['id'] === 'country_switcher') {
        $switcher_entry = $entry;
        break;
    }
}
$register = $switcher_entry['target']['countries'];

check(
    count($markets_us) === count($register['live']),
    sprintf(
        'the switcher renders one item per live market, expected %d got %d',
        count($register['live']),
        count($markets_us)
    )
);
check(
    count($register['suppressed']) > 0,
    'the register suppresses the markets with no subsite, rather than omitting them silently'
);
foreach ($register['suppressed'] as $entry) {
    if (!ldn_url_contains($markets_us, '/' . $entry['code'] . '/')) {
        continue;
    }
    check(false, sprintf('suppressed market "%s" must not appear in the switcher', $entry['code']));
}

check(
    isset($market_urls['United States']) && $market_urls['United States'] === '/us/diamond-prices/',
    'the US resolves to its path form, not the bare domain the hand-built menu used'
);
check(
    isset($market_urls['Japan']) && strpos($market_urls['Japan'], '/jp/daiyamondo-kakaku/') === 0,
    'Japan links to its localised path, got: '
        . (isset($market_urls['Japan']) ? $market_urls['Japan'] : 'nothing')
);
check(
    isset($market_urls['United Kingdom']) && $market_urls['United Kingdom'] === '/uk/diamond-prices/',
    'every market links to its own pricing hub'
);

$non_root = array();
foreach ($market_urls as $title => $url) {
    if (strpos($url, '/') !== 0) {
        $non_root[] = $title . ' => ' . $url;
    }
}
check(
    $non_root === array(),
    'switcher links are root-relative so they cross subsites, got: ' . implode(', ', $non_root)
);

$current_us = array();
foreach ($markets_us as $item) {
    if ($item->current === true) {
        $current_us[] = $item->title;
    }
}
check(
    $current_us === array('United States'),
    'on a US path exactly the US entry is current, got: ' . implode(', ', $current_us)
);

/*
 * The mount bug, exercised against a REAL mounted install rather than the
 * harness default of `/`. Every other nav link is mount-relative, and rightly:
 * it points inside the current subsite. A switcher link points at a different
 * one, so passing it through home_url() on the /jp/ install yields
 * /jp/us/diamond-prices/ - a 404 on every market switch. With the mount left at
 * `/` this check passes either way and guards nothing.
 */
add_filter('ldn_install_mount_path', function ($value) {
    return isset($GLOBALS['__ldn_mount']) ? $GLOBALS['__ldn_mount'] : $value;
});
$GLOBALS['__ldn_mount'] = '/jp/';
$GLOBALS['ldn_preview_site_base'] = '/jp';

list(, $markets_jp) = ldn_switcher(ldn_items_for('/jp/daiyamondo-kakaku/', 'secondary'));
$jp_urls = array();
$current_jp = array();
foreach ($markets_jp as $item) {
    $jp_urls[] = $item->url;
    if ($item->current === true) {
        $current_jp[] = $item->url;
    }
}

$GLOBALS['__ldn_mount'] = null;
$GLOBALS['ldn_preview_site_base'] = '';

check(
    in_array('/us/diamond-prices/', $jp_urls, true),
    'mounted at /jp/, the US link is still /us/diamond-prices/ and not nested under the mount, got: '
        . implode(', ', $jp_urls)
);
check(
    $current_jp === array('/jp/daiyamondo-kakaku/'),
    'the current market follows the request, got: ' . implode(', ', $current_jp)
);

// A yaml-live market whose prices are not on must not appear. That is the
// 404-behind-a-working-menu failure the hub/switcher sync exists to prevent.
list(, $markets_partial) = ldn_switcher(ldn_gated_items(
    array(
        '*/price' => true,
        'uk/price' => false,
    ),
    '/us/diamond-prices/',
    'secondary'
));
check(
    !ldn_url_contains($markets_partial, '/uk/'),
    'UK is live in the register but omitted from the switcher while price is off'
);
check(
    ldn_url_contains($markets_partial, '/us/diamond-prices/'),
    'US stays in the switcher because its price module is on'
);

// Launching a market is a register edit, not a code change.
$GLOBALS['__ldn_nav_path'] = '/us/diamond-prices/';
$nav_extra = new LDN_Nav('ringspo', $config, $fetcher, ldn_rollout_all_on());
$menu_extra = $navigation['menus']['secondary'];
foreach ($menu_extra['items'] as $i => $entry) {
    if (isset($entry['id']) && $entry['id'] === 'country_switcher') {
        $menu_extra['items'][$i]['target']['countries']['live'][] = 'de';
    }
}
list(, $markets_extra) = ldn_switcher(
    $nav_extra->nav_items($nav_extra->resolve_request_scope(), $menu_extra)
);
check(
    ldn_url_contains($markets_extra, '/de/diamant-preise/')
        || ldn_url_contains($markets_extra, '/de/'),
    'moving a market into the register\'s live list adds it with no code change'
);
check(
    count($markets_extra) === count($markets_us) + 1,
    'and adds exactly one item'
);

// -----------------------------------------------------------------------------
// Terminology resolves from the bundle's nav_terms (CP124_03)
//
// Test intent: a structural label and a market name are rendered in the locale
// of the request, from the same i18n tree the destination page resolves from,
// while an editorial label pointing at an English post stays English.
// Would fail if: structural labels stayed on the gettext map, which only has an
// en_GB catalogue, so /jp/ read "Diamond Prices" above ダイヤモンド価格; or if
// the editorial entries were translated too, promising Japanese articles that
// do not exist.
// -----------------------------------------------------------------------------

$jp_primary_titles = ldn_titles(ldn_items_for('/jp/daiyamondo-kakaku/'));
$us_primary_titles = ldn_titles(ldn_items_for('/us/diamond-prices/'));

$jp_terms = $config->get_nav_terms('ringspo', 'ja-JP');
check(
    !empty($jp_terms['label']) && !empty($jp_terms['country']),
    'the bundle ships resolved labels and market names for ja-JP'
);

$jp_prices = $jp_terms['label']['nav.diamond_prices'];
check(
    in_array($jp_prices, $jp_primary_titles, true),
    'on a Japanese path the pricing item uses the Japanese term, got: ' . $jp_prices
);
check(
    !in_array('Diamond Prices', $jp_primary_titles, true),
    'and does NOT also render the English one'
);
check(
    in_array('Diamond Prices', $us_primary_titles, true),
    'on a US path the same entry is English, so the term follows the request'
);

/*
 * The rule that keeps the menu honest: an editorial label is the literal from
 * config, never a resolved term, because its destination post is one specific
 * article in one language. Exercised with an entry scoped to `jp` rather than
 * with the US items: those are `countries: [us]` and correctly absent here, so
 * asserting on them would only have proved the leak this scoping removed.
 */
$editorial_jp = array(
    'items' => array(
        array(
            'id' => 'jp_editorial_probe',
            // A label that also exists as a resolvable nav term. If editorial
            // ever started going through the resolver, this would come back as
            // the Japanese term instead of the literal.
            'label' => 'Diamond Prices',
            'countries' => array('jp'),
            'target' => array('kind' => 'editorial', 'url' => '/jp/probe/'),
        ),
    ),
);
$GLOBALS['__ldn_nav_path'] = '/jp/daiyamondo-kakaku/';
$nav_editorial = new LDN_Nav('ringspo', $config, $fetcher, ldn_rollout_all_on());
$editorial_titles = ldn_titles(
    $nav_editorial->nav_items($nav_editorial->resolve_request_scope(), $editorial_jp)
);
check(
    in_array('Diamond Prices', $editorial_titles, true),
    'an editorial label stays the config literal on a Japanese path, got: '
        . implode(', ', $editorial_titles)
);
check(
    !in_array($jp_prices, $editorial_titles, true),
    'and is NOT swapped for the resolved term, which would promise a Japanese '
        . 'article that does not exist'
);

// The US editorial items must be absent here, which is the other half of it.
foreach (array('Sell jewelry', 'Where to buy', 'Learn') as $us_only) {
    check(
        !in_array($us_only, $jp_primary_titles, true),
        sprintf(
            'US editorial item "%s" does not appear on a Japanese path: it points '
                . 'at a US slug that 404s under the /jp/ mount',
            $us_only
        )
    );
}

list(, $jp_markets) = ldn_switcher(ldn_items_for('/jp/daiyamondo-kakaku/', 'secondary'));
$jp_market_titles = array();
foreach ($jp_markets as $item) {
    $jp_market_titles[] = $item->title;
}
check(
    in_array($jp_terms['country']['jp'], $jp_market_titles, true)
        && !in_array('Japan', $jp_market_titles, true),
    'market names follow the locale too, got: ' . implode(', ', $jp_market_titles)
);
check(
    count($jp_market_titles) === count($market_titles),
    'the same markets are listed whatever the locale; only the wording changes'
);

// A locale the tree does not cover must fall back to the plugin's English rather
// than emitting the Translator's [key] marker or an empty label.
$GLOBALS['__ldn_nav_path'] = '/us/diamond-prices/';
$nav_unknown = new LDN_Nav('ringspo', $config, $fetcher, ldn_rollout_all_on());
$unknown_scope = array(
    'site_id' => 'ringspo',
    'country_code' => 'us',
    'locale' => 'xx-XX',
);
$unknown_titles = ldn_titles(
    $nav_unknown->nav_items($unknown_scope, $navigation['menus']['primary'])
);
check(
    in_array('Diamond Prices', $unknown_titles, true),
    'an unknown locale falls back to the plugin English, got: '
        . implode(', ', array_slice($unknown_titles, 0, 4))
);
foreach ($unknown_titles as $title) {
    if ($title === '' || strpos($title, '[') === 0) {
        check(false, 'no label may be empty or a [key] debug marker, got: ' . $title);
    }
}

$switcher_source = php_strip_whitespace(
    LDN_PLUGIN_DIR . 'components/trait-ldn-nav-country-switcher.php'
);
foreach (array('<ul', '<li', '<nav', '<a href') as $markup) {
    check(
        stripos($switcher_source, $markup) === false,
        sprintf('the country switcher must not emit %s either', $markup)
    );
}

// -----------------------------------------------------------------------------
// Footer (US replace only; theme widgets elsewhere)
//
// Test intent: the US footer is the price and size hubs plus the migrated
// widget links, painted by LDN when no WordPress menu is assigned to the
// location, and Japan keeps the theme footer.
// Would fail if: auto-render ran on augment markets (US legal URLs under /jp/),
// or the James Allen offer were copied in.
// -----------------------------------------------------------------------------

$GLOBALS['ldn_preview_menu_locations'] = array('primary' => 77, 'secondary' => 78);
$GLOBALS['__ldn_nav_path'] = '/us/diamond-prices/';
$nav_footer = new LDN_Nav('ringspo', $config, $fetcher, ldn_rollout_all_on());
$footer_html = $nav_footer->footer_menu_html();
check($footer_html !== '', 'the US footer paints when no menu is assigned to the location');
check(
    strpos($footer_html, 'Diamond Prices') !== false
        && strpos($footer_html, '/us/diamond-prices/') !== false
        && strpos($footer_html, 'Diamond Sizes') !== false
        && strpos($footer_html, '/diamond-size/') !== false,
    'the US footer starts with the price and size hubs, the same destinations as the header'
);
check(
    strpos($footer_html, 'Privacy Policy') !== false
        && strpos($footer_html, '/privacy-policy/') !== false,
    'the US footer includes the privacy link from config'
);
check(
    strpos($footer_html, 'James Allen') === false
        && strpos($footer_html, 'james-allen') === false,
    'the James Allen offer is not migrated into the footer'
);
check(
    strpos($footer_html, 'class="ldn-nav-footer"') !== false,
    'the footer strip uses the plugin class the stylesheet owns'
);

$GLOBALS['__ldn_nav_path'] = '/jp/daiyamondo-kakaku/';
$nav_footer_jp = new LDN_Nav('ringspo', $config, $fetcher, ldn_rollout_all_on());
check(
    $nav_footer_jp->footer_menu_html() === '',
    'Japan does not receive the US footer strip; the theme still owns that footer'
);

$GLOBALS['__ldn_nav_path'] = '/us/diamond-prices/';
$GLOBALS['ldn_preview_menu_locations'] = array(
    'primary' => 77,
    'secondary' => 78,
    'footer' => 79,
);
$nav_footer_assigned = new LDN_Nav('ringspo', $config, $fetcher, ldn_rollout_all_on());
check(
    $nav_footer_assigned->footer_menu_html() === '',
    'auto-render stays silent when a WordPress menu is assigned to footer, so the strip is not painted twice'
);

$footer_menu = new stdClass();
$footer_menu->term_id = 79;
$footer_menu->name = 'Footer';
$footer_menu->slug = 'footer';
$theme_offer = new stdClass();
$theme_offer->ID = 20;
$theme_offer->db_id = 20;
$theme_offer->title = 'James Allen offer (theme)';
$theme_offer->url = '/recommends/james-allen-footer/';
$theme_offer->menu_item_parent = '0';
$theme_offer->menu_order = 1;
$theme_offer->classes = array();
$footer_injected = $nav_footer_assigned->filter_menu_items(array($theme_offer), $footer_menu, array());
$footer_injected_titles = array_map(function ($i) { return $i->title; }, $footer_injected);
check(
    in_array('Privacy Policy', $footer_injected_titles, true)
        && !in_array('James Allen offer (theme)', $footer_injected_titles, true),
    'an assigned footer location still uses replace, so the leftover offer widget is dropped'
);

$GLOBALS['ldn_preview_menu_locations'] = array('primary' => 77, 'secondary' => 78);

$no_legal = ldn_gated_items(
    array(
        '*/price' => true,
        '*/size' => true,
        '*/standard_pages' => false,
    ),
    '/us/diamond-prices/',
    'footer'
);
$no_legal_titles = ldn_titles($no_legal);
check(
    in_array('About', $no_legal_titles, true)
        && !in_array('Privacy Policy', $no_legal_titles, true)
        && !in_array('Terms of Service', $no_legal_titles, true),
    'legal footer links hide when standard_pages is off; the rest of the strip stays'
);
check(
    in_array('Diamond Prices', $no_legal_titles, true)
        && in_array('Diamond Sizes', $no_legal_titles, true),
    'price and size footer hubs stay when only standard_pages is off'
);

$no_price_footer = ldn_gated_items(
    array(
        '*/price' => false,
        '*/size' => true,
        '*/standard_pages' => true,
    ),
    '/us/diamond-prices/',
    'footer'
);
$no_price_footer_titles = ldn_titles($no_price_footer);
check(
    !in_array('Diamond Prices', $no_price_footer_titles, true)
        && in_array('Diamond Sizes', $no_price_footer_titles, true),
    'the footer price hub hides when price is off; the size hub stays'
);

// -----------------------------------------------------------------------------
// No markup, ever: the theme's walker renders
// -----------------------------------------------------------------------------

$source = php_strip_whitespace(LDN_PLUGIN_DIR . 'components/trait-ldn-nav-items.php');
foreach (array('<ul', '<li', '<nav', '<a href') as $markup) {
    check(
        stripos($source, $markup) === false,
        sprintf(
            'the item builder must not emit %s: GP Premium owns the slide-out, its '
                . 'JavaScript and its accessibility',
            $markup
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

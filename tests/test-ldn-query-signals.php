<?php
/**
 * Main-query signal corrections for LDN routes.
 *
 * Test intent (the rules this file enforces):
 *   1. On an LDN route the <title> is the page's own title (the same string as
 *      og:title) with the site name appended using the site's separator — not
 *      the blog index title WordPress produces because LDN rewrites carry no
 *      core query vars.
 *   2. The unused main posts query is skipped on LDN routes and its pagination
 *      state flattened, so nothing can advertise `…/round/page/2/` as the next
 *      page of a pricing URL.
 *   3. Neither correction touches anything else: not secondary queries on an
 *      LDN page, not ordinary site requests, and not the sitemap route.
 *   4. `is_home()` is NOT cleared. Core's WP::handle_404() only spares a
 *      post-less main query from a 404 when a fixed set of conditionals holds,
 *      and is_home() is the one that applies to an LDN route — so clearing it
 *      while skipping the posts query would 404 every LDN page.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-query-signals.php
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');

// --- WordPress shims ---------------------------------------------------------
$GLOBALS['ldn_hooks'] = array();
$GLOBALS['ldn_filter_overrides'] = array();
$GLOBALS['ldn_site_name'] = 'Ringspo';

function add_filter($hook, $cb, $priority = 10, $accepted = 1) {
    $GLOBALS['ldn_hooks'][] = $hook;
}
function add_action($hook, $cb, $priority = 10, $accepted = 1) {
    $GLOBALS['ldn_hooks'][] = $hook;
}
function apply_filters($hook, $value) {
    if (isset($GLOBALS['ldn_filter_overrides'][$hook])) {
        $args = array_slice(func_get_args(), 1);
        return call_user_func_array($GLOBALS['ldn_filter_overrides'][$hook], $args);
    }
    return $value;
}
function wp_strip_all_tags($text) {
    return strip_tags((string) $text);
}
function get_bloginfo($show = '', $filter = 'raw') {
    return $GLOBALS['ldn_site_name'];
}
function __($s, $d = null) { return $s; }

/**
 * Stand-in for WP_Query carrying a realistic paginated blog-index state, which
 * is exactly what an LDN request inherits today.
 */
class WP_Query {
    public $found_posts = 57;
    public $max_num_pages = 6;
    private $vars;
    private $main;

    public function __construct(array $vars = array(), $main = true) {
        $this->vars = $vars;
        $this->main = $main;
    }
    public function is_main_query() { return $this->main; }
    public function get($key) { return isset($this->vars[$key]) ? $this->vars[$key] : ''; }
}

require_once __DIR__ . '/../includes/class-ldn-page-context.php';

// --- Collaborator stubs ------------------------------------------------------
class LDN_Renderer {
    public function document_title(LDN_Page_Context $ctx) {
        return '1 Carat Round Natural Diamond Prices (US)';
    }
}
class LDN_Size_Renderer {
    public function __construct($fetcher = null, $config = null) {}
    public function page_title(LDN_Page_Context $ctx, array $summary) {
        return '1 Carat Round Diamond Size';
    }
}
class LDN_Dispatcher {
    public $ctx = null;
    public function current_context() { return $this->ctx; }
}
class LDN_Size_Dispatcher {
    public $ctx = null;
    public function current_context() { return $this->ctx; }
    public function primary_data() { return array('median_diameter_mm' => 6.39); }
}
class LDN_Plugin {
    public static $self;
    public $price;
    public $size;
    public $render;
    public static function instance() { return self::$self; }
    public function dispatcher() { return $this->price; }
    public function size_dispatcher() { return $this->size; }
    public function renderer() { return $this->render; }
    public function data_fetcher() { return null; }
    public function config() { return null; }
}

require_once __DIR__ . '/../includes/class-ldn-query-signals.php';

$checks = 0;
$fails = 0;
function check($cond, $msg) {
    global $checks, $fails;
    ++$checks;
    if (!$cond) {
        ++$fails;
        fwrite(STDERR, "FAIL: $msg\n");
    }
}

$shape_ctx = new LDN_Page_Context('ringspo', 'shape', 'us', 'natural', '1', 'round');
$signals = new LDN_Query_Signals();

// --- 1. Only the expected hooks, and nothing that rewrites conditionals ------
// Would fail if: someone added a parse_query / pre_get_posts hook to clear
// is_home — which, per core's handle_404(), would 404 every LDN page once the
// posts query below is skipped.
$signals->register();
sort($GLOBALS['ldn_hooks']);
check(
    $GLOBALS['ldn_hooks'] === array('posts_pre_query', 'pre_get_document_title'),
    'registers only the posts-query and document-title corrections'
);
check(
    !in_array('parse_query', $GLOBALS['ldn_hooks'], true)
        && !in_array('pre_get_posts', $GLOBALS['ldn_hooks'], true),
    'does not hook the query parser (is_home must stay true for core 404 handling)'
);

// --- 2. The unused posts query is skipped on LDN routes ----------------------
foreach (array('price', 'size') as $route) {
    $q = new WP_Query(array('ldn_route' => $route));
    $result = $signals->skip_unused_posts_query(null, $q);
    check($result === array(), "posts query short-circuited on the {$route} route");
    check($q->max_num_pages === 1, "pagination flattened on the {$route} route");
    check($q->found_posts === 0, "found_posts zeroed on the {$route} route");
}

// --- 3. Everything else is left completely alone -----------------------------
// The site's own blog, archives and search must keep their posts.
$blog = new WP_Query(array());
check(
    $signals->skip_unused_posts_query(null, $blog) === null,
    'a non-LDN main query is untouched'
);
check(
    $blog->max_num_pages === 6 && $blog->found_posts === 57,
    'a non-LDN query keeps its pagination state'
);

// A widget / related-posts query on an LDN page must still run.
$secondary = new WP_Query(array('ldn_route' => 'price'), false);
check(
    $signals->skip_unused_posts_query(null, $secondary) === null,
    'a secondary query on an LDN page still runs'
);
check($secondary->max_num_pages === 6, 'a secondary query keeps its pagination state');

// The sitemap route streams XML and exits before the theme loads.
$sitemap = new WP_Query(array('ldn_route' => 'sitemap'));
check(
    $signals->skip_unused_posts_query(null, $sitemap) === null,
    'the sitemap route is not treated as a themed page'
);

// An earlier filter that already supplied posts must be respected, not clobbered.
$prefilled = new WP_Query(array());
check(
    $signals->skip_unused_posts_query(array('post'), $prefilled) === array('post'),
    'posts supplied by another filter are passed through'
);

// --- 4. The document title comes from the page, not the blog index -----------
LDN_Plugin::$self = new LDN_Plugin();
LDN_Plugin::$self->render = new LDN_Renderer();
LDN_Plugin::$self->price = new LDN_Dispatcher();
LDN_Plugin::$self->size = new LDN_Size_Dispatcher();

// No context on either dispatcher: this is not an LDN page, so WordPress keeps
// whatever title it built.
check(
    $signals->document_title('Ringspo') === 'Ringspo',
    'a non-LDN request keeps the WordPress title'
);

LDN_Plugin::$self->price->ctx = $shape_ctx;
check(
    $signals->document_title('Ringspo')
        === '1 Carat Round Natural Diamond Prices (US) - Ringspo',
    'a price page title comes from the renderer with the site name appended'
);
check(
    LDN_Query_Signals::resolved_document_title()
        === '1 Carat Round Natural Diamond Prices (US) - Ringspo',
    'resolved_document_title() matches the pre_get_document_title output'
);
check(
    strpos($signals->document_title('Ringspo'), 'Round') !== false,
    'the title describes the page rather than the site'
);

// The separator is the site's, not a hardcoded dash.
$GLOBALS['ldn_filter_overrides']['document_title_separator'] = function ($sep) { return '|'; };
check(
    $signals->document_title('Ringspo')
        === '1 Carat Round Natural Diamond Prices (US) | Ringspo',
    'the site document_title_separator is honoured'
);
unset($GLOBALS['ldn_filter_overrides']['document_title_separator']);

// A title that already carries the brand must not repeat it.
$GLOBALS['ldn_site_name'] = 'Diamond Prices';
check(
    $signals->document_title('x') === '1 Carat Round Natural Diamond Prices (US)',
    'the site name is not appended twice when the title already contains it'
);
$GLOBALS['ldn_site_name'] = 'Ringspo';

// --- 5. Size pages use the size renderer's SEO title ------------------------
LDN_Plugin::$self->price->ctx = null;
LDN_Plugin::$self->size->ctx = new LDN_Page_Context('ringspo', 'size-individual', 'us', null, '1', 'round', 'size');
check(
    $signals->document_title('Ringspo') === '1 Carat Round Diamond Size - Ringspo',
    'a size page title comes from the size renderer'
);

// --- 6. The escape hatch actually disables each aspect ----------------------
$GLOBALS['ldn_filter_overrides']['ldn_normalise_query'] = function ($on, $aspect) {
    return $aspect === 'document_title' ? false : $on;
};
check(
    $signals->document_title('Ringspo') === 'Ringspo',
    'ldn_normalise_query can disable the title correction alone'
);
$off = new WP_Query(array('ldn_route' => 'price'));
check(
    $signals->skip_unused_posts_query(null, $off) === array(),
    'disabling the title aspect leaves the posts-query correction active'
);
$GLOBALS['ldn_filter_overrides']['ldn_normalise_query'] = function ($on, $aspect) { return false; };
$all_off = new WP_Query(array('ldn_route' => 'price'));
check(
    $signals->skip_unused_posts_query(null, $all_off) === null
        && $all_off->max_num_pages === 6,
    'ldn_normalise_query returning false restores stock WordPress behaviour'
);
unset($GLOBALS['ldn_filter_overrides']['ldn_normalise_query']);

if ($fails === 0) {
    echo "OK: {$checks} checks passed\n";
    exit(0);
}
fwrite(STDERR, "FAILED: {$fails}/{$checks} checks failed\n");
exit(1);

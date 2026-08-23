<?php
/**
 * Body schema cleanup on LDN routes.
 *
 * Test intent: LDN HTML routes suppress GeneratePress Blog microdata and swap
 * the blog body class without clearing is_home().
 *
 * Would fail if: generate_show_schema_markup stayed true on a price route, or the
 * blog class remained on the body.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-body-schema.php
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/../includes/class-ldn-body-schema.php';

$checks = 0;
function check($cond, $msg) {
    global $checks;
    ++$checks;
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}

$GLOBALS['wp_query_vars'] = array('ldn_route' => 'price');
if (!function_exists('get_query_var')) {
    function get_query_var($key) {
        return isset($GLOBALS['wp_query_vars'][$key]) ? $GLOBALS['wp_query_vars'][$key] : '';
    }
}

$module = new LDN_Body_Schema();
check($module->suppress_theme_blog_markup(true, null) === false, 'price route suppresses GP Blog markup');
check($module->body_class(array('blog', 'home', 'logged-in')) === array('logged-in', 'ldn-page'),
    'price route swaps blog/home for ldn-page');

$GLOBALS['wp_query_vars'] = array('ldn_route' => 'sitemap');
check($module->suppress_theme_blog_markup(true, null) === true, 'sitemap route leaves GP markup alone');

echo "OK: {$checks} checks passed\n";

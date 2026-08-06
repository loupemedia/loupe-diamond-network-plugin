<?php
/**
 * Size router rewrite-rule ordering.
 *
 * Test intent: /diamond-size/compare/ must resolve to compare-tool, not shape=compare.
 * Would fail if: level-2 shape hub rule is registered before the compare-tool rule.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-size-router.php
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');

if (!class_exists('LDN_Config')) {
    class LDN_Config {
        /** @return array<string, string> */
        public function get_url_structure($site_id) {
            return array(
                'size_level_1'           => '/diamond-size',
                'size_level_2'           => '/diamond-size/{shape}',
                'size_level_3'           => '/diamond-size/{shape}/{carat}',
                'size_level_compare'     => '/diamond-size/compare/{compare}',
                'size_level_methodology' => '/diamond-size/methodology',
            );
        }
    }
}

if (!class_exists('LDN_Artefacts')) {
    class LDN_Artefacts {
        public function site_entitled_to_artefact($site_id, $artefact_id) {
            return true;
        }
    }
}

require_once __DIR__ . '/../includes/class-ldn-size-router.php';

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$router = new LDN_Size_Router('ringspo', null, new LDN_Config(), new LDN_Artefacts());
$rules = $router->build_rewrite_rules();
$keys = array_keys($rules);

$compare_tool_regex = $router->pattern_to_regex('/diamond-size/compare');
$shape_hub_regex = $router->pattern_to_regex('/diamond-size/{shape}');
$compare_pair_regex = $router->pattern_to_regex('/diamond-size/compare/{compare}');

check(isset($rules[$compare_tool_regex]), 'compare-tool rewrite rule exists');
check(
    $rules[$compare_tool_regex] === 'index.php?ldn_route=size&ldn_size_level=compare-tool',
    'compare-tool query maps to compare-tool level'
);

$tool_pos = array_search($compare_tool_regex, $keys, true);
$shape_pos = array_search($shape_hub_regex, $keys, true);
check($tool_pos !== false && $shape_pos !== false, 'compare-tool and shape hub rules are present');
check($tool_pos < $shape_pos, 'compare-tool rule is registered before shape hub rule');

function ldn_rewrite_path_matches($regex, $path) {
    $normalised = trim((string) $path, '/');
    return (bool) preg_match('#' . $regex . '#', $normalised);
}

$tool_path = 'diamond-size/compare';
check(
    ldn_rewrite_path_matches($compare_tool_regex, $tool_path),
    'compare-tool regex matches /diamond-size/compare/'
);
check(
    !ldn_rewrite_path_matches($compare_pair_regex, $tool_path),
    'compare pair regex does not steal the tool hub URL'
);

$pair_path = 'diamond-size/compare/round-1-carat-vs-oval-1-carat';
check(
    ldn_rewrite_path_matches($compare_pair_regex, $pair_path),
    'compare pair regex matches curated comparison URLs'
);

exit($GLOBALS['__fails'] > 0 ? 1 : 0);

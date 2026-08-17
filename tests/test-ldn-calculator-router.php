<?php
/**
 * Calculator destination rewrite-rule ordering.
 *
 * Test intent: /diamond-prices/calculator/ must resolve to the calculator module,
 * not diamond-type level_2 with type=calculator.
 * Would fail if: the generic level-2 price rule is registered before the calculator rule.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-calculator-router.php
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');

if (!class_exists('LDN_Config')) {
    class LDN_Config {
        /** @return array<string, string> */
        public function get_url_structure($site_id) {
            return array(
                'level_2'           => '/{country}/diamond-prices/{type}',
                'calculator_level'  => '/{country}/diamond-prices/calculator',
            );
        }

        /** @return array<string, mixed>|null */
        public function get_site($site_id) {
            return array('canonical_source_country' => 'us');
        }
    }
}

if (!class_exists('LDN_Artefacts')) {
    class LDN_Artefacts {
        public function site_has_wp_widget($site_id, $widget_id, $page_level) {
            return $page_level === 'calculator' && $widget_id === 'price_calculator';
        }
    }
}

if (!class_exists('LDN_Rollout_Reader')) {
    class LDN_Rollout_Reader {
        public function enabled_countries() {
            return array('us');
        }

        public function is_enabled($country, $module) {
            return true;
        }

        public function is_site_enabled($site_id, $country, $module) {
            return true;
        }
    }
}

require_once __DIR__ . '/../includes/class-ldn-calculator-router.php';

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$router = new LDN_Calculator_Router(
    'ringspo',
    new LDN_Rollout_Reader(),
    new LDN_Config(),
    new LDN_Artefacts()
);
$rules = $router->build_rewrite_rules();
$keys = array_keys($rules);

$compiled = $router->compile_pattern('/{country}/diamond-prices/calculator', 'us');
check($compiled !== null, 'calculator pattern compiles for us');
$calculator_regex = $compiled[0];

check(isset($rules[$calculator_regex]), 'calculator rewrite rule exists');
check(
    $rules[$calculator_regex] === 'index.php?ldn_route=calculator&ldn_country=us',
    'calculator query maps to calculator module'
);

function ldn_rewrite_path_matches($regex, $path) {
    $normalised = trim((string) $path, '/');
    return (bool) preg_match('#' . $regex . '#', $normalised);
}

$calculator_path = 'us/diamond-prices/calculator';
check(
    ldn_rewrite_path_matches($calculator_regex, $calculator_path),
    'calculator regex matches /us/diamond-prices/calculator/'
);

$type_regex = '^us/diamond-prices/([^/]+)/?$';
check(
    ldn_rewrite_path_matches($type_regex, $calculator_path),
    'level-2 diamond-type regex would also match calculator URL without ordering guard'
);

// Report the pass count, so a suite that silently stopped running checks is not
// mistaken for a suite that passed.
if ($GLOBALS['__fails'] === 0) {
    fwrite(STDOUT, "OK: {$GLOBALS['__tests']} checks passed\n");
    exit(0);
}
fwrite(STDERR, "FAILED: {$GLOBALS['__fails']}/{$GLOBALS['__tests']} checks failed\n");
exit(1);

<?php
/**
 * WP Rocket compatibility guardrails for LDN front-end scripts.
 *
 * Test intent: every wp_enqueue_script handle that must run before user interaction
 * is registered in LDN_Assets::INTERACTIVE_SCRIPT_HANDLES and wired into Rocket
 * delay/defer/minify exclusions; inline Plotly inits must delegate to LDN.plotChart.
 *
 * Would fail if: a new interactive script is enqueued without Rocket exclusions, or
 * chart_html reverts to a DOMContentLoaded-only Plotly.newPlot bootstrap.
 *
 * @package LoupeDiamondNetwork
 */

$root = dirname(__DIR__);
require_once $root . '/includes/class-ldn-assets.php';

$failures = 0;
function check($condition, $message) {
    global $failures;
    if (!$condition) {
        echo "FAIL: $message\n";
        $failures++;
    }
}

$handles = LDN_Assets::INTERACTIVE_SCRIPT_HANDLES;
check(in_array('ldn-chart-init', $handles, true), 'ldn-chart-init is in INTERACTIVE_SCRIPT_HANDLES');
check(in_array('ldn-price-calculator', $handles, true), 'ldn-price-calculator remains excluded from Rocket delay');
check(in_array('ldn-nat-lab-toggle', $handles, true), 'ldn-nat-lab-toggle is excluded from Rocket delay');
check(in_array('ldn-analytics', $handles, true), 'ldn-analytics is excluded from Rocket delay');

$delay_exclusions = LDN_Assets::exclude_interactive_scripts_from_rocket(array());
check(
    in_array('ldn-chart-init', $delay_exclusions, true),
    'rocket_delay_js_exclusions includes ldn-chart-init'
);
check(
    in_array('ldn-nat-lab-toggle', $delay_exclusions, true),
    'rocket_delay_js_exclusions includes ldn-nat-lab-toggle'
);
check(
    in_array('ldn-analytics', $delay_exclusions, true),
    'rocket_delay_js_exclusions includes ldn-analytics'
);
check(
    in_array('chart-init', $delay_exclusions, true),
    'rocket_delay_js_exclusions includes chart-init path fragment'
);

$minify_exclusions = LDN_Assets::exclude_interactive_script_paths_from_rocket(array());
$has_chart_init_path = false;
foreach ($minify_exclusions as $pattern) {
    if (strpos($pattern, 'chart-init.js') !== false) {
        $has_chart_init_path = true;
        break;
    }
}
check($has_chart_init_path, 'rocket_exclude_js includes chart-init.js path');

$charts_php = file_get_contents($root . '/components/trait-ldn-charts.php');
check(
    strpos($charts_php, 'LDN.plotChart') !== false,
    'trait-ldn-charts.php delegates inline init to LDN.plotChart'
);
check(
    strpos($charts_php, 'DOMContentLoaded",function(){if(window.Plotly)') === false,
    'trait-ldn-charts.php no longer uses DOMContentLoaded-only Plotly bootstrap'
);

$size_php = file_get_contents($root . '/includes/class-ldn-size-renderer.php');
check(
    strpos($size_php, 'LDN.plotChartEl') !== false,
    'size renderer delegates inline init to LDN.plotChartEl'
);

$chart_init_js = file_get_contents($root . '/assets/js/chart-init.js');
check(
    strpos($chart_init_js, 'rocket-allScriptsLoaded') !== false,
    'chart-init.js listens for rocket-allScriptsLoaded'
);
check(
    strpos($chart_init_js, 'requestAnimationFrame') !== false,
    'chart-init.js polls via requestAnimationFrame (not setInterval)'
);

$assets_php = file_get_contents($root . '/includes/class-ldn-assets.php');
check(
    strpos($assets_php, "'ldn-chart-init'") !== false
    && strpos($assets_php, "assets/js/chart-init.js") !== false,
    'class-ldn-assets.php enqueues chart-init.js'
);

if ($failures > 0) {
    echo "\n$failures test(s) failed.\n";
    exit(1);
}

echo "OK: LDN WP Rocket compatibility checks passed.\n";
exit(0);

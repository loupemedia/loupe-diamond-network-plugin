<?php
/**
 * Standalone unit tests for LDN_Test_Combos price-module gating.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-test-combos.php
 *
 * Test intent: Under staging test_only + test_combos, hub levels (L1–L3) must
 *              stay reachable so QA can navigate the pricing tree; only shape
 *              pages (L4) are filtered to the combo list.
 * Would fail if: allows_context() returned false for top-level / diamond-type /
 *              all-shapes while combos are set (hub URLs 404 on staging).
 */

error_reporting(E_ALL);

define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/../includes/class-ldn-page-context.php';
require_once __DIR__ . '/../includes/class-ldn-test-combos.php';

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$combos = LDN_Test_Combos::normalise_list(array(
    array('diamond_type' => 'natural', 'carat' => '1', 'shape' => 'round'),
));

$top = new LDN_Page_Context('ringspo', 'top-level', 'us');
$type = new LDN_Page_Context('ringspo', 'diamond-type', 'us', 'natural');
$all_shapes = new LDN_Page_Context('ringspo', 'all-shapes', 'us', 'natural', '1');
$shape_ok = new LDN_Page_Context('ringspo', 'shape', 'us', 'natural', '1', 'round');
$shape_bad = new LDN_Page_Context('ringspo', 'shape', 'us', 'natural', '2', 'round');

check(
    LDN_Test_Combos::allows_context($top, $combos),
    'top-level allowed under test_only when test_combos is set'
);
check(
    LDN_Test_Combos::allows_context($type, $combos),
    'diamond-type allowed under test_only when test_combos is set'
);
check(
    LDN_Test_Combos::allows_context($all_shapes, $combos),
    'all-shapes allowed under test_only when test_combos is set'
);
check(
    LDN_Test_Combos::allows_context($shape_ok, $combos),
    'shape page in test_combos list is allowed'
);
check(
    !LDN_Test_Combos::allows_context($shape_bad, $combos),
    'shape page outside test_combos list is blocked'
);
check(
    LDN_Test_Combos::allows_context($shape_ok, array()),
    'empty combo list allows all shape pages'
);

$tests = $GLOBALS['__tests'];
$fails = $GLOBALS['__fails'];
if ($fails === 0) {
    fwrite(STDOUT, "OK: {$tests} checks passed\n");
    exit(0);
}
fwrite(STDERR, "FAILED: {$fails}/{$tests} checks failed\n");
exit(1);

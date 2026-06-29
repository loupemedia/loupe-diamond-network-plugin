<?php
/**
 * Size staging test-combo gating.
 *
 * Test intent: size test_combos match on shape+carat only (no diamond_type).
 * Would fail if: size pages required diamond_type like pricing shape pages.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/');
}

require_once dirname(__DIR__) . '/includes/class-ldn-page-context.php';
require_once dirname(__DIR__) . '/includes/class-ldn-test-combos.php';

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$combos = array(
    array('diamond_type' => 'natural', 'carat' => '1', 'shape' => 'round'),
    array('diamond_type' => 'lab-grown', 'carat' => '2', 'shape' => 'round'),
    array('diamond_type' => 'natural', 'carat' => '1.5', 'shape' => 'oval'),
);

$round_one = new LDN_Page_Context('ringspo', 'size-individual', 'us', null, '1', 'round', 'size');
check(LDN_Test_Combos::allows_size_context($round_one, $combos), 'round 1ct individual allowed');

$oval_three = new LDN_Page_Context('ringspo', 'size-individual', 'us', null, '3', 'oval', 'size');
check(!LDN_Test_Combos::allows_size_context($oval_three, $combos), 'oval 3ct blocked when not in combos');

$oval_hub = new LDN_Page_Context('ringspo', 'size-shape-hub', 'us', null, null, 'oval', 'size');
check(LDN_Test_Combos::allows_size_context($oval_hub, $combos), 'oval shape hub allowed when oval in combos');

$mega = new LDN_Page_Context('ringspo', 'size-mega-hub', 'us', null, null, null, 'size');
check(!LDN_Test_Combos::allows_size_context($mega, $combos), 'mega hub blocked under test_only');

$price_shape = new LDN_Page_Context('ringspo', 'shape', 'us', 'natural', '1', 'round', 'price');
check(!LDN_Test_Combos::allows_size_context($price_shape, $combos), 'price context not allowed by size gate');

exit($GLOBALS['__fails'] > 0 ? 1 : 0);

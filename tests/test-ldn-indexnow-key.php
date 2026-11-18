<?php
/**
 * Standalone tests for LDN_IndexNow_Key.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-indexnow-key.php
 *
 * Test intent: LDN serves the configured IndexNow verification token at
 * {key}.txt from the config bundle for the site's domain. Would fail if:
 * key_body() omitted the trailing newline, or returned content when unconfigured.
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/../includes/class-ldn-indexnow-key.php';

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

function with_key(LDN_IndexNow_Key $service, $key) {
    $ref = new ReflectionClass($service);
    $prop = $ref->getProperty('key');
    $prop->setAccessible(true);
    $prop->setValue($service, (string) $key);
}

$config = new class {
    public function indexnow_key_for_site($site_id) {
        if ($site_id === 'ringspo') {
            return 'placeholder';
        }
        return null;
    }
};

$indexnow = new LDN_IndexNow_Key('ringspo', $config);
with_key($indexnow, 'placeholder');
check($indexnow->key_body() === "placeholder\n", 'body is key plus newline');

$missing = new LDN_IndexNow_Key('unknown', $config);
check($missing->key_body() === '', 'unconfigured site has empty key body');

$total = $GLOBALS['__tests'];
$fails = $GLOBALS['__fails'];
if ($fails === 0) {
    fwrite(STDOUT, "OK: {$total} assertions passed\n");
    exit(0);
}
fwrite(STDERR, "{$fails}/{$total} assertions FAILED\n");
exit(1);

<?php
/**
 * S3 key resolution for ring-guide artefacts.
 *
 * Test intent: a shape-page fetch of `market_index_bins_json` must resolve to
 * `{site_id}-market-index-bins.json` under that page's carat/shape prefix.
 *
 * Would fail if: the artefact was omitted from FIXED_BASENAMES (basename null,
 * ring guides fall back to the OG PNG) or `{site_id}` was left unsubstituted
 * (`{site_id}-market-index-bins.json` 404s).
 *
 * Run: php loupe-diamond-network/tests/test-ldn-s3-key-resolver.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABSPATH', __DIR__ . '/');
define('LDN_PLUGIN_DIR', dirname(__DIR__) . '/');
define('LDN_PLUGIN_FILE', LDN_PLUGIN_DIR . 'loupe-diamond-network.php');
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('LDN_VERSION')) {
    define('LDN_VERSION', 'test');
}

require_once dirname(__DIR__, 2) . '/scripts/ldn_preview/preview-shims.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-page-context.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-config.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-s3-key-resolver.php';

$checks = 0;
function check($cond, $msg) {
    global $checks;
    ++$checks;
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}

$config = LDN_Config::instance();
$resolver = new LDN_S3_Key_Resolver($config);
$ctx = new LDN_Page_Context(
    'ringspo',
    'shape',
    'us',
    'natural',
    '4',
    'cushion',
    'price'
);

$bins_name = $resolver->basename_for('market_index_bins_json', 'ringspo');
check(
    $bins_name === 'ringspo-market-index-bins.json',
    'bins basename substitutes the site id'
);
check(
    strpos((string) $bins_name, '{site_id}') === false,
    'bins basename does not leave a {site_id} token'
);

$key = $resolver->resolve_s3_key('market_index_bins_json', $ctx);
check(is_string($key) && $key !== '', '4 ct cushion bins key resolves');
check(
    strpos($key, '4-carat/') !== false,
    '4 ct cushion bins key is under the 4-carat prefix'
);
check(
    strpos($key, '8-carat/') === false,
    '4 ct cushion bins key is not the 8-carat prefix'
);
check(
    substr($key, -strlen('ringspo-market-index-bins.json')) === 'ringspo-market-index-bins.json',
    '4 ct cushion bins key ends with the catalogue filename'
);

$og = $resolver->basename_for('og_preview_png', 'ringspo');
check($og === 'og-preview.png', 'fixed basenames without tokens still resolve');

fwrite(STDOUT, "OK {$checks} checks\n");

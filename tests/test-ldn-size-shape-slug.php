<?php
/**
 * Size URL shape slug round trip.
 *
 * Test intent: every shape-hub URL the size module publishes must parse back to
 * the canonical shape name, because that name is the key the size-checker
 * manifest, the test-combos allowlist and the S3 resolver are all looked up by.
 *
 * Would fail if: the size dispatcher resolved `ldn_shape` with slug_to_shape(),
 * whose per-site map holds Ringspo's price slug `cushion`. The published size
 * URL is `/diamond-size/cushion-cut/` (the S3 folder slug), so `cushion-cut`
 * missed the map and fell through to the dash-to-space fallback, yielding the
 * non-shape string `cushion cut`. That still fetched from S3 (the slug maps back
 * onto itself) so the page looked fine, but it matched no manifest key, and the
 * scale explorer bailed out with an empty shape dropdown and an inert slider.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-size-shape-slug.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABSPATH', __DIR__ . '/');
define('LDN_PLUGIN_DIR', dirname(__DIR__) . '/');
define('LDN_PLUGIN_FILE', LDN_PLUGIN_DIR . 'loupe-diamond-network.php');
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

require_once dirname(__DIR__, 2) . '/scripts/ldn_preview/preview-shims.php';

require_once LDN_PLUGIN_DIR . 'includes/class-ldn-config.php';

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$config = LDN_Config::instance();

// The canonical shape names the network publishes, straight from the bundle, so
// a shape added to config without an S3 slug entry fails here rather than on a
// live page.
$structures = $config->get_url_structures();
$shapes = isset($structures['shape_variations']) && is_array($structures['shape_variations'])
    ? array_keys($structures['shape_variations'])
    : array();
check(count($shapes) >= 10, 'config bundle lists the network shapes');

foreach ($shapes as $shape) {
    $url_slug = $config->shape_to_s3_slug($shape);
    $resolved = $config->size_slug_to_shape($url_slug, 'ringspo');
    check(
        $resolved === $shape,
        "size URL slug '{$url_slug}' resolves back to '{$shape}' (got '" . var_export($resolved, true) . "')"
    );
}

// The four shapes published under a '-cut' suffix are the regression surface:
// their URL slug differs from both the canonical name and Ringspo's price slug.
check(
    $config->size_slug_to_shape('cushion-cut', 'ringspo') === 'cushion',
    'cushion-cut resolves to cushion'
);
check(
    $config->size_slug_to_shape('asscher-cut', 'ringspo') === 'asscher',
    'asscher-cut resolves to asscher'
);
check(
    $config->size_slug_to_shape('emerald-cut', 'ringspo') === 'emerald',
    'emerald-cut resolves to emerald'
);
check(
    $config->size_slug_to_shape('princess-cut', 'ringspo') === 'princess',
    'princess-cut resolves to princess'
);

// Shapes with no suffix must keep going through the per-site map.
check(
    $config->size_slug_to_shape('round', 'ringspo') === 'round',
    'round resolves to round'
);
check(
    $config->size_slug_to_shape('oval', 'ringspo') === 'oval',
    'oval resolves to oval'
);
check($config->size_slug_to_shape('', 'ringspo') === null, 'an empty slug resolves to null');

// Documents why the size module needs its own inverse: the price-page resolver
// does not know about the S3 suffix and degrades the slug to a non-shape string.
check(
    $config->slug_to_shape('cushion-cut', 'ringspo') === 'cushion cut',
    'the price slug resolver still degrades cushion-cut (the bug this guards)'
);

exit($GLOBALS['__fails'] > 0 ? 1 : 0);

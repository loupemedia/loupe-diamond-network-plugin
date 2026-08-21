<?php
/**
 * Price URL shape slug round trip.
 *
 * Test intent: a public price-page shape slug must resolve to the canonical
 * KB shape (S3 folder / page context), and outbound price links must emit
 * that site's public slug so they do not 404.
 *
 * Would fail if: the dispatcher passed `round-brilliant` through unchanged
 * (S3 looks under round-brilliant/), slug_to_shape still read the missing
 * `shape_slug_mappings` key, or build_price_page_url sanitized the canonical
 * name to `/round/`.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-price-shape-slug.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABSPATH', __DIR__ . '/');
define('LDN_PLUGIN_DIR', dirname(__DIR__) . '/');
define('LDN_PLUGIN_FILE', LDN_PLUGIN_DIR . 'loupe-diamond-network.php');
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

require_once dirname(__DIR__, 2) . '/scripts/ldn_preview/preview-shims.php';

require_once LDN_PLUGIN_DIR . 'includes/class-ldn-page-context.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-config.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-dispatcher.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-renderer.php';

if (!class_exists('LDN_Data_Fetcher')) {
    class LDN_Data_Fetcher {
        public function fetch_artefact($artefact_id, $ctx) {
            return null;
        }
    }
}

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
$fetcher = new LDN_Data_Fetcher();
$dpg = new LDN_Dispatcher('diamondpriceguru', $config, $fetcher);
$advisors = new LDN_Dispatcher('diamondadvisors', $config, $fetcher);
$ringspo = new LDN_Dispatcher('ringspo', $config, $fetcher);

check(
    $config->slug_to_shape('round-brilliant', 'diamondpriceguru') === 'round',
    'DPG public slug round-brilliant resolves to canonical round'
);
check(
    $config->slug_to_shape('princess-cut', 'diamondpriceguru') === 'princess',
    'DPG public slug princess-cut resolves to canonical princess'
);
check(
    $config->slug_to_shape('round-cut', 'diamondadvisors') === 'round',
    'Advisors public slug round-cut resolves to canonical round'
);
check(
    $config->slug_to_shape('round', 'ringspo') === 'round',
    'Ringspo bare slug round still resolves to round'
);
check(
    $config->slug_to_shape('cushion-cut', 'ringspo') === 'cushion cut',
    'price resolver still degrades Ringspo cushion-cut (size module owns that inverse)'
);

$ctx = $dpg->build_context(array(
    'ldn_country' => 'us',
    'ldn_type'    => 'mined',
    'ldn_carat'   => '1-ct',
    'ldn_shape'   => 'round-brilliant',
));
check($ctx instanceof LDN_Page_Context, 'DPG shape request builds a page context');
check($ctx !== null && $ctx->shape === 'round', 'DPG context.shape is canonical round, not the URL slug');
check($ctx !== null && $ctx->diamond_type === 'natural', 'DPG mined still canonicalises to natural');
check($ctx !== null && $ctx->carat === '1', 'DPG 1-ct still reduces to 1');

$advisor_ctx = $advisors->build_context(array(
    'ldn_country' => 'us',
    'ldn_type'    => 'natural',
    'ldn_carat'   => '1-carat',
    'ldn_shape'   => 'round-cut',
));
check(
    $advisor_ctx !== null && $advisor_ctx->shape === 'round',
    'Advisors context.shape is canonical round from round-cut'
);

$ringspo_ctx = $ringspo->build_context(array(
    'ldn_country' => 'us',
    'ldn_type'    => 'natural',
    'ldn_carat'   => '1-carat',
    'ldn_shape'   => 'round',
));
check(
    $ringspo_ctx !== null && $ringspo_ctx->shape === 'round',
    'Ringspo shape context is unchanged'
);

$jp_ctx = $ringspo->build_context(array(
    'ldn_country' => 'jp',
    'ldn_type'    => 'tennen',
    'ldn_carat'   => '1-karatto',
    'ldn_shape'   => 'cushion',
));
check($jp_ctx instanceof LDN_Page_Context, 'JP localised request builds a page context');
check(
    $jp_ctx !== null && $jp_ctx->diamond_type === 'natural',
    'JP tennen canonicalises to natural'
);
check(
    $jp_ctx !== null && $jp_ctx->carat === '1',
    'JP 1-karatto reduces to 1'
);
check(
    $jp_ctx !== null && $jp_ctx->shape === 'cushion',
    'JP inherits the English shape slug cushion'
);

$de_ctx = $ringspo->build_context(array(
    'ldn_country' => 'de',
    'ldn_type'    => 'natuerlich',
    'ldn_carat'   => '1-karat',
    'ldn_shape'   => 'kissenschliff',
));
check(
    $de_ctx !== null && $de_ctx->shape === 'cushion',
    'DE kissenschliff resolves to canonical cushion'
);
check(
    $de_ctx !== null && $de_ctx->diamond_type === 'natural' && $de_ctx->carat === '1',
    'DE natuerlich / 1-karat canonicalise'
);

$renderer = new LDN_Renderer($fetcher, $config);
$canonical_ctx = new LDN_Page_Context('diamondpriceguru', 'shape', 'us', 'natural', '1', 'round');
$dpg_url = $renderer->build_price_page_url($canonical_ctx, 'shape');
check(
    strpos((string) $dpg_url, '/round-brilliant/') !== false,
    'DPG outbound shape URL uses the public slug round-brilliant'
);
check(
    strpos((string) $dpg_url, '/round/') === false,
    'DPG outbound shape URL does not emit the canonical S3 slug /round/'
);
check(
    strpos((string) $dpg_url, '/mined/') !== false && strpos((string) $dpg_url, '/1-ct/') !== false,
    'DPG outbound shape URL still uses mined and 1-ct'
);

$ringspo_url = $renderer->build_price_page_url(
    new LDN_Page_Context('ringspo', 'shape', 'us', 'natural', '1', 'round'),
    'shape'
);
check(
    strpos((string) $ringspo_url, '/round/') !== false,
    'Ringspo outbound shape URL stays /round/'
);

$jp_url = $renderer->build_price_page_url(
    new LDN_Page_Context('ringspo', 'shape', 'jp', 'natural', '1', 'cushion'),
    'shape'
);
check(
    strpos((string) $jp_url, '/jp/daiyamondo-kakaku/tennen/1-karatto/cushion/') !== false,
    'JP outbound shape URL uses the localised path C4.5 registers'
);
check(
    strpos((string) $jp_url, '/diamond-prices/') === false,
    'JP outbound shape URL no longer emits the English diamond-prices scheme'
);

exit($GLOBALS['__fails'] > 0 ? 1 : 0);

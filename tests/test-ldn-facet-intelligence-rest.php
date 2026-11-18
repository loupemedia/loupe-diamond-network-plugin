<?php
/**
 * Facet Intelligence REST proxy (PRD-009 Epic 5.4).
 *
 * Test intent: tenant resolution and panel gating stay aligned with
 * config/facet_intelligence_tenants.yaml; unconfigured FI env returns false;
 * the public POST gate requires a page-issued nonce and an IP rate limit;
 * the browser cannot pick a scoring backend.
 *
 * Would fail if: ringspo + us did not resolve to ringspo-us, panel_enabled
 * returned true without consumer advisory and FI credentials, permission_callback
 * returned true with no nonce, or scoring_backend survived sanitize_upstream_body.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-facet-intelligence-rest.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABSPATH', __DIR__ . '/');
define('LDN_PLUGIN_DIR', dirname(__DIR__) . '/');
define('LDN_PLUGIN_URL', 'https://example.test/wp-content/plugins/loupe-diamond-network/');
define('LDN_VERSION', '0.0.0-test');

require_once dirname(__DIR__, 2) . '/scripts/ldn_preview/preview-shims.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-facet-intelligence-rest.php';

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

check(
    LDN_Facet_Intelligence_Rest::resolve_tenant_id('ringspo', 'us') === 'ringspo-us',
    'ringspo us maps to ringspo-us tenant'
);

try {
    LDN_Facet_Intelligence_Rest::resolve_tenant_id('ringspo', 'uk');
    check(false, 'unknown country should throw');
} catch (InvalidArgumentException $exception) {
    check(true, 'unknown country raises InvalidArgumentException');
}

putenv('FACET_INTELLIGENCE_BASE_URL=');
putenv('FACET_INTELLIGENCE_API_KEY=');
check(
    LDN_Facet_Intelligence_Rest::is_configured() === false,
    'panel is unconfigured when env vars are absent'
);

putenv('FACET_INTELLIGENCE_BASE_URL=https://fi.example.test');
putenv('FACET_INTELLIGENCE_API_KEY=test-key');
check(
    LDN_Facet_Intelligence_Rest::is_configured() === true,
    'panel is configured when base URL and API key are set'
);

final class LDN_Config_Fi_Test {
    public function consumer_advisory_enabled($site_id, $country_code) {
        return $site_id === 'ringspo' && $country_code === 'us';
    }
}

final class LDN_Artefacts_Fi_Test {
    public function site_has_wp_widget($site_id, $widget_id, $page_level = 'shape') {
        return $widget_id === 'individual_listings' && $page_level === 'calculator';
    }
}

final class LDN_Plugin_Fi_Test {
    /** @var LDN_Config_Fi_Test */
    private $config;

    /** @var LDN_Artefacts_Fi_Test */
    private $artefacts;

    public function __construct() {
        $this->config = new LDN_Config_Fi_Test();
        $this->artefacts = new LDN_Artefacts_Fi_Test();
    }

    public function config() {
        return $this->config;
    }

    public function artefacts() {
        return $this->artefacts;
    }

    public function feature_enabled($flag, $country_code = null) {
        return $flag === 'show_alternatives';
    }
}

$enabled_plugin = new LDN_Plugin_Fi_Test();
check(
    LDN_Facet_Intelligence_Rest::panel_enabled($enabled_plugin, 'ringspo', 'us') === true,
    'panel enabled when advisory live, FI configured and show_alternatives flag is on'
);

putenv('FACET_INTELLIGENCE_API_KEY=');
check(
    LDN_Facet_Intelligence_Rest::panel_enabled($enabled_plugin, 'ringspo', 'us') === false,
    'panel disabled when FI is not configured even if flags are on'
);

putenv('FACET_INTELLIGENCE_BASE_URL=https://fi.example.test');
putenv('FACET_INTELLIGENCE_API_KEY=test-key');

final class LDN_Fi_Rest_Request {
    /** @var array<string, string> */
    private $headers;

    public function __construct($headers = array()) {
        $this->headers = $headers;
    }

    public function get_header($name) {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp((string) $key, (string) $name) === 0) {
                return $value;
            }
        }
        return '';
    }
}

$valid_nonce = wp_create_nonce(LDN_Facet_Intelligence_Rest::NONCE_ACTION);
$valid_request = new LDN_Fi_Rest_Request(array('X-WP-Nonce' => $valid_nonce));

$_SERVER['REMOTE_ADDR'] = sprintf('198.51.%d.%d', random_int(1, 254), random_int(1, 254));
$allowed = LDN_Facet_Intelligence_Rest::permission_callback($valid_request);
check($allowed === true, 'configured request with a valid nonce is allowed');

$denied = LDN_Facet_Intelligence_Rest::permission_callback(new LDN_Fi_Rest_Request());
check(
    is_wp_error($denied) && $denied->get_error_message() === 'Missing or invalid Facet Intelligence nonce.',
    'configured request without a nonce is forbidden'
);

$bad = LDN_Facet_Intelligence_Rest::permission_callback(
    new LDN_Fi_Rest_Request(array('X-WP-Nonce' => 'not-a-real-nonce'))
);
check(
    is_wp_error($bad) && $bad->get_error_message() === 'Missing or invalid Facet Intelligence nonce.',
    'configured request with a bad nonce is forbidden'
);

$_SERVER['REMOTE_ADDR'] = sprintf('203.0.113.%d', random_int(1, 254));
$rate_request = new LDN_Fi_Rest_Request(array('X-WP-Nonce' => $valid_nonce));
$hit_limit = false;
for ($i = 0; $i < LDN_Facet_Intelligence_Rest::RATE_LIMIT_PER_MINUTE + 1; $i++) {
    $result = LDN_Facet_Intelligence_Rest::permission_callback($rate_request);
    if (is_wp_error($result) && $result->get_error_message() === 'Too many Facet Intelligence requests.') {
        $hit_limit = true;
        check($i === LDN_Facet_Intelligence_Rest::RATE_LIMIT_PER_MINUTE, 'rate limit trips after the per-minute ceiling');
        break;
    }
}
check($hit_limit, 'rate limit returns 429 after the per-minute ceiling');

$sanitized = LDN_Facet_Intelligence_Rest::sanitize_upstream_body(array(
    'buyer_intent' => 'good_value',
    'country_code' => 'us',
    'scoring_backend' => 'vanilla',
));
check(
    !isset($sanitized['scoring_backend']) && !isset($sanitized['country_code']),
    'upstream body drops scoring_backend and country_code'
);
check(
    $sanitized['buyer_intent'] === 'good_value',
    'upstream body keeps discover fields'
);

putenv('FACET_INTELLIGENCE_BASE_URL=');
putenv('FACET_INTELLIGENCE_API_KEY=');

echo "Ran {$GLOBALS['__tests']} checks, {$GLOBALS['__fails']} failed\n";
exit($GLOBALS['__fails'] > 0 ? 1 : 0);

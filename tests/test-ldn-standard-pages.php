<?php
/**
 * Legal page routes and packs (PRD-021 CP137).
 *
 * Test intent: legal pages are new LDN URLs, noindex, git-versioned, and a pack
 * does not emit a present-tense mechanism claim for a capability that is off.
 *
 * Would fail if: the US pack linked "manage cookie preferences" while
 * cookie_consent_manager is false, or routes still compiled /privacy-policy/.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-standard-pages.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABSPATH', __DIR__ . '/');
define('LDN_PLUGIN_DIR', dirname(__DIR__) . '/');
define('LDN_PLUGIN_FILE', LDN_PLUGIN_DIR . 'loupe-diamond-network.php');
define('LDN_INCLUDES_DIR', LDN_PLUGIN_DIR . 'includes/');
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('LDN_VERSION')) {
    define('LDN_VERSION', 'test');
}

require_once dirname(__DIR__, 2) . '/scripts/ldn_preview/preview-shims.php';

require_once LDN_PLUGIN_DIR . 'includes/class-ldn-config.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-page-context.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-standard-pages-router.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-standard-pages-dispatcher.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-standard-pages-renderer.php';
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-analytics.php';

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

class LDN_Fake_Legal_Rollout {
    public function enabled_countries() {
        return array('us', 'de', 'au');
    }
    public function is_enabled($country, $module) {
        return true;
    }
}

$config = LDN_Config::instance();
$router = new LDN_Standard_Pages_Router('ringspo', new LDN_Fake_Legal_Rollout(), $config);
$rules = $router->build_rewrite_rules();
$joined = implode("\n", array_keys($rules)) . "\n" . implode("\n", $rules);

check($rules !== array(), 'legal rewrite rules compile');
check(
    strpos($joined, 'ldn_legal_page=privacy') !== false
        && strpos($joined, 'ldn_legal_page=terms') !== false
        && strpos($joined, 'ldn_legal_page=cookie_notice') !== false
        && strpos($joined, 'ldn_legal_page=disclosure') !== false,
    'the catalogue paths are registered'
);
check(
    strpos($joined, 'privacy-policy') === false
        && strpos($joined, 'terms-conditions') === false,
    'old WordPress slugs are not registered'
);

$dispatcher = new LDN_Standard_Pages_Dispatcher('ringspo', $config);
$us_privacy = $dispatcher->build_context(array(
    'ldn_country' => 'us',
    'ldn_legal_page' => 'privacy',
));
check($us_privacy instanceof LDN_Page_Context, 'US privacy builds a context');

$us_impressum = $dispatcher->build_context(array(
    'ldn_country' => 'us',
    'ldn_legal_page' => 'impressum',
));
check($us_impressum === null, 'Impressum is not owed on the US');

$de_impressum = $dispatcher->build_context(array(
    'ldn_country' => 'de',
    'ldn_legal_page' => 'impressum',
));
check($de_impressum instanceof LDN_Page_Context, 'Impressum is owed on DE');

$renderer = new LDN_Standard_Pages_Renderer($config);
$cookies = $renderer->render($us_privacy, 'cookie_notice');
check($cookies !== '', 'the cookie page renders');
check(
    stripos($cookies, 'manage cookie preferences') === false
        && stripos($cookies, 'manage your cookie') === false,
    'the cookie pack does not offer a preference centre while that capability is off'
);
check(
    stripos($cookies, 'Google Tag Manager') === false
        && stripos($cookies, 'Google Analytics') === false,
    'cookie copy does not claim GTM while Ringspo has no container id'
);
check(
    strpos($cookies, 'noindex') === false,
    'robots is in the head helper, not duplicated into the body'
);

$privacy = $renderer->render($us_privacy, 'privacy');
check(
    strpos($privacy, 'Loupe Media Network Pty Ltd') !== false
        && strpos($privacy, 'Lightspace') !== false
        && strpos($privacy, 'privacy@ringspo.com') !== false,
    'the US privacy pack names the operating entity, business address and Ringspo mailbox'
);
check(
    strpos($privacy, '30-day') === false && strpos($privacy, '30 day') === false,
    'the pack does not promise a statutory DSAR clock while dsar_mailbox is off'
);
check(
    strpos($privacy, 'noindex') === false,
    'the body does not include a robots meta; that belongs in the head'
);

$head = $renderer->render_head($us_privacy, 'privacy');
check(
    strpos($head, 'noindex, follow') !== false
        && strpos($head, 'canonical') === false,
    'legal pages are noindex, follow, with no canonical tag'
);
check(
    strpos($head, 'Privacy policy - Ringspo') !== false
        && strpos($privacy, '<h1>Privacy policy</h1>') !== false
        && strpos($privacy, '<h1>Privacy policy - Ringspo</h1>') === false,
    'the document title includes the brand; the h1 does not'
);

check(
    $renderer->capability('cookie_consent_manager') === false
        && $renderer->jurisdiction_for('us') === 'us'
        && $renderer->jurisdiction_for('jp') === 'row',
    'capabilities and jurisdiction resolve from the bundled catalogue'
);

$pass = $GLOBALS['__tests'] - $GLOBALS['__fails'];
fwrite(STDOUT, "{$pass} of {$GLOBALS['__tests']} checks passed\n");
exit($GLOBALS['__fails'] === 0 ? 0 : 1);

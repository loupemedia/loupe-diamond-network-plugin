<?php
/**
 * Locale resolution and BCP-47 mapping.
 *
 * Test intent: calculator i18n reads locale from the site config bundle per
 * country code; BCP-47 tags map to WordPress locale slugs; Commonwealth
 * English locales without their own .mo reuse en_GB (colour, jewellery).
 *
 * Would fail if: get_locale() returned null for a configured Ringspo country,
 * fr-FR mapped to anything other than fr_FR, or en_AU kept US source strings
 * because WordPress does not fall back plugin catalogues to en_GB.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-locale.php
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
require_once LDN_PLUGIN_DIR . 'includes/class-ldn-locale.php';

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
    LDN_Locale::wp_locale_from_bcp47('fr-FR') === 'fr_FR',
    'BCP-47 fr-FR maps to WordPress fr_FR'
);
check(
    LDN_Locale::wp_locale_from_bcp47('en-GB') === 'en_GB',
    'BCP-47 en-GB maps to WordPress en_GB'
);

$config = LDN_Config::instance();
check(
    $config->get_locale('ringspo', 'us') === 'en-US',
    'ringspo US locale comes from the config bundle'
);
check(
    $config->get_locale('ringspo', 'fr') === 'fr-FR',
    'ringspo France locale comes from the config bundle'
);
check(
    $config->get_locale('ringspo', 'zz') === null,
    'unknown country returns null locale'
);
check(
    $config->consumer_features_live() === false,
    'consumer_features_live is false in the bundled launch_sequence'
);
check(
    $config->consumer_advisory_policy_enabled('ringspo', 'us') === true,
    'ringspo US is entitled to consumer advisory policy (us_only profile)'
);
check(
    $config->consumer_advisory_policy_enabled('ringspo', 'uk') === false,
    'ringspo UK is not entitled under us_only profile'
);
check(
    $config->consumer_advisory_enabled('ringspo', 'us') === false,
    'consumer advisory does not render until consumer_features_live is true'
);
check(
    is_file(LDN_PLUGIN_DIR . 'languages/loupe-diamond-network-en_GB.mo'),
    'en_GB compiled catalogue ships with the plugin'
);
$gb_mo = LDN_PLUGIN_DIR . 'languages/loupe-diamond-network-en_GB.mo';
check(
    LDN_Locale::textdomain_mofile('en_GB') === $gb_mo,
    'en_GB uses its compiled catalogue'
);
check(
    LDN_Locale::textdomain_mofile('en_AU') === $gb_mo,
    'en_AU plugin strings reuse the en_GB Commonwealth catalogue'
);
check(
    LDN_Locale::textdomain_mofile('en_CA') === $gb_mo,
    'en_CA plugin strings reuse the en_GB Commonwealth catalogue'
);
check(
    LDN_Locale::textdomain_mofile('en_US') === '',
    'en_US keeps plugin source strings (American English)'
);
check(
    LDN_Locale::textdomain_mofile('fr_FR') === '',
    'non-English locales do not fall back to en_GB'
);

// Shutdown restore is registered when a switch succeeds (price + calculator routes).
if (!function_exists('switch_to_locale')) {
    function switch_to_locale($locale) {
        return true;
    }
}
if (!function_exists('restore_previous_locale')) {
    function restore_previous_locale() {
        return true;
    }
}
if (!function_exists('determine_locale')) {
    function determine_locale() {
        return 'en_US';
    }
}
if (!function_exists('get_locale')) {
    function get_locale() {
        return 'en_US';
    }
}
LDN_Locale::switch_to_bcp47('fr-FR');
check(
    has_action('shutdown', array('LDN_Locale', 'restore')) !== false,
    'locale switch registers shutdown restore so templates do not restore manually'
);
LDN_Locale::restore();

if ($GLOBALS['__fails'] > 0) {
    fwrite(STDERR, "FAILED: {$GLOBALS['__fails']}/{$GLOBALS['__tests']} checks failed\n");
    exit(1);
}

fwrite(STDOUT, "OK: {$GLOBALS['__tests']} checks passed\n");

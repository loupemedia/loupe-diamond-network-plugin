<?php
/**
 * Calculator destination rewrite-rule ordering.
 *
 * Test intent: /diamond-prices/calculator/ must resolve to the calculator module,
 * not diamond-type level_2 with type=calculator.
 * Would fail if: the generic level-2 price rule is registered before the calculator rule.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-calculator-router.php
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');

if (!class_exists('LDN_Config')) {
    class LDN_Config {
        /** @var string */
        private $mount = '/';

        public function set_install_mount_path($path) {
            $this->mount = self::normalise_mount_path($path);
        }

        public function install_mount_path() {
            return $this->mount;
        }

        public static function normalise_mount_path($path) {
            $trim = trim((string) $path, '/');
            return $trim === '' ? '/' : '/' . strtolower($trim) . '/';
        }

        /** @return array<string, string> */
        public function get_url_structure($site_id) {
            return array(
                'level_2'           => '/{country}/diamond-prices/{type}',
                'calculator_level'  => '/{country}/diamond-prices/calculator',
            );
        }

        /** @return array<string, mixed>|null */
        public function get_site($site_id) {
            return array(
                'canonical_source_country' => 'us',
                'countries' => array(
                    array('code' => 'us'),
                    array('code' => 'nz'),
                ),
            );
        }

        public function site_country_codes($site_id) {
            $site = $this->get_site($site_id);
            $codes = array();
            if (!is_array($site) || empty($site['countries'])) {
                return $codes;
            }
            foreach ($site['countries'] as $entry) {
                if (is_array($entry) && !empty($entry['code'])) {
                    $codes[] = strtolower((string) $entry['code']);
                }
            }
            return $codes;
        }

        public function mount_country($site_id, $mount = null) {
            $mount = $mount !== null
                ? self::normalise_mount_path($mount)
                : $this->install_mount_path();
            $seg = trim($mount, '/');
            if ($seg === '') {
                return null;
            }
            return in_array($seg, $this->site_country_codes($site_id), true) ? $seg : null;
        }

        public function countries_for_install($site_id, array $enabled, $mount = null) {
            $mine = $this->mount_country($site_id, $mount);
            if ($mine === null) {
                return array_values($enabled);
            }
            $enabled = array_map('strtolower', $enabled);
            return in_array($mine, $enabled, true) ? array($mine) : array();
        }

        public function install_relative_pattern($pattern, $country, $mount = null) {
            $mount = $mount !== null
                ? self::normalise_mount_path($mount)
                : $this->install_mount_path();
            $country = strtolower((string) $country);
            if ($mount === '/' || trim($mount, '/') !== $country) {
                return $pattern;
            }
            $parts = array_values(array_filter(explode('/', trim((string) $pattern, '/')), 'strlen'));
            if ($parts === array()) {
                return $pattern;
            }
            $first = strtolower((string) $parts[0]);
            if ($first === '{country}' || $first === $country) {
                array_shift($parts);
            }
            return $parts === array() ? '' : '/' . implode('/', $parts);
        }
    }
}

if (!class_exists('LDN_Artefacts')) {
    class LDN_Artefacts {
        public function site_has_wp_widget($site_id, $widget_id, $page_level) {
            return $page_level === 'calculator' && $widget_id === 'price_calculator';
        }
    }
}

if (!class_exists('LDN_Rollout_Reader')) {
    class LDN_Rollout_Reader {
        /** @var string[] */
        private $countries;

        public function __construct(array $countries = array('us')) {
            $this->countries = $countries;
        }

        public function enabled_countries() {
            return $this->countries;
        }

        public function is_enabled($country, $module) {
            return true;
        }

        public function is_site_enabled($site_id, $country, $module) {
            return true;
        }
    }
}

require_once __DIR__ . '/../includes/class-ldn-calculator-router.php';

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

$router = new LDN_Calculator_Router(
    'ringspo',
    new LDN_Rollout_Reader(),
    new LDN_Config(),
    new LDN_Artefacts()
);
$rules = $router->build_rewrite_rules();
$keys = array_keys($rules);

$compiled = $router->compile_pattern('/{country}/diamond-prices/calculator', 'us');
check($compiled !== null, 'calculator pattern compiles for us');
$calculator_regex = $compiled[0];

check(isset($rules[$calculator_regex]), 'calculator rewrite rule exists');
check(
    $rules[$calculator_regex] === 'index.php?ldn_route=calculator&ldn_country=us',
    'calculator query maps to calculator module'
);

function ldn_rewrite_path_matches($regex, $path) {
    $normalised = trim((string) $path, '/');
    return (bool) preg_match('#' . $regex . '#', $normalised);
}

$calculator_path = 'us/diamond-prices/calculator';
check(
    ldn_rewrite_path_matches($calculator_regex, $calculator_path),
    'calculator regex matches /us/diamond-prices/calculator/'
);

$type_regex = '^us/diamond-prices/([^/]+)/?$';
check(
    ldn_rewrite_path_matches($type_regex, $calculator_path),
    'level-2 diamond-type regex would also match calculator URL without ordering guard'
);

$nz_cfg = new LDN_Config();
$nz_cfg->set_install_mount_path('/nz/');
$nz_router = new LDN_Calculator_Router(
    'ringspo',
    new LDN_Rollout_Reader(array('us', 'nz')),
    $nz_cfg,
    new LDN_Artefacts()
);
$nz_rules = $nz_router->build_rewrite_rules();
$nz_compiled = $nz_router->compile_pattern('/diamond-prices/calculator', 'nz');
check($nz_compiled !== null, 'calculator pattern compiles install-relative for nz');
check(
    isset($nz_rules[$nz_compiled[0]]),
    'calculator rewrite exists on a /nz/ mount'
);
check(
    count($nz_rules) === 1,
    'country subsite registers only its own calculator rule (got ' . count($nz_rules) . ')'
);
check(
    $nz_rules[$nz_compiled[0]] === 'index.php?ldn_route=calculator&ldn_country=nz',
    'install-relative calculator query still sets ldn_country=nz'
);
check(
    ldn_rewrite_path_matches($nz_compiled[0], 'diamond-prices/calculator'),
    'calculator regex on /nz/ matches diamond-prices/calculator'
);
check(
    !ldn_rewrite_path_matches($nz_compiled[0], 'us/diamond-prices/calculator'),
    'calculator regex on /nz/ does not match a us-prefixed path'
);

// Report the pass count, so a suite that silently stopped running checks is not
// mistaken for a suite that passed.
if ($GLOBALS['__fails'] === 0) {
    fwrite(STDOUT, "OK: {$GLOBALS['__tests']} checks passed\n");
    exit(0);
}
fwrite(STDERR, "FAILED: {$GLOBALS['__fails']}/{$GLOBALS['__tests']} checks failed\n");
exit(1);

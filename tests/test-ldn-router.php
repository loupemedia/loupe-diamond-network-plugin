<?php
/**
 * Price-module rewrite rules, and the public-vs-install path defect.
 *
 * Test intent: a price rewrite rule must match the path WordPress actually
 * presents to it. WordPress rewrite rules are install-relative — WP strips the
 * install's home path from the request before matching — so a rule built from a
 * site-absolute public path only works on an install mounted at the site root.
 *
 * Would fail if: compile_pattern still registered /{country}/… on a /nz/ mount,
 * or the carat capture started swallowing slashes.
 *
 * Story CP127_04 Part 1: public path (url_structures.yaml) is not the path
 * WordPress matches. A country subsite mounted at `/nz/` must register
 * `^diamond-prices/?$` and pass `/diamond-prices/` to home_url(), so the
 * public URL stays `/nz/diamond-prices/` on both topologies.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-router.php
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');

if (!class_exists('LDN_Config')) {
    class LDN_Config {
        /** @var array<string, array<string, string>> */
        private $structures = array(
            // Ringspo: country is a path segment.
            'ringspo' => array(
                'level_1'      => '/{country}/diamond-prices',
                'level_2'      => '/{country}/diamond-prices/{type}',
                'level_3'      => '/{country}/diamond-prices/{type}/{carat}',
                'level_4'      => '/{country}/diamond-prices/{type}/{carat}/{shape}',
                'type_natural' => 'natural',
                'type_lab'     => 'lab-grown',
            ),
            // Diamond Price Exact: one country per domain, no country segment.
            'prixdiamantfr' => array(
                'level_1'      => '/diamond-prices',
                'level_2'      => '/diamond-prices/{type}',
                'type_natural' => 'natural',
                'type_lab'     => 'lab-grown',
            ),
            // Diamond Hunt UK: /prices/ is the market hub; type tree has no prefix.
            'diamondhuntuk' => array(
                'level_1'      => '/prices',
                'level_2'      => '/{type}',
                'level_3'      => '/{type}/{carat}',
                'level_4'      => '/{type}/{carat}/{shape}',
                'type_natural' => 'mined-diamonds',
                'type_lab'     => 'lab-diamonds',
            ),
        );

        /** @var array<string, array<string, array>> */
        private $locales = array();

        /** @var string */
        private $mount = '/';

        public function set_install_mount_path($path) {
            $this->mount = self::normalise_mount_path($path);
        }

        public function install_mount_path() {
            return $this->mount;
        }

        public function get_site($site_id) {
            if ($site_id !== 'ringspo') {
                return null;
            }
            return array(
                'countries' => array(
                    array('code' => 'us'),
                    array('code' => 'nz'),
                    array('code' => 'ca'),
                    array('code' => 'jp'),
                ),
            );
        }

        /** @return array<string, string>|null */
        public function get_url_structure($site_id) {
            return isset($this->structures[$site_id]) ? $this->structures[$site_id] : null;
        }

        /** @param array<string,mixed> $segments */
        public function set_url_locale_segments($site_id, $country, array $segments) {
            $this->locales[$site_id][$country] = $segments;
        }

        public function url_locale_segments($site_id, $country) {
            return isset($this->locales[$site_id][$country])
                ? $this->locales[$site_id][$country]
                : array();
        }

        public function localise_url_structure(array $structure, array $segments) {
            if ($segments === array()) {
                return $structure;
            }
            $out = $structure;
            if (!empty($segments['path_diamond_prices'])) {
                foreach ($out as $key => $value) {
                    if (is_string($value) && strpos($value, 'diamond-prices') !== false) {
                        $out[$key] = str_replace(
                            'diamond-prices',
                            $segments['path_diamond_prices'],
                            $value
                        );
                    }
                }
            }
            if (!empty($segments['type_natural'])) {
                $out['type_natural'] = $segments['type_natural'];
            }
            if (!empty($segments['type_lab'])) {
                $out['type_lab'] = $segments['type_lab'];
            }
            return $out;
        }

        public function url_structure_for($site_id, $country = null) {
            $structure = $this->get_url_structure($site_id);
            if (!is_array($structure)) {
                return $structure;
            }
            return $this->localise_url_structure(
                $structure,
                $this->url_locale_segments($site_id, (string) $country)
            );
        }

        public static function normalise_mount_path($path) {
            $trim = trim((string) $path, '/');
            return $trim === '' ? '/' : '/' . strtolower($trim) . '/';
        }

        public function site_country_codes($site_id) {
            $site = $this->get_site($site_id);
            if (!is_array($site) || empty($site['countries'])) {
                return array();
            }
            $codes = array();
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
            $known = $this->site_country_codes($site_id);
            return in_array($seg, $known, true) ? $seg : null;
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

        public function path_relative_to_mount($public_path, $mount = null) {
            $mount = $mount !== null
                ? self::normalise_mount_path($mount)
                : $this->install_mount_path();
            $public = '/' . trim((string) $public_path, '/');
            if ($public === '/' || $mount === '/') {
                return $public === '/' ? '/' : $public;
            }
            $prefix = rtrim($mount, '/');
            if ($public === $prefix) {
                return '/';
            }
            if (strpos($public . '/', $prefix . '/') === 0) {
                $rest = substr($public, strlen($prefix));
                return $rest === '' ? '/' : $rest;
            }
            return $public;
        }
    }
}

if (!class_exists('LDN_Rollout_Reader')) {
    class LDN_Rollout_Reader {
        /** @var string[] */
        private $countries;

        public function __construct(array $countries = array('us', 'nz', 'ca')) {
            $this->countries = $countries;
        }

        public function enabled_countries() {
            return $this->countries;
        }

        public function is_enabled($country, $module) {
            return true;
        }

        public function version_changed() {
            return false;
        }

        public function mark_applied() {}
    }
}

require_once __DIR__ . '/../includes/class-ldn-router.php';

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

/**
 * Mirror of WP::parse_request(): WordPress removes the install's home path from
 * REQUEST_URI before matching rewrite rules, then trims slashes.
 *
 * On a single install home_url() path is `/`, so nothing is removed. On a
 * subdirectory multisite subsite it is that subsite's path (e.g. `/nz/`), which
 * is removed — which is the whole point of this test.
 */
function ldn_wp_requested_path($request_uri, $home_path) {
    $path = (string) $request_uri;
    $home = '/' . trim((string) $home_path, '/');
    if ($home !== '/' && strpos($path, $home) === 0) {
        $path = substr($path, strlen($home));
    }
    return trim($path, '/');
}

function ldn_rewrite_path_matches($regex, $path) {
    return (bool) preg_match('#' . $regex . '#', (string) $path);
}

/** Find the rule whose query names this level, for the given country. */
function ldn_rule_for($rules, $country, $level) {
    foreach ($rules as $regex => $query) {
        if (strpos($query, 'ldn_country=' . $country) !== false
            && strpos($query, 'ldn_level=' . $level) !== false) {
            return $regex;
        }
    }
    return null;
}

// =============================================================================
// 1. The rule builder itself (previously untested)
// =============================================================================

$router = new LDN_Router('ringspo', new LDN_Rollout_Reader(), new LDN_Config());
$rules = $router->build_rewrite_rules();

// 3 countries x 4 levels.
check(count($rules) === 12, 'builds one rule per country per level (got ' . count($rules) . ', want 12)');

$nz_level_1 = ldn_rule_for($rules, 'nz', 1);
check($nz_level_1 !== null, 'a level-1 rule exists for nz');

// The country is a literal, not a capture. This is what couples the rule to the
// install's home path, so it is asserted rather than assumed.
check(
    strpos($nz_level_1, 'nz') === 1,
    'country appears as a literal leading segment in the regex, not a capture (got ' . $nz_level_1 . ')'
);

$compiled = $router->compile_pattern('/{country}/diamond-prices/{type}/{carat}', 'nz', array('natural', 'lab-grown'), 3);
check(
    $compiled[1] === 'index.php?ldn_route=price&ldn_country=nz&ldn_level=3&ldn_type=$matches[1]&ldn_carat=$matches[2]',
    'level-3 query maps type and carat to captures in order (got ' . $compiled[1] . ')'
);

// A carat capture must not swallow a slash, or level 3 would match a level-4 URL.
check(
    !ldn_rewrite_path_matches($compiled[0], 'nz/diamond-prices/natural/1-carat/round'),
    'level-3 regex does not match a level-4 path'
);

// =============================================================================
// 2. Country-subsite install-relative rules (Story CP127_04 Part 1)
// =============================================================================

// Install mounted at the site root: public path and install-relative path are
// the same string. The root still registers every enabled country so live
// ringspo.com/uk/… keeps working until those countries move off the root.
$path_on_root = ldn_wp_requested_path('/nz/diamond-prices/', '/');
check($path_on_root === 'nz/diamond-prices', 'root install presents the full path including country');
check(
    ldn_rewrite_path_matches($nz_level_1, $path_on_root),
    'nz level-1 rule matches on an install mounted at the site root'
);

$path_on_subsite = ldn_wp_requested_path('/nz/diamond-prices/', '/nz/');
check($path_on_subsite === 'diamond-prices', 'WP strips the subsite mount path before rule matching');

$relative_regex = '^' . preg_quote('diamond-prices', '#') . '/?$';
check(
    ldn_rewrite_path_matches($relative_regex, $path_on_subsite),
    'TARGET (CP127_04): an install-relative rule matches on a /nz/ subsite'
);
check(
    !ldn_rewrite_path_matches($relative_regex, $path_on_root),
    'TARGET (CP127_04): that same relative rule must NOT be registered on the root install, '
        . 'where the country is genuinely part of the matched path'
);

$nz_cfg = new LDN_Config();
$nz_cfg->set_install_mount_path('/nz/');
$nz_sub = new LDN_Router('ringspo', new LDN_Rollout_Reader(), $nz_cfg);
$nz_sub_rules = $nz_sub->build_rewrite_rules();
check(
    count($nz_sub_rules) === 4,
    'country subsite registers only its own 4 levels (got ' . count($nz_sub_rules) . ', want 4)'
);

foreach ($nz_sub_rules as $query) {
    check(
        strpos($query, 'ldn_country=nz') !== false,
        'country-subsite rule query is nz, not another country'
    );
    check(
        strpos($query, 'ldn_country=us') === false && strpos($query, 'ldn_country=ca') === false,
        'country subsite does not register us or ca rules'
    );
}

$nz_sub_l1 = ldn_rule_for($nz_sub_rules, 'nz', 1);
check($nz_sub_l1 !== null, 'nz level-1 rule exists on a /nz/ mount');
check(
    $nz_sub_l1 === $relative_regex,
    'nz level-1 rule on /nz/ is install-relative (got ' . $nz_sub_l1 . ')'
);
check(
    ldn_rewrite_path_matches($nz_sub_l1, $path_on_subsite),
    'nz level-1 rule matches diamond-prices after WP strips /nz/'
);
check(
    strpos($nz_sub_rules[$nz_sub_l1], 'ldn_country=nz') !== false,
    'install-relative rule still sets ldn_country=nz in the query'
);

$doubled = ldn_wp_requested_path('/nz/nz/diamond-prices/', '/nz/');
check($doubled === 'nz/diamond-prices', 'doubled public path is what WP would see for /nz/nz/…');
check(
    !ldn_rewrite_path_matches($nz_sub_l1, $doubled),
    'install-relative rule does not match the doubled-country path'
);

foreach (array('us', 'nz', 'ca') as $cc) {
    $regex = ldn_rule_for($rules, $cc, 1);
    check(
        ldn_rewrite_path_matches($regex, ldn_wp_requested_path("/{$cc}/diamond-prices/", '/')),
        "{$cc} level-1 rule matches from an install mounted at the site root"
    );
}

$blog_cfg = new LDN_Config();
$blog_cfg->set_install_mount_path('/blog/');
check(
    $blog_cfg->mount_country('ringspo') === null,
    'a mount that is not a declared country (/blog/) is not treated as one'
);
check(
    $blog_cfg->countries_for_install('ringspo', array('us', 'nz', 'ca')) === array('us', 'nz', 'ca'),
    '/blog/ still registers every enabled country'
);
check(
    $blog_cfg->install_relative_pattern('/{country}/diamond-prices', 'nz') === '/{country}/diamond-prices',
    '/blog/ does not strip the country segment from the public pattern'
);
$blog_router = new LDN_Router('ringspo', new LDN_Rollout_Reader(), $blog_cfg);
$blog_rules = $blog_router->build_rewrite_rules();
check(count($blog_rules) === 12, '/blog/ mount builds the same 12 rules as the root');
$blog_nz = ldn_rule_for($blog_rules, 'nz', 1);
check(
    ldn_rewrite_path_matches($blog_nz, $path_on_root),
    '/blog/ still matches the public path including country'
);

check(
    $nz_cfg->path_relative_to_mount('/nz/diamond-prices/natural/1-carat/round')
        === '/diamond-prices/natural/1-carat/round',
    'path_relative_to_mount strips one /nz/ so home_url() does not double it'
);
check(
    $nz_cfg->path_relative_to_mount('/nz/diamond-prices') === '/diamond-prices',
    'path_relative_to_mount on the hub leaves diamond-prices'
);
check(
    $nz_cfg->install_relative_pattern('/{country}', 'nz') === '',
    'bare /{country} (guru / carat front page) becomes an empty rewrite and is skipped'
);

// =============================================================================
// 3. Country-less families are unaffected
// =============================================================================

// DPE carries one country per domain with no country segment, so its rules are
// built once and are independent of any install path question.
$dpe = new LDN_Router('prixdiamantfr', new LDN_Rollout_Reader(array('fr')), new LDN_Config());
$dpe_rules = $dpe->build_rewrite_rules();
check(count($dpe_rules) === 2, 'country-less family builds one rule set only (got ' . count($dpe_rules) . ', want 2)');

$dpe_level_1 = ldn_rule_for($dpe_rules, 'fr', 1);
check(
    ldn_rewrite_path_matches($dpe_level_1, ldn_wp_requested_path('/diamond-prices/', '/')),
    'country-less level-1 rule matches its own path'
);
check(
    strpos($dpe_level_1, 'fr') === false,
    'country-less regex carries no country segment, though the query still sets ldn_country'
);
check(
    strpos($dpe_rules[$dpe_level_1], 'ldn_country=fr') !== false,
    'country-less family still resolves a country for S3 lookup'
);

// A site with no url structure yields no rules rather than erroring.
$unknown = new LDN_Router('nosuchsite', new LDN_Rollout_Reader(), new LDN_Config());
check($unknown->build_rewrite_rules() === array(), 'unknown site builds no rules');

// A site with no enabled countries yields no rules.
$dark = new LDN_Router('ringspo', new LDN_Rollout_Reader(array()), new LDN_Config());
check($dark->build_rewrite_rules() === array(), 'site with no enabled countries builds no rules');

// =============================================================================
// 4. Diamond Hunt /prices/ is top-level; /mined-diamonds/ stays the type hub
// =============================================================================

$dh = new LDN_Router('diamondhuntuk', new LDN_Rollout_Reader(array('uk')), new LDN_Config());
$dh_rules = $dh->build_rewrite_rules();
check(count($dh_rules) === 4, 'DH builds one rule per level (got ' . count($dh_rules) . ', want 4)');

$dh_prices = ldn_rule_for($dh_rules, 'uk', 1);
check($dh_prices !== null, 'DH level-1 rule exists for /prices/');
check(
    ldn_rewrite_path_matches($dh_prices, ldn_wp_requested_path('/prices/', '/')),
    'DH /prices/ matches the top-level rule'
);
check(
    strpos($dh_rules[$dh_prices], 'ldn_type=') === false,
    'DH /prices/ query does not capture a type, so the dispatcher classifies top-level'
);
check(
    !ldn_rewrite_path_matches($dh_prices, ldn_wp_requested_path('/mined-diamonds/', '/')),
    'DH /prices/ rule does not swallow the type hub'
);

$dh_type = ldn_rule_for($dh_rules, 'uk', 2);
check(
    ldn_rewrite_path_matches($dh_type, ldn_wp_requested_path('/mined-diamonds/', '/')),
    'DH /mined-diamonds/ still matches the type hub'
);
check(
    strpos($dh_rules[$dh_type], 'ldn_type=$matches[1]') !== false,
    'DH type-hub query captures the type slug'
);

// =============================================================================
// 5. Localised paths (same substitutions C4.5 applies) must match
// =============================================================================

$jp_config = new LDN_Config();
$jp_config->set_url_locale_segments('ringspo', 'jp', array(
    'path_diamond_prices' => 'daiyamondo-kakaku',
    'path_prices'         => 'kakaku',
    'type_natural'        => 'tennen',
    'type_lab'            => 'rabo',
    'carat_suffix'        => 'karatto',
));
$jp_router = new LDN_Router('ringspo', new LDN_Rollout_Reader(array('jp')), $jp_config);
$jp_rules = $jp_router->build_rewrite_rules();

$jp_level_4 = null;
foreach ($jp_rules as $regex => $query) {
    if (strpos($query, 'ldn_country=jp') !== false
        && strpos($query, 'ldn_level=4') !== false
        && strpos($regex, 'daiyamondo') !== false
    ) {
        $jp_level_4 = $regex;
        break;
    }
}
check($jp_level_4 !== null, 'JP registers a localised level-4 rule');
check(
    ldn_rewrite_path_matches($jp_level_4, 'jp/daiyamondo-kakaku/tennen/1-karatto/cushion'),
    'JP localised path matches the localised rule'
);
check(
    strpos($jp_level_4, 'tennen') !== false && strpos($jp_level_4, 'rabo') !== false,
    'JP type alternation uses localised slugs, not natural|lab-grown'
);

$jp_english_rule = false;
foreach ($jp_rules as $regex => $query) {
    if (strpos($query, 'ldn_country=jp') !== false
        && strpos($regex, 'diamond\\-prices') !== false
    ) {
        $jp_english_rule = true;
        break;
    }
}
check(
    !$jp_english_rule,
    'JP no longer registers the English diamond-prices scheme'
);
check(
    $jp_level_4 !== null
        && !ldn_rewrite_path_matches($jp_level_4, 'jp/diamond-prices/natural/1-carat/cushion'),
    'the localised JP rule does not also match the English path'
);

$localised = $jp_router->localise_structure(
    $jp_config->get_url_structure('ringspo'),
    $jp_config->url_locale_segments('ringspo', 'jp')
);
check(
    $localised['level_4'] === '/{country}/daiyamondo-kakaku/{type}/{carat}/{shape}',
    'localise_structure replaces diamond-prices only (got ' . $localised['level_4'] . ')'
);
check(
    $localised['type_natural'] === 'tennen' && $localised['type_lab'] === 'rabo',
    'localise_structure swaps type slugs'
);

// =============================================================================
// 5. Composite slug (Diamond Advisors flat leaf)
// =============================================================================

$da_leaf = $router->compile_pattern(
    '/{country}/{carat}-{type}-{shape}-diamond-price',
    'us',
    array('natural', 'laboratory-grown'),
    4
);
check(
    ldn_rewrite_path_matches($da_leaf[0], 'us/1-carat-natural-round-cut-diamond-price'),
    'DA flat leaf matches the composite slug'
);
check(
    !ldn_rewrite_path_matches($da_leaf[0], 'us/1-carat-natural-round-cut-diamond-price/gia/2527938255'),
    'DA flat leaf does not swallow the cert listing path'
);
check(
    strpos($da_leaf[1], 'ldn_type=$matches[2]') !== false
        && strpos($da_leaf[1], 'ldn_carat=$matches[1]') !== false
        && strpos($da_leaf[1], 'ldn_shape=$matches[3]') !== false,
    'DA composite maps carat, type, shape in template order (got ' . $da_leaf[1] . ')'
);

$dpg_shape = $router->compile_pattern(
    '/{country}/{shape}/{carat}/{type}',
    'us',
    array('mined', 'lab'),
    4
);
check(
    ldn_rewrite_path_matches($dpg_shape[0], 'us/round-brilliant/1-ct/mined'),
    'DPG shape-first leaf matches'
);
check(
    !ldn_rewrite_path_matches($dpg_shape[0], 'us/mined/1-ct/round-brilliant'),
    'DPG shape-first leaf does not match the retired type-first path'
);

if ($GLOBALS['__fails'] === 0) {
    fwrite(STDOUT, "OK: {$GLOBALS['__tests']} checks passed\n");
    exit(0);
}
fwrite(STDERR, "FAILED: {$GLOBALS['__fails']}/{$GLOBALS['__tests']} checks failed\n");
exit(1);

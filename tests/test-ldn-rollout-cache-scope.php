<?php
/**
 * Rollout cache scope: network-wide payload, per-blog flush marker.
 *
 * Test intent: the rollout payload describes the whole network, so every subsite
 * of one WordPress network must share a single cached copy and the network must
 * perform ONE fetch per TTL regardless of how many country subsites it has. The
 * applied-version marker is the opposite: it drives a per-blog rewrite flush, so
 * each subsite must keep its own.
 *
 * Would fail if: the payload were cached with get_transient()/get_option(), which
 * are per-blog. A 30-country network would then fetch network-rollout.json 30
 * times per 5-minute TTL (8,640/day instead of 288), and the platform target of
 * ~17 country-path site families would reach ~510 subsites and ~146,880/day.
 *
 * Would ALSO fail if: the applied-version marker were moved network-wide to
 * match. The first subsite to run would record the version and every other
 * subsite would then skip flush_rewrite_rules(), so 29 of 30 country subsites
 * would serve stale rewrite rules after a rollout change - a silent 404 on newly
 * enabled countries, which is the exact failure this split prevents.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-rollout-cache-scope.php
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);

// -----------------------------------------------------------------------------
// Minimal WordPress storage model.
//
// Real WordPress keeps wp_options PER BLOG and wp_sitemeta once per NETWORK.
// $blog_store is swapped when we "switch blog"; $network_store never is.
// -----------------------------------------------------------------------------
$GLOBALS['blog_id'] = 1;
$GLOBALS['blog_stores'] = array();       // blog_id => [key => value]
$GLOBALS['network_store'] = array();     // shared across all blogs
$GLOBALS['fetch_count'] = 0;

function ldn_test_switch_blog($blog_id) {
    $GLOBALS['blog_id'] = $blog_id;
    if (!isset($GLOBALS['blog_stores'][$blog_id])) {
        $GLOBALS['blog_stores'][$blog_id] = array();
    }
}

function &ldn_test_blog_store() {
    ldn_test_switch_blog($GLOBALS['blog_id']);
    return $GLOBALS['blog_stores'][$GLOBALS['blog_id']];
}

// Per-blog options / transients.
function get_option($key, $default = false) {
    $s = &ldn_test_blog_store();
    return array_key_exists($key, $s) ? $s[$key] : $default;
}
function update_option($key, $value, $autoload = null) {
    $s = &ldn_test_blog_store();
    $s[$key] = $value;
    return true;
}
function get_transient($key) {
    $s = &ldn_test_blog_store();
    return array_key_exists('_t_' . $key, $s) ? $s['_t_' . $key] : false;
}
function set_transient($key, $value, $ttl = 0) {
    $s = &ldn_test_blog_store();
    $s['_t_' . $key] = $value;
    return true;
}
function delete_transient($key) {
    $s = &ldn_test_blog_store();
    unset($s['_t_' . $key]);
    return true;
}

// Network-wide options / transients.
function get_site_option($key, $default = false) {
    return array_key_exists($key, $GLOBALS['network_store'])
        ? $GLOBALS['network_store'][$key] : $default;
}
function update_site_option($key, $value) {
    $GLOBALS['network_store'][$key] = $value;
    return true;
}
function get_site_transient($key) {
    return array_key_exists('_t_' . $key, $GLOBALS['network_store'])
        ? $GLOBALS['network_store']['_t_' . $key] : false;
}
function set_site_transient($key, $value, $ttl = 0) {
    $GLOBALS['network_store']['_t_' . $key] = $value;
    return true;
}
function delete_site_transient($key) {
    unset($GLOBALS['network_store']['_t_' . $key]);
    return true;
}

// HTTP: every call counts as one fetch of network-rollout.json.
function wp_remote_get($url, $args = array()) {
    $GLOBALS['fetch_count']++;
    return array('code' => 200, 'body' => $GLOBALS['rollout_json']);
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function is_wp_error($r) { return false; }
function apply_filters($tag, $value) { return $value; }
function wp_json_encode($data) { return json_encode($data); }

if (!class_exists('LDN_Environment')) {
    class LDN_Environment {
        const PRODUCTION = 'production';
        public static function current() { return self::PRODUCTION; }
        public static function normalize($e) { return (string) $e; }
    }
}
if (!class_exists('LDN_Config')) {
    class LDN_Config {
        public static function instance() { return new self(); }
        public function get_rollout_url() { return 'https://example.invalid/network-rollout.json'; }
    }
}
if (!class_exists('LDN_Plugin')) {
    class LDN_Plugin {
        public static function debug_log($ctx, $msg) {}
    }
}

require_once __DIR__ . '/../includes/class-ldn-rollout-reader.php';

// A valid rollout: 3 countries live for ringspo, checksum over `environments`.
$environments = array(
    'production' => array('sites' => array(
        'ringspo' => array(
            'us' => array('price' => true, 'size' => true),
            'nz' => array('price' => true),
            'ca' => array('price' => true),
        ),
    )),
);
$rollout = array(
    'version'      => 42,
    'updated_at'   => '2026-08-18T00:00:00Z',
    'environments' => $environments,
);
// Use the reader's own canonicalisation, so the fixture is valid by construction
// rather than by a hardcoded digest that would rot if the routine changed.
$rollout['checksum'] = LDN_Rollout_Reader::canonical_checksum($environments);
$GLOBALS['rollout_json'] = json_encode($rollout);

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;
function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

// A fresh reader per request, as WordPress would build one per page load.
function reader_for_blog($blog_id) {
    ldn_test_switch_blog($blog_id);
    return new LDN_Rollout_Reader('ringspo', 'https://example.invalid/network-rollout.json', 'production');
}

// =============================================================================
// 1. One network, 30 country subsites => ONE fetch
// =============================================================================

$blogs = range(1, 30);
foreach ($blogs as $b) {
    $r = reader_for_blog($b);
    check($r->is_enabled('us', 'price') === true, "blog {$b} reads the rollout correctly");
}

check(
    $GLOBALS['fetch_count'] === 1,
    "30 subsites of one network perform ONE fetch per TTL (got {$GLOBALS['fetch_count']})"
);

// The payload landed network-wide, not in any blog's own store.
check(
    get_site_transient(LDN_Rollout_Reader::TRANSIENT_KEY) !== false,
    'payload cached in the network store'
);
ldn_test_switch_blog(7);
check(
    get_transient(LDN_Rollout_Reader::TRANSIENT_KEY) === false,
    'payload NOT cached per-blog (blog 7 has no private copy)'
);

// =============================================================================
// 2. The applied-version marker stays per-blog
// =============================================================================

$r1 = reader_for_blog(1);
check($r1->version_changed() === true, 'blog 1 sees the version as unapplied');
$r1->mark_applied();
check($r1->version_changed() === false, 'blog 1 has applied it');

// Blog 2 must still flush its own rules. If this marker were network-wide, blog 1
// marking it applied would make 29 other subsites skip their flush and serve
// stale rewrite rules.
$r2 = reader_for_blog(2);
check(
    $r2->version_changed() === true,
    'blog 2 still needs its own flush after blog 1 applied - marker is per-blog'
);
$r2->mark_applied();

ldn_test_switch_blog(3);
check(
    reader_for_blog(3)->version_changed() === true,
    'blog 3 likewise still needs its own flush'
);

// And the marker did not leak into the network store.
check(
    get_site_option(LDN_Rollout_Reader::APPLIED_VERSION_OPTION, null) === null,
    'applied-version marker is absent from the network store'
);

// =============================================================================
// 3. Last-good is network-wide, and the pre-upgrade per-blog copy is honoured
// =============================================================================

check(
    LDN_Rollout_Reader::LAST_GOOD_OPTION !== ''
        && get_site_option(LDN_Rollout_Reader::LAST_GOOD_OPTION, null) !== null,
    'last-good copy written to the network store'
);

// Simulate an upgraded install: network store empty, S3 unreachable, but a
// per-blog last-good row survives from the previous plugin version. The site must
// not go dark.
$GLOBALS['network_store'] = array();
$GLOBALS['blog_stores'] = array();
ldn_test_switch_blog(9);
update_option(LDN_Rollout_Reader::LAST_GOOD_OPTION, $rollout, false);

$GLOBALS['rollout_json'] = '{ not json';

$upgraded = reader_for_blog(9);
check(
    $upgraded->is_enabled('us', 'price') === true,
    'upgraded install falls back to its per-blog last-good rather than going dark'
);
check(
    get_site_option(LDN_Rollout_Reader::LAST_GOOD_OPTION, null) !== null,
    'and promotes that copy to network scope so siblings benefit'
);

if ($GLOBALS['__fails'] === 0) {
    fwrite(STDOUT, "OK: {$GLOBALS['__tests']} checks passed\n");
    exit(0);
}
fwrite(STDERR, "FAILED: {$GLOBALS['__fails']}/{$GLOBALS['__tests']} checks failed\n");
exit(1);

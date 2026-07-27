<?php
/**
 * SEO-plugin bridge on routed LDN pages.
 *
 * Test intent (the rules this file enforces):
 *   1. On a routed LDN page, exactly one plugin emits each of canonical, meta
 *      description and the Open Graph set — LDN — so SEOPress's version of every
 *      tag LDN also emits is suppressed at source.
 *   2. Off an LDN route, and on a route that 404s, SEOPress is untouched: the
 *      site's ordinary pages must keep their SEO output.
 *   3. Tags that are site-level facts SEOPress gets right and LDN does not emit
 *      (og:locale, twitter:card, twitter:creator) are NOT suppressed.
 *   4. LDN emits its own meta description whenever no unbridged SEO plugin is
 *      active — the previous "any SEO plugin is installed, so stand down" rule
 *      left LDN pages on a SEOPress site with no description at all.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-seo-bridge.php
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
define('SEOPRESS_VERSION', '8.0');

// --- WordPress shims ---------------------------------------------------------
$GLOBALS['ldn_registered'] = array();
function add_filter($hook, $cb, $priority = 10, $accepted = 1) {
    $GLOBALS['ldn_registered'][$hook] = $priority;
}

require_once __DIR__ . '/../includes/class-ldn-page-context.php';

// --- Collaborator stubs ------------------------------------------------------
class LDN_Dispatcher {
    public $ctx = null;
    public function current_context() { return $this->ctx; }
}
class LDN_Size_Dispatcher {
    public $ctx = null;
    public function current_context() { return $this->ctx; }
}
class LDN_Plugin {
    public static $self;
    public $price;
    public $size;
    public static function instance() { return self::$self; }
    public function dispatcher() { return $this->price; }
    public function size_dispatcher() { return $this->size; }
}

require_once __DIR__ . '/../includes/class-ldn-seo-bridge.php';

$checks = 0;
$fails = 0;
function check($cond, $msg) {
    global $checks, $fails;
    ++$checks;
    if (!$cond) {
        ++$fails;
        fwrite(STDERR, "FAIL: $msg\n");
    }
}

$bridge = new LDN_Seo_Bridge();
$bridge->register();

// --- 1. Every tag LDN emits is claimed from SEOPress ------------------------
// Would fail if: a tag LDN emits were left off the list (duplicate in the head)
// or a filter name were misspelled (silently no-ops — verified against SEOPress
// 8.x source, inc/functions/options-titles-metas.php and options-social.php).
$expected = array(
    'seopress_titles_canonical'          => 'canonical',
    'seopress_titles_desc'               => 'meta description',
    'seopress_social_og_url'             => 'og:url',
    'seopress_social_og_type'            => 'og:type',
    'seopress_social_og_title'           => 'og:title',
    'seopress_social_og_desc'            => 'og:description',
    'seopress_social_og_site_name'       => 'og:site_name',
    'seopress_social_og_thumb'           => 'og:image',
    'seopress_social_twitter_card_thumb' => 'twitter:image',
);
foreach ($expected as $filter => $label) {
    check(isset($GLOBALS['ldn_registered'][$filter]), "{$label} is claimed from SEOPress ({$filter})");
}

// --- 2. Site-level tags SEOPress gets right are left alone ------------------
// LDN emits no og:locale or twitter card type, so suppressing these would lose
// information rather than de-duplicate it.
foreach (array('seopress_social_og_locale', 'seopress_social_twitter_card_summary', 'seopress_social_twitter_card_creator') as $keep) {
    check(!isset($GLOBALS['ldn_registered'][$keep]), "not suppressed: {$keep}");
}

// --- 3. Suppression applies to LDN pages and nothing else -------------------
LDN_Plugin::$self = new LDN_Plugin();
LDN_Plugin::$self->price = new LDN_Dispatcher();
LDN_Plugin::$self->size = new LDN_Size_Dispatcher();

$seopress_canonical = '<link rel="canonical" href="https://ringspo.com/us/diamond-prices/">';

// No context on either dispatcher: an ordinary post, or an LDN route that failed
// its artefact probe and will 404. Either way SEOPress keeps its output.
check(
    $bridge->suppress_on_ldn_route($seopress_canonical) === $seopress_canonical,
    'an ordinary site page keeps its SEOPress canonical'
);
check(
    $bridge->suppress_on_ldn_route('A site description.') === 'A site description.',
    'an ordinary site page keeps its SEOPress description'
);

LDN_Plugin::$self->price->ctx = new LDN_Page_Context('ringspo', 'shape', 'us', 'natural', '1', 'round');
check(
    $bridge->suppress_on_ldn_route($seopress_canonical) === '',
    'a routed price page suppresses the SEOPress canonical'
);
check($bridge->suppress_on_ldn_route('Blog description.') === '', 'a routed price page suppresses the SEOPress description');
check($bridge->suppress_on_ldn_route('') === '', 'an already-empty value stays empty');

// A size page is equally an LDN page.
LDN_Plugin::$self->price->ctx = null;
LDN_Plugin::$self->size->ctx = new LDN_Page_Context('ringspo', 'size-individual', 'us', null, '1', 'round', 'size');
check(
    $bridge->suppress_on_ldn_route($seopress_canonical) === '',
    'a routed size page suppresses the SEOPress canonical'
);

// --- 4. Ownership reporting drives whether LDN emits its description --------
// Would fail if: unbridged_plugin_active() went back to counting SEOPress, which
// is what left every LDN page on this network with no meta description.
check(
    LDN_Seo_Bridge::seopress_active() === true,
    'SEOPress is detected as active'
);
check(
    LDN_Seo_Bridge::unbridged_plugin_active() === false,
    'SEOPress does not count as unbridged, so LDN emits its own description'
);

if ($fails === 0) {
    echo "OK: {$checks} checks passed\n";
    exit(0);
}
fwrite(STDERR, "FAILED: {$fails}/{$checks} checks failed\n");
exit(1);

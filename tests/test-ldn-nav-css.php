<?php
/**
 * Navigation stylesheet guards (PRD-018 CP125_03 / CP125_04).
 *
 * Test intent: Mega-menu panel geometry derives from the viewport and a custom
 * property, never from a fixed pixel width or a percentage offset tuned to the
 * current item order, and the stylesheet forks neither the brand palette nor the
 * mobile rules.
 *
 * Would fail if: the stylesheet reproduced `width: 1140px; left: -34% !important`,
 * so adding "Diamond Prices" as a new first item pushed every panel off-centre and
 * the panel overflowed a 1024px laptop.
 *
 * Run: php loupe-diamond-network/tests/test-ldn-nav-css.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$css_path = dirname(__DIR__) . '/assets/css/nav-mega-menu.css';

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $msg) {
    $GLOBALS['__tests']++;
    if (!$cond) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
}

check(is_readable($css_path), 'the navigation stylesheet exists');
if (!is_readable($css_path)) {
    fwrite(STDERR, "1 of 1 checks FAILED\n");
    exit(1);
}

$css = file_get_contents($css_path);

// Declarations only, with comments stripped: the file explains in prose why the
// old Customizer rules were wrong, and quoting them must not trip these guards.
$code = preg_replace('#/\*.*?\*/#s', '', $css);

// -----------------------------------------------------------------------------
// The fragility being replaced
// -----------------------------------------------------------------------------

check(
    stripos($code, '!important') === false,
    'no !important anywhere: the rule being replaced needed one to beat the theme, '
        . 'and that is what made it unmaintainable'
);

check(
    !preg_match('/left:\s*-?\d+(\.\d+)?%/i', $code),
    'no percentage left offset: it is calibrated to where an item currently sits, '
        . 'so it breaks whenever an item is added, removed or renamed'
);

// The lookbehind keeps `max-width`/`min-width` out of it, including the media-query
// breakpoints, which are supposed to be pixel values. Only a bare `width` whose
// whole value is a pixel length is the fragility being guarded against; a px
// fallback inside min() is bounded and fine.
check(
    !preg_match('/(?<![-\w])width:\s*\d{3,}px\s*(?:;|})/i', $code),
    'no fixed multi-hundred-pixel width: 1140px overflows a 1024px laptop'
);

check(
    strpos($code, '100vw') !== false,
    'panel width is bounded by the viewport'
);

check(
    preg_match('/var\(--ldn-nav-panel-max/', $code) === 1,
    'the site-tunable panel width is a custom property, so widening it needs no CSS edit'
);

// -----------------------------------------------------------------------------
// Palette is inherited, not forked
// -----------------------------------------------------------------------------

check(
    !preg_match('/#[0-9a-f]{3,8}\b/i', $code),
    'no hex colour: this stylesheet loads on legacy posts where LDN emits no '
        . '--ldn-* properties, so colour must come from the theme via inherit/currentColor'
);

check(
    substr_count($code, 'currentColor') >= 1 || substr_count($code, 'inherit') >= 1,
    'colours resolve through currentColor or inherit'
);

// A named brand colour would fork the palette just as badly as a hex.
foreach (array('red', 'blue', 'green', 'purple', 'orange', 'teal') as $named) {
    check(
        !preg_match('/(?:color|background(?:-color)?)\s*:\s*' . $named . '\b/i', $code),
        sprintf('no named brand colour "%s"', $named)
    );
}

// -----------------------------------------------------------------------------
// Columns must be allowed to shrink
// -----------------------------------------------------------------------------

check(
    strpos($code, 'minmax(0, 1fr)') !== false,
    'grid tracks use minmax(0, 1fr) so a long label cannot push the row wider than '
        . 'the viewport'
);

/*
 * Check EVERY grid-template-columns value rather than pattern-matching one shape of
 * it. A regex over `repeat(...)` cannot cross the nested paren in
 * `repeat(var(--ldn-nav-cols), 1fr)`, so it silently passed a bare 1fr track.
 */
preg_match_all('/grid-template-columns:\s*([^;}]+)/', $code, $track_lists);
$bare_1fr = array();
foreach ($track_lists[1] as $value) {
    $value = trim($value);
    if (strpos($value, '1fr') === false) {
        continue;
    }
    // Remove the sanctioned form; any 1fr left over is an unshrinkable track.
    if (strpos(str_replace('minmax(0, 1fr)', '', $value), '1fr') !== false) {
        $bare_1fr[] = $value;
    }
}
check(
    $bare_1fr === array(),
    'no bare 1fr track: it refuses to shrink below its longest label, pushing the '
        . 'row past the viewport. Offending: ' . implode(' | ', $bare_1fr)
);

check(
    stripos($code, 'overflow-wrap: anywhere') === false,
    'no overflow-wrap: anywhere, which yields one character per line in a squeezed column'
);

check(
    !preg_match('/white-space:\s*nowrap/i', $code),
    'no nowrap: a non-wrapping label in a multi-column grid forces horizontal scroll'
);

// -----------------------------------------------------------------------------
// The narrow layout is present in the same stylesheet
// -----------------------------------------------------------------------------

check(
    preg_match('/@media[^{]*max-width:\s*(\d+)px/', $code) === 1,
    'a narrow breakpoint exists, so the phone layout ships with the desktop one'
);

preg_match_all('/@media[^{]*max-width:\s*(\d+)px/', $code, $breakpoints);
$narrowest = $breakpoints[1] !== array() ? min(array_map('intval', $breakpoints[1])) : 99999;
check(
    $narrowest <= 400,
    sprintf(
        'the narrowest breakpoint (%dpx) reaches the 320-400px band the mobile rule requires',
        $narrowest
    )
);

check(
    preg_match('/min-height:\s*44px/', $code) === 1,
    'tap targets are at least 44px in the narrow layout'
);

check(
    preg_match('/#generate-slideout-menu[^{]*\{[^}]*position:\s*static/s', $code) === 1,
    'the slide-out undoes the absolute-positioned desktop panel, because a drawer '
        . 'renders server-side with no viewport for a media query to read'
);

// -----------------------------------------------------------------------------
// Scoped to menus LDN owns
// -----------------------------------------------------------------------------

// Every selector must name an LDN class or one of the two theme containers we
// deliberately target. An unscoped `.mega-menu` would restyle other plugins' menus.
$stripped = preg_replace('/@media[^{]*\{/', '', $code);
preg_match_all('/(^|\})\s*([^{}@]+)\{/m', $stripped, $matches);
$unscoped = array();
foreach ($matches[2] as $selector_group) {
    $selector_group = trim($selector_group);
    if ($selector_group === '' || strpos($selector_group, '--') === 0) {
        continue;
    }
    foreach (explode(',', $selector_group) as $selector) {
        $selector = trim($selector);
        if ($selector === '') {
            continue;
        }
        $owned = strpos($selector, '.ldn-nav') !== false
            || strpos($selector, '#generate-slideout-menu') !== false
            || strpos($selector, '#mobile-header') !== false;
        if (!$owned) {
            $unscoped[] = $selector;
        }
    }
}
check(
    $unscoped === array(),
    'every selector is scoped to an LDN class or a named theme container; unscoped: '
        . implode(' | ', $unscoped)
);

check(
    !preg_match('/(^|[^-\w])\.mega-menu\b/', $code),
    'no bare .mega-menu selector: other plugins use that class name'
);

// -----------------------------------------------------------------------------
// Keyboard access
// -----------------------------------------------------------------------------

check(
    strpos($code, ':focus-visible') !== false,
    'focus is styled, so a keyboard user can see where they are'
);

check(
    strpos($code, '.ldn-nav-column-heading > .sub-menu') !== false
        && preg_match(
            '/\.ldn-nav-column-heading\s*>\s*\.sub-menu[^{]*\{[^}]*display:\s*block/s',
            $code
        ),
    'nested column lists are display:block, so a mega panel shows more than headings'
);

check(
    !preg_match('/outline:\s*(none|0)/i', $code),
    'focus outlines are never removed'
);

// -----------------------------------------------------------------------------
// Footer + slide-out utility (phone)
//
// Test intent: the footer is a wrapping row on desktop and a stacked column
// on a phone, with 44px targets, and the slide-out marks where secondary
// items begin. Would fail if: the footer used nowrap or a shared 100% width
// that crushed siblings, or the utility separator was unscoped.
// -----------------------------------------------------------------------------

check(
    strpos($code, '.ldn-nav-footer') !== false
        && preg_match(
            '/\.ldn-nav-footer(?:\s*,\s*\.ldn-nav-footer ul)?[^{]*\{[^}]*flex-direction:\s*column/s',
            $code
        ),
    'the footer stacks to one column below 768px'
);

check(
    preg_match('/\.ldn-nav-footer a[^{]*\{[^}]*min-height:\s*44px/', $code) === 1,
    'footer links are at least 44px tall'
);

check(
    strpos($code, '.ldn-nav-utility-start') !== false,
    'the slide-out marks where the utility items begin'
);

// -----------------------------------------------------------------------------

$total = $GLOBALS['__tests'];
$fails = $GLOBALS['__fails'];
if ($fails === 0) {
    fwrite(STDOUT, "OK: {$total} checks passed\n");
    exit(0);
}
fwrite(STDERR, "{$fails} of {$total} checks FAILED\n");
exit(1);

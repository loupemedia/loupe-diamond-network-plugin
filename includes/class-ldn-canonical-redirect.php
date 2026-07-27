<?php
/**
 * Canonical trailing-slash redirect for routed price and size pages.
 *
 * Both routers compile their rewrite rules with an optional trailing slash
 * (`/?$` — see LDN_Router::compile_pattern() and
 * LDN_Size_Router::pattern_to_regex()), and WordPress's own
 * redirect_canonical() does not normalise custom rewrite matches. Without this
 * guard `/…/round` and `/…/round/` both return 200 for the same page and the
 * duplication is left for `<link rel="canonical">` to resolve.
 *
 * The canonical form is whatever user_trailingslashit() produces, which is what
 * trait-ldn-url.php's current_url() feeds to the canonical tag, og:url, JSON-LD
 * `@id`/`url` and hreflang. Deriving the redirect target the same way keeps the
 * 200 URL and the declared canonical in agreement on every install.
 *
 * Sitemap routes are excluded: LDN_Sitemap_Module anchors its rules with `$`
 * (no slash variant) and a `.xml` path must never gain a trailing slash.
 *
 * @package LoupeDiamondNetwork
 * @since   0.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Canonical_Redirect {

    /**
     * Routes whose URLs represent HTML pages with a slash-terminated canonical.
     *
     * @var string[]
     */
    const ROUTES = array('price', 'size');

    /**
     * Hook into WordPress.
     *
     * Priority 5 on template_redirect runs before the dispatchers'
     * template_include filters, so no body is rendered on a non-canonical URL.
     *
     * @return void
     */
    public function register() {
        add_action('template_redirect', array($this, 'maybe_redirect'), 5);
    }

    /**
     * 301 to the canonical form when the request used the other one.
     *
     * @return void
     */
    public function maybe_redirect() {
        if (!in_array((string) get_query_var('ldn_route'), self::ROUTES, true)) {
            return;
        }

        $method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET');
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $target = self::canonical_request_uri($request_uri);
        if ($target === null) {
            return;
        }

        wp_safe_redirect($this->absolute_url($target), 301);
        exit;
    }

    /**
     * Canonical request URI for a routed page, or null when the given URI is
     * already canonical and must therefore not be redirected.
     *
     * Pure apart from user_trailingslashit(), so it can be unit tested with that
     * function stubbed to either permalink convention.
     *
     * @param string $request_uri Raw REQUEST_URI, may include a query string.
     * @return string|null Path (plus original query string) or null.
     */
    public static function canonical_request_uri($request_uri) {
        $uri = (string) $request_uri;
        if ($uri === '' || $uri[0] !== '/') {
            return null;
        }

        $query = '';
        $split = strpos($uri, '?');
        if ($split !== false) {
            $query = substr($uri, $split);
            $uri = substr($uri, 0, $split);
        }

        if ($uri === '/') {
            return null;
        }

        // Never touch a file-like path (sitemap.xml, llms.txt). Carat slugs may
        // contain a dot ("1.5-carat"), so match an extension rather than a dot.
        if (preg_match('#\.[a-z0-9]{2,4}$#i', $uri)) {
            return null;
        }

        $canonical = function_exists('user_trailingslashit') ? user_trailingslashit($uri) : $uri;
        if ($canonical === $uri) {
            return null;
        }

        return $canonical . $query;
    }

    /**
     * Absolutise a request URI against the site's canonical scheme and host.
     *
     * Taking scheme+host from home_url() (rather than HTTP_HOST) keeps the
     * target inside wp_safe_redirect()'s allowed hosts and folds an apex/www
     * mismatch into the same hop. The path is used verbatim so installs in a
     * subdirectory are not double-prefixed.
     *
     * @param string $request_uri Path, optionally with a query string.
     * @return string
     */
    private function absolute_url($request_uri) {
        $parts = function_exists('home_url') ? parse_url(home_url('/')) : array();
        $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';
        $host = isset($parts['host'])
            ? $parts['host']
            : (isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '');

        return $scheme . '://' . $host . $request_uri;
    }
}

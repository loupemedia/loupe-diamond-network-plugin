<?php
/**
 * Corrections for main-query state that leaks onto LDN pages.
 *
 * LDN rewrite rules target `index.php?ldn_route=…` carrying only this plugin's
 * query vars (see `LDN_Router::compile_pattern()`). WordPress treats a query
 * with no core vars as the blog index, so every LDN page runs with `is_home()`
 * true. Two things leak out of that:
 *
 *   1. The `<title>` is the blog index's, not the page's — on Ringspo that
 *      rendered as the bare site name while `og:title` carried the real
 *      "1 Carat Round Natural Diamond Prices (US)".
 *   2. A full posts query runs and its pagination state stays live, so a
 *      paginated blog index advertised `…/1-carat/round/page/2/` as the next
 *      page of a pricing URL. The templates never read those posts —
 *      `templates/level-1-landing.php` calls `get_header()`, echoes the
 *      renderer and calls `get_footer()`, with no loop at all.
 *
 * `is_home()` is deliberately left TRUE. Core's `WP::handle_404()` spares a
 * post-less main query from a 404 only when one of a fixed set of conditionals
 * holds, and `is_home()` is the one that applies here — clearing it would 404
 * every LDN page as soon as the posts query is skipped. It is also what the
 * active theme keys its body classes off, so flipping it risks changing page
 * furniture across the network. Turning these into true virtual pages is a
 * larger change that needs staging verification of theme layout and of core's
 * `redirect_canonical`.
 *
 * Disable per site (whole class, or one aspect):
 *   add_filter('ldn_normalise_query', '__return_false');
 *   add_filter('ldn_normalise_query', function ($on, $aspect) {
 *       return $aspect === 'document_title' ? false : $on;
 *   }, 10, 2);
 *
 * @package LoupeDiamondNetwork
 * @since   0.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Query_Signals {

    /**
     * Routes that render an HTML page through the theme. The `sitemap` route
     * streams XML and exits before the theme loads, so it has no title or
     * pagination state to correct.
     */
    const ROUTES = array('price', 'size', 'calculator');

    /**
     * Hook the corrections.
     *
     * @return void
     */
    public function register() {
        add_filter('posts_pre_query', array($this, 'skip_unused_posts_query'), 10, 2);
        // Late: whoever filters last wins, and on an LDN route the page's own
        // title must beat the SEO plugin's guess for the blog index.
        add_filter('pre_get_document_title', array($this, 'document_title'), 9999);
    }

    /**
     * Skip the main posts query on LDN routes and flatten its pagination state.
     *
     * @param array|null $posts Null until some filter supplies posts.
     * @param WP_Query   $query
     * @return array|null Empty array to bypass the SQL, or $posts untouched.
     */
    public function skip_unused_posts_query($posts, WP_Query $query) {
        if (!$this->applies_to_query($query, 'posts_query')) {
            return $posts;
        }
        // Normally set by set_found_posts() in the SQL path being bypassed. Set
        // them explicitly so nothing can derive a "next page" for this URL.
        $query->found_posts = 0;
        $query->max_num_pages = 1;
        return array();
    }

    /**
     * Supply the LDN page's own title in place of the blog index's.
     *
     * @param string $title
     * @return string
     */
    public function document_title($title) {
        if (!$this->aspect_enabled('document_title')) {
            return $title;
        }
        $ldn_title = self::resolved_document_title();
        return $ldn_title === null ? $title : $ldn_title;
    }

    /**
     * The LDN page title with site name, or null when this is not an LDN page.
     *
     * Shared by `pre_get_document_title` and the SEOPress title bridge so the
     * browser tab, the SERP link and the social card cannot disagree.
     *
     * @return string|null
     */
    public static function resolved_document_title() {
        $plugin = LDN_Plugin::instance();

        $price = $plugin->dispatcher();
        if ($price instanceof LDN_Dispatcher) {
            $ctx = $price->current_context();
            $renderer = $plugin->renderer();
            if ($ctx instanceof LDN_Page_Context && $renderer instanceof LDN_Renderer) {
                return self::with_site_name($renderer->document_title($ctx));
            }
        }

        $size = $plugin->size_dispatcher();
        if ($size instanceof LDN_Size_Dispatcher) {
            $ctx = $size->current_context();
            if ($ctx instanceof LDN_Page_Context) {
                $summary = $size->primary_data();
                $renderer = new LDN_Size_Renderer($plugin->data_fetcher(), $plugin->config());
                return self::with_site_name(
                    $renderer->page_title($ctx, is_array($summary) ? $summary : array())
                );
            }
        }

        if (class_exists('LDN_Calculator_Module')) {
            $calculator = LDN_Calculator_Module::dispatcher();
            if ($calculator instanceof LDN_Calculator_Dispatcher) {
                $ctx = $calculator->current_context();
                if ($ctx instanceof LDN_Page_Context) {
                    return self::with_site_name(
                        __('Diamond Price Calculator', 'loupe-diamond-network')
                    );
                }
            }
        }

        return null;
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /**
     * Append the site name the way WordPress does for ordinary pages, so LDN
     * titles sit consistently alongside the rest of the site in a SERP: same
     * `document_title_separator`, same `get_bloginfo('name', 'display')` source.
     *
     * Returning a value from `pre_get_document_title` short-circuits
     * `wp_get_document_title()` before its own assembly, so the separator and
     * site name have to be applied here or they are simply lost.
     *
     * No escaping beyond stripping tags: core echoes the title unescaped and
     * feeds it from equally unescaped sources, and running `esc_html()` over a
     * site name that already contains an entity would double-encode it. The
     * page-title half is built from config slugs and numbers, not user input.
     *
     * @param string $title
     * @return string|null Null when there is no title to work with.
     */
    private static function with_site_name($title) {
        $title = is_string($title) ? trim(wp_strip_all_tags($title)) : '';
        if ($title === '') {
            return null;
        }
        $site_name = trim(wp_strip_all_tags(get_bloginfo('name', 'display')));
        if ($site_name === '' || strpos($title, $site_name) !== false) {
            return $title;
        }
        $separator = apply_filters('document_title_separator', '-');
        return $title . ' ' . $separator . ' ' . $site_name;
    }

    /**
     * Whether this correction applies to the given query.
     *
     * @param WP_Query $query
     * @param string   $aspect
     * @return bool
     */
    private function applies_to_query(WP_Query $query, $aspect) {
        if (!$query->is_main_query()) {
            return false;
        }
        if (!in_array($query->get('ldn_route'), self::ROUTES, true)) {
            return false;
        }
        return $this->aspect_enabled($aspect);
    }

    /**
     * @param string $aspect
     * @return bool
     */
    private function aspect_enabled($aspect) {
        return (bool) apply_filters('ldn_normalise_query', true, $aspect);
    }
}

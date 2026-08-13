<?php
/**
 * Hands the <head> of a routed LDN page to LDN, taking it off the SEO plugin.
 *
 * An SEO plugin decides what to emit from what WordPress says the request is,
 * and on an LDN route WordPress says "blog index" (see `LDN_Query_Signals` for
 * why). So the plugin's per-page logic is working from the wrong premise on
 * exactly the pages LDN generates, which produced three defects on Ringspo:
 *
 *   - **No meta description at all.** SEOPress emits one only for a singular
 *     post, a posts page carrying `_seopress_titles_desc`, or a site whose
 *     front page is the blog — an LDN route is none of those — while LDN
 *     suppressed its own on the assumption that an installed SEO plugin had it
 *     covered. Both stayed silent.
 *   - **Two canonical tags.** SEOPress's DEFAULT CANONICAL branch derives one
 *     from the request URL, so it agreed with LDN's only because
 *     `LDN_Canonical_Redirect` guarantees the request URL is already canonical.
 *     Two tags that agree by luck are one settings change from disagreeing, and
 *     conflicting canonicals are ignored outright.
 *   - **An empty `og:image` ahead of the real one**, plus duplicate `og:url`,
 *     `og:type` and `og:site_name`. Scrapers generally take the first
 *     occurrence, so the chart preview never won.
 *   - **`<title>` stuck on the site name.** SEOPress owns the HTML title via
 *     `seopress_titles_title`, deriving it from WordPress's blog-index query
 *     state on LDN routes. `LDN_Query_Signals` hooks `pre_get_document_title`,
 *     but SEOPress does not use that path when it is active — so the tab and
 *     SERP link showed "Modern Jeweler" while `og:title` carried the real page
 *     title from LDN's head block.
 *
 * The fix is ownership, not detection: on an LDN route the competing tags are
 * suppressed at source and LDN emits the full set from the page context. SEOPress
 * filters receive the whole rendered tag, so returning '' removes it.
 *
 * Deliberately NOT suppressed: `og:locale` and `twitter:card` / `twitter:creator`,
 * which are site-level facts SEOPress gets right and LDN does not emit.
 * `twitter:image` IS suppressed — SEOPress pointed it at an unrelated media-library
 * upload, and with no `twitter:image` present X falls back to `og:image`, which is
 * the page's own chart preview.
 *
 * Only SEOPress is bridged, because it is the plugin the network runs and its
 * behaviour here was verified against real output. Yoast and Rank Math are left
 * alone: LDN keeps deferring its meta description to them, as before, since what
 * they emit on these routes has not been checked. See `docs/TECH_DEBT.md`.
 *
 * @package LoupeDiamondNetwork
 * @since   0.12.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Seo_Bridge {

    /**
     * SEOPress filters whose tag LDN emits itself on a routed page.
     *
     * Each receives the fully rendered tag (or, for `seopress_titles_desc`, the
     * description text), and each consumer either guards on a non-empty value or
     * echoes the empty string harmlessly.
     */
    const SEOPRESS_FILTERS = array(
        'seopress_titles_canonical',
        'seopress_titles_desc',
        'seopress_social_og_url',
        'seopress_social_og_type',
        'seopress_social_og_title',
        'seopress_social_og_desc',
        'seopress_social_og_site_name',
        'seopress_social_og_thumb',
        'seopress_social_twitter_card_thumb',
    );

    /**
     * SEOPress filter for the HTML `<title>` string. Unlike the tags above,
     * LDN replaces the value rather than suppressing it.
     */
    const SEOPRESS_TITLE_FILTER = 'seopress_titles_title';

    /**
     * Hook the suppression filters.
     *
     * Registered unconditionally for the site; each callback decides per request
     * whether this is an LDN page, because that is not known until the dispatcher
     * has resolved a route and found its data.
     *
     * @return void
     */
    public function register() {
        if (!self::seopress_active()) {
            return;
        }
        foreach (self::SEOPRESS_FILTERS as $filter) {
            // Late, so a site's own customisation of the tag still runs first and
            // is then discarded along with SEOPress's own value.
            add_filter($filter, array($this, 'suppress_on_ldn_route'), 99);
        }
        add_filter(self::SEOPRESS_TITLE_FILTER, array($this, 'replace_title_on_ldn_route'), 99);
    }

    /**
     * Supply the LDN page title to SEOPress on routed pages.
     *
     * @param string $title SEOPress's derived title (often the bare site name).
     * @return string
     */
    public function replace_title_on_ldn_route($title) {
        $ldn_title = LDN_Query_Signals::resolved_document_title();
        return $ldn_title !== null ? $ldn_title : $title;
    }

    /**
     * Drop the SEO plugin's version of a tag LDN owns, on LDN routes only.
     *
     * @param mixed $value Rendered tag, or description text.
     * @return mixed Empty string on an LDN route, otherwise $value untouched.
     */
    public function suppress_on_ldn_route($value) {
        return self::is_ldn_page() ? '' : $value;
    }

    // =========================================================================
    // State
    // =========================================================================

    /**
     * Whether an SEO plugin LDN does not bridge is active, and so still owns the
     * meta description on routed pages.
     *
     * @return bool
     */
    public static function unbridged_plugin_active() {
        return defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION');
    }

    /**
     * @return bool
     */
    public static function seopress_active() {
        return defined('SEOPRESS_VERSION');
    }

    /**
     * Whether the in-flight request is a rendering LDN page.
     *
     * A dispatcher holds a context only once it has matched a route AND found the
     * page's primary artefact, so a context is proof we are rendering the page
     * rather than about to 404 it — on a 404 the SEO plugin should keep its own
     * output.
     *
     * @return bool
     */
    public static function is_ldn_page() {
        $plugin = LDN_Plugin::instance();
        if (!($plugin instanceof LDN_Plugin)) {
            return false;
        }

        $price = $plugin->dispatcher();
        if ($price instanceof LDN_Dispatcher && $price->current_context() instanceof LDN_Page_Context) {
            return true;
        }

        $size = $plugin->size_dispatcher();
        if ($size instanceof LDN_Size_Dispatcher && $size->current_context() instanceof LDN_Page_Context) {
            return true;
        }

        return false;
    }
}

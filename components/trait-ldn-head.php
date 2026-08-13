<?php
/**
 * Renderer component trait — split from class-ldn-renderer.php (CP53).
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

trait LDN_Trait_Head {
    /**
     * <head> tags for the page (canonical + Open Graph). Returns a string so the
     * dispatcher can echo it on `wp_head`. Filterable/disable-able for sites
     * whose SEO plugin already emits these for dynamic routes.
     *
     * @param LDN_Page_Context $ctx
     * @param string|null      $canonical_url Absolute canonical URL, or null to derive.
     * @param array            $summary       summary-data payload for rich description.
     * @param string|null      $currency      ISO currency code.
     * @param array            $site          Optional site config for country names.
     * @return string
     */
    public function head_tags(LDN_Page_Context $ctx, $canonical_url = null, array $summary = array(), $currency = null, array $site = array()) {
        if (!apply_filters('ldn_emit_head_tags', true, $ctx)) {
            return '';
        }

        if ($canonical_url === null) {
            $canonical_url = $this->current_url();
        }
        $title = $this->document_title($ctx);
        $schema = new LDN_Schema();
        $desc = $schema->dataset_description($ctx, $summary, $currency, null, $site);

        $tags = '';
        if (!$this->seo_plugin_emits_meta()) {
            $tags .= '<meta name="description" content="' . esc_attr($desc) . '" />' . "\n";
        }
        if ($canonical_url !== '') {
            if (!$this->seo_plugin_emits_canonical()) {
                $tags .= '<link rel="canonical" href="' . esc_url($canonical_url) . '" />' . "\n";
            }
            $tags .= '<meta property="og:url" content="' . esc_url($canonical_url) . '" />' . "\n";
        }
        $tags .= '<meta property="og:type" content="website" />' . "\n";
        $tags .= '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
        $tags .= '<meta property="og:description" content="' . esc_attr($desc) . '" />' . "\n";

        $site = $this->config->get_site($ctx->site_id);
        $brand = is_array($site) && !empty($site['brand_name']) ? (string) $site['brand_name'] : '';
        if ($brand !== '') {
            $tags .= '<meta property="og:site_name" content="' . esc_attr($brand) . '" />' . "\n";
        }

        $og_image = $this->og_preview_url($ctx);
        if ($og_image !== '') {
            $tags .= '<meta property="og:image" content="' . esc_url($og_image) . '" />' . "\n";
            $tags .= '<meta property="og:image:width" content="1200" />' . "\n";
            $tags .= '<meta property="og:image:height" content="630" />' . "\n";
            $tags .= '<meta property="og:image:type" content="image/png" />' . "\n";
            $tags .= '<meta property="og:image:alt" content="' . esc_attr($title) . '" />' . "\n";
        }

        return $tags;
    }

    /**
     * The page's title, honouring the profile's `country_in_content.page_titles`.
     *
     * Shared by `og:title` and the `<title>` element (`LDN_Query_Signals` feeds it
     * to `pre_get_document_title`) so the browser tab, the SERP link and the
     * social card cannot disagree. Exposed separately because the country flag
     * and the content profile are resolved from private renderer state.
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function document_title(LDN_Page_Context $ctx) {
        return $this->headline($ctx, $this->country_in_content_flag($this->profile($ctx), 'page_titles'));
    }

    /**
     * Public HTTPS URL for the page's OG chart preview PNG, or '' when absent.
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function og_preview_url(LDN_Page_Context $ctx) {
        if ($ctx->page_level !== 'shape') {
            return '';
        }
        $url = $this->fetcher->resolve_artefact_url('og_preview_png', $ctx);
        return is_string($url) ? $url : '';
    }

    /**
     * Whether another plugin owns the meta description on this page.
     *
     * Only true for an SEO plugin LDN does not bridge. This used to be true for
     * any installed SEO plugin, which cost every LDN page on a SEOPress site its
     * meta description entirely: SEOPress emits one only for a singular post, a
     * posts page carrying `_seopress_titles_desc`, or a blog-as-front-page site,
     * and a routed LDN page is none of those — so LDN stood down for a tag nobody
     * was producing. `LDN_Seo_Bridge` now suppresses SEOPress's description on
     * these routes, making LDN unambiguously the owner.
     *
     * @return bool
     */
    private function seo_plugin_emits_meta() {
        return LDN_Seo_Bridge::unbridged_plugin_active();
    }

    /**
     * Whether another plugin owns the canonical tag on this page.
     *
     * False by design: LDN owns the canonical URL contract for its own routes
     * (trailing slash included — see `LDN_Canonical_Redirect`), and
     * `LDN_Seo_Bridge` suppresses SEOPress's competing tag rather than yielding
     * to it. SEOPress derived its canonical from the request URL, which agreed
     * with LDN's only because the redirect guarantees the request URL is already
     * the canonical form.
     *
     * @return bool
     */
    private function seo_plugin_emits_canonical() {
        return false;
    }
}

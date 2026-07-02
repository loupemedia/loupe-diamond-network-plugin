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
     * @return string
     */
    public function head_tags(LDN_Page_Context $ctx, $canonical_url = null, array $summary = array(), $currency = null) {
        if (!apply_filters('ldn_emit_head_tags', true, $ctx)) {
            return '';
        }

        if ($canonical_url === null) {
            $canonical_url = $this->current_url();
        }
        $title = $this->headline($ctx, $this->country_in_content_flag($this->profile($ctx), 'page_titles'));
        $schema = new LDN_Schema();
        $desc = $schema->dataset_description($ctx, $summary, $currency);

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
        }

        return $tags;
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
     * Whether a common SEO plugin is likely to emit meta description on its own.
     *
     * LDN dynamic routes still emit OG tags (SEOPress often misses these URLs).
     *
     * @return bool
     */
    private function seo_plugin_emits_meta() {
        return defined('SEOPRESS_VERSION') || defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION');
    }

    /**
     * Whether a common SEO plugin is likely to emit canonical on its own.
     *
     * @return bool
     */
    private function seo_plugin_emits_canonical() {
        // Dynamic LDN routes are invisible to most SEO plugins — keep our canonical.
        return false;
    }
}

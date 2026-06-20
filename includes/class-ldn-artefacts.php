<?php
/**
 * Artefact entitlements (read side) — port of shared/config/artefacts.py.
 *
 * Answers "may this site render this artefact / widget?" from the deployed
 * config bundle (artefacts catalogue + site entitlements), so the plugin uses
 * the same product decisions as the Python pipeline.
 *
 * Scope vs. Python `should_produce()`:
 *   - Ported: artefact catalogue lookup, `status` (on_hold ⇒ never render),
 *     entitlement listing (`pages.{level}.pipeline_artefacts`), the
 *     market-index-pipeline gate (site_type), the color/clarity opt-in gate,
 *     and the WP-widget helpers.
 *   - Deferred: the `time_series` technical gate (needs the time-series policy
 *     config, not in the bundle). This is safe on the read side — an artefact
 *     that the pipeline didn't produce simply 404s and the fetcher returns null.
 *
 * @package LoupeDiamondNetwork
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Artefacts {

    /**
     * Pricing page levels that require the market-index pipeline.
     */
    const PRICING_PAGE_LEVELS = array('shape', 'all-shapes', 'diamond-type', 'top-level');

    /**
     * Site types exempt from the market-index pipeline (mirrors
     * shared/config/profiles.py MARKET_INDEX_EXEMPT_SITE_TYPES).
     */
    const MARKET_INDEX_EXEMPT_SITE_TYPES = array('calculator_tool', 'scoring_authority', 'data_source');

    /**
     * @var LDN_Config
     */
    private $config;

    /**
     * @param LDN_Config $config
     */
    public function __construct(LDN_Config $config) {
        $this->config = $config;
    }

    /**
     * Artefact catalogue entry, or null when unknown.
     *
     * @param string $artefact_id
     * @return array|null
     */
    public function get_artefact($artefact_id) {
        $bundle = $this->config->get_bundle();
        $catalogue = isset($bundle['artefacts']['artefacts']) && is_array($bundle['artefacts']['artefacts'])
            ? $bundle['artefacts']['artefacts']
            : array();
        return isset($catalogue[$artefact_id]) && is_array($catalogue[$artefact_id])
            ? $catalogue[$artefact_id]
            : null;
    }

    /**
     * Entitlements block for a site, or null when the site isn't listed.
     *
     * @param string $site_id
     * @return array|null
     */
    public function site_entitlements($site_id) {
        $bundle = $this->config->get_bundle();
        $sites = isset($bundle['entitlements']['sites']) && is_array($bundle['entitlements']['sites'])
            ? $bundle['entitlements']['sites']
            : array();
        return isset($sites[$site_id]) && is_array($sites[$site_id]) ? $sites[$site_id] : null;
    }

    /**
     * Whether the site's entitlements list an artefact (product decision only).
     *
     * Mirrors shared/config/artefacts.py site_entitled_to_artefact().
     *
     * @param string $site_id
     * @param string $artefact_id
     * @return bool
     */
    public function site_entitled_to_artefact($site_id, $artefact_id) {
        $site_ent = $this->site_entitlements($site_id);
        if ($site_ent === null) {
            return false;
        }
        $entry = $this->get_artefact($artefact_id);
        if ($entry === null) {
            return false;
        }
        $page_level = isset($entry['page_level']) ? $entry['page_level'] : 'shape';

        if ($artefact_id === 'url_registry_rows') {
            foreach (self::PRICING_PAGE_LEVELS as $level) {
                if (!empty($this->pipeline_artefacts_for_page($site_ent, $level))) {
                    return true;
                }
            }
            return false;
        }

        if ($page_level === 'all') {
            foreach (self::PRICING_PAGE_LEVELS as $level) {
                if (in_array($artefact_id, $this->pipeline_artefacts_for_page($site_ent, $level), true)) {
                    return true;
                }
            }
            return false;
        }

        if (!$this->page_level_enabled($site_ent, $page_level)) {
            return false;
        }
        return in_array($artefact_id, $this->pipeline_artefacts_for_page($site_ent, $page_level), true);
    }

    /**
     * Whether the plugin should render/fetch an artefact for a site.
     *
     * Combines: catalogue presence, `status` (on_hold blocked), the
     * market-index-pipeline gate, the color/clarity opt-in, and the entitlement
     * listing.
     *
     * @param string $site_id
     * @param string $artefact_id
     * @return bool
     */
    public function should_render($site_id, $artefact_id) {
        $entry = $this->get_artefact($artefact_id);
        if ($entry === null) {
            return false;
        }

        // On-hold artefacts (e.g. cross-site comparison) must never render.
        if (isset($entry['status']) && $entry['status'] === 'on_hold') {
            return false;
        }

        $page_level = isset($entry['page_level']) ? $entry['page_level'] : 'shape';
        $site = $this->config->get_site($site_id);

        if (in_array($page_level, self::PRICING_PAGE_LEVELS, true) && !$this->runs_market_index_pipeline($site)) {
            return false;
        }

        $gate = isset($entry['gate']) ? $entry['gate'] : null;
        if ($gate === 'color_clarity' && !$this->color_clarity_enabled($site)) {
            return false;
        }

        return $this->site_entitled_to_artefact($site_id, $artefact_id);
    }

    /**
     * Whether a WP widget is enabled on a page level for a site.
     *
     * @param string $site_id
     * @param string $widget_id
     * @param string $page_level
     * @return bool
     */
    public function site_has_wp_widget($site_id, $widget_id, $page_level = 'shape') {
        $site_ent = $this->site_entitlements($site_id);
        if ($site_ent === null || !$this->page_level_enabled($site_ent, $page_level)) {
            return false;
        }
        $value = $this->widget_value($site_ent, $page_level, $widget_id);
        if ($value === true) {
            return true;
        }
        if (is_array($value)) {
            return !(isset($value['enabled']) && $value['enabled'] === false);
        }
        return false;
    }

    /**
     * Presentation variant for a widget, or null when disabled.
     *
     * @param string $site_id
     * @param string $widget_id
     * @param string $page_level
     * @return string|null
     */
    public function wp_widget_presentation($site_id, $widget_id, $page_level = 'shape') {
        $site_ent = $this->site_entitlements($site_id);
        if ($site_ent === null) {
            return null;
        }
        $value = $this->widget_value($site_ent, $page_level, $widget_id);
        if ($value === true) {
            return 'default';
        }
        if (is_array($value) && isset($value['presentation'])) {
            return (string) $value['presentation'];
        }
        return null;
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * @param array  $site_ent
     * @param string $page_level
     * @return bool
     */
    private function page_level_enabled(array $site_ent, $page_level) {
        $pages = isset($site_ent['pages']) && is_array($site_ent['pages']) ? $site_ent['pages'] : array();
        if (!isset($pages[$page_level]) || !is_array($pages[$page_level])) {
            return false;
        }
        return !(isset($pages[$page_level]['enabled']) && $pages[$page_level]['enabled'] === false);
    }

    /**
     * @param array  $site_ent
     * @param string $page_level
     * @return string[]
     */
    private function pipeline_artefacts_for_page(array $site_ent, $page_level) {
        $pages = isset($site_ent['pages']) && is_array($site_ent['pages']) ? $site_ent['pages'] : array();
        $page = isset($pages[$page_level]) && is_array($pages[$page_level]) ? $pages[$page_level] : array();
        return isset($page['pipeline_artefacts']) && is_array($page['pipeline_artefacts'])
            ? $page['pipeline_artefacts']
            : array();
    }

    /**
     * @param array  $site_ent
     * @param string $page_level
     * @param string $widget_id
     * @return mixed
     */
    private function widget_value(array $site_ent, $page_level, $widget_id) {
        $pages = isset($site_ent['pages']) && is_array($site_ent['pages']) ? $site_ent['pages'] : array();
        $page = isset($pages[$page_level]) && is_array($pages[$page_level]) ? $pages[$page_level] : array();
        $widgets = isset($page['wp_widgets']) && is_array($page['wp_widgets']) ? $page['wp_widgets'] : array();
        return isset($widgets[$widget_id]) ? $widgets[$widget_id] : null;
    }

    /**
     * Mirrors shared/config/profiles.py site_runs_market_index_pipeline().
     *
     * @param array|null $site
     * @return bool
     */
    private function runs_market_index_pipeline($site) {
        if (!is_array($site) || empty($site['site_type'])) {
            return true;
        }
        return !in_array($site['site_type'], self::MARKET_INDEX_EXEMPT_SITE_TYPES, true);
    }

    /**
     * @param array|null $site
     * @return bool
     */
    private function color_clarity_enabled($site) {
        if (!is_array($site) || empty($site['features']) || !is_array($site['features'])) {
            return false;
        }
        return !empty($site['features']['color_clarity_calc']);
    }
}

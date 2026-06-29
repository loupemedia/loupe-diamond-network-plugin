<?php
/**
 * S3 key resolver — PRD-005 S3 Key Resolution Contract (Steps 1-3, 5).
 *
 * Builds the S3 object key (and full URL) for an artefact on a given page,
 * without ever hardcoding per-site paths. Resolution = folder prefix
 * (site-family rules / `graph_prefix`) + filename (canonical names, with
 * profile `file_naming` overrides).
 *
 * Single Responsibility: this class is pure key math. The entitlement gate
 * (Contract Step 4) and HTTP fetch/cache live in the artefacts/data-fetcher
 * layer (next checkpoint). `resolve_s3_key()` returns the relative key;
 * `resolve_url()` prepends the site's S3 base URL.
 *
 * Scope of this slice: the **standard families** (Ringspo, Loupe, Diamond
 * Price Exact) — shape pages via `graph_prefix`, other levels via the standard
 * folder table. Carat-EMD fixed-carat sites, Better Diamond Initiative
 * (`lab` segment), and "all-shapes-on-top-level" remapping are documented
 * follow-ups (Contract Step 2 family table).
 *
 * The fixed basename map MUST stay in sync with
 * shared/ops/manifest_entitlement_audit.py `_BASENAME_ARTEFACT`.
 *
 * @package LoupeDiamondNetwork
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_S3_Key_Resolver {

    /**
     * Fixed artefact_id → basename (same across families unless a profile
     * overrides via file_naming). Reverse of `_BASENAME_ARTEFACT`.
     *
     * @var array<string, string>
     */
    const FIXED_BASENAMES = array(
        'price_graph_json'       => 'price-graph.json',
        'distribution_json'      => 'distribution-graph.json',
        'templated_copy_json'    => 'copy.json',
        'carat_ladder_json'      => 'carat-ladder.json',
        'carat_ladder_chart'     => 'carat-ladder-chart.json',
        'color_clarity_json'     => 'color-clarity.json',
        'comparison_data_json'   => 'comparison-data.json',
        'shapes_ranking_json'    => 'shapes-ranking.json',
        'type_summary_json'      => 'type-summary.json',
        'market_overview_json'   => 'market-overview.json',
        'market_discount_chart'  => 'market-discount-chart.json',
        'market_trend_chart'     => 'market-trend-chart.json',
        'shapes_at_carat_chart'  => 'shapes-ranking-chart.json',
        'all_shapes_summary_json' => 'summary-data.json',
        'all_shapes_content_json' => 'all-shapes-content.json',
        'og_preview_png'         => 'og-preview.png',
    );

    /**
     * Artefact_id → content-profile `file_naming` key, for profile-varying
     * basenames (e.g. DPE `price_graph` = "price-chart.html" vs Ringspo
     * "price-graph.html").
     *
     * @var array<string, string>
     */
    const PROFILE_FILE_NAMING = array(
        'price_graph_html'        => 'price_graph',
        'summary_data_json'       => 'summary_data',
        'individual_content_json' => 'individual_content',
        'all_shapes_html'         => 'all_shapes',
        'static_content_json'     => 'static_content',
    );

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
     * Resolve the relative S3 key for an artefact on a page.
     *
     * @param string           $artefact_id
     * @param LDN_Page_Context $ctx
     * @return string|null `{folder_prefix}{basename}` (no leading slash), or
     *                     null when the folder or filename can't be resolved.
     */
    public function resolve_s3_key($artefact_id, LDN_Page_Context $ctx) {
        $prefix = $this->folder_prefix($ctx);
        if ($prefix === null) {
            return null;
        }
        $basename = $this->basename_for($artefact_id, $ctx->site_id);
        if ($basename === null) {
            return null;
        }
        return $prefix . $basename;
    }

    /**
     * Resolve the full HTTPS URL for an artefact ({s3_base_url}/{key}).
     *
     * @param string           $artefact_id
     * @param LDN_Page_Context $ctx
     * @return string|null
     */
    public function resolve_url($artefact_id, LDN_Page_Context $ctx) {
        $key = $this->resolve_s3_key($artefact_id, $ctx);
        if ($key === null) {
            return null;
        }
        return rtrim($this->s3_base_url($ctx->site_id), '/') . '/' . $key;
    }

    // =========================================================================
    // Step 2 — folder prefix
    // =========================================================================

    /**
     * Folder prefix for a page (trailing slash, no leading slash).
     *
     * @param LDN_Page_Context $ctx
     * @return string|null
     */
    private function folder_prefix(LDN_Page_Context $ctx) {
        $country = $ctx->country_code;
        $type = $ctx->diamond_type;

        switch ($ctx->page_level) {
            case 'shape':
            case 'individual':
                return $this->shape_prefix($ctx);

            case 'all-shapes':
                if ($country === '' || $type === null || $ctx->carat === null) {
                    return null;
                }
                return "{$country}/{$type}/{$ctx->carat}-carat/all-shapes/";

            case 'diamond-type':
                if ($country === '' || $type === null) {
                    return null;
                }
                return "{$country}/top-level/{$type}/";

            case 'top-level':
                if ($country === '') {
                    return null;
                }
                return "{$country}/top-level/diamond-prices/";

            default:
                return null;
        }
    }

    /**
     * Shape/individual page prefix via the site's `graph_prefix` template.
     *
     * @param LDN_Page_Context $ctx
     * @return string|null
     */
    private function shape_prefix(LDN_Page_Context $ctx) {
        $site = $this->config->get_site($ctx->site_id);
        if ($site === null || empty($site['s3']['graph_prefix'])) {
            return null;
        }
        if ($ctx->diamond_type === null || $ctx->carat === null || $ctx->shape === null) {
            return null;
        }

        $replacements = array(
            '{country}'      => $ctx->country_code,
            '{diamond_type}' => $ctx->diamond_type,
            '{carat}'        => $ctx->carat,
            '{shape}'        => $this->config->shape_to_s3_slug($ctx->shape),
        );

        return strtr((string) $site['s3']['graph_prefix'], $replacements);
    }

    // =========================================================================
    // Step 3 — filename
    // =========================================================================

    /**
     * Basename for an artefact, applying profile overrides then fixed names.
     *
     * @param string $artefact_id
     * @param string $site_id
     * @return string|null
     */
    public function basename_for($artefact_id, $site_id) {
        // Profile-varying names win (e.g. DPE price-chart.html).
        if (isset(self::PROFILE_FILE_NAMING[$artefact_id])) {
            $file_naming = $this->config->get_file_naming($site_id);
            $key = self::PROFILE_FILE_NAMING[$artefact_id];
            if (!empty($file_naming[$key]) && is_string($file_naming[$key])) {
                return $file_naming[$key];
            }
        }

        if (isset(self::FIXED_BASENAMES[$artefact_id])) {
            return self::FIXED_BASENAMES[$artefact_id];
        }

        return null;
    }

    // =========================================================================
    // Step 5 — S3 base URL
    // =========================================================================

    /**
     * Site's S3 base URL. Prefers an explicit `s3.base_url`; otherwise derives
     * a virtual-hosted–style URL from the bucket. Filterable for CloudFront.
     *
     * @param string $site_id
     * @return string
     */
    public function s3_base_url($site_id) {
        $site = $this->config->get_site($site_id);
        $s3 = is_array($site) && isset($site['s3']) && is_array($site['s3']) ? $site['s3'] : array();

        if (!empty($s3['base_url']) && is_string($s3['base_url'])) {
            $base = $s3['base_url'];
        } elseif (!empty($s3['bucket']) && is_string($s3['bucket'])) {
            $base = 'https://' . $s3['bucket'] . '.s3.amazonaws.com';
        } else {
            $base = '';
        }

        return (string) apply_filters('ldn_s3_base_url', $base, $site_id);
    }
}

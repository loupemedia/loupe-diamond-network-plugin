<?php
/**
 * Renderer component trait — split from class-ldn-renderer.php (CP53).
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

trait LDN_Trait_Navigation {
    /**
     * Visible breadcrumb trail when the profile lists `breadcrumb` in schema_features.
     *
     * @param LDN_Page_Context $ctx
     * @param string           $canonical_url
     * @param array|null       $profile Optional pre-resolved profile.
     * @return string
     */
    public function breadcrumb_html(LDN_Page_Context $ctx, $canonical_url = '', ?array $profile = null) {
        if ($profile === null) {
            $profile = $this->profile($ctx);
        }
        if (!$this->profile_has_schema_feature($profile, 'breadcrumb')) {
            return '';
        }

        $trail = $this->breadcrumb_trail($ctx, $canonical_url);
        if (count($trail) < 2) {
            return '';
        }

        $items = '';
        $last = count($trail) - 1;
        foreach ($trail as $i => $crumb) {
            $name = isset($crumb['name']) ? (string) $crumb['name'] : '';
            if ($name === '') {
                continue;
            }
            if ($i > 0) {
                $items .= '<span class="ldn-breadcrumbs__sep" aria-hidden="true">›</span>';
            }
            if ($i === $last) {
                $items .= '<span aria-current="page">' . esc_html($name) . '</span>';
            } else {
                $url = isset($crumb['url']) ? (string) $crumb['url'] : '';
                if ($url === '') {
                    $items .= esc_html($name);
                } else {
                    $items .= '<a href="' . esc_url($url) . '">' . esc_html($name) . '</a>';
                }
            }
        }

        if ($items === '') {
            return '';
        }

        return '<nav class="ldn-breadcrumbs" aria-label="'
            . esc_attr__('Breadcrumb', 'loupe-diamond-network') . '">' . $items . '</nav>';
    }

    /**
     * Visible freshness line: analysis date + sample size (CP53_05).
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary summary-data payload.
     * @return string
     */
    public function freshness_html(LDN_Page_Context $ctx, array $summary) {
        $schema = new LDN_Schema();
        $date_iso = $schema->analysis_date($summary);
        if ($date_iso === '') {
            return '';
        }

        $sample_size = $this->dig_first($summary, array(
            array('distribution', 'sample_size'),
            array('num_diamonds'),
            array('sample_size'),
        ));
        $sample_size = is_numeric($sample_size) ? (int) $sample_size : 0;

        $display_date = $date_iso;
        if (function_exists('date_i18n')) {
            $ts = strtotime($date_iso . 'T12:00:00');
            if ($ts !== false) {
                $display_date = date_i18n(get_option('date_format'), $ts);
            }
        }

        $parts = array(
            sprintf(
                /* translators: %s: formatted date */
                __('Prices last updated: %s', 'loupe-diamond-network'),
                '<time datetime="' . esc_attr($date_iso) . '">' . esc_html($display_date) . '</time>'
            ),
        );
        if ($sample_size > 0) {
            $parts[] = sprintf(
                /* translators: %s: formatted integer count */
                __('Based on %s diamonds in our database', 'loupe-diamond-network'),
                esc_html(number_format($sample_size))
            );
        }

        return '<p class="ldn-freshness">' . implode(' · ', $parts) . '</p>';
    }

    /**
     * Progressive breadcrumb trail (Home → Diamond Prices → … → current page).
     *
     * Reuses build_price_page_url() so intermediate URLs come from the site's
     * url_structure (single source of truth). Crumbs whose URL can't be resolved
     * are dropped. Consumed by LDN_Schema::breadcrumb_node().
     *
     * @param LDN_Page_Context $ctx
     * @param string           $canonical_url Absolute URL of the current page.
     * @return array<int, array{name:string, url:string}>
     */
    public function breadcrumb_trail(LDN_Page_Context $ctx, $canonical_url = '') {
        $trail = array();

        $home = function_exists('home_url') ? (string) home_url('/') : '';
        if ($home !== '') {
            $trail[] = array('name' => 'Home', 'url' => $home);
        }

        $trail[] = array(
            'name' => __('Diamond Prices', 'loupe-diamond-network'),
            'url'  => $ctx->page_level === 'top-level' ? $canonical_url : $this->build_price_page_url($ctx, 'top-level'),
        );

        if ($ctx->diamond_type !== null && $ctx->page_level !== 'top-level') {
            $type_label = isset(self::$TYPE_LABELS[$ctx->diamond_type])
                ? self::$TYPE_LABELS[$ctx->diamond_type]
                : ucwords(str_replace('-', ' ', $ctx->diamond_type));
            $trail[] = array(
                'name' => sprintf('%s Diamonds', $type_label),
                'url'  => $ctx->page_level === 'diamond-type' ? $canonical_url : $this->build_price_page_url($ctx, 'diamond-type'),
            );
        }

        if ($ctx->carat !== null && in_array($ctx->page_level, array('all-shapes', 'shape'), true)) {
            $trail[] = array(
                'name' => sprintf('%s Carat', $this->format_carat_label($ctx->carat)),
                'url'  => $ctx->page_level === 'all-shapes' ? $canonical_url : $this->build_price_page_url($ctx, 'all-shapes'),
            );
        }

        if ($ctx->shape !== null && $ctx->page_level === 'shape') {
            $trail[] = array(
                'name' => ucwords(str_replace('-', ' ', $ctx->shape)),
                'url'  => $canonical_url,
            );
        }

        return array_values(array_filter($trail, static function ($crumb) {
            return !empty($crumb['url']);
        }));
    }

    /**
     * US-only link from a pricing shape page to the matching size page (Decision 5).
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function size_price_link_html(LDN_Page_Context $ctx) {
        if ($ctx->module !== 'price' || $ctx->page_level !== 'shape') {
            return '';
        }
        if (!$this->config->size_price_internal_links($ctx->site_id)) {
            return '';
        }
        $rollout_country = $this->config->size_rollout_country($ctx->site_id);
        if (strtolower($ctx->country_code) !== strtolower($rollout_country)) {
            return '';
        }
        if ($ctx->shape === null || $ctx->carat === null) {
            return '';
        }

        $size_renderer = new LDN_Size_Renderer($this->fetcher, $this->config);
        $url = $size_renderer->build_size_individual_url($ctx->site_id, $ctx->shape, $ctx->carat);
        if ($url === '') {
            return '';
        }

        $shape_label = ucwords(str_replace('-', ' ', $ctx->shape));
        $text = sprintf(
            /* translators: 1: carat weight, 2: diamond shape */
            __('View %1$s carat %2$s diamond size (mm dimensions)', 'loupe-diamond-network'),
            $this->format_carat_label($ctx->carat),
            $shape_label
        );

        return '<section class="ldn-section ldn-price-size-link"><p><a href="'
            . esc_url($url) . '">' . esc_html($text) . '</a></p></section>';
    }
}

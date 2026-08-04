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
     * American vs British spelling for the diamond color grade (US → color).
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    protected function locale_color_word(LDN_Page_Context $ctx) {
        return strtolower((string) $ctx->country_code) === 'us' ? 'color' : 'colour';
    }

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

        $display_date = $this->localised_date($date_iso);

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
     * Adjacent carat and type cross-links for all-shapes hubs.
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function all_shapes_explore_html(LDN_Page_Context $ctx) {
        if ($ctx->page_level !== 'all-shapes' || $ctx->carat === null) {
            return '';
        }

        $size_renderer = new LDN_Size_Renderer($this->fetcher, $this->config);
        $links = '';

        foreach ($size_renderer->adjacent_carat_bands($ctx->carat) as $adj) {
            $url = $this->build_price_page_url($ctx, 'all-shapes', array('carat' => $adj));
            if ($url === '') {
                continue;
            }
            $adj_label = $this->format_carat_label($adj);
            $links .= '<a class="ldn-explore-link" href="' . esc_url($url) . '">'
                . esc_html(sprintf(
                    /* translators: %s: carat weight label */
                    __('%s carat — all shapes', 'loupe-diamond-network'),
                    $adj_label
                ))
                . '</a>';
        }

        if ($ctx->diamond_type === 'natural') {
            $other_type = 'lab-grown';
            $type_label = __('Lab-grown prices at this carat', 'loupe-diamond-network');
        } elseif ($ctx->diamond_type === 'lab-grown') {
            $other_type = 'natural';
            $type_label = __('Natural prices at this carat', 'loupe-diamond-network');
        } else {
            $other_type = null;
            $type_label = '';
        }
        if ($other_type !== null) {
            $type_url = $this->build_price_page_url($ctx, 'all-shapes', array('type' => $other_type));
            if ($type_url !== '') {
                $links .= '<a class="ldn-explore-link" href="' . esc_url($type_url) . '">'
                    . esc_html($type_label) . '</a>';
            }
        }

        if ($links === '') {
            return '';
        }

        return '<section class="ldn-section ldn-all-shapes-explore">'
            . '<h2>' . esc_html__('Explore nearby weights and types', 'loupe-diamond-network') . '</h2>'
            . '<div class="ldn-explore-links">' . $links . '</div>'
            . '</section>';
    }

    /**
     * Curated drill-down links for the top-level market hub.
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function top_level_explore_html(LDN_Page_Context $ctx) {
        if ($ctx->page_level !== 'top-level') {
            return '';
        }

        $anchor_carat = '1';
        $carat_label = $this->format_carat_label($anchor_carat);
        $specs = array(
            array(
                'url'   => $this->build_price_page_url($ctx, 'diamond-type', array('type' => 'natural')),
                'label' => __('Natural diamond prices by carat', 'loupe-diamond-network'),
            ),
            array(
                'url'   => $this->build_price_page_url($ctx, 'diamond-type', array('type' => 'lab-grown')),
                'label' => __('Lab-grown diamond prices by carat', 'loupe-diamond-network'),
            ),
            array(
                'url'   => $this->build_price_page_url(
                    $ctx,
                    'all-shapes',
                    array('type' => 'natural', 'carat' => $anchor_carat)
                ),
                'label' => sprintf(
                    /* translators: %s: carat label */
                    __('%s carat natural — compare shapes', 'loupe-diamond-network'),
                    $carat_label
                ),
            ),
            array(
                'url'   => $this->build_price_page_url(
                    $ctx,
                    'all-shapes',
                    array('type' => 'lab-grown', 'carat' => $anchor_carat)
                ),
                'label' => sprintf(
                    /* translators: %s: carat label */
                    __('%s carat lab-grown — compare shapes', 'loupe-diamond-network'),
                    $carat_label
                ),
            ),
        );

        $links = '';
        foreach ($specs as $spec) {
            if ($spec['url'] === '') {
                continue;
            }
            $links .= '<a class="ldn-explore-link" href="' . esc_url($spec['url']) . '">'
                . esc_html($spec['label']) . '</a>';
        }

        if ($this->size_module_explore_eligible($ctx)) {
            $size_renderer = new LDN_Size_Renderer($this->fetcher, $this->config);
            $size_url = $size_renderer->build_size_mega_hub_url($ctx->site_id);
            if ($size_url !== '') {
                $links .= '<a class="ldn-explore-link" href="' . esc_url($size_url) . '">'
                    . esc_html__('Diamond size charts (mm dimensions)', 'loupe-diamond-network')
                    . '</a>';
            }
        }

        if ($links === '') {
            return '';
        }

        return '<section class="ldn-section ldn-top-level-explore">'
            . '<h2>' . esc_html__('Where to go next', 'loupe-diamond-network') . '</h2>'
            . '<div class="ldn-explore-links">' . $links . '</div>'
            . '</section>';
    }

    /**
     * Curated drill-down links for diamond-type hubs (natural / lab-grown).
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function diamond_type_explore_html(LDN_Page_Context $ctx) {
        if ($ctx->page_level !== 'diamond-type' || $ctx->diamond_type === null) {
            return '';
        }

        $anchor_carat = $this->hub_anchor_carat();
        $carat_label = $this->format_carat_label($anchor_carat);
        $specs = array();

        if ($ctx->diamond_type === 'natural') {
            $other_type = 'lab-grown';
            $other_label = __('Lab-grown diamond prices by carat', 'loupe-diamond-network');
        } elseif ($ctx->diamond_type === 'lab-grown') {
            $other_type = 'natural';
            $other_label = __('Natural diamond prices by carat', 'loupe-diamond-network');
        } else {
            $other_type = null;
            $other_label = '';
        }
        if ($other_type !== null) {
            $specs[] = array(
                'url'   => $this->build_price_page_url($ctx, 'diamond-type', array('type' => $other_type)),
                'label' => $other_label,
            );
        }

        $specs[] = array(
            'url'   => $this->build_price_page_url($ctx, 'top-level'),
            'label' => __('All diamond prices (natural & lab-grown)', 'loupe-diamond-network'),
        );
        $specs[] = array(
            'url'   => $this->build_price_page_url(
                $ctx,
                'all-shapes',
                array('type' => $ctx->diamond_type, 'carat' => $anchor_carat)
            ),
            'label' => sprintf(
                /* translators: %s: carat label */
                __('%s carat — compare shapes', 'loupe-diamond-network'),
                $carat_label
            ),
        );

        $links = '';
        foreach ($specs as $spec) {
            if ($spec['url'] === '') {
                continue;
            }
            $links .= '<a class="ldn-explore-link" href="' . esc_url($spec['url']) . '">'
                . esc_html($spec['label']) . '</a>';
        }

        if ($this->size_module_explore_eligible($ctx)) {
            $size_renderer = new LDN_Size_Renderer($this->fetcher, $this->config);
            $size_url = $size_renderer->build_size_mega_hub_url($ctx->site_id);
            if ($size_url !== '') {
                $links .= '<a class="ldn-explore-link" href="' . esc_url($size_url) . '">'
                    . esc_html__('Diamond size charts (mm dimensions)', 'loupe-diamond-network')
                    . '</a>';
            }
        }

        if ($links === '') {
            return '';
        }

        return '<section class="ldn-section ldn-diamond-type-explore">'
            . '<h2>' . esc_html__('Where to go next', 'loupe-diamond-network') . '</h2>'
            . '<div class="ldn-explore-links">' . $links . '</div>'
            . '</section>';
    }

    /**
     * Whether explore blocks may link into the size module for this context.
     *
     * @param LDN_Page_Context $ctx
     * @return bool
     */
    private function size_module_explore_eligible(LDN_Page_Context $ctx) {
        if (!$this->config->size_price_internal_links($ctx->site_id)) {
            return false;
        }
        $rollout_country = $this->config->size_rollout_country($ctx->site_id);
        return strtolower($ctx->country_code) === strtolower((string) $rollout_country);
    }

    /**
     * US-only size snapshot card from a pricing shape page (Decision 5).
     *
     * Mirrors the live price cards on size pages but links pricing → size with
     * median mm dimensions when size-summary.json is available.
     *
     * @param LDN_Page_Context $ctx
     * @param string|null      $currency
     * @return string
     */
    public function size_dimensions_card_html(LDN_Page_Context $ctx, $currency = null) {
        if (!$this->size_price_link_eligible($ctx)) {
            return '';
        }

        $size_renderer = new LDN_Size_Renderer($this->fetcher, $this->config);
        $url = $size_renderer->build_size_individual_url($ctx->site_id, $ctx->shape, $ctx->carat);
        if ($url === '') {
            return '';
        }

        $size_ctx = new LDN_Page_Context(
            $ctx->site_id,
            'size-individual',
            $ctx->country_code,
            null,
            $ctx->carat,
            $ctx->shape,
            'size'
        );
        $summary = $this->fetcher->fetch_artefact('size_summary_json', $size_ctx);
        $summary = is_array($summary) ? $summary : array();

        $shape_label = ucwords(str_replace('-', ' ', $ctx->shape));
        $carat_label = $this->format_carat_label($ctx->carat);
        $primary = $this->size_card_primary_dimension($size_renderer, $summary);
        $secondary = $this->size_card_secondary_line($size_renderer, $summary);

        $heading = sprintf(
            /* translators: 1: carat weight, 2: diamond shape */
            __('How big is a %1$s carat %2$s?', 'loupe-diamond-network'),
            $carat_label,
            $shape_label
        );

        $card = '<a class="ldn-price-size-card" href="' . esc_url($url) . '">';
        $card .= '<span class="ldn-price-size-card__label">' . esc_html($shape_label)
            . ' · ' . esc_html($carat_label) . ' ct</span>';
        if ($primary !== '') {
            $card .= '<span class="ldn-price-size-card__dimension">' . esc_html($primary) . '</span>';
        }
        if ($secondary !== '') {
            $card .= '<span class="ldn-price-size-card__detail">' . esc_html($secondary) . '</span>';
        }
        $card .= '<span class="ldn-price-size-card__cta">'
            . esc_html__('View mm dimensions & size chart', 'loupe-diamond-network') . ' →</span>';
        $card .= '</a>';

        return '<section class="ldn-section ldn-price-size-link"><h2>' . esc_html($heading) . '</h2>'
            . '<div class="ldn-price-size-cards">' . $card . '</div></section>';
    }

    /**
     * @param LDN_Page_Context $ctx
     * @return bool
     */
    private function size_price_link_eligible(LDN_Page_Context $ctx) {
        if ($ctx->module !== 'price' || $ctx->page_level !== 'shape') {
            return false;
        }
        if (!$this->config->size_price_internal_links($ctx->site_id)) {
            return false;
        }
        $rollout_country = $this->config->size_rollout_country($ctx->site_id);
        if (strtolower($ctx->country_code) !== strtolower((string) $rollout_country)) {
            return false;
        }
        return $ctx->shape !== null && $ctx->carat !== null;
    }

    /**
     * @param LDN_Size_Renderer $size_renderer
     * @param array             $summary
     * @return string
     */
    private function size_card_primary_dimension(LDN_Size_Renderer $size_renderer, array $summary) {
        if ($size_renderer->is_near_round($summary)) {
            $diameter = $size_renderer->average_diameter_mm($summary, 'median');
            if ($diameter !== null) {
                return sprintf(
                    /* translators: %s: diameter in millimetres */
                    __('Median diameter: %s mm', 'loupe-diamond-network'),
                    (string) $diameter
                );
            }
            return '';
        }

        $length = $this->dig_first($summary, array(array('dimensions_mm', 'length', 'median')));
        $width = $this->dig_first($summary, array(array('dimensions_mm', 'width', 'median')));
        if ($length !== null && $width !== null) {
            return sprintf(
                /* translators: 1: width mm, 2: length mm */
                __('Median size: %1$s × %2$s mm', 'loupe-diamond-network'),
                (string) $width,
                (string) $length
            );
        }
        return '';
    }

    /**
     * @param LDN_Size_Renderer $size_renderer
     * @param array             $summary
     * @return string
     */
    private function size_card_secondary_line(LDN_Size_Renderer $size_renderer, array $summary) {
        $faceup = $this->dig_first($summary, array(
            array('faceup_area_mm2', 'median'),
            array('ideal', 'faceup_area_mm2'),
        ));
        if ($faceup !== null && is_numeric($faceup)) {
            return sprintf(
                /* translators: %s: face-up area in square millimetres */
                __('Face-up area: %s mm²', 'loupe-diamond-network'),
                (string) $faceup
            );
        }

        if ($size_renderer->is_near_round($summary)) {
            $p10 = $size_renderer->average_diameter_mm($summary, 'p10');
            $p90 = $size_renderer->average_diameter_mm($summary, 'p90');
            if ($p10 !== null && $p90 !== null) {
                return sprintf(
                    /* translators: 1: low diameter mm, 2: high diameter mm */
                    __('Typical range: %1$s – %2$s mm', 'loupe-diamond-network'),
                    (string) $p10,
                    (string) $p90
                );
            }
        }

        return '';
    }
}

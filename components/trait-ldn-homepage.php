<?php
/**
 * Standalone pricing homepage sections (DPE, carat EMD).
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

trait LDN_Trait_Homepage {
    /**
     * H1 for top-level pages when profile defines homepage.h1.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $profile
     * @return string
     */
    public function homepage_headline(LDN_Page_Context $ctx, array $profile) {
        if ($ctx->page_level !== 'top-level') {
            return $this->headline($ctx, $this->country_in_content_flag($profile, 'h1_headings'));
        }

        $homepage = $this->homepage_config($profile);
        if (!empty($homepage['h1']) && is_string($homepage['h1'])) {
            return $this->interpolate_homepage_string($homepage['h1'], $ctx, $profile);
        }

        return $this->headline($ctx, $this->country_in_content_flag($profile, 'h1_headings'));
    }

    /**
     * Positioning line under the H1 (profile tagline or homepage.tagline).
     *
     * @param LDN_Page_Context $ctx
     * @param array            $profile
     * @return string
     */
    public function homepage_tagline_html(LDN_Page_Context $ctx, array $profile) {
        if ($ctx->page_level !== 'top-level') {
            return '';
        }

        $homepage = $this->homepage_config($profile);
        $raw = '';
        if (!empty($homepage['tagline']) && is_string($homepage['tagline'])) {
            $raw = $homepage['tagline'];
        } elseif (!empty($profile['tagline']) && is_string($profile['tagline'])) {
            $raw = $profile['tagline'];
        }
        $text = trim($this->interpolate_homepage_string($raw, $ctx, $profile));
        if ($text === '') {
            return '';
        }

        return '<p class="ldn-homepage-tagline">' . esc_html($text) . '</p>';
    }

    /**
     * Headline scale stats from market-overview.json.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function hub_stats_html(LDN_Page_Context $ctx, array $bag) {
        $overview = is_array($bag['market_overview']) ? $bag['market_overview'] : array();
        if (empty($overview)) {
            return '';
        }

        $cells = array();
        if (isset($overview['total_diamonds_tracked']) && is_numeric($overview['total_diamonds_tracked'])) {
            $cells[] = array(
                'label' => __('Diamonds in index', 'loupe-diamond-network'),
                'value' => $this->format_stat($overview['total_diamonds_tracked'], 'integer'),
            );
        }
        if (isset($overview['analysis_date']) && (string) $overview['analysis_date'] !== '') {
            $cells[] = array(
                'label' => __('Last updated', 'loupe-diamond-network'),
                'value' => (string) $overview['analysis_date'],
            );
        }
        if (isset($overview['natural']['total_sample_size']) && is_numeric($overview['natural']['total_sample_size'])) {
            $cells[] = array(
                'label' => __('Natural stones', 'loupe-diamond-network'),
                'value' => $this->format_stat($overview['natural']['total_sample_size'], 'integer'),
            );
        }
        if (isset($overview['lab_grown']['total_sample_size']) && is_numeric($overview['lab_grown']['total_sample_size'])) {
            $cells[] = array(
                'label' => __('Lab-grown stones', 'loupe-diamond-network'),
                'value' => $this->format_stat($overview['lab_grown']['total_sample_size'], 'integer'),
            );
        }

        if (empty($cells)) {
            return '';
        }

        return '<section class="ldn-section ldn-hub-stats" aria-label="'
            . esc_attr__('Market index scale', 'loupe-diamond-network') . '">'
            . $this->stats_grid_html($cells)
            . '</section>';
    }

    /**
     * Prominent natural / lab-grown entry links.
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function type_nav_links_html(LDN_Page_Context $ctx) {
        $links = array(
            array(
                'type'  => 'natural',
                'label' => __('Natural diamond prices', 'loupe-diamond-network'),
                'desc'  => __('Mined diamonds — median prices by carat and shape', 'loupe-diamond-network'),
            ),
            array(
                'type'  => 'lab-grown',
                'label' => __('Lab-grown diamond prices', 'loupe-diamond-network'),
                'desc'  => __('Laboratory-grown diamonds — typically lower than natural', 'loupe-diamond-network'),
            ),
        );

        $items = '';
        foreach ($links as $link) {
            $url = $this->build_price_page_url($ctx, 'diamond-type', array('type' => $link['type']));
            if ($url === '') {
                continue;
            }
            $items .= '<a class="ldn-type-nav-card" href="' . esc_url($url) . '">'
                . '<span class="ldn-type-nav-card__title">' . esc_html($link['label']) . '</span>'
                . '<span class="ldn-type-nav-card__desc">' . esc_html($link['desc']) . '</span>'
                . '</a>';
        }
        if ($items === '') {
            return '';
        }

        return '<section class="ldn-section ldn-type-nav" aria-label="'
            . esc_attr__('Browse by diamond type', 'loupe-diamond-network') . '">'
            . '<h2>' . esc_html__('Browse by type', 'loupe-diamond-network') . '</h2>'
            . '<div class="ldn-type-nav-grid">' . $items . '</div></section>';
    }

    /**
     * Single market trend chart (example graph on the hub).
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function price_trends_snapshot_html(LDN_Page_Context $ctx, array $bag) {
        $chart = $this->chart_html(
            isset($bag['market_trend_chart']) && is_array($bag['market_trend_chart'])
                ? $bag['market_trend_chart']
                : array(),
            'ldn-market-trend-chart',
            __('Natural vs lab-grown price change (%)', 'loupe-diamond-network')
        );
        if ($chart === '') {
            return '';
        }

        return '<section class="ldn-section ldn-price-trends-snapshot">'
            . '<h2>' . esc_html__('Recent price trends', 'loupe-diamond-network') . '</h2>'
            . $chart
            . '</section>';
    }

    /**
     * Popular carat ladder + top shape links for the hub.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function popular_searches_html(LDN_Page_Context $ctx, array $bag) {
        $overview = is_array($bag['market_overview']) ? $bag['market_overview'] : array();
        if (empty($overview)) {
            return '';
        }

        $currency = $this->currency_symbol(
            isset($overview['currency']) ? $overview['currency'] : $this->config->get_currency($ctx->site_id, $ctx->country_code)
        );

        $table = $this->carat_price_table_html($ctx, $overview, $currency);
        $shapes = $this->popular_shape_links_html($ctx, $bag, $currency);

        if ($table === '' && $shapes === '') {
            return '';
        }

        $out = '';
        if ($table !== '') {
            $out .= $table;
        }
        if ($shapes !== '') {
            $out .= '<section class="ldn-section ldn-popular-shapes-wrap">' . $shapes . '</section>';
        }

        return $out;
    }

    /**
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @param string           $currency
     * @return string
     */
    private function popular_shape_links_html(LDN_Page_Context $ctx, array $bag, $currency) {
        $top = is_array($bag['top_tables']) ? $bag['top_tables'] : array();
        $rows = array();
        foreach (array('natural_top', 'lab_grown_top') as $key) {
            if (!isset($top[$key]) || !is_array($top[$key])) {
                continue;
            }
            foreach ($top[$key] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rows[] = $row;
            }
        }
        if (empty($rows)) {
            return '';
        }

        usort(
            $rows,
            static function ($a, $b) {
                $sa = isset($a['sample_size']) ? (int) $a['sample_size'] : 0;
                $sb = isset($b['sample_size']) ? (int) $b['sample_size'] : 0;
                return $sb <=> $sa;
            }
        );
        $rows = array_slice($rows, 0, 8);

        $items = '';
        foreach ($rows as $row) {
            $shape = isset($row['shape']) ? (string) $row['shape'] : '';
            $carat = isset($row['carat']) ? (string) $row['carat'] : '';
            $dtype = isset($row['diamond_type']) ? (string) $row['diamond_type'] : 'natural';
            if ($shape === '' || $carat === '') {
                continue;
            }
            $url = $this->build_price_page_url(
                $ctx,
                'shape',
                array('type' => $dtype, 'carat' => $carat, 'shape' => $shape)
            );
            if ($url === '') {
                continue;
            }
            $label = ucwords(str_replace('-', ' ', $shape));
            $price = isset($row['median_price']) && is_numeric($row['median_price'])
                ? $this->format_stat($row['median_price'], 'currency', $currency)
                : '';
            $items .= '<li><a href="' . esc_url($url) . '">'
                . esc_html($this->format_carat_label($carat) . ' ct ' . $label);
            if ($price !== '') {
                $items .= ' <span class="ldn-popular-shape-price">(' . esc_html($price) . ')</span>';
            }
            $items .= '</a></li>';
        }
        if ($items === '') {
            return '';
        }

        return '<div class="ldn-popular-shapes">'
            . '<h3>' . esc_html__('Popular shapes', 'loupe-diamond-network') . '</h3>'
            . '<ul class="ldn-popular-shapes-list">' . $items . '</ul></div>';
    }

    /**
     * @param array $cells
     * @return string
     */
    private function stats_grid_html(array $cells) {
        $top = array_slice($cells, 0, 2);
        $bottom = array_slice($cells, 2, 2);
        $html = '<div class="ldn-stats">';
        foreach (array($top, $bottom) as $row) {
            if (empty($row)) {
                continue;
            }
            $html .= '<div class="ldn-stats-row">';
            foreach ($row as $cell) {
                $html .= '<div class="ldn-stat"><span class="ldn-stat-label">'
                    . esc_html($cell['label']) . '</span><span class="ldn-stat-value">'
                    . esc_html($cell['value']) . '</span></div>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * @param array $profile
     * @return array
     */
    private function homepage_config(array $profile) {
        return isset($profile['homepage']) && is_array($profile['homepage'])
            ? $profile['homepage']
            : array();
    }

    /**
     * Replace {carat}, {country_name}, {brand_name} in homepage copy.
     *
     * @param string           $template
     * @param LDN_Page_Context $ctx
     * @param array            $profile
     * @return string
     */
    private function interpolate_homepage_string($template, LDN_Page_Context $ctx, array $profile) {
        $site = $this->config->get_site($ctx->site_id);
        $site = is_array($site) ? $site : array();
        $brand = isset($site['brand_name']) ? (string) $site['brand_name'] : '';
        $carat = '';
        if (isset($site['carat_weight']) && (string) $site['carat_weight'] !== '') {
            $carat = $this->format_carat_label((string) $site['carat_weight']);
        } elseif ($ctx->carat !== null) {
            $carat = $this->format_carat_label($ctx->carat);
        }

        $replacements = array(
            '{carat}'         => $carat,
            '{country_name}'  => $this->country_full_name($ctx),
            '{brand_name}'    => $brand,
        );

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}

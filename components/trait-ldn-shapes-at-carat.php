<?php
/**
 * Shape hub component — CP53_08 (`shapes_at_carat` widget).
 *
 * Presentation is entitlement-driven: shape_cards (Ringspo, DA, BDI),
 * bar_chart_links (Loupe, DPE), table_ranked (DPG, DHUK, carat EMD top-level).
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

trait LDN_Trait_Shapes_At_Carat {
    /**
     * Dispatch the shapes-at-carat hub by entitlement presentation, or '' when
     * the widget is off or ranking data is absent.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function shapes_at_carat_html(LDN_Page_Context $ctx, array $bag) {
        $artefacts = new LDN_Artefacts($this->config);
        $page_level = (string) $ctx->page_level;
        if (!$artefacts->site_has_wp_widget($ctx->site_id, 'shapes_at_carat', $page_level)) {
            return '';
        }

        $presentation = $artefacts->wp_widget_presentation($ctx->site_id, 'shapes_at_carat', $page_level);
        if ($presentation === null || $presentation === '') {
            return '';
        }

        switch ($presentation) {
            case 'shape_cards':
                return $this->shape_cards_html($ctx, $bag);
            case 'bar_chart_links':
                return $this->shapes_at_carat_hero_html($ctx, $bag);
            case 'table_ranked':
                return $this->shapes_ranking_table_html($ctx, $bag);
            default:
                return '';
        }
    }

    /**
     * Linked shape cards grid for all-shapes hubs (Ringspo, DA, BDI).
     *
     * When ranking chart data is present, appends the bar chart below the card grid.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function shape_cards_html(LDN_Page_Context $ctx, array $bag) {
        $payload = is_array($bag['ranking']) ? $bag['ranking'] : array();
        $rows = isset($payload['shapes']) && is_array($payload['shapes']) ? $payload['shapes'] : array();
        if (empty($rows)) {
            return '';
        }

        $currency = isset($payload['currency_symbol']) ? (string) $payload['currency_symbol'] : '$';
        $carat_label = $this->format_carat_label($ctx->carat);
        $size_links = $this->shape_card_size_links_enabled($ctx);

        if (array_key_exists('change_period', $payload)) {
            $change_period = is_string($payload['change_period']) ? $payload['change_period'] : null;
            $show_change = $change_period !== null;
        } else {
            $change_period = '7_days';
            $show_change = true;
        }

        $size_renderer = $size_links ? new LDN_Size_Renderer($this->fetcher, $this->config) : null;

        $cards = '';
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['shape'])) {
                continue;
            }
            $shape = (string) $row['shape'];
            $price = isset($row['median_price']) ? $row['median_price'] : (isset($row['current_price']) ? $row['current_price'] : null);
            $change = isset($row['price_change']) ? $row['price_change'] : (isset($row['change_7d']) ? $row['change_7d'] : null);
            $url = !empty($row['page_url'])
                ? (string) $row['page_url']
                : $this->build_price_page_url($ctx, 'shape', array('shape' => $shape));
            if ($url === '') {
                continue;
            }

            $price_cell = is_numeric($price)
                ? esc_html($currency . number_format((float) $price, 0))
                : '—';

            $change_html = '';
            if ($show_change) {
                if (is_numeric($change)) {
                    $change_val = (float) $change;
                    $trend = $change_val > 0 ? 'up' : ($change_val < 0 ? 'down' : 'flat');
                    $glyph = $change_val > 0 ? "\xE2\x96\xB2 " : ($change_val < 0 ? "\xE2\x96\xBC " : '');
                    $change_html = '<span class="ldn-shape-card__change ldn-shape-card__change--'
                        . esc_attr($trend) . '">'
                        . esc_html($glyph . number_format(abs($change_val), 2) . '%')
                        . '</span>';
                } else {
                    $change_html = '<span class="ldn-shape-card__change">—</span>';
                }
            }

            $card_heading = $shape;
            if (isset($row['rank']) && is_numeric($row['rank'])) {
                $card_heading = sprintf(
                    /* translators: 1: rank position, 2: shape name */
                    __('#%1$d: %2$s', 'loupe-diamond-network'),
                    (int) $row['rank'],
                    $shape
                );
            }

            $sample_html = '';
            if (isset($row['sample_size']) && is_numeric($row['sample_size']) && (int) $row['sample_size'] > 0) {
                $sample_html = '<span class="ldn-shape-card__sample">'
                    . esc_html(sprintf(
                        /* translators: %s: formatted integer count */
                        __('%s diamonds', 'loupe-diamond-network'),
                        number_format((int) $row['sample_size'])
                    ))
                    . '</span>';
            }

            $range_html = '';
            $price_min = isset($row['price_min']) ? $row['price_min'] : null;
            $price_max = isset($row['price_max']) ? $row['price_max'] : null;
            if (is_numeric($price_min) && is_numeric($price_max) && (float) $price_max > 0) {
                $range_html = '<span class="ldn-shape-card__range">'
                    . esc_html(
                        $currency . number_format((float) $price_min, 0)
                        . ' - ' . $currency . number_format((float) $price_max, 0)
                    )
                    . '</span>';
            }

            $size_link_html = '';
            $card_class = 'ldn-shape-card';
            if ($size_renderer !== null) {
                $size_url = $size_renderer->build_size_shape_hub_url(
                    $ctx->site_id,
                    $this->config->shape_to_s3_slug($shape)
                );
                if ($size_url !== '') {
                    $card_class .= ' ldn-shape-card--has-size';
                    $size_link_html = '<a class="ldn-shape-card__size-link" href="'
                        . esc_url($size_url) . '">'
                        . esc_html__('Size chart', 'loupe-diamond-network') . ' →</a>';
                }
            }

            $cards .= '<div class="' . esc_attr($card_class) . '">'
                . '<a class="ldn-shape-card__main" href="' . esc_url($url) . '">'
                . '<span class="ldn-shape-card__shape">' . esc_html($card_heading) . '</span>'
                . $sample_html
                . $range_html
                . '<span class="ldn-shape-card__price">' . $price_cell . '</span>'
                . $change_html
                . '</a>'
                . $size_link_html
                . '</div>';
        }

        if ($cards === '') {
            return '';
        }

        $heading = sprintf(
            /* translators: %s: carat label */
            __('%s carat diamonds by shape', 'loupe-diamond-network'),
            $carat_label !== '' ? $carat_label : '1'
        );

        $change_caption = '';
        if ($show_change) {
            $change_caption = '<p class="ldn-shape-cards__hint">'
                . esc_html(sprintf(
                    /* translators: %s: change period adjective, e.g. "1-month" */
                    __('%s price change shown on each card.', 'loupe-diamond-network'),
                    $this->change_period_short_label($change_period)
                ))
                . '</p>';
        }

        $banner = $this->partial_coverage_banner_html($ctx, $bag);

        $section = '<section class="ldn-section ldn-shape-cards">'
            . $banner
            . '<h2>' . esc_html($heading) . '</h2>'
            . '<p class="ldn-shape-cards__hint">'
            . esc_html__('Select a shape to see detailed pricing for that cut.', 'loupe-diamond-network')
            . '</p>'
            . $change_caption
            . '<div class="ldn-shape-cards__grid">' . $cards . '</div>'
            . '</section>';

        $type_label = isset(self::$TYPE_LABELS[$ctx->diamond_type])
            ? self::$TYPE_LABELS[$ctx->diamond_type]
            : ucwords(str_replace('-', ' ', (string) $ctx->diamond_type));
        $chart_fallback_title = sprintf(
            /* translators: 1: carat label, 2: diamond type label, 3: country name */
            __('%1$s Carat %2$s Diamond Prices by Shape — %3$s', 'loupe-diamond-network'),
            $carat_label !== '' ? $carat_label : '1',
            $type_label,
            $this->country_full_name($ctx)
        );

        $chart = $this->chart_html(
            isset($bag['ranking_chart']) && is_array($bag['ranking_chart']) ? $bag['ranking_chart'] : array(),
            'ldn-shapes-ranking-chart',
            $chart_fallback_title
        );

        return $section . $chart;
    }

    /**
     * Whether shape cards may show a size-chart affordance for this context.
     *
     * @param LDN_Page_Context $ctx
     * @return bool
     */
    private function shape_card_size_links_enabled(LDN_Page_Context $ctx) {
        if (!$this->config->size_price_internal_links($ctx->site_id)) {
            return false;
        }
        $rollout_country = $this->config->size_rollout_country($ctx->site_id);
        return strtolower($ctx->country_code) === strtolower((string) $rollout_country);
    }
}

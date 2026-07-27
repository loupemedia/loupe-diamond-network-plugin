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
        $country_name = $this->country_full_name($ctx);

        if (array_key_exists('change_period', $payload)) {
            $change_period = is_string($payload['change_period']) ? $payload['change_period'] : null;
            $show_change = $change_period !== null;
        } else {
            $change_period = '7_days';
            $show_change = true;
        }

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

            $cards .= '<a class="ldn-shape-card" href="' . esc_url($url) . '">'
                . '<span class="ldn-shape-card__shape">' . esc_html($shape) . '</span>'
                . '<span class="ldn-shape-card__price">' . $price_cell . '</span>'
                . $change_html
                . '</a>';
        }

        if ($cards === '') {
            return '';
        }

        $heading = sprintf(
            /* translators: 1: carat label, 2: country name */
            __('%1$s carat diamond prices in %2$s by shape', 'loupe-diamond-network'),
            $carat_label !== '' ? $carat_label : '1',
            $country_name
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

        return '<section class="ldn-section ldn-shape-cards">'
            . '<h2>' . esc_html($heading) . '</h2>'
            . '<p class="ldn-shape-cards__hint">'
            . esc_html__('Select a shape to see detailed pricing for that cut.', 'loupe-diamond-network')
            . '</p>'
            . $change_caption
            . '<div class="ldn-shape-cards__grid">' . $cards . '</div>'
            . '</section>';
    }
}

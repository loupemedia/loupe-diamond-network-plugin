<?php
/**
 * Renderer component trait — split from class-ldn-renderer.php (CP53).
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

trait LDN_Trait_Sections {
    /**
     * Render the hero component for the page, or '' when none/unsupported.
     *
     * @param string|null      $hero
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @param string|null      $currency ISO code, for the chart's text fallback.
     * @return string
     */
    private function render_hero($hero, LDN_Page_Context $ctx, array $bag, $currency = null) {
        switch ($hero) {
            case 'distribution_chart':
                return $this->chart_html(
                    $bag['dist'],
                    'ldn-distribution-chart',
                    __('Price distribution', 'loupe-diamond-network'),
                    $this->chart_fallback_text($ctx, $bag, $currency)
                );
            case 'price_graph':
            case 'price_chart':
                return $this->chart_html(
                    $bag['price'],
                    'ldn-price-chart',
                    __('Price over time', 'loupe-diamond-network'),
                    $this->chart_fallback_text($ctx, $bag, $currency)
                );
            case 'table_chart':
                return $this->shapes_at_carat_html($ctx, $bag);
            case 'bar_chart':
                return $this->shapes_at_carat_html($ctx, $bag);
            case 'comparison_chart':
                $type_label = isset(self::$TYPE_LABELS[$ctx->diamond_type])
                    ? self::$TYPE_LABELS[$ctx->diamond_type]
                    : ucfirst(str_replace('-', ' ', $ctx->diamond_type));
                return $this->carat_tiers_table_html(
                    $ctx,
                    $bag,
                    sprintf(
                        /* translators: %s: diamond type label (Natural / Lab-Grown) */
                        __('%s diamond prices by carat weight', 'loupe-diamond-network'),
                        $type_label
                    )
                );
            case 'summary_cards':
            case 'summary_table':
            case 'market_overview':
                return $this->market_overview_table_html($ctx, $bag);
            case 'carat_showcase':
                return $this->shapes_at_carat_hero_html($ctx, $bag);
            default:
                return '';
        }
    }

    /**
     * Text fallback for a summary-backed chart (CP54_04), or '' when the page has
     * no summary payload to describe.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @param string|null      $currency
     * @return string
     */
    private function chart_fallback_text(LDN_Page_Context $ctx, array $bag, $currency = null) {
        $summary = isset($bag['summary']) && is_array($bag['summary']) ? $bag['summary'] : array();
        if (empty($summary)) {
            return '';
        }
        return $this->data_summary_text($ctx, $summary, $currency);
    }

    /**
     * Render a single section by id, or '' when unmapped / not entitled / empty.
     *
     * Public so the section-routing contract (which ids render vs are skipped)
     * is directly unit-testable.
     *
     * @param string           $section_id
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function render_section($section_id, LDN_Page_Context $ctx, array $bag, $currency = null) {
        if (in_array($section_id, self::SUPPRESSED_SECTIONS, true)) {
            return '';
        }

        if ($section_id === 'price_graph') {
            return $this->chart_html(
                $bag['price'],
                'ldn-price-chart',
                __('Price over time', 'loupe-diamond-network'),
                $this->chart_fallback_text($ctx, $bag, $currency)
            );
        }
        if ($section_id === 'faq_static') {
            return $this->faq_html($this->section_value($section_id, $ctx, $bag));
        }
        if ($section_id === 'intro_dynamic') {
            return $this->intro_html(
                $ctx,
                is_array($bag['summary']) ? $bag['summary'] : array(),
                $currency
            );
        }
        if ($section_id === 'carat_ladder') {
            return $this->carat_ladder_html($ctx, $bag, $currency);
        }
        if ($section_id === 'color_clarity') {
            return $this->color_clarity_table_html(
                $ctx,
                isset($bag['color_clarity']) ? $bag['color_clarity'] : array(),
                $currency
            );
        }
        if ($section_id === 'size_explore_link') {
            return $this->size_explore_link_html($ctx, $currency);
        }
        if ($section_id === 'buying_advice_static') {
            return $this->text_block(
                $section_id,
                $this->buying_advice_value($ctx, $bag)
            );
        }
        if ($section_id === 'hub_stats') {
            return $this->hub_stats_html($ctx, $bag);
        }
        if ($section_id === 'data_methodology') {
            return $this->data_methodology_html($ctx, $bag);
        }
        if ($section_id === 'type_nav_links') {
            return $this->type_nav_links_html($ctx, $bag);
        }
        if ($section_id === 'all_shapes_explore') {
            return $this->all_shapes_explore_html($ctx);
        }
        if ($section_id === 'top_level_explore') {
            return $this->top_level_explore_html($ctx);
        }
        if ($section_id === 'diamond_type_explore') {
            return $this->diamond_type_explore_html($ctx, $bag);
        }
        if ($section_id === 'market_overview_table' || $section_id === 'carat_price_table') {
            return $this->market_overview_table_html($ctx, $bag);
        }
        if ($section_id === 'most_traded_table') {
            return $this->most_traded_table_html($ctx, $bag);
        }
        if ($section_id === 'price_calculator') {
            if ($ctx->page_level === 'all-shapes') {
                return $this->all_shapes_calculator_html($ctx, $bag);
            }
            return $this->price_calculator_html($ctx, $bag);
        }
        if ($section_id === 'price_per_carat_chart') {
            return $this->price_per_carat_chart_html($ctx, $bag);
        }
        if ($section_id === 'comparison_chart') {
            $type_label = isset(self::$TYPE_LABELS[$ctx->diamond_type])
                ? self::$TYPE_LABELS[$ctx->diamond_type]
                : ucfirst(str_replace('-', ' ', (string) $ctx->diamond_type));
            return $this->carat_tiers_table_html(
                $ctx,
                $bag,
                sprintf(
                    /* translators: %s: diamond type label (Natural / Lab-Grown) */
                    __('%s diamond prices by carat weight', 'loupe-diamond-network'),
                    $type_label
                )
            );
        }
        if ($section_id === 'price_trends_snapshot') {
            return $this->price_trends_snapshot_html($ctx, $bag);
        }
        if ($section_id === 'popular_searches') {
            return $this->popular_searches_html($ctx, $bag);
        }
        if ($section_id === 'shapes_at_carat') {
            return $this->shapes_at_carat_html($ctx, $bag);
        }
        if (substr($section_id, -8) === '_dynamic') {
            if ($ctx->page_level !== 'shape') {
                // Skip body re-render when the same copy already led the hero band.
                if ($section_id === 'market_overview_dynamic' && $this->market_intro_in_hero) {
                    return '';
                }
                if ($section_id === 'type_overview_dynamic' && $this->type_intro_in_hero) {
                    return '';
                }
                $dynamic = $this->copy_dynamic_html($section_id, $ctx, $bag);
                if ($dynamic !== '') {
                    if ($section_id === 'overview_intro_dynamic' && $ctx->page_level === 'all-shapes') {
                        $dynamic .= $this->stats_html(
                            $ctx,
                            is_array($bag['summary']) ? $bag['summary'] : array(),
                            $currency
                        );
                    }
                    return $dynamic;
                }
                if ($section_id === 'type_overview_dynamic') {
                    return $this->type_intro_html($ctx, $bag, $currency);
                }
            }
            return $this->stats_html($ctx, is_array($bag['summary']) ? $bag['summary'] : array(), $currency);
        }
        if (substr($section_id, -7) === '_static'
            || in_array($section_id, self::EDITORIAL_STATIC_SECTIONS, true)
        ) {
            return $this->text_block($section_id, $this->section_value($section_id, $ctx, $bag));
        }

        // Unknown section id: skipped (forward-compatible with future archetypes).
        return '';
    }

    /**
     * Resolve a static/dynamic section's content value from the fetched copy
     * payloads, keyed by the profile's `section_prompts.{id}.json_key`.
     *
     * @param string           $section_id
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return mixed scalar | array | null
     */
    private function section_value($section_id, LDN_Page_Context $ctx, array $bag) {
        $profile = $this->profile($ctx);
        $prompts = isset($profile['section_prompts']) && is_array($profile['section_prompts'])
            ? $profile['section_prompts']
            : array();
        $json_key = isset($prompts[$section_id]['json_key'])
            ? (string) $prompts[$section_id]['json_key']
            : preg_replace('/_(static|dynamic)$/', '', $section_id);

        // Copy payloads come in two shapes: flat ({json_key: ...}) or nested
        // under a `sections` wrapper (the live C1 static-content.json contract).
        foreach (array('static', 'individual') as $src) {
            $raw = isset($bag[$src]) ? $bag[$src] : null;
            $payload = is_array($raw) ? $raw : array();
            if (isset($payload[$json_key])) {
                return $payload[$json_key];
            }
            if (isset($payload['sections'][$json_key])) {
                return $payload['sections'][$json_key];
            }
        }
        return null;
    }

    /**
     * Merged buying advice: prefers the new `buying_advice` C1 key and falls
     * back to concatenating legacy `expert_take` + `buying_guidance` until C1
     * regenerates static content for the site.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return mixed scalar | null
     */
    private function buying_advice_value(LDN_Page_Context $ctx, array $bag) {
        $direct = $this->section_value('buying_advice_static', $ctx, $bag);
        if (is_scalar($direct) && (string) $direct !== '') {
            return $direct;
        }

        $parts = array();
        foreach (array('expert_take', 'buying_guidance') as $legacy_key) {
            $raw = isset($bag['static']) && is_array($bag['static']) ? $bag['static'] : array();
            $payload = is_array($raw) ? $raw : array();
            $value = null;
            if (isset($payload[$legacy_key])) {
                $value = $payload[$legacy_key];
            } elseif (isset($payload['sections'][$legacy_key])) {
                $value = $payload['sections'][$legacy_key];
            }
            if (is_array($value)) {
                $value = implode("\n\n", array_filter($value, 'is_scalar'));
            }
            if (is_scalar($value) && (string) $value !== '') {
                $parts[] = (string) $value;
            }
        }

        return $parts === array() ? null : implode("\n\n", $parts);
    }
}

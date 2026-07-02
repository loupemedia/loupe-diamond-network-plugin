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
     * @return string
     */
    private function render_hero($hero, LDN_Page_Context $ctx, array $bag) {
        switch ($hero) {
            case 'distribution_chart':
                return $this->chart_html($bag['dist'], 'ldn-distribution-chart', __('Price distribution', 'loupe-diamond-network'));
            case 'price_graph':
            case 'price_chart':
                return $this->chart_html($bag['price'], 'ldn-price-chart', __('Price over time', 'loupe-diamond-network'));
            case 'table_chart':
                return $this->shapes_at_carat_hero_html($ctx, $bag);
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
            return $this->chart_html($bag['price'], 'ldn-price-chart', __('Price over time', 'loupe-diamond-network'));
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
        if ($section_id === 'hub_stats') {
            return $this->hub_stats_html($ctx, $bag);
        }
        if ($section_id === 'type_nav_links') {
            return $this->type_nav_links_html($ctx);
        }
        if ($section_id === 'price_trends_snapshot') {
            return $this->price_trends_snapshot_html($ctx, $bag);
        }
        if ($section_id === 'popular_searches') {
            return $this->popular_searches_html($ctx, $bag);
        }
        if (substr($section_id, -8) === '_dynamic') {
            if ($ctx->page_level !== 'shape') {
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
            $payload = is_array($bag[$src]) ? $bag[$src] : array();
            if (isset($payload[$json_key])) {
                return $payload[$json_key];
            }
            if (isset($payload['sections'][$json_key])) {
                return $payload['sections'][$json_key];
            }
        }
        return null;
    }
}

<?php
/**
 * Renderer data helpers — prefetch, profile, headline, currency (CP53).
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

trait LDN_Trait_Data {
    /**
     * Human-readable H1, e.g. "1 Carat Round Natural Diamond Prices (US)".
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function headline(LDN_Page_Context $ctx, $include_country = true) {
        $parts = array();
        if ($ctx->carat !== null) {
            $parts[] = $ctx->carat . ' Carat';
        }
        if ($ctx->shape !== null) {
            $parts[] = ucwords(str_replace('-', ' ', $ctx->shape));
        }
        if ($ctx->diamond_type !== null) {
            $parts[] = isset(self::$TYPE_LABELS[$ctx->diamond_type])
                ? self::$TYPE_LABELS[$ctx->diamond_type]
                : ucwords(str_replace('-', ' ', $ctx->diamond_type));
        }
        $subject = trim(implode(' ', $parts));
        $suffix = $include_country ? sprintf(' (%s)', strtoupper($ctx->country_code)) : '';
        if ($subject === '') {
            // Ringspo top-level hub: "{Country} Diamond Prices" (full name), not a bare
            // "Diamond Prices" / "(US)" suffix — matches the H1/title SEO brief.
            if ($ctx->page_level === 'top-level' && $ctx->site_id === 'ringspo') {
                return sprintf('%s Diamond Prices', $this->country_full_name($ctx));
            }
            return 'Diamond Prices' . $suffix;
        }
        // Ringspo diamond-type hubs: "{Country} Natural Diamond Prices" (full name).
        if ($ctx->page_level === 'diamond-type' && $ctx->site_id === 'ringspo') {
            return sprintf('%s %s Diamond Prices', $this->country_full_name($ctx), $subject);
        }
        // Ringspo all-shapes: own "by shape" + full country — differentiate from the
        // type hub (no carat) and shape pages (named cut + (CC) suffix).
        if ($ctx->page_level === 'all-shapes' && $ctx->site_id === 'ringspo') {
            return sprintf(
                '%s Diamond Prices by Shape — %s',
                $subject,
                $this->country_full_name($ctx)
            );
        }
        return sprintf('%s Diamond Prices%s', $subject, $suffix);
    }

    /**
     * @param array             $profile
     * @param string            $key
     * @param bool              $default
     * @param LDN_Page_Context|null $ctx When set, honour country_overrides for this key.
     * @return bool
     */
    private function country_in_content_flag(array $profile, $key, $default = true, $ctx = null) {
        $cic = isset($profile['country_in_content']) && is_array($profile['country_in_content'])
            ? $profile['country_in_content']
            : array();
        if ($ctx instanceof LDN_Page_Context) {
            $overrides = isset($cic['country_overrides']) && is_array($cic['country_overrides'])
                ? $cic['country_overrides']
                : array();
            $code = strtolower((string) $ctx->country_code);
            if (isset($overrides[$code]) && is_array($overrides[$code])
                && array_key_exists($key, $overrides[$code])
            ) {
                return (bool) $overrides[$code][$key];
            }
        }
        return array_key_exists($key, $cic) ? (bool) $cic[$key] : $default;
    }

    /**
     * @param mixed       $value
     * @param string      $format
     * @param string|null $currency
     * @return string
     */
    public function format_stat($value, $format, $currency = null) {
        switch ($format) {
            case 'currency':
                return $this->currency_symbol($currency) . number_format((float) $value, 0);
            case 'integer':
                return number_format((int) $value);
            case 'percent':
                return sprintf('%+.1f%%', (float) $value);
            default:
                return (string) $value;
        }
    }

    /**
     * Render an ISO date (YYYY-MM-DD) in the site's configured display format.
     *
     * Returns the input unchanged when WordPress date functions are unavailable
     * or the value is unparseable, so callers always get something printable.
     * The timestamp is built at midday to stop a timezone offset rolling the
     * date back to the previous day.
     *
     * @param string $date_iso
     * @return string
     */
    private function localised_date($date_iso) {
        if (!is_string($date_iso) || $date_iso === '' || !function_exists('date_i18n')) {
            return is_string($date_iso) ? $date_iso : '';
        }
        $ts = strtotime($date_iso . 'T12:00:00');
        if ($ts === false) {
            return $date_iso;
        }
        return date_i18n(get_option('date_format'), $ts);
    }

    /**
     * @param string|null $currency
     * @return string
     */
    private function currency_symbol($currency) {
        if ($currency === null || $currency === '') {
            return '';
        }
        $code = strtoupper((string) $currency);
        return isset(self::CURRENCY_SYMBOLS[$code]) ? self::CURRENCY_SYMBOLS[$code] : $code . ' ';
    }

    /**
     * @param array $arr
     * @param array $paths
     * @return mixed|null
     */
    private function dig_first(array $arr, array $paths) {
        foreach ($paths as $path) {
            $cursor = $arr;
            $ok = true;
            foreach ($path as $segment) {
                if (is_array($cursor) && array_key_exists($segment, $cursor)) {
                    $cursor = $cursor[$segment];
                } else {
                    $ok = false;
                    break;
                }
            }
            if ($ok && $cursor !== null) {
                return $cursor;
            }
        }
        return null;
    }

    /**
     * @param LDN_Page_Context $ctx
     * @return array<string, mixed>
     */
    private function prefetch(LDN_Page_Context $ctx) {
        $bag = array(
            'summary'    => $this->fetcher->fetch_artefact('summary_data_json', $ctx),
            'static'     => $this->fetcher->fetch_artefact('static_content_json', $ctx),
            'copy'       => $this->fetcher->fetch_artefact('templated_copy_json', $ctx),
            'individual' => null,
        );

        switch ($ctx->page_level) {
            case 'shape':
                $bag['price'] = $this->fetcher->fetch_artefact('price_graph_json', $ctx);
                $bag['dist'] = $this->fetcher->fetch_artefact('distribution_json', $ctx);
                $bag['individual'] = $this->fetcher->fetch_artefact('individual_content_json', $ctx);
                $bag['carat_ladder'] = $this->fetcher->fetch_artefact('carat_ladder_json', $ctx);
                $bag['carat_ladder_chart'] = $this->fetcher->fetch_artefact('carat_ladder_chart', $ctx);
                $bag['color_clarity'] = $this->fetcher->fetch_artefact('color_clarity_json', $ctx);
                $bag['price_calculator'] = $this->fetcher->fetch_artefact('price_calculator_json', $ctx);
                break;
            case 'all-shapes':
                $bag['ranking'] = $this->fetcher->fetch_artefact('shapes_ranking_json', $ctx);
                $bag['ranking_chart'] = $this->fetcher->fetch_artefact('shapes_at_carat_chart', $ctx);
                $all_shapes_summary = $this->fetcher->fetch_artefact('all_shapes_summary_json', $ctx);
                if (is_array($all_shapes_summary) && !empty($all_shapes_summary)) {
                    $bag['summary'] = $all_shapes_summary;
                }
                break;
            case 'diamond-type':
                $bag['type_summary'] = $this->fetcher->fetch_artefact('type_summary_json', $ctx);
                $bag['price_per_carat_chart'] = $this->fetcher->fetch_artefact(
                    'type_price_per_carat_chart',
                    $ctx
                );
                $bag['type_carat_history_chart'] = $this->fetcher->fetch_artefact(
                    'type_carat_history_chart',
                    $ctx
                );
                if (!is_array($bag['summary']) || empty($bag['summary'])) {
                    $bag['summary'] = is_array($bag['type_summary']) ? $bag['type_summary'] : array();
                }
                break;
            case 'top-level':
                $bag['market_overview'] = $this->fetcher->fetch_artefact('market_overview_json', $ctx);
                $bag['top_tables'] = $this->fetcher->fetch_artefact('top_tables_json', $ctx);
                $bag['market_discount_chart'] = $this->fetcher->fetch_artefact('market_discount_chart', $ctx);
                $bag['market_trend_chart'] = $this->fetcher->fetch_artefact('market_trend_chart', $ctx);
                $bag['ranking'] = $this->fetcher->fetch_artefact('shapes_ranking_json', $ctx);
                $bag['ranking_chart'] = $this->fetcher->fetch_artefact('shapes_at_carat_chart', $ctx);
                if (!is_array($bag['summary']) || empty($bag['summary'])) {
                    $bag['summary'] = is_array($bag['market_overview']) ? $bag['market_overview'] : array();
                }
                break;
        }

        return $bag;
    }

    /**
     * @param LDN_Page_Context $ctx
     * @return array
     */
    private function profile(LDN_Page_Context $ctx) {
        $profile = $this->config->get_content_profile($ctx->site_id);
        return is_array($profile) ? $profile : array();
    }
}

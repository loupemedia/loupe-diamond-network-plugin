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
            return 'Diamond Prices' . $suffix;
        }
        return sprintf('%s Diamond Prices%s', $subject, $suffix);
    }

    /**
     * @param array  $profile
     * @param string $key
     * @param bool   $default
     * @return bool
     */
    private function country_in_content_flag(array $profile, $key, $default = true) {
        $cic = isset($profile['country_in_content']) && is_array($profile['country_in_content'])
            ? $profile['country_in_content']
            : array();
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
            'summary' => $this->fetcher->fetch_artefact('summary_data_json', $ctx),
            'static'  => $this->fetcher->fetch_artefact('static_content_json', $ctx),
            'copy'    => $this->fetcher->fetch_artefact('templated_copy_json', $ctx),
        );

        switch ($ctx->page_level) {
            case 'shape':
                $bag['price'] = $this->fetcher->fetch_artefact('price_graph_json', $ctx);
                $bag['dist'] = $this->fetcher->fetch_artefact('distribution_json', $ctx);
                $bag['individual'] = $this->fetcher->fetch_artefact('individual_content_json', $ctx);
                $bag['carat_ladder'] = $this->fetcher->fetch_artefact('carat_ladder_json', $ctx);
                $bag['carat_ladder_chart'] = $this->fetcher->fetch_artefact('carat_ladder_chart', $ctx);
                $bag['color_clarity'] = $this->fetcher->fetch_artefact('color_clarity_json', $ctx);
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

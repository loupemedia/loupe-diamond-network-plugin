<?php
/**
 * Renderer component trait — split from class-ldn-renderer.php (CP53).
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

trait LDN_Trait_Url {
    /**
     * @param string      $site_id
     * @param string|null $canonical_type natural|lab-grown
     * @return string
     */
    private function type_url_slug($site_id, $canonical_type) {
        $structure = $this->config->get_url_structure($site_id);
        if (!is_array($structure)) {
            return (string) $canonical_type;
        }
        if ($canonical_type === 'lab-grown' && !empty($structure['type_lab'])) {
            return (string) $structure['type_lab'];
        }
        if ($canonical_type === 'natural' && !empty($structure['type_natural'])) {
            return (string) $structure['type_natural'];
        }
        return (string) $canonical_type;
    }

    /**
     * @param string      $site_id
     * @param string|null $carat_value Numeric carat label.
     * @return string
     */
    private function format_carat_slug($site_id, $carat_value) {
        if ($carat_value === null || $carat_value === '') {
            return '';
        }
        $structure = $this->config->get_url_structure($site_id);
        $format = (is_array($structure) && array_key_exists('carat_format', $structure))
            ? $structure['carat_format']
            : '{value}-carat';
        if ($format === null) {
            return '';
        }
        return str_replace('{value}', $this->format_carat_label($carat_value), (string) $format);
    }

    /**
     * Resolve a price-change policy from the content profile's templated_copy
     * block for a given page level (e.g. "individual_shape", "all_shapes").
     *
     * @param LDN_Page_Context $ctx
     * @param string           $level_key templated_copy sub-key.
     * @return array{period: string|null, show_change: bool}
     */
    private function change_policy(LDN_Page_Context $ctx, $level_key) {
        $profile = $this->profile($ctx);
        $policy = isset($profile['templated_copy'][$level_key])
            && is_array($profile['templated_copy'][$level_key])
            ? $profile['templated_copy'][$level_key]
            : null;

        if ($policy === null) {
            // No policy: preserve legacy 7-day behaviour.
            return array('period' => '7_days', 'show_change' => true);
        }

        $period = (isset($policy['intro_change_period']) && is_string($policy['intro_change_period']))
            ? $policy['intro_change_period']
            : null;

        if (array_key_exists('show_change', $policy)) {
            $show_change = (bool) $policy['show_change'];
        } else {
            $show_change = $period !== null;
        }

        return array('period' => $period, 'show_change' => $show_change);
    }

    /**
     * Shape-page price-change policy (templated_copy.individual_shape).
     *
     * @param LDN_Page_Context $ctx
     * @return array{period: string|null, show_change: bool}
     */
    private function shape_change_policy(LDN_Page_Context $ctx) {
        return $this->change_policy($ctx, 'individual_shape');
    }

    /**
     * Human phrasing for a price-change period label, e.g. "over the last 12 months".
     *
     * @param string|null $period Period label (e.g. "12_months").
     * @return string
     */
    private function change_period_phrase($period) {
        $map = array(
            '7_days'    => 'over the last 7 days',
            '14_days'   => 'over the last 14 days',
            '21_days'   => 'over the last 21 days',
            '30_days'   => 'over the last 30 days',
            '90_days'   => 'over the last 90 days',
            '365_days'  => 'over the last year',
            '1_month'   => 'over the last month',
            '3_months'  => 'over the last 3 months',
            '6_months'  => 'over the last 6 months',
            '12_months' => 'over the last 12 months',
            '24_months' => 'over the last 2 years',
        );
        if ($period !== null && isset($map[$period])) {
            return $map[$period];
        }
        return 'over the last 7 days';
    }

    /**
     * Short adjective for a price-change period label, e.g. "12-month" — used in
     * table column headers.
     *
     * @param string|null $period Period label (e.g. "12_months").
     * @return string
     */
    private function change_period_short_label($period) {
        $map = array(
            '7_days'    => '7-day',
            '14_days'   => '14-day',
            '21_days'   => '21-day',
            '30_days'   => '30-day',
            '90_days'   => '90-day',
            '365_days'  => '12-month',
            '1_month'   => '1-month',
            '3_months'  => '3-month',
            '6_months'  => '6-month',
            '12_months' => '12-month',
            '24_months' => '24-month',
        );
        if ($period !== null && isset($map[$period])) {
            return $map[$period];
        }
        return '7-day';
    }

    /**
     * Plain subject phrase for descriptions, e.g. "1 carat round natural".
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    private function plain_subject(LDN_Page_Context $ctx) {
        $parts = array_filter(array(
            $ctx->carat !== null ? $ctx->carat . ' carat' : null,
            $ctx->shape,
            $ctx->diamond_type,
        ), 'strlen');
        return $parts ? implode(' ', $parts) : 'diamond';
    }

    /**
     * Human country name from the site config countries list.
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    private function country_full_name(LDN_Page_Context $ctx) {
        $site = $this->config->get_site($ctx->site_id);
        if (!is_array($site) || empty($site['countries']) || !is_array($site['countries'])) {
            return strtoupper($ctx->country_code);
        }
        foreach ($site['countries'] as $entry) {
            if (is_array($entry) && isset($entry['code']) && $entry['code'] === $ctx->country_code) {
                return isset($entry['full_name'])
                    ? (string) $entry['full_name']
                    : strtoupper($ctx->country_code);
            }
        }
        return strtoupper($ctx->country_code);
    }

    /**
     * Display carat label (drops trailing zeros for whole weights).
     *
     * @param string|null $carat
     * @return string
     */
    private function format_carat_label($carat) {
        if ($carat === null || $carat === '') {
            return '';
        }
        $value = (float) $carat;
        if ($value === (float) (int) $value) {
            return (string) (int) $value;
        }
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * Best-effort current absolute URL (canonical), or '' when unavailable.
     *
     * @return string
     */
    private function current_url() {
        if (isset($GLOBALS['wp']) && isset($GLOBALS['wp']->request) && $GLOBALS['wp']->request !== '') {
            return home_url(user_trailingslashit($GLOBALS['wp']->request));
        }
        return '';
    }
}

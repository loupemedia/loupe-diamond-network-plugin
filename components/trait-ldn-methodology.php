<?php
/**
 * Data methodology component: "About this data" (`data_methodology` widget).
 *
 * Closes every pricing level with what the number is, how many diamonds are
 * behind it, and when it was measured. The statistic line is per page level
 * because the levels do not publish the same statistic: a shape page carries a
 * true median over stone prices (C3 p50), while every level above it combines
 * per-shape medians weighted by sample size.
 *
 * Retailers are never named and the size of the pool is never stated. The
 * population is described instead. This mirrors LDN_Size_Renderer, which
 * withholds even the retailer count below RETAILER_DISCLOSURE_THRESHOLD (15).
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

trait LDN_Trait_Methodology {
    /**
     * Page levels whose copy refers to a single carat weight, so the
     * approximate-weight explanation is relevant to the reader.
     */
    private static $METHODOLOGY_CARAT_LEVELS = array('shape', 'all-shapes');

    /**
     * Page levels that render a multi-weight table, where the thin-sample note
     * has a column to point at.
     */
    private static $METHODOLOGY_TABLE_LEVELS = array('diamond-type', 'top-level');

    /**
     * Prominent notice when C5.1 reported incomplete shape coverage (staging
     * test combos, partial pipeline runs). Renders above the shape hub when the
     * aggregate explicitly sets coverage_complete to false.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function partial_coverage_banner_html(LDN_Page_Context $ctx, array $bag) {
        if (!$this->methodology_coverage_is_partial($bag)) {
            return '';
        }
        $copy = $this->methodology_copy($ctx);
        if (empty($copy['partial_coverage_note'])) {
            return '';
        }
        $values = $this->methodology_values($ctx, $bag);
        $text = trim(preg_replace(
            '/\s+/',
            ' ',
            $this->fill_methodology_tokens((string) $copy['partial_coverage_note'], $values)
        ));
        if ($text === '') {
            return '';
        }
        return '<aside class="ldn-partial-coverage" role="note"><p>' . esc_html($text) . '</p></aside>';
    }

    /**
     * "About this data" block, or '' when the widget is off for this site/level
     * or the profile carries no methodology copy.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function data_methodology_html(LDN_Page_Context $ctx, array $bag) {
        $artefacts = new LDN_Artefacts($this->config);
        $page_level = (string) $ctx->page_level;
        if (!$artefacts->site_has_wp_widget($ctx->site_id, 'data_methodology', $page_level)) {
            return '';
        }

        // site_has_wp_widget() returns true for any array value without
        // `enabled: false`, so `{presentation: disabled}` passes it. Check the
        // presentation too, as shapes_at_carat does.
        $presentation = $artefacts->wp_widget_presentation($ctx->site_id, 'data_methodology', $page_level);
        if ($presentation === null || $presentation === '' || $presentation === 'disabled') {
            return '';
        }

        $copy = $this->methodology_copy($ctx);
        if (empty($copy)) {
            return '';
        }

        $values = $this->methodology_values($ctx, $bag);
        $paragraphs = array();

        $statistic = $this->methodology_statistic_line($copy, $ctx);
        if ($statistic !== '') {
            $paragraphs[] = $this->fill_methodology_tokens($statistic, $values);
        }

        // Only claim partial coverage when C5.1 actually reported it. An older
        // artefact has no coverage fields, and absence is not evidence of a gap.
        if ($this->methodology_coverage_is_partial($bag) && !empty($copy['partial_coverage_note'])) {
            $paragraphs[] = $this->fill_methodology_tokens(
                (string) $copy['partial_coverage_note'],
                $values
            );
        }

        if (in_array($page_level, self::$METHODOLOGY_CARAT_LEVELS, true)
            && !empty($copy['approximate_weight'])) {
            $paragraphs[] = (string) $copy['approximate_weight'];
        }

        if (in_array($page_level, self::$METHODOLOGY_TABLE_LEVELS, true)
            && !empty($copy['thin_sample_note'])) {
            $paragraphs[] = (string) $copy['thin_sample_note'];
        }

        $closing = array();
        foreach (array('source_line', 'price_basis') as $key) {
            if (!empty($copy[$key])) {
                $closing[] = trim((string) $copy[$key]);
            }
        }
        if (!empty($copy['update_line']) && $values['date'] !== '') {
            $closing[] = $this->fill_methodology_tokens((string) $copy['update_line'], $values);
        }
        if ($closing !== array()) {
            $paragraphs[] = implode(' ', $closing);
        }

        if ($paragraphs === array()) {
            return '';
        }

        $heading = !empty($copy['heading'])
            ? (string) $copy['heading']
            : __('About this data', 'loupe-diamond-network');

        $body = '';
        foreach ($paragraphs as $paragraph) {
            $text = trim(preg_replace('/\s+/', ' ', $paragraph));
            if ($text === '') {
                continue;
            }
            $body .= '<p>' . esc_html($text) . '</p>';
        }
        if ($body === '') {
            return '';
        }

        return '<section class="ldn-section ldn-data-methodology">'
            . '<h2>' . esc_html($heading) . '</h2>'
            . $body
            . '</section>';
    }

    /**
     * The site's `methodology` profile block, or array() when absent.
     *
     * @param LDN_Page_Context $ctx
     * @return array
     */
    private function methodology_copy(LDN_Page_Context $ctx) {
        $profile = $this->config->get_content_profile($ctx->site_id);
        if (!is_array($profile) || empty($profile['methodology']) || !is_array($profile['methodology'])) {
            return array();
        }
        return $profile['methodology'];
    }

    /**
     * The statistic sentence for this page level, keyed by the profile's
     * page_structure naming (individual_shape, all_shapes, ...) rather than the
     * URL-facing page level.
     *
     * @param array            $copy
     * @param LDN_Page_Context $ctx
     * @return string
     */
    private function methodology_statistic_line(array $copy, LDN_Page_Context $ctx) {
        if (empty($copy['statistic']) || !is_array($copy['statistic'])) {
            return '';
        }
        $map = array(
            'shape'        => 'individual_shape',
            'all-shapes'   => 'all_shapes',
            'diamond-type' => 'diamond_type',
            'top-level'    => 'top_level',
        );
        $level = (string) $ctx->page_level;
        if (!isset($map[$level]) || empty($copy['statistic'][$map[$level]])) {
            return '';
        }
        return (string) $copy['statistic'][$map[$level]];
    }

    /**
     * Live values for the copy tokens. Sample size is read from the same paths
     * the stats block uses, so the two cannot disagree on the page.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return array<string, string>
     */
    private function methodology_values(LDN_Page_Context $ctx, array $bag) {
        $summary = isset($bag['summary']) && is_array($bag['summary']) ? $bag['summary'] : array();
        $overview = isset($bag['market_overview']) && is_array($bag['market_overview'])
            ? $bag['market_overview']
            : array();
        $type_summary = isset($bag['type_summary']) && is_array($bag['type_summary'])
            ? $bag['type_summary']
            : array();

        $sample = $this->methodology_first_int(array(
            isset($summary['distribution']['sample_size']) ? $summary['distribution']['sample_size'] : null,
            isset($summary['num_diamonds']) ? $summary['num_diamonds'] : null,
            isset($summary['sample_size']) ? $summary['sample_size'] : null,
            isset($type_summary['aggregate']['total_sample_size']) ? $type_summary['aggregate']['total_sample_size'] : null,
            isset($overview['total_diamonds_tracked']) ? $overview['total_diamonds_tracked'] : null,
        ));

        $aggregate = isset($summary['aggregate']) && is_array($summary['aggregate'])
            ? $summary['aggregate']
            : array();

        $date = '';
        foreach (array(
            isset($summary['analysis_date']) ? $summary['analysis_date'] : null,
            isset($type_summary['analysis_date']) ? $type_summary['analysis_date'] : null,
            isset($overview['analysis_date']) ? $overview['analysis_date'] : null,
        ) as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $date = $this->methodology_format_date($candidate);
                break;
            }
        }

        return array(
            'sample_size'     => $sample > 0 ? number_format($sample) : '',
            'shape'           => $this->methodology_shape_label($ctx),
            'carat'           => (string) $ctx->carat,
            'date'            => $date,
            'shape_count'     => isset($aggregate['shape_count']) ? (string) (int) $aggregate['shape_count'] : '',
            'shapes_expected' => isset($aggregate['shapes_expected']) ? (string) (int) $aggregate['shapes_expected'] : '',
        );
    }

    /**
     * True only when the aggregate explicitly reported incomplete coverage.
     *
     * @param array $bag
     * @return bool
     */
    private function methodology_coverage_is_partial(array $bag) {
        $summary = isset($bag['summary']) && is_array($bag['summary']) ? $bag['summary'] : array();
        if (!isset($summary['aggregate']) || !is_array($summary['aggregate'])) {
            return false;
        }
        $aggregate = $summary['aggregate'];
        if (!isset($aggregate['coverage_complete'])) {
            return false;
        }
        return $aggregate['coverage_complete'] === false;
    }

    /**
     * @param array $candidates
     * @return int
     */
    private function methodology_first_int(array $candidates) {
        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }
        return 0;
    }

    /**
     * @param LDN_Page_Context $ctx
     * @return string
     */
    private function methodology_shape_label(LDN_Page_Context $ctx) {
        $shape = (string) $ctx->shape;
        if ($shape === '') {
            return '';
        }
        return strtolower(str_replace('-', ' ', $shape));
    }

    /**
     * ISO date to reader-facing form, falling back to the raw string.
     *
     * @param string $iso
     * @return string
     */
    private function methodology_format_date($iso) {
        $ts = strtotime($iso);
        if ($ts === false) {
            return $iso;
        }
        return date_i18n(get_option('date_format') ?: 'j F Y', $ts);
    }

    /**
     * Replace {token} placeholders, dropping any the page cannot fill so a
     * missing value never renders as a literal brace.
     *
     * @param string                $template
     * @param array<string, string> $values
     * @return string
     */
    private function fill_methodology_tokens($template, array $values) {
        $out = (string) $template;
        foreach ($values as $key => $value) {
            $out = str_replace('{' . $key . '}', (string) $value, $out);
        }
        return preg_replace('/\{[a-z_]+\}/', '', $out);
    }
}

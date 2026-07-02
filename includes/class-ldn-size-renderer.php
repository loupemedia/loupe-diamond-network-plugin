<?php
/**
 * Size-module page renderer — PRD-015 CP106–107.
 *
 * @package LoupeDiamondNetwork
 * @since   0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Size_Renderer {

    const PLOTLY_CDN = 'https://cdn.plot.ly/plotly-2.35.2.min.js';

    const JSON_SCRIPT_FLAGS = JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** Nominal carat bands (mirrors Ringspo/R_utils CARAT_RANGES labels). */
    const CARAT_BANDS = array(
        '0.3', '0.4', '0.5', '0.6', '0.7', '0.8', '0.9', '1', '1.5', '2', '2.5',
        '3', '4', '5', '6', '7', '8', '9', '10', '11', '12',
    );

    /** Shapes offered for same-carat comparison links when not round. */
    const COMPARE_SHAPES = array('princess', 'oval', 'cushion', 'emerald', 'pear');

    /** @var LDN_Data_Fetcher */
    private $fetcher;

    /** @var LDN_Config */
    private $config;

    /** @var LDN_Renderer|null */
    private $price_renderer = null;

    /**
     * @param LDN_Data_Fetcher $fetcher
     * @param LDN_Config       $config
     */
    public function __construct(LDN_Data_Fetcher $fetcher, LDN_Config $config) {
        $this->fetcher = $fetcher;
        $this->config = $config;
    }

    /**
     * @param LDN_Page_Context $ctx
     * @param array            $summary Primary size-summary.json payload.
     * @return string
     */
    public function render(LDN_Page_Context $ctx, array $summary) {
        $copy = $this->fetcher->fetch_artefact('size_copy_json', $ctx);
        $copy = is_array($copy) ? $copy : array();
        $canonical = $this->current_url($ctx);

        $out = $this->page_shell_open($ctx);
        $out .= $this->breadcrumb_html($ctx, $canonical);
        $out .= '<h1 class="ldn-page-title">' . esc_html($this->headline($ctx, $summary)) . '</h1>';
        $lead = isset($copy['plain_text']) && is_string($copy['plain_text']) && $copy['plain_text'] !== ''
            ? $copy['plain_text']
            : $this->factual_fallback($summary);
        $out .= '<p class="ldn-size-factual ldn-intro-dynamic">' . esc_html($lead) . '</p>';
        $out .= $this->copy_notes_html($copy);

        if ($ctx->page_level === 'size-comparison') {
            $out .= $this->comparison_body_html($ctx, $summary);
        } elseif ($ctx->page_level === 'size-individual') {
            $out .= $this->individual_body_html($ctx, $summary);
            $out .= $this->methodology_html($summary);
            $out .= $this->internal_links_html($ctx, $summary);
        } else {
            $out .= $this->hub_body_html($ctx, $summary);
        }

        $out .= $this->price_links_html($ctx);
        $out .= $this->faq_html($copy);
        $out .= '</main></div>';

        return $out;
    }

    /**
     * Open the page shell with the same CP53 chrome as pricing pages.
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function page_shell_open(LDN_Page_Context $ctx) {
        $profile = $this->content_profile($ctx->site_id);
        $renderer = $this->price_renderer();
        $chrome = $renderer->chrome_heading_class($profile);

        return '<div class="ldn-page-shell"><main class="ldn-price-page ldn-size-page '
            . esc_attr($chrome) . ' ldn-' . esc_attr($ctx->page_level) . '-page">'
            . $renderer->theme_style_block($profile);
    }

    /**
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @return string
     */
    private function individual_body_html(LDN_Page_Context $ctx, array $summary) {
        $visuals = isset($summary['visuals']) && is_array($summary['visuals']) ? $summary['visuals'] : array();
        $scale_svg = '';
        if (!empty($visuals['scale_reference_svg']) && is_string($visuals['scale_reference_svg'])) {
            $scale_svg = $visuals['scale_reference_svg'];
        } else {
            $fallback = $this->fetcher->fetch_artefact_html('shape_outline_svg', $ctx);
            if (is_string($fallback) && $fallback !== '') {
                $scale_svg = $fallback;
            }
        }
        $spread_svg = (!empty($visuals['spread_svg']) && is_string($visuals['spread_svg']))
            ? $visuals['spread_svg'] : '';
        $dims = $this->dimensions_table($summary);
        $ideal = $this->ideal_vs_real_html($summary);
        $hero = '';

        if ($scale_svg !== '' || $dims !== '') {
            $hero = '<section class="ldn-section ldn-size-hero">';
            if ($scale_svg !== '') {
                $hero .= '<div class="ldn-size-hero__outline"><div class="ldn-size-outline ldn-size-outline--scale">'
                    . $scale_svg . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            if ($dims !== '') {
                $hero .= '<div class="ldn-size-hero__dims">' . $dims . '</div>';
            }
            $hero .= '</section>';
        }

        $spread = '';
        if ($spread_svg !== '') {
            $spread = '<section class="ldn-section ldn-size-spread"><h2>'
                . esc_html__('How much do sizes vary?', 'loupe-diamond-network') . '</h2>'
                . '<p class="ldn-size-spread__lead">'
                . esc_html__(
                    'Not every stone measures the same. These outlines show smaller-than-typical, typical, and larger-than-typical examples from real inventory.',
                    'loupe-diamond-network'
                ) . '</p>'
                . '<div class="ldn-size-outline ldn-size-outline--spread">' . $spread_svg . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                . '</section>';
        }

        return $hero . $spread . $this->chart_html($ctx) . $this->proportions_html($summary)
            . $ideal;
    }

    /**
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @return string
     */
    public function render_head_content(LDN_Page_Context $ctx, array $summary, $indexable = true) {
        $copy = $this->fetcher->fetch_artefact('size_copy_json', $ctx);
        $copy = is_array($copy) ? $copy : array();
        $canonical = $this->current_url($ctx);
        $title = $this->page_title($ctx, $summary);
        $description = isset($copy['plain_text']) && is_string($copy['plain_text']) && $copy['plain_text'] !== ''
            ? $copy['plain_text']
            : $this->factual_fallback($summary);

        $out = '<title>' . esc_html($title) . '</title>' . "\n";
        $out .= '<meta name="description" content="' . esc_attr($description) . '" />' . "\n";
        if (!$indexable) {
            $out .= '<meta name="robots" content="noindex, follow" />' . "\n";
        }
        $out .= '<link rel="canonical" href="' . esc_url($canonical) . '" />' . "\n";
        $out .= '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
        $out .= '<meta property="og:description" content="' . esc_attr($description) . '" />' . "\n";
        $out .= '<meta property="og:url" content="' . esc_url($canonical) . '" />' . "\n";
        $out .= $this->json_ld_script($ctx, $summary, $copy, $canonical, $title, $description);

        return $out;
    }

    /**
     * SEO title (may include mm dimensions on individual pages).
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @return string
     */
    public function page_title(LDN_Page_Context $ctx, array $summary) {
        if ($ctx->page_level === 'size-individual' && $ctx->shape !== null && $ctx->carat !== null) {
            $shape = ucwords(str_replace('-', ' ', $ctx->shape));
            $length = $this->dig($summary, array('dimensions_mm', 'length', 'median'));
            $width = $this->dig($summary, array('dimensions_mm', 'width', 'median'));
            if ($length !== null && $width !== null) {
                return sprintf(
                    '%s Carat %s Diamond Size — %s × %s mm',
                    $ctx->carat,
                    $shape,
                    $length,
                    $width
                );
            }
        }
        return $this->headline($ctx, $summary);
    }

    /**
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @return string
     */
    public function headline(LDN_Page_Context $ctx, array $summary) {
        if ($ctx->page_level === 'size-mega-hub') {
            return 'Diamond Size Chart';
        }
        if ($ctx->page_level === 'size-shape-hub' && $ctx->shape !== null) {
            return ucwords(str_replace('-', ' ', $ctx->shape)) . ' Diamond Size Chart';
        }
        if ($ctx->page_level === 'size-individual' && $ctx->shape !== null && $ctx->carat !== null) {
            $shape = ucwords(str_replace('-', ' ', $ctx->shape));
            return $ctx->carat . ' Carat ' . $shape . ' Diamond Size';
        }
        if ($ctx->page_level === 'size-comparison' && isset($summary['a'], $summary['b'])) {
            return $this->comparison_headline($summary['a'], $summary['b']);
        }
        return 'Diamond Size';
    }

    /**
     * Plain-text factual statement for AEO (from size-summary fields).
     *
     * @param array $summary
     * @return string
     */
    public function factual_fallback(array $summary) {
        if (isset($summary['type']) && $summary['type'] === 'comparison'
            && isset($summary['a'], $summary['b'])
        ) {
            return $this->comparison_plain_text($summary);
        }
        if (isset($summary['type']) && in_array($summary['type'], array('shape_hub', 'mega_hub'), true)) {
            $count = isset($summary['rows']) && is_array($summary['rows']) ? count($summary['rows']) : 0;
            return sprintf(
                'Diamond size chart with %d shape and carat combinations from real inventory measurements.',
                $count
            );
        }

        $shape = isset($summary['shape']) ? ucwords(str_replace('-', ' ', (string) $summary['shape'])) : 'Diamond';
        $carat = isset($summary['carat_band']) ? (string) $summary['carat_band'] : '';
        $length = $this->dig($summary, array('dimensions_mm', 'length', 'median'));
        $width = $this->dig($summary, array('dimensions_mm', 'width', 'median'));
        $n = isset($summary['n']) ? (int) $summary['n'] : 0;
        if ($length !== null && $width !== null && $carat !== '') {
            return sprintf(
                'A %s carat %s diamond measures on average %s × %s mm, based on %s real diamonds.',
                $carat,
                $shape,
                $length,
                $width,
                number_format($n)
            );
        }
        return sprintf('%s carat %s diamond size measurements from real inventory.', $carat, $shape);
    }

    /**
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function chart_html(LDN_Page_Context $ctx) {
        $payload = $this->fetcher->fetch_artefact('size_distribution_json', $ctx);
        if (!is_array($payload)) {
            return '';
        }
        $json = wp_json_encode($payload, self::JSON_SCRIPT_FLAGS);
        if (!is_string($json) || $json === '') {
            return '';
        }
        $id = 'ldn-size-chart-' . md5($ctx->page_level . ($ctx->shape ?? '') . ($ctx->carat ?? ''));
        $out = '<section class="ldn-section ldn-chart ldn-size-chart">';
        $out .= '<h2>' . esc_html__('Face-up size spread', 'loupe-diamond-network') . '</h2>';
        $out .= '<p class="ldn-size-chart__lead">' . esc_html__(
            'How face-up area varies across real stones of this carat weight. The bar shows the middle 50% (dark) and full typical range (10th–90th percentile).',
            'loupe-diamond-network'
        ) . '</p>';
        $out .= '<div id="' . esc_attr($id) . '" class="ldn-chart-target"></div>';
        $out .= '<script type="application/json" id="' . esc_attr($id) . '-data">' . $json . '</script>';
        $out .= '<script src="' . esc_url(self::PLOTLY_CDN) . '"></script>';
        $out .= '<script>(function(){var el=document.getElementById(' . wp_json_encode($id) . ');';
        $out .= 'var raw=document.getElementById(' . wp_json_encode($id . '-data') . ');';
        $out .= 'if(!el||!raw||!window.Plotly)return;var fig=JSON.parse(raw.textContent);';
        $out .= 'Plotly.newPlot(el,fig.data||[],fig.layout||{},{responsive:true,displayModeBar:false});})();</script>';
        $out .= '</section>';
        return $out;
    }

    /**
     * @param array $summary
     * @return string
     */
    public function dimensions_table(array $summary) {
        $rows = '';
        if ($this->is_near_round($summary)) {
            $diameter = $this->average_diameter_mm($summary, 'median');
            if ($diameter !== null) {
                $rows .= '<tr><th>' . esc_html__('Average diameter (mm)', 'loupe-diamond-network')
                    . '</th><td>' . esc_html((string) $diameter) . '</td></tr>';
            }
            $p10 = $this->average_diameter_mm($summary, 'p10');
            $p90 = $this->average_diameter_mm($summary, 'p90');
            if ($p10 !== null && $p90 !== null) {
                $rows .= '<tr><th>' . esc_html__('Typical diameter range', 'loupe-diamond-network')
                    . '</th><td>' . esc_html($p10 . ' – ' . $p90) . '</td></tr>';
            }
        } else {
            $length = $this->dig($summary, array('dimensions_mm', 'length', 'median'));
            $width = $this->dig($summary, array('dimensions_mm', 'width', 'median'));
            if ($length !== null) {
                $rows .= '<tr><th>' . esc_html__('Length (mm)', 'loupe-diamond-network')
                    . '</th><td>' . esc_html((string) $length) . '</td></tr>';
            }
            if ($width !== null) {
                $rows .= '<tr><th>' . esc_html__('Width (mm)', 'loupe-diamond-network')
                    . '</th><td>' . esc_html((string) $width) . '</td></tr>';
            }
        }
        $faceup = $this->dig($summary, array('faceup_area_mm2', 'median'));
        $faceup_p10 = $this->dig($summary, array('faceup_area_mm2', 'p10'));
        $faceup_p90 = $this->dig($summary, array('faceup_area_mm2', 'p90'));
        if ($faceup !== null) {
            $rows .= '<tr><th>' . esc_html__('Face-up area (mm²)', 'loupe-diamond-network')
                . '</th><td>' . esc_html((string) $faceup) . '</td></tr>';
        }
        if ($faceup_p10 !== null && $faceup_p90 !== null) {
            $rows .= '<tr><th>' . esc_html__('Face-up range (mm²)', 'loupe-diamond-network')
                . '</th><td>' . esc_html($faceup_p10 . ' – ' . $faceup_p90) . '</td></tr>';
        }
        $lw = $this->dig($summary, array('lw_ratio', 'median'));
        if ($lw !== null && !$this->is_near_round($summary)) {
            $rows .= '<tr><th>' . esc_html__('L/W ratio', 'loupe-diamond-network')
                . '</th><td>' . esc_html((string) $lw) . '</td></tr>';
        }
        $depth = $this->dig($summary, array('depth_percent', 'mean'));
        if ($depth !== null) {
            $rows .= '<tr><th>' . esc_html__('Depth %', 'loupe-diamond-network')
                . '</th><td>' . esc_html((string) $depth) . '</td></tr>';
        }
        $n = isset($summary['n']) ? (int) $summary['n'] : 0;
        if ($n > 0) {
            $rows .= '<tr><th>' . esc_html__('Sample size', 'loupe-diamond-network')
                . '</th><td>' . esc_html(number_format($n)) . '</td></tr>';
        }
        if ($rows === '') {
            return '';
        }
        return '<h2 class="ldn-size-hero__heading">' . esc_html__('Key dimensions', 'loupe-diamond-network')
            . '</h2><table class="ldn-size-table"><tbody>' . $rows . '</tbody></table>';
    }

    /**
     * Ideal vs real inventory callout.
     *
     * @param array $summary
     * @return string
     */
    public function ideal_vs_real_html(array $summary) {
        if (!isset($summary['source']) || $summary['source'] !== 'real') {
            return '';
        }
        $ideal_face = $this->dig($summary, array('ideal', 'faceup_area_mm2'));
        $median_face = $this->dig($summary, array('faceup_area_mm2', 'median'));
        if ($ideal_face === null || $median_face === null || (float) $ideal_face === 0.0) {
            return '';
        }
        $pct = round(((float) $median_face - (float) $ideal_face) / (float) $ideal_face * 100, 1);
        $direction = $pct >= 0 ? 'larger' : 'smaller';
        $pct_abs = abs($pct);
        $shape = isset($summary['shape']) ? ucwords(str_replace('-', ' ', (string) $summary['shape'])) : 'Diamond';
        $carat = isset($summary['carat_band']) ? (string) $summary['carat_band'] : '';
        $text = sprintf(
            /* translators: 1: carat, 2: shape, 3: percent, 4: larger/smaller, 5: ideal face-up mm², 6: median face-up mm² */
            __(
                'Chart sites often cite an industry ideal of %5$s mm² for a %1$s carat %2$s. Real inventory medians %6$s mm² — about %3$s%% %4$s face-up than that reference.',
                'loupe-diamond-network'
            ),
            $carat,
            $shape,
            (string) $pct_abs,
            $direction,
            (string) $ideal_face,
            (string) $median_face
        );
        if ($this->is_near_round($summary)) {
            $ideal_len = $this->dig($summary, array('ideal', 'length_mm'));
            $ideal_wid = $this->dig($summary, array('ideal', 'width_mm'));
            $median_d = $this->average_diameter_mm($summary, 'median');
            if ($ideal_len !== null && $ideal_wid !== null && $median_d !== null) {
                $ideal_d = round(((float) $ideal_len + (float) $ideal_wid) / 2, 2);
                $text .= ' ' . sprintf(
                    /* translators: 1: ideal diameter mm, 2: median diameter mm */
                    __('Ideal diameter is often quoted as %1$s mm; our median is %2$s mm.', 'loupe-diamond-network'),
                    (string) $ideal_d,
                    (string) $median_d
                );
            }
        }
        return '<section class="ldn-section ldn-size-ideal-real"><h2>'
            . esc_html__('Ideal vs real measurements', 'loupe-diamond-network') . '</h2><p>'
            . esc_html($text) . '</p></section>';
    }

    /**
     * @param array  $summary
     * @param string $tier median|p10|p90
     * @return float|null
     */
    public function average_diameter_mm(array $summary, $tier = 'median') {
        $length = $this->dig($summary, array('dimensions_mm', 'length', $tier));
        $width = $this->dig($summary, array('dimensions_mm', 'width', $tier));
        if ($length === null || $width === null) {
            if ($tier !== 'median') {
                return null;
            }
            $length = $this->dig($summary, array('dimensions_mm', 'length', 'median'));
            $width = $this->dig($summary, array('dimensions_mm', 'width', 'median'));
            if ($length === null || $width === null) {
                return null;
            }
        }
        return round(((float) $length + (float) $width) / 2, 2);
    }

    /**
     * @param array $summary
     * @return bool
     */
    public function is_near_round(array $summary) {
        $shape = isset($summary['shape']) ? (string) $summary['shape'] : '';
        if ($shape === 'round') {
            return true;
        }
        $lw = $this->dig($summary, array('lw_ratio', 'median'));
        if ($lw === null) {
            return false;
        }
        return abs((float) $lw - 1.0) <= 0.02;
    }

    /**
     * Mega or per-shape hub content: selector, optional scale visual, ladder table.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @return string
     */
    public function hub_body_html(LDN_Page_Context $ctx, array $summary) {
        $out = '';
        if ($ctx->page_level === 'size-mega-hub') {
            $out .= $this->shape_selector_html($ctx->site_id, $summary);
        }
        if ($ctx->page_level === 'size-shape-hub') {
            $out .= $this->shape_hub_scale_html($ctx);
        }
        $out .= $this->hub_table_html($ctx, $summary);
        return $out;
    }

    /**
     * Crawlable shape tiles linking to per-shape hub pages (mega hub only).
     *
     * @param string $site_id
     * @param array  $summary Hub summary with rows[].
     * @return string
     */
    public function shape_selector_html($site_id, array $summary) {
        $rows = isset($summary['rows']) && is_array($summary['rows']) ? $summary['rows'] : array();
        if ($rows === array()) {
            return '';
        }
        $shapes = array();
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['shape'])) {
                continue;
            }
            $shapes[(string) $row['shape']] = true;
        }
        if ($shapes === array()) {
            return '';
        }
        ksort($shapes);
        $tiles = '';
        foreach (array_keys($shapes) as $shape) {
            $url = $this->build_size_shape_hub_url($site_id, $shape);
            $label = ucwords(str_replace('-', ' ', $shape));
            $tiles .= '<a class="ldn-size-shape-tile" href="' . esc_url($url) . '">'
                . esc_html($label) . '</a>';
        }
        return '<nav class="ldn-size-shape-selector" aria-label="'
            . esc_attr__('Diamond shapes', 'loupe-diamond-network') . '">'
            . $tiles . '</nav>';
    }

    /**
     * 1 ct scale reference for a shape hub (fetched from the individual artefact).
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function shape_hub_scale_html(LDN_Page_Context $ctx) {
        if ($ctx->shape === null) {
            return '';
        }
        $rep_ctx = new LDN_Page_Context(
            $ctx->site_id,
            'size-individual',
            $ctx->country_code,
            null,
            '1',
            $ctx->shape,
            'size'
        );
        $rep = $this->fetcher->fetch_artefact('size_summary_json', $rep_ctx);
        if (!is_array($rep)) {
            return '';
        }
        $visuals = isset($rep['visuals']) && is_array($rep['visuals']) ? $rep['visuals'] : array();
        $svg = (!empty($visuals['scale_reference_svg']) && is_string($visuals['scale_reference_svg']))
            ? $visuals['scale_reference_svg'] : '';
        if ($svg === '') {
            $fallback = $this->fetcher->fetch_artefact_html('shape_outline_svg', $rep_ctx);
            $svg = is_string($fallback) ? $fallback : '';
        }
        if ($svg === '') {
            return '';
        }
        $shape_label = ucwords(str_replace('-', ' ', $ctx->shape));
        return '<section class="ldn-section ldn-size-hub-scale"><h2>'
            . esc_html(sprintf(
                /* translators: %s: diamond shape */
                __('%s at 1 carat — actual size', 'loupe-diamond-network'),
                $shape_label
            )) . '</h2>'
            . '<div class="ldn-size-outline ldn-size-outline--scale">' . $svg . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            . '</section>';
    }

    /**
     * @param LDN_Page_Context $ctx
     * @param array            $summary Hub summary with rows[].
     * @return string
     */
    public function hub_table_html(LDN_Page_Context $ctx, array $summary) {
        $site_id = $ctx->site_id;
        $show_shape = $ctx->page_level === 'size-mega-hub';
        $rows = isset($summary['rows']) && is_array($summary['rows']) ? $summary['rows'] : array();
        if ($rows === array()) {
            return '';
        }
        $has_lw_range = false;
        $has_depth = false;
        $has_delta = false;
        foreach ($rows as $probe) {
            if (!is_array($probe)) {
                continue;
            }
            if (isset($probe['lw_low'], $probe['lw_high'])) {
                $has_lw_range = true;
            }
            if (isset($probe['depth_pct'])) {
                $has_depth = true;
            }
            if (array_key_exists('faceup_delta_pct', $probe)) {
                $has_delta = true;
            }
        }
        $body = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $shape = isset($row['shape']) ? (string) $row['shape'] : '';
            $carat = isset($row['carat']) ? (string) $row['carat'] : '';
            $url = $shape !== '' && $carat !== ''
                ? $this->build_size_individual_url($site_id, $shape, $carat)
                : '';
            $shape_label = ucwords(str_replace('-', ' ', $shape));
            $body .= '<tr>';
            if ($show_shape) {
                $shape_cell = $url !== ''
                    ? '<a href="' . esc_url($url) . '">' . esc_html($shape_label) . '</a>'
                    : esc_html($shape_label);
                $body .= '<td>' . $shape_cell . '</td>';
            }
            $carat_cell = $url !== ''
                ? '<a href="' . esc_url($url) . '">' . esc_html($carat) . ' ct</a>'
                : esc_html($carat);
            $body .= '<td>' . $carat_cell . '</td>';
            $body .= '<td>' . esc_html((string) ($row['length_mm'] ?? '')) . '</td>';
            $body .= '<td>' . esc_html((string) ($row['width_mm'] ?? '')) . '</td>';
            $body .= '<td>' . esc_html((string) ($row['faceup_area_mm2'] ?? '')) . '</td>';
            if ($has_lw_range || isset($row['lw_ratio'])) {
                $lw = $row['lw_ratio'] ?? '';
                $lo = $row['lw_low'] ?? null;
                $hi = $row['lw_high'] ?? null;
                if ($has_lw_range && $lo !== null && $hi !== null && $lw !== '') {
                    $lw_cell = esc_html((string) $lw) . ' <span class="ldn-size-muted">('
                        . esc_html((string) $lo) . '–' . esc_html((string) $hi) . ')</span>';
                } else {
                    $lw_cell = esc_html((string) $lw);
                }
                $body .= '<td>' . $lw_cell . '</td>';
            }
            if ($has_depth) {
                $body .= '<td>' . esc_html((string) ($row['depth_pct'] ?? '')) . '</td>';
            }
            if ($has_delta) {
                $delta = $row['faceup_delta_pct'] ?? null;
                if ($delta === null || $delta === '') {
                    $body .= '<td></td>';
                } else {
                    $sign = (float) $delta >= 0 ? '+' : '';
                    $body .= '<td>' . esc_html($sign . (string) $delta . '%') . '</td>';
                }
            }
            $body .= '</tr>';
        }
        if ($body === '') {
            return '';
        }
        $head = '';
        if ($show_shape) {
            $head .= '<th>' . esc_html__('Shape', 'loupe-diamond-network') . '</th>';
        }
        $head .= '<th>' . esc_html__('Carat', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Length (mm)', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Width (mm)', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Face-up (mm²)', 'loupe-diamond-network') . '</th>';
        if ($has_lw_range || (isset($rows[0]) && is_array($rows[0]) && isset($rows[0]['lw_ratio']))) {
            $head .= '<th>' . esc_html__('L/W ratio', 'loupe-diamond-network') . '</th>';
        }
        if ($has_depth) {
            $head .= '<th>' . esc_html__('Depth %', 'loupe-diamond-network') . '</th>';
        }
        if ($has_delta) {
            $head .= '<th>' . esc_html__('vs ideal face-up', 'loupe-diamond-network') . '</th>';
        }
        $title = $ctx->page_level === 'size-shape-hub'
            ? esc_html__('Carat size chart', 'loupe-diamond-network')
            : esc_html__('All shapes and carat weights', 'loupe-diamond-network');
        return '<section class="ldn-section ldn-size-hub-table"><h2>' . $title
            . '</h2><table class="ldn-size-table"><thead><tr>' . $head
            . '</tr></thead><tbody>' . $body . '</tbody></table></section>';
    }

    /**
     * US-only links back to pricing pages (Decision 5).
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function price_links_html(LDN_Page_Context $ctx) {
        if (!$this->config->size_price_internal_links($ctx->site_id)) {
            return '';
        }
        if ($ctx->country_code !== $this->config->size_rollout_country($ctx->site_id)) {
            return '';
        }
        if ($ctx->shape === null || $ctx->carat === null) {
            return '';
        }

        $price_renderer = $this->price_renderer();
        $country = $this->config->size_rollout_country($ctx->site_id);
        $links = array();
        foreach (array('natural', 'lab-grown') as $dtype) {
            $price_ctx = new LDN_Page_Context(
                $ctx->site_id,
                'shape',
                $country,
                $dtype,
                $ctx->carat,
                $ctx->shape,
                'price'
            );
            $url = $price_renderer->build_price_page_url($price_ctx, 'shape');
            if ($url === '') {
                continue;
            }
            $label = $dtype === 'lab-grown' ? 'Lab-grown prices' : 'Natural prices';
            $links[] = '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        if ($links === array()) {
            return '';
        }
        return '<section class="ldn-section ldn-size-price-links"><p>'
            . esc_html__('View diamond prices for this size:', 'loupe-diamond-network') . ' '
            . implode(' · ', $links) . '</p></section>';
    }

    /**
     * @param array $copy
     * @return string
     */
    public function faq_html(array $copy) {
        $faq = isset($copy['faq']) && is_array($copy['faq']) ? $copy['faq'] : array();
        if ($faq === array()) {
            return '';
        }
        $items = '';
        foreach ($faq as $qa) {
            if (!is_array($qa)) {
                continue;
            }
            $q = $qa['question'] ?? ($qa['q'] ?? null);
            $a = $qa['answer'] ?? ($qa['a'] ?? null);
            if (!is_scalar($q) || !is_scalar($a)) {
                continue;
            }
            $items .= '<dt>' . esc_html((string) $q) . '</dt><dd>' . wp_kses_post(wpautop((string) $a)) . '</dd>';
        }
        if ($items === '') {
            return '';
        }
        return '<section class="ldn-section ldn-faq"><h2>' . esc_html__('FAQ', 'loupe-diamond-network')
            . '</h2><dl>' . $items . '</dl></section>';
    }

    /**
     * Templated intro + face-up / L/W notes from size-copy.json.
     *
     * @param array $copy
     * @return string
     */
    public function copy_notes_html(array $copy) {
        $blocks = isset($copy['copy']) && is_array($copy['copy']) ? $copy['copy'] : array();
        $parts = array();
        foreach (array('intro', 'faceup_note', 'lw_note') as $key) {
            if (!empty($blocks[$key]) && is_string($blocks[$key])) {
                $parts[] = '<p>' . esc_html(trim($blocks[$key])) . '</p>';
            }
        }
        if ($parts === array()) {
            return '';
        }
        return '<section class="ldn-section ldn-size-copy-notes">' . implode('', $parts) . '</section>';
    }

    /**
     * Depth %, L/W spread, and depth↔face-up narrative.
     *
     * @param array $summary
     * @return string
     */
    public function proportions_html(array $summary) {
        $paras = array();
        $faceup = $this->dig($summary, array('faceup_area_mm2', 'median'));
        if ($faceup !== null) {
            $paras[] = sprintf(
                /* translators: %s: face-up area in mm² */
                __('The typical face-up area is %s mm² — the size you see when the stone is set and viewed from above.', 'loupe-diamond-network'),
                (string) $faceup
            );
        }
        $lw = $this->dig($summary, array('lw_ratio', 'median'));
        $lw_lo = $this->dig($summary, array('lw_ratio', 'p25'));
        $lw_hi = $this->dig($summary, array('lw_ratio', 'p75'));
        if ($lw !== null && $lw_lo !== null && $lw_hi !== null && !$this->is_near_round($summary)) {
            $paras[] = sprintf(
                /* translators: 1: median L/W, 2: p25, 3: p75 */
                __('Length-to-width ratio is typically %1$s, with most stones between %2$s and %3$s.', 'loupe-diamond-network'),
                (string) $lw,
                (string) $lw_lo,
                (string) $lw_hi
            );
        }
        $corr = $summary['depth_faceup_corr'] ?? null;
        if ($corr !== null && $summary['source'] === 'real') {
            $abs = abs((float) $corr);
            if ($abs >= 0.15) {
                $direction = (float) $corr < 0 ? 'smaller' : 'larger';
                $paras[] = sprintf(
                    /* translators: 1: smaller|larger */
                    __('In our sample, deeper-cut stones tend to face up %1$s for their carat weight — total depth %% correlates with face-up area.', 'loupe-diamond-network'),
                    $direction
                );
            }
        }
        if ($paras === array()) {
            return '';
        }
        $body = '';
        foreach ($paras as $para) {
            $body .= '<p>' . esc_html($para) . '</p>';
        }
        return '<section class="ldn-section ldn-size-proportions"><h2>'
            . esc_html__('Face-up size & proportions', 'loupe-diamond-network') . '</h2>' . $body . '</section>';
    }

    /**
     * Data methodology transparency strip.
     *
     * @param array $summary
     * @return string
     */
    public function methodology_html(array $summary) {
        $n = isset($summary['n']) ? (int) $summary['n'] : 0;
        $source = isset($summary['source']) ? (string) $summary['source'] : '';
        if ($n <= 0 && $source === '') {
            return '';
        }
        $parts = array();
        if ($source === 'real' && $n > 0) {
            $rc = $summary['retailer_count'] ?? null;
            $retailer_phrase = $rc !== null
                ? sprintf(
                    /* translators: %d: retailer count */
                    __('from %d retailers', 'loupe-diamond-network'),
                    (int) $rc
                )
                : __('from multiple retailers', 'loupe-diamond-network');
            $parts[] = sprintf(
                /* translators: 1: sample count, 2: retailer phrase */
                __('Measurements are aggregated from %1$s real diamonds %2$s.', 'loupe-diamond-network'),
                number_format($n),
                $retailer_phrase
            );
            $excluded = $summary['pct_excluded'] ?? null;
            if ($excluded !== null && (float) $excluded > 0) {
                $parts[] = sprintf(
                    /* translators: %s: percent excluded */
                    __('Stones with implausible geometry were excluded (%s%% of raw rows).', 'loupe-diamond-network'),
                    (string) $excluded
                );
            }
        } elseif ($source === 'computed') {
            $parts[] = __('Sparse inventory — figures use industry ideal proportions until more real measurements are available.', 'loupe-diamond-network');
        }
        if ($parts === array()) {
            return '';
        }
        return '<section class="ldn-section ldn-size-methodology"><h2>'
            . esc_html__('About this data', 'loupe-diamond-network') . '</h2><p>'
            . esc_html(implode(' ', $parts)) . '</p></section>';
    }

    /**
     * Crawlable internal links: shape hub, adjacent carats, comparisons.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @return string
     */
    public function internal_links_html(LDN_Page_Context $ctx, array $summary) {
        if ($ctx->shape === null || $ctx->carat === null) {
            return '';
        }
        $site_id = $ctx->site_id;
        $shape = $ctx->shape;
        $carat = $ctx->carat;
        $shape_label = ucwords(str_replace('-', ' ', $shape));
        $links = array();

        $hub_url = $this->build_size_shape_hub_url($site_id, $shape);
        if ($hub_url !== '') {
            $links[] = '<a href="' . esc_url($hub_url) . '">'
                . esc_html(sprintf(
                    /* translators: %s: shape name */
                    __('%s diamond size chart', 'loupe-diamond-network'),
                    $shape_label
                )) . '</a>';
        }

        $mega_url = $this->build_size_mega_hub_url($site_id);
        if ($mega_url !== '') {
            $links[] = '<a href="' . esc_url($mega_url) . '">'
                . esc_html__('All shapes size chart', 'loupe-diamond-network') . '</a>';
        }

        foreach ($this->adjacent_carat_bands($carat) as $adj) {
            $url = $this->build_size_individual_url($site_id, $shape, $adj);
            $links[] = '<a href="' . esc_url($url) . '">'
                . esc_html(sprintf(
                    /* translators: %s: carat weight */
                    __('%s carat', 'loupe-diamond-network'),
                    $adj
                )) . '</a>';
        }

        foreach ($this->comparison_link_specs($shape, $carat) as $spec) {
            $url = $this->build_comparison_url(
                $site_id,
                $spec['shape_a'],
                $spec['carat_a'],
                $spec['shape_b'],
                $spec['carat_b']
            );
            $links[] = '<a href="' . esc_url($url) . '">' . esc_html($spec['label']) . '</a>';
        }

        if ($links === array()) {
            return '';
        }
        return '<section class="ldn-section ldn-size-internal-links"><h2>'
            . esc_html__('More size guides', 'loupe-diamond-network') . '</h2><ul><li>'
            . implode('</li><li>', $links) . '</li></ul></section>';
    }

    /**
     * Visible breadcrumb nav (when profile enables breadcrumb schema).
     *
     * @param LDN_Page_Context $ctx
     * @param string           $canonical_url
     * @return string
     */
    public function breadcrumb_html(LDN_Page_Context $ctx, $canonical_url = '') {
        if (!$this->schema_feature_enabled('breadcrumb', $ctx->site_id)) {
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
                $items .= $url !== ''
                    ? '<a href="' . esc_url($url) . '">' . esc_html($name) . '</a>'
                    : esc_html($name);
            }
        }
        return '<nav class="ldn-breadcrumbs" aria-label="'
            . esc_attr__('Breadcrumb', 'loupe-diamond-network') . '">' . $items . '</nav>';
    }

    /**
     * @param LDN_Page_Context $ctx
     * @param string           $canonical_url
     * @return array<int, array{name:string, url:string}>
     */
    public function breadcrumb_trail(LDN_Page_Context $ctx, $canonical_url = '') {
        $trail = array();
        $home = function_exists('home_url') ? (string) home_url('/') : '';
        if ($home !== '') {
            $trail[] = array('name' => 'Home', 'url' => $home);
        }

        $mega_url = $this->build_size_mega_hub_url($ctx->site_id);
        $trail[] = array(
            'name' => __('Diamond Size', 'loupe-diamond-network'),
            'url'  => $ctx->page_level === 'size-mega-hub' ? $canonical_url : $mega_url,
        );

        if ($ctx->shape !== null && $ctx->page_level !== 'size-mega-hub') {
            $shape_label = ucwords(str_replace('-', ' ', $ctx->shape)) . ' '
                . __('Size', 'loupe-diamond-network');
            $trail[] = array(
                'name' => $shape_label,
                'url'  => $ctx->page_level === 'size-shape-hub'
                    ? $canonical_url
                    : $this->build_size_shape_hub_url($ctx->site_id, $ctx->shape),
            );
        }

        if ($ctx->carat !== null && $ctx->page_level === 'size-individual') {
            $trail[] = array(
                'name' => sprintf(
                    /* translators: %s: carat weight */
                    __('%s Carat', 'loupe-diamond-network'),
                    $ctx->carat
                ),
                'url'  => $canonical_url,
            );
        }

        if ($ctx->page_level === 'size-comparison' && $ctx->compare_slug !== null) {
            $sides = $this->parse_compare_slug($ctx->compare_slug, $ctx->site_id);
            if ($sides !== null) {
                $trail[] = array(
                    'name' => $this->comparison_headline($sides['a'], $sides['b']),
                    'url'  => $canonical_url,
                );
            }
        }

        return array_values(array_filter($trail, static function ($crumb) {
            return !empty($crumb['url']);
        }));
    }

    /**
     * Dataset + BreadcrumbList + FAQPage JSON-LD for size pages.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @param array            $copy
     * @param string           $canonical
     * @param string           $title
     * @param string           $description
     * @return string
     */
    public function json_ld_script(
        LDN_Page_Context $ctx,
        array $summary,
        array $copy,
        $canonical,
        $title,
        $description
    ) {
        if (!apply_filters('ldn_emit_json_ld', true, $ctx)) {
            return '';
        }
        $schema = new LDN_Schema();
        $site = $this->config->get_site($ctx->site_id);
        $site = is_array($site) ? $site : array();
        $graph = array($schema->organization_node($site));

        $length = $this->dig($summary, array('dimensions_mm', 'length', 'median'));
        $width = $this->dig($summary, array('dimensions_mm', 'width', 'median'));
        $faceup = $this->dig($summary, array('faceup_area_mm2', 'median'));
        $n = isset($summary['n']) ? (int) $summary['n'] : 0;
        $domain = isset($site['domain']) ? (string) $site['domain'] : '';
        $brand = isset($site['brand_name']) ? (string) $site['brand_name'] : '';

        $measured = array();
        if ($length !== null) {
            $measured[] = array('@type' => 'PropertyValue', 'name' => 'length_mm', 'value' => $length);
        }
        if ($width !== null) {
            $measured[] = array('@type' => 'PropertyValue', 'name' => 'width_mm', 'value' => $width);
        }
        if ($faceup !== null) {
            $measured[] = array('@type' => 'PropertyValue', 'name' => 'faceup_area_mm2', 'value' => $faceup);
        }
        if ($n > 0) {
            $measured[] = array('@type' => 'PropertyValue', 'name' => 'sample_size', 'value' => $n);
        }

        $graph[] = array(
            '@type'              => 'Dataset',
            'name'               => $title,
            'description'        => $description,
            'url'                => $canonical,
            'creator'            => array(
                '@type' => 'Organization',
                'name'  => $brand,
                'url'   => $domain !== '' ? 'https://' . $domain : '',
            ),
            'variableMeasured'   => $measured,
            'isAccessibleForFree'=> true,
        );

        if ($this->schema_feature_enabled('faq', $ctx->site_id)) {
            $faq = isset($copy['faq']) && is_array($copy['faq']) ? $copy['faq'] : array();
            $faq_node = $schema->faq_node($faq);
            if ($faq_node !== null) {
                $graph[] = $faq_node;
            }
        }

        $crumbs = $schema->breadcrumb_node($this->breadcrumb_trail($ctx, $canonical));
        if ($crumbs !== null) {
            $graph[] = $crumbs;
        }

        $doc = array('@context' => 'https://schema.org', '@graph' => $graph);
        $json = wp_json_encode($doc, self::JSON_SCRIPT_FLAGS);
        if (!is_string($json) || $json === '') {
            return '';
        }
        return '<script type="application/ld+json">' . $json . '</script>' . "\n";
    }

    /**
     * @param string $carat
     * @return array<int, string>
     */
    public function adjacent_carat_bands($carat) {
        $norm = LDN_Test_Combos::normalise_carat($carat);
        $idx = array_search($norm, self::CARAT_BANDS, true);
        if ($idx === false) {
            return array();
        }
        $out = array();
        if ($idx > 0) {
            $out[] = self::CARAT_BANDS[$idx - 1];
        }
        if ($idx < count(self::CARAT_BANDS) - 1) {
            $out[] = self::CARAT_BANDS[$idx + 1];
        }
        return $out;
    }

    /**
     * Curated comparison link labels for internal navigation (mirrors Z3 curated_pairs subset).
     *
     * @param string $shape
     * @param string $carat
     * @return array<int, array{shape_a:string, carat_a:string, shape_b:string, carat_b:string, label:string}>
     */
    public function comparison_link_specs($shape, $carat) {
        $carat = LDN_Test_Combos::normalise_carat($carat);
        $specs = array();
        if ($shape !== 'round') {
            $specs[] = array(
                'shape_a' => 'round',
                'carat_a' => $carat,
                'shape_b' => $shape,
                'carat_b' => $carat,
                'label'   => sprintf(
                    /* translators: 1: carat, 2: shape */
                    __('%1$s ct round vs %1$s ct %2$s', 'loupe-diamond-network'),
                    $carat,
                    ucwords(str_replace('-', ' ', $shape))
                ),
            );
            return $specs;
        }
        foreach (self::COMPARE_SHAPES as $other) {
            $specs[] = array(
                'shape_a' => 'round',
                'carat_a' => $carat,
                'shape_b' => $other,
                'carat_b' => $carat,
                'label'   => sprintf(
                    /* translators: 1: carat, 2: shape */
                    __('%1$s ct round vs %1$s ct %2$s', 'loupe-diamond-network'),
                    $carat,
                    ucwords(str_replace('-', ' ', $other))
                ),
            );
        }
        return $specs;
    }

    /**
     * @param string $site_id
     * @return string
     */
    public function build_size_mega_hub_url($site_id) {
        $structure = $this->config->get_url_structure($site_id);
        $pattern = is_array($structure) && !empty($structure['size_level_1'])
            ? (string) $structure['size_level_1']
            : '/diamond-size';
        if (!function_exists('home_url')) {
            return $pattern;
        }
        return home_url(user_trailingslashit(ltrim($pattern, '/')));
    }

    /**
     * @param string $site_id
     * @param string $shape
     * @return string
     */
    public function build_size_shape_hub_url($site_id, $shape) {
        $structure = $this->config->get_url_structure($site_id);
        $pattern = is_array($structure) && !empty($structure['size_level_2'])
            ? (string) $structure['size_level_2']
            : '/diamond-size/{shape}';
        $path = str_replace('{shape}', $this->config->shape_to_s3_slug($shape), $pattern);
        if (!function_exists('home_url')) {
            return $path;
        }
        return home_url(user_trailingslashit(ltrim($path, '/')));
    }

    /**
     * @param string $site_id
     * @param string $shape_a
     * @param string $carat_a
     * @param string $shape_b
     * @param string $carat_b
     * @return string
     */
    public function build_comparison_url($site_id, $shape_a, $carat_a, $shape_b, $carat_b) {
        $ka = $this->config->shape_to_s3_slug($shape_a) . '-'
            . LDN_Test_Combos::normalise_carat($carat_a) . '-carat';
        $kb = $this->config->shape_to_s3_slug($shape_b) . '-'
            . LDN_Test_Combos::normalise_carat($carat_b) . '-carat';
        $path = '/diamond-size/compare/' . $ka . '-vs-' . $kb . '/';
        if (!function_exists('home_url')) {
            return $path;
        }
        return home_url(user_trailingslashit(ltrim($path, '/')));
    }

    /**
     * @param string $feature
     * @param string $site_id
     * @return bool
     */
    private function schema_feature_enabled($feature, $site_id) {
        $profile = $this->content_profile($site_id);
        $features = isset($profile['schema_features']) && is_array($profile['schema_features'])
            ? $profile['schema_features']
            : array();
        return in_array($feature, $features, true);
    }

    /**
     * @param string $site_id
     * @param string $shape
     * @param string $carat
     * @return string
     */
    public function build_size_individual_url($site_id, $shape, $carat) {
        $structure = $this->config->get_url_structure($site_id);
        $pattern = is_array($structure) && !empty($structure['size_level_3'])
            ? (string) $structure['size_level_3']
            : '/diamond-size/{shape}/{carat}';
        $path = str_replace(
            array('{shape}', '{carat}'),
            array(
                $this->config->shape_to_s3_slug($shape),
                LDN_Test_Combos::normalise_carat($carat),
            ),
            $pattern
        );
        if (!function_exists('home_url')) {
            return $path;
        }
        return home_url(user_trailingslashit(ltrim($path, '/')));
    }

    /**
     * @param LDN_Page_Context $ctx
     * @return string
     */
    private function current_url(LDN_Page_Context $ctx) {
        if ($ctx->page_level === 'size-individual' && $ctx->shape !== null && $ctx->carat !== null) {
            return $this->build_size_individual_url($ctx->site_id, $ctx->shape, $ctx->carat);
        }
        if ($ctx->page_level === 'size-shape-hub' && $ctx->shape !== null) {
            return $this->build_size_shape_hub_url($ctx->site_id, $ctx->shape);
        }
        if ($ctx->page_level === 'size-mega-hub') {
            return $this->build_size_mega_hub_url($ctx->site_id);
        }
        if ($ctx->page_level === 'size-comparison' && $ctx->compare_slug !== null) {
            $path = '/diamond-size/compare/' . $ctx->compare_slug . '/';
            if (!function_exists('home_url')) {
                return $path;
            }
            return home_url(user_trailingslashit(ltrim($path, '/')));
        }
        if (!function_exists('home_url')) {
            return '';
        }
        return home_url(add_query_arg(array()));
    }

    /**
     * @param array        $data
     * @param array<int,mixed> $path
     * @return mixed
     */
    private function dig(array $data, array $path) {
        $cursor = $data;
        foreach ($path as $key) {
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return null;
            }
            $cursor = $cursor[$key];
        }
        return $cursor;
    }

    /**
     * @param string $site_id
     * @return array
     */
    private function content_profile($site_id) {
        $profile = $this->config->get_content_profile($site_id);
        return is_array($profile) ? $profile : array();
    }

    /**
     * Reuse pricing renderer for chrome helpers and internal URLs.
     *
     * @return LDN_Renderer
     */
    private function price_renderer() {
        if ($this->price_renderer === null) {
            $this->price_renderer = new LDN_Renderer($this->fetcher, $this->config);
        }
        return $this->price_renderer;
    }

    /**
     * Comparison page body: overlay SVG, delta narrative, dimension table, side links.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @return string
     */
    private function comparison_body_html(LDN_Page_Context $ctx, array $summary) {
        if (!isset($summary['a'], $summary['b']) || !is_array($summary['a']) || !is_array($summary['b'])) {
            return '';
        }

        $svg = $this->fetcher->fetch_artefact_html('shape_outline_svg', $ctx);
        $out = '';
        if (is_string($svg) && $svg !== '') {
            $out .= '<section class="ldn-section ldn-size-comparison-visual">'
                . '<h2>' . esc_html__('Actual-size overlay', 'loupe-diamond-network') . '</h2>'
                . '<div class="ldn-size-outline ldn-size-outline--comparison">' . $svg . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                . '</section>';
        }

        $out .= $this->comparison_delta_html($summary);
        $out .= $this->comparison_table_html($summary);
        $out .= $this->comparison_side_links_html($ctx, $summary);

        return $out;
    }

    /**
     * @param array $summary Comparison payload with a, b, deltas.
     * @return string
     */
    public function comparison_table_html(array $summary) {
        if (!isset($summary['a'], $summary['b'])) {
            return '';
        }
        $head = '<tr><th>' . esc_html__('Shape', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Carat', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Length (mm)', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Width (mm)', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Face-up (mm²)', 'loupe-diamond-network') . '</th></tr>';
        $row = static function (array $side) {
            $shape = ucwords(str_replace('-', ' ', (string) ($side['shape'] ?? '')));
            return '<tr><td>' . esc_html($shape) . '</td>'
                . '<td>' . esc_html((string) ($side['carat'] ?? '')) . '</td>'
                . '<td>' . esc_html((string) ($side['length_mm'] ?? '')) . '</td>'
                . '<td>' . esc_html((string) ($side['width_mm'] ?? '')) . '</td>'
                . '<td>' . esc_html((string) ($side['faceup_area_mm2'] ?? '')) . '</td></tr>';
        };
        return '<section class="ldn-section ldn-size-comparison-table"><h2>'
            . esc_html__('Side-by-side measurements', 'loupe-diamond-network') . '</h2>'
            . '<table class="ldn-size-table"><thead>' . $head . '</thead><tbody>'
            . $row($summary['a']) . $row($summary['b'])
            . '</tbody></table></section>';
    }

    /**
     * @param array $summary
     * @return string
     */
    public function comparison_delta_html(array $summary) {
        $pct = $summary['deltas']['faceup_area_pct'] ?? null;
        if ($pct === null || !isset($summary['a'], $summary['b'])) {
            return '';
        }
        $bigger = ($summary['bigger'] ?? 'a') === 'a' ? $summary['a'] : $summary['b'];
        $smaller = ($summary['bigger'] ?? 'a') === 'a' ? $summary['b'] : $summary['a'];
        $bigger_label = sprintf(
            '%s carat %s',
            $bigger['carat'] ?? '',
            ucwords(str_replace('-', ' ', (string) ($bigger['shape'] ?? '')))
        );
        $text = sprintf(
            /* translators: 1: bigger stone label, 2: percent difference, 3: smaller face-up mm², 4: bigger face-up mm² */
            __(
                'The %1$s faces up about %2$s%% larger than the other stone when viewed from above (%3$s mm² vs %4$s mm²).',
                'loupe-diamond-network'
            ),
            $bigger_label,
            (string) abs((float) $pct),
            (string) ($smaller['faceup_area_mm2'] ?? ''),
            (string) ($bigger['faceup_area_mm2'] ?? '')
        );
        return '<section class="ldn-section ldn-size-comparison-delta"><p>' . esc_html($text) . '</p></section>';
    }

    /**
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @return string
     */
    public function comparison_side_links_html(LDN_Page_Context $ctx, array $summary) {
        if (!isset($summary['a'], $summary['b'])) {
            return '';
        }
        $links = array();
        foreach (array('a', 'b') as $key) {
            $side = $summary[$key];
            if (!is_array($side) || empty($side['shape']) || empty($side['carat'])) {
                continue;
            }
            $url = $this->build_size_individual_url($ctx->site_id, (string) $side['shape'], (string) $side['carat']);
            $label = sprintf(
                '%s carat %s size page',
                $side['carat'],
                ucwords(str_replace('-', ' ', (string) $side['shape']))
            );
            $links[] = '<li><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
        }
        if ($links === array()) {
            return '';
        }
        return '<section class="ldn-section ldn-size-comparison-links"><h2>'
            . esc_html__('Individual size pages', 'loupe-diamond-network') . '</h2><ul>'
            . implode('', $links) . '</ul></section>';
    }

    /**
     * Parse /diamond-size/compare/{slug}/ into two shape+carat sides.
     *
     * @param string|null $slug
     * @param string      $site_id
     * @return array{a:array{shape:string,carat:string},b:array{shape:string,carat:string}}|null
     */
    public function parse_compare_slug($slug, $site_id) {
        if (!is_string($slug) || strpos($slug, '-vs-') === false) {
            return null;
        }
        $parts = explode('-vs-', $slug, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $a = $this->parse_comparison_side_token($parts[0], $site_id);
        $b = $this->parse_comparison_side_token($parts[1], $site_id);
        if ($a === null || $b === null) {
            return null;
        }
        return array('a' => $a, 'b' => $b);
    }

    /**
     * Build a comparison summary from two individual size-summary payloads (long-tail).
     *
     * @param array $a
     * @param array $b
     * @return array
     */
    public function build_comparison_summary(array $a, array $b) {
        list($la, $wa, $fa) = $this->headline_dims($a);
        list($lb, $wb, $fb) = $this->headline_dims($b);
        $fa_num = $fa !== null ? (float) $fa : 0.0;
        $fb_num = $fb !== null ? (float) $fb : 0.0;
        $pct = $fa_num !== 0.0 ? round(($fb_num - $fa_num) / $fa_num * 100, 1) : null;

        $side = static function (array $summary, $length, $width, $faceup) {
            return array(
                'shape'           => (string) ($summary['shape'] ?? ''),
                'carat'           => (string) ($summary['carat_band'] ?? ''),
                'length_mm'       => $length,
                'width_mm'        => $width,
                'faceup_area_mm2' => $faceup,
                'source'          => (string) ($summary['source'] ?? ''),
            );
        };

        return array(
            'type'   => 'comparison',
            'a'      => $side($a, $la, $wa, $fa),
            'b'      => $side($b, $lb, $wb, $fb),
            'deltas' => array(
                'faceup_area_pct' => $pct,
                'length_mm'       => ($la !== null && $lb !== null) ? round((float) $lb - (float) $la, 2) : null,
                'width_mm'        => ($wa !== null && $wb !== null) ? round((float) $wb - (float) $wa, 2) : null,
            ),
            'bigger' => $fa_num >= $fb_num ? 'a' : 'b',
        );
    }

    /**
     * @param array $side_a
     * @param array $side_b
     * @return string
     */
    public function comparison_headline(array $side_a, array $side_b) {
        $label = static function (array $side) {
            return sprintf(
                '%s Carat %s',
                $side['carat'] ?? '',
                ucwords(str_replace('-', ' ', (string) ($side['shape'] ?? '')))
            );
        };
        return $label($side_a) . ' vs ' . $label($side_b);
    }

    /**
     * @param array $summary
     * @return string
     */
    private function comparison_plain_text(array $summary) {
        $a = $summary['a'];
        $b = $summary['b'];
        return sprintf(
            'A %s carat %s (%s × %s mm, %s mm² face-up) compared with a %s carat %s (%s × %s mm, %s mm² face-up).',
            $a['carat'] ?? '',
            ucwords(str_replace('-', ' ', (string) ($a['shape'] ?? ''))),
            $a['length_mm'] ?? '',
            $a['width_mm'] ?? '',
            $a['faceup_area_mm2'] ?? '',
            $b['carat'] ?? '',
            ucwords(str_replace('-', ' ', (string) ($b['shape'] ?? ''))),
            $b['length_mm'] ?? '',
            $b['width_mm'] ?? '',
            $b['faceup_area_mm2'] ?? ''
        );
    }

    /**
     * @param array $summary
     * @return array{0: mixed, 1: mixed, 2: mixed}
     */
    private function headline_dims(array $summary) {
        if (isset($summary['source']) && $summary['source'] === 'real') {
            return array(
                $this->dig($summary, array('dimensions_mm', 'length', 'median')),
                $this->dig($summary, array('dimensions_mm', 'width', 'median')),
                $this->dig($summary, array('faceup_area_mm2', 'median')),
            );
        }
        return array(
            $this->dig($summary, array('ideal', 'length_mm')),
            $this->dig($summary, array('ideal', 'width_mm')),
            $this->dig($summary, array('ideal', 'faceup_area_mm2')),
        );
    }

    /**
     * @param string $token e.g. round-1-carat
     * @param string $site_id
     * @return array{shape:string,carat:string}|null
     */
    private function parse_comparison_side_token($token, $site_id) {
        if (!preg_match('/^(.+)-([\d.]+)-carat$/', (string) $token, $matches)) {
            return null;
        }
        $shape = $this->s3_slug_to_shape($matches[1], $site_id);
        if ($shape === null) {
            return null;
        }
        return array(
            'shape' => $shape,
            'carat' => LDN_Test_Combos::normalise_carat($matches[2]),
        );
    }

    /**
     * @param string $slug
     * @param string $site_id
     * @return string|null
     */
    private function s3_slug_to_shape($slug, $site_id) {
        foreach (array('asscher', 'cushion', 'cushion elongated', 'emerald', 'princess') as $shape) {
            if ($this->config->shape_to_s3_slug($shape) === $slug) {
                return str_replace(' ', '-', strtolower($shape));
            }
        }
        $raw = $this->config->slug_to_shape($slug, $site_id);
        if ($raw === null) {
            return null;
        }
        return str_replace(' ', '-', strtolower($raw));
    }
}

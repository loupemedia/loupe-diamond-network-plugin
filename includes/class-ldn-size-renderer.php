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

    /**
     * Only disclose the retailer count in copy once it is a credible breadth
     * signal; below this a small count reads as unimpressive, so the sample
     * size stands alone. Mirrors RETAILER_COUNT_DISCLOSURE_THRESHOLD in
     * Sizing/size_artefacts.py.
     */
    const RETAILER_DISCLOSURE_THRESHOLD = 15;

    /** @var LDN_Data_Fetcher */
    private $fetcher;

    /** @var LDN_Config */
    private $config;

    /** @var LDN_Renderer|null */
    private $price_renderer = null;

    /** @var array|null|false Per-request cache for the size-checker manifest (false = not fetched). */
    private $checker_manifest_cache = false;

    /** @var bool Manifest JSON already embedded on this page. */
    private $checker_manifest_emitted = false;

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
        $out .= '<section class="ldn-section ldn-size-intro">';
        $out .= $this->intro_paragraphs_html($ctx, $summary, $copy);
        $out .= $this->copy_notes_html($copy);
        $out .= '</section>';

        if ($ctx->page_level === 'size-comparison') {
            $out .= $this->comparison_body_html($ctx, $summary);
        } elseif ($ctx->page_level === 'size-comparison-tool') {
            $out .= $this->size_checker_body_html($ctx, $summary);
        } elseif ($ctx->page_level === 'size-methodology') {
            $out .= $this->methodology_body_html($ctx, $summary, $copy);
        } elseif ($ctx->page_level === 'size-individual') {
            $out .= $this->individual_body_html($ctx, $summary, $copy);
            $out .= $this->methodology_html($summary, $ctx->site_id);
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
     * Intro paragraphs inside the header band. The mega hub uses the templated
     * multi-paragraph intro (total sample size + real-data positioning) from
     * size-copy.json; every other level keeps the single factual lead.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @param array            $copy
     * @return string
     */
    public function intro_paragraphs_html(LDN_Page_Context $ctx, array $summary, array $copy) {
        if ($ctx->page_level === 'size-mega-hub'
            && isset($copy['intro_paragraphs']) && is_array($copy['intro_paragraphs'])
            && $copy['intro_paragraphs'] !== array()
        ) {
            $out = '';
            foreach ($copy['intro_paragraphs'] as $i => $para) {
                if (!is_string($para) || trim($para) === '') {
                    continue;
                }
                $cls = $i === 0 ? 'ldn-size-factual ldn-intro-dynamic' : 'ldn-size-intro__note';
                $out .= '<p class="' . esc_attr($cls) . '">' . esc_html(trim($para)) . '</p>';
            }
            if ($out !== '') {
                return $out;
            }
        }
        $lead = isset($copy['plain_text']) && is_string($copy['plain_text']) && $copy['plain_text'] !== ''
            ? $copy['plain_text']
            : $this->factual_fallback($summary);
        return '<p class="ldn-size-factual ldn-intro-dynamic">' . esc_html($lead) . '</p>';
    }

    /**
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @param array            $copy Size-copy.json payload (for the spread variation note).
     * @return string
     */
    private function individual_body_html(LDN_Page_Context $ctx, array $summary, array $copy = array()) {
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
        $hero = '';

        if ($scale_svg !== '' || $dims !== '') {
            $hero = '<section class="ldn-section ldn-size-hero">';
            if ($scale_svg !== '') {
                $hero .= '<div class="ldn-size-hero__outline">'
                    . $this->scale_figure_html($scale_svg, $summary) . '</div>';
            }
            if ($dims !== '') {
                $hero .= '<div class="ldn-size-hero__dims">' . $dims . '</div>';
            }
            $hero .= '</section>';
        }

        $spread = '';
        if ($spread_svg !== '') {
            $spread_heading = $this->spread_section_heading($ctx, $summary);
            $variation = $this->variation_note_html($copy);
            $spread = '<section class="ldn-section ldn-size-spread"><h2>'
                . esc_html($spread_heading) . '</h2>'
                . $variation
                . '<p class="ldn-size-spread__lead">'
                . esc_html__(
                    'These outlines show the bottom 10%, average, and top 10% of face-up size from real inventory at this carat weight.',
                    'loupe-diamond-network'
                ) . '</p>'
                . '<figure class="ldn-size-figure ldn-size-figure--spread">'
                . '<div class="ldn-size-outline ldn-size-outline--spread">' . $spread_svg . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                . $this->spread_labels_html($summary)
                . '<figcaption class="ldn-size-figure__caption">'
                . esc_html__('Dashed line = typical crown height · stones aligned on a shared baseline.', 'loupe-diamond-network')
                . '</figcaption></figure>'
                . '</section>';
        }

        return $hero . $spread . $this->cut_grade_html($summary) . $this->chart_html($ctx) . $this->chart_vs_real_html($summary);
    }

    /**
     * Cut-grade segmentation table (round brilliant only).
     *
     * @param array $summary
     * @return string
     */
    public function cut_grade_html(array $summary) {
        $shape = isset($summary['shape']) ? (string) $summary['shape'] : '';
        if ($shape !== 'round') {
            return '';
        }
        $segments = isset($summary['cut_segments']) && is_array($summary['cut_segments'])
            ? $summary['cut_segments'] : array();
        if ($segments === array()) {
            return '';
        }

        $rows = '';
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $label = isset($segment['label']) ? (string) $segment['label'] : '';
            $n = isset($segment['n']) ? (int) $segment['n'] : 0;
            $share = isset($segment['share_pct']) ? (float) $segment['share_pct'] : null;
            $diameter = $this->dig($segment, array('diameter_mm', 'median'));
            $faceup = $this->dig($segment, array('faceup_area_mm2', 'median'));
            $depth = $this->dig($segment, array('depth_percent', 'median'));
            if ($label === '' || $n <= 0) {
                continue;
            }
            $share_txt = $share !== null ? sprintf('%s%%', (string) $share) : '—';
            $diameter_txt = $diameter !== null ? sprintf('Ø %s mm', (string) $diameter) : '—';
            $faceup_txt = $faceup !== null ? sprintf('%s mm²', (string) $faceup) : '—';
            $depth_txt = $depth !== null ? (string) $depth : '—';
            $rows .= '<tr>'
                . '<th scope="row">' . esc_html($label) . '</th>'
                . '<td>' . esc_html(number_format($n)) . '</td>'
                . '<td>' . esc_html($share_txt) . '</td>'
                . '<td>' . esc_html($diameter_txt) . '</td>'
                . '<td>' . esc_html($faceup_txt) . '</td>'
                . '<td>' . esc_html($depth_txt) . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            return '';
        }

        return '<section class="ldn-section ldn-size-cut-grade"><h2>'
            . esc_html__('How does cut grade affect size?', 'loupe-diamond-network') . '</h2>'
            . '<p class="ldn-size-cut-grade__lead">'
            . esc_html__(
                'Median dimensions from real inventory at this carat weight, split by GIA cut grade. The headline size above pools all grades.',
                'loupe-diamond-network'
            ) . '</p>'
            . '<table class="ldn-size-table ldn-size-table--cut-grade"><thead><tr>'
            . '<th scope="col">' . esc_html__('Cut grade', 'loupe-diamond-network') . '</th>'
            . '<th scope="col">' . esc_html__('Stones', 'loupe-diamond-network') . '</th>'
            . '<th scope="col">' . esc_html__('Share', 'loupe-diamond-network') . '</th>'
            . '<th scope="col">' . esc_html__('Median diameter', 'loupe-diamond-network') . '</th>'
            . '<th scope="col">' . esc_html__('Median face-up', 'loupe-diamond-network') . '</th>'
            . '<th scope="col">' . esc_html__('Median depth %', 'loupe-diamond-network') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></section>';
    }

    /**
     * Scale-reference SVG wrapped in a figure with the HTML caption that used to
     * live inside the SVG (in-SVG text scales unpredictably with the container).
     *
     * @param string $svg     Inline SVG markup.
     * @param array  $summary
     * @return string
     */
    public function scale_figure_html($svg, array $summary) {
        $shape = isset($summary['shape'])
            ? strtolower(str_replace('-', ' ', (string) $summary['shape']))
            : 'diamond';
        $carat = isset($summary['carat_band']) ? (string) $summary['carat_band'] : '';
        $caption = $carat !== ''
            ? sprintf(
                /* translators: 1: carat weight, 2: shape name */
                __('Relative actual size (mm): US quarter (24.26 mm) beside the median %1$s carat %2$s.', 'loupe-diamond-network'),
                $carat,
                $shape
            )
            : __('Relative actual size (mm): US quarter (24.26 mm) beside the median stone.', 'loupe-diamond-network');
        return '<figure class="ldn-size-figure ldn-size-figure--scale">'
            . '<div class="ldn-size-outline ldn-size-outline--scale">' . $svg . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            . '<figcaption class="ldn-size-figure__caption">' . esc_html($caption) . '</figcaption>'
            . '</figure>';
    }

    /**
     * HTML tier labels under the spread SVG (bottom 10% / average / top 10%),
     * replacing the in-SVG text that rendered at unpredictable sizes.
     *
     * @param array $summary
     * @return string
     */
    public function spread_labels_html(array $summary) {
        $tiers = array(
            'p10'    => __('Bottom 10% of size', 'loupe-diamond-network'),
            'median' => __('Average size', 'loupe-diamond-network'),
            'p90'    => __('Top 10% of size', 'loupe-diamond-network'),
        );
        $near_round = $this->is_near_round($summary);
        $cells = '';
        foreach ($tiers as $tier => $label) {
            if ($near_round) {
                $d = $this->average_diameter_mm($summary, $tier);
                $dim_txt = $d !== null ? sprintf('Ø %s mm', (string) $d) : '';
            } else {
                $length = $this->dig($summary, array('dimensions_mm', 'length', $tier));
                $width = $this->dig($summary, array('dimensions_mm', 'width', $tier));
                $dim_txt = ($length !== null && $width !== null)
                    ? sprintf('%s × %s mm', (string) $width, (string) $length)
                    : '';
            }
            $faceup = $this->dig($summary, array('faceup_area_mm2', $tier));
            $area_txt = $faceup !== null ? sprintf('%s mm² face-up', (string) $faceup) : '';
            $cells .= '<div class="ldn-size-spread-label"><strong>' . esc_html($label) . '</strong>';
            if ($dim_txt !== '') {
                $cells .= '<span>' . esc_html($dim_txt) . '</span>';
            }
            if ($area_txt !== '') {
                $cells .= '<span>' . esc_html($area_txt) . '</span>';
            }
            $cells .= '</div>';
        }
        return '<div class="ldn-size-spread-labels">' . $cells . '</div>';
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
        if ($ctx->page_level === 'size-comparison-tool') {
            return __('Diamond Size Checker — Check & Compare Diamond Sizes vs Real Market Data', 'loupe-diamond-network');
        }
        if ($ctx->page_level === 'size-methodology') {
            return __('How We Measure Diamond Sizes — Methodology', 'loupe-diamond-network');
        }
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
        if ($ctx->page_level === 'size-comparison-tool') {
            return __('Diamond Size Checker', 'loupe-diamond-network');
        }
        if ($ctx->page_level === 'size-methodology') {
            return __('How We Measure Diamond Sizes', 'loupe-diamond-network');
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
        if (isset($summary['type'])
            && in_array($summary['type'], array('size_checker', 'comparison_tool', 'spread_checker'), true)
        ) {
            return __('Check one diamond against real market sizes or compare two diamonds side by side.', 'loupe-diamond-network');
        }
        if (isset($summary['type']) && $summary['type'] === 'methodology') {
            $total = isset($summary['stats']['total_n']) ? (int) $summary['stats']['total_n'] : 0;
            if ($total > 0) {
                return sprintf(
                    /* translators: %s: formatted diamond count */
                    __('How our diamond size data is generated from %s real diamond measurements.', 'loupe-diamond-network'),
                    number_format($total)
                );
            }
            return __('How our diamond size data is generated from real diamond measurements.', 'loupe-diamond-network');
        }
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
        // Consumer-first heading; "face-up size distribution" stays in the lead
        // copy for search/AEO term coverage.
        if ($ctx->shape !== null && $ctx->carat !== null) {
            $heading = sprintf(
                /* translators: 1: carat weight, 2: shape name */
                __('Do all %1$s carat %2$s diamonds look the same size?', 'loupe-diamond-network'),
                $ctx->carat,
                strtolower(str_replace('-', ' ', $ctx->shape))
            );
        } else {
            $heading = __('Face-up size distribution', 'loupe-diamond-network');
        }
        $out = '<section class="ldn-section ldn-chart ldn-size-chart">';
        $out .= '<h2>' . esc_html($heading) . '</h2>';
        $out .= '<p class="ldn-size-chart__lead">' . esc_html__(
            'Real stones of the same carat weight spread across a range of face-up sizes. This face-up size distribution shows how many stones fall in each size band; the dotted line marks the industry chart ideal.',
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
        if (!empty($summary['shape'])) {
            $rows .= '<tr><th>' . esc_html__('Shape', 'loupe-diamond-network')
                . '</th><td>' . esc_html(ucwords(str_replace('-', ' ', (string) $summary['shape']))) . '</td></tr>';
        }
        if (!empty($summary['carat_band'])) {
            $rows .= '<tr><th>' . esc_html__('Carat weight', 'loupe-diamond-network')
                . '</th><td>' . esc_html((string) $summary['carat_band'] . ' ct') . '</td></tr>';
        }
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
        $ideal_len = $this->dig($summary, array('ideal', 'length_mm'));
        $ideal_wid = $this->dig($summary, array('ideal', 'width_mm'));
        $median_len = $this->dig($summary, array('dimensions_mm', 'length', 'median'));
        $median_wid = $this->dig($summary, array('dimensions_mm', 'width', 'median'));
        $ideal_face = $this->dig($summary, array('ideal', 'faceup_area_mm2'));
        $median_face = $this->dig($summary, array('faceup_area_mm2', 'median'));
        if ($ideal_len === null || $ideal_wid === null || $median_len === null || $median_wid === null) {
            return '';
        }
        $shape = isset($summary['shape']) ? ucwords(str_replace('-', ' ', (string) $summary['shape'])) : 'Diamond';
        $carat = isset($summary['carat_band']) ? (string) $summary['carat_band'] : '';
        $n = isset($summary['n']) ? (int) $summary['n'] : 0;
        $n_phrase = $n > 0 ? sprintf(
            /* translators: %s: formatted sample count */
            __('Based on %s assessed stones.', 'loupe-diamond-network'),
            number_format($n)
        ) : '';
        if ($this->is_near_round($summary)) {
            $ideal_d = round(((float) $ideal_len + (float) $ideal_wid) / 2, 2);
            $median_d = $this->average_diameter_mm($summary, 'median');
            $text = sprintf(
                /* translators: 1: carat, 2: shape, 3: median L, 4: median W, 5: ideal L, 6: ideal W, 7: ideal diameter, 8: median diameter */
                __(
                    'Chart sites often quote a %1$s carat %2$s at %5$s x %6$s mm (about %7$s mm diameter). '
                    . 'Our inventory median is %3$s x %4$s mm',
                    'loupe-diamond-network'
                ),
                $carat,
                $shape,
                (string) $median_len,
                (string) $median_wid,
                (string) $ideal_len,
                (string) $ideal_wid,
                (string) $ideal_d
            );
            if ($median_d !== null) {
                $text .= sprintf(
                    /* translators: %s: median diameter mm */
                    __(' (~%s mm diameter)', 'loupe-diamond-network'),
                    (string) $median_d
                );
            }
            $text .= '.';
        } else {
            $text = sprintf(
                /* translators: 1: carat, 2: shape, 3-6: median and ideal L×W mm */
                __(
                    'Chart sites often quote a %1$s carat %2$s at %5$s x %6$s mm. '
                    . 'Our inventory median is %3$s x %4$s mm.',
                    'loupe-diamond-network'
                ),
                $carat,
                $shape,
                (string) $median_len,
                (string) $median_wid,
                (string) $ideal_len,
                (string) $ideal_wid
            );
        }
        if ($ideal_face !== null && $median_face !== null && (float) $ideal_face !== 0.0) {
            $pct = round(((float) $median_face - (float) $ideal_face) / (float) $ideal_face * 100, 1);
            $direction = $pct >= 0 ? 'larger' : 'smaller';
            $text .= ' ' . sprintf(
                /* translators: 1: median face-up, 2: percent, 3: larger/smaller, 4: ideal face-up */
                __(
                    'Face-up area medians %1$s mm² — about %2$s%% %3$s than the chart ideal of %4$s mm².',
                    'loupe-diamond-network'
                ),
                (string) $median_face,
                (string) abs($pct),
                $direction,
                (string) $ideal_face
            );
        }
        if ($n_phrase !== '') {
            $text .= ' ' . $n_phrase;
        }
        return '<div class="ldn-size-ideal-real"><p>' . esc_html($text) . '</p></div>';
    }

    /**
     * Merged "chart numbers vs real stones" section: the ideal-vs-real callout
     * and the depth↔face-up correlation narrative under a single heading.
     *
     * @param array $summary
     * @return string
     */
    public function chart_vs_real_html(array $summary) {
        $ideal = $this->ideal_vs_real_html($summary);
        $depth = $this->proportions_html($summary);
        if ($ideal === '' && $depth === '') {
            return '';
        }
        return '<section class="ldn-section ldn-size-chart-vs-real"><h2>'
            . esc_html__('Chart numbers vs real stones', 'loupe-diamond-network') . '</h2>'
            . $ideal . $depth . '</section>';
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
            $matrix = $this->mega_matrix_table_html($ctx, $summary);
            $out .= $matrix !== '' ? $matrix : $this->hub_table_html($ctx, $summary);
            $out .= $this->size_checker_cta_html($ctx->site_id);
            return $out;
        }
        if ($ctx->page_level === 'size-shape-hub') {
            $out .= $this->shape_hub_scale_html($ctx);
        }
        $out .= $this->hub_table_html($ctx, $summary);
        if ($ctx->page_level === 'size-shape-hub') {
            $out .= $this->size_checker_widget_html($ctx);
        }
        return $out;
    }

    /**
     * Mega-hub matrix table: one row per shape, one column per anchor carat,
     * each cell a true-to-scale outline with L×W mm linking to the individual
     * page. Sticky header + sticky shape column via CSS. Returns '' when the
     * summary has no matrix payload (pre-migration artefacts) so the caller
     * can fall back to the flat ladder table.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary Mega hub summary with matrix{carats, rows}.
     * @return string
     */
    public function mega_matrix_table_html(LDN_Page_Context $ctx, array $summary) {
        $matrix = isset($summary['matrix']) && is_array($summary['matrix']) ? $summary['matrix'] : array();
        $carats = isset($matrix['carats']) && is_array($matrix['carats']) ? $matrix['carats'] : array();
        $rows = isset($matrix['rows']) && is_array($matrix['rows']) ? $matrix['rows'] : array();
        if ($carats === array() || $rows === array()) {
            return '';
        }
        $site_id = $ctx->site_id;

        $head = '<th class="ldn-size-matrix__shape-col">'
            . esc_html__('Shape', 'loupe-diamond-network') . '</th>';
        foreach ($carats as $carat) {
            $head .= '<th>' . esc_html((string) $carat . ' ct') . '</th>';
        }

        $body = '';
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['shape'])) {
                continue;
            }
            $shape = (string) $row['shape'];
            $label = isset($row['label']) ? (string) $row['label'] : ucwords(str_replace('-', ' ', $shape));
            $hub_url = $this->build_size_shape_hub_url($site_id, $shape);
            $shape_cell = $hub_url !== ''
                ? '<a href="' . esc_url($hub_url) . '">' . esc_html($label) . '</a>'
                : esc_html($label);
            $body .= '<tr><th class="ldn-size-matrix__shape-col" scope="row">' . $shape_cell . '</th>';
            $cells = isset($row['cells']) && is_array($row['cells']) ? $row['cells'] : array();
            foreach ($carats as $carat) {
                $carat = (string) $carat;
                $cell = isset($cells[$carat]) && is_array($cells[$carat]) ? $cells[$carat] : null;
                if ($cell === null) {
                    $body .= '<td class="ldn-size-matrix__cell ldn-size-matrix__cell--empty">—</td>';
                    continue;
                }
                $url = $this->build_size_individual_url($site_id, $shape, $carat);
                $svg = (!empty($cell['outline_svg']) && is_string($cell['outline_svg']))
                    ? '<span class="ldn-size-matrix__outline">' . $cell['outline_svg'] . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    : '';
                $dims = '';
                if (isset($cell['length_mm'], $cell['width_mm'])) {
                    $dims = '<span class="ldn-size-matrix__dims">'
                        . esc_html((string) $cell['length_mm'] . ' × ' . (string) $cell['width_mm'] . ' mm')
                        . '</span>';
                }
                $inner = $svg . $dims;
                $body .= '<td class="ldn-size-matrix__cell">'
                    . ($url !== ''
                        ? '<a class="ldn-size-matrix__link" href="' . esc_url($url) . '">' . $inner . '</a>'
                        : $inner)
                    . '</td>';
            }
            $body .= '</tr>';
        }
        if ($body === '') {
            return '';
        }
        return '<section class="ldn-section ldn-size-hub-table ldn-size-matrix-section"><h2>'
            . esc_html__('Diamond size chart by shape and carat weight', 'loupe-diamond-network')
            . '</h2><p class="ldn-size-matrix__lead">' . esc_html__(
                'Outlines are drawn to a shared scale — a 3 carat stone really is that much bigger than a 1 carat. Click any size for the full breakdown, or a shape for its complete carat-by-carat chart.',
                'loupe-diamond-network'
            ) . '</p><div class="ldn-size-matrix-scroll"><table class="ldn-size-table ldn-size-matrix">'
            . '<thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div></section>';
    }

    /**
     * Interactive true-scale explorer for a shape hub: carat slider (snapping
     * to available bands) + in-place shape dropdown, defaulting to this hub's
     * shape at 1 ct. The server-rendered 1 ct scale figure stays inside the
     * mount as the crawlable no-JS fallback; size-scale-explorer.js progressively
     * enhances it using the shared size-checker manifest.
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
        $out = '<section class="ldn-section ldn-size-hub-scale" id="ldn-size-scale-explorer"'
            . ' data-shape="' . esc_attr($ctx->shape) . '" data-carat="1">';
        $out .= '<h2>' . esc_html(sprintf(
            /* translators: %s: diamond shape */
            __('%s diamonds at actual size', 'loupe-diamond-network'),
            $shape_label
        )) . '</h2>';
        $out .= '<p class="ldn-size-chart__lead">' . esc_html__(
            'Drag the slider to change the carat weight and watch the stone grow beside a US quarter (24.26 mm). Switch shape to compare outlines at true relative scale.',
            'loupe-diamond-network'
        ) . '</p>';
        $out .= '<div class="ldn-size-scale-controls" id="ldn-scale-controls" hidden>';
        $out .= '<div class="ldn-size-scale-control"><label for="ldn-scale-shape">'
            . esc_html__('Shape', 'loupe-diamond-network') . '</label>'
            . '<select id="ldn-scale-shape"></select></div>';
        $out .= '<div class="ldn-size-scale-control ldn-size-scale-control--slider">'
            . '<label for="ldn-scale-carat">' . esc_html__('Carat weight', 'loupe-diamond-network')
            . ' <output id="ldn-scale-carat-out">1 ct</output></label>'
            . '<input type="range" id="ldn-scale-carat" min="0" max="1" step="1" value="0">'
            . '</div>';
        $out .= '</div>';
        $out .= '<div id="ldn-scale-figure">' . $this->scale_figure_html($svg, $rep) . '</div>';
        $out .= $this->checker_manifest_script($ctx);
        $out .= '</section>';
        return $out;
    }

    /**
     * Fetch the shared size-checker manifest (compare/ prefix), cached per request.
     *
     * @param LDN_Page_Context $ctx
     * @return array|null
     */
    public function checker_manifest(LDN_Page_Context $ctx) {
        if ($this->checker_manifest_cache !== false) {
            return $this->checker_manifest_cache;
        }
        $tool_ctx = $ctx->page_level === 'size-comparison-tool'
            ? $ctx
            : new LDN_Page_Context(
                $ctx->site_id,
                'size-comparison-tool',
                $ctx->country_code,
                null,
                null,
                null,
                'size'
            );
        $manifest = $this->fetcher->fetch_artefact('size_summary_json', $tool_ctx);
        $this->checker_manifest_cache = (is_array($manifest)
            && isset($manifest['type'])
            && in_array($manifest['type'], array('size_checker', 'spread_checker'), true))
            ? $manifest
            : null;
        return $this->checker_manifest_cache;
    }

    /**
     * Embed the size-checker manifest JSON once per page (explorer + widget share it).
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function checker_manifest_script(LDN_Page_Context $ctx) {
        if ($this->checker_manifest_emitted) {
            return '';
        }
        $manifest = $this->checker_manifest($ctx);
        if ($manifest === null) {
            return '';
        }
        $json = wp_json_encode($manifest, self::JSON_SCRIPT_FLAGS);
        if (!is_string($json) || $json === '') {
            return '';
        }
        $this->checker_manifest_emitted = true;
        return '<script type="application/json" id="ldn-size-checker-manifest">'
            . str_replace('</', '<\/', $json) . '</script>';
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
        $has_lw = false;
        $has_depth = false;
        $has_visual = false;
        foreach ($rows as $probe) {
            if (!is_array($probe)) {
                continue;
            }
            if (isset($probe['lw_ratio'])) {
                $has_lw = true;
            }
            if (isset($probe['depth_pct'])) {
                $has_depth = true;
            }
            if (!empty($probe['outline_svg']) && is_string($probe['outline_svg'])) {
                $has_visual = true;
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
            if ($has_visual) {
                $thumb = (!empty($row['outline_svg']) && is_string($row['outline_svg']))
                    ? '<span class="ldn-size-table-thumb">' . $row['outline_svg'] . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    : '';
                $body .= '<td class="ldn-size-table-thumb-cell">' . $thumb . '</td>';
            }
            $body .= '<td>' . esc_html((string) ($row['length_mm'] ?? '')) . '</td>';
            $body .= '<td>' . esc_html((string) ($row['width_mm'] ?? '')) . '</td>';
            $body .= '<td>' . esc_html((string) ($row['faceup_area_mm2'] ?? '')) . '</td>';
            if ($has_lw) {
                $body .= '<td>' . esc_html((string) ($row['lw_ratio'] ?? '')) . '</td>';
            }
            if ($has_depth) {
                $body .= '<td>' . esc_html((string) ($row['depth_pct'] ?? '')) . '</td>';
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
        $head .= '<th>' . esc_html__('Carat', 'loupe-diamond-network') . '</th>';
        if ($has_visual) {
            $head .= '<th class="ldn-size-table-thumb-col">' . esc_html__('Size', 'loupe-diamond-network') . '</th>';
        }
        $head .= '<th>' . esc_html__('Length (mm)', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Width (mm)', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Face-up (mm²)', 'loupe-diamond-network') . '</th>';
        if ($has_lw) {
            $head .= '<th>' . esc_html__('L/W ratio', 'loupe-diamond-network') . '</th>';
        }
        if ($has_depth) {
            $head .= '<th>' . esc_html__('Depth %', 'loupe-diamond-network') . '</th>';
        }
        if ($ctx->page_level === 'size-shape-hub' && $ctx->shape !== null) {
            $title = esc_html(sprintf(
                /* translators: %s: diamond shape */
                __('%s diamond size chart by carat weight', 'loupe-diamond-network'),
                ucwords(str_replace('-', ' ', $ctx->shape))
            ));
        } else {
            $title = esc_html__('All shapes and carat weights', 'loupe-diamond-network');
        }
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
        $currency = $this->config->get_currency($ctx->site_id, $country);
        $cards = array();
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
            $label = $dtype === 'lab-grown' ? __('Lab-grown', 'loupe-diamond-network') : __('Natural', 'loupe-diamond-network');
            $card = $this->price_snapshot_card_html($price_ctx, $url, $label, $currency);
            if ($card !== '') {
                $cards[] = $card;
            } else {
                $links[] = '<a href="' . esc_url($url) . '">'
                    . esc_html(sprintf(
                        /* translators: %s: diamond type label */
                        __('%s prices', 'loupe-diamond-network'),
                        $label
                    )) . '</a>';
            }
        }
        if ($cards === array() && $links === array()) {
            return '';
        }
        $out = '<section class="ldn-section ldn-size-price-links"><h2>'
            . esc_html(sprintf(
                /* translators: 1: carat weight, 2: shape name */
                __('What does a %1$s carat %2$s cost?', 'loupe-diamond-network'),
                (string) $ctx->carat,
                strtolower(str_replace('-', ' ', (string) $ctx->shape))
            )) . '</h2>';
        if ($cards !== array()) {
            $out .= '<div class="ldn-size-price-cards">' . implode('', $cards) . '</div>';
        }
        if ($links !== array()) {
            $out .= '<p>' . esc_html__('View diamond prices for this size:', 'loupe-diamond-network')
                . ' ' . implode(' · ', $links) . '</p>';
        }
        return $out . '</section>';
    }

    /**
     * Compact live price snapshot for one diamond type, sourced from the same
     * summary-data.json that powers the pricing page hero. Returns '' when no
     * usable price figure exists so the caller can fall back to a plain link.
     *
     * @param LDN_Page_Context $price_ctx
     * @param string           $url      Pricing page URL.
     * @param string           $label    "Natural" | "Lab-grown".
     * @param string|null      $currency
     * @return string
     */
    public function price_snapshot_card_html(LDN_Page_Context $price_ctx, $url, $label, $currency) {
        $summary = $this->fetcher->fetch_artefact('summary_data_json', $price_ctx);
        if (!is_array($summary)) {
            return '';
        }
        $price_renderer = $this->price_renderer();
        $current = $this->dig_any($summary, array(
            array('distribution', 'median_price'),
            array('time_series', 'current_price'),
            array('current_price'),
            array('median_price'),
        ));
        if ($current === null || !is_numeric($current)) {
            return '';
        }
        $low = $this->dig_any($summary, array(
            array('distribution', 'price_range', 'min'),
            array('min_price'),
        ));
        $high = $this->dig_any($summary, array(
            array('distribution', 'price_range', 'max'),
            array('max_price'),
        ));
        $samples = $this->dig_any($summary, array(
            array('distribution', 'sample_size'),
            array('num_diamonds'),
            array('sample_size'),
        ));

        $out = '<a class="ldn-size-price-card" href="' . esc_url($url) . '">';
        $out .= '<span class="ldn-size-price-card__type">' . esc_html($label) . '</span>';
        $out .= '<span class="ldn-size-price-card__price">'
            . esc_html($price_renderer->format_stat($current, 'currency', $currency)) . '</span>';
        if (is_numeric($low) && is_numeric($high) && (float) $high > 0) {
            $out .= '<span class="ldn-size-price-card__range">'
                . esc_html(sprintf(
                    /* translators: 1: low price, 2: high price */
                    __('Range %1$s – %2$s', 'loupe-diamond-network'),
                    $price_renderer->format_stat($low, 'currency', $currency),
                    $price_renderer->format_stat($high, 'currency', $currency)
                )) . '</span>';
        }
        if (is_numeric($samples) && (int) $samples > 0) {
            $out .= '<span class="ldn-size-price-card__samples">'
                . esc_html(sprintf(
                    /* translators: %s: diamond count */
                    __('Based on %s diamonds', 'loupe-diamond-network'),
                    number_format((int) $samples)
                )) . '</span>';
        }
        $out .= '<span class="ldn-size-price-card__cta">'
            . esc_html__('See full price analysis', 'loupe-diamond-network') . ' →</span>';
        $out .= '</a>';
        return $out;
    }

    /**
     * First non-null value across candidate paths into a nested array.
     *
     * @param array $arr
     * @param array $paths
     * @return mixed|null
     */
    private function dig_any(array $arr, array $paths) {
        foreach ($paths as $path) {
            $value = $this->dig($arr, $path);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
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
        foreach (array('faceup_note', 'lw_note') as $key) {
            if (!empty($blocks[$key]) && is_string($blocks[$key])) {
                $parts[] = '<p>' . esc_html(trim($blocks[$key])) . '</p>';
            }
        }
        if ($parts === array()) {
            return '';
        }
        return implode('', $parts);
    }

    /**
     * Shape-specific variation note for the spread section.
     *
     * @param array $copy
     * @return string
     */
    public function variation_note_html(array $copy) {
        $blocks = isset($copy['copy']) && is_array($copy['copy']) ? $copy['copy'] : array();
        if (empty($blocks['variation_note']) || !is_string($blocks['variation_note'])) {
            return '';
        }
        return '<p class="ldn-size-variation-note">' . esc_html(trim($blocks['variation_note'])) . '</p>';
    }

    /**
     * Dynamic heading for the size-spread section on individual pages.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @return string
     */
    public function spread_section_heading(LDN_Page_Context $ctx, array $summary) {
        $carat = $ctx->carat !== null ? (string) $ctx->carat : (isset($summary['carat_band']) ? (string) $summary['carat_band'] : '');
        $shape = $ctx->shape !== null
            ? ucwords(str_replace('-', ' ', (string) $ctx->shape))
            : (isset($summary['shape']) ? ucwords(str_replace('-', ' ', (string) $summary['shape'])) : 'Diamond');
        if ($carat === '') {
            return __('How much do diamond sizes vary?', 'loupe-diamond-network');
        }
        return sprintf(
            /* translators: 1: carat weight, 2: shape name */
            __('How much do %1$s carat %2$s diamond sizes vary?', 'loupe-diamond-network'),
            $carat,
            strtolower($shape)
        );
    }

    /**
     * Depth %, L/W spread, and depth↔face-up narrative.
     *
     * @param array $summary
     * @return string
     */
    public function proportions_html(array $summary) {
        $corr = $summary['depth_faceup_corr'] ?? null;
        if ($corr === null || $summary['source'] !== 'real') {
            return '';
        }
        $abs = abs((float) $corr);
        if ($abs < 0.15) {
            return '';
        }
        $direction = (float) $corr < 0 ? 'smaller' : 'larger';
        $n = isset($summary['n']) ? (int) $summary['n'] : 0;
        $para = sprintf(
            /* translators: 1: sample count, 2: smaller|larger */
            __(
                'In our sample of %1$s stones, deeper-cut diamonds tend to face up %2$s for their carat weight — total depth %% correlates with face-up area.',
                'loupe-diamond-network'
            ),
            number_format($n),
            $direction
        );
        return '<div class="ldn-size-proportions"><p>' . esc_html($para) . '</p></div>';
    }

    /**
     * Data methodology transparency strip (links to the full methodology page).
     *
     * @param array       $summary
     * @param string|null $site_id When given, appends a link to /diamond-size/methodology/.
     * @return string
     */
    public function methodology_html(array $summary, $site_id = null) {
        $n = isset($summary['n']) ? (int) $summary['n'] : 0;
        $source = isset($summary['source']) ? (string) $summary['source'] : '';
        if ($n <= 0 && $source === '') {
            return '';
        }
        $parts = array();
        if ($source === 'real' && $n > 0) {
            $rc = isset($summary['retailer_count']) ? (int) $summary['retailer_count'] : 0;
            if ($rc >= self::RETAILER_DISCLOSURE_THRESHOLD) {
                $parts[] = sprintf(
                    /* translators: 1: sample count, 2: retailer count */
                    __('Measurements are aggregated from %1$s real diamonds from %2$d retailers.', 'loupe-diamond-network'),
                    number_format($n),
                    $rc
                );
            } else {
                $parts[] = sprintf(
                    /* translators: %s: sample count */
                    __('Measurements are aggregated from %s real diamonds.', 'loupe-diamond-network'),
                    number_format($n)
                );
            }
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
        $link = '';
        if ($site_id !== null) {
            $url = $this->build_methodology_url($site_id);
            if ($url !== '') {
                $link = ' <a href="' . esc_url($url) . '">'
                    . esc_html__('Read our full methodology', 'loupe-diamond-network') . '</a>.';
            }
        }
        return '<section class="ldn-section ldn-size-methodology"><h2>'
            . esc_html__('About this data', 'loupe-diamond-network') . '</h2><p>'
            . esc_html(implode(' ', $parts)) . $link . '</p></section>';
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

        if ($ctx->page_level === 'size-comparison-tool') {
            $trail[] = array(
                'name' => __('Size Checker', 'loupe-diamond-network'),
                'url'  => $canonical_url,
            );
        }

        if ($ctx->page_level === 'size-methodology') {
            $trail[] = array(
                'name' => __('Methodology', 'loupe-diamond-network'),
                'url'  => $canonical_url,
            );
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
        if ($n <= 0 && isset($summary['total_n'])) {
            $n = (int) $summary['total_n'];
        }
        $domain = isset($site['domain']) ? (string) $site['domain'] : '';
        $brand = isset($site['brand_name']) ? (string) $site['brand_name'] : '';
        $site_url = $domain !== '' ? 'https://' . $domain : '';
        $generated_date = isset($summary['generated_date']) && is_string($summary['generated_date'])
            ? $summary['generated_date'] : '';

        $org_ref = $site_url !== ''
            ? array('@id' => rtrim($site_url, '/') . '/#organization')
            : array('@type' => 'Organization', 'name' => $brand);

        if ($site_url !== '') {
            $graph[] = array(
                '@type'     => 'WebSite',
                '@id'       => rtrim($site_url, '/') . '/#website',
                'url'       => $site_url,
                'name'      => $brand,
                'publisher' => $org_ref,
            );
        }

        $webpage = array(
            '@type'       => 'WebPage',
            '@id'         => $canonical . '#webpage',
            'url'         => $canonical,
            'name'        => $title,
            'description' => $description,
        );
        if ($site_url !== '') {
            $webpage['isPartOf'] = array('@id' => rtrim($site_url, '/') . '/#website');
        }
        if ($generated_date !== '') {
            $webpage['dateModified'] = $generated_date;
        }
        $graph[] = $webpage;

        $measured = array();
        if ($length !== null) {
            $measured[] = array('@type' => 'PropertyValue', 'name' => 'length_mm', 'value' => $length, 'unitText' => 'mm');
        }
        if ($width !== null) {
            $measured[] = array('@type' => 'PropertyValue', 'name' => 'width_mm', 'value' => $width, 'unitText' => 'mm');
        }
        if ($faceup !== null) {
            $measured[] = array('@type' => 'PropertyValue', 'name' => 'faceup_area_mm2', 'value' => $faceup, 'unitText' => 'mm²');
        }
        if ($n > 0) {
            $measured[] = array('@type' => 'PropertyValue', 'name' => 'sample_size', 'value' => $n);
        }

        $dataset = array(
            '@type'                => 'Dataset',
            'name'                 => $title,
            'description'          => $description,
            'url'                  => $canonical,
            'creator'              => $org_ref,
            'variableMeasured'     => $measured,
            'measurementTechnique' => 'Aggregated measurements of real retailer diamond inventory (median, P10–P90 percentiles), not calculated from ideal proportion formulas.',
            'license'              => 'https://creativecommons.org/licenses/by-nc/4.0/',
            'isAccessibleForFree'  => true,
        );
        if ($generated_date !== '') {
            $dataset['dateModified'] = $generated_date;
        }
        $graph[] = $dataset;

        if ($ctx->page_level === 'size-comparison-tool') {
            $graph[] = array(
                '@type'                => 'WebApplication',
                '@id'                  => $canonical . '#webapp',
                'name'                 => $title,
                'url'                  => $canonical,
                'applicationCategory'  => 'UtilityApplication',
                'operatingSystem'      => 'Any',
                'description'          => $description,
                'isAccessibleForFree'  => true,
                'offers'               => array('@type' => 'Offer', 'price' => '0'),
                'provider'             => $org_ref,
            );
        }

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
     * @param string $site_id
     * @return string
     */
    public function build_comparison_tool_url($site_id) {
        $structure = $this->config->get_url_structure($site_id);
        $pattern = is_array($structure) && !empty($structure['size_level_compare'])
            ? (string) $structure['size_level_compare']
            : '/diamond-size/compare/{compare}';
        $path = (string) preg_replace('#/\\{compare\\}.*$#', '', $pattern);
        if (!function_exists('home_url')) {
            return $path;
        }
        return home_url(user_trailingslashit(ltrim($path, '/')));
    }

    /**
     * White-box CTA on a purple band (below the mega-hub matrix) linking to the
     * Diamond Size Checker.
     *
     * @param string $site_id
     * @return string
     */
    public function size_checker_cta_html($site_id) {
        $url = $this->build_comparison_tool_url($site_id);
        if ($url === '') {
            return '';
        }
        return '<section class="ldn-section ldn-size-checker-cta-band">'
            . '<div class="ldn-size-checker-cta"><h2>'
            . esc_html__('Check a diamond\'s size', 'loupe-diamond-network') . '</h2><p>'
            . esc_html__(
                'Compare any of the sizes above, or enter a real diamond\'s carat weight, shape and millimetre measurements to see how it ranks against the market — and how it compares to another stone.',
                'loupe-diamond-network'
            ) . '</p><p><a class="ldn-btn ldn-btn--primary" href="' . esc_url($url) . '">'
            . esc_html__('Open the Diamond Size Checker', 'loupe-diamond-network') . '</a></p></div></section>';
    }

    /**
     * One stone panel of the size checker (reference pick or manual mm entry).
     *
     * @param string $prefix   'a' | 'b'
     * @param string $title
     * @param array  $defaults shape/carat/length_mm/width_mm defaults from the manifest.
     * @param array  $shapes
     * @param array  $carat_bands
     * @return string
     */
    private function size_checker_panel_html($prefix, $title, array $defaults, array $shapes, array $carat_bands) {
        $shape = isset($defaults['shape']) ? (string) $defaults['shape'] : 'round';
        $carat = isset($defaults['carat']) ? (string) $defaults['carat'] : '1';
        $length = $defaults['length_mm'] ?? '';
        $width = $defaults['width_mm'] ?? '';
        $p = esc_attr($prefix);

        $shape_opts = '';
        foreach ($shapes as $s) {
            if (!is_string($s) || $s === '') {
                continue;
            }
            $shape_opts .= '<option value="' . esc_attr($s) . '"' . selected($s, $shape, false) . '>'
                . esc_html(ucwords(str_replace('-', ' ', $s))) . '</option>';
        }
        $band_opts = '';
        foreach ($carat_bands as $band) {
            $band = (string) $band;
            $band_opts .= '<option value="' . esc_attr($band) . '"' . selected($band, $carat, false) . '>'
                . esc_html($band . ' ct') . '</option>';
        }

        $out = '<fieldset class="ldn-size-checker-panel ldn-size-checker-panel--' . $p . '"'
            . ' id="ldn-checker-panel-' . $p . '" data-stone="' . $p . '">';
        $out .= '<legend class="ldn-size-checker-panel__title">' . esc_html($title) . '</legend>';

        $out .= '<label for="ldn-checker-shape-' . $p . '">' . esc_html__('Shape', 'loupe-diamond-network') . '</label>';
        $out .= '<select id="ldn-checker-shape-' . $p . '" name="shape_' . $p . '">' . $shape_opts . '</select>';

        $out .= '<div class="ldn-size-checker-mode" role="radiogroup" aria-label="'
            . esc_attr__('Measurement source', 'loupe-diamond-network') . '">';
        $out .= '<label class="ldn-size-checker-mode__option"><input type="radio" name="mode_' . $p
            . '" value="reference" checked> ' . esc_html__('Typical market size', 'loupe-diamond-network') . '</label>';
        $out .= '<label class="ldn-size-checker-mode__option"><input type="radio" name="mode_' . $p
            . '" value="manual"> ' . esc_html__('Enter measurements', 'loupe-diamond-network') . '</label>';
        $out .= '</div>';

        $out .= '<div class="ldn-size-checker-fields ldn-size-checker-fields--reference" id="ldn-checker-ref-' . $p . '">';
        $out .= '<label for="ldn-checker-band-' . $p . '">' . esc_html__('Carat weight', 'loupe-diamond-network') . '</label>';
        $out .= '<select id="ldn-checker-band-' . $p . '" name="band_' . $p . '">' . $band_opts . '</select>';
        $out .= '</div>';

        $out .= '<div class="ldn-size-checker-fields ldn-size-checker-fields--manual" id="ldn-checker-manual-' . $p . '" hidden>';
        $out .= '<label for="ldn-checker-carat-' . $p . '">' . esc_html__('Carat weight', 'loupe-diamond-network') . '</label>';
        $out .= '<input type="number" id="ldn-checker-carat-' . $p . '" name="carat_' . $p
            . '" min="0.1" max="20" step="0.01" value="' . esc_attr($carat) . '" inputmode="decimal">';
        $out .= '<div class="ldn-size-checker-mm">';
        $out .= '<div><label for="ldn-checker-length-' . $p . '">' . esc_html__('Length (mm)', 'loupe-diamond-network') . '</label>'
            . '<input type="number" id="ldn-checker-length-' . $p . '" name="length_' . $p
            . '" min="1" max="30" step="0.01" value="' . esc_attr((string) $length) . '" inputmode="decimal"></div>';
        $out .= '<div><label for="ldn-checker-width-' . $p . '">' . esc_html__('Width (mm)', 'loupe-diamond-network') . '</label>'
            . '<input type="number" id="ldn-checker-width-' . $p . '" name="width_' . $p
            . '" min="1" max="30" step="0.01" value="' . esc_attr((string) $width) . '" inputmode="decimal"></div>';
        $out .= '<div><label for="ldn-checker-depth-' . $p . '">' . esc_html__('Depth (mm, optional)', 'loupe-diamond-network') . '</label>'
            . '<input type="number" id="ldn-checker-depth-' . $p . '" name="depth_' . $p
            . '" min="0.5" max="25" step="0.01" inputmode="decimal"></div>';
        $out .= '</div>';
        $out .= '<p class="ldn-size-checker-hint">' . esc_html__(
            'Copy these from the listing or grading certificate (shown as length × width × depth).',
            'loupe-diamond-network'
        ) . '</p>';
        $out .= '</div>';

        $out .= '</fieldset>';
        return $out;
    }

    /**
     * Merged Diamond Size Checker: analyse one stone against real-market
     * percentiles, or compare two stones (reference medians or manual mm).
     * Results render in a separate section after the Check button is pressed.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary size_checker manifest from S3.
     * @param bool             $widget  Compact widget mode (shape hubs etc.).
     * @return string
     */
    public function size_checker_body_html(LDN_Page_Context $ctx, array $summary, $widget = false) {
        $shapes = isset($summary['shapes']) && is_array($summary['shapes']) ? $summary['shapes'] : array();
        $carat_bands = isset($summary['carat_bands']) && is_array($summary['carat_bands'])
            ? $summary['carat_bands'] : array();
        if ($shapes === array() || $carat_bands === array()) {
            return '';
        }

        $default_a = isset($summary['default_a']) && is_array($summary['default_a'])
            ? $summary['default_a'] : array('shape' => 'oval', 'carat' => '1.1');
        $default_b = isset($summary['default_b']) && is_array($summary['default_b'])
            ? $summary['default_b'] : array('shape' => 'oval', 'carat' => '1.3');

        $out = '<section class="ldn-section ldn-size-checker' . ($widget ? ' ldn-size-checker--widget' : '') . '"'
            . ' id="ldn-size-checker"'
            . ' data-compare-base="' . esc_attr($this->build_comparison_tool_url($ctx->site_id)) . '">';

        if ($widget) {
            $out .= '<h2>' . esc_html__('Diamond Size Checker', 'loupe-diamond-network') . '</h2>';
            $out .= '<p class="ldn-size-chart__lead">' . esc_html__(
                'Check a diamond against real market sizes, or compare two stones side by side.',
                'loupe-diamond-network'
            ) . '</p>';
        }

        $out .= '<form class="ldn-size-checker-form" id="ldn-size-checker-form" action="#" method="get">';
        $out .= '<div class="ldn-size-checker-panels">';
        $out .= $this->size_checker_panel_html(
            'a',
            __('Your diamond', 'loupe-diamond-network'),
            $default_a,
            $shapes,
            $carat_bands
        );
        $out .= '<div class="ldn-size-checker-second" id="ldn-checker-second">';
        $out .= '<label class="ldn-size-checker-toggle"><input type="checkbox" id="ldn-checker-enable-b"> '
            . esc_html__('Compare with a second diamond', 'loupe-diamond-network') . '</label>';
        $out .= '<div id="ldn-checker-panel-b-wrap" hidden>';
        $out .= $this->size_checker_panel_html(
            'b',
            __('Second diamond', 'loupe-diamond-network'),
            $default_b,
            $shapes,
            $carat_bands
        );
        $out .= '</div></div>';
        $out .= '</div>';
        $out .= '<p class="ldn-size-checker-actions">'
            . '<button type="submit" class="ldn-btn ldn-btn--primary" id="ldn-checker-submit">'
            . esc_html__('Check size', 'loupe-diamond-network') . '</button></p>';
        $out .= '</form>';

        $out .= '<div class="ldn-size-checker-results" id="ldn-size-checker-results" aria-live="polite" hidden>';
        $out .= '<h2 class="ldn-size-checker-results__title">' . esc_html__('Results', 'loupe-diamond-network') . '</h2>';
        $out .= '<div class="ldn-size-checker-results__cards" id="ldn-checker-cards"></div>';
        $out .= '<div class="ldn-size-checker-results__comparison" id="ldn-checker-comparison"></div>';
        $out .= '</div>';

        if (!$widget) {
            $popular = isset($summary['popular']) && is_array($summary['popular']) ? $summary['popular'] : array();
            if ($popular !== array()) {
                $links = '';
                foreach ($popular as $item) {
                    if (!is_array($item) || empty($item['slug'])) {
                        continue;
                    }
                    $slug = (string) $item['slug'];
                    $label = isset($item['label']) ? (string) $item['label'] : $slug;
                    $path = '/diamond-size/compare/' . $slug . '/';
                    $url = function_exists('home_url')
                        ? home_url(user_trailingslashit(ltrim($path, '/')))
                        : $path;
                    $links .= '<li><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
                }
                if ($links !== '') {
                    $out .= '<section class="ldn-section ldn-size-compare-popular"><h2>'
                        . esc_html__('Popular comparisons', 'loupe-diamond-network') . '</h2><ul>'
                        . $links . '</ul></section>';
                }
            }
        } else {
            $tool_url = $this->build_comparison_tool_url($ctx->site_id);
            if ($tool_url !== '') {
                $out .= '<p class="ldn-size-checker-full-link"><a href="' . esc_url($tool_url) . '">'
                    . esc_html__('Open the full Diamond Size Checker', 'loupe-diamond-network') . ' →</a></p>';
            }
        }

        $manifest_json = wp_json_encode($summary, self::JSON_SCRIPT_FLAGS);
        if (is_string($manifest_json) && $manifest_json !== '' && !$this->checker_manifest_emitted) {
            $this->checker_manifest_emitted = true;
            $out .= '<script type="application/json" id="ldn-size-checker-manifest">'
                . str_replace('</', '<\/', $manifest_json) . '</script>';
        }
        $out .= '</section>';

        return $out;
    }

    /**
     * Drop-in size checker widget for non-tool pages (shape hubs, individual
     * pages): fetches the shared manifest from the compare/ prefix and renders
     * the compact checker. Returns '' when the manifest is unavailable.
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function size_checker_widget_html(LDN_Page_Context $ctx) {
        $manifest = $this->checker_manifest($ctx);
        if ($manifest === null) {
            return '';
        }
        if ($ctx->shape !== null) {
            $defaults = isset($manifest['default_a']) && is_array($manifest['default_a'])
                ? $manifest['default_a'] : array();
            $defaults['shape'] = $ctx->shape;
            if ($ctx->carat !== null) {
                $defaults['carat'] = $ctx->carat;
            }
            $manifest['default_a'] = $defaults;
        }
        return $this->size_checker_body_html($ctx, $manifest, true);
    }

    /**
     * Methodology page body: dataset stat cards + templated sections from
     * size-copy.json (why real data, collection, face-up method, drawbacks,
     * freshness).
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary methodology summary with stats{}.
     * @param array            $copy    methodology copy with sections[].
     * @return string
     */
    public function methodology_body_html(LDN_Page_Context $ctx, array $summary, array $copy) {
        $stats = isset($summary['stats']) && is_array($summary['stats']) ? $summary['stats'] : array();
        $out = '';

        $cards = array();
        if (!empty($stats['total_n'])) {
            $cards[] = array(
                __('Real diamonds measured', 'loupe-diamond-network'),
                number_format((int) $stats['total_n']),
            );
        }
        if (!empty($stats['shape_count'])) {
            $cards[] = array(__('Shapes covered', 'loupe-diamond-network'), (string) (int) $stats['shape_count']);
        }
        if (!empty($stats['band_count'])) {
            $cards[] = array(__('Carat weights', 'loupe-diamond-network'), (string) (int) $stats['band_count']);
        }
        if (!empty($stats['retailer_count'])
            && (int) $stats['retailer_count'] >= self::RETAILER_DISCLOSURE_THRESHOLD
        ) {
            $cards[] = array(__('Retailers sampled', 'loupe-diamond-network'), (string) (int) $stats['retailer_count']);
        }
        if ($cards !== array()) {
            $out .= '<section class="ldn-section ldn-size-methodology-stats"><dl class="ldn-stats">';
            foreach ($cards as $card) {
                $out .= '<div><dt>' . esc_html($card[0]) . '</dt><dd>' . esc_html($card[1]) . '</dd></div>';
            }
            $out .= '</dl></section>';
        }

        $sections = isset($copy['sections']) && is_array($copy['sections']) ? $copy['sections'] : array();
        foreach ($sections as $section) {
            if (!is_array($section) || empty($section['heading'])) {
                continue;
            }
            $sid = isset($section['id']) && is_string($section['id']) && $section['id'] !== ''
                ? ' id="' . esc_attr($section['id']) . '"'
                : '';
            $out .= '<section class="ldn-section ldn-size-methodology-section"' . $sid . '><h2>'
                . esc_html((string) $section['heading']) . '</h2>';
            $paragraphs = isset($section['paragraphs']) && is_array($section['paragraphs'])
                ? $section['paragraphs'] : array();
            foreach ($paragraphs as $para) {
                if (is_string($para) && trim($para) !== '') {
                    $out .= '<p>' . esc_html(trim($para)) . '</p>';
                }
            }
            $out .= '</section>';
        }

        $mega_url = $this->build_size_mega_hub_url($ctx->site_id);
        $tool_url = $this->build_comparison_tool_url($ctx->site_id);
        $links = array();
        if ($mega_url !== '') {
            $links[] = '<a href="' . esc_url($mega_url) . '">'
                . esc_html__('Diamond size chart', 'loupe-diamond-network') . '</a>';
        }
        if ($tool_url !== '') {
            $links[] = '<a href="' . esc_url($tool_url) . '">'
                . esc_html__('Diamond Size Checker', 'loupe-diamond-network') . '</a>';
        }
        if ($links !== array()) {
            $out .= '<section class="ldn-section ldn-size-internal-links"><h2>'
                . esc_html__('Explore the data', 'loupe-diamond-network') . '</h2><ul><li>'
                . implode('</li><li>', $links) . '</li></ul></section>';
        }

        return $out;
    }

    /**
     * @param string $site_id
     * @return string
     */
    public function build_methodology_url($site_id) {
        $structure = $this->config->get_url_structure($site_id);
        $path = is_array($structure) && !empty($structure['size_level_methodology'])
            ? (string) $structure['size_level_methodology']
            : '/diamond-size/methodology';
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
        // The router regex appends '-carat' to the {carat} segment
        // (LDN_Size_Router::pattern_to_regex), so the builder must too —
        // otherwise internal links and canonicals point at a 404 variant.
        $path = str_replace(
            array('{shape}', '{carat}'),
            array(
                $this->config->shape_to_s3_slug($shape),
                LDN_Test_Combos::normalise_carat($carat) . '-carat',
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
        if ($ctx->page_level === 'size-comparison-tool') {
            return $this->build_comparison_tool_url($ctx->site_id);
        }
        if ($ctx->page_level === 'size-methodology') {
            return $this->build_methodology_url($ctx->site_id);
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
                . '<h2>' . esc_html__('Face-up area', 'loupe-diamond-network') . '</h2>'
                . $this->comparison_callout_html($summary)
                . $this->comparison_legend_html($summary)
                . '<div class="ldn-faceup-overlay ldn-size-outline ldn-size-outline--comparison">'
                . $svg . '</div>'
                . $this->comparison_faceup_bars_html($summary)
                . '</section>';
        } else {
            $out .= $this->comparison_callout_html($summary);
        }

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
     * @param array $summary Comparison payload with a, b, deltas.
     * @return string
     */
    public function comparison_callout_html(array $summary) {
        if (!isset($summary['a'], $summary['b'])) {
            return '';
        }
        $fa = (float) ($summary['a']['faceup_area_mm2'] ?? 0);
        $fb = (float) ($summary['b']['faceup_area_mm2'] ?? 0);
        if ($fa <= 0 || $fb <= 0) {
            return '';
        }
        $label_a = $this->comparison_stone_label($summary['a']);
        $label_b = $this->comparison_stone_label($summary['b']);
        if (abs($fa - $fb) < 0.01) {
            return '<p class="ldn-faceup-callout ldn-faceup-callout--tie">'
                . esc_html(sprintf(
                    __('Both stones have the same face-up area (%s mm²).', 'loupe-diamond-network'),
                    number_format($fa, 2)
                )) . '</p>';
        }
        $bigger_label = $fa >= $fb ? $label_a : $label_b;
        $smaller = min($fa, $fb);
        $pct = $smaller > 0 ? (int) round(abs($fa - $fb) / $smaller * 100) : 0;
        return '<p class="ldn-faceup-callout"><strong>' . esc_html($bigger_label)
            . '</strong> ' . esc_html__(
                'faces up about',
                'loupe-diamond-network'
            ) . ' <strong>' . esc_html((string) $pct) . '% '
            . esc_html__('larger', 'loupe-diamond-network') . '</strong> '
            . esc_html__('on the finger.', 'loupe-diamond-network') . '</p>';
    }

    /**
     * Color-keyed legend for SSR comparison pages.
     *
     * @param array $summary
     * @return string
     */
    public function comparison_legend_html(array $summary) {
        if (!isset($summary['a'], $summary['b'])) {
            return '';
        }
        $label_a = $this->comparison_stone_label($summary['a']);
        $label_b = $this->comparison_stone_label($summary['b']);
        return '<ul class="ldn-faceup-legend" role="list">'
            . '<li class="ldn-faceup-legend__item ldn-faceup-legend__item--a">'
            . '<span class="ldn-faceup-legend__swatch ldn-faceup-legend__swatch--a" aria-hidden="true"></span>'
            . '<span>' . esc_html($label_a) . '</span></li>'
            . '<li class="ldn-faceup-legend__item ldn-faceup-legend__item--b">'
            . '<span class="ldn-faceup-legend__swatch ldn-faceup-legend__swatch--b" aria-hidden="true"></span>'
            . '<span>' . esc_html($label_b) . '</span></li>'
            . '</ul>';
    }

    /**
     * Horizontal face-up area bars (diamdb-style).
     *
     * @param array $summary
     * @return string
     */
    public function comparison_faceup_bars_html(array $summary) {
        if (!isset($summary['a'], $summary['b'])) {
            return '';
        }
        $fa = (float) ($summary['a']['faceup_area_mm2'] ?? 0);
        $fb = (float) ($summary['b']['faceup_area_mm2'] ?? 0);
        if ($fa <= 0 || $fb <= 0) {
            return '';
        }
        $label_a = $this->comparison_stone_label($summary['a']);
        $label_b = $this->comparison_stone_label($summary['b']);
        $max = max($fa, $fb);
        $pct_a = $max > 0 ? (int) round($fa / $max * 100) : 0;
        $pct_b = $max > 0 ? (int) round($fb / $max * 100) : 0;
        $diff = abs($fa - $fb);
        $smaller = min($fa, $fb);
        $pct_diff = $smaller > 0 ? (int) round($diff / $smaller * 100) : 0;

        $bar = static function ($modifier, $label, $faceup, $pct) {
            return '<div class="ldn-faceup-bar ldn-faceup-bar--' . esc_attr($modifier) . '">'
                . '<div class="ldn-faceup-bar__head">'
                . '<span class="ldn-faceup-bar__label">' . esc_html($label) . '</span>'
                . '<span class="ldn-faceup-bar__value">' . esc_html(number_format($faceup, 2)) . ' mm²</span>'
                . '</div>'
                . '<div class="ldn-faceup-bar__track" role="presentation">'
                . '<div class="ldn-faceup-bar__fill ldn-faceup-bar__fill--' . esc_attr($modifier)
                . '" style="width:' . esc_attr((string) $pct) . '%"></div>'
                . '</div></div>';
        };

        return '<div class="ldn-faceup-bars">'
            . $bar('a', $label_a, $fa, $pct_a)
            . $bar('b', $label_b, $fb, $pct_b)
            . '<p class="ldn-faceup-bars__diff">' . esc_html(sprintf(
                /* translators: 1: mm² difference, 2: percent difference */
                __('Difference: %1$s mm² (%2$s%%)', 'loupe-diamond-network'),
                number_format($diff, 2),
                (string) $pct_diff
            )) . '</p></div>';
    }

    /**
     * @param array $side Comparison side with shape, carat, length_mm, width_mm.
     * @return string
     */
    public function comparison_stone_label(array $side) {
        $shape = ucwords(str_replace('-', ' ', (string) ($side['shape'] ?? '')));
        $carat = (string) ($side['carat'] ?? '');
        $length = $side['length_mm'] ?? null;
        $width = $side['width_mm'] ?? null;
        $label = $carat . ' ct ' . $shape;
        if ($length !== null && $width !== null) {
            $label .= ' (' . number_format((float) $length, 2) . ' × '
                . number_format((float) $width, 2) . ' mm)';
        }
        return $label;
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

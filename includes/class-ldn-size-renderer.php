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

        $out = $this->page_shell_open($ctx);
        $out .= '<h1 class="ldn-page-title">' . esc_html($this->headline($ctx, $summary)) . '</h1>';
        $out .= '<p class="ldn-size-factual ldn-intro-dynamic">'
            . esc_html($this->factual_fallback($summary)) . '</p>';

        if ($ctx->page_level === 'size-individual') {
            $out .= $this->individual_body_html($ctx, $summary);
        } else {
            $out .= $this->hub_table_html($ctx->site_id, $summary);
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
        $svg = $this->fetcher->fetch_artefact_html('shape_outline_svg', $ctx);
        $dims = $this->dimensions_table($summary);
        $hero = '';

        if ((is_string($svg) && $svg !== '') || $dims !== '') {
            $hero = '<section class="ldn-section ldn-size-hero">';
            if (is_string($svg) && $svg !== '') {
                $hero .= '<div class="ldn-size-hero__outline"><div class="ldn-size-outline">' . $svg . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG from our S3
            }
            if ($dims !== '') {
                $hero .= '<div class="ldn-size-hero__dims">' . $dims . '</div>';
            }
            $hero .= '</section>';
        }

        return $hero . $this->chart_html($ctx);
    }

    /**
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @return string
     */
    public function render_head_content(LDN_Page_Context $ctx, array $summary) {
        $canonical = $this->current_url($ctx);
        $title = $this->headline($ctx, $summary);
        $description = $this->factual_fallback($summary);

        $out = '<title>' . esc_html($title) . '</title>' . "\n";
        $out .= '<meta name="description" content="' . esc_attr($description) . '" />' . "\n";
        $out .= '<link rel="canonical" href="' . esc_url($canonical) . '" />' . "\n";
        $out .= '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
        $out .= '<meta property="og:description" content="' . esc_attr($description) . '" />' . "\n";
        $out .= '<meta property="og:url" content="' . esc_url($canonical) . '" />' . "\n";

        return $out;
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
        return 'Diamond Size';
    }

    /**
     * Plain-text factual statement for AEO (from size-summary fields).
     *
     * @param array $summary
     * @return string
     */
    public function factual_fallback(array $summary) {
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
        $out .= '<h2>' . esc_html__('Size distribution', 'loupe-diamond-network') . '</h2>';
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
        $length = $this->dig($summary, array('dimensions_mm', 'length', 'median'));
        $width = $this->dig($summary, array('dimensions_mm', 'width', 'median'));
        $faceup = $this->dig($summary, array('faceup_area_mm2', 'median'));
        $lw = $this->dig($summary, array('lw_ratio', 'median'));
        $rows = '';
        if ($length !== null) {
            $rows .= '<tr><th>Length (mm)</th><td>' . esc_html((string) $length) . '</td></tr>';
        }
        if ($width !== null) {
            $rows .= '<tr><th>Width (mm)</th><td>' . esc_html((string) $width) . '</td></tr>';
        }
        if ($faceup !== null) {
            $rows .= '<tr><th>Face-up area (mm²)</th><td>' . esc_html((string) $faceup) . '</td></tr>';
        }
        if ($lw !== null) {
            $rows .= '<tr><th>L/W ratio</th><td>' . esc_html((string) $lw) . '</td></tr>';
        }
        if ($rows === '') {
            return '';
        }
        return '<h2 class="ldn-size-hero__heading">' . esc_html__('Key dimensions', 'loupe-diamond-network')
            . '</h2><table class="ldn-size-table"><tbody>' . $rows . '</tbody></table>';
    }

    /**
     * @param string $site_id
     * @param array  $summary Hub summary with rows[].
     * @return string
     */
    public function hub_table_html($site_id, array $summary) {
        $rows = isset($summary['rows']) && is_array($summary['rows']) ? $summary['rows'] : array();
        if ($rows === array()) {
            return '';
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
            $shape_cell = $url !== ''
                ? '<a href="' . esc_url($url) . '">' . esc_html(ucwords($shape)) . '</a>'
                : esc_html(ucwords($shape));
            $carat_cell = $url !== ''
                ? '<a href="' . esc_url($url) . '">' . esc_html($carat) . ' ct</a>'
                : esc_html($carat);
            $body .= '<tr><td>' . $shape_cell . '</td><td>' . $carat_cell . '</td>';
            $body .= '<td>' . esc_html((string) ($row['length_mm'] ?? '')) . '</td>';
            $body .= '<td>' . esc_html((string) ($row['width_mm'] ?? '')) . '</td>';
            $body .= '<td>' . esc_html((string) ($row['faceup_area_mm2'] ?? '')) . '</td></tr>';
        }
        if ($body === '') {
            return '';
        }
        $head = '<tr><th>Shape</th><th>Carat</th><th>Length (mm)</th><th>Width (mm)</th><th>Face-up (mm²)</th></tr>';
        $title = esc_html__('Carat size chart', 'loupe-diamond-network');
        return '<section class="ldn-section ldn-size-hub-table"><h2>' . $title . '</h2><table><thead>'
            . $head . '</thead><tbody>' . $body . '</tbody></table></section>';
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
}

<?php
/**
 * Page renderer — PRD-005 CP54 (SEO & structured data).
 *
 * Server-side renders a network page from S3 artefacts. The page composition is
 * **config-driven, not hardcoded**: the renderer resolves the layout (hero +
 * ordered section list) for the (site, page_level, country) tuple via
 * LDN_Config::get_page_layout() and dispatches each section id to a small
 * builder. This means:
 *   - per-site layout differences come from each profile's `page_structure`;
 *   - adding / removing / reordering embedded content is a config change;
 *   - unknown section ids are skipped, so config and code evolve independently
 *     (a future stonealgo-style "guidance" archetype is just a different
 *     section list — the renderer makes no "this is a pricing page" assumption).
 *
 * Charts render **inline** (Plotly via CDN, no iframe — Principle 10). Artefact
 * fetches are entitlement-gated, so a section whose data a site is not entitled
 * to simply yields nothing.
 *
 * Pure builders (headline / stats / chart / faq / json_ld) take payloads as
 * arguments and are unit-tested; render() wires them to the data fetcher.
 *
 * @package LoupeDiamondNetwork
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Renderer {

    /**
     * Plotly.js CDN (pinned). Never bundle (~4.5 MB) — Principle 10 / chart rules.
     */
    const PLOTLY_CDN = 'https://cdn.plot.ly/plotly-2.35.2.min.js';

    /**
     * JSON flags for embedding payloads inside <script> safely (escapes </script>).
     */
    const JSON_SCRIPT_FLAGS = JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Sections that must never render regardless of config presence.
     * cross_site_comparison is ON HOLD per site_content_entitlements.yaml
     * (2026-06-14, network-graph / price-contradiction risk).
     *
     * @var string[]
     */
    const SUPPRESSED_SECTIONS = array('cross_site_comparison');

    /**
     * Human shape/type casing.
     */
    private static $TYPE_LABELS = array(
        'natural'   => 'Natural',
        'lab-grown' => 'Lab-Grown',
    );

    /**
     * ISO currency code → display symbol/prefix. Codes not listed fall back to
     * "{CODE} " so a price is never rendered unlabelled.
     *
     * @var array<string, string>
     */
    const CURRENCY_SYMBOLS = array(
        'USD' => '$', 'AUD' => 'A$', 'CAD' => 'C$', 'NZD' => 'NZ$', 'SGD' => 'S$',
        'HKD' => 'HK$', 'GBP' => '£', 'EUR' => '€', 'JPY' => '¥', 'INR' => '₹',
        'ZAR' => 'R', 'BRL' => 'R$', 'MXN' => 'MX$', 'TRY' => '₺', 'ILS' => '₪',
        'KRW' => '₩', 'AED' => 'AED ', 'SAR' => 'SAR ', 'CHF' => 'CHF ',
        'DKK' => 'kr ', 'SEK' => 'kr ', 'NOK' => 'kr ',
    );

    /**
     * Headline-stat specs for `summary-data.json`. Each spec lists candidate
     * `paths` into the (nested C5 contract or legacy-flat) payload — first hit
     * wins — plus a display label, a value `format`, and whether it feeds the
     * JSON-LD `variableMeasured`. Order = display order.
     *
     * @return array<int, array{label:string, format:string, schema:bool, paths:array}>
     */
    private static function stat_specs() {
        return array(
            array('label' => 'Current price', 'format' => 'currency', 'schema' => true,
                'paths' => array(array('time_series', 'current_price'), array('current_price'))),
            array('label' => 'Median price', 'format' => 'currency', 'schema' => true,
                'paths' => array(array('distribution', 'median_price'), array('median_price'))),
            array('label' => 'Lowest price', 'format' => 'currency', 'schema' => false,
                'paths' => array(array('distribution', 'price_range', 'min'), array('min_price'), array('price_low'))),
            array('label' => 'Highest price', 'format' => 'currency', 'schema' => false,
                'paths' => array(array('distribution', 'price_range', 'max'), array('max_price'), array('price_high'))),
            array('label' => 'Diamonds analysed', 'format' => 'integer', 'schema' => true,
                'paths' => array(array('distribution', 'sample_size'), array('num_diamonds'), array('sample_size'))),
            array('label' => '7-day change', 'format' => 'percent', 'schema' => false,
                'paths' => array(array('time_series', 'change_7_days'), array('change_7d'))),
        );
    }

    /**
     * @var LDN_Data_Fetcher
     */
    private $fetcher;

    /**
     * @var LDN_Config
     */
    private $config;

    /**
     * Guard so the Plotly CDN tag prints at most once per request.
     *
     * @var bool
     */
    private $plotly_emitted = false;

    /**
     * @param LDN_Data_Fetcher $fetcher
     * @param LDN_Config       $config
     */
    public function __construct(LDN_Data_Fetcher $fetcher, LDN_Config $config) {
        $this->fetcher = $fetcher;
        $this->config = $config;
    }

    /**
     * Render the full page body as an HTML string, driven by the resolved layout.
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function render(LDN_Page_Context $ctx) {
        $layout = $this->config->get_page_layout($ctx->site_id, $ctx->page_level, $ctx->country_code);
        $bag = $this->prefetch($ctx);
        $currency = $this->config->get_currency($ctx->site_id, $ctx->country_code);

        $out = '<main class="ldn-price-page ldn-' . esc_attr($ctx->page_level) . '-page">';
        $out .= '<h1>' . esc_html($this->headline($ctx)) . '</h1>';
        $out .= $this->render_hero($layout['hero_component'], $ctx, $bag);

        foreach ($layout['sections'] as $section_id) {
            $out .= $this->render_section((string) $section_id, $ctx, $bag, $currency);
        }

        $out .= $this->json_ld(
            $ctx,
            is_array($bag['summary']) ? $bag['summary'] : array(),
            $this->profile($ctx),
            $currency
        );
        $out .= '</main>';

        return $out;
    }

    /**
     * <head> tags for the page (canonical + Open Graph). Returns a string so the
     * dispatcher can echo it on `wp_head`. Filterable/disable-able for sites
     * whose SEO plugin already emits these for dynamic routes.
     *
     * @param LDN_Page_Context $ctx
     * @param string|null      $canonical_url Absolute canonical URL, or null to derive.
     * @return string
     */
    public function head_tags(LDN_Page_Context $ctx, $canonical_url = null) {
        if (!apply_filters('ldn_emit_head_tags', true, $ctx)) {
            return '';
        }

        if ($canonical_url === null) {
            $canonical_url = $this->current_url();
        }
        $title = $this->headline($ctx);
        $desc = sprintf('Market pricing data for %s diamonds.', strtolower($this->plain_subject($ctx)));

        $tags = '';
        if ($canonical_url !== '') {
            $tags .= '<link rel="canonical" href="' . esc_url($canonical_url) . '" />' . "\n";
            $tags .= '<meta property="og:url" content="' . esc_url($canonical_url) . '" />' . "\n";
        }
        $tags .= '<meta property="og:type" content="website" />' . "\n";
        $tags .= '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
        $tags .= '<meta property="og:description" content="' . esc_attr($desc) . '" />' . "\n";

        return $tags;
    }

    // =========================================================================
    // Section dispatch
    // =========================================================================

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
            default:
                // bar_chart / comparison_chart / summary_cards (other levels): not yet mapped.
                return '';
        }
    }

    /**
     * Render a single section by id, or '' when unmapped / not entitled / empty.
     *
     * @param string           $section_id
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    private function render_section($section_id, LDN_Page_Context $ctx, array $bag, $currency = null) {
        if (in_array($section_id, self::SUPPRESSED_SECTIONS, true)) {
            return '';
        }

        if ($section_id === 'price_graph') {
            return $this->chart_html($bag['price'], 'ldn-price-chart', __('Price over time', 'loupe-diamond-network'));
        }
        if ($section_id === 'faq_static') {
            return $this->faq_html($this->section_value($section_id, $ctx, $bag));
        }
        if (substr($section_id, -7) === '_static') {
            return $this->text_block($section_id, $this->section_value($section_id, $ctx, $bag));
        }
        if (substr($section_id, -8) === '_dynamic') {
            // intro_dynamic etc.: the crawlable headline stats block.
            return $this->stats_html(is_array($bag['summary']) ? $bag['summary'] : array(), $currency);
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

    // =========================================================================
    // Pure builders (unit-tested)
    // =========================================================================

    /**
     * Human-readable H1, e.g. "1 Carat Round Natural Diamond Prices (US)".
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function headline(LDN_Page_Context $ctx) {
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
        $subject = $subject === '' ? 'Diamond' : $subject;
        return sprintf('%s Diamond Prices (%s)', $subject, strtoupper($ctx->country_code));
    }

    /**
     * Crawlable headline-stats list from a summary payload, using the stat-spec
     * map (labels + formatting + nested/legacy path resolution). Only specced,
     * present scalar values render.
     *
     * @param array       $summary
     * @param string|null $currency ISO code for price formatting.
     * @return string
     */
    public function stats_html(array $summary, $currency = null) {
        $rows = '';
        foreach (self::stat_specs() as $spec) {
            $value = $this->dig_first($summary, $spec['paths']);
            if ($value === null || !is_scalar($value) || is_bool($value)) {
                continue;
            }
            $rows .= '<dt>' . esc_html($spec['label']) . '</dt><dd>'
                . esc_html($this->format_stat($value, $spec['format'], $currency)) . '</dd>';
        }
        if ($rows === '') {
            return '';
        }
        return '<dl class="ldn-stats">' . $rows . '</dl>';
    }

    /**
     * Format a stat value for display.
     *
     * @param mixed       $value
     * @param string      $format   currency | integer | percent | text
     * @param string|null $currency ISO code (currency format only).
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
     * Display symbol/prefix for an ISO currency code ('' when unknown/null).
     *
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
     * Return the first non-null value found among candidate paths into $arr.
     *
     * @param array $arr
     * @param array $paths List of paths; each path is an array of segment keys.
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
     * Inline Plotly chart block from a Plotly payload, or '' when absent.
     *
     * @param mixed  $payload Plotly figure (expects a `data` array).
     * @param string $dom_id
     * @param string $title
     * @return string
     */
    public function chart_html($payload, $dom_id, $title) {
        if (!is_array($payload) || empty($payload['data']) || !is_array($payload['data'])) {
            return '';
        }

        $data = wp_json_encode($payload['data'], self::JSON_SCRIPT_FLAGS);
        $layout = wp_json_encode(isset($payload['layout']) ? $payload['layout'] : array(), self::JSON_SCRIPT_FLAGS);
        $cfg = wp_json_encode(isset($payload['config']) ? $payload['config'] : array('responsive' => true), self::JSON_SCRIPT_FLAGS);
        $dom_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $dom_id);

        $html = $this->plotly_loader();
        $html .= '<figure class="ldn-chart">';
        $html .= '<figcaption>' . esc_html($title) . '</figcaption>';
        $html .= '<div id="' . esc_attr($dom_id) . '" class="ldn-chart-target"></div>';
        $html .= '<script>(function(){function d(){Plotly.newPlot('
            . wp_json_encode($dom_id)
            . ',' . $data . ',' . $layout . ',' . $cfg . ');}'
            . 'if(window.Plotly){d();}else{document.addEventListener("DOMContentLoaded",function(){'
            . 'if(window.Plotly){d();}});}})();</script>';
        $html .= '</figure>';

        return $html;
    }

    /**
     * Crawlable FAQ block from a list of {question, answer} pairs, or ''.
     *
     * @param mixed $value
     * @return string
     */
    public function faq_html($value) {
        if (!is_array($value) || empty($value)) {
            return '';
        }
        $items = '';
        foreach ($value as $qa) {
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
        return '<section class="ldn-section ldn-faq"><h2>' . esc_html(__('FAQ', 'loupe-diamond-network')) . '</h2><dl>' . $items . '</dl></section>';
    }

    /**
     * Schema.org Dataset JSON-LD block.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @param array            $profile  content profile (for dataset name suffix)
     * @param string|null      $currency ISO code (unitText on price measures)
     * @return string
     */
    public function json_ld(LDN_Page_Context $ctx, array $summary, array $profile, $currency = null) {
        $suffix = isset($profile['schema_dataset_name_suffix'])
            ? ' ' . trim((string) $profile['schema_dataset_name_suffix'])
            : '';

        $node = array(
            '@context' => 'https://schema.org',
            '@type'    => 'Dataset',
            'name'     => $this->headline($ctx) . $suffix,
            'description' => sprintf(
                'Market pricing data for %s diamonds.',
                strtolower($this->plain_subject($ctx))
            ),
        );

        $measured = array();
        foreach (self::stat_specs() as $spec) {
            if (empty($spec['schema'])) {
                continue;
            }
            $value = $this->dig_first($summary, $spec['paths']);
            if ($value === null || !is_scalar($value) || is_bool($value)) {
                continue;
            }
            $pv = array(
                '@type' => 'PropertyValue',
                'name'  => $spec['label'],
                'value' => $value,
            );
            if ($spec['format'] === 'currency' && $currency) {
                $pv['unitText'] = strtoupper((string) $currency);
            }
            $measured[] = $pv;
        }
        if (!empty($measured)) {
            $node['variableMeasured'] = $measured;
        }

        $json = wp_json_encode($node, self::JSON_SCRIPT_FLAGS);
        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * Render a static text block (heading + paragraphs), or '' when empty.
     *
     * @param string $section_id
     * @param mixed  $value
     * @return string
     */
    public function text_block($section_id, $value) {
        if (is_array($value)) {
            $value = implode("\n\n", array_filter($value, 'is_scalar'));
        }
        if (!is_scalar($value) || (string) $value === '') {
            return '';
        }
        $base = preg_replace('/_(static|dynamic)$/', '', $section_id);
        $heading = ucwords(str_replace('_', ' ', $base));
        $class = 'ldn-' . str_replace('_', '-', $section_id);

        return '<section class="ldn-section ' . esc_attr($class) . '">'
            . '<h2>' . esc_html($heading) . '</h2>'
            . wp_kses_post(wpautop((string) $value))
            . '</section>';
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /**
     * Fetch the (gated) artefacts a page may need, once per render.
     *
     * @param LDN_Page_Context $ctx
     * @return array<string, mixed>
     */
    private function prefetch(LDN_Page_Context $ctx) {
        return array(
            'summary'    => $this->fetcher->fetch_artefact('summary_data_json', $ctx),
            'price'      => $this->fetcher->fetch_artefact('price_graph_json', $ctx),
            'dist'       => $this->fetcher->fetch_artefact('distribution_json', $ctx),
            'static'     => $this->fetcher->fetch_artefact('static_content_json', $ctx),
            'individual' => $this->fetcher->fetch_artefact('individual_content_json', $ctx),
        );
    }

    /**
     * Content profile for the context's site (array, never null).
     *
     * @param LDN_Page_Context $ctx
     * @return array
     */
    private function profile(LDN_Page_Context $ctx) {
        $profile = $this->config->get_content_profile($ctx->site_id);
        return is_array($profile) ? $profile : array();
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

    /**
     * Plotly CDN <script>, emitted at most once per request.
     *
     * @return string
     */
    private function plotly_loader() {
        if ($this->plotly_emitted) {
            return '';
        }
        $this->plotly_emitted = true;
        return '<script src="' . esc_url(self::PLOTLY_CDN) . '"></script>';
    }
}

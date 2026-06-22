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
     * Dynamic section id → copy.json section keys per page level.
     *
     * @var array<string, array<string, string[]>>
     */
    const DYNAMIC_COPY_KEYS = array(
        'all-shapes' => array(
            'overview_dynamic' => array('intro_text', 'analysis', 'shape_analysis', 'intro', 'ranking_summary'),
        ),
        'diamond-type' => array(
            'type_overview_dynamic' => array('intro'),
        ),
        'top-level' => array(
            'market_overview_dynamic' => array('intro', 'market_size'),
        ),
    );

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

        $profile = $this->profile($ctx);

        $out = '<div class="ldn-page-shell">';
        $out .= '<main class="ldn-price-page ldn-' . esc_attr($ctx->page_level) . '-page '
            . esc_attr($this->chrome_heading_class($profile)) . '">';
        $out .= $this->theme_style_block($profile);
        $out .= '<h1 class="ldn-page-title">' . esc_html($this->headline($ctx)) . '</h1>';
        $out .= $this->data_summary_html(
            $ctx,
            is_array($bag['summary']) ? $bag['summary'] : array(),
            $currency
        );
        $out .= $this->render_hero($layout['hero_component'], $ctx, $bag);

        foreach ($layout['sections'] as $section_id) {
            $out .= $this->render_section((string) $section_id, $ctx, $bag, $currency);
        }

        $out .= '</main>';
        $out .= '</div>';

        return $out;
    }

    /**
     * Full `<head>` output for an LDN price page: meta, canonical, OG, JSON-LD,
     * hreflang. Prefetches summary + FAQ source once (lightweight vs full render).
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function render_head_content(LDN_Page_Context $ctx) {
        $profile = $this->profile($ctx);
        $site = $this->config->get_site($ctx->site_id);
        $site = is_array($site) ? $site : array();
        $currency = $this->config->get_currency($ctx->site_id, $ctx->country_code);
        $canonical = $this->current_url();

        $summary = $this->fetcher->fetch_artefact('summary_data_json', $ctx);
        $summary = is_array($summary) ? $summary : array();

        $bag = array(
            'summary'    => $summary,
            'static'     => $this->fetcher->fetch_artefact('static_content_json', $ctx),
            'individual' => null,
            'ranking'    => $ctx->page_level === 'all-shapes'
                ? $this->fetcher->fetch_artefact('shapes_ranking_json', $ctx)
                : null,
        );

        $out = $this->head_tags($ctx, $canonical, $summary, $currency);

        $schema = new LDN_Schema();
        $out .= $schema->render(
            $ctx,
            $summary,
            $profile,
            $site,
            $currency,
            $canonical,
            $this->breadcrumb_trail($ctx, $canonical),
            $this->schema_faq_pairs($ctx, $bag),
            $this->schema_items($ctx, $bag)
        );

        $hreflang = new LDN_Hreflang($this->config);
        $out .= $hreflang->render($ctx, $canonical);

        return $out;
    }

    /**
     * Plain-text factual data summary for AI extraction (CP54_04).
     *
     * Distinct from intro_dynamic editorial copy — one structured sentence with
     * price, sample size, and analysis date visible without JavaScript.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @param string|null      $currency
     * @return string
     */
    public function data_summary_html(LDN_Page_Context $ctx, array $summary, $currency = null) {
        $schema = new LDN_Schema();
        $text = $schema->dataset_description($ctx, $summary, $currency);
        if ($text === '') {
            return '';
        }
        return '<section class="ldn-section ldn-data-summary" aria-label="'
            . esc_attr__('Data summary', 'loupe-diamond-network') . '"><p>'
            . esc_html($text) . '</p></section>';
    }

    /**
     * Network-wide page chrome defaults when a profile omits page_chrome keys.
     *
     * @var array<string, string>
     */
    private static $PAGE_CHROME_DEFAULTS = array(
        'max_width'         => '1000px',
        'content_padding'   => '1.25rem',
        'section_spacing'   => '2rem',
        'heading_style'     => 'minimal',
    );

    /**
     * Allowed heading_style values → BEM modifier on .ldn-price-page.
     *
     * @var array<string, bool>
     */
    private static $VALID_HEADING_STYLES = array(
        'minimal'        => true,
        'loupe_classic'  => true,
    );

    /**
     * CP53: inject brand_tokens + page_chrome as scoped CSS custom properties.
     *
     * @param array $profile Resolved content profile.
     * @return string <style> block, or '' when nothing to emit.
     */
    public function theme_style_block(array $profile) {
        $decls = $this->brand_token_declarations($profile);
        $decls .= $this->page_chrome_declarations($profile);

        if ($decls === '') {
            return '';
        }

        return '<style>.ldn-page-shell,.ldn-price-page{' . $decls . '}</style>';
    }

    /**
     * Back-compat alias for theme_style_block (brand-only callers).
     *
     * @param array $profile
     * @return string
     */
    public function brand_css_vars(array $profile) {
        return $this->theme_style_block($profile);
    }

    /**
     * BEM modifier class from page_chrome.heading_style (default minimal).
     *
     * @param array $profile
     * @return string e.g. ldn-chrome--loupe-classic
     */
    public function chrome_heading_class(array $profile) {
        $chrome = $this->resolved_page_chrome($profile);
        $style = isset($chrome['heading_style']) ? (string) $chrome['heading_style'] : 'minimal';
        $style = $this->sanitize_heading_style($style);
        return 'ldn-chrome--' . $style;
    }

    /**
     * Merge network defaults with profile page_chrome.
     *
     * @param array $profile
     * @return array<string, string>
     */
    private function resolved_page_chrome(array $profile) {
        $chrome = (isset($profile['page_chrome']) && is_array($profile['page_chrome']))
            ? $profile['page_chrome']
            : array();

        $merged = self::$PAGE_CHROME_DEFAULTS;
        foreach ($chrome as $key => $value) {
            if (is_string($value) && $value !== '') {
                $merged[$key] = $value;
            }
        }
        return $merged;
    }

    /**
     * @param array $profile
     * @return string CSS declarations (no wrapper).
     */
    private function brand_token_declarations(array $profile) {
        $map = array(
            'primary'        => '--ldn-primary',
            'secondary'      => '--ldn-secondary',
            'accent'         => '--ldn-accent',
            'background'     => '--ldn-background',
            'text'           => '--ldn-text',
            'secondary_text' => '--ldn-secondary-text',
        );

        $tokens = (isset($profile['brand_tokens']) && is_array($profile['brand_tokens']))
            ? $profile['brand_tokens']
            : array();

        if (empty($tokens['primary'])) {
            $fallback = $this->dig_first($profile, array(
                array('distribution_style', 'color_scheme', 'primary'),
                array('graph_style', 'color_scheme', 'primary'),
            ));
            if (is_string($fallback) && $fallback !== '') {
                $tokens['primary'] = $fallback;
            }
        }

        $decls = '';
        foreach ($map as $key => $var) {
            if (!empty($tokens[$key]) && is_string($tokens[$key])) {
                $value = $this->sanitize_css_color($tokens[$key]);
                if ($value !== '') {
                    $decls .= $var . ':' . $value . ';';
                }
            }
        }
        return $decls;
    }

    /**
     * @param array $profile
     * @return string CSS declarations (no wrapper).
     */
    private function page_chrome_declarations(array $profile) {
        $chrome = $this->resolved_page_chrome($profile);

        $length_map = array(
            'max_width'       => '--ldn-max-width',
            'content_padding' => '--ldn-padding',
            'section_spacing' => '--ldn-section-spacing',
            'title_size'      => '--ldn-title-size',
            'title_size_mobile' => '--ldn-title-size-mobile',
        );

        $font_map = array(
            'title_font' => '--ldn-font-title',
            'body_font'  => '--ldn-font-body',
        );

        $shadow_map = array(
            'h1_shadow'        => '--ldn-h1-shadow',
            'h1_shadow_mobile' => '--ldn-h1-shadow-mobile',
        );

        $decls = '';
        foreach ($length_map as $key => $var) {
            if (!empty($chrome[$key])) {
                $value = $this->sanitize_css_length($chrome[$key]);
                if ($value !== '') {
                    $decls .= $var . ':' . $value . ';';
                }
            }
        }
        foreach ($font_map as $key => $var) {
            if (!empty($chrome[$key])) {
                $value = $this->sanitize_font_stack($chrome[$key]);
                if ($value !== '') {
                    $decls .= $var . ':' . $value . ';';
                }
            }
        }
        foreach ($shadow_map as $key => $var) {
            if (!empty($chrome[$key])) {
                $value = $this->sanitize_box_shadow($chrome[$key]);
                if ($value !== '') {
                    $decls .= $var . ':' . $value . ';';
                }
            }
        }
        return $decls;
    }

    /**
     * @param string $style
     * @return string Sanitised style slug.
     */
    private function sanitize_heading_style($style) {
        $style = preg_replace('/[^a-z0-9_]/', '', strtolower($style));
        if ($style === '' || !isset(self::$VALID_HEADING_STYLES[$style])) {
            return 'minimal';
        }
        return $style;
    }

    /**
     * Allow only hex (#rgb..#rrggbbaa) and rgb()/rgba() colour values so config
     * data cannot inject arbitrary CSS into the page. Returns '' otherwise.
     *
     * @param string $value
     * @return string
     */
    private function sanitize_css_color($value) {
        $value = trim($value);
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $value)) {
            return $value;
        }
        if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d+)\s*)?\)$/', $value)) {
            return $value;
        }
        return '';
    }

    /**
     * Allow px/rem/em/%/vw/vh/ch lengths only.
     *
     * @param string $value
     * @return string
     */
    private function sanitize_css_length($value) {
        $value = trim($value);
        if (preg_match('/^-?\d+(\.\d+)?(px|rem|em|%|vw|vh|ch)$/', $value)) {
            return $value;
        }
        return '';
    }

    /**
     * Allow a conservative font-family stack from config.
     *
     * @param string $value
     * @return string
     */
    private function sanitize_font_stack($value) {
        $value = trim($value);
        if (strlen($value) > 200) {
            return '';
        }
        if (!preg_match('/^[\w\s,"\'\-]+$/', $value)) {
            return '';
        }
        return $value;
    }

    /**
     * Allow simple box-shadow values (offset blur spread colour).
     *
     * @param string $value
     * @return string
     */
    private function sanitize_box_shadow($value) {
        $value = trim($value);
        if (strlen($value) > 120) {
            return '';
        }
        if (!preg_match('/^[\dpx#\s,rgba().%-]+$/', $value)) {
            return '';
        }
        return $value;
    }

    /**
     * <head> tags for the page (canonical + Open Graph). Returns a string so the
     * dispatcher can echo it on `wp_head`. Filterable/disable-able for sites
     * whose SEO plugin already emits these for dynamic routes.
     *
     * @param LDN_Page_Context $ctx
     * @param string|null      $canonical_url Absolute canonical URL, or null to derive.
     * @param array            $summary       summary-data payload for rich description.
     * @param string|null      $currency      ISO currency code.
     * @return string
     */
    public function head_tags(LDN_Page_Context $ctx, $canonical_url = null, array $summary = array(), $currency = null) {
        if (!apply_filters('ldn_emit_head_tags', true, $ctx)) {
            return '';
        }

        if ($canonical_url === null) {
            $canonical_url = $this->current_url();
        }
        $title = $this->headline($ctx);
        $schema = new LDN_Schema();
        $desc = $schema->dataset_description($ctx, $summary, $currency);

        $tags = '';
        if (!$this->seo_plugin_emits_meta()) {
            $tags .= '<meta name="description" content="' . esc_attr($desc) . '" />' . "\n";
        }
        if ($canonical_url !== '') {
            if (!$this->seo_plugin_emits_canonical()) {
                $tags .= '<link rel="canonical" href="' . esc_url($canonical_url) . '" />' . "\n";
            }
            $tags .= '<meta property="og:url" content="' . esc_url($canonical_url) . '" />' . "\n";
        }
        $tags .= '<meta property="og:type" content="website" />' . "\n";
        $tags .= '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
        $tags .= '<meta property="og:description" content="' . esc_attr($desc) . '" />' . "\n";

        $site = $this->config->get_site($ctx->site_id);
        $brand = is_array($site) && !empty($site['brand_name']) ? (string) $site['brand_name'] : '';
        if ($brand !== '') {
            $tags .= '<meta property="og:site_name" content="' . esc_attr($brand) . '" />' . "\n";
        }

        $og_image = $this->og_preview_url($ctx);
        if ($og_image !== '') {
            $tags .= '<meta property="og:image" content="' . esc_url($og_image) . '" />' . "\n";
            $tags .= '<meta property="og:image:width" content="1200" />' . "\n";
            $tags .= '<meta property="og:image:height" content="630" />' . "\n";
            $tags .= '<meta property="og:image:type" content="image/png" />' . "\n";
        }

        return $tags;
    }

    /**
     * Public HTTPS URL for the page's OG chart preview PNG, or '' when absent.
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function og_preview_url(LDN_Page_Context $ctx) {
        if ($ctx->page_level !== 'shape') {
            return '';
        }
        $url = $this->fetcher->resolve_artefact_url('og_preview_png', $ctx);
        return is_string($url) ? $url : '';
    }

    /**
     * Whether a common SEO plugin is likely to emit meta description on its own.
     *
     * LDN dynamic routes still emit OG tags (SEOPress often misses these URLs).
     *
     * @return bool
     */
    private function seo_plugin_emits_meta() {
        return defined('SEOPRESS_VERSION') || defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION');
    }

    /**
     * Whether a common SEO plugin is likely to emit canonical on its own.
     *
     * @return bool
     */
    private function seo_plugin_emits_canonical() {
        // Dynamic LDN routes are invisible to most SEO plugins — keep our canonical.
        return false;
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
            case 'table_chart':
                return $this->shapes_at_carat_hero_html($ctx, $bag);
            case 'comparison_chart':
                return $this->carat_tiers_table_html($ctx, $bag, __('Prices by carat weight', 'loupe-diamond-network'));
            case 'summary_table':
                return $this->market_overview_table_html($ctx, $bag);
            default:
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
        if ($section_id === 'intro_dynamic') {
            return $this->intro_html(
                $ctx,
                is_array($bag['summary']) ? $bag['summary'] : array(),
                $currency
            );
        }
        if (substr($section_id, -8) === '_dynamic') {
            if ($ctx->page_level !== 'shape') {
                $dynamic = $this->copy_dynamic_html($section_id, $ctx, $bag);
                if ($dynamic !== '') {
                    return $dynamic;
                }
            }
            return $this->stats_html(is_array($bag['summary']) ? $bag['summary'] : array(), $currency);
        }
        if (substr($section_id, -7) === '_static') {
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
        if ($subject === '') {
            return sprintf('Diamond Prices (%s)', strtoupper($ctx->country_code));
        }
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
    /**
     * Daily-updating intro paragraph from summary-data.json (price, sample size,
     * 7-day change, price range). Mirrors the legacy L7.1 intro_text template
     * until CP19 dynamic templates ship in C1.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @param string|null      $currency ISO code for price formatting.
     * @return string
     */
    public function intro_html(LDN_Page_Context $ctx, array $summary, $currency = null) {
        $current_price = $this->dig_first($summary, array(
            array('time_series', 'current_price'),
            array('current_price'),
        ));
        if ($current_price === null || !is_numeric($current_price)) {
            return '';
        }

        $sample_size = $this->dig_first($summary, array(
            array('distribution', 'sample_size'),
            array('num_diamonds'),
            array('sample_size'),
        ));
        $sample_size = is_numeric($sample_size) ? (int) $sample_size : 0;

        $change_7d = $this->dig_first($summary, array(
            array('time_series', 'change_7_days'),
            array('change_7d'),
        ));
        $change_7d = is_numeric($change_7d) ? (float) $change_7d : null;

        $min_price = $this->dig_first($summary, array(
            array('distribution', 'price_range', 'min'),
            array('min_price'),
            array('price_low'),
        ));
        $max_price = $this->dig_first($summary, array(
            array('distribution', 'price_range', 'max'),
            array('max_price'),
            array('price_high'),
        ));

        $symbol = $this->currency_symbol($currency);
        $country_name = $this->country_full_name($ctx);
        $color_word = strtolower($ctx->country_code) === 'us' ? 'color' : 'colour';
        $carat_label = $this->format_carat_label($ctx->carat);
        $shape_label = $ctx->shape !== null
            ? ucwords(str_replace('-', ' ', $ctx->shape))
            : '';
        $type_label = $ctx->diamond_type !== null && isset(self::$TYPE_LABELS[$ctx->diamond_type])
            ? self::$TYPE_LABELS[$ctx->diamond_type]
            : ($ctx->diamond_type !== null ? ucwords(str_replace('-', ' ', $ctx->diamond_type)) : '');

        $subject = trim(implode(' ', array_filter(array(
            $carat_label !== '' ? $carat_label . ' carat' : '',
            $shape_label,
            $type_label,
        ))));
        if ($subject === '') {
            $subject = 'diamond';
        }

        $price_text = $symbol . number_format((float) $current_price, 2);
        $diamond_word = $sample_size === 1 ? 'diamond' : 'diamonds';
        $sample_text = number_format($sample_size);

        $paragraph = sprintf(
            'The current price for a %s diamond in %s is %s, calculated from %s %s that match this carat weight and shape in our database',
            esc_html($subject),
            esc_html($country_name),
            esc_html($price_text),
            esc_html($sample_text),
            esc_html($diamond_word)
        );

        if ($change_7d === null) {
            $paragraph .= '.';
        } elseif ($change_7d == 0.0) {
            $paragraph .= ', and has remained stable over the last 7 days.';
        } else {
            $direction = $change_7d > 0 ? 'increased' : 'decreased';
            $paragraph .= sprintf(
                ', and has %s by %s over the last 7 days.',
                esc_html($direction),
                esc_html(sprintf('%.2f%%', abs($change_7d)))
            );
        }

        $range_paragraph = '';
        if (is_numeric($min_price) && is_numeric($max_price) && (float) $max_price > 0) {
            $range_paragraph = sprintf(
                'When comparing to %s carat %s diamond prices, prices for these diamonds range from %s to %s, depending on factors such as %s and clarity.',
                esc_html($carat_label !== '' ? $carat_label : 'this'),
                esc_html(strtolower($type_label !== '' ? $type_label : 'diamond')),
                esc_html($symbol . number_format((float) $min_price, 2)),
                esc_html($symbol . number_format((float) $max_price, 2)),
                esc_html($color_word)
            );
        }

        $body = $paragraph;
        if ($range_paragraph !== '') {
            $body .= "\n\n" . $range_paragraph;
        }

        return '<section class="ldn-section ldn-intro-dynamic">'
            . wp_kses_post(wpautop($body))
            . '</section>';
    }

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
        $layout = $this->prepare_inline_chart_layout(
            isset($payload['layout']) && is_array($payload['layout']) ? $payload['layout'] : array()
        );
        $layout_json = wp_json_encode($layout, self::JSON_SCRIPT_FLAGS);
        $cfg = wp_json_encode(isset($payload['config']) ? $payload['config'] : array('responsive' => true), self::JSON_SCRIPT_FLAGS);
        $dom_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $dom_id);

        $html = $this->plotly_loader();
        $html .= '<figure class="ldn-chart">';
        $html .= '<figcaption>' . esc_html($title) . '</figcaption>';
        $html .= '<div id="' . esc_attr($dom_id) . '" class="ldn-chart-target"></div>';
        $html .= '<script>(function(){function d(){Plotly.newPlot('
            . wp_json_encode($dom_id)
            . ',' . $data . ',' . $layout_json . ',' . $cfg . ');}'
            . 'if(window.Plotly){d();}else{document.addEventListener("DOMContentLoaded",function(){'
            . 'if(window.Plotly){d();}});}})();</script>';
        $html .= '</figure>';

        return $html;
    }

    /**
     * Adjust Plotly layout for inline WP rendering (title in figcaption, not figure).
     *
     * C5 exports layouts with a large top margin for the in-chart title and period
     * toggles. LDN hides .gtitle and shows the title in <figcaption>, so reclaim
     * vertical space and bump height slightly for readability.
     *
     * @param array $layout Plotly layout dict from price-graph.json.
     * @return array
     */
    public function prepare_inline_chart_layout(array $layout) {
        $margin = (isset($layout['margin']) && is_array($layout['margin']))
            ? $layout['margin']
            : array();
        $top = isset($margin['t']) ? (int) $margin['t'] : 150;
        if ($top > 80) {
            $margin['t'] = 72;
        }
        $layout['margin'] = $margin;

        $height = isset($layout['height']) ? (int) $layout['height'] : 390;
        if ($height < 440) {
            $layout['height'] = 440;
        }

        if (isset($layout['title'])) {
            if (is_array($layout['title'])) {
                $layout['title']['text'] = '';
            } else {
                $layout['title'] = array('text' => '');
            }
        }

        return $layout;
    }

    /**
     * Format static prose for crawlable HTML paragraphs.
     *
     * Honors explicit blank lines from C1 (wpautop). When the model returns one
     * long paragraph, split on sentence boundaries into ~2-sentence blocks.
     *
     * @param string $text
     * @return string Safe HTML paragraphs.
     */
    public function format_prose_html($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        if (strpos($text, "\n") !== false) {
            return wp_kses_post(wpautop($text));
        }

        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z"\'(])/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($sentences === false || count($sentences) <= 2) {
            return wp_kses_post(wpautop($text));
        }

        $paragraphs = array();
        for ($i = 0; $i < count($sentences); $i += 2) {
            $chunk = array_slice($sentences, $i, 2);
            $paragraphs[] = implode(' ', $chunk);
        }

        return wp_kses_post(wpautop(implode("\n\n", $paragraphs)));
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
     * Progressive breadcrumb trail (Home → Diamond Prices → … → current page).
     *
     * Reuses build_price_page_url() so intermediate URLs come from the site's
     * url_structure (single source of truth). Crumbs whose URL can't be resolved
     * are dropped. Consumed by LDN_Schema::breadcrumb_node().
     *
     * @param LDN_Page_Context $ctx
     * @param string           $canonical_url Absolute URL of the current page.
     * @return array<int, array{name:string, url:string}>
     */
    public function breadcrumb_trail(LDN_Page_Context $ctx, $canonical_url = '') {
        $trail = array();

        $home = function_exists('home_url') ? (string) home_url('/') : '';
        if ($home !== '') {
            $trail[] = array('name' => 'Home', 'url' => $home);
        }

        $trail[] = array(
            'name' => __('Diamond Prices', 'loupe-diamond-network'),
            'url'  => $ctx->page_level === 'top-level' ? $canonical_url : $this->build_price_page_url($ctx, 'top-level'),
        );

        if ($ctx->diamond_type !== null && $ctx->page_level !== 'top-level') {
            $type_label = isset(self::$TYPE_LABELS[$ctx->diamond_type])
                ? self::$TYPE_LABELS[$ctx->diamond_type]
                : ucwords(str_replace('-', ' ', $ctx->diamond_type));
            $trail[] = array(
                'name' => sprintf('%s Diamonds', $type_label),
                'url'  => $ctx->page_level === 'diamond-type' ? $canonical_url : $this->build_price_page_url($ctx, 'diamond-type'),
            );
        }

        if ($ctx->carat !== null && in_array($ctx->page_level, array('all-shapes', 'shape'), true)) {
            $trail[] = array(
                'name' => sprintf('%s Carat', $this->format_carat_label($ctx->carat)),
                'url'  => $ctx->page_level === 'all-shapes' ? $canonical_url : $this->build_price_page_url($ctx, 'all-shapes'),
            );
        }

        if ($ctx->shape !== null && $ctx->page_level === 'shape') {
            $trail[] = array(
                'name' => ucwords(str_replace('-', ' ', $ctx->shape)),
                'url'  => $canonical_url,
            );
        }

        return array_values(array_filter($trail, static function ($crumb) {
            return !empty($crumb['url']);
        }));
    }

    /**
     * Resolve FAQ {question, answer} pairs for schema, or [] when none.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return array<int, array{question:mixed, answer:mixed}>
     */
    public function schema_faq_pairs(LDN_Page_Context $ctx, array $bag) {
        $value = $this->section_value('faq_static', $ctx, $bag);
        return is_array($value) ? $value : array();
    }

    /**
     * Priced product items for an ItemList (hybrid/recommendation schema types).
     *
     * Only the all-shapes ranking yields a meaningful product list today; other
     * levels return [] (the schema generator then omits ItemList).
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return array<int, array{name:string, price:mixed, currency:string, url:string}>
     */
    public function schema_items(LDN_Page_Context $ctx, array $bag) {
        if ($ctx->page_level !== 'all-shapes' || empty($bag['ranking']) || !is_array($bag['ranking'])) {
            return array();
        }
        $rows = isset($bag['ranking']['shapes']) && is_array($bag['ranking']['shapes'])
            ? $bag['ranking']['shapes']
            : array();
        $currency = $this->config->get_currency($ctx->site_id, $ctx->country_code);

        $items = array();
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['shape'])) {
                continue;
            }
            $price = isset($row['median_price']) ? $row['median_price']
                : (isset($row['current_price']) ? $row['current_price'] : null);
            $url = !empty($row['page_url'])
                ? (string) $row['page_url']
                : $this->build_price_page_url($ctx, 'shape', array('shape' => (string) $row['shape']));
            $items[] = array(
                'name'     => ucwords((string) $row['shape']),
                'price'    => $price,
                'currency' => $currency !== null ? (string) $currency : 'USD',
                'url'      => $url,
            );
        }
        return $items;
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
            . $this->format_prose_html((string) $value)
            . '</section>';
    }

    /**
     * Render templated copy blocks for aggregate-level *_dynamic sections.
     *
     * @param string           $section_id
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function copy_dynamic_html($section_id, LDN_Page_Context $ctx, array $bag) {
        $level_map = isset(self::DYNAMIC_COPY_KEYS[$ctx->page_level])
            ? self::DYNAMIC_COPY_KEYS[$ctx->page_level]
            : array();
        $keys = isset($level_map[$section_id]) ? $level_map[$section_id] : array();
        if (empty($keys)) {
            return '';
        }

        $sections = $this->copy_sections(is_array($bag['copy']) ? $bag['copy'] : array());
        $html = '';
        foreach ($keys as $key) {
            if (!isset($sections[$key]) || !is_scalar($sections[$key]) || (string) $sections[$key] === '') {
                continue;
            }
            $html .= '<section class="ldn-section ldn-copy-' . esc_attr($key) . '">'
                . $this->format_prose_html((string) $sections[$key])
                . '</section>';
        }
        return $html;
    }

    /**
     * Bar chart + linked ranking table for all-shapes hero.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function shapes_at_carat_hero_html(LDN_Page_Context $ctx, array $bag) {
        $html = $this->chart_html(
            is_array($bag['ranking_chart']) ? $bag['ranking_chart'] : array(),
            'ldn-shapes-ranking-chart',
            __('Prices by shape', 'loupe-diamond-network')
        );
        $html .= $this->shapes_ranking_table_html($ctx, $bag);
        return $html;
    }

    /**
     * Linked HTML table from shapes-ranking.json.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function shapes_ranking_table_html(LDN_Page_Context $ctx, array $bag) {
        $payload = is_array($bag['ranking']) ? $bag['ranking'] : array();
        $rows = isset($payload['shapes']) && is_array($payload['shapes']) ? $payload['shapes'] : array();
        if (empty($rows)) {
            return '';
        }

        $currency = isset($payload['currency_symbol']) ? (string) $payload['currency_symbol'] : '$';
        $carat_label = $this->format_carat_label($ctx->carat);
        $country_name = $this->country_full_name($ctx);

        $body = '';
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['shape'])) {
                continue;
            }
            $shape = (string) $row['shape'];
            $price = isset($row['median_price']) ? $row['median_price'] : (isset($row['current_price']) ? $row['current_price'] : null);
            $change = isset($row['price_change']) ? $row['price_change'] : (isset($row['change_7d']) ? $row['change_7d'] : null);
            $url = !empty($row['page_url'])
                ? (string) $row['page_url']
                : $this->build_price_page_url($ctx, 'shape', array('shape' => $shape));
            if ($url === '') {
                continue;
            }
            $price_cell = is_numeric($price) ? esc_html($currency . number_format((float) $price, 2)) : '—';
            $change_cell = is_numeric($change) ? esc_html(number_format((float) $change, 2) . '%') : '—';
            $body .= '<tr><td><a href="' . esc_url($url) . '">' . esc_html($shape) . '</a></td>'
                . '<td>' . $price_cell . '</td><td>' . $change_cell . '</td></tr>';
        }
        if ($body === '') {
            return '';
        }

        $heading = sprintf(
            /* translators: 1: carat label, 2: country name */
            __('%1$s carat diamond prices in %2$s, ranked by shape', 'loupe-diamond-network'),
            $carat_label !== '' ? $carat_label : '1',
            $country_name
        );

        return '<section class="ldn-section ldn-shapes-ranking-table">'
            . '<h2>' . esc_html($heading) . '</h2>'
            . '<p>' . esc_html__('Click on any diamond shape to see more detailed pricing information.', 'loupe-diamond-network') . '</p>'
            . '<table class="ldn-data-table"><thead><tr>'
            . '<th>' . esc_html__('Shape', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Current price', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('7-day % change', 'loupe-diamond-network') . '</th>'
            . '</tr></thead><tbody>' . $body . '</tbody></table></section>';
    }

    /**
     * Carat-tier comparison table for diamond-type pages.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @param string           $title
     * @return string
     */
    public function carat_tiers_table_html(LDN_Page_Context $ctx, array $bag, $title) {
        $payload = is_array($bag['type_summary']) ? $bag['type_summary'] : array();
        $tiers = isset($payload['carat_tiers']) && is_array($payload['carat_tiers']) ? $payload['carat_tiers'] : array();
        if (empty($tiers)) {
            return '';
        }

        $currency = $this->currency_symbol(isset($payload['currency']) ? $payload['currency'] : null);
        $body = '';
        foreach ($tiers as $tier) {
            if (!is_array($tier) || empty($tier['carat_weight'])) {
                continue;
            }
            $carat = (string) $tier['carat_weight'];
            $price = isset($tier['median_price']) ? $tier['median_price'] : null;
            $samples = isset($tier['sample_size']) ? (int) $tier['sample_size'] : 0;
            $url = !empty($tier['page_url'])
                ? (string) $tier['page_url']
                : $this->build_price_page_url($ctx, 'all-shapes', array('carat' => $carat));
            $price_cell = is_numeric($price) ? esc_html($currency . number_format((float) $price, 0)) : '—';
            $link = $url !== ''
                ? '<a href="' . esc_url($url) . '">' . esc_html($carat . ' ct') . '</a>'
                : esc_html($carat . ' ct');
            $body .= '<tr><td>' . $link . '</td><td>' . $price_cell . '</td><td>'
                . esc_html(number_format($samples)) . '</td></tr>';
        }
        if ($body === '') {
            return '';
        }

        return '<section class="ldn-section ldn-carat-tiers-table">'
            . '<h2>' . esc_html($title) . '</h2>'
            . '<table class="ldn-data-table"><thead><tr>'
            . '<th>' . esc_html__('Carat', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Median price', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Diamonds analysed', 'loupe-diamond-network') . '</th>'
            . '</tr></thead><tbody>' . $body . '</tbody></table></section>';
    }

    /**
     * Natural vs lab-grown overview table for top-level pages.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return string
     */
    public function market_overview_table_html(LDN_Page_Context $ctx, array $bag) {
        $overview = is_array($bag['market_overview']) ? $bag['market_overview'] : array();
        if (empty($overview)) {
            return $this->stats_html(is_array($bag['summary']) ? $bag['summary'] : array(), null);
        }

        $currency = $this->currency_symbol(
            isset($overview['currency']) ? $overview['currency'] : $this->config->get_currency($ctx->site_id, $ctx->country_code)
        );
        $rows = '';
        foreach (array('natural' => 'Natural', 'lab_grown' => 'Lab-Grown') as $key => $label) {
            if (!isset($overview[$key]) || !is_array($overview[$key])) {
                continue;
            }
            $block = $overview[$key];
            $avg = isset($block['weighted_avg_price']) ? $block['weighted_avg_price'] : null;
            $combos = isset($block['combo_count']) ? (int) $block['combo_count'] : 0;
            $canonical_type = $key === 'lab_grown' ? 'lab-grown' : 'natural';
            $url = $this->build_price_page_url($ctx, 'diamond-type', array('type' => $canonical_type));
            $price_cell = is_numeric($avg) ? esc_html($currency . number_format((float) $avg, 0)) : '—';
            $name_cell = $url !== ''
                ? '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>'
                : esc_html($label);
            $rows .= '<tr><td>' . $name_cell . '</td><td>' . $price_cell . '</td><td>'
                . esc_html(number_format($combos)) . '</td></tr>';
        }
        if ($rows === '') {
            return '';
        }

        return '<section class="ldn-section ldn-market-overview-table">'
            . '<h2>' . esc_html__('Market overview', 'loupe-diamond-network') . '</h2>'
            . '<table class="ldn-data-table"><thead><tr>'
            . '<th>' . esc_html__('Diamond type', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Weighted average', 'loupe-diamond-network') . '</th>'
            . '<th>' . esc_html__('Combinations tracked', 'loupe-diamond-network') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></section>';
    }

    /**
     * Build an internal price-page URL from the site's url_structure pattern.
     *
     * @param LDN_Page_Context $ctx
     * @param string           $page_level top-level|diamond-type|all-shapes|shape
     * @param array            $parts      Optional type, carat, shape overrides.
     * @return string
     */
    public function build_price_page_url(LDN_Page_Context $ctx, $page_level, array $parts = array()) {
        $structure = $this->config->get_url_structure($ctx->site_id);
        if (!is_array($structure)) {
            return '';
        }

        $level_keys = array(
            'top-level'    => 'level_1',
            'diamond-type' => 'level_2',
            'all-shapes'   => 'level_3',
            'shape'        => 'level_4',
        );
        if (!isset($level_keys[$page_level])) {
            return '';
        }
        $pattern = isset($structure[$level_keys[$page_level]]) ? (string) $structure[$level_keys[$page_level]] : '';
        if ($pattern === '') {
            return '';
        }

        $type = isset($parts['type']) ? (string) $parts['type'] : $ctx->diamond_type;
        $carat = isset($parts['carat']) ? (string) $parts['carat'] : $ctx->carat;
        $shape = isset($parts['shape']) ? (string) $parts['shape'] : $ctx->shape;

        $replacements = array(
            '{country}' => strtolower($ctx->country_code),
            '{type}'    => $this->type_url_slug($ctx->site_id, $type),
            '{carat}'   => $this->format_carat_slug($ctx->site_id, $carat),
            '{shape}'   => sanitize_title($shape),
        );

        $path = $pattern;
        foreach ($replacements as $placeholder => $value) {
            if ($value === '' && strpos($path, $placeholder) !== false) {
                return '';
            }
            $path = str_replace($placeholder, $value, $path);
        }

        return home_url(user_trailingslashit(ltrim($path, '/')));
    }

    /**
     * @param string|null $copy
     * @return array<string, mixed>
     */
    private function copy_sections(array $copy) {
        if (isset($copy['sections']) && is_array($copy['sections'])) {
            return $copy['sections'];
        }
        return array();
    }

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
                break;
            case 'all-shapes':
                $bag['ranking'] = $this->fetcher->fetch_artefact('shapes_ranking_json', $ctx);
                $bag['ranking_chart'] = $this->fetcher->fetch_artefact('shapes_at_carat_chart', $ctx);
                break;
            case 'diamond-type':
                $bag['type_summary'] = $this->fetcher->fetch_artefact('type_summary_json', $ctx);
                break;
            case 'top-level':
                $bag['market_overview'] = $this->fetcher->fetch_artefact('market_overview_json', $ctx);
                if (!is_array($bag['summary']) || empty($bag['summary'])) {
                    $bag['summary'] = is_array($bag['market_overview']) ? $bag['market_overview'] : array();
                }
                break;
        }

        return $bag;
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

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

require_once LDN_PLUGIN_DIR . 'components/trait-ldn-chrome.php';
require_once LDN_PLUGIN_DIR . 'components/trait-ldn-head.php';
require_once LDN_PLUGIN_DIR . 'components/trait-ldn-charts.php';
require_once LDN_PLUGIN_DIR . 'components/trait-ldn-content.php';
require_once LDN_PLUGIN_DIR . 'components/trait-ldn-navigation.php';
require_once LDN_PLUGIN_DIR . 'components/trait-ldn-tables.php';
require_once LDN_PLUGIN_DIR . 'components/trait-ldn-sections.php';
require_once LDN_PLUGIN_DIR . 'components/trait-ldn-schema-bridge.php';
require_once LDN_PLUGIN_DIR . 'components/trait-ldn-url.php';
require_once LDN_PLUGIN_DIR . 'components/trait-ldn-data.php';
require_once LDN_PLUGIN_DIR . 'components/trait-ldn-homepage.php';

final class LDN_Renderer {
    use LDN_Trait_Chrome;
    use LDN_Trait_Head;
    use LDN_Trait_Charts;
    use LDN_Trait_Content;
    use LDN_Trait_Navigation;
    use LDN_Trait_Tables;
    use LDN_Trait_Sections;
    use LDN_Trait_Homepage;
    use LDN_Trait_SchemaBridge;
    use LDN_Trait_Url;
    use LDN_Trait_Data;


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
    const SUPPRESSED_SECTIONS = array('cross_site_comparison', 'type_comparison');

    /**
     * Editorial sections that carry static C1 copy but do NOT use the `_static`
     * suffix in `page_structure`. They are rendered as text blocks like any
     * `_static` section. Mirrors EDITORIAL_STATIC_SECTION_IDS in
     * shared/content/section_prompts.py — keep the two in sync so every section
     * C1 generates is also rendered (otherwise upper-level pages silently drop
     * their generated editorial).
     *
     * @var string[]
     */
    const EDITORIAL_STATIC_SECTIONS = array(
        'type_comparison',
        'shape_preview',
        'natural_vs_lab_analysis',
        'buying_considerations',
        'expert_recommendations',
    );

    /**
     * Display headings for static editorial sections whose auto-generated
     * title (from the section id) would read poorly. Falls back to the
     * title-cased section id when a key is absent.
     *
     * @var array<string, string>
     */
    private static $SECTION_HEADINGS = array(
        'type_comparison'         => 'Natural vs Lab-Grown',
        'shape_preview'           => 'Comparing Diamond Shapes',
        'natural_vs_lab_analysis' => 'Natural vs Lab-Grown Diamonds',
        'price_factors'           => 'What Affects Diamond Prices',
    );

    /**
     * Dynamic section id → copy.json section keys per page level.
     *
     * @var array<string, array<string, string[]>>
     */
    const DYNAMIC_COPY_KEYS = array(
        'all-shapes' => array(
            // Legacy single block — kept for profiles that still list overview_dynamic.
            'overview_dynamic' => array('intro_text', 'analysis', 'shape_analysis'),
            'overview_intro_dynamic' => array('intro_text'),
            'overview_analysis_dynamic' => array('analysis'),
            'overview_detail_dynamic' => array('shape_analysis'),
        ),
        'diamond-type' => array(
            'type_overview_dynamic' => array('intro'),
            'type_buyer_context_dynamic' => array('buyer_context'),
        ),
        'top-level' => array(
            'market_overview_dynamic' => array('intro', 'market_size'),
            'ring_overview_dynamic'   => array('intro', 'market_size'),
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
        $out .= '<h1 class="ldn-page-title">'
            . esc_html($this->headline($ctx, $this->country_in_content_flag($profile, 'h1_headings')))
            . '</h1>';

        $canonical = $this->current_url();
        $out .= $this->breadcrumb_html($ctx, $canonical, $profile);

        // The editorial intro now leads the page; the structured data summary still
        // feeds the meta description + JSON-LD via render_head_content().
        $hero_html = $this->render_hero($layout['hero_component'], $ctx, $bag);
        $sections = is_array($layout['sections']) ? $layout['sections'] : array();

        // A profile can position the hero inline by listing a `hero` token in its
        // sections; otherwise the hero renders first (back-compatible default).
        $hero_inline = in_array('hero', $sections, true);
        if (!$hero_inline) {
            $out .= $hero_html;
        }

        $out .= $this->freshness_html($ctx, $bag['summary'] ?? array());

        foreach ($sections as $section_id) {
            if ((string) $section_id === 'hero') {
                $out .= $hero_html;
                continue;
            }
            $out .= $this->render_section((string) $section_id, $ctx, $bag, $currency);
        }

        $out .= $this->size_price_link_html($ctx);
        $out .= $this->future_feature_mounts($ctx);

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

    private static $PAGE_CHROME_DEFAULTS = array(
        'max_width'         => '1000px',
        'content_padding'   => '1.25rem',
        'section_spacing'   => '2rem',
        'heading_style'     => 'minimal',
    );

    private static $VALID_HEADING_STYLES = array(
        'minimal'         => true,
        'loupe_classic'   => true,
        'ringspo_classic' => true,
    );

    /**
     * Inert mount points for future PRD-006/008 features (gated by ops dashboard).
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    private function future_feature_mounts(LDN_Page_Context $ctx) {
        if (!class_exists('LDN_Plugin')) {
            return '';
        }
        $plugin = LDN_Plugin::instance();
        $country = $ctx->country_code;
        $mounts = array(
            'show_inventory'      => 'ldn-mount-inventory',
            'show_sparklescore'   => 'ldn-mount-sparklescore',
            'show_shortlist'      => 'ldn-mount-shortlist',
            'show_email_capture'  => 'ldn-mount-email-capture',
        );
        $html = '';
        foreach ($mounts as $flag => $class) {
            if (!$plugin->feature_enabled($flag, $country)) {
                continue;
            }
            $html .= '<div class="' . esc_attr($class) . '" data-ldn-feature="'
                . esc_attr($flag) . '" hidden></div>';
        }
        return $html;
    }
}

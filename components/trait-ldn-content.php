<?php
/**
 * Renderer component trait — split from class-ldn-renderer.php (CP53).
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

trait LDN_Trait_Content {
    // PLOTLY_CDN, JSON_SCRIPT_FLAGS, SUPPRESSED_SECTIONS, EDITORIAL_STATIC_SECTIONS,
    // DYNAMIC_COPY_KEYS, and CURRENCY_SYMBOLS live on LDN_Renderer (not here): trait
    // constants require PHP 8.2+; Kinsta staging runs 8.1. self:: in trait methods
    // resolves to the composing class.

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
        'market_guidance'         => 'How to Use These Prices',
        'quality_overview'        => 'Diamond Quality Basics',
        'buying_advice'           => 'Buying advice',
        'pricing_context'         => 'Why this shape is priced this way',
    );

    /**
     * Human shape/type casing.
     */
    private static $TYPE_LABELS = array(
        'natural'   => 'Natural',
        'lab-grown' => 'Lab-Grown',
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
            array('label' => __('Diamonds analyzed', 'loupe-diamond-network'), 'format' => 'integer', 'schema' => true,
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
            . esc_html($this->homepage_headline($ctx, $profile))
            . '</h1>';
        $out .= $this->homepage_tagline_html($ctx, $profile);

        $canonical = $this->current_url();
        $out .= $this->breadcrumb_html($ctx, $canonical, $profile);

        // The editorial intro leads the page; the structured data summary feeds the
        // meta description + JSON-LD via render_head_content(), and the chart's
        // no-JavaScript fallback via render_hero().
        $hero_html = $this->render_hero($layout['hero_component'], $ctx, $bag, $currency);
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

        $hreflang = LDN_Hreflang::from_plugin($this->config);
        $out .= $hreflang->render($ctx, $canonical);

        return $out;
    }

    /**
     * Plain-text factual data summary for AI extraction (CP54_04).
     *
     * One structured sentence — subject, median price, sample size, analysis date
     * — for consumers that do not execute JavaScript. It carries the same claims
     * as the JSON-LD `Dataset.description`, which is deliberate: Google expects a
     * structured-data claim to be supported by content in the page, and until now
     * that description had no counterpart in the HTML. The only difference is the
     * date, rendered here in the site's display format because this copy is
     * visible; the structured data and meta description keep ISO.
     *
     * Returns bare text, not a section. Callers pass it to `chart_html()`, which
     * renders it inside the Plotly target div so it is mutually exclusive with the
     * chart. An earlier version emitted its own `<section>`; that was removed from
     * the page because it restated `intro_html()` (price, sample size) and
     * `freshness_html()` (date, sample size) in different words, and it must not
     * be reinstated as a standalone block for that reason.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @param string|null      $currency
     * @return string Plain text, unescaped — escaped at the point of rendering.
     */
    public function data_summary_text(LDN_Page_Context $ctx, array $summary, $currency = null) {
        $schema = new LDN_Schema();
        // Visible copy, so the date is formatted the same way freshness_html()
        // formats it — a raw ISO date reads as unfinished next to "June 30, 2026"
        // in the freshness line a few hundred pixels below.
        $display_date = $this->localised_date($schema->analysis_date($summary));
        return $schema->dataset_description($ctx, $summary, $currency, $display_date);
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
     * price change, price range). The price-change period is driven by the site's
     * templated_copy.individual_shape policy (e.g. Loupe = 12 months); snapshot
     * families omit the change clause entirely.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @param string|null      $currency ISO code for price formatting.
     * @return string
     */
    public function intro_html(LDN_Page_Context $ctx, array $summary, $currency = null) {
        // Headline price tracks the MEDIAN (p50) — the same figure the carat
        // ladder table shows — so the intro, hero stat and table always agree.
        // Falls back to current_price (trimmed avg) only when no median is present.
        $current_price = $this->dig_first($summary, array(
            array('distribution', 'median_price'),
            array('distribution', 'percentiles', 'p50'),
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

        // Resolve the price-change period from the site's templated_copy policy
        // (individual_shape level). Falls back to legacy 7-day behaviour when no
        // policy is present. Snapshot families (show_change: false) omit the clause.
        $shape_policy = $this->shape_change_policy($ctx);
        $change_period = $shape_policy['period'];
        $show_change = $shape_policy['show_change'];

        if ($change_period !== null) {
            $change_key = 'change_' . $change_period;
            $change_value = $this->dig_first($summary, array(
                array('time_series', $change_key),
                array($change_key),
            ));
        } else {
            $change_value = $this->dig_first($summary, array(
                array('time_series', 'change_7_days'),
                array('change_7d'),
            ));
        }
        $change_value = is_numeric($change_value) ? (float) $change_value : null;
        $change_phrase = $this->change_period_phrase($change_period);

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
        $color_word = $this->locale_color_word($ctx);
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

        $price_text = $symbol . number_format((float) $current_price, 0);
        $diamond_word = $sample_size === 1 ? 'diamond' : 'diamonds';
        $sample_text = number_format($sample_size);

        if ($subject === 'diamond') {
            $paragraph = sprintf(
                'The current price for a diamond in %s is %s, calculated from %s %s that match this carat weight and shape in our database',
                esc_html($country_name),
                esc_html($price_text),
                esc_html($sample_text),
                esc_html($diamond_word)
            );
        } else {
            $paragraph = sprintf(
                'The current price for a %s diamond in %s is %s, calculated from %s %s that match this carat weight and shape in our database',
                esc_html($subject),
                esc_html($country_name),
                esc_html($price_text),
                esc_html($sample_text),
                esc_html($diamond_word)
            );
        }

        if (!$show_change || $change_value === null) {
            $paragraph .= '.';
        } elseif ($change_value == 0.0) {
            $paragraph .= sprintf(
                ', and has remained stable %s.',
                esc_html($change_phrase)
            );
        } else {
            $direction = $change_value > 0 ? 'increased' : 'decreased';
            $paragraph .= sprintf(
                ', and has %s by %s %s.',
                esc_html($direction),
                esc_html(sprintf('%.2f%%', abs($change_value))),
                esc_html($change_phrase)
            );
        }

        $range_paragraph = '';
        if (is_numeric($min_price) && is_numeric($max_price) && (float) $max_price > 0) {
            $range_paragraph = sprintf(
                'Individual stones range from %s to %s, with the difference driven by cut, %s, and clarity.',
                esc_html($symbol . number_format((float) $min_price, 0)),
                esc_html($symbol . number_format((float) $max_price, 0)),
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

    /**
     * Hero-band intro for aggregate hubs (diamond-type / top-level): date sits
     * above this, then the table. Prefer templated copy.json; fall back to
     * type_intro_html on diamond-type pages.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @param string|null      $currency
     * @return string
     */
    private function hub_intro_hero_html(LDN_Page_Context $ctx, array $bag, $currency = null) {
        if ($ctx->page_level === 'diamond-type') {
            // Ringspo owns the type intro in PHP so popular-tier medians ship
            // without waiting on a C5.8 re-run (stale copy.json cited type-wide
            // weighted medians as if they belonged to the most-listed carat).
            if ($ctx->site_id === 'ringspo') {
                $fallback = $this->type_intro_html($ctx, $bag, $currency, true);
                if ($fallback !== '') {
                    $this->type_intro_in_hero = true;
                    return $fallback;
                }
            }
            $sections = $this->copy_sections(is_array($bag['copy']) ? $bag['copy'] : array());
            $text = '';
            if (isset($sections['intro']) && is_scalar($sections['intro']) && (string) $sections['intro'] !== '') {
                $text = (string) $sections['intro'];
            }
            if ($text !== '') {
                $this->type_intro_in_hero = true;
                return '<section class="ldn-type-intro ldn-type-intro--hero-band">'
                    . $this->format_prose_html($text)
                    . '</section>';
            }
            $fallback = $this->type_intro_html($ctx, $bag, $currency, true);
            if ($fallback !== '') {
                $this->type_intro_in_hero = true;
            }
            return $fallback;
        }

        if ($ctx->page_level === 'top-level') {
            // Ringspo hub intro is owned in PHP so the index blurb ships without
            // waiting on a C5.8 re-run, and the weighted-average intro is omitted.
            if ($ctx->site_id === 'ringspo') {
                $ringspo_intro = $this->ringspo_top_level_intro_html($bag);
                if ($ringspo_intro !== '') {
                    $this->market_intro_in_hero = true;
                    return $ringspo_intro;
                }
            }
            $sections = $this->copy_sections(is_array($bag['copy']) ? $bag['copy'] : array());
            $parts = array();
            foreach (array('intro', 'market_size') as $key) {
                if (isset($sections[$key]) && is_scalar($sections[$key]) && (string) $sections[$key] !== '') {
                    $parts[] = (string) $sections[$key];
                }
            }
            if (empty($parts)) {
                return '';
            }
            $this->market_intro_in_hero = true;
            return '<section class="ldn-type-intro ldn-type-intro--hero-band">'
                . $this->format_prose_html(implode("\n\n", $parts))
                . '</section>';
        }

        return '';
    }

    /**
     * Ringspo top-level hero intro: market-size blurb only (no weighted averages).
     *
     * @param array $bag
     * @return string
     */
    private function ringspo_top_level_intro_html(array $bag) {
        $overview = isset($bag['market_overview']) && is_array($bag['market_overview'])
            ? $bag['market_overview']
            : array();
        $total = isset($overview['total_diamonds_tracked']) ? (int) $overview['total_diamonds_tracked'] : 0;
        $natural = isset($overview['natural']) && is_array($overview['natural']) ? $overview['natural'] : array();
        $lab = isset($overview['lab_grown']) && is_array($overview['lab_grown']) ? $overview['lab_grown'] : array();
        $nat_combos = isset($natural['combo_count']) ? (int) $natural['combo_count'] : 0;
        $lab_combos = isset($lab['combo_count']) ? (int) $lab['combo_count'] : 0;

        // Prefer fresh overview numbers; fall back to templated copy.market_size.
        if ($total > 0 && ($nat_combos > 0 || $lab_combos > 0)) {
            $text = sprintf(
                /* translators: 1: total diamonds, 2: natural combo count, 3: lab-grown combo count */
                __(
                    'Our index tracks %1$s diamonds across %2$d natural and %3$d lab-grown '
                    . 'shape and carat combinations, drawn from some of the largest diamond '
                    . 'retailers in the world. Prices are updated every day.',
                    'loupe-diamond-network'
                ),
                number_format_i18n($total),
                $nat_combos,
                $lab_combos
            );
        } else {
            $sections = $this->copy_sections(is_array($bag['copy']) ? $bag['copy'] : array());
            if (!isset($sections['market_size']) || !is_scalar($sections['market_size'])
                || trim((string) $sections['market_size']) === ''
            ) {
                return '';
            }
            $text = (string) $sections['market_size'];
        }

        return '<section class="ldn-type-intro ldn-type-intro--hero-band">'
            . $this->format_prose_html($text)
            . '</section>';
    }

    /**
     * Diamond-type intro from type-summary.json when copy.json is absent or stale.
     *
     * Mirrors the Loupe C5.8 ``diamond_type.intro`` template so the page leads with
     * useful context before the carat-tier table even when templated copy has not
     * been regenerated yet.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @param string|null      $currency ISO code for price formatting.
     * @param bool             $in_hero_band When true, render white-on-green copy
     *                                       inside page_chrome.hero_band (no body
     *                                       section padding).
     * @return string
     */
    public function type_intro_html(LDN_Page_Context $ctx, array $bag, $currency = null, $in_hero_band = false) {
        $payload = is_array($bag['type_summary']) ? $bag['type_summary'] : array();
        $aggregate = isset($payload['aggregate']) && is_array($payload['aggregate'])
            ? $payload['aggregate']
            : array();
        if (empty($aggregate)) {
            return '';
        }

        $carat_count = isset($aggregate['carat_count']) ? (int) $aggregate['carat_count'] : 0;
        $popular = isset($aggregate['most_popular_carat']) ? (string) $aggregate['most_popular_carat'] : '';
        // Cite the popular tier's own median/sample — never type-wide weighted_median.
        $popular_tier = $this->type_summary_carat_tier($payload, $popular);
        $median = isset($popular_tier['median_price']) ? $popular_tier['median_price'] : null;
        $samples = isset($popular_tier['sample_size']) ? (int) $popular_tier['sample_size'] : 0;
        if ($carat_count <= 0) {
            return '';
        }

        $symbol = $this->currency_symbol($currency);
        $include_country = $this->country_in_content_flag($this->profile($ctx), 'body_copy', true, $ctx);
        $country_name = $include_country ? $this->country_full_name($ctx) : '';
        $in_country = $country_name !== '' ? ' in ' . $country_name : '';
        $type_label = $ctx->diamond_type !== null && isset(self::$TYPE_LABELS[$ctx->diamond_type])
            ? self::$TYPE_LABELS[$ctx->diamond_type]
            : ($ctx->diamond_type !== null ? ucwords(str_replace('-', ' ', $ctx->diamond_type)) : 'Diamond');
        $popular_label = $this->format_carat_label($popular);

        $lead = sprintf(
            '%s diamond prices%s span %d carat weights in our index.',
            esc_html($type_label),
            esc_html($in_country),
            $carat_count
        );
        $detail = '';
        if ($popular_label !== '' && is_numeric($median)) {
            $detail = sprintf(
                'The most listed weight is %s carat, with a median of %s across %s tracked stones.',
                esc_html($popular_label),
                esc_html($symbol . number_format((float) $median, 0)),
                esc_html(number_format($samples))
            );
        }

        $body = $lead;
        if ($detail !== '') {
            $body .= "\n\n" . $detail;
        }

        $class = $in_hero_band
            ? 'ldn-type-intro ldn-type-intro--hero-band'
            : 'ldn-section ldn-intro-dynamic ldn-type-intro';

        return '<section class="' . esc_attr($class) . '">'
            . wp_kses_post(wpautop($body))
            . '</section>';
    }

    /**
     * Carat-tier row for the most-listed weight in a type-summary payload.
     *
     * @param array  $payload type-summary.json body
     * @param string $popular Carat weight label from aggregate.most_popular_carat
     * @return array
     */
    /**
     * One carat-tier row from a type-summary payload.
     *
     * @param array  $payload type-summary.json body
     * @param string $carat   Carat weight label
     * @return array
     */
    protected function type_summary_carat_tier(array $payload, $carat) {
        $carat = (string) $carat;
        if ($carat === '') {
            return array();
        }
        $tiers = isset($payload['carat_tiers']) && is_array($payload['carat_tiers'])
            ? $payload['carat_tiers']
            : array();
        foreach ($tiers as $tier) {
            if (!is_array($tier)) {
                continue;
            }
            if ((string) (isset($tier['carat_weight']) ? $tier['carat_weight'] : '') === $carat) {
                return $tier;
            }
        }
        return array();
    }

    public function stats_html(LDN_Page_Context $ctx, array $summary, $currency = null) {
        // Prefer the median (p50) so the headline stat matches the carat
        // ladder table and intro sentence; fall back to current_price (avg).
        $current = $this->dig_first($summary, array(
            array('distribution', 'median_price'),
            array('distribution', 'percentiles', 'p50'),
            array('time_series', 'current_price'),
            array('current_price'),
        ));
        $low = $this->dig_first($summary, array(
            array('distribution', 'price_range', 'min'),
            array('min_price'),
            array('price_low'),
        ));
        $high = $this->dig_first($summary, array(
            array('distribution', 'price_range', 'max'),
            array('max_price'),
            array('price_high'),
        ));
        $samples = $this->dig_first($summary, array(
            array('distribution', 'sample_size'),
            array('num_diamonds'),
            array('sample_size'),
        ));

        $cells = array();
        $price_label = $ctx->page_level === 'all-shapes'
            ? __('Typical price (median)', 'loupe-diamond-network')
            : __('Median (all qualities)', 'loupe-diamond-network');
        if ($current !== null && is_scalar($current) && !is_bool($current)) {
            $cells[] = array(
                'label' => $price_label,
                'value' => $this->format_stat($current, 'currency', $currency),
            );
        }
        if ($samples !== null && is_scalar($samples) && !is_bool($samples)) {
            $cells[] = array(
                'label' => __('Diamonds analyzed', 'loupe-diamond-network'),
                'value' => $this->format_stat($samples, 'integer', $currency),
            );
        }
        if ($ctx->page_level === 'all-shapes') {
            $aggregate = isset($summary['aggregate']) && is_array($summary['aggregate'])
                ? $summary['aggregate']
                : array();
            $shape_count = isset($aggregate['shape_count']) ? (int) $aggregate['shape_count'] : 0;
            if ($shape_count > 0) {
                $cells[] = array(
                    'label' => __('Shapes compared', 'loupe-diamond-network'),
                    'value' => number_format($shape_count),
                );
            }
        }
        if ($low !== null && is_scalar($low) && !is_bool($low)) {
            $cells[] = array(
                'label' => __('Lowest price', 'loupe-diamond-network'),
                'value' => $this->format_stat($low, 'currency', $currency),
            );
        }
        if ($high !== null && is_scalar($high) && !is_bool($high)) {
            $cells[] = array(
                'label' => __('Highest price', 'loupe-diamond-network'),
                'value' => $this->format_stat($high, 'currency', $currency),
            );
        }

        if (empty($cells)) {
            return '';
        }

        $top = array_slice($cells, 0, 2);
        $bottom = array_slice($cells, 2, 2);
        $html = '<div class="ldn-stats">';
        foreach (array($top, $bottom) as $row) {
            if (empty($row)) {
                continue;
            }
            $html .= '<div class="ldn-stats-row">';
            foreach ($row as $cell) {
                $html .= '<div class="ldn-stat"><span class="ldn-stat-label">'
                    . esc_html($cell['label']) . '</span><span class="ldn-stat-value">'
                    . esc_html($cell['value']) . '</span></div>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Headline stat cards for the hero band (page_chrome.hero_band): current
     * (median) price, diamonds analysed, price range, and the period price
     * change. Rendered as a flat card grid so families can skin it edge-to-edge
     * (Ringspo). Returns '' when there is no usable price figure.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @param string|null      $currency
     * @return string
     */
    public function hero_stats_html(LDN_Page_Context $ctx, array $summary, $currency = null) {
        if ($ctx->page_level === 'top-level') {
            return $this->top_level_hero_stats_html($ctx, $summary, $currency);
        }
        if ($ctx->page_level === 'diamond-type') {
            return $this->diamond_type_hero_stats_html($ctx, $summary, $currency);
        }

        $current = $this->dig_first($summary, array(
            array('distribution', 'median_price'),
            array('distribution', 'percentiles', 'p50'),
            array('time_series', 'current_price'),
            array('current_price'),
        ));
        if ($current === null || !is_numeric($current)) {
            return '';
        }

        $samples = $this->dig_first($summary, array(
            array('distribution', 'sample_size'),
            array('num_diamonds'),
            array('sample_size'),
        ));
        $low = $this->dig_first($summary, array(
            array('distribution', 'price_range', 'min'),
            array('min_price'),
            array('price_low'),
        ));
        $high = $this->dig_first($summary, array(
            array('distribution', 'price_range', 'max'),
            array('max_price'),
            array('price_high'),
        ));

        $symbol = $this->currency_symbol($currency);

        $price_label = $ctx->page_level === 'all-shapes'
            ? __('Typical price (median)', 'loupe-diamond-network')
            : __('Median (all qualities)', 'loupe-diamond-network');

        $cards = array();
        $cards[] = array(
            'label' => $price_label,
            'value' => $this->format_stat($current, 'currency', $currency),
        );
        if ($samples !== null && is_numeric($samples)) {
            $cards[] = array(
                'label' => __('Diamonds analyzed', 'loupe-diamond-network'),
                'value' => $this->format_stat($samples, 'integer', $currency),
            );
        }
        if ($ctx->page_level === 'all-shapes') {
            $aggregate = isset($summary['aggregate']) && is_array($summary['aggregate'])
                ? $summary['aggregate']
                : array();
            $shape_count = isset($aggregate['shape_count']) ? (int) $aggregate['shape_count'] : 0;
            if ($shape_count > 0) {
                $cards[] = array(
                    'label' => __('Shapes compared', 'loupe-diamond-network'),
                    'value' => number_format($shape_count),
                );
            }
        }
        if (is_numeric($low) && is_numeric($high) && (float) $high > 0) {
            $cards[] = array(
                'label' => __('Price range', 'loupe-diamond-network'),
                'value' => $symbol . number_format((float) $low, 0) . ' – ' . $symbol . number_format((float) $high, 0),
            );
        }

        // Period price change (policy-driven; e.g. Ringspo headlines 1 month).
        $policy = $this->shape_change_policy($ctx);
        $period = $policy['period'];
        if ($policy['show_change']) {
            $change_key = $period !== null ? 'change_' . $period : 'change_7_days';
            $change = $this->dig_first($summary, array(
                array('time_series', $change_key),
                array($change_key),
            ));
            if ($change !== null && is_numeric($change)) {
                $change = (float) $change;
                if ($change > 0) {
                    $glyph = "\xE2\x96\xB2 "; // ▲
                    $trend = 'up';
                } elseif ($change < 0) {
                    $glyph = "\xE2\x96\xBC "; // ▼
                    $trend = 'down';
                } else {
                    $glyph = '';
                    $trend = 'flat';
                }
                $cards[] = array(
                    'label' => sprintf(
                        /* translators: %s: period adjective, e.g. "1-month" */
                        __('%s change', 'loupe-diamond-network'),
                        $this->change_period_short_label($period)
                    ),
                    'value' => $glyph . sprintf('%.2f%%', abs($change)),
                    'trend' => $trend,
                );
            }
        }

        $html = '<div class="ldn-hero-stats">';
        foreach ($cards as $card) {
            $trend_class = isset($card['trend']) ? ' ldn-stat--' . $card['trend'] : '';
            $html .= '<div class="ldn-stat ldn-hero-stat' . $trend_class . '">'
                . '<span class="ldn-stat-label">' . esc_html($card['label']) . '</span>'
                . '<span class="ldn-stat-value">' . esc_html($card['value']) . '</span>'
                . '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Hero stat cards for the top-level market hub (page_chrome.hero_band).
     *
     * Uses market-overview.json fields — not a single-stone median — so the
     * green band matches shape/all-shapes pages without mislabelling a weighted
     * composite as one diamond's price.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $overview market-overview payload (also in $bag['summary'])
     * @param string|null      $currency
     * @return string
     */
    /**
     * Hero stat cards for diamond-type hubs (page_chrome.hero_band).
     *
     * Uses type-summary aggregate + anchor-carat tier — not a single shape median.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $payload type-summary payload (also in $bag['summary'])
     * @param string|null      $currency
     * @return string
     */
    private function diamond_type_hero_stats_html(LDN_Page_Context $ctx, array $payload, $currency = null) {
        $aggregate = isset($payload['aggregate']) && is_array($payload['aggregate'])
            ? $payload['aggregate']
            : array();
        $carat_count = isset($aggregate['carat_count']) ? (int) $aggregate['carat_count'] : 0;
        $total = isset($aggregate['total_sample_size']) ? (int) $aggregate['total_sample_size'] : 0;
        if ($carat_count <= 0 && $total <= 0) {
            return '';
        }

        $symbol = $this->currency_symbol(
            isset($payload['currency']) ? (string) $payload['currency'] : $currency
        );

        $cards = array();
        if ($carat_count > 0) {
            $cards[] = array(
                'label' => __('Carat weights', 'loupe-diamond-network'),
                'value' => number_format($carat_count),
            );
        }
        if ($total > 0) {
            $cards[] = array(
                'label' => __('Diamonds tracked', 'loupe-diamond-network'),
                'value' => number_format($total),
            );
        }

        if (empty($cards)) {
            return '';
        }

        $html = '<div class="ldn-hero-stats">';
        foreach ($cards as $card) {
            $html .= '<div class="ldn-stat ldn-hero-stat">'
                . '<span class="ldn-stat-label">' . esc_html($card['label']) . '</span>'
                . '<span class="ldn-stat-value">' . esc_html($card['value']) . '</span>'
                . '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    private function top_level_hero_stats_html(LDN_Page_Context $ctx, array $overview, $currency = null) {
        $total = isset($overview['total_diamonds_tracked'])
            ? (int) $overview['total_diamonds_tracked']
            : 0;
        if ($total <= 0) {
            return '';
        }

        $natural = isset($overview['natural']) && is_array($overview['natural'])
            ? $overview['natural']
            : array();
        $lab = isset($overview['lab_grown']) && is_array($overview['lab_grown'])
            ? $overview['lab_grown']
            : array();
        $nat_combos = isset($natural['combo_count']) ? (int) $natural['combo_count'] : 0;
        $lab_combos = isset($lab['combo_count']) ? (int) $lab['combo_count'] : 0;

        $symbol = $this->currency_symbol(
            isset($overview['currency']) ? (string) $overview['currency'] : $currency
        );

        $cards = array();
        $cards[] = array(
            'label' => __('Diamonds tracked', 'loupe-diamond-network'),
            'value' => number_format($total),
        );

        if ($nat_combos > 0 || $lab_combos > 0) {
            $combo_parts = array();
            if ($nat_combos > 0) {
                $combo_parts[] = sprintf(
                    /* translators: %d: combination count */
                    __('%d natural', 'loupe-diamond-network'),
                    $nat_combos
                );
            }
            if ($lab_combos > 0) {
                $combo_parts[] = sprintf(
                    /* translators: %d: combination count */
                    __('%d lab-grown', 'loupe-diamond-network'),
                    $lab_combos
                );
            }
            $cards[] = array(
                'label' => __('Shape & carat combinations', 'loupe-diamond-network'),
                'value' => implode(' · ', $combo_parts),
            );
        }

        $anchor_carat = $this->hub_anchor_carat();
        $row = $this->hub_carat_table_row($overview, $anchor_carat);
        $nat_price = isset($row['natural_median_price']) ? $row['natural_median_price'] : null;
        if (is_numeric($nat_price)) {
            $cards[] = array(
                'label' => sprintf(
                    /* translators: %s: carat label */
                    __('%s ct natural (typical)', 'loupe-diamond-network'),
                    $this->format_carat_label($anchor_carat)
                ),
                'value' => $symbol . number_format((float) $nat_price, 0),
            );
        }

        $discount = isset($row['lab_grown_discount_pct']) ? $row['lab_grown_discount_pct'] : null;
        if (is_numeric($discount)) {
            $cards[] = array(
                'label' => sprintf(
                    /* translators: %s: carat label */
                    __('%s ct lab-grown discount', 'loupe-diamond-network'),
                    $this->format_carat_label($anchor_carat)
                ),
                'value' => sprintf('%.1f%%', (float) $discount),
            );
        }

        $html = '<div class="ldn-hero-stats">';
        foreach ($cards as $card) {
            $html .= '<div class="ldn-stat ldn-hero-stat">'
                . '<span class="ldn-stat-label">' . esc_html($card['label']) . '</span>'
                . '<span class="ldn-stat-value">' . esc_html($card['value']) . '</span>'
                . '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Anchor carat for hub entry points (hero stats, type nav, table highlight).
     *
     * On diamond-type pages, prefer an explicit `?carat=` query (so Natural ↔
     * Lab-grown preserves the slider weight) when it matches a published tier;
     * otherwise fall back to the type's most-listed carat, then 1 ct.
     *
     * @param LDN_Page_Context|null $ctx
     * @param array                 $bag
     * @return string
     */
    protected function hub_anchor_carat(?LDN_Page_Context $ctx = null, array $bag = array()) {
        if ($ctx !== null && $ctx->page_level === 'diamond-type') {
            $requested = $this->requested_type_carat($bag);
            if ($requested !== '') {
                return $requested;
            }
            $payload = isset($bag['type_summary']) && is_array($bag['type_summary'])
                ? $bag['type_summary']
                : array();
            $aggregate = isset($payload['aggregate']) && is_array($payload['aggregate'])
                ? $payload['aggregate']
                : array();
            $popular = isset($aggregate['most_popular_carat'])
                ? (string) $aggregate['most_popular_carat']
                : '';
            if ($popular !== '') {
                return $popular;
            }
        }
        return '1';
    }

    /**
     * Validated `?carat=` for diamond-type hubs, or '' when absent / unknown.
     *
     * @param array $bag Prefetch bag (type_summary.carat_tiers used when present)
     * @return string
     */
    protected function requested_type_carat(array $bag = array()) {
        if (!isset($_GET['carat'])) {
            return '';
        }
        $raw = $_GET['carat'];
        if (is_array($raw)) {
            return '';
        }
        $raw = function_exists('wp_unslash') ? wp_unslash((string) $raw) : (string) $raw;
        $raw = trim($raw);
        if ($raw === '' || !is_numeric($raw)) {
            return '';
        }

        $payload = isset($bag['type_summary']) && is_array($bag['type_summary'])
            ? $bag['type_summary']
            : array();
        $tiers = isset($payload['carat_tiers']) && is_array($payload['carat_tiers'])
            ? $payload['carat_tiers']
            : array();
        if (empty($tiers)) {
            // No tier list yet — accept the numeric value so toggle URLs can
            // still carry the selection from the current page's anchor.
            return $raw;
        }

        $target = (float) $raw;
        foreach ($tiers as $tier) {
            if (!is_array($tier) || !isset($tier['carat_weight'])) {
                continue;
            }
            if ((float) $tier['carat_weight'] === $target) {
                return (string) $tier['carat_weight'];
            }
        }
        return '';
    }

    /**
     * Append `carat=` to a diamond-type hub URL for nat/lab toggle continuity.
     *
     * @param string $url
     * @param string $carat
     * @return string
     */
    protected function append_hub_carat_query($url, $carat) {
        $url = (string) $url;
        $carat = (string) $carat;
        if ($url === '' || $carat === '' || !is_numeric($carat)) {
            return $url;
        }
        if (function_exists('add_query_arg')) {
            return (string) add_query_arg('carat', $carat, $url);
        }
        $sep = strpos($url, '?') === false ? '?' : '&';
        return $url . $sep . 'carat=' . rawurlencode($carat);
    }

    /**
     * Nat/lab toggle and type-level carat lookup in the diamond-type hero band.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @param string|null      $currency
     * @return string
     */
    public function diamond_type_hero_tools_html(LDN_Page_Context $ctx, array $bag, $currency = null) {
        if ($ctx->page_level !== 'diamond-type') {
            return '';
        }

        $toggle = $this->nat_lab_toggle_html($ctx, $bag);
        $lookup = $this->type_carat_lookup_html($ctx, $bag, $currency);
        if ($toggle === '' && $lookup === '') {
            return '';
        }

        return '<div class="ldn-diamond-type-hero-tools">' . $toggle . $lookup . '</div>';
    }

    /**
     * One row from market-overview's carat_price_table by carat weight.
     *
     * @param array  $overview
     * @param string $carat
     * @return array
     */
    protected function hub_carat_table_row(array $overview, $carat) {
        $rows = isset($overview['carat_price_table']) && is_array($overview['carat_price_table'])
            ? $overview['carat_price_table']
            : array();
        $target = (float) $carat;
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['carat_weight'])) {
                continue;
            }
            if ((float) $row['carat_weight'] === $target) {
                return $row;
            }
        }
        return array();
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
    /**
     * Replace em/en dashes with a spaced hyphen. Mirrors
     * shared.content.anti_ai_copy.strip_em_en_dashes so stale S3 copy cannot
     * paint a tell the pipeline backstop already forbids.
     *
     * @param string $text
     * @return string
     */
    public function strip_em_en_dashes($text) {
        $text = str_replace(array("\u{2014}", "\u{2013}"), ' - ', (string) $text);
        return preg_replace('/[^\S\n]{2,}/', ' ', $text);
    }

    public function format_prose_html($text) {
        $text = trim($this->strip_em_en_dashes((string) $text));
        if ($text === '') {
            return '';
        }
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        if (strpos($text, "\n\n") !== false) {
            return wp_kses_post(wpautop($text));
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
            $q = $this->strip_em_en_dashes((string) $q);
            $a = $this->strip_em_en_dashes((string) $a);
            $items .= '<dt>' . esc_html($q) . '</dt><dd>' . wp_kses_post(wpautop($a)) . '</dd>';
        }
        if ($items === '') {
            return '';
        }
        return '<section class="ldn-section ldn-faq"><h2>' . esc_html(__('FAQ', 'loupe-diamond-network')) . '</h2><dl>' . $items . '</dl></section>';
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
        $heading = isset(self::$SECTION_HEADINGS[$base])
            ? self::$SECTION_HEADINGS[$base]
            : ucwords(str_replace('_', ' ', $base));
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
        $paragraphs = '';
        $skip_shape_analysis = $this->ranking_unique_shape_count($bag) === 1;
        foreach ($keys as $key) {
            if ($skip_shape_analysis && $key === 'shape_analysis') {
                continue;
            }
            if (!isset($sections[$key]) || !is_scalar($sections[$key]) || (string) $sections[$key] === '') {
                continue;
            }
            $text = (string) $sections[$key];
            if ($skip_shape_analysis && $key === 'intro_text') {
                $parts = preg_split('/\n\s*\n/', trim($text), 2);
                $text = is_array($parts) && isset($parts[0]) ? $parts[0] : $text;
                $text = preg_replace('/\s+They run from\b.*$/s', '', $text);
            }
            $paragraphs .= $this->format_prose_html($text);
        }
        if ($paragraphs === '') {
            return '';
        }
        $section_class = strpos($section_id, '_combined_') !== false
            ? 'ldn-copy-overview'
            : 'ldn-copy-' . esc_attr($keys[0]);
        return '<section class="ldn-section ' . $section_class . '">' . $paragraphs . '</section>';
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
        $structure = method_exists($this->config, 'url_structure_for')
            ? $this->config->url_structure_for($ctx->site_id, $ctx->country_code)
            : $this->config->get_url_structure($ctx->site_id);
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
            '{type}'    => $this->type_url_slug($ctx->site_id, $type, $ctx->country_code),
            '{carat}'   => $this->format_carat_slug($ctx->site_id, $carat, $ctx->country_code),
            '{shape}'   => $this->config->shape_to_url_slug($shape, $ctx->site_id, $ctx->country_code),
        );

        $path = $pattern;
        foreach ($replacements as $placeholder => $value) {
            if ($value === '' && strpos($path, $placeholder) !== false) {
                return '';
            }
            $path = str_replace($placeholder, $value, $path);
        }

        if (method_exists($this->config, 'path_relative_to_mount')) {
            $path = $this->config->path_relative_to_mount($path);
        }
        return home_url(user_trailingslashit(ltrim($path, '/')));
    }

    /**
     * Distinct shape names in the all-shapes ranking payload, or 0 when unknown.
     *
     * Used to suppress a stale shape_analysis paragraph that compares a shape
     * to itself. 0 means "we do not know", so copy still paints.
     *
     * @param array $bag
     * @return int
     */
    private function ranking_unique_shape_count(array $bag) {
        $ranking = isset($bag['ranking']) && is_array($bag['ranking']) ? $bag['ranking'] : array();
        $rows = array();
        if (isset($ranking['shapes']) && is_array($ranking['shapes'])) {
            $rows = $ranking['shapes'];
        } elseif (isset($ranking[0]) && is_array($ranking[0])) {
            $rows = $ranking;
        }
        $names = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = strtolower(trim((string) ($row['shape'] ?? '')));
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        return count($names);
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
}

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
            . esc_html($this->homepage_headline($ctx, $profile))
            . '</h1>';
        $out .= $this->homepage_tagline_html($ctx, $profile);

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
     * Diamond-type intro from type-summary.json when copy.json is absent or stale.
     *
     * Mirrors the Loupe C5.8 ``diamond_type.intro`` template so the page leads with
     * useful context before the carat-tier table even when templated copy has not
     * been regenerated yet.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @param string|null      $currency ISO code for price formatting.
     * @return string
     */
    public function type_intro_html(LDN_Page_Context $ctx, array $bag, $currency = null) {
        $payload = is_array($bag['type_summary']) ? $bag['type_summary'] : array();
        $aggregate = isset($payload['aggregate']) && is_array($payload['aggregate'])
            ? $payload['aggregate']
            : array();
        if (empty($aggregate)) {
            return '';
        }

        $carat_count = isset($aggregate['carat_count']) ? (int) $aggregate['carat_count'] : 0;
        $popular = isset($aggregate['most_popular_carat']) ? (string) $aggregate['most_popular_carat'] : '';
        $median = isset($aggregate['weighted_median_price']) ? $aggregate['weighted_median_price'] : null;
        $samples = isset($aggregate['total_sample_size']) ? (int) $aggregate['total_sample_size'] : 0;
        if ($carat_count <= 0 || !is_numeric($median)) {
            return '';
        }

        $symbol = $this->currency_symbol($currency);
        $country_name = $this->country_full_name($ctx);
        $type_label = $ctx->diamond_type !== null && isset(self::$TYPE_LABELS[$ctx->diamond_type])
            ? self::$TYPE_LABELS[$ctx->diamond_type]
            : ($ctx->diamond_type !== null ? ucwords(str_replace('-', ' ', $ctx->diamond_type)) : 'Diamond');
        $popular_label = $this->format_carat_label($popular);

        $lead = sprintf(
            '%s diamond prices in %s span %d carat weights in our index — from entry-level sizes to stones well above the 1 carat mark.',
            esc_html($type_label),
            esc_html($country_name),
            $carat_count
        );
        $detail = '';
        if ($popular_label !== '' && is_numeric($median)) {
            $detail = sprintf(
                '%s carat is the most searched weight, with a median of %s across every shape at that weight.',
                esc_html($popular_label),
                esc_html($symbol . number_format((float) $median, 0))
            );
        }

        $body = $lead;
        if ($detail !== '') {
            $body .= "\n\n" . $detail;
        }

        return '<section class="ldn-section ldn-intro-dynamic ldn-type-intro">'
            . wp_kses_post(wpautop($body))
            . '</section>';
    }

    public function stats_html(LDN_Page_Context $ctx, array $summary, $currency = null) {
        $current = $this->dig_first($summary, array(
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
        if ($current !== null && is_scalar($current) && !is_bool($current)) {
            $cells[] = array(
                'label' => __('Current price', 'loupe-diamond-network'),
                'value' => $this->format_stat($current, 'currency', $currency),
            );
        }
        if ($samples !== null && is_scalar($samples) && !is_bool($samples)) {
            $cells[] = array(
                'label' => __('Diamonds analysed', 'loupe-diamond-network'),
                'value' => $this->format_stat($samples, 'integer', $currency),
            );
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
            $items .= '<dt>' . esc_html((string) $q) . '</dt><dd>' . wp_kses_post(wpautop((string) $a)) . '</dd>';
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
}

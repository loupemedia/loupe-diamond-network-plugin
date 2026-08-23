<?php
/**
 * Schema.org JSON-LD generator — PRD-005 CP54 (SEO & structured data).
 *
 * Builds the page's structured data as a single `@graph` document and renders it
 * as one `<script type="application/ld+json">` block. The node set is driven by
 * the site's content-profile `schema_type` + `schema_features` — the SAME config
 * the Python pipeline reads in `shared/charts/base.py::build_page_metadata()`, so
 * PHP (every page level) and Python (standalone chart HTML) stay in contract sync
 * without duplicating the type→node mapping anywhere but config:
 *
 *   schema_type           primary nodes
 *   --------------------  -------------------------------
 *   market_data           Dataset
 *   market_data_article   Dataset + Article (when 'article' in schema_features)
 *   hybrid                Dataset + ItemList (when items available)
 *   recommendation        Dataset + ItemList (when items available)
 *   product_market_data   Dataset
 *   educational_content   Article (when 'article' in schema_features), else Dataset
 *   (default)             Dataset
 *
 * Always layered on top (enrichment beyond the Python builder):
 *   - an Organization node (publisher/creator, referenced by @id)
 *   - a BreadcrumbList (when a trail of >= 2 crumbs is supplied)
 *   - a FAQPage (when 'faq' in schema_features and pairs are present)
 *   - Dataset enrichment: page url, dateModified, temporalCoverage,
 *     spatialCoverage, isPartOf (the site WebSite), keywords, isAccessibleForFree
 *
 * Pure builders take arrays in and return arrays out (unit-testable without
 * WordPress beyond wp_json_encode / esc_*). The renderer wires them to fetched
 * artefact data; `LDN_Renderer` owns URL construction and supplies the
 * breadcrumb trail and any list items so URL logic lives in one place (DRY).
 *
 * @package LoupeDiamondNetwork
 * @since   0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Schema {

    /**
     * JSON flags for embedding inside <script> safely (escapes </script>).
     */
    const JSON_SCRIPT_FLAGS = JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * ISO currency code → display symbol (mirrors LDN_Renderer::CURRENCY_SYMBOLS).
     * Used only for the human-readable description string; PropertyValue carries
     * the ISO code in `unitText`, not the symbol.
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
     * Headline-stat specs for `variableMeasured`. Mirrors the resolution paths in
     * LDN_Renderer::stat_specs() so the on-page stats and the Dataset agree.
     *
     * @return array<int, array{label:string, format:string, paths:array}>
     */
    private static function measured_specs() {
        return array(
            array('label' => 'Current price', 'format' => 'currency',
                'paths' => array(array('time_series', 'current_price'), array('current_price'))),
            array('label' => 'Median price', 'format' => 'currency',
                'paths' => array(array('distribution', 'median_price'), array('median_price'))),
            array('label' => 'Lowest price', 'format' => 'currency',
                'paths' => array(array('distribution', 'price_range', 'min'), array('min_price'), array('price_low'))),
            array('label' => 'Highest price', 'format' => 'currency',
                'paths' => array(array('distribution', 'price_range', 'max'), array('max_price'), array('price_high'))),
            array('label' => __('Diamonds analyzed', 'loupe-diamond-network'), 'format' => 'integer',
                'paths' => array(array('distribution', 'sample_size'), array('num_diamonds'), array('sample_size'))),
        );
    }

    /**
     * Candidate paths (first hit wins) for the dataset's modified/coverage date.
     *
     * @var array<int, string[]>
     */
    const DATE_PATHS = array(
        array('time_series', 'analysis_date'),
        array('metadata', 'open_graph', 'og:updated_time'),
        array('analysis_date'),
        array('analysis_date_iso'),
    );

    /**
     * Render the full JSON-LD `<script>` for a page, or '' when nothing to emit.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary       summary-data payload (level-appropriate)
     * @param array            $profile       resolved content profile
     * @param array            $site          site config (brand_name, domain, countries)
     * @param string|null      $currency      ISO code for price unitText
     * @param string           $canonical_url absolute page URL ('' when unknown)
     * @param array            $breadcrumb    [['name'=>, 'url'=>], ...]
     * @param array            $faq_pairs     [['question'=>, 'answer'=>], ...]
     * @param array            $items         [['name'=>, 'price'=>, 'currency'=>, 'url'=>], ...]
     * @return string
     */
    public function render(
        LDN_Page_Context $ctx,
        array $summary,
        array $profile,
        array $site,
        $currency = null,
        $canonical_url = '',
        array $breadcrumb = array(),
        array $faq_pairs = array(),
        array $items = array()
    ) {
        if (!apply_filters('ldn_emit_json_ld', true, $ctx)) {
            return '';
        }

        $graph = $this->build_graph(
            $ctx, $summary, $profile, $site, $currency, $canonical_url, $breadcrumb, $faq_pairs, $items
        );
        if (empty($graph)) {
            return '';
        }

        $doc = array('@context' => 'https://schema.org', '@graph' => $graph);
        $json = wp_json_encode($doc, self::JSON_SCRIPT_FLAGS);
        if (!is_string($json) || $json === '') {
            return '';
        }
        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /**
     * WebApplication JSON-LD for the calculator destination page.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $site
     * @param string           $name
     * @param string           $description
     * @param string           $canonical_url
     * @return string
     */
    public function render_webapp(
        LDN_Page_Context $ctx,
        array $site,
        $name,
        $description,
        $canonical_url
    ) {
        if (!apply_filters('ldn_emit_json_ld', true, $ctx)) {
            return '';
        }

        $org = $this->organization_node($site);
        $node = array(
            '@type'               => 'WebApplication',
            '@id'                 => rtrim((string) $canonical_url, '/') . '#webapp',
            'name'                => (string) $name,
            'description'         => (string) $description,
            'url'                 => (string) $canonical_url,
            'applicationCategory' => 'FinanceApplication',
            'operatingSystem'     => 'Any (web-based)',
            'isAccessibleForFree' => true,
            'provider'            => $org,
        );

        $doc = array('@context' => 'https://schema.org', '@graph' => array($org, $node));
        $json = wp_json_encode($doc, self::JSON_SCRIPT_FLAGS);
        if (!is_string($json) || $json === '') {
            return '';
        }
        return '<script type="application/ld+json">' . $json . '</script>' . "\n";
    }

    /**
     * Assemble the `@graph` node list (pure — unit-tested).
     *
     * @return array<int, array<string, mixed>>
     */
    public function build_graph(
        LDN_Page_Context $ctx,
        array $summary,
        array $profile,
        array $site,
        $currency = null,
        $canonical_url = '',
        array $breadcrumb = array(),
        array $faq_pairs = array(),
        array $items = array()
    ) {
        $schema_type = isset($profile['schema_type']) ? (string) $profile['schema_type'] : 'market_data';
        $features = isset($profile['schema_features']) && is_array($profile['schema_features'])
            ? $profile['schema_features']
            : array();

        $org = $this->organization_node($site);
        $org_ref = isset($org['@id']) ? array('@id' => $org['@id']) : null;

        $graph = array($org);

        $primary = $this->primary_nodes(
            $schema_type, $features, $ctx, $summary, $profile, $site, $currency, $canonical_url, $items, $org_ref
        );
        foreach ($primary as $node) {
            if (is_array($node) && !empty($node)) {
                $graph[] = $node;
            }
        }

        if (in_array('faq', $features, true)) {
            $faq = $this->faq_node($faq_pairs);
            if ($faq !== null) {
                $graph[] = $faq;
            }
        }

        $crumbs = $this->breadcrumb_node($breadcrumb);
        if ($crumbs !== null) {
            $graph[] = $crumbs;
        }

        return $graph;
    }

    /**
     * Primary content nodes for the schema_type (Dataset / Article / ItemList).
     *
     * @return array<int, array<string, mixed>|null>
     */
    private function primary_nodes(
        $schema_type, array $features, LDN_Page_Context $ctx, array $summary, array $profile,
        array $site, $currency, $canonical_url, array $items, $org_ref
    ) {
        $dataset = $this->dataset_node($ctx, $summary, $profile, $site, $currency, $canonical_url, $org_ref);
        $article = $this->article_node($ctx, $summary, $profile, $canonical_url, $org_ref);
        $item_list = $this->item_list_node($ctx, $items, $canonical_url);

        switch ($schema_type) {
            case 'market_data_article':
                return array($dataset, in_array('article', $features, true) ? $article : null);
            case 'educational_content':
                return in_array('article', $features, true)
                    ? array($article)
                    : array($dataset);
            case 'hybrid':
            case 'recommendation':
                return array($dataset, $item_list);
            case 'product_market_data':
            case 'market_data':
            default:
                return array($dataset);
        }
    }

    // =========================================================================
    // Pure node builders
    // =========================================================================

    /**
     * Publisher/creator Organization node (referenced by @id elsewhere).
     *
     * @param array $site
     * @return array<string, mixed>
     */
    public function organization_node(array $site) {
        $domain = isset($site['domain']) ? trim((string) $site['domain']) : '';
        $name = isset($site['brand_name']) ? (string) $site['brand_name'] : $domain;
        $base = $domain !== '' ? 'https://' . $domain : $this->home();

        $node = array(
            '@type' => 'Organization',
            '@id'   => ($base !== '' ? rtrim($base, '/') : '') . '/#organization',
            'name'  => $name,
        );
        if ($base !== '') {
            $node['url'] = trailingslashit($base);
        }
        return $node;
    }

    /**
     * Enriched Schema.org Dataset node.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @param array            $profile
     * @param array            $site
     * @param string|null      $currency
     * @param string           $canonical_url
     * @param array|null       $org_ref  {'@id': ...} for publisher/creator.
     * @return array<string, mixed>
     */
    public function dataset_node(
        LDN_Page_Context $ctx, array $summary, array $profile, array $site,
        $currency = null, $canonical_url = '', $org_ref = null
    ) {
        $suffix = isset($profile['schema_dataset_name_suffix'])
            ? ' ' . trim((string) $profile['schema_dataset_name_suffix'])
            : '';

        $node = array(
            '@type'              => 'Dataset',
            'name'               => $this->headline($ctx, $site) . $suffix,
            'description'        => $this->dataset_description($ctx, $summary, $currency, null, $site),
            'isAccessibleForFree' => true,
        );
        if ($canonical_url !== '') {
            $node['@id'] = $canonical_url . '#dataset';
            $node['url'] = $canonical_url;
        }

        $keywords = $this->keywords($ctx);
        if (!empty($keywords)) {
            $node['keywords'] = $keywords;
        }

        $place = $this->country_full_name($ctx, $site);
        if ($place !== '') {
            $node['spatialCoverage'] = array('@type' => 'Place', 'name' => $place);
        }

        $date = $this->dataset_date($summary);
        if ($date !== '') {
            $node['dateModified'] = $date;
            $node['temporalCoverage'] = $date;
        }

        if (is_array($org_ref)) {
            $node['creator'] = $org_ref;
            $node['publisher'] = $org_ref;
        }

        $website = $this->website_ref($site);
        if ($website !== null) {
            $node['isPartOf'] = $website;
        }

        $measured = $this->variable_measured($summary, $currency);
        if (!empty($measured)) {
            $node['variableMeasured'] = $measured;
        }

        return $node;
    }

    /**
     * Schema.org Article node (for article/editorial schema types).
     *
     * @return array<string, mixed>
     */
    public function article_node(
        LDN_Page_Context $ctx, array $summary, array $profile, $canonical_url = '', $org_ref = null
    ) {
        $node = array(
            '@type'    => 'Article',
            'headline' => $this->headline($ctx),
            'description' => $this->dataset_description($ctx, $summary, null),
        );
        if ($canonical_url !== '') {
            $node['@id'] = $canonical_url . '#article';
            $node['mainEntityOfPage'] = $canonical_url;
        }
        if (is_array($org_ref)) {
            $node['author'] = $org_ref;
            $node['publisher'] = $org_ref;
        }
        $date = $this->dataset_date($summary);
        if ($date !== '') {
            $node['datePublished'] = $date;
            $node['dateModified'] = $date;
        }
        return $node;
    }

    /**
     * Schema.org ItemList of priced products, or null when no items.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $items [['name'=>, 'price'=>, 'currency'=>, 'url'=>], ...]
     * @param string           $canonical_url
     * @return array<string, mixed>|null
     */
    public function item_list_node(LDN_Page_Context $ctx, array $items, $canonical_url = '') {
        $elements = array();
        $position = 1;
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['name'])) {
                continue;
            }
            $product = array('@type' => 'Product', 'name' => (string) $item['name']);
            if (isset($item['url']) && $item['url'] !== '') {
                $product['url'] = (string) $item['url'];
            }
            if (isset($item['price']) && is_numeric($item['price'])) {
                $product['offers'] = array(
                    '@type' => 'Offer',
                    'price' => (string) $item['price'],
                    'priceCurrency' => strtoupper((string) ($item['currency'] ?? 'USD')),
                );
            }
            $elements[] = array(
                '@type'    => 'ListItem',
                'position' => $position++,
                'item'     => $product,
            );
        }
        if (empty($elements)) {
            return null;
        }

        $node = array(
            '@type'          => 'ItemList',
            'name'           => $this->headline($ctx),
            'numberOfItems'  => count($elements),
            'itemListElement' => $elements,
        );
        if ($canonical_url !== '') {
            $node['url'] = $canonical_url;
        }
        return $node;
    }

    /**
     * Schema.org FAQPage node from {question, answer} pairs, or null.
     *
     * @param array $faq_pairs
     * @return array<string, mixed>|null
     */
    public function faq_node(array $faq_pairs) {
        $entities = array();
        foreach ($faq_pairs as $qa) {
            if (!is_array($qa)) {
                continue;
            }
            $q = $qa['question'] ?? ($qa['q'] ?? null);
            $a = $qa['answer'] ?? ($qa['a'] ?? null);
            if (!is_scalar($q) || !is_scalar($a) || (string) $q === '' || (string) $a === '') {
                continue;
            }
            $entities[] = array(
                '@type' => 'Question',
                'name'  => (string) $q,
                'acceptedAnswer' => array('@type' => 'Answer', 'text' => (string) $a),
            );
        }
        if (empty($entities)) {
            return null;
        }
        return array('@type' => 'FAQPage', 'mainEntity' => $entities);
    }

    /**
     * Schema.org BreadcrumbList node from a trail, or null when < 2 crumbs.
     *
     * @param array $trail [['name'=>, 'url'=>], ...]
     * @return array<string, mixed>|null
     */
    public function breadcrumb_node(array $trail) {
        $elements = array();
        $position = 1;
        foreach ($trail as $crumb) {
            if (!is_array($crumb) || empty($crumb['name'])) {
                continue;
            }
            $item = array(
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => (string) $crumb['name'],
            );
            if (!empty($crumb['url'])) {
                $item['item'] = (string) $crumb['url'];
            }
            $elements[] = $item;
        }
        if (count($elements) < 2) {
            return null;
        }
        return array('@type' => 'BreadcrumbList', 'itemListElement' => $elements);
    }

    // =========================================================================
    // Value helpers
    // =========================================================================

    /**
     * `variableMeasured` PropertyValue list from a summary payload.
     *
     * @param array       $summary
     * @param string|null $currency
     * @return array<int, array<string, mixed>>
     */
    public function variable_measured(array $summary, $currency = null) {
        $out = array();
        foreach (self::measured_specs() as $spec) {
            $value = $this->dig_first($summary, $spec['paths']);
            if ($value === null || !is_scalar($value) || is_bool($value)) {
                continue;
            }
            $pv = array(
                '@type' => 'PropertyValue',
                'name'  => $spec['label'],
                'value' => $value + 0,
            );
            if ($spec['format'] === 'currency' && $currency) {
                $pv['unitText'] = strtoupper((string) $currency);
            }
            $out[] = $pv;
        }
        return $out;
    }

    /**
     * Build a citation-friendly Dataset/Article description with live numbers.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @param string|null      $currency
     * @param string|null      $display_date Human-formatted date to use in place of
     *                                       the ISO one. Only for visible on-page
     *                                       copy (the chart's no-JS fallback); the
     *                                       meta description and JSON-LD Dataset
     *                                       pass null and keep ISO.
     * @param array            $site         Optional site config (country full names).
     * @return string
     */
    public function dataset_description(LDN_Page_Context $ctx, array $summary, $currency = null, $display_date = null, array $site = array()) {
        // Top-level hubs carry market-overview.json (totals / combos), not shape medians.
        if ($ctx->page_level === 'top-level') {
            $hub = $this->top_level_dataset_description($ctx, $summary, $display_date, $site);
            if ($hub !== '') {
                return $hub;
            }
        }

        // All-shapes hubs compare cuts at one weight — lead with that intent + country
        // so they do not compete with type hubs or named-shape pages.
        if ($ctx->page_level === 'all-shapes') {
            $all = $this->all_shapes_dataset_description($ctx, $summary, $currency, $display_date, $site);
            if ($all !== '') {
                return $all;
            }
        }

        $subject = $this->plain_subject($ctx);
        if ($subject === 'diamond') {
            $base = 'Market pricing data for diamonds.';
        } else {
            $base = sprintf('Market pricing data for %s diamonds.', $subject);
        }

        // Resolution order must match intro_html() and hero_stats_html(), including
        // the distribution.percentiles.p50 step. Without p50 here, a summary that
        // carries percentiles but no explicit median_price would make this
        // description skip to time_series.current_price (a trimmed mean) while the
        // visible intro and hero cards quote p50 — so the meta description, the
        // JSON-LD Dataset and the chart's no-JS fallback would contradict the page.
        $median = $this->dig_first($summary, array(
            array('distribution', 'median_price'),
            array('median_price'),
            array('distribution', 'percentiles', 'p50'),
        ));
        if ($median === null) {
            $median = $this->dig_first($summary, array(
                array('time_series', 'current_price'), array('current_price'),
            ));
        }
        $sample = $this->dig_first($summary, array(
            array('distribution', 'sample_size'), array('num_diamonds'), array('sample_size'),
        ));

        if (!is_numeric($median)) {
            return $base;
        }

        $symbol = $this->currency_symbol($currency);
        $detail = sprintf('Median price %s%s', $symbol, number_format((float) $median, 0));
        if (is_numeric($sample) && (int) $sample > 0) {
            $detail .= sprintf(' from %s matching diamonds', number_format((int) $sample));
        }
        $date = $this->dataset_date($summary);
        if ($date !== '') {
            if (is_string($display_date) && $display_date !== '') {
                $date = $display_date;
            }
            $detail .= sprintf(' as of %s', $date);
        }
        return $base . ' ' . $detail . '.';
    }

    /**
     * Meta / Dataset description for the top-level market hub.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $overview market-overview payload (or empty)
     * @param string|null      $display_date
     * @return string Empty when overview lacks usable scale fields.
     */
    private function top_level_dataset_description(LDN_Page_Context $ctx, array $overview, $display_date = null, array $site = array()) {
        $total = isset($overview['total_diamonds_tracked']) ? (int) $overview['total_diamonds_tracked'] : 0;
        $natural = isset($overview['natural']) && is_array($overview['natural']) ? $overview['natural'] : array();
        $lab = isset($overview['lab_grown']) && is_array($overview['lab_grown']) ? $overview['lab_grown'] : array();
        $nat_combos = isset($natural['combo_count']) ? (int) $natural['combo_count'] : 0;
        $lab_combos = isset($lab['combo_count']) ? (int) $lab['combo_count'] : 0;
        if ($total <= 0 && $nat_combos <= 0 && $lab_combos <= 0) {
            return '';
        }

        $country = $this->country_full_name($ctx, $site);
        $parts = array();
        if ($total > 0) {
            $parts[] = sprintf(
                'Independent %s diamond price index covering %s natural and lab-grown diamonds',
                $country,
                number_format($total)
            );
        } else {
            $parts[] = sprintf('Independent %s diamond price index', $country);
        }
        if ($nat_combos > 0 || $lab_combos > 0) {
            $parts[] = sprintf(
                'across %d natural and %d lab-grown shape and carat combinations',
                $nat_combos,
                $lab_combos
            );
        }
        $desc = implode(' ', $parts)
            . '. Daily median prices by carat - compare natural vs lab-grown and drill into any weight';
        $date = $this->dataset_date($overview);
        if ($date !== '') {
            if (is_string($display_date) && $display_date !== '') {
                $date = $display_date;
            }
            $desc .= sprintf('. Updated %s.', $date);
        } else {
            $desc .= '.';
        }
        return $desc;
    }

    /**
     * Meta / Dataset description for the all-shapes (carat) hub.
     *
     * Owns "by shape" + country so this level does not compete with the type hub
     * (bare "{type} diamond prices") or a shape page (named cut).
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @param string|null      $currency
     * @param string|null      $display_date
     * @param array            $site
     * @return string Empty when carat/type context is missing.
     */
    private function all_shapes_dataset_description(
        LDN_Page_Context $ctx,
        array $summary,
        $currency = null,
        $display_date = null,
        array $site = array()
    ) {
        if ($ctx->carat === null || $ctx->diamond_type === null) {
            return '';
        }

        $carat = $this->format_carat_label($ctx->carat);
        $type = $this->type_phrase($ctx->diamond_type);
        $country = $this->country_full_name($ctx, $site);
        $desc = sprintf(
            'Compare %s carat %s diamond prices by shape in the %s',
            $carat,
            $type,
            $country
        );

        $median = $this->dig_first($summary, array(
            array('aggregate', 'weighted_median_price'),
            array('distribution', 'median_price'),
            array('median_price'),
            array('current_price'),
            array('distribution', 'percentiles', 'p50'),
        ));
        $sample = $this->dig_first($summary, array(
            array('num_diamonds'),
            array('distribution', 'sample_size'),
            array('sample_size'),
        ));

        if (is_numeric($median)) {
            $symbol = $this->currency_symbol($currency);
            $desc .= sprintf('. Weighted median %s%s', $symbol, number_format((float) $median, 0));
            if (is_numeric($sample) && (int) $sample > 0) {
                $desc .= sprintf(' across %s stones', number_format((int) $sample));
            }
        }

        $date = $this->dataset_date($summary);
        if ($date !== '') {
            if (is_string($display_date) && $display_date !== '') {
                $date = $display_date;
            }
            $desc .= sprintf(' as of %s', $date);
        }
        return $desc . '.';
    }

    /**
     * SEO keywords from the page context.
     *
     * Phrases are level-scoped so adjacent hierarchy pages do not share the same
     * primary keyword set (type ↔ all-shapes ↔ shape).
     *
     * @param LDN_Page_Context $ctx
     * @return string[]
     */
    private function keywords(LDN_Page_Context $ctx) {
        if ($ctx->page_level === 'top-level') {
            return array_values(array_unique(array_filter(array(
                'diamond prices',
                'natural diamond prices',
                'lab-grown diamond prices',
                'diamond price chart',
                'diamond prices by carat',
            ))));
        }

        $carat = $ctx->carat !== null ? $this->format_carat_label($ctx->carat) : '';
        $type = $ctx->diamond_type !== null ? $this->type_phrase($ctx->diamond_type) : '';
        $shape = $ctx->shape !== null
            ? strtolower(str_replace('-', ' ', $ctx->shape))
            : '';

        if ($ctx->page_level === 'all-shapes') {
            $words = array();
            if ($carat !== '' && $type !== '') {
                $words[] = $carat . ' carat ' . $type . ' diamond prices by shape';
                $words[] = $type . ' diamond shape comparison';
                $words[] = $carat . ' carat diamond shapes price';
            }
            return array_values(array_unique(array_filter($words)));
        }

        if ($ctx->page_level === 'diamond-type') {
            $words = array();
            if ($type !== '') {
                $words[] = $type . ' diamond prices';
                $words[] = $type . ' diamond prices by carat';
            }
            $words[] = 'diamond prices by carat';
            return array_values(array_unique(array_filter($words)));
        }

        // Shape / individual pages.
        $words = array();
        if ($carat !== '' && $shape !== '' && $type !== '') {
            $words[] = $carat . ' carat ' . $shape . ' ' . $type . ' diamond';
            $words[] = $carat . ' carat ' . $shape . ' diamond prices';
        } elseif ($carat !== '') {
            $words[] = $carat . ' carat diamond';
        }
        if ($shape !== '') {
            $words[] = $shape . ' diamond';
        }
        $words[] = 'diamond prices';
        return array_values(array_unique(array_filter($words)));
    }

    /**
     * Lowercase type phrase for meta/keywords ("lab-grown", "natural").
     *
     * @param string|null $diamond_type
     * @return string
     */
    private function type_phrase($diamond_type) {
        if ($diamond_type === null || $diamond_type === '') {
            return '';
        }
        $normalised = strtolower(str_replace('_', '-', (string) $diamond_type));
        if ($normalised === 'lab') {
            return 'lab-grown';
        }
        return $normalised;
    }

    /**
     * First ISO date string (YYYY-MM-DD) found in the summary payload, or ''.
     *
     * @param array $summary
     * @return string
     */
    public function analysis_date(array $summary) {
        return $this->dataset_date($summary);
    }

    /**
     * First ISO date string found in the summary payload, or ''.
     *
     * @param array $summary
     * @return string
     */
    private function dataset_date(array $summary) {
        $value = $this->dig_first($summary, self::DATE_PATHS);
        if (!is_string($value) || $value === '') {
            return '';
        }
        // Normalise to YYYY-MM-DD when an ISO timestamp is supplied.
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * {'@id': website} reference for the site's WebSite node (theme-emitted), or null.
     *
     * @param array $site
     * @return array<string, string>|null
     */
    private function website_ref(array $site) {
        $domain = isset($site['domain']) ? trim((string) $site['domain']) : '';
        $base = $domain !== '' ? 'https://' . $domain : $this->home();
        if ($base === '') {
            return null;
        }
        return array('@id' => rtrim($base, '/') . '/#website');
    }

    /**
     * Human-readable H1, e.g. "1 Carat Round Natural Diamond Prices (US)".
     * Mirrors LDN_Renderer::headline().
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function headline(LDN_Page_Context $ctx, array $site = array()) {
        $labels = array('natural' => 'Natural', 'lab-grown' => 'Lab-Grown');
        $parts = array();
        if ($ctx->carat !== null) {
            $parts[] = $this->format_carat_label($ctx->carat) . ' Carat';
        }
        if ($ctx->shape !== null) {
            $parts[] = ucwords(str_replace('-', ' ', $ctx->shape));
        }
        if ($ctx->diamond_type !== null) {
            $parts[] = isset($labels[$ctx->diamond_type])
                ? $labels[$ctx->diamond_type]
                : ucwords(str_replace('-', ' ', $ctx->diamond_type));
        }
        $subject = trim(implode(' ', $parts));
        if ($subject === '') {
            if ($ctx->site_id === 'ringspo' && $ctx->page_level === 'top-level') {
                // Match LDN_Renderer::headline() — full country name, no (US) suffix.
                return sprintf('%s Diamond Prices', $this->country_full_name($ctx, $site));
            }
            return sprintf('Diamond Prices (%s)', strtoupper($ctx->country_code));
        }
        if ($ctx->site_id === 'ringspo' && $ctx->page_level === 'diamond-type') {
            return sprintf('%s %s Diamond Prices', $this->country_full_name($ctx, $site), $subject);
        }
        if ($ctx->site_id === 'ringspo' && $ctx->page_level === 'all-shapes') {
            return sprintf(
                '%s Diamond Prices by Shape - %s',
                $subject,
                $this->country_full_name($ctx, $site)
            );
        }
        return sprintf('%s Diamond Prices (%s)', $subject, strtoupper($ctx->country_code));
    }

    /**
     * Plain subject phrase, e.g. "1 carat round natural".
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    private function plain_subject(LDN_Page_Context $ctx) {
        $parts = array_filter(array(
            $ctx->carat !== null ? $this->format_carat_label($ctx->carat) . ' carat' : null,
            $ctx->shape !== null ? strtolower(str_replace('-', ' ', $ctx->shape)) : null,
            $ctx->diamond_type,
        ), 'strlen');
        return $parts ? implode(' ', $parts) : 'diamond';
    }

    /**
     * Human country name from site config countries, else uppercase code.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $site
     * @return string
     */
    private function country_full_name(LDN_Page_Context $ctx, array $site) {
        if (!empty($site['countries']) && is_array($site['countries'])) {
            foreach ($site['countries'] as $entry) {
                if (is_array($entry) && isset($entry['code']) && $entry['code'] === $ctx->country_code) {
                    if (isset($entry['full_name'])) {
                        return (string) $entry['full_name'];
                    }
                }
            }
        }
        // Fallback when site config was not passed (meta description path).
        $fallback = array(
            'us' => 'United States',
            'uk' => 'United Kingdom',
            'au' => 'Australia',
            'ca' => 'Canada',
            'nz' => 'New Zealand',
            'ie' => 'Ireland',
            'fr' => 'France',
            'de' => 'Germany',
            'it' => 'Italy',
            'es' => 'Spain',
            'nl' => 'Netherlands',
            'se' => 'Sweden',
            'no' => 'Norway',
            'dk' => 'Denmark',
            'fi' => 'Finland',
            'in' => 'India',
            'ae' => 'United Arab Emirates',
            'sg' => 'Singapore',
            'hk' => 'Hong Kong',
            'za' => 'South Africa',
        );
        $code = strtolower($ctx->country_code);
        if (isset($fallback[$code])) {
            return $fallback[$code];
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
     * Site home URL (best-effort; '' when WP unavailable, e.g. unit tests).
     *
     * @return string
     */
    private function home() {
        return function_exists('home_url') ? (string) home_url('/') : '';
    }

    /**
     * First non-null value among candidate paths into $arr.
     *
     * @param array $arr
     * @param array $paths
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
}

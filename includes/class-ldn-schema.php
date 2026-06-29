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
            array('label' => 'Diamonds analysed', 'format' => 'integer',
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
            'name'               => $this->headline($ctx) . $suffix,
            'description'        => $this->dataset_description($ctx, $summary, $currency),
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
     * @return string
     */
    public function dataset_description(LDN_Page_Context $ctx, array $summary, $currency = null) {
        $subject = $this->plain_subject($ctx);
        if ($subject === 'diamond') {
            $base = 'Market pricing data for diamonds.';
        } else {
            $base = sprintf('Market pricing data for %s diamonds.', $subject);
        }

        $median = $this->dig_first($summary, array(
            array('distribution', 'median_price'), array('median_price'),
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
            $detail .= sprintf(' as of %s', $date);
        }
        return $base . ' ' . $detail . '.';
    }

    /**
     * SEO keywords from the page context.
     *
     * @param LDN_Page_Context $ctx
     * @return string[]
     */
    private function keywords(LDN_Page_Context $ctx) {
        $words = array();
        if ($ctx->carat !== null) {
            $words[] = $this->format_carat_label($ctx->carat) . ' carat diamond';
        }
        if ($ctx->shape !== null) {
            $words[] = strtolower(str_replace('-', ' ', $ctx->shape)) . ' diamond';
        }
        $words[] = 'diamond prices';
        $words[] = 'diamond price chart';
        return array_values(array_unique(array_filter($words)));
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
    public function headline(LDN_Page_Context $ctx) {
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
            return sprintf('Diamond Prices (%s)', strtoupper($ctx->country_code));
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

<?php
/**
 * Loose diamond shopping card page renderer (PRD-025 CP 166).
 *
 * Renders `/{country}/diamonds/...` shopping pages strictly from cached S3
 * `diamond-cards.json` (`0` live HTTP calls to SparkleScore or Facet Intelligence,
 * `0` display or LDO banner ad slots, and `0` hardcoded site IDs or retailer codes).
 *
 * Story_CP166_01 scope:
 *   - Self-referencing canonical URL, OpenGraph metadata and CloudFront preconnect
 *   - Breadcrumb navigation (`Home` -> `Diamonds` -> `{Origin}` -> `{Slice}`)
 *   - Answer-first hero band with live matching stone count, retailer count,
 *     typical 4C price range (`p25 - p75`) and median price
 *   - `CollectionPage` + `ItemList` + `BreadcrumbList` Schema.org JSON-LD
 *     (`Product` + `Offer` with `priceCurrency` and `InStock` availability)
 *   - Initial server-rendered stone card grid (`Story_CP166_02` expands curated
 *     doors, SparkleScore pills, interactive filters and mobile slide-over sheet)
 *
 * @package LoupeDiamondNetwork
 * @since   0.45.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Diamonds_Renderer {

    const JSON_SCRIPT_FLAGS = JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    const SHAPE_LABELS = array(
        'round'    => 'Round',
        'oval'     => 'Oval',
        'cushion'  => 'Cushion Cut',
        'emerald'  => 'Emerald Cut',
        'princess' => 'Princess Cut',
        'pear'     => 'Pear',
        'radiant'  => 'Radiant Cut',
        'marquise' => 'Marquise',
        'asscher'  => 'Asscher Cut',
        'heart'    => 'Heart',
    );

    const MODIFIER_LABELS = array(
        'elongated-cushion' => 'Elongated Cushion Cut',
        'elongated-radiant' => 'Elongated Radiant Cut',
        'eye-clean'         => 'Eye-Clean',
        'colorless'         => 'Colorless (D-F)',
        'near-colorless'    => 'Near-Colorless (G-J)',
        'gia-certified'     => 'GIA Certified',
        'igi-certified'     => 'IGI Certified',
    );

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
     * Build public canonical URL for any `/diamonds/` route on `$site_id`.
     *
     * @param string $site_id
     * @param string $country_code
     * @param string $diamond_type 'all' | 'natural' | 'lab-grown'
     * @param string $diamond_path 'hub', '{shape}', '{carat}', '{carat}/{shape}', '{modifier}', '{carat}/{modifier}'
     * @return string
     */
    public function build_diamonds_url($site_id, $country_code, $diamond_type = 'all', $diamond_path = 'hub') {
        $structure = method_exists($this->config, 'url_structure_for')
            ? $this->config->url_structure_for($site_id, $country_code)
            : $this->config->get_url_structure($site_id);
        if (!is_array($structure) || empty($structure['diamonds_level_1'])) {
            return '';
        }

        $country = strtolower((string) $country_code);
        $path = trim((string) $diamond_path, '/');

        if ($diamond_type === 'all' && ($path === '' || $path === 'hub')) {
            $rel = strtr((string) $structure['diamonds_level_1'], array(
                '{country}' => $country,
            ));
        } else {
            $type_slug = $diamond_type === 'lab-grown'
                ? (string) ($structure['type_lab'] ?? 'lab-grown')
                : (string) ($structure['type_natural'] ?? 'natural');
            $base = isset($structure['diamonds_level_2']) ? (string) $structure['diamonds_level_2'] : '';
            if ($base === '') {
                return '';
            }
            $rel = strtr($base, array(
                '{country}' => $country,
                '{type}'    => $type_slug,
            ));
            if ($path !== '' && $path !== 'hub') {
                $rel = rtrim($rel, '/') . '/' . $path;
            }
        }

        if (method_exists($this->config, 'path_relative_to_mount')) {
            $rel = $this->config->path_relative_to_mount($rel);
        }
        $rel = '/' . trim($rel, '/') . '/';
        if (function_exists('home_url')) {
            return home_url($rel);
        }
        return $rel;
    }

    /**
     * Human-readable `<title>` and `<h1>` for a `/diamonds/` page.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $payload
     * @return string
     */
    public function page_title(LDN_Page_Context $ctx, array $payload = array()) {
        $meta = isset($payload['route_meta']) && is_array($payload['route_meta'])
            ? $payload['route_meta']
            : array();

        $type = $ctx->diamond_type;
        if ($type === 'all' && ($ctx->diamond_path === 'hub' || $ctx->diamond_path === null)) {
            return 'Loose Diamonds Scored for Cut, Size and Value';
        }

        $origin = $type === 'lab-grown' ? 'Lab-Grown' : 'Natural';
        $carat = isset($meta['carat_label']) && $meta['carat_label'] !== null && $meta['carat_label'] !== ''
            ? (string) $meta['carat_label']
            : ($ctx->carat !== null ? (string) $ctx->carat : '');
        $modifier = isset($meta['modifier_slug']) && is_string($meta['modifier_slug'])
            ? $meta['modifier_slug']
            : $this->modifier_from_path($ctx->diamond_path);
        $shape = isset($meta['shape']) && is_string($meta['shape'])
            ? $meta['shape']
            : ($ctx->shape !== null ? $ctx->shape : '');

        $parts = array();
        if ($carat !== '') {
            $parts[] = $carat . ' Carat';
        }
        if ($modifier !== null && isset(self::MODIFIER_LABELS[$modifier])) {
            $parts[] = self::MODIFIER_LABELS[$modifier];
        } elseif ($shape !== '' && isset(self::SHAPE_LABELS[$shape])) {
            $parts[] = self::SHAPE_LABELS[$shape];
        }
        $parts[] = $origin;
        $parts[] = 'Diamonds';

        return implode(' ', $parts);
    }

    /**
     * Answer-first SEO/AEO meta description with live counts and 4C price band.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $payload
     * @return string
     */
    public function meta_description(LDN_Page_Context $ctx, array $payload = array()) {
        $meta = isset($payload['route_meta']) && is_array($payload['route_meta'])
            ? $payload['route_meta']
            : array();
        $title = $this->page_title($ctx, $payload);
        $currency = $this->currency_code($ctx);
        $stones = isset($meta['total_matching_stones']) ? (int) $meta['total_matching_stones'] : 0;
        $retailers = isset($meta['retailer_count']) ? (int) $meta['retailer_count'] : 0;
        $p25 = isset($meta['price_p25_usd']) && $meta['price_p25_usd'] !== null ? (float) $meta['price_p25_usd'] : null;
        $p75 = isset($meta['price_p75_usd']) && $meta['price_p75_usd'] !== null ? (float) $meta['price_p75_usd'] : null;

        if ($stones > 0 && $retailers > 0 && $p25 !== null && $p75 !== null) {
            return sprintf(
                'Compare %s in-stock %s across %d specialist jewelers, typically priced from %s to %s and scored on Sparkle, Face-Up Size and 4C Value.',
                number_format($stones),
                strtolower($title),
                $retailers,
                $this->money($p25, $currency),
                $this->money($p75, $currency)
            );
        }

        return sprintf(
            'Compare certified %s scored on Sparkle, Face-Up Size and 4C Value across specialist jewelers.',
            strtolower($title)
        );
    }

    /**
     * Render `<head>` tags: CloudFront preconnect, meta description, canonical,
     * OpenGraph and `CollectionPage` + `ItemList` + `BreadcrumbList` JSON-LD.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $payload
     * @return string
     */
    public function render_head_content(LDN_Page_Context $ctx, array $payload) {
        if (!apply_filters('ldn_emit_head_tags', true, $ctx)) {
            return '';
        }

        $canonical = $this->build_diamonds_url(
            $ctx->site_id,
            $ctx->country_code,
            (string) $ctx->diamond_type,
            (string) $ctx->diamond_path
        );
        $title = $this->page_title($ctx, $payload);
        $desc = $this->meta_description($ctx, $payload);
        $brand = $this->config->site_brand_name($ctx->site_id);

        $out = '';
        $img_origin = method_exists($this->config, 'diamonds_image_base_url')
            ? $this->config->diamonds_image_base_url($ctx->site_id)
            : '';
        if ($img_origin !== '') {
            $out .= '<link rel="preconnect" href="' . esc_url($img_origin) . '" crossorigin />' . "\n";
        }

        if (!LDN_Seo_Bridge::unbridged_plugin_active() && $desc !== '') {
            $out .= '<meta name="description" content="' . esc_attr($desc) . '" />' . "\n";
        }
        if ($canonical !== '') {
            $out .= '<link rel="canonical" href="' . esc_url($canonical) . '" />' . "\n";
            $out .= '<meta property="og:url" content="' . esc_url($canonical) . '" />' . "\n";
        }
        $out .= '<meta property="og:type" content="website" />' . "\n";
        $out .= '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
        if ($desc !== '') {
            $out .= '<meta property="og:description" content="' . esc_attr($desc) . '" />' . "\n";
        }
        if ($brand !== '') {
            $out .= '<meta property="og:site_name" content="' . esc_attr($brand) . '" />' . "\n";
        }

        $first_img = $this->first_card_image($payload);
        if ($first_img !== '') {
            $out .= '<meta property="og:image" content="' . esc_url($first_img) . '" />' . "\n";
            $out .= '<meta property="og:image:alt" content="' . esc_attr($title) . '" />' . "\n";
        }

        $schema = $this->schema_graph($ctx, $payload, $canonical, $title, $desc);
        if ($schema !== array()) {
            $out .= '<script type="application/ld+json">'
                . wp_json_encode($schema, self::JSON_SCRIPT_FLAGS)
                . "</script>\n";
        }

        return $out;
    }

    /**
     * Render server-side HTML for a `/diamonds/` page (`0` banner ad slots).
     *
     * @param LDN_Page_Context $ctx
     * @param array            $payload
     * @return string
     */
    public function render(LDN_Page_Context $ctx, array $payload) {
        $profile = $this->config->get_content_profile($ctx->site_id);
        $profile = is_array($profile) ? $profile : array();
        $price_renderer = $this->price_renderer();
        $chrome_cls = $price_renderer->chrome_heading_class($profile);

        $meta = isset($payload['route_meta']) && is_array($payload['route_meta'])
            ? $payload['route_meta']
            : array();
        $cards = isset($payload['explorer_cards']) && is_array($payload['explorer_cards'])
            ? $payload['explorer_cards']
            : array();

        $title = $this->page_title($ctx, $payload);
        $currency = $this->currency_code($ctx);
        $stones = isset($meta['total_matching_stones']) ? (int) $meta['total_matching_stones'] : count($cards);
        $retailers = isset($meta['retailer_count']) ? (int) $meta['retailer_count'] : 0;
        $p25 = isset($meta['price_p25_usd']) && $meta['price_p25_usd'] !== null ? (float) $meta['price_p25_usd'] : null;
        $median = isset($meta['price_median_usd']) && $meta['price_median_usd'] !== null ? (float) $meta['price_median_usd'] : null;
        $p75 = isset($meta['price_p75_usd']) && $meta['price_p75_usd'] !== null ? (float) $meta['price_p75_usd'] : null;
        $analysis_date = isset($meta['analysis_date']) && is_string($meta['analysis_date'])
            ? $meta['analysis_date']
            : '';

        $out = '<div class="ldn-page-shell">';
        $out .= '<main class="ldn-price-page ldn-diamonds-page ' . esc_attr($chrome_cls) . '">';
        $out .= $price_renderer->theme_style_block($profile);

        // Hero band: breadcrumbs, answer-first H1 and live benchmark summary strip
        $out .= '<header class="ldn-hero-band ldn-diamonds-hero">';
        $out .= $this->breadcrumbs_html($ctx, $payload);
        $out .= '<h1 class="ldn-page-title">' . esc_html($title) . '</h1>';
        $out .= '<p class="ldn-diamonds-lede">' . esc_html($this->meta_description($ctx, $payload)) . '</p>';

        $out .= '<div class="ldn-hero-stats" aria-label="Live inventory summary">';
        $out .= '<div class="ldn-hero-stat">'
            . '<span class="ldn-stat-label">In-Stock Stones</span>'
            . '<span class="ldn-stat-value">' . esc_html(number_format($stones)) . '</span>'
            . '</div>';
        $out .= '<div class="ldn-hero-stat">'
            . '<span class="ldn-stat-label">Specialist Jewelers</span>'
            . '<span class="ldn-stat-value">' . esc_html((string) $retailers) . '</span>'
            . '</div>';
        if ($p25 !== null && $p75 !== null) {
            $out .= '<div class="ldn-hero-stat">'
                . '<span class="ldn-stat-label">Typical 4C Range</span>'
                . '<span class="ldn-stat-value">'
                . esc_html($this->money($p25, $currency) . ' - ' . $this->money($p75, $currency))
                . '</span></div>';
        }
        if ($median !== null) {
            $out .= '<div class="ldn-hero-stat">'
                . '<span class="ldn-stat-label">Median Price</span>'
                . '<span class="ldn-stat-value">' . esc_html($this->money($median, $currency)) . '</span>'
                . '</div>';
        }
        $out .= '</div>';
        if ($analysis_date !== '') {
            $out .= '<p class="ldn-diamonds-lede" style="margin-top:0.85rem;margin-bottom:0;font-size:0.82rem">'
                . esc_html('Inventory and SparkleScore checks updated ' . $analysis_date . '.')
                . '</p>';
        }
        $out .= '</header>';

        // Initial SSR stone card list
        $out .= '<section class="ldn-section ldn-section--plain ldn-diamonds-section" aria-labelledby="ldn-diamonds-list-heading">';
        $out .= '<h2 id="ldn-diamonds-list-heading">Curated In-Stock Stones</h2>';
        $out .= '<div class="ldn-diamonds-grid">';
        foreach ($cards as $idx => $card) {
            if (!is_array($card)) {
                continue;
            }
            $out .= $this->render_card_summary($card, $currency, $idx < 3);
        }
        $out .= '</div>';
        $out .= '</section>';

        $out .= '</main></div>';
        return $out;
    }

    /**
     * Render one server-side stone card summary (expanded with SparkleScore pills
     * and filter attributes in Story_CP166_02).
     *
     * @param array  $card
     * @param string $currency
     * @param bool   $eager_image
     * @return string
     */
    private function render_card_summary(array $card, $currency, $eager_image) {
        $retailer = isset($card['retailer_name']) ? (string) $card['retailer_name'] : '';
        $price = isset($card['price_usd']) && $card['price_usd'] !== null ? (float) $card['price_usd'] : 0.0;
        $carat = isset($card['carat']) && $card['carat'] !== null ? number_format((float) $card['carat'], 2) : '';
        $shape_key = isset($card['shape']) ? strtolower((string) $card['shape']) : '';
        $shape_label = isset(self::SHAPE_LABELS[$shape_key]) ? self::SHAPE_LABELS[$shape_key] : ucfirst($shape_key);
        $color = isset($card['color']) ? (string) $card['color'] : '';
        $clarity = isset($card['clarity']) ? (string) $card['clarity'] : '';
        $cut = isset($card['cut_grade']) ? (string) $card['cut_grade'] : '';
        $cert = isset($card['cert_lab']) ? (string) $card['cert_lab'] : '';
        $measurements = isset($card['measurements_label']) && is_string($card['measurements_label'])
            ? $card['measurements_label']
            : '';
        $img = isset($card['image_url']) && is_string($card['image_url']) ? $card['image_url'] : '';
        $aff = isset($card['affiliate_url']) && is_string($card['affiliate_url']) ? $card['affiliate_url'] : '';

        $card_title = trim(($carat !== '' ? $carat . 'ct ' : '') . $shape_label . ' Diamond');
        $spec_tokens = array_values(array_filter(array($color, $clarity, $cut, $cert), 'strlen'));
        $spec_line = implode(' · ', $spec_tokens);
        if ($measurements !== '') {
            $spec_line .= ($spec_line !== '' ? ' · ' : '') . $measurements;
        }

        $out = '<article class="ldn-stone-card">';
        $out .= '<div class="ldn-stone-card__media">';
        if ($img !== '') {
            $loading = $eager_image ? 'eager" fetchpriority="high' : 'lazy';
            $out .= '<img src="' . esc_url($img) . '" alt="' . esc_attr($card_title)
                . '" width="600" height="600" decoding="async" loading="' . $loading . '" />';
        } else {
            $out .= '<span class="ldn-stone-card__retailer">' . esc_html($shape_label) . '</span>';
        }
        $out .= '</div>';

        $out .= '<div class="ldn-stone-card__body">';
        $out .= '<div class="ldn-stone-card__top">'
            . '<span class="ldn-stone-card__retailer">' . esc_html($retailer) . '</span>'
            . '<span class="ldn-stone-card__price">' . esc_html($this->money($price, $currency)) . '</span>'
            . '</div>';
        $out .= '<h3 class="ldn-stone-card__title">' . esc_html($card_title) . '</h3>';
        if ($spec_line !== '') {
            $out .= '<div class="ldn-stone-card__specs">' . esc_html($spec_line) . '</div>';
        }
        if ($aff !== '') {
            $out .= '<div class="ldn-stone-card__cta">'
                . '<a class="ldn-stone-card__btn" href="' . esc_url($aff)
                . '" target="_blank" rel="sponsored noopener">'
                . esc_html('View at ' . ($retailer !== '' ? $retailer : 'Retailer'))
                . '</a></div>';
        }
        $out .= '</div>';
        $out .= '</article>';
        return $out;
    }

    /**
     * Build `CollectionPage` + `ItemList` + `BreadcrumbList` Schema.org `@graph`.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $payload
     * @param string           $canonical
     * @param string           $title
     * @param string           $desc
     * @return array
     */
    private function schema_graph(LDN_Page_Context $ctx, array $payload, $canonical, $title, $desc) {
        if ($canonical === '') {
            return array();
        }
        $currency = $this->currency_code($ctx);
        $crumbs = $this->breadcrumb_items($ctx, $payload);
        $crumb_elements = array();
        foreach ($crumbs as $i => $crumb) {
            $crumb_elements[] = array(
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $crumb['label'],
                'item'     => $crumb['url'],
            );
        }

        $cards = isset($payload['explorer_cards']) && is_array($payload['explorer_cards'])
            ? $payload['explorer_cards']
            : array();
        $list_elements = array();
        foreach (array_slice($cards, 0, 24) as $idx => $card) {
            if (!is_array($card)) {
                continue;
            }
            $price = isset($card['price_usd']) && $card['price_usd'] !== null ? (float) $card['price_usd'] : null;
            $aff = isset($card['affiliate_url']) && is_string($card['affiliate_url']) ? $card['affiliate_url'] : '';
            if ($price === null || $price <= 0 || $aff === '') {
                continue;
            }
            $carat = isset($card['carat']) && $card['carat'] !== null ? number_format((float) $card['carat'], 2) : '';
            $shape_key = isset($card['shape']) ? strtolower((string) $card['shape']) : '';
            $shape_label = isset(self::SHAPE_LABELS[$shape_key]) ? self::SHAPE_LABELS[$shape_key] : ucfirst($shape_key);
            $color = isset($card['color']) ? (string) $card['color'] : '';
            $clarity = isset($card['clarity']) ? (string) $card['clarity'] : '';
            $cert = isset($card['cert_lab']) ? (string) $card['cert_lab'] : '';
            $retailer = isset($card['retailer_name']) ? (string) $card['retailer_name'] : '';

            $name_parts = array_values(array_filter(array(
                $carat !== '' ? $carat . ' Carat' : '',
                $shape_label,
                $color,
                $clarity,
                $cert !== '' ? $cert . ' Diamond' : 'Diamond',
            ), 'strlen'));

            $product = array(
                '@type'  => 'Product',
                'name'   => implode(' ', $name_parts),
                'sku'    => isset($card['stone_key']) ? (string) $card['stone_key'] : '',
                'offers' => array(
                    '@type'         => 'Offer',
                    'url'           => $aff,
                    'price'         => round($price, 2),
                    'priceCurrency' => $currency,
                    'availability'  => 'https://schema.org/InStock',
                    'seller'        => array(
                        '@type' => 'Organization',
                        'name'  => $retailer !== '' ? $retailer : 'Retailer',
                    ),
                ),
            );
            if (!empty($card['image_url']) && is_string($card['image_url'])) {
                $product['image'] = $card['image_url'];
            }

            $list_elements[] = array(
                '@type'    => 'ListItem',
                'position' => count($list_elements) + 1,
                'item'     => $product,
            );
        }

        $breadcrumb_id = $canonical . '#breadcrumb';
        $itemlist_id = $canonical . '#itemlist';

        $collection_page = array(
            '@type'       => 'CollectionPage',
            '@id'         => $canonical . '#webpage',
            'url'         => $canonical,
            'name'        => $title,
            'description' => $desc,
            'breadcrumb'  => array('@id' => $breadcrumb_id),
            'mainEntity'  => array('@id' => $itemlist_id),
        );

        $item_list = array(
            '@type'           => 'ItemList',
            '@id'             => $itemlist_id,
            'name'            => $title,
            'numberOfItems'   => count($cards),
            'itemListElement' => $list_elements,
        );

        $breadcrumb_node = array(
            '@type'           => 'BreadcrumbList',
            '@id'             => $breadcrumb_id,
            'itemListElement' => $crumb_elements,
        );

        return array(
            '@context' => 'https://schema.org',
            '@graph'   => array(
                $collection_page,
                $breadcrumb_node,
                $item_list,
            ),
        );
    }

    /**
     * Render visible `<nav class="ldn-breadcrumbs">`.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $payload
     * @return string
     */
    private function breadcrumbs_html(LDN_Page_Context $ctx, array $payload) {
        $items = $this->breadcrumb_items($ctx, $payload);
        if ($items === array()) {
            return '';
        }
        $last = count($items) - 1;
        $out = '<nav class="ldn-breadcrumbs" aria-label="Breadcrumb">';
        foreach ($items as $i => $item) {
            if ($i > 0) {
                $out .= '<span class="ldn-breadcrumbs__sep" aria-hidden="true">/</span>';
            }
            if ($i < $last && $item['url'] !== '') {
                $out .= '<a href="' . esc_url($item['url']) . '">' . esc_html($item['label']) . '</a>';
            } else {
                $out .= '<span aria-current="page">' . esc_html($item['label']) . '</span>';
            }
        }
        $out .= '</nav>';
        return $out;
    }

    /**
     * Ordered breadcrumb items `[{label, url}]`.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $payload
     * @return array<int, array{label:string,url:string}>
     */
    private function breadcrumb_items(LDN_Page_Context $ctx, array $payload) {
        $home = function_exists('home_url') ? home_url('/') : '/';
        $brand = $this->config->site_brand_name($ctx->site_id);
        $items = array(
            array(
                'label' => $brand !== '' ? $brand : 'Home',
                'url'   => $home,
            ),
        );

        $root_url = $this->build_diamonds_url($ctx->site_id, $ctx->country_code, 'all', 'hub');
        $items[] = array(
            'label' => 'Diamonds',
            'url'   => $root_url,
        );

        if ($ctx->diamond_type === 'all' && ($ctx->diamond_path === 'hub' || $ctx->diamond_path === null)) {
            return $items;
        }

        $origin_label = $ctx->diamond_type === 'lab-grown' ? 'Lab-Grown' : 'Natural';
        $type_url = $this->build_diamonds_url($ctx->site_id, $ctx->country_code, (string) $ctx->diamond_type, 'hub');
        $items[] = array(
            'label' => $origin_label,
            'url'   => $type_url,
        );

        $path = trim((string) $ctx->diamond_path, '/');
        if ($path === '' || $path === 'hub') {
            return $items;
        }

        $parts = explode('/', $path);
        if (count($parts) === 2) {
            $carat_slug = $parts[0];
            $carat_label = $ctx->carat !== null ? $ctx->carat . ' Carat' : ucfirst(str_replace('-', ' ', $carat_slug));
            $items[] = array(
                'label' => $carat_label,
                'url'   => $this->build_diamonds_url($ctx->site_id, $ctx->country_code, (string) $ctx->diamond_type, $carat_slug),
            );
            $leaf_slug = $parts[1];
            $leaf_label = isset(self::MODIFIER_LABELS[$leaf_slug])
                ? self::MODIFIER_LABELS[$leaf_slug]
                : (isset(self::SHAPE_LABELS[$leaf_slug]) ? self::SHAPE_LABELS[$leaf_slug] : ucfirst($leaf_slug));
            $items[] = array(
                'label' => $leaf_label,
                'url'   => $this->build_diamonds_url($ctx->site_id, $ctx->country_code, (string) $ctx->diamond_type, $path),
            );
            return $items;
        }

        $single = $parts[0];
        if (isset(self::MODIFIER_LABELS[$single])) {
            $label = self::MODIFIER_LABELS[$single];
        } elseif (isset(self::SHAPE_LABELS[$single])) {
            $label = self::SHAPE_LABELS[$single];
        } elseif ($ctx->carat !== null) {
            $label = $ctx->carat . ' Carat';
        } else {
            $label = $this->page_title($ctx, $payload);
        }
        $items[] = array(
            'label' => $label,
            'url'   => $this->build_diamonds_url($ctx->site_id, $ctx->country_code, (string) $ctx->diamond_type, $path),
        );
        return $items;
    }

    /**
     * @param array $payload
     * @return string
     */
    private function first_card_image(array $payload) {
        $cards = isset($payload['explorer_cards']) && is_array($payload['explorer_cards'])
            ? $payload['explorer_cards']
            : array();
        foreach ($cards as $card) {
            if (is_array($card) && !empty($card['image_url']) && is_string($card['image_url'])) {
                return $card['image_url'];
            }
        }
        return '';
    }

    /**
     * @param string|null $diamond_path
     * @return string|null
     */
    private function modifier_from_path($diamond_path) {
        if (!is_string($diamond_path) || $diamond_path === '' || $diamond_path === 'hub') {
            return null;
        }
        $parts = explode('/', trim($diamond_path, '/'));
        $last = end($parts);
        return ($last !== false && isset(self::MODIFIER_LABELS[$last])) ? $last : null;
    }

    /**
     * @param LDN_Page_Context $ctx
     * @return string
     */
    private function currency_code(LDN_Page_Context $ctx) {
        $cur = $this->config->get_currency($ctx->site_id, $ctx->country_code);
        return ($cur !== null && $cur !== '') ? strtoupper($cur) : 'USD';
    }

    /**
     * @param float  $amount
     * @param string $currency
     * @return string
     */
    private function money($amount, $currency = 'USD') {
        $symbols = array(
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            'AUD' => 'A$',
            'CAD' => 'C$',
        );
        $sym = isset($symbols[$currency]) ? $symbols[$currency] : ($currency . ' ');
        return $sym . number_format(round((float) $amount));
    }

    /**
     * @return LDN_Renderer
     */
    private function price_renderer() {
        if ($this->price_renderer === null) {
            $this->price_renderer = new LDN_Renderer($this->fetcher, $this->config);
        }
        return $this->price_renderer;
    }
}

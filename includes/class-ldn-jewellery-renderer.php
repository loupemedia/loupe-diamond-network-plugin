<?php
/**
 * Finished jewellery page renderer (PRD-020 CP 135).
 *
 * Driven strictly by profile configuration (`jewellery_treatment` and
 * `jewellery_surface` in `config/content_profiles/*.yaml`), with zero site-id
 * branches and zero retailer literals:
 *   - `quality_led` (Ringspo): interactive carat/price/metal explorer
 *     (`jewellery-explorer.json` + server-rendered SVG dot-spread chart + dual
 *     sliders + mobile bottom filter sheet), 4-column / 2-column phone design
 *     cards (`median_p25_p75` framing, circular metal swatches, affiliate CTA),
 *     lead 3x3 color x clarity band table, secondary metal & setting tables,
 *     natural-vs-lab comparison and methodology.
 *   - `metal_setting_led` (The Diamond Advisors): 1280px wide editorial lookbook
 *     (`mean_min_max` average price + full min-max range, breadcrumb + sibling
 *     Natural | Lab-Grown link switch, horizontal Total Carat Weight bar, sticky
 *     serif metal & setting ledger bar, 2-up featured hero plates + 2-column
 *     192px horizontal dossier rows / 108px 1-column on mobile, rectangular
 *     WG/YG/RG/PT stamps with inline variant switching, 2D round-robin
 *     anti-bunching across jewelers, gated LDO sponsor slot, ascending clickable
 *     Metal & Setting breakdown tables with range sparklines, collapsible Color &
 *     Clarity band table when >= 2 rows qualify, and US-English methodology).
 *
 * @package LoupeDiamondNetwork
 * @since   0.44.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Jewellery_Renderer {

    const JSON_SCRIPT_FLAGS = JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    const METAL_ORDER = array('white_gold', 'yellow_gold', 'rose_gold', 'platinum', 'silver');

    const METAL_LABELS = array(
        'white_gold'  => 'White Gold',
        'yellow_gold' => 'Yellow Gold',
        'rose_gold'   => 'Rose Gold',
        'platinum'    => 'Platinum',
        'silver'      => 'Silver',
    );

    const METAL_CODES = array(
        'white_gold'  => 'WG',
        'yellow_gold' => 'YG',
        'rose_gold'   => 'RG',
        'platinum'    => 'PT',
        'silver'      => 'AG',
    );

    const SETTING_LABELS = array(
        'basket_4_prong'   => '4-Prong Basket',
        'four_prong'       => '4-Prong',
        'martini_3_prong'  => '3-Prong Martini',
        'three_prong'      => '3-Prong',
        'bezel'            => 'Bezel',
        'halo'             => 'Halo',
        'channel'          => 'Channel',
        'pave'             => 'Pave',
        'prong'            => 'Classic Prong',
        'illusion_miracle' => 'Illusion',
        'shared_prong'     => 'Shared Prong',
        'bar'              => 'Bar',
        'east_west'        => 'East West',
    );

    /** URL path segments; keep in sync with ``shared/jewellery/ia.py`` ``METAL_SLUGS`` / ``SETTING_SLUGS``. */
    const METAL_URL_SLUGS = array(
        'yellow_gold' => 'yellow-gold',
        'white_gold'  => 'white-gold',
        'rose_gold'   => 'rose-gold',
        'platinum'    => 'platinum',
        'silver'      => 'silver',
        'vermeil'     => 'vermeil',
    );

    const SETTING_URL_SLUGS = array(
        'prong'            => 'prong',
        'martini_3_prong'  => 'martini-3-prong',
        'basket_4_prong'   => 'basket-4-prong',
        'bezel'            => 'bezel',
        'halo'             => 'halo',
        'pave'             => 'pave',
        'channel'          => 'channel',
        'cluster'          => 'cluster',
        'illusion_miracle' => 'illusion-miracle',
        'floating'         => 'floating',
        'east_west'        => 'east-west',
    );

    const CATEGORY_LABELS = array(
        'earrings'  => 'Earrings',
        'bracelet'  => 'Bracelets',
        'bracelets' => 'Bracelets',
        'necklace'  => 'Necklaces',
        'necklaces' => 'Necklaces',
    );

    const CATEGORY_SLUGS = array(
        'earrings'  => 'earrings',
        'bracelet'  => 'bracelets',
        'bracelets' => 'bracelets',
        'necklace'  => 'necklaces',
        'necklaces' => 'necklaces',
    );

    const STYLE_LABELS = array(
        'stud'              => 'Stud Earrings',
        'hoop'              => 'Hoop Earrings',
        'huggie'            => 'Huggie Earrings',
        'drop'              => 'Drop Earrings',
        'tennis'            => 'Tennis Bracelets',
        'bangle'            => 'Bangle Bracelets',
        'station'           => 'Station Necklaces',
        'solitaire_pendant' => 'Solitaire Pendants',
        'tennis_necklace'   => 'Tennis Necklaces',
        'cross'             => 'Cross Pendants',
        'heart_pendant'     => 'Heart Pendants',
    );

    const STYLE_SLUGS = array(
        'stud'              => 'studs',
        'hoop'              => 'hoops',
        'huggie'            => 'huggies',
        'drop'              => 'drops',
        'tennis'            => 'tennis',
        'bangle'            => 'bangles',
        'station'           => 'station',
        'solitaire_pendant' => 'solitaire',
        'tennis_necklace'   => 'tennis',
        'cross'             => 'cross',
        'heart_pendant'     => 'heart',
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
     * Render full `<head>` output: meta description, canonical, OpenGraph,
     * CloudFront image preconnect, and Schema.org JSON-LD (`WebPage`,
     * `BreadcrumbList`, `Product` + `AggregateOffer`, and `Dataset`).
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @return string
     */
    public function render_head_content(LDN_Page_Context $ctx, array $summary) {
        if (!apply_filters('ldn_emit_head_tags', true, $ctx)) {
            return '';
        }

        $canonical = $this->build_jewellery_url($ctx->site_id, $ctx->country_code, $ctx->diamond_type, $ctx->jewelry_path);
        $title = $this->page_title($ctx, $summary);
        $desc = $this->meta_description($ctx, $summary);
        $site = $this->config->get_site($ctx->site_id);
        $brand = is_array($site) && !empty($site['brand_name']) ? (string) $site['brand_name'] : '';

        $out = '';
        $img_origin = method_exists($this->config, 'jewellery_image_base_url')
            ? $this->config->jewellery_image_base_url($ctx->site_id)
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

        $first_img = $this->first_card_image($summary);
        if ($first_img !== '') {
            $out .= '<meta property="og:image" content="' . esc_url($first_img) . '" />' . "\n";
            $out .= '<meta property="og:image:alt" content="' . esc_attr($title) . '" />' . "\n";
        }

        $profile_for_head = $this->profile($ctx->site_id);
        $surface_for_head = $this->surface($profile_for_head);
        $quality_grid = $this->fetcher->fetch_artefact('jewellery_quality_grid_json', $ctx);
        $quality_grid = $this->normalise_quality_grid(is_array($quality_grid) ? $quality_grid : array(), $surface_for_head);
        $trend = $this->fetcher->fetch_artefact('jewellery_trend_json', $ctx);
        $schema = $this->schema_graph(
            $ctx,
            $summary,
            $quality_grid,
            is_array($trend) ? $trend : null,
            $canonical,
            $title,
            $desc
        );
        if ($schema !== array()) {
            $out .= '<script type="application/ld+json">'
                . wp_json_encode($schema, self::JSON_SCRIPT_FLAGS)
                . "</script>\n";
        }

        return $out;
    }

    /**
     * Render the full jewellery page HTML.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @return string
     */
    public function render(LDN_Page_Context $ctx, array $summary) {
        $profile = $this->profile($ctx->site_id);
        $treatment = $this->treatment($profile);
        $surface = $this->surface($profile);

        $quality_grid = $this->fetcher->fetch_artefact('jewellery_quality_grid_json', $ctx);
        $quality_grid = $this->normalise_quality_grid(is_array($quality_grid) ? $quality_grid : array(), $surface);

        $copy = $this->fetcher->fetch_artefact('jewellery_copy_json', $ctx);
        $copy = is_array($copy) ? $copy : array();

        $lab_comp = $this->fetcher->fetch_artefact('jewellery_lab_comparison_json', $ctx);
        $lab_comp = is_array($lab_comp) ? $lab_comp : null;

        $trend = $this->fetcher->fetch_artefact('jewellery_trend_json', $ctx);
        $trend = is_array($trend) ? $trend : null;

        $explorer = $this->fetcher->fetch_artefact('jewellery_explorer_json', $ctx);
        $explorer = is_array($explorer) ? $explorer : null;

        // Enrich pre-Stage-1 S3 summaries gracefully from explorer JSON when needed.
        $summary = $this->enrich_summary_from_explorer($ctx, $summary, $explorer, $surface);

        $price_renderer = $this->price_renderer();
        $chrome_cls = $price_renderer->chrome_heading_class($profile);
        $mod_cls = $treatment === 'metal_setting_led'
            ? 'ldn-jewellery-page--atelier'
            : 'ldn-jewellery-page--explorer';

        $out = '<div class="ldn-page-shell">';
        $out .= '<main class="ldn-price-page ldn-jewellery-page '
            . esc_attr($mod_cls . ' ' . $chrome_cls) . '" data-treatment="' . esc_attr($treatment) . '">';
        $out .= $price_renderer->theme_style_block($profile);
        $out .= $this->metal_style_block($surface);

        if ($ctx->jewelry_path === 'hub') {
            $out .= $this->render_hub($ctx, $summary, $quality_grid, $copy, $lab_comp, $treatment, $surface);
        } elseif ($treatment === 'metal_setting_led') {
            $out .= $this->render_atelier_page($ctx, $summary, $quality_grid, $copy, $lab_comp, $trend, $surface);
        } else {
            $sibling_explorer = $this->fetch_sibling_explorer($ctx, $surface);
            $out .= $this->render_explorer_page(
                $ctx,
                $summary,
                $quality_grid,
                $copy,
                $lab_comp,
                $explorer,
                $sibling_explorer,
                $surface
            );
        }

        $out .= '</main></div>';
        return $out;
    }

    /**
     * Human-readable page `<title>` / `<h1>`.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $summary
     * @return string
     */
    public function page_title(LDN_Page_Context $ctx, array $summary = array()) {
        $is_lab = ($ctx->diamond_type === 'lab-grown');
        $origin_prefix = $is_lab ? 'Lab-Grown ' : '';
        $path = trim((string) $ctx->jewelry_path, '/');

        if ($path === '' || $path === 'hub') {
            return $origin_prefix . 'Diamond Jewelry Prices';
        }

        $is_mens = false;
        $parts = explode('/', $path);
        if ($parts[0] === 'mens') {
            $is_mens = true;
            array_shift($parts);
        }
        $cat_slug = isset($parts[0]) ? $parts[0] : '';
        $style_key = isset($summary['jewelry_style']) && is_string($summary['jewelry_style'])
            ? $summary['jewelry_style']
            : '';

        $carat_prefix = '';
        $qualifiers = array();
        $guide_suffix = '';

        foreach (array_slice($parts, 1) as $idx => $token) {
            if ($idx === 0 && in_array($token, array('studs', 'hoops', 'huggies', 'drops', 'tennis', 'bangles', 'station', 'solitaire', 'cross', 'heart'), true)) {
                continue;
            }
            if ($token === 'size-chart-on-ear') {
                $guide_suffix = ' Size Chart on Ear';
                continue;
            }
            if ($token === '3-prong-vs-4-prong') {
                $guide_suffix = ': 3-Prong vs 4-Prong';
                continue;
            }
            if ($token === 'size-guide') {
                $guide_suffix = ' Size Guide';
                continue;
            }
            if ($token === 'length-guide') {
                $guide_suffix = ' Length Guide';
                continue;
            }
            if (strpos($token, 'under-') === 0) {
                $amt = (int) substr($token, 6);
                if ($amt > 0) {
                    $guide_suffix = ' Under $' . number_format($amt);
                }
                continue;
            }
            if ($token === '1-carat-screw-back') {
                $carat_prefix = '1 Carat ';
                $qualifiers[] = 'Screw-Back';
                continue;
            }
            $carat_lbl = $this->carat_label_from_slug($token);
            if ($carat_lbl !== null) {
                $carat_prefix = str_replace('-', ' to ', $carat_lbl) . ' Carat ';
                continue;
            }
            if (isset(self::METAL_LABELS[str_replace('-', '_', $token)])) {
                $qualifiers[] = self::METAL_LABELS[str_replace('-', '_', $token)];
                continue;
            }
            if (isset(self::SETTING_LABELS[str_replace('-', '_', $token)])) {
                $qualifiers[] = self::SETTING_LABELS[str_replace('-', '_', $token)];
                continue;
            }
            $qualifiers[] = ucwords(str_replace('-', ' ', $token));
        }

        if ($style_key !== '' && $style_key !== 'ALL' && isset(self::STYLE_LABELS[$style_key])) {
            $noun = 'Diamond ' . self::STYLE_LABELS[$style_key];
        } else {
            $cat_label = isset(self::CATEGORY_LABELS[$cat_slug]) ? self::CATEGORY_LABELS[$cat_slug] : 'Jewelry';
            $noun = 'Diamond ' . $cat_label;
        }

        $mens_prefix = $is_mens ? "Men's " : '';
        $qual_prefix = $qualifiers !== array() ? implode(' ', $qualifiers) . ' ' : '';

        return trim($mens_prefix . $carat_prefix . $origin_prefix . $qual_prefix . $noun . $guide_suffix);
    }

    /**
     * Build a public URL for a jewellery route on `$site_id`.
     *
     * @param string      $site_id
     * @param string      $country
     * @param string|null $diamond_type 'natural' | 'lab-grown'
     * @param string|null $jewelry_path e.g. 'hub', 'earrings', 'earrings/studs/1-carat', 'mens/earrings/studs'
     * @return string
     */
    public function build_jewellery_url($site_id, $country, $diamond_type, $jewelry_path) {
        $structure = $this->config->get_url_structure($site_id);
        if (!is_array($structure)) {
            return '';
        }
        $is_lab = ($diamond_type === 'lab-grown');
        $lab_slug = isset($structure['jewellery_type_lab']) ? (string) $structure['jewellery_type_lab'] : '';
        if ($is_lab && $lab_slug === '') {
            return '';
        }

        $path = trim((string) $jewelry_path, '/');
        $replacements = array('{country}' => strtolower((string) $country));
        if ($is_lab) {
            $replacements['{jewellery_type}'] = $lab_slug;
        }

        if ($path === '' || $path === 'hub') {
            $key = $is_lab ? 'jewellery_lab_level_1' : 'jewellery_level_1';
        } else {
            $parts = explode('/', $path);
            if ($parts[0] === 'mens') {
                array_shift($parts);
                $cat = isset($parts[0]) ? $parts[0] : '';
                $rest = implode('/', array_slice($parts, 1));
                $replacements['{category}'] = $cat;
                if ($rest === '') {
                    $key = $is_lab ? 'jewellery_lab_level_mens_cat' : 'jewellery_level_mens_cat';
                } else {
                    $replacements['{subpath}'] = $rest;
                    $key = $is_lab ? 'jewellery_lab_level_mens' : 'jewellery_level_mens';
                }
            } else {
                $cat = $parts[0];
                $rest = implode('/', array_slice($parts, 1));
                $replacements['{category}'] = $cat;
                if ($rest === '') {
                    $key = $is_lab ? 'jewellery_lab_level_2' : 'jewellery_level_2';
                } else {
                    $replacements['{subpath}'] = $rest;
                    $key = $is_lab ? 'jewellery_lab_level_3' : 'jewellery_level_3';
                }
            }
        }

        if (empty($structure[$key]) || !is_string($structure[$key])) {
            return '';
        }

        $rel = strtr($structure[$key], $replacements);
        if (method_exists($this->config, 'path_relative_to_mount')) {
            $rel = $this->config->path_relative_to_mount($rel);
        }
        $rel = '/' . trim($rel, '/') . '/';
        if (function_exists('home_url')) {
            return home_url($rel);
        }
        return $rel;
    }

    // =========================================================================
    // Family B: Diamond Advisors (`metal_setting_led` — `da-v6` Lookbook)
    // =========================================================================

    private function render_atelier_page(
        LDN_Page_Context $ctx,
        array $summary,
        array $quality_grid,
        array $copy,
        $lab_comp,
        $trend,
        array $surface
    ) {
        $hero = isset($summary['hero']) && is_array($summary['hero']) ? $summary['hero'] : array();
        $cards = isset($summary['evidence']) && is_array($summary['evidence']) ? $summary['evidence'] : array();
        $retailer_names = method_exists($this->config, 'retailer_display_names')
            ? $this->config->retailer_display_names()
            : array();

        $title = $this->page_title($ctx, $summary);
        $is_lab = ($ctx->diamond_type === 'lab-grown');
        $origin_word = $is_lab ? 'lab-grown' : 'natural';
        $design_count = isset($hero['design_count']) ? (int) $hero['design_count'] : count($cards);
        $retailer_count = isset($hero['retailer_count']) ? (int) $hero['retailer_count'] : 0;
        $headline_price = $this->hero_headline_price($hero, 'mean_min_max');
        $min_price = isset($hero['min_price']) ? (float) $hero['min_price'] : $headline_price;
        $max_price = isset($hero['max_price']) ? (float) $hero['max_price'] : $headline_price;

        $nat_url = $this->build_jewellery_url($ctx->site_id, $ctx->country_code, 'natural', $ctx->jewelry_path);
        $lab_url = !empty($surface['publish_lab_tree'])
            ? $this->build_jewellery_url($ctx->site_id, $ctx->country_code, 'lab-grown', $ctx->jewelry_path)
            : '';

        $nat_count = null;
        $lab_count = null;
        if (is_array($lab_comp)) {
            if (isset($lab_comp['natural']['design_count'])) {
                $nat_count = (int) $lab_comp['natural']['design_count'];
            }
            if (isset($lab_comp['lab']['design_count'])) {
                $lab_count = (int) $lab_comp['lab']['design_count'];
            }
        }
        if (!$is_lab && $nat_count === null) {
            $nat_count = $design_count;
        }
        if ($is_lab && $lab_count === null) {
            $lab_count = $design_count;
        }

        $out = '<div class="ldn-jwl-wrap">';

        // Row 1: Breadcrumb + Rectangular Natural | Lab-Grown Switch
        $out .= '<div class="ldn-jwl-top-utility">';
        $out .= $this->breadcrumb_html($ctx, $summary, '/');
        if ($lab_url !== '' && ($is_lab || $lab_count === null || $lab_count > 0)) {
            $nat_label = 'Natural' . ($nat_count !== null ? ' (' . number_format($nat_count) . ')' : '');
            $lab_label = 'Lab-Grown' . ($lab_count !== null ? ' (' . number_format($lab_count) . ')' : '');
            $out .= '<nav class="ldn-jwl-origin-switch" aria-label="Diamond origin">';
            $out .= '<a class="ldn-jwl-origin-btn" href="' . esc_url($nat_url) . '"'
                . (!$is_lab ? ' aria-current="page"' : '') . '>' . esc_html($nat_label) . '</a>';
            $out .= '<a class="ldn-jwl-origin-btn" href="' . esc_url($lab_url) . '"'
                . ($is_lab ? ' aria-current="page"' : '') . '>' . esc_html($lab_label) . '</a>';
            $out .= '</nav>';
        }
        $out .= '</div>';

        // Row 2: 2-Column Editorial Hero
        $out .= '<header class="ldn-jwl-hero-band">';
        $out .= '<div>';
        $out .= '<h1 class="ldn-jwl-title">' . esc_html($title) . '</h1>';
        $out .= '<p class="ldn-jwl-scope-line">';
        $out .= esc_html(sprintf(
            '%s %s designs across %d specialist US jewelers.',
            number_format($design_count),
            $origin_word,
            $retailer_count
        ));
        $out .= ' <a class="ldn-jwl-jump-link" href="#price-by-metal-setting">Price by metal &amp; setting &darr;</a>';
        $out .= '</p>';
        $out .= '</div>';

        $out .= '<div class="ldn-jwl-hero-figures" aria-label="Price summary">';
        $out .= '<div><span class="ldn-jwl-figure-label">Average Price</span>'
            . '<span class="ldn-jwl-figure-val">' . esc_html($this->usd($headline_price)) . '</span></div>';
        $out .= '<div><span class="ldn-jwl-figure-label">Price Range</span>'
            . '<span class="ldn-jwl-figure-val ldn-jwl-figure-val--range">'
            . esc_html($this->usd($min_price) . ' - ' . $this->usd($max_price)) . '</span></div>';
        $out .= '</div>';
        $out .= '</header>';

        // Row 3: Total Carat Weight Bar
        $out .= $this->atelier_carat_bar_html($ctx, $summary, $surface);

        // Lookbook Section + Sticky Chapter Bar
        $out .= '<section id="lookbook-section" aria-labelledby="ldn-jwl-lookbook-heading">';
        $out .= '<h2 id="ldn-jwl-lookbook-heading" class="ldn-sr-only">Curated Lookbook</h2>';
        $out .= '<div class="ldn-jwl-sticky-sentinel" aria-hidden="true"></div>';

        $present_metals = array();
        $setting_counts = array();
        foreach (self::METAL_ORDER as $m) {
            if ($m === 'silver') {
                continue;
            }
            $cnt = 0;
            foreach ($cards as $c) {
                $ms = isset($c['metals_available']) && is_array($c['metals_available']) ? $c['metals_available'] : array();
                if (in_array($m, $ms, true)) {
                    $cnt++;
                }
            }
            if ($cnt > 0) {
                $present_metals[$m] = $cnt;
            }
        }
        foreach ($cards as $c) {
            $ss = isset($c['settings_available']) && is_array($c['settings_available']) ? $c['settings_available'] : array();
            foreach ($ss as $s) {
                $setting_counts[$s] = isset($setting_counts[$s]) ? $setting_counts[$s] + 1 : 1;
            }
        }
        $present_settings = array();
        foreach ($setting_counts as $s => $cnt) {
            if ($cnt >= 2) {
                $present_settings[] = $s;
            }
        }

        $initial_visible = isset($summary['featured_count']) && (int) $summary['featured_count'] > 0
            ? min((int) $summary['featured_count'], 12)
            : 12;
        $shown_cards = array_slice($cards, 0, $initial_visible);

        $out .= '<div class="ldn-jwl-chapter-bar" id="ldn-jwl-sticky-bar">';
        $out .= '<div class="ldn-jwl-metal-tabs" role="tablist" aria-label="Filter by precious metal" id="ldn-jwl-metal-tabs">';
        $out .= '<button type="button" role="tab" class="ldn-jwl-metal-tab" aria-selected="true" data-metal="all">'
            . 'All Metals <small>' . count($cards) . '</small></button>';
        foreach ($present_metals as $m => $cnt) {
            $out .= '<button type="button" role="tab" class="ldn-jwl-metal-tab" aria-selected="false" data-metal="' . esc_attr($m) . '">'
                . esc_html(self::METAL_LABELS[$m]) . ' <small>' . (int) $cnt . '</small></button>';
        }
        $out .= '</div>';

        $out .= '<div class="ldn-jwl-chapter-sub">';
        $out .= '<div class="ldn-jwl-setting-links" id="ldn-jwl-setting-chips">';
        if (count($present_settings) > 1) {
            $out .= '<span class="ldn-jwl-setting-label">Setting:</span>';
            $out .= '<button type="button" class="ldn-jwl-setting-btn" aria-pressed="true" data-setting="all">All</button>';
            foreach ($present_settings as $s) {
                $out .= '<button type="button" class="ldn-jwl-setting-btn" aria-pressed="false" data-setting="' . esc_attr($s) . '">'
                    . esc_html($this->setting_label($s)) . '</button>';
            }
        }
        $out .= '</div>';

        $out .= '<label class="ldn-jwl-sort-inline">';
        $out .= '<span id="ldn-jwl-showing-meta">' . count($shown_cards) . ' of ' . count($cards) . ' designs</span>';
        $out .= '<span>&middot; Sort:</span>';
        $out .= '<select id="ldn-jwl-sort" aria-label="Sort designs">';
        $out .= '<option value="featured">Advisor curation</option>';
        $out .= '<option value="price_asc">Lowest price first</option>';
        $out .= '<option value="price_desc">Highest price first</option>';
        $out .= '</select>';
        $out .= '</label>';
        $out .= '</div>';
        $out .= '</div>';

        // Featured 2-up plates + 2-column horizontal dossier
        $lead_cards = count($shown_cards) >= 2 ? array_slice($shown_cards, 0, 2) : array();
        $dossier_cards = count($shown_cards) >= 2 ? array_slice($shown_cards, 2) : $shown_cards;

        $out .= '<div class="ldn-jwl-featured" id="ldn-jwl-featured"' . ($lead_cards === array() ? ' hidden' : '') . '>';
        foreach ($lead_cards as $idx => $c) {
            $out .= $this->atelier_plate_html($c, $headline_price, $origin_word, $retailer_names, true, $idx < 2);
        }
        $out .= '</div>';

        $out .= '<div class="ldn-jwl-dossier" id="ldn-jwl-dossier"' . ($dossier_cards === array() ? ' hidden' : '') . '>';
        foreach ($dossier_cards as $c) {
            $out .= $this->atelier_plate_html($c, $headline_price, $origin_word, $retailer_names, false, false);
        }
        $out .= '</div>';

        $rem = count($cards) - count($shown_cards);
        $out .= '<div class="ldn-jwl-more-wrap" id="ldn-jwl-more-wrap"' . ($rem > 0 ? '' : ' hidden') . '>';
        $out .= '<button type="button" class="ldn-jwl-btn-more" id="ldn-jwl-btn-more">'
            . esc_html(sprintf('View %d more designs (%d remaining)', min(10, max(0, $rem)), max(0, $rem)))
            . '</button>';
        $out .= '</div>';
        $out .= '</section>';

        // Gated LDO Sponsor Slot (`jewellery_ads` wp_widget — false at launch)
        $out .= $this->jewellery_ad_html($ctx);

        // Analytical Breakdown Tables (#price-by-metal-setting)
        $out .= $this->atelier_tables_html($quality_grid, $min_price, $max_price);

        // Trend note (rendered only when status === 'ok')
        if (is_array($trend) && isset($trend['status']) && $trend['status'] === 'ok' && !empty($trend['points'])) {
            $out .= $this->trend_section_html($trend, 'Average Price');
        }

        // Advisory Notes & US-English Methodology
        $out .= $this->atelier_notes_html($ctx, $summary, $copy, $lab_comp, $nat_url, $lab_url);

        // Client payload for instant tab / metal stamp / table row filtering (zero extra fetch)
        $payload = array(
            'mode'           => 'atelier',
            'origin'         => $is_lab ? 'lab' : 'natural',
            'headlinePrice'  => $headline_price,
            'minPrice'       => $min_price,
            'maxPrice'       => $max_price,
            'initialVisible' => $initial_visible,
            'cards'          => $cards,
            'qualityGrid'    => $quality_grid,
        );
        $out .= '<script type="application/json" id="ldn-jewellery-payload">'
            . wp_json_encode($payload, self::JSON_SCRIPT_FLAGS)
            . '</script>';

        $out .= '</div>';
        return $out;
    }

    private function atelier_carat_bar_html(LDN_Page_Context $ctx, array $summary, array $surface) {
        $bands = isset($summary['carat_bands']) && is_array($summary['carat_bands'])
            ? $summary['carat_bands']
            : array();
        if ($bands === array()) {
            return '';
        }

        $category = isset($summary['jewelry_category']) ? (string) $summary['jewelry_category'] : '';
        $cat_slug = isset(self::CATEGORY_SLUGS[$category]) ? self::CATEGORY_SLUGS[$category] : $category;
        $style = isset($summary['jewelry_style']) && $summary['jewelry_style'] !== 'ALL'
            ? (string) $summary['jewelry_style']
            : (isset($surface['default_styles'][$category]) ? (string) $surface['default_styles'][$category] : '');
        $style_slug = isset(self::STYLE_SLUGS[$style]) ? self::STYLE_SLUGS[$style] : '';
        if ($cat_slug === '' || $style_slug === '') {
            return '';
        }

        $current_carat = $this->current_carat_label_from_path($ctx->jewelry_path);

        $out = '<nav class="ldn-jwl-carat-bar" aria-label="Total carat weights">';
        $out .= '<span class="ldn-jwl-carat-label">Total Carat Weight</span>';
        foreach ($bands as $b) {
            if (!is_array($b) || empty($b['carat_label']) || empty($b['carat_slug'])) {
                continue;
            }
            $lbl = (string) $b['carat_label'];
            $slug = (string) $b['carat_slug'];
            $price = isset($b['headline_price']) ? (float) $b['headline_price'] : (float) ($b['mean_price'] ?? 0);
            $subpath = $cat_slug . '/' . $style_slug . '/' . $slug;
            $url = $this->build_jewellery_url($ctx->site_id, $ctx->country_code, $ctx->diamond_type, $subpath);
            $active = ($current_carat !== null && $current_carat === $lbl);
            $out .= '<a class="ldn-jwl-carat-link' . ($active ? ' is-active' : '') . '" href="' . esc_url($url) . '"'
                . ($active ? ' aria-current="page"' : '') . '>'
                . esc_html($lbl . ' ct (' . $this->usd($price) . ')') . '</a>';
        }
        $out .= '</nav>';
        return $out;
    }

    private function atelier_plate_html(
        array $card,
        $mean_price,
        $origin_word,
        array $retailer_names,
        $is_lead,
        $eager_img
    ) {
        $code = isset($card['retailer_code']) ? (string) $card['retailer_code'] : '';
        $house = isset($retailer_names[$code]) ? $retailer_names[$code] : $code;
        $design_key = isset($card['design_key']) ? (string) $card['design_key'] : '';
        $card_id = $code . '|' . $design_key;

        $metals = isset($card['metals_available']) && is_array($card['metals_available'])
            ? $card['metals_available']
            : array();
        $chosen_metal = isset($metals[0]) ? (string) $metals[0] : '';
        $by_metal = isset($card['by_metal']) && is_array($card['by_metal']) ? $card['by_metal'] : array();
        $mv = ($chosen_metal !== '' && isset($by_metal[$chosen_metal]) && is_array($by_metal[$chosen_metal]))
            ? $by_metal[$chosen_metal]
            : array();

        $price = isset($mv['price']) ? (float) $mv['price'] : (float) ($card['price'] ?? 0);
        $title = !empty($mv['product_title']) ? (string) $mv['product_title'] : (string) ($card['product_title'] ?? '');
        $img = !empty($mv['image_url']) ? (string) $mv['image_url'] : (string) ($card['image_url'] ?? '');
        $url = !empty($mv['product_url']) ? (string) $mv['product_url'] : (string) ($card['product_url'] ?? '');
        $karat = !empty($mv['metal_karat'])
            ? (string) $mv['metal_karat']
            : (isset($card['karats_available'][0]) ? (string) $card['karats_available'][0] : '');
        $setting = !empty($mv['setting'])
            ? (string) $mv['setting']
            : (isset($card['settings_available'][0]) ? (string) $card['settings_available'][0] : '');

        $spec_parts = array();
        if ($karat !== '' && stripos($karat, 'platinum') === false) {
            $spec_parts[] = str_replace('_950', '', $karat);
        }
        if ($chosen_metal !== '' && isset(self::METAL_LABELS[$chosen_metal])) {
            $spec_parts[] = strtolower(self::METAL_LABELS[$chosen_metal]);
        }
        if ($setting !== '') {
            $spec_parts[] = strtolower($this->setting_label($setting));
        }
        $spec_line = $spec_parts !== array()
            ? implode(' · ', $spec_parts)
            : ucfirst($origin_word) . ' diamond';

        $note = $this->advisor_price_context($price, $mean_price, $setting);

        $out = '<article class="ldn-jwl-plate' . ($is_lead ? ' ldn-jwl-plate--lead' : '') . '" data-card="' . esc_attr($card_id) . '">';
        $out .= '<div class="ldn-jwl-plate__frame">';
        if ($img !== '') {
            $loading = $eager_img ? 'eager" fetchpriority="high' : 'lazy';
            $out .= '<img src="' . esc_url($img) . '" alt="' . esc_attr($title) . '" width="400" height="400" decoding="async" loading="' . $loading . '" />';
        } else {
            $out .= '<div class="ldn-jwl-plate__no-photo">' . esc_html('Photograph unavailable from ' . $house) . '</div>';
        }
        $out .= '</div>';

        $out .= '<div class="ldn-jwl-plate__caption">';
        $out .= '<div class="ldn-jwl-plate__kicker-row">'
            . '<span class="ldn-jwl-plate__house">' . esc_html($house) . '</span>'
            . '<span class="ldn-jwl-plate__price">' . esc_html($this->usd($price)) . '</span>'
            . '</div>';
        $out .= '<h3 class="ldn-jwl-plate__title">' . esc_html($title) . '</h3>';
        $out .= '<div class="ldn-jwl-plate__spec">' . esc_html($spec_line) . '</div>';
        $out .= '<div class="ldn-jwl-plate__note">' . esc_html($note) . '</div>';

        $out .= '<div class="ldn-jwl-plate__foot">';
        $out .= '<div class="ldn-jwl-metal-stamps">';
        foreach ($metals as $m) {
            $label = isset(self::METAL_LABELS[$m]) ? self::METAL_LABELS[$m] : $m;
            $short = isset(self::METAL_CODES[$m]) ? self::METAL_CODES[$m] : strtoupper(substr($m, 0, 2));
            $pressed = ($m === $chosen_metal) ? 'true' : 'false';
            $out .= '<button type="button" class="ldn-jwl-metal-stamp" title="' . esc_attr($label) . '"'
                . ' aria-label="' . esc_attr($short . ' - ' . $label) . '" aria-pressed="' . $pressed . '"'
                . ' style="--bar: var(--ldn-metal-' . esc_attr($m) . ')"'
                . ' data-card="' . esc_attr($card_id) . '" data-metal="' . esc_attr($m) . '">'
                . esc_html($short) . '</button>';
        }
        $out .= '</div>';
        if ($url !== '') {
            $out .= '<a class="ldn-jwl-btn-details" href="' . esc_url($url) . '" target="_blank" rel="sponsored noopener">More details</a>';
        }
        $out .= '</div>';
        $out .= '</div>';
        $out .= '</article>';
        return $out;
    }

    private function atelier_tables_html(array $quality_grid, $scale_lo, $scale_hi) {
        $lead_id = isset($quality_grid['lead_table']) && is_string($quality_grid['lead_table'])
            ? $quality_grid['lead_table']
            : '';
        $tables_map = isset($quality_grid['tables']) && is_array($quality_grid['tables'])
            ? $quality_grid['tables']
            : array();
        if ($lead_id === '' || empty($tables_map[$lead_id]) || !is_array($tables_map[$lead_id])) {
            return '';
        }

        $lead_rows = $tables_map[$lead_id];
        usort($lead_rows, function ($a, $b) {
            return ((float) ($a['headline_price'] ?? 0)) <=> ((float) ($b['headline_price'] ?? 0));
        });

        $is_metal_setting = ($lead_id === 'metal_setting');
        $heading = $is_metal_setting ? 'Price by Metal & Setting' : 'Price by Precious Metal';
        $hint = $is_metal_setting
            ? 'Select any row to filter the lookbook above to that metal and setting profile.'
            : 'Select any row to filter the lookbook above to that precious metal.';

        $out = '<section class="ldn-jwl-section-tables" id="price-by-metal-setting" aria-labelledby="ldn-jwl-tables-heading">';
        $out .= '<div class="ldn-jwl-section-head">';
        $out .= '<h2 id="ldn-jwl-tables-heading">' . esc_html($heading) . '</h2>';
        $out .= '<span class="ldn-jwl-section-meta">Ordered from lowest average price to highest</span>';
        $out .= '</div>';
        $out .= '<p class="ldn-jwl-table-hint" id="ldn-jwl-lead-hint">' . esc_html($hint) . '</p>';

        $visible_lead = array_slice($lead_rows, 0, 8);
        $has_setting = isset($lead_rows[0]['setting']);

        $out .= '<table class="ldn-jwl-atelier-table" id="ldn-jwl-lead-table">';
        $out .= '<thead><tr><th>Metal</th>'
            . ($has_setting ? '<th>Setting</th>' : '')
            . '<th>Average Price</th><th>Price Range</th><th class="num">Designs</th></tr></thead>';
        $out .= '<tbody id="ldn-jwl-lead-tbody">';
        foreach ($visible_lead as $r) {
            $out .= $this->atelier_table_row_html($r, $has_setting, false, false, $scale_lo, $scale_hi);
        }
        $out .= '</tbody></table>';

        if (count($lead_rows) > 8) {
            $out .= '<div class="ldn-jwl-more-wrap ldn-jwl-more-wrap--left" id="ldn-jwl-table-more-wrap">';
            $out .= '<button type="button" class="ldn-jwl-btn-more ldn-jwl-btn-more--sm" id="ldn-jwl-btn-table-more">'
                . esc_html(sprintf('Show all %d combinations (lowest to highest)', count($lead_rows)))
                . '</button>';
            $out .= '</div>';
        }

        // Secondary breakdown tables (`setting` on tennis, `color_clarity_bands` when >= 2 rows)
        foreach ($tables_map as $tid => $sec_rows) {
            if ($tid === $lead_id || !is_array($sec_rows) || count($sec_rows) < 2) {
                continue;
            }
            usort($sec_rows, function ($a, $b) {
                return ((float) ($a['headline_price'] ?? 0)) <=> ((float) ($b['headline_price'] ?? 0));
            });
            $is_grade = isset($sec_rows[0]['color_band']);
            $is_setting_only = (!$is_grade && isset($sec_rows[0]['setting']));
            $sec_title = $is_grade
                ? 'Price by color & clarity band (where jewelers disclose grades)'
                : 'Price by setting profile (lowest to highest)';

            $out .= '<details class="ldn-jwl-accordion"' . ($is_setting_only ? ' open' : '') . ' data-secondary-id="' . esc_attr($tid) . '">';
            $out .= '<summary>' . esc_html($sec_title) . '</summary>';
            $out .= '<div class="ldn-jwl-accordion__body">';
            if ($is_setting_only) {
                $out .= '<p class="ldn-jwl-table-hint">Select any setting profile to filter the lookbook above.</p>';
            }
            $out .= '<table class="ldn-jwl-atelier-table">';
            $out .= '<thead><tr><th>' . esc_html($is_grade ? 'Color Band' : 'Setting') . '</th>'
                . ($is_grade ? '<th>Clarity Band</th>' : '')
                . '<th>Average Price</th><th>Price Range</th><th class="num">Designs</th></tr></thead>';
            $out .= '<tbody class="ldn-jwl-sec-tbody">';
            foreach ($sec_rows as $r) {
                $out .= $this->atelier_table_row_html($r, false, $is_grade, $is_setting_only, $scale_lo, $scale_hi);
            }
            $out .= '</tbody></table></div></details>';
        }

        $out .= '</section>';
        return $out;
    }

    private function atelier_table_row_html(array $r, $has_setting, $is_grade, $is_setting_only, $scale_lo, $scale_hi) {
        $headline = (float) ($r['headline_price'] ?? 0);
        $r_lo = isset($r['min_price']) ? (float) $r['min_price'] : (float) ($r['range_low'] ?? $headline);
        $r_hi = isset($r['max_price']) ? (float) $r['max_price'] : (float) ($r['range_high'] ?? $headline);
        $span_denom = max(1.0, (float) $scale_hi - (float) $scale_lo);
        $left = max(0.0, min(100.0, (($r_lo - $scale_lo) / $span_denom) * 100.0));
        $right = max(0.0, min(100.0, (($r_hi - $scale_lo) / $span_denom) * 100.0));
        $width = max(3.0, $right - $left);
        $dot = max(0.0, min(100.0, (($headline - $scale_lo) / $span_denom) * 100.0));
        $range_str = (round($r_lo) === round($r_hi))
            ? $this->usd($r_lo)
            : ($this->usd($r_lo) . ' - ' . $this->usd($r_hi));

        $interactive = (!$is_grade);
        $data_attrs = '';
        if ($has_setting) {
            $m = (string) ($r['metal'] ?? '');
            $s = (string) ($r['setting'] ?? '');
            $col1 = '<strong>' . esc_html($this->metal_label($m)) . '</strong>'
                . '<span class="ldn-jwl-mobile-sub"> &middot; ' . esc_html($this->setting_label($s)) . '</span>';
            $col2 = '<td class="cell-hide-mobile">' . esc_html($this->setting_label($s)) . '</td>';
            $data_attrs = ' data-metal="' . esc_attr($m) . '" data-setting="' . esc_attr($s) . '"';
        } elseif ($is_setting_only) {
            $s = (string) ($r['setting'] ?? '');
            $col1 = '<strong>' . esc_html($this->setting_label($s)) . '</strong>';
            $col2 = '';
            $data_attrs = ' data-setting="' . esc_attr($s) . '"';
        } elseif ($is_grade) {
            $cb = (string) ($r['color_band'] ?? '');
            $cl = (string) ($r['clarity_band'] ?? '');
            $col1 = '<strong>' . esc_html($cb) . '</strong>'
                . '<span class="ldn-jwl-mobile-sub"> &middot; ' . esc_html($cl) . '</span>';
            $col2 = '<td class="cell-hide-mobile">' . esc_html($cl) . '</td>';
        } else {
            $m = (string) ($r['metal'] ?? '');
            $col1 = '<strong>' . esc_html($this->metal_label($m)) . '</strong>';
            $col2 = '';
            $data_attrs = ' data-metal="' . esc_attr($m) . '"';
        }

        return '<tr class="' . ($interactive ? 'is-interactive' : '') . '"' . $data_attrs . '>'
            . '<td class="cell-title">' . $col1 . '</td>'
            . $col2
            . '<td class="cell-mean">' . esc_html($this->usd($headline)) . '</td>'
            . '<td class="cell-span"><div class="ldn-jwl-row-span"><span>' . esc_html($range_str) . '</span>'
            . '<span class="ldn-jwl-row-span__track" aria-hidden="true">'
            . '<span class="ldn-jwl-row-span__fill" style="left:' . round($left, 1) . '%;width:' . round($width, 1) . '%"></span>'
            . '<span class="ldn-jwl-row-span__dot" style="left:' . round($dot, 1) . '%"></span>'
            . '</span></div></td>'
            . '<td class="num cell-count">' . (int) ($r['design_count'] ?? 0) . '</td>'
            . '</tr>';
    }

    private function atelier_notes_html(
        LDN_Page_Context $ctx,
        array $summary,
        array $copy,
        $lab_comp,
        $nat_url,
        $lab_url
    ) {
        $is_lab = ($ctx->diamond_type === 'lab-grown');
        $nat_hero = is_array($lab_comp) && isset($lab_comp['natural']) && is_array($lab_comp['natural'])
            ? $lab_comp['natural']
            : (!$is_lab && isset($summary['hero']) ? $summary['hero'] : null);
        $lab_hero = is_array($lab_comp) && isset($lab_comp['lab']) && is_array($lab_comp['lab'])
            ? $lab_comp['lab']
            : ($is_lab && isset($summary['hero']) ? $summary['hero'] : null);

        $out = '<section class="ldn-jwl-notes-grid" aria-label="Advisory notes and methodology">';
        $out .= '<div>';
        if (is_array($nat_hero) && is_array($lab_hero)) {
            $nat_avg = $this->hero_headline_price($nat_hero, 'mean_min_max');
            $lab_avg = $this->hero_headline_price($lab_hero, 'mean_min_max');
            $pct = $nat_avg > 0 ? (int) round((1.0 - ($lab_avg / $nat_avg)) * 100.0) : 0;
            if (!$is_lab) {
                $out .= '<h3>Comparing Lab-Grown Equivalents</h3>';
                $out .= '<p>Across specialist US jewelers, lab-grown equivalents in this category average <strong>'
                    . esc_html($this->usd($lab_avg)) . '</strong> across '
                    . (int) ($lab_hero['design_count'] ?? 0) . ' designs';
                if ($pct > 0) {
                    $out .= ' (' . $pct . '% less than the ' . esc_html($this->usd($nat_avg)) . ' natural average)';
                }
                $out .= '.';
                if ($lab_url !== '') {
                    $out .= ' <a href="' . esc_url($lab_url) . '">View the lab-grown index</a>.';
                }
                $out .= '</p>';
            } else {
                $out .= '<h3>Comparing Natural Equivalents</h3>';
                $out .= '<p>Natural diamond equivalents in this category average <strong>'
                    . esc_html($this->usd($nat_avg)) . '</strong> across '
                    . (int) ($nat_hero['design_count'] ?? 0) . ' designs ('
                    . esc_html($this->usd((float) ($nat_hero['min_price'] ?? $nat_avg)) . ' - ' . $this->usd((float) ($nat_hero['max_price'] ?? $nat_avg)))
                    . ').';
                if ($nat_url !== '') {
                    $out .= ' <a href="' . esc_url($nat_url) . '">View the natural diamond index</a>.';
                }
                $out .= '</p>';
            }
        } elseif (!empty($summary['lab_note']) && is_array($summary['lab_note'])) {
            $ln = $summary['lab_note'];
            $ln_price = (float) ($ln['lab_mean'] ?? $ln['lab_median'] ?? $ln['lab_mean_price'] ?? $ln['lab_median_price'] ?? 0);
            $ln_pct = isset($ln['percent_cheaper']) ? (float) $ln['percent_cheaper'] : 0.0;
            if ($ln_price > 0) {
                $out .= '<h3>Comparing Lab-Grown Equivalents</h3>';
                $out .= '<p>Across specialist US jewelers, lab-grown equivalents in this category sit near <strong>'
                    . esc_html($this->usd($ln_price)) . '</strong>';
                if ($ln_pct > 0) {
                    $out .= ' (' . (int) round($ln_pct) . '% less than natural equivalents)';
                }
                $out .= '.</p>';
            }
        } else {
            $out .= '<h3>Scope of This Index</h3>';
            $paras = isset($copy['paragraphs']) && is_array($copy['paragraphs']) ? $copy['paragraphs'] : array();
            foreach ($paras as $p) {
                if (is_string($p) && trim($p) !== '') {
                    $out .= '<p>' . esc_html($this->us_english($p)) . '</p>';
                }
            }
        }
        $out .= '</div>';

        $out .= '<div><h3>How We Calculate This Index</h3><ul>';
        $method = isset($copy['methodology']) && is_array($copy['methodology'])
            ? $copy['methodology']
            : array(
                'Carat is total weight (TW) for the piece, including pairs.',
                'Each jeweler design is counted once before the average price and full price range are calculated, so listings with multiple metal or grade options do not skew the average.',
                'Cells with fewer than 3 designs are omitted rather than interpolated.',
            );
        foreach ($method as $line) {
            if (is_string($line) && trim($line) !== '') {
                $clean = str_replace(
                    'before the median is calculated',
                    'before the average price and full price range are calculated',
                    $this->us_english($line)
                );
                $out .= '<li>' . esc_html($clean) . '</li>';
            }
        }
        $out .= '</ul></div>';
        $out .= '</section>';
        return $out;
    }

    // =========================================================================
    // Family A: Ringspo (`quality_led` — `v8` Explorer + Lead 3x3 Quality Grid)
    // =========================================================================

    private function render_explorer_page(
        LDN_Page_Context $ctx,
        array $summary,
        array $quality_grid,
        array $copy,
        $lab_comp,
        $explorer,
        $sibling_explorer,
        array $surface
    ) {
        $hero = isset($summary['hero']) && is_array($summary['hero']) ? $summary['hero'] : array();
        $cards = isset($summary['evidence']) && is_array($summary['evidence']) ? $summary['evidence'] : array();
        $retailer_names = method_exists($this->config, 'retailer_display_names')
            ? $this->config->retailer_display_names()
            : array();

        $title = $this->page_title($ctx, $summary);
        $is_lab = ($ctx->diamond_type === 'lab-grown');
        $origin_noun = $is_lab ? 'lab-grown diamond' : 'natural diamond';
        $piece_noun = $this->piece_singular_noun($summary);
        $piece_plural = $this->piece_plural_noun($summary);

        $p25 = isset($hero['p25_price']) ? (float) $hero['p25_price'] : 0.0;
        $p75 = isset($hero['p75_price']) ? (float) $hero['p75_price'] : 0.0;
        $median = isset($hero['median_price']) ? (float) $hero['median_price'] : 0.0;
        $design_count = isset($hero['design_count']) ? (int) $hero['design_count'] : count($cards);
        $retailer_count = isset($hero['retailer_count']) ? (int) $hero['retailer_count'] : 0;
        $checked_date = !empty($summary['analysis_date']) ? (string) $summary['analysis_date'] : '';

        $nat_url = $this->build_jewellery_url($ctx->site_id, $ctx->country_code, 'natural', $ctx->jewelry_path);
        $lab_url = !empty($surface['publish_lab_tree'])
            ? $this->build_jewellery_url($ctx->site_id, $ctx->country_code, 'lab-grown', $ctx->jewelry_path)
            : '';

        $out = '<div class="ldn-jwl-exp-wrap ldn-jwl-layout-b">';
        $out .= $this->breadcrumb_html($ctx, $summary, '›');
        $out .= '<h1 class="ldn-jwl-exp-h1">' . esc_html($title) . '</h1>';

        $out .= '<div class="ldn-jwl-answer">';
        $out .= '<div class="ldn-jwl-answer__range" id="ldn-exp-range">'
            . 'Typical price range: <span class="ldn-jwl-answer__nums">'
            . esc_html($this->usd($p25) . ' to ' . $this->usd($p75)) . '</span></div>';
        $out .= '<div class="ldn-jwl-answer__sub" id="ldn-exp-range-sub">'
            . esc_html(sprintf(
                'Middle price %s across %s designs from %d US retailers%s.',
                $this->usd($median),
                number_format($design_count),
                $retailer_count,
                $checked_date !== '' ? ', checked ' . $checked_date : ''
            )) . '</div>';
        $out .= '</div>';

        $has_explorer = is_array($explorer) && !empty($explorer['band_order']) && !empty($explorer['bands']);

        if ($has_explorer) {
            // Mobile compact filter bar (Natural/Lab + Filters bottom-sheet button)
            $out .= '<div class="ldn-jwl-bar">';
            $out .= $this->explorer_seg_html($is_lab, $nat_url, $lab_url, false);
            $out .= '<button type="button" class="ldn-jwl-bar__filters" id="ldn-exp-open-filters" aria-controls="ldn-exp-filters" aria-expanded="false">'
                . 'Filters<span class="ldn-jwl-bar__summary" id="ldn-exp-filter-summary">All weights &middot; any price &middot; all metals</span></button>';
            $out .= '</div>';

            $out .= '<div class="ldn-jwl-exp-page">';
            $out .= '<aside class="ldn-jwl-filters" id="ldn-exp-filters" aria-label="Filters">';
            $out .= '<div class="ldn-jwl-filters__head"><strong>Filters</strong>'
                . '<button type="button" class="ldn-jwl-filters__x" id="ldn-exp-close-filters" aria-label="Close filters">&times;</button></div>';
            $out .= $this->explorer_seg_html($is_lab, $nat_url, $lab_url, true);

            $initial_band = !empty($explorer['initial_band']) && isset($explorer['bands'][$explorer['initial_band']])
                ? (string) $explorer['initial_band']
                : (string) $explorer['band_order'][0];
            $band_idx = array_search($initial_band, $explorer['band_order'], true);
            if ($band_idx === false) {
                $band_idx = 0;
            }
            $band_max = max(1, count($explorer['band_order']) - 1);
            $b_stats = $explorer['bands'][$initial_band]['stats'];
            $b_min = (float) ($b_stats['min_price'] ?? 0);
            $b_max = (float) ($b_stats['max_price'] ?? 0);

            $out .= '<div class="ldn-jwl-controls">';
            $out .= '<div>';
            $out .= '<div class="ldn-jwl-control__label"><span>Total diamond weight</span><strong id="ldn-exp-carat-out">'
                . esc_html($initial_band . ' ct') . '</strong></div>';
            $out .= '<div class="ldn-jwl-dual" id="ldn-exp-carat-dual" style="--lo:' . round($band_idx / $band_max, 4) . ';--hi:' . round($band_idx / $band_max, 4) . '">';
            $out .= '<span class="ldn-jwl-dual__fill"></span>';
            $out .= '<input type="range" class="ldn-jwl-dual__in" id="ldn-exp-carat-lo" min="0" max="' . $band_max . '" step="1" value="' . (int) $band_idx . '" aria-label="Smallest total weight" />';
            $out .= '<input type="range" class="ldn-jwl-dual__in" id="ldn-exp-carat-hi" min="0" max="' . $band_max . '" step="1" value="' . (int) $band_idx . '" aria-label="Largest total weight" />';
            $out .= '</div>';
            $out .= '<div class="ldn-jwl-stops" id="ldn-exp-carat-stops">';
            foreach ($explorer['band_order'] as $i => $b_lbl) {
                $at = count($explorer['band_order']) > 1 ? ($i / (count($explorer['band_order']) - 1)) : 0;
                $cur = ($b_lbl === $initial_band) ? ' aria-current="true" data-in="true"' : '';
                $out .= '<button type="button" class="ldn-jwl-stop" data-band="' . esc_attr($b_lbl) . '" style="--at:' . round($at, 4) . '"' . $cur . '>'
                    . esc_html($b_lbl) . '</button>';
            }
            $out .= '</div></div>';

            $out .= '<div>';
            $out .= '<div class="ldn-jwl-control__label"><span>Price</span><strong id="ldn-exp-budget-out">'
                . esc_html($this->usd($b_min) . ' to ' . $this->usd($b_max)) . '</strong></div>';
            $out .= '<div class="ldn-jwl-dual" id="ldn-exp-price-dual" style="--lo:0;--hi:1">';
            $out .= '<span class="ldn-jwl-dual__fill"></span>';
            $out .= '<input type="range" class="ldn-jwl-dual__in" id="ldn-exp-price-lo" min="0" max="1000" step="1" value="0" aria-label="Lowest price" />';
            $out .= '<input type="range" class="ldn-jwl-dual__in" id="ldn-exp-price-hi" min="0" max="1000" step="1" value="1000" aria-label="Highest price" />';
            $out .= '</div>';
            $out .= '<div class="ldn-jwl-budget-ends"><span id="ldn-exp-budget-min">' . esc_html($this->usd($b_min))
                . '</span><span id="ldn-exp-budget-max">' . esc_html($this->usd($b_max)) . '</span></div>';
            $out .= '</div>';

            $out .= '<div class="ldn-jwl-metals">';
            $out .= '<div class="ldn-jwl-control__label"><span>Metal</span></div>';
            $out .= '<div class="ldn-jwl-metals__row" id="ldn-exp-metals" role="group" aria-label="Metal">';
            foreach (array('white_gold', 'yellow_gold', 'rose_gold', 'platinum') as $m) {
                $out .= '<button type="button" class="ldn-jwl-metal" aria-pressed="true" data-metal="' . esc_attr($m) . '">'
                    . '<span class="ldn-jwl-metal__swatch" style="--sw: var(--ldn-metal-' . esc_attr($m) . ')"></span>'
                    . '<span class="ldn-jwl-metal__name">' . esc_html(self::METAL_LABELS[$m]) . '</span>'
                    . '<span class="ldn-jwl-metal__icon" aria-hidden="true">&times;</span></button>';
            }
            $out .= '</div>';
            $out .= '<div class="ldn-jwl-metals__hint" id="ldn-exp-metals-hint">Showing all metals. Tap one to hide it.</div>';
            $out .= '</div>';
            $out .= '</div>';

            $out .= '<button type="button" class="ldn-jwl-filters__done" id="ldn-exp-done-filters">Show ' . esc_html($piece_plural) . '</button>';
            $out .= '</aside>';

            $out .= '<div class="ldn-jwl-results">';
            $out .= '<section class="ldn-jwl-tool" id="ldn-exp-tool" aria-label="Price spread">';
            $out .= '<button type="button" class="ldn-jwl-spread-toggle" id="ldn-exp-spread-toggle" aria-controls="ldn-exp-spread" aria-expanded="false">See the price spread</button>';
            $out .= '<div class="ldn-jwl-spread" id="ldn-exp-spread">';
            $out .= $this->server_single_band_svg($explorer, $initial_band, $piece_noun);
            $out .= '<div class="ldn-jwl-legend"><span><i class="k-dot"></i>One ' . esc_html($piece_noun) . '</span>'
                . '<span><i class="k-band"></i>Typical price for that weight</span>'
                . '<span><i class="k-budget"></i>Your price range</span></div>';
            $out .= '</div>';
            $out .= '<div class="ldn-jwl-tip" id="ldn-exp-tip" hidden></div>';
            $out .= '</section>';

            $out .= $this->explorer_by_carat_html($ctx, $explorer, $sibling_explorer, $summary, $surface);
        } else {
            $out .= '<div class="ldn-jwl-results">';
            if ($lab_url !== '') {
                $out .= '<div class="ldn-jwl-exp-static-switch">' . $this->explorer_seg_html($is_lab, $nat_url, $lab_url, true) . '</div>';
            }
        }

        $featured_cnt = isset($summary['featured_count']) && (int) $summary['featured_count'] > 0
            ? (int) $summary['featured_count']
            : 24;
        $phone_cnt = isset($summary['featured_phone_count']) && (int) $summary['featured_phone_count'] > 0
            ? (int) $summary['featured_phone_count']
            : 12;
        $shown_cards = array_slice($cards, 0, $featured_cnt);

        $out .= '<h2 class="ldn-jwl-exp-h2" id="ldn-exp-grid-title">' . esc_html(ucfirst($origin_noun) . ' ' . $piece_plural) . '</h2>';
        $out .= '<div class="ldn-jwl-lede"><span id="ldn-exp-count">' . count($cards) . ' designs</span>';
        $out .= '<label>Sort <select id="ldn-exp-sort">'
            . '<option value="spread">Our picks</option>'
            . '<option value="low">Price: low to high</option>'
            . '<option value="high">Price: high to low</option>'
            . '<option value="carat_low">Carat: low to high</option>'
            . '<option value="carat_high">Carat: high to low</option>'
            . '</select></label></div>';

        $out .= '<div class="ldn-jwl-grid" id="ldn-exp-grid">';
        foreach ($shown_cards as $idx => $c) {
            $out .= $this->explorer_card_html($c, $hero, $retailer_names, $idx >= $phone_cnt, $idx < 2);
        }
        $out .= '</div>';

        $left = max(0, count($cards) - count($shown_cards));
        $out .= '<button class="ldn-jwl-more" id="ldn-exp-more" type="button"' . ($left > 0 ? '' : ' hidden') . '>'
            . esc_html(sprintf('Show more %s (%d more)', $piece_plural, $left)) . '</button>';

        if ($has_explorer) {
            $out .= '</div></div>'; // .ldn-jwl-results + .ldn-jwl-exp-page
        } else {
            $out .= '</div>';
        }

        // Quality Grid Breakdown Tables (3x3 Color x Clarity lead + Metal / Setting secondary)
        $out .= $this->quality_led_tables_html($ctx, $summary, $quality_grid);

        // Natural vs Lab-Grown comparison table & Methodology
        $out .= $this->quality_led_footer_html($ctx, $copy, $lab_comp, $nat_url, $lab_url);

        if ($has_explorer) {
            $exp_payload = array(
                'mode'        => 'explorer',
                'initialType' => $is_lab ? 'lab' : 'natural',
                'pieceNoun'   => $piece_noun,
                'piecePlural' => $piece_plural,
                'checkedDate' => $checked_date,
                'naturalUrl'  => $nat_url,
                'labUrl'      => $lab_url,
                'natural'     => !$is_lab ? $explorer : $sibling_explorer,
                'lab'         => $is_lab ? $explorer : $sibling_explorer,
            );
            $out .= '<script type="application/json" id="ldn-jewellery-payload">'
                . wp_json_encode($exp_payload, self::JSON_SCRIPT_FLAGS)
                . '</script>';
        }

        $out .= '</div>';
        return $out;
    }

    private function explorer_seg_html($is_lab, $nat_url, $lab_url, $rail) {
        if ($lab_url === '') {
            return '';
        }
        $cls = 'ldn-jwl-seg' . ($rail ? ' ldn-jwl-seg--rail' : '');
        $nat_txt = $rail ? 'Natural diamonds' : 'Natural';
        $lab_txt = $rail ? 'Lab-grown diamonds' : 'Lab-grown';
        $nat_cur = !$is_lab ? ' aria-current="page"' : '';
        $lab_cur = $is_lab ? ' aria-current="page"' : '';
        return '<nav class="' . esc_attr($cls) . '" aria-label="Diamond type">'
            . '<a class="ldn-jwl-seg__btn" href="' . esc_url($nat_url) . '" data-type="natural"' . $nat_cur . '>' . esc_html($nat_txt) . '</a>'
            . '<a class="ldn-jwl-seg__btn" href="' . esc_url($lab_url) . '" data-type="lab"' . $lab_cur . '>' . esc_html($lab_txt) . '</a>'
            . '</nav>';
    }

    private function server_single_band_svg(array $explorer, $band, $piece_noun) {
        $s = isset($explorer['bands'][$band]['stats']) ? $explorer['bands'][$band]['stats'] : array();
        $min = (float) ($s['min_price'] ?? 0);
        $max = max($min + 1.0, (float) ($s['max_price'] ?? 1));
        $p25 = (float) ($s['p25_price'] ?? $min);
        $p75 = (float) ($s['p75_price'] ?? $max);
        $med = (float) ($s['median_price'] ?? $min);

        $w = 800.0;
        $h = 110.0;
        $pad = 14.0;
        $base = $h - 26.0;
        $x = function ($v) use ($min, $max, $w, $pad) {
            $pos = max(0.0, min(1.0, ($v - $min) / max(1.0, $max - $min)));
            return round($pad + $pos * ($w - 2.0 * $pad), 1);
        };

        $svg = '<svg class="ldn-jwl-picture" id="ldn-exp-picture" viewBox="0 0 800 110" role="img" aria-label="'
            . esc_attr('Each dot is one ' . $piece_noun . ' at this weight') . '">';
        $svg .= '<rect x="' . $x($p25) . '" y="10" width="' . max(2.0, $x($p75) - $x($p25)) . '" height="' . ($base - 10) . '" fill="var(--ldn-jwl-band)" rx="4" />';
        $svg .= '<line x1="' . $x($med) . '" x2="' . $x($med) . '" y1="6" y2="' . $base . '" stroke="var(--ldn-text)" stroke-width="1.5" />';
        $svg .= '<line x1="' . $pad . '" x2="' . ($w - $pad) . '" y1="' . $base . '" y2="' . $base . '" stroke="var(--ldn-jwl-line)" />';

        $order = isset($explorer['bands'][$band]['order']) && is_array($explorer['bands'][$band]['order'])
            ? $explorer['bands'][$band]['order']
            : array();
        $placed = array();
        foreach ($order as $k) {
            $price = isset($explorer['designs'][$k]['prices'][$band])
                ? (float) $explorer['designs'][$k]['prices'][$band]
                : null;
            if ($price === null) {
                continue;
            }
            $cx = $x($price);
            $cy = $base - 8.0;
            foreach ($placed as $pt) {
                if (abs($pt[0] - $cx) < 9.0 && abs($pt[1] - $cy) < 9.0) {
                    $cy -= 9.0;
                }
            }
            $cy = max(8.0, $cy);
            $placed[] = array($cx, $cy);
            $svg .= '<circle class="dot" data-key="' . esc_attr($k) . '" data-band="' . esc_attr($band) . '" cx="' . $cx . '" cy="' . $cy . '" r="4" />';
        }

        $svg .= '<text x="' . $pad . '" y="' . ($h - 6) . '">' . esc_html($this->usd($min)) . '</text>';
        $svg .= '<text x="' . ($w - $pad) . '" y="' . ($h - 6) . '" text-anchor="end">' . esc_html($this->usd($max)) . '</text>';
        $svg .= '<text class="median-label" x="' . $x($med) . '" y="' . ($h - 6) . '" text-anchor="middle">' . esc_html($this->usd($med)) . '</text>';
        $svg .= '</svg>';
        return $svg;
    }

    private function explorer_by_carat_html(
        LDN_Page_Context $ctx,
        array $explorer,
        $sibling_explorer,
        array $summary,
        array $surface
    ) {
        $is_lab = ($ctx->diamond_type === 'lab-grown');
        $nat_exp = !$is_lab ? $explorer : (is_array($sibling_explorer) ? $sibling_explorer : array());
        $lab_exp = $is_lab ? $explorer : (is_array($sibling_explorer) ? $sibling_explorer : array());
        $order = !empty($explorer['band_order']) ? $explorer['band_order'] : array();
        if ($order === array()) {
            return '';
        }

        $category = isset($summary['jewelry_category']) ? (string) $summary['jewelry_category'] : '';
        $cat_slug = isset(self::CATEGORY_SLUGS[$category]) ? self::CATEGORY_SLUGS[$category] : $category;
        $style = isset($summary['jewelry_style']) && $summary['jewelry_style'] !== 'ALL'
            ? (string) $summary['jewelry_style']
            : (isset($surface['default_styles'][$category]) ? (string) $surface['default_styles'][$category] : '');
        $style_slug = isset(self::STYLE_SLUGS[$style]) ? self::STYLE_SLUGS[$style] : '';

        $out = '<details class="ldn-jwl-by-carat"><summary>Prices by carat weight</summary>';
        $out .= '<table><caption class="ldn-sr-only">Prices by total diamond weight, natural and lab-grown</caption>';
        $out .= '<thead><tr><th>Total weight</th><th>Natural diamonds</th><th>Lab-grown diamonds</th><th class="col-designs">Designs</th></tr></thead>';
        $out .= '<tbody id="ldn-exp-by-carat">';

        foreach ($order as $b) {
            $ns = isset($nat_exp['bands'][$b]['stats']) ? $nat_exp['bands'][$b]['stats'] : null;
            $ls = isset($lab_exp['bands'][$b]['stats']) ? $lab_exp['bands'][$b]['stats'] : null;
            $n_str = is_array($ns) ? ($this->usd((float) $ns['p25_price']) . ' to ' . $this->usd((float) $ns['p75_price'])) : '-';
            $l_str = is_array($ls) ? ($this->usd((float) $ls['p25_price']) . ' to ' . $this->usd((float) $ls['p75_price'])) : '-';
            $cnt = (is_array($ns) ? (int) $ns['design_count'] : 0) + (is_array($ls) ? (int) $ls['design_count'] : 0);

            $slug = $this->carat_slug_from_label($b);
            $href = ($cat_slug !== '' && $style_slug !== '' && $slug !== '')
                ? $this->build_jewellery_url($ctx->site_id, $ctx->country_code, $ctx->diamond_type, $cat_slug . '/' . $style_slug . '/' . $slug)
                : '';
            $lbl = str_replace('-', ' to ', (string) $b) . ' carat';
            $cell = $href !== '' ? '<a href="' . esc_url($href) . '">' . esc_html($lbl) . '</a>' : esc_html($lbl);

            $out .= '<tr data-band="' . esc_attr($b) . '"><td>' . $cell . '</td><td>' . esc_html($n_str) . '</td><td>' . esc_html($l_str) . '</td><td class="col-designs">' . $cnt . '</td></tr>';
        }
        $out .= '</tbody></table></details>';
        return $out;
    }

    private function explorer_card_html(array $card, array $hero, array $retailer_names, $desktop_only, $eager_img) {
        $code = isset($card['retailer_code']) ? (string) $card['retailer_code'] : '';
        $house = isset($retailer_names[$code]) ? $retailer_names[$code] : $code;
        $key = $code . '|' . (string) ($card['design_key'] ?? '');
        $price = (float) ($card['price'] ?? 0);
        $title = (string) ($card['product_title'] ?? '');
        $img = (string) ($card['image_url'] ?? '');
        $url = (string) ($card['product_url'] ?? '');
        $pos = (string) ($card['price_position'] ?? 'typical');

        $pos_labels = array(
            'below_typical' => 'Value price',
            'typical'       => 'Typical price',
            'above_typical' => 'Premium price',
        );
        $pos_txt = isset($pos_labels[$pos]) ? $pos_labels[$pos] : 'Typical price';

        $min = (float) ($hero['min_price'] ?? 0);
        $max = max($min + 1.0, (float) ($hero['max_price'] ?? 1));
        $span = max(1.0, $max - $min);
        $lo_pct = max(0.0, min(100.0, (((float) ($hero['p25_price'] ?? $min) - $min) / $span) * 100.0));
        $hi_pct = max(0.0, min(100.0, (((float) ($hero['p75_price'] ?? $max) - $min) / $span) * 100.0));
        $me_pct = max(0.0, min(100.0, (($price - $min) / $span) * 100.0));

        $metals = isset($card['metals_available']) && is_array($card['metals_available']) ? $card['metals_available'] : array();
        $carats = isset($card['carats_available']) && is_array($card['carats_available']) ? $card['carats_available'] : array();

        $out = '<article class="ldn-jwl-card" data-key="' . esc_attr($key) . '"' . ($desktop_only ? ' data-desktop-only' : '') . '>';
        $out .= '<div class="ldn-jwl-card__media">';
        $out .= '<a href="' . esc_url($url) . '" target="_blank" rel="sponsored noopener" class="ldn-jwl-card__img">';
        if ($img !== '') {
            $loading = $eager_img ? 'eager" fetchpriority="high' : 'lazy';
            $out .= '<img src="' . esc_url($img) . '" alt="' . esc_attr($title) . '" width="400" height="400" decoding="async" loading="' . $loading . '" />';
        } else {
            $out .= '<span class="ldn-jwl-card__noimg">No photo from retailer</span>';
        }
        $out .= '</a>';
        if ($pos === 'above_typical') {
            $why_id = 'why-' . preg_replace('/[^a-zA-Z0-9_\-]/', '-', $key);
            $out .= '<div class="ldn-jwl-card__badge">'
                . '<button type="button" class="ldn-jwl-pill ldn-jwl-pill--why" aria-expanded="false" aria-describedby="' . esc_attr($why_id) . '">'
                . esc_html($pos_txt) . '</button>'
                . '<span class="ldn-jwl-pill__tip" id="' . esc_attr($why_id) . '" role="tooltip">Often fewer, larger diamonds or a heavier setting</span>'
                . '</div>';
        } else {
            $out .= '<span class="ldn-jwl-pill' . ($pos === 'typical' ? ' ldn-jwl-pill--typical' : '') . '">' . esc_html($pos_txt) . '</span>';
        }
        $out .= '</div>';

        $out .= '<div class="ldn-jwl-card__priceline"><span class="ldn-jwl-card__price">' . esc_html($this->usd($price)) . '</span>'
            . '<span class="ldn-jwl-meter" title="' . esc_attr($pos_txt) . '">'
            . '<span class="ldn-jwl-meter__band" style="left:' . round($lo_pct, 1) . '%;width:' . round(max(2.0, $hi_pct - $lo_pct), 1) . '%"></span>'
            . '<span class="ldn-jwl-meter__dot" style="left:' . round($me_pct, 1) . '%"></span>'
            . '</span></div>';

        $out .= '<a href="' . esc_url($url) . '" target="_blank" rel="sponsored noopener" class="ldn-jwl-card__title">' . esc_html($title) . '</a>';

        if ($metals !== array()) {
            $names = array();
            $sw = '';
            foreach (self::METAL_ORDER as $m) {
                if (!in_array($m, $metals, true) || !isset(self::METAL_LABELS[$m])) {
                    continue;
                }
                $names[] = strtolower(self::METAL_LABELS[$m]);
                $sw .= '<span class="ldn-jwl-swatch" style="--sw: var(--ldn-metal-' . esc_attr($m) . ')" title="' . esc_attr(self::METAL_LABELS[$m]) . '"></span>';
            }
            if ($sw !== '') {
                $out .= '<div class="ldn-jwl-card__row"><span>Metals</span>'
                    . '<span class="ldn-jwl-card__vals ldn-jwl-card__metals" tabindex="0" data-names="' . esc_attr('Available in ' . implode(', ', $names)) . '">'
                    . $sw . '</span></div>';
            }
        }
        if ($carats !== array()) {
            $wt = '';
            foreach ($carats as $idx => $b) {
                $wt .= '<span class="ldn-jwl-wt"' . ($idx === 0 ? ' aria-current="true"' : '') . '>' . esc_html((string) $b) . '</span>';
            }
            $out .= '<div class="ldn-jwl-card__row"><span>Carats</span><span class="ldn-jwl-card__vals">' . $wt . '</span></div>';
        }
        $out .= '<a href="' . esc_url($url) . '" target="_blank" rel="sponsored noopener" class="ldn-jwl-card__cta">'
            . esc_html('View at ' . $house) . '</a>';
        $out .= '</article>';
        return $out;
    }

    private function quality_led_tables_html(LDN_Page_Context $ctx, array $summary, array $quality_grid) {
        $lead_id = isset($quality_grid['lead_table']) && is_string($quality_grid['lead_table'])
            ? $quality_grid['lead_table']
            : '';
        $tables = isset($quality_grid['tables']) && is_array($quality_grid['tables'])
            ? $quality_grid['tables']
            : array();
        if ($tables === array()) {
            return '';
        }

        $ordered_ids = array();
        if ($lead_id !== '' && !empty($tables[$lead_id])) {
            $ordered_ids[] = $lead_id;
        }
        foreach (array_keys($tables) as $tid) {
            if (!in_array($tid, $ordered_ids, true) && !empty($tables[$tid])) {
                $ordered_ids[] = $tid;
            }
        }
        if ($ordered_ids === array()) {
            return '';
        }

        $titles = array(
            'color_clarity_bands'   => 'Prices by diamond color and clarity',
            'default_color_clarity' => 'Prices by diamond color and clarity',
            'metal'                 => 'Prices by precious metal',
            'setting'               => 'Prices by setting style',
            'metal_setting'         => 'Prices by metal and setting',
            'prong_comparison'      => 'Prices by prong style',
        );

        $out = '<section class="ldn-jwl-exp-tables">';
        foreach ($ordered_ids as $idx => $tid) {
            $rows = $tables[$tid];
            if (!is_array($rows) || $rows === array()) {
                continue;
            }
            $heading = isset($titles[$tid]) ? $titles[$tid] : 'Price breakdown';
            $is_lead = ($idx === 0);

            if ($is_lead) {
                $out .= '<h2 class="ldn-jwl-exp-h2">' . esc_html($heading) . '</h2>';
            } else {
                $out .= '<details class="ldn-jwl-table-accordion"><summary>' . esc_html($heading) . '</summary>';
            }

            $out .= '<div class="ldn-table-card"><table class="ldn-data-table ldn-jwl-data-table">';
            if ($tid === 'color_clarity_bands' || $tid === 'default_color_clarity') {
                $out .= '<thead><tr><th>Color</th><th>Clarity</th><th>Middle price</th><th>Typical range</th><th class="num">Designs</th></tr></thead><tbody>';
                foreach ($rows as $r) {
                    $out .= '<tr><td><strong>' . esc_html((string) ($r['color_band'] ?? '')) . '</strong></td>'
                        . '<td>' . esc_html((string) ($r['clarity_band'] ?? '')) . '</td>'
                        . '<td>' . esc_html($this->usd((float) ($r['headline_price'] ?? 0))) . '</td>'
                        . '<td>' . esc_html($this->usd((float) ($r['range_low'] ?? 0)) . ' to ' . $this->usd((float) ($r['range_high'] ?? 0))) . '</td>'
                        . '<td class="num">' . (int) ($r['design_count'] ?? 0) . '</td></tr>';
                }
            } elseif ($tid === 'metal') {
                $out .= '<thead><tr><th>Metal</th><th>Middle price</th><th>Typical range</th><th class="num">Designs</th></tr></thead><tbody>';
                foreach ($rows as $r) {
                    $metal = (string) ($r['metal'] ?? '');
                    $label = $this->metal_label($metal);
                    $cell = $this->quality_led_breakdown_label_cell(
                        $ctx,
                        $summary,
                        $label,
                        $this->jewellery_facet_path_for_metal($summary, $metal)
                    );
                    $out .= '<tr><td>' . $cell . '</td>'
                        . '<td>' . esc_html($this->usd((float) ($r['headline_price'] ?? 0))) . '</td>'
                        . '<td>' . esc_html($this->usd((float) ($r['range_low'] ?? 0)) . ' to ' . $this->usd((float) ($r['range_high'] ?? 0))) . '</td>'
                        . '<td class="num">' . (int) ($r['design_count'] ?? 0) . '</td></tr>';
                }
            } else {
                $out .= '<thead><tr><th>Setting</th><th>Middle price</th><th>Typical range</th><th class="num">Designs</th></tr></thead><tbody>';
                foreach ($rows as $r) {
                    $setting = (string) ($r['setting'] ?? '');
                    $label = $this->setting_label($setting);
                    $cell = $this->quality_led_breakdown_label_cell(
                        $ctx,
                        $summary,
                        $label,
                        $this->jewellery_facet_path_for_setting($summary, $setting)
                    );
                    $out .= '<tr><td>' . $cell . '</td>'
                        . '<td>' . esc_html($this->usd((float) ($r['headline_price'] ?? 0))) . '</td>'
                        . '<td>' . esc_html($this->usd((float) ($r['range_low'] ?? 0)) . ' to ' . $this->usd((float) ($r['range_high'] ?? 0))) . '</td>'
                        . '<td class="num">' . (int) ($r['design_count'] ?? 0) . '</td></tr>';
                }
            }
            $out .= '</tbody></table></div>';
            if (!$is_lead) {
                $out .= '</details>';
            }
        }
        $out .= '</section>';
        return $out;
    }

    private function quality_led_footer_html(LDN_Page_Context $ctx, array $copy, $lab_comp, $nat_url, $lab_url) {
        $out = '<section class="ldn-jwl-exp-notes">';
        if (is_array($lab_comp) && isset($lab_comp['natural']['median_price']) && isset($lab_comp['lab']['median_price'])) {
            $nh = $lab_comp['natural'];
            $lh = $lab_comp['lab'];
            $out .= '<h2 class="ldn-jwl-exp-h2">Natural vs lab-grown prices</h2>';
            $out .= '<div class="ldn-table-card"><table class="ldn-data-table ldn-jwl-data-table">';
            $out .= '<thead><tr><th>Diamond type</th><th>Middle price</th><th>Typical range</th><th class="num">Designs</th></tr></thead><tbody>';
            $out .= '<tr><td><strong><a href="' . esc_url($nat_url) . '">Natural diamonds</a></strong></td>'
                . '<td>' . esc_html($this->usd((float) $nh['median_price'])) . '</td>'
                . '<td>' . esc_html($this->usd((float) $nh['p25_price']) . ' to ' . $this->usd((float) $nh['p75_price'])) . '</td>'
                . '<td class="num">' . (int) $nh['design_count'] . '</td></tr>';
            $out .= '<tr><td><strong><a href="' . esc_url($lab_url) . '">Lab-grown diamonds</a></strong></td>'
                . '<td>' . esc_html($this->usd((float) $lh['median_price'])) . '</td>'
                . '<td>' . esc_html($this->usd((float) $lh['p25_price']) . ' to ' . $this->usd((float) $lh['p75_price'])) . '</td>'
                . '<td class="num">' . (int) $lh['design_count'] . '</td></tr>';
            $out .= '</tbody></table></div>';
        }

        $method = isset($copy['methodology']) && is_array($copy['methodology']) ? $copy['methodology'] : array();
        if ($method !== array()) {
            $out .= '<h2 class="ldn-jwl-exp-h2">How we measure these prices</h2><ul class="ldn-jwl-method-list">';
            foreach ($method as $line) {
                if (is_string($line) && trim($line) !== '') {
                    $out .= '<li>' . esc_html($this->us_english($line)) . '</li>';
                }
            }
            $out .= '</ul>';
        }
        $out .= '</section>';
        return $out;
    }

    // =========================================================================
    // Hub (`/us/diamond-jewelry-prices/` and `/us/jewelry/`)
    // =========================================================================

    private function render_hub(
        LDN_Page_Context $ctx,
        array $summary,
        array $quality_grid,
        array $copy,
        $lab_comp,
        $treatment,
        array $surface
    ) {
        $is_atelier = ($treatment === 'metal_setting_led');
        $is_lab = ($ctx->diamond_type === 'lab-grown');
        $framing = isset($surface['price_framing']) ? (string) $surface['price_framing'] : 'median_p25_p75';
        $nat_url = $this->build_jewellery_url($ctx->site_id, $ctx->country_code, 'natural', 'hub');
        $lab_url = !empty($surface['publish_lab_tree'])
            ? $this->build_jewellery_url($ctx->site_id, $ctx->country_code, 'lab-grown', 'hub')
            : '';

        $wrap_cls = $is_atelier ? 'ldn-jwl-wrap' : 'ldn-jwl-exp-wrap ldn-jwl-layout-b';
        $out = '<div class="' . esc_attr($wrap_cls) . '">';
        if ($is_atelier) {
            $out .= '<div class="ldn-jwl-top-utility">' . $this->breadcrumb_html($ctx, $summary, '/');
            if ($lab_url !== '') {
                $out .= '<nav class="ldn-jwl-origin-switch" aria-label="Diamond origin">'
                    . '<a class="ldn-jwl-origin-btn" href="' . esc_url($nat_url) . '"' . (!$is_lab ? ' aria-current="page"' : '') . '>Natural</a>'
                    . '<a class="ldn-jwl-origin-btn" href="' . esc_url($lab_url) . '"' . ($is_lab ? ' aria-current="page"' : '') . '>Lab-Grown</a>'
                    . '</nav>';
            }
            $out .= '</div>';
            $out .= '<header class="ldn-jwl-hero-band"><div><h1 class="ldn-jwl-title">' . esc_html($this->page_title($ctx, $summary)) . '</h1>';
            $out .= '<p class="ldn-jwl-scope-line">Retail benchmark prices across stud earrings, tennis bracelets and diamond necklaces.</p></div></header>';
        } else {
            $out .= $this->breadcrumb_html($ctx, $summary, '›');
            $out .= '<h1 class="ldn-jwl-exp-h1">' . esc_html($this->page_title($ctx, $summary)) . '</h1>';
            if ($lab_url !== '') {
                $out .= '<div class="ldn-jwl-exp-static-switch">' . $this->explorer_seg_html($is_lab, $nat_url, $lab_url, true) . '</div>';
            }
        }

        // Category cards
        $cat_defs = array(
            'earrings'  => array('slug' => 'earrings',  'title' => 'Diamond Earrings',  'sub' => 'Studs, hoops, huggies and drops by total carat weight'),
            'bracelet'  => array('slug' => 'bracelets', 'title' => 'Diamond Bracelets', 'sub' => 'Tennis and bangle bracelets from 1 ct to 10 ct+'),
            'necklace'  => array('slug' => 'necklaces', 'title' => 'Diamond Necklaces', 'sub' => 'Solitaire pendants, station and tennis necklaces'),
        );
        $comp_by_cat = array();
        if (is_array($lab_comp) && !empty($lab_comp['categories']) && is_array($lab_comp['categories'])) {
            foreach ($lab_comp['categories'] as $entry) {
                if (is_array($entry) && !empty($entry['jewelry_category'])) {
                    $comp_by_cat[(string) $entry['jewelry_category']] = $entry;
                }
            }
        }

        $out .= '<div class="ldn-jwl-hub-grid">';
        foreach ($cat_defs as $cat_key => $def) {
            $url = $this->build_jewellery_url($ctx->site_id, $ctx->country_code, $ctx->diamond_type, $def['slug']);
            $entry = isset($comp_by_cat[$cat_key]) ? $comp_by_cat[$cat_key] : null;
            $block = is_array($entry) ? ($is_lab ? ($entry['lab'] ?? null) : ($entry['natural'] ?? null)) : null;

            $out .= '<a class="ldn-jwl-hub-card" href="' . esc_url($url) . '">';
            $out .= '<h2>' . esc_html($def['title']) . '</h2>';
            $out .= '<p>' . esc_html($def['sub']) . '</p>';
            if (is_array($block)) {
                $hl = $this->hero_headline_price($block, $framing);
                $lbl = ($framing === 'mean_min_max' || $framing === 'mean_p10_p90') ? 'Average' : 'Middle price';
                $out .= '<div class="ldn-jwl-hub-card__stat"><span>' . esc_html($lbl . ' ' . $this->usd($hl)) . '</span>'
                    . '<span>' . (int) ($block['design_count'] ?? 0) . ' designs</span></div>';
            }
            $out .= '</a>';
        }
        $out .= '</div>';

        $out .= $is_atelier
            ? $this->atelier_notes_html($ctx, $summary, $copy, null, $nat_url, $lab_url)
            : $this->quality_led_footer_html($ctx, $copy, null, $nat_url, $lab_url);
        $out .= '</div>';
        return $out;
    }

    // =========================================================================
    // Schema.org JSON-LD, Breadcrumbs, Ads & Helpers
    // =========================================================================

    private function breadcrumb_html(LDN_Page_Context $ctx, array $summary, $sep) {
        $items = $this->breadcrumb_items($ctx, $summary);
        $cls = ($sep === '/') ? 'ldn-jwl-crumbs' : 'ldn-jwl-exp-crumbs';
        $out = '<nav class="' . esc_attr($cls) . '" aria-label="Breadcrumb"><ol>';
        $last = count($items) - 1;
        foreach ($items as $idx => $item) {
            if ($idx === $last || empty($item['url'])) {
                $out .= '<li aria-current="page">' . esc_html($item['name']) . '</li>';
            } else {
                $out .= '<li><a href="' . esc_url($item['url']) . '">' . esc_html($item['name']) . '</a></li>';
            }
        }
        $out .= '</ol></nav>';
        return $out;
    }

    private function breadcrumb_items(LDN_Page_Context $ctx, array $summary) {
        $items = array();
        $home = function_exists('home_url') ? home_url('/') : '/';
        $items[] = array('name' => 'Home', 'url' => $home);

        $is_lab = ($ctx->diamond_type === 'lab-grown');
        $hub_label = $is_lab ? 'Lab-Grown Jewelry' : 'Fine Jewelry';
        $hub_url = $this->build_jewellery_url($ctx->site_id, $ctx->country_code, $ctx->diamond_type, 'hub');
        $path = trim((string) $ctx->jewelry_path, '/');

        if ($path === '' || $path === 'hub') {
            $items[] = array('name' => $hub_label, 'url' => '');
            return $items;
        }
        $items[] = array('name' => $hub_label, 'url' => $hub_url);

        $parts = explode('/', $path);
        if ($parts[0] === 'mens') {
            array_shift($parts);
        }
        $cat_slug = isset($parts[0]) ? $parts[0] : '';
        if ($cat_slug !== '' && isset(self::CATEGORY_LABELS[$cat_slug])) {
            $cat_url = $this->build_jewellery_url($ctx->site_id, $ctx->country_code, $ctx->diamond_type, $cat_slug);
            if (count($parts) === 1) {
                $items[] = array('name' => self::CATEGORY_LABELS[$cat_slug], 'url' => '');
                return $items;
            }
            $items[] = array('name' => self::CATEGORY_LABELS[$cat_slug], 'url' => $cat_url);
        }

        if (count($parts) > 2 && isset($summary['jewelry_style']) && isset(self::STYLE_SLUGS[$summary['jewelry_style']])) {
            $st_key = $summary['jewelry_style'];
            $st_url = $this->build_jewellery_url(
                $ctx->site_id,
                $ctx->country_code,
                $ctx->diamond_type,
                $cat_slug . '/' . self::STYLE_SLUGS[$st_key]
            );
            $items[] = array('name' => self::STYLE_LABELS[$st_key], 'url' => $st_url);
        }

        $items[] = array('name' => $this->page_title($ctx, $summary), 'url' => '');
        return $items;
    }

    private function schema_graph(
        LDN_Page_Context $ctx,
        array $summary,
        array $quality_grid,
        $trend,
        $canonical,
        $title,
        $desc
    ) {
        if ($canonical === '') {
            return array();
        }
        $graph = array();

        $crumbs = $this->breadcrumb_items($ctx, $summary);
        $list_items = array();
        foreach ($crumbs as $i => $c) {
            $entry = array(
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $c['name'],
            );
            if (!empty($c['url'])) {
                $entry['item'] = $c['url'];
            } else {
                $entry['item'] = $canonical;
            }
            $list_items[] = $entry;
        }
        $graph[] = array(
            '@type'           => 'BreadcrumbList',
            '@id'             => $canonical . '#breadcrumb',
            'itemListElement' => $list_items,
        );

        $webpage = array(
            '@type'      => 'WebPage',
            '@id'        => $canonical . '#webpage',
            'url'        => $canonical,
            'name'       => $title,
            'breadcrumb' => array('@id' => $canonical . '#breadcrumb'),
        );
        if ($desc !== '') {
            $webpage['description'] = $desc;
        }
        if (!empty($summary['analysis_date'])) {
            $webpage['dateModified'] = (string) $summary['analysis_date'];
        }

        if (isset($summary['aggregate_offer']) && is_array($summary['aggregate_offer']) && $ctx->jewelry_path !== 'hub') {
            $ao = $summary['aggregate_offer'];
            $product = array(
                '@type'  => 'Product',
                '@id'    => $canonical . '#product',
                'name'   => $title,
                'offers' => array(
                    '@type'         => 'AggregateOffer',
                    'lowPrice'      => $ao['lowPrice'],
                    'highPrice'     => $ao['highPrice'],
                    'offerCount'    => (int) $ao['offerCount'],
                    'priceCurrency' => (string) ($ao['priceCurrency'] ?? 'USD'),
                ),
            );
            $first_img = $this->first_card_image($summary);
            if ($first_img !== '') {
                $product['image'] = $first_img;
            }
            $graph[] = $product;
            $webpage['mainEntity'] = array('@id' => $canonical . '#product');
        }

        $tables = isset($quality_grid['tables']) && is_array($quality_grid['tables']) ? $quality_grid['tables'] : array();
        $has_visible_table = false;
        foreach ($tables as $rows) {
            if (is_array($rows) && count($rows) > 0) {
                $has_visible_table = true;
                break;
            }
        }
        if ($has_visible_table) {
            $dataset = array(
                '@type'       => 'Dataset',
                '@id'         => $canonical . '#dataset',
                'name'        => $title . ' Price Index',
                'description' => $desc !== '' ? $desc : ($title . ' retail price benchmark.'),
                'url'         => $canonical,
            );
            if (!empty($summary['analysis_date'])) {
                $dataset['dateModified'] = (string) $summary['analysis_date'];
            }
            // Omit trend measurement when status === 'too_new' so schema matches visible page.
            if (is_array($trend) && isset($trend['status']) && $trend['status'] === 'ok') {
                $dataset['variableMeasured'] = array('Retail price', 'Weekly price history');
            } else {
                $dataset['variableMeasured'] = array('Retail price');
            }
            $graph[] = $dataset;
        }

        $graph[] = $webpage;
        return array(
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        );
    }

    private function trend_section_html(array $trend, $label) {
        $pts = isset($trend['points']) && is_array($trend['points']) ? $trend['points'] : array();
        if (count($pts) < 2) {
            return '';
        }
        $out = '<section class="ldn-jwl-section-tables"><h2>Weekly Price History</h2>';
        $out .= '<table class="ldn-jwl-atelier-table"><thead><tr><th>Week</th><th class="num">' . esc_html($label) . '</th></tr></thead><tbody>';
        foreach ($pts as $pt) {
            $out .= '<tr><td>' . esc_html((string) ($pt['analysis_date'] ?? '')) . '</td>'
                . '<td class="num">' . esc_html($this->usd((float) ($pt['headline_price'] ?? 0))) . '</td></tr>';
        }
        $out .= '</tbody></table></section>';
        return $out;
    }

    private function jewellery_ad_html(LDN_Page_Context $ctx) {
        if (!class_exists('LDN_Artefacts') || !class_exists('LDN_Plugin')) {
            return '';
        }
        $artefacts = new LDN_Artefacts($this->config);
        if (!$artefacts->site_has_wp_widget($ctx->site_id, 'jewellery_ads', 'jewellery')) {
            return '';
        }
        $ad_system = LDN_Plugin::instance()->ad_system();
        if ($ad_system === null || !method_exists($ad_system, 'render_slot')) {
            return '';
        }
        $rendered = (string) $ad_system->render_slot('jewellery_mid_content', $ctx);
        if ($rendered === '') {
            return '';
        }
        return '<aside class="ldn-jwl-ad-slot" aria-label="Sponsored jeweler">' . $rendered . '</aside>';
    }

    private function normalise_quality_grid(array $quality_grid, array $surface) {
        if (empty($quality_grid['tables']) || !is_array($quality_grid['tables'])) {
            return $quality_grid;
        }
        $framing = isset($surface['price_framing']) ? (string) $surface['price_framing'] : 'median_p25_p75';
        $raw_tables = $quality_grid['tables'];
        $map = array();

        foreach ($raw_tables as $k => $entry) {
            if (is_int($k) && is_array($entry) && !empty($entry['id']) && isset($entry['rows']) && is_array($entry['rows'])) {
                $tid = (string) $entry['id'];
                $rows = $entry['rows'];
            } elseif (is_string($k) && is_array($entry)) {
                $tid = $k;
                $rows = $entry;
            } else {
                continue;
            }
            $norm_rows = array();
            foreach ($rows as $r) {
                if (!is_array($r)) {
                    continue;
                }
                if (!isset($r['headline_price'])) {
                    $r['headline_price'] = $this->hero_headline_price($r, $framing);
                }
                if (!isset($r['range_low'])) {
                    if ($framing === 'mean_min_max') {
                        $r['range_low'] = (float) ($r['min_price'] ?? $r['p25_price'] ?? $r['headline_price']);
                    } elseif ($framing === 'mean_p10_p90') {
                        $r['range_low'] = (float) ($r['p10_price'] ?? $r['p25_price'] ?? $r['headline_price']);
                    } else {
                        $r['range_low'] = (float) ($r['p25_price'] ?? $r['headline_price']);
                    }
                }
                if (!isset($r['range_high'])) {
                    if ($framing === 'mean_min_max') {
                        $r['range_high'] = (float) ($r['max_price'] ?? $r['p75_price'] ?? $r['headline_price']);
                    } elseif ($framing === 'mean_p10_p90') {
                        $r['range_high'] = (float) ($r['p90_price'] ?? $r['p75_price'] ?? $r['headline_price']);
                    } else {
                        $r['range_high'] = (float) ($r['p75_price'] ?? $r['headline_price']);
                    }
                }
                $norm_rows[] = $r;
            }
            $map[$tid] = $norm_rows;
        }

        $quality_grid['tables'] = $map;
        return $quality_grid;
    }

    private function enrich_summary_from_explorer(LDN_Page_Context $ctx, array $summary, $explorer, array $surface) {
        $framing = isset($surface['price_framing']) ? (string) $surface['price_framing'] : 'median_p25_p75';
        if (!empty($summary['evidence']) && is_array($summary['evidence']) && count($summary['evidence']) > 2) {
            $cols = ($framing === 'mean_min_max' || $framing === 'mean_p10_p90') ? 2 : 3;
            $summary['evidence'] = $this->interleave_cards_2d($summary['evidence'], $cols);
        }
        if (!is_array($explorer)) {
            return $summary;
        }

        // Backfill carat_bands if summary predates Stage 1 J4.
        if (empty($summary['carat_bands']) && !empty($explorer['band_order']) && !empty($explorer['bands'])) {
            $bands = array();
            foreach ($explorer['band_order'] as $lbl) {
                if (empty($explorer['bands'][$lbl]['stats'])) {
                    continue;
                }
                $st = $explorer['bands'][$lbl]['stats'];
                $hl = $this->hero_headline_price($st, $framing);
                $bands[] = array(
                    'carat_label'    => (string) $lbl,
                    'carat_slug'     => $this->carat_slug_from_label((string) $lbl),
                    'headline_price' => $hl,
                    'mean_price'     => (float) ($st['mean_price'] ?? $hl),
                    'median_price'   => (float) ($st['median_price'] ?? $hl),
                    'design_count'   => (int) ($st['design_count'] ?? 0),
                );
            }
            if ($bands !== array()) {
                $summary['carat_bands'] = $bands;
            }
        }

        // Backfill by_metal on evidence cards from explorer variants if absent.
        if (!empty($summary['evidence']) && is_array($summary['evidence']) && !empty($explorer['designs'])) {
            $band_lbl = $this->current_carat_label_from_path($ctx->jewelry_path);
            if ($band_lbl === null && !empty($explorer['initial_band'])) {
                $band_lbl = (string) $explorer['initial_band'];
            }
            foreach ($summary['evidence'] as $i => $card) {
                if (!empty($card['by_metal'])) {
                    continue;
                }
                $k = (string) ($card['retailer_code'] ?? '') . '|' . (string) ($card['design_key'] ?? '');
                if (!isset($explorer['designs'][$k]['variants'])) {
                    continue;
                }
                $variants_by_band = $explorer['designs'][$k]['variants'];
                $chosen_band_vars = ($band_lbl !== null && isset($variants_by_band[$band_lbl]))
                    ? $variants_by_band[$band_lbl]
                    : reset($variants_by_band);
                if (!is_array($chosen_band_vars)) {
                    continue;
                }
                $bm = array();
                foreach ($chosen_band_vars as $m => $v) {
                    if (!is_array($v)) {
                        continue;
                    }
                    $bm[$m] = array(
                        'price'         => isset($v['price']) ? (float) $v['price'] : (float) ($card['price'] ?? 0),
                        'product_title' => (string) ($v['product_title'] ?? $card['product_title'] ?? ''),
                        'image_url'     => (string) ($v['image_url'] ?? $card['image_url'] ?? ''),
                        'product_url'   => (string) ($v['product_url'] ?? $card['product_url'] ?? ''),
                        'setting'       => '',
                        'metal_karat'   => '',
                    );
                }
                if ($bm !== array()) {
                    $summary['evidence'][$i]['by_metal'] = $bm;
                }
            }
        }

        if (!empty($summary['evidence']) && is_array($summary['evidence']) && count($summary['evidence']) > 2) {
            $cols = ($framing === 'mean_min_max' || $framing === 'mean_p10_p90') ? 2 : 3;
            $summary['evidence'] = $this->interleave_cards_2d($summary['evidence'], $cols);
        }

        return $summary;
    }

    /**
     * 2D round-robin anti-bunching so no two adjacent cards share a retailer_code
     * horizontally or vertically when alternatives remain.
     *
     * @param array<int, array> $cards
     * @param int               $cols
     * @return array<int, array>
     */
    private function interleave_cards_2d(array $cards, $cols = 2) {
        $pool = array_values($cards);
        $out = array();
        $cols = max(1, (int) $cols);
        while ($pool !== array()) {
            $idx = count($out);
            $prev_left = ($idx % $cols !== 0 && isset($out[$idx - 1]['retailer_code']))
                ? (string) $out[$idx - 1]['retailer_code']
                : '';
            $prev_above = ($idx >= $cols && isset($out[$idx - $cols]['retailer_code']))
                ? (string) $out[$idx - $cols]['retailer_code']
                : '';
            $chosen = -1;
            foreach ($pool as $i => $c) {
                $code = isset($c['retailer_code']) ? (string) $c['retailer_code'] : '';
                if ($code !== $prev_left && $code !== $prev_above) {
                    $chosen = $i;
                    break;
                }
            }
            if ($chosen < 0) {
                foreach ($pool as $i => $c) {
                    $code = isset($c['retailer_code']) ? (string) $c['retailer_code'] : '';
                    if ($code !== $prev_left) {
                        $chosen = $i;
                        break;
                    }
                }
            }
            if ($chosen < 0) {
                $chosen = 0;
            }
            $out[] = $pool[$chosen];
            array_splice($pool, $chosen, 1);
        }
        return $out;
    }

    private function fetch_sibling_explorer(LDN_Page_Context $ctx, array $surface) {
        // Dedicated /lab-grown/ routes navigate cleanly via <a href>; avoid doubling HTML payload.
        return null;
    }

    private function metal_style_block(array $surface) {
        $tokens = isset($surface['metal_tokens']) && is_array($surface['metal_tokens'])
            ? $surface['metal_tokens']
            : array();
        $decls = array();
        foreach ($tokens as $metal => $hex) {
            if (is_string($metal) && is_string($hex) && preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($hex))) {
                $safe_metal = preg_replace('/[^a-z0-9_]/', '', strtolower($metal));
                $decls[] = '--ldn-metal-' . $safe_metal . ':' . trim($hex);
            }
        }
        if ($decls === array()) {
            return '';
        }
        return '<style>.ldn-jewellery-page{' . implode(';', $decls) . '}</style>';
    }

    private function meta_description(LDN_Page_Context $ctx, array $summary) {
        $title = $this->page_title($ctx, $summary);
        $hero = isset($summary['hero']) && is_array($summary['hero']) ? $summary['hero'] : null;
        if (!is_array($hero)) {
            return $title . ' retail benchmark prices across US jewelers.';
        }
        $profile = $this->profile($ctx->site_id);
        $surface = $this->surface($profile);
        $framing = isset($surface['price_framing']) ? (string) $surface['price_framing'] : 'median_p25_p75';
        $cnt = (int) ($hero['design_count'] ?? 0);
        $ret = (int) ($hero['retailer_count'] ?? 0);

        if ($framing === 'mean_min_max' || $framing === 'mean_p10_p90') {
            $avg = $this->hero_headline_price($hero, $framing);
            $lo = (float) ($hero['min_price'] ?? $avg);
            $hi = (float) ($hero['max_price'] ?? $avg);
            return sprintf(
                '%s average %s (full range %s to %s) across %s designs from %d US jewelers.',
                $title,
                $this->usd($avg),
                $this->usd($lo),
                $this->usd($hi),
                number_format($cnt),
                $ret
            );
        }

        $med = (float) ($hero['median_price'] ?? 0);
        $p25 = (float) ($hero['p25_price'] ?? $med);
        $p75 = (float) ($hero['p75_price'] ?? $med);
        return sprintf(
            '%s typically cost %s to %s (middle price %s) across %s designs from %d US retailers.',
            $title,
            $this->usd($p25),
            $this->usd($p75),
            $this->usd($med),
            number_format($cnt),
            $ret
        );
    }

    private function advisor_price_context($price, $mean_price, $setting) {
        $ratio = (float) $price / max(1.0, (float) $mean_price);
        $avg = $this->usd($mean_price);
        if ($ratio <= 0.72) {
            return 'Opening tier · well under the ' . $avg . ' average';
        }
        if ($ratio < 0.92) {
            return 'Below the ' . $avg . ' average';
        }
        if ($ratio <= 1.10) {
            return 'Close to the ' . $avg . ' average';
        }
        if ($setting === 'bezel' || $setting === 'halo') {
            return 'Commands a setting premium';
        }
        return 'Commands a premium · heavier mount or higher spec';
    }

    private function hero_headline_price(array $hero, $framing) {
        if ($framing === 'mean_min_max' || $framing === 'mean_p10_p90') {
            if (isset($hero['mean_price'])) {
                return (float) $hero['mean_price'];
            }
        }
        if (isset($hero['headline_price'])) {
            return (float) $hero['headline_price'];
        }
        return (float) ($hero['median_price'] ?? 0);
    }

    private function first_card_image(array $summary) {
        if (empty($summary['evidence']) || !is_array($summary['evidence'])) {
            return '';
        }
        foreach ($summary['evidence'] as $c) {
            if (is_array($c) && !empty($c['image_url']) && is_string($c['image_url'])) {
                return $c['image_url'];
            }
        }
        return '';
    }

    private function carat_label_from_slug($token) {
        $map = array(
            'under-0.5-carat' => '0.25',
            '0.5-carat'       => '0.5',
            '0.75-carat'      => '0.75',
            '1-carat'         => '1',
            '1.5-carat'       => '1.5',
            '2-carat'         => '2',
            '3-carat'         => '3',
            '4-carat'         => '4',
            '5-carat'         => '5',
            '6-7-8-carat'     => '6-8',
            '10-carat-plus'   => '10+',
        );
        return isset($map[$token]) ? $map[$token] : null;
    }

    private function carat_slug_from_label($label) {
        if ($label === '0.25') {
            return 'under-0.5-carat';
        }
        if ($label === '6-8') {
            return '6-7-8-carat';
        }
        if ($label === '10+') {
            return '10-carat-plus';
        }
        return $label . '-carat';
    }

    private function current_carat_label_from_path($jewelry_path) {
        $parts = explode('/', trim((string) $jewelry_path, '/'));
        foreach ($parts as $token) {
            if ($token === '1-carat-screw-back') {
                return '1';
            }
            $lbl = $this->carat_label_from_slug($token);
            if ($lbl !== null) {
                return $lbl;
            }
        }
        return null;
    }

    private function piece_singular_noun(array $summary) {
        $cat = isset($summary['jewelry_category']) ? (string) $summary['jewelry_category'] : '';
        if ($cat === 'earrings') {
            return 'pair';
        }
        if ($cat === 'bracelet') {
            return 'bracelet';
        }
        if ($cat === 'necklace') {
            return 'necklace';
        }
        return 'piece';
    }

    private function piece_plural_noun(array $summary) {
        $style = isset($summary['jewelry_style']) ? (string) $summary['jewelry_style'] : '';
        if (isset(self::STYLE_LABELS[$style])) {
            return strtolower(self::STYLE_LABELS[$style]);
        }
        $cat = isset($summary['jewelry_category']) ? (string) $summary['jewelry_category'] : '';
        if ($cat === 'earrings') {
            return 'earrings';
        }
        if ($cat === 'bracelet') {
            return 'bracelets';
        }
        if ($cat === 'necklace') {
            return 'necklaces';
        }
        return 'designs';
    }

    /**
     * First-column label for a breakdown row; links when IA allows a standalone facet URL.
     *
     * Mirrors ``shared/jewellery/ia.py`` ``_route_spec_for_cell`` for metal and setting only.
     * Does not emit a link for the current page path or when no route exists (e.g. color bands).
     */
    private function quality_led_breakdown_label_cell(
        LDN_Page_Context $ctx,
        array $summary,
        $label,
        $facet_path
    ) {
        $facet_path = trim((string) $facet_path, '/');
        $current = trim((string) $ctx->jewelry_path, '/');
        if ($facet_path === '' || $facet_path === $current) {
            return '<strong>' . esc_html($label) . '</strong>';
        }
        $url = $this->build_jewellery_url($ctx->site_id, $ctx->country_code, $ctx->diamond_type, $facet_path);
        if ($url === '') {
            return '<strong>' . esc_html($label) . '</strong>';
        }
        return '<strong><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></strong>';
    }

    private function jewellery_facet_path_for_metal(array $summary, $metal) {
        $metal = (string) $metal;
        if (!isset(self::METAL_URL_SLUGS[$metal])) {
            return '';
        }
        $m_slug = self::METAL_URL_SLUGS[$metal];
        $cat = isset($summary['jewelry_category']) ? (string) $summary['jewelry_category'] : '';
        $cat_slug = isset(self::CATEGORY_SLUGS[$cat]) ? self::CATEGORY_SLUGS[$cat] : '';
        if ($cat_slug === '') {
            return '';
        }
        $style = isset($summary['jewelry_style']) ? (string) $summary['jewelry_style'] : '';
        if ($style === 'ALL') {
            $style = '';
        }
        if ($cat === 'earrings' && $style === 'stud') {
            return $cat_slug . '/studs/' . $m_slug;
        }
        if ($cat === 'bracelet' && $style === 'tennis') {
            return $cat_slug . '/tennis/' . $m_slug;
        }
        if ($cat === 'necklace' && $style === '') {
            return $cat_slug . '/' . $m_slug;
        }
        return '';
    }

    private function jewellery_facet_path_for_setting(array $summary, $setting) {
        $setting = (string) $setting;
        if (!isset(self::SETTING_URL_SLUGS[$setting])) {
            return '';
        }
        $s_slug = self::SETTING_URL_SLUGS[$setting];
        $cat = isset($summary['jewelry_category']) ? (string) $summary['jewelry_category'] : '';
        $cat_slug = isset(self::CATEGORY_SLUGS[$cat]) ? self::CATEGORY_SLUGS[$cat] : '';
        if ($cat_slug === '') {
            return '';
        }
        $style = isset($summary['jewelry_style']) ? (string) $summary['jewelry_style'] : '';
        if ($style === 'ALL') {
            $style = '';
        }
        $style_slug = ($style !== '' && isset(self::STYLE_SLUGS[$style])) ? self::STYLE_SLUGS[$style] : '';
        if ($style_slug === '') {
            return '';
        }
        $allows = array(
            'earrings' => array('stud'),
            'bracelet' => array('tennis'),
            'necklace' => array('solitaire_pendant', 'tennis'),
        );
        if (!isset($allows[$cat]) || !in_array($style, $allows[$cat], true)) {
            return '';
        }
        return $cat_slug . '/' . $style_slug . '/' . $s_slug;
    }

    private function metal_label($m) {
        return isset(self::METAL_LABELS[$m]) ? self::METAL_LABELS[$m] : ucwords(str_replace('_', ' ', $m));
    }

    private function setting_label($s) {
        return isset(self::SETTING_LABELS[$s]) ? self::SETTING_LABELS[$s] : ucwords(str_replace('_', ' ', $s));
    }

    private function us_english($text) {
        return str_replace(
            array('jewellers', 'jeweller', 'Jewellers', 'Jeweller', 'colour', 'Colour'),
            array('jewelers', 'jeweler', 'Jewelers', 'Jeweler', 'color', 'Color'),
            (string) $text
        );
    }

    private function usd($amount) {
        return '$' . number_format((float) round((float) $amount));
    }

    private function profile($site_id) {
        $p = $this->config->get_content_profile($site_id);
        return is_array($p) ? $p : array();
    }

    private function treatment(array $profile) {
        return isset($profile['jewellery_treatment']) && is_string($profile['jewellery_treatment'])
            ? $profile['jewellery_treatment']
            : 'quality_led';
    }

    private function surface(array $profile) {
        return isset($profile['jewellery_surface']) && is_array($profile['jewellery_surface'])
            ? $profile['jewellery_surface']
            : array();
    }

    private function price_renderer() {
        if ($this->price_renderer === null) {
            $this->price_renderer = new LDN_Renderer($this->fetcher, $this->config);
        }
        return $this->price_renderer;
    }
}

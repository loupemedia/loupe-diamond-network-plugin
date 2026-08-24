<?php
/**
 * Inject live price/size panels onto legacy WP education posts.
 *
 * Ringspo posts live at /{n}-carat-…-diamond-ring/ or /{prefix}-engagement-rings/.
 * Other sites declare exact permalinks under `editorial_ring_guides.paths`.
 * The inject strips stale Ninja Tables and replaces Price/Size H2 sections
 * when those headings are present (the /N-carat-…-diamond-ring/ posts).
 * Shape guides never use those H2s. Across all ten /{shape}-engagement-rings/
 * posts the live cards land on the copy those pages actually have: the price
 * card after the replaced Gutenberg comparison (or the well-priced /
 * most-expensive / less-expensive H2/H3), and the size card after the
 * buying-guide "diamond carat (weight)" heading. Prepend is last resort.
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Editorial_Bridge {

    /** Official US quarter diameter (mm); same constant Z3 uses for scale SVGs. */
    const US_QUARTER_DIAMETER_MM = '24.26';

    /** @var string */
    private $site_id;

    /** @var LDN_Config */
    private $config;

    /** @var LDN_Data_Fetcher */
    private $fetcher;

    /**
     * @param string           $site_id
     * @param LDN_Config       $config
     * @param LDN_Data_Fetcher $fetcher
     */
    public function __construct($site_id, LDN_Config $config, LDN_Data_Fetcher $fetcher) {
        $this->site_id = (string) $site_id;
        $this->config = $config;
        $this->fetcher = $fetcher;
    }

    /**
     * @return void
     */
    public function register() {
        if ($this->config->editorial_ring_guides($this->site_id) === null) {
            return;
        }
        add_filter('body_class', array($this, 'body_class'));
        add_filter('the_content', array($this, 'filter_content'), 20);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Scope full-bleed band CSS to legacy ring-guide permalinks only.
     *
     * @param string[] $classes
     * @return string[]
     */
    public function body_class($classes) {
        if (self::match_path($this->request_path(), $this->config->editorial_ring_guides($this->site_id)) === null) {
            return $classes;
        }
        $classes[] = 'ldn-editorial-ring-guide';
        return $classes;
    }

    /**
     * @param string $content
     * @return string
     */
    public function filter_content($content) {
        if (is_admin() || is_feed()) {
            return $content;
        }
        if (get_query_var('ldn_route') !== '' && get_query_var('ldn_route') !== false) {
            return $content;
        }
        if (function_exists('is_singular') && !is_singular()) {
            return $content;
        }
        $path = $this->request_path();
        if ($path === '') {
            return $content;
        }
        return $this->transform((string) $content, $path);
    }

    /**
     * @return void
     */
    public function enqueue_assets() {
        if (is_admin()) {
            return;
        }
        if (function_exists('is_singular') && !is_singular()) {
            return;
        }
        if (self::match_path($this->request_path(), $this->config->editorial_ring_guides($this->site_id)) === null) {
            return;
        }
        $version = defined('LDN_VERSION') ? LDN_VERSION : '0.1.0';
        $base_url = defined('LDN_PLUGIN_URL') ? LDN_PLUGIN_URL : '';
        if ($base_url === '') {
            return;
        }
        wp_enqueue_style(
            'ldn-editorial-ring-guide',
            $base_url . 'assets/css/editorial-ring-guide.css',
            array(),
            $version
        );
    }

    /**
     * Apply the inject to HTML for a permalink path (used by tests and preview).
     *
     * @param string $html
     * @param string $path
     * @return string
     */
    public function transform($html, $path) {
        $guides = $this->config->editorial_ring_guides($this->site_id);
        $match = self::match_path($path, $guides);
        if ($match === null) {
            return $html;
        }
        $html = self::strip_ninja_tables($html);
        $panels = $this->build_panels($match, $guides);
        if (!empty($guides['replace_price_tables'])) {
            $compare = $this->shape_comparison_html($match, $guides);
            if ($match['kind'] === 'shape_hub' && $compare !== '') {
                // Whole-section splice on shape guides carries the live table.
                $panels['price'] = $compare . $panels['price'];
            } else {
                $html = self::replace_price_tables($html, $compare);
            }
        }
        return self::splice_panels($html, $match, $panels);
    }

    /**
     * Match a permalink against the configured guides.
     *
     * `kind` is one of `hub` (all shapes at a carat), `shape` (one shape at a
     * carat) or `shape_hub` (one shape, every carat).
     *
     * @param string     $path
     * @param array|null $guides
     * @return array{kind:string,carat:string,shape:?string,infix:?string}|null
     */
    public static function match_path($path, $guides) {
        if (!is_array($guides) || empty($guides['enabled'])) {
            return null;
        }
        $path = strtolower(rtrim((string) $path, '/')) . '/';
        if ($path === '/') {
            return null;
        }

        $declared = self::match_declared_path($path, $guides);
        if ($declared !== null) {
            return $declared;
        }

        $shape_page = self::match_shape_page($path, $guides);
        if ($shape_page !== null) {
            return $shape_page;
        }

        $carats = array();
        foreach (isset($guides['carats']) && is_array($guides['carats']) ? $guides['carats'] : array() as $carat) {
            $carats[(string) $carat] = true;
        }
        if ($carats === array()) {
            return null;
        }

        if (!empty($guides['include_hubs'])
            && preg_match('#^/(?P<carat>[\d.]+)-carat-diamond-ring/$#', $path, $hub)
        ) {
            $carat = self::normalise_carat_label($hub['carat']);
            if (isset($carats[$carat])) {
                return array(
                    'kind'  => 'hub',
                    'carat' => $carat,
                    'shape' => null,
                    'infix' => null,
                );
            }
        }

        $infixes = isset($guides['infixes']) && is_array($guides['infixes']) ? $guides['infixes'] : array();
        $by_infix = array();
        foreach ($infixes as $shape => $infix) {
            $by_infix[strtolower((string) $infix)] = strtolower((string) $shape);
        }
        if ($by_infix === array()) {
            return null;
        }

        if (!preg_match('#^/(?P<carat>[\d.]+)-carat-(?P<infix>[a-z0-9-]+)-diamond-ring/$#', $path, $shape_m)) {
            return null;
        }
        $carat = self::normalise_carat_label($shape_m['carat']);
        $infix = strtolower($shape_m['infix']);
        if (!isset($carats[$carat]) || !isset($by_infix[$infix])) {
            return null;
        }
        return array(
            'kind'  => 'shape',
            'carat' => $carat,
            'shape' => $by_infix[$infix],
            'infix' => $infix,
        );
    }

    /**
     * Exact permalinks declared under `paths`. Used by sites whose education
     * posts are not /{n}-carat-…-diamond-ring/ (Diamond Hunt's /diamond-shapes/
     * and so on). An unknown kind or a shape kind without a shape is refused
     * rather than guessed.
     *
     * @param string $path   Lowercased, slash-terminated request path.
     * @param array  $guides
     * @return array{kind:string,carat:string,shape:?string,infix:?string,price_heading:?string}|null
     */
    private static function match_declared_path($path, array $guides) {
        $declared = isset($guides['paths']) && is_array($guides['paths'])
            ? $guides['paths']
            : array();
        foreach ($declared as $permalink => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $key = strtolower(rtrim((string) $permalink, '/')) . '/';
            if ($key !== $path) {
                continue;
            }
            $kind = isset($spec['kind']) ? (string) $spec['kind'] : '';
            if (!in_array($kind, array('hub', 'shape', 'shape_hub'), true)) {
                return null;
            }
            $carat = isset($spec['carat']) ? self::normalise_carat_label($spec['carat']) : '';
            if ($carat === '') {
                return null;
            }
            $shape = isset($spec['shape']) ? strtolower((string) $spec['shape']) : null;
            if ($kind !== 'hub' && ($shape === null || $shape === '')) {
                return null;
            }
            $infix = isset($spec['infix']) && (string) $spec['infix'] !== ''
                ? strtolower((string) $spec['infix'])
                : $shape;
            return array(
                'kind'          => $kind,
                'carat'         => $carat,
                'shape'         => $kind === 'hub' ? null : $shape,
                'infix'         => $kind === 'hub' ? null : $infix,
                'price_heading' => isset($spec['price_heading'])
                    ? (string) $spec['price_heading']
                    : null,
            );
        }
        return null;
    }

    /**
     * Carat-free shape guides at /{prefix}-{suffix}/, e.g. Ringspo's ten
     * /{shape}-engagement-rings/ posts. `carat` carries the anchor weight the
     * price figure and CTA resolve against; the ladder covers the rest.
     *
     * @param string $path   Lowercased, slash-terminated request path.
     * @param array  $guides
     * @return array{kind:string,carat:string,shape:?string,infix:?string}|null
     */
    private static function match_shape_page($path, array $guides) {
        $config = isset($guides['shape_pages']) && is_array($guides['shape_pages'])
            ? $guides['shape_pages']
            : array();
        if (empty($config['prefixes']) || !is_array($config['prefixes'])) {
            return null;
        }
        $suffix = isset($config['suffix']) ? strtolower(trim((string) $config['suffix'], '/')) : '';
        $anchor = isset($config['anchor_carat']) ? (string) $config['anchor_carat'] : '';
        if ($suffix === '' || $anchor === '') {
            return null;
        }

        $by_prefix = array();
        foreach ($config['prefixes'] as $shape => $prefix) {
            $by_prefix[strtolower((string) $prefix)] = strtolower((string) $shape);
        }

        $pattern = '#^/(?P<prefix>[a-z0-9-]+)-' . preg_quote($suffix, '#') . '/$#';
        if (!preg_match($pattern, $path, $matched)) {
            return null;
        }
        $prefix = strtolower($matched['prefix']);
        if (!isset($by_prefix[$prefix])) {
            return null;
        }

        return array(
            'kind'  => 'shape_hub',
            'carat' => self::normalise_carat_label($anchor),
            'shape' => $by_prefix[$prefix],
            'infix' => $prefix,
        );
    }

    /**
     * Swap hand-built price tables for live data.
     *
     * These posts embed Gutenberg tables (`wp-block-table`) quoting one
     * retailer on one long-past date, so no shortcode strip reaches them. Only
     * tables whose header row names a price are touched: the depth and table
     * percentage tables in the same posts head their columns
     * "Excellent / Very good / Good" and must survive untouched.
     *
     * The first pricing table becomes the live comparison. Any further pricing
     * table is dropped, because a second stale table would contradict the
     * first. When there is no live replacement to show, every pricing table is
     * left alone rather than deleting content and leaving a hole.
     *
     * @param string $html
     * @param string $replacement Live comparison HTML, or '' to leave as is.
     * @return string
     */
    public static function replace_price_tables($html, $replacement) {
        if (!is_string($html) || $html === '' || (string) $replacement === '') {
            return (string) $html;
        }
        $pattern = '#(?P<table><figure\b[^>]*wp-block-table[^>]*>\s*<table\b.*?</table>\s*</figure>'
            . '|<table\b.*?</table>)'
            . '(?P<tail>\s*<p\b[^>]*>.*?</p>)?#is';
        $seen = 0;
        $out = preg_replace_callback(
            $pattern,
            function ($m) use ($replacement, &$seen) {
                if (!self::table_is_pricing($m['table'])) {
                    return $m[0];
                }
                ++$seen;
                $tail = isset($m['tail']) ? $m['tail'] : '';
                return ($seen === 1 ? $replacement : '') . self::drop_percentage_sentences($tail);
            },
            $html
        );
        return is_string($out) ? $out : $html;
    }

    /**
     * Drop the sentences in a replaced table's closing paragraph that quote a
     * percentage, because that number described the table we just removed.
     *
     * Sentence level rather than paragraph level: several of these posts follow
     * the stale figure with a caveat worth keeping ("The actual saving will
     * depend on the carat, cut and clarity..."). A paragraph left with nothing
     * is dropped entirely.
     *
     * Bails out on any paragraph containing markup, since splitting sentences
     * inside HTML could cut a link in half. The affected paragraphs are plain
     * text on all ten Ringspo guides.
     *
     * @param string $tail Captured "\s*<p ...>...</p>", or ''.
     * @return string
     */
    private static function drop_percentage_sentences($tail) {
        if ((string) $tail === '') {
            return '';
        }
        if (!preg_match('#^(?P<lead>\s*)<p(?P<attrs>[^>]*)>(?P<inner>.*)</p>$#is', $tail, $p)) {
            return $tail;
        }
        if (strpos($p['inner'], '<') !== false) {
            return $tail;
        }

        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/u', trim($p['inner']));
        if (!is_array($sentences)) {
            return $tail;
        }
        $kept = array();
        foreach ($sentences as $sentence) {
            if (!preg_match('/\d+(?:\.\d+)?\s*%/', $sentence)) {
                $kept[] = $sentence;
            }
        }
        if ($kept === $sentences) {
            return $tail;
        }
        if ($kept === array()) {
            return '';
        }
        return $p['lead'] . '<p' . $p['attrs'] . '>' . implode(' ', $kept) . '</p>';
    }

    /**
     * Does this table's header row name a price?
     *
     * @param string $table
     * @return bool
     */
    private static function table_is_pricing($table) {
        if (!preg_match('#<tr\b.*?</tr>#is', $table, $row)) {
            return false;
        }
        $text = strtolower(strip_tags($row[0]));
        return (bool) preg_match('/price|cost|\$|\x{00A3}|\x{20AC}/u', $text);
    }

    /**
     * @param string $html
     * @return string
     */
    public static function strip_ninja_tables($html) {
        $stripped = preg_replace('/\[ninja_tables?[^\]]*\]/i', '', (string) $html);
        return is_string($stripped) ? $stripped : (string) $html;
    }

    /**
     * Place the price and size cards next to the copy they replace.
     *
     * Order: named Price/Size H2 sections (carat posts and any shape guide
     * that names them), then whole H3 sections on the ten shape guides, then
     * insert fallbacks, then prepend only what still has nowhere to go.
     *
     * @param string $html
     * @param array  $match
     * @param array{price:string,size:string,combined:string} $panels
     * @return string
     */
    public static function splice_panels($html, array $match, array $panels) {
        $price_placed = 0;
        $size_placed = 0;
        $out = self::replace_heading_section(
            $html,
            self::price_heading_regex($match),
            $panels['price'],
            $price_placed
        );
        $out = self::replace_heading_section(
            $out,
            self::size_heading_regex($match),
            $panels['size'],
            $size_placed
        );

        $price_done = $price_placed > 0;
        $size_done = $size_placed > 0;
        if (!$price_done && $match['kind'] === 'shape_hub') {
            $out = self::replace_subheading_section(
                $out,
                self::shape_guide_price_heading_regex(),
                $panels['price'],
                $price_placed
            );
            $price_done = $price_placed > 0;
        }
        if (!$price_done) {
            $out = self::insert_after_compare($out, $panels['price'], $price_done);
        }
        if (!$price_done) {
            $out = self::insert_after_heading(
                $out,
                self::shape_guide_price_heading_regex(),
                $panels['price'],
                $price_done
            );
        }
        if (!$size_done && $match['kind'] === 'shape_hub') {
            $out = self::replace_subheading_section(
                $out,
                self::shape_guide_size_heading_regex(),
                $panels['size'],
                $size_placed
            );
            $size_done = $size_placed > 0;
        }
        if (!$size_done) {
            $out = self::insert_after_heading(
                $out,
                self::shape_guide_size_heading_regex(),
                $panels['size'],
                $size_done
            );
        }

        if ($price_done && $size_done) {
            return $out;
        }
        if (!$price_done && !$size_done) {
            return $panels['combined'] . $out;
        }
        if (!$price_done) {
            return $panels['price'] . $out;
        }
        return $out . $panels['size'];
    }

    /**
     * @param string $carat
     * @return string
     */
    public static function normalise_carat_label($carat) {
        return LDN_Test_Combos::normalise_carat($carat);
    }

    /**
     * @return string
     */
    private function request_path() {
        if (isset($_SERVER['REQUEST_URI'])) {
            $uri = (string) $_SERVER['REQUEST_URI'];
            $path = function_exists('wp_parse_url')
                ? wp_parse_url($uri, PHP_URL_PATH)
                : parse_url($uri, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                return $path;
            }
        }
        return '';
    }

    /**
     * @param array $match
     * @param array $guides
     * @return array{price:string,size:string,combined:string}
     */
    private function build_panels(array $match, array $guides) {
        $price_url = $this->price_url($match, $guides);
        $size_url = $this->size_url($match);
        $figure_note = $this->price_figure_note($match, $guides);

        $ladder = $this->price_ladder_html($match, $guides);

        $price = $this->price_card_html($match, $guides, $price_url, $figure_note, $ladder);
        $include_size = !isset($guides['include_size']) || !empty($guides['include_size']);
        $size = $include_size ? $this->size_card_html($match, $size_url, $guides) : '';
        $combined = '<aside class="ldn-ring-guide">' . $price . $size . '</aside>';
        return array(
            'price'     => $price,
            'size'      => $size,
            'combined'  => $combined,
        );
    }

    /**
     * @param array  $match
     * @param array  $guides
     * @param string $url
     * @param string $figure_note HTML note under the chart (may include <time>).
     * @param string $ladder Pre-rendered carat ladder, or '' for none.
     * @return string
     */
    private function price_card_html(array $match, array $guides, $url, $figure_note, $ladder = '') {
        // Complementary H2, not the LDN head term ("4 carat … diamond price").
        $market = self::market_label($guides);
        $heading = sprintf(
            /* translators: %s: US, UK or another market label */
            __('Current %s prices', 'loupe-diamond-network'),
            $market
        );
        $lead = sprintf(
            /* translators: %s: Color or Colour */
            __(
                '%s, clarity and cut quality move the price a long way - two stones with the same carat can be very different values.',
                'loupe-diamond-network'
            ),
            self::colour_word($guides)
        );
        $anchor = $match['kind'] === 'hub'
            ? sprintf(
                /* translators: %s: carat weight */
                __('%s carat diamond prices by shape', 'loupe-diamond-network'),
                $match['carat']
            )
            : sprintf(
                /* translators: 1: carat weight, 2: shape */
                __('%1$s carat %2$s diamond prices', 'loupe-diamond-network'),
                $match['carat'],
                $this->shape_label($match['shape'])
            );

        $out = '<section class="ldn-ring-guide__card ldn-ring-guide__card--price">';
        $out .= '<h2>' . esc_html($heading) . '</h2>';
        $out .= '<p>' . esc_html($lead) . '</p>';
        $figure = '';
        if (in_array($match['kind'], array('shape', 'shape_hub'), true) && $match['shape'] !== null) {
            $figure = $this->shape_distribution_figure_html($match, $guides, $url, $figure_note);
        }
        if ($figure === '' && $match['kind'] === 'hub') {
            $figure = $this->hub_ranking_figure_html($match, $guides, $url, $figure_note);
        }
        if ($figure === '') {
            $og_url = $this->og_preview_url($match, $guides);
            if ($og_url !== null && $og_url !== '') {
                $alt = sprintf(
                    /* translators: 1: carat, 2: shape or "all shapes", 3: market label */
                    __('Price distribution for %1$s carat %2$s diamonds (%3$s)', 'loupe-diamond-network'),
                    $match['carat'],
                    $match['kind'] === 'hub' ? __('all shapes', 'loupe-diamond-network') : $this->shape_label($match['shape']),
                    $market
                );
                $figure = '<figure class="ldn-ring-guide__figure">';
                $figure .= '<a href="' . esc_url($url) . '">';
                $figure .= '<img src="' . esc_url($og_url) . '" alt="' . esc_attr($alt) . '" width="1200" height="630" loading="lazy">';
                $figure .= '</a>';
                if ($figure_note !== '') {
                    $figure .= $figure_note; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
                $figure .= '</figure>';
            }
        }
        $out .= $figure; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        $out .= $ladder; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        $out .= '<p><a class="ldn-ring-guide__cta" href="' . esc_url($url) . '">'
            . esc_html($anchor)
            . ' <span aria-hidden="true">→</span></a></p>';
        $out .= '</section>';
        return $out;
    }

    /**
     * @param array  $match
     * @param string $url
     * @param array  $guides
     * @return string
     */
    private function size_card_html(array $match, $url, array $guides = array()) {
        // Complementary H2, not the LDN head term ("4 carat … diamond size").
        $heading = __('How big it looks', 'loupe-diamond-network');
        $lead = __(
            'Carat is weight, not diameter. How large the stone looks depends on shape, depth and whether it is square or elongated.',
            'loupe-diamond-network'
        );
        if ($match['kind'] === 'hub') {
            $anchor = __('Diamond size chart (all shapes)', 'loupe-diamond-network');
        } elseif ($match['kind'] === 'shape_hub') {
            $anchor = sprintf(
                /* translators: %s: shape name */
                __('%s diamond size chart', 'loupe-diamond-network'),
                $this->shape_label($match['shape'])
            );
        } else {
            $anchor = sprintf(
                /* translators: 1: carat weight, 2: shape */
                __('%1$s carat %2$s diamond size', 'loupe-diamond-network'),
                $match['carat'],
                $this->shape_label($match['shape'])
            );
        }

        $out = '<section class="ldn-ring-guide__card ldn-ring-guide__card--size">';
        $out .= '<h2>' . esc_html($heading) . '</h2>';
        $out .= '<p>' . esc_html($lead) . '</p>';
        $out .= $this->size_chart_html($match, $guides);
        $out .= '<p><a class="ldn-ring-guide__cta" href="' . esc_url($url) . '">'
            . esc_html($anchor)
            . ' <span aria-hidden="true">→</span></a></p>';
        $out .= '</section>';
        return $out;
    }

    /**
     * True-scale 4 ct (etc.) shape row from the size mega-hub matrix: live
     * median L×W plus outline SVGs, with a US quarter for reference.
     *
     * @param array $match
     * @return string
     */
    private function size_chart_html(array $match, array $guides = array()) {
        if ($match['kind'] === 'hub') {
            $cells = $this->hub_size_shapes($match['carat']);
            $kicker = sprintf(
                /* translators: %s: carat weight */
                __('%s carat at actual size', 'loupe-diamond-network'),
                $match['carat']
            );
            $aria = sprintf(
                /* translators: %s: carat weight */
                __('%s carat diamond sizes drawn at true scale next to a US quarter', 'loupe-diamond-network'),
                $match['carat']
            );
        } elseif ($match['kind'] === 'shape_hub') {
            $cells = $this->shape_size_carats($match['shape'], $this->size_carats($guides));
            $kicker = sprintf(
                /* translators: %s: shape name */
                __('%s at actual size', 'loupe-diamond-network'),
                $this->shape_label($match['shape'])
            );
            $aria = sprintf(
                /* translators: %s: shape name */
                __('%s diamond sizes by carat weight, drawn at true scale next to a US quarter', 'loupe-diamond-network'),
                $this->shape_label($match['shape'])
            );
        } elseif ($match['kind'] === 'shape' && $match['shape'] !== null) {
            $cells = $this->shape_size_carats($match['shape'], array((string) $match['carat']));
            $kicker = sprintf(
                /* translators: %s: carat weight */
                __('%s carat at actual size', 'loupe-diamond-network'),
                $match['carat']
            );
            $aria = sprintf(
                /* translators: 1: carat weight, 2: shape name */
                __('%1$s carat %2$s diamond size drawn at true scale next to a US quarter', 'loupe-diamond-network'),
                $match['carat'],
                $this->shape_label($match['shape'])
            );
        } else {
            return '';
        }
        if ($cells === array()) {
            return '';
        }

        $quarter_mm = self::US_QUARTER_DIAMETER_MM;
        $quarter_url = class_exists('LDN_Assets') ? LDN_Assets::us_quarter_image_url() : '';
        $caption = __(
            'Typical face-up size from live US listings, drawn at true scale next to a US quarter for reference.',
            'loupe-diamond-network'
        );

        $out = '<figure class="ldn-ring-guide__size-chart">';
        $out .= '<p class="ldn-ring-guide__size-chart-kicker">' . esc_html($kicker) . '</p>';
        $out .= '<div class="ldn-ring-guide__size-chart-canvas" role="img" aria-label="'
            . esc_attr($aria) . '">';

        $out .= '<div class="ldn-ring-guide__size-item ldn-ring-guide__size-item--quarter"'
            . ' style="--mm-w:' . esc_attr($quarter_mm) . ';--mm-l:' . esc_attr($quarter_mm) . '">';
        $out .= '<span class="ldn-ring-guide__size-stone">';
        if ($quarter_url !== '') {
            $out .= '<img src="' . esc_url($quarter_url) . '" alt="'
                . esc_attr(sprintf(
                    /* translators: %s: diameter in millimeters */
                    __('US quarter, %s mm across', 'loupe-diamond-network'),
                    $quarter_mm
                ))
                . '" width="243" height="243">';
        }
        $out .= '</span>';
        $out .= '<span class="ldn-ring-guide__size-label">'
            . esc_html(__('US quarter', 'loupe-diamond-network')) . '</span>';
        $out .= '<span class="ldn-ring-guide__size-dims">'
            . esc_html($quarter_mm . ' mm') . '</span>';
        $out .= '</div>';

        $out .= '<div class="ldn-ring-guide__size-chart-shapes">';
        foreach ($cells as $cell) {
            $shape = (string) $cell['shape'];
            $label = (string) $cell['label'];
            $length = (string) $cell['length_mm'];
            $width = (string) $cell['width_mm'];
            $href = $this->size_individual_url($shape, (string) $cell['carat']);
            $svg = $this->safe_outline_svg(isset($cell['outline_svg']) ? $cell['outline_svg'] : '');
            $dims = $length . ' × ' . $width . ' mm';
            $item = '<span class="ldn-ring-guide__size-stone">' . $svg . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                . '<span class="ldn-ring-guide__size-label">' . esc_html($label) . '</span>'
                . '<span class="ldn-ring-guide__size-dims">' . esc_html($dims) . '</span>';
            $style = '--mm-w:' . esc_attr($width) . ';--mm-l:' . esc_attr($length);
            $out .= $href !== ''
                ? '<a class="ldn-ring-guide__size-item" data-shape="' . esc_attr($shape)
                    . '" style="' . $style . '" href="'
                    . esc_url($href) . '">' . $item . '</a>'
                : '<div class="ldn-ring-guide__size-item" data-shape="' . esc_attr($shape)
                    . '" style="' . $style . '">' . $item . '</div>';
        }

        $out .= '</div></div>';
        $out .= '<figcaption>' . esc_html($caption) . '</figcaption>';
        $out .= '</figure>';
        return $out;
    }

    /**
     * @param string $carat
     * @return list<array{shape:string,label:string,length_mm:float|int|string,width_mm:float|int|string,outline_svg?:string}>
     */
    private function hub_size_shapes($carat) {
        $ctx = new LDN_Page_Context(
            $this->site_id,
            'size-mega-hub',
            'us',
            null,
            null,
            null,
            'size'
        );
        $summary = $this->fetcher->fetch_artefact('size_summary_json', $ctx);
        if (!is_array($summary)) {
            return array();
        }
        $matrix = isset($summary['matrix']) && is_array($summary['matrix']) ? $summary['matrix'] : array();
        $rows = isset($matrix['rows']) && is_array($matrix['rows']) ? $matrix['rows'] : array();
        $carat_key = (string) $carat;
        $out = array();
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['shape'])) {
                continue;
            }
            $cells = isset($row['cells']) && is_array($row['cells']) ? $row['cells'] : array();
            $cell = null;
            if (isset($cells[$carat_key]) && is_array($cells[$carat_key])) {
                $cell = $cells[$carat_key];
            } elseif (isset($cells[(int) $carat_key]) && is_array($cells[(int) $carat_key])) {
                $cell = $cells[(int) $carat_key];
            }
            if ($cell === null || !isset($cell['length_mm'], $cell['width_mm'])) {
                continue;
            }
            $out[] = array(
                'shape'       => (string) $row['shape'],
                'carat'       => $carat_key,
                'label'       => isset($row['label'])
                    ? (string) $row['label']
                    : $this->shape_label($row['shape']),
                'length_mm'   => $cell['length_mm'],
                'width_mm'    => $cell['width_mm'],
                'outline_svg' => isset($cell['outline_svg']) ? $cell['outline_svg'] : '',
            );
        }
        return $out;
    }

    /**
     * The mega-hub matrix row for one shape, transposed into carat cells.
     *
     * Reads the same artefact as the hub card, so the shape guide costs no
     * extra S3 round trip once the matrix transient is warm. The per-carat
     * `size_summary_json` cells are deliberately not used: cushion and the
     * other elongated shapes still publish n=0 there.
     *
     * @param string|null $shape
     * @param list<string> $carats Ordered carat columns to keep.
     * @return list<array{shape:string,carat:string,label:string,length_mm:float|int|string,width_mm:float|int|string,outline_svg?:string}>
     */
    private function shape_size_carats($shape, array $carats) {
        if ($shape === null || $shape === '' || $carats === array()) {
            return array();
        }
        $ctx = new LDN_Page_Context(
            $this->site_id,
            'size-mega-hub',
            'us',
            null,
            null,
            null,
            'size'
        );
        $summary = $this->fetcher->fetch_artefact('size_summary_json', $ctx);
        if (!is_array($summary)) {
            return array();
        }
        $matrix = isset($summary['matrix']) && is_array($summary['matrix']) ? $summary['matrix'] : array();
        $rows = isset($matrix['rows']) && is_array($matrix['rows']) ? $matrix['rows'] : array();
        $target = strtolower((string) $shape);

        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['shape'])) {
                continue;
            }
            if (strtolower((string) $row['shape']) !== $target) {
                continue;
            }
            $cells = isset($row['cells']) && is_array($row['cells']) ? $row['cells'] : array();
            $out = array();
            foreach ($carats as $carat) {
                $carat_key = (string) $carat;
                $cell = null;
                if (isset($cells[$carat_key]) && is_array($cells[$carat_key])) {
                    $cell = $cells[$carat_key];
                } elseif (isset($cells[(int) $carat_key]) && is_array($cells[(int) $carat_key])) {
                    $cell = $cells[(int) $carat_key];
                }
                if ($cell === null || !isset($cell['length_mm'], $cell['width_mm'])) {
                    continue;
                }
                $out[] = array(
                    'shape'       => (string) $row['shape'],
                    'carat'       => $carat_key,
                    'label'       => sprintf(
                        /* translators: %s: carat weight */
                        __('%s ct', 'loupe-diamond-network'),
                        $carat_key
                    ),
                    'length_mm'   => $cell['length_mm'],
                    'width_mm'    => $cell['width_mm'],
                    'outline_svg' => isset($cell['outline_svg']) ? $cell['outline_svg'] : '',
                );
            }
            return $out;
        }
        return array();
    }

    /**
     * Median price by carat for one shape, built from the per-carat
     * `summary_data_json` the shape pages already publish.
     *
     * Ringspo is not entitled to `carat_ladder_json`, so this walks the
     * weights in `shape_pages.ladder_carats`. Each response is transient
     * cached by LDN_Data_Fetcher for the pricing TTL, so a warm page costs no
     * S3 traffic.
     *
     * @param array $match
     * @param array $guides
     * @return string
     */
    private function price_ladder_html(array $match, array $guides) {
        if ($match['kind'] !== 'shape_hub' || empty($match['shape'])) {
            return '';
        }
        $carats = $this->ladder_carats($guides);
        if ($carats === array()) {
            return '';
        }

        $country = isset($guides['default_country']) ? (string) $guides['default_country'] : 'us';
        $type = isset($guides['default_type']) ? (string) $guides['default_type'] : 'natural';
        $currency = method_exists($this->config, 'get_currency')
            ? (string) $this->config->get_currency($this->site_id, $country)
            : 'USD';

        $rows = array();
        $dates = array();
        foreach ($carats as $carat) {
            $carat_key = self::normalise_carat_label($carat);
            $ctx = new LDN_Page_Context(
                $this->site_id,
                'shape',
                $country,
                $type,
                $carat_key,
                $match['shape'],
                'price'
            );
            $summary = $this->fetcher->fetch_artefact('summary_data_json', $ctx);
            $median = self::summary_median($summary);
            if ($median === null) {
                continue;
            }
            $rows[] = array(
                'carat'  => $carat_key,
                'median' => $median,
                'sample' => self::summary_sample($summary),
                'url'    => $this->price_url($match, $guides, $carat_key),
            );
            $dates[] = self::summary_analysis_date($summary);
        }
        if (count($rows) < 2) {
            return '';
        }

        $out = '<div class="ldn-ring-guide__ladder-wrap">';
        $out .= '<table class="ldn-ring-guide__ladder">';
        $out .= '<caption>' . esc_html(sprintf(
            /* translators: 1: shape name, 2: market label */
            __('%1$s diamonds: median %2$s asking price by carat weight', 'loupe-diamond-network'),
            $this->shape_label($match['shape']),
            self::market_label($guides)
        )) . '</caption>';
        $out .= '<thead><tr>'
            . '<th scope="col">' . esc_html(__('Carat', 'loupe-diamond-network')) . '</th>'
            . '<th scope="col">' . esc_html(__('Median price', 'loupe-diamond-network')) . '</th>'
            . '<th scope="col">' . esc_html(__('Stones priced', 'loupe-diamond-network')) . '</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $label = sprintf(
                /* translators: %s: carat weight */
                __('%s carat', 'loupe-diamond-network'),
                $row['carat']
            );
            $cell = $row['url'] !== ''
                ? '<a href="' . esc_url($row['url']) . '">' . esc_html($label) . '</a>'
                : esc_html($label);
            $out .= '<tr' . ($row['carat'] === $match['carat'] ? ' class="is-anchor"' : '') . '>';
            $out .= '<th scope="row">' . $cell . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $out .= '<td>' . esc_html(self::format_money($row['median'], $currency)) . '</td>';
            $out .= '<td>' . esc_html($row['sample'] !== null ? number_format($row['sample']) : '') . '</td>';
            $out .= '</tr>';
        }
        $out .= '</tbody></table></div>';
        $out .= $this->updated_line($dates);
        return $out;
    }

    /**
     * Live replacement for the hand-built "this shape vs round" table.
     *
     * One fetch of the all-shapes aggregate at the anchor carat gives every
     * shape's median, so the differential is computed rather than asserted.
     * The reference shape comes from config (`comparison_reference`), so this
     * is not wired to round.
     *
     * @param array $match
     * @param array $guides
     * @return string
     */
    private function shape_comparison_html(array $match, array $guides) {
        if ($match['kind'] !== 'shape_hub' || empty($match['shape'])) {
            return '';
        }
        $config = isset($guides['shape_pages']) && is_array($guides['shape_pages'])
            ? $guides['shape_pages']
            : array();
        $reference = isset($config['comparison_reference'])
            ? strtolower((string) $config['comparison_reference'])
            : '';
        if ($reference === '') {
            return '';
        }

        $country = isset($guides['default_country']) ? (string) $guides['default_country'] : 'us';
        $type = isset($guides['default_type']) ? (string) $guides['default_type'] : 'natural';
        $ctx = new LDN_Page_Context(
            $this->site_id,
            'all-shapes',
            $country,
            $type,
            $match['carat'],
            null,
            'price'
        );
        $summary = $this->fetcher->fetch_artefact('summary_data_json', $ctx);
        if (!is_array($summary) || empty($summary['ranking']) || !is_array($summary['ranking'])) {
            return '';
        }

        $mine = self::ranking_row($summary['ranking'], $match['shape'], $match['infix']);
        $theirs = self::ranking_row($summary['ranking'], $reference, null);
        if ($mine === null || $theirs === null || $mine['median'] <= 0 || $theirs['median'] <= 0) {
            return '';
        }
        $same = $mine['shape'] === $theirs['shape'];
        $currency = method_exists($this->config, 'get_currency')
            ? (string) $this->config->get_currency($this->site_id, $country)
            : 'USD';
        $diff_pct = (($mine['median'] - $theirs['median']) / $theirs['median']) * 100;
        $date = self::summary_analysis_date($summary);
        $shape_name = $this->shape_label($match['shape']);

        $out = '<div class="ldn-ring-guide__compare">';
        $out .= '<table class="ldn-ring-guide__ladder ldn-ring-guide__compare-table">';
        $out .= '<caption>' . esc_html(sprintf(
            /* translators: 1: carat weight, 2: shape name */
            __('%1$s carat prices today: %2$s against the market', 'loupe-diamond-network'),
            $match['carat'],
            $shape_name
        )) . '</caption>';
        $out .= '<thead><tr>'
            . '<th scope="col">' . esc_html(__('Shape', 'loupe-diamond-network')) . '</th>'
            . '<th scope="col">' . esc_html(__('Median price', 'loupe-diamond-network')) . '</th>'
            . '<th scope="col">' . esc_html(__('Stones priced', 'loupe-diamond-network')) . '</th>'
            . '</tr></thead><tbody>';
        $rows = $same ? array($mine) : array($theirs, $mine);
        foreach ($rows as $row) {
            $out .= '<tr>';
            $out .= '<th scope="row">' . esc_html($row['label']) . '</th>';
            $out .= '<td>' . esc_html(self::format_money($row['median'], $currency)) . '</td>';
            $out .= '<td>' . esc_html($row['sample'] !== null ? number_format($row['sample']) : '') . '</td>';
            $out .= '</tr>';
        }
        $out .= '</tbody></table>';

        if (!$same) {
            $verdict = $diff_pct < 0
                ? sprintf(
                    /* translators: 1: shape, 2: percentage, 3: reference shape, 4: carat */
                    __('At %4$s carat a %1$s costs about %2$s%% less than %3$s.', 'loupe-diamond-network'),
                    $shape_name,
                    number_format(abs($diff_pct), 0),
                    $theirs['label'],
                    $match['carat']
                )
                : sprintf(
                    /* translators: 1: shape, 2: percentage, 3: reference shape, 4: carat */
                    __('At %4$s carat a %1$s costs about %2$s%% more than %3$s.', 'loupe-diamond-network'),
                    $shape_name,
                    number_format($diff_pct, 0),
                    $theirs['label'],
                    $match['carat']
                );
            $out .= '<p>' . esc_html($verdict) . '</p>';
        }
        if ($mine['rank'] !== null && !empty($summary['aggregate']['shape_count'])) {
            $out .= '<p>' . esc_html(sprintf(
                /* translators: 1: shape, 2: rank, 3: number of shapes */
                __('That puts %1$s %2$d of %3$d shapes by price at this weight.', 'loupe-diamond-network'),
                $shape_name,
                (int) $mine['rank'],
                (int) $summary['aggregate']['shape_count']
            )) . '</p>';
        }
        $out .= $this->updated_line(array($date));
        $out .= '</div>';
        return $out;
    }

    /**
     * Find a shape in the all-shapes ranking.
     *
     * Ranking labels are display names ("Cushion cut", "Princess cut"), so both
     * the URL slug and the editorial infix are tried after stripping
     * non-letters. An unmatched shape returns null rather than falling back to
     * a neighbouring row.
     *
     * @param array       $ranking
     * @param string      $shape
     * @param string|null $infix
     * @return array{shape:string,label:string,median:float,sample:?int,rank:?int}|null
     */
    private static function ranking_row(array $ranking, $shape, $infix) {
        $wanted = array(self::squash((string) $shape));
        if ($infix !== null && $infix !== '') {
            $wanted[] = self::squash((string) $infix);
        }
        foreach ($ranking as $row) {
            if (!is_array($row) || empty($row['shape'])) {
                continue;
            }
            $label = (string) $row['shape'];
            $key = self::squash($label);
            if (!in_array($key, $wanted, true)) {
                continue;
            }
            $median = isset($row['median_price']) && is_numeric($row['median_price'])
                ? (float) $row['median_price']
                : (isset($row['current_price']) && is_numeric($row['current_price']) ? (float) $row['current_price'] : 0.0);
            return array(
                'shape'  => $key,
                'label'  => $label,
                'median' => $median,
                'sample' => isset($row['sample_size']) && is_numeric($row['sample_size'])
                    ? (int) $row['sample_size']
                    : null,
                'rank'   => isset($row['rank']) && is_numeric($row['rank']) ? (int) $row['rank'] : null,
            );
        }
        return null;
    }

    /**
     * Lowercase letters only, so "Cushion cut" and "cushion-cut" compare equal.
     *
     * @param string $value
     * @return string
     */
    private static function squash($value) {
        return preg_replace('/[^a-z]/', '', strtolower((string) $value));
    }

    /**
     * "Updated 18 August 2026", or a span when the rows disagree.
     *
     * The freshness cadence rebuilds popular weights daily and the long tail
     * less often, so a ladder legitimately mixes dates. Showing the span keeps
     * the claim true of every row rather than advertising the newest.
     *
     * @param list<string> $dates ISO dates; blanks are ignored.
     * @return string
     */
    private function updated_line(array $dates) {
        $clean = array();
        foreach ($dates as $date) {
            if (is_string($date) && $date !== '') {
                $clean[$date] = true;
            }
        }
        if ($clean === array()) {
            return '';
        }
        $clean = array_keys($clean);
        sort($clean);
        $oldest = $clean[0];
        $newest = $clean[count($clean) - 1];

        if ($oldest === $newest) {
            $body = sprintf(
                /* translators: %s: date the prices were last rebuilt */
                __('Prices updated %s', 'loupe-diamond-network'),
                '<time datetime="' . esc_attr($newest) . '">' . esc_html(self::format_date($newest)) . '</time>'
            );
        } else {
            $body = sprintf(
                /* translators: 1: oldest date, 2: newest date */
                __('Prices updated between %1$s and %2$s', 'loupe-diamond-network'),
                '<time datetime="' . esc_attr($oldest) . '">' . esc_html(self::format_date($oldest)) . '</time>',
                '<time datetime="' . esc_attr($newest) . '">' . esc_html(self::format_date($newest)) . '</time>'
            );
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- parts escaped above.
        return '<p class="ldn-ring-guide__updated">' . $body . '</p>';
    }

    /**
     * @param mixed $summary
     * @return float|null
     */
    private static function summary_median($summary) {
        if (!is_array($summary)) {
            return null;
        }
        $paths = array(
            array('distribution', 'percentiles', 'p50'),
            array('distribution', 'median_price'),
            array('time_series', 'current_price'),
        );
        foreach ($paths as $path) {
            $node = $summary;
            foreach ($path as $key) {
                if (!is_array($node) || !isset($node[$key])) {
                    $node = null;
                    break;
                }
                $node = $node[$key];
            }
            if (is_numeric($node) && (float) $node > 0) {
                return (float) $node;
            }
        }
        return null;
    }

    /**
     * The date the pricing artefact was last rebuilt.
     *
     * C5 writes it to `time_series.analysis_date` on shape summaries and to a
     * top-level `analysis_date` on the all-shapes aggregate. `metadata` holds
     * only the JSON-LD/OG payload, so reading `metadata.analysis_date` alone
     * silently produced an undated caption on every live page.
     *
     * @param mixed $summary
     * @return string ISO date, or '' when the artefact carries none.
     */
    private static function summary_analysis_date($summary) {
        if (!is_array($summary)) {
            return '';
        }
        $paths = array(
            array('time_series', 'analysis_date'),
            array('analysis_date'),
            array('metadata', 'analysis_date'),
            array('_meta', 'analysis_date'),
            array('metadata', 'json_ld', 0, 'dateModified'),
        );
        foreach ($paths as $path) {
            $node = $summary;
            foreach ($path as $key) {
                if (!is_array($node) || !isset($node[$key])) {
                    $node = null;
                    break;
                }
                $node = $node[$key];
            }
            if (is_string($node) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $node)) {
                return $node;
            }
        }
        return '';
    }

    /**
     * ISO date to reader format ("2026-08-18" -> "18 August 2026").
     *
     * Deliberately not `date_i18n`: this runs in the preview harness too, and
     * the ring guides are English-only surfaces.
     *
     * @param string $iso
     * @return string
     */
    private static function format_date($iso) {
        $dt = DateTime::createFromFormat('Y-m-d', (string) $iso);
        return $dt instanceof DateTime ? $dt->format('j F Y') : (string) $iso;
    }

    /**
     * @param mixed $summary
     * @return int|null
     */
    private static function summary_sample($summary) {
        if (!is_array($summary)
            || !isset($summary['time_series']['num_diamonds'])
            || !is_numeric($summary['time_series']['num_diamonds'])
        ) {
            return null;
        }
        return (int) $summary['time_series']['num_diamonds'];
    }

    /**
     * @param float  $value
     * @param string $currency ISO 4217 code.
     * @return string
     */
    private static function format_money($value, $currency) {
        $symbols = array('USD' => '$', 'GBP' => '£', 'EUR' => '€', 'AUD' => 'A$', 'CAD' => 'C$');
        $code = strtoupper((string) $currency);
        $symbol = isset($symbols[$code]) ? $symbols[$code] : '';
        $amount = number_format((float) $value, 0);
        return $symbol !== '' ? $symbol . $amount : $code . ' ' . $amount;
    }

    /**
     * @param array $guides
     * @return list<string>
     */
    private function ladder_carats(array $guides) {
        return self::carat_list($guides, 'ladder_carats');
    }

    /**
     * @param array $guides
     * @return list<string>
     */
    private function size_carats(array $guides) {
        return self::carat_list($guides, 'size_carats');
    }

    /**
     * @param array  $guides
     * @param string $key
     * @return list<string>
     */
    private static function carat_list(array $guides, $key) {
        $config = isset($guides['shape_pages']) && is_array($guides['shape_pages'])
            ? $guides['shape_pages']
            : array();
        if (empty($config[$key]) || !is_array($config[$key])) {
            return array();
        }
        $out = array();
        foreach ($config[$key] as $carat) {
            $label = self::normalise_carat_label($carat);
            if ($label !== '') {
                $out[] = $label;
            }
        }
        return $out;
    }

    /**
     * @param mixed $svg
     * @return string
     */
    private function safe_outline_svg($svg) {
        if (!is_string($svg) || $svg === '' || stripos($svg, '<svg') === false) {
            return '';
        }
        if (stripos($svg, '<script') !== false) {
            return '';
        }
        return $svg;
    }

    /**
     * @param array       $match
     * @param array       $guides
     * @param string|null $carat_override Ladder rows resolve their own weight.
     * @return string
     */
    private function price_url(array $match, array $guides, $carat_override = null) {
        $country = isset($guides['default_country']) ? (string) $guides['default_country'] : 'us';
        $structure = method_exists($this->config, 'url_structure_for')
            ? $this->config->url_structure_for($this->site_id, $country)
            : $this->config->get_url_structure($this->site_id);
        if (!is_array($structure)) {
            return '';
        }
        $type_key = isset($guides['default_type']) ? (string) $guides['default_type'] : 'natural';
        $type_slug = $type_key === 'lab-grown' && !empty($structure['type_lab'])
            ? (string) $structure['type_lab']
            : (string) ($structure['type_natural'] ?? 'natural');
        $carat = $carat_override !== null ? (string) $carat_override : (string) $match['carat'];
        $carat_slug = $this->carat_url_slug($carat, $structure);
        $hub_level = isset($guides['price_hub_level']) ? (string) $guides['price_hub_level'] : 'level_3';
        $shape_level = isset($guides['price_shape_level']) ? (string) $guides['price_shape_level'] : 'level_4';
        $level = $match['kind'] === 'hub' ? $hub_level : $shape_level;
        $pattern = isset($structure[$level]) ? (string) $structure[$level] : '';
        if ($pattern === '') {
            return '';
        }
        $path = strtr($pattern, array(
            '{country}' => $country,
            '{type}'    => $type_slug,
            '{carat}'   => $carat_slug,
            '{shape}'   => $match['shape'] !== null
                ? $this->config->shape_to_url_slug($match['shape'], $this->site_id, $country)
                : '',
        ));
        // Drop unused tokens (a cert leaf still has {lab}/{cert}) rather than
        // emitting them into a public href.
        $path = preg_replace('#\{[^}]+\}#', '', $path);
        $path = preg_replace('#/+#', '/', (string) $path);
        return $this->home_path($path);
    }

    /**
     * @param array $match
     * @return string
     */
    private function size_url(array $match) {
        $structure = $this->config->get_url_structure($this->site_id);
        if ($match['kind'] === 'hub') {
            $pattern = is_array($structure) && !empty($structure['size_level_1'])
                ? (string) $structure['size_level_1']
                : '/diamond-size';
            return $this->home_path($pattern);
        }
        if ($match['kind'] === 'shape_hub' && !empty($match['shape'])) {
            $pattern = is_array($structure) && !empty($structure['size_level_2'])
                ? (string) $structure['size_level_2']
                : '/diamond-size/{shape}';
            return $this->home_path(str_replace(
                '{shape}',
                $this->config->shape_to_url_slug($match['shape'], $this->site_id),
                $pattern
            ));
        }
        return $this->size_individual_url($match['shape'], $match['carat']);
    }

    /**
     * @param string $shape
     * @param string $carat
     * @return string
     */
    private function size_individual_url($shape, $carat) {
        $structure = $this->config->get_url_structure($this->site_id);
        $pattern = is_array($structure) && !empty($structure['size_level_3'])
            ? (string) $structure['size_level_3']
            : '/diamond-size/{shape}/{carat}';
        $path = str_replace(
            array('{shape}', '{carat}'),
            array(
                $this->config->shape_to_url_slug($shape, $this->site_id),
                self::normalise_carat_label($carat) . '-carat',
            ),
            $pattern
        );
        return $this->home_path($path);
    }

    /**
     * All-shapes median bar chart for carat hubs (no shape-level OG PNG exists).
     *
     * @param array  $match
     * @param array  $guides
     * @param string $url
     * @param string $figure_note
     * @return string
     */
    private function hub_ranking_figure_html(array $match, array $guides, $url, $figure_note) {
        if (empty($guides['embed_og_preview'])) {
            return '';
        }
        $country = isset($guides['default_country']) ? (string) $guides['default_country'] : 'us';
        $type = isset($guides['default_type']) ? (string) $guides['default_type'] : 'natural';
        $ctx = new LDN_Page_Context(
            $this->site_id,
            'all-shapes',
            $country,
            $type,
            $match['carat'],
            null,
            'price'
        );
        $payload = $this->fetcher->fetch_artefact('shapes_ranking_json', $ctx);
        $rows = (is_array($payload) && isset($payload['shapes']) && is_array($payload['shapes']))
            ? $payload['shapes']
            : array();
        $bars = array();
        $max = 0.0;
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['shape'])) {
                continue;
            }
            $price = isset($row['median_price']) ? $row['median_price'] : (isset($row['current_price']) ? $row['current_price'] : null);
            if (!is_numeric($price) || (float) $price <= 0) {
                continue;
            }
            $value = (float) $price;
            if ($value > $max) {
                $max = $value;
            }
            $bars[] = array(
                'label' => $this->shape_label($row['shape']),
                'value' => $value,
            );
        }
        if (count($bars) < 2 || $max <= 0) {
            return '';
        }
        $bars = array_slice($bars, 0, 10);
        $currency = (is_array($payload) && !empty($payload['currency_symbol']))
            ? (string) $payload['currency_symbol']
            : '$';
        $market = self::market_label($guides);
        $title = sprintf(
            /* translators: 1: carat weight, 2: market label */
            __('Median %2$s asking price by shape for %1$s carat diamonds', 'loupe-diamond-network'),
            $match['carat'],
            $market
        );
        $row_h = 36;
        $bar_x = 140;
        $bar_max_w = 470;
        $price_x = 620;
        $width = 760;
        $top = 16;
        $height = $top + (count($bars) * $row_h) + 12;
        $svg = '<svg class="ldn-ring-guide__chart" viewBox="0 0 ' . (int) $width . ' ' . (int) $height
            . '" role="img" xmlns="http://www.w3.org/2000/svg">';
        $svg .= '<title>' . esc_html($title) . '</title>';
        foreach ($bars as $i => $bar) {
            $y = $top + ($i * $row_h);
            $bar_w = max(4, (int) round(($bar['value'] / $max) * $bar_max_w));
            $price_label = $currency . number_format($bar['value'], 0);
            $svg .= '<text x="8" y="' . (int) ($y + 22) . '" fill="#1a1a2e" font-size="13" font-family="system-ui, sans-serif">'
                . esc_html($bar['label']) . '</text>';
            $svg .= '<rect x="' . (int) $bar_x . '" y="' . (int) ($y + 8) . '" width="' . (int) $bar_w
                . '" height="20" rx="3" fill="#706cc8"></rect>';
            $svg .= '<text x="' . (int) $price_x . '" y="' . (int) ($y + 22)
                . '" fill="#1a1a2e" font-size="13" font-family="system-ui, sans-serif">'
                . esc_html($price_label) . '</text>';
        }
        $svg .= '</svg>';

        $out = '<figure class="ldn-ring-guide__figure">';
        $out .= '<a href="' . esc_url($url) . '">' . $svg . '</a>';
        if ($figure_note !== '') {
            $out .= $figure_note; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        $out .= '</figure>';
        return $out;
    }

    /**
     * Readable inline histogram for carat × shape guides (replaces the OG PNG).
     *
     * @param array  $match
     * @param array  $guides
     * @param string $url
     * @param string $figure_note
     * @return string
     */
    private function shape_distribution_figure_html(array $match, array $guides, $url, $figure_note) {
        if (empty($guides['embed_og_preview']) || $match['shape'] === null) {
            return '';
        }
        $ctx = $this->shape_price_context($match, $guides);
        $bins = $this->fetcher->fetch_artefact('market_index_bins_json', $ctx);
        $summary = $this->fetcher->fetch_artefact('summary_data_json', $ctx);
        if (!is_array($bins) || empty($bins['edges']) || empty($bins['counts']) || !is_array($bins['edges'])) {
            return '';
        }
        $edges = array_values($bins['edges']);
        $counts = array_values($bins['counts']);
        $bin_count = min(count($edges) - 1, count($counts));
        if ($bin_count < 2) {
            return '';
        }
        $max_count = 0.0;
        for ($i = 0; $i < $bin_count; $i++) {
            if (is_numeric($counts[$i]) && (float) $counts[$i] > $max_count) {
                $max_count = (float) $counts[$i];
            }
        }
        if ($max_count <= 0) {
            return '';
        }
        $min_x = (float) $edges[0];
        $max_x = (float) $edges[count($edges) - 1];
        $range_x = $max_x - $min_x;
        if ($range_x <= 0) {
            return '';
        }

        $currency = '$';
        if (is_array($summary) && !empty($summary['currency_symbol'])) {
            $currency = (string) $summary['currency_symbol'];
        } elseif (isset($guides['default_country']) && strtolower((string) $guides['default_country']) === 'uk') {
            $currency = '£';
        }

        $chart_left = 56;
        $chart_right = 736;
        $chart_top = 52;
        $chart_bottom = 280;
        $chart_width = $chart_right - $chart_left;
        $chart_height = $chart_bottom - $chart_top;
        $width = 760;
        $height = 320;

        $title = sprintf(
            /* translators: 1: carat weight, 2: shape name */
            __('%1$s carat %2$s diamond prices', 'loupe-diamond-network'),
            $match['carat'],
            $this->shape_label($match['shape'])
        );

        $svg = '<svg class="ldn-ring-guide__chart ldn-ring-guide__chart--distribution" viewBox="0 0 '
            . (int) $width . ' ' . (int) $height
            . '" role="img" xmlns="http://www.w3.org/2000/svg">';
        $svg .= '<title>' . esc_html($title) . '</title>';
        $svg .= '<text x="16" y="28" fill="#1a1a2e" font-size="16" font-weight="700"'
            . ' font-family="system-ui, sans-serif">' . esc_html($title) . '</text>';

        $p25 = self::summary_percentile($summary, 'p25');
        $p75 = self::summary_percentile($summary, 'p75');
        if ($p25 !== null && $p75 !== null && $p75 > $p25) {
            $iqr_x0 = $chart_left + (int) round((($p25 - $min_x) / $range_x) * $chart_width);
            $iqr_x1 = $chart_left + (int) round((($p75 - $min_x) / $range_x) * $chart_width);
            $svg .= '<rect x="' . (int) $iqr_x0 . '" y="' . (int) $chart_top . '" width="'
                . (int) max(1, $iqr_x1 - $iqr_x0) . '" height="' . (int) $chart_height
                . '" fill="rgba(112, 108, 200, 0.22)"></rect>';
        }

        for ($i = 0; $i < $bin_count; $i++) {
            if (!is_numeric($counts[$i]) || (float) $counts[$i] <= 0) {
                continue;
            }
            $x0 = $chart_left + (int) round(((float) $edges[$i] - $min_x) / $range_x * $chart_width);
            $x1 = $chart_left + (int) round(((float) $edges[$i + 1] - $min_x) / $range_x * $chart_width);
            $bar_w = max(1, $x1 - $x0);
            $bar_h = (int) round(((float) $counts[$i] / $max_count) * $chart_height);
            $y = $chart_bottom - $bar_h;
            $svg .= '<rect x="' . (int) $x0 . '" y="' . (int) $y . '" width="' . (int) $bar_w
                . '" height="' . (int) $bar_h . '" fill="#706cc8" opacity="0.88"></rect>';
        }

        $p50 = self::summary_median($summary);
        if ($p50 !== null) {
            $median_x = $chart_left + (int) round((($p50 - $min_x) / $range_x) * $chart_width);
            $median_label = sprintf(
                /* translators: 1: currency symbol, 2: median price */
                __('Median: %1$s%2$s', 'loupe-diamond-network'),
                $currency,
                number_format($p50, 0)
            );
            $label_w = max(96, (int) (strlen($median_label) * 7.5));
            $badge_x = max($chart_left, min($median_x - (int) ($label_w / 2), $chart_right - $label_w));
            $svg .= '<line x1="' . (int) $median_x . '" y1="' . (int) ($chart_top - 4)
                . '" x2="' . (int) $median_x . '" y2="' . (int) $chart_bottom
                . '" stroke="#5249a3" stroke-width="2" stroke-dasharray="5 4"></line>';
            $svg .= '<rect x="' . (int) $badge_x . '" y="8" width="' . (int) $label_w
                . '" height="22" rx="4" fill="#5249a3"></rect>';
            $svg .= '<text x="' . (int) ($badge_x + 8) . '" y="24" fill="#ffffff" font-size="14"'
                . ' font-weight="700" font-family="system-ui, sans-serif">'
                . esc_html($median_label) . '</text>';
        }

        $svg .= '<text x="16" y="' . (int) ($chart_top + ($chart_height / 2))
            . '" fill="#4a4a68" font-size="11" font-family="system-ui, sans-serif"'
            . ' transform="rotate(-90 16 ' . (int) ($chart_top + ($chart_height / 2)) . ')">'
            . esc_html(__('Number of diamonds', 'loupe-diamond-network')) . '</text>';

        $tick_indices = array(0, (int) floor($bin_count / 2), $bin_count);
        $seen = array();
        foreach ($tick_indices as $idx) {
            if ($idx < 0 || $idx > $bin_count || isset($seen[$idx])) {
                continue;
            }
            $seen[$idx] = true;
            $price = (float) $edges[$idx];
            $tick_x = $chart_left + (int) round((($price - $min_x) / $range_x) * $chart_width);
            $svg .= '<text x="' . (int) $tick_x . '" y="' . (int) ($chart_bottom + 22)
                . '" fill="#4a4a68" font-size="11" text-anchor="middle"'
                . ' font-family="system-ui, sans-serif">'
                . esc_html($currency . number_format($price, 0)) . '</text>';
        }

        $svg .= '</svg>';

        $out = '<figure class="ldn-ring-guide__figure">';
        $out .= '<a href="' . esc_url($url) . '">' . $svg . '</a>';
        if ($figure_note !== '') {
            $out .= $figure_note; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        $out .= '</figure>';
        return $out;
    }

    /**
     * @param array $match
     * @param array $guides
     * @return LDN_Page_Context
     */
    private function shape_price_context(array $match, array $guides) {
        $country = isset($guides['default_country']) ? (string) $guides['default_country'] : 'us';
        $type = isset($guides['default_type']) ? (string) $guides['default_type'] : 'natural';
        return new LDN_Page_Context(
            $this->site_id,
            'shape',
            $country,
            $type,
            $match['carat'],
            $match['shape'],
            'price'
        );
    }

    /**
     * @param array $match
     * @param array $guides
     * @return string|null
     */
    private function og_preview_url(array $match, array $guides) {
        $shape_kinds = array('shape', 'shape_hub');
        if (empty($guides['embed_og_preview'])
            || !in_array($match['kind'], $shape_kinds, true)
            || $match['shape'] === null
        ) {
            return null;
        }
        $country = isset($guides['default_country']) ? (string) $guides['default_country'] : 'us';
        $type = isset($guides['default_type']) ? (string) $guides['default_type'] : 'natural';
        $ctx = new LDN_Page_Context(
            $this->site_id,
            'shape',
            $country,
            $type,
            $match['carat'],
            $match['shape'],
            'price'
        );
        return $this->fetcher->resolve_artefact_url('og_preview_png', $ctx);
    }

    /**
     * @param array $match
     * @param array $guides
     * @return string HTML note paragraph, or ''.
     */
    private function price_figure_note(array $match, array $guides) {
        if ($match['kind'] === 'hub') {
            return $this->figure_note_html(
                esc_html(__('Open the live chart of prices by shape', 'loupe-diamond-network'))
            );
        }
        if (!in_array($match['kind'], array('shape', 'shape_hub'), true) || $match['shape'] === null) {
            return $this->figure_note_html(
                esc_html(sprintf(
                    /* translators: %s: color or colour */
                    __('Open the live chart and %s/clarity grid', 'loupe-diamond-network'),
                    strtolower(self::colour_word($guides))
                ))
            );
        }
        $summary = $this->fetcher->fetch_artefact(
            'summary_data_json',
            $this->shape_price_context($match, $guides)
        );
        $date = self::summary_analysis_date($summary);
        if ($date !== '') {
            $body = sprintf(
                /* translators: 1: market label, 2: <time> element */
                __('Live %1$s asking prices as of %2$s.', 'loupe-diamond-network'),
                esc_html(self::market_label($guides)),
                '<time datetime="' . esc_attr($date) . '">' . esc_html(self::format_date($date)) . '</time>'
            );
            return $this->figure_note_html($body);
        }
        return $this->figure_note_html(
            esc_html(sprintf(
                /* translators: %s: color or colour */
                __('Open the live chart and %s/clarity grid', 'loupe-diamond-network'),
                strtolower(self::colour_word($guides))
            ))
        );
    }

    /**
     * @param string $inner HTML-safe note body (may include <time>).
     * @return string
     */
    private function figure_note_html($inner) {
        if ((string) $inner === '') {
            return '';
        }
        return '<p class="ldn-ring-guide__figure-note">' . $inner . '</p>';
    }

    /**
     * @param mixed  $summary
     * @param string $key Percentile key, e.g. p25 or p50.
     * @return float|null
     */
    private static function summary_percentile($summary, $key) {
        if (!is_array($summary)) {
            return null;
        }
        $node = isset($summary['distribution']['percentiles'][$key])
            ? $summary['distribution']['percentiles'][$key]
            : null;
        if (is_numeric($node) && (float) $node > 0) {
            return (float) $node;
        }
        return null;
    }

    /**
     * @param string $carat
     * @param array  $structure
     * @return string
     */
    private function carat_url_slug($carat, array $structure) {
        $format = isset($structure['carat_format']) ? (string) $structure['carat_format'] : '{value}-carat';
        return str_replace('{value}', self::normalise_carat_label($carat), $format);
    }

    /**
     * @param string $path
     * @return string
     */
    private function home_path($path) {
        $path = '/' . ltrim((string) $path, '/');
        if (function_exists('user_trailingslashit')) {
            $path = user_trailingslashit($path);
        } elseif (substr($path, -1) !== '/') {
            $path .= '/';
        }
        if (function_exists('home_url')) {
            return home_url($path);
        }
        return $path;
    }

    /**
     * @param string|null $shape
     * @return string
     */
    private function shape_label($shape) {
        return ucwords(str_replace('-', ' ', (string) $shape));
    }

    /**
     * Short market name for inject headings ("US", "UK").
     *
     * @param array $guides
     * @return string
     */
    private static function market_label(array $guides) {
        if (!empty($guides['market_label'])) {
            return (string) $guides['market_label'];
        }
        $country = isset($guides['default_country'])
            ? strtolower((string) $guides['default_country'])
            : 'us';
        $labels = array('us' => 'US', 'uk' => 'UK');
        return isset($labels[$country]) ? $labels[$country] : strtoupper($country);
    }

    /**
     * Colour/Color for inject copy. British when the guide says so, or when
     * the default country is the UK. Ringspo stays American.
     *
     * @param array $guides
     * @return string
     */
    private static function colour_word(array $guides) {
        $spelling = isset($guides['spelling']) ? strtolower((string) $guides['spelling']) : '';
        $country = isset($guides['default_country'])
            ? strtolower((string) $guides['default_country'])
            : '';
        if ($spelling === 'british' || $country === 'uk') {
            return __('Colour', 'loupe-diamond-network');
        }
        return __('Color', 'loupe-diamond-network');
    }

    /**
     * @param array $match
     * @return string
     */
    private static function price_heading_regex(array $match) {
        if (!empty($match['price_heading'])) {
            return preg_quote((string) $match['price_heading'], '/');
        }
        $carat = preg_quote($match['carat'], '/');
        if ($match['kind'] === 'hub') {
            return $carat . '\s+carat\s+diamond\s+(?:ring\s+)?(?:price|prices|cost|costs)';
        }
        $infix = preg_quote((string) $match['infix'], '/');
        $infix_words = preg_quote(str_replace('-', ' ', (string) $match['infix']), '/');
        $shape = preg_quote(str_replace('-', ' ', (string) $match['shape']), '/');
        $names = '(?:' . $infix_words . '|' . $infix . '|' . $shape . ')';
        if ($match['kind'] === 'shape_hub') {
            return $names . '\s+diamond(?:s)?\s+(?:price|prices|cost|costs)';
        }
        return $carat . '\s+carat\s+' . $names . '\s+diamond(?:s)?\s+(?:price|prices|cost|costs)';
    }

    /**
     * @param array $match
     * @return string
     */
    private static function size_heading_regex(array $match) {
        $carat = preg_quote($match['carat'], '/');
        if ($match['kind'] === 'hub') {
            return $carat . '\s+carat\s+diamond\s+(?:ring\s+)?size';
        }
        $infix = preg_quote((string) $match['infix'], '/');
        $infix_words = preg_quote(str_replace('-', ' ', (string) $match['infix']), '/');
        $shape = preg_quote(str_replace('-', ' ', (string) $match['shape']), '/');
        $names = '(?:' . $infix_words . '|' . $infix . '|' . $shape . ')';
        if ($match['kind'] === 'shape_hub') {
            return $names . '\s+diamond(?:s)?\s+size';
        }
        return $carat . '\s+carat\s+' . $names . '\s+diamond(?:s)?\s+size';
    }

    /**
     * Price-section H2/H3 on the ten /{shape}-engagement-rings/ posts.
     *
     * Live wording, Aug 2026: nine say "well-priced", round says "most
     * expensive shape", pear says "less expensive".
     *
     * @return string
     */
    public static function shape_guide_price_heading_regex() {
        return '[^<]*?(?:well-priced|most expensive shape|less expensive)';
    }

    /**
     * Buying-guide carat H2/H3 on those same ten posts.
     *
     * Matches "Cushion cut diamonds carat weight", "Princess cut diamond
     * carat", "Round brilliant diamonds and Carat Weight". Does not match
     * "look large for their carat weight" (pear/marquise), because that
     * heading has words between "diamond(s)" and "carat".
     *
     * @return string
     */
    public static function shape_guide_size_heading_regex() {
        return '[^<]*?\bdiamond(?:s)?(?:\s+and)?\s+carat(?:\s+weight)?';
    }

    /**
     * @param string $html
     * @param string $insert
     * @param bool   $placed
     * @return string
     */
    private static function insert_after_compare($html, $insert, &$placed) {
        if ($placed || (string) $insert === '') {
            return $html;
        }
        return self::insert_after_first(
            $html,
            '#(<div\s+class="ldn-ring-guide__compare">.*?</div>)#is',
            $insert,
            $placed
        );
    }

    /**
     * @param string $html
     * @param string $heading_inner_regex
     * @param string $insert
     * @param bool   $placed
     * @return string
     */
    private static function insert_after_heading($html, $heading_inner_regex, $insert, &$placed) {
        if ($placed || (string) $insert === '') {
            return $html;
        }
        $pattern = '/(<(?:h2|h3)\b[^>]*>\s*(?:' . $heading_inner_regex . ')\s*<\/(?:h2|h3)>)/is';
        return self::insert_after_first($html, $pattern, $insert, $placed);
    }

    /**
     * @param string $html
     * @param string $pattern Full pattern whose first capture is kept.
     * @param string $insert
     * @param bool   $placed
     * @return string
     */
    private static function insert_after_first($html, $pattern, $insert, &$placed) {
        $count = 0;
        $out = preg_replace_callback(
            $pattern,
            function ($m) use ($insert) {
                return $m[1] . $insert;
            },
            $html,
            1,
            $count
        );
        if (is_string($out) && $count > 0) {
            $placed = true;
            return $out;
        }
        return $html;
    }

    /**
     * @param string $html
     * @param string $heading_inner_regex
     * @param string $replacement
     * @param int    $replaced
     * @return string
     */
    private static function replace_heading_section($html, $heading_inner_regex, $replacement, &$replaced) {
        return self::replace_heading_at_level($html, $heading_inner_regex, $replacement, $replaced, 2);
    }

    /**
     * Replace an H3 block through the next H2 or H3 (shape engagement guides).
     *
     * @param string $html
     * @param string $heading_inner_regex
     * @param string $replacement
     * @param int    $replaced
     * @return string
     */
    private static function replace_subheading_section($html, $heading_inner_regex, $replacement, &$replaced) {
        return self::replace_heading_at_level($html, $heading_inner_regex, $replacement, $replaced, 3);
    }

    /**
     * @param string $html
     * @param string $heading_inner_regex
     * @param string $replacement
     * @param int    $replaced
     * @param int    $level 2 or 3
     * @return string
     */
    private static function replace_heading_at_level($html, $heading_inner_regex, $replacement, &$replaced, $level) {
        $tag = 'h' . $level;
        $stop = $level === 2 ? '<h2\b' : '<h[23]\b';
        $pattern = '/<' . $tag . '\b[^>]*>\s*(?:' . $heading_inner_regex . ')\s*<\/' . $tag . '>.*?(?=' . $stop . '|$)/is';
        $count = 0;
        // Literal insert: prices like $3,510 must not be read as PCRE $3 backrefs.
        $literal = str_replace(array('\\', '$'), array('\\\\', '\\$'), (string) $replacement);
        $out = preg_replace($pattern, $literal, $html, 1, $count);
        if (is_string($out) && $count > 0) {
            $replaced += $count;
            return $out;
        }
        return $html;
    }
}

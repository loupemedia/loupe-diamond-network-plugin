<?php
/**
 * Inject live price/size panels onto Ringspo's legacy WP ring-buying guides.
 *
 * Those posts live at /{n}-carat-…-diamond-ring/ (not LDN rewrite routes).
 * Matching permalinks are listed on the site's content profile under
 * `editorial_ring_guides`. The inject strips stale Ninja Tables, replaces the
 * Price and Size H2 sections when those headings are present, and otherwise
 * prepends the panel so staging still shows the live links.
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Editorial_Bridge {

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
        add_filter('the_content', array($this, 'filter_content'), 20);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
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
        return self::splice_panels($html, $match, $panels);
    }

    /**
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
     * @param string $html
     * @return string
     */
    public static function strip_ninja_tables($html) {
        $stripped = preg_replace('/\[ninja_tables?[^\]]*\]/i', '', (string) $html);
        return is_string($stripped) ? $stripped : (string) $html;
    }

    /**
     * Replace Price/Size H2 sections when present; otherwise prepend the combined panel.
     *
     * @param string $html
     * @param array  $match
     * @param array{price:string,size:string,combined:string} $panels
     * @return string
     */
    public static function splice_panels($html, array $match, array $panels) {
        $replaced = 0;
        $out = self::replace_heading_section($html, self::price_heading_regex($match), $panels['price'], $replaced);
        $out = self::replace_heading_section($out, self::size_heading_regex($match), $panels['size'], $replaced);
        if ($replaced === 0) {
            return $panels['combined'] . $out;
        }
        return $out;
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
        $og_url = $this->og_preview_url($match, $guides);
        $caption = $this->price_caption($match, $guides);

        $price = $this->price_card_html($match, $price_url, $og_url, $caption);
        $size = $this->size_card_html($match, $size_url);
        $combined = '<aside class="ldn-ring-guide">' . $price . $size . '</aside>';
        return array(
            'price'     => $price,
            'size'      => $size,
            'combined'  => $combined,
        );
    }

    /**
     * @param array       $match
     * @param string      $url
     * @param string|null $og_url
     * @param string      $caption
     * @return string
     */
    private function price_card_html(array $match, $url, $og_url, $caption) {
        // Complementary H2, not the LDN head term ("4 carat … diamond price").
        $heading = __('Current US prices', 'loupe-diamond-network');
        $lead = __(
            'Colour, clarity and cut quality move the price a long way - two stones with the same carat can be very different values.',
            'loupe-diamond-network'
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
        if ($og_url !== null && $og_url !== '') {
            $alt = sprintf(
                /* translators: 1: carat, 2: shape or "all shapes" */
                __('Price distribution for %1$s carat %2$s diamonds (US)', 'loupe-diamond-network'),
                $match['carat'],
                $match['kind'] === 'hub' ? __('all shapes', 'loupe-diamond-network') : $this->shape_label($match['shape'])
            );
            $out .= '<figure class="ldn-ring-guide__figure">';
            $out .= '<a href="' . esc_url($url) . '">';
            $out .= '<img src="' . esc_url($og_url) . '" alt="' . esc_attr($alt) . '" width="1200" height="630" loading="lazy">';
            $out .= '</a>';
            if ($caption !== '') {
                $out .= '<figcaption>' . esc_html($caption) . '</figcaption>';
            }
            $out .= '</figure>';
        }
        $out .= '<p><a class="ldn-ring-guide__cta" href="' . esc_url($url) . '">'
            . esc_html($anchor)
            . ' <span aria-hidden="true">→</span></a></p>';
        $out .= '</section>';
        return $out;
    }

    /**
     * @param array  $match
     * @param string $url
     * @return string
     */
    private function size_card_html(array $match, $url) {
        // Complementary H2, not the LDN head term ("4 carat … diamond size").
        $heading = __('How big it looks', 'loupe-diamond-network');
        $lead = __(
            'Carat is weight, not diameter. How large the stone looks depends on shape, depth and whether it is square or elongated.',
            'loupe-diamond-network'
        );
        $anchor = $match['kind'] === 'hub'
            ? __('Diamond size chart (all shapes)', 'loupe-diamond-network')
            : sprintf(
                /* translators: 1: carat weight, 2: shape */
                __('%1$s carat %2$s diamond size', 'loupe-diamond-network'),
                $match['carat'],
                $this->shape_label($match['shape'])
            );

        $out = '<section class="ldn-ring-guide__card ldn-ring-guide__card--size">';
        $out .= '<h2>' . esc_html($heading) . '</h2>';
        $out .= '<p>' . esc_html($lead) . '</p>';
        $out .= '<p><a class="ldn-ring-guide__cta" href="' . esc_url($url) . '">'
            . esc_html($anchor)
            . ' <span aria-hidden="true">→</span></a></p>';
        $out .= '</section>';
        return $out;
    }

    /**
     * @param array $match
     * @param array $guides
     * @return string
     */
    private function price_url(array $match, array $guides) {
        $structure = $this->config->get_url_structure($this->site_id);
        if (!is_array($structure)) {
            return '';
        }
        $country = isset($guides['default_country']) ? (string) $guides['default_country'] : 'us';
        $type_key = isset($guides['default_type']) ? (string) $guides['default_type'] : 'natural';
        $type_slug = $type_key === 'lab-grown' && !empty($structure['type_lab'])
            ? (string) $structure['type_lab']
            : (string) ($structure['type_natural'] ?? 'natural');
        $carat_slug = $this->carat_url_slug($match['carat'], $structure);
        $level = $match['kind'] === 'hub' ? 'level_3' : 'level_4';
        $pattern = isset($structure[$level]) ? (string) $structure[$level] : '';
        if ($pattern === '') {
            return '';
        }
        $path = strtr($pattern, array(
            '{country}' => $country,
            '{type}'    => $type_slug,
            '{carat}'   => $carat_slug,
            '{shape}'   => $match['shape'] !== null
                ? $this->config->shape_to_url_slug($match['shape'], $this->site_id)
                : '',
        ));
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
        $pattern = is_array($structure) && !empty($structure['size_level_3'])
            ? (string) $structure['size_level_3']
            : '/diamond-size/{shape}/{carat}';
        $path = str_replace(
            array('{shape}', '{carat}'),
            array(
                $this->config->shape_to_url_slug($match['shape'], $this->site_id),
                self::normalise_carat_label($match['carat']) . '-carat',
            ),
            $pattern
        );
        return $this->home_path($path);
    }

    /**
     * @param array $match
     * @param array $guides
     * @return string|null
     */
    private function og_preview_url(array $match, array $guides) {
        if (empty($guides['embed_og_preview']) || $match['kind'] !== 'shape' || $match['shape'] === null) {
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
     * @return string
     */
    private function price_caption(array $match, array $guides) {
        if ($match['kind'] !== 'shape' || $match['shape'] === null) {
            return __('Open the live chart and colour/clarity grid', 'loupe-diamond-network');
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
        $summary = $this->fetcher->fetch_artefact('summary_data_json', $ctx);
        $date = '';
        if (is_array($summary)) {
            if (isset($summary['metadata']['analysis_date']) && is_string($summary['metadata']['analysis_date'])) {
                $date = $summary['metadata']['analysis_date'];
            } elseif (isset($summary['_meta']['analysis_date']) && is_string($summary['_meta']['analysis_date'])) {
                $date = $summary['_meta']['analysis_date'];
            }
        }
        if ($date !== '') {
            return sprintf(
                /* translators: %s: analysis date */
                __('Live US asking-price distribution as of %s - open the full chart', 'loupe-diamond-network'),
                $date
            );
        }
        return __('Open the live chart and colour/clarity grid', 'loupe-diamond-network');
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
     * @param array $match
     * @return string
     */
    private static function price_heading_regex(array $match) {
        $carat = preg_quote($match['carat'], '/');
        if ($match['kind'] === 'hub') {
            return $carat . '\s+carat\s+diamond\s+(?:ring\s+)?(?:price|prices|cost|costs)';
        }
        $infix = preg_quote((string) $match['infix'], '/');
        $infix_words = preg_quote(str_replace('-', ' ', (string) $match['infix']), '/');
        $shape = preg_quote(str_replace('-', ' ', (string) $match['shape']), '/');
        return $carat . '\s+carat\s+(?:' . $infix . '|' . $infix_words . '|' . $shape . ')\s+diamond(?:s)?\s+(?:price|prices|cost|costs)';
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
        return $carat . '\s+carat\s+(?:' . $infix . '|' . $infix_words . '|' . $shape . ')\s+diamond(?:s)?\s+size';
    }

    /**
     * @param string $html
     * @param string $heading_inner_regex
     * @param string $replacement
     * @param int    $replaced
     * @return string
     */
    private static function replace_heading_section($html, $heading_inner_regex, $replacement, &$replaced) {
        $pattern = '/<h2\b[^>]*>\s*(?:' . $heading_inner_regex . ')\s*<\/h2>.*?(?=<h2\b|$)/is';
        $count = 0;
        $out = preg_replace($pattern, $replacement, $html, 1, $count);
        if (is_string($out) && $count > 0) {
            $replaced += $count;
            return $out;
        }
        return $html;
    }
}

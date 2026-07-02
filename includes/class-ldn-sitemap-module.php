<?php
/**
 * Sitemap routing — price (registry), size (S3 artefact), and combined index.
 *
 * Uses the same XML envelope (LDN_Sitemap) and HTTP response path for all
 * module sitemaps so crawlers see a consistent format.
 *
 * @package LoupeDiamondNetwork
 * @since   0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Sitemap_Module {

    const ROUTE = 'sitemap';

    const QUERY_VARS = array(
        'ldn_route',
        'ldn_sitemap_kind',
        'ldn_country',
    );

    /** @var string */
    private $site_id;

    /** @var LDN_Config */
    private $config;

    /** @var LDN_Rollout_Reader|null */
    private $rollout;

    /** @var LDN_Data_Fetcher */
    private $fetcher;

    /** @var LDN_Page_Registry */
    private $registry;

    /**
     * @param string                  $site_id
     * @param LDN_Config              $config
     * @param LDN_Rollout_Reader|null $rollout
     * @param LDN_Data_Fetcher        $fetcher
     * @param LDN_Page_Registry|null  $registry
     */
    public function __construct(
        $site_id,
        LDN_Config $config,
        $rollout,
        LDN_Data_Fetcher $fetcher,
        LDN_Page_Registry $registry = null
    ) {
        $this->site_id = (string) $site_id;
        $this->config = $config;
        $this->rollout = $rollout;
        $this->fetcher = $fetcher;
        $this->registry = $registry instanceof LDN_Page_Registry ? $registry : new LDN_Page_Registry();
    }

    /**
     * @param LDN_Plugin $plugin
     * @return void
     */
    public static function register(LDN_Plugin $plugin) {
        if (!$plugin->is_network_site()) {
            return;
        }
        $site_id = $plugin->site_id();
        if ($site_id === null) {
            return;
        }
        $module = new self(
            $site_id,
            $plugin->config(),
            $plugin->rollout(),
            $plugin->data_fetcher()
        );
        $module->hook();
    }

    /**
     * @return void
     */
    public function hook() {
        add_filter('query_vars', array($this, 'register_query_vars'));
        add_action('init', array($this, 'add_rules'));
        add_filter('template_include', array($this, 'dispatch'), 5);
    }

    /**
     * @param array $vars
     * @return array
     */
    public function register_query_vars($vars) {
        return array_merge($vars, self::QUERY_VARS);
    }

    /**
     * @return void
     */
    public function add_rules() {
        foreach ($this->build_rules() as $regex => $query) {
            add_rewrite_rule($regex, $query, 'top');
        }
    }

    /**
     * @return array<string, string>
     */
    public function build_rules() {
        $rules = array();

        $index_path = $this->config->network_sitemap_index_path();
        if ($index_path !== '') {
            $rules[$this->pattern_to_regex($index_path)] =
                'index.php?ldn_route=' . self::ROUTE . '&ldn_sitemap_kind=index';
        }

        $price_path = $this->config->price_sitemap_path($this->site_id);
        if (is_string($price_path) && $price_path !== '') {
            $rules = array_merge($rules, $this->rules_for_path($price_path, 'price'));
        }

        $size_path = $this->config->size_sitemap_path($this->site_id);
        if (is_string($size_path) && $size_path !== '') {
            $rules[$this->pattern_to_regex($size_path)] =
                'index.php?ldn_route=' . self::ROUTE . '&ldn_sitemap_kind=size';
        }

        return $rules;
    }

    /**
     * @param string $path
     * @param string $kind
     * @return array<string, string>
     */
    private function rules_for_path($path, $kind) {
        if (strpos($path, '{country}') === false) {
            return array(
                $this->pattern_to_regex($path) =>
                    'index.php?ldn_route=' . self::ROUTE . '&ldn_sitemap_kind=' . $kind,
            );
        }

        $countries = $this->enabled_countries_for_kind($kind);
        $rules = array();
        foreach ($countries as $country) {
            $concrete = str_replace('{country}', $country, $path);
            $rules[$this->pattern_to_regex($concrete)] =
                'index.php?ldn_route=' . self::ROUTE
                . '&ldn_sitemap_kind=' . $kind
                . '&ldn_country=' . $country;
        }
        return $rules;
    }

    /**
     * @param string $kind price|size
     * @return string[]
     */
    private function enabled_countries_for_kind($kind) {
        if (!$this->rollout instanceof LDN_Rollout_Reader) {
            return array();
        }
        $module = $kind === 'size' ? 'size' : 'price';
        $out = array();
        foreach ($this->rollout->enabled_countries() as $country) {
            if ($this->rollout->is_enabled($country, $module)) {
                $out[] = $country;
            }
        }
        return $out;
    }

    /**
     * @param string $template
     * @return string
     */
    public function dispatch($template) {
        if (get_query_var('ldn_route') !== self::ROUTE) {
            return $template;
        }

        $kind = (string) get_query_var('ldn_sitemap_kind');
        $country = (string) get_query_var('ldn_country');
        $country = $country !== '' ? strtolower($country) : null;

        $xml = '';
        switch ($kind) {
            case 'index':
                $xml = $this->render_index();
                break;
            case 'price':
                $xml = $this->render_price_sitemap($country);
                break;
            case 'size':
                $xml = $this->render_size_sitemap();
                break;
            default:
                $this->emit_404();
                return $template;
        }

        if ($xml === '') {
            LDN_Diagnostics::note('Sitemap empty or unavailable: ' . $kind);
            $this->emit_404();
            return $template;
        }

        $this->emit_xml($xml);
        exit;
    }

    /**
     * @return string
     */
    private function render_index() {
        $child_urls = array();
        $base = function_exists('home_url') ? rtrim((string) home_url('/'), '/') : '';

        $price_path = $this->config->price_sitemap_path($this->site_id);
        if (is_string($price_path) && $price_path !== '' && $this->has_enabled_module('price')) {
            if (strpos($price_path, '{country}') !== false) {
                foreach ($this->enabled_countries_for_kind('price') as $country) {
                    $path = str_replace('{country}', $country, $price_path);
                    $child_urls[] = $base . $path;
                }
            } else {
                $child_urls[] = $base . $price_path;
            }
        }

        $size_path = $this->config->size_sitemap_path($this->site_id);
        if (is_string($size_path) && $size_path !== '' && $this->has_enabled_module('size')) {
            $child_urls[] = $base . $size_path;
        }

        $child_urls = array_values(array_unique(array_filter($child_urls)));
        if (empty($child_urls)) {
            return '';
        }
        return LDN_Sitemap::sitemap_index($child_urls);
    }

    /**
     * @param string|null $country
     * @return string
     */
    private function render_price_sitemap($country) {
        if (!$this->has_enabled_module('price')) {
            return '';
        }

        $countries = array();
        if ($country !== null) {
            if (!$this->rollout instanceof LDN_Rollout_Reader
                || !$this->rollout->is_enabled($country, 'price')
            ) {
                return '';
            }
            $countries = array($country);
        } else {
            $countries = $this->enabled_countries_for_kind('price');
        }

        $rows = $this->registry->fetch_sitemap_rows($this->site_id, $countries, 4);
        if (empty($rows)) {
            return '';
        }

        return LDN_Sitemap::urlset_from_rows($rows);
    }

    /**
     * @return string
     */
    private function render_size_sitemap() {
        if (!$this->has_enabled_module('size')) {
            return '';
        }

        $country = $this->config->size_rollout_country($this->site_id);
        if ($country === null || $country === '') {
            return '';
        }
        if ($this->rollout instanceof LDN_Rollout_Reader
            && !$this->rollout->is_enabled($country, 'size')
        ) {
            return '';
        }

        $ctx = new LDN_Page_Context(
            $this->site_id,
            'size-sitemap',
            $country,
            null,
            null,
            null,
            'size'
        );

        $raw = $this->fetcher->fetch_artefact_html('size_sitemap_xml', $ctx);
        if (!is_string($raw) || trim($raw) === '') {
            return '';
        }

        return LDN_Sitemap::normalise_urlset_xml($raw);
    }

    /**
     * @param string $module
     * @return bool
     */
    private function has_enabled_module($module) {
        if (!$this->rollout instanceof LDN_Rollout_Reader) {
            return false;
        }
        foreach ($this->rollout->enabled_countries() as $country) {
            if ($this->rollout->is_enabled($country, $module)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $path
     * @return string
     */
    private function pattern_to_regex($path) {
        $path = '/' . ltrim($path, '/');
        $regex = preg_quote($path, '#');
        $regex = str_replace('\{country\}', '([^/]+)', $regex);
        return '^' . $regex . '$';
    }

    /**
     * @param string $xml
     * @return void
     */
    private function emit_xml($xml) {
        if (function_exists('status_header')) {
            status_header(200);
        }
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        header('Content-Type: application/xml; charset=UTF-8');
        echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * @return void
     */
    private function emit_404() {
        global $wp_query;
        if ($wp_query instanceof WP_Query) {
            $wp_query->set_404();
        }
        if (function_exists('status_header')) {
            status_header(404);
        }
    }
}

add_action('ldn_booted', array('LDN_Sitemap_Module', 'register'));

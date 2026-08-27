<?php
/**
 * Legal page router — new network URLs, never the old WordPress slugs.
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Standard_Pages_Router {

    const MODULE = 'legal';

    const QUERY_VARS = array(
        'ldn_route',
        'ldn_country',
        'ldn_legal_page',
    );

    /** @var string */
    private $site_id;

    /** @var LDN_Rollout_Reader|null */
    private $rollout;

    /** @var LDN_Config */
    private $config;

    /**
     * @param string                  $site_id
     * @param LDN_Rollout_Reader|null $rollout
     * @param LDN_Config              $config
     */
    public function __construct($site_id, $rollout, LDN_Config $config) {
        $this->site_id = (string) $site_id;
        $this->rollout = $rollout;
        $this->config = $config;
    }

    /**
     * @return void
     */
    public function register() {
        add_filter('query_vars', array($this, 'register_query_vars'));
        add_action('init', array($this, 'add_rules'), 4);
    }

    /**
     * @param array $vars
     * @return array
     */
    public function register_query_vars($vars) {
        foreach (self::QUERY_VARS as $name) {
            if (!in_array($name, $vars, true)) {
                $vars[] = $name;
            }
        }
        return $vars;
    }

    /**
     * @return void
     */
    public function add_rules() {
        foreach ($this->build_rewrite_rules() as $regex => $query) {
            add_rewrite_rule($regex, $query, 'top');
        }
    }

    /**
     * @return array<string, string>
     */
    public function build_rewrite_rules() {
        $pages = $this->legal_pages();
        if ($pages === array()) {
            return array();
        }

        $countries = $this->enabled_countries();
        if (method_exists($this->config, 'countries_for_install')) {
            $countries = $this->config->countries_for_install($this->site_id, $countries);
        }
        if ($countries === array()) {
            return array();
        }

        $rules = array();
        $default = $countries[0];
        foreach ($pages as $page_id => $path) {
            $slug = trim((string) $path, '/');
            if ($slug === '') {
                continue;
            }
            $quoted = preg_quote($slug, '#');
            $rules['^' . $quoted . '/?$'] = 'index.php?ldn_route=' . self::MODULE
                . '&ldn_legal_page=' . rawurlencode($page_id)
                . '&ldn_country=' . rawurlencode($default);
            foreach ($countries as $country) {
                $rules['^' . preg_quote($country, '#') . '/' . $quoted . '/?$'] =
                    'index.php?ldn_route=' . self::MODULE
                    . '&ldn_legal_page=' . rawurlencode($page_id)
                    . '&ldn_country=' . rawurlencode($country);
            }
        }
        return $rules;
    }

    /**
     * @return array<string, string> page_id => path
     */
    public function legal_pages() {
        $catalogue = $this->config->get_standard_pages();
        $pages = isset($catalogue['pages']) && is_array($catalogue['pages'])
            ? $catalogue['pages']
            : array();
        $out = array();
        foreach ($pages as $page_id => $spec) {
            if (!is_array($spec) || (isset($spec['page_class']) ? $spec['page_class'] : '') !== 'legal') {
                continue;
            }
            $path = isset($spec['path']) ? (string) $spec['path'] : '';
            if ($path === '' || strpos($path, 'privacy-policy') !== false
                || strpos($path, 'terms-conditions') !== false
            ) {
                continue;
            }
            $out[(string) $page_id] = $path;
        }
        return $out;
    }

    /**
     * @return string[]
     */
    private function enabled_countries() {
        if (!is_object($this->rollout) || !method_exists($this->rollout, 'enabled_countries')
            || !method_exists($this->rollout, 'is_enabled')
        ) {
            return array();
        }
        $countries = array();
        foreach ($this->rollout->enabled_countries() as $code) {
            $code = strtolower((string) $code);
            if ($this->rollout->is_enabled($code, 'standard_pages')
                || $this->rollout->is_enabled($code, 'price')
                || $this->rollout->is_enabled($code, 'size')
            ) {
                $countries[] = $code;
            }
        }
        return $countries;
    }
}

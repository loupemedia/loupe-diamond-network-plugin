<?php
/**
 * Calculator destination router — standalone /diamond-prices/calculator/ page.
 *
 * Registers before generic price level-2 rules so ``/us/diamond-prices/calculator``
 * is not captured as diamond-type ``calculator``.
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Calculator_Router {

    const MODULE = 'calculator';

    const QUERY_VARS = array(
        'ldn_route',
        'ldn_country',
    );

    /** @var string */
    private $site_id;

    /** @var LDN_Rollout_Reader|null */
    private $rollout;

    /** @var LDN_Config */
    private $config;

    /** @var LDN_Artefacts */
    private $artefacts;

    /**
     * @param string                  $site_id
     * @param LDN_Rollout_Reader|null $rollout
     * @param LDN_Config              $config
     * @param LDN_Artefacts           $artefacts
     */
    public function __construct($site_id, $rollout, LDN_Config $config, LDN_Artefacts $artefacts) {
        $this->site_id = (string) $site_id;
        $this->rollout = $rollout;
        $this->config = $config;
        $this->artefacts = $artefacts;
    }

    /**
     * @return void
     */
    public function register() {
        add_filter('query_vars', array($this, 'register_query_vars'));
        add_action('init', array($this, 'add_rules'), 5);
    }

    /**
     * @return bool
     */
    public function is_live() {
        if (!$this->rollout instanceof LDN_Rollout_Reader) {
            return false;
        }
        if (!$this->artefacts->site_has_wp_widget($this->site_id, 'price_calculator', 'calculator')) {
            return false;
        }
        $country = $this->default_country();
        return $country !== null && $this->rollout->is_enabled($country, 'price');
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
        if (!$this->is_live()) {
            return;
        }
        foreach ($this->build_rewrite_rules() as $regex => $query) {
            add_rewrite_rule($regex, $query, 'top');
        }
    }

    /**
     * @return array<string, string>
     */
    public function build_rewrite_rules() {
        $structure = $this->config->get_url_structure($this->site_id);
        $pattern = is_array($structure) && !empty($structure['calculator_level'])
            ? (string) $structure['calculator_level']
            : '/{country}/diamond-prices/calculator';

        $rules = array();
        foreach ($this->enabled_countries() as $country) {
            $compiled = $this->compile_pattern($pattern, $country);
            if ($compiled !== null) {
                $rules[$compiled[0]] = $compiled[1];
            }
        }
        return $rules;
    }

    /**
     * @param string $pattern
     * @param string $country
     * @return array{0:string,1:string}|null
     */
    public function compile_pattern($pattern, $country) {
        $segments = array_values(array_filter(explode('/', trim($pattern, '/')), 'strlen'));
        $regex_parts = array();
        foreach ($segments as $segment) {
            if ($segment === '{country}') {
                $regex_parts[] = preg_quote($country, '#');
                continue;
            }
            $regex_parts[] = preg_quote($segment, '#');
        }
        if ($regex_parts === array()) {
            return null;
        }
        $regex = '^' . implode('/', $regex_parts) . '/?$';
        $query = 'index.php?ldn_route=' . self::MODULE . '&ldn_country=' . rawurlencode($country);
        return array($regex, $query);
    }

    /**
     * @return string[]
     */
    private function enabled_countries() {
        if (!$this->rollout instanceof LDN_Rollout_Reader) {
            return array();
        }
        $countries = array();
        foreach ($this->rollout->enabled_countries() as $code) {
            if ($this->rollout->is_enabled($code, 'price')) {
                $countries[] = strtolower((string) $code);
            }
        }
        return $countries;
    }

    /**
     * @return string|null
     */
    private function default_country() {
        $site = $this->config->get_site($this->site_id);
        if (!is_array($site)) {
            return null;
        }
        if (!empty($site['canonical_source_country'])) {
            return strtolower((string) $site['canonical_source_country']);
        }
        $countries = $this->enabled_countries();
        return $countries !== array() ? $countries[0] : null;
    }
}

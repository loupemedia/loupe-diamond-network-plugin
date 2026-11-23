<?php
/**
 * Finished jewellery router (PRD-020 CP 135).
 *
 * Compiles WordPress rewrite rules for finished diamond jewellery routes from
 * `url_structures.yaml` (`jewellery_*` and `jewellery_lab_*`), gated on:
 *   1. `jewellery_summary_json` entitlement for this site (`site_content_entitlements.yaml`)
 *   2. `jewellery_module.rollout_countries` on the site config (`['us']` in v1)
 *   3. Rollout reader: `jewellery` is on for that country (price being on does not publish jewellery)
 *
 * Priority 5 on `init` ensures dedicated jewellery prefixes (`/us/jewelry`,
 * `/us/earrings/...`, `/us/lab-grown/...`) register ahead of `LDN_Router`
 * `flat_leaf` loose-diamond shape/carat captures (`/{country}/{shape}`).
 * Category captures are constrained to `(earrings|bracelets|necklaces)` so
 * loose-diamond slugs such as `/us/round-cut` or `/us/1-carat` never match here.
 *
 * @package LoupeDiamondNetwork
 * @since   0.44.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Jewellery_Router {

    const MODULE = 'jewellery';

    const CATEGORIES = array('earrings', 'bracelets', 'necklaces');

    const QUERY_VARS = array(
        'ldn_route',
        'ldn_country',
        'ldn_jewellery_type',
        'ldn_jewellery_kind',
        'ldn_jewellery_category',
        'ldn_jewellery_subpath',
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
     * @param string[] $vars
     * @return string[]
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
     * Whether this site is entitled to publish finished jewellery routes.
     *
     * @return bool
     */
    public function is_entitled() {
        return $this->artefacts->site_entitled_to_artefact(
            $this->site_id,
            'jewellery_summary_json'
        );
    }

    /**
     * Whether this site's profile publishes the `/lab-grown/` jewellery tree.
     *
     * @param array $structure
     * @return bool
     */
    public function lab_tree_enabled(array $structure) {
        if (empty($structure['jewellery_type_lab']) || !is_string($structure['jewellery_type_lab'])) {
            return false;
        }
        $profile = $this->config->get_content_profile($this->site_id);
        if (!is_array($profile)
            || empty($profile['jewellery_surface'])
            || !is_array($profile['jewellery_surface'])
        ) {
            return false;
        }
        return !empty($profile['jewellery_surface']['publish_lab_tree']);
    }

    /**
     * Countries enabled for finished jewellery on this install.
     *
     * Intersects `jewellery_module.rollout_countries` (US-only in v1) with
     * countries whose hub `jewellery` flag is on. Price being on does not
     * publish these routes.
     *
     * @return string[]
     */
    public function enabled_countries() {
        if (!$this->is_entitled() || !($this->rollout instanceof LDN_Rollout_Reader)) {
            return array();
        }
        $allowed = method_exists($this->config, 'jewellery_rollout_countries')
            ? $this->config->jewellery_rollout_countries($this->site_id)
            : array();
        if ($allowed === array()) {
            return array();
        }

        $out = array();
        foreach ($this->rollout->enabled_countries() as $country) {
            $code = strtolower((string) $country);
            if (!in_array($code, $allowed, true)) {
                continue;
            }
            if ($this->rollout->is_enabled($code, 'jewellery')) {
                $out[] = $code;
            }
        }
        if (method_exists($this->config, 'countries_for_install')) {
            $out = $this->config->countries_for_install($this->site_id, $out);
        }
        return array_values(array_unique($out));
    }

    /**
     * Build rewrite rules for all enabled jewellery countries on this site.
     *
     * Lab-grown rules come first so `/us/lab-grown/...` and
     * `/us/diamond-jewelry-prices/lab-grown/...` always resolve before natural
     * patterns.
     *
     * @return array<string, string>
     */
    public function build_rewrite_rules() {
        $structure = $this->config->get_url_structure($this->site_id);
        if (!is_array($structure)) {
            return array();
        }

        $countries = $this->enabled_countries();
        if ($countries === array()) {
            return array();
        }

        $rules = array();
        $publish_lab = $this->lab_tree_enabled($structure);
        $lab_slug = $publish_lab ? (string) $structure['jewellery_type_lab'] : '';

        foreach ($countries as $country) {
            if ($publish_lab && $lab_slug !== '') {
                $lab_specs = array(
                    'jewellery_lab_level_1'        => 'hub',
                    'jewellery_lab_level_mens_cat' => 'mens_cat',
                    'jewellery_lab_level_mens'     => 'mens',
                    'jewellery_lab_level_2'        => 'category',
                    'jewellery_lab_level_3'        => 'subpath',
                );
                foreach ($lab_specs as $key => $kind) {
                    if (empty($structure[$key]) || !is_string($structure[$key])) {
                        continue;
                    }
                    $compiled = $this->compile_pattern(
                        $structure[$key],
                        $country,
                        'lab-grown',
                        $kind,
                        $lab_slug
                    );
                    if ($compiled !== null) {
                        $rules[$compiled[0]] = $compiled[1];
                    }
                }
            }

            $nat_specs = array(
                'jewellery_level_1'        => 'hub',
                'jewellery_level_mens_cat' => 'mens_cat',
                'jewellery_level_mens'     => 'mens',
                'jewellery_level_2'        => 'category',
                'jewellery_level_3'        => 'subpath',
            );
            foreach ($nat_specs as $key => $kind) {
                if (empty($structure[$key]) || !is_string($structure[$key])) {
                    continue;
                }
                $compiled = $this->compile_pattern(
                    $structure[$key],
                    $country,
                    'natural',
                    $kind,
                    ''
                );
                if ($compiled !== null) {
                    $rules[$compiled[0]] = $compiled[1];
                }
            }
        }

        return $rules;
    }

    /**
     * Compile one jewellery URL pattern into `[regex, query]`.
     *
     * @param string $pattern
     * @param string $country
     * @param string $diamond_type 'natural' | 'lab-grown'
     * @param string $kind         'hub' | 'category' | 'subpath' | 'mens_cat' | 'mens'
     * @param string $type_slug    Literal `{jewellery_type}` replacement when lab.
     * @return array{0:string,1:string}|null
     */
    public function compile_pattern($pattern, $country, $diamond_type, $kind, $type_slug = '') {
        if ($type_slug !== '') {
            $pattern = str_replace('{jewellery_type}', $type_slug, $pattern);
        }
        if (method_exists($this->config, 'install_relative_pattern')) {
            $pattern = $this->config->install_relative_pattern($pattern, $country);
        }
        if ($pattern === '' || $pattern === '/') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim($pattern, '/')), 'strlen'));
        if ($segments === array()) {
            return null;
        }

        $regex_parts = array();
        $query = array(
            'ldn_route'          => self::MODULE,
            'ldn_country'        => $country,
            'ldn_jewellery_type' => $diamond_type,
            'ldn_jewellery_kind' => $kind,
        );
        $capture = 0;
        $cat_alt = '(' . implode('|', array_map(function ($c) {
            return preg_quote($c, '#');
        }, self::CATEGORIES)) . ')';

        foreach ($segments as $segment) {
            if ($segment === '{country}') {
                $regex_parts[] = preg_quote($country, '#');
                continue;
            }
            if ($segment === '{category}') {
                $regex_parts[] = $cat_alt;
                $query['ldn_jewellery_category'] = '$matches[' . (++$capture) . ']';
                continue;
            }
            if ($segment === '{subpath}') {
                $regex_parts[] = '([a-z0-9][a-z0-9\-]*(?:/[a-z0-9][a-z0-9\-]*)*)';
                $query['ldn_jewellery_subpath'] = '$matches[' . (++$capture) . ']';
                continue;
            }
            $regex_parts[] = preg_quote($segment, '#');
        }

        $regex = '^' . implode('/', $regex_parts) . '/?$';
        $pairs = array();
        foreach ($query as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        return array($regex, 'index.php?' . implode('&', $pairs));
    }
}

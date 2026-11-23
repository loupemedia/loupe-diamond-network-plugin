<?php
/**
 * Loose diamond shopping router (PRD-025 CP 166).
 *
 * Compiles WordPress rewrite rules for `/{country}/diamonds/...` shopping card
 * routes from `url_structures.yaml` (`diamonds_level_*`), gated on:
 *   1. `diamond_cards_json` entitlement (`site_content_entitlements.yaml`)
 *   2. `loose_diamonds_module.enabled` + `pages.diamonds.countries` (`['us']` in v1)
 *   3. Rollout reader: `diamonds` is on for that country (price being on does not publish selection pages)
 *
 * Priority 5 on `init` ensures dedicated `/{country}/diamonds/...` rules win ahead
 * of generic price rules. Captures are strictly constrained to the 10 canonical
 * shapes, 21 commercial carat bands and 7 approved modifier slugs from
 * `shared/stones/ia.py` - budget (`/under-$X/`) and growth-method (`/hpht/`, `/cvd/`)
 * slugs never compile or match.
 *
 * @package LoupeDiamondNetwork
 * @since   0.45.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Diamonds_Router {

    const MODULE = 'diamonds';

    const SHAPES = array(
        'round',
        'oval',
        'cushion',
        'emerald',
        'princess',
        'pear',
        'radiant',
        'marquise',
        'asscher',
        'heart',
    );

    const CARAT_LABELS = array(
        '0.3',
        '0.4',
        '0.5',
        '0.6',
        '0.7',
        '0.8',
        '0.9',
        '1',
        '1.5',
        '2',
        '2.5',
        '3',
        '4',
        '5',
        '6',
        '7',
        '8',
        '9',
        '10',
        '11',
        '12',
    );

    const MODIFIERS = array(
        'elongated-cushion',
        'elongated-radiant',
        'eye-clean',
        'colorless',
        'near-colorless',
        'gia-certified',
        'igi-certified',
    );

    const CARAT_ELIGIBLE_MODIFIERS = array(
        'elongated-cushion',
        'elongated-radiant',
    );

    const QUERY_VARS = array(
        'ldn_route',
        'ldn_country',
        'ldn_diamonds_kind',
        'ldn_diamonds_type',
        'ldn_diamonds_carat',
        'ldn_diamonds_shape',
        'ldn_diamonds_modifier',
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
     * @return bool
     */
    public function is_entitled() {
        return $this->artefacts->site_entitled_to_artefact(
            $this->site_id,
            'diamond_cards_json'
        );
    }

    /**
     * Countries enabled for loose-diamond card pages on this install.
     *
     * @return string[]
     */
    public function enabled_countries() {
        if (!$this->is_entitled() || !($this->rollout instanceof LDN_Rollout_Reader)) {
            return array();
        }
        $allowed = method_exists($this->config, 'diamonds_rollout_countries')
            ? $this->config->diamonds_rollout_countries($this->site_id)
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
            if ($this->rollout->is_enabled($code, 'diamonds')) {
                $out[] = $code;
            }
        }
        if (method_exists($this->config, 'countries_for_install')) {
            $out = $this->config->countries_for_install($this->site_id, $out);
        }
        return array_values(array_unique($out));
    }

    /**
     * Build rewrite rules ordered specific-before-general so carat+modifier and
     * carat+shape leaves win cleanly ahead of 3-segment hubs.
     *
     * @return array<string, string>
     */
    public function build_rewrite_rules() {
        $structure = $this->config->get_url_structure($this->site_id);
        if (!is_array($structure) || empty($structure['diamonds_level_1'])) {
            return array();
        }

        $countries = $this->enabled_countries();
        if ($countries === array()) {
            return array();
        }

        $specs = array(
            'diamonds_level_carat_modifier' => 'carat_modifier',
            'diamonds_level_leaf'           => 'leaf',
            'diamonds_level_carat'          => 'carat',
            'diamonds_level_modifier'       => 'modifier',
            'diamonds_level_shape'          => 'shape',
            'diamonds_level_2'              => 'type',
            'diamonds_level_1'              => 'root',
        );

        $rules = array();
        foreach ($countries as $country) {
            $loc_structure = method_exists($this->config, 'url_structure_for')
                ? $this->config->url_structure_for($this->site_id, $country)
                : $structure;
            if (!is_array($loc_structure)) {
                $loc_structure = $structure;
            }
            foreach ($specs as $key => $kind) {
                if (empty($loc_structure[$key]) || !is_string($loc_structure[$key])) {
                    continue;
                }
                $compiled = $this->compile_pattern($loc_structure[$key], $country, $kind, $loc_structure);
                if ($compiled !== null) {
                    $rules[$compiled[0]] = $compiled[1];
                }
            }
        }

        return $rules;
    }

    /**
     * Compile one `diamonds_level_*` URL pattern into `[regex, query]`.
     *
     * @param string $pattern
     * @param string $country
     * @param string $kind
     * @param array  $structure
     * @return array{0:string,1:string}|null
     */
    public function compile_pattern($pattern, $country, $kind, array $structure) {
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

        $type_slugs = array();
        foreach (array('type_natural', 'type_lab') as $tk) {
            if (!empty($structure[$tk]) && is_string($structure[$tk])) {
                $type_slugs[] = preg_quote($structure[$tk], '#');
            }
        }
        if ($type_slugs === array()) {
            return null;
        }
        $type_alt = '(' . implode('|', array_unique($type_slugs)) . ')';

        $carat_fmt = !empty($structure['carat_format']) && is_string($structure['carat_format'])
            ? $structure['carat_format']
            : '{value}-carat';
        $carat_slugs = array();
        foreach (self::CARAT_LABELS as $label) {
            $carat_slugs[] = preg_quote(str_replace('{value}', $label, $carat_fmt), '#');
        }
        $carat_alt = '(' . implode('|', $carat_slugs) . ')';

        $shape_alt = '(' . implode('|', array_map(function ($s) {
            return preg_quote($s, '#');
        }, self::SHAPES)) . ')';

        $mod_pool = ($kind === 'carat_modifier') ? self::CARAT_ELIGIBLE_MODIFIERS : self::MODIFIERS;
        $mod_alt = '(' . implode('|', array_map(function ($m) {
            return preg_quote($m, '#');
        }, $mod_pool)) . ')';

        $regex_parts = array();
        $query = array(
            'ldn_route'         => self::MODULE,
            'ldn_country'       => $country,
            'ldn_diamonds_kind' => $kind,
        );
        $capture = 0;

        foreach ($segments as $segment) {
            if ($segment === '{country}') {
                $regex_parts[] = preg_quote($country, '#');
                continue;
            }
            if ($segment === '{type}') {
                $regex_parts[] = $type_alt;
                $query['ldn_diamonds_type'] = '$matches[' . (++$capture) . ']';
                continue;
            }
            if ($segment === '{carat}') {
                $regex_parts[] = $carat_alt;
                $query['ldn_diamonds_carat'] = '$matches[' . (++$capture) . ']';
                continue;
            }
            if ($segment === '{shape}') {
                $regex_parts[] = $shape_alt;
                $query['ldn_diamonds_shape'] = '$matches[' . (++$capture) . ']';
                continue;
            }
            if ($segment === '{modifier}') {
                $regex_parts[] = $mod_alt;
                $query['ldn_diamonds_modifier'] = '$matches[' . (++$capture) . ']';
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

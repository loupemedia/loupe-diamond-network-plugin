<?php
/**
 * Dynamic router (size module) — PRD-015 CP106.
 *
 * Registers rewrite rules for the /diamond-size/ tree when rollout enables
 * size for the site's configured rollout country (US-only at launch).
 *
 * @package LoupeDiamondNetwork
 * @since   0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Size_Router {

    const MODULE = 'size';

    const QUERY_VARS = array(
        'ldn_route',
        'ldn_size_level',
        'ldn_shape',
        'ldn_carat',
        'ldn_compare_slug',
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
        add_action('init', array($this, 'add_rules'));
    }

    /**
     * Whether size routes should be registered for this site.
     *
     * @return bool
     */
    public function is_live() {
        if (!$this->rollout instanceof LDN_Rollout_Reader) {
            return false;
        }
        $country = $this->config->size_rollout_country($this->site_id);
        if (!$this->rollout->is_enabled($country, self::MODULE)) {
            return false;
        }
        return $this->artefacts->site_entitled_to_artefact($this->site_id, 'size_summary_json');
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

        $rules = $this->build_rewrite_rules();
        foreach ($rules as $regex => $query) {
            add_rewrite_rule($regex, $query, 'top');
        }
    }

    /**
     * @return array<string, string>
     */
    public function build_rewrite_rules() {
        $structure = $this->config->get_url_structure($this->site_id);
        $level3 = is_array($structure) && !empty($structure['size_level_3'])
            ? (string) $structure['size_level_3']
            : '/diamond-size/{shape}/{carat}';
        $level2 = is_array($structure) && !empty($structure['size_level_2'])
            ? (string) $structure['size_level_2']
            : '/diamond-size/{shape}';
        $level1 = is_array($structure) && !empty($structure['size_level_1'])
            ? (string) $structure['size_level_1']
            : '/diamond-size';
        $compare = is_array($structure) && !empty($structure['size_level_compare'])
            ? (string) $structure['size_level_compare']
            : '/diamond-size/compare/{compare}';
        $compare_tool = is_array($structure) && !empty($structure['size_level_compare'])
            ? (string) preg_replace('#/\\{compare\\}.*$#', '', $compare)
            : '/diamond-size/compare';
        $spread_checker = is_array($structure) && !empty($structure['size_level_spread_checker'])
            ? (string) $structure['size_level_spread_checker']
            : '/diamond-size/spread-checker';

        $individual = $this->pattern_to_regex($level3);
        $shape_hub = $this->pattern_to_regex($level2);
        $mega = $this->pattern_to_regex($level1);
        $comparison = $this->pattern_to_regex($compare);
        $comparison_tool = $this->pattern_to_regex($compare_tool);
        $spread_checker_rule = $this->pattern_to_regex($spread_checker);

        return array(
            $comparison => 'index.php?ldn_route=size&ldn_size_level=compare&ldn_compare_slug=$matches[1]',
            $comparison_tool => 'index.php?ldn_route=size&ldn_size_level=compare-tool',
            $spread_checker_rule => 'index.php?ldn_route=size&ldn_size_level=spread-checker',
            $individual => 'index.php?ldn_route=size&ldn_size_level=individual&ldn_shape=$matches[1]&ldn_carat=$matches[2]',
            $shape_hub => 'index.php?ldn_route=size&ldn_size_level=shape&ldn_shape=$matches[1]',
            $mega => 'index.php?ldn_route=size&ldn_size_level=mega',
        );
    }

    /**
     * Convert a url_structures pattern to a rewrite regex.
     *
     * @param string $pattern e.g. /diamond-size/{shape}/{carat}
     * @return string
     */
    public function pattern_to_regex($pattern) {
        $path = trim((string) $pattern, '/');
        $parts = $path === '' ? array() : explode('/', $path);
        $regex_parts = array();
        $match_index = 1;

        foreach ($parts as $part) {
            if ($part === '{shape}') {
                $regex_parts[] = '([^/]+)';
                ++$match_index;
                continue;
            }
            if ($part === '{carat}') {
                $regex_parts[] = '([0-9]+(?:\\.[0-9]+)?)-carat';
                ++$match_index;
                continue;
            }
            if ($part === '{compare}') {
                $regex_parts[] = '([^/]+)';
                ++$match_index;
                continue;
            }
            $regex_parts[] = preg_quote($part, '/');
        }

        return '^' . implode('/', $regex_parts) . '/?$';
    }
}

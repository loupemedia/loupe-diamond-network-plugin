<?php
/**
 * Dynamic router (size module) — PRD-015 CP106.
 *
 * Registers rewrite rules for the size URL tree when rollout enables
 * size for the site's configured rollout country (US-only at launch).
 * Patterns come from config/url_structures.yaml per site_id.
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
        $methodology = is_array($structure) && !empty($structure['size_level_methodology'])
            ? (string) $structure['size_level_methodology']
            : '/diamond-size/methodology';

        $rules = array(
            $this->pattern_to_regex($methodology) => $this->pattern_to_query($methodology, 'methodology'),
            $this->pattern_to_regex($level3) => $this->pattern_to_query($level3, 'individual'),
            $this->pattern_to_regex($level2) => $this->pattern_to_query(
                $level2,
                $this->hub_level_from_pattern($level2)
            ),
            $this->pattern_to_regex($level1) => 'index.php?ldn_route=size&ldn_size_level=mega',
        );

        if (is_array($structure) && !empty($structure['size_level_compare'])) {
            $compare = (string) $structure['size_level_compare'];
            $compare_tool = (string) preg_replace('#/\\{compare\\}.*$#', '', $compare);
            $rules[$this->pattern_to_regex($compare)] = $this->pattern_to_query($compare, 'compare');
            $rules[$this->pattern_to_regex($compare_tool)] = 'index.php?ldn_route=size&ldn_size_level=compare-tool';
        }

        if (is_array($structure) && !empty($structure['size_level_spread_checker'])) {
            $spread_checker = (string) $structure['size_level_spread_checker'];
            $rules[$this->pattern_to_regex($spread_checker)] = 'index.php?ldn_route=size&ldn_size_level=spread-checker';
        }

        return $rules;
    }

    /**
     * Level-2 hub: shape-first sites use ``shape``; carat-first use ``carat``.
     *
     * @param string $pattern
     * @return string
     */
    public function hub_level_from_pattern($pattern) {
        $path = trim((string) $pattern, '/');
        $has_carat = strpos($path, '{carat}') !== false;
        $has_shape = strpos($path, '{shape}') !== false;
        if ($has_carat && !$has_shape) {
            return 'carat';
        }
        return 'shape';
    }

    /**
     * Build a rewrite query string mapping {shape}/{carat} tokens to query vars.
     *
     * @param string $pattern
     * @param string $size_level mega|shape|carat|individual|compare|methodology
     * @return string
     */
    public function pattern_to_query($pattern, $size_level) {
        $path = trim((string) $pattern, '/');
        $parts = $path === '' ? array() : explode('/', $path);
        $query = array(
            'index.php?ldn_route=size',
            'ldn_size_level=' . $size_level,
        );
        $match_index = 1;
        foreach ($parts as $part) {
            if ($part === '{shape}') {
                $query[] = 'ldn_shape=$matches[' . $match_index . ']';
                ++$match_index;
                continue;
            }
            if ($part === '{carat}') {
                $query[] = 'ldn_carat=$matches[' . $match_index . ']';
                ++$match_index;
                continue;
            }
            if ($part === '{compare}') {
                $query[] = 'ldn_compare_slug=$matches[' . $match_index . ']';
                ++$match_index;
            }
        }
        return implode('&', $query);
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

        foreach ($parts as $part) {
            if ($part === '{shape}') {
                $regex_parts[] = '([^/]+)';
                continue;
            }
            if ($part === '{carat}') {
                $regex_parts[] = '([0-9]+(?:\\.[0-9]+)?)-carat';
                continue;
            }
            if ($part === '{compare}') {
                $regex_parts[] = '([^/]+)';
                continue;
            }
            $regex_parts[] = preg_quote($part, '/');
        }

        if ($regex_parts === array()) {
            return '^$';
        }

        return '^' . implode('/', $regex_parts) . '/?$';
    }
}

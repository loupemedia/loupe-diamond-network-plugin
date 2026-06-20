<?php
/**
 * Dynamic router (price module) — PRD-005 CP52, slice 1.
 *
 * Registers WordPress rewrite rules for the price-page hierarchy, but ONLY for
 * the `(country × module)` combinations the rollout reader reports as live for
 * this site. URL patterns come from `url_structures.yaml` (via LDN_Config), so
 * each site family gets its correct shape (Ringspo has `/{country}/…`, Loupe
 * and DPE omit the country segment) with no per-site code branches.
 *
 * Because routes are gated by rollout, we flush rewrite rules only when the
 * rollout version changes (LDN_Rollout_Reader::version_changed()).
 *
 * Scope of this slice: rewrite-rule generation + query vars + version-change
 * flush. Template dispatch / rendering and the size-module tree are later
 * checkpoints. The rule-builder and path matching are pure and unit-tested.
 *
 * The query var `ldn_type` holds the site's RAW type slug (e.g. `mined`,
 * `lab-grown`); the render layer maps it back to canonical natural/lab using
 * the site's url-structure `type_natural` / `type_lab`.
 *
 * @package LoupeDiamondNetwork
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Router {

    /**
     * Module this router slice serves.
     */
    const MODULE = 'price';

    /**
     * Query vars introduced by this plugin.
     *
     * @var string[]
     */
    const QUERY_VARS = array(
        'ldn_route',
        'ldn_country',
        'ldn_type',
        'ldn_carat',
        'ldn_shape',
        'ldn_level',
    );

    /**
     * @var string
     */
    private $site_id;

    /**
     * @var LDN_Rollout_Reader
     */
    private $rollout;

    /**
     * @var LDN_Config
     */
    private $config;

    /**
     * @param string             $site_id
     * @param LDN_Rollout_Reader $rollout
     * @param LDN_Config         $config
     */
    public function __construct($site_id, LDN_Rollout_Reader $rollout, LDN_Config $config) {
        $this->site_id = (string) $site_id;
        $this->rollout = $rollout;
        $this->config = $config;
    }

    // =========================================================================
    // WordPress wiring
    // =========================================================================

    /**
     * Hook into WordPress: query vars, rewrite rules, version-change flush.
     *
     * @return void
     */
    public function register() {
        add_filter('query_vars', array($this, 'register_query_vars'));
        add_action('init', array($this, 'add_rules'));
        // Late on init, after rules are present, reconcile against rollout version.
        add_action('init', array($this, 'maybe_flush'), 99);
    }

    /**
     * Register this plugin's query vars.
     *
     * @param string[] $vars
     * @return string[]
     */
    public function register_query_vars($vars) {
        return array_merge($vars, self::QUERY_VARS);
    }

    /**
     * Add the rewrite rules for currently-enabled routes.
     *
     * @return void
     */
    public function add_rules() {
        foreach ($this->build_rewrite_rules() as $regex => $query) {
            add_rewrite_rule($regex, $query, 'top');
        }
    }

    /**
     * Flush rewrite rules only when the published rollout version changed.
     *
     * @return void
     */
    public function maybe_flush() {
        if ($this->rollout->version_changed()) {
            flush_rewrite_rules();
            $this->rollout->mark_applied();
        }
    }

    // =========================================================================
    // Pure rule builder (unit-tested)
    // =========================================================================

    /**
     * Build the full set of rewrite rules for this site's enabled price routes.
     *
     * @return array<string, string> regex => 'index.php?…'
     */
    public function build_rewrite_rules() {
        $structure = $this->config->get_url_structure($this->site_id);
        if (!is_array($structure)) {
            return array();
        }

        $countries = $this->enabled_price_countries();
        if (empty($countries)) {
            return array();
        }

        $type_slugs = $this->type_slugs($structure);
        $levels = $this->ordered_levels($structure);
        $has_country = $this->patterns_have_country($levels);

        // Country-less families (Loupe/DPE) carry one implied country; build the
        // rule set once using that country as the query value.
        if (!$has_country) {
            $countries = array($countries[0]);
        }

        $rules = array();
        foreach ($countries as $country) {
            foreach ($levels as $level_num => $pattern) {
                list($regex, $query) = $this->compile_pattern(
                    $pattern,
                    $country,
                    $type_slugs,
                    $level_num
                );
                $rules[$regex] = $query;
            }
        }

        return $rules;
    }

    /**
     * Countries for this site that have the price module enabled.
     *
     * @return string[]
     */
    private function enabled_price_countries() {
        $out = array();
        foreach ($this->rollout->enabled_countries() as $country) {
            if ($this->rollout->is_enabled($country, self::MODULE)) {
                $out[] = $country;
            }
        }
        return $out;
    }

    /**
     * Ordered map of level number => pattern (level_1, level_2, …).
     *
     * @param array $structure
     * @return array<int, string>
     */
    private function ordered_levels(array $structure) {
        $levels = array();
        for ($i = 1; $i <= 6; $i++) {
            $key = 'level_' . $i;
            if (!empty($structure[$key]) && is_string($structure[$key])) {
                $levels[$i] = $structure[$key];
            }
        }
        return $levels;
    }

    /**
     * Site-specific type slugs (natural/lab), excluding null (lab-only sites).
     *
     * @param array $structure
     * @return string[]
     */
    private function type_slugs(array $structure) {
        $slugs = array();
        foreach (array('type_natural', 'type_lab') as $key) {
            if (!empty($structure[$key]) && is_string($structure[$key])) {
                $slugs[] = $structure[$key];
            }
        }
        return $slugs;
    }

    /**
     * Whether any level pattern contains the {country} placeholder.
     *
     * @param array<int, string> $levels
     * @return bool
     */
    private function patterns_have_country(array $levels) {
        foreach ($levels as $pattern) {
            if (strpos($pattern, '{country}') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compile one level pattern into [regex, query string].
     *
     * Placeholders: {country} is substituted as a literal (we gate per country);
     * {type} becomes an alternation of the site's type slugs; {carat} and
     * {shape} become generic captures. Other segments are literal.
     *
     * @param string   $pattern    e.g. "/{country}/diamond-prices/{type}/{carat}".
     * @param string   $country    Enabled country code (literal / query value).
     * @param string[] $type_slugs Site type slugs for the {type} alternation.
     * @param int      $level      Hierarchy level number.
     * @return array{0:string,1:string} [regex, 'index.php?…']
     */
    public function compile_pattern($pattern, $country, array $type_slugs, $level) {
        $segments = array_values(array_filter(explode('/', trim($pattern, '/')), 'strlen'));

        $regex_parts = array();
        $query = array(
            'ldn_route'   => self::MODULE,
            'ldn_country' => $country,
            'ldn_level'   => (string) $level,
        );
        $capture = 0;

        foreach ($segments as $segment) {
            if ($segment === '{country}') {
                $regex_parts[] = preg_quote($country, '#');
                continue;
            }
            if ($segment === '{type}') {
                $alts = array_map(function ($s) { return preg_quote($s, '#'); }, $type_slugs);
                $regex_parts[] = '(' . implode('|', $alts) . ')';
                $query['ldn_type'] = '$matches[' . (++$capture) . ']';
                continue;
            }
            if ($segment === '{carat}') {
                $regex_parts[] = '([^/]+)';
                $query['ldn_carat'] = '$matches[' . (++$capture) . ']';
                continue;
            }
            if ($segment === '{shape}') {
                $regex_parts[] = '([^/]+)';
                $query['ldn_shape'] = '$matches[' . (++$capture) . ']';
                continue;
            }
            // Literal segment (e.g. "diamond-prices", "prices").
            $regex_parts[] = preg_quote($segment, '#');
        }

        // Lab-only families (e.g. BDI): no {type} placeholder and no natural
        // slug — record the implied canonical type for downstream rendering.
        if (!isset($query['ldn_type']) && empty($type_slugs)) {
            $query['ldn_type'] = 'lab-grown';
        }

        $regex = '^' . implode('/', $regex_parts) . '/?$';
        $query_string = 'index.php?' . $this->build_query_string($query);

        return array($regex, $query_string);
    }

    /**
     * Join query pairs into a rewrite target. Values containing `$matches[n]`
     * are left raw; literals are passed through unchanged (all values here are
     * URL-safe slugs / digits).
     *
     * @param array<string,string> $pairs
     * @return string
     */
    private function build_query_string(array $pairs) {
        $parts = array();
        foreach ($pairs as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        return implode('&', $parts);
    }
}

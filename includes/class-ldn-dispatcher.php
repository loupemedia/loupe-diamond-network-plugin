<?php
/**
 * Request dispatcher — PRD-005 CP52 (template dispatch).
 *
 * Turns a matched price route (query vars set by LDN_Router) into a
 * LDN_Page_Context and selects the render template. Probes the page's primary
 * artefact so a route with no underlying data 404s cleanly instead of rendering
 * an empty shell.
 *
 * Page level is derived from which captured vars are present — robust across
 * site families (no per-family level-number table):
 *   shape present              → 'shape'
 *   carat present (no shape)   → 'all-shapes'
 *   type present  (no carat)   → 'diamond-type'
 *   none of the above          → 'top-level'
 *
 * `ldn_type` arrives as the site's RAW URL slug (e.g. 'mined', 'lab-grown');
 * it is canonicalised to 'natural' / 'lab-grown' here. `ldn_carat` arrives as
 * the URL slug (e.g. '1-carat', '1ct') and is reduced to the numeric value via
 * the family's `carat_format`.
 *
 * Scope: standard families (Ringspo/Loupe/DPE). Reverse shape-slug mapping for
 * suffixed families (guru `round-brilliant`, advisors `round-cut`) is a
 * documented follow-up; those sites pass the slug through unchanged for now.
 *
 * @package LoupeDiamondNetwork
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Dispatcher {

    /**
     * Route marker set by the router (query var `ldn_route`).
     */
    const ROUTE = 'price';

    /**
     * Primary existence-probe artefact per page level.
     */
    const PRIMARY_ARTEFACT = array(
        'shape'        => 'summary_data_json',
        'all-shapes'   => 'shapes_ranking_json',
        'diamond-type' => 'type_summary_json',
        'top-level'    => 'market_overview_json',
    );

    /**
     * @var string
     */
    private $site_id;

    /**
     * @var LDN_Config
     */
    private $config;

    /**
     * @var LDN_Data_Fetcher
     */
    private $fetcher;

    /**
     * Context for the in-flight request (read by the template).
     *
     * @var LDN_Page_Context|null
     */
    private $context = null;

    /**
     * Primary artefact payload for the in-flight request (read by the template).
     *
     * @var array|null
     */
    private $primary_data = null;

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
     * Hook the template_include filter.
     *
     * @return void
     */
    public function register() {
        add_filter('template_include', array($this, 'dispatch'));
    }

    /**
     * Select the template for the current request.
     *
     * @param string $template Default template chosen by WordPress.
     * @return string
     */
    public function dispatch($template) {
        if (get_query_var('ldn_route') !== self::ROUTE) {
            return $template;
        }

        $ctx = $this->build_context(array(
            'ldn_country' => get_query_var('ldn_country'),
            'ldn_type'    => get_query_var('ldn_type'),
            'ldn_carat'   => get_query_var('ldn_carat'),
            'ldn_shape'   => get_query_var('ldn_shape'),
        ));
        if ($ctx === null) {
            return $template;
        }

        LDN_Diagnostics::set_context($ctx);

        $rollout = LDN_Plugin::instance()->rollout();
        if ($rollout instanceof LDN_Rollout_Reader
            && $rollout->is_test_only($ctx->country_code, self::ROUTE)
        ) {
            $combos = $rollout->get_test_combos();
            if ($combos !== array() && !LDN_Test_Combos::allows_context($ctx, $combos)) {
                LDN_Diagnostics::note('URL blocked: not in staging test_combos list.');
                $this->trigger_404();
                add_action('wp_footer', array(__CLASS__, 'render_staging_footer'), 99);
                return $template;
            }
        }

        $primary = isset(self::PRIMARY_ARTEFACT[$ctx->page_level])
            ? self::PRIMARY_ARTEFACT[$ctx->page_level]
            : null;
        $data = $primary !== null ? $this->fetcher->fetch_artefact($primary, $ctx) : null;

        if ($data === null) {
            LDN_Diagnostics::note(
                $primary !== null
                    ? "Primary artefact missing or failed: {$primary}."
                    : 'No primary artefact for this page level.'
            );
            $this->trigger_404();
            add_action('wp_footer', array(__CLASS__, 'render_staging_footer'), 99);
            return $template;
        }

        $this->context = $ctx;
        $this->primary_data = $data;

        LDN_Assets::register_enqueue($ctx, $this->config);

        // Registered here (template_include) so it fires when the template later
        // calls get_header() → wp_head; emitting from the body would be too late.
        add_action('wp_head', array($this, 'render_head'), 5);
        add_action('wp_footer', array(__CLASS__, 'render_staging_footer'), 99);

        $custom = LDN_PLUGIN_DIR . 'templates/price-page.php';
        return is_readable($custom) ? $custom : $template;
    }

    /**
     * Staging diagnostics footer (404 and successful LDN pages).
     *
     * @return void
     */
    public static function render_staging_footer() {
        echo LDN_Diagnostics::render_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in renderer
    }

    /**
     * Echo the page's <head> tags (meta, canonical, OG, JSON-LD, hreflang) on wp_head.
     *
     * @return void
     */
    public function render_head() {
        if (!($this->context instanceof LDN_Page_Context)) {
            return;
        }
        echo LDN_Plugin::instance()->renderer()->render_head_content($this->context); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within renderer
    }

    /**
     * Context resolved for the current request (for the template).
     *
     * @return LDN_Page_Context|null
     */
    public function current_context() {
        return $this->context;
    }

    /**
     * Primary artefact payload for the current request (for the template).
     *
     * @return array|null
     */
    public function primary_data() {
        return $this->primary_data;
    }

    // =========================================================================
    // Pure context building (unit-tested)
    // =========================================================================

    /**
     * Build a PageContext from raw query vars, or null when insufficient.
     *
     * @param array<string,mixed> $vars
     * @return LDN_Page_Context|null
     */
    public function build_context(array $vars) {
        $country = $this->str_or_null($vars, 'ldn_country');
        if ($country === null) {
            return null;
        }
        $type_raw = $this->str_or_null($vars, 'ldn_type');
        $carat_raw = $this->str_or_null($vars, 'ldn_carat');
        $shape = $this->str_or_null($vars, 'ldn_shape');

        $page_level = $this->page_level_from_vars($type_raw, $carat_raw, $shape);
        $type = $this->canonical_type($type_raw);
        $carat = $this->parse_carat($carat_raw);

        return new LDN_Page_Context($this->site_id, $page_level, $country, $type, $carat, $shape);
    }

    /**
     * Derive the page level from which vars are present.
     *
     * @param string|null $type
     * @param string|null $carat
     * @param string|null $shape
     * @return string
     */
    public function page_level_from_vars($type, $carat, $shape) {
        if ($shape !== null) {
            return 'shape';
        }
        if ($carat !== null) {
            return 'all-shapes';
        }
        if ($type !== null) {
            return 'diamond-type';
        }
        return 'top-level';
    }

    /**
     * Map the site's raw type slug to canonical natural / lab-grown.
     *
     * @param string|null $raw
     * @return string|null
     */
    public function canonical_type($raw) {
        if ($raw === null) {
            return null;
        }
        $structure = $this->config->get_url_structure($this->site_id);
        if (is_array($structure)) {
            if (isset($structure['type_natural']) && $raw === $structure['type_natural']) {
                return 'natural';
            }
            if (isset($structure['type_lab']) && $raw === $structure['type_lab']) {
                return 'lab-grown';
            }
        }
        // Already-canonical or unknown slug: pass through.
        return $raw;
    }

    /**
     * Reduce a carat URL slug to its numeric value using `carat_format`.
     *
     * @param string|null $raw e.g. '1-carat', '1ct'.
     * @return string|null e.g. '1'.
     */
    public function parse_carat($raw) {
        if ($raw === null) {
            return null;
        }
        $structure = $this->config->get_url_structure($this->site_id);
        $format = is_array($structure) && isset($structure['carat_format']) ? $structure['carat_format'] : null;

        // Fixed-carat families (carat in the domain, not the URL).
        if ($format === null) {
            if (is_array($structure) && isset($structure['carat_weight'])) {
                return (string) $structure['carat_weight'];
            }
            return $raw;
        }

        $parts = explode('{value}', $format);
        $prefix = isset($parts[0]) ? $parts[0] : '';
        $suffix = isset($parts[1]) ? $parts[1] : '';

        $value = $raw;
        if ($prefix !== '' && strpos($value, $prefix) === 0) {
            $value = substr($value, strlen($prefix));
        }
        if ($suffix !== '' && substr($value, -strlen($suffix)) === $suffix) {
            $value = substr($value, 0, -strlen($suffix));
        }
        return $value;
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /**
     * Read a var as a non-empty string, or null.
     *
     * @param array  $vars
     * @param string $key
     * @return string|null
     */
    private function str_or_null(array $vars, $key) {
        if (!isset($vars[$key])) {
            return null;
        }
        $value = (string) $vars[$key];
        return $value === '' ? null : $value;
    }

    /**
     * Mark the current request as a 404 (no data for this route).
     *
     * @return void
     */
    private function trigger_404() {
        global $wp_query;
        if ($wp_query instanceof WP_Query) {
            $wp_query->set_404();
        }
        if (function_exists('status_header')) {
            status_header(404);
        }
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
    }
}

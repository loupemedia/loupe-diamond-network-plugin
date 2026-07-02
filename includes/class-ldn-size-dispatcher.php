<?php
/**
 * Size-module request dispatcher — PRD-015 CP106.
 *
 * @package LoupeDiamondNetwork
 * @since   0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Size_Dispatcher {

    const ROUTE = 'size';

    const PRIMARY_ARTEFACT = array(
        'size-individual'  => 'size_summary_json',
        'size-shape-hub'   => 'size_summary_json',
        'size-mega-hub'    => 'size_summary_json',
        'size-comparison'  => 'size_summary_json',
    );

    /** @var string */
    private $site_id;

    /** @var LDN_Config */
    private $config;

    /** @var LDN_Data_Fetcher */
    private $fetcher;

    /** @var LDN_Page_Context|null */
    private $context = null;

    /** @var array|null */
    private $primary_data = null;

    /** @var bool Curated comparison (indexable); false = long-tail composed on the fly. */
    private $comparison_indexable = true;

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
        add_filter('template_include', array($this, 'dispatch'), 5);
    }

    /**
     * @param string $template
     * @return string
     */
    public function dispatch($template) {
        if (get_query_var('ldn_route') !== self::ROUTE) {
            return $template;
        }

        $ctx = $this->build_context(array(
            'ldn_size_level'   => get_query_var('ldn_size_level'),
            'ldn_shape'        => get_query_var('ldn_shape'),
            'ldn_carat'        => get_query_var('ldn_carat'),
            'ldn_compare_slug' => get_query_var('ldn_compare_slug'),
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
            if ($combos !== array() && !LDN_Test_Combos::allows_size_context($ctx, $combos)) {
                LDN_Diagnostics::note('URL blocked: not in staging test_combos list (size).');
                $this->trigger_404();
                add_action('wp_footer', array('LDN_Dispatcher', 'render_staging_footer'), 99);
                return $template;
            }
        }

        $primary = isset(self::PRIMARY_ARTEFACT[$ctx->page_level])
            ? self::PRIMARY_ARTEFACT[$ctx->page_level]
            : null;
        $data = null;
        $this->comparison_indexable = true;

        if ($ctx->page_level === 'size-comparison') {
            $data = $this->load_comparison_data($ctx, $primary);
        } elseif ($primary !== null) {
            $data = $this->fetcher->fetch_artefact($primary, $ctx);
        }

        if ($data === null) {
            LDN_Diagnostics::note(
                $primary !== null
                    ? "Primary size artefact missing or failed: {$primary}."
                    : 'No primary artefact for this size page level.'
            );
            $this->trigger_404();
            add_action('wp_footer', array('LDN_Dispatcher', 'render_staging_footer'), 99);
            return $template;
        }

        $this->context = $ctx;
        $this->primary_data = $data;

        LDN_Assets::register_enqueue($ctx, $this->config);

        add_action('wp_head', array($this, 'render_head'), 5);
        add_action('wp_footer', array('LDN_Dispatcher', 'render_staging_footer'), 99);

        $custom = LDN_PLUGIN_DIR . 'templates/size-page.php';
        return is_readable($custom) ? $custom : $template;
    }

    /**
     * @return void
     */
    public function render_head() {
        if (!($this->context instanceof LDN_Page_Context)) {
            return;
        }
        $renderer = new LDN_Size_Renderer($this->fetcher, $this->config);
        echo $renderer->render_head_content(
            $this->context,
            $this->primary_data,
            $this->comparison_indexable
        ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * @return LDN_Page_Context|null
     */
    public function current_context() {
        return $this->context;
    }

    /**
     * @return array|null
     */
    public function primary_data() {
        return $this->primary_data;
    }

    /**
     * @return bool
     */
    public function comparison_indexable() {
        return $this->comparison_indexable;
    }

    /**
     * @param array<string, mixed> $vars
     * @return LDN_Page_Context|null
     */
    public function build_context(array $vars) {
        $level_raw = $this->str_or_null($vars, 'ldn_size_level');
        if ($level_raw === null) {
            return null;
        }

        $page_level = $this->page_level_from_size_level($level_raw);
        if ($page_level === null) {
            return null;
        }

        $compare_slug = $this->str_or_null($vars, 'ldn_compare_slug');
        if ($page_level === 'size-comparison' && $compare_slug === null) {
            return null;
        }

        $shape_slug = $this->str_or_null($vars, 'ldn_shape');
        $shape = $shape_slug !== null
            ? $this->config->slug_to_shape($shape_slug, $this->site_id)
            : null;
        $carat = $this->parse_carat($this->str_or_null($vars, 'ldn_carat'));
        $country = $this->config->size_rollout_country($this->site_id);

        return new LDN_Page_Context(
            $this->site_id,
            $page_level,
            $country,
            null,
            $carat,
            $shape,
            'size',
            $compare_slug
        );
    }

    /**
     * Curated comparison artefact on S3, or compose from two individual summaries.
     *
     * @param LDN_Page_Context $ctx
     * @param string|null      $primary
     * @return array|null
     */
    private function load_comparison_data(LDN_Page_Context $ctx, $primary) {
        if ($primary === null) {
            return null;
        }

        $curated = $this->fetcher->fetch_artefact($primary, $ctx);
        if (is_array($curated) && isset($curated['type']) && $curated['type'] === 'comparison') {
            $this->comparison_indexable = true;
            return $curated;
        }

        $renderer = new LDN_Size_Renderer($this->fetcher, $this->config);
        $sides = $renderer->parse_compare_slug($ctx->compare_slug, $this->site_id);
        if ($sides === null) {
            return null;
        }

        $country = $ctx->country_code;
        $ctx_a = new LDN_Page_Context(
            $this->site_id,
            'size-individual',
            $country,
            null,
            $sides['a']['carat'],
            $sides['a']['shape'],
            'size'
        );
        $ctx_b = new LDN_Page_Context(
            $this->site_id,
            'size-individual',
            $country,
            null,
            $sides['b']['carat'],
            $sides['b']['shape'],
            'size'
        );
        $summary_a = $this->fetcher->fetch_artefact('size_summary_json', $ctx_a);
        $summary_b = $this->fetcher->fetch_artefact('size_summary_json', $ctx_b);
        if (!is_array($summary_a) || !is_array($summary_b)) {
            return null;
        }

        $this->comparison_indexable = false;
        return $renderer->build_comparison_summary($summary_a, $summary_b);
    }

    /**
     * @param string $raw mega|shape|individual|compare
     * @return string|null
     */
    public function page_level_from_size_level($raw) {
        switch (strtolower((string) $raw)) {
            case 'mega':
                return 'size-mega-hub';
            case 'shape':
                return 'size-shape-hub';
            case 'individual':
                return 'size-individual';
            case 'compare':
                return 'size-comparison';
            case 'sitemap':
                return 'size-sitemap';
            default:
                return null;
        }
    }

    /**
     * @param string|null $raw
     * @return string|null
     */
    public function parse_carat($raw) {
        if ($raw === null) {
            return null;
        }
        return LDN_Test_Combos::normalise_carat($raw);
    }

    /**
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

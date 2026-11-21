<?php
/**
 * Finished jewellery request dispatcher (PRD-020 CP 135).
 *
 * Resolves `LDN_Page_Context` (`module = 'jewellery'`, `page_level = 'jewellery'`)
 * from rewrite query vars, fetches `jewellery_summary_json` from S3 (returning a
 * clean HTTP 404 when a facet lacks supply), enqueues assets, hooks `<head>`
 * metadata + Schema.org JSON-LD, and dispatches `templates/jewellery-page.php`.
 *
 * @package LoupeDiamondNetwork
 * @since   0.44.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Jewellery_Dispatcher {

    const ROUTE = 'jewellery';

    const PRIMARY_ARTEFACT = 'jewellery_summary_json';

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
            'ldn_country'            => get_query_var('ldn_country'),
            'ldn_jewellery_type'     => get_query_var('ldn_jewellery_type'),
            'ldn_jewellery_kind'     => get_query_var('ldn_jewellery_kind'),
            'ldn_jewellery_category' => get_query_var('ldn_jewellery_category'),
            'ldn_jewellery_subpath'  => get_query_var('ldn_jewellery_subpath'),
        ));
        if ($ctx === null) {
            $this->trigger_404();
            return $template;
        }

        if (class_exists('LDN_Diagnostics')) {
            LDN_Diagnostics::set_context($ctx);
        }

        $data = $this->fetch_primary_data($ctx);
        if (!is_array($data)) {
            if (class_exists('LDN_Diagnostics')) {
                LDN_Diagnostics::note(
                    'Primary jewellery artefact missing or failed: ' . self::PRIMARY_ARTEFACT
                    . ' (' . $ctx->jewelry_path . ').'
                );
            }
            $this->trigger_404();
            add_action('wp_footer', array('LDN_Dispatcher', 'render_staging_footer'), 99);
            return $template;
        }

        $this->context = $ctx;
        $this->primary_data = $data;

        LDN_Assets::register_enqueue($ctx, $this->config);

        add_action('wp_head', array($this, 'render_head'), 5);
        add_action('wp_footer', array('LDN_Dispatcher', 'render_staging_footer'), 99);

        $custom = LDN_PLUGIN_DIR . 'templates/jewellery-page.php';
        return is_readable($custom) ? $custom : $template;
    }

    /**
     * @return void
     */
    public function render_head() {
        if (!($this->context instanceof LDN_Page_Context) || !is_array($this->primary_data)) {
            return;
        }
        $renderer = new LDN_Jewellery_Renderer($this->fetcher, $this->config);
        echo $renderer->render_head_content(
            $this->context,
            $this->primary_data
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
     * Build `LDN_Page_Context` from matched rewrite query vars.
     *
     * @param array<string, mixed> $vars
     * @return LDN_Page_Context|null
     */
    public function build_context(array $vars) {
        $country = $this->clean_slug($vars, 'ldn_country');
        $type = $this->clean_slug($vars, 'ldn_jewellery_type');
        $kind = $this->clean_slug($vars, 'ldn_jewellery_kind');
        $category = $this->clean_slug($vars, 'ldn_jewellery_category');
        $subpath = $this->clean_subpath($vars, 'ldn_jewellery_subpath');

        if ($country === null) {
            return null;
        }
        $allowed = method_exists($this->config, 'jewellery_rollout_countries')
            ? $this->config->jewellery_rollout_countries($this->site_id)
            : array();
        if ($allowed !== array() && !in_array($country, $allowed, true)) {
            return null;
        }

        if ($type !== 'natural' && $type !== 'lab-grown') {
            $type = 'natural';
        }
        if ($type === 'lab-grown') {
            $structure = $this->config->get_url_structure($this->site_id);
            $profile = $this->config->get_content_profile($this->site_id);
            if (!is_array($structure)
                || empty($structure['jewellery_type_lab'])
                || !is_array($profile)
                || empty($profile['jewellery_surface']['publish_lab_tree'])
            ) {
                return null;
            }
        }

        $jewelry_path = $this->resolve_jewelry_path($kind, $category, $subpath);
        if ($jewelry_path === null) {
            return null;
        }

        return new LDN_Page_Context(
            $this->site_id,
            'jewellery',
            $country,
            $type,
            null,
            null,
            'jewellery',
            null,
            $jewelry_path
        );
    }

    /**
     * Fetch `jewellery_summary_json` for a context.
     *
     * @param LDN_Page_Context $ctx
     * @return array|null
     */
    public function fetch_primary_data(LDN_Page_Context $ctx) {
        $data = $this->fetcher->fetch_artefact(self::PRIMARY_ARTEFACT, $ctx);
        return is_array($data) ? $data : null;
    }

    /**
     * Map matched route kind + category + subpath to the S3 `jewelry_path`.
     *
     * @param string|null $kind
     * @param string|null $category
     * @param string|null $subpath
     * @return string|null
     */
    private function resolve_jewelry_path($kind, $category, $subpath) {
        if ($kind === 'hub') {
            return 'hub';
        }
        if ($category === null || !in_array($category, LDN_Jewellery_Router::CATEGORIES, true)) {
            return null;
        }
        switch ($kind) {
            case 'category':
                return $category;
            case 'subpath':
                return $subpath !== null ? $category . '/' . $subpath : null;
            case 'mens_cat':
                return 'mens/' . $category;
            case 'mens':
                return $subpath !== null ? 'mens/' . $category . '/' . $subpath : null;
            default:
                return null;
        }
    }

    /**
     * @param array<string, mixed> $vars
     * @param string               $key
     * @return string|null
     */
    private function clean_slug(array $vars, $key) {
        if (!isset($vars[$key]) || !is_string($vars[$key])) {
            return null;
        }
        $val = strtolower(trim($vars[$key]));
        if ($val === '' || !preg_match('/^[a-z0-9_\-]+$/', $val)) {
            return null;
        }
        return $val;
    }

    /**
     * @param array<string, mixed> $vars
     * @param string               $key
     * @return string|null
     */
    private function clean_subpath(array $vars, $key) {
        if (!isset($vars[$key]) || !is_string($vars[$key])) {
            return null;
        }
        $val = strtolower(trim($vars[$key], " \t\n\r\0\x0B/"));
        if ($val === '' || !preg_match('/^[a-z0-9][a-z0-9\-]*(?:\/[a-z0-9][a-z0-9\-]*)*$/', $val)) {
            return null;
        }
        return $val;
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

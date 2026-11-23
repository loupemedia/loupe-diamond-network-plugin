<?php
/**
 * Loose diamond shopping request dispatcher (PRD-025 CP 166).
 *
 * Resolves `LDN_Page_Context` (`module = 'diamonds'`, `page_level = 'diamonds'`),
 * verifies the canonical request path is registered in `ops.page_url_registry`
 * (`module = 'diamonds'`), fetches cached `diamond_cards_json` from S3 (zero
 * live HTTP calls to SparkleScore or Facet Intelligence), enqueues `/diamonds/`
 * assets without ad scripts, hooks `<head>` metadata + `CollectionPage` JSON-LD,
 * and dispatches `templates/diamonds-page.php`.
 *
 * @package LoupeDiamondNetwork
 * @since   0.45.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Diamonds_Dispatcher {

    const ROUTE = 'diamonds';

    const PRIMARY_ARTEFACT = 'diamond_cards_json';

    const MODIFIER_IMPLIED_SHAPES = array(
        'elongated-cushion' => 'cushion',
        'elongated-radiant' => 'radiant',
    );

    /** @var string */
    private $site_id;

    /** @var LDN_Config */
    private $config;

    /** @var LDN_Data_Fetcher */
    private $fetcher;

    /** @var LDN_Page_Registry|null */
    private $registry;

    /** @var array<string, bool>|null */
    private $known_paths = null;

    /** @var LDN_Page_Context|null */
    private $context = null;

    /** @var array|null */
    private $primary_data = null;

    /**
     * @param string                 $site_id
     * @param LDN_Config             $config
     * @param LDN_Data_Fetcher       $fetcher
     * @param LDN_Page_Registry|null $registry
     * @param string[]|null          $known_paths Optional test allowlist of url_path rows.
     */
    public function __construct(
        $site_id,
        LDN_Config $config,
        LDN_Data_Fetcher $fetcher,
        $registry = null,
        ?array $known_paths = null
    ) {
        $this->site_id = (string) $site_id;
        $this->config = $config;
        $this->fetcher = $fetcher;
        $this->registry = ($registry instanceof LDN_Page_Registry) ? $registry : null;
        if (is_array($known_paths)) {
            $this->known_paths = array();
            foreach ($known_paths as $p) {
                $norm = '/' . trim((string) $p, '/');
                $this->known_paths[$norm] = true;
            }
        }
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
            'ldn_country'           => get_query_var('ldn_country'),
            'ldn_diamonds_kind'     => get_query_var('ldn_diamonds_kind'),
            'ldn_diamonds_type'     => get_query_var('ldn_diamonds_type'),
            'ldn_diamonds_carat'    => get_query_var('ldn_diamonds_carat'),
            'ldn_diamonds_shape'    => get_query_var('ldn_diamonds_shape'),
            'ldn_diamonds_modifier' => get_query_var('ldn_diamonds_modifier'),
        ));
        if ($ctx === null) {
            $this->trigger_404();
            return $template;
        }

        if (class_exists('LDN_Diagnostics')) {
            LDN_Diagnostics::set_context($ctx);
        }

        if (!$this->is_path_allowed($ctx)) {
            if (class_exists('LDN_Diagnostics')) {
                LDN_Diagnostics::note(
                    'Diamonds path absent from ops.page_url_registry (module=diamonds): '
                    . $this->public_url_path($ctx)
                );
            }
            $this->trigger_404();
            add_action('wp_footer', array('LDN_Dispatcher', 'render_staging_footer'), 99);
            return $template;
        }

        $data = $this->fetch_primary_data($ctx);
        if (!is_array($data)) {
            if (class_exists('LDN_Diagnostics')) {
                LDN_Diagnostics::note(
                    'Primary diamonds artefact missing or failed: ' . self::PRIMARY_ARTEFACT
                    . ' (' . $ctx->diamond_path . ').'
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

        $custom = LDN_PLUGIN_DIR . 'templates/diamonds-page.php';
        return is_readable($custom) ? $custom : $template;
    }

    /**
     * @return void
     */
    public function render_head() {
        if (!($this->context instanceof LDN_Page_Context) || !is_array($this->primary_data)) {
            return;
        }
        $renderer = new LDN_Diamonds_Renderer($this->fetcher, $this->config);
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
        $kind = $this->clean_slug($vars, 'ldn_diamonds_kind');
        $raw_type = $this->clean_slug($vars, 'ldn_diamonds_type');
        $raw_carat = $this->clean_slug($vars, 'ldn_diamonds_carat');
        $raw_shape = $this->clean_slug($vars, 'ldn_diamonds_shape');
        $raw_modifier = $this->clean_slug($vars, 'ldn_diamonds_modifier');

        if ($country === null || $kind === null) {
            return null;
        }
        $allowed = method_exists($this->config, 'diamonds_rollout_countries')
            ? $this->config->diamonds_rollout_countries($this->site_id)
            : array();
        if ($allowed === array() || !in_array($country, $allowed, true)) {
            return null;
        }

        $structure = method_exists($this->config, 'url_structure_for')
            ? $this->config->url_structure_for($this->site_id, $country)
            : $this->config->get_url_structure($this->site_id);
        if (!is_array($structure)) {
            return null;
        }

        if ($kind === 'root') {
            return new LDN_Page_Context(
                $this->site_id,
                'diamonds',
                $country,
                'all',
                null,
                null,
                'diamonds',
                null,
                null,
                'hub'
            );
        }

        $canonical_type = $this->resolve_diamond_type($raw_type, $structure);
        if ($canonical_type === null) {
            return null;
        }

        $carat_label = $raw_carat !== null ? $this->extract_carat_label($raw_carat, $structure) : null;
        $shape = ($raw_shape !== null && in_array($raw_shape, LDN_Diamonds_Router::SHAPES, true))
            ? $raw_shape
            : null;
        $modifier = ($raw_modifier !== null && in_array($raw_modifier, LDN_Diamonds_Router::MODIFIERS, true))
            ? $raw_modifier
            : null;

        $diamond_path = null;
        switch ($kind) {
            case 'type':
                $diamond_path = 'hub';
                break;
            case 'shape':
                if ($shape === null) {
                    return null;
                }
                $diamond_path = $shape;
                break;
            case 'carat':
                if ($carat_label === null || $raw_carat === null) {
                    return null;
                }
                $diamond_path = $raw_carat;
                break;
            case 'leaf':
                if ($carat_label === null || $raw_carat === null || $shape === null) {
                    return null;
                }
                $diamond_path = $raw_carat . '/' . $shape;
                break;
            case 'modifier':
                if ($modifier === null) {
                    return null;
                }
                if (isset(self::MODIFIER_IMPLIED_SHAPES[$modifier])) {
                    $shape = self::MODIFIER_IMPLIED_SHAPES[$modifier];
                }
                $diamond_path = $modifier;
                break;
            case 'carat_modifier':
                if ($carat_label === null
                    || $raw_carat === null
                    || $modifier === null
                    || !in_array($modifier, LDN_Diamonds_Router::CARAT_ELIGIBLE_MODIFIERS, true)
                ) {
                    return null;
                }
                if (isset(self::MODIFIER_IMPLIED_SHAPES[$modifier])) {
                    $shape = self::MODIFIER_IMPLIED_SHAPES[$modifier];
                }
                $diamond_path = $raw_carat . '/' . $modifier;
                break;
            default:
                return null;
        }

        return new LDN_Page_Context(
            $this->site_id,
            'diamonds',
            $country,
            $canonical_type,
            $carat_label,
            $shape,
            'diamonds',
            null,
            null,
            $diamond_path
        );
    }

    /**
     * Verify the canonical URL path exists in `ops.page_url_registry` (`module='diamonds'`).
     *
     * When Postgres is unavailable (e.g. local S3-only preview harness without DB),
     * returns `true` so `fetch_primary_data()` acts as the S3 existence gate.
     *
     * @param LDN_Page_Context $ctx
     * @return bool
     */
    public function is_path_allowed(LDN_Page_Context $ctx) {
        $url_path = $this->public_url_path($ctx);
        if ($url_path === '') {
            return false;
        }
        if (is_array($this->known_paths)) {
            return isset($this->known_paths[$url_path]);
        }
        if ($this->registry instanceof LDN_Page_Registry) {
            $registered = $this->registry->is_registered_module_path(
                $this->site_id,
                $url_path,
                LDN_Page_Registry::MODULE_DIAMONDS
            );
            if ($registered !== null) {
                return $registered;
            }
        }
        return true;
    }

    /**
     * Resolve the registry `url_path` (no trailing slash) for a diamonds context.
     *
     * @param LDN_Page_Context $ctx
     * @return string
     */
    public function public_url_path(LDN_Page_Context $ctx) {
        $structure = method_exists($this->config, 'url_structure_for')
            ? $this->config->url_structure_for($ctx->site_id, $ctx->country_code)
            : $this->config->get_url_structure($ctx->site_id);
        if (!is_array($structure)) {
            return '';
        }

        if ($ctx->diamond_type === 'all' && $ctx->diamond_path === 'hub') {
            $pattern = isset($structure['diamonds_level_1']) ? (string) $structure['diamonds_level_1'] : '';
            if ($pattern === '') {
                return '';
            }
            return '/' . trim(strtr($pattern, array('{country}' => $ctx->country_code)), '/');
        }

        $type_slug = $ctx->diamond_type === 'lab-grown'
            ? (string) ($structure['type_lab'] ?? 'lab-grown')
            : (string) ($structure['type_natural'] ?? 'natural');

        if ($ctx->diamond_path === 'hub') {
            $pattern = isset($structure['diamonds_level_2']) ? (string) $structure['diamonds_level_2'] : '';
            if ($pattern === '') {
                return '';
            }
            return '/' . trim(strtr($pattern, array(
                '{country}' => $ctx->country_code,
                '{type}'    => $type_slug,
            )), '/');
        }

        $base = isset($structure['diamonds_level_2']) ? (string) $structure['diamonds_level_2'] : '';
        if ($base === '') {
            return '';
        }
        $prefix = '/' . trim(strtr($base, array(
            '{country}' => $ctx->country_code,
            '{type}'    => $type_slug,
        )), '/');
        return $prefix . '/' . trim((string) $ctx->diamond_path, '/');
    }

    /**
     * Fetch `diamond_cards_json` from S3 via `LDN_Data_Fetcher` (cached; zero API calls).
     *
     * @param LDN_Page_Context $ctx
     * @return array|null
     */
    public function fetch_primary_data(LDN_Page_Context $ctx) {
        $data = $this->fetcher->fetch_artefact(self::PRIMARY_ARTEFACT, $ctx);
        return is_array($data) ? $data : null;
    }

    /**
     * @param string|null $slug
     * @param array       $structure
     * @return string|null
     */
    private function resolve_diamond_type($slug, array $structure) {
        if ($slug === null) {
            return null;
        }
        $nat = isset($structure['type_natural']) ? strtolower((string) $structure['type_natural']) : 'natural';
        $lab = isset($structure['type_lab']) ? strtolower((string) $structure['type_lab']) : 'lab-grown';
        if ($slug === $nat) {
            return 'natural';
        }
        if ($slug === $lab) {
            return 'lab-grown';
        }
        return null;
    }

    /**
     * @param string $carat_slug
     * @param array  $structure
     * @return string|null
     */
    private function extract_carat_label($carat_slug, array $structure) {
        $fmt = !empty($structure['carat_format']) && is_string($structure['carat_format'])
            ? $structure['carat_format']
            : '{value}-carat';
        foreach (LDN_Diamonds_Router::CARAT_LABELS as $label) {
            if (strtolower(str_replace('{value}', $label, $fmt)) === $carat_slug) {
                return $label;
            }
        }
        return null;
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
        if ($val === '' || !preg_match('/^[a-z0-9.\-_]+$/', $val)) {
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

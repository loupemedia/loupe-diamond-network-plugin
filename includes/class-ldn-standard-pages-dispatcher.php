<?php
/**
 * Legal page dispatcher (PRD-021 CP137).
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Standard_Pages_Dispatcher {

    const ROUTE = 'legal';

    /** @var string */
    private $site_id;

    /** @var LDN_Config */
    private $config;

    /** @var LDN_Page_Context|null */
    private $context = null;

    /** @var string */
    private $page_id = '';

    /**
     * @param string     $site_id
     * @param LDN_Config $config
     */
    public function __construct($site_id, LDN_Config $config) {
        $this->site_id = (string) $site_id;
        $this->config = $config;
    }

    /**
     * @return void
     */
    public function register() {
        add_filter('template_include', array($this, 'dispatch'), 4);
    }

    /**
     * @param string $template
     * @return string
     */
    public function dispatch($template) {
        if (get_query_var('ldn_route') !== self::ROUTE) {
            return $template;
        }

        $page_id = (string) get_query_var('ldn_legal_page');
        $ctx = $this->build_context(array(
            'ldn_country' => get_query_var('ldn_country'),
            'ldn_legal_page' => $page_id,
        ));
        if ($ctx === null) {
            return $template;
        }

        $this->page_id = $page_id;
        $this->context = $ctx;
        if (class_exists('LDN_Diagnostics')) {
            LDN_Diagnostics::set_context($ctx);
        }
        if (class_exists('LDN_Locale')) {
            LDN_Locale::switch_for_context($ctx, $this->config);
        }
        if (class_exists('LDN_Assets')) {
            LDN_Assets::register_enqueue($ctx, $this->config);
        }

        add_action('wp_head', array($this, 'render_head'), 5);
        add_filter('wp_robots', array($this, 'noindex_robots'), 20);

        $custom = LDN_PLUGIN_DIR . 'templates/legal-page.php';
        return is_readable($custom) ? $custom : $template;
    }

    /**
     * @param array $robots
     * @return array
     */
    public function noindex_robots($robots) {
        if (!is_array($robots)) {
            $robots = array();
        }
        $robots['noindex'] = true;
        $robots['follow'] = true;
        unset($robots['index']);
        return $robots;
    }

    /**
     * @return void
     */
    public function render_head() {
        if (!($this->context instanceof LDN_Page_Context)) {
            return;
        }
        $renderer = new LDN_Standard_Pages_Renderer($this->config);
        echo $renderer->render_head($this->context, $this->page_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * @param array $vars
     * @return LDN_Page_Context|null
     */
    public function build_context(array $vars) {
        $country = isset($vars['ldn_country']) ? strtolower((string) $vars['ldn_country']) : '';
        $page_id = isset($vars['ldn_legal_page']) ? (string) $vars['ldn_legal_page'] : '';
        if ($country === '' || $page_id === '') {
            return null;
        }
        if (!$this->page_is_owed($page_id, $country)) {
            return null;
        }
        $this->page_id = $page_id;
        return new LDN_Page_Context(
            $this->site_id,
            $page_id,
            $country,
            null,
            null,
            null,
            self::ROUTE
        );
    }

    /**
     * @param string $page_id
     * @param string $country
     * @return bool
     */
    private function page_is_owed($page_id, $country) {
        $catalogue = $this->config->get_standard_pages();
        $required = isset($catalogue['required_pages']) && is_array($catalogue['required_pages'])
            ? $catalogue['required_pages']
            : array();
        $extras = isset($catalogue['country_extra_pages'][$country])
            ? (array) $catalogue['country_extra_pages'][$country]
            : array();
        return in_array($page_id, $required, true) || in_array($page_id, $extras, true);
    }

    /**
     * @return LDN_Page_Context|null
     */
    public function current_context() {
        return $this->context;
    }

    /**
     * @return string
     */
    public function current_page_id() {
        return $this->page_id;
    }
}

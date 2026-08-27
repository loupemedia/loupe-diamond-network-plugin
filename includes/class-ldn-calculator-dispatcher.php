<?php
/**
 * Calculator destination dispatcher.
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Calculator_Dispatcher {

    const ROUTE = 'calculator';

    /** @var string */
    private $site_id;

    /** @var LDN_Config */
    private $config;

    /** @var LDN_Data_Fetcher */
    private $fetcher;

    /** @var LDN_Page_Context|null */
    private $context = null;

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

        $ctx = $this->build_context(array(
            'ldn_country' => get_query_var('ldn_country'),
        ));
        if ($ctx === null) {
            return $template;
        }

        LDN_Diagnostics::set_context($ctx);
        $this->context = $ctx;

        LDN_Locale::switch_for_context($ctx, $this->config);

        LDN_Assets::register_enqueue($ctx, $this->config);

        add_action('wp_head', array($this, 'render_head'), 5);
        add_action('wp_footer', array('LDN_Dispatcher', 'render_staging_footer'), 99);

        $custom = LDN_PLUGIN_DIR . 'templates/calculator-page.php';
        return is_readable($custom) ? $custom : $template;
    }

    /**
     * @return void
     */
    public function render_head() {
        if (!($this->context instanceof LDN_Page_Context)) {
            return;
        }
        $renderer = new LDN_Calculator_Renderer($this->fetcher, $this->config);
        echo $renderer->render_head_content($this->context); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * @param array $vars
     * @return LDN_Page_Context|null
     */
    public function build_context(array $vars) {
        $country = isset($vars['ldn_country']) ? strtolower((string) $vars['ldn_country']) : '';
        if ($country === '') {
            return null;
        }
        if (class_exists('LDN_Analytics')) {
            LDN_Analytics::set_page_type('calculator');
        }
        return new LDN_Page_Context(
            $this->site_id,
            'calculator',
            $country,
            null,
            null,
            null,
            self::ROUTE
        );
    }

    /**
     * @return LDN_Page_Context|null
     */
    public function current_context() {
        return $this->context;
    }
}

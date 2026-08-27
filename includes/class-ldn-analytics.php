<?php
/**
 * GTM bootstrap and dataLayer helper.
 *
 * One container per existing GA4 install (Ringspo vs Loupe). Application code
 * pushes named events; GTM maps them to GA4. Empty container id = off.
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Analytics {

    const CONTAINER_PATTERN = '/^GTM-[A-Z0-9]+$/';

    /** @var string */
    private static $page_type = 'site';

    /**
     * @param string $page_type
     * @return void
     */
    public static function set_page_type($page_type) {
        $page_type = trim((string) $page_type);
        if ($page_type !== '') {
            self::$page_type = $page_type;
        }
    }

    /**
     * @return string
     */
    public static function page_type() {
        return self::$page_type;
    }

    /**
     * @return void
     */
    public static function register() {
        add_action('wp_head', array(__CLASS__, 'print_head'), 0);
        add_action('wp_body_open', array(__CLASS__, 'print_body_open'), 0);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_script'));
    }

    /**
     * @param string $site_id
     * @return string Valid GTM- id or empty.
     */
    public static function container_id_for_site($site_id) {
        $id = self::parse_container_id(self::analytics_map(), (string) $site_id);
        if (function_exists('apply_filters')) {
            $id = (string) apply_filters('ldn_gtm_container_id', $id, $site_id);
            $id = self::normalise_container_id($id);
        }
        return $id;
    }

    /**
     * @param array  $analytics Bundle analytics slice.
     * @param string $site_id
     * @return string
     */
    public static function parse_container_id(array $analytics, $site_id) {
        $site_id = (string) $site_id;
        $containers = isset($analytics['containers']) && is_array($analytics['containers'])
            ? $analytics['containers']
            : array();
        foreach ($containers as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ids = isset($row['site_ids']) && is_array($row['site_ids']) ? $row['site_ids'] : array();
            foreach ($ids as $candidate) {
                if ((string) $candidate === $site_id) {
                    $raw = isset($row['gtm_container_id']) ? $row['gtm_container_id'] : '';
                    return self::normalise_container_id($raw);
                }
            }
        }
        return '';
    }

    /**
     * @param string $container_id
     * @param bool   $is_production
     * @param bool   $is_preview
     * @return bool
     */
    public static function should_inject($container_id, $is_production, $is_preview) {
        return $container_id !== '' && $is_production && !$is_preview;
    }

    /**
     * @return void
     */
    public static function print_head() {
        if (is_admin()) {
            return;
        }
        $site_id = self::current_site_id();
        $container = self::container_id_for_site($site_id);
        if (!self::should_inject($container, self::is_production(), self::is_preview())) {
            return;
        }
        echo self::head_snippet($container, self::datalayer_params($site_id)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * @return void
     */
    public static function print_body_open() {
        if (is_admin()) {
            return;
        }
        $site_id = self::current_site_id();
        $container = self::container_id_for_site($site_id);
        if (!self::should_inject($container, self::is_production(), self::is_preview())) {
            return;
        }
        echo self::noscript_snippet($container); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * @return void
     */
    public static function enqueue_script() {
        if (is_admin()) {
            return;
        }
        $site_id = self::current_site_id();
        if ($site_id === '') {
            return;
        }
        $url = defined('LDN_PLUGIN_URL') ? LDN_PLUGIN_URL : '';
        $version = defined('LDN_VERSION') ? LDN_VERSION : '0.0.0';
        wp_enqueue_script(
            'ldn-analytics',
            $url . 'assets/js/analytics.js',
            array(),
            $version,
            true
        );
        wp_localize_script(
            'ldn-analytics',
            'ldnAnalytics',
            array(
                'siteId' => $site_id,
                'country' => self::current_country(),
                'pageType' => self::$page_type,
            )
        );
    }

    /**
     * @param string $container_id
     * @param array  $params
     * @return string
     */
    public static function head_snippet($container_id, array $params) {
        $container_id = self::normalise_container_id($container_id);
        if ($container_id === '') {
            return '';
        }
        $payload = array();
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $payload[ (string) $key ] = $value;
        }
        $json = wp_json_encode($payload);
        if (!is_string($json) || $json === '') {
            $json = '{}';
        }
        $esc_id = esc_js($container_id);
        return '<script>window.dataLayer=window.dataLayer||[];window.dataLayer.push('
            . $json
            . ');</script>' . "\n"
            . '<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({\'gtm.start\':'
            . 'new Date().getTime(),event:\'gtm.js\'});var f=d.getElementsByTagName(s)[0],'
            . 'j=d.createElement(s),dl=l!=\'dataLayer\'?\'&l=\'+l:\'\';j.async=true;j.src='
            . '\'https://www.googletagmanager.com/gtm.js?id=\'+i+dl;f.parentNode.insertBefore(j,f);'
            . '})(window,document,\'script\',\'dataLayer\',\'' . $esc_id . '\');</script>' . "\n";
    }

    /**
     * @param string $container_id
     * @return string
     */
    public static function noscript_snippet($container_id) {
        $container_id = self::normalise_container_id($container_id);
        if ($container_id === '') {
            return '';
        }
        $url = 'https://www.googletagmanager.com/ns.html?id=' . rawurlencode($container_id);
        return '<noscript><iframe src="' . esc_url($url)
            . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
    }

    /**
     * @param mixed $raw
     * @return string
     */
    public static function normalise_container_id($raw) {
        $id = strtoupper(trim((string) $raw));
        if ($id === '' || !preg_match(self::CONTAINER_PATTERN, $id)) {
            return '';
        }
        return $id;
    }

    /**
     * @return array
     */
    private static function analytics_map() {
        if (!class_exists('LDN_Config')) {
            return array();
        }
        return LDN_Config::instance()->get_analytics();
    }

    /**
     * @return string
     */
    private static function current_site_id() {
        if (!class_exists('LDN_Plugin')) {
            return '';
        }
        $id = LDN_Plugin::instance()->site_id();
        return is_string($id) ? $id : '';
    }

    /**
     * @return string
     */
    private static function current_country() {
        if (function_exists('get_query_var')) {
            return strtolower(trim((string) get_query_var('ldn_country')));
        }
        return '';
    }

    /**
     * @param string $site_id
     * @return array
     */
    private static function datalayer_params($site_id) {
        return array(
            'site_id' => $site_id,
            'country' => self::current_country(),
            'page_type' => self::$page_type,
        );
    }

    /**
     * @return bool
     */
    private static function is_production() {
        return class_exists('LDN_Environment') && LDN_Environment::is_production();
    }

    /**
     * @return bool
     */
    private static function is_preview() {
        return defined('LDN_PREVIEW_BASE_URL');
    }
}

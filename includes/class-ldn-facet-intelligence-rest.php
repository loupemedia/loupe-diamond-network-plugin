<?php
/**
 * REST proxy for Facet Intelligence discover / recommend (PRD-009 Epic 5.4).
 *
 * Keeps API keys server-side; the browser calls wp-json only.
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Facet_Intelligence_Rest {

    public const NONCE_ACTION = 'ldn_facet_intelligence';
    public const RATE_LIMIT_PER_MINUTE = 30;

    /**
     * Wave 1 tenant map — keep in sync with config/facet_intelligence_tenants.yaml.
     *
     * @var array<string, array<string, string>>
     */
    private const TENANT_MAP = array(
        'ringspo' => array(
            'us' => 'ringspo-us',
        ),
    );

    /**
     * @param LDN_Plugin $plugin
     * @return void
     */
    public static function register(LDN_Plugin $plugin) {
        add_action('rest_api_init', function () {
            register_rest_route(
                'ldn/v1',
                '/facet-intelligence/discover',
                array(
                    'methods'             => 'POST',
                    'callback'            => array(__CLASS__, 'discover'),
                    'permission_callback' => array(__CLASS__, 'permission_callback'),
                )
            );
            register_rest_route(
                'ldn/v1',
                '/facet-intelligence/recommend',
                array(
                    'methods'             => 'POST',
                    'callback'            => array(__CLASS__, 'recommend'),
                    'permission_callback' => array(__CLASS__, 'permission_callback'),
                )
            );
        });
    }

    /**
     * @param WP_REST_Request|object|null $request
     * @return bool|WP_Error
     */
    public static function permission_callback($request = null) {
        if (!self::is_configured()) {
            return new WP_Error(
                'ldn_fi_unconfigured',
                'Facet Intelligence is not configured.',
                array('status' => 503)
            );
        }
        if (!self::nonce_is_valid(self::request_nonce($request))) {
            return new WP_Error(
                'ldn_fi_forbidden',
                'Missing or invalid Facet Intelligence nonce.',
                array('status' => 403)
            );
        }
        $rate_limit = self::check_rate_limit();
        if (is_wp_error($rate_limit)) {
            return $rate_limit;
        }
        return true;
    }

    /**
     * @param WP_REST_Request|object|null $request
     * @return string
     */
    public static function request_nonce($request) {
        if ($request !== null && method_exists($request, 'get_header')) {
            $header = $request->get_header('X-WP-Nonce');
            if (is_string($header) && $header !== '') {
                return $header;
            }
        }
        if ($request !== null && method_exists($request, 'get_param')) {
            $param = $request->get_param('_wpnonce');
            if (is_string($param) && $param !== '') {
                return $param;
            }
        }
        return '';
    }

    /**
     * @param string $nonce
     * @return bool
     */
    public static function nonce_is_valid($nonce) {
        if (!is_string($nonce) || $nonce === '' || !function_exists('wp_verify_nonce')) {
            return false;
        }
        return (bool) wp_verify_nonce($nonce, self::NONCE_ACTION);
    }

    /**
     * @return true|WP_Error
     */
    public static function check_rate_limit() {
        $ip = self::client_ip();
        $key = 'ldn_fi_rl_' . md5($ip);
        $count = get_transient($key);
        if ($count === false) {
            set_transient($key, 1, MINUTE_IN_SECONDS);
            return true;
        }
        if ((int) $count >= self::RATE_LIMIT_PER_MINUTE) {
            return new WP_Error(
                'ldn_fi_rate_limited',
                'Too many Facet Intelligence requests.',
                array('status' => 429)
            );
        }
        set_transient($key, (int) $count + 1, MINUTE_IN_SECONDS);
        return true;
    }

    /**
     * @return string
     */
    public static function client_ip() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && is_string($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) && is_string($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
        } elseif (!empty($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        } else {
            $ip = '0.0.0.0';
        }
        $valid = filter_var($ip, FILTER_VALIDATE_IP);
        return is_string($valid) ? $valid : '0.0.0.0';
    }

    /**
     * Browser must not choose a scoring backend. Owned tenants are sparklescore.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function sanitize_upstream_body(array $body) {
        unset($body['scoring_backend'], $body['country_code']);
        return $body;
    }

    /**
     * @return bool
     */
    public static function is_configured() {
        $base = getenv('FACET_INTELLIGENCE_BASE_URL');
        $key = getenv('FACET_INTELLIGENCE_API_KEY');
        return is_string($base) && trim($base) !== ''
            && is_string($key) && trim($key) !== '';
    }

    /**
     * @param LDN_Plugin|null $plugin
     * @param string          $site_id
     * @param string          $country_code
     * @return bool
     */
    public static function panel_enabled($plugin, $site_id, $country_code) {
        if ($plugin === null) {
            return false;
        }
        $config = $plugin->config();
        if (!$config->consumer_advisory_enabled($site_id, $country_code)) {
            return false;
        }
        if (!self::is_configured()) {
            return false;
        }
        if ($plugin->feature_enabled('show_alternatives', $country_code)) {
            return true;
        }
        return $plugin->artefacts()->site_has_wp_widget($site_id, 'individual_listings', 'calculator');
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function discover(WP_REST_Request $request) {
        return self::proxy($request, '/v1/discover');
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function recommend(WP_REST_Request $request) {
        return self::proxy($request, '/v1/recommend');
    }

    /**
     * @param WP_REST_Request $request
     * @param string          $path
     * @return WP_REST_Response|WP_Error
     */
    private static function proxy(WP_REST_Request $request, $path) {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new WP_Error('ldn_invalid_json', 'Request body must be JSON.', array('status' => 400));
        }

        $plugin = LDN_Plugin::instance();
        $site_id = $plugin->site_id();
        if ($site_id === null) {
            return new WP_Error('ldn_not_network_site', 'Not a network site.', array('status' => 404));
        }

        $country = isset($body['country_code']) ? strtolower(sanitize_text_field((string) $body['country_code'])) : 'us';
        $body = self::sanitize_upstream_body($body);

        try {
            $tenant_id = self::resolve_tenant_id($site_id, $country);
        } catch (InvalidArgumentException $exception) {
            return new WP_Error('ldn_fi_tenant', $exception->getMessage(), array('status' => 400));
        }

        if (!self::panel_enabled($plugin, $site_id, $country)) {
            return new WP_Error(
                'ldn_fi_disabled',
                'Facet Intelligence alternatives are not enabled for this market.',
                array('status' => 403)
            );
        }

        return self::forward($path, $tenant_id, $body);
    }

    /**
     * @param string $site_id
     * @param string $country_code
     * @return string
     */
    public static function resolve_tenant_id($site_id, $country_code) {
        $site_key = strtolower(trim($site_id));
        $country_key = strtolower(trim($country_code));
        if (!isset(self::TENANT_MAP[$site_key][$country_key])) {
            throw new InvalidArgumentException(
                'No Facet Intelligence tenant mapping for this site and country.'
            );
        }
        return (string) self::TENANT_MAP[$site_key][$country_key];
    }

    /**
     * @param string               $path
     * @param string               $tenant_id
     * @param array<string, mixed> $body
     * @return WP_REST_Response|WP_Error
     */
    private static function forward($path, $tenant_id, array $body) {
        $base = rtrim((string) getenv('FACET_INTELLIGENCE_BASE_URL'), '/');
        $url = $base . $path;
        $headers = array(
            'Content-Type'                => 'application/json',
            'Accept'                      => 'application/json',
            'Authorization'               => 'Bearer ' . (string) getenv('FACET_INTELLIGENCE_API_KEY'),
            'X-Facet-Intelligence-Tenant' => $tenant_id,
        );

        $response = wp_remote_post(
            $url,
            array(
                'headers' => $headers,
                'body'    => wp_json_encode($body),
                'timeout' => 15,
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('ldn_fi_upstream', $response->get_error_message(), array('status' => 502));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($payload)) {
            return new WP_Error('ldn_fi_bad_response', 'Invalid upstream response.', array('status' => 502));
        }

        if ($code >= 400) {
            $detail = isset($payload['detail']) ? (string) $payload['detail'] : 'Facet Intelligence request failed.';
            return new WP_Error('ldn_fi_api', $detail, array('status' => $code > 0 ? $code : 502));
        }

        return new WP_REST_Response($payload, $code > 0 ? $code : 200);
    }
}

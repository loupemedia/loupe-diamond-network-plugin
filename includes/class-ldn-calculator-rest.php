<?php
/**
 * REST routes for the calculator destination page.
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Calculator_Rest {

    /**
     * @param LDN_Plugin $plugin
     * @return void
     */
    public static function register(LDN_Plugin $plugin) {
        add_action('rest_api_init', function () use ($plugin) {
            register_rest_route(
                'ldn/v1',
                '/price-calculator-manifest',
                array(
                    'methods'             => 'GET',
                    'callback'            => array(__CLASS__, 'manifest'),
                    'permission_callback' => '__return_true',
                    'args'                => array(
                        'country' => array('required' => true, 'type' => 'string'),
                        'type'    => array('required' => true, 'type' => 'string'),
                        'carat'   => array('required' => true, 'type' => 'string'),
                        'shape'   => array('required' => true, 'type' => 'string'),
                    ),
                )
            );
            register_rest_route(
                'ldn/v1',
                '/price-calculator-panel',
                array(
                    'methods'             => 'GET',
                    'callback'            => array(__CLASS__, 'panel'),
                    'permission_callback' => '__return_true',
                    'args'                => array(
                        'country' => array('required' => true, 'type' => 'string'),
                        'type'    => array('required' => true, 'type' => 'string'),
                        'carat'   => array('required' => true, 'type' => 'string'),
                        'shape'   => array('required' => true, 'type' => 'string'),
                    ),
                )
            );
            register_rest_route(
                'ldn/v1',
                '/price-position',
                array(
                    'methods'             => 'POST',
                    'callback'            => array(__CLASS__, 'price_position'),
                    'permission_callback' => '__return_true',
                )
            );
        });
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function manifest(WP_REST_Request $request) {
        $plugin = LDN_Plugin::instance();
        $site_id = $plugin->site_id();
        if ($site_id === null) {
            return new WP_Error('ldn_not_network_site', 'Not a network site.', array('status' => 404));
        }

        $country = strtolower(sanitize_text_field((string) $request->get_param('country')));
        $diamond_type = self::canonical_diamond_type((string) $request->get_param('type'));
        $carat = self::normalise_carat((string) $request->get_param('carat'));
        $shape = self::canonical_shape((string) $request->get_param('shape'));

        if ($diamond_type === null || $carat === null || $shape === null) {
            return new WP_Error('ldn_invalid_params', 'Invalid specification.', array('status' => 400));
        }

        $ctx = new LDN_Page_Context(
            $site_id,
            'shape',
            $country,
            $diamond_type,
            $carat,
            $shape,
            'price'
        );
        $manifest = $plugin->data_fetcher()->fetch_artefact('price_calculator_json', $ctx);
        if (!is_array($manifest) || empty($manifest)) {
            return new WP_Error('ldn_manifest_missing', 'No calculator data for that specification.', array('status' => 404));
        }

        return rest_ensure_response($manifest);
    }

    /**
     * Rendered calculator panel HTML for a specification.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function panel(WP_REST_Request $request) {
        $manifest_response = self::manifest($request);
        if (is_wp_error($manifest_response)) {
            return $manifest_response;
        }
        $manifest = $manifest_response->get_data();
        if (!is_array($manifest)) {
            return new WP_Error('ldn_panel_failed', 'Could not build calculator panel.', array('status' => 500));
        }

        $plugin = LDN_Plugin::instance();
        $site_id = $plugin->site_id();
        $country = strtolower(sanitize_text_field((string) $request->get_param('country')));
        $ctx = new LDN_Page_Context($site_id, 'calculator', $country, null, null, null, 'calculator');
        LDN_Locale::switch_for_context($ctx, $plugin->config());
        $renderer = new LDN_Calculator_Renderer($plugin->data_fetcher(), $plugin->config());
        $html = $renderer->render_panel_html($ctx, $manifest);

        if ($html === '') {
            return new WP_Error('ldn_panel_empty', 'Calculator panel unavailable.', array('status' => 404));
        }

        return rest_ensure_response(array(
            'manifest' => $manifest,
            'html'     => $html,
        ));
    }

    /**
     * Proxy to SparkleScore price-position API.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function price_position(WP_REST_Request $request) {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new WP_Error('ldn_invalid_json', 'Request body must be JSON.', array('status' => 400));
        }

        $base = getenv('SPARKLESCORE_BASE_URL');
        if (!is_string($base) || trim($base) === '') {
            return new WP_Error(
                'ldn_sparklescore_unconfigured',
                'Price-position service is not configured.',
                array('status' => 503)
            );
        }

        $url = rtrim($base, '/') . '/api/v1/price-position';
        $headers = array('Content-Type' => 'application/json');
        $api_key = getenv('SPARKLESCORE_API_KEY');
        if (is_string($api_key) && $api_key !== '') {
            $headers['X-API-Key'] = $api_key;
        }

        $response = wp_remote_post(
            $url,
            array(
                'headers' => $headers,
                'body'    => wp_json_encode($body),
                'timeout' => 15,
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('ldn_sparklescore_error', $response->get_error_message(), array('status' => 502));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($payload)) {
            return new WP_Error('ldn_sparklescore_bad_response', 'Invalid upstream response.', array('status' => 502));
        }

        return new WP_REST_Response($payload, $code > 0 ? $code : 200);
    }

    /**
     * @param string $raw
     * @return string|null
     */
    private static function canonical_diamond_type($raw) {
        $value = strtolower(trim($raw));
        if ($value === 'natural' || $value === 'mined') {
            return 'natural';
        }
        if (in_array($value, array('lab', 'lab-grown', 'lab grown'), true)) {
            return 'lab-grown';
        }
        return null;
    }

    /**
     * @param string $raw
     * @return string|null
     */
    private static function normalise_carat($raw) {
        if (preg_match('/^(\d+(?:\.\d+)?)/', trim($raw), $matches)) {
            $value = (float) $matches[1];
            if ($value > 0) {
                $text = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
                return $text === '' ? '0' : $text;
            }
        }
        return null;
    }

    /**
     * @param string $raw
     * @return string|null
     */
    private static function canonical_shape($raw) {
        $slug = sanitize_title($raw);
        $map = array(
            'round'         => 'round',
            'princess'      => 'princess',
            'cushion'       => 'cushion',
            'oval'          => 'oval',
            'emerald'       => 'emerald',
            'emerald-cut'   => 'emerald',
            'asscher'       => 'asscher',
            'asscher-cut'   => 'asscher',
            'radiant'       => 'radiant',
            'pear'          => 'pear',
            'marquise'      => 'marquise',
            'heart'         => 'heart',
        );
        return isset($map[$slug]) ? $map[$slug] : null;
    }
}

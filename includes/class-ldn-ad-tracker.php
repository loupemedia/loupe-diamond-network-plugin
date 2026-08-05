<?php
/**
 * Renders layout ad slots and records impressions (production only).
 *
 * @package LoupeDiamondNetwork
 * @since   0.20.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Ad_Tracker {

    /** @var LDN_Config */
    private $config;

    public function __construct(LDN_Config $config) {
        $this->config = $config;
    }

    /**
     * @param array<string, mixed> $payload
     * @return void
     */
    public function track_impression($payload) {
        if (!LDN_Environment::is_production()) {
            return;
        }
        $payload['event_type'] = 'impression';
        $this->post_event($payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return void
     */
    public function track_click($payload) {
        if (!LDN_Environment::is_production()) {
            return;
        }
        $payload['event_type'] = 'click';
        $this->post_event($payload);
    }

    /**
     * @return string REST track URL for client-side clicks.
     */
    public function track_endpoint_url() {
        $base = $this->config->ads_track_base_url();
        if ($base === '') {
            return '';
        }
        return rtrim($base, '/') . '/track';
    }

    /**
     * @param array<string, mixed> $payload
     * @return void
     */
    private function post_event($payload) {
        $url = $this->track_endpoint_url();
        if ($url === '') {
            return;
        }

        wp_remote_post(
            $url,
            array(
                'timeout' => 2,
                'blocking' => false,
                'headers' => array('Content-Type' => 'application/json'),
                'body' => wp_json_encode($payload),
            )
        );
    }
}

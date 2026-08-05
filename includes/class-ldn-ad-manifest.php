<?php
/**
 * Fetches and caches the network ads manifest (ads.json) from S3.
 *
 * @package LoupeDiamondNetwork
 * @since   0.20.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Ad_Manifest {

    const TRANSIENT_KEY = 'ldn_ads_manifest';

    const TRANSIENT_TTL = 5 * MINUTE_IN_SECONDS;

    /** @var LDN_Config */
    private $config;

    /** @var array|null */
    private $data = null;

    public function __construct(LDN_Config $config) {
        $this->config = $config;
    }

    /**
     * @return array|null Parsed manifest or null on failure.
     */
    public function get() {
        if ($this->data !== null) {
            return $this->data;
        }

        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            $this->data = $cached;
            return $this->data;
        }

        $url = $this->config->ads_manifest_url();
        if ($url === '') {
            return null;
        }

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 10,
                'headers' => array('Accept' => 'application/json'),
            )
        );

        if (is_wp_error($response)) {
            LDN_Plugin::debug_log('ad_manifest', 'ads.json fetch failed: ' . $response->get_error_message());
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            LDN_Plugin::debug_log('ad_manifest', 'ads.json HTTP ' . $code);
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            LDN_Plugin::debug_log('ad_manifest', 'ads.json invalid JSON');
            return null;
        }

        $this->data = $decoded;
        set_transient(self::TRANSIENT_KEY, $this->data, self::TRANSIENT_TTL);

        return $this->data;
    }

    /**
     * Flatten manifest into candidate ad rows for resolution.
     *
     * @return array<int, array<string, mixed>>
     */
    public function candidates() {
        $manifest = $this->get();
        if (!is_array($manifest) || !isset($manifest['advertisers']) || !is_array($manifest['advertisers'])) {
            return array();
        }

        $rows = array();
        foreach ($manifest['advertisers'] as $advertiser_slug => $advertiser) {
            if (!is_array($advertiser) || !isset($advertiser['ads']) || !is_array($advertiser['ads'])) {
                continue;
            }
            $click_url = isset($advertiser['click_url']) ? (string) $advertiser['click_url'] : '';
            $advertiser_name = isset($advertiser['name']) ? (string) $advertiser['name'] : $advertiser_slug;

            foreach ($advertiser['ads'] as $ad_slug => $ad) {
                if (!is_array($ad)) {
                    continue;
                }
                if (empty($ad['active'])) {
                    continue;
                }
                $rows[] = array(
                    'advertiser_slug' => (string) $advertiser_slug,
                    'advertiser_name' => $advertiser_name,
                    'ad_slug' => (string) $ad_slug,
                    'ad_id' => $advertiser_slug . '/' . $ad_slug,
                    'name' => isset($ad['name']) ? (string) $ad['name'] : $ad_slug,
                    'click_url' => $click_url,
                    'slot_type' => isset($ad['slot_type']) ? (string) $ad['slot_type'] : 'retailer',
                    'diamond_type' => isset($ad['diamond_type']) ? (string) $ad['diamond_type'] : 'both',
                    'images' => isset($ad['images']) && is_array($ad['images']) ? $ad['images'] : array(),
                    'targeting' => isset($ad['targeting']) && is_array($ad['targeting']) ? $ad['targeting'] : array(),
                    'layout_slots' => isset($ad['slots']) && is_array($ad['slots']) ? $ad['slots'] : array(),
                    'priority' => isset($ad['priority']) ? (int) $ad['priority'] : 10,
                );
            }
        }

        return $rows;
    }
}

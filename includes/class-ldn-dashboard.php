<?php
/**
 * Ops dashboard config reader — PRD-005 CP51 (PHP equivalent of pipeline_config.py).
 *
 * Reads the shared S3 ops JSON (`pipeline-config.json`) for the
 * `loupe_diamond_network` section: feature flags, active_sites, staging_urls
 * (preview metadata only), and config version for cache invalidation.
 *
 * Module on/off for price/size remains network-rollout.json (LDN_Rollout_Reader).
 * This class is for product feature flags (inventory, SparkleScore, shortlists).
 *
 * @package LoupeDiamondNetwork
 * @since   0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Dashboard {

    const TRANSIENT_KEY = 'ldn_ops_dashboard';

    const TRANSIENT_TTL = 5 * MINUTE_IN_SECONDS;

    const SECTION = 'loupe_diamond_network';

    const FETCH_TIMEOUT = 5;

    /**
     * @var LDN_Config
     */
    private $config;

    /**
     * @var array|null Parsed loupe_diamond_network section.
     */
    private $section = null;

    /**
     * @param LDN_Config $config
     */
    public function __construct(LDN_Config $config) {
        $this->config = $config;
    }

    /**
     * @return void
     */
    public function flush() {
        delete_transient(self::TRANSIENT_KEY);
        $this->section = null;
    }

    /**
     * Raw `loupe_diamond_network` object from ops JSON, or [] when absent.
     *
     * @param bool $force_refresh
     * @return array
     */
    public function get_section($force_refresh = false) {
        if ($this->section !== null && !$force_refresh) {
            return $this->section;
        }

        $cached = $force_refresh ? false : get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            $this->section = $cached;
            return $this->section;
        }

        $parsed = $this->fetch_section();
        $this->section = $parsed;
        set_transient(self::TRANSIENT_KEY, $parsed, self::TRANSIENT_TTL);
        return $this->section;
    }

    /**
     * Config version string for cache-bust coordination (ops dashboard version.txt pattern).
     *
     * @return string
     */
    public function config_version() {
        $section = $this->get_section();
        return isset($section['version']) ? (string) $section['version'] : '';
    }

    /**
     * Whether a site_id is listed in active_sites (legacy global list).
     *
     * Rollout hub supersedes for module enablement; this remains for flags that
     * still reference the ops dashboard list.
     *
     * @param string $site_id
     * @return bool
     */
    public function is_site_active($site_id) {
        $section = $this->get_section();
        $sites = isset($section['active_sites']) && is_array($section['active_sites'])
            ? $section['active_sites']
            : array();
        if (empty($sites)) {
            return true;
        }
        return in_array($site_id, $sites, true);
    }

    /**
     * Global or per-country feature flag (cascades: global → country override).
     *
     * @param string      $flag         e.g. show_inventory, show_sparklescore.
     * @param string|null $country_code Lowercase country code for override lookup.
     * @return bool
     */
    public function is_feature_enabled($flag, $country_code = null) {
        $section = $this->get_section();
        $features = isset($section['features']) && is_array($section['features'])
            ? $section['features']
            : array();

        $value = isset($features[$flag]) ? (bool) $features[$flag] : false;

        if ($country_code !== null && $country_code !== '') {
            $by_country = isset($section['features_by_country']) && is_array($section['features_by_country'])
                ? $section['features_by_country']
                : array();
            $country = strtolower($country_code);
            if (isset($by_country[$country]) && is_array($by_country[$country])
                && array_key_exists($flag, $by_country[$country])
            ) {
                $value = (bool) $by_country[$country][$flag];
            }
        }

        return (bool) apply_filters('ldn_feature_enabled', $value, $flag, $country_code, $section);
    }

    /**
     * Staging preview URL for a site (operator metadata only — not used for env detection).
     *
     * @param string $site_id
     * @return string|null
     */
    public function staging_preview_url($site_id) {
        $section = $this->get_section();
        $urls = isset($section['staging_urls']) && is_array($section['staging_urls'])
            ? $section['staging_urls']
            : array();
        if (!isset($urls[$site_id])) {
            return null;
        }
        $url = (string) $urls[$site_id];
        return $url !== '' ? $url : null;
    }

    /**
     * @return array
     */
    private function fetch_section() {
        $url = $this->config_url();
        if ($url === '') {
            return array();
        }

        $response = wp_remote_get($url, array(
            'timeout' => self::FETCH_TIMEOUT,
            'headers' => array('Accept' => 'application/json'),
        ));

        if (is_wp_error($response)) {
            LDN_Plugin::debug_log('Dashboard', 'fetch failed — ' . $response->get_error_message());
            return $this->last_good();
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            LDN_Plugin::debug_log('Dashboard', 'HTTP ' . $code . ' from ' . $url);
            return $this->last_good();
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            LDN_Plugin::debug_log('Dashboard', 'invalid JSON from ops config');
            return $this->last_good();
        }

        $section = isset($decoded[self::SECTION]) && is_array($decoded[self::SECTION])
            ? $decoded[self::SECTION]
            : array();

        update_option('ldn_dashboard_last_good', $section, false);
        return $section;
    }

    /**
     * @return array
     */
    private function last_good() {
        $stored = get_option('ldn_dashboard_last_good', array());
        return is_array($stored) ? $stored : array();
    }

    /**
     * @return string
     */
    private function config_url() {
        if (defined('LDN_PIPELINE_CONFIG_URL')) {
            return (string) LDN_PIPELINE_CONFIG_URL;
        }
        $consumer = $this->config->network_consumer();
        if (isset($consumer['pipeline_config']['url'])) {
            return (string) $consumer['pipeline_config']['url'];
        }
        return '';
    }
}

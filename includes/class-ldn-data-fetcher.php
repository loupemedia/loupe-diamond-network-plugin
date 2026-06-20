<?php
/**
 * Artefact data fetcher — PRD-005 Artefact-Centric Data Fetch API.
 *
 * Composes the entitlement gate (LDN_Artefacts), key resolver
 * (LDN_S3_Key_Resolver), an HTTP GET, and WordPress transient caching.
 * Components fetch by `artefact_id` + PageContext; filenames/keys are an
 * implementation detail handled by the resolver.
 *
 * Behaviour:
 *   - Not entitled / on-hold        → null, NO HTTP request.
 *   - Key unresolvable              → null.
 *   - HTTP error / non-200 / bad JSON → null (negative-cached briefly to avoid
 *     hammering S3 on every request).
 *   - Success                       → decoded array (JSON) cached for TTL.
 *
 * @package LoupeDiamondNetwork
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Data_Fetcher {

    /**
     * Success cache TTL (seconds). Pricing artefacts refresh daily on S3;
     * a few minutes keeps pages fast without serving very stale data.
     */
    const TTL_OK = 5 * MINUTE_IN_SECONDS;

    /**
     * Negative cache TTL (seconds) for misses/failures.
     */
    const TTL_MISS = MINUTE_IN_SECONDS;

    /**
     * Sentinel stored for negative caching (distinct from transient `false`).
     */
    const MISS = '__ldn_miss__';

    /**
     * HTTP timeout (seconds).
     */
    const FETCH_TIMEOUT = 5;

    /**
     * @var LDN_S3_Key_Resolver
     */
    private $resolver;

    /**
     * @var LDN_Artefacts
     */
    private $artefacts;

    /**
     * @param LDN_S3_Key_Resolver $resolver
     * @param LDN_Artefacts       $artefacts
     */
    public function __construct(LDN_S3_Key_Resolver $resolver, LDN_Artefacts $artefacts) {
        $this->resolver = $resolver;
        $this->artefacts = $artefacts;
    }

    /**
     * Fetch a JSON artefact as a decoded array.
     *
     * @param string           $artefact_id
     * @param LDN_Page_Context $ctx
     * @return array|null Decoded JSON, or null if not entitled / missing / failed.
     */
    public function fetch_artefact($artefact_id, LDN_Page_Context $ctx) {
        $url = $this->gated_url($artefact_id, $ctx);
        if ($url === null) {
            return null;
        }

        $key = 'ldn_af_' . md5($url);
        $cached = get_transient($key);
        if ($cached !== false) {
            return ($cached === self::MISS || !is_array($cached)) ? null : $cached;
        }

        $raw = $this->http_get($url);
        if ($raw === null) {
            set_transient($key, self::MISS, self::TTL_MISS);
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->log("artefact '{$artefact_id}' returned non-JSON from {$url}");
            set_transient($key, self::MISS, self::TTL_MISS);
            return null;
        }

        set_transient($key, $decoded, $this->ttl($artefact_id));
        return $decoded;
    }

    /**
     * Fetch a standalone HTML artefact (chart fallbacks only) as a raw string.
     *
     * @param string           $artefact_id
     * @param LDN_Page_Context $ctx
     * @return string|null
     */
    public function fetch_artefact_html($artefact_id, LDN_Page_Context $ctx) {
        $url = $this->gated_url($artefact_id, $ctx);
        if ($url === null) {
            return null;
        }

        $key = 'ldn_afh_' . md5($url);
        $cached = get_transient($key);
        if ($cached !== false) {
            return ($cached === self::MISS || !is_string($cached)) ? null : $cached;
        }

        $raw = $this->http_get($url);
        if ($raw === null || $raw === '') {
            set_transient($key, self::MISS, self::TTL_MISS);
            return null;
        }

        set_transient($key, $raw, $this->ttl($artefact_id));
        return $raw;
    }

    /**
     * Probe an artefact without reading/writing fetch transients (staging diagnostics).
     *
     * @param string           $artefact_id
     * @param LDN_Page_Context $ctx
     * @return array{artefact_id: string, entitled: bool, url: string|null, http_code: int|null, ok: bool, reason: string}
     */
    public function probe_artefact($artefact_id, LDN_Page_Context $ctx) {
        $report = array(
            'artefact_id' => (string) $artefact_id,
            'entitled' => $this->artefacts->should_render($ctx->site_id, $artefact_id),
            'url' => null,
            'http_code' => null,
            'ok' => false,
            'reason' => '',
        );

        if (!$report['entitled']) {
            $report['reason'] = 'not entitled';
            return $report;
        }

        $url = $this->resolver->resolve_url($artefact_id, $ctx);
        if ($url === null || $url === '') {
            $report['reason'] = 'url unresolved';
            return $report;
        }
        $report['url'] = $url;

        $response = wp_remote_get($url, array('timeout' => self::FETCH_TIMEOUT));
        if (is_wp_error($response)) {
            $report['reason'] = $response->get_error_message();
            return $report;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $report['http_code'] = $code;
        if ($code !== 200) {
            $report['reason'] = 'HTTP ' . $code;
            return $report;
        }

        $body = wp_remote_retrieve_body($response);
        if ($body === '' || $body === null) {
            $report['reason'] = 'empty body';
            return $report;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $report['reason'] = 'not valid JSON';
            return $report;
        }

        $report['ok'] = true;
        $report['reason'] = 'ok';
        return $report;
    }

    /**
     * Delete cached artefact transients so the next request re-fetches from S3.
     *
     * @return void
     */
    public function flush_caches() {
        global $wpdb;
        if (!isset($wpdb->options)) {
            return;
        }
        $like_json = $wpdb->esc_like('_transient_ldn_af_') . '%';
        $like_html = $wpdb->esc_like('_transient_ldn_afh_') . '%';
        $like_json_to = $wpdb->esc_like('_transient_timeout_ldn_af_') . '%';
        $like_html_to = $wpdb->esc_like('_transient_timeout_ldn_afh_') . '%';
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
                $like_json,
                $like_html,
                $like_json_to,
                $like_html_to
            )
        );
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /**
     * Entitlement gate + key resolution → URL, or null when either blocks.
     *
     * @param string           $artefact_id
     * @param LDN_Page_Context $ctx
     * @return string|null
     */
    private function gated_url($artefact_id, LDN_Page_Context $ctx) {
        if (!$this->artefacts->should_render($ctx->site_id, $artefact_id)) {
            return null;
        }
        return $this->resolver->resolve_url($artefact_id, $ctx);
    }

    /**
     * HTTP GET returning the body string, or null on any failure.
     *
     * @param string $url
     * @return string|null
     */
    private function http_get($url) {
        $response = wp_remote_get($url, array('timeout' => self::FETCH_TIMEOUT));
        if (is_wp_error($response)) {
            $this->log('fetch failed: ' . $response->get_error_message());
            return null;
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        return ($body === '' || $body === null) ? null : $body;
    }

    /**
     * Success TTL (filterable per artefact).
     *
     * @param string $artefact_id
     * @return int
     */
    private function ttl($artefact_id) {
        return (int) apply_filters('ldn_artefact_ttl', self::TTL_OK, $artefact_id);
    }

    /**
     * @param string $message
     * @return void
     */
    private function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[LDN_Data_Fetcher] ' . $message);
        }
    }
}

<?php
/**
 * Rollout reader (slave side of the Network Rollout Hub).
 *
 * Implements PRD-005 FR19. The central hub on loupemedianetwork.com publishes a
 * versioned `network-rollout.json` to S3 describing which
 * (site_id x country x module) combinations are live. Each consumer site PULLS
 * that file, reads only its own slice, validates it, and applies it. There are
 * no inbound endpoints and no credentials on the slave — it only reads.
 *
 * Contract (see docs/architecture/network-rollout-hub.md):
 *
 *   {
 *     "version": 42,                  // monotonic int; flush permalinks on change
 *     "updated_at": "<iso8601>",
 *     "updated_by": "operator@…",
 *     "checksum": "sha256:…",         // over the canonicalised rollout payload
 *     "environments": {
 *       "production": { "sites": { "ringspo": { "us": { "price": true } } } },
 *       "staging":    { "sites": { "ringspo": { "us": { "price": true, "size": true } } } }
 *     }
 *   }
 *
 * Environment dimension: one file serves every install. Each slave reads the
 * bucket for ITS environment (LDN_Environment::current()), so a live and a
 * staging install of the same site_id can be enabled differently. The checksum
 * covers the `environments` object.
 *
 * Legacy single-environment shape (top-level `sites`, checksum over `sites`) is
 * still accepted and treated as production for every environment, so older
 * published files keep working until the hub emits the two-bucket shape.
 *
 * Rules enforced here:
 *   - Absent site / country / module  ⇒ OFF (false).
 *   - A slave reads ONLY its own `sites[$site_id]` within its environment.
 *   - If the `environments` shape is present but this environment's bucket is
 *     absent, fall back to the `production` bucket (staging mirrors prod until
 *     it is explicitly diverged).
 *   - A fresh, validated fetch is cached in a short transient; on ANY failure
 *     (network, schema, checksum) the last-good copy is retained — the site
 *     never goes dark because the rollout file is unreachable.
 *   - `version` is monotonic; the router flushes rewrite rules only when the
 *     applied version changes.
 *
 * @package LoupeDiamondNetwork
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Rollout_Reader {

    /**
     * Transient holding the most recent FRESH fetch (avoids refetch per request).
     */
    const TRANSIENT_KEY = 'ldn_rollout_cache';

    /**
     * Fresh-cache TTL. Propagation is allowed to lag by minutes (per design).
     */
    const TRANSIENT_TTL = 5 * MINUTE_IN_SECONDS;

    /**
     * Durable last-good copy (survives transient expiry / cache flushes).
     */
    const LAST_GOOD_OPTION = 'ldn_rollout_last_good';

    /**
     * Persisted version the router has already applied (for flush decisions).
     */
    const APPLIED_VERSION_OPTION = 'ldn_rollout_applied_version';

    /**
     * HTTP timeout for the rollout fetch (seconds).
     */
    const FETCH_TIMEOUT = 5;

    /**
     * Known module identifiers. Used only for input normalisation/validation.
     */
    const MODULES = array('price', 'size');

    /**
     * This site's id (resolved upstream from the request domain).
     *
     * @var string
     */
    private $site_id;

    /**
     * Source URL for network-rollout.json.
     *
     * @var string
     */
    private $url;

    /**
     * In-memory copy of the validated rollout for this request.
     *
     * @var array|null
     */
    private $rollout = null;

    /**
     * Environment bucket this install reads ('production' | 'staging').
     *
     * @var string
     */
    private $environment;

    /**
     * @param string      $site_id     Resolved site id for this install.
     * @param string|null $url         Override the rollout source URL (tests/deploys).
     * @param string|null $environment Override the environment bucket (tests);
     *                                 defaults to LDN_Environment::current().
     */
    public function __construct($site_id, $url = null, $environment = null) {
        $this->site_id = (string) $site_id;
        $this->url = $url !== null ? (string) $url : self::default_url();
        $this->environment = $environment !== null
            ? LDN_Environment::normalize($environment)
            : LDN_Environment::current();
    }

    /**
     * Environment bucket this reader resolves against.
     *
     * @return string 'production' | 'staging'
     */
    public function environment() {
        return $this->environment;
    }

    /**
     * Default rollout source URL.
     *
     * Priority: `LDN_ROLLOUT_URL` constant → config bundle (`network_consumer.rollout.url`)
     * → legacy direct S3 URL. Filterable via `ldn_rollout_url`.
     *
     * @return string
     */
    public static function default_url() {
        if (defined('LDN_ROLLOUT_URL') && LDN_ROLLOUT_URL) {
            return (string) apply_filters('ldn_rollout_url', LDN_ROLLOUT_URL);
        }

        $from_bundle = LDN_Config::instance()->get_rollout_url();
        if ($from_bundle !== '') {
            return (string) apply_filters('ldn_rollout_url', $from_bundle);
        }

        return (string) apply_filters(
            'ldn_rollout_url',
            'https://loupe-operations.s3.amazonaws.com/ops/network-rollout.json'
        );
    }

    // =========================================================================
    // Public query API
    // =========================================================================

    /**
     * Is a given (country, module) live for this site?
     *
     * @param string $country Country code (case-insensitive, e.g. 'us').
     * @param string $module  Module id ('price' | 'size').
     * @return bool
     */
    public function is_enabled($country, $module) {
        $slice = $this->get_site_slice();
        $country = strtolower(trim((string) $country));
        $module = strtolower(trim((string) $module));

        return isset($slice[$country][$module]) && $slice[$country][$module] === true;
    }

    /**
     * Country codes for this site that have at least one module enabled.
     *
     * @return string[]
     */
    public function enabled_countries() {
        $out = array();
        foreach ($this->get_site_slice() as $country => $modules) {
            if (!is_array($modules)) {
                continue;
            }
            foreach ($modules as $enabled) {
                if ($enabled === true) {
                    $out[] = (string) $country;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Enabled module ids for this site in a given country.
     *
     * @param string $country
     * @return string[]
     */
    public function enabled_modules($country) {
        $slice = $this->get_site_slice();
        $country = strtolower(trim((string) $country));
        if (empty($slice[$country]) || !is_array($slice[$country])) {
            return array();
        }
        $out = array();
        foreach ($slice[$country] as $module => $enabled) {
            if ($enabled === true) {
                $out[] = (string) $module;
            }
        }
        return $out;
    }

    /**
     * This site's slice: `{ country: { module: bool } }` for THIS environment.
     * Empty when nothing is enabled or the rollout is unavailable.
     *
     * @return array
     */
    public function get_site_slice() {
        $sites = $this->resolve_env_sites($this->get_rollout());
        if (empty($sites[$this->site_id]) || !is_array($sites[$this->site_id])) {
            return array();
        }
        return $sites[$this->site_id];
    }

    /**
     * Staging test combo filter for this environment (empty = no filter).
     *
     * @return array<int, array{diamond_type: string, carat: string, shape: string}>
     */
    public function get_test_combos() {
        $rollout = $this->get_rollout();
        if (!isset($rollout['environments']) || !is_array($rollout['environments'])) {
            return array();
        }
        $env = $rollout['environments'];
        if (!isset($env[$this->environment]) || !is_array($env[$this->environment])) {
            return array();
        }
        $bucket = $env[$this->environment];
        if (!isset($bucket['test_combos'])) {
            return array();
        }
        return LDN_Test_Combos::normalise_list($bucket['test_combos']);
    }

    /**
     * Whether test_only is set for (country, module) on this site.
     *
     * @param string $country
     * @param string $module
     * @return bool
     */
    public function is_test_only($country, $module) {
        $slice = $this->get_site_slice();
        $country = strtolower(trim((string) $country));
        $module = strtolower(trim((string) $module));
        return !empty($slice[$country][$module])
            && !empty($slice[$country]['test_only']);
    }

    /**
     * Drop cached rollout so the next read pulls fresh from S3.
     *
     * @return void
     */
    public function invalidate_cache() {
        delete_transient(self::TRANSIENT_KEY);
        $this->rollout = null;
    }

    /**
     * Resolve the `{ site_id: { country: { module: bool } } }` map for this
     * install's environment, handling both the two-bucket `environments` shape
     * and the legacy top-level `sites` shape.
     *
     * @param array $rollout
     * @return array
     */
    private function resolve_env_sites(array $rollout) {
        if (isset($rollout['environments']) && is_array($rollout['environments'])) {
            $envs = $rollout['environments'];
            if (isset($envs[$this->environment]['sites']) && is_array($envs[$this->environment]['sites'])) {
                return $envs[$this->environment]['sites'];
            }
            // Environment bucket absent: mirror production until diverged.
            if (isset($envs[LDN_Environment::PRODUCTION]['sites'])
                && is_array($envs[LDN_Environment::PRODUCTION]['sites'])
            ) {
                return $envs[LDN_Environment::PRODUCTION]['sites'];
            }
            return array();
        }

        // Legacy single-environment shape.
        return isset($rollout['sites']) && is_array($rollout['sites']) ? $rollout['sites'] : array();
    }

    /**
     * Current published rollout version (int) or null when unknown.
     *
     * @return int|null
     */
    public function current_version() {
        $rollout = $this->get_rollout();
        return isset($rollout['version']) ? (int) $rollout['version'] : null;
    }

    // =========================================================================
    // Version / flush tracking (consumed by the router, CP52)
    // =========================================================================

    /**
     * Version the router last applied (flushed rewrite rules for).
     *
     * @return int|null
     */
    public function applied_version() {
        $value = get_option(self::APPLIED_VERSION_OPTION, null);
        return $value === null ? null : (int) $value;
    }

    /**
     * Has the published version changed since the router last applied it?
     *
     * @return bool
     */
    public function version_changed() {
        $current = $this->current_version();
        if ($current === null) {
            return false;
        }
        return $current !== $this->applied_version();
    }

    /**
     * Record the current version as applied (call after flushing rewrite rules).
     *
     * @return void
     */
    public function mark_applied() {
        $current = $this->current_version();
        if ($current !== null) {
            update_option(self::APPLIED_VERSION_OPTION, $current, false);
        }
    }

    // =========================================================================
    // Fetch + validate + cache
    // =========================================================================

    /**
     * Resolve the active rollout: fresh transient → network fetch → last-good.
     *
     * Always returns a well-formed array (`version`, `sites` present). Empty
     * `sites` means "nothing enabled", which is the safe default.
     *
     * @return array
     */
    public function get_rollout() {
        if ($this->rollout !== null) {
            return $this->rollout;
        }

        $cached = get_transient(self::TRANSIENT_KEY);
        if ($this->is_valid($cached)) {
            $this->rollout = $cached;
            return $this->rollout;
        }

        $fetched = $this->fetch();
        if ($this->is_valid($fetched)) {
            set_transient(self::TRANSIENT_KEY, $fetched, self::TRANSIENT_TTL);
            update_option(self::LAST_GOOD_OPTION, $fetched, false);
            $this->rollout = $fetched;
            return $this->rollout;
        }

        $last_good = get_option(self::LAST_GOOD_OPTION, null);
        if ($this->is_valid($last_good)) {
            $this->log('using last-good rollout (fresh fetch unavailable/invalid)');
            $this->rollout = $last_good;
            return $this->rollout;
        }

        $this->log('no valid rollout available; defaulting to empty (all off)');
        $this->rollout = $this->empty_rollout();
        return $this->rollout;
    }

    /**
     * Fetch and decode the rollout JSON over HTTP.
     *
     * @return array|null Decoded array, or null on transport/parse failure.
     */
    private function fetch() {
        $response = wp_remote_get($this->url, array('timeout' => self::FETCH_TIMEOUT));

        if (is_wp_error($response)) {
            $this->log('rollout fetch failed: ' . $response->get_error_message());
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->log("rollout fetch HTTP {$code}");
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if ($body === '' || $body === null) {
            $this->log('rollout fetch returned empty body');
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $this->log('rollout body is not valid JSON: ' . json_last_error_msg());
            return null;
        }

        return $decoded;
    }

    /**
     * Validate schema + checksum. Rejects anything malformed so a corrupt or
     * truncated fetch can never overwrite a good last-known state.
     *
     * @param mixed $data
     * @return bool
     */
    public function is_valid($data) {
        if (!is_array($data)) {
            return false;
        }
        if (!isset($data['version']) || !is_numeric($data['version'])) {
            return false;
        }

        // Accept either the two-bucket `environments` shape or legacy `sites`.
        $payload = self::checksum_payload($data);
        if ($payload === null) {
            return false;
        }

        // Checksum is required for integrity, computed over the rollout payload.
        if (empty($data['checksum']) || !is_string($data['checksum'])) {
            $this->log('rollout rejected: missing checksum');
            return false;
        }
        $expected = self::canonical_checksum($payload);
        if (!hash_equals($expected, $data['checksum'])) {
            $this->log('rollout rejected: checksum mismatch');
            return false;
        }

        return true;
    }

    /**
     * The object the checksum is computed over: the `environments` object when
     * present, else the legacy `sites` object. Null when neither is a valid
     * array (malformed file).
     *
     * @param array $data
     * @return array|null
     */
    public static function checksum_payload(array $data) {
        if (isset($data['environments']) && is_array($data['environments'])) {
            return $data['environments'];
        }
        if (isset($data['sites']) && is_array($data['sites'])) {
            return $data['sites'];
        }
        return null;
    }

    /**
     * Canonical checksum over the rollout payload (the `environments` object,
     * or the legacy `sites` object — see checksum_payload()).
     *
     * Recursively key-sorts the payload, JSON-encodes it deterministically, and
     * returns `sha256:<hex>`. The hub MUST use this exact routine over the same
     * payload when writing `network-rollout.json` so the slave can verify
     * integrity.
     *
     * @param array $payload
     * @return string e.g. "sha256:ab12…".
     */
    public static function canonical_checksum(array $payload) {
        $canonical = self::ksort_recursive($payload);
        $json = wp_json_encode($canonical);
        return 'sha256:' . hash('sha256', (string) $json);
    }

    /**
     * Return a recursively key-sorted copy of an array (stable serialisation).
     *
     * @param mixed $value
     * @return mixed
     */
    private static function ksort_recursive($value) {
        if (!is_array($value)) {
            return $value;
        }
        $sorted = array();
        foreach ($value as $key => $item) {
            $sorted[$key] = self::ksort_recursive($item);
        }
        ksort($sorted);
        return $sorted;
    }

    /**
     * Well-formed empty rollout (nothing enabled).
     *
     * @return array
     */
    private function empty_rollout() {
        return array('version' => 0, 'sites' => array());
    }

    /**
     * Log a rollout-layer message (only when WP_DEBUG is on).
     *
     * @param string $message
     * @return void
     */
    private function log($message) {
        LDN_Plugin::debug_log('Rollout_Reader', $message);
    }
}

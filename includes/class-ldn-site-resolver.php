<?php
/**
 * Site resolver.
 *
 * Answers the first question every request must resolve: "which site_id is this
 * install serving?" The network runs as separate WordPress installs, so each
 * one maps to exactly one site_id.
 *
 * Resolution order (first match wins):
 *   1. `LDN_SITE_ID` constant (wp-config.php) — optional escape hatch.
 *   2. `LDN_SITE_ID` environment variable (Kinsta / host env).
 *   3. WordPress option `ldn_site_id` — set via Tools → Loupe Diamond Network
 *      (recommended on staging; no file edits).
 *   4. `ldn_resolve_site_id` filter — alias domains / custom maps.
 *   5. Domain match — request host against each site config's `domain`.
 *   6. null — unknown host (logged); callers must treat as "not our site".
 *
 * Host normalisation strips a leading `www.` and any `:port`, and lowercases,
 * so `WWW.Ringspo.com:443` resolves the same as `ringspo.com`.
 *
 * @package LoupeDiamondNetwork
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Site_Resolver {

    /** WordPress option key for the wp-admin site picker. */
    const OPTION_KEY = 'ldn_site_id';

    /**
     * Config reader (source of the domain → site_id map).
     *
     * @var LDN_Config
     */
    private $config;

    /**
     * How the last resolve() chose site_id (for admin diagnostics).
     *
     * @var string
     */
    private $last_resolution_source = '';

    /**
     * Cached domain → site_id index (normalised hosts).
     *
     * @var array<string, string>|null
     */
    private $domain_index = null;

    /**
     * Memoised resolution per host for this request.
     *
     * @var array<string, ?string>
     */
    private $resolved = array();

    /**
     * @param LDN_Config $config Config reader.
     */
    public function __construct(LDN_Config $config) {
        $this->config = $config;
    }

    /**
     * Resolve the site_id for a host (defaults to the current request host).
     *
     * @param string|null $host Override host; defaults to the request host.
     * @return string|null Site id, or null when the host is not in the network.
     */
    public function resolve($host = null) {
        // 1. wp-config constant (optional; overrides everything).
        if (defined('LDN_SITE_ID') && LDN_SITE_ID) {
            $this->last_resolution_source = 'wp-config (LDN_SITE_ID)';
            return (string) LDN_SITE_ID;
        }

        // 2. Environment variable (same name as the constant).
        $env_id = getenv('LDN_SITE_ID');
        if (is_string($env_id) && $env_id !== '') {
            $this->last_resolution_source = 'environment variable (LDN_SITE_ID)';
            return $env_id;
        }

        // 3. wp-admin dropdown (Tools → Loupe Diamond Network).
        $saved = get_option(self::OPTION_KEY, '');
        if (is_string($saved) && $saved !== '' && $this->config->get_site($saved) !== null) {
            $this->last_resolution_source = 'wp-admin site setting';
            return $saved;
        }

        $host = $host !== null ? $host : $this->current_host();
        $host = self::normalize_host($host);

        if (array_key_exists($host, $this->resolved)) {
            return $this->resolved[$host];
        }

        // 4. Filter hook (staging maps, alias domains, custom logic).
        $filtered = apply_filters('ldn_resolve_site_id', null, $host);
        if (is_string($filtered) && $filtered !== '') {
            $this->last_resolution_source = 'ldn_resolve_site_id filter';
            return $this->resolved[$host] = $filtered;
        }

        // 5. Domain match against config.
        $index = $this->get_domain_index();
        $site_id = isset($index[$host]) ? $index[$host] : null;

        if ($site_id === null) {
            $this->last_resolution_source = 'unresolved';
            $this->log("no site matches host '{$host}'");
        } else {
            $this->last_resolution_source = 'domain match (' . $host . ')';
        }

        return $this->resolved[$host] = $site_id;
    }

    /**
     * Plain-English label for how resolve() last chose site_id.
     *
     * @return string
     */
    public function resolution_source() {
        return $this->last_resolution_source;
    }

    /**
     * Saved site_id from wp-admin, or empty string when using auto-detect.
     *
     * @return string
     */
    public static function get_saved_site_id() {
        $saved = get_option(self::OPTION_KEY, '');
        return is_string($saved) ? $saved : '';
    }

    /**
     * Persist site_id from wp-admin (empty string = auto-detect from domain).
     *
     * @param string $site_id
     * @return bool
     */
    public static function save_site_id($site_id) {
        $site_id = sanitize_key($site_id);
        if ($site_id === '') {
            return delete_option(self::OPTION_KEY);
        }
        return update_option(self::OPTION_KEY, $site_id, false);
    }

    /**
     * Site choices for the admin dropdown (id => label).
     *
     * @return array<string, string>
     */
    public function site_choices() {
        $choices = array();
        foreach ($this->config->get_all_sites() as $site_id => $site) {
            $label = isset($site['brand_name']) ? (string) $site['brand_name'] : $site_id;
            if (!empty($site['domain'])) {
                $label .= ' (' . $site['domain'] . ')';
            }
            $choices[$site_id] = $label;
        }
        asort($choices, SORT_NATURAL | SORT_FLAG_CASE);
        return $choices;
    }

    /**
     * Current request host (raw), from HTTP_HOST with a SERVER_NAME fallback.
     *
     * @return string
     */
    public function current_host() {
        if (!empty($_SERVER['HTTP_HOST'])) {
            return (string) $_SERVER['HTTP_HOST'];
        }
        if (!empty($_SERVER['SERVER_NAME'])) {
            return (string) $_SERVER['SERVER_NAME'];
        }
        return '';
    }

    /**
     * Normalise a host for comparison: lowercase, strip `:port` and leading `www.`.
     *
     * @param string $host
     * @return string
     */
    public static function normalize_host($host) {
        $host = strtolower(trim((string) $host));
        // Drop port if present.
        $colon = strpos($host, ':');
        if ($colon !== false) {
            $host = substr($host, 0, $colon);
        }
        // Drop a single leading www.
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }
        return $host;
    }

    /**
     * Build (and cache) the normalised domain → site_id index from config.
     *
     * @return array<string, string>
     */
    private function get_domain_index() {
        if ($this->domain_index !== null) {
            return $this->domain_index;
        }

        $index = array();
        foreach ($this->config->get_all_sites() as $site_id => $site) {
            if (empty($site['domain'])) {
                continue;
            }
            $host = self::normalize_host($site['domain']);
            if ($host === '') {
                continue;
            }
            if (isset($index[$host]) && $index[$host] !== $site_id) {
                $this->log("domain '{$host}' maps to both '{$index[$host]}' and '{$site_id}'");
            }
            $index[$host] = (string) $site_id;
        }

        return $this->domain_index = $index;
    }

    /**
     * Log a resolver message (only when WP_DEBUG is on).
     *
     * @param string $message
     * @return void
     */
    private function log($message) {
        LDN_Plugin::debug_log('Site_Resolver', $message);
    }
}

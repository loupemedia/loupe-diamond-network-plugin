<?php
/**
 * Hreflang tags for LDN price routes — PRD-005 CP54 (CP54_03).
 *
 * Emits `<link rel="alternate" hreflang="…">` for cross-domain clusters that
 * share a content profile (Loupe ccTLD network, Diamond Price Exact family).
 * Only siblings whose price module is hub-ON in the published rollout are
 * listed, so a Modern Jeweler-only launch does not advertise 404 alternates.
 * Multi-country sites (Ringspo URL with `{country}`) are deferred — those pages
 * need per-country path substitution and remain the legacy mega-plugin's job
 * until the registry-driven hreflang pass lands.
 *
 * @package LoupeDiamondNetwork
 * @since   0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Hreflang {

    /**
     * Content profiles that form cross-domain hreflang clusters (same path on each domain).
     *
     * @var string[]
     */
    const CROSS_DOMAIN_PROFILES = array('loupe_sites', 'diamond_price_exact_sites');

    /**
     * Profile → x-default locale (BCP-47).
     *
     * @var array<string, string>
     */
    const X_DEFAULT_LOCALE = array(
        'loupe_sites'               => 'en-US',
        'diamond_price_exact_sites' => 'en-US',
    );

    /**
     * @var LDN_Config
     */
    private $config;

    /**
     * @var LDN_Rollout_Reader|null
     */
    private $rollout;

    /**
     * @param LDN_Config                  $config
     * @param LDN_Rollout_Reader|null     $rollout When null, no cluster tags are emitted.
     */
    public function __construct($config, $rollout = null) {
        $this->config = $config;
        $this->rollout = $rollout;
    }

    /**
     * @param LDN_Config $config
     * @return self
     */
    public static function from_plugin($config) {
        $rollout = null;
        if (class_exists('LDN_Plugin')) {
            $plugin = LDN_Plugin::instance();
            if ($plugin) {
                $rollout = $plugin->rollout();
            }
        }
        return new self($config, $rollout);
    }

    /**
     * Render hreflang link tags for the current LDN page, or ''.
     *
     * @param LDN_Page_Context $ctx
     * @param string           $canonical_url
     * @return string
     */
    public function render(LDN_Page_Context $ctx, $canonical_url = '') {
        if (!apply_filters('ldn_emit_hreflang', true, $ctx)) {
            return '';
        }

        $profile_name = $this->profile_name($ctx->site_id);
        if ($profile_name === null || !in_array($profile_name, self::CROSS_DOMAIN_PROFILES, true)) {
            return '';
        }

        $path = $this->request_path();
        if ($path === '') {
            return '';
        }

        $alternates = $this->cluster_alternates($profile_name, $path);
        if (count($alternates) < 2) {
            return '';
        }

        $tags = '';
        foreach ($alternates as $locale => $url) {
            $tags .= '<link rel="alternate" hreflang="' . esc_attr($locale) . '" href="'
                . esc_url($url) . '" />' . "\n";
        }

        $x_default = $this->x_default_url($profile_name, $alternates);
        if ($x_default !== '') {
            $tags .= '<link rel="alternate" hreflang="x-default" href="'
                . esc_url($x_default) . '" />' . "\n";
        }

        return $tags;
    }

    /**
     * Build locale → absolute URL map for every site in the cluster.
     *
     * @param string $profile_name
     * @param string $path         Request path without leading domain (may include trailing slash).
     * @return array<string, string>
     */
    public function cluster_alternates($profile_name, $path) {
        $path = ltrim($path, '/');
        $out = array();

        foreach ($this->config->sites_for_profile($profile_name) as $site_id) {
            $site = $this->config->get_site($site_id);
            if (!is_array($site) || empty($site['domain'])) {
                continue;
            }
            if (!$this->sibling_is_live($site_id, $site)) {
                continue;
            }
            $locale = $this->primary_locale($site);
            if ($locale === '') {
                continue;
            }
            $url = 'https://' . trim((string) $site['domain']) . '/' . $path;
            $out[$locale] = user_trailingslashit($url);
        }

        return $out;
    }

    /**
     * Resolve x-default URL for a cluster.
     *
     * @param string               $profile_name
     * @param array<string,string> $alternates
     * @return string
     */
    public function x_default_url($profile_name, array $alternates) {
        $locale = isset(self::X_DEFAULT_LOCALE[$profile_name])
            ? self::X_DEFAULT_LOCALE[$profile_name]
            : 'en-US';
        return isset($alternates[$locale]) ? $alternates[$locale] : '';
    }

    /**
     * Hub-ON price module for this sibling, or false when rollout is unknown.
     *
     * @param string $site_id
     * @param array  $site
     * @return bool
     */
    private function sibling_is_live($site_id, array $site) {
        if ($this->rollout === null || !method_exists($this->rollout, 'is_site_enabled')) {
            return false;
        }
        $country = $this->rollout_country($site);
        if ($country === '') {
            return false;
        }
        return (bool) $this->rollout->is_site_enabled($site_id, $country, 'price');
    }

    /**
     * @param array $site
     * @return string
     */
    private function rollout_country(array $site) {
        if (!empty($site['canonical_source_country'])) {
            return strtolower((string) $site['canonical_source_country']);
        }
        if (empty($site['countries']) || !is_array($site['countries'])) {
            return '';
        }
        $entry = $site['countries'][0];
        if (!is_array($entry) || empty($entry['code'])) {
            return '';
        }
        return strtolower((string) $entry['code']);
    }

    /**
     * @param string $site_id
     * @return string|null Profile bundle key e.g. loupe_sites.
     */
    private function profile_name($site_id) {
        $site = $this->config->get_site($site_id);
        if (!is_array($site) || empty($site['content_profile'])) {
            return null;
        }
        $ref = (string) $site['content_profile'];
        return preg_replace('/\.(ya?ml)$/i', '', basename($ref));
    }

    /**
     * Primary BCP-47 locale from the site's first country entry.
     *
     * @param array $site
     * @return string
     */
    private function primary_locale(array $site) {
        if (empty($site['countries']) || !is_array($site['countries'])) {
            return '';
        }
        $entry = $site['countries'][0];
        if (!is_array($entry)) {
            return '';
        }
        if (!empty($entry['locale'])) {
            return (string) $entry['locale'];
        }
        if (!empty($entry['code'])) {
            return strtolower((string) $entry['code']);
        }
        return '';
    }

    /**
     * Current request path relative to the site root.
     *
     * @return string
     */
    private function request_path() {
        if (isset($GLOBALS['wp']) && isset($GLOBALS['wp']->request) && $GLOBALS['wp']->request !== '') {
            return (string) $GLOBALS['wp']->request;
        }
        return '';
    }
}

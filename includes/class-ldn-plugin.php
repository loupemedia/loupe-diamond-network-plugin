<?php
/**
 * Plugin orchestrator.
 *
 * Single entry point that holds the plugin's services and wires their WordPress
 * hooks. Foundation services (config reader, S3 key resolver, data fetcher,
 * rollout reader, router) are attached here as each is implemented in later
 * CP51/CP52 stories. Kept deliberately thin: it composes services, it does not
 * contain business logic.
 *
 * @package LoupeDiamondNetwork
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Plugin {

    /**
     * Singleton instance.
     *
     * @var LDN_Plugin|null
     */
    private static $instance = null;

    /**
     * Whether init() has already run (idempotency guard).
     *
     * @var bool
     */
    private $booted = false;

    /**
     * Lazily-built site resolver.
     *
     * @var LDN_Site_Resolver|null
     */
    private $site_resolver = null;

    /**
     * Lazily-built rollout reader (keyed to the resolved site).
     *
     * @var LDN_Rollout_Reader|null
     */
    private $rollout_reader = null;

    /**
     * Lazily-built price-module router.
     *
     * @var LDN_Router|null
     */
    private $router = null;

    /**
     * Lazily-built artefact entitlements service.
     *
     * @var LDN_Artefacts|null
     */
    private $artefacts = null;

    /**
     * Lazily-built S3 key resolver.
     *
     * @var LDN_S3_Key_Resolver|null
     */
    private $s3_resolver = null;

    /**
     * Lazily-built data fetcher.
     *
     * @var LDN_Data_Fetcher|null
     */
    private $data_fetcher = null;

    /**
     * Lazily-built request dispatcher.
     *
     * @var LDN_Dispatcher|null
     */
    private $dispatcher = null;

    /**
     * Lazily-built page renderer.
     *
     * @var LDN_Renderer|null
     */
    private $renderer = null;

    /**
     * Memoised resolution of the current request's site_id.
     *
     * Uses a sentinel (false) to distinguish "not yet resolved" from the
     * legitimate resolved value null ("host is not in the network").
     *
     * @var string|null|false
     */
    private $site_id = false;

    /**
     * Get the singleton instance.
     *
     * @return LDN_Plugin
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton.
     */
    private function __construct() {}

    /**
     * Wire up services and hooks. Safe to call more than once.
     *
     * @return void
     */
    public function init() {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        // Register dynamic routes only for known network hosts. Off-network
        // installs (or the hub itself) load the plugin without routing.
        if ($this->is_network_site()) {
            $this->router()->register();
            $this->dispatcher()->register();
            (new LDN_Llms_Txt($this->site_id(), $this->config()))->register();
        }

        if (is_admin()) {
            LDN_Admin::register();
        }

        /**
         * Fires after the Loupe Diamond Network plugin has booted.
         *
         * Later stories hook config/fetcher/render registration here. Exposed
         * as an action so each service registers itself without this method
         * growing a hard dependency on every class.
         *
         * @param LDN_Plugin $plugin The orchestrator instance.
         */
        do_action('ldn_booted', $this);
    }

    /**
     * Plugin version (mirrors the LDN_VERSION constant).
     *
     * @return string
     */
    public function version() {
        return defined('LDN_VERSION') ? LDN_VERSION : '0.0.0';
    }

    // =========================================================================
    // Per-request services (lazy — built only when first needed)
    // =========================================================================

    /**
     * Config reader (shared singleton).
     *
     * @return LDN_Config
     */
    public function config() {
        return LDN_Config::instance();
    }

    /**
     * Site resolver for this install.
     *
     * @return LDN_Site_Resolver
     */
    public function site_resolver() {
        if ($this->site_resolver === null) {
            $this->site_resolver = new LDN_Site_Resolver($this->config());
        }
        return $this->site_resolver;
    }

    /**
     * The resolved site_id for the current request, or null when this host is
     * not part of the network.
     *
     * @return string|null
     */
    public function site_id() {
        if ($this->site_id === false) {
            $this->site_id = $this->site_resolver()->resolve();
        }
        return $this->site_id;
    }

    /**
     * Whether the current request belongs to a known network site.
     *
     * @return bool
     */
    public function is_network_site() {
        return $this->site_id() !== null;
    }

    /**
     * Rollout reader for the resolved site, or null when off-network.
     *
     * @return LDN_Rollout_Reader|null
     */
    public function rollout() {
        $site_id = $this->site_id();
        if ($site_id === null) {
            return null;
        }
        if ($this->rollout_reader === null) {
            $this->rollout_reader = new LDN_Rollout_Reader($site_id);
        }
        return $this->rollout_reader;
    }

    /**
     * Price-module router for the resolved site, or null when off-network.
     *
     * @return LDN_Router|null
     */
    public function router() {
        if (!$this->is_network_site()) {
            return null;
        }
        if ($this->router === null) {
            $this->router = new LDN_Router($this->site_id(), $this->rollout(), $this->config());
        }
        return $this->router;
    }

    /**
     * Artefact entitlements service (context-independent).
     *
     * @return LDN_Artefacts
     */
    public function artefacts() {
        if ($this->artefacts === null) {
            $this->artefacts = new LDN_Artefacts($this->config());
        }
        return $this->artefacts;
    }

    /**
     * S3 key resolver (context-independent).
     *
     * @return LDN_S3_Key_Resolver
     */
    public function s3_resolver() {
        if ($this->s3_resolver === null) {
            $this->s3_resolver = new LDN_S3_Key_Resolver($this->config());
        }
        return $this->s3_resolver;
    }

    /**
     * Artefact data fetcher (entitlement gate + S3 fetch + caching).
     *
     * @return LDN_Data_Fetcher
     */
    public function data_fetcher() {
        if ($this->data_fetcher === null) {
            $this->data_fetcher = new LDN_Data_Fetcher($this->s3_resolver(), $this->artefacts());
        }
        return $this->data_fetcher;
    }

    /**
     * Request dispatcher for the resolved site, or null when off-network.
     *
     * @return LDN_Dispatcher|null
     */
    public function dispatcher() {
        if (!$this->is_network_site()) {
            return null;
        }
        if ($this->dispatcher === null) {
            $this->dispatcher = new LDN_Dispatcher($this->site_id(), $this->config(), $this->data_fetcher());
        }
        return $this->dispatcher;
    }

    /**
     * Page renderer (SSR HTML from S3 artefacts).
     *
     * @return LDN_Renderer
     */
    public function renderer() {
        if ($this->renderer === null) {
            $this->renderer = new LDN_Renderer($this->data_fetcher(), $this->config());
        }
        return $this->renderer;
    }

    /**
     * Clear memoised site_id and site-dependent services (after admin site change).
     *
     * @return void
     */
    public function reset_site_context() {
        $this->site_id = false;
        $this->rollout_reader = null;
        $this->router = null;
        $this->dispatcher = null;
    }
}

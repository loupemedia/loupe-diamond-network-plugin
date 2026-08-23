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
     * How far into CHANGELOG.md to look for the newest entry before giving up.
     * The heading sits in the first few lines; this only guards against
     * scanning a long file if the format ever changes.
     */
    const CHANGELOG_SCAN_LINES = 40;

    /**
     * Singleton instance.
     *
     * @var LDN_Plugin|null
     */
    private static $instance = null;

    /**
     * Parsed newest changelog entry. `false` = not yet read, `null` = read and
     * unparseable — distinguished so an unreadable file is not re-read on every
     * call within a request.
     *
     * @var array|null|false
     */
    private static $release_info = false;

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
     * @var LDN_Nav|null
     */
    private $nav = null;

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
     * Ops dashboard config reader (feature flags).
     *
     * @var LDN_Dashboard|null
     */
    private $dashboard = null;

    /**
     * @var LDN_Ad_System|null|false
     */
    private $ad_system = false;

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
            $this->nav()->register();
            (new LDN_Canonical_Redirect())->register();
            (new LDN_Query_Signals())->register();
            (new LDN_Body_Schema())->register();
            (new LDN_Seo_Bridge())->register();
            (new LDN_Llms_Txt($this->site_id(), $this->config(), $this->artefacts()))->register();
            (new LDN_Robots_Txt())->register();
            require_once LDN_INCLUDES_DIR . 'class-ldn-sitemap-module.php';
            (new LDN_Editorial_Bridge(
                $this->site_id(),
                $this->config(),
                $this->data_fetcher()
            ))->register();
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

    /**
     * Version and release date of the newest CHANGELOG.md entry.
     *
     * The version alone tells an operator *what* is deployed but not *when* it
     * was cut, which is the question being asked when someone is checking
     * whether a deploy landed. The changelog is the only place that date is
     * recorded, so it is read from there rather than declared a second time in
     * PHP — a separate constant would be a fourth version source to keep in
     * sync, and the one most likely to be forgotten.
     *
     * Deliberately NOT `filemtime()`: that reports when the file reached the
     * server, which changes on every redeploy of an unchanged build, and the
     * versioning rule bans it as a version source.
     *
     * The caller compares `version` against `version()` — when they disagree,
     * the changelog is missing an entry for the running build and its date
     * belongs to an older one, so it must not be presented as this build's.
     * `tests/unit/test_plugin_version_changelog_sync.py` fails on that drift,
     * so it should not reach a deploy.
     *
     * @return array{version:string,date:string}|null Null if unreadable or unparsed.
     */
    public static function release_info() {
        if (self::$release_info === false) {
            self::$release_info = self::parse_release(LDN_PLUGIN_DIR . 'CHANGELOG.md');
        }
        return self::$release_info;
    }

    /**
     * Read the topmost `## [x.y.z] — YYYY-MM-DD` heading.
     *
     * Streamed and capped rather than read whole: the changelog only grows, the
     * entry wanted is always at the top, and this runs on the staging
     * diagnostics panel on every page view.
     *
     * @param string $path
     * @return array{version:string,date:string}|null
     */
    private static function parse_release($path) {
        if (!is_readable($path)) {
            return null;
        }
        $handle = fopen($path, 'r');
        if (!$handle) {
            return null;
        }

        $release = null;
        $lines = 0;
        while (($line = fgets($handle)) !== false) {
            if (++$lines > self::CHANGELOG_SCAN_LINES) {
                break;
            }
            // Any dash variant: which one the file uses is cosmetic, the ISO
            // date is not.
            if (preg_match('/^##\s*\[([^\]]+)\]\s*[—–-]\s*(\d{4}-\d{2}-\d{2})/u', $line, $matches)) {
                $release = array('version' => $matches[1], 'date' => $matches[2]);
                break;
            }
        }
        fclose($handle);

        return $release;
    }

    /**
     * Write a debug log line prefixed with plugin version (WP_DEBUG_LOG only).
     *
     * @param string $component Short class or area label.
     * @param string $message   Log message.
     * @return void
     */
    public static function debug_log($component, $message) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            return;
        }
        $version = defined('LDN_VERSION') ? LDN_VERSION : '?';
        error_log(sprintf('[LDN %s] [%s] %s', $version, $component, $message));
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
     * Navigation module for the resolved site, or null when off-network.
     *
     * Deliberately context-free: the header renders on legacy posts and 404s
     * where no route matched, so this does not depend on the dispatcher having
     * built an LDN_Page_Context. CP125_02 hooks the menu filters onto it.
     *
     * @return LDN_Nav|null
     */
    public function nav() {
        if (!$this->is_network_site()) {
            return null;
        }
        if ($this->nav === null) {
            $this->nav = new LDN_Nav($this->site_id(), $this->config(), $this->data_fetcher());
        }
        return $this->nav;
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
     * Display ad system (CP 55) — manifest fetch, resolve, render, track.
     *
     * @return LDN_Ad_System|null Null when core classes are absent.
     */
    public function ad_system() {
        if ($this->ad_system === false) {
            if (!class_exists('LDN_Ad_System') || !class_exists('LDN_Ad_Manifest')) {
                $this->ad_system = null;
            } else {
                $this->ad_system = new LDN_Ad_System(
                    $this->config(),
                    new LDN_Ad_Manifest($this->config())
                );
            }
        }
        return $this->ad_system;
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
     * Ops dashboard reader (feature flags, active_sites metadata).
     *
     * @return LDN_Dashboard
     */
    public function dashboard() {
        if ($this->dashboard === null) {
            $this->dashboard = new LDN_Dashboard($this->config());
        }
        return $this->dashboard;
    }

    /**
     * Whether a future product feature should render for this site/country.
     *
     * @param string      $flag         e.g. show_inventory, show_sparklescore.
     * @param string|null $country_code
     * @return bool
     */
    public function feature_enabled($flag, $country_code = null) {
        return $this->dashboard()->is_feature_enabled($flag, $country_code);
    }

    /**
     * Size-module dispatcher for the resolved site, or null when off-network.
     *
     * @return LDN_Size_Dispatcher|null
     */
    public function size_dispatcher() {
        if (!class_exists('LDN_Size_Module')) {
            return null;
        }
        return LDN_Size_Module::dispatcher();
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

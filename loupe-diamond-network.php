<?php
/**
 * Plugin Name:       Loupe Diamond Network
 * Plugin URI:        https://loupemedianetwork.com/
 * Description:       Renders the diamond pricing network's pages (price + size modules) via dynamic routes, fetching pipeline artefacts from S3 at request time. Rollout (which site × country × module is live) is driven centrally — see docs/architecture/network-rollout-hub.md.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Loupe Media Network
 * License:           Proprietary
 * Text Domain:       loupe-diamond-network
 *
 * @package LoupeDiamondNetwork
 *
 * Architecture: PRD-005 (Loupe Diamond Network Plugin). This is the greenfield
 * mega-plugin that retires loupe-pricing-wp-plugin. It runs on each consumer
 * site (separate WordPress installs) and resolves its behaviour from the site's
 * config by site_id — there are no per-site code branches.
 *
 * CP51_01: Plugin scaffold & activation (this file). Subsequent foundation
 * pieces (config reader, S3 key resolver, data fetcher, rollout reader, router)
 * are loaded on demand via the autoloader below once their class files exist.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('LDN_VERSION')) {
    // Guard against double-load (e.g. plugin present in two locations).
    return;
}

define('LDN_VERSION', '0.3.0');
define('LDN_PLUGIN_FILE', __FILE__);
define('LDN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LDN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LDN_INCLUDES_DIR', LDN_PLUGIN_DIR . 'includes/');

/**
 * Autoloader for plugin classes.
 *
 * Maps `LDN_Foo_Bar` -> `includes/class-ldn-foo-bar.php`. Only loads classes
 * with the `LDN_` prefix so it never interferes with WordPress core or other
 * plugins. Missing files are ignored silently (the scaffold can activate before
 * every class exists).
 */
spl_autoload_register(function ($class) {
    if (strpos($class, 'LDN_') !== 0) {
        return;
    }

    $slug = strtolower(str_replace('_', '-', $class));
    $path = LDN_INCLUDES_DIR . 'class-' . $slug . '.php';

    if (is_readable($path)) {
        require_once $path;
    }
});

/**
 * Activation hook.
 *
 * Rewrite rules are registered by the router (CP52) on `init`; here we only
 * flush so any rules present take effect immediately. Safe to run before the
 * router exists — flushing with no LDN rules is a no-op for this plugin.
 */
function ldn_activate() {
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'ldn_activate');

/**
 * Deactivation hook. Clean up rewrite rules so stale LDN routes don't linger.
 */
function ldn_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'ldn_deactivate');

/**
 * Boot the plugin once WordPress is ready.
 *
 * The orchestrator (LDN_Plugin) wires up the foundation services as they are
 * implemented. It is loaded via the autoloader; if the class file is absent the
 * plugin still activates cleanly (scaffold-only state).
 */
function ldn_bootstrap() {
    if (class_exists('LDN_Plugin')) {
        require_once LDN_INCLUDES_DIR . 'class-ldn-size-module.php';
        LDN_Plugin::instance()->init();
    }
}
add_action('plugins_loaded', 'ldn_bootstrap');

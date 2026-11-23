<?php
/**
 * Loose diamond shopping card module bootstrap (PRD-025 CP 166).
 *
 * Registers router + dispatcher on ldn_booted for entitled sites.
 *
 * @package LoupeDiamondNetwork
 * @since   0.45.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Diamonds_Module {

    /** @var LDN_Diamonds_Router|null */
    private static $router = null;

    /** @var LDN_Diamonds_Dispatcher|null */
    private static $dispatcher = null;

    /**
     * @param LDN_Plugin $plugin
     * @return void
     */
    public static function register(LDN_Plugin $plugin) {
        if (!$plugin->is_network_site()) {
            return;
        }

        $site_id = $plugin->site_id();
        if ($site_id === null) {
            return;
        }

        self::$router = new LDN_Diamonds_Router(
            $site_id,
            $plugin->rollout(),
            $plugin->config(),
            $plugin->artefacts()
        );
        self::$router->register();

        self::$dispatcher = new LDN_Diamonds_Dispatcher(
            $site_id,
            $plugin->config(),
            $plugin->data_fetcher(),
            new LDN_Page_Registry()
        );
        self::$dispatcher->register();
    }

    /**
     * @return LDN_Diamonds_Dispatcher|null
     */
    public static function dispatcher() {
        return self::$dispatcher;
    }

    /**
     * @return LDN_Diamonds_Router|null
     */
    public static function router() {
        return self::$router;
    }
}

add_action('ldn_booted', array('LDN_Diamonds_Module', 'register'));

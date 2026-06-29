<?php
/**
 * Size module bootstrap — registers router + dispatcher on ldn_booted.
 *
 * @package LoupeDiamondNetwork
 * @since   0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Size_Module {

    /** @var LDN_Size_Router|null */
    private static $router = null;

    /** @var LDN_Size_Dispatcher|null */
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

        self::$router = new LDN_Size_Router(
            $site_id,
            $plugin->rollout(),
            $plugin->config(),
            $plugin->artefacts()
        );
        self::$router->register();

        self::$dispatcher = new LDN_Size_Dispatcher(
            $site_id,
            $plugin->config(),
            $plugin->data_fetcher()
        );
        self::$dispatcher->register();
    }

    /**
     * @return LDN_Size_Dispatcher|null
     */
    public static function dispatcher() {
        return self::$dispatcher;
    }
}

add_action('ldn_booted', array('LDN_Size_Module', 'register'));

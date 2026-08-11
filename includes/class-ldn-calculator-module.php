<?php
/**
 * Calculator destination bootstrap.
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Calculator_Module {

    /** @var LDN_Calculator_Router|null */
    private static $router = null;

    /** @var LDN_Calculator_Dispatcher|null */
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

        self::$router = new LDN_Calculator_Router(
            $site_id,
            $plugin->rollout(),
            $plugin->config(),
            $plugin->artefacts()
        );
        self::$router->register();

        self::$dispatcher = new LDN_Calculator_Dispatcher(
            $site_id,
            $plugin->config(),
            $plugin->data_fetcher()
        );
        self::$dispatcher->register();

        LDN_Calculator_Rest::register($plugin);
    }

    /**
     * @return LDN_Calculator_Dispatcher|null
     */
    public static function dispatcher() {
        return self::$dispatcher;
    }
}

add_action('ldn_booted', array('LDN_Calculator_Module', 'register'), 5);

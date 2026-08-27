<?php
/**
 * Standard-pages destination bootstrap (PRD-021 CP137).
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Standard_Pages_Module {

    /** @var LDN_Standard_Pages_Router|null */
    private static $router = null;

    /** @var LDN_Standard_Pages_Dispatcher|null */
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

        self::$router = new LDN_Standard_Pages_Router(
            $site_id,
            $plugin->rollout(),
            $plugin->config()
        );
        self::$router->register();

        self::$dispatcher = new LDN_Standard_Pages_Dispatcher(
            $site_id,
            $plugin->config()
        );
        self::$dispatcher->register();
    }

    /**
     * @return LDN_Standard_Pages_Dispatcher|null
     */
    public static function dispatcher() {
        return self::$dispatcher;
    }
}

add_action('ldn_booted', array('LDN_Standard_Pages_Module', 'register'), 5);

<?php
/**
 * Per-request locale switching for LDN routes.
 *
 * Resolves BCP-47 locale from the site config bundle (country entry), switches
 * WordPress to the matching WP locale for gettext + date formatting, and
 * reloads the plugin text domain.
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Locale {

    /** @var bool */
    private static $switched = false;

    /** @var string|null */
    private static $previous_locale = null;

    /**
     * Switch WordPress to the locale for a page context's country.
     *
     * No-op when the country has no locale, switch_to_locale is unavailable,
     * or the target locale is already active.
     *
     * @param LDN_Page_Context $ctx
     * @param LDN_Config       $config
     * @return void
     */
    public static function switch_for_context(LDN_Page_Context $ctx, LDN_Config $config) {
        $bcp47 = $config->get_locale($ctx->site_id, $ctx->country_code);
        if ($bcp47 === null || $bcp47 === '') {
            return;
        }
        self::switch_to_bcp47($bcp47);
    }

    /**
     * Switch WordPress to a BCP-47 locale tag (e.g. fr-FR).
     *
     * @param string $bcp47
     * @return void
     */
    public static function switch_to_bcp47($bcp47) {
        if (!function_exists('switch_to_locale')) {
            return;
        }

        $wp_locale = self::wp_locale_from_bcp47($bcp47);
        if ($wp_locale === '') {
            return;
        }

        $current = function_exists('determine_locale') ? determine_locale() : get_locale();
        if ($current === $wp_locale) {
            self::load_textdomain();
            return;
        }

        if (self::$switched) {
            self::restore();
        }

        self::$previous_locale = $current;
        if (switch_to_locale($wp_locale)) {
            self::$switched = true;
            self::load_textdomain();
            self::register_shutdown_restore();
        }
    }

    /**
     * Restore the active locale at the end of the request (idempotent).
     *
     * Registered automatically after a successful switch so every LDN
     * dispatcher (price, calculator, size) does not need template-level restore.
     *
     * @return void
     */
    public static function register_shutdown_restore() {
        static $registered = false;
        if ($registered || !self::$switched) {
            return;
        }
        $registered = true;
        add_action('shutdown', array(__CLASS__, 'restore'), 0);
    }

    /**
     * Restore the locale active before the first switch in this request.
     *
     * @return void
     */
    public static function restore() {
        if (!self::$switched || self::$previous_locale === null) {
            return;
        }
        if (function_exists('restore_previous_locale')) {
            restore_previous_locale();
        }
        self::$switched = false;
        self::$previous_locale = null;
        self::load_textdomain();
    }

    /**
     * @param string $bcp47
     * @return string
     */
    public static function wp_locale_from_bcp47($bcp47) {
        $bcp47 = trim((string) $bcp47);
        if ($bcp47 === '') {
            return '';
        }
        return str_replace('-', '_', $bcp47);
    }

    /**
     * Load plugin translations for the active WordPress locale.
     *
     * @return void
     */
    public static function load_textdomain() {
        if (!function_exists('load_plugin_textdomain')) {
            return;
        }
        load_plugin_textdomain(
            'loupe-diamond-network',
            false,
            dirname(plugin_basename(LDN_PLUGIN_FILE)) . '/languages'
        );
    }
}

<?php
/**
 * Minimal wp-admin tools (site picker, pull rollout / flush caches).
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Admin {

    const PAGE_SLUG = 'ldn-network-tools';
    const NONCE_PULL = 'ldn_admin_pull';
    const NONCE_SITE = 'ldn_admin_save_site';

    /**
     * @return void
     */
    public static function register() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
    }

    /**
     * @return void
     */
    public static function menu() {
        add_management_page(
            __('Loupe Diamond Network', 'loupe-diamond-network'),
            __('Loupe Diamond Network', 'loupe-diamond-network'),
            'manage_options',
            self::PAGE_SLUG,
            array(__CLASS__, 'render_page')
        );
    }

    /**
     * @return void
     */
    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'loupe-diamond-network'));
        }

        $notice = '';
        $notice_type = 'success';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['ldn_save_site'])) {
                list($notice, $notice_type) = self::handle_save_site();
            } elseif (isset($_POST['ldn_pull_rollout'])) {
                list($notice, $notice_type) = self::handle_pull();
            }
        }

        $plugin = LDN_Plugin::instance();
        $resolver = $plugin->site_resolver();
        $rollout = $plugin->rollout();
        $version = ($rollout instanceof LDN_Rollout_Reader) ? $rollout->current_version() : null;
        $plugin_version = $plugin->version();
        $environment = LDN_Environment::current();
        $site_id = $plugin->site_id();
        $saved_site_id = LDN_Site_Resolver::get_saved_site_id();
        $site_choices = $resolver->site_choices();
        $resolution_source = $resolver->resolution_source();
        $current_host = $resolver->current_host();

        include LDN_PLUGIN_DIR . 'admin/views/tools.php';
    }

    /**
     * @return array{0: string, 1: string} notice message and type
     */
    private static function handle_save_site() {
        if (!isset($_POST['_wpnonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::NONCE_SITE)
        ) {
            return array(__('Security check failed.', 'loupe-diamond-network'), 'error');
        }

        $raw = isset($_POST['ldn_site_id']) ? sanitize_key(wp_unslash($_POST['ldn_site_id'])) : '';
        $config = LDN_Plugin::instance()->config();

        if ($raw !== '' && $config->get_site($raw) === null) {
            return array(__('Unknown site id.', 'loupe-diamond-network'), 'error');
        }

        LDN_Site_Resolver::save_site_id($raw);
        LDN_Plugin::instance()->reset_site_context();
        self::pull_now();

        if ($raw === '') {
            return array(
                __('Site setting cleared — using auto-detect from domain.', 'loupe-diamond-network'),
                'success',
            );
        }

        return array(
            sprintf(
                /* translators: %s: site id */
                __('This install is now set to site "%s". Caches cleared.', 'loupe-diamond-network'),
                $raw
            ),
            'success',
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function handle_pull() {
        if (!isset($_POST['_wpnonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::NONCE_PULL)
        ) {
            return array(__('Security check failed.', 'loupe-diamond-network'), 'error');
        }

        self::pull_now();
        return array(
            __('Rollout and artefact caches cleared. Next page load will pull fresh from S3.', 'loupe-diamond-network'),
            'success',
        );
    }

    /**
     * Force rollout re-fetch and flush artefact transients; flush rewrite rules.
     *
     * @return void
     */
    public static function pull_now() {
        $plugin = LDN_Plugin::instance();
        $rollout = $plugin->rollout();
        if ($rollout instanceof LDN_Rollout_Reader) {
            $rollout->invalidate_cache();
            $rollout->get_rollout();
            if ($rollout->version_changed()) {
                flush_rewrite_rules();
                $rollout->mark_applied();
            }
        }
        $plugin->data_fetcher()->flush_caches();
        $plugin->config()->flush();
        $plugin->dashboard()->flush();
        $registry = new LDN_Page_Registry();
        $registry->flush_cache($plugin->site_id());
    }
}

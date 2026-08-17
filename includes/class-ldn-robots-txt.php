<?php
/**
 * /robots.txt — three-tier bot policy + LDN sitemap index.
 *
 * Served by the plugin (same pattern as /llms.txt) so a plugin deploy updates
 * Sitemap lines. A physical robots.txt in the web root still wins at nginx;
 * the admin notice tells the operator to delete that file once.
 *
 * Canonical policy text: config/security/robots-consumer-site.txt (kept in
 * sync with assets/robots-consumer-site.txt).
 *
 * @package LoupeDiamondNetwork
 * @since   0.22.8
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Robots_Txt {

    /**
     * Query var that marks a robots.txt request.
     */
    const QUERY_VAR = 'ldn_robots';

    /**
     * Register rewrite + core robots_txt filter + admin notice.
     *
     * @return void
     */
    public function register() {
        add_filter('query_vars', array($this, 'register_query_var'));
        add_action('init', array($this, 'add_rewrite_rule'));
        add_action('template_redirect', array($this, 'maybe_serve'), 0);
        add_filter('robots_txt', array($this, 'filter_robots_txt'), 999, 2);
        add_action('admin_notices', array($this, 'maybe_static_file_notice'));
    }

    /**
     * @param string[] $vars
     * @return string[]
     */
    public function register_query_var($vars) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /**
     * @return void
     */
    public function add_rewrite_rule() {
        add_rewrite_rule('^robots\.txt$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
    }

    /**
     * Output text/plain and exit when this is a robots.txt request.
     *
     * @return void
     */
    public function maybe_serve() {
        if ((int) get_query_var(self::QUERY_VAR) !== 1) {
            return;
        }
        if (!apply_filters('ldn_serve_robots_txt', true)) {
            return;
        }

        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo $this->generate();
        exit;
    }

    /**
     * Replace WordPress / SEOPress robots output when no rewrite has fired.
     *
     * @param string $output Existing robots body.
     * @param bool   $public Whether the site is public (blog_public).
     * @return string
     */
    public function filter_robots_txt($output, $public) {
        if ((int) $public !== 1) {
            return $output;
        }
        if (!apply_filters('ldn_serve_robots_txt', true)) {
            return $output;
        }
        return $this->generate();
    }

    /**
     * Warn if a disk robots.txt is hiding this plugin.
     *
     * @return void
     */
    public function maybe_static_file_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!$this->static_file_present()) {
            return;
        }
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__(
            'A physical robots.txt file in the web root is hiding the Loupe Diamond Network version. Delete that file once so /robots.txt is served by the plugin on deploy.',
            'loupe-diamond-network'
        );
        echo '</p></div>';
    }

    /**
     * Whether nginx/Apache would serve a file instead of WordPress.
     *
     * @return bool
     */
    public function static_file_present() {
        return is_readable($this->static_file_path());
    }

    /**
     * @return string
     */
    public function static_file_path() {
        return (defined('ABSPATH') ? ABSPATH : '') . 'robots.txt';
    }

    /**
     * Build the robots.txt body (pure — unit-tested).
     *
     * @param string|null $home Origin used to replace DOMAIN (tests pass this).
     * @return string
     */
    public function generate($home = null) {
        $template = $this->template_body();
        if ($template === '') {
            return '';
        }
        $host = $this->host_from_home($home);
        return str_replace('DOMAIN', $host, $template);
    }

    /**
     * @param string|null $home
     * @return string
     */
    public function host_from_home($home = null) {
        if ($home === null || $home === '') {
            $home = $this->home();
        }
        $host = (string) parse_url((string) $home, PHP_URL_HOST);
        return $host !== '' ? $host : 'DOMAIN';
    }

    /**
     * @return string
     */
    public function template_path() {
        return dirname(__DIR__) . '/assets/robots-consumer-site.txt';
    }

    /**
     * @return string
     */
    private function template_body() {
        $path = $this->template_path();
        if (!is_readable($path)) {
            return '';
        }
        $body = (string) file_get_contents($path);
        return $body;
    }

    /**
     * @return string
     */
    private function home() {
        return function_exists('home_url') ? (string) home_url('/') : '';
    }
}

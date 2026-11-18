<?php
/**
 * IndexNow verification key at the web root ({key}.txt).
 *
 * Serves the public verification token from the deployed config bundle
 * (config/indexnow_keys.yaml). IndexNow and Bing use this file to confirm
 * the site owner controls the domain before accepting URL push notifications.
 *
 * @package LoupeDiamondNetwork
 * @since   0.42.31
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_IndexNow_Key {

    /**
     * Query var that marks an IndexNow key request.
     */
    const QUERY_VAR = 'ldn_indexnow';

    /**
     * @var string
     */
    private $site_id;

    /**
     * @var LDN_Config
     */
    private $config;

    /**
     * Resolved verification key for this install, or empty when unconfigured.
     *
     * @var string
     */
    private $key = '';

    /**
     * @param string     $site_id
     * @param LDN_Config $config
     */
    public function __construct($site_id, $config) {
        $this->site_id = (string) $site_id;
        $this->config = $config;
    }

    /**
     * Register rewrite rule + template_redirect handler when a key exists.
     *
     * @return void
     */
    public function register() {
        $key = $this->config->indexnow_key_for_site($this->site_id);
        if (!is_string($key) || $key === '') {
            return;
        }
        if (!preg_match('/^[a-f0-9]{8,128}$/i', $key)) {
            return;
        }

        $this->key = strtolower($key);
        add_filter('query_vars', array($this, 'register_query_var'));
        add_action('init', array($this, 'add_rewrite_rule'));
        add_action('template_redirect', array($this, 'maybe_serve'));
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
        if ($this->key === '') {
            return;
        }
        add_rewrite_rule(
            '^' . preg_quote($this->key, '#') . '\.txt$',
            'index.php?' . self::QUERY_VAR . '=1',
            'top'
        );
    }

    /**
     * Output text/plain and exit when this is the configured key file request.
     *
     * @return void
     */
    public function maybe_serve() {
        if ((int) get_query_var(self::QUERY_VAR) !== 1 || $this->key === '') {
            return;
        }
        if (!apply_filters('ldn_serve_indexnow_key', true, $this->site_id, $this->key)) {
            return;
        }

        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo $this->key_body();
        exit;
    }

    /**
     * Plain-text body for the verification file (pure — unit-tested).
     *
     * @return string
     */
    public function key_body() {
        if ($this->key === '') {
            return '';
        }
        return $this->key . "\n";
    }
}

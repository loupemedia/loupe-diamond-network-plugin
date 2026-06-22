<?php
/**
 * /llms.txt generator — PRD-005 CP54 (LLM visibility).
 *
 * Serves a plain-text, machine-readable site descriptor at the web root on
 * every network consumer install. Content is generated from the deployed config
 * bundle (site + profile + URL structure) — not hand-written per domain.
 *
 * @package LoupeDiamondNetwork
 * @since   0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Llms_Txt {

    /**
     * Query var that marks an llms.txt request.
     */
    const QUERY_VAR = 'ldn_llms';

    /**
     * @var LDN_Config
     */
    private $config;

    /**
     * @var string
     */
    private $site_id;

    /**
     * @param string     $site_id
     * @param LDN_Config $config
     */
    public function __construct($site_id, $config) {
        $this->site_id = (string) $site_id;
        $this->config = $config;
    }

    /**
     * Register rewrite rule + template_redirect handler.
     *
     * @return void
     */
    public function register() {
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
        add_rewrite_rule('^llms\.txt$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
    }

    /**
     * Output text/plain and exit when this is an llms.txt request.
     *
     * @return void
     */
    public function maybe_serve() {
        if ((int) get_query_var(self::QUERY_VAR) !== 1) {
            return;
        }
        if (!apply_filters('ldn_serve_llms_txt', true, $this->site_id)) {
            return;
        }

        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo $this->generate();
        exit;
    }

    /**
     * Build the llms.txt body (pure — unit-tested).
     *
     * @return string
     */
    public function generate() {
        $site = $this->config->get_site($this->site_id);
        if (!is_array($site)) {
            return '';
        }

        $brand = isset($site['brand_name']) ? (string) $site['brand_name'] : $this->site_id;
        $domain = isset($site['domain']) ? (string) $site['domain'] : '';
        $base = $domain !== '' ? 'https://' . $domain : $this->home();

        $countries = $this->country_labels($site);
        $country_phrase = !empty($countries)
            ? implode(', ', $countries)
            : strtoupper((string) ($site['countries'][0]['code'] ?? ''));

        $lines = array();
        $lines[] = '# ' . $brand;
        $lines[] = '> Independent diamond market pricing and guidance'
            . ($country_phrase !== '' ? ' for ' . $country_phrase . '.' : '.');
        $lines[] = '';

        $lines[] = '## Key pages';
        foreach ($this->sample_page_urls($base) as $label => $url) {
            if ($url !== '') {
                $lines[] = '- ' . $label . ': ' . $url;
            }
        }
        $lines[] = '';

        $lines[] = '## Data';
        $lines[] = '- Updated daily (market index pipeline)';
        $lines[] = '- Coverage: natural + lab-grown diamonds, 10+ shapes, 21 carat weights';
        $lines[] = '- Sources: major online diamond retailers (see site for current pool)';
        $lines[] = '- Format: daily-refreshed market index with distribution and time-series charts';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Example internal URLs for the site's URL structure.
     *
     * @param string $base Site origin (https://domain).
     * @return array<string, string> label => absolute URL
     */
    public function sample_page_urls($base) {
        $structure = $this->config->get_url_structure($this->site_id);
        if (!is_array($structure)) {
            return array();
        }

        $base = rtrim($base, '/');
        $type_nat = isset($structure['type_natural']) ? (string) $structure['type_natural'] : 'natural';
        $carat = $this->sample_carat_slug($structure);
        $shape = 'round';

        $out = array();
        if (!empty($structure['level_1'])) {
            $out['Diamond prices (overview)'] = $base . $this->expand_pattern(
                (string) $structure['level_1'], $type_nat, $carat, $shape
            );
        }
        if (!empty($structure['level_2'])) {
            $out['Natural diamond prices'] = $base . $this->expand_pattern(
                (string) $structure['level_2'], $type_nat, $carat, $shape
            );
        }
        if (!empty($structure['level_4'])) {
            $out['1 carat round natural (example)'] = $base . $this->expand_pattern(
                (string) $structure['level_4'], $type_nat, $carat, $shape
            );
        }
        return $out;
    }

    /**
     * @param array $structure
     * @return string Carat URL segment e.g. "1-carat".
     */
    private function sample_carat_slug(array $structure) {
        $format = isset($structure['carat_format']) ? (string) $structure['carat_format'] : '{value}-carat';
        if ($format === '' || $format === null) {
            return isset($structure['carat_weight']) ? (string) $structure['carat_weight'] : '1';
        }
        return str_replace('{value}', '1', $format);
    }

    /**
     * Substitute placeholders in a url_structures pattern (no {country} → omit segment).
     *
     * @param string $pattern
     * @param string $type
     * @param string $carat
     * @param string $shape
     * @return string Path beginning with /
     */
    public function expand_pattern($pattern, $type, $carat, $shape) {
        $site = $this->config->get_site($this->site_id);
        $country = '';
        if (is_array($site) && !empty($site['countries'][0]['code'])) {
            $country = (string) $site['countries'][0]['code'];
        }

        $replacements = array(
            '{country}' => $country,
            '{type}'    => $type,
            '{carat}'   => $carat,
            '{shape}'   => $shape,
        );

        $path = (string) $pattern;
        foreach ($replacements as $placeholder => $value) {
            $path = str_replace($placeholder, $value, $path);
        }
        // Drop empty country segments when pattern had {country} but value is empty.
        $path = preg_replace('#/+#', '/', $path);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }
        return user_trailingslashit($path);
    }

    /**
     * Human country names from site config.
     *
     * @param array $site
     * @return string[]
     */
    private function country_labels(array $site) {
        $out = array();
        if (empty($site['countries']) || !is_array($site['countries'])) {
            return $out;
        }
        foreach ($site['countries'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (!empty($entry['full_name'])) {
                $out[] = (string) $entry['full_name'];
            } elseif (!empty($entry['code'])) {
                $out[] = strtoupper((string) $entry['code']);
            }
        }
        return $out;
    }

    /**
     * @return string
     */
    private function home() {
        return function_exists('home_url') ? (string) home_url('/') : '';
    }
}

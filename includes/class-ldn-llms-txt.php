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
     * @var LDN_Artefacts|null
     */
    private $artefacts;

    /**
     * @var string
     */
    private $site_id;

    /**
     * @param string              $site_id
     * @param LDN_Config          $config
     * @param LDN_Artefacts|null  $artefacts Optional entitlement gate for size URLs.
     */
    public function __construct($site_id, $config, $artefacts = null) {
        $this->site_id = (string) $site_id;
        $this->config = $config;
        $this->artefacts = $artefacts;
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

        $size_urls = $this->size_sample_page_urls($base);
        if ($size_urls !== array()) {
            $lines[] = '';
            $lines[] = '## Diamond size analysis';
            $lines[] = '> Real face-up dimensions, length/width spread, and shape comparisons'
                . ' from retailer inventory measurements (not ideal-cut theory alone).';
            foreach ($size_urls as $label => $url) {
                if ($url !== '') {
                    $lines[] = '- ' . $label . ': ' . $url;
                }
            }
            $lines[] = '';
            $lines[] = '## Size dataset';
            $lines[] = '- Median length, width, face-up area (mm²), depth %, and L/W ratio per shape × carat';
            $lines[] = '- Percentile spreads (p10–p90) on individual size pages';
            $lines[] = '- Sources: aggregated measurements from major US online diamond retailers';
            $lines[] = '- Format: server-rendered charts and tables; curated comparisons indexed, long-tail on demand';
        }

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
     * Key size-module URLs when the site is entitled and url_structures defines size paths.
     *
     * @param string $base Site origin (https://domain).
     * @return array<string, string> label => absolute URL
     */
    public function size_sample_page_urls($base) {
        if (!$this->size_module_enabled()) {
            return array();
        }

        $structure = $this->config->get_url_structure($this->site_id);
        if (!is_array($structure) || empty($structure['size_level_1'])) {
            return array();
        }

        $base = rtrim($base, '/');
        $carat = $this->sample_carat_slug($structure);
        $shape = 'round';
        $shape_slug = $this->config->shape_to_s3_slug($shape);
        $out = array();

        $out['Diamond size chart (all shapes)'] = $this->size_path_url(
            $base,
            (string) $structure['size_level_1'],
            array('shape' => $shape_slug, 'carat' => $carat)
        );

        if (!empty($structure['size_level_2'])) {
            $out['Round diamond size chart'] = $this->size_path_url(
                $base,
                (string) $structure['size_level_2'],
                array('shape' => $shape_slug, 'carat' => $carat)
            );
        }

        if (!empty($structure['size_level_3'])) {
            $out['1 carat round size (example)'] = $this->size_path_url(
                $base,
                (string) $structure['size_level_3'],
                array('shape' => $shape_slug, 'carat' => $carat)
            );
        }

        if (!empty($structure['size_level_compare'])) {
            $princess_slug = $this->config->shape_to_s3_slug('princess');
            $compare_slug = $shape_slug . '-' . $carat . '-vs-' . $princess_slug . '-' . $carat;
            $out['Size comparison (example)'] = $this->size_path_url(
                $base,
                (string) $structure['size_level_compare'],
                array('shape' => $shape_slug, 'carat' => $carat, 'compare' => $compare_slug)
            );
        }

        if (!empty($structure['size_level_sitemap'])) {
            $out['Size pages XML sitemap'] = $this->size_path_url(
                $base,
                (string) $structure['size_level_sitemap'],
                array()
            );
        }

        return $out;
    }

    /**
     * Whether this site should document the size module in llms.txt.
     *
     * @return bool
     */
    public function size_module_enabled() {
        $structure = $this->config->get_url_structure($this->site_id);
        if (!is_array($structure) || empty($structure['size_level_1'])) {
            return false;
        }
        if ($this->artefacts instanceof LDN_Artefacts) {
            return $this->artefacts->site_entitled_to_artefact($this->site_id, 'size_summary_json');
        }
        return true;
    }

    /**
     * Build an absolute size-module URL from a url_structures pattern.
     *
     * @param string               $base
     * @param string               $pattern
     * @param array<string,string> $parts shape, carat, compare
     * @return string
     */
    public function size_path_url($base, $pattern, array $parts) {
        $path = (string) $pattern;
        $replacements = array(
            '{shape}'   => $parts['shape'] ?? 'round',
            '{carat}'   => $parts['carat'] ?? '1-carat',
            '{compare}' => $parts['compare'] ?? '',
        );
        foreach ($replacements as $placeholder => $value) {
            $path = str_replace($placeholder, $value, $path);
        }
        $path = preg_replace('#/+#', '/', $path);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }
        if (!preg_match('/\.xml$/i', $path)) {
            $path = user_trailingslashit($path);
        }
        return rtrim($base, '/') . $path;
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

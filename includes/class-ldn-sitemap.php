<?php
/**
 * Sitemap XML builder — consistent urlset format for price (registry) and size (S3).
 *
 * @package LoupeDiamondNetwork
 * @since   0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Sitemap {

    /**
     * Build a standard urlset from registry rows with optional hreflang alternates.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array<int, array{locale:string, href:string}>> $alternates_by_url keyed by canonical_url
     * @return string XML document.
     */
    public static function urlset_from_rows(array $rows, array $alternates_by_url = array()) {
        $body = '';
        foreach ($rows as $row) {
            $loc = isset($row['canonical_url']) ? trim((string) $row['canonical_url']) : '';
            if ($loc === '') {
                continue;
            }
            $body .= "  <url>\n";
            $body .= '    <loc>' . self::xml_escape($loc) . "</loc>\n";

            $lastmod = self::format_lastmod($row['last_generated'] ?? null);
            if ($lastmod !== '') {
                $body .= '    <lastmod>' . self::xml_escape($lastmod) . "</lastmod>\n";
            }

            if (isset($alternates_by_url[$loc]) && is_array($alternates_by_url[$loc])) {
                foreach ($alternates_by_url[$loc] as $alt) {
                    if (empty($alt['href']) || empty($alt['locale'])) {
                        continue;
                    }
                    $body .= '    <xhtml:link rel="alternate" hreflang="'
                        . self::xml_escape((string) $alt['locale']) . '" href="'
                        . self::xml_escape((string) $alt['href']) . "\" />\n";
                }
            }

            $body .= "  </url>\n";
        }

        return self::wrap_urlset($body);
    }

    /**
     * Parse an existing urlset XML (e.g. Z3 size sitemap) into rows for re-emission.
     *
     * @param string $xml
     * @return array<int, array{canonical_url:string, last_generated:?string}>
     */
    public static function parse_urlset($xml) {
        if (!is_string($xml) || trim($xml) === '') {
            return array();
        }
        if (!function_exists('simplexml_load_string')) {
            return array();
        }
        $previous = libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($root === false) {
            return array();
        }

        $root->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urls = $root->url ?? $root->children('http://www.sitemaps.org/schemas/sitemap/0.9')->url;
        if ($urls === null) {
            $urls = array();
        }
        if (!is_array($urls) && !($urls instanceof Traversable)) {
            $urls = array($urls);
        }

        $rows = array();
        foreach ($urls as $url_node) {
            $loc = '';
            if (isset($url_node->loc)) {
                $loc = trim((string) $url_node->loc);
            } else {
                $loc_nodes = $url_node->children('http://www.sitemaps.org/schemas/sitemap/0.9')->loc;
                if (isset($loc_nodes[0])) {
                    $loc = trim((string) $loc_nodes[0]);
                }
            }
            if ($loc === '') {
                continue;
            }
            $lastmod = '';
            if (isset($url_node->lastmod)) {
                $lastmod = trim((string) $url_node->lastmod);
            }
            $rows[] = array(
                'canonical_url'  => $loc,
                'last_generated' => $lastmod !== '' ? $lastmod : null,
            );
        }
        return $rows;
    }

    /**
     * Normalise foreign XML into our urlset wrapper (shared Content-Type path).
     *
     * @param string $xml
     * @return string
     */
    public static function normalise_urlset_xml($xml) {
        $rows = self::parse_urlset($xml);
        if (empty($rows)) {
            return self::wrap_urlset('');
        }
        return self::urlset_from_rows($rows);
    }

    /**
     * Sitemap index listing child sitemap absolute URLs.
     *
     * @param array<int, string> $sitemap_urls
     * @return string
     */
    public static function sitemap_index(array $sitemap_urls) {
        $body = '';
        $now = gmdate('Y-m-d');
        foreach ($sitemap_urls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            $body .= "  <sitemap>\n";
            $body .= '    <loc>' . self::xml_escape($url) . "</loc>\n";
            $body .= '    <lastmod>' . self::xml_escape($now) . "</lastmod>\n";
            $body .= "  </sitemap>\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . $body
            . '</sitemapindex>';
    }

    /**
     * @param string $body Inner <url> elements.
     * @return string
     */
    public static function wrap_urlset($body) {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n"
            . $body
            . '</urlset>';
    }

    /**
     * @param mixed $value
     * @return string ISO8601 date (Y-m-d) or ''.
     */
    private static function format_lastmod($value) {
        if ($value === null || $value === '') {
            return '';
        }
        $ts = strtotime((string) $value);
        if ($ts === false) {
            return '';
        }
        return gmdate('Y-m-d', $ts);
    }

    /**
     * @param string $value
     * @return string
     */
    private static function xml_escape($value) {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}

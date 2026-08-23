<?php
/**
 * Read-only client for ops.page_url_registry (CP52 / FR20).
 *
 * @package LoupeDiamondNetwork
 * @since   0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Page_Registry {

    const TRANSIENT_PREFIX = 'ldn_page_registry_';

    const TRANSIENT_TTL = 15 * MINUTE_IN_SECONDS;

    /**
     * Inclusive cap for price-sitemap rows.
     *
     * C4.5 writes shape-leaf pages at hierarchy_level 5 (product "Level 4").
     * A cap of 4 dropped every money page and the sitemap 404'd empty.
     * 6+ is reserved for a future cert/listing leaf that must not be indexed
     * with the market-index tree.
     */
    const PRICE_SITEMAP_MAX_HIERARCHY = 5;

    const MODULE_PRICING = 'pricing';

    const MODULE_CALCULATOR = 'calculator';

    const MODULE_SIZE = 'size';

    /** @var string[] Modules included in the price sitemap child. */
    const PRICE_SITEMAP_MODULES = array(self::MODULE_PRICING, self::MODULE_CALCULATOR);

    /** @var string[] Modules included in the size sitemap child. */
    const SIZE_SITEMAP_MODULES = array(self::MODULE_SIZE);

    /**
     * Whether a registry hierarchy_level belongs in the price sitemap.
     *
     * @param int $hierarchy_level
     * @return bool
     */
    public static function price_sitemap_includes_level($hierarchy_level) {
        $level = (int) $hierarchy_level;
        return $level >= 1 && $level <= self::PRICE_SITEMAP_MAX_HIERARCHY;
    }

    /**
     * Fetch sitemap rows for a site, optionally filtered by country codes.
     *
     * Each row: canonical_url, url_path, locale, last_generated, country_code,
     * hierarchy_level, diamond_type, carat, shape.
     *
     * @param string   $site_id
     * @param string[] $country_codes Lowercase; empty = all countries.
     * @param int      $max_level     Max hierarchy_level inclusive.
     * @return array<int, array<string, mixed>>
     */
    public function fetch_sitemap_rows(
        $site_id,
        array $country_codes = array(),
        $max_level = self::PRICE_SITEMAP_MAX_HIERARCHY,
        array $modules = self::PRICE_SITEMAP_MODULES
    ) {
        $site_id = (string) $site_id;
        $module_key = implode(',', $modules);
        $cache_key = self::TRANSIENT_PREFIX . md5(
            $site_id . '|' . implode(',', $country_codes) . '|' . $max_level . '|' . $module_key
        );
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $rows = $this->query_sitemap_rows($site_id, $country_codes, (int) $max_level, $modules);
        set_transient($cache_key, $rows, self::TRANSIENT_TTL);
        return $rows;
    }

    /**
     * @return void
     */
    public function flush_cache($site_id = null) {
        global $wpdb;
        if (!($wpdb instanceof wpdb)) {
            return;
        }
        $like = $wpdb->esc_like(self::TRANSIENT_PREFIX) . '%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_' . $like,
            '_transient_timeout_' . $like
        ));
    }

    /**
     * @param string   $site_id
     * @param string[] $country_codes
     * @param int      $max_level
     * @return array<int, array<string, mixed>>
     */
    private function query_sitemap_rows($site_id, array $country_codes, $max_level, array $modules) {
        $conn = LDN_Db::connection();
        if ($conn === null) {
            LDN_Plugin::debug_log('Page_Registry', 'no database connection');
            return array();
        }

        $modules = array_values(array_filter(array_map('strval', $modules)));
        if (empty($modules)) {
            return array();
        }

        $params = array($site_id, $modules, $max_level);
        $country_filter = '';
        if (!empty($country_codes)) {
            $placeholders = array();
            foreach ($country_codes as $code) {
                $placeholders[] = '$' . (count($params) + 1);
                $params[] = strtolower((string) $code);
            }
            $country_filter = ' AND country_code IN (' . implode(', ', $placeholders) . ')';
        }

        $sql = "
            SELECT canonical_url, url_path, locale, last_generated, country_code,
                   hierarchy_level, diamond_type, carat, shape, module
            FROM ops.page_url_registry
            WHERE site_id = \$1
              AND module = ANY(\$2::text[])
              AND is_indexable = TRUE
              AND hierarchy_level <= \$3
              {$country_filter}
            ORDER BY module, hierarchy_level, country_code, diamond_type, carat NULLS FIRST, shape NULLS FIRST
        ";

        $result = @pg_query_params($conn, $sql, $params);
        if ($result === false) {
            LDN_Plugin::debug_log('Page_Registry', 'query failed — ' . pg_last_error($conn));
            return array();
        }

        $rows = array();
        while ($row = pg_fetch_assoc($result)) {
            if (!is_array($row) || empty($row['canonical_url'])) {
                continue;
            }
            $rows[] = $row;
        }
        pg_free_result($result);
        return $rows;
    }

    /**
     * Hreflang alternates for the same page intent across locales (registry-driven).
     *
     * @param string      $diamond_type
     * @param string|null $carat
     * @param string|null $shape
     * @param string      $country_code
     * @param int         $hierarchy_level
     * @return array<int, array{locale:string, canonical_url:string}>
     */
    public function fetch_hreflang_cluster(
        $diamond_type,
        $carat,
        $shape,
        $country_code,
        $hierarchy_level
    ) {
        $conn = LDN_Db::connection();
        if ($conn === null) {
            return array();
        }

        $sql = "
            SELECT site_id, canonical_url, locale
            FROM ops.page_url_registry
            WHERE diamond_type = \$1
              AND country_code = \$2
              AND hierarchy_level = \$3
              AND module = \$6
              AND (carat IS NOT DISTINCT FROM \$4::numeric)
              AND (shape IS NOT DISTINCT FROM \$5)
            ORDER BY locale
        ";

        $carat_param = $carat !== null && $carat !== '' ? (string) $carat : null;
        $shape_param = $shape !== null && $shape !== '' ? (string) $shape : null;

        $result = @pg_query_params($conn, $sql, array(
            $diamond_type,
            strtolower((string) $country_code),
            (int) $hierarchy_level,
            $carat_param,
            $shape_param,
            self::MODULE_PRICING,
        ));

        if ($result === false) {
            return array();
        }

        $rows = array();
        while ($row = pg_fetch_assoc($result)) {
            if (!is_array($row) || empty($row['canonical_url'])) {
                continue;
            }
            $rows[] = array(
                'locale'        => isset($row['locale']) ? (string) $row['locale'] : 'en',
                'canonical_url' => (string) $row['canonical_url'],
                'site_id'       => isset($row['site_id']) ? (string) $row['site_id'] : '',
            );
        }
        pg_free_result($result);
        return $rows;
    }
}

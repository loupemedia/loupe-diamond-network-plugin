<?php
/**
 * Renderer component trait — split from class-ldn-renderer.php (CP53).
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

trait LDN_Trait_SchemaBridge {
    /**
     * @param array  $profile
     * @param string $feature
     * @return bool
     */
    private function profile_has_schema_feature(array $profile, $feature) {
        $features = isset($profile['schema_features']) && is_array($profile['schema_features'])
            ? $profile['schema_features']
            : array();

        return in_array($feature, $features, true);
    }

    /**
     * Resolve FAQ {question, answer} pairs for schema, or [] when none.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return array<int, array{question:mixed, answer:mixed}>
     */
    public function schema_faq_pairs(LDN_Page_Context $ctx, array $bag) {
        $value = $this->section_value('faq_static', $ctx, $bag);
        return is_array($value) ? $value : array();
    }

    /**
     * Priced product items for an ItemList (hybrid/recommendation schema types).
     *
     * Only the all-shapes ranking yields a meaningful product list today; other
     * levels return [] (the schema generator then omits ItemList).
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return array<int, array{name:string, price:mixed, currency:string, url:string}>
     */
    public function schema_items(LDN_Page_Context $ctx, array $bag) {
        if ($ctx->page_level !== 'all-shapes' || empty($bag['ranking']) || !is_array($bag['ranking'])) {
            return array();
        }
        $rows = isset($bag['ranking']['shapes']) && is_array($bag['ranking']['shapes'])
            ? $bag['ranking']['shapes']
            : array();
        $currency = $this->config->get_currency($ctx->site_id, $ctx->country_code);

        $items = array();
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['shape'])) {
                continue;
            }
            $price = isset($row['median_price']) ? $row['median_price']
                : (isset($row['current_price']) ? $row['current_price'] : null);
            $url = !empty($row['page_url'])
                ? (string) $row['page_url']
                : $this->build_price_page_url($ctx, 'shape', array('shape' => (string) $row['shape']));
            $items[] = array(
                'name'     => ucwords((string) $row['shape']),
                'price'    => $price,
                'currency' => $currency !== null ? (string) $currency : 'USD',
                'url'      => $url,
            );
        }
        return $items;
    }
}

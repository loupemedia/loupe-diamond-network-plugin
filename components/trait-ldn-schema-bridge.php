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
        return $this->merged_faq_pairs($ctx, $bag);
    }

    /**
     * Child page items for an ItemList (hybrid/recommendation schema types).
     *
     * Only the all-shapes ranking yields a list today; other levels return []
     * (the schema generator then omits ItemList). Each row is a shape price
     * page, not a Product.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $bag
     * @return array<int, array{name:string, url:string}>
     */
    public function schema_items(LDN_Page_Context $ctx, array $bag) {
        if ($ctx->page_level !== 'all-shapes' || empty($bag['ranking']) || !is_array($bag['ranking'])) {
            return array();
        }
        $rows = isset($bag['ranking']['shapes']) && is_array($bag['ranking']['shapes'])
            ? $bag['ranking']['shapes']
            : array();

        $items = array();
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['shape'])) {
                continue;
            }
            $url = !empty($row['page_url'])
                ? (string) $row['page_url']
                : $this->build_price_page_url($ctx, 'shape', array('shape' => (string) $row['shape']));
            if ($url === '') {
                continue;
            }
            $items[] = array(
                'name' => ucwords((string) $row['shape']),
                'url'  => $url,
            );
        }
        return $items;
    }
}

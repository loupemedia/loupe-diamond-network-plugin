<?php
/**
 * Staging test-page filter (shape-level combos).
 *
 * When rollout has ``test_only`` for a (site × country × module) and the
 * environment carries a ``test_combos`` list, only those shape URLs are served;
 * all other shape requests 404. Non-shape levels 404 while test_only is on.
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Test_Combos {

    /**
     * Canonical staging test matrix from the deployed config bundle.
     *
     * @param LDN_Config|null $config
     * @return array<int, array{diamond_type: string, carat: string, shape: string}>
     */
    public static function defaults(LDN_Config $config = null) {
        if ($config === null) {
            $config = new LDN_Config();
        }
        return self::shape_combos_from_matrix($config->get_staging_qa_matrix());
    }

    /**
     * @param array $matrix staging_qa_matrix section from the bundle.
     * @return array<int, array{diamond_type: string, carat: string, shape: string}>
     */
    public static function shape_combos_from_matrix(array $matrix) {
        $raw = isset($matrix['shape_combos']) && is_array($matrix['shape_combos'])
            ? $matrix['shape_combos']
            : array();
        $out = array();
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = isset($entry['diamond_type']) ? strtolower(trim((string) $entry['diamond_type'])) : '';
            $carat = isset($entry['carat']) ? self::normalise_carat($entry['carat']) : '';
            $shape = isset($entry['shape']) ? strtolower(trim((string) $entry['shape'])) : '';
            if ($type === '' || $carat === '' || $shape === '') {
                continue;
            }
            $out[] = array(
                'diamond_type' => $type,
                'carat' => $carat,
                'shape' => $shape,
            );
        }
        if ($out !== array()) {
            return $out;
        }
        return self::fallback_shape_combos();
    }

    /**
     * @return array<int, array{diamond_type: string, carat: string, shape: string}>
     */
    private static function fallback_shape_combos() {
        return array(
            array('diamond_type' => 'natural', 'carat' => '1', 'shape' => 'round'),
            array('diamond_type' => 'natural', 'carat' => '1.5', 'shape' => 'oval'),
            array('diamond_type' => 'lab-grown', 'carat' => '2', 'shape' => 'round'),
            array('diamond_type' => 'lab-grown', 'carat' => '3', 'shape' => 'oval'),
        );
    }

    /**
     * Normalise a rollout ``test_combos`` array.
     *
     * @param mixed $raw
     * @return array<int, array{diamond_type: string, carat: string, shape: string}>
     */
    public static function normalise_list($raw) {
        if (!is_array($raw)) {
            return array();
        }
        $out = array();
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = isset($entry['diamond_type']) ? strtolower(trim((string) $entry['diamond_type'])) : '';
            $carat = isset($entry['carat']) ? self::normalise_carat($entry['carat']) : '';
            $shape = isset($entry['shape']) ? strtolower(trim((string) $entry['shape'])) : '';
            if ($type === '' || $carat === '' || $shape === '') {
                continue;
            }
            $out[] = array(
                'diamond_type' => $type,
                'carat' => $carat,
                'shape' => $shape,
            );
        }
        return $out;
    }

    /**
     * Whether a shape-level context is allowed by the test combo list.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $combos From normalise_list().
     * @return bool
     */
    public static function allows_context(LDN_Page_Context $ctx, array $combos) {
        if ($combos === array()) {
            return true;
        }
        if ($ctx->page_level !== 'shape') {
            return false;
        }
        foreach ($combos as $combo) {
            if (self::combo_matches($ctx, $combo)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param LDN_Page_Context $ctx
     * @param array            $combo
     * @return bool
     */
    public static function combo_matches(LDN_Page_Context $ctx, array $combo) {
        $type = isset($combo['diamond_type']) ? strtolower((string) $combo['diamond_type']) : '';
        $carat = isset($combo['carat']) ? self::normalise_carat($combo['carat']) : '';
        $shape = isset($combo['shape']) ? strtolower((string) $combo['shape']) : '';

        if ($ctx->diamond_type === null || strtolower($ctx->diamond_type) !== $type) {
            return false;
        }
        if ($ctx->carat === null || self::normalise_carat($ctx->carat) !== $carat) {
            return false;
        }
        if ($ctx->shape === null || strtolower($ctx->shape) !== $shape) {
            return false;
        }
        return true;
    }

    /**
     * @param mixed $carat
     * @return string
     */
    public static function normalise_carat($carat) {
        if (!is_numeric($carat)) {
            return trim((string) $carat);
        }
        $value = (float) $carat;
        if (abs($value - round($value)) < 0.0001) {
            return (string) (int) round($value);
        }
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}

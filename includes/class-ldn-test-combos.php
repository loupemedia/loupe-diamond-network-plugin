<?php
/**
 * Staging test-page filter (shape-level combos).
 *
 * When rollout has ``test_only`` for a (site × country × module) and the
 * environment carries a ``test_combos`` list, hub levels (top-level, diamond-type,
 * all-shapes) are always allowed; only shape-level URLs are filtered to the
 * combo list. All other shape requests 404.
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
    public static function defaults(?LDN_Config $config = null) {
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
     * Hub page levels that bypass the shape combo filter under test_only.
     *
     * @var string[]
     */
    const HUB_PAGE_LEVELS = array('top-level', 'diamond-type', 'all-shapes');

    /**
     * Whether a price-module context is allowed by the test combo list.
     *
     * Hub levels are always allowed when combos are present (S3 primary-artefact
     * probe still 404s missing data). Shape pages must match a combo entry.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $combos From normalise_list().
     * @return bool
     */
    public static function allows_context(LDN_Page_Context $ctx, array $combos) {
        if ($combos === array()) {
            return true;
        }
        if (in_array($ctx->page_level, self::HUB_PAGE_LEVELS, true)) {
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
     * Whether a size-module context is allowed by the staging test combo list.
     *
     * Size pages have no diamond_type — match on shape + carat only.
     *
     * @param LDN_Page_Context $ctx
     * @param array            $combos From normalise_list().
     * @return bool
     */
    public static function allows_size_context(LDN_Page_Context $ctx, array $combos) {
        if ($combos === array()) {
            return true;
        }
        if ($ctx->module !== 'size') {
            return false;
        }
        if ($ctx->page_level === 'size-mega-hub') {
            return false;
        }
        if ($ctx->page_level === 'size-sitemap') {
            return true;
        }
        if ($ctx->page_level === 'size-shape-hub') {
            if ($ctx->shape === null) {
                return false;
            }
            foreach ($combos as $combo) {
                $shape = isset($combo['shape']) ? strtolower((string) $combo['shape']) : '';
                if ($shape !== '' && strtolower($ctx->shape) === $shape) {
                    return true;
                }
            }
            return false;
        }
        if ($ctx->page_level === 'size-individual') {
            foreach ($combos as $combo) {
                if (self::size_combo_matches($ctx, $combo)) {
                    return true;
                }
            }
            return false;
        }
        if ($ctx->page_level === 'size-comparison') {
            if ($ctx->compare_slug === null) {
                return false;
            }
            $sides = self::parse_compare_slug_sides($ctx->compare_slug);
            if ($sides === null) {
                return false;
            }
            foreach ($combos as $combo) {
                foreach (array('a', 'b') as $key) {
                    $probe = new LDN_Page_Context(
                        $ctx->site_id,
                        'size-individual',
                        $ctx->country_code,
                        null,
                        $sides[$key]['carat'],
                        $sides[$key]['shape'],
                        'size'
                    );
                    if (self::size_combo_matches($probe, $combo)) {
                        return true;
                    }
                }
            }
            return false;
        }
        if ($ctx->page_level === 'size-comparison-tool') {
            return $combos !== array();
        }
        if ($ctx->page_level === 'size-spread-checker') {
            return $combos !== array();
        }
        return false;
    }

    /**
     * Match size individual page on shape + carat (ignores diamond_type).
     *
     * @param LDN_Page_Context $ctx
     * @param array            $combo
     * @return bool
     */
    public static function size_combo_matches(LDN_Page_Context $ctx, array $combo) {
        $carat = isset($combo['carat']) ? self::normalise_carat($combo['carat']) : '';
        $shape = isset($combo['shape']) ? strtolower((string) $combo['shape']) : '';
        if ($ctx->shape === null || strtolower($ctx->shape) !== $shape) {
            return false;
        }
        if ($ctx->carat === null || self::normalise_carat($ctx->carat) !== $carat) {
            return false;
        }
        return true;
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

    /**
     * Minimal compare-slug parser for staging test_combos gating (shape slug may be approximate).
     *
     * @param string $slug
     * @return array{a:array{shape:string,carat:string},b:array{shape:string,carat:string}}|null
     */
    public static function parse_compare_slug_sides($slug) {
        if (!is_string($slug) || strpos($slug, '-vs-') === false) {
            return null;
        }
        $parts = explode('-vs-', $slug, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $parse = static function ($token) {
            if (!preg_match('/^(.+)-([\d.]+)-carat$/', $token, $m)) {
                return null;
            }
            return array(
                'shape' => str_replace('-', ' ', strtolower($m[1])),
                'carat' => self::normalise_carat($m[2]),
            );
        };
        $a = $parse($parts[0]);
        $b = $parse($parts[1]);
        if ($a === null || $b === null) {
            return null;
        }
        return array('a' => $a, 'b' => $b);
    }
}

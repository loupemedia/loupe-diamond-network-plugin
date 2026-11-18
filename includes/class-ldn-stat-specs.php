<?php
/**
 * Shared headline-stat specs for summary-data.json consumers.
 *
 * Single source for path resolution and per-page-level price labels. On
 * all-shapes (L3) and diamond-type hubs, distribution.median_price is a
 * sample-weighted mean of per-shape medians (C5.1 / C5.2), not a true stone
 * median — those levels label the figure "Typical price". Shape (L4) pages
 * carry C3's p50 and keep "Median price" in Schema.org and a longer on-page
 * label in hero/stat cards.
 *
 * @package LoupeDiamondNetwork
 * @since   0.42.30
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Stat_Specs {

    /**
     * Page levels where the headline price is a composite across shapes.
     *
     * @var array<string, bool>
     */
    const COMPOSITE_PRICE_LEVELS = array(
        'all-shapes'   => true,
        'diamond-type' => true,
    );

    /**
     * Candidate paths for the headline price figure (first hit wins).
     *
     * @return array<int, array<int, string>>
     */
    public static function headline_price_paths() {
        return array(
            array('distribution', 'median_price'),
            array('aggregate', 'weighted_median_price'),
            array('median_price'),
            array('distribution', 'percentiles', 'p50'),
        );
    }

    /**
     * On-page label for stats grids and hero stat cards.
     *
     * @param string $page_level
     * @return string
     */
    public static function on_page_headline_price_label($page_level) {
        if (isset(self::COMPOSITE_PRICE_LEVELS[$page_level])) {
            return __('Typical price', 'loupe-diamond-network');
        }
        return __('Median (all qualities)', 'loupe-diamond-network');
    }

    /**
     * Schema.org variableMeasured name for the headline price PropertyValue.
     *
     * @param string $page_level
     * @return string
     */
    public static function schema_headline_price_label($page_level) {
        if (isset(self::COMPOSITE_PRICE_LEVELS[$page_level])) {
            return __('Typical price', 'loupe-diamond-network');
        }
        return __('Median price', 'loupe-diamond-network');
    }

    /**
     * Plain-text phrase for Dataset.description (no gettext — schema copy only).
     *
     * @param string $page_level
     * @return string
     */
    public static function description_headline_price_phrase($page_level) {
        if (isset(self::COMPOSITE_PRICE_LEVELS[$page_level])) {
            return 'Typical price';
        }
        return 'Median price';
    }

    /**
     * Specs for JSON-LD variableMeasured. Excludes trimmed-mean current_price.
     *
     * @param string $page_level
     * @return array<int, array{label:string, format:string, paths:array<int, array<int, string>>}>
     */
    public static function measured_specs($page_level) {
        return array(
            array(
                'label'  => self::schema_headline_price_label($page_level),
                'format' => 'currency',
                'paths'  => self::headline_price_paths(),
            ),
            array(
                'label'  => __('Lowest price', 'loupe-diamond-network'),
                'format' => 'currency',
                'paths'  => array(
                    array('distribution', 'price_range', 'min'),
                    array('min_price'),
                    array('price_low'),
                ),
            ),
            array(
                'label'  => __('Highest price', 'loupe-diamond-network'),
                'format' => 'currency',
                'paths'  => array(
                    array('distribution', 'price_range', 'max'),
                    array('max_price'),
                    array('price_high'),
                ),
            ),
            array(
                'label'  => __('Diamonds analyzed', 'loupe-diamond-network'),
                'format' => 'integer',
                'paths'  => array(
                    array('distribution', 'sample_size'),
                    array('num_diamonds'),
                    array('sample_size'),
                ),
            ),
        );
    }
}

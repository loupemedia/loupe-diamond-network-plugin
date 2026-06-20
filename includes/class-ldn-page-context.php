<?php
/**
 * Page context value object.
 *
 * Immutable description of the page being rendered, built by the router from
 * the request (PRD-005 S3 Key Resolution Contract, Step 1). Consumed by the S3
 * key resolver, data fetcher, and render layer.
 *
 * Field conventions (canonical — not raw URL slugs):
 *   - `page_level`   : 'shape' | 'all-shapes' | 'diamond-type' | 'top-level'
 *                      (also 'individual' for leaf cert pages). Hyphenated to
 *                      match site_content_entitlements.yaml.
 *   - `country_code` : lowercase (e.g. 'us', 'au').
 *   - `diamond_type` : canonical 'natural' | 'lab-grown' (the dispatch layer
 *                      maps the site's raw URL slug → canonical before this is
 *                      built; e.g. guru 'mined' → 'natural').
 *   - `carat`        : numeric string used in the S3 prefix (e.g. '1', '1.5').
 *   - `shape`        : knowledge-base shape name (e.g. 'round'); slugged via
 *                      LDN_Config::shape_to_s3_slug() at resolution time.
 *
 * @package LoupeDiamondNetwork
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Page_Context {

    /** @var string */
    public $site_id;

    /** @var string */
    public $page_level;

    /** @var string */
    public $country_code;

    /** @var string|null */
    public $diamond_type;

    /** @var string|null */
    public $carat;

    /** @var string|null */
    public $shape;

    /**
     * @param string      $site_id
     * @param string      $page_level
     * @param string      $country_code
     * @param string|null $diamond_type
     * @param string|null $carat
     * @param string|null $shape
     */
    public function __construct(
        $site_id,
        $page_level,
        $country_code,
        $diamond_type = null,
        $carat = null,
        $shape = null
    ) {
        $this->site_id = (string) $site_id;
        $this->page_level = (string) $page_level;
        $this->country_code = strtolower((string) $country_code);
        $this->diamond_type = $diamond_type !== null ? (string) $diamond_type : null;
        $this->carat = $carat !== null ? (string) $carat : null;
        $this->shape = $shape !== null ? (string) $shape : null;
    }
}

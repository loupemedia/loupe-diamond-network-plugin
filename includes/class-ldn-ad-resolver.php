<?php
/**
 * Resolves ads.json candidates for a page context and layout slot.
 *
 * Targeting specificity: country + site (no hardcoded retailers or sites).
 * Diamond type: natural / lab-grown page context maps to manifest natural / lab / both.
 *
 * @package LoupeDiamondNetwork
 * @since   0.20.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Ad_Resolver {

    /** @var LDN_Ad_Manifest */
    private $manifest;

    /** @var LDN_Config */
    private $config;

    public function __construct(LDN_Ad_Manifest $manifest, LDN_Config $config) {
        $this->manifest = $manifest;
        $this->config = $config;
    }

    /**
     * Resolve up to $limit ads for a layout slot on this page.
     *
     * @param LDN_Page_Context $ctx
     * @param string           $layout_slot_id
     * @param int              $limit
     * @return array<int, array<string, mixed>>
     */
    public function resolve_for_layout_slot(LDN_Page_Context $ctx, $layout_slot_id, $limit = 1) {
        $slot_def = $this->config->get_layout_slot_definition($layout_slot_id);
        if ($slot_def === null) {
            return array();
        }

        $commercial_type = isset($slot_def['commercial_slot_type'])
            ? (string) $slot_def['commercial_slot_type']
            : 'retailer';
        $image_size = isset($slot_def['image_size']) ? (string) $slot_def['image_size'] : 'horizontal';
        $max_ads = isset($slot_def['max_ads']) ? (int) $slot_def['max_ads'] : 1;
        $limit = min(max(1, $limit), max(1, $max_ads));

        $scored = array();
        foreach ($this->manifest->candidates() as $ad) {
            if ($ad['slot_type'] !== $commercial_type) {
                continue;
            }
            if (!$this->layout_slot_allows_ad($layout_slot_id, $ad)) {
                continue;
            }
            if (!$this->diamond_type_matches($ctx->diamond_type, $ad['diamond_type'])) {
                continue;
            }
            $specificity = $this->targeting_specificity(
                $ad['targeting'],
                $ctx->site_id,
                $ctx->country_code
            );
            if ($specificity < 0) {
                continue;
            }
            $scored[] = array(
                'specificity' => $specificity,
                'priority' => (int) $ad['priority'],
                'ad' => $ad,
                'image_size' => $image_size,
                'layout_slot_id' => $layout_slot_id,
            );
        }

        if (empty($scored)) {
            return array();
        }

        usort(
            $scored,
            function ($a, $b) {
                if ($a['specificity'] !== $b['specificity']) {
                    return $b['specificity'] - $a['specificity'];
                }
                return $b['priority'] - $a['priority'];
            }
        );

        $picked = array();
        $seen_advertisers = array();
        foreach ($scored as $row) {
            $slug = $row['ad']['advertiser_slug'];
            if (isset($seen_advertisers[$slug])) {
                continue;
            }
            $picked[] = $row;
            $seen_advertisers[$slug] = true;
            if (count($picked) >= $limit) {
                break;
            }
        }

        return $picked;
    }

    /**
     * @param string           $layout_slot_id
     * @param string           $page_level
     * @return array{mode: string, section_id?: string}|null
     */
    public function placement_for_layout_slot($layout_slot_id, $page_level) {
        return $this->config->get_layout_slot_placement($layout_slot_id, $page_level);
    }

    /**
     * @param array<string, mixed> $targeting
     * @param string               $site_id
     * @param string               $country_code
     * @return int Negative when excluded.
     */
    private function targeting_specificity($targeting, $site_id, $country_code) {
        $sites = isset($targeting['sites']) && is_array($targeting['sites']) ? $targeting['sites'] : array('all');
        $countries = isset($targeting['countries']) && is_array($targeting['countries'])
            ? $targeting['countries']
            : array('all');

        $score = 0;

        if (in_array('all', $sites, true)) {
            $score += 0;
        } elseif (in_array($site_id, $sites, true)) {
            $score += 8;
        } else {
            return -1;
        }

        $country = strtolower((string) $country_code);
        if (in_array('all', $countries, true)) {
            $score += 0;
        } elseif (in_array($country, $countries, true)) {
            $score += 4;
        } else {
            return -1;
        }

        return $score;
    }

    /**
     * @param string|null $page_diamond_type
     * @param string      $ad_diamond_type
     * @return bool
     */
    private function diamond_type_matches($page_diamond_type, $ad_diamond_type) {
        if ($page_diamond_type === null || $page_diamond_type === '') {
            return true;
        }
        if ($ad_diamond_type === 'both') {
            return true;
        }
        $canonical = $page_diamond_type === 'lab-grown' ? 'lab' : 'natural';
        if ($ad_diamond_type === 'lab' && $canonical === 'lab') {
            return true;
        }
        if ($ad_diamond_type === 'natural' && $canonical === 'natural') {
            return true;
        }
        return false;
    }

    /**
     * @param string               $layout_slot_id
     * @param array<string, mixed> $ad
     * @return bool
     */
    private function layout_slot_allows_ad($layout_slot_id, $ad) {
        $slots = isset($ad['layout_slots']) ? $ad['layout_slots'] : array();
        if (empty($slots)) {
            return true;
        }
        return in_array($layout_slot_id, $slots, true);
    }
}

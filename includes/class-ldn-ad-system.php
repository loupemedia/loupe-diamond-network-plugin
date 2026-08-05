<?php
/**
 * Ad system facade — resolve, render, track (CP 55).
 *
 * @package LoupeDiamondNetwork
 * @since   0.20.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Ad_System {

    /** @var LDN_Ad_Resolver */
    private $resolver;

    /** @var LDN_Ad_Tracker */
    private $tracker;

    /** @var LDN_Config */
    private $config;

    public function __construct(LDN_Config $config, LDN_Ad_Manifest $manifest) {
        $this->config = $config;
        $this->resolver = new LDN_Ad_Resolver($manifest, $config);
        $this->tracker = new LDN_Ad_Tracker($config);
    }

    /**
     * Whether ads should render for this request (filterable).
     *
     * @param LDN_Page_Context $ctx
     * @return bool
     */
    public function is_enabled_for_context(LDN_Page_Context $ctx) {
        $enabled = apply_filters('ldn_ads_enabled', true, $ctx);
        return (bool) $enabled;
    }

    /**
     * Build HTML for a layout slot id, or '' when no ad / disabled.
     *
     * @param LDN_Page_Context $ctx
     * @param string           $layout_slot_id
     * @return string
     */
    public function render_layout_slot(LDN_Page_Context $ctx, $layout_slot_id) {
        if (!$this->is_enabled_for_context($ctx)) {
            return '';
        }

        $slot_def = $this->config->get_layout_slot_definition($layout_slot_id);
        if ($slot_def === null) {
            return '';
        }

        $max_ads = isset($slot_def['max_ads']) ? (int) $slot_def['max_ads'] : 1;
        $resolved = $this->resolver->resolve_for_layout_slot($ctx, $layout_slot_id, $max_ads);
        if (empty($resolved)) {
            return '';
        }

        $parts = array();
        foreach ($resolved as $row) {
            $html = $this->render_resolved_ad($ctx, $row);
            if ($html !== '') {
                $parts[] = $html;
            }
        }

        if (empty($parts)) {
            return '';
        }

        $wrapper_class = 'ldn-ad-row';
        if (count($parts) > 1) {
            $wrapper_class .= ' ldn-ad-row--multi';
        }

        return '<div class="' . esc_attr($wrapper_class) . '" data-ldn-layout-slot="'
            . esc_attr($layout_slot_id) . '">' . implode('', $parts) . '</div>';
    }

    /**
     * Placement map for injecting ads into the section stream.
     *
     * @param LDN_Page_Context $ctx
     * @param array<string, mixed> $layout From get_page_layout()
     * @return array<string, array<int, string>> Keys: after_hero, after_section:{id}, after_last_section
     */
    public function build_injection_map(LDN_Page_Context $ctx, array $layout) {
        $map = array(
            'after_hero' => array(),
            'after_last_section' => array(),
        );

        $slot_ids = isset($layout['ad_slots']) && is_array($layout['ad_slots'])
            ? $layout['ad_slots']
            : array();

        foreach ($slot_ids as $layout_slot_id) {
            $placement = $this->resolver->placement_for_layout_slot(
                (string) $layout_slot_id,
                $ctx->page_level
            );
            if ($placement === null) {
                continue;
            }

            $html = $this->render_layout_slot($ctx, (string) $layout_slot_id);
            if ($html === '') {
                continue;
            }

            $mode = isset($placement['mode']) ? (string) $placement['mode'] : '';
            if ($mode === 'after_hero') {
                $map['after_hero'][] = $html;
                continue;
            }
            if ($mode === 'after_last_section') {
                $map['after_last_section'][] = $html;
                continue;
            }
            if ($mode === 'after_section' && isset($placement['section_id'])) {
                $key = 'after_section:' . (string) $placement['section_id'];
                if (!isset($map[$key])) {
                    $map[$key] = array();
                }
                $map[$key][] = $html;
            }
        }

        return $map;
    }

    /**
     * @return LDN_Ad_Tracker
     */
    public function tracker() {
        return $this->tracker;
    }

    /**
     * @param LDN_Page_Context     $ctx
     * @param array<string, mixed> $row
     * @return string
     */
    private function render_resolved_ad(LDN_Page_Context $ctx, array $row) {
        $ad = $row['ad'];
        $image_size = isset($row['image_size']) ? (string) $row['image_size'] : 'horizontal';
        $layout_slot_id = isset($row['layout_slot_id']) ? (string) $row['layout_slot_id'] : '';

        $images = isset($ad['images']) && is_array($ad['images']) ? $ad['images'] : array();
        $image_url = isset($images[$image_size]) ? (string) $images[$image_size] : '';
        if ($image_url === '' && $image_size === 'horizontal' && isset($images['leaderboard'])) {
            $image_url = (string) $images['leaderboard'];
        }
        if ($image_url === '') {
            return '';
        }

        $dims = $this->config->get_ad_image_dimensions($image_size);
        $click_url = isset($ad['click_url']) ? (string) $ad['click_url'] : '';
        if ($click_url === '') {
            return '';
        }

        $ad_id = isset($ad['ad_id']) ? (string) $ad['ad_id'] : '';
        $impression_id = uniqid('ldn_ad_', true);
        $page_url = $this->current_page_url();

        $this->tracker->track_impression(
            array(
                'event_type' => 'impression',
                'ad_slot_id' => $layout_slot_id,
                'ad_id' => $ad_id,
                'site_id' => $ctx->site_id,
                'country_code' => $ctx->country_code,
                'page_url' => $page_url,
                'diamond_type' => $this->normalize_diamond_type_for_track($ctx->diamond_type),
            )
        );

        $css_class = isset($dims['css_class']) ? (string) $dims['css_class'] : 'ldn-ad--horizontal';
        $width = isset($dims['width']) ? (int) $dims['width'] : 728;
        $height = isset($dims['height']) ? (int) $dims['height'] : 90;

        $data_attrs = sprintf(
            'data-ldn-ad-track="1" data-ldn-layout-slot="%s" data-ldn-ad-id="%s" data-ldn-site-id="%s" '
            . 'data-ldn-country="%s" data-ldn-impression-id="%s" data-ldn-click-url="%s"',
            esc_attr($layout_slot_id),
            esc_attr($ad_id),
            esc_attr($ctx->site_id),
            esc_attr($ctx->country_code),
            esc_attr($impression_id),
            esc_attr($click_url)
        );

        $alt = isset($ad['name']) ? (string) $ad['name'] : __('Advertisement', 'loupe-diamond-network');

        $html = '<aside class="ldn-ad-slot ' . esc_attr($css_class) . '" ' . $data_attrs . ' role="complementary">';
        $html .= '<a href="' . esc_url($click_url) . '" class="ldn-ad-link" target="_blank" '
            . 'rel="noopener sponsored">';
        $html .= '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($alt) . '" '
            . 'width="' . esc_attr((string) $width) . '" height="' . esc_attr((string) $height) . '" '
            . 'loading="lazy" class="ldn-ad-image" />';
        $html .= '</a>';
        $html .= '<span class="ldn-ad-label">' . esc_html__('Advertisement', 'loupe-diamond-network') . '</span>';
        $html .= '</aside>';

        return $html;
    }

    /**
     * @param string|null $diamond_type
     * @return string|null
     */
    private function normalize_diamond_type_for_track($diamond_type) {
        if ($diamond_type === null || $diamond_type === '') {
            return null;
        }
        if ($diamond_type === 'lab-grown') {
            return 'lab';
        }
        return $diamond_type;
    }

    /**
     * @return string
     */
    private function current_page_url() {
        if (function_exists('home_url') && isset($_SERVER['REQUEST_URI'])) {
            return home_url(wp_unslash((string) $_SERVER['REQUEST_URI']));
        }
        return '';
    }
}

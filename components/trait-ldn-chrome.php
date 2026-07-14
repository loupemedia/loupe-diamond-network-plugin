<?php
/**
 * Renderer component trait — split from class-ldn-renderer.php (CP53).
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

trait LDN_Trait_Chrome {
    /**
     * Network-wide page chrome defaults when a profile omits page_chrome keys.
     *
     * @var array<string, string>
     */
    private static $PAGE_CHROME_DEFAULTS = array(
        'max_width'         => '1000px',
        'content_padding'   => '1.25rem',
        'section_spacing'   => '2rem',
        'heading_style'     => 'minimal',
    );

    /**
     * Allowed heading_style values → BEM modifier on .ldn-price-page.
     *
     * @var array<string, bool>
     */
    private static $VALID_HEADING_STYLES = array(
        'minimal'         => true,
        'loupe_classic'   => true,
        'ringspo_classic' => true,
        'editorial'       => true,
    );

    /**
     * CP53: inject brand_tokens + page_chrome as scoped CSS custom properties.
     *
     * @param array $profile Resolved content profile.
     * @return string <style> block, or '' when nothing to emit.
     */
    public function theme_style_block(array $profile) {
        $decls = $this->brand_token_declarations($profile);
        $decls .= $this->page_chrome_declarations($profile);

        if ($decls === '') {
            return '';
        }

        return '<style>.ldn-page-shell,.ldn-price-page,.ldn-size-page{' . $decls . '}</style>';
    }

    /**
     * Back-compat alias for theme_style_block (brand-only callers).
     *
     * @param array $profile
     * @return string
     */
    public function brand_css_vars(array $profile) {
        return $this->theme_style_block($profile);
    }

    /**
     * BEM modifier class from page_chrome.heading_style (default minimal).
     *
     * @param array $profile
     * @return string e.g. ldn-chrome--loupe-classic
     */
    public function chrome_heading_class(array $profile) {
        $chrome = $this->resolved_page_chrome($profile);
        $style = isset($chrome['heading_style']) ? (string) $chrome['heading_style'] : 'minimal';
        $style = $this->sanitize_heading_style($style);
        // The validity key uses underscores (e.g. loupe_classic) but the BEM
        // modifier in the family stylesheets is hyphenated
        // (.ldn-chrome--loupe-classic), so normalise the separator here.
        return 'ldn-chrome--' . str_replace('_', '-', $style);
    }

    /**
     * Merge network defaults with profile page_chrome.
     *
     * @param array $profile
     * @return array<string, string>
     */
    private function resolved_page_chrome(array $profile) {
        $chrome = (isset($profile['page_chrome']) && is_array($profile['page_chrome']))
            ? $profile['page_chrome']
            : array();

        $merged = self::$PAGE_CHROME_DEFAULTS;
        foreach ($chrome as $key => $value) {
            if (is_string($value) && $value !== '') {
                $merged[$key] = $value;
            }
        }
        return $merged;
    }

    /**
     * @param array $profile
     * @return string CSS declarations (no wrapper).
     */
    private function brand_token_declarations(array $profile) {
        $map = array(
            'primary'        => '--ldn-primary',
            'secondary'      => '--ldn-secondary',
            'accent'         => '--ldn-accent',
            'background'     => '--ldn-background',
            'text'           => '--ldn-text',
            'secondary_text' => '--ldn-secondary-text',
        );

        $tokens = (isset($profile['brand_tokens']) && is_array($profile['brand_tokens']))
            ? $profile['brand_tokens']
            : array();

        if (empty($tokens['primary'])) {
            $fallback = $this->dig_first($profile, array(
                array('distribution_style', 'color_scheme', 'primary'),
                array('graph_style', 'color_scheme', 'primary'),
            ));
            if (is_string($fallback) && $fallback !== '') {
                $tokens['primary'] = $fallback;
            }
        }

        $decls = '';
        foreach ($map as $key => $var) {
            if (!empty($tokens[$key]) && is_string($tokens[$key])) {
                $value = $this->sanitize_css_color($tokens[$key]);
                if ($value !== '') {
                    $decls .= $var . ':' . $value . ';';
                }
            }
        }
        return $decls;
    }

    /**
     * @param array $profile
     * @return string CSS declarations (no wrapper).
     */
    private function page_chrome_declarations(array $profile) {
        $chrome = $this->resolved_page_chrome($profile);

        $length_map = array(
            'max_width'       => '--ldn-max-width',
            'content_padding' => '--ldn-padding',
            'section_spacing' => '--ldn-section-spacing',
            'title_size'      => '--ldn-title-size',
            'title_size_mobile' => '--ldn-title-size-mobile',
        );

        $font_map = array(
            'title_font' => '--ldn-font-title',
            'body_font'  => '--ldn-font-body',
        );

        $shadow_map = array(
            'h1_shadow'        => '--ldn-h1-shadow',
            'h1_shadow_mobile' => '--ldn-h1-shadow-mobile',
        );

        $decls = '';
        foreach ($length_map as $key => $var) {
            if (!empty($chrome[$key])) {
                $value = $this->sanitize_css_length($chrome[$key]);
                if ($value !== '') {
                    $decls .= $var . ':' . $value . ';';
                }
            }
        }
        foreach ($font_map as $key => $var) {
            if (!empty($chrome[$key])) {
                $value = $this->sanitize_font_stack($chrome[$key]);
                if ($value !== '') {
                    $decls .= $var . ':' . $value . ';';
                }
            }
        }
        foreach ($shadow_map as $key => $var) {
            if (!empty($chrome[$key])) {
                $value = $this->sanitize_box_shadow($chrome[$key]);
                if ($value !== '') {
                    $decls .= $var . ':' . $value . ';';
                }
            }
        }
        return $decls;
    }

    /**
     * @param string $style
     * @return string Sanitised style slug.
     */
    private function sanitize_heading_style($style) {
        $style = preg_replace('/[^a-z0-9_]/', '', strtolower($style));
        if ($style === '' || !isset(self::$VALID_HEADING_STYLES[$style])) {
            return 'minimal';
        }
        return $style;
    }

    /**
     * Allow only hex (#rgb..#rrggbbaa) and rgb()/rgba() colour values so config
     * data cannot inject arbitrary CSS into the page. Returns '' otherwise.
     *
     * @param string $value
     * @return string
     */
    private function sanitize_css_color($value) {
        $value = trim($value);
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $value)) {
            return $value;
        }
        if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d+)\s*)?\)$/', $value)) {
            return $value;
        }
        return '';
    }

    /**
     * Allow px/rem/em/%/vw/vh/ch lengths only.
     *
     * @param string $value
     * @return string
     */
    private function sanitize_css_length($value) {
        $value = trim($value);
        if (preg_match('/^-?\d+(\.\d+)?(px|rem|em|%|vw|vh|ch)$/', $value)) {
            return $value;
        }
        return '';
    }

    /**
     * Allow a conservative font-family stack from config.
     *
     * @param string $value
     * @return string
     */
    private function sanitize_font_stack($value) {
        $value = trim($value);
        if (strlen($value) > 200) {
            return '';
        }
        if (!preg_match('/^[\w\s,"\'\-]+$/', $value)) {
            return '';
        }
        return $value;
    }

    /**
     * Allow simple box-shadow values (offset blur spread colour).
     *
     * @param string $value
     * @return string
     */
    private function sanitize_box_shadow($value) {
        $value = trim($value);
        if (strlen($value) > 120) {
            return '';
        }
        if (!preg_match('/^[\dpx#\s,rgba().%-]+$/', $value)) {
            return '';
        }
        return $value;
    }
}

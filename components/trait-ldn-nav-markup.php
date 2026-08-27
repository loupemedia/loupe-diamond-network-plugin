<?php
/**
 * Site-shell markup — header, utility bar and footer (PRD-018 CP125).
 *
 * The item builder in trait-ldn-nav-items.php still emits no markup. This file
 * is the walker: it turns those items into `ldn-shell-*` / `ldn-nav-*` HTML.
 * Theme class names (`main-navigation`, `menu-item-has-children`, `gp-mega-menu`)
 * are stripped so GeneratePress cannot double-decorate or clip panels.
 *
 * @package LoupeDiamondNetwork
 * @since   0.40.0
 */

if (!defined('ABSPATH')) {
    exit;
}

trait LDN_Trait_Nav_Markup {

    /**
     * Theme class names the shell must never paint. Their only purpose was to
     * satisfy a walker we no longer use on `renderer: ldn` markets.
     *
     * @var string[]
     */
    private static $SHELL_DENIED_CLASSES = array(
        'main-navigation',
        'menu-item',
        'menu-item-has-children',
        'gp-mega-menu',
        'sub-menu',
    );

    /**
     * Does LDN emit header, secondary bar and footer for this request?
     *
     * @return bool
     */
    public function owns_shell() {
        $navigation = $this->config->get_navigation($this->site_id);
        if (!is_array($navigation)) {
            return false;
        }
        $scope = $this->resolve_request_scope();
        if ($scope === null) {
            return false;
        }
        if ($this->shell_renderer_for($navigation, $scope['country_code']) !== 'ldn') {
            return false;
        }
        if ($this->theme_shell_adapter() === null) {
            $this->log(
                'site_shell.renderer is ldn but this theme has no suppression adapter; '
                    . 'refusing the shell rather than drawing a second header'
            );
            return false;
        }
        return true;
    }

    /**
     * @param array  $navigation
     * @param string $country_code
     * @return string `ldn` | `theme`
     */
    public function shell_renderer_for(array $navigation, $country_code) {
        $declared = array();
        if (isset($navigation['site_shell']) && is_array($navigation['site_shell'])
            && isset($navigation['site_shell']['renderer'])
        ) {
            $declared = $navigation['site_shell']['renderer'];
        }
        if (!is_array($declared)) {
            return 'theme';
        }
        $code = strtolower((string) $country_code);
        if (!empty($declared[$code])) {
            return (string) $declared[$code];
        }
        return 'theme';
    }

    /**
     * Who knows how to hide this theme's own header.
     *
     * Preview has no theme: treat that as ready so the harness can render the
     * shell. GeneratePress is the only live adapter. Any other template refuses
     * rather than stacking two headers.
     *
     * @return string|null `generatepress` | `preview` | null
     */
    private function theme_shell_adapter() {
        if (!function_exists('get_template')) {
            return 'preview';
        }
        $template = (string) get_template();
        if ($template === '' || $template === 'generatepress') {
            return $template === '' ? 'preview' : 'generatepress';
        }
        return null;
    }

    /**
     * Full header + utility bar, or empty when this request is still on the theme.
     *
     * @return string
     */
    public function header_html() {
        if (!$this->owns_shell()) {
            return '';
        }

        $navigation = $this->config->get_navigation($this->site_id);
        $scope = $this->resolve_request_scope();
        if (!is_array($navigation) || $scope === null) {
            return '';
        }

        $this->nav_item_seq = 0;
        $primary = array();
        if (!empty($navigation['menus']['primary']) && is_array($navigation['menus']['primary'])) {
            $primary = $this->nav_items($scope, $navigation['menus']['primary']);
        }

        $utility = array();
        if (!empty($navigation['menus']['secondary']) && is_array($navigation['menus']['secondary'])) {
            $utility = $this->nav_items($scope, $navigation['menus']['secondary']);
        }

        $drawer = $primary;
        if ($utility !== array()) {
            $first = true;
            foreach ($utility as $item) {
                if ($first && is_object($item) && (string) $item->menu_item_parent === '0') {
                    $item->classes[] = 'ldn-nav-utility-start';
                    $first = false;
                }
            }
            $drawer = array_merge($drawer, $utility);
        }

        $html = $this->shell_token_block();
        $html .= '<div class="ldn-shell">';
        $html .= $this->shell_utility_html($utility);
        $html .= '<header class="ldn-shell-header">';
        $html .= '<div class="ldn-shell-header__inner">';
        $html .= $this->shell_brand_html('header');
        $html .= '<nav class="ldn-shell-primary" aria-label="'
            . esc_attr($this->shell_label('Primary')) . '">';
        $html .= '<button type="button" class="ldn-shell-drawer-toggle" aria-expanded="false" '
            . 'aria-controls="ldn-shell-drawer">'
            . esc_html($this->shell_label('Menu'))
            . '</button>';
        $html .= '<ul class="ldn-nav-list ldn-shell-primary__list">';
        $html .= $this->shell_walk($primary, 0, 'desktop');
        $html .= '</ul>';
        $html .= '<div class="ldn-shell-drawer" id="ldn-shell-drawer" hidden>';
        $html .= '<ul class="ldn-nav-list ldn-shell-drawer__list">';
        $html .= $this->shell_walk($drawer, 0, 'drawer');
        $html .= '</ul></div>';
        $html .= '</nav></div></header></div>';

        return $html;
    }

    /**
     * Purple footer band: logo left, columns of config items, white links.
     *
     * @return string
     */
    public function shell_footer_html() {
        $navigation = $this->config->get_navigation($this->site_id);
        $scope = $this->resolve_request_scope();
        if (!is_array($navigation) || $scope === null
            || empty($navigation['menus']['footer'])
            || !is_array($navigation['menus']['footer'])
        ) {
            return '';
        }

        $this->nav_item_seq = 0;
        $items = $this->nav_items($scope, $navigation['menus']['footer']);
        if ($items === array()) {
            return '';
        }

        $top = array();
        foreach ($items as $item) {
            if (is_object($item) && (string) $item->menu_item_parent === '0') {
                $top[] = $item;
            }
        }
        $columns = $this->shell_footer_columns($top);

        $html = '<footer class="ldn-shell-footer">';
        $html .= '<div class="ldn-shell-footer__inner">';
        $html .= '<div class="ldn-shell-footer__brand">' . $this->shell_brand_html('footer') . '</div>';
        $html .= '<nav class="ldn-shell-footer__nav" aria-label="'
            . esc_attr($this->shell_label('Footer')) . '">';
        $html .= '<div class="ldn-shell-footer__columns">';
        foreach ($columns as $column) {
            $html .= '<ul class="ldn-shell-footer__col">';
            foreach ($column as $item) {
                $html .= '<li class="' . esc_attr($this->shell_item_class_attr($item, false)) . '">';
                $html .= $this->shell_link($item);
                $html .= '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div></nav></div></footer>';

        return $html;
    }

    /**
     * @param array $top Top-level footer items
     * @return array<int, array>
     */
    private function shell_footer_columns(array $top) {
        $grouped = array();
        $has_column = false;
        foreach ($top as $item) {
            if (is_object($item) && isset($item->ldn_column) && (int) $item->ldn_column > 0) {
                $has_column = true;
                break;
            }
        }
        if (!$has_column) {
            $column_count = min(4, max(1, count($top)));
            $chunk = (int) ceil(count($top) / $column_count);
            return array_values(array_chunk($top, $chunk));
        }
        foreach ($top as $item) {
            $col = (is_object($item) && isset($item->ldn_column)) ? (int) $item->ldn_column : 1;
            if ($col < 1) {
                $col = 1;
            }
            if (!isset($grouped[$col])) {
                $grouped[$col] = array();
            }
            $grouped[$col][] = $item;
        }
        ksort($grouped, SORT_NUMERIC);
        return array_values($grouped);
    }

    /**
     * @param array  $items
     * @param int    $parent_id
     * @param string $surface `desktop` | `drawer`
     * @return string
     */
    private function shell_walk(array $items, $parent_id, $surface) {
        $html = '';
        foreach ($items as $item) {
            if (!is_object($item)) {
                continue;
            }
            $item_parent = isset($item->menu_item_parent) ? (string) $item->menu_item_parent : '0';
            if ($item_parent !== (string) $parent_id) {
                continue;
            }
            $children = $this->shell_walk($items, (int) $item->ID, $surface);
            $has_children = $children !== '';
            $html .= '<li class="' . esc_attr($this->shell_item_class_attr($item, $has_children)) . '">';
            $html .= $this->shell_link($item);
            if ($has_children) {
                $title = isset($item->title) ? (string) $item->title : '';
                $html .= '<button type="button" class="ldn-shell-disclose" aria-expanded="false">';
                $html .= '<span class="screen-reader-text">'
                    . esc_html(sprintf($this->shell_label('Show %s menu'), $title))
                    . '</span>';
                $html .= '</button>';
                $html .= '<ul class="ldn-nav-sub">' . $children . '</ul>';
            }
            $html .= '</li>';
        }
        return $html;
    }

    /**
     * @param object $item
     * @return string
     */
    private function shell_link($item) {
        $url = isset($item->url) ? (string) $item->url : '';
        $title = isset($item->title) ? (string) $item->title : '';
        return '<a href="' . esc_url($url) . '">' . esc_html($title) . '</a>';
    }

    /**
     * @param object $item
     * @param bool   $has_children
     * @return string
     */
    private function shell_item_class_attr($item, $has_children) {
        $classes = isset($item->classes) && is_array($item->classes) ? $item->classes : array();
        $kept = array();
        foreach ($classes as $class) {
            $class = (string) $class;
            if ($class === '') {
                continue;
            }
            if (in_array($class, self::$SHELL_DENIED_CLASSES, true)) {
                continue;
            }
            if (strpos($class, 'menu-item') === 0 || strpos($class, 'gp-mega-menu') === 0) {
                continue;
            }
            $kept[] = $class;
        }
        if ($has_children && !in_array('ldn-nav-has-children', $kept, true)) {
            $kept[] = 'ldn-nav-has-children';
        }
        return implode(' ', array_unique($kept));
    }

    /**
     * @param array $items
     * @return string
     */
    private function shell_utility_html(array $items) {
        if ($items === array()) {
            return '';
        }
        return '<div class="ldn-shell-utility">'
            . '<nav class="ldn-shell-utility__nav" aria-label="'
            . esc_attr($this->shell_label('Utility')) . '">'
            . '<ul class="ldn-nav-list ldn-shell-utility__list">'
            . $this->shell_walk($items, 0, 'desktop')
            . '</ul></nav></div>';
    }

    /**
     * @param string $surface `header` | `footer`
     * @return string
     */
    private function shell_brand_html($surface = 'header') {
        $home = function_exists('home_url') ? home_url('/') : '/';
        $name = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : $this->site_id;
        if ($name === '') {
            $name = $this->site_id;
        }

        if ($surface === 'header' && function_exists('has_custom_logo') && has_custom_logo()
            && function_exists('get_custom_logo')
        ) {
            $logo = get_custom_logo();
            if (is_string($logo) && $logo !== '') {
                return '<div class="ldn-shell-brand">' . $logo . '</div>';
            }
        }

        $src = $this->brand_logo_url($surface);
        if ($src !== '') {
            return '<a class="ldn-shell-brand" href="' . esc_url($home) . '">'
                . '<img src="' . esc_url($src) . '" alt="' . esc_attr($name) . '"'
                . ' width="150" height="51" decoding="async">'
                . '</a>';
        }

        return '<a class="ldn-shell-brand" href="' . esc_url($home) . '">'
            . esc_html($name) . '</a>';
    }

    /**
     * Plugin-bundled mark from page_chrome. Footer prefers the on-primary file.
     *
     * @param string $surface
     * @return string
     */
    private function brand_logo_url($surface) {
        $profile = $this->config->get_content_profile($this->site_id);
        $chrome = (is_array($profile) && isset($profile['page_chrome']) && is_array($profile['page_chrome']))
            ? $profile['page_chrome']
            : array();
        $key = ($surface === 'footer' && !empty($chrome['footer_logo'])) ? 'footer_logo' : 'header_logo';
        if (empty($chrome[$key]) || !is_string($chrome[$key])) {
            return '';
        }
        $file = basename($chrome[$key]);
        if ($file === '' || strpos($file, '..') !== false) {
            return '';
        }
        $path = LDN_PLUGIN_DIR . 'assets/img/' . $file;
        if (!is_file($path)) {
            return '';
        }
        $base = defined('LDN_PLUGIN_URL') ? LDN_PLUGIN_URL : '';
        return $base . 'assets/img/' . rawurlencode($file);
    }

    /**
     * Site-wide brand tokens so the shell stylesheet can use `--ldn-primary`
     * on editorial posts and 404s, not only on LDN route bodies.
     *
     * @return string
     */
    public function shell_token_block() {
        $profile = $this->config->get_content_profile($this->site_id);
        if (!is_array($profile)) {
            $profile = array();
        }
        $renderer = new LDN_Renderer($this->nav_fetcher(), $this->config);
        return $renderer->root_style_block($profile);
    }

    /**
     * English fallbacks for chrome chrome labels the i18n tree does not carry.
     *
     * @param string $text
     * @return string
     */
    private function shell_label($text) {
        if (function_exists('_x')) {
            return _x($text, 'navigation', 'loupe-diamond-network');
        }
        return $text;
    }
}

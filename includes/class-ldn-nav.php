<?php
/**
 * Navigation module — PRD-018 CP125, slice 1 (site, country and locale resolution).
 *
 * Every other LDN renderer starts from an LDN_Page_Context that the dispatcher
 * builds out of route query vars. Navigation is different: the header renders on
 * legacy editorial posts, on the cart and on 404s, where no route matched and no
 * context exists. So resolution here deliberately takes no context, and
 * LDN_Locale::switch_for_context() is unusable for the same reason.
 *
 * Country comes from the request path, never from the multisite blog. That is the
 * same source the router already uses for the `ldn_country` query var, so the menu
 * and the page beneath it cannot disagree about which market the visitor is in.
 * A subsite mounted at /ca/ also puts /ca/ in the request path for its own posts,
 * so path parsing stays correct on a subsite without this module needing to know
 * multisite exists.
 *
 * Resolution is a pure function of the request path and the config bundle. No
 * cookie, session, Accept-Language header or geo-IP: page caches key on URL, so
 * anything else here serves one country's header to another country.
 *
 * @package LoupeDiamondNetwork
 * @since   0.24.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once LDN_PLUGIN_DIR . 'components/trait-ldn-nav-items.php';
require_once LDN_PLUGIN_DIR . 'components/trait-ldn-nav-country-switcher.php';
require_once LDN_PLUGIN_DIR . 'components/trait-ldn-nav-markup.php';

final class LDN_Nav {

    use LDN_Trait_Nav_Items;
    use LDN_Trait_Nav_Country_Switcher;
    use LDN_Trait_Nav_Markup;

    /**
     * Theme location used when a menu block does not name one. GeneratePress
     * registers `primary` and `secondary`; both are matched by LOCATION, never by
     * menu slug, because Ringspo's subsites named their menus differently
     * (`menu-aus-menu`, `menu-ireland-menu`, `menu-hk-menu`) and a slug-keyed hook
     * would silently no-op on exactly those three.
     */
    const DEFAULT_LOCATIONS = array(
        'primary' => 'primary',
        'secondary' => 'secondary',
        'footer' => 'footer',
    );

    /**
     * @var string
     */
    private $site_id;

    /**
     * @var LDN_Config
     */
    private $config;

    /**
     * Module ids a `gate` may name. The ROLLOUT HUB's ids, mirroring
     * LDN_Rollout_Reader::MODULES and ROLLOUT_MODULES in shared/config/navigation.py.
     * A navigation-specific vocabulary would let the hub be off while the menu
     * believed it was on.
     */
    const GATE_MODULES = array('price', 'size', 'standard_pages');

    /**
     * Synthetic item IDs start high so they cannot collide with real post IDs in
     * a menu we are augmenting rather than replacing.
     */
    const NAV_ID_BASE = 900000;

    /**
     * Keys that make a generated entry a fan-out: one item per value.
     *
     * Mirrors FAN_OUT_KEYS in shared/config/navigation.py.
     */
    const FAN_OUT_DIMENSIONS = array('shapes', 'carats', 'diamond_types');

    /**
     * The hub that lists what a truncated column omits.
     *
     * Keyed by family AND by the dimension the column fans out over, because those
     * need different destinations: a column of nine carats should land on the page
     * that lists carats, and a column of ten shapes on the page that lists shapes.
     * Keying on family alone sent both columns of a menu to one URL, which put two
     * identically-linked items in the drawer.
     */
        const NAV_FAMILY_HUB = array(
        // family => array(fan-out dimension => hub family)
        'price_all_shapes' => array('carats' => 'price_diamond_type'),
        'price_diamond_type' => array('diamond_types' => 'price_top_level'),
        'price_individual_shape' => array('shapes' => 'price_all_shapes'),
        'size_shape_hub' => array('shapes' => 'size_mega_hub'),
        'size_individual' => array('carats' => 'size_shape_hub'),
    );

    /**
     * @var LDN_Data_Fetcher|null
     */
    private $fetcher;

    /**
     * @var LDN_Rollout_Reader|null
     */
    private $rollout;

    /**
     * Memoised per-request module state for the resolved country.
     *
     * Resolved ONCE, not per entry: ~200 entries rendered twice a page would
     * otherwise be ~400 rollout lookups.
     *
     * @var array<string, bool>|null
     */
    private $module_state;

    /**
     * @var LDN_Renderer|null
     */
    private $price_renderer;

    /**
     * @var LDN_Size_Renderer|null
     */
    private $size_renderer;

    /**
     * Memoised scope. GP Premium renders the menu twice per page (desktop and
     * slide-out), so unmemoised resolution would double the work every request.
     *
     * @var array<string, mixed>|null
     */
    private $scope;

    /**
     * Distinguishes "not resolved yet" from "resolved to null".
     *
     * @var bool
     */
    private $resolved = false;

    /**
     * The footer hook fires twice (GeneratePress widget slot, then wp_footer)
     * so the strip is printed once per request.
     *
     * @var bool
     */
    private $footer_rendered = false;

    /**
     * @param string                $site_id
     * @param LDN_Config            $config
     * @param LDN_Data_Fetcher|null $fetcher Only needed to construct the URL builders.
     */
    /**
     * @param string                 $site_id
     * @param LDN_Config             $config
     * @param LDN_Data_Fetcher|null  $fetcher Only needed to construct the URL builders.
     * @param LDN_Rollout_Reader|null $rollout Injectable so tests can drive gating.
     */
    public function __construct($site_id, LDN_Config $config, $fetcher = null, $rollout = null) {
        $this->site_id = (string) $site_id;
        $this->config = $config;
        $this->fetcher = $fetcher;
        $this->rollout = $rollout;
    }

    /**
     * Is this entry live for the resolved scope?
     *
     * An unknown module omits the entry and logs, rather than defaulting to visible.
     * Showing an item nobody declared live is the failure that hides itself: the
     * staged rollout leaks and the menu looks fine. The build-time validator in
     * shared/config/navigation.py refuses an unknown module outright, so this branch
     * is a backstop for a hand-edited bundle rather than the primary defence.
     *
     * @param array $entry
     * @param array $scope
     * @return bool
     */
    public function entry_is_live(array $entry, array $scope) {
        if (empty($entry['gate']) || !is_array($entry['gate'])) {
            return true;
        }

        $module = isset($entry['gate']['module']) ? (string) $entry['gate']['module'] : '';
        if ($module === '') {
            $this->log('gate with no module; omitting the entry rather than guessing');
            return false;
        }

        if (!in_array($module, self::GATE_MODULES, true)) {
            $this->log(sprintf(
                'unknown gate module "%s"; omitting. Must be one of: %s',
                $module,
                implode(', ', self::GATE_MODULES)
            ));
            return false;
        }

        $state = $this->module_state($scope);
        return isset($state[$module]) ? $state[$module] : false;
    }

    /**
     * Live modules for the resolved country, resolved once per request.
     *
     * A pure function of site, country and rollout state, so it stays cache-safe.
     *
     * @param array $scope
     * @return array<string, bool>
     */
    private function module_state(array $scope) {
        if ($this->module_state !== null) {
            return $this->module_state;
        }

        $country = $scope['country_code'];
        $reader = $this->rollout_reader();

        $state = array();
        foreach (self::GATE_MODULES as $module) {
            $live = $reader !== null ? (bool) $reader->is_enabled($country, $module) : false;

            /*
             * Size pages are published for one country only, and that is a separate
             * switch from the hub: the module can be live while this country is not
             * the one size rolled out to. Same gate trait-ldn-navigation.php uses for
             * its explore links, so the menu and the on-page links agree.
             */
            if ($module === 'size' && $live) {
                $rollout_country = $this->config->size_rollout_country($this->site_id);
                if ($rollout_country !== null && $rollout_country !== ''
                    && strtolower($country) !== strtolower((string) $rollout_country)
                ) {
                    $live = false;
                    $this->log(sprintf(
                        'size is live but "%s" is not its rollout country ("%s"); omitting size entries',
                        $country,
                        (string) $rollout_country
                    ));
                }
            }

            $state[$module] = $live;
        }

        $this->module_state = $state;
        return $state;
    }

    /**
     * @return LDN_Rollout_Reader|null
     */
    private function rollout_reader() {
        if ($this->rollout === null && class_exists('LDN_Plugin')) {
            $plugin = LDN_Plugin::instance();
            if (method_exists($plugin, 'rollout')) {
                $this->rollout = $plugin->rollout();
            }
        }
        if ($this->rollout === null) {
            // Never silently treat "no reader" as everything-on.
            $this->log('no rollout reader available; gated entries will be omitted');
        }
        return $this->rollout;
    }

    /**
     * Hook menu filtering.
     *
     * @return void
     */
    public function register() {
        if (!function_exists('add_filter')) {
            return;
        }
        add_filter('wp_get_nav_menu_items', array($this, 'filter_menu_items'), 20, 3);
        add_filter('wp_nav_menu_objects', array($this, 'filter_menu_objects'), 20, 2);
        add_filter('generate_navigation_location', array($this, 'suppress_generatepress_nav'));
        add_filter('body_class', array($this, 'filter_body_class'));

        if (function_exists('add_action')) {
            add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
            add_action('wp_head', array($this, 'print_shell_tokens'), 1);
            add_action('wp_body_open', array($this, 'render_header'), 1);
            add_action('init', array($this, 'register_footer_location'));
            add_action('generate_after_footer_widgets', array($this, 'render_footer_menu'), 10);
            add_action('wp_footer', array($this, 'render_footer_menu'), 8);
        }
    }

    /**
     * Register the footer location so an operator can assign a WP menu later.
     *
     * Auto-render still covers a site that never assigns one (Ringspo US).
     *
     * @return void
     */
    public function register_footer_location() {
        if (!function_exists('register_nav_menu')) {
            return;
        }
        register_nav_menu('footer', 'LDN Footer');
    }

    /**
     * Enqueue the navigation stylesheet.
     *
     * On EVERY page, unlike the other LDN stylesheets which are page-specific:
     * the header renders on legacy editorial posts and 404s too, and an unstyled
     * mega-menu panel there would overlay the content.
     *
     * @return void
     */
    public function enqueue_styles() {
        if (!function_exists('wp_enqueue_style') || !defined('LDN_PLUGIN_URL')) {
            return;
        }
        if ($this->config->get_navigation($this->site_id) === null) {
            // No declared menu means nothing of ours renders, so no stylesheet.
            return;
        }

        wp_enqueue_style(
            'ldn-nav-mega-menu',
            LDN_PLUGIN_URL . 'assets/css/nav-mega-menu.css',
            array(),
            defined('LDN_VERSION') ? LDN_VERSION : '0.1.0'
        );

        if (!$this->site_declares_ldn_shell()) {
            return;
        }

        wp_enqueue_style(
            'ldn-nav-chrome',
            LDN_PLUGIN_URL . 'assets/css/nav-chrome.css',
            array('ldn-nav-mega-menu'),
            defined('LDN_VERSION') ? LDN_VERSION : '0.1.0'
        );
        if (function_exists('wp_enqueue_script')) {
            wp_enqueue_script(
                'ldn-nav-chrome',
                LDN_PLUGIN_URL . 'assets/js/nav-chrome.js',
                array(),
                defined('LDN_VERSION') ? LDN_VERSION : '0.1.0',
                true
            );
        }
    }

    /**
     * @return bool
     */
    private function site_declares_ldn_shell() {
        $navigation = $this->config->get_navigation($this->site_id);
        if (!is_array($navigation) || empty($navigation['site_shell']['renderer'])
            || !is_array($navigation['site_shell']['renderer'])
        ) {
            return false;
        }
        return in_array('ldn', $navigation['site_shell']['renderer'], true);
    }

    /**
     * @return void
     */
    public function print_shell_tokens() {
        if (!$this->owns_shell()) {
            return;
        }
        echo $this->shell_token_block(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * @return void
     */
    public function render_header() {
        $html = $this->header_html();
        if ($html === '') {
            return;
        }
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * GeneratePress removes its primary navigation when this filter returns ''.
     *
     * @param mixed $location
     * @return mixed
     */
    public function suppress_generatepress_nav($location) {
        return $this->owns_shell() ? '' : $location;
    }

    /**
     * @param array $classes
     * @return array
     */
    public function filter_body_class($classes) {
        if (!is_array($classes)) {
            $classes = array();
        }
        if ($this->owns_shell()) {
            $classes[] = 'ldn-shell-owned';
        }
        return $classes;
    }

    /**
     * Paint the config footer when the theme has no menu on that location.
     *
     * Injection already covers an assigned footer menu. Ringspo's live footer
     * is widgets, so without this hook `menus.footer` would never appear.
     * Only `replace` markets get the strip: an `augment` market keeps the
     * theme footer, which is still that country's own links.
     *
     * @return void
     */
    public function render_footer_menu() {
        if ($this->footer_rendered) {
            return;
        }
        $html = $this->footer_menu_html();
        if ($html === '') {
            return;
        }
        $this->footer_rendered = true;
        echo $html;
    }

    /**
     * Footer markup for the current request, or empty when this request
     * should leave the theme footer alone.
     *
     * @return string
     */
    public function footer_menu_html() {
        if ($this->owns_shell()) {
            return $this->shell_footer_html();
        }

        if ($this->footer_location_has_assigned_menu()) {
            return '';
        }

        $navigation = $this->config->get_navigation($this->site_id);
        if (!is_array($navigation) || empty($navigation['menus']['footer'])
            || !is_array($navigation['menus']['footer'])
        ) {
            return '';
        }

        $scope = $this->resolve_request_scope();
        if ($scope === null) {
            return '';
        }
        if ($this->injection_mode_for($navigation, $scope['country_code']) !== 'replace') {
            return '';
        }

        $this->nav_item_seq = 0;
        $items = $this->nav_items($scope, $navigation['menus']['footer']);
        if ($items === array()) {
            return '';
        }

        return '<nav class="ldn-nav-footer-wrap" aria-label="Footer">'
            . '<ul class="ldn-nav-footer">'
            . $this->walk_nav_items_html($items, 0)
            . '</ul></nav>';
    }

    /**
     * @return bool
     */
    private function footer_location_has_assigned_menu() {
        if (!function_exists('get_nav_menu_locations')) {
            return false;
        }
        $locations = get_nav_menu_locations();
        return is_array($locations) && !empty($locations['footer']);
    }

    /**
     * Nested list markup from the same item objects the theme walker uses.
     *
     * Footer is the one place LDN emits HTML: the live footer is widgets, not
     * a `wp_nav_menu` location, so there is no theme walker to hand items to.
     *
     * @param array $items
     * @param int   $parent_id
     * @return string
     */
    private function walk_nav_items_html(array $items, $parent_id) {
        $html = '';
        foreach ($items as $item) {
            if (!is_object($item)) {
                continue;
            }
            $item_parent = isset($item->menu_item_parent) ? (string) $item->menu_item_parent : '0';
            if ($item_parent !== (string) $parent_id) {
                continue;
            }
            $classes = isset($item->classes) && is_array($item->classes)
                ? implode(' ', array_filter($item->classes))
                : '';
            $url = isset($item->url) ? (string) $item->url : '';
            $title = isset($item->title) ? (string) $item->title : '';
            $children = $this->walk_nav_items_html($items, (int) $item->ID);
            $html .= '<li class="' . esc_attr($classes) . '">';
            $html .= '<a href="' . esc_url($url) . '">' . esc_html($title) . '</a>';
            if ($children !== '') {
                $html .= '<ul class="sub-menu">' . $children . '</ul>';
            }
            $html .= '</li>';
        }
        return $html;
    }

    /**
     * Inject declared items into whichever menu is assigned to a location we own.
     *
     * @param array  $items
     * @param object $menu WP_Term for the menu being fetched.
     * @param array  $args
     * @return array
     */
    public function filter_menu_items($items, $menu, $args = array()) {
        if (!is_array($items) || !is_object($menu)) {
            return $items;
        }

        $navigation = $this->config->get_navigation($this->site_id);
        if (!is_array($navigation) || empty($navigation['menus']) || !is_array($navigation['menus'])) {
            return $items;
        }

        $menu_name = $this->menu_name_for_menu($menu, $navigation['menus']);
        if ($menu_name === null) {
            return $items;
        }

        $scope = $this->resolve_request_scope();
        if ($scope === null) {
            return $items;
        }

        $menu_config = $navigation['menus'][$menu_name];
        if (!is_array($menu_config)) {
            return $items;
        }

        $mode = $this->injection_mode_for($navigation, $scope['country_code']);

        $this->nav_item_seq = $this->highest_menu_order($items);
        $injected = $this->nav_items($scope, $menu_config);

        if ($injected === array()) {
            $this->log(sprintf('menu "%s" resolved no items; leaving it untouched', $menu_name));
            return $items;
        }

        if ($mode === 'replace') {
            return $injected;
        }

        return array_merge($items, $injected);
    }

    /**
     * How to treat the existing WordPress menu in one market.
     *
     * `replace` means "this file is the whole menu here", which is only true
     * where that market's own items have been extracted into config. Ringspo's
     * nine subsites have nine hand-built menus sharing almost nothing, so the
     * claim is per country and only the US makes it today.
     *
     * An undeclared market gets `augment`. Validation in
     * `shared/config/navigation.py` makes undeclared impossible to ship, so this
     * is the second line rather than a silent default - and it errs additively,
     * because a wrong `replace` deletes that market's entire header while a
     * wrong `augment` merely appends two items.
     *
     * @param array  $navigation
     * @param string $country_code
     * @return string
     */
    private function injection_mode_for(array $navigation, $country_code) {
        // renderer: ldn is the cutover. The theme header is suppressed, so this
        // market is replace even when YAML still lists it as augment.
        if ($this->shell_renderer_for($navigation, $country_code) === 'ldn') {
            return 'replace';
        }

        $declared = isset($navigation['injection_mode'])
            ? $navigation['injection_mode']
            : 'augment';

        if (!is_array($declared)) {
            return (string) $declared;
        }

        $code = strtolower((string) $country_code);
        if (!empty($declared[$code])) {
            return (string) $declared[$code];
        }

        $this->log(sprintf(
            'country "%s" has no injection_mode; augmenting rather than replacing '
                . 'a menu that was never extracted',
            $code
        ));
        return 'augment';
    }

    /**
     * Replace our injected items with the narrow set when this render is the
     * slide-out or the mobile header.
     *
     * Detection is by render args, never by user agent or viewport: there is no
     * viewport server-side, and any user-agent sniff would make output vary on a
     * non-URL input, which breaks the page cache.
     *
     * @param array  $items
     * @param object $args wp_nav_menu() args.
     * @return array
     */
    public function filter_menu_objects($items, $args = null) {
        if (!is_array($items) || $items === array()) {
            return $items;
        }

        $navigation = $this->config->get_navigation($this->site_id);
        if (!is_array($navigation) || empty($navigation['menus']) || !is_array($navigation['menus'])) {
            return $items;
        }

        $menu_name = $this->menu_name_for_args($args, $navigation['menus']);
        if ($menu_name === null || !$this->is_narrow_render($args, $navigation['menus'][$menu_name])) {
            return $items;
        }

        $scope = $this->resolve_request_scope();
        if ($scope === null) {
            return $items;
        }

        // Drop the items we injected, keep the theme's own, then re-add narrow.
        $kept = array();
        $ours = 0;
        foreach ($items as $item) {
            if (is_object($item) && isset($item->ID) && (int) $item->ID >= self::NAV_ID_BASE) {
                $ours++;
                continue;
            }
            $kept[] = $item;
        }

        if ($ours === 0) {
            return $items;
        }

        $this->nav_item_seq = $this->highest_menu_order($kept);
        $narrow = $this->nav_items($scope, $navigation['menus'][$menu_name], 'narrow');
        $merged = array_merge($kept, $narrow);

        // GP's secondary location is a desktop utility bar and is hidden in the
        // slide-out. On a replace market the config is the whole chrome, so
        // About / Contact / the switcher have to live in this drawer or they
        // vanish on a phone. Augment markets keep their own theme drawer.
        if ($menu_name === 'primary'
            && $this->injection_mode_for($navigation, $scope['country_code']) === 'replace'
            && !empty($navigation['menus']['secondary'])
            && is_array($navigation['menus']['secondary'])
        ) {
            $this->nav_item_seq = $this->highest_menu_order($merged);
            $utility = $this->nav_items($scope, $navigation['menus']['secondary']);
            $first = true;
            foreach ($utility as $item) {
                if ($first && is_object($item)
                    && (string) $item->menu_item_parent === '0'
                ) {
                    $item->classes[] = 'ldn-nav-utility-start';
                    $first = false;
                }
            }
            $merged = array_merge($merged, $utility);
        }

        return $merged;
    }

    /**
     * Whether this render is a narrow one (slide-out or mobile header).
     *
     * The discriminators are declarable per menu because they are theme-specific:
     * GP Premium's slide-out is `#generate-slideout-menu`, and a site that assigns
     * the slide-out its own theme location needs that named instead.
     *
     * @param object|null $args
     * @param mixed       $menu_config
     * @return bool
     */
    private function is_narrow_render($args, $menu_config) {
        $menu_ids = array('generate-slideout-menu', 'generate-mobile-menu');
        $locations = array('slideout', 'mobile');

        if (is_array($menu_config) && !empty($menu_config['narrow_render'])
            && is_array($menu_config['narrow_render'])
        ) {
            $declared = $menu_config['narrow_render'];
            if (!empty($declared['menu_ids']) && is_array($declared['menu_ids'])) {
                $menu_ids = $declared['menu_ids'];
            }
            if (!empty($declared['theme_locations']) && is_array($declared['theme_locations'])) {
                $locations = $declared['theme_locations'];
            }
        }

        $menu_id = $this->arg_value($args, 'menu_id');
        if ($menu_id !== '' && in_array($menu_id, $menu_ids, true)) {
            return true;
        }

        $location = $this->arg_value($args, 'theme_location');
        return $location !== '' && in_array($location, $locations, true);
    }

    /**
     * Declared menu block matching a render's theme location.
     *
     * A narrow render may sit on its own location (`slideout`), so that location
     * maps back to the block whose `narrow_render` claims it.
     *
     * @param object|null $args
     * @param array       $menus
     * @return string|null
     */
    private function menu_name_for_args($args, array $menus) {
        $location = $this->arg_value($args, 'theme_location');

        foreach ($menus as $menu_name => $menu_config) {
            if ($location !== '' && $this->location_for_menu_name($menu_name, $menu_config) === $location) {
                return (string) $menu_name;
            }
            if ($this->is_narrow_render($args, $menu_config)) {
                // The slide-out normally mirrors the primary menu.
                return isset($menus['primary']) ? 'primary' : (string) $menu_name;
            }
        }

        return null;
    }

    /**
     * @param object|null $args
     * @param string      $key
     * @return string
     */
    private function arg_value($args, $key) {
        if (is_object($args) && isset($args->$key) && is_string($args->$key)) {
            return $args->$key;
        }
        if (is_array($args) && isset($args[$key]) && is_string($args[$key])) {
            return $args[$key];
        }
        return '';
    }

    /**
     * Which declared menu block, if any, corresponds to this WP menu.
     *
     * Resolved through `get_nav_menu_locations()` so the menu's editorial NAME is
     * irrelevant. A location with no menu assigned warns rather than failing
     * silently, because a menu that quietly does not appear is the hardest kind of
     * bug to notice on a thin site.
     *
     * @param object $menu
     * @param array  $menus Declared menu blocks.
     * @return string|null
     */
    private function menu_name_for_menu($menu, array $menus) {
        if (!function_exists('get_nav_menu_locations')) {
            return null;
        }
        $locations = get_nav_menu_locations();
        if (!is_array($locations)) {
            return null;
        }

        $term_id = isset($menu->term_id) ? (int) $menu->term_id : 0;
        if ($term_id === 0) {
            return null;
        }

        foreach ($menus as $menu_name => $menu_config) {
            $location = $this->location_for_menu_name($menu_name, $menu_config);
            if ($location === '') {
                continue;
            }
            if (!isset($locations[$location]) || (int) $locations[$location] === 0) {
                $this->log(sprintf(
                    'theme location "%s" has no menu assigned; "%s" items not injected',
                    $location,
                    (string) $menu_name
                ));
                continue;
            }
            if ((int) $locations[$location] === $term_id) {
                return (string) $menu_name;
            }
        }

        return null;
    }

    /**
     * @param string $menu_name
     * @param mixed  $menu_config
     * @return string
     */
    private function location_for_menu_name($menu_name, $menu_config) {
        if (is_array($menu_config) && !empty($menu_config['theme_location'])) {
            return (string) $menu_config['theme_location'];
        }
        return isset(self::DEFAULT_LOCATIONS[$menu_name])
            ? self::DEFAULT_LOCATIONS[$menu_name]
            : '';
    }

    /**
     * Highest menu_order already present, so appended items sort after them.
     *
     * @param array $items
     * @return int
     */
    private function highest_menu_order(array $items) {
        $highest = 0;
        foreach ($items as $item) {
            if (is_object($item) && isset($item->menu_order) && (int) $item->menu_order > $highest) {
                $highest = (int) $item->menu_order;
            }
        }
        return $highest;
    }

    /**
     * Price URL builder. Constructed lazily; it only assigns two properties.
     *
     * @return LDN_Renderer
     */
    private function nav_price_renderer() {
        if ($this->price_renderer === null) {
            $this->price_renderer = new LDN_Renderer($this->nav_fetcher(), $this->config);
        }
        return $this->price_renderer;
    }

    /**
     * @return LDN_Size_Renderer
     */
    private function nav_size_renderer() {
        if ($this->size_renderer === null) {
            $this->size_renderer = new LDN_Size_Renderer($this->nav_fetcher(), $this->config);
        }
        return $this->size_renderer;
    }

    /**
     * @return LDN_Data_Fetcher
     */
    private function nav_fetcher() {
        if ($this->fetcher === null && class_exists('LDN_Plugin')) {
            $this->fetcher = LDN_Plugin::instance()->data_fetcher();
        }
        return $this->fetcher;
    }

    /**
     * Resolve site, country and locale for the current request.
     *
     * @return array{site_id:string, country_code:string, locale:string}|null
     */
    public function resolve_request_scope() {
        if ($this->resolved) {
            return $this->scope;
        }
        $this->resolved = true;

        $site = $this->config->get_site($this->site_id);
        if (!is_array($site) || $site === array()) {
            $this->log(sprintf('no site config for "%s"; navigation not resolved', $this->site_id));
            $this->scope = null;
            return null;
        }

        $country = $this->resolve_country();
        if ($country === null) {
            $this->scope = null;
            return null;
        }

        $locale = $this->config->get_locale($this->site_id, $country);
        if ($locale === null) {
            // A country in countries[] with no locale is a config error, but the
            // header must still render, so fall back rather than drop the menu.
            $locale = $this->site_default_locale();
            $this->log(sprintf('country "%s" has no locale; using site default "%s"', $country, $locale));
        }

        $this->scope = array(
            'site_id' => $this->site_id,
            'country_code' => $country,
            'locale' => (string) $locale,
        );
        return $this->scope;
    }

    /**
     * Country for this request: the router's query var if it set one, else the
     * path, else the site default.
     *
     * Preferring `ldn_country` is what makes disagreement with the page below
     * structurally impossible rather than merely unlikely - the router set it
     * from this same path.
     *
     * @return string|null
     */
    private function resolve_country() {
        $from_router = $this->router_country();
        if ($from_router !== null) {
            return $from_router;
        }

        $from_path = $this->country_from_path($this->request_path(), $this->site_id);
        if ($from_path !== null) {
            return $from_path;
        }

        return $this->default_country();
    }

    /**
     * The `ldn_country` query var, when a price or calculator route matched.
     *
     * @return string|null
     */
    private function router_country() {
        if (!function_exists('get_query_var')) {
            return null;
        }
        $country = get_query_var('ldn_country');
        if (!is_string($country) || $country === '') {
            return null;
        }
        $country = strtolower($country);
        return $this->is_declared_country($country) ? $country : null;
    }

    /**
     * Country from the request path, or null when the site has no {country}
     * segment or the segment is not a declared country.
     *
     * Do not read the multisite blog here: country is a property of the URL the
     * visitor asked for, and every other LDN module already treats it that way.
     *
     * @param string $path    Request path, leading slash, no query string.
     * @param string $site_id
     * @return string|null
     */
    private function country_from_path($path, $site_id) {
        $index = $this->country_segment_index($site_id);
        if ($index === null) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', (string) $path), 'strlen'));
        if (!isset($segments[$index])) {
            return null;
        }

        $candidate = strtolower($segments[$index]);
        if ($this->is_declared_country($candidate)) {
            return $candidate;
        }

        // A typo'd or foreign country segment must not cost the page its header,
        // so this warns and falls through to the site default.
        $this->log(sprintf('unrecognised country segment "%s" in path "%s"', $candidate, $path));
        return null;
    }

    /**
     * Which path segment holds the country, from the site's `path_structure`.
     *
     * Null when the site does not put a country in its paths at all (Loupe, DPE),
     * in which case its single country is the answer.
     *
     * @param string $site_id
     * @return int|null
     */
    private function country_segment_index($site_id) {
        $site = $this->config->get_site($site_id);
        $structure = is_array($site) && !empty($site['path_structure'])
            ? (string) $site['path_structure']
            : '';
        if ($structure === '' || strpos($structure, '{country}') === false) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $structure), 'strlen'));
        foreach ($segments as $index => $segment) {
            if ($segment === '{country}') {
                return $index;
            }
        }
        return null;
    }

    /**
     * The country whose locale matches the site's `default_locale`.
     *
     * @return string|null
     */
    private function default_country() {
        $site = $this->config->get_site($this->site_id);
        if (!is_array($site) || empty($site['countries']) || !is_array($site['countries'])) {
            $this->log('site declares no countries[]; navigation not resolved');
            return null;
        }

        $default_locale = $this->site_default_locale();
        if ($default_locale !== null) {
            foreach ($site['countries'] as $entry) {
                if (is_array($entry) && isset($entry['locale'])
                    && (string) $entry['locale'] === $default_locale
                    && !empty($entry['code'])
                ) {
                    return strtolower((string) $entry['code']);
                }
            }
        }

        // No country matches default_locale. Take the first declared country
        // rather than none, and say so: a header with the wrong country is
        // recoverable, a site with no header is not.
        foreach ($site['countries'] as $entry) {
            if (is_array($entry) && !empty($entry['code'])) {
                $code = strtolower((string) $entry['code']);
                $this->log(sprintf(
                    'no country matches default_locale "%s"; falling back to "%s"',
                    (string) $default_locale,
                    $code
                ));
                return $code;
            }
        }

        return null;
    }

    /**
     * @return string|null
     */
    private function site_default_locale() {
        $site = $this->config->get_site($this->site_id);
        if (is_array($site) && !empty($site['default_locale'])) {
            return (string) $site['default_locale'];
        }
        return null;
    }

    /**
     * @param string $country
     * @return bool
     */
    private function is_declared_country($country) {
        return $this->config->get_country_entry($this->site_id, $country) !== null;
    }

    /**
     * Request path with no query string, leading slash kept.
     *
     * Deliberately the PUBLIC path rather than the install-relative one that
     * rewrite rules match: on a subsite mounted at /ca/, the country segment is
     * part of the public path and is stripped from the matched path. Country
     * detection wants the former. See docs/architecture/wordpress-site-topology.md.
     *
     * @return string
     */
    public function request_path() {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        if (function_exists('wp_unslash')) {
            $uri = wp_unslash($uri);
        }

        $path = (string) parse_url($uri, PHP_URL_PATH);
        if ($path === '') {
            $path = '/';
        }

        /**
         * Filters the request path navigation resolves country from.
         *
         * Exists so tests can drive resolution without faking $_SERVER globally.
         *
         * @param string $path
         */
        if (function_exists('apply_filters')) {
            $path = (string) apply_filters('ldn_nav_request_path', $path);
        }

        return $path;
    }

    /**
     * Reset the memo. Tests only; a real request resolves once.
     *
     * @return void
     */
    public function reset_memo() {
        $this->scope = null;
        $this->resolved = false;
    }

    /**
     * @param string $message
     * @return void
     */
    private function log($message) {
        if (class_exists('LDN_Plugin')) {
            LDN_Plugin::debug_log('Nav', $message);
        }
    }
}

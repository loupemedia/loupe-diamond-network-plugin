<?php
/**
 * Navigation item construction — PRD-018 CP125_02.
 *
 * Expands a declared menu (from the config bundle) into WP_Post-like menu items
 * that the active theme's walker renders. Ringspo runs GeneratePress, whose mega
 * menus are produced by CSS classes on the menu item rather than by a plugin, so
 * emitting items and classes is enough and this must never emit markup.
 *
 * Every URL is delegated to the builder that already serves that page family:
 * `build_price_page_url()` for prices, `LDN_Size_Renderer`'s builders for sizes.
 * A second builder would drift, and the menu would start pointing at URLs the
 * router does not serve.
 *
 * `LDN_Page_Context` appears here purely as the parameter object those builders
 * take. That is different from the resolver in `class-ldn-nav.php`, which must not
 * DEPEND on a context because the header renders where no route matched. Country,
 * type, carat and shape all come from the resolved scope and the declaration.
 *
 * @package LoupeDiamondNetwork
 * @since   0.25.0
 */

if (!defined('ABSPATH')) {
    exit;
}

trait LDN_Trait_Nav_Items {

    /**
     * Synthetic item IDs start high so they cannot collide with real post IDs in
     * a menu we are augmenting rather than replacing.
     */
    const NAV_ID_BASE = 900000;

    /**
     * Pricing links are capped at level 3 unless the entry names an anchor carat.
     * A level-4 page exists only where that combo had data, so an unanchored
     * shape link is a 404 waiting for a thin day.
     */
    const PRICE_MAX_UNANCHORED_LEVEL = 3;

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
        'price_individual_shape' => array('shapes' => 'price_all_shapes'),
        'size_shape_hub' => array('shapes' => 'size_mega_hub'),
        'size_individual' => array('carats' => 'size_shape_hub'),
    );

    /**
     * @var int
     */
    private $nav_item_seq = 0;

    /**
     * Resolved wording for the locale being rendered, from the bundle's
     * `nav_terms`. Set in `nav_items()`, which is the only entry point, so every
     * label lookup below sees the request's locale without threading it through
     * six signatures.
     *
     * @var array<string, array<string, string>>
     */
    private $nav_terms = array();

    /**
     * Build the synthetic item list for a resolved scope and one menu's config.
     *
     * @param array  $scope       From LDN_Nav::resolve_request_scope().
     * @param array  $menu_config One entry of navigation.menus.
     * @param string $variant     'desktop' | 'narrow'.
     * @return array<int, object> WP_Post-like items, already ordered.
     */
    public function nav_items(array $scope, array $menu_config, $variant = 'desktop') {
        $items = array();
        $this->nav_terms = $this->nav_resolve_terms($scope);
        $declared = isset($menu_config['items']) && is_array($menu_config['items'])
            ? $menu_config['items']
            : array();

        foreach ($declared as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach ($this->expand_entry($entry, $scope, 0, $variant) as $item) {
                $items[] = $item;
            }
        }

        if ($variant === 'narrow') {
            $items = $this->enforce_mobile_budget($items, $menu_config);
        }

        return $items;
    }

    /**
     * Refuse to exceed the declared slide-out budget.
     *
     * Truncating is a poor outcome, so this logs loudly. The budget existing at all
     * is what stops the drawer silently regrowing into the 200-item scroll that
     * CP125_04 exists to prevent, as columns are added over time.
     *
     * @param array<int, object> $items
     * @param array              $menu_config
     * @return array<int, object>
     */
    private function enforce_mobile_budget(array $items, array $menu_config) {
        if (empty($menu_config['mobile_item_budget'])) {
            return $items;
        }
        $budget = (int) $menu_config['mobile_item_budget'];
        if ($budget <= 0 || count($items) <= $budget) {
            return $items;
        }

        $this->nav_log(sprintf(
            'slide-out set of %d exceeds mobile_item_budget %d; truncating. Declare '
                . 'fewer columns or raise the budget deliberately.',
            count($items),
            $budget
        ));

        // Keep whole top-level branches rather than cutting mid-branch, which would
        // leave a heading with no parent.
        return array_slice($items, 0, $budget);
    }

    /**
     * Expand one declared entry into zero or more items.
     *
     * Zero when the entry is gated out or its URL cannot resolve. One bad entry
     * must never cost the site its whole header, so this logs and drops.
     *
     * @param array $entry
     * @param array $scope
     * @param int   $parent_id
     * @return array<int, object>
     */
    private function expand_entry(array $entry, array $scope, $parent_id, $variant = 'desktop') {
        if (!$this->nav_entry_in_country($entry, $scope)) {
            return array();
        }

        if (!$this->nav_entry_passes_gate($entry, $scope)) {
            return array();
        }

        $target = isset($entry['target']) && is_array($entry['target']) ? $entry['target'] : $entry;
        $kind = isset($target['kind']) ? (string) $target['kind'] : '';

        // A fan-out becomes many sibling items, each labelled from terminology.
        if ($kind === 'generated' && $this->nav_is_fan_out($target)) {
            return $this->expand_fan_out($target, $scope, $parent_id);
        }

        if ($kind === 'country_switcher') {
            return $this->expand_country_switcher($scope, $entry, $parent_id);
        }

        $url = '';
        if ($kind === 'generated') {
            $url = $this->nav_generated_url($target, $scope);
        } elseif ($kind === 'editorial') {
            $url = $this->nav_editorial_url($target);
        }

        if ($url === '') {
            $this->nav_log(sprintf(
                'dropping entry "%s": no resolvable URL',
                isset($entry['id']) ? (string) $entry['id'] : $kind
            ));
            return array();
        }

        $item = $this->nav_item($this->nav_entry_label($entry), $url, $parent_id, $entry);
        $items = array($item);

        $children = $this->expand_columns($entry, $scope, $item->ID, $variant, $url);
        foreach ($children as $child) {
            $items[] = $child;
        }

        /*
         * An item that DECLARED columns but produced none has had everything beneath
         * it gated out. Keeping the parent would leave a mega-menu trigger opening an
         * empty panel. Items with no columns at all (a plain editorial link) are
         * untouched by this.
         */
        if ($children === array() && !empty($entry['columns']) && is_array($entry['columns'])) {
            $this->nav_log(sprintf(
                'dropping "%s": every column beneath it was gated out',
                isset($entry['id']) ? (string) $entry['id'] : $this->nav_entry_label($entry)
            ));
            return array();
        }

        return $items;
    }

    /**
     * Expand a top-level item's mega-menu columns into child items.
     *
     * @param array $entry
     * @param array $scope
     * @param int   $parent_id
     * @return array<int, object>
     */
    private function expand_columns(
        array $entry,
        array $scope,
        $parent_id,
        $variant = 'desktop',
        $parent_url = ''
    ) {
        $items = array();
        $columns = isset($entry['columns']) && is_array($entry['columns']) ? $entry['columns'] : array();

        // Narrow only: hrefs already used in this branch, so a collapsed heading
        // cannot duplicate its parent or a sibling. Two identically-linked rows in a
        // drawer cost a tap and teach the visitor nothing.
        $seen_urls = $parent_url !== '' ? array($parent_url => true) : array();

        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }

            // The column heading is itself a menu item, marked so CSS can style it
            // as a heading rather than a link. It points at nothing clickable of
            // its own, so it reuses the parent's URL rather than emitting '#'.
            $heading_label = $this->nav_heading_label($column);
            $entries = isset($column['entries']) && is_array($column['entries'])
                ? $column['entries']
                : array();

            $is_list = isset($entries['kind']) && $entries['kind'] === 'list';
            $list_items = ($is_list && isset($entries['items']) && is_array($entries['items']))
                ? $entries['items']
                : array();

            /*
             * In the slide-out, a fan-out column collapses to its heading, which
             * then links to the hub that still lists the ten shapes or nine carats.
             * Short `list` columns stay whole: they hold the calculator and the
             * comparison tool, which have to stay reachable in two taps.
             * A headed editorial list with more than three leaves (Learn here,
             * carat guides, retailer reviews) collapses the same way, or the
             * drawer inherits the 168-item desktop tree.
             */
            $collapse_long_list = $variant === 'narrow'
                && $is_list
                && $heading_label !== ''
                && count($list_items) > 3;
            if ($variant === 'narrow' && !$is_list && $this->nav_is_fan_out($entries)) {
                $hub = $this->nav_column_hub_url($entries, $scope);
                if ($hub === '' || $heading_label === '') {
                    continue;
                }
                if (isset($seen_urls[$hub])) {
                    $this->nav_log(sprintf(
                        'narrow: dropping "%s", its hub duplicates a link already in this branch',
                        $heading_label
                    ));
                    continue;
                }
                $seen_urls[$hub] = true;
                $heading = $this->nav_item($heading_label, $hub, $parent_id, array());
                $heading->classes[] = 'ldn-nav-column-heading';
                $heading->classes[] = 'ldn-nav-narrow-hub';
                $items[] = $heading;
                continue;
            }
            if ($collapse_long_list) {
                $hub = '';
                foreach ($list_items as $sub) {
                    if (!is_array($sub)) {
                        continue;
                    }
                    $expanded = $this->expand_entry($sub, $scope, $parent_id, $variant);
                    if ($expanded === array()) {
                        continue;
                    }
                    $hub = $expanded[0]->url;
                    break;
                }
                if ($hub === '' || $heading_label === '') {
                    continue;
                }
                if (isset($seen_urls[$hub])) {
                    $this->nav_log(sprintf(
                        'narrow: dropping "%s", its hub duplicates a link already in this branch',
                        $heading_label
                    ));
                    continue;
                }
                $seen_urls[$hub] = true;
                $heading = $this->nav_item($heading_label, $hub, $parent_id, array());
                $heading->classes[] = 'ldn-nav-column-heading';
                $heading->classes[] = 'ldn-nav-narrow-hub';
                $items[] = $heading;
                continue;
            }

            $children = array();
            if ($is_list) {
                foreach ((isset($entries['items']) && is_array($entries['items'])) ? $entries['items'] : array() as $sub) {
                    if (!is_array($sub)) {
                        continue;
                    }
                    foreach ($this->expand_entry($sub, $scope, $parent_id, $variant) as $child) {
                        $children[] = $child;
                    }
                }
            } else {
                foreach ($this->expand_entry($entries, $scope, $parent_id, $variant) as $child) {
                    $children[] = $child;
                }
            }

            if ($children === array()) {
                // An empty column would render as a stray heading.
                continue;
            }

            if ($heading_label !== '') {
                $first = $children[0];
                $heading = $this->nav_item($heading_label, $first->url, $parent_id, array());
                $heading->classes[] = 'ldn-nav-column-heading';
                $items[] = $heading;
                foreach ($children as $child) {
                    $child->menu_item_parent = (string) $heading->ID;
                }
            }

            foreach ($children as $child) {
                $items[] = $child;
            }
        }

        return $items;
    }

    /**
     * Expand a fan-out (shapes or carats) into one item per value.
     *
     * @param array $target
     * @param array $scope
     * @param int   $parent_id
     * @return array<int, object>
     */
    private function expand_fan_out(array $target, array $scope, $parent_id) {
        $items = array();
        $family = isset($target['page_family']) ? (string) $target['page_family'] : '';

        if (!empty($target['shapes']) && is_array($target['shapes'])) {
            foreach ($target['shapes'] as $shape) {
                $one = $target;
                unset($one['shapes']);
                $one['shape'] = $shape;
                $url = $this->nav_generated_url($one, $scope);
                if ($url === '') {
                    $this->nav_log(sprintf('dropping %s shape "%s": no URL', $family, (string) $shape));
                    continue;
                }
                $items[] = $this->nav_item($this->nav_shape_label($shape), $url, $parent_id, array());
            }
            return $items;
        }

        if (!empty($target['carats']) && is_array($target['carats'])) {
            foreach ($target['carats'] as $carat) {
                $one = $target;
                unset($one['carats']);
                $one['carat'] = $carat;
                $url = $this->nav_generated_url($one, $scope);
                if ($url === '') {
                    $this->nav_log(sprintf('dropping %s carat "%s": no URL', $family, (string) $carat));
                    continue;
                }
                $items[] = $this->nav_item($this->nav_carat_label($carat), $url, $parent_id, array());
            }
            return $items;
        }

        return $items;
    }

    /**
     * Hub URL for a column whose items the narrow variant drops.
     *
     * @param array $entries The column's declared entries block.
     * @param array $scope
     * @return string '' when no hub is declared for that family.
     */
    private function nav_column_hub_url(array $entries, array $scope) {
        $family = isset($entries['page_family']) ? (string) $entries['page_family'] : '';
        $dimension = $this->nav_fan_out_dimension($entries);

        if ($dimension === '' || !isset(self::NAV_FAMILY_HUB[$family][$dimension])) {
            $this->nav_log(sprintf(
                'no narrow hub declared for family "%s" fanning over "%s"',
                $family,
                $dimension
            ));
            return '';
        }

        $target = array('page_family' => self::NAV_FAMILY_HUB[$family][$dimension]);

        /*
         * The hub needs whatever the column held FIXED, which is the anchor. A column
         * of shapes at 1 carat has its carat fixed, so the shape-listing hub is the
         * one at 1 carat. Carry the diamond type for the same reason: a natural menu
         * must not land on the site's default type.
         */
        if (!empty($entries['diamond_type'])) {
            $target['diamond_type'] = $entries['diamond_type'];
        }
        if (!empty($entries['anchor_carat'])) {
            $target['carat'] = $entries['anchor_carat'];
        }
        if (!empty($entries['anchor_shape'])) {
            $target['shape'] = $entries['anchor_shape'];
        }

        return $this->nav_generated_url($target, $scope);
    }

    /**
     * Which dimension a fan-out column varies over.
     *
     * @param array $entries
     * @return string 'shapes' | 'carats' | 'diamond_types' | ''
     */
    private function nav_fan_out_dimension(array $entries) {
        foreach (self::FAN_OUT_DIMENSIONS as $dimension) {
            if (!empty($entries[$dimension]) && is_array($entries[$dimension])) {
                return $dimension;
            }
        }
        return '';
    }

    /**
     * URL for a generated entry, dispatched by page family.
     *
     * Unknown families are an explicit failure, never a fallback into another
     * family's builder: a silently mis-built URL is far harder to spot than a
     * missing item. See `.cursor/rules/retailer-extensibility.mdc`.
     *
     * @param array $target
     * @param array $scope
     * @return string '' when the URL cannot be built.
     */
    private function nav_generated_url(array $target, array $scope) {
        $family = isset($target['page_family']) ? (string) $target['page_family'] : '';
        $site_id = $scope['site_id'];
        $country = $scope['country_code'];

        switch ($family) {
            case 'price_top_level':
                return $this->nav_price_url($site_id, 'top-level', $country, $target);

            case 'price_diamond_type':
                return $this->nav_price_url($site_id, 'diamond-type', $country, $target);

            case 'price_all_shapes':
                return $this->nav_price_url($site_id, 'all-shapes', $country, $target);

            case 'price_individual_shape':
                // Level 4 only with an explicit anchor carat.
                if (empty($target['anchor_carat']) && empty($target['carat'])) {
                    $this->nav_log('refusing an unanchored level-4 price link');
                    return '';
                }
                return $this->nav_price_url($site_id, 'shape', $country, $target);

            case 'price_calculator':
                return $this->nav_pattern_url($site_id, 'calculator_level', array('{country}' => $country));

            case 'size_mega_hub':
                return $this->nav_size_renderer()->build_size_mega_hub_url($site_id);

            case 'size_shape_hub':
                $shape = $this->nav_target_shape($target);
                return $shape === ''
                    ? ''
                    : $this->nav_size_renderer()->build_size_shape_hub_url($site_id, $shape);

            case 'size_individual':
                $shape = $this->nav_target_shape($target);
                $carat = $this->nav_target_carat($target);
                return ($shape === '' || $carat === '')
                    ? ''
                    : $this->nav_size_renderer()->build_size_individual_url($site_id, $shape, $carat);

            case 'size_compare_tool':
                // The bare tool is the compare pattern without a target stone, and
                // has no builder of its own.
                return $this->nav_pattern_index_url($site_id, 'size_level_compare');

            case 'size_methodology':
                return $this->nav_pattern_url($site_id, 'size_level_methodology', array());
        }

        $this->nav_log(sprintf('unknown page_family "%s"', $family));
        return '';
    }

    /**
     * Price URL via the one existing builder.
     *
     * @param string $site_id
     * @param string $page_level
     * @param string $country
     * @param array  $target
     * @return string
     */
    private function nav_price_url($site_id, $page_level, $country, array $target) {
        $type = $this->nav_target_type($target);
        $carat = $this->nav_target_carat($target);
        $shape = $this->nav_target_shape($target);

        $ctx = new LDN_Page_Context(
            $site_id,
            $page_level,
            $country,
            $type !== '' ? $type : null,
            $carat !== '' ? $carat : null,
            $shape !== '' ? $shape : null,
            'price'
        );

        $url = $this->nav_price_renderer()->build_price_page_url($ctx, $page_level);
        return is_string($url) ? $url : '';
    }

    /**
     * Absolute URL from a url_structures pattern with no placeholders left.
     *
     * @param string               $site_id
     * @param string               $key
     * @param array<string,string> $replacements
     * @return string
     */
    private function nav_pattern_url($site_id, $key, array $replacements) {
        $country = isset($replacements['{country}']) ? $replacements['{country}'] : null;
        $structure = method_exists($this->config, 'url_structure_for')
            ? $this->config->url_structure_for($site_id, $country)
            : $this->config->get_url_structure($site_id);
        if (!is_array($structure) || empty($structure[$key])) {
            return '';
        }
        $path = (string) $structure[$key];
        foreach ($replacements as $placeholder => $value) {
            if ($value === '') {
                return '';
            }
            $path = str_replace($placeholder, $value, $path);
        }
        if (strpos($path, '{') !== false) {
            // An unsubstituted placeholder would ship a literal brace in the href.
            $this->nav_log(sprintf('pattern "%s" still has a placeholder: %s', $key, $path));
            return '';
        }
        return $this->nav_home_url($path);
    }

    /**
     * The index form of a pattern: everything before its trailing {placeholder}.
     *
     * @param string $site_id
     * @param string $key
     * @return string
     */
    private function nav_pattern_index_url($site_id, $key) {
        $structure = $this->config->get_url_structure($site_id);
        if (!is_array($structure) || empty($structure[$key])) {
            return '';
        }
        $segments = array_values(array_filter(explode('/', (string) $structure[$key]), 'strlen'));
        $kept = array();
        foreach ($segments as $segment) {
            if (strpos($segment, '{') !== false) {
                break;
            }
            $kept[] = $segment;
        }
        if ($kept === array()) {
            return '';
        }
        return $this->nav_home_url('/' . implode('/', $kept));
    }

    /**
     * @param array $target
     * @return string
     */
    private function nav_editorial_url(array $target) {
        $url = isset($target['url']) ? (string) $target['url'] : '';
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#', $url)) {
            return $url;
        }
        if ($url[0] !== '/') {
            return '';
        }
        $fragment = '';
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        return $this->nav_home_url($url) . $fragment;
    }

    /**
     * @param string $path Root-relative.
     * @return string
     */
    private function nav_home_url($path) {
        if (method_exists($this->config, 'path_relative_to_mount')) {
            $path = $this->config->path_relative_to_mount($path);
        }
        $path = ltrim($path, '/');
        if (!function_exists('home_url')) {
            return '/' . $path;
        }
        if (function_exists('user_trailingslashit')) {
            return home_url(user_trailingslashit($path));
        }
        return home_url($path);
    }

    // =========================================================================
    // Item construction
    // =========================================================================

    /**
     * One WP_Post-like menu item carrying the fields WordPress walkers require.
     *
     * @param string $title
     * @param string $url
     * @param int    $parent_id
     * @param array  $entry Declared entry, for mega-menu classes.
     * @return object
     */
    private function nav_item($title, $url, $parent_id, array $entry) {
        $this->nav_item_seq++;
        $id = self::NAV_ID_BASE + $this->nav_item_seq;

        $item = new stdClass();
        $item->ID = $id;
        $item->db_id = $id;
        $item->menu_item_parent = (string) $parent_id;
        $item->object_id = $id;
        $item->post_parent = 0;
        $item->type = 'custom';
        $item->object = 'custom';
        $item->type_label = 'Custom Link';
        $item->title = $title;
        $item->post_title = $title;
        $item->url = $url;
        $item->target = '';
        $item->attr_title = '';
        $item->description = '';
        $item->xfn = '';
        $item->current = false;
        $item->current_item_ancestor = false;
        $item->current_item_parent = false;
        $item->menu_order = $this->nav_item_seq;
        $item->post_type = 'nav_menu_item';
        $item->post_status = 'publish';
        $item->classes = $this->nav_item_classes($entry);

        return $item;
    }

    /**
     * CSS classes for an item, including the mega-menu hooks GeneratePress reads.
     *
     * @param array $entry
     * @return string[]
     */
    private function nav_item_classes(array $entry) {
        $classes = array('ldn-nav-item');

        if (!empty($entry['id'])) {
            $classes[] = 'ldn-nav-' . sanitize_html_class((string) $entry['id']);
        }

        if (!empty($entry['mega_menu_columns'])) {
            $columns = (int) $entry['mega_menu_columns'];
            $classes[] = 'menu-item-has-children';
            $classes[] = 'ldn-nav-mega';
            $classes[] = 'ldn-nav-mega-' . $columns . '-col';
            // GP Premium's own hook, so the theme's existing mega CSS applies.
            $classes[] = 'gp-mega-menu';
            $classes[] = 'gp-mega-menu-col-' . $columns;
        }

        return $classes;
    }

    // =========================================================================
    // Labels
    // =========================================================================

    /**
     * @param array $entry
     * @return string
     */
    private function nav_entry_label(array $entry) {
        if (isset($entry['label']) && $entry['label'] !== '') {
            // Editorial destinations carry a literal label by design.
            return (string) $entry['label'];
        }
        if (!empty($entry['label_key'])) {
            return $this->nav_structural_label((string) $entry['label_key']);
        }
        return '';
    }

    /**
     * @param array $column
     * @return string
     */
    private function nav_heading_label(array $column) {
        if (isset($column['heading']) && $column['heading'] !== '') {
            return (string) $column['heading'];
        }
        if (!empty($column['heading_key'])) {
            return $this->nav_structural_label((string) $column['heading_key']);
        }
        return '';
    }

    /**
     * Resolved wording for a scope's locale, or an empty set.
     *
     * @param array $scope
     * @return array<string, array<string, string>>
     */
    private function nav_resolve_terms(array $scope) {
        if (empty($scope['site_id']) || empty($scope['locale'])) {
            return array();
        }
        if (!method_exists($this->config, 'get_nav_terms')) {
            return array();
        }
        $terms = $this->config->get_nav_terms($scope['site_id'], $scope['locale']);
        return is_array($terms) ? $terms : array();
    }

    /**
     * Structural nav label for a `nav.*` key.
     *
     * The i18n tree wins, because it is the same source the destination page,
     * chart and breadcrumb resolve from, and it carries all 33 locales. The
     * gettext map below is the fallback for a site whose bundle ships no
     * `nav_terms` at all, and it is what keeps the msgids in the POT.
     *
     * Note that gettext only has an `en_GB` catalogue, so reaching the fallback
     * means English regardless of locale. That is the bug the i18n tree fixes,
     * not something to rely on.
     *
     * @param string $key
     * @return string
     */
    private function nav_structural_label($key) {
        if (!empty($this->nav_terms['label'][$key])) {
            return (string) $this->nav_terms['label'][$key];
        }

        $labels = array(
            'nav.diamond_prices' => _x('Diamond Prices', 'navigation', 'loupe-diamond-network'),
            'nav.diamond_sizes' => _x('Diamond Sizes', 'navigation', 'loupe-diamond-network'),
            'nav.prices_by_carat' => _x('Prices by Carat', 'navigation', 'loupe-diamond-network'),
            'nav.prices_by_shape' => _x('Prices by Shape', 'navigation', 'loupe-diamond-network'),
            'nav.price_tools' => _x('Tools', 'navigation', 'loupe-diamond-network'),
            'nav.price_calculator' => _x('Price Calculator', 'navigation', 'loupe-diamond-network'),
            'nav.prices_lab_grown' => _x('Lab-Grown Prices', 'navigation', 'loupe-diamond-network'),
            'nav.sizes_by_shape' => _x('Sizes by Shape', 'navigation', 'loupe-diamond-network'),
            'nav.sizes_by_carat' => _x('Sizes by Carat', 'navigation', 'loupe-diamond-network'),
            'nav.size_tools' => _x('Tools', 'navigation', 'loupe-diamond-network'),
            'nav.size_comparison' => _x('Size Comparison', 'navigation', 'loupe-diamond-network'),
            'nav.size_methodology' => _x('How We Measure', 'navigation', 'loupe-diamond-network'),
            'nav.select_country' => _x('Select Country', 'navigation', 'loupe-diamond-network'),
        );

        if (isset($labels[$key])) {
            return $labels[$key];
        }

        $this->nav_log(sprintf('no structural label for "%s"', $key));
        return '';
    }

    /**
     * Shape label from the shared shape vocabulary, never a literal in config.
     *
     * @param string $shape
     * @return string
     */
    private function nav_shape_label($shape) {
        // The size renderer owns shape wording (it derives the "cut" suffix from
        // the S3 slug map), so reuse it rather than title-casing a slug here.
        $renderer = $this->nav_size_renderer();
        if (is_object($renderer) && method_exists($renderer, 'size_shape_label')) {
            $label = $renderer->size_shape_label((string) $shape);
            if (is_string($label) && $label !== '') {
                return $label;
            }
        }
        return ucwords(str_replace('-', ' ', (string) $shape));
    }

    /**
     * @param string $carat
     * @return string
     */
    private function nav_carat_label($carat) {
        $value = (float) $carat;
        $number = ($value === (float) (int) $value)
            ? (string) (int) $value
            : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return sprintf(
            /* translators: %s is a carat weight, e.g. 1.5 */
            _x('%s Carat', 'navigation carat label', 'loupe-diamond-network'),
            $number
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param array $target
     * @return string
     */
    private function nav_target_shape(array $target) {
        if (!empty($target['shape'])) {
            return (string) $target['shape'];
        }
        if (!empty($target['anchor_shape'])) {
            return (string) $target['anchor_shape'];
        }
        return '';
    }

    /**
     * @param array $target
     * @return string
     */
    private function nav_target_carat(array $target) {
        if (!empty($target['carat'])) {
            return (string) $target['carat'];
        }
        if (!empty($target['anchor_carat'])) {
            return (string) $target['anchor_carat'];
        }
        return '';
    }

    /**
     * @param array $target
     * @return string Canonical natural|lab-grown.
     */
    private function nav_target_type(array $target) {
        if (!empty($target['diamond_type'])) {
            return (string) $target['diamond_type'];
        }
        return '';
    }

    /**
     * @param array $target
     * @return bool
     */
    private function nav_is_fan_out(array $target) {
        return $this->nav_fan_out_dimension($target) !== '';
    }

    /**
     * Whether an entry belongs in this market at all.
     *
     * Distinct from the rollout `gate`, which asks whether a module has launched
     * for a country. This asks whether the item is *that market's item*, and the
     * answer is a config fact with no rollout dimension.
     *
     * No `countries` means network-wide, which is right for the generated pricing
     * and size families: the same product in every market, differing only in
     * data. `countries` is for editorial, which genuinely differs - Ringspo's
     * nine subsites have nine hand-built menus, and the US items point at US
     * posts that 404 under the `/jp/` mount while Japan's twelve retailer
     * reviews and twelve birthstone pages have no US equivalent.
     *
     * Dropping an entry drops its whole subtree, because children are expanded
     * from this same call. That is the intent: a market that does not get "Learn"
     * must not get its columns either.
     *
     * @param array $entry
     * @param array $scope
     * @return bool
     */
    private function nav_entry_in_country(array $entry, array $scope) {
        if (empty($entry['countries']) || !is_array($entry['countries'])) {
            return true;
        }

        // A country_switcher's `countries` is the live/suppressed register, a
        // different thing that happens to share the key. It is never a
        // membership list, so it must not be read as one.
        if (isset($entry['countries']['live']) || isset($entry['countries']['suppressed'])) {
            return true;
        }

        $country = strtolower((string) $scope['country_code']);
        foreach ($entry['countries'] as $code) {
            if (is_scalar($code) && strtolower((string) $code) === $country) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an entry's `gate` allows it for this scope.
     *
     * Two conditions, both required. The site must publish that page family at all
     * (a config fact), AND the module must be live for this country in the rollout
     * hub (a rollout fact). Either alone is insufficient: a site can have the URL
     * structure long before the country launches.
     *
     * @param array $entry
     * @param array $scope
     * @return bool
     */
    private function nav_entry_passes_gate(array $entry, array $scope) {
        if (empty($entry['gate']) || !is_array($entry['gate'])) {
            return true;
        }

        $module = isset($entry['gate']['module']) ? (string) $entry['gate']['module'] : '';
        if ($module !== '' && !$this->nav_module_published($module, $scope)) {
            return false;
        }

        // entry_is_live() lives on LDN_Nav because it owns the per-request memo.
        if (method_exists($this, 'entry_is_live')) {
            return $this->entry_is_live($entry, $scope);
        }

        return false;
    }

    /**
     * Does the site publish this module's URL structure at all?
     *
     * @param string $module
     * @param array  $scope
     * @return bool
     */
    private function nav_module_published($module, array $scope) {
        $structure = $this->config->get_url_structure($scope['site_id']);
        if (!is_array($structure)) {
            return false;
        }
        if ($module === 'size') {
            return !empty($structure['size_level_1']);
        }
        if ($module === 'price') {
            return !empty($structure['level_1']);
        }
        if ($module === 'standard_pages') {
            // Legal chrome is not a URL-structure family. The hub ON/OFF is
            // the gate; this check only asks whether the site could publish it.
            return true;
        }
        // Unknown module: refuse rather than fall through to another module's check.
        $this->nav_log(sprintf('unknown gate module "%s" in publish check', $module));
        return false;
    }

    /**
     * @param string $message
     * @return void
     */
    private function nav_log($message) {
        if (class_exists('LDN_Plugin')) {
            LDN_Plugin::debug_log('Nav', $message);
        }
    }
}

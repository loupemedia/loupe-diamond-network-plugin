<?php
/**
 * Country switcher items — PRD-018 CP126_01.
 *
 * One item per market the site has launched, generated from the register on the
 * `country_switcher` entry and the site's own `countries[]`. There is no second
 * country list: which markets exist is site config, which of them are live
 * in the register is validated in `shared/config/navigation.py`, and which of
 * those the header actually shows is the rollout hub. A yaml-live market whose
 * `price` module is off is omitted, so the switcher cannot point at a 404.
 *
 * Ringspo's hand-built switcher is why this is generated. It was maintained
 * once per country subsite, and nine copies of a nine-item list had already
 * drifted in ordering, casing, placement and labelling before anyone noticed
 * that 21 of 30 configured markets were unreachable.
 *
 * URLs here are ROOT-RELATIVE, deliberately, and this is the one place in
 * navigation that does not go through `nav_home_url()`. Every other link points
 * inside the current subsite, so mount-relative is right for them. A switcher
 * link points at a DIFFERENT subsite, and `home_url()` on the `/us/` install
 * would turn `/jp/diamond-prices/` into `/us/jp/diamond-prices/`.
 *
 * @package LoupeDiamondNetwork
 * @since   0.34.0
 */

if (!defined('ABSPATH')) {
    exit;
}

trait LDN_Trait_Nav_Country_Switcher {

    /**
     * Desktop flyout becomes two columns once this many live markets paint.
     * The drawer at 768px stays one column regardless.
     */
    private const COUNTRY_SWITCHER_TWO_COL_MIN = 8;

    /**
     * Expand a country_switcher entry into a parent plus one item per live market.
     *
     * @param array $scope     From LDN_Nav::resolve_request_scope().
     * @param array $entry     The declared country_switcher entry.
     * @param int   $parent_id
     * @return array<int, object>
     */
    private function expand_country_switcher(array $scope, array $entry, $parent_id) {
        $site_id = $scope['site_id'];
        $target = isset($entry['target']) && is_array($entry['target'])
            ? $entry['target']
            : $entry;

        $live = $this->country_switcher_live_codes($target);
        if ($live === array()) {
            $this->nav_log('country switcher declares no live markets; dropping it');
            return array();
        }

        // The parent is a real destination, not "#": on a phone the parent is
        // what gets tapped, and an uncrawlable header link is a wasted one.
        $parent_url = $this->country_switcher_url($site_id, $scope['country_code']);
        if ($parent_url === '') {
            return array();
        }

        $parent = $this->nav_item(
            $this->nav_entry_label($entry),
            $parent_url,
            $parent_id,
            $entry
        );
        // No `menu-item-has-children` here either: the walker adds it from the
        // market items parented below, and a second copy doubles the arrow.
        $items = array($parent);
        $emitted = 0;

        foreach ($live as $code) {
            $url = $this->country_switcher_url($site_id, $code);
            if ($url === '') {
                // Refusing beats emitting a link into a market whose paths we
                // cannot build. A dead entry in the header looks deliberate.
                $this->nav_log(sprintf('country "%s": no resolvable path; omitted', $code));
                continue;
            }

            $item = $this->nav_item(
                $this->country_label($site_id, $code),
                $url,
                $parent->ID,
                array('id' => 'country-' . $code)
            );
            $item->classes[] = 'ldn-nav-country';

            if ($code === $scope['country_code']) {
                $item->current = true;
                $item->classes[] = 'current-menu-item';
                $item->classes[] = 'ldn-nav-country-current';
            }

            $items[] = $item;
            $emitted++;
        }

        if ($emitted >= self::COUNTRY_SWITCHER_TWO_COL_MIN) {
            $parent->classes[] = 'ldn-nav-countries-2-col';
        }

        return $items;
    }

    /**
     * Live market codes, in declared order.
     *
     * Order is declared rather than sorted because it is a product decision, and
     * because the drift this replaces included one subsite listing the UK before
     * Singapore while the other eight did the reverse.
     *
     * @param array $target
     * @return string[]
     */
    private function country_switcher_live_codes(array $target) {
        if (empty($target['countries']['live']) || !is_array($target['countries']['live'])) {
            return array();
        }

        $codes = array();
        foreach ($target['countries']['live'] as $code) {
            $code = strtolower(trim((string) $code));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        // A live register entry whose prices are not on is a 404 behind a
        // working menu. Hide it until the hub enables price for that market.
        $reader = $this->rollout_reader();
        $filtered = array();
        foreach ($codes as $code) {
            if ($reader === null || !$reader->is_enabled($code, 'price')) {
                $this->nav_log(sprintf(
                    'country "%s": omitted from switcher; price is not live',
                    $code
                ));
                continue;
            }
            $filtered[] = $code;
        }
        return $filtered;
    }

    /**
     * Root-relative path to a market's pricing hub.
     *
     * Reads the localised structure, so Japan gets its Japanese path rather than
     * the English one. Returns '' when the site does not put its markets on
     * paths of one domain, because a root-relative link would then point at the
     * wrong host and be worse than no link.
     *
     * @param string $site_id
     * @param string $country_code
     * @return string
     */
    private function country_switcher_url($site_id, $country_code) {
        $country_code = strtolower(trim((string) $country_code));
        if ($country_code === '' || !$this->site_has_path_countries($site_id)) {
            return '';
        }

        $structure = method_exists($this->config, 'url_structure_for')
            ? $this->config->url_structure_for($site_id, $country_code)
            : $this->config->get_url_structure($site_id);
        if (!is_array($structure) || empty($structure['level_1'])) {
            return '';
        }

        $path = str_replace('{country}', $country_code, (string) $structure['level_1']);
        if (strpos($path, '{') !== false) {
            $this->nav_log(sprintf('country "%s": unsubstituted placeholder in %s', $country_code, $path));
            return '';
        }

        return '/' . trim($path, '/') . '/';
    }

    /**
     * Does this site serve its markets as paths under one domain?
     *
     * Root-relative switcher links are only correct when it does. A site with a
     * domain per market needs absolute URLs, which nothing declares yet, so this
     * refuses rather than guessing.
     *
     * @param string $site_id
     * @return bool
     */
    private function site_has_path_countries($site_id) {
        $site = $this->config->get_site($site_id);
        if (!is_array($site) || empty($site['path_structure'])) {
            return false;
        }
        return strpos((string) $site['path_structure'], '{country}') !== false;
    }

    /**
     * Display name for a market, in the language of the page being rendered.
     *
     * One convention for every country, so the mix of eight English names and
     * one endonym cannot recur. `i18n/{locale}/common.json` owns the wording and
     * reaches the plugin through the bundle's `nav_terms`; `full_name` from
     * `countries[]` is the fallback for a locale the tree does not cover, which
     * means English.
     *
     * @param string $site_id
     * @param string $country_code
     * @return string
     */
    private function country_label($site_id, $country_code) {
        if (!empty($this->nav_terms['country'][$country_code])) {
            return (string) $this->nav_terms['country'][$country_code];
        }

        $site = $this->config->get_site($site_id);
        $countries = (is_array($site) && !empty($site['countries']) && is_array($site['countries']))
            ? $site['countries']
            : array();

        foreach ($countries as $entry) {
            if (!is_array($entry) || empty($entry['code'])) {
                continue;
            }
            if (strtolower((string) $entry['code']) !== $country_code) {
                continue;
            }
            if (!empty($entry['full_name'])) {
                return (string) $entry['full_name'];
            }
            break;
        }

        $this->nav_log(sprintf('country "%s": no full_name in countries[]', $country_code));
        return strtoupper($country_code);
    }
}

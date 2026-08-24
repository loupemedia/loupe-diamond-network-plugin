# Changelog

## [0.39.6] — 2026-08-24

- **Shape guides replace stale price and size sections like carat posts.** On `/cushion-cut-engagement-rings/` (and the other nine `/{shape}-engagement-rings/` posts), the well-priced / most-expensive / less-expensive H3 block and the buying-guide carat-weight H3 block are now removed in full and swapped for the live compare table plus price card, and the size card respectively. Stale retailer tables, dollar ranges, carat-scaling prose and affiliate tails in those sections no longer sit under the inject. Carat `/N-carat-…-diamond-ring/` posts are unchanged (Price/Size H2 replace). The pear/marquise "look large for their carat weight" pro section is not touched.

## [0.39.5] — 2026-08-23

- **Nested ring-guide colour bands span the viewport.** Best Place To Buy and the other mint/purple blocks sit inside a colourless GenerateBlocks wrapper whose inner container is still ~800px, so a top-level `50vw` breakout never reached them. Nested containers that paint white-on-colour copy now break out the same way; the white Alon review card (contrast colour, deeper nesting) stays inset.
- **Shape-guide injects land in the sections they belong to.** `/cushion-cut-engagement-rings/` (and the other nine `/{shape}-engagement-rings/` posts) do not have a "diamond prices" / "diamond size" H2, so the live cards were prepended into the intro. The price card now follows the replaced Gutenberg comparison (or the well-priced / most-expensive / less-expensive heading). The size card follows the buying-guide carat-weight heading. Named Price/Size H2s on `/N-carat-…-diamond-ring/` posts still replace in place.
- **Carat × shape ring guides get a readable price histogram.** `/4-carat-cushion-cut-diamond-ring/` (and the other shape guides) now plot `market_index_bins_json` inline with a dark title, IQR band and a larger median badge instead of scaling the 1200×630 OG social card. The note under the chart inherits body typography (`Live US asking prices as of …`) and no longer says "open the full chart".
- **"How big it looks" on carat × shape guides.** The size card on `/N-carat-…-diamond-ring/` posts now draws that carat at true scale next to a US quarter, using the same mega-hub matrix as the carat hub and shape engagement guides.

## [0.39.4] — 2026-08-23

- **Ring-guide carat hubs show a by-shape price chart.** `/4-carat-diamond-ring/` has no shape-level OG PNG, so Current US prices now plots live all-shapes medians. Shape guides still use `og-preview.png`. Heading-section splice now treats `$` in the insert as a literal so `$3,510` is not eaten as a PCRE backref.
- **Loupe diamond-type hub reading order.** The 1/2/3 ct price-change chart and its analysis now lead the page. The carat ladder table follows, then the intro and buyer-context copy that explain how to use the table.
- **Loupe diamond-type history chart plots % change.** C5.2 now rebases 1, 2 and 3 carat lines at the start of each selected period (1/3/12 months) so all three weights are readable on one axis. Period buttons swap both the traces and the y-axis range.

## [0.39.3] — 2026-08-23

- Shape price-over-time charts now title themselves with carat, shape, type and country (for example "1 carat Round Natural diamond prices in United States") instead of the generic "Price over time". After C5 republishes, a non-empty Plotly title still wins.
- Intro copy no longer puts a comma before "and has decreased" / "cut, color and clarity".
- **Hub carat table highlight is Ringspo-only.** Loupe top-level hubs no longer tint the 1 ct row. Removed the star glyph from anchor-row styling — Ringspo keeps a subtle background tint only.

## [0.39.2] — 2026-08-23

- **Loupe all-shapes reading order.** The median intro and stat cards lead. The ranked-by-shape heading now titles the bar chart, the shape-gap sentence sits under the chart, and the linked table follows. Trend copy moves below the table so it does not split that block.

## [0.39.1] — 2026-08-23

- Fix PHP 8.1 fatal on every request (front and wp-admin): move nav constants off `trait-ldn-nav-items.php` onto `LDN_Nav`. Trait constants are PHP 8.2+ only; Kinsta staging (Modern Jeweler) runs 8.1. Same pattern as the earlier content-trait fix.

## [0.39.0] — 2026-08-23

- **CP52_04 registry modules land in LDN.** Price sitemaps now read `module IN (pricing, calculator)` with `is_indexable = TRUE` from `ops.page_url_registry`. Size sitemaps read the same table (`module = size`) instead of the Z3 S3 artefact. Calculator rows are written by C4.5; size rows by Z3 after migration 018.
- **Calculator destination head is complete.** Canonical, Open Graph and `WebApplication` JSON-LD now ship on `/diamond-prices/calculator` routes.
- **Blog microdata removed on LDN routes.** `LDN_Body_Schema` suppresses GeneratePress `itemtype="Blog"` on routed pages and swaps the `blog` body class for `ldn-page` without clearing `is_home()`.

## [0.38.0] — 2026-08-22

- **The US footer is now in the same YAML as the header.** Diamond Prices and Diamond Sizes first (the hubs the old widget footer could not link to), then the seven live widget links (intro, sell, where to buy, about, disclosure, privacy, terms). The James Allen offer is dropped, not migrated. Items are `countries: [us]`, so Japan and the other seven blogs keep the theme footer. A `standard_pages` gate now passes the publish check (legal chrome is not a URL-structure family), so privacy, terms and disclosure can actually appear when the hub module is on.
- **LDN paints that footer when no WordPress menu is assigned to the location.** Ringspo's live footer is GeneratePress widgets, so the header-style `wp_nav_menu` filter would never fire. Auto-render runs only for a `replace` market. An assigned footer menu still uses the existing inject path.
- **Secondary items join the phone slide-out on a replace market.** GeneratePress hides the utility bar on a phone, which would have stranded About, Contact and the country switcher. Desktop secondary is unchanged. Augment markets keep their own theme drawer.

## [0.37.0] — 2026-08-22

- **`injection_mode` is per country, and a site-wide `replace` is now a validation error.** Ringspo is a multisite with one subsite per market, and the nine live subsites have nine separately hand-built menus that share almost nothing: 168 items on `us`, 40 Japanese items on `jp` (diamond info, brands, twelve retailer reviews, twelve birthstone pages), 34 on `uk`, 22 on `ca`, five to seven on `au` / `nz` / `sg` / `hk` / `ie`. Since 0.32.0 the one line `injection_mode: replace` applied to all nine, so a deploy would have deleted eight markets' headers and put English labels on US slugs that 404 under those mounts. Only `us` has been migrated, so only `us` claims `replace`.
- **An entry can declare which markets it belongs to.** `countries: [us]` on the eight US editorial items, because `augment` appends the *whole* declared menu, so per-country mode alone would still have appended Learn, Sell jewelry and Where to buy on top of Japan's own menu. Generated pricing and size families stay network-wide: the same product everywhere, gated by rollout rather than by membership. Dropping an entry drops its subtree, so a market that does not get Learn does not get its columns.
- **Two guards that make the next step safe.** A live market with no declared mode fails validation, and a market claiming `replace` with no editorial of its own fails too, because replace discards the WordPress menu and would leave that market with pricing links and nothing else. At runtime an undeclared market augments rather than replaces, erring additively.
- Editorial labels are asserted against a market-scoped entry now rather than against the US items on a Japanese path. The old assertion only held because of the leak this release removes.

## [0.36.0] — 2026-08-22

- **Navigation wording comes from the i18n tree (PRD-018 CP124_03, part).** Structural labels and market names resolve from `i18n/{locale}/common.json`, the same source the destination page, chart and breadcrumb use, and ship pre-resolved in the bundle's new `nav_terms` section. The plugin's `_x()` map survives as the fallback for a site with no `nav_terms`, which is English by definition: gettext has only an `en_GB` catalogue, so wrapping these in `_x()` had made them *translatable*, never translated. A reader on `/jp/` saw "Diamond Prices" above a page headed ダイヤモンド価格.
- **Editorial labels are still not translated, deliberately.** Learn, Ring guides, Sell jewelry and Where to buy point at one English post set, so a Japanese label there would promise an article that does not exist. Guarded by test, because it looks like an omission.
- **Eight of the nine live markets author nothing.** `en-AU`, `en-CA`, `en-HK`, `en-IE`, `en-NZ` and `en-SG` inherit through `_fallback` to `en-GB` then `en-US`, and Commonwealth spelling applies on the way through, so `jewelry` becomes `jewellery` without a second copy of the term. Only `en-US` and `ja-JP` carry the 43 keys.
- **A launch into an untranslated language now fails CI.** `nav_term_locale_gaps()` reports what a locale inherits rather than what it resolves, because the fallback chain means everything resolves. Moving `fr` into the switcher register's `live` list without French terms names `fr-FR` and the missing keys. Suppressed markets are exempt: nobody can see them.

## [0.35.0] — 2026-08-22

- **`standard_pages` is a third rollout module (PRD-021 CP 136).** The hub, the menu gate and the slave share one vocabulary (`price`, `size`, `standard_pages`). A country switcher entry is omitted until `price` is live for that market, so a yaml-live country cannot sit in the header above a 404. The config bundle now carries the standard-pages catalogue so the hub can refuse an unmapped country at publish.

## [0.34.0] — 2026-08-22

- **The country switcher is generated (PRD-018 CP126_01).** `injection_mode: replace` in 0.32.0 made LDN the owner of the secondary menu, and the switcher was still a parent with no children, so "Select Country" would have shipped as a single link to the US price hub. It now emits one item per live market, from a register on the `country_switcher` entry.
- **Markets are a three-state register, not a list.** Every code in `countries[]` must be `live` or `suppressed` with a reason; an undeclared one fails validation naming it. Ringspo declares 9 live and 21 suppressed. Launching a market is deleting a line, and adding a country to site config can no longer leave it configured but unreachable from the header — which is how the hand-built menu reached 9 of 30 without anyone noticing.
- **Switcher links are root-relative, and only there.** Every other nav link is mount-relative because it stays inside the current subsite. A switcher link crosses to another one, and `home_url()` on the `/jp/` install turns `/us/diamond-prices/` into `/jp/us/diamond-prices/`. Guarded by a test that runs against a mounted install; with the mount left at `/` the check passes either way.
- **One label convention and one ordering.** Names come from `countries[]` `full_name`, order is the declared register order. The nine hand-built subsite copies had drifted on both: Japan was `日本` while the rest were English, and `au` listed the UK before Singapore where the other eight reversed them. The US resolves to `/us/diamond-prices/` rather than the bare domain, and Japan to its localised `/jp/daiyamondo-kakaku/`. Labels are English pending the move to `i18n/{locale}/common.json`.

## [0.33.0] — 2026-08-22

- **Ringspo primary nav is six items.** Diamond Prices, Diamond Sizes, Learn, Ring guides, Sell jewelry, Where to buy. Learn merges the old Learn here / Guides / Jewelry columns (jewelry SKUs sit under Guides). Carat weight guides is labelled Ring guides so it does not read as a third price/size index. Secondary and the theme footer are unchanged.

## [0.32.0] — 2026-08-21

- **Ringspo navigation is `injection_mode: replace` (PRD-018 CP124_02).** The US WordPress menu (168 primary items, 14 secondary) is now declared in `config/navigation/ringspo.yaml`, with Diamond Prices and Diamond Sizes merged in after Jewelry. LDN owns membership; leftover WP items no longer share the bar, which is what wrapped the header onto two rows and duplicated Sell jewelry.
- **Mega-menu columns show their links.** Nested `.sub-menu` lists under column headings are `display: block; position: static`, matching the Customizer rule the LDN stylesheet replaced. Without this the Diamond Prices / Diamond Sizes panels only showed the three headings.
- **Editorial items may use an absolute http(s) URL.** Secondary "Reviews of Ringspo" points at TrustSpot. Hash parents (`Learn here`) resolve to the first real descendant rather than emitting `#`.
- **Long editorial columns collapse in the slide-out.** A headed list with more than three leaves becomes its heading, same as a generated fan-out, so the drawer does not inherit the 168-item desktop tree. Short tool lists stay whole.

## [0.31.0] — 2026-08-21

- **Competing-brand price URL trees.** Diamond Price Exact is carat-first at the domain root (`/1ct/round/natural/`). Diamond Price Guru is shape-first (`/us/round-brilliant/1-ct/mined/`). Diamond Advisors uses a flat leaf (`/us/1-carat-natural-round-cut-diamond-price/`) plus sibling shape and carat hubs so large terms still have a parent. `compile_pattern` now matches composite slug segments (`{carat}-{type}-{shape}-diamond-price`).

## [0.30.0] — 2026-08-19

- **Country-subsite price routes resolve (CP127_04 Part 1).** Rewrite rules and outbound URLs are now install-relative: a mount at `/nz/` registers `^diamond-prices/?$` and passes `/diamond-prices/` to `home_url()`, so the public URL stays `/nz/diamond-prices/` instead of 404ing or doubling to `/nz/nz/…`. Driven by mount path plus `countries[]`, never `is_multisite()`. The root install still registers every enabled country, so live `ringspo.com/uk/…` keeps working until those countries move off the root. Guru / carat bare `/{country}` front pages are skipped as rewrites (they are the subsite home) and still need the `is_home()` handler.

## [0.29.1] — 2026-08-19

- **Outbound price URLs use the localised scheme.** `build_price_page_url`, nav, the calculator rewrite and editorial injects all read `url_structure_for($site, $country)`, so a French menu now emits `/fr/prix-diamants/naturel/1-carat/` and Japan `/jp/daiyamondo-kakaku/tennen/1-karatto/`. The English rewrite variant for those countries is gone: one public URL per page, matching C4.5.

## [0.29.0] — 2026-08-19

- **Price rewrite rules honour the locale C4.5 already published.** Non-English countries register a second, localised rule set from the `url_locales` bundle section (`daiyamondo-kakaku` / `tennen` / `karatto` for Japan) so a sitemap URL is a URL the router can match. The English scheme stays registered so preview and nav paths that still emit it keep working. `LDN_Dispatcher` maps the captured slugs back to canonical type, carat and shape.

## [0.28.1] — 2026-08-18

- **All-shapes copy does not invent a shape comparison from one ranking row.** When the ranking has a single shape, `shape_analysis` is dropped (and C5.8 keeps only the first intro paragraph) so Diamond Price Guru no longer says Round is both cheapest and priciest and that switching saves $0.
- **Diamond Hunt UK market hub is `/prices/`.** `url_structures` level_1 is now `/prices`; the existing `/mined-diamonds/` and `/lab-diamonds/` tree shifts to level_2–4 so `build_price_page_url()` (top-level → level_1) and C4.5 both emit the public path. Editorial inject hub/shape keys move to level_3 / level_4 to keep the same hrefs. Cert listings stay on level_5.

## [0.28.0] — 2026-08-18

### Navigation rollout gating (PRD-018 CP125_05)

- **Navigation entries gated on a module are omitted for any country where that module is not live.** `LDN_Nav::entry_is_live()` reads the rollout hub through `LDN_Rollout_Reader::is_enabled()`, resolved ONCE per request next to the CP125_01 scope memo rather than per entry - roughly 200 entries rendered twice a page would otherwise be ~400 lookups. Gated entries are absent from the item list, never rendered and hidden with CSS, so a staged rollout cannot leak pre-launch links to crawlers.
- **Fixed: the gate had never fired, because the menu and the hub disagreed about the module's name.** The shipped Ringspo config gated on `pricing`; `LDN_Rollout_Reader::MODULES` only knows `price` and `size`. Nothing matched, so the item was ungated for every country while looking correctly configured. `gate.module` is now validated at build time against the hub's ids (`ROLLOUT_MODULES` in `shared/config/navigation.py`), refused at runtime if unknown, and the config says `price`.
- **Size entries additionally respect `size_module.rollout_country`.** The hub switch and the size rollout country are separate facts: size can be live while this country is not the one it rolled out to. Same gate `trait-ldn-navigation.php` uses for its explore links, so the menu and the on-page links agree. Ringspo UK and CA now render pricing with no size branch at all.
- **An item whose every column is gated out is dropped with them.** Otherwise a mega-menu trigger survives and opens an empty panel. Items that declare no columns are untouched.
- **An unknown module, an empty module, or no rollout reader all omit the entry.** Never a permissive default: an item that is present but points at an unlaunched page is far harder to notice than a missing one, which is the three-state failure `.cursor/rules/retailer-extensibility.mdc` describes.

## [0.27.1] — 2026-08-18

- **Suffixed price shape slugs resolve to the S3 shape.** Guru `round-brilliant` and Advisors `round-cut` now invert `shape_variations` (the same map `shape_to_url_slug()` already writes) so the page context and S3 prefix are canonical `round`. Outbound price links emit the public slug, so a DPG carat-ladder row no longer points at `/round/` and 404s on the live path.
- **Diamond Hunt UK pricing pages can render C-series artefacts.** The profile listed planning-era section ids the renderer skips (`uk_market_context`, `retailer_notes`, `natural_vs_lab_uk`, `partner_spotlight`) and asked for a time-series hero plus `price-data.json` / `buying-guide.json` while C5/C1 write `summary-data.json` / `static-content.json`. Layout now uses renderer-known ids (distribution chart, carat ladder, shapes-at-carat, market overview table, C5.8 dynamic copy) plus UK C1 prompts for FAQ, mined vs lab and shape preview. All-shapes / type / top-level now entitle `templated_copy_json` and `static_content_json` so those sections can receive files.

## [0.27.0] — 2026-08-18

- **Diamond Price Guru stats sit on the paper, not in a tinted box with a yellow left stripe.** That combination is the outlined-colour-box tell. Labels stack above values so "Typical price" and "$3,338" no longer run together.
- **Mega-menu layout ships with the plugin (PRD-018 CP125_03).** `assets/css/nav-mega-menu.css` replaces the per-site Customizer rule `.mega-menu > ul { width: 1140px; left: -34% !important; }`, whose offset was calibrated to where the old top-level items sat and so misaligns every panel the moment the item set changes - which is exactly what PRD-018 does. Panel width is `min(calc(100vw - 2rem), var(--ldn-nav-panel-max, 1140px))`, so it cannot overflow a 1024px laptop, and column count comes from the class the item already carries. 2 to 5 columns, matching the config vocabulary.
- **The menu inherits the theme's colours instead of imposing LDN's.** This stylesheet loads on every page, including legacy editorial posts, where LDN's `--ldn-*` custom properties are **not** emitted - only the page renderers emit those. So every colour resolves through `inherit`, `currentColor` or a system keyword, and the guard test asserts the file contains no hex at all. The panel is a solid surface with a shadow rather than a tinted box with a matching border.
- **The phone layout ships in the same change (CP125_04).** GP Premium's slide-out renders the same menu object as the desktop bar, and the desktop rule it inherited (`position: static; display: block` on submenus) is correct in a hover dropdown and is what dumps every child inline in a drawer. Submenus are now collapsed in the slide-out and the mobile header, tap targets are at least 44px, and the narrowest breakpoint reaches the 320-400px band.
- **The slide-out gets a declared shallower item set, not the desktop tree.** A fan-out column collapses to its heading, which links to the hub that still lists its contents; `list` columns stay whole because they hold the calculator and the comparison tool, which have to stay two taps away. The US primary menu goes from 51 desktop items to 12. `mobile_item_budget` is enforced, so the drawer cannot quietly regrow as columns are added.
- **Hub destinations depend on which dimension a column fans out over.** Keying on page family alone sent a menu's carat column and its shape column to the same URL, putting two identically-linked rows in the drawer. A carat column now lands on the page that lists carats and a shape column on the page that lists shapes, and a collapsed heading whose hub duplicates a link already in its branch is dropped.
- **A collapsed mega panel no longer holds ~500px of blank page open at phone width.** Found by looking at the rendered menu at 360px. Once the narrow media query makes the panel `position: static` it is back in normal flow, and the base rule only made it `visibility: hidden`, which still reserves its box - so the bar measured 700px+ with nothing in it. The narrow rule is now `display: none` with `.ldn-nav-open` / `[aria-expanded="true"]` revealing it, matching the slide-out. Hover cannot open a panel on a phone, so nothing would have revealed it either.
- **Narrow renders are detected from `wp_nav_menu()` args, never a user agent.** There is no viewport server-side, and a user-agent sniff would make output vary on a non-URL input while the page cache keys on URL. The discriminators (`generate-slideout-menu`, and the `slideout` theme location) are declarable per menu under `narrow_render`, because they are theme-specific. Asserted by test, along with the absence of `wp_is_mobile` and `HTTP_USER_AGENT` from the module.

## [0.26.0] — 2026-08-18

- **Editorial inject accepts declared permalinks, not only Ringspo URL shapes.** Sites whose education posts are `/diamond-shapes/` rather than `/4-carat-diamond-ring/` list them under `editorial_ring_guides.paths`. Kind, carat and shape are explicit; an unknown kind is refused. Price hrefs use configurable `price_hub_level` / `price_shape_level` so Diamond Hunt's four-level tree does not inherit Ringspo's level_3/level_4 meaning. Copy follows `default_country` (UK prices, Colour). `include_size: false` skips the US-quarter size card on sites with no size tree. Diamond Hunt's block is in the profile with `enabled: false` until UK artefacts exist.
- **Diamond Price Guru is a printed poster, not a dark luxury shell.** Profile tokens are now Guru Yellow (`#FFC52F`) on Warm White paper, with Diamond Blue and one Guru Red accent. Headlines load Fraunces (Windsor stand-in) and body stays Inter. The rubber-hose guru sits in a full-bleed yellow H1 masthead via `families/diamondpriceguru.css`.

## [0.25.1] — 2026-08-18

- **Diamond Advisors pages can render C-series artefacts.** The profile listed planning-era section ids the renderer skips (`range_chart`, `expert_assessment_dynamic`, `boxplot_chart`, `quality_comparison`) and asked for `market-data.json` / `recommendations.json` while C5/C1 write `summary-data.json` / `static-content.json`. Layout now uses renderer-known ids (distribution chart, colour/clarity grid, carat ladder, shapes-at-carat, market overview table, C5.8 dynamic copy) plus the advisor static sections C1 already prompts for.
- **Pricing prose cannot paint an em dash even when S3 still has one.** `format_prose_html` and `faq_html` replace U+2014/U+2013 with a spaced hyphen, matching the Python C5.8 backstop, so Diamond Price Guru (and every other family) stops showing the tell from stale `copy.json`. Chart title formats, schema suffixes and comparison labels in content profiles no longer use the character either. Table empty cells may still use `—`.

## [0.25.0] — 2026-08-18

- **Pricing and size pages now have a header entry point (PRD-018 CP125_02).** `LDN_Trait_Nav_Items` expands the declared menu from the config bundle into WP_Post-like items that the theme's own walker renders. Ringspo's GeneratePress mega menus come from CSS classes on the menu item rather than from a plugin, so emitting items and classes is sufficient; a guard test asserts the builder emits no `<ul>`, `<li>`, `<nav>` or `<a href` at all, because re-implementing GP Premium's slide-out would mean re-implementing its JavaScript and accessibility too.
- **Injection keys on the theme LOCATION, never the menu slug.** Six of Ringspo's nine subsites call their menu something that yields `menu-main-menu`; `au`, `ie` and `hk` do not, and they are also the three thinnest menus, so a slug-keyed hook would have silently done nothing on exactly the sites where an unchanged five-item header still looks plausible. The fixture in `tests/test-ldn-nav-items.php` is a menu named `Aus Menu`, so that regression fails loudly. A location with no menu assigned warns rather than passing quietly.
- **`injection_mode: augment` appends to the existing WordPress menu.** The alternative, LDN owning the whole menu, requires extracting ~168 existing US items into config first (CP124_02, which needs live database access). Augment lets the new page families reach the header now; `replace` becomes correct per site once that site's extraction lands. Appended items take `menu_order` above the highest already present, so they never sort above a hand-placed item.
- **Every URL comes from the builder that already serves that page family.** Prices go through `build_price_page_url()`, sizes through `LDN_Size_Renderer`'s builders. A second builder would drift and the menu would start pointing at URLs the router does not serve. An unknown `page_family` is an explicit failure rather than a fallback into another family's builder, and one unresolvable entry is dropped and logged rather than aborting the whole header.
- **Level-4 price links require an explicit anchor carat.** A per-shape page exists only where that combo had data, so an unanchored fan-out over shapes is a 404 waiting for a thin day. All ten shapes were verified to serve real content at 1 carat natural, and the anchor is enforced in both the Python validator and here. 44 of the 45 URLs the US menu emits were checked end to end against the render harness; the 45th is the `/sell-jewelry/` WordPress post, which the harness cannot see because it only renders LDN routes.
- **Navigation ships in the config bundle.** `shared/config/build_plugin_config_bundle.py` adds a `navigation` section, resolving `_extends` and validating each declaration at build time, so PHP needs no inheritance logic and an invalid menu fails the build instead of reaching a browser. Read through the new `LDN_Config::get_navigation()`.
- **Diamond Price Guru pages render the artefacts already on S3.** The profile listed planning-era section ids the renderer skips (`market_snapshot_dynamic`, `shape_value_ranking`, `price_alert_cta`) and asked for `price-data.json` / `buying-guide.json` while C-series writes `summary-data.json` / `static-content.json`. Layout now uses renderer-known ids (distribution chart, carat ladder, shapes-at-carat, market overview table, C5.8 dynamic copy). Percentile callout and price-alert CTA stay unbuilt.

## [0.24.0] — 2026-08-18

- **New navigation module, resolution half only (PRD-018 CP125_01).** `LDN_Nav` resolves site, country and locale on any request, including legacy editorial posts, the cart and 404s where no route matched. Every other LDN renderer starts from an `LDN_Page_Context` the dispatcher builds from route query vars, which does not exist on those pages, so the header could not have used the existing path. Nothing renders yet: the menu-object filters are CP125_02. Exposed as `LDN_Plugin::nav()` so that story has a seam to hook onto.
- **Country comes from the URL, and cannot disagree with the page below it.** Resolution prefers the `ldn_country` query var the router already sets from the path, falls back to parsing the path itself for unrouted requests, then to the country matching the site's `default_locale`. Because the preferred source is the router's own, a menu that contradicts the prices beneath it is structurally impossible rather than merely unlikely. An unrecognised or typo'd country segment warns and falls back, so a 404 keeps its header instead of losing it.
- **No multisite call, and no non-URL input, both enforced by test.** `tests/test-ldn-nav.php` strips comments before grepping the module, so the file can document why `switch_to_blog()`, `is_multisite()`, `LDN_Page_Context`, `$_COOKIE` and `Accept-Language` are absent without tripping its own guard. Page caches key on URL, so a geo or cookie input here would serve one country's header to another. Country detection reads the **public** request path rather than the install-relative one rewrite rules match, which is what keeps it correct on a country subsite - see `docs/architecture/wordpress-site-topology.md`.

## [0.23.5] — 2026-08-18

- **Rollout payload is cached once per WordPress network, not once per subsite.** `LDN_Rollout_Reader` cached the fetched `network-rollout.json` in `get_transient()` and its last-good copy in `get_option()`, both of which are per-blog. The payload describes the whole network (every site, every country), so on a multisite every country subsite fetched and stored its own identical copy: 30 fetches per 5-minute TTL on a 30-country network, 8,640 a day where 288 would do. Payload and last-good now use `*_site_transient` / `*_site_option`, so one network performs one fetch per TTL. These calls fall back to the options table on a single install, so there is no `is_multisite()` branch and single-site behaviour is unchanged. Prompted by the decision to run one country subsite per market across the ~17 country-path site families (PRD-018 CP127_04), which would otherwise have reached ~510 subsites and ~146,880 fetches a day.
- **The applied-version marker deliberately stays per-blog.** Rewrite rules are per-blog, so each subsite must flush its own and therefore needs its own record of what it last applied. Moving it network-wide would let the first subsite to run suppress the flush on all the others, leaving newly enabled countries 404ing on 29 of 30 subsites. Both halves of that split are asserted in `tests/test-ldn-rollout-cache-scope.php`, which fails in one direction if the payload goes per-blog and in the other if the marker goes network-wide.
- **Upgrade path preserved.** On multisite the pre-0.23.5 per-blog last-good row is invisible to `get_site_option()`, so the reader reads it once and promotes it to network scope rather than letting a subsite go dark while S3 is unreachable during the upgrade window.
- New guard test `tests/test-ldn-router.php` covers the price rewrite-rule builder, which had none despite its file header claiming it was unit-tested, and pins the known gap that a price route cannot resolve on a country subsite because `compile_pattern()` registers the site-absolute public path where WordPress wants an install-relative one. No behaviour change; the fix is PRD-018 CP127_04.

## [0.23.4] — 2026-08-18

- **Size mega hub matrix: sticky carat header while scrolling shapes.** The matrix scroll wrapper had no height cap, so vertical scroll happened on the page and the purple carat row scrolled away with the body rows. Added `max-height: min(70vh, 36rem)` (tighter on narrow screens) so scroll stays inside `.ldn-size-matrix-scroll`, where `position: sticky` on the header row already applies. Horizontal scroll and the sticky shape column are unchanged.

## [0.23.3] — 2026-08-18

- **Size scale explorer works on the cushion, asscher, emerald and princess hubs.** The size module publishes shape hubs at their S3 folder slug (`/diamond-size/cushion-cut/`), but the dispatcher resolved that segment with `slug_to_shape()`, whose per-site map holds Ringspo's price slug `cushion`. The slug missed the map and fell through the dash-to-space fallback to the non-shape string `cushion cut`. S3 fetches still succeeded, because that string maps back onto the same folder, so the page rendered and the fault stayed invisible on the server. The size-checker manifest is keyed on the canonical shape, so `bandsForShape()` came back empty and the explorer bailed before it filled the shape dropdown or bound the slider. `LDN_Config::size_slug_to_shape()` now tries the S3 map before the per-site one. No published URL changes.
- **Sparse carat bands draw from ideal proportions instead of nothing.** Cushion publishes `n: 0` with an empty dimension distribution at 0.3, 1 and 1.5 ct, so once the shape resolved correctly the slider reached bands `renderFigure()` had no median to scale from, leaving the previous stone on screen beneath the new carat label. Those bands do carry industry ideal proportions, and at 1 ct they are the 5.83 × 5.83 mm the hub table and the server-rendered figure already show, so the explorer now falls back to them. The caption says so rather than claiming a median. This is presentation only; the underlying gap is that the cushion per-cell summaries feeding the checker manifest report no measured stones at those weights.
- **A shape's page title no longer depends on that bug.** Those four hubs read "Cushion Cut Diamond Size Chart" only because the mis-parsed shape happened to contain the suffix. Headings, breadcrumbs and the spread section now take their label from `size_shape_label()`, which derives the suffix from the S3 map, so the wording survives the corrected shape. A shape that merely shares another's folder (`cushion elongated`) keeps its own name.
- **Unenhanced scale controls stay hidden.** The controls ship empty with `hidden` and are revealed by `size-checker.js` once it has a manifest, but the author `display: grid` on `.ldn-size-scale-controls` beat the browser's `[hidden]` rule, so a failed enhancement painted a dead dropdown and slider instead of nothing.
- Guard test `tests/test-ldn-size-shape-slug.php` asserts every shape in the config bundle round-trips from its published size URL back to its canonical name, so a new shape missing from the S3 map fails there rather than on a live page.

## [0.23.2] — 2026-08-18

- **Natural / lab-grown switch is now instant.** Natural and lab-grown are separate WordPress pages, so the switch used to cost a full navigation: PHP render, S3 artefact fetches, theme JS and a ~3 MB Plotly boot. Prefetching only helped when the browser had finished warming the sibling before the click, which it usually had not. `nat-lab-toggle.js` now fetches the sibling document once, replaces `<main>` in place, re-runs the chart bootstraps and pushes the URL. The server is still the only thing that renders a page, so the swapped copy, tables and charts cannot drift from what a crawler or a reader without JavaScript sees.
- **The carat weight follows the reader across the switch.** The sibling is fetched at its canonical (carat-free) URL because page caches and WP Rocket's link preloader skip query strings, so warming `?carat=2` would miss the cache on every switch. The weight is re-applied to the slider client-side instead, via `LDN.initTypeCaratLookup({ carat })`. The `?carat=` href stays on the pill so a reader without JavaScript, or a failed swap, still lands on the right weight.
- **Only chart scripts are re-run.** `chart_html` tags its Plotly bootstrap `data-ldn-chart`; the swap re-creates exactly those scripts and leaves everything else in the subtree inert, so ad and analytics scripts cannot double-fire and Plotly is not re-downloaded. Ad slots inside a swapped body will not re-request — a `ldn:page-swapped` window event is dispatched as the seam for that, unwired for now.
- Modified clicks (new tab, middle-click), cross-origin hrefs, a failed fetch and a document with no `main.ldn-price-page` all fall through to normal navigation. Back is a swap too: the page the reader came from is warmed on idle after the first switch, and the weight is stamped on both history entries so Back returns them to the stone they were looking at rather than that type's most-listed default.

## [0.23.1] — 2026-08-18

- **Top-level hub type-nav band:** natural/lab entry cards on `/diamond-prices/` render on a signature-green (`accent`) band between the purple carat table and the guidance block. Band coercion now allows purple/green alternation while still blocking back-to-back purple or back-to-back green.
- **Ring-guide bands edge-to-edge:** legacy GenerateBlocks colour sections on ring-buying guides (e.g. `/4-carat-diamond-ring/`) now use the same `50vw` full-bleed breakout as LDN pricing pages, so the mint Alon call-to-action and the purple blocks no longer stop short of the viewport. Which sections are coloured stays a WordPress block setting — the inject paints no band of its own.

## [0.23.0] — 2026-08-18

- **Ring-guide inject covers carat-free shape guides:** `/{shape}-engagement-rings/` now matches via `editorial_ring_guides.shape_pages`, so Ringspo's ten shape posts get a live price ladder (median per carat, each row linking to its own price page) and a true-scale size row for that shape. `prefixes` is the allowlist: an unlisted shape is left untouched. Test slice is cushion only.
- The ladder is built from per-carat `summary-data.json` because Ringspo is not entitled to `carat_ladder_json`. The size row reads the size mega-hub matrix, not the per-carat `size-summary.json`, which still publishes `n: 0` for cushion and the other elongated shapes.
- **Hand-built price tables are replaced with live data** (`editorial_ring_guides.replace_price_tables`). These posts embedded Gutenberg `wp-block-table` price tables, so no shortcode strip reached them: the cushion guide still quoted one retailer at F/VS1 and claimed a 38% saving against round, where the live all-shapes ranking at 1 carat puts cushion at $2,450 against round at $3,510, a 30% saving. Only tables whose header row names a price are touched, so the depth and table percentage tables in the same posts survive. The reference shape is config (`comparison_reference`), not a literal.
- **The replaced table's closing claim goes with it.** The sentence after each price table quoted that table's percentage ("save yourself a huge 38%"), which would have contradicted the live figure directly above it. Removal is sentence level, not paragraph level, because several guides follow the stale number with a caveat worth keeping. A closing paragraph with no percentage is untouched, so the radiant guide's affiliate call to action survives.
- **Ring-guide copy polish:** US source strings now use American spelling (`Color`, `color/clarity`). Size-chart caption rewritten in plain language (no "every outline shares one millimeter scale"). The shape-vs-round comparison table no longer tints a row; the carat ladder still highlights the anchor weight.
- **Fix: pricing dates never rendered.** `price_caption` read `metadata.analysis_date` and `_meta.analysis_date`, but C5 writes the date to `time_series.analysis_date` (and a top-level `analysis_date` on the all-shapes aggregate), so every ring-guide chart caption silently fell back to undated copy. Dates now render as `<time>` elements in reader format. The carat ladder states a span ("updated between 17 and 18 August 2026") because the freshness cadence rebuilds popular weights daily and the long tail less often.

## [0.22.12] — 2026-08-15

- **All-shapes FAQ ranking:** prepend C5.8 `copy.json` FAQ (live most/least expensive shapes) ahead of C1 educational FAQs so the FAQ cannot contradict the ranking chart.

## [0.22.11] — 2026-08-15

- **Shape hub cards:** stacked lines (`#1: Marquise`, sample, typical range, median, change). Kill leftover underlines on card-as-link blocks. Typical range is p10-p90 once C5.1 republishes (raw min/max hid a $117k 1ct marquise tail).

## [0.22.10] — 2026-08-15

- **Shape hub cards:** 5×2 grid on desktop (2-col tablet, 1-col phone). Card-as-link blocks (shape, explore, type-nav, size/price) no longer inherit `.ldn-section a` underlines on every line; Size chart still underlines on hover.

## [0.22.9] — 2026-08-15

- **Hreflang follows hub-ON:** Loupe (and DPE) alternate tags only list siblings whose price module is live in the published rollout. A Modern Jeweler-only launch no longer advertises Jewellery Monthly (and the rest) as language alternates while those URLs 404.

## [0.22.8] — 2026-08-15

- **Price sitemap includes shape pages:** C4.5 writes those rows at registry `hierarchy_level` 5; the plugin had been asking for `<= 4`, so the sitemap was empty and 404'd. Cap is now 5.
- **Plugin serves `/robots.txt`:** three-tier bot policy plus `Sitemap: {host}/sitemaps/network.xml`. Plugin deploy updates sitemap lines; do not copy a template onto each WP root. If a physical `robots.txt` is already in the web root, delete it once so nginx does not hide the plugin.

## [0.22.7] — 2026-08-15

- **Size-page key dimensions:** at ≤768px the millimetre table stacks under the coin/diamond figure. The stacking media query was invalid (`{ {`) so two columns stayed at every width.

## [0.22.6] — 2026-08-15

- **Ring-guide size chart layout:** the quarter no longer takes 100% width (that crushed the shapes into a one-character column). Two-column grid: intrinsic quarter + fluid 5×2 shapes, stacking under 640px.

## [0.22.5] — 2026-08-15

- **Ring-guide size chart:** even stroke-only facet lines, and the comparison reflows (2-column shapes under the quarter) when the article is narrow.

## [0.22.4] — 2026-08-15

- **Ring-guide size chart strokes:** lighter, still even facet lines (0.9px non-scaling stroke in brand purple).

## [0.22.3] — 2026-08-15

- **Ring-guide size chart alignment:** stones in each row share a baseline, name and millimetre lines align across the row, and the shelf rule under each outline is gone.

## [0.22.2] — 2026-08-15

- **Ring-guide size chart layout:** quarter sits to the left of a 5×2 shape grid (Heart no longer orphans on a third row). Drop the green disc behind the coin.

## [0.22.1] — 2026-08-15

- **Ring-guide size chart polish:** consistent screen-pixel facet strokes (the source SVGs were stretching line weight with each stone's millimetres). Ringspo purple gems, 5 mm grid and a green quarter token.

## [0.22.0] — 2026-08-15

- **Ring-guide size chart:** `/4-carat-diamond-ring/` now shows a true-scale comparison (US quarter + every shape) from the live size mega-hub medians and outline SVGs, replacing the old static millimetre infographic.

## [0.21.9] — 2026-08-15

- **Size-page band prose:** "Chart numbers vs real stones" (ideal-vs-real callout, depth note, L/W lead) inherits the purple/green band instead of sitting in a white card with grey ink. Ringspo `brand_tokens.text` now sets `--ldn-text` to ink (`#1a1a2e`) so body copy is not the `#333` fallback.

## [0.21.8] — 2026-08-15

- **About this data on coloured bands:** the size-page methodology strip inherits the band colour (white on Ringspo purple) instead of muted grey inside a dark-on-purple block. Same rule for pricing `data_methodology` when that section is tint/accent. Not a white card.

## [0.21.7] — 2026-08-15

- **Face-up overlay:** dedicated comparison pages use the same 200px overlay as the size checker. The viewport cap no longer shrinks curated millimetre SVGs to actual size.

## [0.21.6] — 2026-08-15

- **English spelling:** US pages use American forms in plugin copy (color, jewelry, millimeter, Diamonds analyzed). The en_GB catalogue maps those to Commonwealth forms; AU/CA/NZ and other `en_*` locales reuse that catalogue (WordPress does not fall back to en_GB on its own).

## [0.21.5] — 2026-08-15

- **Size L/W explainer:** bow-tie copy uses a spaced hyphen (house style), not an em dash.

## [0.21.4] — 2026-08-15

- **Ring-guide inject copy:** house style on reader strings (no em/en dashes, no Oxford comma).

## [0.21.3] — 2026-08-15

- **Ring-guide inject chrome:** drop the tinted outlined card (AI tell). On Ringspo editorial posts the price/size blocks are unboxed article flow — theme headings, chart PNG with 12px radius + shadow, purple text CTA.

## [0.21.2] — 2026-08-15

- **Ringspo ring-guide inject (test slice):** on `/4-carat-diamond-ring/` and `/4-carat-cushion-cut-diamond-ring/`, strip Ninja Tables and replace the Price/Size sections with live US price and size links (shape pages also embed `og-preview.png`). Public hrefs use the site URL slug (`cushion`), not the S3 folder (`cushion-cut`). Expand `editorial_ring_guides` in the Ringspo profile after staging QA.

## [0.21.1] — 2026-08-14

- **Size percentile notes:** drop the lone `No.` FAQ opener, em dashes, and `extreme outliers` in the p10-p90 spread note. Buyer copy now says `No, …` and `bad measurements`.

## [0.21.0] — 2026-08-14

- **Loupe hub history charts:** top-level `/diamond-prices/` shows the natural vs lab-grown % trend chart with templated 1/3/12-month analysis; diamond-type pages show 1/2/3 ct price over time. Ringspo snapshot charts (discount curve, price-per-carat) are unchanged.

## [0.20.46] — 2026-08-13

- **SEO titles on SEOPress sites:** replace `seopress_titles_title` on routed LDN pages so the HTML `<title>` matches `og:title` (e.g. `Diamond Prices - Modern Jeweler` instead of the bare site name). `pre_get_document_title` alone did not win when SEOPress owned the title tag.

## [0.20.45] — 2026-08-13

- **Share cards:** emit `og:image:alt` with the page title so Open Graph previews stay readable when the PNG is the chart card.

## [0.20.44] — 2026-08-12

- **Nat/lab toggle:** preserve the carat slider weight across Natural ↔ Lab-grown via `?carat=` (inactive pill + page load preference over most-listed default).

## [0.20.43] — 2026-08-12

- **Price calculator:** wrap controls in a white surface so tint/accent bands keep heading + lead on the coloured background while fields stay readable.
- **All-shapes calculator:** use destination-style shape picker icons (faceted outlines) instead of text pills.

## [0.20.42] — 2026-08-12

- **Calculator UI:** field-help tips show on hover/focus (click still works on touch); smaller grade-slider stop labels; wrap How it works / What drives the price in `ldn-section` so band spacing separates them; cut stop shows Super Ideal (not Ideal).

## [0.20.41] — 2026-08-12

- **Nat/lab toggle:** prefetch the sibling diamond-type page (`rel=prefetch` + Speculation Rules); flip the active pill immediately on click while navigation continues.

## [0.20.40] — 2026-08-12

- **Price→size explore CTA:** separate copy from the link; style as an explore-style text CTA so the arrow no longer orphans on wrap.

## [0.20.39] — 2026-08-12

- **Diamond-type carat table:** show all rows in a full-height card (no inner scrollbar); Ringspo card chrome aligned with size-matrix tables.
- **Where to go next:** drop the “compare shapes” card so three cards fit one row; slightly tighter card styling on Ringspo.

## [0.20.38] — 2026-08-12

- **Face-up callout:** drop “on the finger” from the comparison result line (avoid diamdb-style phrasing).

## [0.20.37] — 2026-08-12

- **Methodology page:** engaging hero intro (not factual dump); dataset stats as cards in the green header; Explore links as cards; tighter section spacing; plain bands so positional purple does not paint prose sections.

## [0.20.36] — 2026-08-12

- **Face-up overlay / bars:** white surface on purple bands so overlay strokes and area bars stay visible.
- **Comparison side links:** “Individual size pages” uses the shared explore-card grid instead of a bullet list.

## [0.20.35] — 2026-08-12

- **Size checker results:** drop divider above Results; result cards no longer show a “Second diamond” heading (A/B stay aligned).
- **Compare by shape:** rename hub link list (was “Popular comparisons”); multi-column layout. Pair order comes from Z3 hub priority (engagement sizes).
- **Explore the data:** card grid (size chart + methodology) matching other LDN hubs.

## [0.20.34] — 2026-08-12

- **Size matrix:** rounded corners without outer stroke — scroll wrapper uses `overflow: auto` so radius clips the purple header; Ringspo cell radii match `--ringspo-card-radius`.

## [0.20.33] — 2026-08-12

- **Methodology page:** purple “drawbacks” H2 stays white (ink rule no longer overrides); oval spread example + face-up fill sketch; FAQ rewritten for buyer utility; copy notes natural and lab-grown are pooled.
- **Size checker CTA:** keep primary button labels free of theme link underlines.
- **PHP 8.4:** explicit nullable `$ctx` on size segmentation helpers (deprecation noise in local preview).

## [0.20.32] — 2026-08-12

- **Size checker compare:** align A/B panels when “Second diamond” is shown (legend no longer shifts fields).
- **Face-up callout:** remove the mint outline on the comparison result line (plain text, no card).
- **Comparison page:** drop the duplicate delta paragraph under the callout.
- **Comparison scale wells:** white surface + equal-height columns so purple diamonds read on purple bands and the two tables align.
- **Face-up overlay:** cap SVG size so the overlay fits the viewport.
- **Size ladder:** no card border around the shape-hub / mega-hub matrix table (header + row rules only).
- **Size checker results:** drop the duplicate “Size rank” list item (same copy as the quality headline).
- **Size FAQ / footer:** FAQ is forced `ldn-section--plain` (and excluded from positional purple bands) so it is not purple against the theme footer; theme `#content` bottom padding is zeroed on LDN pages so no white gutter above the footer.
- **Size JSON-LD:** stop emitting a duplicate `WebSite` node; keep `WebPage.isPartOf` → `#website` (SEOPress / theme owns WebSite).
- **Size matrix:** drop the gray border around the mega-hub / shape-hub scroll wrapper.

## [0.20.31] — 2026-08-12

- **Size FAQ / footer:** FAQ is forced `ldn-section--plain` (and excluded from positional purple bands) so it is not purple against the theme footer; theme `#content` bottom padding is zeroed on LDN pages so no white gutter above the footer.
- **Size JSON-LD:** stop emitting a duplicate `WebSite` node; keep `WebPage.isPartOf` → `#website` (SEOPress / theme owns WebSite).
- **Size matrix:** drop the gray border around the mega-hub / shape-hub scroll wrapper.

## [0.20.30] — 2026-08-12

- **WP Rocket / Plotly:** `chart-init.js` (~1 KB, excluded from Delay JS) queues `Plotly.newPlot` until the delayed Plotly CDN loads; Plotly itself stays in Rocket's deferred queue for performance.
- **Size charts:** same deferred-init pattern as pricing distribution charts.

## [0.20.29] — 2026-08-12

- **WP Rocket:** exclude calculator (and size interactive) scripts from Delay JS / defer / minify so grade sliders wire on load.
- **Tooltips:** field-help `?` panels close on outside click or Escape.
- **Calculator speed:** cache REST panels by type/carat/shape, abort in-flight fetches, refresh shape/type immediately and debounce carat while dragging.
- **Cut slider:** do not offer Fair (even on stale manifests); Fair stays in pooled Any/ALL only.

## [0.20.28] — 2026-08-08

- **Price-module i18n:** locale switch on all `LDN_Dispatcher` pricing routes; automatic locale restore on `shutdown`.
- **Translation template:** `scripts/ldn_extract_pot.sh` generates `languages/loupe-diamond-network.pot` (391 strings).

## [0.20.27] — 2026-08-08

- **Calculator i18n:** `LDN_Locale` switches WordPress locale per country on calculator page + panel REST; `get_locale()` and consumer-advisory gates on `LDN_Config`.
- **Driver copy:** price-driver sentences ship in `manifest.labels` (`driverIncrease` / `driverDecrease`); JS interpolates tokens only.
- **Consumer guidance slot:** optional `consumer_guidance` section (Phase 8 listings shell), gated by `consumer_advisory_enabled()` + calculator `individual_listings` widget.
- **Docs:** `docs/architecture/calculator-i18n.md` — locale hooks, rollout checklist, consumer guidance extension.

## [0.20.26] — 2026-08-08

- **Sample line spacing:** more room above the “Based on N diamonds…” line under the price row.
- **Quote analysis (hybrid):** eighteen interior slots (four percentile bands × four sub-positions) plus below-p10 / above-p90 extremes.
- **Calculator content:** `how_it_works` and `what_drives_price` sections under the tool (Ringspo profile); driver percentages refresh when selectors change.

## [0.20.25] — 2026-08-08

- **Slider outline fix:** range inputs no longer inherit the text-field border (`input:not([type="range"])`).
- **Quote analysis:** twelve position slots with natural US copy; each reminds readers that cut, certification, and seller terms matter — not price alone.
- **No duplicate analysis:** destination page shows commentary once, below the spectrum bar (not above and below the prices).

## [0.20.24] — 2026-08-08

- **Grade slider tracks:** no outline ring (transparent input shell; grey on the track only).
- **US spelling:** Color (not Colour) on US calculator pages.
- **Quote spectrum:** solid purple rail (no centre fade); more space under the bar; verdict analysis under “Your quote”.
- **Sample line** under the price row has more top margin.

## [0.20.23] — 2026-08-08

- **All calculator sliders** match carat: soft grey track, purple thumb, no progress fill.
- **Lead count** uses `market_overview_json.total_diamonds_tracked` (country-wide inventory), not the default shape-page sample.
- **Quoted price** hint is shorter and sits under the input; result sample line has no divider rule.

## [0.20.22] — 2026-08-08

- **Brand range control:** native-looking chunky purple thumb + fill, soft grey unfilled track (grade and carat). Drops the bordered “custom UI” thumb.
- **Calculator lead:** cites diamond pool size and “Prices last updated …” from the manifest date instead of “Compared like-for-like…”.

## [0.20.21] — 2026-08-08

- **Grade slider track:** soft grey unfilled rail with purple fill behind the thumb (custom track/thumb; `--ldn-grade-fill` updated as the value moves).

## [0.20.20] — 2026-08-08

- **Grade slider stops** sit at the same `i/max` positions as the native range thumb (was equal-width flex cells, so end grades looked off).
- **Result sample line** (“Based on …”) gets more space above it and a light rule so it does not crowd the price row.

## [0.20.19] — 2026-08-08

- **Calculator brand tokens:** destination page wraps in `ldn-page-shell` / `ldn-price-page` and emits `theme_style_block()`, so grade sliders use Ringspo purple instead of the browser's black range thumb.
- **Quoted price:** narrower field plus a one-line hint on how to use the optional quote check.

## [0.20.18] — 2026-08-08

- **Calculator controls layout:** colour/clarity and cut/quote sit in two columns on desktop (stack on mobile).
- **Fancy-shape cut:** greyed slider with an explanation instead of removing the control.
- **Field help:** `?` affordances (details/summary, works with JS off) on colour, clarity, cut, and quoted price.

## [0.20.17] — 2026-08-08

- **Destination pickers:** diamond type is a Natural / Lab-grown segmented control; carat is a range slider paired with a number input (0.3–12, matching the calculator band grid).
- **Cut stop labels:** long grades abbreviate on the slider (VG / Ex / Ideal) so six stops no longer truncate into each other; full names stay in `aria-label`.

## [0.20.16] — 2026-08-08

- **Calculator answer surface:** cut is a point slider (worst→best, “Any” first); the result labels Lower end / Typical / Higher end and cites “Based on N diamonds at …”.
- **Destination lead** is a short claim under the H1 (with pool size when known). Shape-page intro about the page median stays on shape pages only — it no longer appears inside the shared panel.

## [0.20.15] — 2026-08-08

- **Shape picker icons keep their aspect ratio:** plugin SVGs are letterboxed in a square viewBox with a uniform scale, so oval no longer collapses to a circle. Size-pipeline unit-box snippets still stretch (required for true millimetre length/width).

## [0.20.14] — 2026-08-08

- **Calculator shape chooser is an icon row:** shape moves from a `<select>` to a keyboard-navigable radio group carrying the faceted outline for each of the ten shapes. Icons are painted as CSS masks so the line-art takes the site's brand colour when selected.
- Artwork is generated, not hand-drawn: `Sizing/build_shape_svgs.py` now also writes `assets/img/shapes/{shape}.svg` from the same source geometry the size pipeline uses, so the plugin and the size pages cannot drift.
- Fixed a crash when switching to a shape with no published cells — the quote spectrum assumed the calculator panel was always present.

## [0.20.13] — 2026-08-08

- **Calculator page composition is config-driven:** the destination page renders the section list from `page_structure.calculator.sections` (with `section_bands` adjacency/footer coercion, as pricing pages do) instead of a hardcoded body, so another site adds or reorders blocks in its profile with no PHP change.

## [0.20.12] — 2026-08-08

- **Calculator destination:** read legacy grouped manifests on S3 (`colour_group`/`clarity_group`) until C5.9 re-publishes individual grades; destination page shows pickers even when the initial panel is empty.

## [0.20.11] — 2026-08-08

- **Calculator destination page:** standalone `/diamond-prices/calculator/` route with free type/carat/shape pickers, REST panel refresh, and quote-position spectrum (embedded shape-page calculators unchanged).

## [0.20.10] — 2026-08-07

- **Price calculator:** result shows p10 and p90 on the wings with the median price large in the centre.

## [0.20.9] — 2026-08-07

- **Price calculator:** individual colour and clarity grades (point sliders) replace grouped dropdowns; result shows the price directly with an optional IQR range; quote verdict leads when a price is entered.

## [0.20.8] — 2026-08-06

- **Staging diagnostics:** only probe `size_distribution_json` on `size-individual` pages — Z3 publishes it per shape×carat folder, not on hubs, comparison pages, or the Size Checker tool (fixes spurious HTTP 403 on `/diamond-size/compare/`).
- **Size comparison pages:** long-tail comparisons now carry full side fields (depth %, table %, L/W, ideal delta, sample size, quarter-scale SVGs) from individual summaries; face-up overlay renders client-side when no curated `overlay_svg` exists; delta narrative, side-by-side table, and face-up bars always show; market price cards with per-carat figures for both stones.

## [0.20.7] — 2026-08-06

- **Size Checker routing:** register `/diamond-size/compare/` as `compare-tool` before the shape-hub rule so it no longer resolves as shape=`compare` (wrong H1, widget layout, self-link).
- **Diamond Size Checker page:** add how-it-works steps, a default true-scale example preview in Results, popular comparisons + explore links (size chart, methodology). Widget embed on shape hubs unchanged.

## [0.20.6] — 2026-08-06

- **Shape hub table:** carat ladder uses the mega-hub matrix styling (purple header, alternating rows, larger true-to-scale outlines). Z3 now emits matrix-scale SVGs for shape-hub rows — re-run Z3 (or wait for pipeline) to refresh S3 `size-summary.json`.
- **Size Checker widget:** drop the redundant "Your diamond" panel legend and "Your diamond vs the market" results heading; fix legend float so panel titles no longer collide with the Shape field.

## [0.20.5] — 2026-08-06

- **Size-page L/W histogram on purple band:** keep the elongated-shape L/W section purple (white Plotly bars) — a white-surface override on `.ldn-section.ldn-chart` had painted the whole band white, hiding white bars; chart targets on purple skip the white-card rule.
- **Size-page footer gap:** drop bottom padding on `main.ldn-size-page` and the last section so the purple FAQ band meets the theme footer without a white strip.
- **Size distribution histograms (Z2/Z3):** length / diameter / L/W charts use 30 bins over the typical p10–p90 window (not 12 bins across full min–max); chart x-axis zooms to the binned range. Re-run Z2 + Z3 (or wait for pipeline) to refresh S3 `size-distribution.json`.

## [0.20.4] — 2026-08-06

- **All-shapes hub (Ringspo):** chart titles render as H3 from the Plotly payload; drop redundant ranking table from shape cards; merge trend + shape copy into one block; remove generic Shape Breakdown / Expert Recommendations; explore links use card grid; shape-switching price calculator hub (`presentation: hub`).

## [0.20.3] — 2026-08-06

- **Diamond-type hub (Ringspo):** lighter green hero — intro, aggregate stat cards (carat weights + diamonds tracked), natural/lab toggle, and type-level carat price lookup; carat table and price-per-carat chart combined in one white section with sticky table header (desktop); milestone copy lives only under the chart; explore links use type-nav cards.
- **Anchor carat** on diamond-type pages follows `most_popular_carat` from type-summary (not hardcoded 1 ct).
- **Ops:** regenerate lab-grown `static-content.json` via C1 (`--force`) if staging diagnostics show schema behind.

## [0.20.2] — 2026-08-06

- **Ringspo shape-page IA:** reorder sections (colour/clarity heatmap before calculator; merged buying advice after tools); chart data summary is screen-reader/crawler-only (`ldn-chart-fallback--sr`); hero stat label clarifies page median is across all qualities; calculator copy distinguishes spec-level price from page median; size cross-link moves to a compact `size_explore_link` block after FAQ (no longer appended under the heatmap).

## [0.20.1] — 2026-08-05

- **Ringspo top-level hub polish:** shorter carat-table intro; lab-grown discount chart nested under the table with a proper heading and intro (removes the band-padding gap); drop `quality_overview_static` and `top_level_explore` from the section list; most-traded table labels drop a trailing "Cut" and use nowrap to avoid two-line rows.
- **Section bands:** the first body section after a green hero band coerces from purple (`tint`) to white (`plain`) so green and purple never sit back-to-back.

## [0.20.0] - 2026-08-04

- **Display ads (CP 55):** config-driven ad slots — `config/ad_slots.yaml` maps layout slot ids to commercial types, placements, and image sizes; manifest from `network_consumer.ads.manifest_url`; resolve by `site_id`, `country_code`, and `diamond_type`; server-side impressions + client click tracking to hub `ldn-ops/v1/track`; staging suppresses events. Works across all sites/countries that declare `ad_slots` in content profiles.

## [0.19.8] - 2026-08-04

- **Size mega hub (Ringspo):** restore horizontal scroll on the matrix table (`overflow-x: auto` on `.ldn-size-matrix-scroll`; `width: max-content` on the table so columns do not compress). Ringspo card-radius rules had set `overflow: hidden`, which blocked sideways scroll and broke sticky header/shape cells.
- **Size checker CTA:** primary button label visible again — Ringspo band `color: inherit` on links was overriding `.ldn-btn--primary` (purple text on purple button).

## [0.19.7] - 2026-08-04

- **Diamond-type hub (Ringspo):** hero stat cards from `type-summary.json` (carat weights, diamonds tracked, 1 ct typical price, 1 ct sample size); section cleanup drops redundant `type_overview_dynamic`; adds `diamond_type_explore` and `faq_static` (C1 prompt in `section_prompts_base.yaml`).
- **Diamond-type polish:** 1 ct row highlighted in the carat tiers table; stacked mobile table; price-per-carat chart gets a no-JS crawler fallback (1 ct + 2 ct PPC).

## [0.19.6] - 2026-08-04

- **Top-level hub polish:** 1 ct row highlighted in the carat table; type-nav cards show typical 1 ct prices from `market-overview.json`; lab-grown discount chart gets a no-JS crawler fallback (1 ct + 2 ct); most-traded tables stack as cards on narrow viewports.

## [0.19.5] - 2026-08-04

- **Top-level hub (Ringspo):** section list cleanup — removed unimplemented `partner_spotlight` and redundant `market_overview_dynamic` / `cross_site_comparison`; added `market_guidance_static`, `quality_overview_static`, `shape_preview`, `top_level_explore`, and `faq_static` (C1 prompts in `section_prompts_base.yaml`). Same cleanup on `diamond_type` (drop monetisation placeholders).

## [0.19.4] - 2026-08-04

- **Top-level hub hero stats (Ringspo):** the green hero band now shows market-scale cards from `market-overview.json` — diamonds tracked, combination counts, 1 ct natural typical price, and 1 ct lab-grown discount — instead of leaving the band prose-only.

## [0.19.3] - 2026-08-04

- **All-shapes hub (Ringspo):** shape cards show rank, sample size, and price range; optional size-chart link when the size module is rolled out; partial-coverage banner when C5.1 reports incomplete shape coverage (staging-safe); bar chart and extended ranking table below the card grid; hero stat cards use "Typical price (median)" and include "Shapes compared".
- **All-shapes page structure:** split overview into analysis + detail sections; FAQ and explore links (adjacent carats, natural ↔ lab-grown); C1 `faq_static` prompt for all-shapes in `section_prompts_base.yaml`.

## [0.19.2] - 2026-08-04

- **Shape hub table:** carat column shows linked weights on per-shape hubs; lead copy explains medians and click-through; missing depth % shows an em dash.
- **Shape hub intro:** cites total real-diamond count when `total_n` is in the shape-hub summary (Z3 now emits it).
- **Scale explorer:** US quarter stays a fixed on-screen size while the stone grows (fixed SVG viewBox width).
- **Diamond Size Checker:** fixes `undefined carat` in percentile copy; manual entry ranks against the entered carat (face-up knots scaled by carat^(2/3)); widget form + results sit in one white surface; panel titles no longer overlap the card border.

## [0.19.1] - 2026-08-04

- **Size-page distribution layout (elongated shapes):** length histogram moves under the three-tier spread silhouettes; L/W ratio histogram sits in its own purple band with an explainer and **white bars** (bars were invisible when purple-on-purple).
- **About this data** moves from the chart section into **Chart numbers vs real stones**.
- **Chart-vs-real copy** replaces “Chart sites” with “Other websites that publish diamond size charts”.
- **Footer gap** on size pages: drop `min-height: 100vh` phantom whitespace above the theme footer.

## [0.19.0] - 2026-08-03

- **Schema-aware staging diagnostics.** The panel now probes size-page JSON artefacts (not the pricing default list), shows a **Schema** column comparing each artefact's `_meta.schema_version` to the catalogue version in the config bundle, and lists drift in the notes. Unstamped or behind artefacts still render with their existing fallbacks — the page never 404s solely for schema drift — but the mismatch is no longer invisible. Fresh fetches also log drift when `WP_DEBUG_LOG` is on.
- Config bundle rebuilt so every artefact carries `schema_version` for the plugin read path.

## [0.18.2] - 2026-08-03

- **Section band adjacency rules** enforced at render time: coloured bands (`tint` purple, `accent` green) never sit back-to-back — white separates them — and the last body section is never purple because the theme footer is already purple. Optional sections (empty FAQ, skipped hub intro) can change which modules render on shape pages; coercion applies after render so variable fourth-level content still obeys the rule.
- **Top-level hub bands** updated: type-nav cards return to a plain band between the purple carat table and purple most-traded block (was green accent sandwiched between purple).

## [0.18.1] - 2026-08-02

- **L/W segmentation table** on fancy-shape individual size pages when `size-summary.json` includes `lw_segments` (from Z2 `lw_stats`). Round pages keep the cut-grade table from Z2.1. Histogram charts are suppressed when the table is present or when bin counts do not cover enough of the sample.
- **Size-page chart sections** render on a white band even when positional purple banding would colour the section; axis/heading text stays dark on the white surface.
- **"What published charts assume"** callout: removed the purple left-border accent stripe.
- **Percentile range note** copy clarified in `size-copy.json` source template.

## [0.18.0] - 2026-07-31

- **The price calculator ships on shape pages** (`price_calculator` section, entitlement-gated), reading the percentile cells C5.9 publishes as `price-calculator.json`. The reader picks colour group, clarity group and, on round pages, cut grade; leaving the price field blank answers "what should this cost", and entering a quoted price answers where that price sits against the same set of stones. **One widget, not two**: the checker is the calculator with the price field filled in, so there is no separate `price_checker` anything.
- **The default cell is server-rendered as prose**, so the page answers a reader with JavaScript off and a crawler that runs none. Result states get no URLs of their own and are not indexable.
- **The manifest is an argument, never fetched from page context.** `price_calculator_from_manifest()` takes the cell manifest and reads nothing from `$ctx->shape` or `$ctx->carat`, which is what lets a future calculator page with no shape or carat of its own host the same component instead of a second implementation. A guard test renders the module against a manifest that disagrees with its page context and asserts the manifest wins.
- **No nearest-band fallback.** A specification with no cell says so and suggests a wider group; it never answers from the pooled-cut cell or a coarser colour/clarity band. The producer applies no sample floor either, so every answer states how many diamonds it rests on: "3 diamonds" tells the reader exactly how much to trust the figure, whereas withholding the cell tells them nothing.
- **A quoted price gets a band, not a percentile.** The manifest publishes five percentiles (p10 to p90), which cannot support an interpolated integer percentile, so the verdict is one of six bands from "cheaper than almost every diamond at this specification" to its opposite.
- The cut control is emitted only when the manifest sets `has_cut_dimension`, which is round-only: cut grading is not comparable across fancy shapes. On those pages the copy explains the absence rather than leaving a missing control unexplained, and the specification sentence drops cut entirely instead of claiming "any cut".
- Every reader-facing string lives in PHP and travels to the browser inside the manifest's `labels`, so `assets/js/price-calculator.js` holds no English and translation stays where the `.po` files can reach it. Strings the page cannot use (the cut phrases on a fancy shape) are not shipped.
- The section sits above the colour/clarity heatmap on Ringspo shape pages and stays on a plain band: two tinted sections in a row read as one, and white gives the form controls the most contrast.

## [0.17.0] — 2026-07-31

- **The diamond-type hub finally has a chart.** New `price_per_carat_chart` section renders C5.2's `price-per-carat-chart.json`: price per carat against carat weight for the page's own diamond type, with copy that explains why a carat costs more in a bigger stone and tells the reader to look for the step-ups at 1, 1.5 and 2 carats. Level 2 previously had no chart at all; its `comparison_chart` hero is a table despite the name.
- The chart is derived from the same `carat_tiers` the hub's table renders, so the curve can never disagree with the table's price-per-carat column beside it. Unlike the pre-existing `type-comparison-chart` (which is written only to the natural prefix and is consumed solely by the legacy pricing plugin), it is written per diamond type, so the lab-grown hub gets one too.
- No text fallback is emitted inside the chart target, because the table on the same page already lists every number the curve plots.
- **The top-level lab-grown discount curve is switched on for Ringspo.** The chart, the fetch and the render path all already existed; no site had ever been entitled to `market_discount_chart`, so it had never been produced. It is a cross-section by carat rather than a time series, so it does not breach Ringspo's "Loupe owns history" rule.
- **New `most_traded_table` section on the top-level hub**, from C5.3's `top-tables.json`: the ten most-traded natural and lab-grown shape/carat combinations, each row linking to that combination's shape page. This is the hub's internal-link block, putting the deepest pages one click from the top. It labels its figure **"Median price"** rather than "Typical price" because these rows are true per-combination medians (C3's p50 for one shape at one weight), unlike the carat table higher up the page whose figure combines every shape at that weight. Sample size is shown per row, as elsewhere. A group with no rows renders no heading, so a single-type site does not get an empty "Lab-grown" block.
- The DPE/carat homepage keeps its own `popular_searches` section, which pairs the carat table with a flat list of shape links; the two presentations are deliberately separate rather than one widget with a presentation flag, since they sit on different page types.

## [0.16.0] — 2026-07-31

- **"About this data" closes every pricing level** (`data_methodology` widget, entitlement-gated, copy from the profile's `methodology` block). States the statistic, the number of diamonds behind it, that the figures are asking prices rather than sold prices, and the date. The statistic line is **per page level**, because the levels do not publish the same statistic: a shape page carries a true median (C3's p50 over stone prices) while every level above it combines per-shape medians weighted by sample size. A guard test refuses any profile whose upper-level copy calls that composite a median.
- **Retailers are never named and the pool size is never stated.** Same posture as the size module, which withholds even the retailer count below `RETAILER_DISCLOSURE_THRESHOLD` (15); the pricing pool is smaller than that. Enforced by test against the real profiles, not just the render stub.
- **Partial shape coverage is disclosed on the page.** When C5.1 reports `coverage_complete: false`, the block states how many of the tracked shapes the figure covers. It fires only on an explicit flag, so an older artefact without coverage fields never implies a gap.
- Reworded the carat-table intros on the top-level and diamond-type hubs: clearer explanation of the weighting, and the thin-sample warning now points at the "Diamonds analysed" column. No em dashes in new reader-facing copy.

## [0.15.0] — 2026-07-31

- **The top-level carat table now discloses its sample size.** `carat_price_table` rows carry `natural_sample_size` / `lab_grown_sample_size` from C5.3, rendered as a "Diamonds analysed" column. The heaviest weights are traded far less often than 1 ct — 9 ct rested on 28 stones against 8 ct's 113 — which is how a heavier weight can read as cheaper than a lighter one. Thin rows are still published; the count is what makes them interpretable. Rows from artefacts written before C5.3 carried the field degrade to an em dash.
- **"Median price" corrected to "Typical price" on the diamond-type carat table.** That figure is the sample-weighted mean of each shape's median at that weight, not a median of the stones — the per-shape carat ladder, which *is* a true median from C3's p50, keeps its original label. Ringspo's how-to-read copy and the top-level table intro now describe the actual calculation.
- Known remaining instance: the L3 all-shapes stats block still labels the same composite figure "Median price", and does so in Schema.org output. It lives in three duplicated `stat_specs()` lists (renderer, schema, content trait) and needs level-aware labelling — logged, not fixed here.

## [0.14.0] — 2026-07-31

- **Section bands are declared, not positional.** A pricing section's surface colour now comes from `page_structure.{level}.section_bands` in the content profile, emitted as `ldn-section--tint` / `--accent` / `--plain`. Previously it came from `:nth-of-type(n+3):nth-of-type(odd)`, so inserting or reordering a module reshuffled the colour of every section below it, and each new white-surfaced component needed its own "restore dark text" override (there were 20+). Those are replaced by one text-inheritance rule plus one list of components that own a white surface.
- **Bands own their vertical space with padding** (`page_chrome.band_spacing`, default 3rem) instead of margin. A margin on a coloured full-bleed band rendered as a white gutter above and below the colour, which the retired per-section `margin-top: 0` patches were each working around individually.
- **Spacing scale** (`--ldn-space-1` … `--ldn-space-7`) in `shared.css`; Ringspo chrome now expresses its rhythm in those steps rather than a dozen unrelated literals.
- **Reading measure** via `page_chrome.measure` (Ringspo: 68ch). Body copy previously ran the full content column at ~95 characters per line.
- Ringspo: `h3` raised to 1.2rem so sub-headings separate from body copy; band padding now steps down under 768px (only type scaled before).
- Size pages keep positional banding until `LDN_Size_Renderer` is migrated to declared bands; the shared band rules match both forms.

## [0.13.4] — 2026-07-29

- **All-shapes SEO**: Ringspo H1/title is `{carat} {Type} Diamond Prices by Shape — {Country}`; meta/Dataset description leads with compare-by-shape + full country name; keywords are level-scoped so this hub does not share primary phrases with the type hub or named-shape pages.

## [0.13.3] — 2026-07-29

- **Diamond-type hubs: intro cites the most-listed carat's own median/sample** (not type-wide weighted median); Ringspo H1 is `{Country} Natural/Lab-Grown Diamond Prices`; carat table adds price-per-carat + how-to-read copy; footer gap fix for type pages.

## [0.13.2] — 2026-07-29

- **Ringspo top-level hub polish**: H1/title is `{Country} Diamond Prices` (e.g. United States Diamond Prices); drop the weighted-average intro; keep an enhanced market-size blurb (largest retailers, updated daily).
- Shorter nat/lab H2 and fixed comparison copy; type-nav cards move to a green band under the carat table with heading “Browse natural or lab-grown prices”; remove Comparing Diamond Shapes from the Ringspo layout.
- Fix duplicate intro under the table (`market_overview_dynamic` now skips when the hero already showed it); close the phantom footer gap on top-level hubs.
- SEO: top-level meta/Dataset description and keywords use market-overview scale (totals, combos, daily update) instead of the generic “Market pricing data for diamonds.” fallback.

## [0.13.1] — 2026-07-28

- **All-shapes shape cards move to a purple body band** with the title `{carat} carat diamonds by shape` (no longer nested inside the green hero — that nesting caused the large gap before copy).
- **Type and top-level hubs**: freshness date renders under the title; intro copy renders above the carat/overview table; body sections no longer repeat that intro.
- **US body-copy country policy** applied in the diamond-type intro fallback so blanked country names cannot produce "in span".
- Spacing: first section after the hero and `h2` → prose gaps tightened on Ringspo chrome.

## [0.13.0] — 2026-07-27

- **Operator surfaces show when the build was cut, not just which one it is**: the staging diagnostics panel and **Tools → Loupe Diamond Network** now print the release date alongside `Plugin v0.13.0`. The version alone only answers "did my deploy land?" if you remember what was current.
- The date comes from the newest `CHANGELOG.md` entry via `LDN_Plugin::release_info()`, not a new constant — a fourth version source would be the one most likely to be forgotten, and `filemtime()` is both banned as a version source and wrong for this (it moves on every redeploy of an unchanged build). The file is streamed and capped at 40 lines since the entry is always at the top and the panel renders on every staging page view.
- If the newest entry is not the running version, the date is withheld and the changelog's newest version named instead: that date belongs to an older build, and a confidently wrong date is worse than none. `tests/unit/test_plugin_version_changelog_sync.py` fails on that drift for all three plugins, so it should not reach a deploy.

## [0.12.1] — 2026-07-27

- **Size pages had two `<title>` elements**: `LDN_Size_Renderer::render_head_content()` emitted its own on `wp_head` priority 5, and 0.11.0 added a second at priority 1 via `pre_get_document_title`. Only the first counts, so the size page's descriptive title lost to whatever the blog-index query produced — the exact defect 0.11.0 set out to fix, still visible on size URLs. The size head no longer emits a `<title>`; the document title comes from `LDN_Query_Signals` alone, as on price pages, and now carries the site name and separator like every other page on the site.
- **`og:type` and `og:site_name` restored on size pages**: 0.12.0 suppressed SEOPress's copies on every LDN route, but only the price head emits replacements — the size head's OG set stopped at `og:url`. Both tags vanished from size pages. The size head now emits them, `og:site_name` from the site config's `brand_name`.
- Size pages still have no `og:image`: SEOPress's was empty and is now suppressed, and `og_preview_url()` resolves a preview only for price shape pages. `twitter:image` is likewise absent by design so X falls back to `og:image`, which on a size page means no image at all. Producing a size preview needs a new artefact from the pipeline, not a plugin change — see `docs/TECH_DEBT.md`.
- `tests/test-ldn-size-renderer.php` now walks `LDN_Seo_Bridge::SEOPRESS_FILTERS` and asserts the size head emits a replacement for each claimed tag, with the two unreplaced ones named explicitly. Adding a filter to the bridge without emitting the tag now fails a test instead of silently deleting it from the page.

## [0.12.0] — 2026-07-27

- **Routed pages had no meta description at all**: `seo_plugin_emits_meta()` stood down whenever an SEO plugin was merely installed, but SEOPress emits a description only for a singular post, a posts page carrying `_seopress_titles_desc`, or a blog-as-front-page site — a routed LDN page is none of those. Both plugins stayed silent, so every price page on the network shipped without one. LDN now owns the tag. (Size pages were unaffected — `LDN_Size_Renderer` emits its description unconditionally, without consulting `seo_plugin_emits_meta()`.)
- **One canonical instead of two**: SEOPress's DEFAULT CANONICAL branch emits a canonical on these routes after all, derived from the request URL — it agreed with LDN's only because `LDN_Canonical_Redirect` guarantees the request URL is already the canonical form. Two tags that agree by luck are one settings change away from disagreeing, and conflicting canonicals are ignored outright.
- **Social previews use the page's chart again**: SEOPress emitted an empty `og:image` (plus empty `og:image:secure_url` and `og:image:alt`) *ahead* of LDN's real one, and pointed `twitter:image` at an unrelated media-library upload. Scrapers take the first occurrence, so the chart preview never won. Duplicate `og:url`, `og:type` and `og:site_name` are gone too.
- New `LDN_Seo_Bridge` handles all of the above by suppressing SEOPress's version of each tag LDN emits, scoped to routes where a dispatcher holds a page context — so ordinary posts and pages keep their SEO plugin output untouched, and so does an LDN route that 404s. Filter names were verified against SEOPress 8.x source; each takes the fully rendered tag, so returning `''` removes it. `og:locale`, `twitter:card` and `twitter:creator` are deliberately left alone as site-level facts LDN does not emit. Yoast and Rank Math are not bridged and LDN keeps deferring its description to them, as before.
- `head_tags()` had no test coverage, which is how the missing description survived; `tests/test-ldn-renderer.php` § 15 now asserts one canonical, one description and a non-empty `og:image` with SEOPress active, and fails if the old rule returns.

## [0.11.0] — 2026-07-27

- **Routed pages get their own `<title>`**: LDN rewrite rules target `index.php?ldn_route=…` with no core query vars, and WordPress treats a query carrying no core vars as the blog index — so every price and size page inherited the blog index's title, rendering as the bare site name while `og:title` carried the real "1 Carat Round Natural Diamond Prices (US)". New `LDN_Query_Signals` supplies the page's own title via `pre_get_document_title`, sourced from the new `LDN_Renderer::document_title()` (price) and `LDN_Size_Renderer::page_title()` (size) so the tab, the SERP link and the social card cannot disagree. The site name and `document_title_separator` are applied here because returning from `pre_get_document_title` short-circuits `wp_get_document_title()` before its own assembly.
- **No more `page/2/` on pricing URLs**: the same blog-index query left its pagination state live, so a paginated blog advertised `…/1-carat/round/page/2/` as the next page of a pricing URL. The main posts query — which the templates never read; `level-1-landing.php` has no loop — is now skipped via `posts_pre_query` with `found_posts` and `max_num_pages` flattened, removing the crawl trap and a wasted `SELECT` on every LDN page across the network.
- `is_home()` is deliberately left true: core's `WP::handle_404()` spares a post-less main query from a 404 only for a fixed set of conditionals, and `is_home()` is the one that applies here, so clearing it would 404 every LDN page once the posts query is skipped. It is also what the theme keys its body classes off. That leaves the `itemtype="schema.org/Blog"` on `<body>` in place (a weak signal the JSON-LD `Dataset` overrides) — see `docs/TECH_DEBT.md`. Disable either correction per site with the `ldn_normalise_query` filter.

## [0.10.1] — 2026-07-27

- **Chart fallback date matches the page**: the CP54_04 factual statement rendered its date as raw ISO (`as of 2026-06-30`) while the freshness line a few hundred pixels below showed `June 30, 2026`. `data_summary_text()` now formats the date with the site's `date_format`, and the date-localisation logic shared with `freshness_html()` moved into `LDN_Trait_Data::localised_date()`. The JSON-LD `Dataset.description` and the meta description are unchanged and keep ISO — `dataset_description()` only substitutes a display date when one is passed, which just the visible fallback does.

## [0.10.0] — 2026-07-27

- **Chart text fallback without JavaScript (CP54_04)**: summary-backed charts now carry their factual statement (median price, sample size, analysis date) inside the Plotly target div. `Plotly.newPlot()` clears the container when it draws, so the text is in the HTML source for crawlers, JS-disabled browsers and a blocked Plotly CDN, but never shown alongside the chart — which is why it does not duplicate the intro paragraph or freshness line for ordinary readers. `LDN_Trait_Content::data_summary_html()` (dead code since the editorial intro took over the page) is replaced by `data_summary_text()`, returning bare text for the chart to render. The statement is the same string as the JSON-LD `Dataset.description`, which previously had no visible counterpart.

## [0.9.0] — 2026-07-27

- **One URL form per page**: price and size routes matched both `/…/round` and `/…/round/` with no redirect between them, so a single page served 200 on two URLs. New `LDN_Canonical_Redirect` 301s the non-canonical form on `template_redirect`, deriving the target from `user_trailingslashit()` so the 200 URL always agrees with the canonical tag, `og:url`, JSON-LD `@id`/`url` and hreflang. Sitemap routes are excluded (`.xml` must not gain a slash).
- **Sitemap emits the canonical form**: `canonical_url` in `ops.page_url_registry` is now slash-terminated (written by C4.5), so `<loc>` values and cross-site comparison links no longer advertise the variant that redirects.

## [0.8.3] — 2026-07-14

- **Ringspo card chrome**: all white card surfaces (hero chart, headline stat cards, shape/price/size cards, tables on purple bands, size-module panels) use a consistent **12px** corner radius via `--ringspo-card-radius`.

## [0.8.2] — 2026-07-14

- **Diamond-type pages**: type-summary intro copy now renders inside the green hero band (white on green) when templated copy is absent; the body section no longer duplicates it.
- **Pricing → size link**: shape pages show a size snapshot card (median mm dimensions + CTA) after the colour/clarity table instead of a plain footer link.
- **Purple-band tables**: colour/clarity heatmap scroll wrapper is transparent; only the table is carded for a less boxy look on purple sections.

## [0.8.1] — 2026-07-14

- **Top-level hub**: `summary_cards` hero component now renders the C5.3 natural vs lab-grown carat price table (was an unmapped no-op on Ringspo).
- **Breadcrumbs**: pricing pages render the breadcrumb trail above the H1 in the hero band (and on non-band pages).
- **US locale**: color/clarity heatmap headings and copy use American *color* spelling on `us` pages; other countries keep *colour*.

## [0.8.0] — 2026-07-14

- **Shape hub cards (CP53_08)**: `shapes_at_carat` widget with entitlement-driven presentations — `shape_cards` (linked card grid for Ringspo, DA, BDI), `bar_chart_links` (chart + table for Loupe/DPE), `table_ranked` (ranked table for DPG/DHUK/carat EMD). `bar_chart` / `table_chart` hero components now dispatch through this layer instead of rendering nothing on Ringspo all-shapes pages.

## [0.7.0] — 2026-07-13

- **Diamond Chart pre-launch (CP 114)**: carat-hub mm ruler/grid scale explorer (no quarter); individual pages lead with full min–max spread; profile marketing shell at `/` with CTAs to `/size/`; `z3_enabled` on; CORS whitelist for `diamondchart.org`.

## [0.6.9] — 2026-07-13

- **Diamond Chart URL IA (CP 114)**: carat-first `/size/` tree — mega hub `/size/`, carat hubs `/size/{carat}-carat/`, individuals `/size/{carat}-carat/{shape}/`; no shape hubs, compare routes, or checker on carat-first sites. Router matches `{carat}`/`{shape}` token order from `url_structures.yaml`.

## [0.6.8] — 2026-07-13

- **Editorial chrome (CP 114)**: register `page_chrome.heading_style: editorial` (was falling back to minimal). New `families/editorial.css` — Crypto Head–inspired dark hero, light page, white card sections; shared by any site that opts in. LDN loads `families/{heading_style}.css` when present. Diamond Chart palette + site overrides updated.

## [0.6.7] — 2026-07-13

- **Diamond Chart SEO (CP 114)**: mega hub H1 is plain "Diamond Size Chart" (ranking target); "Full Measured Range" moves to document title only. Profile-driven `matrix_carats` for homepage matrix columns (distinct from Ringspo). Z3 respects dashboard `site_ids`; ops dashboard + LDO catalog register `diamondchart`.

## [0.6.6] — 2026-07-13

- **Diamond Chart copy & homepage (CP 114)**: site-specific FAQ/methodology templates with real-data placeholders (`range_min`/`range_max`, `{total_n}`); mega hub title "Full Measured Range", matrix range labels, detailed min–max table, methodology CTA (no size checker); `families/diamondchart.css`.

## [0.6.5] — 2026-07-13

- **Diamond Chart (`full_range` presentation, CP 114 scaffold)**: size renderer supports `range_presentation: full_range` in `size-summary.json` — min–max spread labels, key-dimensions rows, and range note copy (vs Ringspo p10–p90). Z3 profile `full_0_100` emits two-stone spread SVG. Site config + entitlements scaffolded; Z3 uploads remain gated until bucket live.

## [0.6.4] — 2026-07-13

- **Percentile rationale (p10–p90)**: methodology page adds a dedicated section and FAQ explaining why ranges use 10th–90th percentiles instead of min/max; individual pages label range rows `(10th–90th %)`, add a spread-section note with link to `#why-percentile-ranges`, and mention percentiles in the inline "About this data" strip. Z3 `size-copy.json` gains a `percentile_range_note` blurb for the page intro after re-run.

## [0.6.3] — 2026-07-13

- **Shape-aware distribution charts**: round / near-round individual pages show an average-diameter histogram; elongated shapes show length (mm) and L/W ratio histograms. The confusing chart-ideal overlay line is removed from histograms (ideal vs real stays in the "Chart numbers vs real stones" section).
- **Spread diagram**: dashed crown-height guide only — solid median line and dot markers removed. Tier labels drop the Ø symbol (`6.39 mm average diameter`).
- **Key dimensions heading** is now title case: "{carat} Carat {Shape} Diamond Key Dimensions".
- **Chart numbers vs real stones** splits into two subsections: "What published charts assume" and "How depth changes face-up size". "About this data" moves inline under the distribution charts.
- **Comparison pages**: two-column layout with per-stone quarter-scale image, measurements, depth %, table %, ideal proportions, and face-up delta vs chart ideal; overlay comparison below.
- **Size Checker widget**: shape + carat on one row; "Depth (mm)" label; dropdowns use "1 carat" not "1 ct"; results cards use "carat" wording and new percentile phrasing; quarter stays fixed size in the shape-hub scale explorer (mm-based viewBox).
- **Methodology page**: green header band, white body sections; only "The drawbacks of ideal-proportion charts" uses the purple band. Footer gap after the last section fixed on size pages.
- **Requires Z2 → Z3 re-run** on staging for new histogram fields (`diameter_histogram`, `length_histogram`, `lw_ratio_histogram`) and comparison `visuals` in S3 artefacts.

## [0.6.2] — 2026-07-12

- **US quarter scale reference**: replaces the programmatic coin circles with a raster quarter PNG (`assets/img/us-quarter.png`) at the official **24.26 mm** diameter in true-mm scale SVGs (individual pages, shape-hub SSR fallback) and the interactive scale explorer (`size-checker.js`). Z3 emits a `{{LDN_US_QUARTER_IMG}}` placeholder; WP resolves it to the plugin asset URL at render time.

## [0.6.1] — 2026-07-12

- **Cut-grade size table** on round individual size pages: when `size-summary.json` includes `cut_segments` (from Z2.1 + Z3), renders "How does cut grade affect size?" with median diameter, face-up area, and depth % per GIA cut grade. Headline pooled stats are unchanged.

## [0.6.0] — 2026-07-11

- **Diamond Size Checker** replaces the separate comparison tool and spread checker. `/diamond-size/compare/` now hosts the merged tool: check one diamond against real-market percentiles or toggle on a second stone to compare — reference stones (typical market size by shape × carat) or manual carat + L×W(×D mm) entry. Results render in a separate section after the **Check size** button is pressed (no more live-updating under the form). `/diamond-size/spread-checker/` 301-redirects to `/diamond-size/compare/`. New shared `size-checker.js` (replaces `size-comparison.js` + `size-spread-checker.js`); the checker also ships as a drop-in widget (`size_checker_widget_html`) rendered on shape hubs.
- **Mega hub matrix table**: `/diamond-size/` swaps the long every-combination table for a Blue Nile-style matrix — one row per shape, ~10 nominal carat columns, each cell a true-to-scale outline SVG with W×L mm linking to the individual page. Sticky header row + sticky shape column; horizontal scroll on mobile. Old shape-tile selector and dual CTAs removed; a single Size Checker CTA (white box on brand purple) sits under the matrix.
- **Mega hub intro** now leads with the real sample size ("measurements from N real diamonds") and positions our data against chart sites that derive sizes from idealised cut proportions; rendered white-on-teal inside the header band, and the h1's default top margin no longer opens a white line between the breadcrumb and title bands.
- **Interactive scale explorer** on shape hubs: the static US-quarter figure gains a carat slider (snaps to real carat bands) and a shape dropdown, re-rendering the stone at true relative scale from the shared checker manifest. Server-rendered 1 ct figure remains the no-JS/crawler fallback.
- **Shape-hub table cleanup**: dropped the L/W range brackets and the "vs. ideal chart" delta column; heading is now "{Shape} diamond size chart by carat weight"; tables get rounded corners; carat links and outline thumbnails stay legible inside purple Ringspo bands (dark text/links on white table cards).
- **New `/diamond-size/methodology/` page** (`size-methodology` level): dataset stat cards (real diamonds measured, shapes, carat weights, retailers), why we use real measurements, how we collect and aggregate, the face-up area model, drawbacks of proportion-formula charts, and data freshness — plus FAQ. Individual pages' "About this data" strip links to it. Added to llms.txt.
- **Structured data upgrade** across all size pages: `@graph` now includes `WebSite`, `WebPage` (with `dateModified` from the artefact's `generated_date`) and an enriched `Dataset` (`measurementTechnique`, `license`, unit-annotated `variableMeasured`, sample size); the size checker page adds a `WebApplication` node. Organization referenced by `@id` throughout.
- **Brand colours in comparisons**: stone A is Ringspo purple `#706cc8`, stone B signature green `#6cc8be` (was fuchsia/slate) across the JS overlay, SSR comparison SVGs (Z3), legend swatches and face-up bars.
- **Fix**: `build_size_individual_url()` now appends `-carat` to the `{carat}` segment, matching the router regex and the Z3 sitemap — internal links (breadcrumbs, hub tables, matrix cells, adjacent-carat links) previously pointed at the 404 variant `/diamond-size/round/1/`.

## [0.5.0] — 2026-07-11

- Size pages — live price snapshots: the "View diamond prices" text links are replaced with a **What does a X carat {shape} cost?** section embedding live figures from the pricing `summary-data.json` (median price, price range, diamond count) as white cards linking to the natural and lab-grown pricing pages. Falls back to plain links when no price artefact resolves.
- Size SVGs are now **geometry-only**: captions and tier labels move out of the mm-scaled SVGs (where text rendered at unpredictable pixel sizes) into HTML — `scale_figure_html()` adds the "Relative actual size" figcaption; `spread_labels_html()` renders the bottom-10%/average/top-10% tier labels (Ø mm + face-up mm²) as a grid under the spread figure. Requires Z3 re-run to strip the old in-SVG text.
- Removed the destructive `.ldn-size-outline svg [fill]` CSS rule that recoloured **every** filled SVG element (coin, labels, guides) to brand purple — the root cause of the unreadable spread diagram. Stone colour is injected upstream via `currentColor`.
- Merged **Depth and face-up size** + **Ideal vs real measurements** into one section, "Chart numbers vs real stones" (single `h2`; ideal callout + depth↔face-up narrative).
- Key dimensions table now leads with **Shape** and **Carat weight** rows so the block is self-describing.
- Face-up distribution section heading is consumer-first ("Do all X carat {shape} diamonds look the same size?"); "face-up size distribution" stays in the lead copy for AEO term coverage.
- Ringspo size-page chrome: intro band is now white-on-signature-green (extending the header band), and the 1rem breadcrumb margin that opened a white gap between the green breadcrumb and title bands is removed. Size figures, price cards and the ideal-vs-real callout sit on white cards inside purple bands.

## [0.4.2] — 2026-07-04

- Ringspo band palette: hero/title band is **signature green `#6cc8be`** with white text; lower content bands use **solid signature purple `#706cc8`** with white headings, body copy and links. Pricing context and Expert take remain white; subsequent odd sections (colour × clarity, FAQ, etc.) are full-bleed purple. Tables and charts inside purple bands sit on white cards so data stays readable.

## [0.4.1] — 2026-07-03

- Ringspo palette refinement: the hero/title band is now the **signature purple `#706cc8`** (was green), matching the brand header. Content sections below stay edge-to-edge and alternate white with a subtle **signature-green** tint (two brand colours only). All bands — hero, every content section, and the standalone title/breadcrumbs on size/other page types — use the same true `100vw` full-bleed, so nothing stops short of the viewport edges. CSS-only; plugin version bumped for asset cache-busting.

## [0.4.0] — 2026-07-03

- Ringspo pricing pages: new full-bleed **hero band** (`page_chrome.hero_band`) in the signature green (#6cc8be) grouping the title, breadcrumbs, hero chart (on a white card) and white headline stat cards (`hero_stats_html`: current price · diamonds analysed · price range · period change). Content sections below the band are white and edge-to-edge (single brand colour, sharp edges) — replacing the old alternating purple/green tint bands. Ringspo intro leads with stat cards (`page_chrome.intro_style: cards`) instead of the prose paragraph, and section order is Pricing context → Expert take. Loupe/other families are unaffected (flags off). Config bundle regenerated.

## [0.3.11] — 2026-07-03

- Headline price consistency: `intro_html()` and `stats_html()` now prefer the distribution **median** (`distribution.median_price` → `percentiles.p50`) for the lead sentence and "Current price" stat, falling back to `current_price` only when no median is present. This makes the intro, hero stat and carat-ladder table agree (e.g. $3,711 for 1ct round natural) instead of the intro leading with the outlier-inflated mean.

## [0.3.10] — 2026-07-03

- Fix fatal `TypeError` on individual size pages (e.g. `/diamond-size/round/1-carat/`): `individual_body_html()` referenced an undefined `$copy`, passing `null` to `variation_note_html(array $copy)`. The already-fetched size-copy payload is now threaded through from `render()`.

## [0.3.9] — 2026-07-03

- Size methodology copy only names the retailer count when it is a credible breadth signal (>= 15); below that the sample size (`n`) stands alone. Mirrors `RETAILER_COUNT_DISCLOSURE_THRESHOLD` in the Z3 artefact builder.

## [0.3.8] — 2026-07-03

- Face-up comparison UX (spread checker, comparison hub, curated pair pages): distinct fuchsia vs slate stone colours, winner callout, colour legend, horizontal face-up area bars with mm² difference (diamdb-style).

## [0.3.7] — 2026-07-03

- Size tool overlays (comparison pages, spread chart, comparison hub, spread checker) use faceted diamond line-art; shared `size-faceted-overlay.js`; manifests embed `faceted_shapes` from Z3.

## [0.3.6] — 2026-07-03

- Stone spread checker at `/diamond-size/spread-checker/`: free-form carat + L×W inputs for two stones, real-market percentile rank, face-up overlay, Z3 manifest artefact; mega hub CTA.

## [0.3.5] — 2026-07-03

- Comparison tool hub at `/diamond-size/compare/`: interactive shape/carat picker, live face-up preview, popular curated links, Z3 manifest artefact; mega hub CTA.

## [0.3.4] — 2026-07-03

- Size pages: deduplicated intro copy, singular/plural retailer phrasing, dynamic spread headings, L×W-first ideal vs real, histogram distribution chart, stroke SVG outlines, hub table thumbnails, shape/mega hub FAQs, full-bleed intro section colours.

## [0.3.3] — 2026-07-02

- Stamp plugin version in `WP_DEBUG_LOG` lines (`LDN_Plugin::debug_log`), staging diagnostics panel, and wp-admin Tools status.

## [0.3.2] — 2026-07-02

- Fix PHP 8.1 warning on non-shape pages: initialise `individual` in prefetch bag and guard `section_value()` bag access.

## [0.3.1] — 2026-07-02

- Fix PHP 8.1 fatal on pricing pages: remove trait constants from `trait-ldn-content.php` (PHP 8.2+ only); constants remain on `LDN_Renderer`.

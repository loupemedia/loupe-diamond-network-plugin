# Changelog

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

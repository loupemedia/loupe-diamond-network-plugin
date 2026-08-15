# Changelog

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

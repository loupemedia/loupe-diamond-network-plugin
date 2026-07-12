# Changelog

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

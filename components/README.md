# LDN page components (CP53)

HTML builders for the price module live in **trait files** under this folder.
`LDN_Renderer` (`includes/class-ldn-renderer.php`) is the thin orchestrator: it
loads these traits, holds shared constants, and implements `render()` /
`render_head_content()`.

| Trait file | Responsibility |
|------------|----------------|
| `trait-ldn-chrome.php` | Brand tokens, page chrome CSS vars |
| `trait-ldn-head.php` | Canonical, Open Graph meta |
| `trait-ldn-charts.php` | Inline Plotly chart blocks |
| `trait-ldn-content.php` | Intro, stats, FAQ, static copy |
| `trait-ldn-navigation.php` | Breadcrumbs, freshness line |
| `trait-ldn-tables.php` | Ranking, carat ladder, colour/clarity grids |
| `trait-ldn-sections.php` | `render_section()` / `render_hero()` dispatch |
| `trait-ldn-schema-bridge.php` | FAQ/items payloads for JSON-LD |
| `trait-ldn-url.php` | URL builders, change-policy helpers |
| `trait-ldn-data.php` | Prefetch, profile, headline, currency helpers |
| `trait-ldn-homepage.php` | Standalone hub sections (`hub_stats`, `type_nav_links`, `popular_searches`, homepage H1/tagline) |

Level templates under `templates/level-*.php` delegate to the same renderer; per-level
customisation can diverge there without touching section builders.

**Standalone pricing homepages** (Diamond Price Exact, carat EMD): section ids and SEO IA — [`docs/strategy/standalone-site-homepages.md`](../../docs/strategy/standalone-site-homepages.md). Copy in profile `homepage` + `tagline`; colours/fonts only in `brand_tokens` / `page_chrome`.

Add new sections by extending `trait-ldn-sections.php` (or a new trait) and
listing the section id in the site's `page_structure` config — not by growing
the orchestrator.

# Changelog

## [0.3.3] — 2026-07-02

- Stamp plugin version in `WP_DEBUG_LOG` lines (`LDN_Plugin::debug_log`), staging diagnostics panel, and wp-admin Tools status.

## [0.3.2] — 2026-07-02

- Fix PHP 8.1 warning on non-shape pages: initialise `individual` in prefetch bag and guard `section_value()` bag access.

## [0.3.1] — 2026-07-02

- Fix PHP 8.1 fatal on pricing pages: remove trait constants from `trait-ldn-content.php` (PHP 8.2+ only); constants remain on `LDN_Renderer`.

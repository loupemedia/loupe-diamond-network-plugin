# Plugin translations

WordPress loads compiled catalogues from this folder for the `loupe-diamond-network` text domain.

## Extract template (no WP-CLI required)

From the repo root:

```bash
./scripts/ldn_extract_pot.sh
```

Writes `loupe-diamond-network.pot` (source strings from `includes/`, `components/`, `templates/`).

Requires GNU `xgettext` (`brew install gettext` on macOS).

Alternative when WP-CLI is available:

```bash
wp i18n make-pot loupe-diamond-network languages/loupe-diamond-network.pot \
  --domain=loupe-diamond-network
```

## Add a locale

### UK English (starter catalogue)

```bash
python scripts/ldn_build_en_gb_po.py
```

Refreshes `loupe-diamond-network-en_GB.po` from the `.pot`, applies Commonwealth spelling overrides (`Color` → `Colour`, etc.), and compiles `loupe-diamond-network-en_GB.mo`.

### Other locales

1. Copy the `.pot` to `loupe-diamond-network-{locale}.po` (e.g. `loupe-diamond-network-fr_FR.po`).
2. Translate strings (Poedit, GlotPress, etc.).
3. Compile: `msgfmt -o loupe-diamond-network-fr_FR.mo loupe-diamond-network-fr_FR.po`
4. Deploy `.mo` files with the plugin.

At runtime, `LDN_Locale::switch_for_context()` switches WordPress to the country's locale from the config bundle (price pages, calculator, and panel REST) and reloads this text domain. Locale restores automatically on `shutdown`.

See `docs/architecture/calculator-i18n.md` for rollout stages and hook points.

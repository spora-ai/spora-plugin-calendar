# spora-plugin-calendar

Spora plugin: calendar. The full reference (install, configuration,
per-tool parameters, development) has moved to the docs site:

**[docs.spora-ai.com/develop/plugins/reference/calendar](https://docs.spora-ai.com/develop/plugins/reference/calendar)**

See the docs site for the canonical reference. The README previously
in this repo has been migrated there.

## Translations

This plugin ships a German catalogue at [`locales/de.json`](locales/de.json),
declared through `translationsPaths()` in `src/CalendarPlugin.php`. The host
merges it under core's own catalogue, so a German install reads the plugin's
operator copy in German.

The **key is the English source string**, byte for byte — editing the English
in the source changes the key, so the old entry stops matching and the new
English has no entry. The gate is this repository's own
`tests/Unit/LocaleCatalogTest.php`: it fails on an empty translation, on a
translation identical to its key, and on a key the source no longer produces.

**Translated**

- the tool's display name — `Calendar`
- the `url`, `username`, `password` and `auth_method` setting descriptions
- the `plugin.json` description

The `http_timeout` setting's description is core's own string: core's
catalogue already translates it, and core wins a key collision, so this
plugin does not repeat it.

**Never translated** — these strings are read by the model, so the operator
sees exactly what the model sees:

- `#[Tool].description`
- `#[ToolOperation].description`
- `#[ToolParameter].description`
- every `#[ToolSetting].label` and its option labels

The approval prompts `describeAction()` returns are plain English sentences
that this catalogue does not carry, so they read English too.

See the [plugin author guide → Translations](https://docs.spora-ai.com/develop/plugins/author-guide/translations).

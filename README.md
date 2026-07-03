# CalendarPlugin

CalDAV calendar read/write for Spora agents.

**Status: placeholder** (`v0.1.0`). The real tool class is not in this repo
yet — it will be added in a follow-up release (`v0.2.0`) once the B1–B7
tool extraction lands in `spora-ai/spora-core`.

For now this package:

- Declares the `calendar` plugin slug (visible in `php bin/spora plugin:list`)
- Exposes an empty `tools()` hook (no tools contributed yet)
- Boots cleanly in a Spora install via `composer require spora-ai/spora-plugin-calendar:^0.1`

## Local development

```bash
composer install
./vendor/bin/pest
./vendor/bin/phpstan analyse --no-progress
./vendor/bin/php-cs-fixer fix --dry-run --diff
```

## License

MIT

<?php

declare(strict_types=1);

namespace Spora\Plugins\Calendar;

use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Calendar\Tools\CalDavCalendarTool;

/**
 * Calendar plugin entry point. Contributes the CalDavCalendarTool to agents
 * (list / get / create / edit / delete events on a CalDAV server).
 */
final class CalendarPlugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Calendar';
    }

    /** @return array<class-string<\Spora\Tools\ToolInterface>> */
    public function tools(): array
    {
        return [
            CalDavCalendarTool::class,
        ];
    }

    /**
     * Translation catalogues this plugin ships.
     *
     * Each directory holds one `<lang>.json` per language. Spora resolves
     * a string through **your** catalogue first, then the host's, then the
     * English literal itself, so shipping a catalogue is purely additive:
     * nothing breaks while `de.json` is missing or partial.
     *
     * Keys are the English source text — `de.json` reads
     * `{"Calendar": "Kalender"}`, not `{"tool": "Kalender"}`. That is what
     * lets the host's CI fail when a string is edited without being
     * re-translated: the key changes, the old entry orphans.
     *
     * **Not everything English belongs in here.** A string the model reads
     * is never translated — not `#[Tool].description`, not
     * `#[ToolParameter].description`, not `#[ToolSetting].label` or its
     * `options`, and not `ToolResult` content. What `locales/de.json`
     * holds is the operator-facing copy: every tool's `displayName`, every
     * `#[ToolSetting].description`, and the `plugin.json` description —
     * each a string only an operator ever reads.
     *
     * Needs a core that reads `translationsPaths()` — 0.31.0 and later. On
     * 0.30.x nothing calls the method, so the catalogue is silently
     * ignored and the copy stays English, which is the fallback every
     * layer already uses.
     *
     * @return string[]
     */
    public function translationsPaths(): array
    {
        return [
            __DIR__ . '/../locales',
        ];
    }
}

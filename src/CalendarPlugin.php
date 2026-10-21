<?php

declare(strict_types=1);

namespace Spora\Plugins\Calendar;

use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Calendar\Tools\CalDavCalendarTool;

/**
 * CalDAV calendar read/write for Spora agents.
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
     * The plugin's `locales/` directory — one `<lang>.json` per language.
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

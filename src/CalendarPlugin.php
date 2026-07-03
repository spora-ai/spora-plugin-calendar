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
}

<?php

declare(strict_types=1);

namespace Spora\Plugins\Calendar;

use Spora\Plugins\AbstractPlugin;

/**
 * Placeholder plugin entry point for the Calendar extraction (v0.1.0).
 *
 * The real tool class lands in a follow-up release. This file declares the
 * plugin and an empty hook surface so the framework can install, boot, and
 * inspect it before any tools are available.
 *
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
        return [];
    }
}

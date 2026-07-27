<?php

declare(strict_types=1);

use Spora\Plugins\Calendar\Tools\CalDavCalendarTool;
use Spora\Tools\Attributes\ToolParameter;

/**
 * Per-op `required[]` binding tests for CalDavCalendarTool.
 *
 * Reads `#[ToolParameter]` constructor arguments via reflection and
 * asserts the per-op required list — does NOT instantiate the attribute,
 * so the test sidesteps the `bool|array $required` signature change in
 * spora-core main that hasn't shipped yet. Once spora-core releases the
 * new signature AND the plugin's `composer.json` bumps its constraint,
 * replace the reflection with a proper
 * `ToolParameterSchemaBuilder::build(CalDavCalendarTool::class)`
 * round-trip (the in-tree spora-core tests show the pattern).
 */
function calendarToolParameterArgs(string $name): array
{
    $reflection = new ReflectionClass(CalDavCalendarTool::class);
    foreach ($reflection->getAttributes(ToolParameter::class) as $attribute) {
        $args = $attribute->getArguments();
        if (($args['name'] ?? null) === $name) {
            return $args;
        }
    }

    throw new RuntimeException("ToolParameter '{$name}' not declared on " . CalDavCalendarTool::class);
}

it('binds start_date to list_events only', function () {
    expect(calendarToolParameterArgs('start_date')['required'])->toBe(['list_events']);
});

it('binds end_date to list_events only', function () {
    expect(calendarToolParameterArgs('end_date')['required'])->toBe(['list_events']);
});

it('binds event_uri to get_event, edit_event, delete_event', function () {
    expect(calendarToolParameterArgs('event_uri')['required'])
        ->toBe(['get_event', 'edit_event', 'delete_event']);
});

it('binds etag to edit_event only', function () {
    expect(calendarToolParameterArgs('etag')['required'])->toBe(['edit_event']);
});

it('binds summary to create_event only', function () {
    expect(calendarToolParameterArgs('summary')['required'])->toBe(['create_event']);
});

it('keeps description, location, timezone, all_day at required: false', function () {
    expect(calendarToolParameterArgs('description')['required'])->toBeFalse();
    expect(calendarToolParameterArgs('location')['required'])->toBeFalse();
    expect(calendarToolParameterArgs('timezone')['required'])->toBeFalse();
    expect(calendarToolParameterArgs('all_day')['required'])->toBeFalse();
});
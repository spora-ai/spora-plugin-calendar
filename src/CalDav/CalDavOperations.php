<?php

declare(strict_types=1);

namespace Spora\Plugins\Calendar\CalDav;

use DateTimeImmutable;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Public CRUD entry points for the CalDAV calendar operations. Thin facade
 * over {@see CalDavOperationHelpers} — each method runs the input parsing,
 * config loading, and HTTP dispatch through the helper and returns the
 * resulting ToolResult.
 */
final class CalDavOperations
{
    public function __construct(private readonly CalDavOperationHelpers $helpers) {}

    public function listEvents(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $dates = $this->helpers->resolveListEventDates(
            (string) ($arguments['start_date'] ?? ''),
            (string) ($arguments['end_date'] ?? ''),
        );
        if ($dates instanceof ToolResult) {
            return $dates;
        }

        $config = $this->helpers->loadBaseConfig($agentId, $userId);
        if ($config instanceof ToolResult) {
            return $config;
        }

        return $this->helpers->dispatchListEventsRequest($dates, $config);
    }

    public function getEvent(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $eventUri = trim((string) ($arguments['event_uri'] ?? ''));
        if ($eventUri === '') {
            return $this->helpers->getEventError('event_uri');
        }

        $config = $this->helpers->loadBaseConfig($agentId, $userId);
        if ($config instanceof ToolResult) {
            return $config;
        }

        return $this->helpers->dispatchGetEventRequest($eventUri, $config);
    }

    public function createEvent(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $prepared = $this->prepareCreate($arguments, $agentId, $userId);
        if ($prepared instanceof ToolResult) {
            return $prepared;
        }

        return $this->helpers->dispatchCreateEventRequest(
            $prepared['inputs'],
            $prepared['dates'],
            $prepared['config'],
            $agentId,
        );
    }

    public function editEvent(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $inputs = $this->helpers->parseEditInputs($arguments);
        if ($inputs instanceof ToolResult) {
            return $inputs;
        }

        $config = $this->helpers->loadBaseConfig($agentId, $userId);
        if ($config instanceof ToolResult) {
            return $config;
        }

        $eventUri = $this->helpers->resolveEventUri($inputs['eventUri'], $config['url']);
        $existing = $this->helpers->fetchExistingEvent($eventUri, $config);
        if ($existing instanceof ToolResult) {
            return $existing;
        }

        $updates = $this->helpers->buildEditUpdates($arguments, $existing, $inputs['timezone'], $inputs['allDay']);
        if ($updates instanceof ToolResult) {
            return $updates;
        }

        return $this->helpers->dispatchEditEventRequest($eventUri, $inputs, $updates, $config, $agentId);
    }

    public function deleteEvent(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $eventUri = trim((string) ($arguments['event_uri'] ?? ''));
        if ($eventUri === '') {
            return $this->helpers->getEventError('event_uri');
        }

        $config = $this->helpers->loadBaseConfig($agentId, $userId);
        if ($config instanceof ToolResult) {
            return $config;
        }

        return $this->helpers->dispatchDeleteRequest($arguments, $eventUri, $config);
    }

    /** @return array{inputs: array{summary: string, start_date: string, end_date: string, description: string, location: string, timezone: string, allDay: bool}, dates: array{start: DateTimeImmutable, end: DateTimeImmutable}, config: array{url: string, username: string, password: string, settings: array<string, mixed>}}|ToolResult */
    private function prepareCreate(array $arguments, int $agentId, ?int $userId): array|ToolResult
    {
        $inputs = $this->helpers->parseCreateInputs($arguments);
        if ($inputs instanceof ToolResult) {
            return $inputs;
        }
        $dates = $this->helpers->parseCreateDates($inputs);
        if ($dates instanceof ToolResult) {
            return $dates;
        }
        $config = $this->helpers->loadBaseConfig($agentId, $userId);
        if ($config instanceof ToolResult) {
            return $config;
        }
        return ['inputs' => $inputs, 'dates' => $dates, 'config' => $config];
    }
}

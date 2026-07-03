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
        $parsed = $this->parseCreateData($arguments);
        if ($parsed instanceof ToolResult) {
            return $parsed;
        }

        $config = $this->helpers->loadBaseConfig($agentId, $userId);
        if ($config instanceof ToolResult) {
            return $config;
        }

        return $this->helpers->dispatchCreateEventRequest(
            $parsed['inputs'],
            $parsed['dates'],
            $config,
            $agentId,
        );
    }

    public function editEvent(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $ctx = $this->loadEditContext($arguments, $agentId, $userId);
        if ($ctx instanceof ToolResult) {
            return $ctx;
        }

        return $this->executeEdit($arguments, $ctx, $agentId);
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

    /** @return array{inputs: array{summary: string, start_date: string, end_date: string, description: string, location: string, timezone: string, allDay: bool}, dates: array{start: DateTimeImmutable, end: DateTimeImmutable}}|ToolResult */
    private function parseCreateData(array $arguments): array|ToolResult
    {
        $inputs = $this->helpers->parseCreateInputs($arguments);
        if ($inputs instanceof ToolResult) {
            return $inputs;
        }

        $dates = $this->helpers->parseCreateDates($inputs);
        if ($dates instanceof ToolResult) {
            return $dates;
        }

        return ['inputs' => $inputs, 'dates' => $dates];
    }

    /** @return array{eventUri: string, inputs: array{eventUri: string, etag: string, timezone: string, allDay: bool}, config: array{url: string, username: string, password: string, settings: array<string, mixed>}}|ToolResult */
    private function loadEditContext(array $arguments, int $agentId, ?int $userId): array|ToolResult
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

        return ['eventUri' => $eventUri, 'inputs' => $inputs, 'config' => $config];
    }

    /**
     * @param array{eventUri: string, inputs: array{eventUri: string, etag: string, timezone: string, allDay: bool}, config: array{url: string, username: string, password: string, settings: array<string, mixed>}} $ctx
     * @return array{eventUri: string, inputs: array{eventUri: string, etag: string, timezone: string, allDay: bool}, updates: array{uid: ?string, summary: string, start: DateTimeImmutable, end: DateTimeImmutable, description: string, location: string}, config: array{url: string, username: string, password: string, settings: array<string, mixed>}}|ToolResult
     */
    private function loadEditPayload(array $arguments, array $ctx): array|ToolResult
    {
        $existing = $this->helpers->fetchExistingEvent($ctx['eventUri'], $ctx['config']);
        if ($existing instanceof ToolResult) {
            return $existing;
        }

        $updates = $this->helpers->buildEditUpdates(
            $arguments,
            $existing,
            $ctx['inputs']['timezone'],
            $ctx['inputs']['allDay'],
        );
        if ($updates instanceof ToolResult) {
            return $updates;
        }

        return [
            'eventUri' => $ctx['eventUri'],
            'inputs'   => $ctx['inputs'],
            'updates'  => $updates,
            'config'   => $ctx['config'],
        ];
    }

    /**
     * @param array{eventUri: string, inputs: array{eventUri: string, etag: string, timezone: string, allDay: bool}, config: array{url: string, username: string, password: string, settings: array<string, mixed>}} $ctx
     */
    private function executeEdit(array $arguments, array $ctx, int $agentId): ToolResult
    {
        $payload = $this->loadEditPayload($arguments, $ctx);
        if ($payload instanceof ToolResult) {
            return $payload;
        }

        return $this->helpers->dispatchEditEventRequest(
            $payload['eventUri'],
            $payload['inputs'],
            $payload['updates'],
            $payload['config'],
            $agentId,
        );
    }
}

<?php

declare(strict_types=1);

namespace Spora\Plugins\Calendar\CalDav;

use DateTimeImmutable;
use DateTimeZone;
use Spora\Plugins\Calendar\Tools\CalDavCalendarTool;
use Spora\Services\ToolConfigService;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Per-operation implementation for {@see CalDavOperations}. Holds the
 * input/date parsing, base-config loading, and HTTP-dispatch helpers.
 */
final class CalDavOperationHelpers
{
    private const ERR_CONFIG_INCOMPLETE  = 'CalDAV configuration is incomplete or missing.';
    private const ERR_EVENT_NOT_FOUND    = 'Event not found.';
    private const ERR_MISSING_EVENT_URI  = 'Missing required parameter: event_uri';
    private const ERR_MISSING_ETAG       = 'Missing required parameter: etag (required for safe updates)';
    private const ERR_MISSING_DATES      = 'Missing start_date or end_date parameters.';
    private const ERR_MISSING_CREATE_ARGS = 'Missing required parameters: summary, start_date, or end_date';
    private const ERR_INVALID_DATE       = 'Invalid date format provided. Must be ISO-8601.';
    private const ERR_END_BEFORE_START   = 'end_date must be after start_date.';

    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly CalDavClient $client,
        private readonly IcsBuilder $builder,
        private readonly IcsParser $parser,
        private readonly CalDavResponseMapper $mapper,
    ) {}

    public function getEventError(string $field): ToolResult
    {
        return new ToolResult(false, "Missing required parameter: {$field}");
    }

    public function resolveEventUri(string $eventUri, string $baseUrl): string
    {
        return $this->client->resolveEventUri($eventUri, $baseUrl);
    }

    /** @param array{url: string, username: string, password: string, settings: array<string, mixed>} $config */
    public function dispatchDeleteRequest(array $arguments, string $eventUri, array $config): ToolResult
    {
        $eventUri = $this->client->resolveEventUri($eventUri, $config['url']);
        $etag = $this->client->normalizeEtag(trim((string) ($arguments['etag'] ?? '')));

        $headers = [];
        if ($etag !== '') {
            $headers['If-Match'] = $etag;
        }
        $requestOptions = [
            'headers'    => $headers,
            'auth_basic' => [$config['username'], $config['password']],
            'timeout'    => $this->client->effectiveTimeout($config['settings']),
        ];

        return $this->mapper->runHttp(
            'DELETE',
            $eventUri,
            $requestOptions,
            fn(ResponseInterface $r) => $this->mapper->handleDeleteResponse(),
            'Failed to delete CalDAV event',
            fn(ResponseInterface $r, int $code) => $this->mapper->handlePutError($code),
        );
    }

    /** @return array{0: string, 1: string}|ToolResult */
    public function resolveListEventDates(string $startDateStr, string $endDateStr): array|ToolResult
    {
        if ($startDateStr === '' || $endDateStr === '') {
            return new ToolResult(false, self::ERR_MISSING_DATES);
        }
        return $this->parseDateRange($startDateStr, $endDateStr);
    }

    /** @param array{0: string, 1: string} $dates
     *  @param array{url: string, username: string, password: string, settings: array<string, mixed>} $config */
    public function dispatchListEventsRequest(array $dates, array $config): ToolResult
    {
        $requestOptions = [
            'headers'    => ['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8'],
            'auth_basic' => [$config['username'], $config['password']],
            'body'       => $this->buildReportXml($dates[0], $dates[1]),
            'timeout'    => $this->client->effectiveTimeout($config['settings']),
        ];

        return $this->mapper->runHttp(
            'REPORT',
            $config['url'],
            $requestOptions,
            fn(ResponseInterface $r) => $this->parser->parseListResponse($r->getContent()),
            'Failed to fetch CalDAV calendar',
        );
    }

    /** @param array{url: string, username: string, password: string, settings: array<string, mixed>} $config */
    public function dispatchGetEventRequest(string $eventUri, array $config): ToolResult
    {
        $resolvedUri = $this->client->resolveEventUri($eventUri, $config['url']);
        $requestOptions = [
            'headers'    => ['Accept' => 'text/calendar'],
            'auth_basic' => [$config['username'], $config['password']],
            'timeout'    => $this->client->effectiveTimeout($config['settings']),
        ];

        return $this->mapper->runHttp(
            'GET',
            $resolvedUri,
            $requestOptions,
            function (ResponseInterface $r) use ($resolvedUri) {
                return $this->mapper->handleGetResponse($r, $resolvedUri, fn(string $ics, string $uri, ?string $etag) => $this->parser->parseEventForGet($ics, $uri, $etag));
            },
            'Failed to fetch CalDAV event',
            fn(ResponseInterface $r, int $code) => $this->mapper->handleGetError($code),
        );
    }

    /** @param array{summary: string, start_date: string, end_date: string, description: string, location: string, timezone: string, allDay: bool} $inputs
     *  @param array{start: DateTimeImmutable, end: DateTimeImmutable} $dates
     *  @param array{url: string, username: string, password: string, settings: array<string, mixed>} $config */
    public function dispatchCreateEventRequest(array $inputs, array $dates, array $config, int $agentId): ToolResult
    {
        $eventUri  = rtrim($config['url'], '/') . '/' . ltrim($this->builder->generateEventFilename($inputs['summary'], $dates['start']), '/');
        $icsContent = $this->builder->buildIcs(
            $this->builder->generateUid($agentId),
            $inputs['summary'],
            $inputs['description'],
            $inputs['location'],
            new EventDateRange(
                start: $dates['start'],
                end: $dates['end'],
                timezone: $inputs['timezone'],
                allDay: $inputs['allDay'],
            ),
        );

        $requestOptions = [
            'headers'    => ['Content-Type' => 'text/calendar; charset=utf-8'],
            'auth_basic' => [$config['username'], $config['password']],
            'body'       => $icsContent,
            'timeout'    => $this->client->effectiveTimeout($config['settings']),
        ];

        return $this->mapper->runHttp(
            'PUT',
            $eventUri,
            $requestOptions,
            fn(ResponseInterface $r) => $this->mapper->handleCreateResponse($r, $eventUri, $inputs['summary']),
            'Failed to create CalDAV event',
            fn(ResponseInterface $r, int $code) => $this->mapper->handleCreateError($code),
        );
    }

    /** @param array{eventUri: string, etag: string, timezone: string, allDay: bool} $inputs
     *  @param array{uid: ?string, summary: string, start: DateTimeImmutable, end: DateTimeImmutable, description: string, location: string} $updates
     *  @param array{url: string, username: string, password: string, settings: array<string, mixed>} $config */
    public function dispatchEditEventRequest(string $eventUri, array $inputs, array $updates, array $config, int $agentId): ToolResult
    {
        $icsContent = $this->builder->buildIcs(
            $updates['uid'] ?? $this->builder->generateUid($agentId),
            $updates['summary'],
            $updates['description'],
            $updates['location'],
            new EventDateRange(
                start: $updates['start'],
                end: $updates['end'],
                timezone: $inputs['timezone'],
                allDay: $inputs['allDay'],
            ),
        );

        $requestOptions = [
            'headers'    => ['Content-Type' => 'text/calendar; charset=utf-8', 'If-Match' => $inputs['etag']],
            'auth_basic' => [$config['username'], $config['password']],
            'body'       => $icsContent,
            'timeout'    => $this->client->effectiveTimeout($config['settings']),
        ];

        return $this->mapper->runHttp(
            'PUT',
            $eventUri,
            $requestOptions,
            fn(ResponseInterface $r) => $this->mapper->handlePutResponse($r, $eventUri, $updates['summary']),
            'Failed to update CalDAV event',
            fn(ResponseInterface $r, int $code) => $this->mapper->handlePutError($code),
        );
    }

    /** @return array{eventUri: string, etag: string, timezone: string, allDay: bool}|ToolResult */
    public function parseEditInputs(array $arguments): array|ToolResult
    {
        $eventUri = trim((string) ($arguments['event_uri'] ?? ''));
        if ($eventUri === '') {
            return new ToolResult(false, self::ERR_MISSING_EVENT_URI);
        }
        $etag = $this->client->normalizeEtag(trim((string) ($arguments['etag'] ?? '')));
        if ($etag === '') {
            return new ToolResult(false, self::ERR_MISSING_ETAG);
        }
        return [
            'eventUri' => $eventUri,
            'etag'     => $etag,
            'timezone' => trim((string) ($arguments['timezone'] ?? '')),
            'allDay'   => (bool) ($arguments['all_day'] ?? false),
        ];
    }

    /** @return array{summary: string, start_date: string, end_date: string, description: string, location: string, timezone: string, allDay: bool}|ToolResult */
    public function parseCreateInputs(array $arguments): array|ToolResult
    {
        $summary      = trim((string) ($arguments['summary'] ?? ''));
        $startDateStr = (string) ($arguments['start_date'] ?? '');
        $endDateStr   = (string) ($arguments['end_date'] ?? '');
        if ($summary === '' || $startDateStr === '' || $endDateStr === '') {
            return new ToolResult(false, self::ERR_MISSING_CREATE_ARGS);
        }
        return [
            'summary'     => $summary,
            'start_date'  => $startDateStr,
            'end_date'    => $endDateStr,
            'description' => trim((string) ($arguments['description'] ?? '')),
            'location'    => trim((string) ($arguments['location'] ?? '')),
            'timezone'    => trim((string) ($arguments['timezone'] ?? '')),
            'allDay'      => (bool) ($arguments['all_day'] ?? false),
        ];
    }

    /** @param array{summary: string, start_date: string, end_date: string, description: string, location: string, timezone: string, allDay: bool} $inputs
     *  @return array{start: DateTimeImmutable, end: DateTimeImmutable}|ToolResult */
    public function parseCreateDates(array $inputs): array|ToolResult
    {
        try {
            $start = $this->builder->parseEventDate($inputs['start_date'], $inputs['timezone'], $inputs['allDay']);
            $end   = $this->builder->parseEventDate($inputs['end_date'], $inputs['timezone'], $inputs['allDay']);
        } catch (Throwable $e) {
            return new ToolResult(false, 'Invalid date format: ' . $e->getMessage());
        }
        if ($end <= $start) {
            return new ToolResult(false, self::ERR_END_BEFORE_START);
        }
        return ['start' => $start, 'end' => $end];
    }

    /** @return array{url: string, username: string, password: string, settings: array<string, mixed>}|ToolResult */
    public function loadBaseConfig(int $agentId, ?int $userId): array|ToolResult
    {
        $settings = $this->configService->getEffectiveSettings(CalDavCalendarTool::class, $agentId, $userId);
        $url      = rtrim((string) ($settings['core.caldav.url']      ?? ''), '/');
        $username = (string) ($settings['core.caldav.username'] ?? '');
        $password = (string) ($settings['core.caldav.password'] ?? '');
        if ($url === '' || $username === '' || $password === '') {
            return new ToolResult(false, self::ERR_CONFIG_INCOMPLETE);
        }
        return ['url' => $url, 'username' => $username, 'password' => $password, 'settings' => $settings];
    }

    /** @return array{0: string, 1: string}|ToolResult */
    public function parseDateRange(string $startDateStr, string $endDateStr): array|ToolResult
    {
        try {
            $start = new DateTimeImmutable($startDateStr);
            $end   = new DateTimeImmutable($endDateStr);
        } catch (Throwable) {
            return new ToolResult(false, self::ERR_INVALID_DATE);
        }
        $startFormatted = $start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $endFormatted   = $end->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        return [$startFormatted, $endFormatted];
    }

    public function buildReportXml(string $startFormatted, string $endFormatted): string
    {
        return <<<XML
<?xml version="1.0" encoding="utf-8" ?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
    <d:prop>
        <d:getetag />
        <c:calendar-data />
    </d:prop>
    <c:filter>
        <c:comp-filter name="VCALENDAR">
            <c:comp-filter name="VEVENT">
                <c:time-range start="{$startFormatted}" end="{$endFormatted}"/>
            </c:comp-filter>
        </c:comp-filter>
    </c:filter>
</c:calendar-query>
XML;
    }

    /** @param array{url: string, username: string, password: string, settings: array<string, mixed>} $config
     *  @return array<string, mixed>|ToolResult */
    public function fetchExistingEvent(string $eventUri, array $config): array|ToolResult
    {
        $requestOptions = [
            'headers'    => ['Accept' => 'text/calendar'],
            'auth_basic' => [$config['username'], $config['password']],
            'timeout'    => $this->client->effectiveTimeout($config['settings']),
        ];

        $response = $this->client->request('GET', $eventUri, $requestOptions);
        $statusCode = $response->getStatusCode();
        if ($statusCode === 404) {
            return new ToolResult(false, self::ERR_EVENT_NOT_FOUND);
        }
        if ($statusCode >= 400) {
            return new ToolResult(false, 'Failed to fetch existing event: HTTP ' . $statusCode);
        }
        return $this->parser->parseEventForEdit($response->getContent());
    }

    /** @param array<string, mixed> $existingData
     *  @return array{uid: ?string, summary: string, start: DateTimeImmutable, end: DateTimeImmutable, description: string, location: string}|ToolResult */
    public function buildEditUpdates(array $arguments, array $existingData, string $timezone, bool $allDay): array|ToolResult
    {
        $dates = $this->resolveEditDates($arguments, $existingData, $timezone, $allDay);
        if ($dates instanceof ToolResult) {
            return $dates;
        }
        if ($dates['end'] <= $dates['start']) {
            return new ToolResult(false, self::ERR_END_BEFORE_START);
        }
        return [
            'uid'         => $existingData['uid'] ?: null,
            'summary'     => !empty($arguments['summary']) ? trim((string) $arguments['summary']) : (string) $existingData['summary'],
            'start'       => $dates['start'],
            'end'         => $dates['end'],
            'description' => !empty($arguments['description']) ? trim((string) $arguments['description']) : (string) $existingData['description'],
            'location'    => !empty($arguments['location']) ? trim((string) $arguments['location']) : (string) $existingData['location'],
        ];
    }

    /** @param array<string, mixed> $arguments
     *  @param array<string, mixed> $existingData
     *  @return array{start: DateTimeImmutable, end: DateTimeImmutable}|ToolResult */
    public function resolveEditDates(array $arguments, array $existingData, string $timezone, bool $allDay): array|ToolResult
    {
        try {
            $start = !empty($arguments['start_date'])
                ? $this->builder->parseEventDate((string) $arguments['start_date'], $timezone, $allDay)
                : $existingData['dtstart'];
            $end = !empty($arguments['end_date'])
                ? $this->builder->parseEventDate((string) $arguments['end_date'], $timezone, $allDay)
                : $existingData['dtend'];
        } catch (Throwable $e) {
            return new ToolResult(false, 'Invalid date format: ' . $e->getMessage());
        }
        if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
            return new ToolResult(false, 'Failed to parse existing event dates. Fetch the latest event details and verify DTSTART/DTEND are present.');
        }
        return ['start' => $start, 'end' => $end];
    }
}

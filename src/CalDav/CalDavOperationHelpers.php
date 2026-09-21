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
    private const ERR_MISSING_DATES      = 'Missing start_date or end_date parameters.';
    private const ERR_MISSING_CREATE_ARGS = 'Missing required parameters: summary, start_date, or end_date';
    private const ERR_INVALID_DATE       = 'Invalid date format provided. Must be ISO-8601.';
    private const ERR_END_BEFORE_START   = 'end_date must be after start_date.';
    private const ERR_SUMMARY_TOO_LONG   = 'summary must be 255 characters or fewer.';
    private const MAX_SUMMARY_LENGTH     = 255;

    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly CalDavClient $client,
        private readonly IcsBuilder $builder,
        private readonly IcsParser $parser,
        private readonly CalDavResponseMapper $mapper,
        private readonly CalDavXmlBuilder $xmlBuilder = new CalDavXmlBuilder(),
        private readonly CalDavRequestOptionsBuilder $requestBuilder = new CalDavRequestOptionsBuilder(),
    ) {}

    public function getEventError(string $field): ToolResult
    {
        return new ToolResult(false, "Missing required parameter: {$field}", [
            'status' => 'error',
            'action' => 'get_event',
            'reason' => 'missing_parameter',
            'field'  => $field,
        ]);
    }

    /**
     * E3/O1: uniform error envelope. Use this for every validation/
     * parse failure so callers can branch on `data.status === 'error'`
     * instead of parsing the text content.
     */
    public function errorResult(string $action, string $message, ?string $reason = null, ?string $hint = null, ?string $field = null): ToolResult
    {
        $data = [
            'status' => 'error',
            'action' => $action,
        ];
        if ($reason !== null) {
            $data['reason'] = $reason;
        }
        if ($hint  !== null) {
            $data['hint']   = $hint;
        }
        if ($field !== null) {
            $data['field']  = $field;
        }
        return new ToolResult(false, $message, $data);
    }

    public function resolveEventUri(string $eventUri, string $baseUrl): string
    {
        return $this->client->resolveEventUri($eventUri, $baseUrl);
    }

    /** @param array{url: string, username: string, password: string, authMethod: string, settings: array<string, mixed>} $config */
    public function dispatchDeleteRequest(array $arguments, string $eventUri, array $config): ToolResult
    {
        $eventUri = $this->client->resolveEventUri($eventUri, $config['url']);
        $etag = $this->client->normalizeEtag(trim((string) ($arguments['etag'] ?? '')));

        $headers = [];
        if ($etag !== '') {
            $headers['If-Match'] = $etag;
        }
        $requestOptions = $this->requestBuilder->buildRequestOptions($config, $headers, null, $this->client->effectiveTimeout($config["settings"]));

        return $this->mapper->runHttp(
            'DELETE',
            $eventUri,
            $requestOptions,
            fn(ResponseInterface $r) => $this->mapper->handleDeleteResponse($eventUri),
            'Failed to delete CalDAV event',
            fn(ResponseInterface $r, int $code) => $this->mapper->handlePutError($code),
        );
    }

    /** @return array{0: string, 1: string}|ToolResult */
    public function resolveListEventDates(string $startDateStr, string $endDateStr): array|ToolResult
    {
        if ($startDateStr === '' || $endDateStr === '') {
            return $this->errorResult('list_events', self::ERR_MISSING_DATES, 'missing_parameter', null, $startDateStr === '' ? 'start_date' : 'end_date');
        }
        return $this->parseDateRange($startDateStr, $endDateStr);
    }

    /** @param array{0: string, 1: string} $dates
     *  @param array{url: string, username: string, password: string, authMethod: string, settings: array<string, mixed>} $config */
    public function dispatchListEventsRequest(array $dates, array $config): ToolResult
    {
        $requestOptions = $this->requestBuilder->buildRequestOptions(
            $config,
            ['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8'],
            $this->xmlBuilder->buildReportXml($dates[0], $dates[1]),
            $this->client->effectiveTimeout($config['settings']),
        );

        return $this->mapper->runHttp(
            'REPORT',
            $config['url'],
            $requestOptions,
            fn(ResponseInterface $r) => $this->parser->parseListResponse($r->getContent()),
            'Failed to fetch CalDAV calendar',
        );
    }

    /** E2: PROPFIND Depth: 1 against the configured URL to enumerate sibling
     *  calendars. Useful when operators want to switch the active calendar.
     *  @param array{url: string, username: string, password: string, authMethod: string, settings: array<string, mixed>} $config */
    public function dispatchListCalendarsRequest(array $config): ToolResult
    {
        $requestOptions = $this->requestBuilder->buildRequestOptions(
            $config,
            ['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8'],
            $this->xmlBuilder->buildPropfindXml(),
            $this->client->effectiveTimeout($config['settings']),
        );

        return $this->mapper->runHttp(
            'PROPFIND',
            $config['url'],
            $requestOptions,
            fn(ResponseInterface $r) => $this->parser->parseCalendarListResponse($r->getContent()),
            'Failed to list CalDAV calendars',
        );
    }

    /** @param array{url: string, username: string, password: string, authMethod: string, settings: array<string, mixed>} $config */
    public function dispatchGetEventRequest(string $eventUri, array $config): ToolResult
    {
        $resolvedUri = $this->client->resolveEventUri($eventUri, $config['url']);
        $requestOptions = $this->requestBuilder->buildRequestOptions($config, ["Accept" => "text/calendar"], null, $this->client->effectiveTimeout($config["settings"]));

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
     *  @param array{url: string, username: string, password: string, authMethod: string, settings: array<string, mixed>} $config */
    public function dispatchCreateEventRequest(array $inputs, array $dates, array $config, int $agentId): ToolResult
    {
        $eventUri  = rtrim($config['url'], '/') . '/' . ltrim($this->builder->generateEventFilename($inputs['summary'], $dates['start']), '/');
        $uid       = $this->builder->generateUid($agentId);
        $icsContent = $this->builder->buildIcs(
            $uid,
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

        $requestOptions = $this->requestBuilder->buildRequestOptions(
            $config,
            // If-None-Match: * makes the create idempotent — a retry against
            // a server that already accepted the first PUT will not overwrite
            // or duplicate. RFC 4791 §5.3.2.
            [
                'Content-Type'  => 'text/calendar; charset=utf-8',
                'If-None-Match' => '*',
            ],
            $icsContent,
            $this->client->effectiveTimeout($config['settings']),
        );

        return $this->mapper->runHttp(
            'PUT',
            $eventUri,
            $requestOptions,
            fn(ResponseInterface $r) => $this->mapper->handleCreateResponse($r, $eventUri, $inputs['summary'], $uid),
            'Failed to create CalDAV event',
            fn(ResponseInterface $r, int $code) => $this->mapper->handleCreateError($code),
        );
    }

    /** @param array{eventUri: string, etag: string, timezone: string, allDay: bool} $inputs
     *  @param array{uid: ?string, summary: string, start: DateTimeImmutable, end: DateTimeImmutable, description: string, location: string} $updates
     *  @param array{url: string, username: string, password: string, authMethod: string, settings: array<string, mixed>} $config */
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

        $requestOptions = $this->requestBuilder->buildRequestOptions(
            $config,
            ['Content-Type' => 'text/calendar; charset=utf-8', 'If-Match' => $inputs['etag']],
            $icsContent,
            $this->client->effectiveTimeout($config['settings']),
        );

        return $this->mapper->runHttp(
            'PUT',
            $eventUri,
            $requestOptions,
            fn(ResponseInterface $r) => $this->mapper->handlePutResponse($r, $eventUri, $updates['summary']),
            'Failed to update CalDAV event',
            fn(ResponseInterface $r, int $code) => $this->mapper->handlePutError($code),
        );
    }

    /**
     * Edit inputs are accepted with or without an ETag. If the caller did
     * not supply one, OR supplied a non-RFC-7232 placeholder like
     * "initial" / "none", the edit flow reuses the ETag that comes back
     * from the GET it already does to merge unchanged fields
     * (RFC 7232 §4.3.1). The GET is issued regardless, so the
     * convenience mode is free of cost.
     *
     * @return array{eventUri: string, etag: string, timezone: string, allDay: bool}|ToolResult
     */
    public function parseEditInputs(array $arguments): array|ToolResult
    {
        $eventUri = trim((string) ($arguments['event_uri'] ?? ''));
        if ($eventUri === '') {
            return $this->errorResult('edit_event', self::ERR_MISSING_EVENT_URI, 'missing_parameter', null, 'event_uri');
        }
        $candidate = $this->client->normalizeEtag(trim((string) ($arguments['etag'] ?? '')));
        return [
            'eventUri' => $eventUri,
            // Trusted ETag goes through; anything else is replaced by the
            // server-fetched one in loadEditPayload().
            'etag'     => $this->client->isTrustedEtag($candidate) ? $candidate : '',
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
            return $this->errorResult('create_event', self::ERR_MISSING_CREATE_ARGS, 'missing_parameter');
        }
        if (strlen($summary) > self::MAX_SUMMARY_LENGTH) {
            return $this->errorResult('create_event', self::ERR_SUMMARY_TOO_LONG, 'summary_too_long', 'Shorten the summary and retry.', 'summary');
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
        // B2 mirror: for non-all_day requests, expand bare YYYY-MM-DD
        // inputs to a full-day range. start_date=YYYY-MM-DD → T00:00:00,
        // end_date=YYYY-MM-DD → T23:59:59, so a single-day timed request
        // covers the whole day. All-day requests leave the input alone.
        $startStr = $inputs['allDay']
            ? $inputs['start_date']
            : $this->requestBuilder->expandDateOnly($inputs['start_date'], false);
        $endStr   = $inputs['allDay']
            ? $inputs['end_date']
            : $this->requestBuilder->expandDateOnly($inputs['end_date'], true);
        try {
            $start = $this->builder->parseEventDate($startStr, $inputs['timezone'], $inputs['allDay']);
            $end   = $this->builder->parseEventDate($endStr, $inputs['timezone'], $inputs['allDay']);
        } catch (Throwable $e) {
            return $this->errorResult('create_event', 'Invalid date format: ' . $e->getMessage(), 'invalid_date', 'Use ISO-8601 (e.g. "2026-09-22T09:00:00") or YYYY-MM-DD for all_day events.');
        }
        // All-day events accept start == end (a single-day event) — the
        // builder bumps DTEND by one day to satisfy RFC 5545 §3.6.1.
        // Timed events still require strict end > start.
        $strictEnd = $inputs['allDay'] ? $end < $start : $end <= $start;
        if ($strictEnd) {
            return $this->errorResult('create_event', self::ERR_END_BEFORE_START, 'end_before_start');
        }
        return ['start' => $start, 'end' => $end];
    }

    /** @return array{url: string, username: string, password: string, authMethod: string, settings: array<string, mixed>}|ToolResult */
    public function loadBaseConfig(int $agentId, ?int $userId): array|ToolResult
    {
        $settings = $this->configService->getEffectiveSettings(CalDavCalendarTool::class, $agentId, $userId);
        $url      = rtrim((string) ($settings['url']      ?? ''), '/');
        $username = (string) ($settings['username'] ?? '');
        $password = (string) ($settings['password'] ?? '');
        if ($url === '' || $username === '' || $password === '') {
            return $this->errorResult('*', self::ERR_CONFIG_INCOMPLETE, 'config_incomplete', 'Configure url, username, and password in the tool settings.');
        }
        $authMethod = (string) ($settings['auth_method'] ?? CalDavClient::AUTH_AUTO);
        return [
            'url'        => $url,
            'username'   => $username,
            'password'   => $password,
            'authMethod' => $authMethod,
            'settings'   => $settings,
        ];
    }

    /**
     * Build the Symfony HttpClient options array shared by every
     * dispatch method. Centralising it keeps `auth_method`,
     * `auth_basic`, and the timeout in lockstep — the digest-retry
     * path in {@see CalDavClient} reads `auth_method` to decide
     * whether to send Basic preemptively or wait for a 401 challenge.
     *
     * @param array{url: string, username: string, password: string, authMethod: string, settings: array<string, mixed>} $config
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */

    /** @return array{0: string, 1: string}|ToolResult */
    public function parseDateRange(string $startDateStr, string $endDateStr): array|ToolResult
    {
        try {
            $start = new DateTimeImmutable($this->requestBuilder->expandDateOnly($startDateStr, false));
            $end   = new DateTimeImmutable($this->requestBuilder->expandDateOnly($endDateStr, true));
        } catch (Throwable) {
            return $this->errorResult('list_events', self::ERR_INVALID_DATE, 'invalid_date', 'Use ISO-8601 (e.g. "2026-09-22T09:00:00") or YYYY-MM-DD.');
        }
        $startFormatted = $start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $endFormatted   = $end->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        return [$startFormatted, $endFormatted];
    }

    /**
     * Expand a bare date `YYYY-MM-DD` into a full ISO-8601 timestamp. Start
     * dates expand to T00:00:00 (midnight) and end dates to T23:59:59 so a
     * caller passing `start_date=2026-09-22, end_date=2026-09-22` covers
     * the whole day without the server rejecting it as "no time component".
     * Strings that already contain a time component pass through unchanged.
     */


    /**
     * Fetch the existing event body so the edit flow can merge changed
     * fields with the unchanged ones. The current ETag is returned too so
     * callers that did not supply one can still send a conditional PUT
     * (RFC 7232 §4.3.1).
     *
     * @param  array{url: string, username: string, password: string, authMethod: string, settings: array<string, mixed>} $config
     * @return array{event: array<string, mixed>, etag: string}|ToolResult
     */
    public function fetchExistingEvent(string $eventUri, array $config): array|ToolResult
    {
        $requestOptions = $this->requestBuilder->buildRequestOptions($config, ["Accept" => "text/calendar"], null, $this->client->effectiveTimeout($config["settings"]));

        $response = $this->client->request('GET', $eventUri, $requestOptions);
        $statusCode = $response->getStatusCode();
        if ($statusCode === 404) {
            return $this->errorResult('edit_event', self::ERR_EVENT_NOT_FOUND, 'not_found');
        }
        if ($statusCode >= 400) {
            return $this->errorResult('edit_event', 'Failed to fetch existing event: HTTP ' . $statusCode, 'fetch_failed', null, null);
        }
        $etag = $response->getHeaders(false)['etag'][0] ?? '';
        return [
            'event' => $this->parser->parseEventForEdit($response->getContent()),
            'etag'  => $etag,
        ];
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
            return $this->errorResult('edit_event', self::ERR_END_BEFORE_START, 'end_before_start');
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
            return $this->errorResult('edit_event', 'Invalid date format: ' . $e->getMessage(), 'invalid_date');
        }
        if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
            return $this->errorResult('edit_event', 'Failed to parse existing event dates. Fetch the latest event details and verify DTSTART/DTEND are present.', 'missing_dtstart_or_dtend');
        }
        return ['start' => $start, 'end' => $end];
    }
}

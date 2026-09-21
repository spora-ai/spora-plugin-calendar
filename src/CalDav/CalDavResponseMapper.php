<?php

declare(strict_types=1);

namespace Spora\Plugins\Calendar\CalDav;

use Closure;
use Psr\Log\LoggerInterface;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Centralised HTTP dispatch + status-code mapping for CalDavCalendarTool.
 * Owns the error-handling closure that maps an HTTP error to a ToolResult
 * so each public method can focus on its own success semantics.
 */
final class CalDavResponseMapper
{
    private const ERR_EVENT_NOT_FOUND = 'Event not found.';
    private const LOG_CALDAV_EXCEPTION = 'CalDAV Exception';

    public function __construct(
        private readonly CalDavClient $client,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param array<string, mixed> $requestOptions
     * @param Closure(ResponseInterface): ToolResult $onSuccess
     * @param Closure(ResponseInterface, int): ToolResult|null $onHttpError
     *        Optional override for the default "CalDAV server returned HTTP N" message.
     */
    public function runHttp(
        string $method,
        string $url,
        array $requestOptions,
        Closure $onSuccess,
        string $errorPrefix,
        ?Closure $onHttpError = null,
    ): ToolResult {
        try {
            $response = $this->client->request($method, $url, $requestOptions);
        } catch (Throwable $e) {
            $this->logger?->error(self::LOG_CALDAV_EXCEPTION, ['exception' => $e, 'method' => $method, 'url' => $url]);
            return new ToolResult(false, "{$errorPrefix}: {$e->getMessage()}", [
                'status' => 'error',
                'reason' => 'exception',
                'message' => $e->getMessage(),
            ]);
        }
        return $this->handleResponse($response, $method, $url, $onSuccess, $onHttpError);
    }

    /**
     * @param Closure(ResponseInterface): ToolResult $onSuccess
     * @param Closure(ResponseInterface, int): ToolResult|null $onHttpError
     */
    private function handleResponse(
        ResponseInterface $response,
        string $method,
        string $url,
        Closure $onSuccess,
        ?Closure $onHttpError,
    ): ToolResult {
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            $errorMsg = $this->safeResponseContent($response);
            $this->client->logHttpError($method, $url, $statusCode, $errorMsg, $response->getHeaders(false));
            if ($onHttpError !== null) {
                return $onHttpError($response, $statusCode);
            }
            return new ToolResult(false, "CalDAV server returned HTTP {$statusCode}", [
                'status'      => 'error',
                'http_status' => $statusCode,
            ]);
        }
        return $onSuccess($response);
    }

    public function handleGetResponse(ResponseInterface $response, string $eventUri, Closure $parseEvent): ToolResult
    {
        $etag = $response->getHeaders(false)['etag'][0] ?? null;
        return $parseEvent($response->getContent(), $eventUri, $etag);
    }

    public function handleGetError(int $statusCode): ToolResult
    {
        if ($statusCode === 404) {
            return $this->errorResult('get_event', $statusCode, self::ERR_EVENT_NOT_FOUND, 'Verify the event_uri; the event may have been deleted on the server.');
        }
        return $this->errorResult('get_event', $statusCode, "CalDAV server returned HTTP {$statusCode}");
    }

    public function handleCreateResponse(ResponseInterface $response, string $eventUri, string $summary, string $uid): ToolResult
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode === 201) {
            $etag = $response->getHeaders(false)['etag'][0] ?? null;
            $etagDisplay = $etag ?? '(server did not return an ETag)';
            // E1: surface the assigned URI + UID + ETag in the human-readable
            // text too — without this, follow-up edits have to round-trip
            // through list_events just to discover what URI the server wrote.
            return new ToolResult(
                true,
                "Event '{$summary}' created successfully.\nURI: {$eventUri}\nUID: {$uid}\nETag: {$etagDisplay}",
                [
                    'status'    => 'ok',
                    'action'    => 'create_event',
                    'event_uri' => $eventUri,
                    'uid'       => $uid,
                    'etag'      => $etag,
                ],
            );
        }
        return new ToolResult(true, "Event '{$summary}' created successfully.", [
            'status' => 'ok',
            'action' => 'create_event',
        ]);
    }

    public function handleCreateError(int $statusCode): ToolResult
    {
        if ($statusCode === 415) {
            return $this->errorResult(
                'create_event',
                $statusCode,
                'Calendar server rejected the event payload (HTTP 415 — unsupported media type).',
                'The CalDAV server may not support all-day VEVENTs. Try create_event without all_day=true (the builder will encode it as a 24-hour timed event), or verify the URL points at a writable calendar collection.',
            );
        }
        return $this->errorResult('create_event', $statusCode, "CalDAV server returned HTTP {$statusCode}");
    }

    public function handlePutResponse(ResponseInterface $response, string $eventUri, string $summary): ToolResult
    {
        $newEtag = $response->getHeaders(false)['etag'][0] ?? null;
        return new ToolResult(true, "Event '{$summary}' updated successfully.", [
            'status'    => 'ok',
            'action'    => 'edit_event',
            'event_uri' => $eventUri,
            'etag'      => $newEtag,
        ]);
    }

    public function handlePutError(int $statusCode): ToolResult
    {
        if ($statusCode === 412) {
            // The wording covers BOTH stale-ETag and event-gone cases:
            // some CalDAV servers return 412 (instead of 404) when the
            // target URI doesn't exist, so a literal "the event has been
            // modified" is misleading on delete.
            return $this->errorResult(
                'edit_event',
                $statusCode,
                'Precondition Failed: the event no longer matches the supplied ETag. It may have been modified, or the URI may have been deleted on the server.',
                'Re-issue get_event to confirm the URI exists, then retry with the new ETag.',
            );
        }
        if ($statusCode === 404) {
            return $this->errorResult('edit_event', $statusCode, self::ERR_EVENT_NOT_FOUND, 'Verify the event_uri; the event may have been deleted on the server.');
        }
        return $this->errorResult('edit_event', $statusCode, "CalDAV server returned HTTP {$statusCode}");
    }

    public function handleDeleteResponse(string $eventUri): ToolResult
    {
        return new ToolResult(true, 'Event deleted successfully.', [
            'status'    => 'ok',
            'action'    => 'delete_event',
            'event_uri' => $eventUri,
        ]);
    }

    /**
     * Build a uniform error envelope: `{status: 'error', action, http_status, hint?}`.
     * `data` is additive — existing tools that parse the text content still work.
     *
     * @return ToolResult
     */
    private function errorResult(string $action, int $httpStatus, string $message, ?string $hint = null): ToolResult
    {
        $data = [
            'status'      => 'error',
            'action'      => $action,
            'http_status' => $httpStatus,
        ];
        if ($hint !== null) {
            $data['hint'] = $hint;
        }
        return new ToolResult(false, $message, $data);
    }

    /**
     * Read the body of a 4xx/5xx response for logging. Some mocked responses
     * don't define getContent, so swallow the failure and log an empty body.
     */
    private function safeResponseContent(ResponseInterface $response): string
    {
        try {
            return $response->getContent(false);
        } catch (Throwable) {
            return '';
        }
    }
}

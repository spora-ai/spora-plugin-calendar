<?php

declare(strict_types=1);

namespace Spora\Plugins\Calendar\Tools;

use Psr\Log\LoggerInterface;
use Spora\Plugins\Calendar\CalDav\CalDavClient;
use Spora\Plugins\Calendar\CalDav\CalDavOperationHelpers;
use Spora\Plugins\Calendar\CalDav\CalDavOperations;
use Spora\Plugins\Calendar\CalDav\CalDavResponseMapper;
use Spora\Plugins\Calendar\CalDav\IcsBuilder;
use Spora\Plugins\Calendar\CalDav\IcsParser;
use Spora\Services\ToolConfigService;
use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * CalDAV calendar tool — list, get, create, edit, delete events. Delegates
 * the input/date/config parsing and HTTP dispatch to {@see CalDavOperations};
 * the class only owns the tool entry points and argument validation.
 */
#[Tool(
    name: 'calendar',
    description: 'Manage calendar events on a CalDAV-compatible server (e.g. Nextcloud, Baikal). Supports listing, viewing, creating, editing, and deleting events.',
    displayName: 'Calendar',
    category: 'productivity',
)]
#[ToolOperation(name: 'list_events', description: 'Fetch upcoming events from CalDAV calendar within a date range', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get_event', description: 'Get details of a specific event by its CalDAV URI', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'create_event', description: 'Create a new event on the CalDAV calendar', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'edit_event', description: 'Edit an existing event on the CalDAV calendar', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'delete_event', description: 'Delete an event from the CalDAV calendar', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolSetting(key: 'core.caldav.url', label: 'CalDAV URL', type: 'text', description: 'URL to the Calendar server (e.g. Nextcloud, Baikal)', )]
#[ToolSetting(key: 'core.caldav.username', label: 'Username', type: 'text', description: 'CalDAV username', )]
#[ToolSetting(key: 'core.caldav.password', label: 'Password', type: 'password', description: 'CalDAV password or app token', required: true)]
#[ToolSetting(
    key: 'core.caldav.http_timeout',
    label: 'HTTP Timeout',
    type: 'text',
    description: 'Seconds before an HTTP request fails (default: 30)',
    default: '30',
)]
// Parameter declaration order matches the hand-rolled schema so the approval UI
// renders fields in the same sequence. `action` is auto-synthesized.
#[ToolParameter(name: 'start_date', type: 'string', description: 'Start date in ISO-8601 format (or YYYY-MM-DD for all_day events)', required: false)]
#[ToolParameter(name: 'end_date', type: 'string', description: 'End date in ISO-8601 format (or YYYY-MM-DD for all_day events)', required: false)]
#[ToolParameter(name: 'event_uri', type: 'string', description: 'The CalDAV URI of the event (required for get_event, edit_event, delete_event)', required: false)]
#[ToolParameter(name: 'etag', type: 'string', description: 'The ETag of the event (required for edit_event, optional for delete_event)', required: false)]
#[ToolParameter(name: 'summary', type: 'string', description: 'Event title/summary (required for create_event)', required: false)]
#[ToolParameter(name: 'description', type: 'string', description: 'Event description (optional)', required: false)]
#[ToolParameter(name: 'location', type: 'string', description: 'Event location (optional)', required: false)]
#[ToolParameter(name: 'timezone', type: 'string', description: 'IANA timezone identifier (e.g. Europe/Berlin). Optional for create_event and edit_event.', required: false)]
#[ToolParameter(name: 'all_day', type: 'boolean', description: 'If true, start_date and end_date are interpreted as date-only (YYYY-MM-DD) and the event is an all-day event. Optional.', required: false)]
final class CalDavCalendarTool extends AbstractTool
{
    private readonly CalDavOperations $operations;

    public function __construct(
        ToolConfigService $configService,
        HttpClientInterface $httpClient,
        ?LoggerInterface $logger = null,
        array $appConfig = [],
    ) {
        $client  = new CalDavClient($httpClient, $logger);
        $mapper  = new CalDavResponseMapper($client, $logger);
        $builder = new IcsBuilder($appConfig);
        $parser  = new IcsParser();
        $this->operations = new CalDavOperations(
            new CalDavOperationHelpers($configService, $client, $builder, $parser, $mapper),
        );
    }

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'list_events'  => $this->operations->listEvents($arguments, $agentId, $userId),
            'get_event'    => $this->operations->getEvent($arguments, $agentId, $userId),
            'create_event' => $this->operations->createEvent($arguments, $agentId, $userId),
            'edit_event'   => $this->operations->editEvent($arguments, $agentId, $userId),
            'delete_event' => $this->operations->deleteEvent($arguments, $agentId, $userId),
            default        => new ToolResult(false, "Unknown operation: {$operation}"),
        };
    }

    public function describeAction(array $arguments): string
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'list_events'  => 'Fetch CalDAV calendar events',
            'get_event'    => 'Get a specific CalDAV calendar event',
            'create_event' => 'Create a new CalDAV calendar event',
            'edit_event'   => 'Edit an existing CalDAV calendar event',
            'delete_event' => 'Delete a CalDAV calendar event',
            default        => 'Unknown CalDAV operation',
        };
    }
}

<?php

declare(strict_types=1);

namespace Spora\Plugins\Calendar\CalDav;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Icalendar\Component\VEvent;
use Icalendar\Parser\Parser;
use LibXMLError;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Parses CalDAV server responses: XML multistatus (for list), and iCalendar
 * (for get/edit). Stateless: each public method is pure with respect to its
 * inputs.
 */
final class IcsParser
{
    private const ICS_DATETIME_UTC   = 'Ymd\THis\Z';
    private const ICS_DATETIME_LOCAL = 'Ymd\THis';

    /**
     * Parse a CalDAV multistatus XML body and turn each <c:calendar-data>
     * payload into a row in the result.
     */
    public function parseListResponse(string $xmlBody): ToolResult
    {
        $parser = new DOMDocument();
        $previousUseErrors = libxml_use_internal_errors(true);
        try {
            $loaded = $parser->loadXML($xmlBody);
            $loadErrors = libxml_get_errors();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previousUseErrors);
        }

        if ($loaded === false) {
            return $this->malformedXmlResult($loadErrors);
        }

        $eventData = $this->collectEventsFromMultistatus($parser);
        if ($eventData === []) {
            return new ToolResult(true, 'No events found in the specified time range.', [
                'status'  => 'ok',
                'action'  => 'list_events',
                'events'  => [],
                'count'   => 0,
            ]);
        }

        return new ToolResult(
            true,
            "Found " . count($eventData) . " events:\n\n" . $this->formatEventRows($eventData),
            [
                'status' => 'ok',
                'action' => 'list_events',
                'events' => $eventData,
                'count'  => count($eventData),
            ],
        );
    }

    /**
     * Parse a PROPFIND multistatus response into a list of available
     * calendars. Filters to entries whose resourcetype contains
     * <c:calendar/> so non-calendar collections (address books, etc.)
     * don't pollute the result.
     *
     * @return ToolResult with `data.calendars[]` = list of {href, name}
     */
    public function parseCalendarListResponse(string $xmlBody): ToolResult
    {
        $parser = new DOMDocument();
        $previousUseErrors = libxml_use_internal_errors(true);
        try {
            $loaded = $parser->loadXML($xmlBody);
            $loadErrors = libxml_get_errors();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previousUseErrors);
        }

        if ($loaded === false) {
            return $this->malformedXmlResult($loadErrors);
        }

        $xpath = new DOMXPath($parser);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');

        $calendars = [];
        $responses = $xpath->query('//d:response');
        if ($responses === false) {
            $responses = new DOMNodeList();
        }
        foreach ($responses as $response) {
            $href = $xpath->query('d:href', $response)->item(0)?->textContent;
            if ($href === null || $href === '') {
                continue;
            }
            $isCalendar = $xpath->query('.//c:calendar', $response)->length > 0;
            if (!$isCalendar) {
                continue;
            }
            $nameNode = $xpath->query('d:propstat/d:prop/d:displayname', $response)->item(0);
            $name = $nameNode !== null ? trim($nameNode->textContent) : '';
            $calendars[] = [
                'href' => trim($href),
                'name' => trim($name),
            ];
        }

        if ($calendars === []) {
            return new ToolResult(true, 'No calendars found at the configured URL.', [
                'status'    => 'ok',
                'action'    => 'list_calendars',
                'calendars' => [],
                'count'     => 0,
            ]);
        }

        $rows = [];
        foreach ($calendars as $cal) {
            $rows[] = "- {$cal['name']} ({$cal['href']})";
        }
        return new ToolResult(
            true,
            "Found " . count($calendars) . " calendars:\n\n" . implode("\n", $rows),
            [
                'status'    => 'ok',
                'action'    => 'list_calendars',
                'calendars' => $calendars,
                'count'     => count($calendars),
            ],
        );
    }

    /**
     * Parse a VCALENDAR body for a single event (used by get_event).
     */
    public function parseEventForGet(string $icsContent, string $eventUri, ?string $etag): ToolResult
    {
        $event = $this->firstVEvent($icsContent);
        if ($event === null) {
            return new ToolResult(false, 'No VEVENT found in the calendar data.', [
                'status' => 'error',
                'action' => 'get_event',
                'reason' => 'no_vevent',
            ]);
        }

        // P0: the library's $event->getDtStart() returns just the date value,
        // losing the TZID parameter. Walk the raw properties so we can
        // surface the original timezone context to the caller — without it,
        // an agent that edits the event would re-write it as floating local
        // time, silently shifting wall-clock across DST boundaries.
        [$dtstartRaw, $dtstartTzid] = $this->extractDateProperty($event, 'DTSTART');
        [$dtendRaw,   $dtendTzid]   = $this->extractDateProperty($event, 'DTEND');

        $details = new EventDetails(
            uid: $event->getUid(),
            summary: $event->getSummary(),
            dtstart: $event->getDtStart(),
            dtend: $event->getDtEnd(),
            description: $event->getDescription(),
            location: $event->getLocation(),
        );

        return new ToolResult(true, $this->formatGetEventOutput($eventUri, $etag, $details), [
            'status'      => 'ok',
            'action'      => 'get_event',
            'event_uri'   => $eventUri,
            'uid'         => $details->uid,
            'summary'     => $details->summary,
            'dtstart'     => $dtstartRaw,
            'dtend'       => $dtendRaw,
            'dtstart_tzid' => $dtstartTzid,
            'dtend_tzid'   => $dtendTzid,
            'description' => $details->description,
            'location'    => $details->location,
            'etag'        => $etag,
        ]);
    }

    /**
     * Walk the event's raw properties and extract the value + TZID for
     * a given date-time property (DTSTART / DTEND).
     *
     * The craigk5n library exposes only the value via getDtStart()/getDtEnd();
     * the TZID parameter is lost unless we iterate the property bag.
     *
     * @return array{0: ?string, 1: ?string} [value, tzid]
     */
    private function extractDateProperty(VEvent $event, string $propertyName): array
    {
        foreach ($event->getProperties() as $property) {
            if (strcasecmp($property->getName(), $propertyName) !== 0) {
                continue;
            }
            $value = $property->getValue()->getRawValue();
            $tzid = null;
            foreach ($property->getParameters() as $key => $paramValue) {
                if (strcasecmp($key, 'TZID') === 0) {
                    $tzid = (string) $paramValue;
                    break;
                }
            }
            return [$value, $tzid];
        }
        return [null, null];
    }

    /**
     * Parse a VCALENDAR body and return the first VEVENT as a flat array
     * suitable for `edit_event`'s merge step. Returns a stub if the body has
     * no VEVENT (caller treats that as an "incomplete" event).
     *
     * Includes the original TZID so the builder can re-emit the event with
     * the same timezone context — without it, the wire payload drops to
     * floating local time and the wall-clock drifts across DST boundaries.
     *
     * @return array{uid: ?string, summary: string, dtstart: ?DateTimeImmutable, dtend: ?DateTimeImmutable, description: string, location: string, timezone: ?string}
     */
    public function parseEventForEdit(string $icsContent): array
    {
        /** @var VEvent|null $event */
        $event = $this->firstVEvent($icsContent);
        if ($event === null) {
            return [
                'uid'         => null,
                'summary'     => '',
                'dtstart'     => null,
                'dtend'       => null,
                'description' => '',
                'location'    => '',
                'timezone'    => null,
            ];
        }

        // P0: pull the TZID from the raw DTSTART/DTEND properties and parse
        // the datetimes with that timezone context — otherwise the DateTime
        // ends up floating and a subsequent setTimezone() to the new
        // target TZ would shift the wall-clock across DST boundaries.
        [$startRaw, $startTzid] = $this->extractDateProperty($event, 'DTSTART');
        [$endRaw,   $endTzid]   = $this->extractDateProperty($event, 'DTEND');

        return [
            'uid'         => $event->getUid() ?? '',
            'summary'     => $event->getSummary() ?? '',
            'dtstart'     => $this->parseIcsDateString($startRaw, $startTzid),
            'dtend'       => $this->parseIcsDateString($endRaw, $endTzid),
            'description' => $event->getDescription() ?? '',
            'location'    => $event->getLocation() ?? '',
            'timezone'    => $startTzid,
        ];
    }

    /**
     * @return list<array{event_uri: ?string, uid: string, summary: string, dtstart: string, dtend: string}>
     */
    public function parseEventRowsFromIcs(string $icsContent, ?string $eventUri): array
    {
        $events = array_values((new Parser(Parser::LENIENT))->parse($icsContent)->getComponents('VEVENT'));
        $rows = [];
        foreach ($events as $event) {
            /** @var VEvent $event */
            $rows[] = [
                'event_uri' => $eventUri,
                'uid'       => $event->getUid() ?? '',
                'summary'   => $event->getSummary() ?? 'Unknown Event',
                'dtstart'   => $event->getDtStart() ?? 'Unknown Start',
                'dtend'     => $event->getDtEnd() ?? 'Unknown End',
            ];
        }
        return $rows;
    }

    private function firstVEvent(string $icsContent): ?VEvent
    {
        /** @var list<VEvent> $events */
        $events = array_values((new Parser(Parser::LENIENT))->parse($icsContent)->getComponents('VEVENT'));
        if ($events === []) {
            return null;
        }
        return $events[0];
    }

    private function makeXPath(DOMDocument $parser): DOMXPath
    {
        $xpath = new DOMXPath($parser);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');
        return $xpath;
    }

    /**
     * @param DOMNodeList<DOMNode> $calendarDataNodes
     * @return list<array{event_uri: ?string, uid: string, summary: string, dtstart: string, dtend: string}>
     */
    private function collectEvents(DOMNodeList $calendarDataNodes, DOMXPath $xpath): array
    {
        $eventData = [];
        foreach ($calendarDataNodes as $calData) {
            $icsContent = $calData->textContent;
            if (trim($icsContent) === '') {
                continue;
            }
            $eventUri = $this->findEventUriForCalData($calData, $xpath);
            foreach ($this->parseEventRowsFromIcs($icsContent, $eventUri) as $row) {
                $eventData[] = $row;
            }
        }
        return $eventData;
    }

    /**
     * @param list<array{event_uri: ?string, uid: string, summary: string, dtstart: string, dtend: string}> $eventData
     */
    private function formatEventRows(array $eventData): string
    {
        $output = '';
        foreach ($eventData as $row) {
            $output .= "- Event: {$row['summary']}\n  URI:   {$row['event_uri']}\n  UID:   {$row['uid']}\n  Start: {$row['dtstart']}\n  End:   {$row['dtend']}\n\n";
        }
        return trim($output);
    }

    private function formatGetEventOutput(
        string $eventUri,
        ?string $etag,
        EventDetails $details,
    ): string {
        $output  = "Event Details:\n";
        $output .= "- URI: {$eventUri}\n";
        if ($details->uid) {
            $output .= "- UID: {$details->uid}\n";
        }
        if ($details->summary) {
            $output .= "- Summary: {$details->summary}\n";
        }
        if ($details->dtstart) {
            $output .= "- Start: {$details->dtstart}\n";
        }
        if ($details->dtend) {
            $output .= "- End: {$details->dtend}\n";
        }
        if ($details->description) {
            $output .= "- Description: {$details->description}\n";
        }
        if ($details->location) {
            $output .= "- Location: {$details->location}\n";
        }
        if ($etag) {
            $output .= "- ETag: {$etag}\n";
        }
        return $output;
    }

    private function findEventUriForCalData(DOMNode $calData, DOMXPath $xpath): ?string
    {
        // Walk up to find the parent <d:response> for the href (event URI)
        $eventUri = null;
        $responseNode = $calData->parentNode;
        while ($responseNode !== null) {
            if ($responseNode->localName === 'response') {
                $hrefNode = $xpath->query('d:href', $responseNode)->item(0);
                if ($hrefNode !== null) {
                    $eventUri = trim($hrefNode->textContent);
                }
                break;
            }
            $responseNode = $responseNode->parentNode;
        }
        return $eventUri;
    }

    private function parseIcsDateVariant(string $dateStr): ?DateTimeImmutable
    {
        if (str_ends_with($dateStr, 'Z')) {
            $parsed = DateTimeImmutable::createFromFormat(self::ICS_DATETIME_UTC, $dateStr, new DateTimeZone('UTC'));
            return $parsed instanceof DateTimeImmutable ? $parsed->setTimezone(new DateTimeZone('UTC')) : null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Ymd', $dateStr);
        return $parsed instanceof DateTimeImmutable ? $parsed : null;
    }

    /**
     * Parse an iCalendar date with an explicit TZID parameter
     * (e.g. "DTSTART;TZID=Europe/Berlin:20260601T100000"). Returns null if
     * the input lacks a recognisable TZID or the date fails to parse.
     */
    private function parseIcsDateWithTimezone(string $dateStr): ?DateTimeImmutable
    {
        if (!str_contains($dateStr, ';TZID=') || !preg_match('/;TZID=([^:]+):(.+)$/', $dateStr, $m)) {
            return null;
        }
        try {
            $tz = new DateTimeZone($m[1]);
            $parsed = DateTimeImmutable::createFromFormat(self::ICS_DATETIME_LOCAL, $m[2], $tz);
        } catch (Throwable) {
            // Invalid TZID — fall back to the generic parser in the caller.
            return null;
        }
        return $parsed instanceof DateTimeImmutable ? $parsed->setTimezone($tz) : null;
    }

    private function parseIcsDateString(?string $dateStr, ?string $tzid = null): ?DateTimeImmutable
    {
        if ($dateStr === null || $dateStr === '') {
            return null;
        }
        // P0 round-trip: when the source DTSTART carried a TZID parameter,
        // parse the value as local time in that zone so a subsequent
        // setTimezone() doesn't shift the wall-clock.
        if ($tzid !== null && $tzid !== ''
            && !str_ends_with($dateStr, 'Z') && strlen($dateStr) !== 8
            && ($parsed = $this->parseIcsDateWithTzid($dateStr, $tzid)) !== null) {
            return $parsed;
        }
        return $this->parseIcsDateFallback($dateStr);
    }

    /**
     * Parse a date-time value as local time in the given TZID zone.
     * Returns null on bad TZID or bad date string.
     */
    private function parseIcsDateWithTzid(string $dateStr, string $tzid): ?DateTimeImmutable
    {
        try {
            $tz = new DateTimeZone($tzid);
            $parsed = DateTimeImmutable::createFromFormat(self::ICS_DATETIME_LOCAL, $dateStr, $tz);
            return $parsed instanceof DateTimeImmutable ? $parsed->setTimezone($tz) : null;
        } catch (Throwable) {
            // Invalid TZID — fall back to the generic parser in the caller.
            return null;
        }
    }

    /**
     * Parse a date-time without an explicit TZID: try the bare UTC/date
     * variant first, then the `;TZID=…:…` form, then DateTime's native
     * constructor as a last resort.
     */
    private function parseIcsDateFallback(string $dateStr): ?DateTimeImmutable
    {
        if (strlen($dateStr) === 8 || str_ends_with($dateStr, 'Z')) {
            return $this->parseIcsDateVariant($dateStr);
        }
        return $this->parseIcsDateWithTimezone($dateStr) ?? $this->parseIcsDateGeneric($dateStr);
    }

    /**
     * Last-resort parser that hands the string to DateTimeImmutable's
     * native constructor. Returns null on parse failure.
     */
    private function parseIcsDateGeneric(string $dateStr): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($dateStr);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<LibXMLError> $loadErrors
     */
    private function malformedXmlResult(array $loadErrors): ToolResult
    {
        $firstError = $loadErrors[0] ?? null;
        $detail = $firstError instanceof LibXMLError ? trim($firstError->message) : 'malformed XML';
        return new ToolResult(false, "CalDAV response could not be parsed: {$detail}", [
            'status'  => 'error',
            'reason'  => 'malformed_xml',
            'message' => $detail,
        ]);
    }

    /**
     * @return list<array{event_uri: ?string, uid: string, summary: string, dtstart: string, dtend: string}>
     */
    private function collectEventsFromMultistatus(DOMDocument $parser): array
    {
        $xpath = $this->makeXPath($parser);
        $calendarDataNodes = $xpath->query('//c:calendar-data');
        if ($calendarDataNodes === false || $calendarDataNodes->length === 0) {
            return [];
        }
        return $this->collectEvents($calendarDataNodes, $xpath);
    }
}

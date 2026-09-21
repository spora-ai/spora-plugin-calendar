<?php

declare(strict_types=1);

namespace Spora\Plugins\Calendar\CalDav;

/**
 * Builds the small CalDAV/WebDAV XML payloads the plugin sends. Pure:
 * no I/O, no DI, no logging. Lifted out of {@see CalDavOperationHelpers}
 * to keep that class under Sonar's 20-method ceiling (S1448).
 */
final class CalDavXmlBuilder
{
    /**
     * Body for a `REPORT` request on a calendar collection: a
     * `c:calendar-query` with a `c:time-range` filter so the server
     * returns only events overlapping the caller's window.
     */
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

    /**
     * Body for a `PROPFIND Depth: 1` request used by `list_calendars`.
     * The two properties asked for are `displayname` (to label the
     * result for the operator) and `resourcetype` (so the parser can
     * filter non-calendar collections out).
     */
    public function buildPropfindXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:">
    <d:prop>
        <d:displayname />
        <d:resourcetype />
    </d:prop>
</d:propfind>
XML;
    }
}

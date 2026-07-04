# Calendar Plugin for Spora

CalDAV calendar read/write for [Spora](https://github.com/spora-ai/Spora)
agents — list upcoming events, fetch a single event by URI, and create, edit,
or delete events on any RFC 4791-compliant CalDAV server (iCloud, Fastmail,
Nextcloud, Radicale, Baïkal, Google Calendar via CalDAV, etc.). iCalendar
(RFC 5545) payloads are built and parsed with
[`craigk5n/php-icalendar-core`](https://packagist.org/packages/craigk5n/php-icalendar-core).
CalDAV is a protocol, not a SaaS — any server speaking
[RFC 4791](https://www.rfc-editor.org/rfc/rfc4791) (CalDAV) and
[RFC 5545](https://www.rfc-editor.org/rfc/rfc5545) (iCalendar) works.

## Installation

```bash
php bin/spora plugin:install spora-ai/spora-plugin-calendar
```

For local development against a sibling checkout, pass `--path=/abs/path/to/checkout`.

After install, the `calendar` tool is exposed. Operations are dispatched via the `action` parameter (see [Per-tool parameters](#per-tool-parameters)).

## Configuration

Settings → Tools → Calendar. The three required fields are the CalDAV
collection URL, the username, and a password (most providers require an
**app-specific password**, not your account password — see the vendor list
below).

| Setting | Required | Default | Notes |
|---|---|---|---|
| `core.caldav.url` | yes | — | Full URL to a specific CalDAV calendar collection, e.g. `https://caldav.icloud.com/...` |
| `core.caldav.username` | yes | — | CalDAV account username (often the account email) |
| `core.caldav.password` | yes | — | CalDAV password or app-specific token |
| `core.caldav.http_timeout` | no | `30` | Seconds before an HTTP request fails. Overrides `SPORA_TOOL_HTTP_TIMEOUT` |

The `password` field is encrypted at rest by Spora's `ToolConfigService`,
masked in the UI, and never logged. The CalDAV client uses HTTP Basic auth
(`auth_basic`) over HTTPS; ETag handling follows
[RFC 7232](https://www.rfc-editor.org/rfc/rfc7232) for safe updates.

## Per-tool parameters

The tool exposes a single `action` discriminator; each action takes the
parameters below. String dates use ISO-8601 (`YYYY-MM-DDTHH:MM:SS[±HH:MM]`
or `YYYY-MM-DD` for all-day events). Returns `ToolResult::ok` on success or
`ToolResult::fail` on validation / HTTP failure — never throws.

| Action | Description | Parameters |
|---|---|---|
| `list_events` | Fetch events within a date range. | `start_date` (string, required), `end_date` (string, required) |
| `get_event` | Get one event by its CalDAV URI. | `event_uri` (string, required) |
| `create_event` | Create a new event. Requires approval. | `summary` (string, required), `start_date` (string, required), `end_date` (string, required), `description` (string, optional), `location` (string, optional), `timezone` (string, optional, IANA name like `Europe/Berlin`), `all_day` (bool, optional) |
| `edit_event` | Edit an existing event. Requires approval. | `event_uri` (string, required), `etag` (string, required), `summary` (string, optional — falls back to existing), `start_date` (string, optional), `end_date` (string, optional), `description` (string, optional), `location` (string, optional), `timezone` (string, optional), `all_day` (bool, optional) |
| `delete_event` | Delete an event. Requires approval. | `event_uri` (string, required), `etag` (string, optional — adds `If-Match` for safer deletion) |

`create_event` and `edit_event` write iCalendar `VTIMEZONE`-aware payloads:
when `timezone` is set, `DTSTART`/`DTEND` carry the `TZID` parameter; when
`all_day` is `true`, dates are interpreted as date-only (`YYYY-MM-DD`).

For safe edits, fetch the event with `get_event` first to obtain its current
`etag` — the server returns `412 Precondition Failed` (mapped to a friendly
`ToolResult::fail` message) if the event has been modified since.

## CalDAV servers

CalDAV is an open IETF protocol; any of these work with the same
configuration shape. Most providers require an **app-specific password**
rather than your account password.

| Provider | CalDAV URL | App password |
|---|---|---|
| Apple iCloud | `https://caldav.icloud.com` (per-calendar URL from the Calendar app's "Calendar Sharing" dialog) | <https://appleid.apple.com/account/manage> → App-Specific Passwords |
| Fastmail | Per-calendar URL from Settings → Calendars → ⋯ → "CalDAV URL" (host: `caldav.fastmail.com`) | Account password (Fastmail supports CalDAV directly with the account password) |
| Google Calendar (via CalDAV) | `https://apidata.googleusercontent.com/caldav/v2/<calendarID>/events` (calendar ID from Google Calendar settings) | <https://myaccount.google.com/apppasswords> |
| Nextcloud | `https://<your-nextcloud>/remote.php/dav/calendars/<username>/<calendar-name>/` (copy from Calendar → Settings → "iOS/OS X CalDAV address") | Nextcloud user profile → Security → "App passwords" |
| Radicale (self-hosted) | `https://<your-radicale-host>/<user>/<calendar>/` (default port `5232`) | Account password (configure auth in `config`) |
| Baïkal (self-hosted) | `https://<your-baikal>/baikal/cal.php/calendars/<user>/<calendar>/` | Account password |

Radicale's docs: <https://radicale.org/>. Baïkal: <https://sabre.io/baikal/>.

## Development

```bash
composer install
./vendor/bin/pest
./vendor/bin/phpstan analyse --no-progress
./vendor/bin/php-cs-fixer fix --dry-run --diff --no-interaction
```

CI: `.github/workflows/ci.yml` — Pest on PHP 8.4 + 8.5, PHPStan, and
php-cs-fixer dry-run. The `coverage` job generates `coverage.xml` (Pest
with Xdebug) and the `sonar` job uploads it to SonarCloud (project key
`spora-ai_spora-plugin-calendar`). Requires the `SONAR_TOKEN` secret in
the repo. MIT license.
# Calendar Group Event Importer (`local_grpcalendarimport`)

A Moodle local plugin that lets administrators bulk-import group calendar events from a CSV or TSV file via a simple admin interface.

## Features

- Upload a CSV or TSV file to create multiple group calendar events at once
- Auto-detects comma or tab delimiters
- Skips duplicate events (configurable) — checks by name, course, group, time, and event type
- Validates that each course and group exist before creating events
- Displays a per-row results table showing created, skipped, and errored rows

## Requirements

- Moodle 4.5 or later (`requires = 2024100700`)
- PHP 8.1+

## Installation

1. Download or clone this repository into your Moodle installation:
   ```
   /path/to/moodle/local/grpcalendarimport/
   ```
2. Log in to Moodle as an administrator and visit **Site administration → Notifications** to complete the install.
3. The tool will appear under **Site administration → Tools → Import Group Calendar Events**.

## CSV Format

The CSV must include a header row. Column names match the Moodle `{event}` table fields.

### Required columns

| Column | Description |
|---|---|
| `name` | Event title |
| `courseid` | Moodle course ID |
| `groupid` | Moodle group ID (must belong to the course) |
| `timestart` | Unix timestamp for the event start time |

### Recommended columns

| Column | Description | Default |
|---|---|---|
| `timeduration` | Duration in seconds | `3600` (1 hour) |
| `eventtype` | Event type (e.g. `group`) | `group` |
| `description` | Event description | _(empty)_ |
| `location` | Event location | _(empty)_ |
| `visible` | `1` = visible, `0` = hidden | `1` |

### Optional columns

`categoryid`, `userid`, `repeatid`, `component`, `modulename`, `instance`, `type`, `timesort`, `uuid`, `sequence`, `subscriptionid`, `priority`

### Example

```csv
name,courseid,groupid,timestart,timeduration,description,location
Team Meeting,2,5,1746000000,3600,Weekly sync,Room 101
Practice Session,2,6,1746003600,5400,Pre-game warmup,Field B
```

## Permissions

| Capability | Default role | Description |
|---|---|---|
| `local/grpcalendarimport:manage` | Manager | Access the import tool |

## License

GNU General Public License v3 or later — see [LICENSE](LICENSE).

Copyright 2026 SCCA

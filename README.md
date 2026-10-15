# Calendar Group Event Importer (`local_grpcalendarimport`)

A Moodle local plugin that lets administrators bulk-import group calendar events from a CSV or TSV
file via a simple admin interface.

## Features

- Upload a CSV or TSV file to create multiple group calendar events at once
- Auto-detects comma or tab delimiters
- Skips duplicate events (configurable) — checks by name, course, group, time, and event type
- Validates that each course and group exist before creating events
- Displays a per-row results table showing created, skipped, and errored rows

## Requirements

- Moodle 5.1 to 5.3 LTS (`requires = 2025100600`, `supported = [501, 503]`)
- PHP 8.2 or later (Moodle 5.2 and 5.3 require PHP 8.3 or later)

## Installing via uploaded ZIP file

1. Download `local_grpcalendarimport-<version>.zip` from the
   [Releases](https://github.com/verzog/moodle-local_grpcalendarimport/releases) page. Use the attached
   plugin zip, not GitHub's "Source code (zip)", which has the wrong top-level folder name.
2. Log in to your Moodle site as an admin and go to **Site administration → Plugins → Install plugins**.
3. Upload the ZIP file. You should only be prompted to add extra details if your plugin type is not
   automatically detected.
4. Check the plugin validation report and finish the installation.

## Installing manually

The plugin can also be installed by putting the contents of this directory in

    {your/moodle/dirroot}/public/local/grpcalendarimport

From Moodle 5.1 the web root is the `public/` directory of the Moodle checkout.

Afterwards, log in to your Moodle site as an admin and go to **Site administration → Notifications** to
complete the installation. Alternatively, you can run

    $ php admin/cli/upgrade.php

to complete the installation from the command line.

To find the tool, search **Site administration** for "Calendar Group Event Importer".

## CSV format

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

`categoryid`, `userid`, `repeatid`, `component`, `modulename`, `instance`, `type`, `timesort`, `uuid`,
`sequence`, `subscriptionid`, `priority`

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

2026 Vernon Spain

This program is free software: you can redistribute it and/or modify it under the terms of the GNU
General Public License as published by the Free Software Foundation, either version 3 of the License,
or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even
the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public
License for more details.

You should have received a copy of the GNU General Public License along with this program. If not, see
<https://www.gnu.org/licenses/>.

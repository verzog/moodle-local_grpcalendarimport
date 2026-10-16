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
| `timestart` | Event start: `DD/MM/YYYY HH:MM` in your time zone, or a Unix timestamp |

### Recommended columns

| Column | Description | Default |
|---|---|---|
| `timeduration` | Duration in seconds | `3600` (1 hour) |
| `eventtype` | Event type (e.g. `group`) | `group` |
| `description` | Event description | _(empty)_ |
| `location` | Event location | _(empty)_ |
| `visible` | `1` = visible, `0` = hidden | `1` |

### Optional columns

`categoryid`, `userid`, `timesort`, `uuid`, `sequence`, `priority`

Column names are not case-sensitive. Any other columns are ignored, including internal `{event}` fields
such as `component`, `modulename`, `instance`, `repeatid`, `type` and `subscriptionid`. Blank cells use
the default value, and blank lines are skipped. Only `group` events can be imported.

### Example

```csv
name,courseid,groupid,timestart,timeduration,description,location
Team Meeting,2,5,03/05/2027 09:00,3600,Weekly sync,Room 101
Practice Session,2,6,04/05/2027 16:00,5400,Pre-game warmup,Field B
```

### Prefilled templates

Instead of looking up course and group IDs, use **Create a prefilled template** on the import page:

1. Choose a category (optionally with its subcategories), specific courses, or both, and how many events each group needs.
2. Click **Preview template**. The page shows how many courses, groups and rows the template will have. It warns you
   about any courses that have no groups (they're left out) and if the template hits the limit of 5,000 rows.
3. Download it as **CSV** or **Excel**. Each group has one row per event. `courseid` and `groupid` are filled in,
   and `course_name` and `group_name` are next to them so you can see which is which.
4. Fill in `name` and `timestart` for each row, then upload the file on the import page. Save an Excel template as
   CSV first. The extra name columns are ignored when importing.

### Start times

`timestart` accepts any of these. Dates are read in the time zone of the person uploading the file.

- `DD/MM/YYYY HH:MM`, e.g. `03/05/2027 09:00`. Seconds are optional, and the leading zeros on day, month and
  hour can be left out (`3/5/2027 9:00`).
- 12-hour times with AM/PM, e.g. `3/05/2027 9:00 AM`, which is what spreadsheets often write when saving as CSV.
- `DD/MM/YYYY` on its own means midnight.
- `YYYY-MM-DD HH:MM`, the format some spreadsheets save dates in.
- A Unix timestamp, e.g. `1809298800`.

Impossible dates such as `31/02/2027` are reported as errors rather than moved to the next month. The year
must have four digits.

## Import history and logging

Every import of a file is recorded, whether it came from the upload page or from another plugin such as
`tool_automate`. To debug a failed import:

- **Site administration → Plugins → Group calendar import history** lists every run: who ran it, the file,
  what ran it, and how many events were created, skipped and failed. Open a run to see each failed row,
  with its error and **the exact values in the row**, and download the failed rows as CSV or Excel.
- After an upload with errors, the import page links straight to that run's history.
- Moodle's logs (**Reports → Logs**) get a "Group calendar import completed" event per run and a
  "Group calendar import row failed" event per failed row. Row events stop after the first 50 failures of
  a run, so one bad file can't flood the site log; the history always has every row.
- History older than **Keep import history for** (Site administration → Plugins → Local plugins →
  Calendar Group Event Importer; 90 days by default) is deleted each night by a scheduled task.

The history records which user ran each import, so the plugin's privacy provider exports and deletes it
on request.

## For developers

Other plugins can create group events through the `\local_grpcalendarimport\importer` class instead of
the upload page. `tool_automate` uses it for its "Create group calendar event" action and its scheduled
calendar CSV import.

| Method | Purpose |
|---|---|
| `import_file($filepath, $skipduplicates, $filename = '', $source = '')` | Import a whole file (see below) |
| `create_event($row, $skipduplicates, $rownum)` | Create one event from a row keyed by the column names above |
| `event_exists($name, $courseid, $groupid, $timestart, $eventtype)` | Duplicate check used by `create_event()` |
| `parse_file($filepath, &$headers)` / `missing_columns($headers)` | Read and check a file without importing it |
| `parse_time($value, $timezone = null)` | Read a start time in any accepted format; returns a timestamp or null |

`import_file()` returns the created/skipped/error counts, a result per row, and `runid`, the run's id in
the import history. Pass your component name as `$source` (e.g. `'tool_automate'`) and the original file
name as `$filename`, so the history shows what ran the import and on which file.

Check `class_exists('\local_grpcalendarimport\importer')` and `importer::API_VERSION` (currently `1`)
before calling it. The class does no capability checks: callers must check their own.

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

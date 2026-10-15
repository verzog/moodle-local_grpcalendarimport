<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Local library functions for the GRP Calendar Import plugin.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Parse a CSV or TSV file into an array of associative arrays keyed by header.
 *
 * Header names are lower-cased and stripped of BOM and control characters.
 * Blank lines are skipped.
 *
 * @param string $filepath Absolute path to the uploaded file.
 * @param array|null $headers Set to the normalised header names found in the file.
 * @return array Array of rows, each row is an associative array.
 */
function local_grpcalendarimport_parse_csv(string $filepath, ?array &$headers = null): array {
    $rows = [];
    $headers = [];
    if (!is_readable($filepath)) {
        return $rows;
    }
    $handle = fopen($filepath, 'r');
    if (!$handle) {
        return $rows;
    }

    // Detect delimiter from the first non-blank line (the header), skipping blank or delimiter-only lines.
    do {
        $firstline = fgets($handle);
    } while ($firstline !== false && trim($firstline, " \t\r\n\0\x0B,") === '');
    if ($firstline === false) {
        fclose($handle);
        return $rows;
    }
    rewind($handle);
    $delimiter = (substr_count($firstline, "\t") > substr_count($firstline, ',')) ? "\t" : ',';

    $headerfound = false;
    // The escape character is passed explicitly: relying on its default is deprecated in PHP 8.4.
    while (($line = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
        // Skip blank lines, including lines that contain only delimiters.
        if (trim(implode('', $line)) === '') {
            continue;
        }
        if (!$headerfound) {
            // Normalise headers: strip BOM and control characters, and lower-case.
            $headers = array_map(function ($h) {
                return strtolower(trim(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $h)));
            }, $line);
            $headerfound = true;
            continue;
        }
        // Pad short rows to avoid array_combine mismatch.
        if (count($line) < count($headers)) {
            $line = array_pad($line, count($headers), '');
        }
        $rows[] = array_combine($headers, array_slice($line, 0, count($headers)));
    }
    fclose($handle);
    return $rows;
}

/**
 * Rows of the sample CSV shown on the import page and offered for download.
 *
 * Timestamps are 03/05/2027 09:00, 04/05/2027 16:00 and 07/06/2027 10:00, Australia/Sydney time.
 *
 * @return string[] CSV lines, header first, without line endings.
 */
function local_grpcalendarimport_sample_csv_lines(): array {
    return [
        'name,courseid,groupid,timestart,timeduration,description,location,eventtype,visible',
        'Team Meeting,2,5,1809298800,3600,Weekly sync,Room 101,group,1',
        'Practice Session,2,6,1809410400,5400,Pre-game warmup,Field B,group,1',
        'Championship Race,3,7,1812326400,7200,Regional finals,Track A,group,1',
    ];
}

/**
 * Columns that every import file must contain.
 *
 * @return string[]
 */
function local_grpcalendarimport_required_columns(): array {
    return ['name', 'courseid', 'groupid', 'timestart'];
}

/**
 * Work out which required columns are missing from a file's header row.
 *
 * @param string[] $headers Normalised header names, as returned by local_grpcalendarimport_parse_csv().
 * @return string[] The missing column names, empty if none are missing.
 */
function local_grpcalendarimport_missing_columns(array $headers): array {
    return array_values(array_diff(local_grpcalendarimport_required_columns(), $headers));
}

/**
 * Read an integer column from a CSV row, treating a missing or blank cell as "use the default".
 *
 * @param array $row Associative array of CSV columns.
 * @param string $column Column name.
 * @param int|null $default Value to use when the cell is missing or blank.
 * @return int|null
 */
function local_grpcalendarimport_int_column(array $row, string $column, ?int $default): ?int {
    $value = trim((string)($row[$column] ?? ''));
    return ($value === '') ? $default : (int)$value;
}

/**
 * Create a single group calendar event from a CSV row.
 *
 * @param array $row         Associative array of CSV columns.
 * @param bool  $skipduplicates Whether to skip events that already exist.
 * @param int   $rownum      1-based row number for reporting.
 * @return array Result array with keys: row, status (ok|skip|error), message.
 */
function local_grpcalendarimport_create_event(array $row, bool $skipduplicates, int $rownum): array {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/calendar/lib.php');

    $eventname   = trim($row['name'] ?? '');
    $courseid    = local_grpcalendarimport_int_column($row, 'courseid', 0);
    $groupid     = local_grpcalendarimport_int_column($row, 'groupid', 0);
    $timestart   = local_grpcalendarimport_int_column($row, 'timestart', 0);
    $duration    = local_grpcalendarimport_int_column($row, 'timeduration', 3600);
    $description = trim($row['description'] ?? '');
    $location    = trim($row['location'] ?? '');
    $visible     = local_grpcalendarimport_int_column($row, 'visible', 1) ? 1 : 0;
    $eventtype   = trim($row['eventtype'] ?? '');
    $eventtype   = ($eventtype === '') ? 'group' : $eventtype;
    $uuid        = trim($row['uuid'] ?? '');
    $sequence    = local_grpcalendarimport_int_column($row, 'sequence', 1);
    $priority    = local_grpcalendarimport_int_column($row, 'priority', null);
    $timesort    = local_grpcalendarimport_int_column($row, 'timesort', $timestart);
    $categoryid  = local_grpcalendarimport_int_column($row, 'categoryid', 0);
    $userid      = local_grpcalendarimport_int_column($row, 'userid', 0);

    // Basic validation.
    if (empty($eventname)) {
        return ['row' => $rownum, 'status' => 'error', 'message' => get_string('error_missingname', 'local_grpcalendarimport')];
    }
    if ($courseid <= 0) {
        return ['row' => $rownum, 'status' => 'error', 'message' => get_string('error_courseid', 'local_grpcalendarimport')];
    }
    if ($groupid <= 0) {
        return ['row' => $rownum, 'status' => 'error', 'message' => get_string('error_groupid', 'local_grpcalendarimport')];
    }
    if ($timestart <= 0) {
        return ['row' => $rownum, 'status' => 'error', 'message' => get_string('error_timestart', 'local_grpcalendarimport')];
    }
    // This tool only creates group events; other types need fields that a CSV can't safely supply.
    if ($eventtype !== 'group') {
        return [
            'row' => $rownum,
            'status' => 'error',
            'message' => get_string('error_eventtype', 'local_grpcalendarimport', $eventtype),
        ];
    }

    // Verify course exists.
    if (!$DB->record_exists('course', ['id' => $courseid])) {
        return [
            'row' => $rownum,
            'status' => 'error',
            'message' => get_string('error_coursenotfound', 'local_grpcalendarimport', $courseid),
        ];
    }

    // Verify group exists and belongs to this course.
    if (!$DB->record_exists('groups', ['id' => $groupid, 'courseid' => $courseid])) {
        return [
            'row' => $rownum,
            'status' => 'error',
            'message' => get_string(
                'error_groupnotfound',
                'local_grpcalendarimport',
                ['groupid' => $groupid, 'courseid' => $courseid]
            ),
        ];
    }

    // Skip duplicate check using sql_compare_text() for TEXT column.
    if ($skipduplicates) {
        $sql = "SELECT id
                  FROM {event}
                 WHERE " . $DB->sql_compare_text('name') . " = " . $DB->sql_compare_text(':name') . "
                   AND courseid  = :courseid
                   AND groupid   = :groupid
                   AND timestart = :timestart
                   AND eventtype = :eventtype";
        $params = [
            'name' => $eventname,
            'courseid' => $courseid,
            'groupid' => $groupid,
            'timestart' => $timestart,
            'eventtype' => $eventtype,
        ];
        $exists = $DB->record_exists_sql($sql, $params);
        if ($exists) {
            return [
                'row' => $rownum,
                'status' => 'skip',
                'message' => get_string('result_duplicate', 'local_grpcalendarimport', $eventname),
            ];
        }
    }

    // Build the event record using {event} table field names. Fields that link an event to
    // modules, subscriptions or repeat series are internal to Moodle and are not read from the CSV.
    $event = new stdClass();
    $event->name           = $eventname;
    $event->description    = $description;
    $event->format         = FORMAT_PLAIN;
    $event->categoryid     = $categoryid;
    $event->courseid       = $courseid;
    $event->groupid        = $groupid;
    $event->userid         = $userid;
    $event->repeatid       = 0;
    $event->component      = '';
    $event->modulename     = '';
    $event->instance       = 0;
    $event->type           = CALENDAR_EVENT_TYPE_STANDARD;
    $event->eventtype      = $eventtype;
    $event->timestart      = $timestart;
    $event->timeduration   = $duration;
    $event->timesort       = $timesort;
    $event->visible        = $visible;
    $event->uuid           = $uuid;
    $event->sequence       = $sequence;
    $event->timemodified   = time();
    $event->subscriptionid = null;
    $event->priority       = $priority;
    $event->location       = $location;

    try {
        $calendarevent = \calendar_event::create($event, false);
        return [
            'row'     => $rownum,
            'status'  => 'ok',
            'message' => get_string(
                'result_created',
                'local_grpcalendarimport',
                ['name' => $eventname, 'id' => $calendarevent->id]
            ),
        ];
    } catch (Exception $e) {
        return [
            'row' => $rownum,
            'status' => 'error',
            'message' => get_string('error_exception', 'local_grpcalendarimport', $e->getMessage()),
        ];
    }
}

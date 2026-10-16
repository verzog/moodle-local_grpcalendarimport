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
 * Public API for creating group calendar events.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport;

/**
 * Public API for creating group calendar events, one row at a time or a whole file.
 *
 * Other plugins (for example tool_automate) call this class directly. Check that it exists with
 * class_exists('\local_grpcalendarimport\importer') and compare API_VERSION before relying on it.
 * Callers are responsible for their own capability checks; this class does not check permissions.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class importer {
    /** @var int Version of this API. Raised only when a public method changes in a breaking way. */
    public const API_VERSION = 1;

    /**
     * Parse a CSV or TSV file into an array of associative arrays keyed by header.
     *
     * Header names are lower-cased and stripped of BOM and control characters.
     * Blank lines are skipped.
     *
     * @param string $filepath Absolute path to the file.
     * @param array|null $headers Set to the normalised header names found in the file.
     * @return array Array of rows, each row is an associative array.
     */
    public static function parse_file(string $filepath, ?array &$headers = null): array {
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
     * Columns that every import file must contain.
     *
     * @return string[]
     */
    public static function required_columns(): array {
        return ['name', 'courseid', 'groupid', 'timestart'];
    }

    /**
     * Work out which required columns are missing from a file's header row.
     *
     * @param string[] $headers Normalised header names, as returned by parse_file().
     * @return string[] The missing column names, empty if none are missing.
     */
    public static function missing_columns(array $headers): array {
        return array_values(array_diff(self::required_columns(), $headers));
    }

    /**
     * Read an integer column from a row, treating a missing or blank cell as "use the default".
     *
     * @param array $row Associative array of columns.
     * @param string $column Column name.
     * @param int|null $default Value to use when the cell is missing or blank.
     * @return int|null
     */
    public static function int_column(array $row, string $column, ?int $default): ?int {
        $value = trim((string)($row[$column] ?? ''));
        return ($value === '') ? $default : (int)$value;
    }

    /**
     * Read a date and time, or a Unix timestamp, from a cell.
     *
     * Accepted: a Unix timestamp; DD/MM/YYYY HH:MM (seconds optional); DD/MM/YYYY (midnight);
     * YYYY-MM-DD HH:MM (seconds optional, a "T" separator is allowed). Day, month and hour may drop
     * their leading zero. Dates are read in the given time zone, or the current user's if none is given.
     *
     * @param string $value The cell value.
     * @param \DateTimeZone|null $timezone Time zone to read dates in.
     * @return int|null The Unix timestamp, or null if the value is blank or can't be read as a valid date.
     */
    public static function parse_time(string $value, ?\DateTimeZone $timezone = null): ?int {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int)$value;
        }
        $timezone = $timezone ?? \core_date::get_user_timezone_object();
        // A leading "!" resets fields the format doesn't set, so a date on its own means midnight.
        $formats = ['!d/m/Y H:i', '!d/m/Y H:i:s', '!d/m/Y', '!Y-m-d H:i', '!Y-m-d H:i:s', '!Y-m-d\TH:i', '!Y-m-d\TH:i:s'];
        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $value, $timezone);
            if ($date === false) {
                continue;
            }
            // Reject dates PHP would silently roll over, such as 31/02/2027.
            $errors = \DateTime::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                return null;
            }
            return $date->getTimestamp();
        }
        return null;
    }

    /**
     * Import every row of a CSV or TSV file.
     *
     * The file is checked for the required columns first; if any are missing, or the file has no
     * data rows, nothing is created and 'error' explains why.
     *
     * @param string $filepath Absolute path to the file.
     * @param bool $skipduplicates Whether to skip events that already exist.
     * @return array Keys: error (string, empty when the file was processed), counts (ok, skip, error),
     *               results (one create_event() result per row).
     */
    public static function import_file(string $filepath, bool $skipduplicates): array {
        $outcome = ['error' => '', 'counts' => ['ok' => 0, 'skip' => 0, 'error' => 0], 'results' => []];

        $headers = [];
        $rows = self::parse_file($filepath, $headers);
        $missing = self::missing_columns($headers);
        if (!empty($missing)) {
            $outcome['error'] = get_string('error_missingcolumns', 'local_grpcalendarimport', implode(', ', $missing));
            return $outcome;
        }
        if (empty($rows)) {
            $outcome['error'] = get_string('error_norows', 'local_grpcalendarimport');
            return $outcome;
        }

        $rownum = 1;
        foreach ($rows as $row) {
            $result = self::create_event($row, $skipduplicates, $rownum);
            $outcome['results'][] = $result;
            $outcome['counts'][$result['status']]++;
            $rownum++;
        }
        return $outcome;
    }

    /**
     * Create a single group calendar event from a row.
     *
     * @param array $row Associative array of columns, named after {event} fields.
     * @param bool $skipduplicates Whether to skip events that already exist.
     * @param int $rownum 1-based row number for reporting.
     * @return array Result array with keys: row, status (ok|skip|error), message.
     */
    public static function create_event(array $row, bool $skipduplicates, int $rownum): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/calendar/lib.php');

        $eventname   = trim($row['name'] ?? '');
        $courseid    = self::int_column($row, 'courseid', 0);
        $groupid     = self::int_column($row, 'groupid', 0);
        $timestartvalue = trim((string)($row['timestart'] ?? ''));
        $timestart   = self::parse_time($timestartvalue) ?? 0;
        $duration    = self::int_column($row, 'timeduration', 3600);
        $description = trim($row['description'] ?? '');
        $location    = trim($row['location'] ?? '');
        $visible     = self::int_column($row, 'visible', 1) ? 1 : 0;
        $eventtype   = trim($row['eventtype'] ?? '');
        $eventtype   = ($eventtype === '') ? 'group' : $eventtype;
        $uuid        = trim($row['uuid'] ?? '');
        $sequence    = self::int_column($row, 'sequence', 1);
        $priority    = self::int_column($row, 'priority', null);
        $timesort    = self::int_column($row, 'timesort', $timestart);
        $categoryid  = self::int_column($row, 'categoryid', 0);
        $userid      = self::int_column($row, 'userid', 0);

        // Basic validation.
        if (empty($eventname)) {
            return self::result($rownum, 'error', get_string('error_missingname', 'local_grpcalendarimport'));
        }
        if ($courseid <= 0) {
            return self::result($rownum, 'error', get_string('error_courseid', 'local_grpcalendarimport'));
        }
        if ($groupid <= 0) {
            return self::result($rownum, 'error', get_string('error_groupid', 'local_grpcalendarimport'));
        }
        if ($timestart <= 0) {
            // Say what was wrong with a filled-in cell; a blank cell keeps the general message.
            $error = ($timestartvalue === '')
                ? get_string('error_timestart', 'local_grpcalendarimport')
                : get_string('error_timestartformat', 'local_grpcalendarimport', $timestartvalue);
            return self::result($rownum, 'error', $error);
        }
        // This tool only creates group events; other types need fields that a CSV can't safely supply.
        if ($eventtype !== 'group') {
            return self::result($rownum, 'error', get_string('error_eventtype', 'local_grpcalendarimport', $eventtype));
        }

        // Verify course exists.
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            return self::result($rownum, 'error', get_string('error_coursenotfound', 'local_grpcalendarimport', $courseid));
        }

        // Verify group exists and belongs to this course.
        if (!$DB->record_exists('groups', ['id' => $groupid, 'courseid' => $courseid])) {
            $a = ['groupid' => $groupid, 'courseid' => $courseid];
            return self::result($rownum, 'error', get_string('error_groupnotfound', 'local_grpcalendarimport', $a));
        }

        if ($skipduplicates && self::event_exists($eventname, $courseid, $groupid, $timestart, $eventtype)) {
            return self::result($rownum, 'skip', get_string('result_duplicate', 'local_grpcalendarimport', $eventname));
        }

        // Build the event record using {event} table field names. Fields that link an event to
        // modules, subscriptions or repeat series are internal to Moodle and are not read from the row.
        $event = new \stdClass();
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
            $a = ['name' => $eventname, 'id' => $calendarevent->id];
            return self::result($rownum, 'ok', get_string('result_created', 'local_grpcalendarimport', $a));
        } catch (\Exception $e) {
            return self::result($rownum, 'error', get_string('error_exception', 'local_grpcalendarimport', $e->getMessage()));
        }
    }

    /**
     * Whether a matching event already exists (same name, course, group, start time and type).
     *
     * @param string $name Event name.
     * @param int $courseid Course id.
     * @param int $groupid Group id.
     * @param int $timestart Start time as a Unix timestamp.
     * @param string $eventtype Event type.
     * @return bool
     */
    public static function event_exists(string $name, int $courseid, int $groupid, int $timestart, string $eventtype): bool {
        global $DB;

        // The name column is TEXT, so compare it with sql_compare_text().
        $sql = "SELECT id
                  FROM {event}
                 WHERE " . $DB->sql_compare_text('name') . " = " . $DB->sql_compare_text(':name') . "
                   AND courseid = :courseid
                   AND groupid = :groupid
                   AND timestart = :timestart
                   AND eventtype = :eventtype";
        $params = [
            'name' => $name,
            'courseid' => $courseid,
            'groupid' => $groupid,
            'timestart' => $timestart,
            'eventtype' => $eventtype,
        ];
        return $DB->record_exists_sql($sql, $params);
    }

    /**
     * Build a result array.
     *
     * @param int $rownum 1-based row number.
     * @param string $status ok, skip or error.
     * @param string $message Message for the results table.
     * @return array
     */
    private static function result(int $rownum, string $status, string $message): array {
        return ['row' => $rownum, 'status' => $status, 'message' => $message];
    }
}

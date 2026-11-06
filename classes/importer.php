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

    /** @var int Most rows the upload page imports from one file; larger files are rejected before anything is created. */
    public const MAX_UPLOAD_ROWS = 2000;

    /** @var int Most ids looked up in one database query. */
    private const LOOKUP_CHUNK = 1000;

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
     * Accepted: a Unix timestamp; DD/MM/YYYY HH:MM (seconds optional, or 12-hour with AM/PM); DD/MM/YYYY (midnight);
     * YYYY-MM-DD HH:MM (seconds optional, a "T" separator is allowed). Day, month and hour may drop
     * their leading zero; the year must have four digits. Dates are read in the given time zone, or the
     * current user's if none is given. A time that doesn't exist there, such as one skipped when daylight
     * saving starts, is rejected rather than moved.
     *
     * @param string $value The cell value.
     * @param \DateTimeZone|null $timezone Time zone to read dates in.
     * @return int|null The Unix timestamp, or null if the value is blank or can't be read as a valid date.
     */
    public static function parse_time(string $value, ?\DateTimeZone $timezone = null): ?int {
        $value = trim($value);
        // PHP's date parser throws on null bytes, which would stop the whole import, so reject them here.
        if ($value === '' || str_contains($value, "\0")) {
            return null;
        }
        if (ctype_digit($value)) {
            return (int)$value;
        }
        $timezone = $timezone ?? \core_date::get_user_timezone_object();
        // A leading "!" resets fields the format doesn't set, so a date on its own means midnight.
        // The 12-hour forms (9:00 AM, 9:00am) are what spreadsheets often write when saving as CSV.
        $formats = [
            '!d/m/Y H:i', '!d/m/Y H:i:s', '!d/m/Y g:i A', '!d/m/Y g:i:s A', '!d/m/Y g:iA', '!d/m/Y',
            '!Y-m-d H:i', '!Y-m-d H:i:s', '!Y-m-d\TH:i', '!Y-m-d\TH:i:s',
        ];
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
            // The date must be exactly what was typed: a four-digit year, and no shift such as a
            // 02:30 that daylight saving skips becoming 03:30.
            $typed = date_parse_from_format($format, $value);
            $created = [
                'year' => (int)$date->format('Y'), 'month' => (int)$date->format('n'), 'day' => (int)$date->format('j'),
                'hour' => (int)$date->format('G'), 'minute' => (int)$date->format('i'), 'second' => (int)$date->format('s'),
            ];
            foreach ($created as $field => $number) {
                if ((int)$typed[$field] !== $number) {
                    return null;
                }
            }
            if ($created['year'] < 1000) {
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
     * Every call is recorded in the import history (see import_log), including each failed row and
     * its values, and logged as events.
     *
     * @param string $filepath Absolute path to the file.
     * @param bool $skipduplicates Whether to skip events that already exist.
     * @param string $filename Name to show for the file in the history; defaults to the file's own name.
     * @param string $source What is running the import, shown in the history: e.g. 'upload' or a component
     *                       name such as 'tool_automate'. Defaults to 'api'.
     * @param int $maxrows Reject the whole file, creating nothing, if it has more data rows than this; 0 for no limit.
     * @return array Keys: error (string, empty when the file was processed), counts (ok, skip, error),
     *               results (one create_event() result per row), runid (import history id, 0 if not recorded).
     */
    public static function import_file(
        string $filepath,
        bool $skipduplicates,
        string $filename = '',
        string $source = '',
        int $maxrows = 0
    ): array {
        $outcome = ['error' => '', 'counts' => ['ok' => 0, 'skip' => 0, 'error' => 0], 'results' => []];

        $headers = [];
        $rows = self::parse_file($filepath, $headers);
        $missing = self::missing_columns($headers);
        if (!empty($missing)) {
            $outcome['error'] = get_string('error_missingcolumns', 'local_grpcalendarimport', implode(', ', $missing));
        } else if (empty($rows)) {
            $outcome['error'] = get_string('error_norows', 'local_grpcalendarimport');
        } else if ($maxrows > 0 && count($rows) > $maxrows) {
            $a = (object)['rows' => count($rows), 'max' => $maxrows];
            $outcome['error'] = get_string('error_toomanyrows', 'local_grpcalendarimport', $a);
        } else {
            // Look up every course, group and user in the file once, rather than once per row.
            [$courses, $groups, $users] = self::existing_records($rows);
            $rownum = 1;
            foreach ($rows as $row) {
                $result = self::create_event_checked($row, $skipduplicates, $rownum, $courses, $groups, $users);
                $outcome['results'][] = $result;
                $outcome['counts'][$result['status']]++;
                $rownum++;
            }
        }

        $outcome['runid'] = import_log::record(
            $filename !== '' ? $filename : basename($filepath),
            $source !== '' ? $source : 'api',
            $skipduplicates,
            $outcome,
            $rows
        );
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
        return self::create_event_checked($row, $skipduplicates, $rownum, null, null, null);
    }

    /**
     * Find which of the courses, groups and users named in some rows exist, one query each for the whole set.
     *
     * @param array $rows Rows, as returned by parse_file().
     * @return array Three arrays: course ids that exist (as keys, values true), the course id of each group
     *               that exists (keyed by group id), and user ids that exist and aren't deleted (as keys).
     */
    private static function existing_records(array $rows): array {
        global $DB;

        $courseids = [];
        $groupids = [];
        $userids = [];
        foreach ($rows as $row) {
            $courseid = self::int_column($row, 'courseid', 0);
            if ($courseid > 0) {
                $courseids[$courseid] = $courseid;
            }
            $groupid = self::int_column($row, 'groupid', 0);
            if ($groupid > 0) {
                $groupids[$groupid] = $groupid;
            }
            $userid = self::int_column($row, 'userid', 0);
            if ($userid > 0) {
                $userids[$userid] = $userid;
            }
        }

        // Chunked, so a file naming thousands of ids stays within the database's limit on query parameters.
        $courses = [];
        foreach (array_chunk($courseids, self::LOOKUP_CHUNK) as $chunk) {
            foreach ($DB->get_records_list('course', 'id', $chunk, '', 'id') as $course) {
                $courses[(int)$course->id] = true;
            }
        }
        $groups = [];
        foreach (array_chunk($groupids, self::LOOKUP_CHUNK) as $chunk) {
            foreach ($DB->get_records_list('groups', 'id', $chunk, '', 'id, courseid') as $group) {
                $groups[(int)$group->id] = (int)$group->courseid;
            }
        }
        $users = [];
        foreach (array_chunk($userids, self::LOOKUP_CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            foreach ($DB->get_records_select('user', "id {$insql} AND deleted = 0", $params, '', 'id') as $user) {
                $users[(int)$user->id] = true;
            }
        }
        return [$courses, $groups, $users];
    }

    /**
     * Create a single group calendar event from a row, using already looked-up courses, groups and users if given.
     *
     * @param array $row Associative array of columns, named after {event} fields.
     * @param bool $skipduplicates Whether to skip events that already exist.
     * @param int $rownum 1-based row number for reporting.
     * @param bool[]|null $courses Course ids that exist, as keys; null to look the row's course up.
     * @param int[]|null $groups Course id of each group that exists, keyed by group id; null to look the row's group up.
     * @param bool[]|null $users User ids that exist and aren't deleted, as keys; null to look the row's user up.
     * @return array Result array with keys: row, status (ok|skip|error), message.
     */
    private static function create_event_checked(
        array $row,
        bool $skipduplicates,
        int $rownum,
        ?array $courses,
        ?array $groups,
        ?array $users
    ): array {
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
        $courseexists = ($courses !== null)
            ? isset($courses[$courseid])
            : $DB->record_exists('course', ['id' => $courseid]);
        if (!$courseexists) {
            return self::result($rownum, 'error', get_string('error_coursenotfound', 'local_grpcalendarimport', $courseid));
        }

        // Verify group exists and belongs to this course.
        $groupexists = ($groups !== null)
            ? (($groups[$groupid] ?? 0) === $courseid)
            : $DB->record_exists('groups', ['id' => $groupid, 'courseid' => $courseid]);
        if (!$groupexists) {
            $a = ['groupid' => $groupid, 'courseid' => $courseid];
            return self::result($rownum, 'error', get_string('error_groupnotfound', 'local_grpcalendarimport', $a));
        }

        // A userid of 0 or a blank cell is left for calendar_event to fill in with the current user; any other value
        // must be a user who exists and isn't deleted.
        if ($userid !== 0) {
            $userexists = ($users !== null)
                ? isset($users[$userid])
                : $DB->record_exists('user', ['id' => $userid, 'deleted' => 0]);
            if (!$userexists) {
                return self::result($rownum, 'error', get_string('error_usernotfound', 'local_grpcalendarimport', $userid));
            }
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
            // The exception's text can include SQL and table details, so it stays out of the results, the import
            // history and the logs. Only its error code is shown; developers get the full text through debugging().
            debugging("Group calendar import, row {$rownum}: " . $e->getMessage(), DEBUG_DEVELOPER);
            $code = ($e instanceof \moodle_exception) ? $e->errorcode : (new \ReflectionClass($e))->getShortName();
            return self::result($rownum, 'error', get_string('error_createfailed', 'local_grpcalendarimport', $code));
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

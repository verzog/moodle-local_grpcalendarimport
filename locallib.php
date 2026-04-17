<?php
// This file is part of Moodle - http://moodle.org/
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
 * @copyright 2026 SCCA
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Parse a CSV or TSV file into an array of associative arrays keyed by header.
 *
 * @param string $filepath Absolute path to the uploaded file.
 * @return array Array of rows, each row is an associative array.
 */
function local_grpcalendarimport_parse_csv(string $filepath): array {
    $rows = [];
    if (!is_readable($filepath)) {
        return $rows;
    }
    $handle = fopen($filepath, 'r');
    if (!$handle) {
        return $rows;
    }

    // Detect delimiter from the first line.
    $firstline = fgets($handle);
    rewind($handle);
    $delimiter = (substr_count($firstline, "\t") > substr_count($firstline, ',')) ? "\t" : ',';

    $headers = null;
    while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
        if ($headers === null) {
            // Normalise headers: strip BOM and control characters.
            $headers = array_map(function ($h) {
                return trim(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $h));
            }, $line);
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

    $eventname      = trim($row['name'] ?? '');
    $courseid       = (int)($row['courseid'] ?? 0);
    $groupid        = (int)($row['groupid'] ?? 0);
    $timestart      = (int)($row['timestart'] ?? 0);
    $duration       = (int)($row['timeduration'] ?? 3600);
    $description    = trim($row['description'] ?? '');
    $location       = trim($row['location'] ?? '');
    $visible        = isset($row['visible']) ? (int)$row['visible'] : 1;
    $eventtype      = trim($row['eventtype'] ?? 'group');
    $uuid           = trim($row['uuid'] ?? '');
    $sequence       = (int)($row['sequence'] ?? 1);
    $priority       = (isset($row['priority']) && $row['priority'] !== '') ? (int)$row['priority'] : null;
    $subscriptionid = (isset($row['subscriptionid']) && $row['subscriptionid'] !== '') ? (int)$row['subscriptionid'] : null;
    $timesort       = (isset($row['timesort']) && $row['timesort'] !== '') ? (int)$row['timesort'] : $timestart;
    $type           = (int)($row['type'] ?? 0);
    $component      = trim($row['component'] ?? '');
    $modulename     = trim($row['modulename'] ?? '');
    $instance       = (int)($row['instance'] ?? 0);
    $repeatid       = (int)($row['repeatid'] ?? 0);
    $categoryid     = (int)($row['categoryid'] ?? 0);
    $userid         = (int)($row['userid'] ?? 0);

    // Basic validation.
    if (empty($eventname)) {
        return ['row' => $rownum, 'status' => 'error', 'message' => 'Missing name.'];
    }
    if ($courseid <= 0) {
        return ['row' => $rownum, 'status' => 'error', 'message' => 'Invalid or missing courseid.'];
    }
    if ($groupid <= 0) {
        return ['row' => $rownum, 'status' => 'error', 'message' => 'Invalid or missing groupid.'];
    }
    if ($timestart <= 0) {
        return ['row' => $rownum, 'status' => 'error', 'message' => 'Invalid or missing timestart.'];
    }

    // Verify course exists.
    if (!$DB->record_exists('course', ['id' => $courseid])) {
        return ['row' => $rownum, 'status' => 'error', 'message' => "Course ID $courseid not found."];
    }

    // Verify group exists and belongs to this course.
    if (!$DB->record_exists('groups', ['id' => $groupid, 'courseid' => $courseid])) {
        return ['row' => $rownum, 'status' => 'error', 'message' => "Group ID $groupid not found in course $courseid."];
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
            return ['row' => $rownum, 'status' => 'skip', 'message' => "Duplicate skipped: $eventname."];
        }
    }

    // Build the event record using {event} table field names.
    $event = new stdClass();
    $event->name           = $eventname;
    $event->description    = $description;
    $event->format         = FORMAT_PLAIN;
    $event->categoryid     = $categoryid;
    $event->courseid       = $courseid;
    $event->groupid        = $groupid;
    $event->userid         = $userid;
    $event->repeatid       = $repeatid;
    $event->component      = $component;
    $event->modulename     = $modulename;
    $event->instance       = $instance;
    $event->type           = $type;
    $event->eventtype      = $eventtype ?: 'group';
    $event->timestart      = $timestart;
    $event->timeduration   = $duration;
    $event->timesort       = $timesort;
    $event->visible        = $visible;
    $event->uuid           = $uuid;
    $event->sequence       = $sequence;
    $event->timemodified   = time();
    $event->subscriptionid = $subscriptionid;
    $event->priority       = $priority;
    $event->location       = $location;

    try {
        $calendarevent = \calendar_event::create($event, false);
        return [
            'row'     => $rownum,
            'status'  => 'ok',
            'message' => "Created: \"$eventname\" (event ID {$calendarevent->id}).",
        ];
    } catch (Exception $e) {
        return ['row' => $rownum, 'status' => 'error', 'message' => 'Exception: ' . $e->getMessage()];
    }
}

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

defined('MOODLE_INTERNAL') || die();

/**
 * Process imported calendar events and store them in the database.
 *
 * @param array $events Array of event data to import.
 * @return array Array of processing results.
 */
function local_grpcalendarimport_process_events(array $events): array {
    global $DB;

    $results = [];

    foreach ($events as $event) {
        $eventobj = new \stdClass();
        $eventobj->name = $event['name'] ?? '';
        $eventobj->description = $event['description'] ?? '';
        $eventobj->descriptionformat = FORMAT_HTML;
        $eventobj->courseid = (int)($event['courseid'] ?? 0);
        $eventobj->groupid = (int)($event['groupid'] ?? 0);
        $eventobj->userid = (int)($event['userid'] ?? 0);
        $eventobj->timestart = (int)($event['timestart'] ?? 0);
        $eventobj->timeduration = (int)($event['timeduration'] ?? 3600);
        $eventobj->visible = (int)($event['visible'] ?? 1);

        $eventid = $DB->insert_record('event', $eventobj);
        $results[] = [
            'eventid' => $eventid,
            'name' => $eventobj->name,
            'status' => 'created',
        ];
    }

    return $results;
}

/**
 * Validate event data before import.
 *
 * @param array $event Event data to validate.
 * @return array Array with 'valid' boolean and optional 'errors' array.
 */
function local_grpcalendarimport_validate_event(array $event): array {
    $errors = [];

    if (empty($event['name'])) {
        $errors[] = 'Event name is required.';
    }

    if (empty($event['courseid'])) {
        $errors[] = 'Course ID is required.';
    }

    if (empty($event['groupid'])) {
        $errors[] = 'Group ID is required.';
    }

    if (empty($event['timestart'])) {
        $errors[] = 'Event start time is required.';
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
    ];
}

/**
 * Get summary statistics for imported events.
 *
 * @param int $courseid Course ID to filter by (optional).
 * @return array Statistics array.
 */
function local_grpcalendarimport_get_statistics(int $courseid = 0): array {
    global $DB;

    $where = '';
    $params = [];

    if ($courseid > 0) {
        $where = 'WHERE courseid = ?';
        $params = [$courseid];
    }

    $total = $DB->count_records('event', $where ? ['courseid' => $courseid] : []);

    return [
        'total_events' => $total,
        'courseid' => $courseid,
        'timestamp' => time(),
    ];
}

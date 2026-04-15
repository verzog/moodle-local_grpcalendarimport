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
 * Import group calendar events from a CSV file.
 *
 * Expected CSV columns match the Moodle {event} table fields:
 *   id, name, description, categoryid, courseid, groupid, userid,
 *   repeatid, component, modulename, instance, type, eventtype,
 *   timestart, timeduration, timesort, visible, uuid, sequence,
 *   timemodified, subscriptionid, priority, location
 *
 * Required: name, courseid, groupid, timestart
 * Recommended: timeduration, description, location, visible, eventtype
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 SCCA
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

admin_externalpage_setup('local_grpcalendarimport');
require_capability('local/grpcalendarimport:manage', context_system::instance());

// Upload form definition.
/**
 * Form for uploading a CSV file of calendar events.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 SCCA
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_grpcalendarimport_form extends moodleform {
    /**
     * Define the form fields.
     */
    public function definition() {
        $mform = $this->_form;
        $mform->addElement(
            'header',
            'importheader',
            get_string('import_heading', 'local_grpcalendarimport')
        );
        $mform->addElement(
            'filepicker',
            'csvfile',
            get_string('upload_csv', 'local_grpcalendarimport'),
            null,
            [
                'accepted_types' => ['.csv', '.txt', '.tsv'],
                'maxbytes'       => 5 * 1024 * 1024,
            ]
        );
        $mform->addRule('csvfile', null, 'required');
        $mform->addElement(
            'advcheckbox',
            'skipduplicates',
            'Skip duplicate events',
            'Skip if an event with the same name, course and timestart already exists',
            [],
            [0, 1]
        );
        $mform->setDefault('skipduplicates', 1);
        $this->add_action_buttons(false, get_string('import_button', 'local_grpcalendarimport'));
    }
}

/**
 * Parse a CSV or TSV file into an array of associative arrays keyed by header.
 *
 * @param string $filepath Absolute path to the uploaded file.
 * @return array Array of rows, each row is an associative array.
 */
function local_grpcalendarimport_parse_csv(string $filepath): array {
    $rows = [];
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
    global $DB;

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
        $exists = $DB->record_exists_sql($sql, [
            'name'      => $eventname,
            'courseid'  => $courseid,
            'groupid'   => $groupid,
            'timestart' => $timestart,
            'eventtype' => $eventtype,
        ]);
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

// Page setup.
$PAGE->set_url(new moodle_url('/local/grpcalendarimport/index.php'));
$PAGE->set_title(get_string('import_heading', 'local_grpcalendarimport'));
$PAGE->set_heading(get_string('import_heading', 'local_grpcalendarimport'));

$form    = new local_grpcalendarimport_form();
$results = [];

if ($form->is_cancelled()) {
    redirect(new moodle_url('/admin/index.php'));
} else if ($data = $form->get_data()) {
    // Save uploaded file to a temp location.
    $tmpfile = $form->save_temp_file('csvfile');

    if (!$tmpfile) {
        \core\notification::error('Could not read uploaded file.');
    } else {
        $rows      = local_grpcalendarimport_parse_csv($tmpfile);
        $skipdupes = !empty($data->skipduplicates);
        $rownum    = 1;
        $counts    = ['ok' => 0, 'skip' => 0, 'error' => 0];

        foreach ($rows as $row) {
            $result    = local_grpcalendarimport_create_event($row, $skipdupes, $rownum);
            $results[] = $result;
            $counts[$result['status']]++;
            $rownum++;
        }
        @unlink($tmpfile);

        \core\notification::success(
            "Import complete — Created: {$counts['ok']}, Skipped: {$counts['skip']}, Errors: {$counts['error']}."
        );
    }
}

// Output.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('import_heading', 'local_grpcalendarimport'));

$form->display();

if (!empty($results)) {
    echo $OUTPUT->heading(get_string('results_heading', 'local_grpcalendarimport'), 3);

    $table             = new html_table();
    $table->head       = ['Row', 'Event Name / Message', 'Status'];
    $table->attributes = ['class' => 'generaltable'];

    foreach ($results as $r) {
        if ($r['status'] === 'ok') {
            $statuslabel = html_writer::tag('span', '&#x2714; Created', ['style' => 'color:green;font-weight:bold']);
        } else if ($r['status'] === 'skip') {
            $statuslabel = html_writer::tag('span', '&#x23ED; Skipped', ['style' => 'color:orange']);
        } else {
            $statuslabel = html_writer::tag('span', '&#x2716; Error', ['style' => 'color:red;font-weight:bold']);
        }
        $table->data[] = [$r['row'], htmlspecialchars($r['message']), $statuslabel];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();

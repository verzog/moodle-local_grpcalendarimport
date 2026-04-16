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
require_once(__DIR__ . '/locallib.php');

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

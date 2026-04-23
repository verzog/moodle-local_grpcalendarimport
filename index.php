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
            get_string('skipduplicates', 'local_grpcalendarimport'),
            get_string('skipduplicates_desc', 'local_grpcalendarimport'),
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
        \core\notification::error(get_string('upload_error', 'local_grpcalendarimport'));
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
        unlink($tmpfile);

        \core\notification::success(
            "Import complete — Created: {$counts['ok']}, Skipped: {$counts['skip']}, Errors: {$counts['error']}."
        );
    }
}

// Output.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('import_heading', 'local_grpcalendarimport'));

// Instructions panel (collapsed by default; expands on click).
$sampleurl = new moodle_url('/local/grpcalendarimport/download.php');

$reqtable = new html_table();
$reqtable->attributes = ['class' => 'table table-sm table-bordered mb-3'];
$reqtable->head = [
    get_string('description_column', 'local_grpcalendarimport'),
    'Description',
];
$reqtable->data = [
    [html_writer::tag('code', 'name'),      'Event title'],
    [html_writer::tag('code', 'courseid'),  'Moodle course ID — visible in the course URL '
        . '(e.g. ' . html_writer::tag('code', '/course/view.php?id=2') . ')'],
    [html_writer::tag('code', 'groupid'),   'Moodle group ID — must belong to the course '
        . '(find via Course &rsaquo; Participants &rsaquo; Groups)'],
    [html_writer::tag('code', 'timestart'), 'Event start as a Unix timestamp '
        . '(e.g. ' . html_writer::tag('code', '1746086400') . ' = 2026-05-01 08:00 UTC)'],
];

$rectable = new html_table();
$rectable->attributes = ['class' => 'table table-sm table-bordered mb-3'];
$rectable->head = [
    get_string('description_column', 'local_grpcalendarimport'),
    'Description',
    get_string('default_value', 'local_grpcalendarimport'),
];
$rectable->data = [
    [html_writer::tag('code', 'timeduration'), 'Duration in seconds',     '3600 (1 hour)'],
    [html_writer::tag('code', 'eventtype'),    'Calendar event type',     '"group"'],
    [html_writer::tag('code', 'description'),  'Event description text',  '(empty)'],
    [html_writer::tag('code', 'location'),     'Location string',         '(empty)'],
    [html_writer::tag('code', 'visible'),      '1 = visible, 0 = hidden', '1'],
];

$opttable = new html_table();
$opttable->attributes = ['class' => 'table table-sm table-bordered mb-3'];
$opttable->head = [
    get_string('description_column', 'local_grpcalendarimport'),
    'Description',
];
$opttable->data = [
    [html_writer::tag('code', 'categoryid'),     'Category ID'],
    [html_writer::tag('code', 'userid'),         'User ID to associate with the event'],
    [html_writer::tag('code', 'timesort'),       'Sort timestamp (defaults to timestart)'],
    [html_writer::tag('code', 'uuid'),           'Unique identifier for external sync'],
    [html_writer::tag('code', 'priority'),       'Display priority'],
    [html_writer::tag('code', 'subscriptionid'), 'Calendar subscription ID'],
];

$samplecsv = implode("\n", [
    'name,courseid,groupid,timestart,timeduration,description,location,eventtype,visible',
    'Team Meeting,2,5,1746086400,3600,Weekly sync,Room 101,group,1',
    'Practice Session,2,6,1746172800,5400,Pre-game warmup,Field B,group,1',
    'Championship Race,3,7,1748736000,7200,Regional finals,Track A,group,1',
]);

$panelbody = html_writer::tag('p', get_string('csv_format_intro', 'local_grpcalendarimport'));
$panelbody .= html_writer::tag('h6',
    get_string('required_columns', 'local_grpcalendarimport'),
    ['class' => 'fw-bold mt-3']);
$panelbody .= html_writer::table($reqtable);
$panelbody .= html_writer::tag('h6',
    get_string('recommended_columns', 'local_grpcalendarimport'),
    ['class' => 'fw-bold mt-3']);
$panelbody .= html_writer::table($rectable);
$panelbody .= html_writer::tag('h6',
    get_string('optional_columns', 'local_grpcalendarimport'),
    ['class' => 'fw-bold mt-3']);
$panelbody .= html_writer::table($opttable);
$panelbody .= html_writer::tag('h6',
    get_string('sample_csv_heading', 'local_grpcalendarimport'),
    ['class' => 'fw-bold mt-3']);
$panelbody .= html_writer::tag('pre', htmlspecialchars($samplecsv),
    ['class' => 'bg-light p-2 border rounded small']);
$panelbody .= html_writer::link($sampleurl,
    get_string('download_sample', 'local_grpcalendarimport'),
    ['class' => 'btn btn-sm btn-outline-secondary']);

$togglebtn = html_writer::tag(
    'button',
    get_string('instructions_heading', 'local_grpcalendarimport'),
    [
        'type'           => 'button',
        'class'          => 'btn btn-link text-start w-100 p-0 text-decoration-none fw-semibold',
        'data-bs-toggle' => 'collapse',
        'data-bs-target' => '#grpcalinstructions',
        'aria-expanded'  => 'true',
        'aria-controls'  => 'grpcalinstructions',
    ]
);
$cardheader = html_writer::div($togglebtn, 'card-header');
$collapseinner = html_writer::div($panelbody, 'card-body');
$collapsediv = html_writer::tag('div', $collapseinner,
    ['class' => 'collapse show', 'id' => 'grpcalinstructions']);
echo html_writer::div($cardheader . $collapsediv, 'card mb-4');

$form->display();

if (!empty($results)) {
    echo $OUTPUT->heading(get_string('results_heading', 'local_grpcalendarimport'), 3);

    $table             = new html_table();
    $table->head       = [
        get_string('row', 'local_grpcalendarimport'),
        get_string('event_message', 'local_grpcalendarimport'),
        get_string('status', 'local_grpcalendarimport'),
    ];
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

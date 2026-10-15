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
 * Import group calendar events from a CSV file.
 *
 * Required columns: name, courseid, groupid, timestart.
 * Recommended: timeduration, description, location, visible, eventtype.
 * Optional: categoryid, userid, timesort, uuid, sequence, priority.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/locallib.php');

admin_externalpage_setup('local_grpcalendarimport');
require_capability('local/grpcalendarimport:manage', context_system::instance());

// Page setup.
$PAGE->set_url(new moodle_url('/local/grpcalendarimport/index.php'));
$PAGE->set_title(get_string('import_heading', 'local_grpcalendarimport'));
$PAGE->set_heading(get_string('import_heading', 'local_grpcalendarimport'));

$form    = new \local_grpcalendarimport\form\import_form();
$results = [];

if ($form->is_cancelled()) {
    redirect(new moodle_url('/admin/index.php'));
} else if ($data = $form->get_data()) {
    // Save uploaded file to a temp location.
    $tmpfile = $form->save_temp_file('csvfile');

    if (!$tmpfile) {
        \core\notification::error(get_string('upload_error', 'local_grpcalendarimport'));
    } else {
        $outcome = \local_grpcalendarimport\importer::import_file($tmpfile, !empty($data->skipduplicates));
        unlink($tmpfile);

        if ($outcome['error'] !== '') {
            \core\notification::error($outcome['error']);
        } else {
            $results = $outcome['results'];
            \core\notification::success(get_string('import_summary', 'local_grpcalendarimport', (object)$outcome['counts']));
        }
    }
}

// Output.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('import_heading', 'local_grpcalendarimport'));

// Instructions panel (expanded by default; collapses on click).
$sampleurl = new moodle_url('/local/grpcalendarimport/download.php');
$code = function (string $text): string {
    return html_writer::tag('code', s($text));
};
$coldesc = function (string $column, $a = null): string {
    return get_string('coldesc_' . $column, 'local_grpcalendarimport', $a);
};
$columnheading = get_string('description_column', 'local_grpcalendarimport');
$descheading = get_string('description_heading', 'local_grpcalendarimport');
$tableclass = ['class' => 'table table-sm table-bordered mb-3'];

$example = 1809298800;
$reqtable = new html_table();
$reqtable->attributes = $tableclass;
$reqtable->head = [$columnheading, $descheading];
$reqtable->data = [
    [$code('name'), $coldesc('name')],
    [$code('courseid'), $coldesc('courseid', $code('/course/view.php?id=2'))],
    [$code('groupid'), $coldesc('groupid')],
    [
        $code('timestart'),
        $coldesc('timestart', (object)[
            'timestamp' => $code((string)$example),
            'date' => userdate($example, '%d/%m/%Y %H:%M'),
        ]),
    ],
];

$empty = get_string('default_empty', 'local_grpcalendarimport');
$rectable = new html_table();
$rectable->attributes = $tableclass;
$rectable->head = [$columnheading, $descheading, get_string('default_value', 'local_grpcalendarimport')];
$rectable->data = [
    [$code('timeduration'), $coldesc('timeduration'), get_string('default_timeduration', 'local_grpcalendarimport')],
    [$code('eventtype'), $coldesc('eventtype'), $code('group')],
    [$code('description'), $coldesc('description'), $empty],
    [$code('location'), $coldesc('location'), $empty],
    [$code('visible'), $coldesc('visible'), $code('1')],
];

$opttable = new html_table();
$opttable->attributes = $tableclass;
$opttable->head = [$columnheading, $descheading];
$opttable->data = [];
foreach (['categoryid', 'userid', 'timesort', 'uuid', 'sequence', 'priority'] as $column) {
    $opttable->data[] = [$code($column), $coldesc($column)];
}

$sectionheading = function (string $identifier): string {
    return html_writer::tag('h6', get_string($identifier, 'local_grpcalendarimport'), ['class' => 'fw-bold mt-3']);
};

$panelbody = html_writer::tag('p', get_string('csv_format_intro', 'local_grpcalendarimport'));
$panelbody .= $sectionheading('required_columns');
$panelbody .= html_writer::table($reqtable);
$panelbody .= $sectionheading('recommended_columns');
$panelbody .= html_writer::table($rectable);
$panelbody .= $sectionheading('optional_columns');
$panelbody .= html_writer::table($opttable);
$panelbody .= $sectionheading('sample_csv_heading');
$panelbody .= html_writer::tag(
    'pre',
    s(implode("\n", local_grpcalendarimport_sample_csv_lines())),
    ['class' => 'bg-light p-2 border rounded small']
);
$panelbody .= html_writer::link(
    $sampleurl,
    get_string('download_sample', 'local_grpcalendarimport'),
    ['class' => 'btn btn-sm btn-outline-secondary']
);

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
$collapsediv = html_writer::tag(
    'div',
    $collapseinner,
    ['class' => 'collapse show', 'id' => 'grpcalinstructions']
);
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

    $badgeclasses = [
        'ok'    => 'badge bg-success',
        'skip'  => 'badge bg-warning text-dark',
        'error' => 'badge bg-danger',
    ];
    foreach ($results as $r) {
        $statuslabel = html_writer::span(
            get_string('status_' . $r['status'], 'local_grpcalendarimport'),
            $badgeclasses[$r['status']]
        );
        $table->data[] = [$r['row'], s($r['message']), $statuslabel];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();

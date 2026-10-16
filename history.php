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
 * Import history: every import run, and the failed rows of one run.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_grpcalendarimport\import_log;
use local_grpcalendarimport\template;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$runid = optional_param('id', 0, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$dataformat = optional_param('dataformat', '', PARAM_ALPHA);

$pageurl = new moodle_url('/local/grpcalendarimport/history.php');
admin_externalpage_setup('local_grpcalendarimport_history', '', null, $pageurl->out(false));
require_capability('local/grpcalendarimport:manage', context_system::instance());

$component = 'local_grpcalendarimport';
$perpage = 50;

if ($runid) {
    $run = $DB->get_record(import_log::RUN_TABLE, ['id' => $runid], '*', MUST_EXIST);

    // Download this run's failed rows: row number, error, then every column the rows had.
    if ($dataformat !== '') {
        require_sesskey();
        if (!in_array($dataformat, ['csv', 'excel'], true)) {
            throw new moodle_exception('invalidparameter', 'debug');
        }
        // Read the rows twice, a record at a time: once for the column names, once to write them out.
        $columns = [];
        $rs = $DB->get_recordset(import_log::FAIL_TABLE, ['runid' => $run->id], '', 'id, rowdata');
        foreach ($rs as $failure) {
            $columns += array_fill_keys(array_keys((array)json_decode((string)$failure->rowdata, true)), true);
        }
        $rs->close();
        $columns = array_keys($columns);
        $failures = $DB->get_recordset(import_log::FAIL_TABLE, ['runid' => $run->id], 'rownum, id');
        \core\dataformat::download_data(
            'import_failures_' . $run->id,
            $dataformat,
            // Headings and values come from the uploaded file, so quote any that a spreadsheet would run as a formula.
            array_map([template::class, 'escape_formula'], array_merge(['row', 'error'], $columns)),
            $failures,
            function ($failure) use ($columns): array {
                $values = (array)json_decode((string)$failure->rowdata, true);
                $record = [$failure->rownum, template::escape_formula((string)$failure->message)];
                foreach ($columns as $column) {
                    $record[] = template::escape_formula((string)($values[$column] ?? ''));
                }
                return $record;
            }
        );
        $failures->close();
        die();
    }
}

$PAGE->set_url($runid ? new moodle_url($pageurl, ['id' => $runid]) : $pageurl);
$PAGE->set_title(get_string('history_heading', $component));
$PAGE->set_heading(get_string('history_heading', $component));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('history_heading', $component));

if ($runid) {
    // One run: its summary, then each failed row with the values it had.
    $user = $run->userid ? core_user::get_user($run->userid) : null;
    $summary = new html_table();
    $summary->attributes = ['class' => 'generaltable'];
    $summary->data = [
        [get_string('history_date', $component), userdate($run->timecreated, get_string('strftimedatetimeshort', 'langconfig'))],
        [get_string('history_user', $component), $user ? fullname($user) : '-'],
        [get_string('history_file', $component), s($run->filename)],
        [get_string('history_source', $component), s($run->source)],
        [get_string('skipduplicates', $component), $run->skipduplicates ? get_string('yes') : get_string('no')],
        [get_string('status_ok', $component), $run->countcreated],
        [get_string('history_skipped', $component), $run->countskipped],
        [get_string('status_error', $component), $run->counterrors],
    ];
    if (!empty($run->fileerror)) {
        $summary->data[] = [get_string('history_fileerror', $component), s($run->fileerror)];
    }
    echo html_writer::table($summary);

    // Failed rows are stored without a limit, so show them a page at a time.
    $failcount = $DB->count_records(import_log::FAIL_TABLE, ['runid' => $run->id]);
    if ($failcount) {
        $failures = $DB->get_records(import_log::FAIL_TABLE, ['runid' => $run->id], 'rownum, id', '*', $page * $perpage, $perpage);
        echo $OUTPUT->heading(get_string('history_failures', $component), 3);
        foreach (['csv', 'excel'] as $format) {
            echo $OUTPUT->single_button(
                new moodle_url($pageurl, ['id' => $run->id, 'dataformat' => $format]),
                get_string('history_download_' . $format, $component),
                'post'
            );
        }
        $table = new html_table();
        $table->attributes = ['class' => 'generaltable'];
        $table->head = [
            get_string('row', $component),
            get_string('status_error', $component),
            get_string('history_rowdata', $component),
        ];
        foreach ($failures as $failure) {
            $cells = '';
            foreach ((array)json_decode((string)$failure->rowdata, true) as $column => $value) {
                $cells .= html_writer::tag('dt', s($column), ['class' => 'col-4 fw-normal text-muted'])
                    . html_writer::tag('dd', s((string)$value), ['class' => 'col-8 mb-0']);
            }
            $table->data[] = [
                $failure->rownum,
                s($failure->message),
                html_writer::tag('dl', $cells, ['class' => 'row small mb-0']),
            ];
        }
        echo html_writer::table($table);
        echo $OUTPUT->paging_bar($failcount, $page, $perpage, new moodle_url($pageurl, ['id' => $run->id]));
    } else if (empty($run->fileerror)) {
        echo $OUTPUT->notification(get_string('history_nofailures', $component), \core\output\notification::NOTIFY_SUCCESS);
    }
    echo html_writer::link($pageurl, get_string('history_back', $component), ['class' => 'btn btn-link px-0']);
} else {
    // All runs, newest first.
    $total = $DB->count_records(import_log::RUN_TABLE);
    if ($total === 0) {
        echo $OUTPUT->notification(get_string('history_none', $component), \core\output\notification::NOTIFY_INFO);
    } else {
        $userfields = \core_user\fields::for_name()->get_sql('u', true);
        $sql = "SELECT r.* {$userfields->selects}
                  FROM {" . import_log::RUN_TABLE . "} r
             LEFT JOIN {user} u ON u.id = r.userid
              ORDER BY r.timecreated DESC, r.id DESC";
        $runs = $DB->get_records_sql($sql, $userfields->params, $page * $perpage, $perpage);

        $table = new html_table();
        $table->attributes = ['class' => 'generaltable'];
        $table->head = [
            get_string('history_date', $component),
            get_string('history_user', $component),
            get_string('history_file', $component),
            get_string('history_source', $component),
            get_string('status_ok', $component),
            get_string('history_skipped', $component),
            get_string('status_error', $component),
            '',
        ];
        foreach ($runs as $run) {
            $problem = $run->counterrors > 0 || !empty($run->fileerror);
            $errors = !empty($run->fileerror) ? get_string('history_filerejected', $component) : $run->counterrors;
            $table->data[] = [
                userdate($run->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                $run->userid ? fullname($run) : '-',
                s($run->filename),
                s($run->source),
                $run->countcreated,
                $run->countskipped,
                $problem ? html_writer::span($errors, 'badge bg-danger') : $errors,
                html_writer::link(new moodle_url($pageurl, ['id' => $run->id]), get_string('history_view', $component)),
            ];
        }
        echo html_writer::table($table);
        echo $OUTPUT->paging_bar($total, $page, $perpage, $pageurl);
    }
    echo html_writer::link(
        new moodle_url('/local/grpcalendarimport/index.php'),
        get_string('template_back', $component),
        ['class' => 'btn btn-link px-0']
    );
}

echo $OUTPUT->footer();

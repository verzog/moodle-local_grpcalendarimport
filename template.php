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
 * Create a prefilled import template from a category and/or courses.
 *
 * The form previews the template (with warnings for courses without groups and for the row limit);
 * the download buttons post the same choices back with a dataformat to send the file.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_grpcalendarimport\template;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$dataformat = optional_param('dataformat', '', PARAM_ALPHA);

$pageurl = new moodle_url('/local/grpcalendarimport/template.php');
admin_externalpage_setup('local_grpcalendarimport', '', null, $pageurl->out(false));
require_capability('local/grpcalendarimport:manage', context_system::instance());

$component = 'local_grpcalendarimport';
$formats = ['csv', 'excel'];

// Download: rebuild the template from the posted choices and send it as a file.
if ($dataformat !== '') {
    require_sesskey();
    if (!in_array($dataformat, $formats, true)) {
        throw new moodle_exception('invalidparameter', 'debug');
    }
    $categoryid = optional_param('categoryid', 0, PARAM_INT);
    $subcategories = optional_param('subcategories', false, PARAM_BOOL);
    $courseids = explode(',', optional_param('courseids', '', PARAM_SEQUENCE));
    $eventspergroup = optional_param('eventspergroup', 1, PARAM_INT);

    $courses = template::find_courses($categoryid, $subcategories, $courseids);
    $outcome = template::build($courses, $eventspergroup);

    \core\dataformat::download_data(
        'group_calendar_template',
        $dataformat,
        template::columns(),
        $outcome['rows'],
        function (array $row): array {
            return array_values($row);
        }
    );
    die();
}

$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('template_heading', $component));
$PAGE->set_heading(get_string('template_heading', $component));

$form = new \local_grpcalendarimport\form\template_form($pageurl);

$preview = '';
if ($data = $form->get_data()) {
    $courseids = array_map('intval', (array)($data->courseids ?? []));
    $courses = template::find_courses((int)$data->categoryid, !empty($data->subcategories), $courseids);
    $outcome = template::build($courses, (int)$data->eventspergroup);

    $notifications = [];
    if (!empty($outcome['nogroups'])) {
        $notifications[] = $OUTPUT->notification(
            get_string('template_nogroups', $component, s(implode(', ', $outcome['nogroups']))),
            \core\output\notification::NOTIFY_WARNING
        );
    }
    if ($outcome['truncated']) {
        $notifications[] = $OUTPUT->notification(
            get_string('template_truncated', $component, template::MAX_ROWS),
            \core\output\notification::NOTIFY_WARNING
        );
    }

    if (empty($outcome['rows'])) {
        $notifications[] = $OUTPUT->notification(
            get_string('template_norows', $component),
            \core\output\notification::NOTIFY_ERROR
        );
        $preview = implode('', $notifications);
    } else {
        $summary = (object)[
            'courses' => count($courses) - count($outcome['nogroups']),
            'groups' => $outcome['groupcount'],
            'rows' => count($outcome['rows']),
        ];
        $buttons = '';
        foreach ($formats as $format) {
            $params = [
                'dataformat' => $format,
                'categoryid' => (int)$data->categoryid,
                'subcategories' => empty($data->subcategories) ? 0 : 1,
                'courseids' => implode(',', $courseids),
                'eventspergroup' => (int)$data->eventspergroup,
            ];
            $label = get_string('template_download_' . $format, $component);
            $buttons .= $OUTPUT->single_button(
                new moodle_url($pageurl, $params),
                $label,
                'post',
                ['type' => \core\output\single_button::BUTTON_PRIMARY]
            );
        }
        $preview = implode('', $notifications)
            . html_writer::tag('p', get_string('template_summary', $component, $summary))
            . html_writer::div($buttons, 'd-flex gap-2 mb-4');
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('template_heading', $component));
echo html_writer::tag('p', get_string('template_intro', $component));
echo $preview;
$form->display();
echo html_writer::link(
    new moodle_url('/local/grpcalendarimport/index.php'),
    get_string('template_back', $component),
    ['class' => 'btn btn-link px-0']
);
echo $OUTPUT->footer();

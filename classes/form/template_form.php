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
 * Form for choosing the courses to include in a prefilled template.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport\form;

use local_grpcalendarimport\template;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for choosing the courses to include in a prefilled template.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_form extends \moodleform {
    /**
     * Define the form fields.
     */
    public function definition() {
        $mform = $this->_form;
        $component = 'local_grpcalendarimport';

        $mform->addElement('header', 'templateheader', get_string('template_heading', $component));

        $categories = [0 => get_string('choosedots')] + \core_course_category::make_categories_list();
        $mform->addElement('select', 'categoryid', get_string('template_category', $component), $categories);
        $mform->setType('categoryid', PARAM_INT);

        $mform->addElement('advcheckbox', 'subcategories', get_string('template_subcategories', $component));
        $mform->setDefault('subcategories', 1);
        $mform->hideIf('subcategories', 'categoryid', 'eq', 0);

        $mform->addElement('course', 'courseids', get_string('template_courses', $component), ['multiple' => true]);

        $mform->addElement('text', 'eventspergroup', get_string('template_eventspergroup', $component), ['size' => 4]);
        $mform->setType('eventspergroup', PARAM_INT);
        $mform->setDefault('eventspergroup', 1);
        $mform->addHelpButton('eventspergroup', 'template_eventspergroup', $component);

        $this->add_action_buttons(false, get_string('template_preview', $component));
    }

    /**
     * Require a category or at least one course, and a sensible number of events per group.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['categoryid']) && empty($data['courseids'])) {
            $errors['categoryid'] = get_string('template_selectsomething', 'local_grpcalendarimport');
        }
        $events = (int)($data['eventspergroup'] ?? 0);
        if ($events < 1 || $events > template::MAX_EVENTS_PER_GROUP) {
            $max = template::MAX_EVENTS_PER_GROUP;
            $errors['eventspergroup'] = get_string('template_eventsrange', 'local_grpcalendarimport', $max);
        }
        return $errors;
    }
}

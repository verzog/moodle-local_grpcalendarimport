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
 * Upload form for the group calendar event importer.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for uploading a CSV file of calendar events.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_form extends \moodleform {
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

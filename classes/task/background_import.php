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
 * Adhoc task that imports the next part of a background import.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport\task;

use local_grpcalendarimport\background;

/**
 * Adhoc task that imports the next part of a background import, then queues another for the rest.
 *
 * Custom data: runid, the import history run. The task runs as the user who uploaded the file.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class background_import extends \core\task\adhoc_task {
    /**
     * Task name shown in the admin.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_background_import', 'local_grpcalendarimport');
    }

    /**
     * Import the next part of the run.
     */
    public function execute() {
        $runid = (int)($this->get_custom_data()->runid ?? 0);
        if (background::process($runid)) {
            mtrace("Group calendar background import {$runid} has finished.");
        } else {
            mtrace("Group calendar background import {$runid} imported a part; another task will import the rest.");
        }
    }
}

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
 * Scheduled task that deletes old import history.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport\task;

use local_grpcalendarimport\import_log;

/**
 * Scheduled task that deletes import history older than the "Keep import history for" setting.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_history extends \core\task\scheduled_task {
    /**
     * Task name shown in the admin.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_cleanup_history', 'local_grpcalendarimport');
    }

    /**
     * Delete history older than the retention setting; 0 keeps it forever.
     */
    public function execute() {
        // Until an admin saves the setting after an upgrade, use its 90-day default.
        $setting = get_config('local_grpcalendarimport', 'historyretention');
        $days = ($setting === false || $setting === '') ? 90 : (int)$setting;
        if ($days <= 0) {
            mtrace('Import history is kept forever; nothing to delete.');
            return;
        }
        // Calendar arithmetic, so daylight saving doesn't shift the cut-off by an hour.
        $cutoff = (new \DateTime('now', \core_date::get_server_timezone_object()))->modify("-{$days} days")->getTimestamp();
        $deleted = import_log::delete_older_than($cutoff);
        mtrace("Deleted {$deleted} import history runs older than {$days} days.");
    }
}

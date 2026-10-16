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
 * Event fired when a row of a group calendar import fails.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport\event;

/**
 * Event fired when a row of a group calendar import fails.
 *
 * Only the first few failures of a run are logged as events (see importer::MAX_ROW_EVENTS);
 * every failure is kept in the import history.
 *
 * other: runid, filename, rownum, message, rowdata (the row's cell values).
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_row_failed extends \core\event\base {
    /**
     * Set basic properties.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_grpcalendarimport_fail';
    }

    /**
     * Event name shown in the logs.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_import_row_failed', 'local_grpcalendarimport');
    }

    /**
     * Description shown in the logs.
     *
     * @return string
     */
    public function get_description() {
        $other = $this->other;
        return "Row {$other['rownum']} of the group calendar file '{$other['filename']}' imported by the user with id " .
            "'{$this->userid}' failed: {$other['message']}";
    }

    /**
     * Link to the run this row belongs to in the import history.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/grpcalendarimport/history.php', ['id' => $this->other['runid']]);
    }

    /**
     * Check the data the event needs.
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['runid', 'filename', 'rownum', 'message'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }

    /**
     * Mapping for backup and restore; import history isn't backed up.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'local_grpcalendarimport_fail', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Mapping of the other fields for backup and restore; runid isn't restored either.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['runid' => ['db' => 'local_grpcalendarimport_run', 'restore' => \core\event\base::NOT_MAPPED]];
    }
}

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
 * Event fired when a group calendar import file has been processed.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport\event;

/**
 * Event fired when a group calendar import file has been processed, successfully or not.
 *
 * other: filename, source, created, skipped, errors, fileerror (empty unless the whole file was rejected).
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_completed extends \core\event\base {
    /**
     * Set basic properties.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_grpcalendarimport_run';
    }

    /**
     * Event name shown in the logs.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_import_completed', 'local_grpcalendarimport');
    }

    /**
     * Description shown in the logs.
     *
     * @return string
     */
    public function get_description() {
        $other = $this->other;
        if (!empty($other['fileerror'])) {
            return "The user with id '{$this->userid}' imported the group calendar file '{$other['filename']}', " .
                "which was rejected: {$other['fileerror']}";
        }
        return "The user with id '{$this->userid}' imported the group calendar file '{$other['filename']}': " .
            "{$other['created']} created, {$other['skipped']} skipped, {$other['errors']} errors.";
    }

    /**
     * Link to this run in the import history.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/grpcalendarimport/history.php', ['id' => $this->objectid]);
    }

    /**
     * Check the data the event needs.
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['filename', 'created', 'skipped', 'errors'] as $key) {
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
        return ['db' => 'local_grpcalendarimport_run', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Mapping of the other fields for backup and restore; none refer to other records.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}

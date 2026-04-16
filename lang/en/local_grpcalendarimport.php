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
 * Language strings for local_grpcalendarimport.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 SCCA
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Strings in alphabetical order as required by Moodle coding style.
$string['col_message']              = 'Event Name / Message';
$string['col_status']               = 'Status';
$string['error_coursenotfound']     = 'Course ID {$a} not found.';
$string['error_exception']          = 'Exception: {$a}';
$string['error_fileread']           = 'Could not read uploaded file.';
$string['error_groupnotfound']      = 'Group ID {$a->groupid} not found in course {$a->courseid}.';
$string['error_invalidcourseid']    = 'Invalid or missing course ID.';
$string['error_invalidgroupid']     = 'Invalid or missing group ID.';
$string['error_invalidtimestart']   = 'Invalid or missing event start time.';
$string['error_missingname']        = 'Missing event name.';
$string['grpcalendarimport:manage'] = 'Import calendar events';
$string['import_button']            = 'Import Events';
$string['import_complete']          = 'Import complete — Created: {$a->created}, Skipped: {$a->skipped}, Errors: {$a->errors}.';
$string['import_heading']           = 'Import Group Calendar Events';
$string['pluginname']               = 'Calendar Group Event Importer';
$string['pluginname_desc']          = 'Import group calendar events from a CSV file.';
$string['privacy:metadata']         = 'The Calendar Group Event Importer plugin does not store any personal data.';
$string['results_heading']          = 'Import Results';
$string['row']                      = 'Row';
$string['skipduplicates']           = 'Skip duplicate events';
$string['skipduplicates_help']      = 'Skip if an event with the same name, course and timestart already exists';
$string['status_error']             = 'Error';
$string['status_ok']                = 'Created';
$string['status_ok_msg']            = 'Created: "{$a->name}" (event ID {$a->id}).';
$string['status_skip']              = 'Skipped (already exists)';
$string['status_skip_msg']          = 'Duplicate skipped: {$a}.';
$string['upload_csv']               = 'Upload CSV file';

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
$string['csv_format_intro']           = 'Upload a comma- or tab-delimited file with a header row. Columns can be in any order. UTF-8 and UTF-8-BOM encodings are both accepted.';
$string['default_value']              = 'Default';
$string['description_column']         = 'Column';
$string['download_sample']            = 'Download sample CSV';
$string['event_message']              = 'Event Name / Message';
$string['grpcalendarimport:manage']   = 'Import group calendar events';
$string['import_button']              = 'Import Events';
$string['import_heading']             = 'Import Group Calendar Events';
$string['instructions_heading']       = 'Instructions';
$string['optional_columns']           = 'Optional columns';
$string['pluginname']                 = 'Calendar Group Event Importer';
$string['pluginname_desc']            = 'Import group calendar events from a CSV file.';
$string['privacy:metadata']           = 'This plugin does not directly store any personal data. Calendar events are stored in the core calendar system.';
$string['recommended_columns']        = 'Recommended columns';
$string['required_columns']           = 'Required columns';
$string['results_heading']            = 'Import Results';
$string['row']                        = 'Row';
$string['sample_csv_heading']         = 'Example CSV';
$string['skipduplicates']             = 'Skip duplicate events';
$string['skipduplicates_desc']        = 'Skip if an event with the same name, course and timestart already exists';
$string['status']                     = 'Status';
$string['status_error']               = 'Error';
$string['status_ok']                  = 'Created';
$string['status_skip']                = 'Skipped (already exists)';
$string['upload_csv']                 = 'Upload CSV file';
$string['upload_error']               = 'Could not read uploaded file.';

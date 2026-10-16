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
 * Language strings for local_grpcalendarimport.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['coldesc_categoryid'] = 'Category ID';
$string['coldesc_courseid'] = 'Moodle course ID, shown in the course URL (e.g. {$a})';
$string['coldesc_description'] = 'Event description text';
$string['coldesc_eventtype'] = 'Calendar event type (only "group" is accepted)';
$string['coldesc_groupid'] = 'Moodle group ID. It must belong to the course (find it via Course › Participants › Groups).';
$string['coldesc_location'] = 'Event location';
$string['coldesc_name'] = 'Event title';
$string['coldesc_priority'] = 'Display priority';
$string['coldesc_sequence'] = 'Revision number for external sync';
$string['coldesc_timeduration'] = 'Duration in seconds';
$string['coldesc_timesort'] = 'Sort timestamp (defaults to timestart)';
$string['coldesc_timestart'] = 'Event start as DD/MM/YYYY HH:MM in your time zone (e.g. {$a->date}), or as a Unix timestamp (e.g. {$a->timestamp})';
$string['coldesc_userid'] = 'User ID to associate with the event';
$string['coldesc_uuid'] = 'Unique identifier for external sync';
$string['coldesc_visible'] = '1 = visible, 0 = hidden';
$string['csv_format_intro'] = 'Upload a comma- or tab-delimited file with a header row. Columns can be in any order and column names are not case-sensitive. UTF-8 and UTF-8-BOM encodings are both accepted.';
$string['default_empty'] = '(empty)';
$string['default_timeduration'] = '3600 (1 hour)';
$string['default_value'] = 'Default';
$string['description_column'] = 'Column';
$string['description_heading'] = 'Description';
$string['download_sample'] = 'Download sample CSV';
$string['error_courseid'] = 'Invalid or missing courseid.';
$string['error_coursenotfound'] = 'Course ID {$a} not found.';
$string['error_eventtype'] = 'Unsupported eventtype "{$a}". Only "group" events can be imported.';
$string['error_exception'] = 'Exception: {$a}';
$string['error_groupid'] = 'Invalid or missing groupid.';
$string['error_groupnotfound'] = 'Group ID {$a->groupid} not found in course {$a->courseid}.';
$string['error_missingcolumns'] = 'The file is missing these required columns: {$a}. Check the header row.';
$string['error_missingname'] = 'Missing name.';
$string['error_norows'] = 'The file has a header row but no events to import.';
$string['error_timestart'] = 'Invalid or missing timestart.';
$string['error_timestartformat'] = 'Could not read timestart "{$a}". Use DD/MM/YYYY HH:MM (for example 03/05/2027 09:00) or a Unix timestamp.';
$string['event_message'] = 'Event name / message';
$string['grpcalendarimport:manage'] = 'Import group calendar events';
$string['import_button'] = 'Import events';
$string['import_heading'] = 'Import group calendar events';
$string['import_summary'] = 'Import complete. Created: {$a->ok}, skipped: {$a->skip}, errors: {$a->error}.';
$string['instructions_heading'] = 'Instructions';
$string['optional_columns'] = 'Optional columns';
$string['pluginname'] = 'Calendar Group Event Importer';
$string['pluginname_desc'] = 'Import group calendar events from a CSV file.';
$string['privacy:metadata'] = 'This plugin does not directly store any personal data. Calendar events are stored in the core calendar system.';
$string['recommended_columns'] = 'Recommended columns';
$string['required_columns'] = 'Required columns';
$string['result_created'] = 'Created: "{$a->name}" (event ID {$a->id}).';
$string['result_duplicate'] = 'Duplicate skipped: {$a}.';
$string['results_heading'] = 'Import results';
$string['row'] = 'Row';
$string['sample_csv_heading'] = 'Example CSV';
$string['skipduplicates'] = 'Skip duplicate events';
$string['skipduplicates_desc'] = 'Skip an event if one with the same name, course, group, start time and event type already exists';
$string['status'] = 'Status';
$string['status_error'] = 'Error';
$string['status_ok'] = 'Created';
$string['status_skip'] = 'Skipped (already exists)';
$string['template_back'] = 'Back to the import page';
$string['template_category'] = 'Category';
$string['template_courses'] = 'Courses';
$string['template_download_csv'] = 'Download CSV';
$string['template_download_excel'] = 'Download Excel';
$string['template_eventspergroup'] = 'Events per group';
$string['template_eventspergroup_help'] = 'How many blank event rows to create for each group. In the spreadsheet you can copy or delete rows if some groups need a different number.';
$string['template_eventsrange'] = 'Enter a number from 1 to {$a}.';
$string['template_heading'] = 'Create a prefilled template';
$string['template_intro'] = 'Choose a category, specific courses, or both. The template lists every group in those courses, with one row per event. Fill in each event\'s name and start time (DD/MM/YYYY HH:MM), then upload the file on the import page. If you edit the Excel version, save it as CSV before uploading.';
$string['template_nogroups'] = 'No groups found in these courses, so they are not in the template: {$a}';
$string['template_norows'] = 'No groups were found in the selected courses, so there is nothing to download.';
$string['template_preview'] = 'Preview template';
$string['template_selectsomething'] = 'Choose a category or at least one course.';
$string['template_subcategories'] = 'Include subcategories';
$string['template_summary'] = 'The template covers {$a->courses} courses and {$a->groups} groups: {$a->rows} rows.';
$string['template_truncated'] = 'The template is limited to {$a} rows, so not every group is included. Choose fewer courses or fewer events per group to get the rest.';
$string['upload_csv'] = 'Upload CSV file';
$string['upload_error'] = 'Could not read uploaded file.';

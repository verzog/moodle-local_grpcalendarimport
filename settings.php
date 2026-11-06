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
 * Admin settings registration for local_grpcalendarimport.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// The import and history pages are for anyone with the manage capability (managers by default), not only
// site administrators; admin_externalpage checks the capability again when a page is opened.
if ($hassiteconfig || has_capability('local/grpcalendarimport:manage', context_system::instance())) {
    $ADMIN->add('tools', new admin_externalpage(
        'local_grpcalendarimport',
        get_string('pluginname', 'local_grpcalendarimport'),
        new moodle_url('/local/grpcalendarimport/index.php'),
        'local/grpcalendarimport:manage'
    ));
    $ADMIN->add('tools', new admin_externalpage(
        'local_grpcalendarimport_history',
        get_string('history_heading', 'local_grpcalendarimport'),
        new moodle_url('/local/grpcalendarimport/history.php'),
        'local/grpcalendarimport:manage'
    ));
}

// The history retention setting is site configuration, so it stays with site administrators.
if ($hassiteconfig) {
    $settings = new admin_settingpage('local_grpcalendarimport_settings', get_string('pluginname', 'local_grpcalendarimport'));
    $retention = [
        30 => get_string('numdays', '', 30),
        90 => get_string('numdays', '', 90),
        180 => get_string('numdays', '', 180),
        365 => get_string('numdays', '', 365),
        0 => get_string('history_keepforever', 'local_grpcalendarimport'),
    ];
    $settings->add(new admin_setting_configselect(
        'local_grpcalendarimport/historyretention',
        get_string('history_retention', 'local_grpcalendarimport'),
        get_string('history_retention_desc', 'local_grpcalendarimport'),
        90,
        $retention
    ));
    $limits = (object)[
        'upload' => \local_grpcalendarimport\importer::MAX_UPLOAD_ROWS,
        'max' => \local_grpcalendarimport\background::MAX_ROWS,
    ];
    $settings->add(new admin_setting_configcheckbox(
        'local_grpcalendarimport/backgroundimports',
        get_string('backgroundimports', 'local_grpcalendarimport'),
        get_string('backgroundimports_desc', 'local_grpcalendarimport', $limits),
        1
    ));
    $ADMIN->add('localplugins', $settings);
}

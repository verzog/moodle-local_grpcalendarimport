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

if ($hassiteconfig) {
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
    $ADMIN->add('localplugins', $settings);
}

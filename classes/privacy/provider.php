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
 * Privacy provider for local_grpcalendarimport.
 *
 * This plugin imports calendar events via CSV upload and does not store any
 * personal data itself; all event records belong to the core calendar subsystem.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 SCCA
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport\privacy;

use core_privacy\local\metadata\null_provider;

/**
 * Privacy provider — this plugin stores no personal data of its own.
 */
class provider implements null_provider {
    /**
     * Get a description of the user data stored or processed by this plugin.
     *
     * @return string Language string key.
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}

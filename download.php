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
 * Serve the sample CSV file for download.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('local/grpcalendarimport:manage', context_system::instance());

// Timestamps used in sample rows (Australia/Sydney time).
// 1809298800 = 03/05/2027 09:00 AEST.
// 1809410400 = 04/05/2027 16:00 AEST.
// 1812326400 = 07/06/2027 10:00 AEST.
$content = "name,courseid,groupid,timestart,timeduration,description,location,eventtype,visible\r\n"
         . "Team Meeting,2,5,1809298800,3600,Weekly sync,Room 101,group,1\r\n"
         . "Practice Session,2,6,1809410400,5400,Pre-game warmup,Field B,group,1\r\n"
         . "Championship Race,3,7,1812326400,7200,Regional finals,Track A,group,1\r\n";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="grp_calendar_sample.csv"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
echo $content;
die();

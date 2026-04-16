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
 * Unit tests for local_grpcalendarimport library functions.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 SCCA
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport;

use advanced_testcase;

/**
 * Unit test class for local_grpcalendarimport.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 SCCA
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class locallib_test extends advanced_testcase {
    /**
     * Test validation of valid event data.
     */
    public function test_validate_event_valid(): void {
        $this->resetAfterTest();

        $event = [
            'name' => 'Test Event',
            'courseid' => 1,
            'groupid' => 1,
            'timestart' => time(),
        ];

        $result = \local_grpcalendarimport_validate_event($event);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test validation of event with missing name.
     */
    public function test_validate_event_missing_name(): void {
        $this->resetAfterTest();

        $event = [
            'name' => '',
            'courseid' => 1,
            'groupid' => 1,
            'timestart' => time(),
        ];

        $result = \local_grpcalendarimport_validate_event($event);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * Test validation of event with missing courseid.
     */
    public function test_validate_event_missing_courseid(): void {
        $this->resetAfterTest();

        $event = [
            'name' => 'Test Event',
            'courseid' => 0,
            'groupid' => 1,
            'timestart' => time(),
        ];

        $result = \local_grpcalendarimport_validate_event($event);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * Test validation of event with missing groupid.
     */
    public function test_validate_event_missing_groupid(): void {
        $this->resetAfterTest();

        $event = [
            'name' => 'Test Event',
            'courseid' => 1,
            'groupid' => 0,
            'timestart' => time(),
        ];

        $result = \local_grpcalendarimport_validate_event($event);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * Test validation of event with missing timestart.
     */
    public function test_validate_event_missing_timestart(): void {
        $this->resetAfterTest();

        $event = [
            'name' => 'Test Event',
            'courseid' => 1,
            'groupid' => 1,
            'timestart' => 0,
        ];

        $result = \local_grpcalendarimport_validate_event($event);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * Test processing empty events array.
     */
    public function test_process_events_empty(): void {
        $this->resetAfterTest();

        $events = [];
        $result = \local_grpcalendarimport_process_events($events);

        $this->assertEmpty($result);
    }

    /**
     * Test statistics retrieval without course filter.
     */
    public function test_get_statistics_all(): void {
        $this->resetAfterTest();

        $stats = \local_grpcalendarimport_get_statistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_events', $stats);
        $this->assertArrayHasKey('courseid', $stats);
        $this->assertArrayHasKey('timestamp', $stats);
    }

    /**
     * Test statistics retrieval with course filter.
     */
    public function test_get_statistics_by_course(): void {
        $this->resetAfterTest();

        $stats = \local_grpcalendarimport_get_statistics(1);

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_events', $stats);
        $this->assertEquals(1, $stats['courseid']);
    }
}

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
 * Unit test class for local_grpcalendarimport library functions.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 SCCA
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class locallib_test extends advanced_testcase {
    /**
     * Test parse_csv with basic CSV data.
     */
    public function test_parse_csv_basic(): void {
        $this->resetAfterTest();

        $csvdata = "name,courseid,groupid,timestart\n" .
                   "Event 1,1,1,1700000000\n" .
                   "Event 2,1,2,1700000001\n";

        $tmpfile = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tmpfile, $csvdata);

        $rows = \local_grpcalendarimport_parse_csv($tmpfile);

        $this->assertCount(2, $rows);
        $this->assertEquals('Event 1', $rows[0]['name']);
        $this->assertEquals('1', $rows[0]['courseid']);
        $this->assertEquals('1', $rows[0]['groupid']);

        unlink($tmpfile);
    }

    /**
     * Test parse_csv with TSV data.
     */
    public function test_parse_csv_tsv(): void {
        $this->resetAfterTest();

        $tsvdata = "name\tcourseid\tgroupid\ttimestart\n" .
                   "Event 1\t1\t1\t1700000000\n";

        $tmpfile = tempnam(sys_get_temp_dir(), 'tsv_');
        file_put_contents($tmpfile, $tsvdata);

        $rows = \local_grpcalendarimport_parse_csv($tmpfile);

        $this->assertCount(1, $rows);
        $this->assertEquals('Event 1', $rows[0]['name']);

        unlink($tmpfile);
    }

    /**
     * Test parse_csv with BOM stripping.
     */
    public function test_parse_csv_bom_stripping(): void {
        $this->resetAfterTest();

        // Add UTF-8 BOM to CSV header.
        $csvdata = "\xef\xbb\xbfname,courseid,groupid,timestart\n" .
                   "Event 1,1,1,1700000000\n";

        $tmpfile = tempnam(sys_get_temp_dir(), 'bom_');
        file_put_contents($tmpfile, $csvdata);

        $rows = \local_grpcalendarimport_parse_csv($tmpfile);

        $this->assertCount(1, $rows);
        // BOM should be stripped from 'name' header.
        $this->assertArrayHasKey('name', $rows[0]);

        unlink($tmpfile);
    }

    /**
     * Test parse_csv with short row padding.
     */
    public function test_parse_csv_short_row_padding(): void {
        $this->resetAfterTest();

        $csvdata = "name,courseid,groupid,timestart,description\n" .
                   "Event 1,1,1,1700000000\n";

        $tmpfile = tempnam(sys_get_temp_dir(), 'short_');
        file_put_contents($tmpfile, $csvdata);

        $rows = \local_grpcalendarimport_parse_csv($tmpfile);

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('description', $rows[0]);
        $this->assertEquals('', $rows[0]['description']);

        unlink($tmpfile);
    }

    /**
     * Test parse_csv with invalid filepath.
     */
    public function test_parse_csv_invalid_path(): void {
        $this->resetAfterTest();

        $rows = \local_grpcalendarimport_parse_csv('/nonexistent/path/to/file.csv');

        $this->assertEmpty($rows);
    }

    /**
     * Test create_event with missing name.
     */
    public function test_create_event_missing_name(): void {
        $this->resetAfterTest();

        $row = [
            'name' => '',
            'courseid' => 1,
            'groupid' => 1,
            'timestart' => time(),
        ];

        $result = \local_grpcalendarimport_create_event($row, false, 1);

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('Missing name', $result['message']);
    }

    /**
     * Test create_event with invalid courseid.
     */
    public function test_create_event_invalid_courseid(): void {
        $this->resetAfterTest();

        $row = [
            'name' => 'Test Event',
            'courseid' => 0,
            'groupid' => 1,
            'timestart' => time(),
        ];

        $result = \local_grpcalendarimport_create_event($row, false, 1);

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('Invalid or missing courseid', $result['message']);
    }

    /**
     * Test create_event with invalid groupid.
     */
    public function test_create_event_invalid_groupid(): void {
        $this->resetAfterTest();

        $row = [
            'name' => 'Test Event',
            'courseid' => 1,
            'groupid' => 0,
            'timestart' => time(),
        ];

        $result = \local_grpcalendarimport_create_event($row, false, 1);

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('Invalid or missing groupid', $result['message']);
    }

    /**
     * Test create_event with missing course.
     */
    public function test_create_event_course_not_found(): void {
        $this->resetAfterTest();

        $row = [
            'name' => 'Test Event',
            'courseid' => 9999,
            'groupid' => 1,
            'timestart' => time(),
        ];

        $result = \local_grpcalendarimport_create_event($row, false, 1);

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('not found', $result['message']);
    }

    /**
     * Test create_event with success (requires valid course and group).
     */
    public function test_create_event_success(): void {
        $this->resetAfterTest(true);

        // Create a course and group for testing.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group = $generator->create_group(['courseid' => $course->id]);

        $row = [
            'name' => 'Test Event',
            'courseid' => $course->id,
            'groupid' => $group->id,
            'timestart' => time(),
            'timeduration' => 3600,
            'description' => 'Test description',
            'location' => 'Test location',
            'visible' => 1,
            'eventtype' => 'group',
        ];

        $result = \local_grpcalendarimport_create_event($row, false, 1);

        $this->assertEquals('ok', $result['status']);
        $this->assertStringContainsString('Created', $result['message']);
    }

    /**
     * Test create_event duplicate skip when enabled.
     */
    public function test_create_event_duplicate_skip(): void {
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group = $generator->create_group(['courseid' => $course->id]);

        $eventdata = [
            'name' => 'Duplicate Event',
            'courseid' => $course->id,
            'groupid' => $group->id,
            'timestart' => 1700000000,
            'timeduration' => 3600,
            'description' => 'Test',
            'visible' => 1,
            'eventtype' => 'group',
        ];

        // Create the first event.
        $result1 = \local_grpcalendarimport_create_event($eventdata, false, 1);
        $this->assertEquals('ok', $result1['status']);

        // Try to create duplicate with skipduplicates enabled.
        $result2 = \local_grpcalendarimport_create_event($eventdata, true, 2);
        $this->assertEquals('skip', $result2['status']);
        $this->assertStringContainsString('Duplicate skipped', $result2['message']);
    }

    /**
     * Test create_event duplicate allowed when skipduplicates is false.
     */
    public function test_create_event_duplicate_not_skipped(): void {
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group = $generator->create_group(['courseid' => $course->id]);

        $eventdata = [
            'name' => 'Duplicate Event',
            'courseid' => $course->id,
            'groupid' => $group->id,
            'timestart' => 1700000000,
            'timeduration' => 3600,
            'description' => 'Test',
            'visible' => 1,
            'eventtype' => 'group',
        ];

        // Create the first event.
        $result1 = \local_grpcalendarimport_create_event($eventdata, false, 1);
        $this->assertEquals('ok', $result1['status']);

        // Try to create duplicate with skipduplicates disabled.
        $result2 = \local_grpcalendarimport_create_event($eventdata, false, 2);
        $this->assertEquals('ok', $result2['status']);
    }
}

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
 * Unit tests for local_grpcalendarimport library functions.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../locallib.php');

/**
 * Unit test class for local_grpcalendarimport library functions.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class locallib_test extends \advanced_testcase {
    /**
     * Test parse_csv with basic CSV data.
     *
     * @covers ::local_grpcalendarimport_parse_csv
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
     *
     * @covers ::local_grpcalendarimport_parse_csv
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
     *
     * @covers ::local_grpcalendarimport_parse_csv
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
     *
     * @covers ::local_grpcalendarimport_parse_csv
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
     *
     * @covers ::local_grpcalendarimport_parse_csv
     */
    public function test_parse_csv_invalid_path(): void {
        $this->resetAfterTest();

        $rows = \local_grpcalendarimport_parse_csv('/nonexistent/path/to/file.csv');

        $this->assertEmpty($rows);
    }

    /**
     * Test create_event with missing name.
     *
     * @covers ::local_grpcalendarimport_create_event
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
     *
     * @covers ::local_grpcalendarimport_create_event
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
     *
     * @covers ::local_grpcalendarimport_create_event
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
     *
     * @covers ::local_grpcalendarimport_create_event
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
     *
     * @covers ::local_grpcalendarimport_create_event
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
     *
     * @covers ::local_grpcalendarimport_create_event
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
     *
     * @covers ::local_grpcalendarimport_create_event
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

    /**
     * Write CSV content to a temporary file and parse it.
     *
     * @param string $content File content.
     * @param array|null $headers Set to the normalised headers.
     * @return array Parsed rows.
     */
    private function parse_string(string $content, ?array &$headers = null): array {
        $tmpfile = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tmpfile, $content);
        $rows = \local_grpcalendarimport_parse_csv($tmpfile, $headers);
        unlink($tmpfile);
        return $rows;
    }

    /**
     * Test that blank lines, including delimiter-only lines, are skipped.
     *
     * @covers ::local_grpcalendarimport_parse_csv
     */
    public function test_parse_csv_skips_blank_lines(): void {
        $rows = $this->parse_string("name,courseid,groupid,timestart\n\nEvent 1,1,1,1700000000\n,,,\n\n");

        $this->assertCount(1, $rows);
        $this->assertEquals('Event 1', $rows[0]['name']);
    }

    /**
     * Test that header names are lower-cased and returned to the caller.
     *
     * @covers ::local_grpcalendarimport_parse_csv
     */
    public function test_parse_csv_headers_lowercased(): void {
        $headers = null;
        $rows = $this->parse_string("Name,CourseID,GroupID,TimeStart\nEvent 1,1,1,1700000000\n", $headers);

        $this->assertEquals(['name', 'courseid', 'groupid', 'timestart'], $headers);
        $this->assertEquals('Event 1', $rows[0]['name']);
    }

    /**
     * Test that a backslash before a quote is kept as data, not treated as an escape character.
     *
     * @covers ::local_grpcalendarimport_parse_csv
     */
    public function test_parse_csv_backslash_is_literal(): void {
        // The description cell is "C:\" in the file.
        $rows = $this->parse_string("name,description\n\"Event 1\",\"C:\\\"\nEvent 2,x\n");

        $this->assertCount(2, $rows);
        $this->assertEquals('C:\\', $rows[0]['description']);
        $this->assertEquals('Event 2', $rows[1]['name']);
    }

    /**
     * Test that an empty file gives no rows and no headers.
     *
     * @covers ::local_grpcalendarimport_parse_csv
     */
    public function test_parse_csv_empty_file(): void {
        $headers = null;
        $rows = $this->parse_string('', $headers);

        $this->assertSame([], $rows);
        $this->assertSame([], $headers);
    }

    /**
     * Test the required column check.
     *
     * @covers ::local_grpcalendarimport_missing_columns
     */
    public function test_missing_columns(): void {
        $this->assertSame([], \local_grpcalendarimport_missing_columns(['timestart', 'groupid', 'courseid', 'name', 'extra']));
        $this->assertSame(['courseid', 'timestart'], \local_grpcalendarimport_missing_columns(['name', 'groupid']));
    }

    /**
     * Test that blank cells fall back to defaults instead of becoming zero.
     *
     * @covers ::local_grpcalendarimport_create_event
     */
    public function test_create_event_blank_cells_use_defaults(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $row = [
            'name' => 'Blank cells',
            'courseid' => $course->id,
            'groupid' => $group->id,
            'timestart' => 1809298800,
            'timeduration' => '',
            'visible' => '',
            'eventtype' => '',
            'sequence' => '',
        ];
        $result = \local_grpcalendarimport_create_event($row, true, 1);
        $this->assertEquals('ok', $result['status']);

        $event = $DB->get_record('event', ['name' => 'Blank cells'], '*', MUST_EXIST);
        $this->assertEquals(1, $event->visible);
        $this->assertEquals(3600, $event->timeduration);
        $this->assertEquals('group', $event->eventtype);
        $this->assertEquals(1, $event->sequence);

        // A blank eventtype must still match the stored 'group' event as a duplicate.
        $result = \local_grpcalendarimport_create_event($row, true, 2);
        $this->assertEquals('skip', $result['status']);
    }

    /**
     * Test that only group events can be imported.
     *
     * @covers ::local_grpcalendarimport_create_event
     */
    public function test_create_event_rejects_other_eventtypes(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $row = [
            'name' => 'Site event',
            'courseid' => $course->id,
            'groupid' => $group->id,
            'timestart' => 1809298800,
            'eventtype' => 'site',
        ];
        $result = \local_grpcalendarimport_create_event($row, false, 1);

        $this->assertEquals('error', $result['status']);
        $this->assertEquals(get_string('error_eventtype', 'local_grpcalendarimport', 'site'), $result['message']);
    }

    /**
     * Test that internal {event} fields in the CSV are ignored.
     *
     * @covers ::local_grpcalendarimport_create_event
     */
    public function test_create_event_ignores_internal_fields(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $row = [
            'name' => 'Internal fields',
            'courseid' => $course->id,
            'groupid' => $group->id,
            'timestart' => 1809298800,
            'component' => 'mod_assign',
            'modulename' => 'assign',
            'instance' => '5',
            'repeatid' => '7',
            'type' => '1',
            'subscriptionid' => '3',
        ];
        $result = \local_grpcalendarimport_create_event($row, false, 1);
        $this->assertEquals('ok', $result['status']);

        $event = $DB->get_record('event', ['name' => 'Internal fields'], '*', MUST_EXIST);
        $this->assertEmpty($event->component);
        $this->assertEmpty($event->modulename);
        $this->assertEquals(0, $event->instance);
        $this->assertEquals(0, $event->repeatid);
        $this->assertEquals(CALENDAR_EVENT_TYPE_STANDARD, $event->type);
        $this->assertEmpty($event->subscriptionid);
    }
}

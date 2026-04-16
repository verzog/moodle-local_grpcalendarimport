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
 * PHPUnit tests for local_grpcalendarimport locallib functions.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 SCCA
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/grpcalendarimport/locallib.php');

/**
 * Tests for local_grpcalendarimport_parse_csv and local_grpcalendarimport_create_event.
 *
 * @covers ::local_grpcalendarimport_parse_csv
 * @covers ::local_grpcalendarimport_create_event
 */
class local_grpcalendarimport_locallib_test extends advanced_testcase {

    /** @var string[] Temp files to delete after each test. */
    private array $tmpfiles = [];

    /**
     * Remove any temp files created during the test.
     *
     * @return void
     */
    protected function tearDown(): void {
        foreach ($this->tmpfiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->tmpfiles = [];
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // local_grpcalendarimport_parse_csv
    // -------------------------------------------------------------------------

    /**
     * Returns an empty array when the file has no data rows.
     *
     * @return void
     */
    public function test_parse_csv_empty_file(): void {
        $tmpfile = $this->make_tmpfile('');
        $result = local_grpcalendarimport_parse_csv($tmpfile);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Header-only file returns an empty array.
     *
     * @return void
     */
    public function test_parse_csv_header_only(): void {
        $tmpfile = $this->make_tmpfile("name,courseid,groupid,timestart\n");
        $result = local_grpcalendarimport_parse_csv($tmpfile);
        $this->assertEmpty($result);
    }

    /**
     * Parses a simple comma-delimited CSV correctly.
     *
     * @return void
     */
    public function test_parse_csv_comma_delimited(): void {
        $content = "name,courseid,groupid,timestart\nEvent One,2,3,1700000000\n";
        $tmpfile = $this->make_tmpfile($content);
        $result = local_grpcalendarimport_parse_csv($tmpfile);
        $this->assertCount(1, $result);
        $this->assertEquals('Event One', $result[0]['name']);
        $this->assertEquals('2', $result[0]['courseid']);
        $this->assertEquals('3', $result[0]['groupid']);
        $this->assertEquals('1700000000', $result[0]['timestart']);
    }

    /**
     * Parses a tab-delimited TSV correctly.
     *
     * @return void
     */
    public function test_parse_csv_tab_delimited(): void {
        $content = "name\tcourseid\tgroupid\ttimestart\nEvent Tab\t2\t3\t1700000000\n";
        $tmpfile = $this->make_tmpfile($content);
        $result = local_grpcalendarimport_parse_csv($tmpfile);
        $this->assertCount(1, $result);
        $this->assertEquals('Event Tab', $result[0]['name']);
    }

    /**
     * Strips UTF-8 BOM from the first header column.
     *
     * @return void
     */
    public function test_parse_csv_strips_bom(): void {
        $bom = "\xEF\xBB\xBF";
        $content = "{$bom}name,courseid,groupid,timestart\nEvent BOM,2,3,1700000000\n";
        $tmpfile = $this->make_tmpfile($content);
        $result = local_grpcalendarimport_parse_csv($tmpfile);
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('name', $result[0]);
        $this->assertEquals('Event BOM', $result[0]['name']);
    }

    /**
     * Short rows are padded so array_combine does not throw.
     *
     * @return void
     */
    public function test_parse_csv_short_row_padded(): void {
        $content = "name,courseid,groupid,timestart\nShort Row\n";
        $tmpfile = $this->make_tmpfile($content);
        $result = local_grpcalendarimport_parse_csv($tmpfile);
        $this->assertCount(1, $result);
        $this->assertEquals('Short Row', $result[0]['name']);
        $this->assertEquals('', $result[0]['courseid']);
    }

    /**
     * Parses multiple data rows.
     *
     * @return void
     */
    public function test_parse_csv_multiple_rows(): void {
        $content = "name,courseid,groupid,timestart\nAlpha,2,3,1700000000\nBeta,2,3,1700003600\n";
        $tmpfile = $this->make_tmpfile($content);
        $result = local_grpcalendarimport_parse_csv($tmpfile);
        $this->assertCount(2, $result);
        $this->assertEquals('Alpha', $result[0]['name']);
        $this->assertEquals('Beta', $result[1]['name']);
    }

    // -------------------------------------------------------------------------
    // local_grpcalendarimport_create_event – validation errors
    // -------------------------------------------------------------------------

    /**
     * Missing event name returns an error result.
     *
     * @return void
     */
    public function test_create_event_missing_name(): void {
        $this->resetAfterTest();
        $result = local_grpcalendarimport_create_event(
            ['name' => '', 'courseid' => '2', 'groupid' => '1', 'timestart' => '1700000000'],
            false, 1
        );
        $this->assertEquals('error', $result['status']);
        $this->assertEquals(1, $result['row']);
    }

    /**
     * Zero courseid returns an error result.
     *
     * @return void
     */
    public function test_create_event_invalid_courseid(): void {
        $this->resetAfterTest();
        $result = local_grpcalendarimport_create_event(
            ['name' => 'Test', 'courseid' => '0', 'groupid' => '1', 'timestart' => '1700000000'],
            false, 1
        );
        $this->assertEquals('error', $result['status']);
    }

    /**
     * Zero groupid returns an error result.
     *
     * @return void
     */
    public function test_create_event_invalid_groupid(): void {
        $this->resetAfterTest();
        $result = local_grpcalendarimport_create_event(
            ['name' => 'Test', 'courseid' => '2', 'groupid' => '0', 'timestart' => '1700000000'],
            false, 1
        );
        $this->assertEquals('error', $result['status']);
    }

    /**
     * Zero timestart returns an error result.
     *
     * @return void
     */
    public function test_create_event_invalid_timestart(): void {
        $this->resetAfterTest();
        $result = local_grpcalendarimport_create_event(
            ['name' => 'Test', 'courseid' => '2', 'groupid' => '1', 'timestart' => '0'],
            false, 1
        );
        $this->assertEquals('error', $result['status']);
    }

    /**
     * Non-existent course returns an error result.
     *
     * @return void
     */
    public function test_create_event_course_not_found(): void {
        $this->resetAfterTest();
        $result = local_grpcalendarimport_create_event(
            ['name' => 'Test', 'courseid' => '99999', 'groupid' => '1', 'timestart' => '1700000000'],
            false, 1
        );
        $this->assertEquals('error', $result['status']);
    }

    /**
     * Group that does not belong to the given course returns an error result.
     *
     * @return void
     */
    public function test_create_event_group_wrong_course(): void {
        $this->resetAfterTest();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $group   = $this->getDataGenerator()->create_group(['courseid' => $course1->id]);

        $result = local_grpcalendarimport_create_event([
            'name'      => 'Test',
            'courseid'  => (string)$course2->id,
            'groupid'   => (string)$group->id,
            'timestart' => '1700000000',
        ], false, 1);
        $this->assertEquals('error', $result['status']);
    }

    // -------------------------------------------------------------------------
    // local_grpcalendarimport_create_event – success and duplicate skip
    // -------------------------------------------------------------------------

    /**
     * Valid row creates the event and returns ok status.
     *
     * @return void
     */
    public function test_create_event_success(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $group  = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $result = local_grpcalendarimport_create_event([
            'name'      => 'Test Event',
            'courseid'  => (string)$course->id,
            'groupid'   => (string)$group->id,
            'timestart' => '1700000000',
        ], false, 1);

        $this->assertEquals('ok', $result['status']);
        $this->assertEquals(1, $result['row']);
    }

    /**
     * Second identical row is skipped when duplicate checking is enabled.
     *
     * @return void
     */
    public function test_create_event_skip_duplicate(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $group  = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $row = [
            'name'      => 'Dup Event',
            'courseid'  => (string)$course->id,
            'groupid'   => (string)$group->id,
            'timestart' => '1700000000',
        ];

        $first = local_grpcalendarimport_create_event($row, true, 1);
        $this->assertEquals('ok', $first['status']);

        $second = local_grpcalendarimport_create_event($row, true, 2);
        $this->assertEquals('skip', $second['status']);
    }

    /**
     * Second identical row is NOT skipped when duplicate checking is disabled.
     *
     * @return void
     */
    public function test_create_event_no_skip_when_disabled(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $group  = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $row = [
            'name'      => 'Dup Event',
            'courseid'  => (string)$course->id,
            'groupid'   => (string)$group->id,
            'timestart' => '1700000000',
        ];

        local_grpcalendarimport_create_event($row, false, 1);
        $second = local_grpcalendarimport_create_event($row, false, 2);
        $this->assertEquals('ok', $second['status']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Write content to a temporary file and register it for cleanup.
     *
     * @param string $content File contents.
     * @return string Absolute path to the temp file.
     */
    private function make_tmpfile(string $content): string {
        $path = tempnam(sys_get_temp_dir(), 'grpcal_test_');
        file_put_contents($path, $content);
        $this->tmpfiles[] = $path;
        return $path;
    }
}

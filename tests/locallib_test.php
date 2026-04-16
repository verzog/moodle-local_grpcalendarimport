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

namespace local_grpcalendarimport;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../locallib.php');

/**
 * Unit tests for local_grpcalendarimport library functions.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 SCCA
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::local_grpcalendarimport_parse_csv
 * @covers ::local_grpcalendarimport_create_event
 */
final class locallib_test extends \advanced_testcase {
    /**
     * Temporary file path created during tests.
     *
     * @var string|null
     */
    private ?string $tmpfile = null;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    protected function tearDown(): void {
        if ($this->tmpfile !== null && file_exists($this->tmpfile)) {
            unlink($this->tmpfile);
            $this->tmpfile = null;
        }
        parent::tearDown();
    }

    /**
     * Write CSV content to a temp file and return its path.
     *
     * @param string $content Raw file content.
     * @return string Absolute path to the temp file.
     */
    private function make_temp_file(string $content): string {
        $this->tmpfile = tempnam(sys_get_temp_dir(), 'grpci_');
        file_put_contents($this->tmpfile, $content);
        return $this->tmpfile;
    }

    /**
     * Basic CSV with three columns returns one data row.
     */
    public function test_parse_csv_basic(): void {
        $path = $this->make_temp_file("name,courseid,groupid\nEvent A,1,2\n");
        $rows = local_grpcalendarimport_parse_csv($path);

        $this->assertCount(1, $rows);
        $this->assertEquals('Event A', $rows[0]['name']);
        $this->assertEquals('1', $rows[0]['courseid']);
        $this->assertEquals('2', $rows[0]['groupid']);
    }

    /**
     * TSV (tab-separated) files are detected and parsed correctly.
     */
    public function test_parse_csv_tsv(): void {
        $path = $this->make_temp_file("name\tcourseid\tgroupid\nEvent B\t3\t4\n");
        $rows = local_grpcalendarimport_parse_csv($path);

        $this->assertCount(1, $rows);
        $this->assertEquals('Event B', $rows[0]['name']);
        $this->assertEquals('3', $rows[0]['courseid']);
    }

    /**
     * UTF-8 BOM and control characters are stripped from header names.
     */
    public function test_parse_csv_bom(): void {
        // UTF-8 BOM is \xEF\xBB\xBF followed by the first header.
        $path = $this->make_temp_file("\xEF\xBB\xBFname,courseid\nEvent C,5\n");
        $rows = local_grpcalendarimport_parse_csv($path);

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('name', $rows[0]);
        $this->assertEquals('Event C', $rows[0]['name']);
    }

    /**
     * Rows shorter than the header are padded with empty strings.
     */
    public function test_parse_csv_short_rows(): void {
        $path = $this->make_temp_file("name,courseid,groupid\nEvent D,6\n");
        $rows = local_grpcalendarimport_parse_csv($path);

        $this->assertCount(1, $rows);
        $this->assertEquals('Event D', $rows[0]['name']);
        $this->assertEquals('', $rows[0]['groupid']);
    }

    /**
     * An invalid file path returns an empty array without errors.
     */
    public function test_parse_csv_invalid_path(): void {
        $rows = local_grpcalendarimport_parse_csv('/nonexistent/path/file.csv');
        $this->assertIsArray($rows);
        $this->assertCount(0, $rows);
    }

    /**
     * A header-only CSV (no data rows) returns an empty array.
     */
    public function test_parse_csv_header_only(): void {
        $path = $this->make_temp_file("name,courseid,groupid\n");
        $rows = local_grpcalendarimport_parse_csv($path);
        $this->assertCount(0, $rows);
    }

    /**
     * A row with an empty name returns an error result.
     */
    public function test_create_event_missing_name(): void {
        $row = ['name' => '', 'courseid' => '1', 'groupid' => '1', 'timestart' => (string)time()];
        $result = local_grpcalendarimport_create_event($row, false, 1);

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('Missing name', $result['message']);
    }

    /**
     * A row with courseid of zero returns an error result.
     */
    public function test_create_event_missing_courseid(): void {
        $row = ['name' => 'Test', 'courseid' => '0', 'groupid' => '1', 'timestart' => (string)time()];
        $result = local_grpcalendarimport_create_event($row, false, 2);

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('courseid', $result['message']);
    }

    /**
     * A row with groupid of zero returns an error result.
     */
    public function test_create_event_missing_groupid(): void {
        $row = ['name' => 'Test', 'courseid' => '1', 'groupid' => '0', 'timestart' => (string)time()];
        $result = local_grpcalendarimport_create_event($row, false, 3);

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('groupid', $result['message']);
    }

    /**
     * A row with timestart of zero returns an error result.
     */
    public function test_create_event_missing_timestart(): void {
        $row = ['name' => 'Test', 'courseid' => '1', 'groupid' => '1', 'timestart' => '0'];
        $result = local_grpcalendarimport_create_event($row, false, 4);

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('timestart', $result['message']);
    }

    /**
     * A row referencing a non-existent course returns an error result.
     */
    public function test_create_event_invalid_course(): void {
        $row = ['name' => 'Test', 'courseid' => '9999999', 'groupid' => '1', 'timestart' => (string)time()];
        $result = local_grpcalendarimport_create_event($row, false, 5);

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('not found', $result['message']);
    }

    /**
     * A row referencing a group not in the given course returns an error result.
     */
    public function test_create_event_invalid_group(): void {
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $othercourse->id]);

        $row = [
            'name'      => 'Test',
            'courseid'  => (string)$course->id,
            'groupid'   => (string)$group->id,
            'timestart' => (string)time(),
        ];
        $result = local_grpcalendarimport_create_event($row, false, 6);

        $this->assertEquals('error', $result['status']);
        $this->assertStringContainsString('not found', $result['message']);
    }

    /**
     * A valid row creates an event and returns an ok result.
     */
    public function test_create_event_success(): void {
        $course = $this->getDataGenerator()->create_course();
        $group  = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $row = [
            'name'      => 'My Event',
            'courseid'  => (string)$course->id,
            'groupid'   => (string)$group->id,
            'timestart' => (string)mktime(9, 0, 0, 6, 15, 2026),
            'eventtype' => 'group',
        ];
        $result = local_grpcalendarimport_create_event($row, false, 7);

        $this->assertEquals('ok', $result['status']);
        $this->assertStringContainsString('My Event', $result['message']);
    }

    /**
     * Importing the same row twice with skip-duplicates enabled skips the second row.
     */
    public function test_create_event_duplicate_skipped(): void {
        $course = $this->getDataGenerator()->create_course();
        $group  = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $row = [
            'name'      => 'Dup Event',
            'courseid'  => (string)$course->id,
            'groupid'   => (string)$group->id,
            'timestart' => (string)mktime(10, 0, 0, 6, 15, 2026),
            'eventtype' => 'group',
        ];

        $first  = local_grpcalendarimport_create_event($row, true, 1);
        $second = local_grpcalendarimport_create_event($row, true, 2);

        $this->assertEquals('ok', $first['status']);
        $this->assertEquals('skip', $second['status']);
        $this->assertStringContainsString('Duplicate', $second['message']);
    }

    /**
     * Importing the same row twice without skip-duplicates creates two events.
     */
    public function test_create_event_duplicate_not_skipped(): void {
        $course = $this->getDataGenerator()->create_course();
        $group  = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $row = [
            'name'      => 'No Skip Event',
            'courseid'  => (string)$course->id,
            'groupid'   => (string)$group->id,
            'timestart' => (string)mktime(11, 0, 0, 6, 15, 2026),
            'eventtype' => 'group',
        ];

        $first  = local_grpcalendarimport_create_event($row, false, 1);
        $second = local_grpcalendarimport_create_event($row, false, 2);

        $this->assertEquals('ok', $first['status']);
        $this->assertEquals('ok', $second['status']);
    }
}

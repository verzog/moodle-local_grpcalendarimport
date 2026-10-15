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
 * Unit tests for the importer API class.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport;

/**
 * Unit tests for the importer API class.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_grpcalendarimport\importer
 */
#[\PHPUnit\Framework\Attributes\CoversClass(importer::class)]
final class importer_test extends \advanced_testcase {
    /**
     * Write content to a temporary file.
     *
     * @param string $content File content.
     * @return string Path to the file.
     */
    private function temp_file(string $content): string {
        $tmpfile = tempnam(sys_get_temp_dir(), 'grpcal_');
        file_put_contents($tmpfile, $content);
        return $tmpfile;
    }

    /**
     * Test that a whole file is imported and the counts add up.
     */
    public function test_import_file_counts(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $csv = "name,courseid,groupid,timestart\n" .
            "Event 1,{$course->id},{$group->id},1809298800\n" .
            "Event 1,{$course->id},{$group->id},1809298800\n" .
            "Event 2,{$course->id},99999,1809298800\n";
        $file = $this->temp_file($csv);
        $outcome = importer::import_file($file, true);
        unlink($file);

        $this->assertSame('', $outcome['error']);
        $this->assertSame(['ok' => 1, 'skip' => 1, 'error' => 1], $outcome['counts']);
        $this->assertCount(3, $outcome['results']);
        $this->assertSame(3, $outcome['results'][2]['row']);
    }

    /**
     * Test that a file missing required columns creates nothing.
     */
    public function test_import_file_missing_columns(): void {
        global $DB;
        $this->resetAfterTest();

        $file = $this->temp_file("name,courseid\nEvent 1,2\n");
        $outcome = importer::import_file($file, true);
        unlink($file);

        $expected = get_string('error_missingcolumns', 'local_grpcalendarimport', 'groupid, timestart');
        $this->assertSame($expected, $outcome['error']);
        $this->assertSame([], $outcome['results']);
        $this->assertSame(0, $DB->count_records('event', ['eventtype' => 'group']));
    }

    /**
     * Test that a file with a header but no data rows is reported.
     */
    public function test_import_file_no_rows(): void {
        $this->resetAfterTest();

        $file = $this->temp_file("name,courseid,groupid,timestart\n");
        $outcome = importer::import_file($file, true);
        unlink($file);

        $this->assertSame(get_string('error_norows', 'local_grpcalendarimport'), $outcome['error']);
    }

    /**
     * Test the duplicate lookup used by other plugins.
     */
    public function test_event_exists(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $row = ['name' => 'Check-in', 'courseid' => $course->id, 'groupid' => $group->id, 'timestart' => 1809298800];

        $this->assertFalse(importer::event_exists('Check-in', $course->id, $group->id, 1809298800, 'group'));
        $this->assertSame('ok', importer::create_event($row, false, 1)['status']);
        $this->assertTrue(importer::event_exists('Check-in', $course->id, $group->id, 1809298800, 'group'));
        $this->assertFalse(importer::event_exists('Check-in', $course->id, $group->id, 1809298801, 'group'));
    }

    /**
     * Test that the API version is published for callers to check.
     */
    public function test_api_version(): void {
        $this->assertGreaterThanOrEqual(1, importer::API_VERSION);
    }
}

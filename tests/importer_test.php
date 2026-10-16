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
     * Cells that parse_time() should read, with the timestamp each should give in Australia/Sydney.
     *
     * @return array
     */
    public static function parse_time_valid_provider(): array {
        return [
            'timestamp' => ['1809298800', 1809298800],
            'dd/mm/yyyy hh:mm' => ['03/05/2027 09:00', 1809298800],
            'no leading zeros' => ['3/5/2027 9:00', 1809298800],
            'with seconds' => ['03/05/2027 09:00:00', 1809298800],
            'date only is midnight' => ['03/05/2027', 1809266400],
            'iso' => ['2027-05-03 09:00', 1809298800],
            'iso with T' => ['2027-05-03T09:00', 1809298800],
            'surrounding spaces' => ['  03/05/2027 09:00 ', 1809298800],
            'daylight saving (AEDT)' => ['15/01/2027 09:00', 1799964000],
        ];
    }

    /**
     * Test that valid dates and timestamps are read in the given time zone.
     *
     * @param string $value Cell value.
     * @param int $expected Expected timestamp.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('parse_time_valid_provider')]
    public function test_parse_time_valid(string $value, int $expected): void {
        $this->assertSame($expected, importer::parse_time($value, new \DateTimeZone('Australia/Sydney')));
    }

    /**
     * Cells that parse_time() should reject.
     *
     * @return array
     */
    public static function parse_time_invalid_provider(): array {
        return [
            'blank' => [''],
            'words' => ['next Monday'],
            'month 13' => ['03/13/2027 09:00'],
            'no 31 February' => ['31/02/2027 09:00'],
            'hour 25' => ['03/05/2027 25:00'],
            'us order with time words' => ['May 3 2027 9am'],
        ];
    }

    /**
     * Test that unreadable or impossible dates give null rather than a rolled-over date.
     *
     * @param string $value Cell value.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('parse_time_invalid_provider')]
    public function test_parse_time_invalid(string $value): void {
        $this->assertNull(importer::parse_time($value, new \DateTimeZone('Australia/Sydney')));
    }

    /**
     * Test that a date with no time zone given is read in the current user's time zone.
     */
    public function test_parse_time_uses_user_timezone(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user(['timezone' => 'Australia/Perth']));

        // 09:00 in Perth (UTC+8) is 01:00 UTC.
        $this->assertSame(1809306000, importer::parse_time('03/05/2027 09:00'));
    }

    /**
     * Test that create_event() accepts a DD/MM/YYYY HH:MM start time.
     */
    public function test_create_event_with_date(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user(['timezone' => 'Australia/Sydney']));

        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $row = ['name' => 'Dated', 'courseid' => $course->id, 'groupid' => $group->id, 'timestart' => '03/05/2027 09:00'];

        $result = importer::create_event($row, false, 1);

        $this->assertEquals('ok', $result['status']);
        $event = $DB->get_record('event', ['courseid' => $course->id, 'groupid' => $group->id], '*', MUST_EXIST);
        $this->assertEquals(1809298800, $event->timestart);
        $this->assertEquals(1809298800, $event->timesort);
    }

    /**
     * Test that an unreadable start time gives an error that quotes the cell.
     */
    public function test_create_event_bad_date(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $row = ['name' => 'Bad', 'courseid' => $course->id, 'groupid' => $group->id, 'timestart' => '31/02/2027 09:00'];

        $result = importer::create_event($row, false, 1);

        $this->assertEquals('error', $result['status']);
        $this->assertEquals(get_string('error_timestartformat', 'local_grpcalendarimport', '31/02/2027 09:00'), $result['message']);
    }

    /**
     * Test that the API version is published for callers to check.
     */
    public function test_api_version(): void {
        $this->assertGreaterThanOrEqual(1, importer::API_VERSION);
    }
}

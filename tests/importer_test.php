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
     * Test that a file with more rows than the limit is rejected whole, and one at the limit is imported.
     */
    public function test_import_file_row_limit(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $csv = "name,courseid,groupid,timestart\n";
        for ($i = 1; $i <= 3; $i++) {
            $csv .= "Event {$i},{$course->id},{$group->id},1809298800\n";
        }
        $file = $this->temp_file($csv);

        $outcome = importer::import_file($file, false, '', '', 2);
        $a = (object)['rows' => 3, 'max' => 2];
        $this->assertSame(get_string('error_toomanyrows', 'local_grpcalendarimport', $a), $outcome['error']);
        $this->assertSame(['ok' => 0, 'skip' => 0, 'error' => 0], $outcome['counts']);
        $this->assertSame(0, $DB->count_records('event', ['courseid' => $course->id]));
        $run = $DB->get_record(import_log::RUN_TABLE, ['id' => $outcome['runid']], '*', MUST_EXIST);
        $this->assertSame($outcome['error'], $run->fileerror);

        $outcome = importer::import_file($file, false, '', '', 3);
        unlink($file);
        $this->assertSame('', $outcome['error']);
        $this->assertSame(3, $outcome['counts']['ok']);
        $this->assertSame(3, $DB->count_records('event', ['courseid' => $course->id]));
    }

    /**
     * Test that a whole-file import still rejects missing courses and groups, and groups from another course.
     */
    public function test_import_file_checks_courses_and_groups(): void {
        $this->resetAfterTest();

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $group1 = $this->getDataGenerator()->create_group(['courseid' => $course1->id]);
        $group2 = $this->getDataGenerator()->create_group(['courseid' => $course2->id]);
        $missing = 999999;

        $file = $this->temp_file("name,courseid,groupid,timestart\n" .
            "Good,{$course1->id},{$group1->id},1809298800\n" .
            "Other course's group,{$course1->id},{$group2->id},1809298800\n" .
            "No such course,{$missing},{$group1->id},1809298800\n" .
            "No such group,{$course1->id},{$missing},1809298800\n" .
            "Good too,{$course2->id},{$group2->id},1809298800\n");
        $outcome = importer::import_file($file, false);
        unlink($file);

        $component = 'local_grpcalendarimport';
        $this->assertSame(['ok' => 2, 'skip' => 0, 'error' => 3], $outcome['counts']);
        $this->assertSame('ok', $outcome['results'][0]['status']);
        $this->assertSame(
            get_string('error_groupnotfound', $component, ['groupid' => $group2->id, 'courseid' => $course1->id]),
            $outcome['results'][1]['message']
        );
        $this->assertSame(get_string('error_coursenotfound', $component, $missing), $outcome['results'][2]['message']);
        $this->assertSame(
            get_string('error_groupnotfound', $component, ['groupid' => $missing, 'courseid' => $course1->id]),
            $outcome['results'][3]['message']
        );
        $this->assertSame('ok', $outcome['results'][4]['status']);
    }

    /**
     * Test that a whole-file import only accepts a userid of an existing, undeleted user (or none).
     */
    public function test_import_file_checks_userid(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group = $generator->create_group(['courseid' => $course->id]);
        $user = $generator->create_user();
        $deleted = $generator->create_user();
        delete_user($deleted);
        $missing = 999999;

        $file = $this->temp_file("name,courseid,groupid,timestart,userid\n" .
            "No user,{$course->id},{$group->id},1809298800,\n" .
            "Zero user,{$course->id},{$group->id},1809298800,0\n" .
            "Real user,{$course->id},{$group->id},1809298800,{$user->id}\n" .
            "Missing user,{$course->id},{$group->id},1809298800,{$missing}\n" .
            "Deleted user,{$course->id},{$group->id},1809298800,{$deleted->id}\n" .
            "Negative user,{$course->id},{$group->id},1809298800,-5\n");
        $outcome = importer::import_file($file, false);
        unlink($file);

        $component = 'local_grpcalendarimport';
        $this->assertSame(['ok' => 3, 'skip' => 0, 'error' => 3], $outcome['counts']);
        $this->assertSame(['ok', 'ok', 'ok', 'error', 'error', 'error'], array_column($outcome['results'], 'status'));
        $this->assertSame(get_string('error_usernotfound', $component, $missing), $outcome['results'][3]['message']);
        $this->assertSame(get_string('error_usernotfound', $component, $deleted->id), $outcome['results'][4]['message']);
        $this->assertSame(get_string('error_usernotfound', $component, -5), $outcome['results'][5]['message']);

        // The name column is TEXT, which can't go in a get_record() condition, so match names in PHP.
        $userids = [];
        foreach ($DB->get_records('event', ['courseid' => $course->id], '', 'id, name, userid') as $event) {
            $userids[$event->name] = (int)$event->userid;
        }
        $this->assertCount(3, $userids);
        $this->assertSame((int)$user->id, $userids['Real user']);
        // A blank or 0 userid becomes the user running the import, as calendar_event does for any new event.
        $this->assertSame((int)$USER->id, $userids['No user']);
        $this->assertSame((int)$USER->id, $userids['Zero user']);
    }

    /**
     * Test that when Moodle can't save an event, the row shows an error code but not the database's error text.
     */
    public function test_create_failure_hides_exception_text(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group = $generator->create_group(['courseid' => $course->id]);
        // The uuid column holds 255 characters, so saving this fails on every supported database.
        $uuid = str_repeat('x', 300);
        $file = $this->temp_file("name,courseid,groupid,timestart,uuid\n" .
            "Too long,{$course->id},{$group->id},1809298800,{$uuid}\n");

        $sink = $this->redirectEvents();
        $outcome = importer::import_file($file, false);
        $events = $sink->get_events();
        $sink->close();
        unlink($file);
        // The full error, which may include SQL, goes to developer debugging only.
        $this->assertDebuggingCalled();

        $expected = get_string('error_createfailed', 'local_grpcalendarimport', 'dmlwriteexception');
        $this->assertSame(['ok' => 0, 'skip' => 0, 'error' => 1], $outcome['counts']);
        $this->assertSame($expected, $outcome['results'][0]['message']);
        $this->assertStringNotContainsString($uuid, $outcome['results'][0]['message']);

        // The import history and the log event get the same safe message.
        $stored = $DB->get_field(import_log::FAIL_TABLE, 'message', ['runid' => $outcome['runid']], MUST_EXIST);
        $this->assertSame($expected, $stored);
        $rowevents = array_values(array_filter($events, function ($event) {
            return $event instanceof event\import_row_failed;
        }));
        $this->assertCount(1, $rowevents);
        $this->assertSame($expected, $rowevents[0]->other['message']);
    }

    /**
     * Test that creating a single event also rejects a userid that doesn't belong to a user.
     */
    public function test_create_event_checks_userid(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group = $generator->create_group(['courseid' => $course->id]);
        $user = $generator->create_user();
        $row = ['name' => 'One event', 'courseid' => $course->id, 'groupid' => $group->id, 'timestart' => '1809298800'];

        $result = importer::create_event($row + ['userid' => 999999], false, 1);
        $this->assertSame('error', $result['status']);
        $this->assertSame(get_string('error_usernotfound', 'local_grpcalendarimport', 999999), $result['message']);

        $this->assertSame('ok', importer::create_event($row + ['userid' => $user->id], false, 2)['status']);
    }

    /**
     * Test that course and group lookups don't add database reads for every row of a file.
     */
    public function test_import_file_lookups_do_not_grow_with_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_group(['courseid' => $course1->id]);
        $group2 = $this->getDataGenerator()->create_group(['courseid' => $course2->id]);

        // Every row names a group from the other course, so each one stops at the group check.
        $reads = function (int $rowcount) use ($DB, $course1, $group2): int {
            $csv = "name,courseid,groupid,timestart\n";
            for ($i = 0; $i < $rowcount; $i++) {
                $csv .= "Event {$i},{$course1->id},{$group2->id},1809298800\n";
            }
            $file = $this->temp_file($csv);
            $sink = $this->redirectEvents();
            $before = $DB->perf_get_reads();
            $outcome = importer::import_file($file, false);
            $after = $DB->perf_get_reads();
            $sink->close();
            unlink($file);
            $this->assertSame($rowcount, $outcome['counts']['error']);
            return $after - $before;
        };

        // The first import warms Moodle's caches, so it isn't compared.
        $reads(2);
        $extrarows = 20;
        $extrareads = $reads(2 + $extrarows) - $reads(2);
        // Checking each row on its own took two reads per row; now it should take none.
        $this->assertLessThan($extrarows, $extrareads);
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
            '12-hour AM' => ['3/05/2027 9:00 AM', 1809298800],
            '12-hour lower-case, no space' => ['3/05/2027 9:00am', 1809298800],
            '12-hour PM' => ['04/05/2027 4:00 PM', 1809410400],
            '12-hour with seconds' => ['3/05/2027 9:00:00 AM', 1809298800],
            'daylight saving ends (first 02:30)' => ['04/04/2027 02:30', 1806769800],
            'just after daylight saving starts' => ['03/10/2027 03:00', 1822492800],
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
            'two-digit year' => ['3/5/27 9:00'],
            'three-digit year' => ['3/5/027 9:00'],
            'skipped by daylight saving' => ['04/10/2026 02:30'],
            'embedded null byte' => ["03/05/2027\0 09:00"],
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

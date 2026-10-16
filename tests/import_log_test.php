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
 * Unit tests for the import history and log events.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport;

/**
 * Unit tests for the import history and log events.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(import_log::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(event\import_completed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(event\import_row_failed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(task\cleanup_history::class)]
final class import_log_test extends \advanced_testcase {
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
     * Test that an import with failed rows is recorded with each failed row's values, and logged as events.
     */
    public function test_failed_rows_are_recorded_and_logged(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $file = $this->temp_file("name,courseid,groupid,timestart\n" .
            "Good,{$course->id},{$group->id},1809298800\n" .
            "Bad date,{$course->id},{$group->id},31/02/2027 09:00\n" .
            ",{$course->id},{$group->id},1809298800\n");

        $sink = $this->redirectEvents();
        $outcome = importer::import_file($file, true, 'term3.csv', 'upload');
        $events = $sink->get_events();
        $sink->close();
        unlink($file);

        $this->assertGreaterThan(0, $outcome['runid']);
        $run = $DB->get_record(import_log::RUN_TABLE, ['id' => $outcome['runid']], '*', MUST_EXIST);
        $this->assertEquals($user->id, $run->userid);
        $this->assertEquals('term3.csv', $run->filename);
        $this->assertEquals('upload', $run->source);
        $this->assertEquals(1, $run->countcreated);
        $this->assertEquals(2, $run->counterrors);
        $this->assertNull($run->fileerror);

        $failures = array_values($DB->get_records(import_log::FAIL_TABLE, ['runid' => $run->id], 'rownum'));
        $this->assertCount(2, $failures);
        $this->assertEquals(2, $failures[0]->rownum);
        $this->assertEquals(
            get_string('error_timestartformat', 'local_grpcalendarimport', '31/02/2027 09:00'),
            $failures[0]->message
        );
        $this->assertEquals('31/02/2027 09:00', json_decode($failures[0]->rowdata, true)['timestart']);
        $this->assertEquals(3, $failures[1]->rownum);

        $ours = array_values(array_filter($events, function ($event) {
            return $event->component === 'local_grpcalendarimport';
        }));
        $this->assertCount(3, $ours);
        $this->assertInstanceOf(event\import_row_failed::class, $ours[0]);
        $this->assertEquals('Bad date', $ours[0]->other['rowdata']['name']);
        $this->assertInstanceOf(event\import_completed::class, $ours[2]);
        $this->assertEquals(2, $ours[2]->other['errors']);
        $this->assertEquals($run->id, $ours[2]->objectid);
        $this->assertStringContainsString('term3.csv', $ours[2]->get_description());
    }

    /**
     * Test that a file rejected as a whole is recorded with the reason.
     */
    public function test_rejected_file_is_recorded(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $file = $this->temp_file("name,courseid\nEvent,2\n");

        $outcome = importer::import_file($file, true);
        unlink($file);

        $run = $DB->get_record(import_log::RUN_TABLE, ['id' => $outcome['runid']], '*', MUST_EXIST);
        $this->assertEquals($outcome['error'], $run->fileerror);
        $this->assertEquals('api', $run->source);
        $this->assertEquals(0, $DB->count_records(import_log::FAIL_TABLE, ['runid' => $run->id]));
    }

    /**
     * Test that only the first MAX_ROW_EVENTS failures become events, but every failure is kept.
     */
    public function test_row_events_are_capped(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $lines = ["name,courseid,groupid,timestart"];
        for ($i = 0; $i < import_log::MAX_ROW_EVENTS + 5; $i++) {
            $lines[] = "Event {$i},0,0,1809298800";
        }
        $file = $this->temp_file(implode("\n", $lines) . "\n");

        $sink = $this->redirectEvents();
        $outcome = importer::import_file($file, false);
        $rowevents = array_filter($sink->get_events(), function ($event) {
            return $event instanceof event\import_row_failed;
        });
        $sink->close();
        unlink($file);

        $this->assertCount(import_log::MAX_ROW_EVENTS, $rowevents);
        $stored = $DB->count_records(import_log::FAIL_TABLE, ['runid' => $outcome['runid']]);
        $this->assertEquals(import_log::MAX_ROW_EVENTS + 5, $stored);
    }

    /**
     * Test that the clean-up task deletes runs older than the retention setting, with their failed rows.
     */
    public function test_cleanup_task(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $file = $this->temp_file("name,courseid,groupid,timestart\nBad,0,0,1809298800\n");
        $old = importer::import_file($file, false)['runid'];
        $new = importer::import_file($file, false)['runid'];
        unlink($file);
        $DB->set_field(import_log::RUN_TABLE, 'timecreated', time() - 100 * DAYSECS, ['id' => $old]);

        set_config('historyretention', 90, 'local_grpcalendarimport');
        $task = new task\cleanup_history();
        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertFalse($DB->record_exists(import_log::RUN_TABLE, ['id' => $old]));
        $this->assertFalse($DB->record_exists(import_log::FAIL_TABLE, ['runid' => $old]));
        $this->assertTrue($DB->record_exists(import_log::RUN_TABLE, ['id' => $new]));

        // Keep forever.
        set_config('historyretention', 0, 'local_grpcalendarimport');
        $DB->set_field(import_log::RUN_TABLE, 'timecreated', time() - 1000 * DAYSECS, ['id' => $new]);
        ob_start();
        $task->execute();
        ob_end_clean();
        $this->assertTrue($DB->record_exists(import_log::RUN_TABLE, ['id' => $new]));
    }

    /**
     * Test that old runs are deleted in batches until none are left, and newer runs are kept.
     */
    public function test_delete_older_than_in_batches(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $file = $this->temp_file("name,courseid,groupid,timestart\nBad,0,0,1809298800\n");
        $old = [];
        for ($i = 0; $i < 5; $i++) {
            $old[] = importer::import_file($file, false)['runid'];
        }
        $new = importer::import_file($file, false)['runid'];
        unlink($file);
        [$insql, $params] = $DB->get_in_or_equal($old);
        $DB->set_field_select(import_log::RUN_TABLE, 'timecreated', 1000, "id {$insql}", $params);

        $this->assertEquals(5, import_log::delete_older_than(2000, 2));
        $this->assertEquals(0, $DB->count_records_select(import_log::RUN_TABLE, "id {$insql}", $params));
        $this->assertEquals(0, $DB->count_records_select(import_log::FAIL_TABLE, "runid {$insql}", $params));
        $this->assertTrue($DB->record_exists(import_log::RUN_TABLE, ['id' => $new]));
    }
}

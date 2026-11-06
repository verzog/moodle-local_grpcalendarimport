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
 * Unit tests for background imports.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport;

/**
 * Unit tests for background imports.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(background::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(task\background_import::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(import_log::class)]
final class background_test extends \advanced_testcase {
    /** @var \stdClass Course the test events are created in. */
    private \stdClass $course;

    /** @var \stdClass Group the test events are created for. */
    private \stdClass $group;

    /**
     * Reset after each test, log in as an administrator, and create a course with a group.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
    }

    /**
     * Write an import file with some valid rows followed by some rows that fail (they have no group).
     *
     * @param int $good Rows that create an event.
     * @param int $bad Rows that fail.
     * @return string Path to the file.
     */
    private function write_file(int $good, int $bad = 0): string {
        $lines = ['name,courseid,groupid,timestart'];
        for ($i = 1; $i <= $good; $i++) {
            $lines[] = "Event {$i},{$this->course->id},{$this->group->id}," . (1809298800 + $i * HOURSECS);
        }
        for ($i = 1; $i <= $bad; $i++) {
            $lines[] = "Bad {$i},{$this->course->id},0,1809298800";
        }
        $file = tempnam(sys_get_temp_dir(), 'grpcal_');
        file_put_contents($file, implode("\n", $lines) . "\n");
        return $file;
    }

    /**
     * Queue a file of rows as a background import.
     *
     * @param int $good Rows that create an event.
     * @param int $bad Rows that fail.
     * @return int The run id.
     */
    private function queue_rows(int $good, int $bad = 0): int {
        $file = $this->write_file($good, $bad);
        $runid = background::queue($file, 'big.csv', false, $good + $bad);
        unlink($file);
        return $runid;
    }

    /**
     * The run's record.
     *
     * @param int $runid The run.
     * @return \stdClass
     */
    private function get_run(int $runid): \stdClass {
        global $DB;
        return $DB->get_record(import_log::RUN_TABLE, ['id' => $runid], '*', MUST_EXIST);
    }

    /**
     * How many files a run has stored.
     *
     * @param int $runid The run.
     * @return int
     */
    private function count_stored_files(int $runid): int {
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_grpcalendarimport',
            import_log::FILEAREA,
            $runid,
            'id',
            false
        );
        return count($files);
    }

    /**
     * How many events the test course has.
     *
     * @return int
     */
    private function count_events(): int {
        global $DB;
        return $DB->count_records('event', ['courseid' => $this->course->id]);
    }

    /**
     * Test that queuing keeps the file, records a queued run and queues a task that runs as the uploader.
     */
    public function test_queue(): void {
        global $USER;
        $runid = $this->queue_rows(5);

        $run = $this->get_run($runid);
        $this->assertSame(import_log::STATUS_QUEUED, $run->status);
        $this->assertEquals(5, $run->totalrows);
        $this->assertEquals(0, $run->rowsdone);
        $this->assertEquals($USER->id, $run->userid);
        $this->assertSame('upload', $run->source);
        $this->assertSame(1, $this->count_stored_files($runid));
        $this->assertSame(0, $this->count_events());

        $tasks = \core\task\manager::get_adhoc_tasks(task\background_import::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertEquals($runid, $task->get_custom_data()->runid);
        $this->assertEquals($USER->id, $task->get_userid());
        $this->assertTrue(background::user_has_pending((int)$USER->id));
    }

    /**
     * Test that an import runs in parts, saving its progress, then finishes, deletes its file and notifies the uploader.
     */
    public function test_process_in_parts(): void {
        global $USER;
        $runid = $this->queue_rows(5, 2);
        $sink = $this->redirectMessages();

        $this->assertFalse(background::process($runid, 3));
        $run = $this->get_run($runid);
        $this->assertSame(import_log::STATUS_RUNNING, $run->status);
        $this->assertEquals(3, $run->rowsdone);
        $this->assertSame(3, $this->count_events());
        $this->assertTrue(background::user_has_pending((int)$USER->id));

        $this->assertFalse(background::process($runid, 3));
        $this->assertEquals(6, $this->get_run($runid)->rowsdone);
        $this->assertCount(0, $sink->get_messages());

        $this->assertTrue(background::process($runid, 3));
        $run = $this->get_run($runid);
        $this->assertSame(import_log::STATUS_DONE, $run->status);
        $this->assertEquals(7, $run->rowsdone);
        $this->assertEquals(5, $run->countcreated);
        $this->assertEquals(2, $run->counterrors);
        $this->assertNull($run->fileerror);
        $this->assertSame(5, $this->count_events());
        $this->assertSame(0, $this->count_stored_files($runid));
        $this->assertFalse(background::user_has_pending((int)$USER->id));

        // The failed rows keep their row numbers in the whole file.
        $failed = array_map('intval', array_column($this->get_failures($runid), 'rownum'));
        $this->assertSame([6, 7], $failed);

        $messages = $sink->get_messages();
        $sink->close();
        $this->assertCount(1, $messages);
        $this->assertSame('local_grpcalendarimport', $messages[0]->component);
        $this->assertSame('importfinished', $messages[0]->eventtype);
        $this->assertEquals($USER->id, $messages[0]->useridto);
        $this->assertStringContainsString('big.csv', $messages[0]->subject);

        // A finished run is left alone.
        $this->assertTrue(background::process($runid, 3));
        $this->assertSame(5, $this->count_events());
    }

    /**
     * A run's failed rows, in row order.
     *
     * @param int $runid The run.
     * @return \stdClass[]
     */
    private function get_failures(int $runid): array {
        global $DB;
        return array_values($DB->get_records(import_log::FAIL_TABLE, ['runid' => $runid], 'rownum'));
    }

    /**
     * Test that the adhoc task, run by cron as the uploader, imports the file and notifies them.
     */
    public function test_task_runs_whole_import(): void {
        $runid = $this->queue_rows(4);
        $sink = $this->redirectMessages();

        $this->expectOutputRegex('/has finished/');
        $this->runAdhocTasks(task\background_import::class);

        $run = $this->get_run($runid);
        $this->assertSame(import_log::STATUS_DONE, $run->status);
        $this->assertEquals(4, $run->countcreated);
        $this->assertSame(4, $this->count_events());
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(task\background_import::class));
        $this->assertCount(1, $sink->get_messages());
        $sink->close();
    }

    /**
     * Test that turning background imports off stops a queued import and tells the uploader.
     */
    public function test_turned_off_stops_import(): void {
        $runid = $this->queue_rows(5);
        set_config('backgroundimports', 0, 'local_grpcalendarimport');
        $this->assertFalse(background::enabled());
        $sink = $this->redirectMessages();

        $this->assertTrue(background::process($runid));

        $run = $this->get_run($runid);
        $this->assertSame(import_log::STATUS_STOPPED, $run->status);
        $this->assertSame(get_string('background_stopped', 'local_grpcalendarimport'), $run->fileerror);
        $this->assertSame(0, $this->count_events());
        $this->assertSame(0, $this->count_stored_files($runid));
        $messages = $sink->get_messages();
        $sink->close();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('stopped', $messages[0]->subject);
    }

    /**
     * Test that background imports are on until an administrator turns them off.
     */
    public function test_enabled_by_default(): void {
        unset_config('backgroundimports', 'local_grpcalendarimport');
        $this->assertTrue(background::enabled());
        set_config('backgroundimports', 1, 'local_grpcalendarimport');
        $this->assertTrue(background::enabled());
        set_config('backgroundimports', 0, 'local_grpcalendarimport');
        $this->assertFalse(background::enabled());
    }

    /**
     * Test that the history clean-up leaves unfinished background imports alone, and deletes a finished one's file.
     */
    public function test_cleanup_keeps_unfinished_runs(): void {
        global $DB;
        $runid = $this->queue_rows(2);
        $DB->set_field(import_log::RUN_TABLE, 'timecreated', time() - YEARSECS, ['id' => $runid]);

        $this->assertSame(0, import_log::delete_older_than(time()));
        $this->assertSame(1, $this->count_stored_files($runid));

        $DB->set_field(import_log::RUN_TABLE, 'status', import_log::STATUS_DONE, ['id' => $runid]);
        $this->assertSame(1, import_log::delete_older_than(time()));
        $this->assertFalse($DB->record_exists(import_log::RUN_TABLE, ['id' => $runid]));
        $this->assertSame(0, $this->count_stored_files($runid));
    }

    /**
     * Test that an import whose file has gone missing stops rather than failing every time it runs.
     */
    public function test_missing_file_stops_import(): void {
        $runid = $this->queue_rows(3);
        get_file_storage()->delete_area_files(
            \context_system::instance()->id,
            'local_grpcalendarimport',
            import_log::FILEAREA,
            $runid
        );
        $sink = $this->redirectMessages();

        $this->assertTrue(background::process($runid));
        $sink->close();

        $run = $this->get_run($runid);
        $this->assertSame(import_log::STATUS_STOPPED, $run->status);
        $this->assertSame(get_string('background_filemissing', 'local_grpcalendarimport'), $run->fileerror);
        $this->assertSame(0, $this->count_events());
    }
}

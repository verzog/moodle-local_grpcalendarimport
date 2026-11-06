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
 * Background imports: large files imported by cron instead of during the upload request.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport;

/**
 * Background imports: large files imported by cron instead of during the upload request.
 *
 * The uploaded file is kept in a file area until the import finishes. Each run of the adhoc task imports
 * up to ROWS_PER_TASK rows, saving its progress every BATCH rows, then queues another task for the rest.
 * If a task stops partway, the next one carries on from the last saved batch. Turning the
 * "backgroundimports" setting off stops queued imports the next time their task runs.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class background {
    /** @var int Most rows a background import accepts. */
    public const MAX_ROWS = 20000;

    /** @var int Most rows one run of the adhoc task imports before queuing another for the rest. */
    public const ROWS_PER_TASK = 2000;

    /** @var int Rows imported between saves of the progress, so a stopped task loses at most this many. */
    public const BATCH = 200;

    /**
     * Whether background imports are turned on (they are unless an administrator turns them off).
     *
     * @return bool
     */
    public static function enabled(): bool {
        $setting = get_config('local_grpcalendarimport', 'backgroundimports');
        return $setting === false || $setting === '' || !empty($setting);
    }

    /**
     * Whether a user has a background import that hasn't finished yet.
     *
     * A run only counts while a task is still queued to carry it on, so a run left unfinished with no task (for
     * example after Moodle gave up retrying its task) doesn't hide the user's upload form for good.
     *
     * @param int $userid The user.
     * @return bool
     */
    public static function user_has_pending(int $userid): bool {
        global $DB;
        $runids = $DB->get_fieldset_select(
            import_log::RUN_TABLE,
            'id',
            'userid = :userid AND (status = :queued OR status = :running)',
            ['userid' => $userid, 'queued' => import_log::STATUS_QUEUED, 'running' => import_log::STATUS_RUNNING]
        );
        if (empty($runids)) {
            return false;
        }
        $runids = array_map('intval', $runids);
        foreach (\core\task\manager::get_adhoc_tasks(task\background_import::class) as $task) {
            if (in_array((int)($task->get_custom_data()->runid ?? 0), $runids, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Queue a file to be imported in the background, as the current user.
     *
     * The caller checks the file first: it has the required columns and between 1 and MAX_ROWS data rows.
     *
     * @param string $filepath Absolute path to the file; it is copied, so the caller can delete it afterwards.
     * @param string $filename Name to show for the file in the history.
     * @param bool $skipduplicates Whether to skip events that already exist.
     * @param int $totalrows How many data rows the file has.
     * @return int The run id, for the import history.
     */
    public static function queue(string $filepath, string $filename, bool $skipduplicates, int $totalrows): int {
        global $USER;
        $runid = import_log::start_run($filename, 'upload', $skipduplicates, import_log::STATUS_QUEUED, $totalrows);
        get_file_storage()->create_file_from_pathname([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_grpcalendarimport',
            'filearea' => import_log::FILEAREA,
            'itemid' => $runid,
            'filepath' => '/',
            'filename' => 'import.csv',
            'userid' => $USER->id,
        ], $filepath);
        self::queue_task($runid, (int)$USER->id);
        return $runid;
    }

    /**
     * Import the next part of a background import.
     *
     * @param int $runid The run.
     * @param int $maxrows Most rows to import in this call.
     * @return bool True when the run has finished or stopped, false when rows remain and another task was queued.
     */
    public static function process(int $runid, int $maxrows = self::ROWS_PER_TASK): bool {
        global $DB;

        $run = $DB->get_record(import_log::RUN_TABLE, ['id' => $runid]);
        if (!$run || !in_array($run->status, [import_log::STATUS_QUEUED, import_log::STATUS_RUNNING], true)) {
            // Already finished or stopped, or deleted from the history.
            return true;
        }
        $component = 'local_grpcalendarimport';
        if (!self::enabled()) {
            self::finish($run, get_string('background_stopped', $component), import_log::STATUS_STOPPED);
            return true;
        }
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            $component,
            import_log::FILEAREA,
            $runid,
            'id',
            false
        );
        if (empty($files)) {
            self::finish($run, get_string('background_filemissing', $component), import_log::STATUS_STOPPED);
            return true;
        }
        if ($run->status === import_log::STATUS_QUEUED) {
            $DB->set_field(import_log::RUN_TABLE, 'status', import_log::STATUS_RUNNING, ['id' => $runid]);
        }

        $tmpfile = reset($files)->copy_content_to_temp();
        $rows = importer::parse_file($tmpfile);
        unlink($tmpfile);

        // Carry on from the last saved batch, a batch at a time, saving the progress after each one.
        $end = min(count($rows), (int)$run->rowsdone + max(1, $maxrows));
        for ($offset = (int)$run->rowsdone; $offset < $end; $offset += self::BATCH) {
            $length = min(self::BATCH, $end - $offset);
            $outcome = importer::create_events(array_slice($rows, $offset, $length), (bool)$run->skipduplicates, $offset + 1);
            import_log::add_results($runid, $outcome['results'], $rows, $offset + $length);
        }

        if ($end < count($rows)) {
            self::queue_task($runid, (int)$run->userid);
            return false;
        }
        self::finish($run, '', import_log::STATUS_DONE);
        return true;
    }

    /**
     * Queue the adhoc task that imports the next part of a run, to run as the user who uploaded the file.
     *
     * @param int $runid The run.
     * @param int $userid The user who uploaded the file.
     */
    private static function queue_task(int $runid, int $userid): void {
        $task = new task\background_import();
        $task->set_custom_data(['runid' => $runid]);
        if ($userid > 0) {
            $task->set_userid($userid);
        }
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Finish a run: delete its stored file, record it as finished, and tell the user who uploaded it.
     *
     * @param \stdClass $run The run.
     * @param string $error Why it stopped, or '' if it finished normally.
     * @param string $status import_log::STATUS_DONE or import_log::STATUS_STOPPED.
     */
    private static function finish(\stdClass $run, string $error, string $status): void {
        get_file_storage()->delete_area_files(
            \context_system::instance()->id,
            'local_grpcalendarimport',
            import_log::FILEAREA,
            $run->id
        );
        self::notify(import_log::finish_run((int)$run->id, $error, $status));
    }

    /**
     * Send the user who uploaded the file a notification that the import has finished or stopped.
     *
     * @param \stdClass $run The finished run.
     */
    private static function notify(\stdClass $run): void {
        if (empty($run->userid)) {
            return;
        }
        $user = \core_user::get_user($run->userid);
        if (!$user || !empty($user->deleted)) {
            return;
        }
        $component = 'local_grpcalendarimport';
        $url = new \moodle_url('/local/grpcalendarimport/history.php', ['id' => $run->id]);
        $a = (object)[
            'filename' => $run->filename,
            'created' => $run->countcreated,
            'skipped' => $run->countskipped,
            'errors' => $run->counterrors,
            'url' => $url->out(false),
        ];
        $stopped = $run->status === import_log::STATUS_STOPPED;

        $message = new \core\message\message();
        $message->component = $component;
        $message->name = 'importfinished';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = get_string($stopped ? 'background_subject_stopped' : 'background_subject', $component, $a);
        $message->fullmessage = get_string($stopped ? 'background_body_stopped' : 'background_body', $component, $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = get_string('history_heading', $component);
        message_send($message);
    }
}

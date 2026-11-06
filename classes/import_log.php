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
 * Import history: records each import run and its failed rows, and fires log events.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport;

/**
 * Import history: records each import run and its failed rows, and fires log events.
 *
 * Recording never throws: a logging problem is reported with debugging() and the import result stands.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_log {
    /** @var string Table of import runs. */
    public const RUN_TABLE = 'local_grpcalendarimport_run';

    /** @var string Table of failed rows. */
    public const FAIL_TABLE = 'local_grpcalendarimport_fail';

    /** @var int Most "row failed" log events per run; every failure is still kept in the history. */
    public const MAX_ROW_EVENTS = 50;

    /** @var int How many old runs the clean-up deletes per batch. */
    public const DELETE_BATCH = 1000;

    /** @var string Status of a background import that is waiting for cron. */
    public const STATUS_QUEUED = 'queued';

    /** @var string Status of a background import that has started but not finished. */
    public const STATUS_RUNNING = 'running';

    /** @var string Status of a finished import. Imports from the upload page or the API finish straight away. */
    public const STATUS_DONE = 'done';

    /** @var string Status of a background import stopped because background imports were turned off. */
    public const STATUS_STOPPED = 'stopped';

    /** @var string File area holding the uploaded file of a background import until it finishes. */
    public const FILEAREA = 'queuedimport';

    /**
     * Record an import run, its failed rows, and the matching log events.
     *
     * @param string $filename Name of the imported file, for display.
     * @param string $source What ran the import: 'upload', or the calling component's name.
     * @param bool $skipduplicates Whether duplicates were skipped.
     * @param array $outcome The result of importer::import_file() (error, counts, results).
     * @param array $rows The file's rows, in order, so failed rows can be stored with their values.
     * @return int The new run id, or 0 if it couldn't be recorded.
     */
    public static function record(string $filename, string $source, bool $skipduplicates, array $outcome, array $rows): int {
        try {
            // The import has already happened, so the run starts as done: if saving its results fails partway,
            // it must never look like an unfinished background import.
            $runid = self::start_run($filename, $source, $skipduplicates, self::STATUS_DONE, count($rows));
            self::add_results($runid, $outcome['results'], $rows, count($outcome['results']));
            self::finish_run($runid, $outcome['error']);
            return $runid;
        } catch (\Throwable $e) {
            debugging('Could not record the group calendar import history: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return 0;
        }
    }

    /**
     * Start recording a run, with no results yet.
     *
     * @param string $filename Name of the imported file, for display.
     * @param string $source What ran the import: 'upload', or the calling component's name.
     * @param bool $skipduplicates Whether duplicates are skipped.
     * @param string $status One of the STATUS_ constants.
     * @param int $totalrows How many data rows the file has.
     * @return int The new run id.
     */
    public static function start_run(string $filename, string $source, bool $skipduplicates, string $status, int $totalrows): int {
        global $DB, $USER;
        return (int)$DB->insert_record(self::RUN_TABLE, (object)[
            'userid' => (int)($USER->id ?? 0),
            'filename' => \core_text::substr($filename, 0, 255),
            'source' => \core_text::substr($source, 0, 100),
            'skipduplicates' => $skipduplicates ? 1 : 0,
            'countcreated' => 0,
            'countskipped' => 0,
            'counterrors' => 0,
            'fileerror' => null,
            'status' => $status,
            'totalrows' => $totalrows,
            'rowsdone' => 0,
            'timecreated' => time(),
        ]);
    }

    /**
     * Add some rows' results to a run: its counts, its failed rows, and their log events.
     *
     * Only the first MAX_ROW_EVENTS failures of a run, across every call, are logged as events.
     *
     * @param int $runid The run.
     * @param array $results Results for the rows, as returned by importer::create_event().
     * @param array $rows The file's rows, keyed from 0, so failed rows can be stored with their values.
     * @param int $rowsdone How many of the file's rows have now been processed in all.
     */
    public static function add_results(int $runid, array $results, array $rows, int $rowsdone): void {
        global $DB;
        $run = $DB->get_record(self::RUN_TABLE, ['id' => $runid], '*', MUST_EXIST);
        $context = \context_system::instance();
        $counts = ['ok' => 0, 'skip' => 0, 'error' => 0];
        // Failures already stored for this run have used up that many of its log events.
        $events = $DB->count_records(self::FAIL_TABLE, ['runid' => $runid]);
        // Failures after the ones logged as events don't need their ids, so they're saved in one batch.
        $unlogged = [];
        foreach ($results as $result) {
            $counts[$result['status']]++;
            if ($result['status'] !== 'error') {
                continue;
            }
            $rowdata = $rows[$result['row'] - 1] ?? [];
            $failure = (object)[
                'runid' => $runid,
                'rownum' => (int)$result['row'],
                'message' => $result['message'],
                'rowdata' => json_encode($rowdata),
            ];
            if ($events >= self::MAX_ROW_EVENTS) {
                $unlogged[] = $failure;
            } else {
                $failid = $DB->insert_record(self::FAIL_TABLE, $failure);
                $events++;
                event\import_row_failed::create([
                    'context' => $context,
                    'objectid' => $failid,
                    'other' => [
                        'runid' => $runid,
                        'filename' => $run->filename,
                        'rownum' => (int)$result['row'],
                        'message' => $result['message'],
                        'rowdata' => $rowdata,
                    ],
                ])->trigger();
            }
        }
        if ($unlogged) {
            $DB->insert_records(self::FAIL_TABLE, $unlogged);
        }

        $DB->update_record(self::RUN_TABLE, (object)[
            'id' => $runid,
            'countcreated' => $run->countcreated + $counts['ok'],
            'countskipped' => $run->countskipped + $counts['skip'],
            'counterrors' => $run->counterrors + $counts['error'],
            'rowsdone' => $rowsdone,
        ]);
    }

    /**
     * Finish a run and log it as completed.
     *
     * @param int $runid The run.
     * @param string $fileerror Why the whole file was rejected or stopped, or '' if it wasn't.
     * @param string $status STATUS_DONE, or STATUS_STOPPED for a background import that was turned off.
     * @return \stdClass The finished run.
     */
    public static function finish_run(int $runid, string $fileerror = '', string $status = self::STATUS_DONE): \stdClass {
        global $DB;
        $DB->update_record(self::RUN_TABLE, (object)[
            'id' => $runid,
            'status' => $status,
            'fileerror' => $fileerror !== '' ? $fileerror : null,
        ]);
        $run = $DB->get_record(self::RUN_TABLE, ['id' => $runid], '*', MUST_EXIST);
        event\import_completed::create([
            'context' => \context_system::instance(),
            'objectid' => $runid,
            'other' => [
                'filename' => $run->filename,
                'source' => $run->source,
                'created' => (int)$run->countcreated,
                'skipped' => (int)$run->countskipped,
                'errors' => (int)$run->counterrors,
                'fileerror' => (string)$run->fileerror,
            ],
        ])->trigger();
        return $run;
    }

    /**
     * Delete runs (and their failed rows) by id.
     *
     * @param int[] $runids Run ids.
     */
    public static function delete_runs(array $runids): void {
        global $DB;
        if (empty($runids)) {
            return;
        }
        $fs = get_file_storage();
        $contextid = \context_system::instance()->id;
        foreach (array_chunk($runids, 500) as $chunk) {
            // A background import's uploaded file is normally deleted when it finishes; this catches any left over.
            foreach ($chunk as $runid) {
                $fs->delete_area_files($contextid, 'local_grpcalendarimport', self::FILEAREA, $runid);
            }
            $DB->delete_records_list(self::FAIL_TABLE, 'runid', $chunk);
            $DB->delete_records_list(self::RUN_TABLE, 'id', $chunk);
        }
    }

    /**
     * Delete runs older than a given time, except background imports that haven't finished.
     *
     * @param int $before Delete runs recorded before this timestamp.
     * @param int $batch How many runs to fetch and delete at a time.
     * @return int How many runs were deleted.
     */
    public static function delete_older_than(int $before, int $batch = self::DELETE_BATCH): int {
        global $DB;
        // Work in batches so a large backlog never has to be held in memory at once.
        $deleted = 0;
        do {
            $runids = array_keys($DB->get_records_select(
                self::RUN_TABLE,
                'timecreated < :before AND status <> :queued AND status <> :running',
                ['before' => $before, 'queued' => self::STATUS_QUEUED, 'running' => self::STATUS_RUNNING],
                'id',
                'id',
                0,
                $batch
            ));
            self::delete_runs($runids);
            $deleted += count($runids);
        } while (count($runids) === $batch);
        return $deleted;
    }
}

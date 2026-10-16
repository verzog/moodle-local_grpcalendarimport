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
        global $DB, $USER;

        try {
            $now = time();
            $run = (object)[
                'userid' => (int)($USER->id ?? 0),
                'filename' => \core_text::substr($filename, 0, 255),
                'source' => \core_text::substr($source, 0, 100),
                'skipduplicates' => $skipduplicates ? 1 : 0,
                'countcreated' => (int)$outcome['counts']['ok'],
                'countskipped' => (int)$outcome['counts']['skip'],
                'counterrors' => (int)$outcome['counts']['error'],
                'fileerror' => $outcome['error'] !== '' ? $outcome['error'] : null,
                'timecreated' => $now,
            ];
            $run->id = $DB->insert_record(self::RUN_TABLE, $run);

            $context = \context_system::instance();
            $events = 0;
            foreach ($outcome['results'] as $result) {
                if ($result['status'] !== 'error') {
                    continue;
                }
                $rowdata = $rows[$result['row'] - 1] ?? [];
                $failid = $DB->insert_record(self::FAIL_TABLE, (object)[
                    'runid' => $run->id,
                    'rownum' => (int)$result['row'],
                    'message' => $result['message'],
                    'rowdata' => json_encode($rowdata),
                ]);
                if ($events < self::MAX_ROW_EVENTS) {
                    $events++;
                    event\import_row_failed::create([
                        'context' => $context,
                        'objectid' => $failid,
                        'other' => [
                            'runid' => $run->id,
                            'filename' => $run->filename,
                            'rownum' => (int)$result['row'],
                            'message' => $result['message'],
                            'rowdata' => $rowdata,
                        ],
                    ])->trigger();
                }
            }

            event\import_completed::create([
                'context' => $context,
                'objectid' => $run->id,
                'other' => [
                    'filename' => $run->filename,
                    'source' => $run->source,
                    'created' => $run->countcreated,
                    'skipped' => $run->countskipped,
                    'errors' => $run->counterrors,
                    'fileerror' => (string)$run->fileerror,
                ],
            ])->trigger();

            return (int)$run->id;
        } catch (\Throwable $e) {
            debugging('Could not record the group calendar import history: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return 0;
        }
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
        foreach (array_chunk($runids, 500) as $chunk) {
            $DB->delete_records_list(self::FAIL_TABLE, 'runid', $chunk);
            $DB->delete_records_list(self::RUN_TABLE, 'id', $chunk);
        }
    }

    /**
     * Delete runs older than a given time.
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
                'timecreated < :before',
                ['before' => $before],
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

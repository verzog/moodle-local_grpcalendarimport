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
 * Privacy API implementation for the GRP Calendar Import plugin.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_grpcalendarimport\import_log;

/**
 * Privacy provider for the local_grpcalendarimport plugin.
 *
 * The import history records which user ran each import, in the system context. The calendar events
 * an import creates are core calendar data and are covered by core_calendar.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data the plugin stores.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(import_log::RUN_TABLE, [
            'userid' => 'privacy:metadata:run:userid',
            'filename' => 'privacy:metadata:run:filename',
            'source' => 'privacy:metadata:run:source',
            'countcreated' => 'privacy:metadata:run:counts',
            'countskipped' => 'privacy:metadata:run:counts',
            'counterrors' => 'privacy:metadata:run:counts',
            'fileerror' => 'privacy:metadata:run:fileerror',
            'timecreated' => 'privacy:metadata:run:timecreated',
        ], 'privacy:metadata:run');
        $collection->add_database_table(import_log::FAIL_TABLE, [
            'rownum' => 'privacy:metadata:fail:rownum',
            'message' => 'privacy:metadata:fail:message',
            'rowdata' => 'privacy:metadata:fail:rowdata',
        ], 'privacy:metadata:fail');
        return $collection;
    }

    /**
     * The system context, if the user has run any imports.
     *
     * @param int $userid The user.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        if ($DB->record_exists(import_log::RUN_TABLE, ['userid' => $userid])) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Users with import history in the context.
     *
     * @param userlist $userlist The userlist to add to.
     */
    public static function get_users_in_context(userlist $userlist) {
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {' . import_log::RUN_TABLE . '} WHERE userid > 0', []);
    }

    /**
     * Export the user's import history.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_SYSTEM) {
                continue;
            }
            $runs = [];
            foreach ($DB->get_records(import_log::RUN_TABLE, ['userid' => $userid], 'timecreated') as $run) {
                $failures = [];
                foreach ($DB->get_records(import_log::FAIL_TABLE, ['runid' => $run->id], 'rownum') as $failure) {
                    $failures[] = [
                        'row' => $failure->rownum,
                        'error' => $failure->message,
                        'values' => json_decode((string)$failure->rowdata, true),
                    ];
                }
                $runs[] = [
                    'file' => $run->filename,
                    'source' => $run->source,
                    'created' => $run->countcreated,
                    'skipped' => $run->countskipped,
                    'errors' => $run->counterrors,
                    'fileerror' => $run->fileerror,
                    'time' => transform::datetime($run->timecreated),
                    'failures' => $failures,
                ];
            }
            if ($runs) {
                writer::with_context($context)->export_data(
                    [get_string('history_heading', 'local_grpcalendarimport')],
                    (object)['runs' => $runs]
                );
            }
        }
    }

    /**
     * Delete all import history in the context.
     *
     * @param \context $context The context.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            import_log::delete_runs($DB->get_fieldset_select(import_log::RUN_TABLE, 'id', '1 = 1'));
        }
    }

    /**
     * Delete one user's import history.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                self::delete_for_userids([$contextlist->get_user()->id]);
            }
        }
    }

    /**
     * Delete several users' import history.
     *
     * @param approved_userlist $userlist The approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        if ($userlist->get_context()->contextlevel == CONTEXT_SYSTEM) {
            self::delete_for_userids($userlist->get_userids());
        }
    }

    /**
     * Delete the import runs (and their failed rows) of the given users.
     *
     * @param int[] $userids User ids.
     */
    private static function delete_for_userids(array $userids): void {
        global $DB;
        if (empty($userids)) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        import_log::delete_runs($DB->get_fieldset_select(import_log::RUN_TABLE, 'id', "userid {$insql}", $params));
    }
}

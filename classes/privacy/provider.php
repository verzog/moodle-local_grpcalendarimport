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

/**
 * Privacy API implementation for the GRP Calendar Import plugin.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 SCCA
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for the local_grpcalendarimport plugin.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 SCCA
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Return the fields which contain personal data.
     *
     * @param collection $collection The collection of personal data.
     * @return collection The collection of personal data.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'event',
            [
                'userid' => 'privacy:metadata:event:userid',
                'description' => 'privacy:metadata:event:description',
                'location' => 'privacy:metadata:event:location',
                'uuid' => 'privacy:metadata:event:uuid',
            ],
            'privacy:metadata:event'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user data.
     *
     * @param int $userid The user ID.
     * @return contextlist The contextlist.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = 'SELECT DISTINCT c.id
                  FROM {context} c
                  JOIN {course} co ON c.instanceid = co.id
                  JOIN {event} e ON e.courseid = co.id
                 WHERE c.contextlevel = :contextlevel
                   AND e.userid = :userid';

        $params = [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ];

        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get the list of users in a context.
     *
     * @param userlist $userlist The userlist.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $sql = 'SELECT DISTINCT e.userid
                  FROM {event} e
                 WHERE e.courseid = :courseid
                   AND e.userid != 0';

        $params = [
            'courseid' => $context->instanceid,
        ];

        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Delete all user data in a list of contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return void
     */
    public static function delete_data_for_contexts(approved_contextlist $contextlist): void {
        global $DB;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            $DB->delete_records('event', ['courseid' => $context->instanceid]);
        }
    }

    /**
     * Delete all user data for a given user.
     *
     * @param int $userid The user ID.
     * @return void
     */
    public static function delete_data_for_user(int $userid): void {
        global $DB;

        $DB->delete_records('event', ['userid' => $userid]);
    }

    /**
     * Delete user data in bulk for a list of users.
     *
     * @param approved_userlist $userlist The approved user list.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $userids = $userlist->get_userids();

        foreach ($userids as $userid) {
            $DB->delete_records('event', [
                'userid' => $userid,
                'courseid' => $context->instanceid,
            ]);
        }
    }
}

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
 * Privacy provider tests for local_grpcalendarimport.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_grpcalendarimport\import_log;
use local_grpcalendarimport\importer;

/**
 * Privacy provider tests for local_grpcalendarimport.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Run an import with one failing row as the given user.
     *
     * @param \stdClass $user The user.
     * @return int The run id.
     */
    private function import_as(\stdClass $user): int {
        $this->setUser($user);
        $file = tempnam(sys_get_temp_dir(), 'grpcal_');
        file_put_contents($file, "name,courseid,groupid,timestart\nBad,0,0,1809298800\n");
        $runid = importer::import_file($file, false, 'privacy.csv')['runid'];
        unlink($file);
        return $runid;
    }

    /**
     * Test the metadata describes both tables.
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('local_grpcalendarimport'));
        $names = array_map(function ($item) {
            return $item->get_name();
        }, $collection->get_collection());
        $this->assertContains(import_log::RUN_TABLE, $names);
        $this->assertContains(import_log::FAIL_TABLE, $names);
    }

    /**
     * Test contexts and users are found for users with history, and only them.
     */
    public function test_contexts_and_users(): void {
        $this->resetAfterTest();
        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();
        $this->import_as($u1);
        $system = \context_system::instance();

        $this->assertEquals([$system->id], provider::get_contexts_for_userid($u1->id)->get_contextids());
        $this->assertEmpty(provider::get_contexts_for_userid($u2->id)->get_contextids());

        $userlist = new userlist($system, 'local_grpcalendarimport');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$u1->id], $userlist->get_userids());
    }

    /**
     * Test the user's runs and failed rows are exported.
     */
    public function test_export_user_data(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->import_as($user);
        $system = \context_system::instance();

        provider::export_user_data(new approved_contextlist($user, 'local_grpcalendarimport', [$system->id]));

        $data = writer::with_context($system)->get_data([get_string('history_heading', 'local_grpcalendarimport')]);
        $this->assertCount(1, $data->runs);
        $this->assertEquals('privacy.csv', $data->runs[0]['file']);
        $this->assertEquals('Bad', $data->runs[0]['failures'][0]['values']['name']);
    }

    /**
     * Test deleting one user's, several users', and everyone's history.
     */
    public function test_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();
        $u3 = $this->getDataGenerator()->create_user();
        $run1 = $this->import_as($u1);
        $run2 = $this->import_as($u2);
        $this->import_as($u3);
        $system = \context_system::instance();

        provider::delete_data_for_user(new approved_contextlist($u1, 'local_grpcalendarimport', [$system->id]));
        $this->assertFalse($DB->record_exists(import_log::RUN_TABLE, ['id' => $run1]));
        $this->assertFalse($DB->record_exists(import_log::FAIL_TABLE, ['runid' => $run1]));
        $this->assertTrue($DB->record_exists(import_log::RUN_TABLE, ['id' => $run2]));

        provider::delete_data_for_users(new approved_userlist($system, 'local_grpcalendarimport', [$u2->id]));
        $this->assertFalse($DB->record_exists(import_log::RUN_TABLE, ['id' => $run2]));
        $this->assertEquals(1, $DB->count_records(import_log::RUN_TABLE));

        provider::delete_data_for_all_users_in_context($system);
        $this->assertEquals(0, $DB->count_records(import_log::RUN_TABLE));
        $this->assertEquals(0, $DB->count_records(import_log::FAIL_TABLE));
    }
}

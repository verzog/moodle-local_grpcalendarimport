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
 * Tests for who can reach the plugin's admin pages, and the manage capability's definition.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport;

/**
 * Tests for who can reach the plugin's admin pages, and the manage capability's definition.
 *
 * settings.php and db/access.php are not classes, so there is no class to cover.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class access_test extends \advanced_testcase {
    /**
     * Load the admin tree as the current user.
     *
     * @return \admin_root
     */
    private function admin_tree(): \admin_root {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        return admin_get_root(true, true);
    }

    /**
     * Test that a manager, who isn't a site administrator, can open the import and history pages.
     */
    public function test_manager_can_reach_pages(): void {
        global $DB;
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($roleid, $manager->id, \context_system::instance());
        $this->setUser($manager);

        $tree = $this->admin_tree();
        foreach (['local_grpcalendarimport', 'local_grpcalendarimport_history'] as $name) {
            $page = $tree->locate($name);
            $this->assertNotEmpty($page, "The {$name} page isn't in a manager's admin tree.");
            $this->assertTrue($page->check_access());
        }
        // The retention setting is site configuration, for site administrators only.
        $this->assertEmpty($tree->locate('local_grpcalendarimport_settings'));
    }

    /**
     * Test that a user without the capability doesn't get the pages.
     */
    public function test_user_without_capability_cannot_reach_pages(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $tree = $this->admin_tree();
        $this->assertEmpty($tree->locate('local_grpcalendarimport'));
        $this->assertEmpty($tree->locate('local_grpcalendarimport_history'));
    }

    /**
     * Test that a site administrator gets the pages and the retention setting.
     */
    public function test_admin_can_reach_pages_and_setting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $tree = $this->admin_tree();
        $this->assertNotEmpty($tree->locate('local_grpcalendarimport'));
        $this->assertNotEmpty($tree->locate('local_grpcalendarimport_history'));
        $this->assertNotEmpty($tree->locate('local_grpcalendarimport_settings'));
    }

    /**
     * Test that the manage capability carries the configuration and spam risks.
     */
    public function test_capability_risks(): void {
        global $DB;
        $risks = (int)$DB->get_field('capabilities', 'riskbitmask', ['name' => 'local/grpcalendarimport:manage'], MUST_EXIST);
        $this->assertSame(RISK_CONFIG | RISK_SPAM, $risks);
    }
}

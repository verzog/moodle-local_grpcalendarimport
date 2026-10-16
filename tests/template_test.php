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
 * Unit tests for the prefilled template builder.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport;

/**
 * Unit tests for the prefilled template builder.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(template::class)]
final class template_test extends \advanced_testcase {
    /**
     * Test that a category's courses are found, with or without its subcategories, and never the front page.
     */
    public function test_find_courses_by_category(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $parent = $generator->create_category();
        $child = $generator->create_category(['parent' => $parent->id]);
        $c1 = $generator->create_course(['category' => $parent->id]);
        $c2 = $generator->create_course(['category' => $child->id]);
        $generator->create_course();

        $this->assertEquals([$c1->id], array_keys(template::find_courses($parent->id, false, [])));

        $withchildren = array_keys(template::find_courses($parent->id, true, []));
        sort($withchildren);
        $this->assertEquals([$c1->id, $c2->id], $withchildren);

        $this->assertArrayNotHasKey(SITEID, template::find_courses(0, false, [SITEID, $c1->id]));
    }

    /**
     * Test that chosen courses are added to a category's courses without duplicates.
     */
    public function test_find_courses_merges_and_dedupes(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $c1 = $generator->create_course(['category' => $category->id]);
        $c2 = $generator->create_course();

        $courses = template::find_courses($category->id, true, [$c1->id, $c2->id]);

        $ids = array_keys($courses);
        sort($ids);
        $this->assertEquals([$c1->id, $c2->id], $ids);
    }

    /**
     * Test one row per group per event, with names filled in and courses without groups reported.
     */
    public function test_build_rows_and_nogroups(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Year 9 Science']);
        $g1 = $generator->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        $generator->create_group(['courseid' => $course->id, 'name' => 'Group B']);
        $empty = $generator->create_course(['fullname' => 'No groups here']);

        $outcome = template::build(template::find_courses(0, false, [$course->id, $empty->id]), 3);

        $this->assertCount(6, $outcome['rows']);
        $this->assertEquals(2, $outcome['groupcount']);
        $this->assertFalse($outcome['truncated']);
        $this->assertEquals([$empty->id => 'No groups here'], $outcome['nogroups']);

        $first = $outcome['rows'][0];
        $this->assertEquals(template::columns(), array_keys($first));
        $this->assertEquals($course->id, $first['courseid']);
        $this->assertEquals('Year 9 Science', $first['course_name']);
        $this->assertEquals($g1->id, $first['groupid']);
        $this->assertEquals('Group A', $first['group_name']);
        $this->assertEquals('', $first['name']);
        $this->assertEquals('', $first['timestart']);
        $this->assertEquals('group', $first['eventtype']);
    }

    /**
     * Test that the row limit cuts the template off and says so.
     */
    public function test_build_truncates(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $generator->create_group(['courseid' => $course->id]);
        $generator->create_group(['courseid' => $course->id]);

        $outcome = template::build(template::find_courses(0, false, [$course->id]), 3, 4);

        $this->assertCount(4, $outcome['rows']);
        $this->assertTrue($outcome['truncated']);
    }

    /**
     * Test that out-of-range events per group are clamped.
     */
    public function test_build_clamps_events_per_group(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $generator->create_group(['courseid' => $course->id]);
        $courses = template::find_courses(0, false, [$course->id]);

        $this->assertCount(1, template::build($courses, 0)['rows']);
        $this->assertCount(template::MAX_EVENTS_PER_GROUP, template::build($courses, 1000)['rows']);
    }

    /**
     * Test that the CSV and Excel files are written, with the importer's column names as the CSV header.
     */
    public function test_write_csv_and_excel(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $generator->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        $rows = template::build(template::find_courses(0, false, [$course->id]), 2)['rows'];
        $callback = function (array $row): array {
            return array_values($row);
        };

        $csvfile = \core\dataformat::write_data('template', 'csv', template::columns(), $rows, $callback);
        $lines = array_values(array_filter(array_map('trim', file($csvfile))));
        $this->assertEquals(implode(',', template::columns()), preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]));
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('Group A', $lines[1]);

        $excelfile = \core\dataformat::write_data('template', 'excel', template::columns(), $rows, $callback);
        $this->assertGreaterThan(0, filesize($excelfile));
        // An .xlsx file is a zip archive.
        $this->assertSame('PK', file_get_contents($excelfile, false, null, 0, 2));
    }

    /**
     * Test that the form needs a category or a course, and a sensible number of events per group.
     */
    public function test_form_validation(): void {
        global $CFG;
        require_once($CFG->libdir . '/formslib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $form = new form\template_form();
        $errors = $form->validation(['categoryid' => 0, 'courseids' => [], 'eventspergroup' => 1], []);
        $this->assertArrayHasKey('categoryid', $errors);

        $errors = $form->validation(['categoryid' => 1, 'courseids' => [], 'eventspergroup' => 0], []);
        $this->assertArrayNotHasKey('categoryid', $errors);
        $this->assertArrayHasKey('eventspergroup', $errors);

        $errors = $form->validation(['categoryid' => 0, 'courseids' => [2], 'eventspergroup' => 5], []);
        $this->assertEmpty($errors);

        // The form renders without notices.
        $this->assertStringContainsString('eventspergroup', $form->render());
    }

    /**
     * Test that a filled-in template imports as it is: the extra name columns are ignored.
     */
    public function test_filled_template_imports(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user(['timezone' => 'Australia/Sydney']));
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Maths, Year 10']);
        $generator->create_group(['courseid' => $course->id, 'name' => 'Group "Alpha"']);

        $outcome = template::build(template::find_courses(0, false, [$course->id]), 2);

        // Fill in the template the way a person would, then write it as CSV.
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, template::columns(), ',', '"', '');
        foreach ($outcome['rows'] as $i => $row) {
            $row['name'] = 'Lesson ' . ($i + 1);
            $row['timestart'] = '0' . ($i + 3) . '/05/2027 09:00';
            fputcsv($handle, array_values($row), ',', '"', '');
        }
        rewind($handle);
        $tmpfile = tempnam(sys_get_temp_dir(), 'grpcal_');
        file_put_contents($tmpfile, stream_get_contents($handle));
        fclose($handle);

        $result = importer::import_file($tmpfile, true);
        unlink($tmpfile);

        $this->assertEquals('', $result['error']);
        $this->assertEquals(['ok' => 2, 'skip' => 0, 'error' => 0], $result['counts']);
    }
}

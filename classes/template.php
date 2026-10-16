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
 * Builds prefilled import templates: one row per group, ready for event details.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grpcalendarimport;

/**
 * Builds prefilled import templates: one row per group, repeated for each event to be created.
 *
 * The rows use the importer's column names, plus course_name and group_name for readability; the
 * importer ignores columns it doesn't know, so a filled-in template uploads unchanged.
 *
 * @package   local_grpcalendarimport
 * @copyright 2026 Vernon Spain
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template {
    /** @var int Most rows a template can have; larger selections are cut off with a warning. */
    public const MAX_ROWS = 5000;

    /** @var int Most event rows that can be requested per group. */
    public const MAX_EVENTS_PER_GROUP = 50;

    /**
     * Column names, in the order they appear in the template.
     *
     * @return string[]
     */
    public static function columns(): array {
        return [
            'name', 'courseid', 'course_name', 'groupid', 'group_name', 'timestart',
            'timeduration', 'description', 'location', 'eventtype', 'visible',
        ];
    }

    /**
     * Find the courses in a category (optionally with its subcategories) and/or a list of course ids.
     *
     * The site front page is never included. Courses are returned once each, in course order.
     *
     * @param int $categoryid Category id, or 0 for none.
     * @param bool $subcategories Whether to include courses in subcategories of $categoryid.
     * @param int[] $courseids Extra course ids.
     * @return \stdClass[] Course records (id, category, fullname, shortname, sortorder), keyed by id.
     */
    public static function find_courses(int $categoryid, bool $subcategories, array $courseids): array {
        global $DB;

        $fields = 'id, category, fullname, shortname, sortorder';
        $courses = [];
        if ($categoryid > 0) {
            $category = \core_course_category::get($categoryid, MUST_EXIST, true);
            $categoryids = [$category->id];
            if ($subcategories) {
                $categoryids = array_merge($categoryids, $category->get_all_children_ids());
            }
            $courses = $DB->get_records_list('course', 'category', $categoryids, 'sortorder', $fields);
        }
        $courseids = array_filter(array_map('intval', $courseids));
        if (!empty($courseids)) {
            $courses += $DB->get_records_list('course', 'id', $courseids, 'sortorder', $fields);
        }
        unset($courses[SITEID]);

        uasort($courses, function ($a, $b) {
            return $a->sortorder <=> $b->sortorder;
        });
        return $courses;
    }

    /**
     * Build the template rows: one row per group, repeated $eventspergroup times.
     *
     * @param \stdClass[] $courses Courses, as returned by find_courses().
     * @param int $eventspergroup Rows to create for each group.
     * @param int $maxrows Stop once this many rows have been built.
     * @return array Keys: rows (each keyed by columns()), groupcount (int), nogroups (course id => course name),
     *               truncated (bool, true when $maxrows was reached before every group was added).
     */
    public static function build(array $courses, int $eventspergroup, int $maxrows = self::MAX_ROWS): array {
        $eventspergroup = max(1, min($eventspergroup, self::MAX_EVENTS_PER_GROUP));
        $outcome = ['rows' => [], 'groupcount' => 0, 'nogroups' => [], 'truncated' => false];

        foreach ($courses as $course) {
            $context = \context_course::instance($course->id);
            $coursename = format_string($course->fullname, true, ['context' => $context, 'escape' => false]);
            $groups = groups_get_all_groups($course->id, 0, 0, 'g.id, g.name');
            if (empty($groups)) {
                $outcome['nogroups'][$course->id] = $coursename;
                continue;
            }
            foreach ($groups as $group) {
                $groupname = format_string($group->name, true, ['context' => $context, 'escape' => false]);
                $outcome['groupcount']++;
                for ($i = 0; $i < $eventspergroup; $i++) {
                    if (count($outcome['rows']) >= $maxrows) {
                        $outcome['truncated'] = true;
                        return $outcome;
                    }
                    $outcome['rows'][] = [
                        'name' => '',
                        'courseid' => $course->id,
                        'course_name' => $coursename,
                        'groupid' => $group->id,
                        'group_name' => $groupname,
                        'timestart' => '',
                        'timeduration' => 3600,
                        'description' => '',
                        'location' => '',
                        'eventtype' => 'group',
                        'visible' => 1,
                    ];
                }
            }
        }
        return $outcome;
    }
}

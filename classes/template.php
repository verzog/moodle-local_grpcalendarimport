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

    /** @var int Most course names listed in the "courses without groups" warning. */
    public const MAX_NOGROUPS_LISTED = 10;

    /** @var int Most courses or categories looked up in one database query. */
    private const LOOKUP_CHUNK = 500;

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
        $courses = [];
        if ($categoryid > 0) {
            $category = \core_course_category::get($categoryid, MUST_EXIST, true);
            $categoryids = [$category->id];
            if ($subcategories) {
                $categoryids = array_merge($categoryids, $category->get_all_children_ids());
            }
            $courses = self::get_courses('category', $categoryids);
        }
        $courseids = array_filter(array_map('intval', $courseids));
        if (!empty($courseids)) {
            $courses += self::get_courses('id', $courseids);
        }
        unset($courses[SITEID]);

        uasort($courses, function ($a, $b) {
            return $a->sortorder <=> $b->sortorder;
        });
        return $courses;
    }

    /**
     * Fetch courses by id or by category, loading each course's context in the same query.
     *
     * Preloading the contexts means build() needs no query per course to format its name.
     *
     * @param string $field Column to match: 'id' or 'category'.
     * @param int[] $values Values to match.
     * @return \stdClass[] Course records (id, category, fullname, shortname, sortorder), keyed by id.
     */
    private static function get_courses(string $field, array $values): array {
        global $DB;

        // The column name goes into the SQL, so only these two are allowed.
        if (!in_array($field, ['id', 'category'], true)) {
            throw new \coding_exception("Can't look courses up by '{$field}'.");
        }
        $ctxselect = \context_helper::get_preload_record_columns_sql('ctx');
        $courses = [];
        foreach (array_chunk(array_values(array_unique($values)), self::LOOKUP_CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $params['contextlevel'] = CONTEXT_COURSE;
            $sql = "SELECT c.id, c.category, c.fullname, c.shortname, c.sortorder, {$ctxselect}
                      FROM {course} c
                 LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :contextlevel
                     WHERE c.{$field} {$insql}";
            foreach ($DB->get_records_sql($sql, $params) as $course) {
                // This caches the context and removes its columns from the record.
                \context_helper::preload_from_record($course);
                $courses[$course->id] = $course;
            }
        }
        return $courses;
    }

    /**
     * Fetch the groups of several courses that the current user can see, in at most two queries.
     *
     * Applies the same visibility rule as groups_get_all_groups(): in a course where the user lacks
     * moodle/course:viewhiddengroups, only groups visible to everyone, and "members" or "own" groups the user
     * belongs to, are returned.
     *
     * @param int[] $courseids Course ids.
     * @return array Course id => list of group records (id, courseid, name), in name order.
     */
    private static function get_groups(array $courseids): array {
        global $DB, $USER;

        // The course contexts were preloaded by find_courses(), so these checks don't query per course.
        $viewall = [];
        $restricted = [];
        foreach ($courseids as $courseid) {
            if (has_capability('moodle/course:viewhiddengroups', \context_course::instance($courseid))) {
                $viewall[] = $courseid;
            } else {
                $restricted[] = $courseid;
            }
        }

        $bycourse = [];
        if (!empty($viewall)) {
            [$insql, $params] = $DB->get_in_or_equal($viewall, SQL_PARAMS_NAMED, 'cid');
            $sql = "SELECT g.id, g.courseid, g.name
                      FROM {groups} g
                     WHERE g.courseid {$insql}
                  ORDER BY g.courseid, g.name, g.id";
            self::add_groups($bycourse, $sql, $params);
        }
        if (!empty($restricted)) {
            [$insql, $params] = $DB->get_in_or_equal($restricted, SQL_PARAMS_NAMED, 'cid');
            [$visinsql, $visparams] = $DB->get_in_or_equal(
                [GROUPS_VISIBILITY_MEMBERS, GROUPS_VISIBILITY_OWN],
                SQL_PARAMS_NAMED,
                'vis'
            );
            $params += $visparams + ['userid' => $USER->id, 'visall' => GROUPS_VISIBILITY_ALL];
            $sql = "SELECT g.id, g.courseid, g.name
                      FROM {groups} g
                 LEFT JOIN {groups_members} gm ON gm.groupid = g.id AND gm.userid = :userid
                     WHERE g.courseid {$insql}
                       AND (g.visibility = :visall OR (g.visibility {$visinsql} AND gm.id IS NOT NULL))
                  ORDER BY g.courseid, g.name, g.id";
            self::add_groups($bycourse, $sql, $params);
        }
        return $bycourse;
    }

    /**
     * Run a group query and add its groups to a list keyed by course id.
     *
     * @param array $bycourse Course id => list of group records, added to.
     * @param string $sql Query returning id, courseid and name.
     * @param array $params Query parameters.
     */
    private static function add_groups(array &$bycourse, string $sql, array $params): void {
        global $DB;
        $groups = $DB->get_recordset_sql($sql, $params);
        foreach ($groups as $group) {
            $bycourse[$group->courseid][] = $group;
        }
        $groups->close();
    }

    /**
     * Build the template rows: one row per group, repeated $eventspergroup times.
     *
     * @param \stdClass[] $courses Courses, as returned by find_courses().
     * @param int $eventspergroup Rows to create for each group.
     * @param int $maxrows Stop once this many rows have been built.
     * @return array Keys: rows (each keyed by columns()), coursecount and groupcount (int, courses and groups
     *               with at least one row), nogroups (course id => course name), truncated (bool, true when
     *               $maxrows was reached before every group was added).
     */
    public static function build(array $courses, int $eventspergroup, int $maxrows = self::MAX_ROWS): array {
        $eventspergroup = max(1, min($eventspergroup, self::MAX_EVENTS_PER_GROUP));
        $outcome = ['rows' => [], 'coursecount' => 0, 'groupcount' => 0, 'nogroups' => [], 'truncated' => false];

        // Work through the courses a batch at a time, fetching each batch's groups in one query, and stop
        // as soon as the row limit is reached so the remaining courses are never looked at.
        foreach (array_chunk($courses, self::LOOKUP_CHUNK) as $batch) {
            $groupsbycourse = self::get_groups(array_map(function ($course) {
                return (int)$course->id;
            }, $batch));
            foreach ($batch as $course) {
                $context = \context_course::instance($course->id);
                $coursename = format_string($course->fullname, true, ['context' => $context, 'escape' => false]);
                $groups = $groupsbycourse[$course->id] ?? [];
                if (empty($groups)) {
                    $outcome['nogroups'][$course->id] = $coursename;
                    continue;
                }
                $coursecounted = false;
                foreach ($groups as $group) {
                    $groupname = format_string($group->name, true, ['context' => $context, 'escape' => false]);
                    for ($i = 0; $i < $eventspergroup; $i++) {
                        if (count($outcome['rows']) >= $maxrows) {
                            $outcome['truncated'] = true;
                            return $outcome;
                        }
                        // Count the course and group once their first row is in.
                        if (!$coursecounted) {
                            $outcome['coursecount']++;
                            $coursecounted = true;
                        }
                        if ($i === 0) {
                            $outcome['groupcount']++;
                        }
                        $outcome['rows'][] = [
                            'name' => '',
                            'courseid' => $course->id,
                            'course_name' => self::escape_formula($coursename),
                            'groupid' => $group->id,
                            'group_name' => self::escape_formula($groupname),
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
        }
        return $outcome;
    }

    /**
     * The warning for courses without groups, listing the first few course names and counting the rest.
     *
     * @param string[] $nogroups Course names, as in build()'s nogroups.
     * @return string The warning, with the course names escaped for HTML.
     */
    public static function nogroups_message(array $nogroups): string {
        $component = 'local_grpcalendarimport';
        $listed = s(implode(', ', array_slice($nogroups, 0, self::MAX_NOGROUPS_LISTED)));
        $more = count($nogroups) - self::MAX_NOGROUPS_LISTED;
        if ($more <= 0) {
            return get_string('template_nogroups', $component, $listed);
        }
        return get_string('template_nogroups_more', $component, (object)['courses' => $listed, 'more' => $more]);
    }

    /**
     * Stop a spreadsheet treating a name as a formula, by putting a quote in front of =, +, - or @.
     *
     * Moodle 5.1.1 and later do this for CSV and Excel downloads too; the quote this adds stops core adding a
     * second one, so the result is the same on every 5.1 release.
     *
     * @param string $value The cell value.
     * @return string
     */
    public static function escape_formula(string $value): string {
        $trimmed = ltrim($value);
        if ($value === '-' || $trimmed === '' || !in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            return $value;
        }
        return "'" . $value;
    }
}

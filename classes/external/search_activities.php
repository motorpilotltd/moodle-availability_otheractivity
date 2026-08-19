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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace availability_otheractivity\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Search the whole site for activities with completion tracking enabled.
 *
 * Used by the availability dialog to populate the activity picker. Results
 * are scoped: users holding availability/otheractivity:searchall (managers by
 * default) search every course, including hidden ones; anyone else only sees
 * courses where they can manage activities. Users with no editing rights
 * anywhere get nothing, whichever mode they call.
 *
 * @package    availability_otheractivity
 * @copyright  2026 Simon Lewis
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_activities extends external_api {
    /** @var int Maximum number of results returned by a search. */
    const MAX_RESULTS = 100;

    /** @var int Minimum length of a search query. */
    const MIN_QUERY_LENGTH = 2;

    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_RAW_TRIMMED, 'Search text', VALUE_DEFAULT, ''),
            'cmid' => new external_value(
                PARAM_INT,
                'Exact course module id to look up instead of searching',
                VALUE_DEFAULT,
                0
            ),
            'excludecmid' => new external_value(
                PARAM_INT,
                'Course module id to omit from results',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Run the search (or an exact lookup when cmid is given).
     *
     * @param string $query Search text, matched against activity names and course names.
     * @param int $cmid Exact course module id to look up, or 0 to search.
     * @param int $excludecmid Course module id to omit from results, or 0.
     * @return array ['activities' => [...], 'more' => bool]
     */
    public static function execute(string $query = '', int $cmid = 0, int $excludecmid = 0): array {
        global $DB, $USER;

        [
            'query' => $query,
            'cmid' => $cmid,
            'excludecmid' => $excludecmid,
        ] = self::validate_parameters(self::execute_parameters(), [
            'query' => $query,
            'cmid' => $cmid,
            'excludecmid' => $excludecmid,
        ]);

        self::validate_context(\context_system::instance());

        // Work out which courses this user may see results from. Null means
        // no restriction (site-wide search).
        $courseids = null;
        if (!has_capability('availability/otheractivity:searchall', \context_system::instance())) {
            $courses = get_user_capability_course('moodle/course:manageactivities', $USER->id);
            $courseids = $courses ? array_map(static function ($course) {
                return (int) $course->id;
            }, $courses) : [];
            if (!$courseids) {
                return ['activities' => [], 'more' => false];
            }
        }

        if ($cmid) {
            return ['activities' => self::lookup($cmid, $courseids), 'more' => false];
        }

        if (\core_text::strlen($query) < self::MIN_QUERY_LENGTH) {
            return ['activities' => [], 'more' => false];
        }

        // Activity names live in each module's own table, so the search is a
        // union over the installed module types. Modules without a name
        // column cannot be offered.
        $branches = [];
        $params = [];
        $i = 0;
        foreach ($DB->get_records('modules') as $module) {
            $columns = $DB->get_columns($module->name);
            if (!isset($columns['name'])) {
                continue;
            }
            $branches[] = "SELECT cm.id AS cmid, cm.course AS courseid, m.name AS name
                             FROM {course_modules} cm
                             JOIN {" . $module->name . "} m ON m.id = cm.instance
                            WHERE cm.module = :module{$i}
                                  AND cm.completion > 0
                                  AND cm.deletioninprogress = 0";
            $params['module' . $i] = $module->id;
            $i++;
        }
        if (!$branches) {
            return ['activities' => [], 'more' => false];
        }

        $where = ['c.enablecompletion = 1'];
        $like = '%' . $DB->sql_like_escape($query) . '%';
        $where[] = '(' . $DB->sql_like('x.name', ':q1', false) . ' OR '
            . $DB->sql_like('c.shortname', ':q2', false) . ' OR '
            . $DB->sql_like('c.fullname', ':q3', false) . ')';
        $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];

        if ($courseids !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
            $where[] = "c.id $insql";
            $params += $inparams;
        }
        if ($excludecmid) {
            $where[] = 'x.cmid <> :excludecmid';
            $params['excludecmid'] = $excludecmid;
        }

        $sql = "SELECT x.cmid, x.name, c.id AS courseid, c.fullname
                  FROM (" . implode(' UNION ALL ', $branches) . ") x
                  JOIN {course} c ON c.id = x.courseid
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY c.fullname, x.name, x.cmid";

        $rows = $DB->get_records_sql($sql, $params, 0, self::MAX_RESULTS + 1);
        $more = count($rows) > self::MAX_RESULTS;
        $rows = array_slice($rows, 0, self::MAX_RESULTS);

        $activities = [];
        foreach ($rows as $row) {
            $activities[] = self::format_result(
                (int) $row->cmid,
                $row->name,
                (int) $row->courseid,
                $row->fullname
            );
        }

        return ['activities' => $activities, 'more' => $more];
    }

    /**
     * Resolve a single course module id to a result entry.
     *
     * Used to label a previously saved selection when the dialog reopens, so
     * it deliberately skips the completion filters (tracking may have been
     * switched off since the condition was saved) but keeps the course
     * scoping: cm ids cannot be probed for names the caller could not reach
     * through search.
     *
     * @param int $cmid The course module id.
     * @param array|null $courseids Courses the caller may see, or null for all.
     * @return array Zero or one result entries.
     */
    protected static function lookup(int $cmid, ?array $courseids): array {
        try {
            [$course, $cm] = get_course_and_cm_from_cmid($cmid);
        } catch (\moodle_exception $e) {
            return [];
        }
        if ($courseids !== null && !in_array((int) $course->id, $courseids, true)) {
            return [];
        }
        return [self::format_result((int) $cm->id, $cm->name, (int) $course->id, $course->fullname)];
    }

    /**
     * Build one result entry with its display label.
     *
     * @param int $cmid The course module id.
     * @param string $name The raw activity name.
     * @param int $courseid The course id.
     * @param string $coursefullname The raw course full name.
     * @return array
     */
    protected static function format_result(int $cmid, string $name, int $courseid, string $coursefullname): array {
        $label = get_string('activitylabel', 'availability_otheractivity', (object) [
            'name' => format_string($name, true, ['context' => \context_module::instance($cmid)]),
            'coursename' => format_string($coursefullname, true, ['context' => \context_course::instance($courseid)]),
            'courseid' => $courseid,
            'cmid' => $cmid,
        ]);
        return ['cmid' => $cmid, 'label' => $label];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'activities' => new external_multiple_structure(
                new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module id'),
                    'label' => new external_value(PARAM_RAW, 'Display label including course id and cm id'),
                ])
            ),
            'more' => new external_value(PARAM_BOOL, 'Whether results were truncated at the limit'),
        ]);
    }
}

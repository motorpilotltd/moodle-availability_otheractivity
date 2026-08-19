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

namespace availability_otheractivity;

/**
 * Editing form support for availability_otheractivity.
 *
 * The activity picker is populated by an AJAX search (the site-wide list can
 * be vast), so the only init parameter is the course module being edited,
 * which the search then excludes from its results.
 *
 * @package    availability_otheractivity
 * @copyright  2026 Simon Lewis
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class frontend extends \core_availability\frontend {
    /**
     * Strings required by the JavaScript.
     *
     * @return array
     */
    protected function get_javascript_strings() {
        return [
            'cmidlabel',
            'error_selectcm',
            'label_activity',
            'label_condition',
            'label_search',
            'noresults',
            'option_complete',
            'option_fail',
            'option_incomplete',
            'option_pass',
            'searchfailed',
            'searchhint',
            'searching',
            'toomanyresults',
        ];
    }

    /**
     * Parameters passed to the JavaScript initialiser.
     *
     * @param \stdClass $course The course.
     * @param \cm_info|null $cm The course module being edited.
     * @param \section_info|null $section The section being edited.
     * @return array
     */
    protected function get_javascript_init_params(
        $course,
        ?\cm_info $cm = null,
        ?\section_info $section = null
    ) {
        return [$cm ? (int) $cm->id : 0];
    }

    /**
     * Only offer the condition when there is something to point it at.
     *
     * @param \stdClass $course The course.
     * @param \cm_info|null $cm The course module being edited.
     * @param \section_info|null $section The section being edited.
     * @return bool
     */
    protected function allow_add($course, ?\cm_info $cm = null, ?\section_info $section = null) {
        global $CFG, $DB;

        if (empty($CFG->enablecompletion)) {
            return false;
        }

        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {course} c ON c.id = cm.course
                 WHERE cm.completion > 0
                       AND cm.deletioninprogress = 0
                       AND c.enablecompletion = 1";
        return $DB->record_exists_sql($sql);
    }
}

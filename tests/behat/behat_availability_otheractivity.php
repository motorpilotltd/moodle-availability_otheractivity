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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

/**
 * Behat steps for availability_otheractivity.
 *
 * @package    availability_otheractivity
 * @copyright  2026 Simon Lewis
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_availability_otheractivity extends behat_base {
    /**
     * Restrict one activity by completion of another, both given by idnumber.
     *
     * Writes the availability tree directly, so the enforcement side can be
     * tested without driving the JavaScript editing dialog.
     *
     * @Given the :restricted activity is restricted by completion of the :target activity
     * @param string $restricted The idnumber of the activity to restrict.
     * @param string $target The idnumber of the activity that must be completed.
     */
    public function activity_is_restricted_by_completion_of(string $restricted, string $target): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $restrictedcm = $DB->get_record('course_modules', ['idnumber' => $restricted], '*', MUST_EXIST);
        $targetcm = $DB->get_record('course_modules', ['idnumber' => $target], '*', MUST_EXIST);

        $availability = json_encode(\core_availability\tree::get_root_json([
            \availability_otheractivity\condition::get_json((int) $targetcm->id, COMPLETION_COMPLETE),
        ]));
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $restrictedcm->id]);
        rebuild_course_cache($restrictedcm->course, true);
    }

    /**
     * Select the first search result in the other activity restriction form.
     *
     * The result labels contain generated ids, so a plain "I set the field"
     * step cannot name them in advance.
     *
     * @When I select the first search result in the other activity restriction
     */
    public function i_select_the_first_search_result(): void {
        $this->getSession()->executeScript(
            "(function() {
                var select = document.querySelector('.availability-otheractivity select[name=cm]');
                var options = select.querySelectorAll('option');
                for (var i = 0; i < options.length; i++) {
                    if (options[i].value !== '0') {
                        select.value = options[i].value;
                        break;
                    }
                }
                select.dispatchEvent(new Event('change', {bubbles: true}));
            })();"
        );
    }
}

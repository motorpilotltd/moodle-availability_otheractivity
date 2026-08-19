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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Tests for the site-wide activity search external function.
 *
 * @package    availability_otheractivity
 * @copyright  2026 Simon Lewis
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      availability_otheractivity
 * @covers     \availability_otheractivity\external\search_activities
 */
final class search_activities_test extends \externallib_advanced_testcase {
    /**
     * Run the external function and clean the return value.
     *
     * @param string $query Search text.
     * @param int $cmid Exact lookup cm id.
     * @param int $excludecmid Excluded cm id.
     * @return array
     */
    protected function search(string $query = '', int $cmid = 0, int $excludecmid = 0): array {
        $result = search_activities::execute($query, $cmid, $excludecmid);
        return external_api::clean_returnvalue(search_activities::execute_returns(), $result);
    }

    /**
     * Create two completion-enabled courses with one tracked activity each.
     *
     * @return array [course1, course2, cm record 1, cm record 2]
     */
    protected function create_environment(): array {
        set_config('enablecompletion', 1);
        $generator = self::getDataGenerator();

        $course1 = $generator->create_course([
            'enablecompletion' => 1,
            'fullname' => 'Alpha course',
            'shortname' => 'ALPHA',
        ]);
        $course2 = $generator->create_course([
            'enablecompletion' => 1,
            'fullname' => 'Beta course',
            'shortname' => 'BETA',
        ]);

        $module1 = $generator->create_module('page', [
            'course' => $course1->id,
            'name' => 'Tracked page one',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $module2 = $generator->create_module('page', [
            'course' => $course2->id,
            'name' => 'Tracked page two',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        return [$course1, $course2, $module1, $module2];
    }

    public function test_search_requires_a_minimum_query_length(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->create_environment();

        $result = $this->search('T');
        $this->assertSame([], $result['activities']);
        $this->assertFalse($result['more']);
    }

    public function test_search_matches_activity_and_course_names(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course1, , $module1, $module2] = $this->create_environment();

        // By activity name, across both courses.
        $result = $this->search('Tracked page');
        $this->assertEqualsCanonicalizing(
            [$module1->cmid, $module2->cmid],
            array_column($result['activities'], 'cmid')
        );

        // By course shortname.
        $result = $this->search('ALPHA');
        $this->assertEquals([$module1->cmid], array_column($result['activities'], 'cmid'));

        // The label carries the course id and cm id for identification.
        $label = $result['activities'][0]['label'];
        $this->assertStringContainsString('Tracked page one', $label);
        $this->assertStringContainsString('Alpha course', $label);
        $this->assertStringContainsString('course id ' . $course1->id, $label);
        $this->assertStringContainsString('cm id ' . $module1->cmid, $label);
    }

    public function test_search_only_returns_completion_tracked_activities(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course1] = $this->create_environment();
        $generator = self::getDataGenerator();

        // No completion tracking.
        $generator->create_module('page', [
            'course' => $course1->id,
            'name' => 'Untracked page',
            'completion' => COMPLETION_TRACKING_NONE,
        ]);

        // Tracked, but the course has completion disabled.
        $offcourse = $generator->create_course(['enablecompletion' => 0]);
        $generator->create_module('page', [
            'course' => $offcourse->id,
            'name' => 'Untrackable page',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        $result = $this->search('Untrack');
        $this->assertSame([], $result['activities']);
    }

    public function test_search_excludes_modules_being_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, , $module1] = $this->create_environment();

        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $module1->cmid]);

        $result = $this->search('Tracked page one');
        $this->assertSame([], $result['activities']);
    }

    public function test_search_honours_the_exclude_parameter(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, , $module1, $module2] = $this->create_environment();

        $result = $this->search('Tracked page', 0, $module1->cmid);
        $this->assertEquals([$module2->cmid], array_column($result['activities'], 'cmid'));
    }

    public function test_search_scope_without_the_searchall_capability(): void {
        $this->resetAfterTest();
        [$course1, , $module1] = $this->create_environment();
        $generator = self::getDataGenerator();

        // An editing teacher in course 1 only sees course 1.
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course1->id, 'editingteacher');
        $this->setUser($teacher);

        $result = $this->search('Tracked page');
        $this->assertEquals([$module1->cmid], array_column($result['activities'], 'cmid'));

        // A student sees nothing at all.
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course1->id, 'student');
        $this->setUser($student);

        $result = $this->search('Tracked page');
        $this->assertSame([], $result['activities']);
    }

    public function test_search_scope_with_the_searchall_capability(): void {
        global $DB;
        $this->resetAfterTest();
        [, , $module1, $module2] = $this->create_environment();
        $generator = self::getDataGenerator();

        // A site manager searches everything without any enrolment.
        $manager = $generator->create_user();
        $managerrole = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);
        role_assign($managerrole->id, $manager->id, \context_system::instance()->id);
        $this->setUser($manager);

        $result = $this->search('Tracked page');
        $this->assertEqualsCanonicalizing(
            [$module1->cmid, $module2->cmid],
            array_column($result['activities'], 'cmid')
        );
    }

    public function test_exact_lookup(): void {
        $this->resetAfterTest();
        [$course1, , $module1] = $this->create_environment();
        $generator = self::getDataGenerator();
        $this->setAdminUser();

        $result = $this->search('', $module1->cmid);
        $this->assertCount(1, $result['activities']);
        $this->assertEquals($module1->cmid, $result['activities'][0]['cmid']);
        $this->assertStringContainsString('Tracked page one', $result['activities'][0]['label']);

        // A missing cm id resolves to nothing.
        $result = $this->search('', 999999);
        $this->assertSame([], $result['activities']);

        // The lookup is scoped like the search: a user without editing rights
        // in the target course cannot probe cm ids for names.
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course1->id, 'student');
        $this->setUser($student);

        $result = $this->search('', $module1->cmid);
        $this->assertSame([], $result['activities']);
    }

    public function test_search_truncates_and_flags_more(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablecompletion', 1);
        $generator = self::getDataGenerator();

        $course = $generator->create_course(['enablecompletion' => 1]);
        $total = search_activities::MAX_RESULTS + 1;
        for ($i = 1; $i <= $total; $i++) {
            $generator->create_module('page', [
                'course' => $course->id,
                'name' => 'Bulk page ' . $i,
                'completion' => COMPLETION_TRACKING_MANUAL,
            ]);
        }

        $result = $this->search('Bulk page');
        $this->assertCount(search_activities::MAX_RESULTS, $result['activities']);
        $this->assertTrue($result['more']);
    }
}

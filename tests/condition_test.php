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

use core_availability\mock_info;
use core_availability\tree;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/availability/tests/fixtures/mock_info.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Tests for the other activity availability condition.
 *
 * @package    availability_otheractivity
 * @copyright  2026 Simon Lewis
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      availability_otheractivity
 * @covers     \availability_otheractivity\condition
 */
final class condition_test extends \advanced_testcase {
    /**
     * Create two completion-enabled courses, a user enrolled in both, and a
     * manually-completable target activity in the first course.
     *
     * @return array [source course, other course, user, target cm_info]
     */
    protected function create_environment(): array {
        set_config('enablecompletion', 1);
        set_config('enableavailability', 1);

        $generator = self::getDataGenerator();
        $coursea = $generator->create_course(['enablecompletion' => 1, 'fullname' => 'Source course']);
        $courseb = $generator->create_course(['enablecompletion' => 1, 'fullname' => 'Other course']);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $coursea->id, 'student');
        $generator->enrol_user($user->id, $courseb->id, 'student');

        $target = $generator->create_module('page', [
            'course' => $coursea->id,
            'name' => 'Target page',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $targetcm = get_fast_modinfo($coursea)->get_cm($target->cmid);

        return [$coursea, $courseb, $user, $targetcm];
    }

    /**
     * Write a completion state for a user directly, bypassing the manual
     * completion API so pass/fail states can be simulated without grading.
     *
     * @param \cm_info $cm The course module.
     * @param int $userid The user id.
     * @param int $state The COMPLETION_xx state.
     */
    protected function set_completion_state(\cm_info $cm, int $userid, int $state): void {
        global $DB;

        $existing = $DB->get_record('course_modules_completion', [
            'coursemoduleid' => $cm->id,
            'userid' => $userid,
        ]);
        if ($existing) {
            $existing->completionstate = $state;
            $existing->timemodified = time();
            $DB->update_record('course_modules_completion', $existing);
        } else {
            $DB->insert_record('course_modules_completion', (object) [
                'coursemoduleid' => $cm->id,
                'userid' => $userid,
                'completionstate' => $state,
                'viewed' => 0,
                'overrideby' => null,
                'timemodified' => time(),
            ]);
        }
        \cache::make('core', 'completion')->purge();
    }

    public function test_constructor_validates_structure(): void {
        $condition = new condition((object) ['cm' => 7, 'e' => COMPLETION_COMPLETE]);
        $this->assertEquals(
            (object) ['type' => 'otheractivity', 'cm' => 7, 'e' => COMPLETION_COMPLETE],
            $condition->save()
        );

        $this->expectException(\coding_exception::class);
        new condition((object) ['e' => COMPLETION_COMPLETE]);
    }

    public function test_constructor_rejects_non_numeric_cm(): void {
        $this->expectException(\coding_exception::class);
        new condition((object) ['cm' => 'seven', 'e' => COMPLETION_COMPLETE]);
    }

    public function test_constructor_rejects_missing_expected_state(): void {
        $this->expectException(\coding_exception::class);
        new condition((object) ['cm' => 7]);
    }

    public function test_constructor_rejects_invalid_expected_state(): void {
        $this->expectException(\coding_exception::class);
        new condition((object) ['cm' => 7, 'e' => 99]);
    }

    public function test_get_json(): void {
        $this->assertEquals(
            (object) ['type' => 'otheractivity', 'cm' => 4, 'e' => COMPLETION_COMPLETE_PASS],
            condition::get_json(4, COMPLETION_COMPLETE_PASS)
        );
        $this->assertEquals(COMPLETION_COMPLETE, condition::get_json(4)->e);
    }

    public function test_is_available_complete_and_incomplete(): void {
        $this->resetAfterTest();
        [$coursea, $courseb, $user, $targetcm] = $this->create_environment();
        $info = new mock_info($courseb, $user->id);

        $complete = new condition(condition::get_json($targetcm->id, COMPLETION_COMPLETE));
        $incomplete = new condition(condition::get_json($targetcm->id, COMPLETION_INCOMPLETE));

        // Nothing completed yet.
        $this->assertFalse($complete->is_available(false, $info, false, $user->id));
        $this->assertTrue($complete->is_available(true, $info, false, $user->id));
        $this->assertTrue($incomplete->is_available(false, $info, false, $user->id));

        $completion = new \completion_info($coursea);
        $completion->update_state($targetcm, COMPLETION_COMPLETE, $user->id);

        $this->assertTrue($complete->is_available(false, $info, false, $user->id));
        $this->assertFalse($complete->is_available(true, $info, false, $user->id));
        $this->assertFalse($incomplete->is_available(false, $info, false, $user->id));
        $this->assertTrue($incomplete->is_available(true, $info, false, $user->id));
    }

    public function test_is_available_pass_and_fail(): void {
        $this->resetAfterTest();
        [, $courseb, $user, $targetcm] = $this->create_environment();
        $info = new mock_info($courseb, $user->id);

        $complete = new condition(condition::get_json($targetcm->id, COMPLETION_COMPLETE));
        $pass = new condition(condition::get_json($targetcm->id, COMPLETION_COMPLETE_PASS));
        $fail = new condition(condition::get_json($targetcm->id, COMPLETION_COMPLETE_FAIL));

        $this->set_completion_state($targetcm, $user->id, COMPLETION_COMPLETE_PASS);
        $this->assertTrue($complete->is_available(false, $info, false, $user->id));
        $this->assertTrue($pass->is_available(false, $info, false, $user->id));
        $this->assertFalse($fail->is_available(false, $info, false, $user->id));

        $this->set_completion_state($targetcm, $user->id, COMPLETION_COMPLETE_FAIL);
        $this->assertTrue($complete->is_available(false, $info, false, $user->id));
        $this->assertFalse($pass->is_available(false, $info, false, $user->id));
        $this->assertTrue($fail->is_available(false, $info, false, $user->id));
    }

    public function test_is_available_fails_closed_when_activity_missing(): void {
        $this->resetAfterTest();
        [, $courseb, $user] = $this->create_environment();
        $info = new mock_info($courseb, $user->id);

        $condition = new condition(condition::get_json(999999));
        $this->assertFalse($condition->is_available(false, $info, false, $user->id));
        $this->assertTrue($condition->is_available(true, $info, false, $user->id));
    }

    public function test_is_available_fails_closed_without_completion_tracking(): void {
        $this->resetAfterTest();
        [$coursea, $courseb, $user] = $this->create_environment();
        $info = new mock_info($courseb, $user->id);
        $generator = self::getDataGenerator();

        // Activity without completion tracking, in a completion-enabled course.
        $notracking = $generator->create_module('page', [
            'course' => $coursea->id,
            'completion' => COMPLETION_TRACKING_NONE,
        ]);
        $condition = new condition(condition::get_json($notracking->cmid, COMPLETION_INCOMPLETE));
        // Even "must not be complete" fails closed when tracking is off.
        $this->assertFalse($condition->is_available(false, $info, false, $user->id));

        // Activity with completion tracking, in a course with completion disabled.
        $offcourse = $generator->create_course(['enablecompletion' => 0]);
        $offtarget = $generator->create_module('page', [
            'course' => $offcourse->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $condition = new condition(condition::get_json($offtarget->cmid, COMPLETION_COMPLETE));
        $this->assertFalse($condition->is_available(false, $info, false, $user->id));
    }

    public function test_get_description(): void {
        $this->resetAfterTest();
        [, $courseb, $user, $targetcm] = $this->create_environment();
        $info = new mock_info($courseb, $user->id);

        $complete = new condition(condition::get_json($targetcm->id, COMPLETION_COMPLETE));
        $description = $complete->get_description(true, false, $info);
        $this->assertStringContainsString('Target page', $description);
        $this->assertStringContainsString('Source course', $description);
        $this->assertStringContainsString('marked complete', $description);

        // NOT of "complete" uses the direct opposite.
        $notdescription = $complete->get_description(true, true, $info);
        $this->assertStringContainsString('incomplete', $notdescription);

        $pass = new condition(condition::get_json($targetcm->id, COMPLETION_COMPLETE_PASS));
        $this->assertStringContainsString(
            'complete with pass grade',
            $pass->get_description(true, false, $info)
        );
        $this->assertStringContainsString(
            'not complete with pass grade',
            $pass->get_description(true, true, $info)
        );
    }

    public function test_get_description_with_missing_target(): void {
        $this->resetAfterTest();
        [, $courseb, $user] = $this->create_environment();
        $info = new mock_info($courseb, $user->id);

        $condition = new condition(condition::get_json(999999));
        $this->assertStringContainsString(
            get_string('missing', 'availability_otheractivity'),
            $condition->get_description(true, false, $info)
        );
    }

    public function test_update_dependency_id(): void {
        $condition = new condition(condition::get_json(10));

        $this->assertFalse($condition->update_dependency_id('course_modules', 99, 100));
        $this->assertTrue($condition->update_dependency_id('course_modules', 10, 11));
        $this->assertFalse($condition->update_dependency_id('groups', 11, 12));

        $this->assertEquals(11, $condition->save()->cm);
    }

    public function test_duplication_preserves_the_condition(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [, $courseb, , $targetcm] = $this->create_environment();

        $page = self::getDataGenerator()->create_module('page', [
            'course' => $courseb->id,
            'availability' => json_encode(tree::get_root_json([
                condition::get_json($targetcm->id, COMPLETION_COMPLETE),
            ])),
        ]);
        $pagecm = get_fast_modinfo($courseb)->get_cm($page->cmid);

        // Duplicating the restricted page must keep the cross-course target
        // id untouched, even though the target is not part of the backup.
        $newcm = duplicate_module($courseb, $pagecm);

        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $newcm->id]));
        $this->assertEquals($targetcm->id, $availability->c[0]->cm);
        $this->assertEquals(COMPLETION_COMPLETE, $availability->c[0]->e);
    }

    public function test_course_restore_remaps_a_target_in_the_same_backup(): void {
        global $CFG, $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Keep the unpacked backup directory, as the restore controller
        // consumes it directly rather than the packaged .mbz file.
        $CFG->keeptempdirectoriesonbackup = true;

        [$coursea, , , $targetcm] = $this->create_environment();

        // Target and restricted activity in the same course, so the backup
        // contains both and the reference must follow the restored copy.
        self::getDataGenerator()->create_module('page', [
            'course' => $coursea->id,
            'name' => 'Restricted page',
            'availability' => json_encode(tree::get_root_json([
                condition::get_json($targetcm->id, COMPLETION_COMPLETE),
            ])),
        ]);

        $newcourseid = $this->backup_and_restore($coursea->id, 'RSTOA1');

        $newmodinfo = get_fast_modinfo($newcourseid);
        $newtargetcm = null;
        $newpagecm = null;
        foreach ($newmodinfo->cms as $newcm) {
            if ($newcm->modname !== 'page') {
                continue;
            }
            if ($newcm->name === 'Target page') {
                $newtargetcm = $newcm;
            } else if ($newcm->name === 'Restricted page') {
                $newpagecm = $newcm;
            }
        }
        $this->assertNotNull($newtargetcm);
        $this->assertNotNull($newpagecm);

        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $newpagecm->id]));
        $this->assertEquals($newtargetcm->id, $availability->c[0]->cm);
        $this->assertNotEquals($targetcm->id, $availability->c[0]->cm);
    }

    public function test_course_restore_keeps_a_cross_course_target_on_the_same_site(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->keeptempdirectoriesonbackup = true;

        [, $courseb, , $targetcm] = $this->create_environment();

        $page = self::getDataGenerator()->create_module('page', [
            'course' => $courseb->id,
            'name' => 'Restricted page',
            'availability' => json_encode(tree::get_root_json([
                condition::get_json($targetcm->id, COMPLETION_COMPLETE),
            ])),
        ]);
        $this->assertNotEmpty($page);

        $newcourseid = $this->backup_and_restore($courseb->id, 'RSTOA2');

        $newpagecm = null;
        foreach (get_fast_modinfo($newcourseid)->cms as $newcm) {
            if ($newcm->modname === 'page') {
                $newpagecm = $newcm;
            }
        }
        $this->assertNotNull($newpagecm);

        // The target lives in another course, outside the backup, but this is
        // the same site: the reference must survive unchanged.
        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $newpagecm->id]));
        $this->assertEquals($targetcm->id, $availability->c[0]->cm);
    }

    public function test_course_restore_clears_a_deleted_target(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->keeptempdirectoriesonbackup = true;

        [, $courseb, , $targetcm] = $this->create_environment();

        self::getDataGenerator()->create_module('page', [
            'course' => $courseb->id,
            'name' => 'Restricted page',
            'availability' => json_encode(tree::get_root_json([
                condition::get_json($targetcm->id, COMPLETION_COMPLETE),
            ])),
        ]);

        course_delete_module($targetcm->id);

        $newcourseid = $this->backup_and_restore($courseb->id, 'RSTOA3');

        $newpagecm = null;
        foreach (get_fast_modinfo($newcourseid)->cms as $newcm) {
            if ($newcm->modname === 'page') {
                $newpagecm = $newcm;
            }
        }
        $this->assertNotNull($newpagecm);

        // The reference cannot be resolved anywhere, so it must be cleared
        // (the condition then fails closed) rather than left dangling.
        $availability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $newpagecm->id]));
        $this->assertEquals(0, $availability->c[0]->cm);
    }

    /**
     * Back up a course and restore it into a new course.
     *
     * @param int $courseid The course to back up.
     * @param string $shortname Shortname for the restored course.
     * @return int The new course id.
     */
    protected function backup_and_restore(int $courseid, string $shortname): int {
        global $DB, $USER;

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $courseid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $category = $DB->get_field('course', 'category', ['id' => $courseid]);
        $newcourseid = \restore_dbops::create_new_course('Restored', $shortname, $category);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }
}

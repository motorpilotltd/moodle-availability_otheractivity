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

use core_availability\info;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');

/**
 * Availability condition based on completion of an activity anywhere on the site.
 *
 * Core's completion condition can only reference activities in the same
 * course. This condition stores a site-wide course module id, so content can
 * be gated on what a user has done in another course entirely. The expected
 * completion states mirror core's: complete, incomplete, complete with pass
 * grade and complete with fail grade.
 *
 * The condition fails closed: if the referenced activity is missing, is being
 * deleted, or does not have completion tracking enabled, nobody meets it
 * (everybody, where the restriction is negated).
 *
 * @package    availability_otheractivity
 * @copyright  2026 Simon Lewis
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class condition extends \core_availability\condition {
    /** @var int Site-wide course module id of the target activity. */
    protected $cmid;

    /** @var int Expected completion state (COMPLETION_xx constant). */
    protected $expectedcompletion;

    /** @var array Cached same-site flags, keyed by restore id. */
    protected static $samesitecache = [];

    /**
     * Build the condition from its saved structure.
     *
     * @param \stdClass $structure The decoded JSON structure.
     * @throws \coding_exception If the structure is malformed.
     */
    public function __construct($structure) {
        if (!isset($structure->cm) || !is_number($structure->cm)) {
            throw new \coding_exception('Missing or invalid ->cm for otheractivity condition');
        }
        $this->cmid = (int) $structure->cm;

        $validstates = [
            COMPLETION_COMPLETE,
            COMPLETION_INCOMPLETE,
            COMPLETION_COMPLETE_PASS,
            COMPLETION_COMPLETE_FAIL,
        ];
        if (!isset($structure->e) || !in_array($structure->e, $validstates)) {
            throw new \coding_exception('Missing or invalid ->e for otheractivity condition');
        }
        $this->expectedcompletion = (int) $structure->e;
    }

    /**
     * Return the structure to save.
     *
     * @return \stdClass
     */
    public function save() {
        return (object) [
            'type' => 'otheractivity',
            'cm' => $this->cmid,
            'e' => $this->expectedcompletion,
        ];
    }

    /**
     * Build a structure for use in unit tests and generators.
     *
     * @param int $cmid The target course module id (site-wide).
     * @param int $expectedcompletion Expected completion state (COMPLETION_xx).
     * @return \stdClass
     */
    public static function get_json(int $cmid, int $expectedcompletion = COMPLETION_COMPLETE) {
        return (object) ['type' => 'otheractivity', 'cm' => $cmid, 'e' => $expectedcompletion];
    }

    /**
     * Decide whether the condition is met.
     *
     * @param bool $not Whether the condition is negated.
     * @param info $info Availability info for the item being checked.
     * @param bool $grabthelot Whether to prefetch for many users.
     * @param int $userid The user id.
     * @return bool
     */
    public function is_available($not, info $info, $grabthelot, $userid) {
        $allow = false;

        $target = $this->get_target();
        if ($target) {
            [$course, $cm] = $target;
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm) != COMPLETION_TRACKING_NONE) {
                $data = $completion->get_data($cm, $grabthelot, $userid);
                $state = (int) $data->completionstate;
                $completedstates = [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS, COMPLETION_COMPLETE_FAIL];

                switch ($this->expectedcompletion) {
                    case COMPLETION_COMPLETE:
                        // Complete also covers complete-pass and complete-fail,
                        // matching core's completion condition.
                        $allow = in_array($state, $completedstates, true);
                        break;
                    case COMPLETION_INCOMPLETE:
                        $allow = !in_array($state, $completedstates, true);
                        break;
                    case COMPLETION_COMPLETE_PASS:
                        $allow = $state === COMPLETION_COMPLETE_PASS;
                        break;
                    case COMPLETION_COMPLETE_FAIL:
                        $allow = $state === COMPLETION_COMPLETE_FAIL;
                        break;
                }
            }
        }

        if ($not) {
            $allow = !$allow;
        }

        return $allow;
    }

    /**
     * Describe the condition for display.
     *
     * The target activity may be in another course, so core's CMNAME
     * placeholder (which only resolves within the current course) cannot be
     * used; the names are resolved and formatted here instead.
     *
     * @param bool $full Whether to show the full description.
     * @param bool $not Whether the condition is negated.
     * @param info $info Availability info.
     * @return string
     */
    public function get_description($full, $not, info $info) {
        $target = $this->get_target();
        if (!$target) {
            $label = get_string('missing', 'availability_otheractivity');
        } else {
            [$course, $cm] = $target;
            $label = get_string('activitycourse', 'availability_otheractivity', (object) [
                'activity' => $cm->get_formatted_name(),
                'course' => format_string(
                    $course->fullname,
                    true,
                    ['context' => \context_course::instance($course->id)]
                ),
            ]);
        }

        if ($not) {
            // Use the direct opposite where one exists, like core does.
            switch ($this->expectedcompletion) {
                case COMPLETION_INCOMPLETE:
                    $str = 'requires_complete';
                    break;
                case COMPLETION_COMPLETE:
                    $str = 'requires_incomplete';
                    break;
                default:
                    $str = 'requires_not_' . self::get_lang_string_keyword($this->expectedcompletion);
                    break;
            }
        } else {
            $str = 'requires_' . self::get_lang_string_keyword($this->expectedcompletion);
        }

        return get_string($str, 'availability_otheractivity', $label);
    }

    /**
     * Return the lang string keyword for a completion state.
     *
     * @param int $completionstate COMPLETION_xx constant.
     * @return string
     * @throws \coding_exception On an unexpected state.
     */
    protected static function get_lang_string_keyword(int $completionstate): string {
        switch ($completionstate) {
            case COMPLETION_INCOMPLETE:
                return 'incomplete';
            case COMPLETION_COMPLETE:
                return 'complete';
            case COMPLETION_COMPLETE_PASS:
                return 'complete_pass';
            case COMPLETION_COMPLETE_FAIL:
                return 'complete_fail';
            default:
                throw new \coding_exception('Unexpected completion state: ' . $completionstate);
        }
    }

    /**
     * Debug string used by unit tests.
     *
     * @return string
     */
    protected function get_debug_string() {
        return 'cm' . $this->cmid . ' e' . $this->expectedcompletion;
    }

    /**
     * Resolve the target course and course module, or null if unusable.
     *
     * @return array|null [course, cm_info] or null.
     */
    protected function get_target(): ?array {
        if (!$this->cmid) {
            return null;
        }
        try {
            [$course, $cm] = get_course_and_cm_from_cmid($this->cmid);
        } catch (\moodle_exception $e) {
            return null;
        }
        if (!empty($cm->deletioninprogress)) {
            return null;
        }
        return [$course, $cm];
    }

    /**
     * Update the stored course module id after a restore.
     *
     * @param string $table The table being remapped.
     * @param int $oldid The old id.
     * @param int $newid The new id.
     * @return bool Whether anything changed.
     */
    public function update_dependency_id($table, $oldid, $newid) {
        if ($table === 'course_modules' && (int) $this->cmid === (int) $oldid) {
            $this->cmid = $newid;
            return true;
        }
        return false;
    }

    /**
     * Ask to be included in the after-restore pass so ids can be remapped.
     *
     * @param int $restoreid The restore id.
     * @param int $courseid The course id.
     * @param \base_logger $logger The logger.
     * @param string $name The item name.
     * @param \base_task $task The restore task.
     * @return bool
     */
    public function include_after_restore(
        $restoreid,
        $courseid,
        \base_logger $logger,
        $name,
        \base_task $task
    ) {
        return true;
    }

    /**
     * Remap the target id after a restore.
     *
     * If the target activity was part of the backup, point at its restored
     * copy. Otherwise, on a same-site restore (duplication, import, or a
     * course backup restored on the site it came from) the stored id still
     * refers to the same activity, so keep it where it still exists. A
     * genuinely unresolvable reference is cleared with a restore-log warning
     * rather than left pointing at an arbitrary module on a different site.
     *
     * @param string $restoreid The restore id.
     * @param int $courseid The course id.
     * @param \base_logger $logger The logger.
     * @param string $name The item name.
     * @return bool Whether the condition changed.
     */
    public function update_after_restore($restoreid, $courseid, \base_logger $logger, $name) {
        global $DB;

        $rec = \restore_dbops::get_backup_ids_record($restoreid, 'course_module', $this->cmid);
        if ($rec && $rec->newitemid) {
            $this->cmid = (int) $rec->newitemid;
            return true;
        }

        if (
            self::restore_is_samesite($restoreid)
            && $DB->record_exists('course_modules', ['id' => $this->cmid])
        ) {
            return false;
        }

        $logger->process(
            "Restored item ($name) has availability condition on an activity " .
                "that cannot be found on this site",
            \backup::LOG_WARNING
        );
        $this->cmid = 0;
        return true;
    }

    /**
     * Decide whether a restore is running on the site the backup came from.
     *
     * On a different site the stored course module id is meaningless: it may
     * happen to match an unrelated module, so it must never be kept.
     *
     * @param string $restoreid The restore id.
     * @return bool
     */
    protected static function restore_is_samesite($restoreid): bool {
        if (!array_key_exists($restoreid, self::$samesitecache)) {
            try {
                $controller = \restore_controller_dbops::load_controller($restoreid);
                self::$samesitecache[$restoreid] = $controller->is_samesite();
            } catch (\Throwable $e) {
                // If the controller cannot be loaded, be conservative.
                self::$samesitecache[$restoreid] = false;
            }
        }
        return self::$samesitecache[$restoreid];
    }
}

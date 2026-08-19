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

/**
 * Language strings for availability_otheractivity.
 *
 * @package    availability_otheractivity
 * @copyright  2026 Simon Lewis
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activitycourse'] = '{$a->activity} ({$a->course})';
$string['activitylabel'] = '{$a->name} [{$a->coursename}] (course id {$a->courseid}, cm id {$a->cmid})';
$string['cmidlabel'] = 'cm id {$a}';
$string['description'] = 'Require the user to have completed (or not completed) an activity anywhere on the site.';
$string['error_selectcm'] = 'You must select an activity.';
$string['label_activity'] = 'Activity';
$string['label_condition'] = 'Required completion status';
$string['label_search'] = 'Search activities';
$string['missing'] = '(Missing activity)';
$string['noresults'] = 'No matching activities found.';
$string['option_complete'] = 'must be marked complete';
$string['option_fail'] = 'must be complete with fail grade';
$string['option_incomplete'] = 'must not be marked complete';
$string['option_pass'] = 'must be complete with pass grade';
$string['otheractivity:searchall'] = 'Search all courses when selecting the target activity';
$string['pluginname'] = 'Restriction by activity in another course';
$string['privacy:metadata'] = 'The Restriction by activity in another course plugin does not store any personal data.';
$string['requires_complete'] = 'The activity <strong>{$a}</strong> is marked complete';
$string['requires_complete_fail'] = 'The activity <strong>{$a}</strong> is complete with fail grade';
$string['requires_complete_pass'] = 'The activity <strong>{$a}</strong> is complete with pass grade';
$string['requires_incomplete'] = 'The activity <strong>{$a}</strong> is incomplete';
$string['requires_not_complete_fail'] = 'The activity <strong>{$a}</strong> is not complete with fail grade';
$string['requires_not_complete_pass'] = 'The activity <strong>{$a}</strong> is not complete with pass grade';
$string['searchfailed'] = 'The search failed. Please try again.';
$string['searchhint'] = 'Type at least 2 characters to search';
$string['searching'] = 'Searching…';
$string['title'] = 'Other activity';
$string['toomanyresults'] = 'Only the first matches are shown. Refine your search to narrow the list.';

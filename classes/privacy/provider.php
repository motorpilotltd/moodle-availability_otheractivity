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

namespace availability_otheractivity\privacy;

/**
 * Privacy provider for availability_otheractivity.
 *
 * The condition only reads completion data owned by core; it stores nothing
 * about users itself.
 *
 * @package    availability_otheractivity
 * @copyright  2026 Simon Lewis
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\null_provider {
    /**
     * Explain why this plugin stores no personal data.
     *
     * @return string The language string identifier.
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}

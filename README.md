# Other activity availability condition for Moodle (availability_otheractivity)

An availability restriction that gates access to course activities and
sections based on completion of an activity **anywhere on the site**, not just
in the same course. Core's own "Activity completion" restriction only reaches
activities in the current course; this plugin removes that boundary, so a
course can require, for example, an induction quiz completed in a different
course.

## How it works

- A teacher adds an "Other activity" restriction, searches for the target
  activity by name (or by course name), and picks the required completion
  status: marked complete, not marked complete, complete with pass grade, or
  complete with fail grade - the same options as core's completion
  restriction.
- Because the site-wide list of activities can be vast, the picker is a
  live search. Each result is labelled with the activity name, its course,
  and the course id and course module id, so the right one can be identified
  with certainty. Only activities with completion tracking enabled (in
  courses with completion enabled) are offered.
- Search scope is controlled by the `availability/otheractivity:searchall`
  capability (allowed for managers by default): with it, every course on the
  site is searchable, including hidden ones; without it, results are limited
  to courses where the user can manage activities.
- The condition fails closed: if the referenced activity is missing, being
  deleted, or no longer has completion tracking enabled, nobody meets it
  (everybody, where the restriction is negated).

## Backup and restore

Duplicating a restricted activity, or restoring a backup on the same site,
keeps the restriction pointing at the existing target activity. If the target
was itself part of the backup, the reference follows the restored copy. On a
different site the stored id cannot be trusted, so an unresolvable reference
is cleared and a warning is written to the restore log; the condition then
fails closed rather than pointing at an arbitrary activity.

## Requirements

- Moodle 4.5 or later (tested against 4.5 to 5.2).
- Completion tracking enabled on the site and in the courses involved.

## Installation

1. Copy this directory to `availability/condition/otheractivity` in your
   Moodle root.
2. Visit Site administration, or run `php admin/cli/upgrade.php`.

## Status

Alpha. A PHPUnit suite (condition semantics, fail-closed behaviour, the
search web service and backup/restore round trips), Behat features and a
moodle-plugin-ci GitHub Actions workflow are included. Still, please test in
a staging environment before using this anywhere that matters.

## Licence

Copyright © 2026 Simon Lewis

GPL v3 or later.

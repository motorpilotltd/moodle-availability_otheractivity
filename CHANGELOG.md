# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0-alpha.1] - 2026-08-19

### Added

- Initial version of the plugin: an availability condition restricting by
  completion state (complete, incomplete, complete with pass or fail grade)
  of an activity anywhere on the site.
- AJAX search picker for the target activity, labelled with course id and
  course module id, limited to completion-tracked activities.
- `availability/otheractivity:searchall` capability (managers by default)
  controlling site-wide search; other users search only courses where they
  can manage activities.
- Fail-closed evaluation, same-site-aware backup and restore handling,
  PHPUnit and Behat suites, and a moodle-plugin-ci GitHub Actions workflow.

[1.0.0-alpha.1]: https://github.com/motorpilotltd/moodle-availability_otheractivity/releases/tag/v1.0.0-alpha.1

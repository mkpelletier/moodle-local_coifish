# Changelog

## [1.3.4] - 2026-05-20

### Changed
- **Longitudinal feedback-review metric now counts Unified Grader feedback views** alongside the native `mod_assign` events, via the shared `\gradereport_coifish\report::get_feedback_view_event_sql()` helper. Snapshots taken against courses that use UG for assignments / quizzes / forums / BBB no longer under-report student engagement with feedback.

### Fixed
- **Peer-review blockers**: added GPL v3 `LICENSE` file in the plugin root (was missing), and frankenstyle-prefixed the only global helper `generate_export_data()` → `local_coifish_generate_export_data()` in `export.php` to remove the namespace-collision risk flagged by the Moodle plugins-DB reviewer.

## [1.3.3] - 2026-05-19

### Fixed
- **False "persistent / across courses" recommendations on single-course students.** `social_isolation` and `feedback_neglect` risk factors fire on the per-course average, so they correctly flag a one-course student with very low forum/feedback activity — but the recommendation text claimed a cross-course pattern that didn't exist yet. `api::generate_recommendations()` now selects a `_single` wording variant ("In their recent course, …, watch whether the pattern repeats") when `coursescompleted < 2`. Same treatment for `intervention_positive` / `intervention_unresponsive` based on `totalinterventions < 2` so a single intervention isn't described in the plural. Badge labels `risk_factor_social_isolation` and `risk_factor_feedback_neglect` are also reworded to drop the "persistent / across courses" framing.
- **Snapshots for concluded courses no longer skew longitudinal data with post-course activity.** `metrics_helper::capture_student_metrics()` now accepts an `$endtime` parameter; the `build_profiles` task passes `$course->enddate` so engagement views, forum posts, feedback views, assignment-grade windows, and grade-checks are clamped at course end when the snapshot is written. `build_active_snapshots` is unchanged — it already excludes concluded courses.
- **Engagement metric in longitudinal snapshots now honours grade-category drop/keep rules** via the shared `\gradereport_coifish\report::get_expected_activity_count()` helper. Optional assignments/quizzes in "best N of M" or "drop lowest N" categories no longer inflate the engagement denominator.
- install.xml now declares the XMLDB schema namespace (resolves `core\db\plugin_checks_test::test_db_install_file` failure).

## [1.3.2] - 2026-05-13

### Added
- **Enrolment-status filter on Student Risk Overview** — new dropdown with three options: "Currently enrolled" (default), "All", and "No current enrolment". Reduces noise from students on a study break, and surfaces re-engagement opportunities. Sourced directly from `user_enrolments` so it works even before the active-snapshot task has run.

### Fixed
- **Empty Current Enrolments card for new-term students** — `capture_student_metrics` now returns a row with `grade = null` (instead of bailing out) when a student has no `grade_grades` entry yet or when the course has no `grade_items` row of type `course`. The active-snapshot task and the on-demand refresh both write the row in this case. Post-course `build_profiles` continues to skip students without a final grade.
- **Non-academic courses leaking into current-enrolment queries** — the existing `local_coifish/course_category` "Limit course matching to category" admin setting is now honoured by the active-snapshot task, the on-demand refresh, the Current Enrolments display, and the "Currently enrolled" filter on the Risk Overview. A new helper `filter_helper::get_category_scope_sql()` centralises the include logic.
- **Stale active-snapshot rows after withdrawal or scope change** — both the on-demand refresh and the daily task now delete `local_coifish_active_snapshot` rows for students who have withdrawn from a course, and for courses that have fallen out of scope (now hidden, ended, deleted, or excluded by category). The on-demand refresh notification reports how many stale entries were removed.

## [1.3.1] - 2026-05-13

### Security
- **Privacy provider** added — declares the four user-keyed tables (`profile`, `course_snapshot`, `active_snapshot`, `lecturer`) to Moodle's privacy subsystem with full export, contextlist, userlist and delete support. Required for GDPR / PoPIA compliance.
- **CSRF hardening on CSV export** — `export.php` now requires a valid sesskey when downloading the lecturer time report.
- **Regex validation on cohort programme patterns** — admin setting rejects patterns that fail to compile or contain catastrophic-backtracking nested quantifiers (e.g. `(a+)+`).

### Changed
- Risk overview SQL now uses `\core_user\fields::for_name()` so display names respect `fullnamedisplay` and alternate-name privacy controls.

### Removed
- Unused `\local_coifish\api::get_student_profiles()` (dead code with no callers).

## [1.3.0] - 2026-05-13

### Added
- **Current Enrolments table** on the student drill-down — shows in-progress courses (with term, start/end, grade so far, engagement, social, self-reg, feedback) for active enrolments that have not yet completed and so are not in Course History.
- **New scheduled task `build_active_snapshots`** (daily at 03:30) populates the new `local_coifish_active_snapshot` table for visible currently-running courses.
- **On-demand refresh button** on the student drill-down. Recomputes only this student's active-enrolment snapshots, throttled to once per hour per course to protect small servers.
- **Freshness caption** ("Last refreshed: X ago") above the Current Enrolments table.
- **Drill-down hyperlinks on course names** in both Current Enrolments and Course History — each links to `/grade/report/coifish/index.php?id=COURSEID&userid=USERID` and opens in a new tab.
- **`Term label source` admin setting** — choose whether the term column reads from the immediate parent course category (default), the course fullname, or a course customfield (shortname configurable).

### Changed
- Shared metric calculation factored into `local_coifish\metrics_helper` so the new active-snapshot task and the existing post-course snapshot task compute identically.

## [1.2.1] - 2026-04-09

### Changed
- **Social presence snapshot** — Course snapshots now use the same group-aware composite methodology as the grade report: forum breadth (60%) + volume (40%), respecting separate group modes.
- Configurable teaching roles setting for institutions with custom roles.
- Course category filter to exclude development/test courses from reports.
- Cohort filter respects course pattern when a specific cohort is selected (admins no longer bypass filtering).
- Debug panel available on lecturer profile via `?debug=1` URL parameter.

## [1.2.0] - 2026-04-02

### Added
- **Programme cohort mapping widget** — Custom admin setting that combines cohort selection and course shortname regex patterns in a single table UI. Replaces the separate cohort checkbox and textarea settings.
- **Course pattern scoping** — Lecturers and students are now scoped to programme coordinators via course shortname regex patterns rather than cohort co-membership. A pattern like `^THE` matches all Theology courses, determining which lecturers and students a PC can see.
- **CSV export** — Programme coordinators can export a time commitment report (per lecturer per course) for a selected date range. Accessible from the lecturer list page.
- **Lecturer time report API endpoint** — `local_coifish_get_lecturer_time_report` web service function for SIS integration, returning per-lecturer per-course time breakdowns for a date range.
- **Course filter on lecturer profile** — Dropdown to filter the lecturer profile to a specific course, scoped to the PC's programme pattern.
- **Date range filter on lecturer profile** — From/to date pickers with clear button for time-scoped analysis.

### Changed
- Capabilities `viewfullprofile` and `viewlecturerprofile` changed from `CONTEXT_COURSECAT` to `CONTEXT_SYSTEM` so they work with system-level role assignments.
- Admin report links moved outside `$hassiteconfig` block so non-admin coordinators with `moodle/site:configview` can see them under Site Administration > Reports.
- Navigation hook now adds a top-level "CoIFish Longitudinal Profile" node for non-admin coordinators.
- Lecturer course dropdown filtered by PC's programme pattern to prevent cross-programme course visibility.
- `estimate_activity_hours` method made public for use by export and API.

### Fixed
- Programme coordinators with custom roles could not see report links (required `moodle/site:config` instead of `moodle/site:configview`).
- Cohort scoping returned all lecturers when PC was not in any included cohort (now returns empty).
- Admin check changed from `has_capability('moodle/cohort:manage')` to `is_siteadmin()` for consistent behaviour.
- Missing capability lang string `coifish:viewlecturerprofile`.

## [1.1.0] - 2026-04-02

### Added
- **Student risk overview** — Institution-wide dashboard of at-risk students with filtering by course category or site cohort.
- **Student drill-down** — Full longitudinal profile with course history, risk indicators, and prescriptive recommendations.
- **Lecturer performance profiles** — Aggregated cross-course analytics for each lecturer with feedback quality, grading turnaround, intervention effectiveness, forum engagement, and student outcome metrics.
- **Lecturer activity time estimation** — Session gap analysis on LMS event logs estimating hours spent on marking, student communication, and live sessions, with configurable preparation multiplier.
- **KPI detail modals** — "How is this determined?" drill-down for each key performance indicator with data sources, benchmarks, and research citations.
- **Strengths and focus areas** — Automated identification of top 3 strengths and bottom 3 focus areas with constructive recommendations.
- **Cohort-based organisation mode** — Alternative to category filtering; admins select which site cohorts to include in reports.
- **Course-level longitudinal toggle** — Enable or disable longitudinal data per course in CoIFish report settings.
- **Early warning integration** — CoIFish grade report displays at-risk students on the cohort insights tab and longitudinal profiles on student insights.
- **Prescriptive recommendations** — Risk-factor-based guidance for teachers: engagement decline, social isolation, feedback neglect, intervention unresponsiveness, grade decline, late starter, irregular patterns.
- **External API** — Web service endpoints for SIS integration: student profile, early warnings, course history.
- **Navigation hooks** — Risk overview and lecturer profiles accessible via Site Administration > Reports.
- **PHPUnit tests** — 15 tests covering API, lecturer API, and filter helper across PostgreSQL and MariaDB.
- **CI workflow** — GitHub Actions with PHPCS, phplint, phpdoc, validate, savepoints, mustache, grunt, and PHPUnit.

## [1.0.0] - 2026-04-01

### Added
- Initial release.
- Longitudinal student profiles built from historical course data via daily scheduled task (3:00 AM).
- Per-student course snapshots capturing grade, engagement, social presence, self-regulation, and feedback review metrics.
- Risk classification (low, moderate, high) based on accumulated risk factors.
- Engagement pattern detection (consistent, declining, growing, irregular).
- Intervention response tracking (positive, mixed, unresponsive) from CoIFish intervention data.
- Privacy-controlled API with three detail levels (patterns, summary, full).
- Integration with gradereport_coifish for in-course longitudinal profile display.

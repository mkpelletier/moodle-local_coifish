# Changelog

## [1.5.1] - 2026-06-17

### Fixed
- **Assignment drill-down rows now line up with the course-table columns.** The per-assignment breakdown was rendered as a separate nested `<table>` inside a full-width cell, so its columns auto-sized independently and drifted out of alignment with the course header row. The rows are now rendered as `<tr>`s in the *same* table (each course's detail is a collapsible `<tbody>`), so every column aligns with its heading. Assignment names are indented to keep the nested-under-course cue.

## [1.5.0] - 2026-06-17

### Added
- **Feedback-by-course drill-down** on the lecturer profile so a coordinator can see which courses make up a lecturer's composite feedback-quality score, rather than the number being opaque. A collapsible "Feedback by course" card lists each course (worst composite first) with its coverage/depth/quality/personalisation, composite, and with-feedback/graded counts, via `lecturer_api::get_feedback_breakdown()` — one query over the `gradereport_coifish_feedback` cache, scoped to the same in-scope courses (and course filter) as the rest of the profile, and consistent with the dilution fix (only courses with `totalgraded > 0`). Read-only.
- **Lazy per-assignment expand.** Each course row expands on demand to its per-assignment feedback breakdown, fetched once over a new AJAX web service `local_coifish_get_assignment_feedback(userid, courseid)` and cached client-side per course (never eager-loaded). The web service guards `\gradereport_coifish\report::get_assignment_feedback_breakdown()` with `class_exists`/`method_exists` and returns `[]` when the optional grade report is absent. New AMD module `local_coifish/feedback_breakdown`.

### Changed
- **Feedback-quality scores are no longer diluted by ungraded courses.** A lecturer's `avgfeedbackquality` (and the coverage/depth/personalisation averages) now average only over courses where they actually graded something (`totalgraded > 0`), instead of being dragged toward zero by courses they hold a teaching role in but do not grade. Applied consistently across the cached profile, the filtered/range view, and the weekly trend snapshot.
- **Removed an N+1 query from the daily profile build.** `build_lecturer_profiles` previously issued one forum-post count query per course inside the per-lecturer loop; it now uses a single grouped query per lecturer.

## [1.4.3] - 2026-06-12

### Changed
- **Active-snapshot task is much lighter on the database.** `build_active_snapshots` now skips students whose snapshot is unchanged — it compares each student's last logstore activity and last gradebook change (two per-course aggregate queries) against the snapshot's compute time, and only recomputes the ones that actually moved. A jittered 7-day TTL forces a periodic rebuild so course-structure drift still self-heals, spread across days to avoid a synchronised spike. The run logs `refreshed N, skipped M` via mtrace.
- **Per-course-invariant work hoisted out of the per-student loop.** The expected-activity count and the forum-discussion list (previously re-queried for every student inside `capture_student_metrics`) are now fetched once per course and passed in, removing two queries per student. `capture_student_metrics` gained optional `$totalactivities` / `$discussions` parameters (callers that omit them are unaffected), and the discussion query is exposed as `metrics_helper::get_course_discussions()`.

## [1.4.2] - 2026-06-12

### Fixed
- **The `course_category` setting now applies to lecturer profiles.** Previously the category limit was honoured only on the student/risk-overview side, so the lecturer profile (cached and filtered), the profile course dropdown, the weekly trend snapshots, and the per-course time report counted courses in every category (including dev/test courses outside the configured tree). Every lecturer-side course-selection query now applies `filter_helper::get_category_scope_sql()`, consistent with the risk overview. The time report additionally gained the `lecturer_excluded_courses` filter it was missing.
- **Student-outcome averaging no longer silently drops course rows.** `build_lecturer_profiles()` grouped the per-course outcome query by `(courseid, courseenddate)` while keying the result on `courseid`, so a course with more than one end-date value lost rows (and skewed the average). Now grouped by `courseid` alone with `MAX(courseenddate)` for trend ordering.
- **Filtered lecturer profiles no longer emit a `fullname()` debugging notice.** `compute_lecturer_profile_for_range()` now fetches the full set of user name fields, matching the other two profile paths.

## [1.4.1] - 2026-06-12

### Changed
- **Grading turnaround now clocks from the first grade**, not the last edit. All three turnaround sites (`lecturer_api::compute_lecturer_profile_for_range()`, `lecturer_api::build_lecturer_profiles()`, and `lecturer_period_snapshot::upsert()`) now measure submission → `assign_grades.timecreated` instead of `assign_grades.timemodified`, so a grade correction made months later no longer retroactively inflates a lecturer's turnaround. The weekly snapshot's period bound is likewise on first-grade time. The shared expression matches the `gradereport_coifish` plugin for consistency.
- **Integrity referrals pause the turnaround clock.** Where the optional `local_unifiedgrader_referral` table is present (Unified Grader installed), an item escalated for an academic-integrity review stops the lecturer's clock at the moment of referral (1-stamp model: submission → escalation), so delays outside the lecturer's control are not counted against them. Every query that touches the table is guarded by `table_exists()` and degrades gracefully to the first-grade base when it is absent.

### Added
- **Per-assignment turnaround drill-down** via `lecturer_api::get_turnaround_breakdown()` and a collapsible "Turnaround by assignment" card on the lecturer profile. Each row shows the raw (first-grade) vs adjusted (referral-paused) average days, the number graded, and a badge counting items held for integrity review. Assignments averaging more than 7 days are highlighted. Read-only.

## [1.4.0] - 2026-06-09

### Added
- **Historical lecturer-activity trends** with weekly per-lecturer caching. A new `local_coifish_lecturer_period_snapshot` table holds one row per lecturer per ISO week. The existing daily task writes the current week's row; a new hourly `backfill_lecturer_snapshots` task fills missing past weeks at a bounded rate per run (default 50/run) until the configured horizon is met. Past weeks are written exactly once and never recomputed — read path on the lecturer profile is a single indexed SELECT, no log scans.
- **"Historical trends" card** on the lecturer profile rendering inline SVG sparklines (no chart-library dependency) for feedback quality, grading turnaround, forum posts per week, intervention effectiveness, hours total, and average student grade. Each sparkline shows the latest value with a Δ% vs the window start.
- **Admin setting `lecturer_backfill_years`** (default 3, range 1–10) controls how far back the historical backfill goes.
- **Dynamic paginated tables** on Student Risk Overview and Lecturer list using Moodle's `core_table\dynamic` API. AJAX pagination, sortable columns, and configurable page size. Summary count cards (high/moderate/total) move to a separate aggregate query so the numbers reflect the full filtered population rather than just the visible page.
- **Defensive hard cap** (`MAX_RISK_OVERVIEW_ROWS = 1000`) on `api::get_risk_overview()` to guard programmatic / web-service callers against accidentally pulling unbounded result sets.

### Changed
- **Risk Overview filter dropdowns** preserved as URL state but the table itself now refreshes via AJAX. Bookmarks with existing `?categoryid=…&risklevel=…&enrolstatus=…` continue to work.
- **`metrics_helper::capture_student_metrics()`** signature gains a `$starttime` parameter applied to the three `logstore_standard_log` queries (engagement, feedback view, self-regulation) plus the `forum_posts` and `assign_grades` time-bounded supporting queries. Callers populate from the course start date.
- **`lecturer_api::estimate_activity_hours()`** signature changed from `(uid, courseids, $now)` to `(uid, courseids, $timefrom, $timeto)` with both bounds applied to the marking and communication `logstore_standard_log` queries. Callers pass either the user-selected date range or a 120-day cron lookback. Each UNION branch in the `local_unifiedgrader` fallback now uses unique `:tfromN`/`:ttoN` placeholders.
- **Cohort programme pattern resolution** factored into `filter_helper::get_course_ids_matching_pattern()`. Simple anchored patterns (`^THE`, `^BIB2`) translate to SQL `LIKE 'THE%'` and run server-side. Complex patterns fall back to a category-scoped PHP regex pass — never the whole `course` table. Per-request memoisation cache included.
- **Renderables transitioned to the Output API.** The CSV export form's PHP-echoed HTML moved to `templates/export_form.mustache` with a new `\local_coifish\output\export_form` renderable. The cohort-programme admin setting's inline `<script>` and HTML moved to `templates/cohort_patterns_setting.mustache` + `amd/src/cohort_patterns_setting.js` loaded via `$PAGE->requires->js_call_amd()`. No remaining PHP-built HTML strings or inline JS in the plugin.

### Fixed
- **N+1 query patterns eliminated** in seven hot paths by bulk-loading users and existing snapshot rows ahead of loops. `refreshcurrent.php` pre-loads the student's existing active-snapshot rows; `build_active_snapshots::refresh_course()` does the same per-course; `build_profiles::snapshot_course()` pre-loads existing userids via `get_fieldset_select`; `api::get_early_warnings()`, `lecturer_api::get_all_lecturer_profiles()`, `local_coifish_generate_export_data()`, and `external\get_lecturer_time_report` all bulk-load user records using `\core_user\fields::for_name()`. The two lecturer-time call sites also bulk-load `(lecturer, course)` role assignments in one query. Query counts now constant per page render.
- **`logstore_standard_log` queries no longer scan unbounded history.** All five queries in the plugin apply strict time bounds aligned with the table's shipped `course-time` and `user-module` indexes (both ending in `timecreated`), turning range scans into index seeks. Addresses peer-reviewer concern about production log-table size.
- **`get_lecturer_profile()` no longer triggers Moodle 5.x "missing name fields" debugging notices.** User record fetch now uses `\core_user\fields::for_name()` so all fullname-relevant columns (including phonetic and alternate names) are present on the object passed to `fullname()`.

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

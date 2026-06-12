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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Scheduled task to build in-progress (active) per-student per-course snapshots
 * for courses that are still running.
 *
 * The output table local_coifish_active_snapshot powers the "Current enrolments"
 * card on the student drill-down. It is a parallel structure to
 * local_coifish_course_snapshot (which only contains final post-course values).
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coifish\task;

use core\task\scheduled_task;
use local_coifish\metrics_helper;

/**
 * Build in-progress course snapshots for active enrolments.
 */
class build_active_snapshots extends scheduled_task {
    /**
     * Base number of days a snapshot is trusted without a detected change before
     * a refresh is forced anyway (so course-structure drift self-heals). A
     * per-user jitter is added on top to spread forced refreshes across days.
     */
    private const STALE_TTL_DAYS = 7;

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_build_active_snapshots', 'local_coifish');
    }

    /**
     * Execute the task: refresh active snapshots for every visible currently-running course.
     */
    public function execute(): void {
        global $DB;

        if (get_config('local_coifish', 'profile_enabled') === '0') {
            return;
        }

        $now = time();
        [$catfrag, $catparams] = \local_coifish\filter_helper::get_category_scope_sql('c');
        $courses = $DB->get_records_sql(
            "SELECT c.id, c.fullname, c.category, c.startdate, c.enddate
               FROM {course} c
              WHERE c.id != :siteid
                AND c.visible = 1
                AND (c.enddate = 0 OR c.enddate > :now)
                $catfrag",
            array_merge(['siteid' => SITEID, 'now' => $now], $catparams)
        );

        $refreshed = 0;
        $skipped = 0;
        foreach ($courses as $course) {
            $counts = $this->refresh_course($course, $now);
            $refreshed += $counts['refreshed'];
            $skipped += $counts['skipped'];
        }
        mtrace("local_coifish: active snapshots refreshed {$refreshed}, "
            . "skipped {$skipped} unchanged within TTL.");

        // Delete snapshot rows whose course is no longer in scope (course
        // hidden, ended, deleted, or now outside the configured category).
        // Per-course unenrolment cleanup happens inside refresh_course().
        $courseids = array_keys($courses);
        if (empty($courseids)) {
            $DB->delete_records('local_coifish_active_snapshot');
        } else {
            [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'kc', false);
            $DB->delete_records_select('local_coifish_active_snapshot', "courseid $insql", $inparams);
        }
    }

    /**
     * Refresh active snapshots for every enrolled student in a course.
     *
     * @param object $course Course record.
     * @param int $now Current timestamp.
     */
    protected function refresh_course(object $course, int $now): array {
        global $DB;

        $context = \context_course::instance($course->id, IGNORE_MISSING);
        if (!$context) {
            return ['refreshed' => 0, 'skipped' => 0];
        }

        $students = get_enrolled_users($context, 'moodle/course:isincompletionreports', 0, 'u.id');
        $studentids = array_keys($students);

        // Remove snapshot rows for users no longer actively enrolled in this course.
        if (empty($studentids)) {
            $DB->delete_records('local_coifish_active_snapshot', ['courseid' => $course->id]);
            return ['refreshed' => 0, 'skipped' => 0];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'su', false);
        $DB->delete_records_select(
            'local_coifish_active_snapshot',
            "courseid = :cid AND userid $insql",
            array_merge(['cid' => $course->id], $inparams)
        );

        // Course grade item may not exist yet on brand-new courses; capture_student_metrics
        // handles a missing item by leaving grade null.
        $courseitem = $DB->get_record('grade_items', [
            'courseid' => $course->id,
            'itemtype' => 'course',
        ]) ?: null;

        $termlabel = metrics_helper::resolve_term_label($course);

        // Per-course-invariant inputs, fetched once and passed into every
        // refresh_one call rather than recomputed for each student.
        $totalactivities = \gradereport_coifish\report::get_expected_activity_count((int)$course->id);
        $discussions = metrics_helper::get_course_discussions((int)$course->id);

        // Pre-load existing rows (with their compute time) in one query so we can
        // both skip unchanged students and avoid a get_record per student.
        $existingbyuser = $DB->get_records(
            'local_coifish_active_snapshot',
            ['courseid' => (int)$course->id],
            '',
            'userid, id, timecomputed'
        );

        // Per-course "last change" signals for the staleness skip: the latest
        // logstore activity by each student (their own views/posts/feedback
        // views) and the latest gradebook change for them (teacher grading or
        // feedback). One aggregate query each, vs re-deriving per student.
        $coursestart = (int)($course->startdate ?? 0);
        $lastactivity = $DB->get_records_sql_menu(
            "SELECT userid, MAX(timecreated) AS lastts
               FROM {logstore_standard_log}
              WHERE courseid = :cid AND timecreated >= :cstart
           GROUP BY userid",
            ['cid' => (int)$course->id, 'cstart' => $coursestart]
        );
        $lastgrade = $DB->get_records_sql_menu(
            "SELECT gg.userid, MAX(gg.timemodified) AS lastts
               FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.courseid = :cid
           GROUP BY gg.userid",
            ['cid' => (int)$course->id]
        );

        $refreshed = 0;
        $skipped = 0;
        foreach ($studentids as $userid) {
            $existing = $existingbyuser[$userid] ?? null;
            $lastchange = max((int)($lastactivity[$userid] ?? 0), (int)($lastgrade[$userid] ?? 0));
            if (self::is_snapshot_fresh($existing, (int)$userid, $lastchange, $now)) {
                $skipped++;
                continue;
            }
            self::refresh_one(
                $course,
                $userid,
                $courseitem,
                $termlabel,
                $now,
                $existing,
                $totalactivities,
                $discussions
            );
            $refreshed++;
        }

        return ['refreshed' => $refreshed, 'skipped' => $skipped];
    }

    /**
     * Decide whether an existing active snapshot can be left untouched this run.
     *
     * Skips only when (a) a snapshot already exists, (b) nothing the snapshot
     * depends on has changed since it was computed — no newer logstore activity
     * by the student and no newer gradebook change for them — and (c) the
     * snapshot is still within its time-to-live. The TTL forces a periodic
     * rebuild so drift our signals can't see (e.g. an added activity shifting
     * the engagement denominator) self-heals, and is jittered by user id so the
     * forced rebuilds spread across days instead of spiking on one night.
     *
     * @param object|null $existing Pre-loaded snapshot row (needs `timecomputed`), or null.
     * @param int $userid Student user id (seeds the TTL jitter).
     * @param int $lastchange Latest change timestamp detected for this student.
     * @param int $now Current timestamp.
     * @return bool True to skip (still fresh), false to rebuild.
     */
    protected static function is_snapshot_fresh(?object $existing, int $userid, int $lastchange, int $now): bool {
        if (!$existing) {
            return false;
        }
        $computed = (int)$existing->timecomputed;
        if ($computed < $lastchange) {
            return false;
        }
        $ttl = (self::STALE_TTL_DAYS + ($userid % 7)) * DAYSECS;
        return $computed >= ($now - $ttl);
    }

    /**
     * Refresh (or insert) a single active snapshot row.
     *
     * Public + static so the on-demand refresh endpoint can reuse it.
     *
     * @param object $course Course record.
     * @param int $userid Student user ID.
     * @param object|null $courseitem Course grade item (re-fetched if null).
     * @param string|null $termlabel Pre-resolved term label, or null to resolve here.
     * @param int|null $now Timestamp; defaults to time().
     * @param object|null $existing Pre-fetched existing row (must have `id` field) to avoid
     *                              an extra get_record. Pass null to look up here. Pass false-y
     *                              if no existing row is expected.
     * @return bool True if a row was written, false if metrics could not be captured.
     */
    public static function refresh_one(
        object $course,
        int $userid,
        ?object $courseitem = null,
        ?string $termlabel = null,
        ?int $now = null,
        ?object $existing = null,
        ?int $totalactivities = null,
        ?array $discussions = null
    ): bool {
        global $DB;

        $now = $now ?? time();

        // Look up the course grade item once if the caller didn't pre-fetch it.
        // A missing/zero item just means we'll record null grade — engagement,
        // social, self-regulation and feedback metrics are still meaningful.
        if ($courseitem === null) {
            $courseitem = $DB->get_record('grade_items', [
                'courseid' => $course->id,
                'itemtype' => 'course',
            ]) ?: null;
        }

        // Lower-bound logstore scans at the course start date.
        $metrics = metrics_helper::capture_student_metrics(
            (int)$course->id,
            $userid,
            $courseitem,
            0,
            (int)($course->startdate ?? 0),
            $totalactivities,
            $discussions
        );

        if ($termlabel === null) {
            $termlabel = metrics_helper::resolve_term_label($course);
        }

        $row = (object)[
            'userid' => $userid,
            'courseid' => (int)$course->id,
            'currentgrade' => $metrics['grade'],
            'engagement' => $metrics['engagement'],
            'social' => $metrics['social'],
            'selfregulation' => $metrics['selfregulation'],
            'feedbackpct' => $metrics['feedbackpct'],
            'coursestartdate' => (int)($course->startdate ?? 0),
            'courseenddate' => (int)($course->enddate ?? 0),
            'categoryid' => (int)($course->category ?? 0),
            'termlabel' => $termlabel,
            'timecomputed' => $now,
        ];

        // Callers must pre-load the existing row (or pass null if there isn't one)
        // so we avoid a get_record per call inside a per-student loop.
        if ($existing) {
            $row->id = $existing->id;
            $DB->update_record('local_coifish_active_snapshot', $row);
        } else {
            $DB->insert_record('local_coifish_active_snapshot', $row);
        }

        return true;
    }
}

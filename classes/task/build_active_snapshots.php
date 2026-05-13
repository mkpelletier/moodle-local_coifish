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

        foreach ($courses as $course) {
            $this->refresh_course($course, $now);
        }

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
    protected function refresh_course(object $course, int $now): void {
        global $DB;

        $context = \context_course::instance($course->id, IGNORE_MISSING);
        if (!$context) {
            return;
        }

        $students = get_enrolled_users($context, 'moodle/course:isincompletionreports', 0, 'u.id');
        $studentids = array_keys($students);

        // Remove snapshot rows for users no longer actively enrolled in this course.
        if (empty($studentids)) {
            $DB->delete_records('local_coifish_active_snapshot', ['courseid' => $course->id]);
            return;
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

        foreach ($studentids as $userid) {
            self::refresh_one($course, $userid, $courseitem, $termlabel, $now);
        }
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
     * @return bool True if a row was written, false if metrics could not be captured.
     */
    public static function refresh_one(
        object $course,
        int $userid,
        ?object $courseitem = null,
        ?string $termlabel = null,
        ?int $now = null
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

        $metrics = metrics_helper::capture_student_metrics((int)$course->id, $userid, $courseitem);

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

        $existing = $DB->get_record('local_coifish_active_snapshot', [
            'userid' => $userid,
            'courseid' => (int)$course->id,
        ], 'id');
        if ($existing) {
            $row->id = $existing->id;
            $DB->update_record('local_coifish_active_snapshot', $row);
        } else {
            $DB->insert_record('local_coifish_active_snapshot', $row);
        }

        return true;
    }
}

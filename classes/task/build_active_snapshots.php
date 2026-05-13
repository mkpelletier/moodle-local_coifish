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
        $courses = $DB->get_records_sql(
            "SELECT c.id, c.fullname, c.category, c.startdate, c.enddate
               FROM {course} c
              WHERE c.id != :siteid
                AND c.visible = 1
                AND (c.enddate = 0 OR c.enddate > :now)",
            ['siteid' => SITEID, 'now' => $now]
        );

        foreach ($courses as $course) {
            $this->refresh_course($course, $now);
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
        if (empty($students)) {
            return;
        }

        $courseitem = $DB->get_record('grade_items', [
            'courseid' => $course->id,
            'itemtype' => 'course',
        ]);
        if (!$courseitem || $courseitem->grademax <= 0) {
            return;
        }

        $termlabel = metrics_helper::resolve_term_label($course);

        foreach (array_keys($students) as $userid) {
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

        if ($courseitem === null) {
            $courseitem = $DB->get_record('grade_items', [
                'courseid' => $course->id,
                'itemtype' => 'course',
            ]);
            if (!$courseitem || $courseitem->grademax <= 0) {
                return false;
            }
        }

        $metrics = metrics_helper::capture_student_metrics((int)$course->id, $userid, $courseitem);
        if ($metrics === null) {
            // No grade record yet — keep any existing row but don't write a stub.
            return false;
        }

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

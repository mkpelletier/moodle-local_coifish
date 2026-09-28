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
 * Ad-hoc task recomputing historical social-presence and live-teaching metrics.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coifish\task;

use core\task\adhoc_task;
use local_coifish\metrics_helper;

/**
 * Brings stored metrics up to the current live-session-aware definitions.
 *
 * Phase 1 recomputes only the `social` value of completed-course snapshots
 * written under an older social-presence definition (socialversion below the
 * current one), clamped to each course's own dates, so longitudinal trends and
 * the social-isolation risk flag compare like with like. Grades and all other
 * metrics are left untouched. Student profiles pick the new values up on the
 * next nightly build_profiles run, which re-aggregates every profile.
 *
 * Phase 2 recomputes the live-session fields of existing weekly lecturer
 * snapshots (see lecturer_period_snapshot::refresh_live()).
 *
 * Active (in-progress) snapshots need no work here: the daily
 * build_active_snapshots task treats rows with an older socialversion as stale.
 *
 * Work is done in time-boxed batches; when the budget runs out the task queues
 * a continuation of itself carrying its cursor.
 */
class recompute_social_snapshots extends adhoc_task {
    /** @var int Seconds of work per run before queueing a continuation. */
    public const TIME_BUDGET = 300;

    /**
     * Get the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_recompute_social_snapshots', 'local_coifish');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        $deadline = time() + self::TIME_BUDGET;
        $data = (object)($this->get_custom_data() ?? []);
        $phase = (int)($data->phase ?? 1);
        $lastid = (int)($data->lastid ?? 0);

        if ($phase === 1) {
            if (!$this->recompute_course_snapshots($deadline)) {
                $this->queue_continuation(1, 0);
                return;
            }
            $phase = 2;
            $lastid = 0;
        }

        $lastid = $this->recompute_lecturer_periods($lastid, $deadline);
        if ($lastid > 0) {
            $this->queue_continuation(2, $lastid);
        }
    }

    /**
     * Phase 1: recompute outdated social values, one course at a time.
     *
     * @param int $deadline Stop starting new courses after this time.
     * @return bool True when no outdated rows remain.
     */
    protected function recompute_course_snapshots(int $deadline): bool {
        global $DB;

        $version = metrics_helper::get_social_version();
        $courseids = $DB->get_fieldset_sql(
            "SELECT DISTINCT courseid FROM {local_coifish_course_snapshot} WHERE socialversion < :v",
            ['v' => $version]
        );
        foreach ($courseids as $courseid) {
            if (time() >= $deadline) {
                return false;
            }
            $course = $DB->get_record('course', ['id' => $courseid], 'id, startdate');
            $rows = $DB->get_records_select(
                'local_coifish_course_snapshot',
                'courseid = :cid AND socialversion < :v',
                ['cid' => $courseid, 'v' => $version],
                '',
                'id, userid, courseenddate'
            );
            if (!$course) {
                // Course deleted: nothing to recompute from; mark as current so it is not retried.
                [$insql, $params] = $DB->get_in_or_equal(array_keys($rows));
                $DB->set_field_select('local_coifish_course_snapshot', 'socialversion', $version, "id $insql", $params);
                continue;
            }
            $discussions = metrics_helper::get_course_discussions((int)$courseid);
            foreach ($rows as $row) {
                $social = metrics_helper::capture_social(
                    (int)$courseid,
                    (int)$row->userid,
                    (int)$row->courseenddate,
                    (int)$course->startdate,
                    $discussions
                );
                $DB->update_record('local_coifish_course_snapshot', (object)[
                    'id' => $row->id,
                    'social' => $social,
                    'socialversion' => $version,
                ]);
            }
        }
        return true;
    }

    /**
     * Phase 2: refresh live-session fields of weekly lecturer snapshots.
     *
     * @param int $lastid Resume after this snapshot id.
     * @param int $deadline Stop after this time.
     * @return int The last processed id when stopped early, or 0 when finished.
     */
    protected function recompute_lecturer_periods(int $lastid, int $deadline): int {
        global $DB;

        $rs = $DB->get_recordset_select(
            'local_coifish_lecturer_period_snapshot',
            'id > :lastid',
            ['lastid' => $lastid],
            'id ASC',
            'id, userid, periodstart, periodend, hours_marking, hours_communication'
        );
        foreach ($rs as $row) {
            if (time() >= $deadline) {
                $rs->close();
                return $lastid;
            }
            \local_coifish\lecturer_period_snapshot::refresh_live($row);
            $lastid = (int)$row->id;
        }
        $rs->close();
        return 0;
    }

    /**
     * Queue a continuation of this task.
     *
     * @param int $phase Phase to resume in.
     * @param int $lastid Cursor within that phase.
     */
    protected function queue_continuation(int $phase, int $lastid): void {
        $task = new self();
        $task->set_custom_data(['phase' => $phase, 'lastid' => $lastid]);
        \core\task\manager::queue_adhoc_task($task);
    }
}

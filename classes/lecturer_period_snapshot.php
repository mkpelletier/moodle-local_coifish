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
 * Per-period (weekly) lecturer snapshot writer.
 *
 * Used by both the daily current-week task and the hourly backfill task.
 * Past periods are written once and never recomputed, so production logstore
 * scans stay bounded to a single ISO-week window per write.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coifish;

/**
 * Compute and upsert one weekly snapshot row for one lecturer.
 */
class lecturer_period_snapshot {
    /**
     * Compute one period snapshot for one lecturer and upsert into the table.
     *
     * Backfilled rows omit feedback-quality metrics (avgfeedbackquality,
     * avgcoverage, avgdepth, avgpersonalisation) because the upstream
     * gradereport_coifish_feedback cache holds only current-state values.
     * Those metrics fill in going forward as fresh weekly snapshots are
     * written.
     *
     * @param int $userid Lecturer user ID.
     * @param int $periodstart Period start timestamp (inclusive, Mon 00:00).
     * @param int $periodend Period end timestamp (inclusive, Sun 23:59:59).
     * @return bool True on write, false if lecturer has no teaching role.
     */
    public static function upsert(int $userid, int $periodstart, int $periodend): bool {
        global $DB;

        $dbman = $DB->get_manager();

        // Courses this lecturer holds a teaching role in.
        [$trinsql, $trparams] = \local_coifish\filter_helper::get_teacher_role_sql('tr');
        $courses = $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.startdate, c.enddate
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
               JOIN {course} c ON c.id = ctx.instanceid
              WHERE ra.userid = :uid
                AND ra.roleid $trinsql
                AND c.id != :siteid",
            array_merge(['ctxlevel' => CONTEXT_COURSE, 'uid' => $userid, 'siteid' => SITEID], $trparams)
        );

        if (empty($courses)) {
            return false;
        }
        $courseids = array_keys($courses);
        [$insqlc, $inparamsc] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'pcr');

        // Activity hours within the period (logstore + UG annotations, time-bounded).
        $hours = \local_coifish\lecturer_api::estimate_activity_hours(
            $userid,
            $courseids,
            $periodstart,
            $periodend
        );

        // Grading turnaround within the period (assign_grades.timemodified bounded).
        $turnaround = $DB->get_field_sql(
            "SELECT AVG(ag.timemodified - asub.timemodified)
               FROM {assign_grades} ag
               JOIN {assign_submission} asub
                    ON asub.assignment = ag.assignment AND asub.userid = ag.userid
                    AND asub.status = 'submitted'
               JOIN {assign} a ON a.id = ag.assignment
              WHERE ag.grader = :uid
                AND a.course $insqlc
                AND ag.grade >= 0
                AND ag.timemodified > asub.timemodified
                AND ag.timemodified BETWEEN :pfrom AND :pto",
            array_merge(
                ['uid' => $userid, 'pfrom' => $periodstart, 'pto' => $periodend],
                $inparamsc
            )
        );
        $avgturnarounddays = $turnaround > 0 ? round($turnaround / 86400, 1) : null;

        // Forum posts within the period (forum_posts.created bounded).
        $totalposts = (int)$DB->count_records_sql(
            "SELECT COUNT(fp.id)
               FROM {forum_posts} fp
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE fd.course $insqlc AND fp.userid = :uid
                AND fp.created BETWEEN :pfrom AND :pto",
            array_merge(['uid' => $userid, 'pfrom' => $periodstart, 'pto' => $periodend], $inparamsc)
        );
        // One week per period — posts-per-week is just the count.
        $avgforumpostspw = $totalposts > 0 ? round((float)$totalposts, 1) : 0.0;

        // Interventions recorded by this teacher within the period.
        $totalintv = 0;
        $intvimproved = 0;
        if ($dbman->table_exists('gradereport_coifish_intv')) {
            $totalintv = (int)$DB->count_records_sql(
                "SELECT COUNT(*) FROM {gradereport_coifish_intv}
                  WHERE teacherid = :uid AND timecreated BETWEEN :pfrom AND :pto",
                ['uid' => $userid, 'pfrom' => $periodstart, 'pto' => $periodend]
            );
            if (
                $totalintv > 0
                && $dbman->table_exists('gradereport_coifish_intv_stu')
                && $dbman->table_exists('gradereport_coifish_intv_out')
            ) {
                $intvimproved = (int)$DB->count_records_sql(
                    "SELECT COUNT(DISTINCT i.id)
                       FROM {gradereport_coifish_intv} i
                       JOIN {gradereport_coifish_intv_stu} s ON s.interventionid = i.id
                       JOIN {gradereport_coifish_intv_out} o ON o.intvstudentid = s.id
                      WHERE i.teacherid = :uid AND o.outcome = 'improved'
                        AND i.timecreated BETWEEN :pfrom AND :pto",
                    ['uid' => $userid, 'pfrom' => $periodstart, 'pto' => $periodend]
                );
            }
        }

        // Student grades from courses that ENDED within this period.
        [$tr3insql, $tr3params] = \local_coifish\filter_helper::get_teacher_role_sql('tr3');
        $studentgrades = $DB->get_records_sql(
            "SELECT cs.courseid, AVG(cs.finalgrade) AS avggrade
               FROM {local_coifish_course_snapshot} cs
               JOIN {role_assignments} ra ON ra.userid = :uid
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
                    AND ctx.instanceid = cs.courseid
              WHERE ra.roleid $tr3insql
                AND cs.courseenddate BETWEEN :pfrom AND :pto
           GROUP BY cs.courseid",
            array_merge(
                ['uid' => $userid, 'ctxlevel' => CONTEXT_COURSE, 'pfrom' => $periodstart, 'pto' => $periodend],
                $tr3params
            )
        );
        $gradevalues = array_filter(
            array_column(array_values($studentgrades), 'avggrade'),
            function ($v) {
                return $v !== null;
            }
        );
        $avgstudentgrade = !empty($gradevalues)
            ? round(array_sum($gradevalues) / count($gradevalues), 2)
            : null;

        // Feedback-quality metrics: only filled for the most recent period
        // (the gradereport_coifish_feedback cache holds current state only).
        $avgquality = $avgcoverage = $avgdepth = $avgpers = null;
        $iscurrentperiod = ($periodend >= time() - 7 * 86400);
        if ($iscurrentperiod && $dbman->table_exists('gradereport_coifish_feedback')) {
            $fb = $DB->get_record_sql(
                "SELECT AVG(composite) AS avgquality, AVG(coverage) AS avgcoverage,
                        AVG(depth) AS avgdepth, AVG(personalisation) AS avgpers
                   FROM {gradereport_coifish_feedback}
                  WHERE userid = :uid",
                ['uid' => $userid]
            );
            if ($fb) {
                $avgquality = $fb->avgquality !== null ? (int)round($fb->avgquality) : null;
                $avgcoverage = $fb->avgcoverage !== null ? (int)round($fb->avgcoverage) : null;
                $avgdepth = $fb->avgdepth !== null ? (int)round($fb->avgdepth) : null;
                $avgpers = $fb->avgpers !== null ? (int)round($fb->avgpers) : null;
            }
        }

        $row = (object)[
            'userid' => $userid,
            'periodstart' => $periodstart,
            'periodend' => $periodend,
            'coursecount' => count($courses),
            'avgfeedbackquality' => $avgquality,
            'avgcoverage' => $avgcoverage,
            'avgdepth' => $avgdepth,
            'avgpersonalisation' => $avgpers,
            'avgturnarounddays' => $avgturnarounddays,
            'avgforumpostspw' => $avgforumpostspw,
            'hours_marking' => $hours['marking'],
            'hours_communication' => $hours['communication'],
            'hours_livesessions' => $hours['livesessions'],
            'hours_total' => $hours['total'],
            'totalinterventions' => $totalintv,
            'interventionsimproved' => $intvimproved,
            'avgstudentgrade' => $avgstudentgrade,
            'timecomputed' => time(),
        ];

        $existing = $DB->get_record(
            'local_coifish_lecturer_period_snapshot',
            ['userid' => $userid, 'periodstart' => $periodstart],
            'id'
        );
        if ($existing) {
            $row->id = $existing->id;
            $DB->update_record('local_coifish_lecturer_period_snapshot', $row);
        } else {
            $DB->insert_record('local_coifish_lecturer_period_snapshot', $row);
        }

        return true;
    }

    /**
     * Compute the ISO week (Mon 00:00 to Sun 23:59:59) that contains a timestamp.
     *
     * @param int $ts Unix timestamp.
     * @return array Two-element array: start timestamp, end timestamp.
     */
    public static function week_bounds(int $ts): array {
        // Find Monday of the ISO week. The dow value is Sunday-indexed: 0 = Sun, 1 = Mon, etc.
        $dow = (int)gmdate('w', $ts);
        $offset = ($dow === 0 ? 6 : $dow - 1); // Days since Monday.
        $monday = strtotime(gmdate('Y-m-d', $ts - $offset * 86400) . ' 00:00:00 UTC');
        $sunday = $monday + 7 * 86400 - 1;
        return [$monday, $sunday];
    }
}

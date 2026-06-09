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
 * Data layer for lecturer performance profiles.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coifish;

/**
 * API for building and retrieving lecturer performance profiles.
 */
class lecturer_api {
    /** @var array Dimension keys in display order. */
    protected const DIMENSIONS = [
        'feedback_quality', 'feedback_coverage', 'feedback_depth', 'feedback_personalisation',
        'grading_turnaround', 'intervention_effectiveness', 'forum_engagement', 'student_outcomes',
    ];

    /**
     * Get a lecturer's formatted performance profile.
     *
     * @param int $userid Lecturer user ID.
     * @param bool $isself Whether the viewer is the lecturer themselves.
     * @return array Formatted profile data or empty array if not found.
     */
    public static function get_lecturer_profile(int $userid, bool $isself = false): array {
        global $DB;

        $record = $DB->get_record('local_coifish_lecturer', ['userid' => $userid]);
        if (!$record) {
            return [];
        }

        // Include all name fields so fullname() doesn't trigger Moodle 5.x
        // "missing name fields" debugging notices.
        $namefields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
        $user = $DB->get_record_sql(
            "SELECT id, email, $namefields FROM {user} WHERE id = :id",
            ['id' => $userid]
        );
        if (!$user) {
            return [];
        }

        return self::format_profile($record, $user, $isself);
    }

    /**
     * Compute a lecturer profile on-the-fly for a specific date range.
     *
     * Used when the user applies a date filter. This bypasses the cached table
     * and queries source data directly, constrained by the given timestamps.
     *
     * @param int $userid Lecturer user ID.
     * @param bool $isself Whether the viewer is the lecturer.
     * @param int $timefrom Start timestamp (0 for no lower bound).
     * @param int $timeto End timestamp.
     * @param int $courseid Filter to a specific course (0 for all courses).
     * @return array Formatted profile data or empty array.
     */
    public static function compute_lecturer_profile_for_range(
        int $userid,
        bool $isself,
        int $timefrom,
        int $timeto,
        int $courseid = 0
    ): array {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname, email');
        if (!$user) {
            return [];
        }

        $dbman = $DB->get_manager();
        $hasfeedback = $dbman->table_exists('gradereport_coifish_feedback');
        $hasintv = $dbman->table_exists('gradereport_coifish_intv');

        // Get courses this lecturer teaches.
        [$trinsql, $trparams] = \local_coifish\filter_helper::get_teacher_role_sql();
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
            return [];
        }

        // Apply course filter if specified.
        if ($courseid > 0) {
            if (!isset($courses[$courseid])) {
                return []; // Lecturer doesn't teach this course.
            }
            $courseids = [$courseid];
        } else {
            $courseids = array_keys($courses);
        }
        $coursecount = count($courseids);
        [$insqlc, $inparamsc] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'crs');

        // Time condition for logstore queries.
        $timeconditions = '';
        $timeparams = [];
        if ($timefrom > 0) {
            $timeconditions .= ' AND timecreated >= :tfrom';
            $timeparams['tfrom'] = $timefrom;
        }
        if ($timeto > 0) {
            $timeconditions .= ' AND timecreated <= :tto';
            $timeparams['tto'] = $timeto;
        }

        // Feedback quality (from cache — not time-bounded as it's a snapshot).
        $fb = null;
        if ($hasfeedback) {
            $fb = $DB->get_record_sql(
                "SELECT AVG(composite) AS avgquality, AVG(coverage) AS avgcoverage,
                        AVG(depth) AS avgdepth, AVG(personalisation) AS avgpers
                   FROM {gradereport_coifish_feedback}
                  WHERE userid = :uid",
                ['uid' => $userid]
            );
        }

        // Grading turnaround within date range.
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
                AND ag.timemodified > asub.timemodified"
                . ($timefrom > 0 ? ' AND ag.timemodified >= :tfrom' : '')
                . ($timeto > 0 ? ' AND ag.timemodified <= :tto' : ''),
            array_merge(['uid' => $userid], $inparamsc, $timeparams)
        );
        $avgturnarounddays = $turnaround > 0 ? round($turnaround / 86400, 1) : null;

        // Interventions within date range.
        $totalintv = 0;
        $intvimproved = 0;
        if ($hasintv) {
            $totalintv = (int)$DB->count_records_sql(
                "SELECT COUNT(*) FROM {gradereport_coifish_intv}
                  WHERE teacherid = :uid"
                    . ($timefrom > 0 ? ' AND timecreated >= :tfrom' : '')
                    . ($timeto > 0 ? ' AND timecreated <= :tto' : ''),
                array_merge(['uid' => $userid], $timeparams)
            );
            if ($totalintv > 0) {
                $intvimproved = (int)$DB->count_records_sql(
                    "SELECT COUNT(DISTINCT i.id)
                       FROM {gradereport_coifish_intv} i
                       JOIN {gradereport_coifish_intv_stu} s ON s.interventionid = i.id
                       JOIN {gradereport_coifish_intv_out} o ON o.intvstudentid = s.id
                      WHERE i.teacherid = :uid AND o.outcome = 'improved'"
                        . ($timefrom > 0 ? ' AND i.timecreated >= :tfrom' : '')
                        . ($timeto > 0 ? ' AND i.timecreated <= :tto' : ''),
                    array_merge(['uid' => $userid], $timeparams)
                );
            }
        }
        $intveffectiveness = $totalintv > 0 ? round(($intvimproved / $totalintv) * 100) : null;

        // Forum engagement within date range.
        $totalposts = (int)$DB->count_records_sql(
            "SELECT COUNT(fp.id)
               FROM {forum_posts} fp
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE fd.course $insqlc AND fp.userid = :uid"
                . ($timefrom > 0 ? ' AND fp.created >= :tfrom' : '')
                . ($timeto > 0 ? ' AND fp.created <= :tto' : ''),
            array_merge(['uid' => $userid], $inparamsc, $timeparams)
        );
        $rangeweeks = max(1, ($timeto - ($timefrom ?: $timeto - 120 * 86400)) / (7 * 86400));
        $avgforumpostspw = round($totalposts / $rangeweeks, 1);

        // Activity time estimation within date range. Defaults: lower bound 120
        // days back from $timeto if no $timefrom given, to keep log scans bounded.
        $hto = $timeto ?: time();
        $hfrom = $timefrom > 0 ? $timefrom : ($hto - 120 * 86400);
        $hours = self::estimate_activity_hours($userid, $courseids, $hfrom, $hto);

        // Student outcome — use all-time from cache as it needs course completion data.
        $cached = $DB->get_record('local_coifish_lecturer', ['userid' => $userid]);
        $avgstudentgrade = $cached->avgstudentgrade ?? null;
        $studentgradetrend = $cached->studentgradetrend ?? 'unknown';

        // Compute strengths and focus areas.
        $dimensions = [
            'feedback_quality' => $fb->avgquality ?? 50,
            'feedback_coverage' => $fb->avgcoverage ?? 50,
            'feedback_depth' => $fb->avgdepth ?? 50,
            'feedback_personalisation' => $fb->avgpers ?? 50,
            'grading_turnaround' => $avgturnarounddays !== null
                ? max(0, min(100, round((7 - $avgturnarounddays) / 7 * 100)))
                : 50,
            'intervention_effectiveness' => $intveffectiveness ?? 50,
            'forum_engagement' => min(100, round($avgforumpostspw / 3.0 * 100)),
            'student_outcomes' => $avgstudentgrade !== null
                ? min(100, round($avgstudentgrade))
                : 50,
        ];
        $sf = self::compute_strengths_and_focus($dimensions);

        // Build a record-like object for format_profile.
        $record = (object)[
            'userid' => $userid,
            'coursecount' => $coursecount,
            'avgfeedbackquality' => $fb ? round($fb->avgquality) : null,
            'avgcoverage' => $fb ? round($fb->avgcoverage) : null,
            'avgdepth' => $fb ? round($fb->avgdepth) : null,
            'avgpersonalisation' => $fb ? round($fb->avgpers) : null,
            'avgturnarounddays' => $avgturnarounddays,
            'totalinterventions' => $totalintv,
            'interventionsimproved' => $intvimproved,
            'interventioneffectiveness' => $intveffectiveness,
            'avgforumpostspw' => $avgforumpostspw,
            'avgstudentgrade' => $avgstudentgrade,
            'studentgradetrend' => $studentgradetrend,
            'hours_marking' => $hours['marking'],
            'hours_communication' => $hours['communication'],
            'hours_livesessions' => $hours['livesessions'],
            'hours_total' => $hours['total'],
            'strengths' => json_encode($sf['strengths']),
            'focusareas' => json_encode($sf['focusareas']),
            'timemodified' => time(),
        ];

        return self::format_profile($record, $user, $isself);
    }

    /**
     * Get all lecturer profiles, optionally filtered by course category or explicit user IDs.
     *
     * @param int $categoryid Category filter (0 for all). Ignored if $userids is provided.
     * @param array|null $userids Explicit user ID filter (from cohort mode). Null = use category.
     * @return array Array of formatted profiles.
     */
    public static function get_all_lecturer_profiles(int $categoryid = 0, ?array $userids = null): array {
        global $DB;

        if ($userids !== null) {
            // Explicit user ID filter (cohort mode).
            if (empty($userids)) {
                return [];
            }
            $lecturerids = $userids;
            [$insql2, $inparams2] = $DB->get_in_or_equal($lecturerids, SQL_PARAMS_NAMED);
            $records = $DB->get_records_select('local_coifish_lecturer', "userid $insql2", $inparams2);
        } else if ($categoryid > 0) {
            // Get lecturers who teach in courses within this category tree.
            $cat = \core_course_category::get($categoryid, IGNORE_MISSING);
            if (!$cat) {
                return [];
            }
            $catids = array_merge([$categoryid], $cat->get_all_children_ids());
            [$insql, $inparams] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED);
            [$trinsql, $trparams] = \local_coifish\filter_helper::get_teacher_role_sql();
            $lecturerids = $DB->get_fieldset_sql(
                "SELECT DISTINCT ra.userid
                   FROM {role_assignments} ra
                   JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
                   JOIN {course} c ON c.id = ctx.instanceid
                  WHERE c.category $insql
                    AND ra.roleid $trinsql",
                array_merge(['ctxlevel' => CONTEXT_COURSE], $inparams, $trparams)
            );
            if (empty($lecturerids)) {
                return [];
            }
            [$insql2, $inparams2] = $DB->get_in_or_equal($lecturerids, SQL_PARAMS_NAMED);
            $records = $DB->get_records_select('local_coifish_lecturer', "userid $insql2", $inparams2);
        } else {
            $records = $DB->get_records('local_coifish_lecturer', null, 'coursecount DESC');
        }

        if (empty($records)) {
            return [];
        }

        // Bulk-load user records (name + email) for every lecturer in one query.
        $userids = array_column(array_values($records), 'userid');
        [$uinsql, $uinparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'lu');
        $namefields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
        $users = $DB->get_records_select('user', "id $uinsql", $uinparams, '', "id, email, $namefields");

        $result = [];
        foreach ($records as $record) {
            $user = $users[$record->userid] ?? null;
            if ($user) {
                $result[] = self::format_profile($record, $user, false);
            }
        }
        return $result;
    }

    /**
     * Build lecturer profiles from source data. Called by the scheduled task.
     *
     * @param int $now Current timestamp.
     */
    public static function build_lecturer_profiles(int $now): void {
        global $DB;

        $dbman = $DB->get_manager();
        $hasfeedback = $dbman->table_exists('gradereport_coifish_feedback');
        $hasintv = $dbman->table_exists('gradereport_coifish_intv');

        // Find all users who hold a grading role in at least one course.
        [$tr1insql, $tr1params] = \local_coifish\filter_helper::get_teacher_role_sql('tr1');
        $lecturers = $DB->get_records_sql(
            "SELECT DISTINCT ra.userid
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
              WHERE ra.roleid $tr1insql",
            array_merge(['ctxlevel' => CONTEXT_COURSE], $tr1params)
        );

        foreach ($lecturers as $lecturer) {
            $uid = $lecturer->userid;

            // Get courses this lecturer teaches.
            [$tr2insql, $tr2params] = \local_coifish\filter_helper::get_teacher_role_sql('tr2');
            $courses = $DB->get_records_sql(
                "SELECT DISTINCT c.id, c.startdate, c.enddate
                   FROM {role_assignments} ra
                   JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
                   JOIN {course} c ON c.id = ctx.instanceid
                  WHERE ra.userid = :uid
                    AND ra.roleid $tr2insql
                    AND c.id != :siteid",
                array_merge(['ctxlevel' => CONTEXT_COURSE, 'uid' => $uid, 'siteid' => SITEID], $tr2params)
            );

            if (empty($courses)) {
                continue;
            }

            $coursecount = count($courses);
            $courseids = array_keys($courses);

            // Feedback quality from CoIFish cache.
            $fb = null;
            if ($hasfeedback) {
                $fb = $DB->get_record_sql(
                    "SELECT AVG(composite) AS avgquality, AVG(coverage) AS avgcoverage,
                            AVG(depth) AS avgdepth, AVG(personalisation) AS avgpers
                       FROM {gradereport_coifish_feedback}
                      WHERE userid = :uid",
                    ['uid' => $uid]
                );
            }

            // Grading turnaround across courses.
            [$insqlc, $inparamsc] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
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
                    AND ag.timemodified > asub.timemodified",
                array_merge(['uid' => $uid], $inparamsc)
            );
            $avgturnarounddays = $turnaround > 0 ? round($turnaround / 86400, 1) : null;

            // Interventions.
            $totalintv = 0;
            $intvimproved = 0;
            if ($hasintv) {
                $totalintv = (int)$DB->count_records_sql(
                    "SELECT COUNT(*) FROM {gradereport_coifish_intv}
                      WHERE teacherid = :uid",
                    ['uid' => $uid]
                );
                if ($totalintv > 0) {
                    $intvimproved = (int)$DB->count_records_sql(
                        "SELECT COUNT(DISTINCT i.id)
                           FROM {gradereport_coifish_intv} i
                           JOIN {gradereport_coifish_intv_stu} s ON s.interventionid = i.id
                           JOIN {gradereport_coifish_intv_out} o ON o.intvstudentid = s.id
                          WHERE i.teacherid = :uid AND o.outcome = 'improved'",
                        ['uid' => $uid]
                    );
                }
            }
            $intveffectiveness = $totalintv > 0 ? round(($intvimproved / $totalintv) * 100) : null;

            // Forum engagement.
            $totalweeks = 0;
            $totalposts = 0;
            foreach ($courses as $course) {
                $start = $course->startdate ?: ($now - 120 * 86400);
                $weeks = max(1, ($now - $start) / (7 * 86400));
                $posts = (int)$DB->count_records_sql(
                    "SELECT COUNT(fp.id)
                       FROM {forum_posts} fp
                       JOIN {forum_discussions} fd ON fd.id = fp.discussion
                      WHERE fd.course = :cid AND fp.userid = :uid",
                    ['cid' => $course->id, 'uid' => $uid]
                );
                $totalweeks += $weeks;
                $totalposts += $posts;
            }
            $avgforumpostspw = $totalweeks > 0 ? round($totalposts / $totalweeks, 1) : null;

            // Student outcome trends.
            [$tr3insql, $tr3params] = \local_coifish\filter_helper::get_teacher_role_sql('tr3');
            $studentgrades = $DB->get_records_sql(
                "SELECT cs.courseid, AVG(cs.finalgrade) AS avggrade, cs.courseenddate
                   FROM {local_coifish_course_snapshot} cs
                   JOIN {role_assignments} ra ON ra.userid = :uid
                   JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
                        AND ctx.instanceid = cs.courseid
                  WHERE ra.roleid $tr3insql
               GROUP BY cs.courseid, cs.courseenddate
               ORDER BY cs.courseenddate ASC",
                array_merge(['uid' => $uid, 'ctxlevel' => CONTEXT_COURSE], $tr3params)
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
            $studentgradetrend = self::compute_trend(array_values($gradevalues));

            // Estimate time spent on activities. The daily cron looks back
            // 120 days so the log scan stays small and the smoothed average
            // still reflects recent (vs all-time) teaching activity.
            $hours = self::estimate_activity_hours($uid, $courseids, $now - 120 * 86400, $now);

            // Compute strengths and focus areas.
            $dimensions = [
                'feedback_quality' => $fb->avgquality ?? 50,
                'feedback_coverage' => $fb->avgcoverage ?? 50,
                'feedback_depth' => $fb->avgdepth ?? 50,
                'feedback_personalisation' => $fb->avgpers ?? 50,
                'grading_turnaround' => $avgturnarounddays !== null
                    ? max(0, min(100, round((7 - $avgturnarounddays) / 7 * 100)))
                    : 50,
                'intervention_effectiveness' => $intveffectiveness ?? 50,
                'forum_engagement' => $avgforumpostspw !== null
                    ? min(100, round($avgforumpostspw / 3.0 * 100))
                    : 50,
                'student_outcomes' => $avgstudentgrade !== null
                    ? min(100, round($avgstudentgrade))
                    : 50,
            ];
            $sf = self::compute_strengths_and_focus($dimensions);

            // Upsert.
            $record = [
                'userid' => $uid,
                'coursecount' => $coursecount,
                'avgfeedbackquality' => $fb ? round($fb->avgquality) : null,
                'avgcoverage' => $fb ? round($fb->avgcoverage) : null,
                'avgdepth' => $fb ? round($fb->avgdepth) : null,
                'avgpersonalisation' => $fb ? round($fb->avgpers) : null,
                'avgturnarounddays' => $avgturnarounddays,
                'totalinterventions' => $totalintv,
                'interventionsimproved' => $intvimproved,
                'interventioneffectiveness' => $intveffectiveness,
                'avgforumpostspw' => $avgforumpostspw,
                'avgstudentgrade' => $avgstudentgrade,
                'studentgradetrend' => $studentgradetrend,
                'hours_marking' => $hours['marking'],
                'hours_communication' => $hours['communication'],
                'hours_livesessions' => $hours['livesessions'],
                'hours_total' => $hours['total'],
                'strengths' => json_encode($sf['strengths']),
                'focusareas' => json_encode($sf['focusareas']),
                'timemodified' => $now,
            ];

            $existing = $DB->get_record('local_coifish_lecturer', ['userid' => $uid]);
            if ($existing) {
                $record['id'] = $existing->id;
                $DB->update_record('local_coifish_lecturer', (object)$record);
            } else {
                $DB->insert_record('local_coifish_lecturer', (object)$record);
            }

            // Also write a snapshot for the current ISO week so the trend
            // visualisation on the lecturer profile has the latest data point.
            // Backfill of older weeks is handled by a separate task.
            [$wstart, $wend] = \local_coifish\lecturer_period_snapshot::week_bounds($now);
            \local_coifish\lecturer_period_snapshot::upsert($uid, $wstart, $wend);
        }
    }

    /**
     * Read the most recent N weekly snapshots for one lecturer, oldest-first.
     * Single indexed query against the per-period snapshot table — no log
     * scan, no per-week computation.
     *
     * @param int $userid Lecturer user ID.
     * @param int $weeks Number of recent weeks to return (default 26).
     * @return array Ordered array of snapshot row stdClass objects.
     */
    public static function get_lecturer_period_snapshots(int $userid, int $weeks = 26): array {
        global $DB;
        $weeks = max(1, min(520, $weeks));
        $now = time();
        $fromts = $now - ($weeks + 1) * 7 * 86400;
        return array_values($DB->get_records_select(
            'local_coifish_lecturer_period_snapshot',
            'userid = :uid AND periodstart >= :fts',
            ['uid' => $userid, 'fts' => $fromts],
            'periodstart ASC',
            '*',
            0,
            $weeks
        ));
    }

    /** @var int Maximum gap in seconds between events to be considered the same session. */
    protected const SESSION_GAP = 1800; // 30 minutes.

    /** @var int Minimum session duration in seconds (prevents single-click being counted). */
    protected const MIN_SESSION = 60; // 1 minute.

    /**
     * Estimate hours spent on marking, communication, and live sessions.
     *
     * Uses logstore event timestamps grouped into sessions. Events within 30 minutes
     * of each other are considered the same session; gaps larger than that start a new session.
     *
     * @param int $uid Lecturer user ID.
     * @param array $courseids Course IDs the lecturer teaches.
     * @param int $timefrom Lower bound on event timestamps (0 = no lower bound).
     *                      Critical for {logstore_standard_log} performance: pass the
     *                      smallest course-startdate in $courseids, or the daily-task
     *                      lookback window, to avoid full-history scans.
     * @param int $timeto Upper bound on event timestamps (0 = use time()).
     * @return array ['marking' => float, 'communication' => float, 'livesessions' => float, 'total' => float]
     */
    public static function estimate_activity_hours(int $uid, array $courseids, int $timefrom, int $timeto = 0): array {
        global $DB;

        if (empty($courseids)) {
            return ['marking' => 0, 'communication' => 0, 'livesessions' => 0, 'total' => 0];
        }

        if ($timeto <= 0) {
            $timeto = time();
        }

        [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'crs');

        // Time bounds applied to every logstore_standard_log query below — without
        // them a production-scale log table can take seconds to scan per call.
        $timeclause = '';
        $timeparams = [];
        if ($timefrom > 0) {
            $timeclause .= ' AND timecreated >= :tfrom';
            $timeparams['tfrom'] = $timefrom;
        }
        $timeclause .= ' AND timecreated <= :tto';
        $timeparams['tto'] = $timeto;

        // 1. Marking & feedback events.
        $markingevents = $DB->get_fieldset_sql(
            "SELECT timecreated
               FROM {logstore_standard_log}
              WHERE userid = :uid AND courseid $insql
                AND (
                    (action = 'graded')
                    OR (component = 'gradereport_grader' AND action = 'viewed')
                    OR (component = 'mod_assign' AND action = 'viewed'
                        AND target IN ('grading_table', 'grading_form', 'submission'))
                    OR (component = 'local_unifiedgrader')
                    OR (component = 'assignfeedback_comments' AND action = 'created')
                )
                $timeclause
           ORDER BY timecreated ASC",
            array_merge(['uid' => $uid], $inparams, $timeparams)
        );

        // Also include unified grader annotation timestamps if available (same time window).
        // Moodle DML requires each named placeholder to appear exactly once per SQL
        // statement, so each UNION branch gets its own :tfromN / :ttoN names.
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_unifiedgrader_annot')) {
            $ugparams = ['uid' => $uid, 'uid2' => $uid, 'uid3' => $uid];
            $build = function (string $column, string $suffix) use ($timefrom, $timeto, &$ugparams): string {
                $clause = '';
                if ($timefrom > 0) {
                    $clause .= " AND $column >= :tfrom$suffix";
                    $ugparams["tfrom$suffix"] = $timefrom;
                }
                $clause .= " AND $column <= :tto$suffix";
                $ugparams["tto$suffix"] = $timeto;
                return $clause;
            };
            $cl1 = $build('timecreated', '1');
            $cl2 = $build('timemodified', '2');
            $cl3 = $build('timecreated', '3');
            $ugannot = $DB->get_fieldset_sql(
                "SELECT timecreated FROM {local_unifiedgrader_annot} WHERE authorid = :uid $cl1
                 UNION ALL
                 SELECT timemodified FROM {local_unifiedgrader_annot}
                       WHERE authorid = :uid2 AND timemodified > 0 $cl2
                 UNION ALL
                 SELECT timecreated FROM {local_unifiedgrader_scomm} WHERE authorid = :uid3 $cl3
                 ORDER BY 1 ASC",
                $ugparams
            );
            $markingevents = array_merge($markingevents, $ugannot);
            sort($markingevents);
        }

        // 2. Communication events.
        $commevents = $DB->get_fieldset_sql(
            "SELECT timecreated
               FROM {logstore_standard_log}
              WHERE userid = :uid AND courseid $insql
                AND (
                    (component = 'core' AND target = 'message' AND action = 'sent')
                    OR (component LIKE 'local_satsmail%' AND action = 'sent')
                    OR (component = 'mod_forum' AND action = 'created' AND target IN ('post', 'discussion'))
                    OR (component = 'core' AND target = 'course_module' AND action = 'viewed'
                        AND objecttable = 'forum')
                )
                $timeclause
           ORDER BY timecreated ASC",
            array_merge(['uid' => $uid], $inparams, $timeparams)
        );

        // 3. Live session events (BigBlueButton).
        // Try recording durations first, fall back to log-based session estimation.
        $livesessionhours = 0.0;
        if ($dbman->table_exists('bigbluebuttonbn_recordings')) {
            $recordings = $DB->get_records_sql(
                "SELECT r.id, r.importeddata
                   FROM {bigbluebuttonbn_recordings} r
                   JOIN {bigbluebuttonbn} b ON b.id = r.bigbluebuttonbnid
                  WHERE b.course $insql AND r.status > 0",
                $inparams
            );
            foreach ($recordings as $rec) {
                if (!empty($rec->importeddata)) {
                    $data = json_decode($rec->importeddata, true);
                    // BBB stores duration in milliseconds or seconds depending on version.
                    $duration = $data['duration'] ?? $data['playback']['duration'] ?? 0;
                    if ($duration > 86400) {
                        $duration /= 1000; // Convert from milliseconds.
                    }
                    $livesessionhours += $duration / 3600;
                }
            }
        }
        // Fall back to log-based estimation if no recordings found.
        if ($livesessionhours == 0 && $dbman->table_exists('bigbluebuttonbn_logs')) {
            $bbbevents = $DB->get_fieldset_sql(
                "SELECT l.timecreated
                   FROM {bigbluebuttonbn_logs} l
                   JOIN {bigbluebuttonbn} b ON b.id = l.bigbluebuttonbnid
                  WHERE l.userid = :uid AND b.course $insql
               ORDER BY l.timecreated ASC",
                array_merge(['uid' => $uid], $inparams)
            );
            $livesessionhours = self::compute_session_hours($bbbevents);
        }
        $livesessionhours = round($livesessionhours, 1);

        // Apply preparation multiplier to live session hours.
        $prepmultiplier = (int)(get_config('local_coifish', 'prep_multiplier') ?? 2);
        $prephours = round($livesessionhours * $prepmultiplier, 1);
        $livesessiontotal = round($livesessionhours + $prephours, 1);

        $markinghours = self::compute_session_hours($markingevents);
        $commhours = self::compute_session_hours($commevents);
        $total = round($markinghours + $commhours + $livesessiontotal, 1);

        return [
            'marking' => $markinghours,
            'communication' => $commhours,
            'livesessions' => $livesessiontotal,
            'total' => $total,
        ];
    }

    /**
     * Compute total hours from ordered event timestamps using session gap detection.
     *
     * Events within SESSION_GAP seconds of each other are grouped into a session.
     * Each session's duration is (last_event - first_event), clamped to a minimum.
     * Single events (no subsequent event within the gap) count as MIN_SESSION.
     *
     * @param array $timestamps Ordered Unix timestamps.
     * @return float Total hours.
     */
    protected static function compute_session_hours(array $timestamps): float {
        if (empty($timestamps)) {
            return 0.0;
        }

        $totalseconds = 0;
        $sessionstart = (int)$timestamps[0];
        $prev = $sessionstart;

        for ($i = 1; $i < count($timestamps); $i++) {
            $current = (int)$timestamps[$i];
            if ($current - $prev > self::SESSION_GAP) {
                // End of session — add its duration.
                $duration = $prev - $sessionstart;
                $totalseconds += max($duration, self::MIN_SESSION);
                $sessionstart = $current;
            }
            $prev = $current;
        }

        // Final session.
        $duration = $prev - $sessionstart;
        $totalseconds += max($duration, self::MIN_SESSION);

        return round($totalseconds / 3600, 1);
    }

    /**
     * Format a lecturer profile record for template rendering.
     *
     * @param object $record DB record.
     * @param object $user User record.
     * @param bool $isself Whether the viewer is the lecturer.
     * @return array Formatted data.
     */
    protected static function format_profile(object $record, object $user, bool $isself): array {
        $component = 'local_coifish';
        $strengths = json_decode($record->strengths ?: '[]', true);
        $focusareas = json_decode($record->focusareas ?: '[]', true);

        $strengthlabels = [];
        foreach ($strengths as $key) {
            $strkey = 'lecturer_strength_' . $key;
            if (get_string_manager()->string_exists($strkey, $component)) {
                $strengthlabels[] = [
                    'key' => $key,
                    'label' => get_string('lecturer_dim_' . $key, $component),
                    'text' => get_string($strkey, $component),
                ];
            }
        }

        $focuslabels = [];
        foreach ($focusareas as $key) {
            $strkey = 'lecturer_focus_' . $key;
            if (get_string_manager()->string_exists($strkey, $component)) {
                $focuslabels[] = [
                    'key' => $key,
                    'label' => get_string('lecturer_dim_' . $key, $component),
                    'text' => get_string($strkey, $component),
                ];
            }
        }

        return [
            'hasprofile' => true,
            'userid' => (int)$record->userid,
            'fullname' => fullname($user),
            'email' => $user->email,
            'isself' => $isself,
            'coursecount' => (int)$record->coursecount,
            'avgfeedbackquality' => $record->avgfeedbackquality,
            'avgcoverage' => $record->avgcoverage,
            'avgdepth' => $record->avgdepth,
            'avgpersonalisation' => $record->avgpersonalisation,
            'avgturnarounddays' => $record->avgturnarounddays,
            'totalinterventions' => (int)$record->totalinterventions,
            'interventionsimproved' => (int)$record->interventionsimproved,
            'interventioneffectiveness' => $record->interventioneffectiveness,
            'avgforumpostspw' => $record->avgforumpostspw,
            'avgstudentgrade' => $record->avgstudentgrade !== null ? round($record->avgstudentgrade, 1) : null,
            'studentgradetrend' => $record->studentgradetrend,
            'studentgradetrendlabel' => get_string('trajectory_' . $record->studentgradetrend, $component),
            'hours_marking' => (float)$record->hours_marking,
            'hours_communication' => (float)$record->hours_communication,
            'hours_livesessions' => (float)$record->hours_livesessions,
            'hours_total' => (float)$record->hours_total,
            'hashours' => ((float)$record->hours_total > 0),
            'strengths' => $strengthlabels,
            'hasstrengths' => !empty($strengthlabels),
            'focusareas' => $focuslabels,
            'hasfocusareas' => !empty($focuslabels),
            'viewurl' => (new \moodle_url('/local/coifish/lecturerprofile.php', [
                'userid' => $record->userid,
            ]))->out(false),
        ];
    }

    /**
     * Compute strengths (top 3) and focus areas (bottom 3) from dimension scores.
     *
     * @param array $dimensions Associative array of dimension_key => score (0-100).
     * @return array ['strengths' => string[], 'focusareas' => string[]]
     */
    protected static function compute_strengths_and_focus(array $dimensions): array {
        arsort($dimensions);
        $keys = array_keys($dimensions);
        $strengths = array_slice($keys, 0, 3);

        asort($dimensions);
        $keys = array_keys($dimensions);
        $focusareas = array_slice($keys, 0, 3);

        // Don't list something as both a strength and a focus area.
        $focusareas = array_diff($focusareas, $strengths);

        return [
            'strengths' => array_values($strengths),
            'focusareas' => array_values($focusareas),
        ];
    }

    /**
     * Compute a trend from ordered values using linear regression.
     *
     * @param array $values Numeric values in order.
     * @return string 'improving', 'declining', 'stable', or 'unknown'.
     */
    protected static function compute_trend(array $values): string {
        if (count($values) < 2) {
            return 'unknown';
        }
        $n = count($values);
        $sumx = $sumy = $sumxy = $sumx2 = 0;
        for ($i = 0; $i < $n; $i++) {
            $sumx += $i;
            $sumy += $values[$i];
            $sumxy += $i * $values[$i];
            $sumx2 += $i * $i;
        }
        $denom = ($n * $sumx2 - $sumx * $sumx);
        if ($denom == 0) {
            return 'stable';
        }
        $slope = ($n * $sumxy - $sumx * $sumy) / $denom;
        $mean = $sumy / $n;
        $normslope = $mean > 0 ? ($slope / $mean) * 100 : 0;
        if ($normslope > 5) {
            return 'improving';
        } else if ($normslope < -5) {
            return 'declining';
        }
        return 'stable';
    }
}

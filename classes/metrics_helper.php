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
 * Shared metric calculation helpers used by both the post-course snapshot
 * task and the in-progress active-snapshot task.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coifish;

/**
 * Static helpers for per-student per-course metric capture and term resolution.
 *
 * The metric calculation is the source of truth for both in-progress (active)
 * and final (course history) snapshots, so the two stay aligned.
 */
class metrics_helper {
    /**
     * Capture a student's metrics for a course: grade so far (null if no grade
     * recorded yet), engagement, social, self-regulation, feedback review.
     *
     * The grade field is null when the student has no grade_grades row for the
     * course item, when finalgrade is null, or when no course grade item exists
     * yet. In-progress callers should still persist these rows; post-course
     * callers should treat grade === null as "did not participate" and skip.
     *
     * When $endtime > 0, time-based queries (log events, forum posts, feedback
     * views, grade checks) are clamped at that timestamp so post-course activity
     * — students browsing closed course content months later — does not skew the
     * frozen snapshot used for longitudinal aggregation.
     *
     * @param int $courseid Course ID.
     * @param int $userid Student user ID.
     * @param object|null $courseitem The course grade item, or null if absent.
     * @param int $endtime Optional upper bound on timestamps (0 = no bound).
     * @return array ['grade' => float|null, 'engagement', 'social', 'selfregulation', 'feedbackpct']
     */
    public static function capture_student_metrics(
        int $courseid,
        int $userid,
        ?object $courseitem,
        int $endtime = 0
    ): array {
        global $DB;

        $grade = null;
        if ($courseitem && (float)$courseitem->grademax > 0) {
            $gg = $DB->get_record('grade_grades', [
                'itemid' => $courseitem->id,
                'userid' => $userid,
            ]);
            if ($gg && $gg->finalgrade !== null) {
                $grade = round(((float)$gg->finalgrade / (float)$courseitem->grademax) * 100, 2);
            }
        }

        $timeclause = $endtime > 0 ? ' AND l.timecreated <= :endtime' : '';
        $postclause = $endtime > 0 ? ' AND fp.created <= :endtime' : '';
        $endparams = $endtime > 0 ? ['endtime' => $endtime] : [];

        // Engagement: distinct activities viewed.
        // Drop/keep-aware so optional assignments/quizzes don't inflate the denominator
        // and skew the longitudinal engagement signal downward for students who legitimately
        // skipped optional work.
        $totalactivities = \gradereport_coifish\report::get_expected_activity_count($courseid);
        $engaged = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT l.contextinstanceid)
               FROM {logstore_standard_log} l
              WHERE l.courseid = :cid AND l.userid = :uid
                AND l.action = 'viewed' AND l.target = 'course_module'" . $timeclause,
            array_merge(['cid' => $courseid, 'uid' => $userid], $endparams)
        );
        $engagement = $totalactivities > 0 ? min(100, round(($engaged / $totalactivities) * 100)) : null;

        // Social presence: group-aware breadth + post-volume composite.
        $alldiscussions = $DB->get_records_sql(
            "SELECT fd.id, fd.groupid, cm.groupmode
               FROM {forum_discussions} fd
               JOIN {forum} f ON f.id = fd.forum
               JOIN {course_modules} cm ON cm.instance = f.id AND cm.course = :cid
               JOIN {modules} m ON m.id = cm.module AND m.name = 'forum'
              WHERE fd.course = :cid2",
            ['cid' => $courseid, 'cid2' => $courseid]
        );
        $usergroups = groups_get_user_groups($courseid, $userid);
        $mygroupids = $usergroups[0] ?? [];
        $visiblediscussions = 0;
        foreach ($alldiscussions as $disc) {
            if ((int)$disc->groupmode === SEPARATEGROUPS) {
                if ((int)$disc->groupid === -1 || in_array((int)$disc->groupid, $mygroupids)) {
                    $visiblediscussions++;
                }
            } else {
                $visiblediscussions++;
            }
        }
        $threads = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT fd.id)
               FROM {forum_posts} fp
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE fd.course = :cid AND fp.userid = :uid" . $postclause,
            array_merge(['cid' => $courseid, 'uid' => $userid], $endparams)
        );
        $postcount = (int)$DB->count_records_sql(
            "SELECT COUNT(fp.id)
               FROM {forum_posts} fp
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE fd.course = :cid AND fp.userid = :uid" . $postclause,
            array_merge(['cid' => $courseid, 'uid' => $userid], $endparams)
        );
        $breadth = $visiblediscussions > 0
            ? min(100, round(($threads / $visiblediscussions) * 200))
            : ($threads > 0 ? 50 : 0);
        $volume = min(100, round($postcount / 5 * 100));
        $social = ($breadth > 0 || $volume > 0)
            ? round($breadth * 0.6 + $volume * 0.4)
            : null;

        // Feedback review percentage.
        $feedbacktimeclause = $endtime > 0 ? ' AND ag.timemodified <= :endtime' : '';
        $totalfeedback = (int)$DB->count_records_sql(
            "SELECT COUNT(ag.id)
               FROM {assign_grades} ag
               JOIN {assign} a ON a.id = ag.assignment
              WHERE a.course = :cid AND ag.userid = :uid AND ag.grade >= 0" . $feedbacktimeclause,
            array_merge(['cid' => $courseid, 'uid' => $userid], $endparams)
        );
        $viewedfeedback = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT l.contextinstanceid)
               FROM {logstore_standard_log} l
              WHERE l.userid = :uid AND l.courseid = :cid
                AND l.eventname IN (:ev1, :ev2)" . $timeclause,
            array_merge([
                'uid' => $userid, 'cid' => $courseid,
                'ev1' => '\\mod_assign\\event\\feedback_viewed',
                'ev2' => '\\mod_assign\\event\\submission_status_viewed',
            ], $endparams)
        );
        $feedbackpct = $totalfeedback > 0 ? min(100, round(($viewedfeedback / $totalfeedback) * 100)) : null;

        // Self-regulation approximation: grade-check frequency.
        $gradechecks = (int)$DB->count_records_sql(
            "SELECT COUNT(*)
               FROM {logstore_standard_log} l
              WHERE l.userid = :uid AND l.courseid = :cid
                AND l.eventname = :ev" . $timeclause,
            array_merge([
                'uid' => $userid, 'cid' => $courseid,
                'ev' => '\\gradereport_user\\event\\grade_report_viewed',
            ], $endparams)
        );
        $selfregulation = min(100, round($gradechecks * 10));

        return [
            'grade' => $grade,
            'engagement' => $engagement,
            'social' => $social,
            'selfregulation' => $selfregulation,
            'feedbackpct' => $feedbackpct,
        ];
    }

    /**
     * Intervention summary pulled from gradereport_coifish tables (if present).
     *
     * @param int $courseid Course ID.
     * @param int $userid Student user ID.
     * @return array ['count' => int, 'improved' => int]
     */
    public static function get_intervention_summary(int $courseid, int $userid): array {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('gradereport_coifish_intv')) {
            return ['count' => 0, 'improved' => 0];
        }

        $count = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT i.id)
               FROM {gradereport_coifish_intv} i
               JOIN {gradereport_coifish_intv_stu} s ON s.interventionid = i.id
              WHERE i.courseid = :cid AND s.studentid = :uid",
            ['cid' => $courseid, 'uid' => $userid]
        );

        $improved = 0;
        if ($count > 0) {
            $improved = (int)$DB->count_records_sql(
                "SELECT COUNT(DISTINCT i.id)
                   FROM {gradereport_coifish_intv} i
                   JOIN {gradereport_coifish_intv_stu} s ON s.interventionid = i.id
                   JOIN {gradereport_coifish_intv_out} o ON o.intvstudentid = s.id
                  WHERE i.courseid = :cid AND s.studentid = :uid AND o.outcome = 'improved'",
                ['cid' => $courseid, 'uid' => $userid]
            );
        }

        return ['count' => $count, 'improved' => $improved];
    }

    /**
     * Resolve a term label for a course, per the term_source admin setting.
     *
     * Returns an empty string if no term can be resolved.
     *
     * @param object $course Course record (must include id, fullname, category).
     * @return string Term label suitable for display.
     */
    public static function resolve_term_label(object $course): string {
        $source = get_config('local_coifish', 'term_source') ?: 'category';

        if ($source === 'fullname') {
            // No transformation: rely on the course fullname already containing term info.
            return '';
        }

        if ($source === 'customfield') {
            $shortname = get_config('local_coifish', 'term_customfield_shortname');
            if (!$shortname) {
                return '';
            }
            return self::get_customfield_value((int)$course->id, $shortname);
        }

        // Default: category name.
        if (empty($course->category)) {
            return '';
        }
        $cat = \core_course_category::get((int)$course->category, IGNORE_MISSING);
        return $cat ? format_string($cat->name) : '';
    }

    /**
     * Read a course customfield value by shortname.
     *
     * @param int $courseid
     * @param string $shortname
     * @return string Value or empty string.
     */
    protected static function get_customfield_value(int $courseid, string $shortname): string {
        global $DB;

        $field = $DB->get_record_sql(
            "SELECT cfd.value
               FROM {customfield_field} cff
               JOIN {customfield_data} cfd ON cfd.fieldid = cff.id
              WHERE cff.shortname = :sn AND cfd.instanceid = :cid",
            ['sn' => $shortname, 'cid' => $courseid]
        );
        return $field ? format_string($field->value) : '';
    }

    /**
     * URL for the gradereport_coifish report for a course, optionally
     * filtered to a single user.
     *
     * @param int $courseid
     * @param int|null $userid Optional student user ID for the user-specific drill-in.
     * @return \moodle_url
     */
    public static function coifish_report_url(int $courseid, ?int $userid = null): \moodle_url {
        $params = ['id' => $courseid];
        if ($userid !== null) {
            $params['userid'] = $userid;
        }
        return new \moodle_url('/grade/report/coifish/index.php', $params);
    }
}

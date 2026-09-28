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
     * When $starttime > 0, the same queries are bounded below — vital for the
     * logstore_standard_log queries which can otherwise scan the full log
     * history on production sites. Callers should pass the course startdate
     * (or course creation time) so we only look at activity within the course's
     * actual lifetime.
     *
     * @param int $courseid Course ID.
     * @param int $userid Student user ID.
     * @param object|null $courseitem The course grade item, or null if absent.
     * @param int $endtime Optional upper bound on timestamps (0 = no bound).
     * @param int $starttime Optional lower bound on timestamps (0 = no bound).
     * @param int|null $totalactivities Pre-computed expected activity count for the course, or null to compute it here.
     * @param array|null $discussions Pre-fetched course discussion list, or null to fetch it here.
     * @return array ['grade' => float|null, 'engagement', 'social', 'socialversion', 'selfregulation', 'feedbackpct']
     */
    public static function capture_student_metrics(
        int $courseid,
        int $userid,
        ?object $courseitem,
        int $endtime = 0,
        int $starttime = 0,
        ?int $totalactivities = null,
        ?array $discussions = null
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

        $timeclause = '';
        $endparams = [];
        if ($starttime > 0) {
            $timeclause .= ' AND l.timecreated >= :starttime';
            $endparams['starttime'] = $starttime;
        }
        if ($endtime > 0) {
            $timeclause .= ' AND l.timecreated <= :endtime';
            $endparams['endtime'] = $endtime;
        }

        // Engagement: distinct activities viewed.
        // Drop/keep-aware so optional assignments/quizzes don't inflate the denominator
        // and skew the longitudinal engagement signal downward for students who legitimately
        // skipped optional work.
        // Per-course-invariant; the caller (e.g. the batch snapshot task) may
        // pass it in to avoid recomputing it for every student in the course.
        $totalactivities = $totalactivities
            ?? \gradereport_coifish\report::get_expected_activity_count($courseid);
        $engaged = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT l.contextinstanceid)
               FROM {logstore_standard_log} l
              WHERE l.courseid = :cid AND l.userid = :uid
                AND l.action = 'viewed' AND l.target = 'course_module'" . $timeclause,
            array_merge(['cid' => $courseid, 'uid' => $userid], $endparams)
        );
        $engagement = $totalactivities > 0 ? min(100, round(($engaged / $totalactivities) * 100)) : null;

        // Social presence: forum participation blended with live-session
        // (BigBlueButton) interaction — see capture_social().
        $social = self::capture_social($courseid, $userid, $endtime, $starttime, $discussions);

        // Feedback review percentage.
        $feedbacktimeclause = '';
        if ($starttime > 0) {
            $feedbacktimeclause .= ' AND ag.timemodified >= :starttime';
        }
        if ($endtime > 0) {
            $feedbacktimeclause .= ' AND ag.timemodified <= :endtime';
        }
        $totalfeedback = (int)$DB->count_records_sql(
            "SELECT COUNT(ag.id)
               FROM {assign_grades} ag
               JOIN {assign} a ON a.id = ag.assignment
              WHERE a.course = :cid AND ag.userid = :uid AND ag.grade >= 0" . $feedbacktimeclause,
            array_merge(['cid' => $courseid, 'uid' => $userid], $endparams)
        );
        [$evsql, $evparams] = \gradereport_coifish\report::get_feedback_view_event_sql('fve');
        $viewedfeedback = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT l.contextinstanceid)
               FROM {logstore_standard_log} l
              WHERE l.userid = :uid AND l.courseid = :cid
                AND l.eventname $evsql" . $timeclause,
            array_merge([
                'uid' => $userid, 'cid' => $courseid,
            ], $evparams, $endparams)
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
            'socialversion' => self::get_social_version(),
            'selfregulation' => $selfregulation,
            'feedbackpct' => $feedbackpct,
        ];
    }

    /**
     * Version of the social-presence definition written with each snapshot:
     * gradereport_coifish's SOCIAL_METRIC_VERSION. Rows with an older (or 0,
     * legacy forum-only) version are recomputed by the recompute_social_snapshots task.
     *
     * @return int
     */
    public static function get_social_version(): int {
        return \gradereport_coifish\report::SOCIAL_METRIC_VERSION;
    }

    /**
     * A student's social-presence rate (0–100) for a course, or null when there
     * is nothing to measure (no forum activity and no live sessions open to them).
     *
     * Forum participation (group-aware discussion breadth and post volume) is
     * blended with live-session interaction using gradereport_coifish's single
     * definition ({@see \gradereport_coifish\report::blend_live_social()}), so the
     * longitudinal record matches the student's Community engagement widget.
     *
     * @param int $courseid Course ID.
     * @param int $userid Student user ID.
     * @param int $endtime Upper bound on timestamps (0 = now).
     * @param int $starttime Lower bound on forum post timestamps (0 = none).
     * @param array|null $discussions Pre-fetched course discussion list, or null to fetch it here.
     * @return int|null
     */
    public static function capture_social(
        int $courseid,
        int $userid,
        int $endtime = 0,
        int $starttime = 0,
        ?array $discussions = null
    ): ?int {
        global $DB;

        $postclause = '';
        $params = ['cid' => $courseid, 'uid' => $userid];
        if ($starttime > 0) {
            $postclause .= ' AND fp.created >= :starttime';
            $params['starttime'] = $starttime;
        }
        if ($endtime > 0) {
            $postclause .= ' AND fp.created <= :endtime';
            $params['endtime'] = $endtime;
        }

        // The discussion list is per-course-invariant, so the caller may pass it
        // in to avoid one forum query per student.
        $alldiscussions = $discussions ?? self::get_course_discussions($courseid);
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
        $posts = $DB->get_record_sql(
            "SELECT COUNT(DISTINCT fd.id) AS threads, COUNT(fp.id) AS posts
               FROM {forum_posts} fp
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
              WHERE fd.course = :cid AND fp.userid = :uid" . $postclause,
            $params
        );
        $threads = (int)($posts->threads ?? 0);
        $postcount = (int)($posts->posts ?? 0);
        $breadth = $visiblediscussions > 0
            ? min(100, round(($threads / $visiblediscussions) * 200))
            : ($threads > 0 ? 50 : 0);
        $volume = min(100, round($postcount / 5 * 100));
        $forumsocial = ($breadth > 0 || $volume > 0) ? (int)round($breadth * 0.6 + $volume * 0.4) : null;

        $analyser = \gradereport_coifish\live_sessions::for_course($courseid, $endtime);
        $live = $analyser->get_student($userid);
        if ($live['available'] <= 0) {
            return $forumsocial;
        }
        return \gradereport_coifish\report::blend_live_social(
            $courseid,
            $forumsocial ?? 0,
            $live,
            $analyser->get_intensity(),
            $visiblediscussions > 0 || $postcount > 0
        );
    }

    /**
     * Forum discussions in a course, with group context, for the social-presence
     * metric. Per-course-invariant, so batch callers fetch it once and pass it
     * into {@see capture_student_metrics()} rather than re-querying per student.
     *
     * @param int $courseid Course ID.
     * @return array Discussion rows (id, groupid, groupmode).
     */
    public static function get_course_discussions(int $courseid): array {
        global $DB;

        return $DB->get_records_sql(
            "SELECT fd.id, fd.groupid, cm.groupmode
               FROM {forum_discussions} fd
               JOIN {forum} f ON f.id = fd.forum
               JOIN {course_modules} cm ON cm.instance = f.id AND cm.course = :cid
               JOIN {modules} m ON m.id = cm.module AND m.name = 'forum'
              WHERE fd.course = :cid2",
            ['cid' => $courseid, 'cid2' => $courseid]
        );
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

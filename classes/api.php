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
 * Public API for the CoIFish longitudinal profile plugin.
 *
 * This class provides the interface that gradereport_coifish calls to
 * retrieve student profiles, and that external functions wrap for SIS access.
 * It enforces privacy controls based on the configured detail level and
 * the caller's capabilities.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coifish;

/**
 * API for retrieving longitudinal student profiles.
 */
class api {
    /**
     * Get a student's longitudinal profile for display in a course context.
     *
     * Respects the configured privacy level: teachers may only see patterns,
     * while programme coordinators see full detail.
     *
     * @param int $userid The student user ID.
     * @param int $courseid The course context (for capability checks). 0 for system-level.
     * @return array Profile data formatted for display, or empty array if unavailable.
     */
    public static function get_student_profile(int $userid, int $courseid = 0): array {
        global $DB;

        $enabled = get_config('local_coifish', 'profile_enabled');
        if ($enabled === '0') {
            return [];
        }

        $profile = $DB->get_record('local_coifish_profile', ['userid' => $userid]);
        if (!$profile) {
            return [];
        }

        $detaillevel = self::get_detail_level($courseid);
        return self::format_profile($profile, $detaillevel);
    }

    /**
     * Get students at risk for early warning in a course.
     *
     * Returns profiles for enrolled students who have moderate or high risk levels.
     *
     * @param int $courseid The course ID.
     * @return array Array of risk profile summaries.
     */
    public static function get_early_warnings(int $courseid): array {
        global $DB;

        $enabled = get_config('local_coifish', 'profile_enabled');
        if ($enabled === '0') {
            return [];
        }

        $context = \context_course::instance($courseid);
        $students = get_enrolled_users($context, 'moodle/course:isincompletionreports', 0, 'u.id');
        if (empty($students)) {
            return [];
        }

        $studentids = array_keys($students);
        [$insql, $inparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED);

        $profiles = $DB->get_records_select(
            'local_coifish_profile',
            "userid $insql AND risklevel IN ('moderate', 'high')",
            $inparams,
            'risklevel DESC, avggrade ASC'
        );

        $detaillevel = self::get_detail_level($courseid);
        $warnings = [];
        foreach ($profiles as $profile) {
            $user = $DB->get_record('user', ['id' => $profile->userid], 'id, firstname, lastname');
            if (!$user) {
                continue;
            }
            $formatted = self::format_profile($profile, $detaillevel);
            $formatted['userid'] = $profile->userid;
            $formatted['fullname'] = fullname($user);
            $formatted['viewurl'] = (new \moodle_url('/grade/report/coifish/index.php', [
                'id' => $courseid,
                'userid' => $profile->userid,
                'view' => 'insights',
            ]))->out(false);
            $warnings[] = $formatted;
        }
        return $warnings;
    }

    /**
     * Get all at-risk student profiles for the institution-wide risk overview.
     *
     * @param int $categoryid Course category filter (0 for all). Ignored in cohort mode.
     * @param string $risklevel Filter: 'all', 'moderate', 'high'.
     * @param array|null $studentids Explicit student ID filter (from cohort mode). Null = no filter.
     * @param string $enrolstatus Enrolment-status filter: 'all', 'current' (has an active enrolment
     *                            in a currently running visible course), or 'notenrolled' (none).
     * @return array Array of formatted profiles with user info.
     */
    public static function get_risk_overview(
        int $categoryid = 0,
        string $risklevel = 'all',
        ?array $studentids = null,
        string $enrolstatus = 'all'
    ): array {
        global $DB;

        $conditions = [];
        $params = [];

        if ($risklevel === 'high') {
            $conditions[] = "p.risklevel = 'high'";
        } else if ($risklevel === 'moderate') {
            $conditions[] = "p.risklevel = 'moderate'";
        } else {
            $conditions[] = "p.risklevel IN ('moderate', 'high')";
        }

        // Explicit student ID filter (cohort mode).
        $extrajoin = '';
        if ($studentids !== null) {
            if (empty($studentids)) {
                return [];
            }
            [$insqlstu, $inparamsstu] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'stu');
            $conditions[] = "p.userid $insqlstu";
            $params = array_merge($params, $inparamsstu);
        } else if ($categoryid > 0) {
            // Category filter.
            $cat = \core_course_category::get($categoryid, IGNORE_MISSING);
            if (!$cat) {
                return [];
            }
            $catids = array_merge([$categoryid], $cat->get_all_children_ids());
            [$insql, $inparams] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, 'cat');
            $extrajoin = "JOIN {user_enrolments} ue ON ue.userid = p.userid
                          JOIN {enrol} e ON e.id = ue.enrolid
                          JOIN {course} c ON c.id = e.courseid AND c.category $insql";
            $params = array_merge($params, $inparams);
        }

        // Current-enrolment filter. Sources the truth from user_enrolments + course
        // directly so the filter works even before the active-snapshot task has run.
        if ($enrolstatus === 'current' || $enrolstatus === 'notenrolled') {
            [$cescopefrag, $cescopeparams] = \local_coifish\filter_helper::get_category_scope_sql('ce_c', 'ce_cat');
            $existssql = "EXISTS (
                SELECT 1
                  FROM {user_enrolments} ce_ue
                  JOIN {enrol} ce_e ON ce_e.id = ce_ue.enrolid
                  JOIN {course} ce_c ON ce_c.id = ce_e.courseid
                 WHERE ce_ue.userid = p.userid
                   AND ce_ue.status = 0
                   AND (ce_ue.timeend = 0 OR ce_ue.timeend > :ce_now1)
                   AND (ce_ue.timestart = 0 OR ce_ue.timestart <= :ce_now2)
                   AND ce_c.id != :ce_siteid
                   AND ce_c.visible = 1
                   AND (ce_c.enddate = 0 OR ce_c.enddate > :ce_now3)
                   $cescopefrag
            )";
            $conditions[] = ($enrolstatus === 'current') ? $existssql : "NOT $existssql";
            $now = time();
            $params['ce_now1'] = $now;
            $params['ce_now2'] = $now;
            $params['ce_now3'] = $now;
            $params['ce_siteid'] = SITEID;
            $params = array_merge($params, $cescopeparams);
        }

        $namefields = \core_user\fields::for_name()->get_sql('u')->selects;
        $where = implode(' AND ', $conditions);
        $sql = "SELECT DISTINCT p.* $namefields,
                       CASE p.risklevel WHEN 'high' THEN 1 WHEN 'moderate' THEN 2 ELSE 3 END AS risksort
                  FROM {local_coifish_profile} p
                  JOIN {user} u ON u.id = p.userid AND u.deleted = 0
                  $extrajoin
                 WHERE $where
              ORDER BY risksort, p.avggrade ASC";

        $records = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($records as $rec) {
            $profile = self::format_profile($rec, 'full');
            $profile['userid'] = (int)$rec->userid;
            $profile['fullname'] = fullname($rec);
            $profile['viewurl'] = (new \moodle_url('/local/coifish/studentprofile.php', [
                'userid' => $rec->userid,
            ]))->out(false);
            $result[] = $profile;
        }
        return $result;
    }

    /**
     * Get a student's course snapshot history (for full profile views).
     *
     * @param int $userid The student user ID.
     * @return array Ordered array of course snapshots.
     */
    public static function get_course_history(int $userid): array {
        global $DB;

        $snapshots = $DB->get_records_sql(
            "SELECT cs.*, c.fullname AS coursename, c.category AS coursecategory
               FROM {local_coifish_course_snapshot} cs
               JOIN {course} c ON c.id = cs.courseid
              WHERE cs.userid = :userid
           ORDER BY cs.courseenddate ASC",
            ['userid' => $userid]
        );

        $result = [];
        foreach ($snapshots as $snap) {
            $course = (object)['id' => $snap->courseid, 'category' => $snap->coursecategory, 'fullname' => $snap->coursename];
            $result[] = [
                'courseid' => (int)$snap->courseid,
                'coursename' => format_string($snap->coursename),
                'termlabel' => \local_coifish\metrics_helper::resolve_term_label($course),
                'viewurl' => \local_coifish\metrics_helper::coifish_report_url((int)$snap->courseid, $userid)->out(false),
                'finalgrade' => $snap->finalgrade !== null ? round($snap->finalgrade, 1) : null,
                'engagement' => (int)$snap->engagement,
                'social' => (int)$snap->social,
                'selfregulation' => (int)$snap->selfregulation,
                'feedbackpct' => (int)$snap->feedbackpct,
                'interventioncount' => (int)$snap->interventioncount,
                'interventionsimproved' => (int)$snap->interventionsimproved,
                'courseenddate' => (int)$snap->courseenddate,
            ];
        }
        return $result;
    }

    /**
     * Get a student's current (in-progress) course enrolments with cached metrics.
     *
     * Sourced from {local_coifish_active_snapshot}, which is refreshed daily by
     * the build_active_snapshots task and on demand from the drill-down page.
     * Filtered to courses that are still visible and currently running, in case
     * a snapshot lingers after the course has ended or been hidden.
     *
     * @param int $userid The student user ID.
     * @return array Ordered array of current enrolment rows.
     */
    public static function get_current_enrolments(int $userid): array {
        global $DB;

        $now = time();
        [$catfrag, $catparams] = \local_coifish\filter_helper::get_category_scope_sql('c');
        $rows = $DB->get_records_sql(
            "SELECT acs.*, c.fullname AS coursename, c.category AS coursecategory,
                    c.startdate AS coursestart, c.enddate AS courseend
               FROM {local_coifish_active_snapshot} acs
               JOIN {course} c ON c.id = acs.courseid
              WHERE acs.userid = :userid
                AND c.visible = 1
                AND (c.enddate = 0 OR c.enddate > :now)
                $catfrag
           ORDER BY c.startdate DESC, c.fullname ASC",
            array_merge(['userid' => $userid, 'now' => $now], $catparams)
        );

        $result = [];
        foreach ($rows as $row) {
            $course = (object)[
                'id' => $row->courseid,
                'category' => $row->coursecategory,
                'fullname' => $row->coursename,
            ];
            // Fall back to live term resolution if termlabel is stale or admin
            // changed the term_source setting since the snapshot was written.
            $termlabel = !empty($row->termlabel)
                ? $row->termlabel
                : \local_coifish\metrics_helper::resolve_term_label($course);
            $result[] = [
                'courseid' => (int)$row->courseid,
                'coursename' => format_string($row->coursename),
                'termlabel' => $termlabel,
                'viewurl' => \local_coifish\metrics_helper::coifish_report_url((int)$row->courseid, $userid)->out(false),
                'currentgrade' => $row->currentgrade !== null ? round($row->currentgrade, 1) : null,
                'engagement' => $row->engagement !== null ? (int)$row->engagement : null,
                'social' => $row->social !== null ? (int)$row->social : null,
                'selfregulation' => $row->selfregulation !== null ? (int)$row->selfregulation : null,
                'feedbackpct' => $row->feedbackpct !== null ? (int)$row->feedbackpct : null,
                'coursestartdate' => (int)$row->coursestart,
                'courseenddate' => (int)$row->courseend,
                'timecomputed' => (int)$row->timecomputed,
            ];
        }
        return $result;
    }

    /**
     * Latest timecomputed across all of a student's active snapshots.
     *
     * @param int $userid
     * @return int Unix timestamp, or 0 if no snapshots exist.
     */
    public static function get_current_enrolments_freshness(int $userid): int {
        global $DB;
        $val = $DB->get_field_sql(
            "SELECT MAX(timecomputed) FROM {local_coifish_active_snapshot} WHERE userid = :uid",
            ['uid' => $userid]
        );
        return (int)$val;
    }

    /**
     * Determine the detail level the current user is allowed to see.
     *
     * @param int $courseid Course context (0 for system).
     * @return string 'patterns', 'summary', or 'full'.
     */
    protected static function get_detail_level(int $courseid): string {
        // Programme coordinators with full profile capability always see full detail.
        if ($courseid > 0) {
            $context = \context_course::instance($courseid);
            if (has_capability('local/coifish:viewfullprofile', $context)) {
                return 'full';
            }
        }

        $systemcontext = \context_system::instance();
        if (has_capability('local/coifish:viewfullprofile', $systemcontext)) {
            return 'full';
        }

        // Course teachers get the configured level.
        return get_config('local_coifish', 'teacher_detail_level') ?: 'patterns';
    }

    /**
     * Format a profile record according to the allowed detail level.
     *
     * - 'patterns': Risk level, engagement pattern, risk factors. No grades.
     * - 'summary': Above plus trends and intervention response. No specific grades.
     * - 'full': Everything including average grades and course history.
     *
     * @param object $profile The profile DB record.
     * @param string $detaillevel The allowed detail level.
     * @return array Formatted profile data.
     */
    protected static function format_profile(object $profile, string $detaillevel): array {
        $component = 'local_coifish';
        $riskfactors = json_decode($profile->riskfactors ?: '[]', true);
        $riskfactorlabels = [];
        foreach ($riskfactors as $factor) {
            $key = 'risk_factor_' . $factor;
            if (get_string_manager()->string_exists($key, $component)) {
                $riskfactorlabels[] = get_string($key, $component);
            }
        }

        // Generate prescriptive recommendations based on risk factors and patterns.
        $recommendations = self::generate_recommendations($profile, $riskfactors);

        // Patterns level: minimal — risk indicators and engagement pattern only.
        $data = [
            'hasprofile' => true,
            'coursescompleted' => (int)$profile->coursescompleted,
            'risklevel' => $profile->risklevel,
            'isriskhigh' => $profile->risklevel === 'high',
            'isriskmoderate' => $profile->risklevel === 'moderate',
            'isrisklow' => $profile->risklevel === 'low',
            'risklabel' => get_string('profile_risk_' . $profile->risklevel, $component),
            'riskfactors' => $riskfactorlabels,
            'hasriskfactors' => !empty($riskfactorlabels),
            'engagementpattern' => $profile->engagementpattern,
            'engagementpatternlabel' => get_string('engagement_pattern_' . $profile->engagementpattern, $component),
            'recommendations' => $recommendations,
            'hasrecommendations' => !empty($recommendations),
        ];

        if ($detaillevel === 'patterns') {
            return $data;
        }

        // Summary level: add trends and intervention response.
        $data['gradetrend'] = $profile->gradetrend;
        $data['gradetrendlabel'] = get_string('trajectory_' . $profile->gradetrend, $component);
        $data['socialtrend'] = $profile->socialtrend;
        $data['socialtrendlabel'] = get_string('trajectory_' . $profile->socialtrend, $component);
        $data['selfregtrend'] = $profile->selfregtrend;
        $data['selfregtrendlabel'] = get_string('trajectory_' . $profile->selfregtrend, $component);
        $data['interventionresponse'] = $profile->interventionresponse;
        $data['interventionresponselabel'] = get_string(
            'intervention_response_' . $profile->interventionresponse,
            $component
        );
        $data['totalinterventions'] = (int)$profile->totalinterventions;

        if ($detaillevel === 'summary') {
            return $data;
        }

        // Full level: add numeric metrics.
        $data['avggrade'] = $profile->avggrade !== null ? round($profile->avggrade, 1) : null;
        $data['avgsocial'] = $profile->avgsocial;
        $data['avgselfregulation'] = $profile->avgselfregulation;
        $data['avgfeedbackpct'] = $profile->avgfeedbackpct;
        $data['interventionsimproved'] = (int)$profile->interventionsimproved;
        $data['coursesinprogress'] = (int)$profile->coursesinprogress;

        return $data;
    }

    /**
     * Generate prescriptive recommendations based on a student's longitudinal profile.
     *
     * Each recommendation includes an icon, severity, and actionable text grounded
     * in the CoI framework and intervention research.
     *
     * @param object $profile The profile DB record.
     * @param array $riskfactors Array of risk factor keys.
     * @return array Array of recommendation arrays for template rendering.
     */
    protected static function generate_recommendations(object $profile, array $riskfactors): array {
        $component = 'local_coifish';
        $recs = [];

        // With only one historical snapshot we cannot honestly claim a
        // cross-course pattern; pick softer single-course wording in that case.
        $issinglecourse = ((int)$profile->coursescompleted) < 2;

        // Engagement decline pattern. Engagement-decline classification needs
        // ≥2 snapshots in classify_engagement_pattern(), so the cross-course
        // framing is safe here.
        if ($profile->engagementpattern === 'declining' || in_array('engagement_decline', $riskfactors)) {
            $recs[] = [
                'icon' => 'calendar-check-o',
                'severity' => 'warning',
                'text' => get_string('rec_engagement_decline', $component),
            ];
        }

        // Social isolation.
        if (in_array('social_isolation', $riskfactors)) {
            $recs[] = [
                'icon' => 'users',
                'severity' => 'warning',
                'text' => get_string($issinglecourse ? 'rec_social_isolation_single' : 'rec_social_isolation', $component),
            ];
        }

        // Feedback neglect.
        if (in_array('feedback_neglect', $riskfactors)) {
            $recs[] = [
                'icon' => 'commenting-o',
                'severity' => 'warning',
                'text' => get_string($issinglecourse ? 'rec_feedback_neglect_single' : 'rec_feedback_neglect', $component),
            ];
        }

        // Unresponsive to interventions. Uses intervention count for tone,
        // not course count — a single failed intervention shouldn't claim
        // "previous interventions" in the plural.
        if (in_array('intervention_unresponsive', $riskfactors)) {
            $isfewinterventions = ((int)$profile->totalinterventions) < 2;
            $recs[] = [
                'icon' => 'refresh',
                'severity' => 'danger',
                'text' => get_string(
                    $isfewinterventions ? 'rec_intervention_unresponsive_single' : 'rec_intervention_unresponsive',
                    $component
                ),
            ];
        }

        // Grade decline.
        if (in_array('grade_decline', $riskfactors)) {
            $recs[] = [
                'icon' => 'arrow-down',
                'severity' => 'danger',
                'text' => get_string('rec_grade_decline', $component),
            ];
        }

        // Positive intervention response — reinforce what works.
        if ($profile->interventionresponse === 'positive') {
            $isfewinterventions = ((int)$profile->totalinterventions) < 2;
            $recs[] = [
                'icon' => 'thumbs-up',
                'severity' => 'success',
                'text' => get_string(
                    $isfewinterventions ? 'rec_intervention_positive_single' : 'rec_intervention_positive',
                    $component
                ),
            ];
        }

        // Late starter pattern.
        if ($profile->engagementpattern === 'growing') {
            $recs[] = [
                'icon' => 'clock-o',
                'severity' => 'info',
                'text' => get_string('rec_late_starter', $component),
            ];
        }

        // Irregular pattern.
        if ($profile->engagementpattern === 'irregular') {
            $recs[] = [
                'icon' => 'random',
                'severity' => 'warning',
                'text' => get_string('rec_irregular', $component),
            ];
        }

        return $recs;
    }
}

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
 * Scheduled task to build longitudinal student profiles from historical course data.
 *
 * Runs daily (default 3:00 AM) after the CoIFish grade report tasks (2:00/2:30 AM).
 * Processes completed and in-progress courses to build per-student profiles containing
 * engagement patterns, academic trajectories, intervention responses, and risk indicators.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coifish\task;

use core\task\scheduled_task;

/**
 * Build longitudinal student profiles.
 */
class build_profiles extends scheduled_task {
    /**
     * Return the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_build_profiles', 'local_coifish');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB;

        $enabled = get_config('local_coifish', 'profile_enabled');
        if ($enabled === '0') {
            return;
        }

        $mincourses = (int)get_config('local_coifish', 'min_courses') ?: 1;
        $now = time();

        // Step 1: Build course snapshots for completed courses that don't have one yet.
        $this->build_course_snapshots($now);

        // Step 2: Aggregate snapshots into longitudinal profiles.
        $this->aggregate_profiles($mincourses, $now);

        // Step 3: Build lecturer performance profiles.
        \local_coifish\lecturer_api::build_lecturer_profiles($now);
    }

    /**
     * Build per-student course snapshots for courses that have ended.
     *
     * A course is considered "ended" if its end date has passed, or if it has no end date
     * but started more than 6 months ago (likely completed).
     *
     * @param int $now Current timestamp.
     */
    protected function build_course_snapshots(int $now): void {
        global $DB;

        $sixmonthsago = $now - (180 * 86400);

        // Find courses that have ended and have the CoIFish grade report available.
        $courses = $DB->get_records_sql(
            "SELECT c.id, c.enddate, c.startdate
               FROM {course} c
              WHERE c.id != :siteid
                AND c.visible = 1
                AND (
                    (c.enddate > 0 AND c.enddate < :now)
                    OR (c.enddate = 0 AND c.startdate > 0 AND c.startdate < :sixmonths)
                )",
            ['siteid' => SITEID, 'now' => $now, 'sixmonths' => $sixmonthsago]
        );

        foreach ($courses as $course) {
            $this->snapshot_course($course->id, $course->enddate ?: $now, $now);
        }
    }

    /**
     * Build snapshots for all students in a course who don't have one yet.
     *
     * @param int $courseid The course ID.
     * @param int $courseenddate The course end date.
     * @param int $now Current timestamp.
     */
    protected function snapshot_course(int $courseid, int $courseenddate, int $now): void {
        global $DB;

        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return;
        }

        $students = get_enrolled_users($context, 'moodle/course:isincompletionreports', 0, 'u.id');
        if (empty($students)) {
            return;
        }

        // Get course grade item.
        $courseitem = $DB->get_record('grade_items', [
            'courseid' => $courseid,
            'itemtype' => 'course',
        ]);
        if (!$courseitem || $courseitem->grademax <= 0) {
            return;
        }

        foreach (array_keys($students) as $userid) {
            // Skip if already snapshotted.
            $snapshotexists = $DB->record_exists('local_coifish_course_snapshot', [
                'userid' => $userid,
                'courseid' => $courseid,
            ]);
            if ($snapshotexists) {
                continue;
            }

            // Clamp metric queries at the course end date so post-course activity
            // (e.g. students browsing closed course content) does not skew the snapshot.
            $endtime = $courseenddate > 0 ? $courseenddate : 0;
            $snapshot = \local_coifish\metrics_helper::capture_student_metrics(
                $courseid,
                $userid,
                $courseitem,
                $endtime
            );
            // A null grade after course end means the student did not participate;
            // do not pollute the longitudinal training set with such rows.
            if ($snapshot['grade'] === null) {
                continue;
            }

            // Get intervention data from CoIFish tables (if they exist).
            $intvdata = \local_coifish\metrics_helper::get_intervention_summary($courseid, $userid);

            $DB->insert_record('local_coifish_course_snapshot', (object)[
                'userid' => $userid,
                'courseid' => $courseid,
                'finalgrade' => $snapshot['grade'],
                'engagement' => $snapshot['engagement'],
                'social' => $snapshot['social'],
                'selfregulation' => $snapshot['selfregulation'],
                'feedbackpct' => $snapshot['feedbackpct'],
                'cognitiveengagement' => $snapshot['engagement'],
                'interventioncount' => $intvdata['count'],
                'interventionsimproved' => $intvdata['improved'],
                'courseenddate' => $courseenddate,
                'timecreated' => $now,
            ]);
        }
    }

    /**
     * Aggregate course snapshots into longitudinal profiles.
     *
     * @param int $mincourses Minimum completed courses required.
     * @param int $now Current timestamp.
     */
    protected function aggregate_profiles(int $mincourses, int $now): void {
        global $DB;

        // Find students with enough course snapshots.
        $students = $DB->get_records_sql(
            "SELECT userid, COUNT(*) AS coursecount
               FROM {local_coifish_course_snapshot}
           GROUP BY userid
             HAVING COUNT(*) >= :mincourses",
            ['mincourses' => $mincourses]
        );

        foreach ($students as $student) {
            $snapshots = $DB->get_records('local_coifish_course_snapshot', [
                'userid' => $student->userid,
            ], 'courseenddate ASC');

            $profile = $this->compute_profile($student->userid, array_values($snapshots));

            $existing = $DB->get_record('local_coifish_profile', ['userid' => $student->userid]);
            if ($existing) {
                $profile['id'] = $existing->id;
                $DB->update_record('local_coifish_profile', (object)$profile);
            } else {
                $DB->insert_record('local_coifish_profile', (object)$profile);
            }
        }
    }

    /**
     * Compute a longitudinal profile from a student's course snapshots.
     *
     * @param int $userid Student user ID.
     * @param array $snapshots Ordered array of course snapshot objects.
     * @return array Profile record fields.
     */
    protected function compute_profile(int $userid, array $snapshots): array {
        $count = count($snapshots);

        // Grades.
        $grades = array_filter(array_column($snapshots, 'finalgrade'), function ($v) {
            return $v !== null;
        });
        $avggrade = !empty($grades) ? round(array_sum($grades) / count($grades), 2) : null;
        $gradetrend = $this->compute_trend(array_values($grades));

        // Engagement.
        $engagements = array_filter(array_column($snapshots, 'engagement'), function ($v) {
            return $v !== null;
        });
        $engagementpattern = $this->classify_engagement_pattern(array_values($engagements));

        // Social presence.
        $socials = array_filter(array_column($snapshots, 'social'), function ($v) {
            return $v !== null;
        });
        $avgsocial = !empty($socials) ? round(array_sum($socials) / count($socials)) : null;
        $socialtrend = $this->compute_trend(array_values($socials));

        // Self-regulation.
        $selfregs = array_filter(array_column($snapshots, 'selfregulation'), function ($v) {
            return $v !== null;
        });
        $avgselfregulation = !empty($selfregs) ? round(array_sum($selfregs) / count($selfregs)) : null;
        $selfregtrend = $this->compute_trend(array_values($selfregs));

        // Feedback review.
        $feedbacks = array_filter(array_column($snapshots, 'feedbackpct'), function ($v) {
            return $v !== null;
        });
        $avgfeedbackpct = !empty($feedbacks) ? round(array_sum($feedbacks) / count($feedbacks)) : null;

        // Interventions.
        $totalinterventions = array_sum(array_column($snapshots, 'interventioncount'));
        $interventionsimproved = array_sum(array_column($snapshots, 'interventionsimproved'));
        if ($totalinterventions === 0) {
            $interventionresponse = 'none';
        } else if ($interventionsimproved >= $totalinterventions * 0.6) {
            $interventionresponse = 'positive';
        } else if ($interventionsimproved >= $totalinterventions * 0.3) {
            $interventionresponse = 'mixed';
        } else {
            $interventionresponse = 'unresponsive';
        }

        // Risk factors.
        $riskfactors = [];
        if ($gradetrend === 'declining') {
            $riskfactors[] = 'grade_decline';
        }
        if ($engagementpattern === 'declining') {
            $riskfactors[] = 'engagement_decline';
        }
        if ($avgsocial !== null && $avgsocial < 20) {
            $riskfactors[] = 'social_isolation';
        }
        if ($avgfeedbackpct !== null && $avgfeedbackpct < 30) {
            $riskfactors[] = 'feedback_neglect';
        }
        if ($interventionresponse === 'unresponsive') {
            $riskfactors[] = 'intervention_unresponsive';
        }

        // Overall risk level.
        $riskcount = count($riskfactors);
        if ($riskcount >= 3) {
            $risklevel = 'high';
        } else if ($riskcount >= 1) {
            $risklevel = 'moderate';
        } else {
            $risklevel = 'low';
        }

        // Count in-progress courses.
        global $DB;
        $inprogress = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT e.courseid)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {course} c ON c.id = e.courseid
              WHERE ue.userid = :uid
                AND (c.enddate = 0 OR c.enddate > :now)
                AND c.startdate <= :now2
                AND c.visible = 1
                AND c.id != :siteid",
            ['uid' => $userid, 'now' => time(), 'now2' => time(), 'siteid' => SITEID]
        );

        return [
            'userid' => $userid,
            'coursescompleted' => $count,
            'coursesinprogress' => $inprogress,
            'avggrade' => $avggrade,
            'gradetrend' => $gradetrend,
            'engagementpattern' => $engagementpattern,
            'avgsocial' => $avgsocial,
            'socialtrend' => $socialtrend,
            'avgselfregulation' => $avgselfregulation,
            'selfregtrend' => $selfregtrend,
            'avgfeedbackpct' => $avgfeedbackpct,
            'totalinterventions' => $totalinterventions,
            'interventionsimproved' => $interventionsimproved,
            'interventionresponse' => $interventionresponse,
            'risklevel' => $risklevel,
            'riskfactors' => json_encode($riskfactors),
            'timemodified' => time(),
        ];
    }

    /**
     * Compute a trend direction from an ordered series of values.
     *
     * Uses simple linear regression slope to determine if the values
     * are improving, declining, or stable.
     *
     * @param array $values Ordered numeric values.
     * @return string 'improving', 'declining', 'stable', or 'unknown'.
     */
    protected function compute_trend(array $values): string {
        if (count($values) < 2) {
            return 'unknown';
        }

        $n = count($values);
        $sumx = 0;
        $sumy = 0;
        $sumxy = 0;
        $sumx2 = 0;

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
        // Normalise slope relative to the mean value.
        $mean = $sumy / $n;
        $normslope = $mean > 0 ? ($slope / $mean) * 100 : 0;

        if ($normslope > 5) {
            return 'improving';
        } else if ($normslope < -5) {
            return 'declining';
        }
        return 'stable';
    }

    /**
     * Classify a student's engagement pattern across courses.
     *
     * @param array $values Ordered engagement rates.
     * @return string Pattern classification.
     */
    protected function classify_engagement_pattern(array $values): string {
        if (count($values) < 2) {
            return 'unknown';
        }

        $trend = $this->compute_trend($values);

        // Check for consistency (low variance).
        $mean = array_sum($values) / count($values);
        $variance = 0;
        foreach ($values as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $variance /= count($values);
        $stddev = sqrt($variance);
        $cv = $mean > 0 ? ($stddev / $mean) * 100 : 0;

        if ($cv < 15) {
            return 'consistent';
        }
        if ($trend === 'declining') {
            return 'declining';
        }
        if ($trend === 'improving') {
            return 'growing';
        }
        return 'irregular';
    }
}

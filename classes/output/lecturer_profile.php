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
 * Renderable for a single lecturer's performance profile.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coifish\output;

use renderable;
use templatable;
use renderer_base;

/**
 * Prepares lecturer profile data for the lecturer profile template.
 */
class lecturer_profile implements renderable, templatable {
    /** @var int The lecturer user ID. */
    protected int $userid;

    /** @var bool Whether the viewer is the lecturer themselves. */
    protected bool $isself;

    /** @var string Date from filter (Y-m-d or empty). */
    protected string $datefrom;

    /** @var string Date to filter (Y-m-d or empty). */
    protected string $dateto;

    /** @var int Course filter (0 for all courses). */
    protected int $courseid;

    /**
     * Constructor.
     *
     * @param int $userid The lecturer user ID.
     * @param bool $isself Whether the viewer is the lecturer.
     * @param string $datefrom Start date filter (Y-m-d or empty for all time).
     * @param string $dateto End date filter (Y-m-d or empty for all time).
     * @param int $courseid Course filter (0 for all courses).
     */
    public function __construct(
        int $userid,
        bool $isself = false,
        string $datefrom = '',
        string $dateto = '',
        int $courseid = 0
    ) {
        $this->userid = $userid;
        $this->isself = $isself;
        $this->datefrom = $datefrom;
        $this->dateto = $dateto;
        $this->courseid = $courseid;
    }

    /**
     * Export data for the mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return \stdClass Template data.
     */
    public function export_for_template(renderer_base $output): \stdClass {
        // Parse date filters into timestamps.
        $timefrom = 0;
        $timeto = 0;
        if (!empty($this->datefrom)) {
            $timefrom = strtotime($this->datefrom);
            if ($timefrom === false) {
                $timefrom = 0;
            }
        }
        if (!empty($this->dateto)) {
            $timeto = strtotime($this->dateto . ' 23:59:59');
            if ($timeto === false) {
                $timeto = 0;
            }
        }

        $hasfilter = ($timefrom > 0 || $timeto > 0 || $this->courseid > 0);

        // If any filter is active, compute on-the-fly; otherwise use cached data.
        if ($hasfilter) {
            $profile = \local_coifish\lecturer_api::compute_lecturer_profile_for_range(
                $this->userid,
                $this->isself,
                $timefrom,
                $timeto ?: time(),
                $this->courseid
            );
        } else {
            $profile = \local_coifish\lecturer_api::get_lecturer_profile($this->userid, $this->isself);
        }

        $data = new \stdClass();

        // Build course dropdown for this lecturer.
        $lecturercourses = $this->get_lecturer_courses($this->userid);
        $data->courses = [];
        foreach ($lecturercourses as $cid => $cname) {
            $data->courses[] = [
                'id' => $cid,
                'name' => $cname,
                'selected' => ($cid == $this->courseid),
            ];
        }
        $data->hascourses = !empty($data->courses);

        // Filter form data.
        $data->datefrom = $this->datefrom;
        $data->dateto = $this->dateto;
        $data->selectedcourseid = $this->courseid;
        $data->hasfilter = $hasfilter;
        $data->formurl = (new \moodle_url('/local/coifish/lecturerprofile.php', [
            'userid' => $this->userid,
        ]))->out(false);

        if (empty($profile)) {
            $data->hasprofile = false;
            return $data;
        }

        // Map the API return value directly onto the template data.
        foreach ($profile as $key => $value) {
            $data->$key = $value;
        }

        // Dimension breakdown for progress bars.
        $data->dimensions = [];
        $dimensionkeys = [
            'feedback_quality' => $profile['avgfeedbackquality'],
            'feedback_coverage' => $profile['avgcoverage'],
            'feedback_depth' => $profile['avgdepth'],
            'feedback_personalisation' => $profile['avgpersonalisation'],
            'grading_turnaround' => $profile['avgturnarounddays'] !== null
                ? max(0, min(100, round((7 - $profile['avgturnarounddays']) / 7 * 100)))
                : null,
            'intervention_effectiveness' => $profile['interventioneffectiveness'],
            'forum_engagement' => $profile['avgforumpostspw'] !== null
                ? min(100, round($profile['avgforumpostspw'] / 3.0 * 100))
                : null,
            'student_outcomes' => $profile['avgstudentgrade'] !== null
                ? min(100, round($profile['avgstudentgrade']))
                : null,
        ];

        $component = 'local_coifish';
        foreach ($dimensionkeys as $key => $score) {
            $scorevalue = $score !== null ? (int)$score : 0;
            if ($scorevalue >= 70) {
                $barclass = 'bg-success';
            } else if ($scorevalue >= 40) {
                $barclass = 'bg-warning';
            } else {
                $barclass = 'bg-danger';
            }
            $data->dimensions[] = [
                'key' => $key,
                'label' => get_string('lecturer_dim_' . $key, $component),
                'score' => $scorevalue,
                'hasscore' => ($score !== null),
                'barclass' => $barclass,
            ];
        }
        $data->hasdimensions = !empty($data->dimensions);

        // Back link for coordinators viewing another lecturer.
        if (!$this->isself) {
            $data->backurl = (new \moodle_url('/local/coifish/lecturerprofile.php'))->out(false);
        }

        // Debug mode — show raw values when ?debug=1 is in the URL.
        $data->showdebug = optional_param('debug', 0, PARAM_BOOL);
        if ($data->showdebug) {
            $data->debugdata = [];
            foreach ($profile as $key => $value) {
                if (is_array($value)) {
                    $displayvalue = json_encode($value);
                } else if (is_bool($value)) {
                    $displayvalue = $value ? 'true' : 'false';
                } else if ($value === null) {
                    $displayvalue = '(null)';
                } else {
                    $displayvalue = (string)$value;
                }
                $data->debugdata[] = ['key' => $key, 'value' => $displayvalue];
            }
        }

        // Historical trend sparklines. Cached per-week in
        // local_coifish_lecturer_period_snapshot — populated by the daily
        // build_lecturer_profiles task and the hourly backfill_lecturer_snapshots
        // task. Last 26 weeks of data, oldest-first.
        $snapshots = \local_coifish\lecturer_api::get_lecturer_period_snapshots($this->userid, 26);
        $data->trends = $this->build_sparkline_data($snapshots);
        $data->hastrends = !empty($data->trends);
        $data->trendweeks = count($snapshots);

        // Per-assignment grading-turnaround drill-down (raw first-grade vs
        // integrity-referral-adjusted). Scoped to the same non-excluded courses
        // the profile metrics use, and to the active course/date filters.
        $breakdowncourseids = array_keys($this->get_lecturer_courses($this->userid));
        if ($this->courseid > 0) {
            $breakdowncourseids = array_values(array_intersect($breakdowncourseids, [$this->courseid]));
        }
        $data->turnaroundbreakdown = \local_coifish\lecturer_api::get_turnaround_breakdown(
            $this->userid,
            $breakdowncourseids,
            $timefrom,
            $timeto
        );
        $data->hasturnaroundbreakdown = !empty($data->turnaroundbreakdown);

        return $data;
    }

    /**
     * Build sparkline-ready point series for each tracked metric.
     *
     * Returns one entry per metric for which we have at least 3 data points.
     * Each entry contains the field name, the rendered SVG <polyline> points
     * (already in viewBox coordinates), and a label.
     *
     * @param array $snapshots Period snapshot rows oldest-first.
     * @return array
     */
    protected function build_sparkline_data(array $snapshots): array {
        $metrics = [
            'avgfeedbackquality' => ['label' => 'lecturer_feedback_quality', 'unit' => '%', 'range' => [0, 100]],
            'avgturnarounddays' => ['label' => 'lecturer_turnaround', 'unit' => 'd', 'range' => null],
            'avgforumpostspw' => ['label' => 'lecturer_dim_forum_engagement', 'unit' => '/wk', 'range' => null],
            'interventionsimproved' => ['label' => 'lecturer_dim_intervention_effectiveness', 'unit' => '', 'range' => null],
            'hours_total' => ['label' => 'lecturer_time_hours', 'unit' => 'h', 'range' => null],
            'avgstudentgrade' => ['label' => 'lecturer_student_outcomes', 'unit' => '%', 'range' => [0, 100]],
        ];

        $width = 120;
        $height = 32;
        $padx = 2;
        $pady = 4;
        $usablew = $width - 2 * $padx;
        $usableh = $height - 2 * $pady;

        $out = [];
        foreach ($metrics as $field => $meta) {
            $values = [];
            foreach ($snapshots as $s) {
                $v = $s->$field ?? null;
                $values[] = ($v === null || $v === '') ? null : (float)$v;
            }
            $nonnull = array_values(array_filter($values, function ($v) {
                return $v !== null;
            }));
            if (count($nonnull) < 3) {
                continue; // Not enough data for a meaningful trend.
            }

            // Scale to the metric's natural range, or auto-fit if unbounded.
            if ($meta['range']) {
                [$lo, $hi] = $meta['range'];
            } else {
                $lo = min($nonnull);
                $hi = max($nonnull);
                if ($hi - $lo < 1e-6) {
                    $hi = $lo + 1; // Avoid divide-by-zero on a flat series.
                }
            }

            $n = count($values);
            $points = [];
            for ($i = 0; $i < $n; $i++) {
                if ($values[$i] === null) {
                    continue; // Sparkline skips gaps.
                }
                $x = $padx + ($n > 1 ? ($i / ($n - 1)) * $usablew : $usablew / 2);
                $y = $pady + $usableh - (($values[$i] - $lo) / ($hi - $lo)) * $usableh;
                $points[] = round($x, 2) . ',' . round($y, 2);
            }
            $latest = end($nonnull);
            $first = reset($nonnull);
            $deltapct = ($first > 0)
                ? round((($latest - $first) / $first) * 100)
                : 0;

            $out[] = [
                'field' => $field,
                'label' => get_string($meta['label'], 'local_coifish'),
                'points' => implode(' ', $points),
                'width' => $width,
                'height' => $height,
                'latest' => is_float($latest) ? round($latest, 1) : $latest,
                'unit' => $meta['unit'],
                'deltapct' => $deltapct,
                'isup' => $deltapct > 0,
                'isdown' => $deltapct < 0,
            ];
        }
        return $out;
    }

    /**
     * Get the list of courses this lecturer teaches, for the filter dropdown.
     *
     * @param int $userid Lecturer user ID.
     * @return array Keyed by course ID => course fullname.
     */
    protected function get_lecturer_courses(int $userid): array {
        global $DB;

        [$trinsql, $trparams] = \local_coifish\filter_helper::get_teacher_role_sql();
        [$exclfrag, $exclparams] = \local_coifish\filter_helper::get_excluded_courses_sql('c', 'lpx');
        [$catfrag, $catparams] = \local_coifish\filter_helper::get_category_scope_sql('c', 'lpcat');
        $records = $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.fullname, c.shortname
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
               JOIN {course} c ON c.id = ctx.instanceid
              WHERE ra.userid = :uid
                AND ra.roleid $trinsql
                AND c.id != :siteid
                $exclfrag
                $catfrag
           ORDER BY c.fullname ASC",
            array_merge(
                ['ctxlevel' => CONTEXT_COURSE, 'uid' => $userid, 'siteid' => SITEID],
                $trparams,
                $exclparams,
                $catparams
            )
        );

        // If the viewer is a PC (not self-view, not admin), filter to their programme's courses.
        $pattern = '';
        if (!$this->isself && !is_siteadmin()) {
            $pattern = \local_coifish\filter_helper::get_user_course_pattern();
        }

        $courses = [];
        foreach ($records as $rec) {
            if (!empty($pattern) && !preg_match('/' . $pattern . '/i', $rec->shortname)) {
                continue;
            }
            $courses[$rec->id] = format_string($rec->fullname);
        }
        return $courses;
    }
}

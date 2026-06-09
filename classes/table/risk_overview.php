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
 * Dynamic table backing the institution-wide Student Risk Overview.
 *
 * Replaces the previous "fetch everything then render" approach with a
 * paginated, sortable, AJAX-refreshable table_sql so the report scales to
 * institutions with thousands of at-risk students without loading them all
 * into memory or a single HTML response.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace local_coifish\table;

use context;
use core_table\dynamic as dynamic_table;
use core_table\local\filter\filterset;
use moodle_url;
use table_sql;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * Paginated, sortable view of at-risk students across the institution.
 */
class risk_overview extends table_sql implements dynamic_table {
    /**
     * Constructor: declare columns, sortable cols, and default sort.
     *
     * @param string $uniqueid Caller-provided unique id for this table instance.
     */
    public function __construct(string $uniqueid) {
        parent::__construct($uniqueid);

        $component = 'local_coifish';
        $columns = ['fullname', 'risklevel', 'riskfactors', 'engagementpattern',
                    'gradetrend', 'interventionresponse', 'coursescompleted'];
        $headers = [
            get_string('col_student', $component),
            get_string('col_risk', $component),
            get_string('col_risk_factors', $component),
            get_string('col_engagement', $component),
            get_string('col_grade_trend', $component),
            get_string('col_intervention_response', $component),
            get_string('col_courses', $component),
        ];
        $this->define_columns($columns);
        $this->define_headers($headers);

        // The risk-factor / pattern / response columns hold pre-computed
        // labels, not values that meaningfully sort, so leave them unsortable.
        $this->no_sorting('riskfactors');
        $this->no_sorting('engagementpattern');
        $this->no_sorting('interventionresponse');

        // Default sort: high risk first, then by ascending average grade
        // (the most-at-risk floats to the top).
        $this->sortable(true, 'risksort', SORT_ASC);
        $this->collapsible(false);
        $this->set_attribute('class', 'generaltable local-coifish-risk-table');
        $this->is_downloadable(false);
    }

    /**
     * Apply filters then assemble the SQL the parent table_sql will paginate/sort.
     *
     * @param filterset $filterset
     */
    public function set_filterset(filterset $filterset): void {
        parent::set_filterset($filterset);

        global $DB;

        $conditions = [];
        $params = [];

        $risklevel = $this->filter_string('risklevel', 'all');
        if ($risklevel === 'high') {
            $conditions[] = "p.risklevel = 'high'";
        } else if ($risklevel === 'moderate') {
            $conditions[] = "p.risklevel = 'moderate'";
        } else {
            $conditions[] = "p.risklevel IN ('moderate', 'high')";
        }

        // Cohort-mode student ID restriction (computed from the cohort filter).
        $categoryid = (int)$this->filter_int('categoryid', 0);
        $cohortid = (int)$this->filter_int('cohortid', 0);
        $extrajoin = '';

        $mode = \local_coifish\filter_helper::get_mode();
        if ($mode === 'cohort') {
            // filter_helper::get_filtered_student_ids() returns null when the
            // caller (e.g. an admin with no specific cohort selected) should
            // see everyone — distinct from an explicit empty list of zero
            // matching students.
            $studentids = \local_coifish\filter_helper::get_filtered_student_ids($cohortid);
            if ($studentids !== null) {
                if (empty($studentids)) {
                    // Genuine empty set; force a no-rows result.
                    $conditions[] = '1 = 0';
                } else {
                    [$insqlstu, $inparamsstu] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'stu');
                    $conditions[] = "p.userid $insqlstu";
                    $params = array_merge($params, $inparamsstu);
                }
            }
        } else if ($categoryid > 0) {
            $cat = \core_course_category::get($categoryid, IGNORE_MISSING);
            if ($cat) {
                $catids = array_merge([$categoryid], $cat->get_all_children_ids());
                [$insqlcat, $inparamscat] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, 'cat');
                $extrajoin = "JOIN {user_enrolments} ue ON ue.userid = p.userid
                              JOIN {enrol} e ON e.id = ue.enrolid
                              JOIN {course} c ON c.id = e.courseid AND c.category $insqlcat";
                $params = array_merge($params, $inparamscat);
            }
        }

        // Current-enrolment filter, matching api::get_risk_overview() semantics.
        $enrolstatus = $this->filter_string('enrolstatus', 'current');
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

        // The risksort CASE expression is exposed both for the default
        // sort and for the optional client-side sort by risk severity.
        $fields = "DISTINCT p.id, p.userid, p.coursescompleted, p.avggrade, p.risklevel,
                   p.engagementpattern, p.gradetrend, p.interventionresponse,
                   p.riskfactors, p.totalinterventions, p.interventionsimproved
                   $namefields,
                   CASE p.risklevel WHEN 'high' THEN 1 WHEN 'moderate' THEN 2 ELSE 3 END AS risksort";
        $from = "{local_coifish_profile} p
                 JOIN {user} u ON u.id = p.userid AND u.deleted = 0
                 $extrajoin";

        $this->set_sql($fields, $from, $where, $params);
        $this->set_count_sql(
            "SELECT COUNT(DISTINCT p.id) FROM $from WHERE $where",
            $params
        );
    }

    /**
     * Render the student name as a link into the drill-down profile.
     *
     * @param object $row
     * @return string
     */
    public function col_fullname($row): string {
        $url = new moodle_url('/local/coifish/studentprofile.php', ['userid' => $row->userid]);
        return \html_writer::link($url, fullname($row), ['class' => 'fw-semibold']);
    }

    /**
     * Risk-level coloured badge.
     *
     * @param object $row
     * @return string
     */
    public function col_risklevel($row): string {
        $level = $row->risklevel;
        $classes = ['high' => 'bg-danger', 'moderate' => 'bg-warning text-dark', 'low' => 'bg-success'];
        $label = get_string('profile_risk_' . $level, 'local_coifish');
        return \html_writer::span($label, 'badge ' . ($classes[$level] ?? 'bg-secondary'));
    }

    /**
     * Risk-factor chips.
     *
     * @param object $row
     * @return string
     */
    public function col_riskfactors($row): string {
        $factors = json_decode($row->riskfactors ?: '[]', true);
        if (empty($factors)) {
            return \html_writer::span('-', 'text-muted');
        }
        $component = 'local_coifish';
        $html = '';
        foreach ($factors as $factor) {
            $key = 'risk_factor_' . $factor;
            if (!get_string_manager()->string_exists($key, $component)) {
                continue;
            }
            $html .= \html_writer::span(get_string($key, $component), 'badge bg-secondary me-1 mb-1');
        }
        return $html;
    }

    /**
     * Engagement pattern label.
     *
     * @param object $row
     * @return string
     */
    public function col_engagementpattern($row): string {
        $key = 'engagement_pattern_' . $row->engagementpattern;
        if (!get_string_manager()->string_exists($key, 'local_coifish')) {
            return '-';
        }
        return s(get_string($key, 'local_coifish'));
    }

    /**
     * Grade trend label.
     *
     * @param object $row
     * @return string
     */
    public function col_gradetrend($row): string {
        if (empty($row->gradetrend)) {
            return \html_writer::span('-', 'text-muted');
        }
        return s(get_string('trajectory_' . $row->gradetrend, 'local_coifish'));
    }

    /**
     * Intervention response label.
     *
     * @param object $row
     * @return string
     */
    public function col_interventionresponse($row): string {
        if (empty($row->interventionresponse)) {
            return \html_writer::span('-', 'text-muted');
        }
        return s(get_string('intervention_response_' . $row->interventionresponse, 'local_coifish'));
    }

    /**
     * Courses-completed count, right-aligned via column spec.
     *
     * @param object $row
     * @return string
     */
    public function col_coursescompleted($row): string {
        return (string)(int)$row->coursescompleted;
    }

    /**
     * Where the table lives. Used to seed the AJAX refresh URL.
     */
    public function guess_base_url(): void {
        $this->baseurl = new moodle_url('/local/coifish/index.php');
    }

    /**
     * Context for capability checks. Risk overview is institution-wide.
     */
    public function get_context(): context {
        return \context_system::instance();
    }

    /**
     * Confirm the current user may load this table via AJAX.
     */
    public function has_capability(): bool {
        return has_capability('local/coifish:viewfullprofile', $this->get_context());
    }

    /**
     * Read an integer filter value with a default, since all our filters are optional.
     *
     * @param string $name
     * @param int $default
     * @return int
     */
    protected function filter_int(string $name, int $default): int {
        $fs = $this->get_filterset();
        if (!$fs->has_filter($name)) {
            return $default;
        }
        $values = $fs->get_filter($name)->get_filter_values();
        return empty($values) ? $default : (int)reset($values);
    }

    /**
     * Read a string filter value with a default.
     *
     * @param string $name
     * @param string $default
     * @return string
     */
    protected function filter_string(string $name, string $default): string {
        $fs = $this->get_filterset();
        if (!$fs->has_filter($name)) {
            return $default;
        }
        $values = $fs->get_filter($name)->get_filter_values();
        return empty($values) ? $default : (string)reset($values);
    }
}

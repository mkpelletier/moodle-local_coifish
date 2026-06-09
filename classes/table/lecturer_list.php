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
 * Dynamic table backing the institution-wide Lecturer list.
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
 * Paginated, sortable view of lecturer profiles.
 */
class lecturer_list extends table_sql implements dynamic_table {
    /**
     * Constructor: declare columns, sortable cols, and default sort.
     *
     * @param string $uniqueid Caller-provided unique id for this table instance.
     */
    public function __construct(string $uniqueid) {
        parent::__construct($uniqueid);

        $component = 'local_coifish';
        $columns = ['fullname', 'coursecount', 'avgfeedbackquality',
                    'avgturnarounddays', 'interventioneffectiveness', 'studentgradetrend'];
        $headers = [
            get_string('col_lecturer', $component),
            get_string('col_courses', $component),
            get_string('lecturer_feedback_quality', $component),
            get_string('lecturer_turnaround', $component),
            get_string('lecturer_intervention_eff', $component),
            get_string('col_student_grade_trend', $component),
        ];
        $this->define_columns($columns);
        $this->define_headers($headers);

        // The trend label is a categorical string, leave unsortable.
        $this->no_sorting('studentgradetrend');

        // Default sort by course count descending (matches the previous default).
        $this->sortable(true, 'coursecount', SORT_DESC);
        $this->collapsible(false);
        $this->set_attribute('class', 'generaltable local-coifish-lecturer-table');
        $this->is_downloadable(false);
    }

    /**
     * Build the SQL the parent table_sql will paginate/sort.
     *
     * @param filterset $filterset
     */
    public function set_filterset(filterset $filterset): void {
        parent::set_filterset($filterset);

        global $DB;

        $categoryid = $this->filter_int('categoryid', 0);
        $cohortid = $this->filter_int('cohortid', 0);
        $mode = \local_coifish\filter_helper::get_mode();

        $conditions = [];
        $params = [];

        if ($mode === 'cohort') {
            // null = "no restriction" (e.g. admin); empty array = "explicit zero matches".
            $lecturerids = \local_coifish\filter_helper::get_filtered_lecturer_ids($cohortid);
            if ($lecturerids !== null) {
                if (empty($lecturerids)) {
                    $conditions[] = '1 = 0';
                } else {
                    [$insql, $inparams] = $DB->get_in_or_equal($lecturerids, SQL_PARAMS_NAMED, 'lec');
                    $conditions[] = "l.userid $insql";
                    $params = array_merge($params, $inparams);
                }
            }
        } else if ($categoryid > 0) {
            $cat = \core_course_category::get($categoryid, IGNORE_MISSING);
            if (!$cat) {
                $conditions[] = '1 = 0';
            } else {
                $catids = array_merge([$categoryid], $cat->get_all_children_ids());
                [$insqlcat, $inparamscat] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, 'lcat');
                [$trinsql, $trparams] = \local_coifish\filter_helper::get_teacher_role_sql();
                $catexists = "EXISTS (
                    SELECT 1
                      FROM {role_assignments} ra
                      JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :lctxlevel
                      JOIN {course} c ON c.id = ctx.instanceid
                     WHERE ra.userid = l.userid
                       AND c.category $insqlcat
                       AND ra.roleid $trinsql
                )";
                $conditions[] = $catexists;
                $params['lctxlevel'] = CONTEXT_COURSE;
                $params = array_merge($params, $inparamscat, $trparams);
            }
        }

        if (empty($conditions)) {
            $conditions[] = '1 = 1';
        }

        $namefields = \core_user\fields::for_name()->get_sql('u')->selects;
        $where = implode(' AND ', $conditions);
        $fields = "l.id, l.userid, l.coursecount, l.avgfeedbackquality,
                   l.avgturnarounddays, l.interventioneffectiveness, l.studentgradetrend
                   $namefields";
        $from = "{local_coifish_lecturer} l
                 JOIN {user} u ON u.id = l.userid AND u.deleted = 0";

        $this->set_sql($fields, $from, $where, $params);
        $this->set_count_sql("SELECT COUNT(*) FROM $from WHERE $where", $params);
    }

    /**
     * Render the lecturer name as a link into the profile.
     *
     * @param object $row
     * @return string
     */
    public function col_fullname($row): string {
        $url = new moodle_url('/local/coifish/lecturerprofile.php', ['userid' => $row->userid]);
        return \html_writer::link($url, fullname($row), ['class' => 'fw-semibold']);
    }

    /**
     * Course count, centred.
     *
     * @param object $row
     * @return string
     */
    public function col_coursecount($row): string {
        return (string)(int)$row->coursecount;
    }

    /**
     * Average feedback quality percentage or em-dash placeholder.
     *
     * @param object $row
     * @return string
     */
    public function col_avgfeedbackquality($row): string {
        return ($row->avgfeedbackquality === null || $row->avgfeedbackquality === '')
            ? \html_writer::span('-', 'text-muted')
            : ((int)$row->avgfeedbackquality) . '%';
    }

    /**
     * Average grading turnaround in days.
     *
     * @param object $row
     * @return string
     */
    public function col_avgturnarounddays($row): string {
        if ($row->avgturnarounddays === null || $row->avgturnarounddays === '') {
            return \html_writer::span('-', 'text-muted');
        }
        return ((float)$row->avgturnarounddays) . 'd';
    }

    /**
     * Intervention effectiveness percentage.
     *
     * @param object $row
     * @return string
     */
    public function col_interventioneffectiveness($row): string {
        return ($row->interventioneffectiveness === null || $row->interventioneffectiveness === '')
            ? \html_writer::span('-', 'text-muted')
            : ((int)$row->interventioneffectiveness) . '%';
    }

    /**
     * Student grade trend label.
     *
     * @param object $row
     * @return string
     */
    public function col_studentgradetrend($row): string {
        if (empty($row->studentgradetrend)) {
            return \html_writer::span('-', 'text-muted');
        }
        $key = 'trajectory_' . $row->studentgradetrend;
        if (!get_string_manager()->string_exists($key, 'local_coifish')) {
            return s((string)$row->studentgradetrend);
        }
        return s(get_string($key, 'local_coifish'));
    }

    /**
     * Where the table lives. Used to seed the AJAX refresh URL.
     */
    public function guess_base_url(): void {
        $this->baseurl = new moodle_url('/local/coifish/lecturerprofile.php');
    }

    /**
     * Context for capability checks.
     */
    public function get_context(): context {
        return \context_system::instance();
    }

    /**
     * Confirm the current user may load this table via AJAX.
     */
    public function has_capability(): bool {
        $ctx = $this->get_context();
        return has_capability('local/coifish:viewfullprofile', $ctx)
            || has_capability('local/coifish:viewlecturerprofile', $ctx);
    }

    /**
     * Read an integer filter value with a default.
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
}

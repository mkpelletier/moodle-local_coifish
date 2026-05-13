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
 * Renderable for the institution-wide student risk overview.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coifish\output;

use renderable;
use templatable;
use renderer_base;
use local_coifish\filter_helper;

/**
 * Prepares at-risk student data for the risk overview template.
 */
class risk_overview implements renderable, templatable {
    /** @var int Filter ID (category or cohort, depending on mode). */
    protected int $filterid;

    /** @var string Risk level filter: 'all', 'moderate', or 'high'. */
    protected string $risklevel;

    /** @var string Enrolment-status filter: 'all', 'current', or 'notenrolled'. */
    protected string $enrolstatus;

    /**
     * Constructor.
     *
     * @param int $filterid Filter ID (category or cohort).
     * @param string $risklevel Risk level filter.
     * @param string $enrolstatus Current-enrolment filter.
     */
    public function __construct(int $filterid = 0, string $risklevel = 'all', string $enrolstatus = 'current') {
        $this->filterid = $filterid;
        $this->risklevel = $risklevel;
        $this->enrolstatus = $enrolstatus;
    }

    /**
     * Export data for the mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return \stdClass Template data.
     */
    public function export_for_template(renderer_base $output): \stdClass {
        $data = new \stdClass();

        $mode = filter_helper::get_mode();

        // Get filtered students based on organisation mode.
        if ($mode === 'cohort') {
            $studentids = filter_helper::get_filtered_student_ids($this->filterid);
            $students = \local_coifish\api::get_risk_overview(0, $this->risklevel, $studentids, $this->enrolstatus);
        } else {
            $students = \local_coifish\api::get_risk_overview($this->filterid, $this->risklevel, null, $this->enrolstatus);
        }

        $data->students = $students;
        $data->hasstudents = !empty($students);

        // Summary statistics.
        $data->totalcount = count($students);
        $data->highcount = 0;
        $data->moderatecount = 0;
        foreach ($students as $student) {
            if ($student['risklevel'] === 'high') {
                $data->highcount++;
            } else if ($student['risklevel'] === 'moderate') {
                $data->moderatecount++;
            }
        }

        // Filter dropdown (adapts to mode).
        $filter = filter_helper::get_filter_options($this->filterid);
        $data->filteroptions = $filter['options'];
        $data->filterlabel = $filter['label'];
        $data->filteralllabel = $filter['alllabel'];
        $data->filterparamname = $filter['paramname'];

        // Current filter state.
        $data->selectedfilter = $this->filterid;
        $data->selectedrisklevel = $this->risklevel;
        $data->isriskall = ($this->risklevel === 'all');
        $data->isriskhigh = ($this->risklevel === 'high');
        $data->isriskmoderate = ($this->risklevel === 'moderate');
        $data->selectedenrolstatus = $this->enrolstatus;
        $data->isenrolall = ($this->enrolstatus === 'all');
        $data->isenrolcurrent = ($this->enrolstatus === 'current');
        $data->isenrolnotenrolled = ($this->enrolstatus === 'notenrolled');

        // Form action URL.
        $data->formurl = (new \moodle_url('/local/coifish/index.php'))->out(false);

        return $data;
    }
}

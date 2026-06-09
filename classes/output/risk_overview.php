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

        // Summary counts (full filtered population, independent of pagination).
        if ($mode === 'cohort') {
            $studentids = filter_helper::get_filtered_student_ids($this->filterid);
            $counts = \local_coifish\api::get_risk_overview_counts(0, $this->risklevel, $studentids, $this->enrolstatus);
        } else {
            $counts = \local_coifish\api::get_risk_overview_counts(
                $this->filterid,
                $this->risklevel,
                null,
                $this->enrolstatus
            );
        }
        $data->totalcount = $counts['total'];
        $data->highcount = $counts['high'];
        $data->moderatecount = $counts['moderate'];
        $data->hasstudents = $counts['total'] > 0;

        // Render the dynamic table's initial HTML; AJAX takes over on
        // pagination, sort, and filter changes.
        $table = new \local_coifish\table\risk_overview('coifish-risk-overview');
        $filterset = new \local_coifish\table\risk_overview_filterset();
        $filterset->add_filter(new \core_table\local\filter\integer_filter(
            'categoryid',
            \core_table\local\filter\filter::JOINTYPE_DEFAULT,
            [$this->filterid]
        ));
        $filterset->add_filter(new \core_table\local\filter\integer_filter(
            'cohortid',
            \core_table\local\filter\filter::JOINTYPE_DEFAULT,
            [$this->filterid]
        ));
        $filterset->add_filter(new \core_table\local\filter\string_filter(
            'risklevel',
            \core_table\local\filter\filter::JOINTYPE_DEFAULT,
            [$this->risklevel]
        ));
        $filterset->add_filter(new \core_table\local\filter\string_filter(
            'enrolstatus',
            \core_table\local\filter\filter::JOINTYPE_DEFAULT,
            [$this->enrolstatus]
        ));
        $table->set_filterset($filterset);

        ob_start();
        $table->out(50, false);
        $data->tablehtml = ob_get_clean();

        // Filter dropdown (adapts to mode).
        $filter = filter_helper::get_filter_options($this->filterid);
        $data->filteroptions = $filter['options'];
        $data->filterlabel = $filter['label'];
        $data->filteralllabel = $filter['alllabel'];
        $data->filterparamname = $filter['paramname'];

        // Current filter state for the URL-bound dropdowns.
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

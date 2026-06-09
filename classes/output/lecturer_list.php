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
 * Renderable for the lecturer list overview.
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
 * Prepares lecturer list data for the lecturer list template.
 */
class lecturer_list implements renderable, templatable {
    /** @var int Filter ID (category or cohort, depending on mode). */
    protected int $filterid;

    /**
     * Constructor.
     *
     * @param int $filterid Filter ID (category or cohort).
     */
    public function __construct(int $filterid = 0) {
        $this->filterid = $filterid;
    }

    /**
     * Export data for the mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return \stdClass Template data.
     */
    public function export_for_template(renderer_base $output): \stdClass {
        global $DB;
        $data = new \stdClass();

        // Render the dynamic table's initial HTML; AJAX takes over on
        // pagination, sort, and filter changes.
        $table = new \local_coifish\table\lecturer_list('coifish-lecturer-list');
        $filterset = new \local_coifish\table\lecturer_list_filterset();
        $filterset->add_filter(new \core_table\local\filter\integer_filter(
            'categoryid', \core_table\local\filter\filter::JOINTYPE_DEFAULT, [$this->filterid]
        ));
        $filterset->add_filter(new \core_table\local\filter\integer_filter(
            'cohortid', \core_table\local\filter\filter::JOINTYPE_DEFAULT, [$this->filterid]
        ));
        $table->set_filterset($filterset);

        ob_start();
        $table->out(50, false);
        $data->tablehtml = ob_get_clean();

        // Is there *any* lecturer profile data at all? Drives the empty-state alert.
        $data->haslecturers = $DB->record_exists('local_coifish_lecturer', []);

        // Filter dropdown (adapts to mode).
        $filter = filter_helper::get_filter_options($this->filterid);
        $data->filteroptions = $filter['options'];
        $data->filterlabel = $filter['label'];
        $data->filteralllabel = $filter['alllabel'];
        $data->filterparamname = $filter['paramname'];
        $data->selectedfilter = $this->filterid;

        // Form action URL.
        $data->formurl = (new \moodle_url('/local/coifish/lecturerprofile.php'))->out(false);

        // Export URL.
        $data->exporturl = (new \moodle_url('/local/coifish/export.php'))->out(false);

        return $data;
    }
}

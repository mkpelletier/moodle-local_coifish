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
 * Renderable for the lecturer time-report export form.
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
 * Prepares the export form for the Mustache template.
 */
class export_form implements renderable, templatable {
    /** @var string Current "from" date (YYYY-MM-DD or empty). */
    protected string $datefrom;

    /** @var string Current "to" date (YYYY-MM-DD or empty). */
    protected string $dateto;

    /** @var int Current cohort filter selection (0 for all). */
    protected int $cohortid;

    /**
     * Constructor.
     *
     * @param string $datefrom Current "from" date.
     * @param string $dateto Current "to" date.
     * @param int $cohortid Current cohort selection.
     */
    public function __construct(string $datefrom, string $dateto, int $cohortid) {
        $this->datefrom = $datefrom;
        $this->dateto = $dateto;
        $this->cohortid = $cohortid;
    }

    /**
     * Export data for the Mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return \stdClass Template data.
     */
    public function export_for_template(renderer_base $output): \stdClass {
        $data = new \stdClass();

        $data->formurl = (new \moodle_url('/local/coifish/export.php'))->out(false);
        $data->cancelurl = (new \moodle_url('/local/coifish/lecturerprofile.php'))->out(false);
        $data->sesskey = sesskey();
        $data->datefrom = $this->datefrom;
        $data->dateto = $this->dateto;

        // Cohort filter only shown in cohort mode.
        $mode = \local_coifish\filter_helper::get_mode();
        $data->hascohortfilter = ($mode === 'cohort');
        if ($data->hascohortfilter) {
            $filter = \local_coifish\filter_helper::get_filter_options($this->cohortid);
            $data->cohortlabel = $filter['label'];
            $data->cohortalllabel = $filter['alllabel'];
            $data->cohortoptions = array_values($filter['options']);
        }

        return $data;
    }
}

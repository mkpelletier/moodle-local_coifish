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
 * Renderable for the student drill-down profile page.
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
 * Prepares full student profile and course history data for the drill-down template.
 */
class student_drilldown implements renderable, templatable {
    /** @var int The student user ID. */
    protected int $userid;

    /**
     * Constructor.
     *
     * @param int $userid The student user ID.
     */
    public function __construct(int $userid) {
        $this->userid = $userid;
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

        // Student identity.
        $user = $DB->get_record('user', ['id' => $this->userid], 'id, firstname, lastname, email');
        $data->userid = $this->userid;
        $data->fullname = $user ? fullname($user) : '';

        // Full longitudinal profile (system-level = courseid 0).
        $profile = \local_coifish\api::get_student_profile($this->userid, 0);
        $data->profile = $profile;
        $data->hasprofile = !empty($profile) && !empty($profile['hasprofile']);

        // Recommendations from the profile.
        $data->recommendations = $profile['recommendations'] ?? [];
        $data->hasrecommendations = !empty($data->recommendations);

        // Course history.
        $history = \local_coifish\api::get_course_history($this->userid);
        foreach ($history as &$snap) {
            if (!empty($snap['courseenddate'])) {
                $snap['courseenddateformatted'] = userdate($snap['courseenddate'], get_string('strftimedate'));
            } else {
                $snap['courseenddateformatted'] = '-';
            }
        }
        unset($snap);

        $data->history = $history;
        $data->hashistory = !empty($history);

        // Current enrolments (in-progress courses).
        $current = \local_coifish\api::get_current_enrolments($this->userid);
        foreach ($current as &$row) {
            $row['coursestartformatted'] = !empty($row['coursestartdate'])
                ? userdate($row['coursestartdate'], get_string('strftimedate'))
                : '-';
            $row['courseendformatted'] = !empty($row['courseenddate'])
                ? userdate($row['courseenddate'], get_string('strftimedate'))
                : '-';
        }
        unset($row);
        $data->current = $current;
        $data->hascurrent = !empty($current);

        // Freshness caption + refresh button.
        $latest = \local_coifish\api::get_current_enrolments_freshness($this->userid);
        if ($latest > 0) {
            $data->freshnesslabel = get_string(
                'freshness_label',
                'local_coifish',
                format_time(time() - $latest)
            );
        } else {
            $data->freshnesslabel = get_string('freshness_never', 'local_coifish');
        }
        $data->refreshurl = (new \moodle_url('/local/coifish/refreshcurrent.php', [
            'userid' => $this->userid,
            'sesskey' => sesskey(),
        ]))->out(false);

        // Back link.
        $data->backurl = (new \moodle_url('/local/coifish/index.php'))->out(false);

        return $data;
    }
}

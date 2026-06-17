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
 * External function returning a lecturer's per-assignment feedback breakdown for one course.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coifish\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Lazy per-assignment feedback drill-down for a lecturer in one course.
 */
class get_assignment_feedback extends external_api {
    /**
     * Define parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Lecturer user ID'),
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $userid Lecturer user ID.
     * @param int $courseid Course ID.
     * @return array List of per-assignment feedback rows.
     */
    public static function execute(int $userid, int $courseid): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'courseid' => $courseid,
        ]);

        require_login();
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coifish:viewlecturerprofile', \context_system::instance());

        // The sibling gradereport plugin is optional — degrade gracefully.
        if (
            !class_exists('\gradereport_coifish\report')
            || !method_exists('\gradereport_coifish\report', 'get_assignment_feedback_breakdown')
        ) {
            return [];
        }

        $rows = \gradereport_coifish\report::get_assignment_feedback_breakdown(
            $params['courseid'],
            $params['userid']
        );

        // Drop assignments a coordinator has marked as not feedback-relevant.
        $excluded = array_flip(\local_coifish\feedback_exclusions::get_excluded_cmids());
        if (!empty($excluded)) {
            $rows = array_values(array_filter($rows, function ($row) use ($excluded) {
                return !isset($excluded[(int)$row['cmid']]);
            }));
        }

        return $rows;
    }

    /**
     * Define return structure.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'cmid' => new external_value(PARAM_INT, 'Course module ID'),
                'name' => new external_value(PARAM_TEXT, 'Assignment name'),
                'coverage' => new external_value(PARAM_INT, 'Feedback coverage score'),
                'depth' => new external_value(PARAM_INT, 'Feedback depth score'),
                'quality' => new external_value(PARAM_INT, 'Feedback quality score'),
                'personalisation' => new external_value(PARAM_INT, 'Feedback personalisation score'),
                'composite' => new external_value(PARAM_INT, 'Composite feedback score'),
                'ngraded' => new external_value(PARAM_INT, 'Number of graded submissions'),
                'nwithfeedback' => new external_value(PARAM_INT, 'Number of submissions with feedback'),
            ])
        );
    }
}

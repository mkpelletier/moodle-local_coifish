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
 * External function to mark an assignment as not feedback-relevant (or re-include it).
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coifish\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_coifish\feedback_exclusions;

/**
 * Toggle the per-assignment "not feedback-relevant" exclusion for the feedback analytics.
 */
class toggle_feedback_exclusion extends external_api {
    /**
     * Define parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Assignment course-module ID'),
            'excluded' => new external_value(PARAM_BOOL, 'True to exclude from feedback analytics, false to re-include'),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $cmid Assignment course-module ID.
     * @param bool $excluded True to exclude, false to re-include.
     * @return array The new exclusion state for the course module.
     */
    public static function execute(int $cmid, bool $excluded): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'excluded' => $excluded,
        ]);

        require_login();

        // Must be a real assignment course module; this also gives us its course.
        $cm = get_coursemodule_from_id('assign', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_course::instance($cm->course);
        self::validate_context($context);
        require_capability('local/coifish:viewlecturerprofile', $context);

        feedback_exclusions::set_excluded($params['cmid'], $params['excluded']);

        return [
            'cmid' => $params['cmid'],
            'excluded' => $params['excluded'],
        ];
    }

    /**
     * Define return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Assignment course-module ID'),
            'excluded' => new external_value(PARAM_BOOL, 'The new exclusion state'),
        ]);
    }
}

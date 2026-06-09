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
 * External function to get lecturer time commitment data per course.
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
 * Get lecturer time commitment data per course for a date range.
 */
class get_lecturer_time_report extends external_api {
    /**
     * Define parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Lecturer user ID (0 for all lecturers)', VALUE_DEFAULT, 0),
            'timefrom' => new external_value(PARAM_INT, 'Start timestamp'),
            'timeto' => new external_value(PARAM_INT, 'End timestamp'),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $userid Lecturer user ID (0 for all).
     * @param int $timefrom Start timestamp.
     * @param int $timeto End timestamp.
     * @return array
     */
    public static function execute(int $userid, int $timefrom, int $timeto): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'timefrom' => $timefrom,
            'timeto' => $timeto,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/coifish:apiaccess', $context);

        $apienabled = get_config('local_coifish', 'api_enabled');
        if ($apienabled === '0') {
            return [];
        }

        // Get lecturers.
        if ($params['userid'] > 0) {
            $lecturerids = [$params['userid']];
        } else {
            $lecturerids = $DB->get_fieldset_select('local_coifish_lecturer', 'userid', '');
        }

        if (empty($lecturerids)) {
            return [];
        }

        // Bulk-load lecturer user records and their teacher-role course
        // assignments in two queries instead of N+1 per lecturer.
        [$uinsql, $uinparams] = $DB->get_in_or_equal($lecturerids, SQL_PARAMS_NAMED, 'uu');
        $namefields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
        $users = $DB->get_records_select('user', "id $uinsql", $uinparams, '', "id, $namefields");

        [$rinsql, $rinparams] = $DB->get_in_or_equal($lecturerids, SQL_PARAMS_NAMED, 'rl');
        [$trinsql, $trparams] = \local_coifish\filter_helper::get_teacher_role_sql();
        $assignments = $DB->get_records_sql(
            "SELECT DISTINCT ra.userid AS lectid, c.id AS courseid, c.fullname AS coursename, c.shortname
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
               JOIN {course} c ON c.id = ctx.instanceid
              WHERE ra.userid $rinsql
                AND ra.roleid $trinsql
                AND c.id != :siteid
           ORDER BY ra.userid ASC, c.shortname ASC",
            array_merge(['ctxlevel' => CONTEXT_COURSE, 'siteid' => SITEID], $rinparams, $trparams)
        );
        $coursesbylecturer = [];
        foreach ($assignments as $a) {
            $coursesbylecturer[(int)$a->lectid][] = $a;
        }

        $rows = [];
        foreach ($lecturerids as $uid) {
            $user = $users[$uid] ?? null;
            if (!$user) {
                continue;
            }
            $fullname = fullname($user);
            foreach ($coursesbylecturer[(int)$uid] ?? [] as $course) {
                $hours = \local_coifish\lecturer_api::estimate_activity_hours(
                    $uid,
                    [(int)$course->courseid],
                    (int)$params['timefrom'],
                    (int)$params['timeto']
                );

                $rows[] = [
                    'userid' => (int)$uid,
                    'fullname' => $fullname,
                    'courseid' => (int)$course->courseid,
                    'coursename' => format_string($course->coursename),
                    'shortname' => $course->shortname,
                    'hours_marking' => $hours['marking'],
                    'hours_communication' => $hours['communication'],
                    'hours_livesessions' => $hours['livesessions'],
                    'hours_total' => $hours['total'],
                    'timefrom' => $params['timefrom'],
                    'timeto' => $params['timeto'],
                ];
            }
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
                'userid' => new external_value(PARAM_INT, 'Lecturer user ID'),
                'fullname' => new external_value(PARAM_TEXT, 'Lecturer name'),
                'courseid' => new external_value(PARAM_INT, 'Course ID'),
                'coursename' => new external_value(PARAM_TEXT, 'Course name'),
                'shortname' => new external_value(PARAM_TEXT, 'Course shortname'),
                'hours_marking' => new external_value(PARAM_FLOAT, 'Marking and feedback hours'),
                'hours_communication' => new external_value(PARAM_FLOAT, 'Student communication hours'),
                'hours_livesessions' => new external_value(PARAM_FLOAT, 'Live session hours'),
                'hours_total' => new external_value(PARAM_FLOAT, 'Total hours'),
                'timefrom' => new external_value(PARAM_INT, 'Start timestamp'),
                'timeto' => new external_value(PARAM_INT, 'End timestamp'),
            ])
        );
    }
}

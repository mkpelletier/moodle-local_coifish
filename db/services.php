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
 * External function definitions for local_coifish.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_coifish_get_student_profile' => [
        'classname' => 'local_coifish\external\get_student_profile',
        'description' => 'Get a student\'s longitudinal profile.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/coifish:viewprofile',
    ],
    'local_coifish_get_early_warnings' => [
        'classname' => 'local_coifish\external\get_early_warnings',
        'description' => 'Get early warning profiles for at-risk students in a course.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/coifish:viewprofile',
    ],
    'local_coifish_get_course_history' => [
        'classname' => 'local_coifish\external\get_course_history',
        'description' => 'Get a student\'s course-by-course snapshot history.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'local/coifish:viewfullprofile',
    ],
    'local_coifish_get_lecturer_time_report' => [
        'classname' => 'local_coifish\external\get_lecturer_time_report',
        'description' => 'Get lecturer time commitment data per course for a date range.',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'local/coifish:apiaccess',
    ],
    'local_coifish_get_assignment_feedback' => [
        'classname' => 'local_coifish\external\get_assignment_feedback',
        'description' => 'Get a lecturer\'s per-assignment feedback breakdown for one course.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/coifish:viewlecturerprofile',
    ],
];

$services = [
    'CoIFish Longitudinal Profile API' => [
        'functions' => [
            'local_coifish_get_student_profile',
            'local_coifish_get_early_warnings',
            'local_coifish_get_course_history',
            'local_coifish_get_lecturer_time_report',
        ],
        'restrictedusers' => 0,
        'enabled' => 0,
        'shortname' => 'local_coifish_api',
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ],
];

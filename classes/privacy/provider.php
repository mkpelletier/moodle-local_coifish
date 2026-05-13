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
 * Privacy provider for local_coifish.
 *
 * Declares the four tables that store user-keyed data and implements
 * export / deletion / context-discovery for Moodle's privacy subsystem.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coifish\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Implementation of the privacy subsystem plugin provider for local_coifish.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * Tables in which local_coifish stores user-keyed data.
     *
     * All four are keyed by user id at the system level (no per-course context),
     * so we attach them to the system context for the purposes of the privacy API.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_coifish_profile',
            [
                'userid' => 'privacy:metadata:local_coifish_profile:userid',
                'coursescompleted' => 'privacy:metadata:local_coifish_profile:coursescompleted',
                'avggrade' => 'privacy:metadata:local_coifish_profile:avggrade',
                'gradetrend' => 'privacy:metadata:local_coifish_profile:gradetrend',
                'engagementpattern' => 'privacy:metadata:local_coifish_profile:engagementpattern',
                'risklevel' => 'privacy:metadata:local_coifish_profile:risklevel',
                'riskfactors' => 'privacy:metadata:local_coifish_profile:riskfactors',
                'timemodified' => 'privacy:metadata:local_coifish_profile:timemodified',
            ],
            'privacy:metadata:local_coifish_profile'
        );

        $collection->add_database_table(
            'local_coifish_course_snapshot',
            [
                'userid' => 'privacy:metadata:local_coifish_course_snapshot:userid',
                'courseid' => 'privacy:metadata:local_coifish_course_snapshot:courseid',
                'finalgrade' => 'privacy:metadata:local_coifish_course_snapshot:finalgrade',
                'engagement' => 'privacy:metadata:local_coifish_course_snapshot:engagement',
                'social' => 'privacy:metadata:local_coifish_course_snapshot:social',
                'selfregulation' => 'privacy:metadata:local_coifish_course_snapshot:selfregulation',
                'feedbackpct' => 'privacy:metadata:local_coifish_course_snapshot:feedbackpct',
                'interventioncount' => 'privacy:metadata:local_coifish_course_snapshot:interventioncount',
                'courseenddate' => 'privacy:metadata:local_coifish_course_snapshot:courseenddate',
                'timecreated' => 'privacy:metadata:local_coifish_course_snapshot:timecreated',
            ],
            'privacy:metadata:local_coifish_course_snapshot'
        );

        $collection->add_database_table(
            'local_coifish_active_snapshot',
            [
                'userid' => 'privacy:metadata:local_coifish_active_snapshot:userid',
                'courseid' => 'privacy:metadata:local_coifish_active_snapshot:courseid',
                'currentgrade' => 'privacy:metadata:local_coifish_active_snapshot:currentgrade',
                'engagement' => 'privacy:metadata:local_coifish_active_snapshot:engagement',
                'social' => 'privacy:metadata:local_coifish_active_snapshot:social',
                'selfregulation' => 'privacy:metadata:local_coifish_active_snapshot:selfregulation',
                'feedbackpct' => 'privacy:metadata:local_coifish_active_snapshot:feedbackpct',
                'timecomputed' => 'privacy:metadata:local_coifish_active_snapshot:timecomputed',
            ],
            'privacy:metadata:local_coifish_active_snapshot'
        );

        $collection->add_database_table(
            'local_coifish_lecturer',
            [
                'userid' => 'privacy:metadata:local_coifish_lecturer:userid',
                'coursecount' => 'privacy:metadata:local_coifish_lecturer:coursecount',
                'avgfeedbackquality' => 'privacy:metadata:local_coifish_lecturer:avgfeedbackquality',
                'avgstudentgrade' => 'privacy:metadata:local_coifish_lecturer:avgstudentgrade',
                'hours_total' => 'privacy:metadata:local_coifish_lecturer:hours_total',
                'strengths' => 'privacy:metadata:local_coifish_lecturer:strengths',
                'focusareas' => 'privacy:metadata:local_coifish_lecturer:focusareas',
                'timemodified' => 'privacy:metadata:local_coifish_lecturer:timemodified',
            ],
            'privacy:metadata:local_coifish_lecturer'
        );

        return $collection;
    }

    /**
     * Locate contexts where the user has data. All tables are system-scoped,
     * so we return the system context if any of them holds a row for the user.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.contextlevel = :sysctx
                   AND (
                        EXISTS (SELECT 1 FROM {local_coifish_profile} WHERE userid = :u1)
                     OR EXISTS (SELECT 1 FROM {local_coifish_course_snapshot} WHERE userid = :u2)
                     OR EXISTS (SELECT 1 FROM {local_coifish_active_snapshot} WHERE userid = :u3)
                     OR EXISTS (SELECT 1 FROM {local_coifish_lecturer} WHERE userid = :u4)
                   )";
        $params = [
            'sysctx' => CONTEXT_SYSTEM,
            'u1' => $userid,
            'u2' => $userid,
            'u3' => $userid,
            'u4' => $userid,
        ];
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Locate users who have data in a given context. Only the system context applies.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }

        foreach ([
            'local_coifish_profile',
            'local_coifish_course_snapshot',
            'local_coifish_active_snapshot',
            'local_coifish_lecturer',
        ] as $table) {
            $userlist->add_from_sql('userid', "SELECT userid FROM {{$table}}", []);
        }
    }

    /**
     * Export user data from the four tables, grouped under the plugin name
     * at the system context.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        $hasSystem = false;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel === CONTEXT_SYSTEM) {
                $hasSystem = true;
                $syscontext = $context;
                break;
            }
        }
        if (!$hasSystem) {
            return;
        }

        $subcontext = [get_string('pluginname', 'local_coifish')];

        $profile = $DB->get_record('local_coifish_profile', ['userid' => $userid]);
        if ($profile) {
            writer::with_context($syscontext)->export_data(
                array_merge($subcontext, ['profile']),
                (object)(array)$profile
            );
        }

        foreach (['local_coifish_course_snapshot', 'local_coifish_active_snapshot'] as $table) {
            $rows = $DB->get_records($table, ['userid' => $userid]);
            if (!empty($rows)) {
                writer::with_context($syscontext)->export_data(
                    array_merge($subcontext, [$table]),
                    (object)['rows' => array_values($rows)]
                );
            }
        }

        $lecturer = $DB->get_record('local_coifish_lecturer', ['userid' => $userid]);
        if ($lecturer) {
            writer::with_context($syscontext)->export_data(
                array_merge($subcontext, ['lecturer']),
                (object)(array)$lecturer
            );
        }
    }

    /**
     * Delete all user data in the given context. Because the plugin's data is
     * system-scoped per-user, this is only invoked for system context with the
     * effect of wiping every row in all four tables.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        $DB->delete_records('local_coifish_profile');
        $DB->delete_records('local_coifish_course_snapshot');
        $DB->delete_records('local_coifish_active_snapshot');
        $DB->delete_records('local_coifish_lecturer');
    }

    /**
     * Delete all data for the given user across the system context.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        if (empty($contextlist->count())) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel === CONTEXT_SYSTEM) {
                self::delete_user_rows($userid);
                return;
            }
        }
    }

    /**
     * Delete data for an approved userlist (system context only).
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        foreach ([
            'local_coifish_profile',
            'local_coifish_course_snapshot',
            'local_coifish_active_snapshot',
            'local_coifish_lecturer',
        ] as $table) {
            $DB->delete_records_select($table, "userid $insql", $params);
        }
    }

    /**
     * Remove the four tables' rows for a single user.
     *
     * @param int $userid
     */
    protected static function delete_user_rows(int $userid): void {
        global $DB;
        foreach ([
            'local_coifish_profile',
            'local_coifish_course_snapshot',
            'local_coifish_active_snapshot',
            'local_coifish_lecturer',
        ] as $table) {
            $DB->delete_records($table, ['userid' => $userid]);
        }
    }
}

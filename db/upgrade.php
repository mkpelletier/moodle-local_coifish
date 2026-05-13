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
 * Database upgrade steps for local_coifish.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin database schema.
 *
 * @param int $oldversion The old version of the plugin.
 * @return bool
 */
function xmldb_local_coifish_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026040200) {
        $table = new xmldb_table('local_coifish_lecturer');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('coursecount', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('avgfeedbackquality', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
        $table->add_field('avgcoverage', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
        $table->add_field('avgdepth', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
        $table->add_field('avgpersonalisation', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
        $table->add_field('avgturnarounddays', XMLDB_TYPE_NUMBER, '7, 1', null, null, null, null);
        $table->add_field('totalinterventions', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('interventionsimproved', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('interventioneffectiveness', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
        $table->add_field('avgforumpostspw', XMLDB_TYPE_NUMBER, '5, 1', null, null, null, null);
        $table->add_field('avgstudentgrade', XMLDB_TYPE_NUMBER, '7, 2', null, null, null, null);
        $table->add_field('studentgradetrend', XMLDB_TYPE_CHAR, '15', null, XMLDB_NOTNULL, null, 'unknown');
        $table->add_field('strengths', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('focusareas', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('userid', XMLDB_INDEX_UNIQUE, ['userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026040200, 'local', 'coifish');
    }

    if ($oldversion < 2026040201) {
        $table = new xmldb_table('local_coifish_lecturer');
        $fields = [
            new xmldb_field('hours_marking', XMLDB_TYPE_NUMBER, '7, 1', null, XMLDB_NOTNULL, null, '0', 'avgstudentgrade'),
            new xmldb_field('hours_communication', XMLDB_TYPE_NUMBER, '7, 1', null, XMLDB_NOTNULL, null, '0', 'hours_marking'),
            new xmldb_field('hours_livesessions', XMLDB_TYPE_NUMBER, '7, 1', null, XMLDB_NOTNULL, null, '0', 'hours_communication'),
            new xmldb_field('hours_total', XMLDB_TYPE_NUMBER, '7, 1', null, XMLDB_NOTNULL, null, '0', 'hours_livesessions'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026040201, 'local', 'coifish');
    }

    if ($oldversion < 2026040300) {
        // Ensure the lecturer table exists with all fields for installations
        // that may have skipped earlier steps.
        $table = new xmldb_table('local_coifish_lecturer');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('coursecount', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('avgfeedbackquality', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
            $table->add_field('avgcoverage', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
            $table->add_field('avgdepth', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
            $table->add_field('avgpersonalisation', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
            $table->add_field('avgturnarounddays', XMLDB_TYPE_NUMBER, '7, 1', null, null, null, null);
            $table->add_field('totalinterventions', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('interventionsimproved', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('interventioneffectiveness', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
            $table->add_field('avgforumpostspw', XMLDB_TYPE_NUMBER, '5, 1', null, null, null, null);
            $table->add_field('avgstudentgrade', XMLDB_TYPE_NUMBER, '7, 2', null, null, null, null);
            $table->add_field('studentgradetrend', XMLDB_TYPE_CHAR, '15', null, XMLDB_NOTNULL, null, 'unknown');
            $table->add_field('hours_marking', XMLDB_TYPE_NUMBER, '7, 1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('hours_communication', XMLDB_TYPE_NUMBER, '7, 1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('hours_livesessions', XMLDB_TYPE_NUMBER, '7, 1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('hours_total', XMLDB_TYPE_NUMBER, '7, 1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('strengths', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('focusareas', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid', XMLDB_INDEX_UNIQUE, ['userid']);
            $dbman->create_table($table);
        } else {
            // Table exists but may be missing hours fields.
            $ltable = new xmldb_table('local_coifish_lecturer');
            $after = 'avgstudentgrade';
            foreach (['hours_marking', 'hours_communication', 'hours_livesessions', 'hours_total'] as $fname) {
                $field = new xmldb_field($fname, XMLDB_TYPE_NUMBER, '7, 1', null, XMLDB_NOTNULL, null, '0', $after);
                if (!$dbman->field_exists($ltable, $field)) {
                    $dbman->add_field($ltable, $field);
                }
                $after = $fname;
            }
        }
        upgrade_plugin_savepoint(true, 2026040300, 'local', 'coifish');
    }

    if ($oldversion < 2026051300) {
        $table = new xmldb_table('local_coifish_active_snapshot');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('currentgrade', XMLDB_TYPE_NUMBER, '7, 2', null, null, null, null);
        $table->add_field('engagement', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
        $table->add_field('social', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
        $table->add_field('selfregulation', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
        $table->add_field('feedbackpct', XMLDB_TYPE_INTEGER, '3', null, null, null, null);
        $table->add_field('coursestartdate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('courseenddate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('termlabel', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('timecomputed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('userid_courseid', XMLDB_INDEX_UNIQUE, ['userid', 'courseid']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        $table->add_index('timecomputed', XMLDB_INDEX_NOTUNIQUE, ['timecomputed']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026051300, 'local', 'coifish');
    }

    return true;
}

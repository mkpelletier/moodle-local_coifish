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
 * CSV export of lecturer time commitments per course.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

$datefrom = optional_param('datefrom', '', PARAM_TEXT);
$dateto = optional_param('dateto', '', PARAM_TEXT);
$download = optional_param('download', 0, PARAM_BOOL);
$cohortid = optional_param('cohortid', 0, PARAM_INT);

require_login();

$systemcontext = context_system::instance();
$iscoordinator = has_capability('local/coifish:viewfullprofile', $systemcontext)
    || has_capability('local/coifish:viewlecturerprofile', $systemcontext);

if (!$iscoordinator) {
    throw new required_capability_exception($systemcontext, 'local/coifish:viewlecturerprofile', 'nopermissions', '');
}

// If download requested, generate CSV and exit.
if ($download && !empty($datefrom) && !empty($dateto)) {
    require_sesskey();
    $timefrom = strtotime($datefrom);
    $timeto = strtotime($dateto . ' 23:59:59');

    if ($timefrom === false || $timeto === false || $timefrom >= $timeto) {
        throw new moodle_exception('Invalid date range');
    }

    // Get lecturers visible to this user.
    $mode = \local_coifish\filter_helper::get_mode();
    if ($mode === 'cohort' && !is_siteadmin()) {
        $lecturerids = \local_coifish\filter_helper::get_filtered_lecturer_ids($cohortid);
    } else {
        $lecturerids = null;
    }

    // Build the data.
    $rows = local_coifish_generate_export_data($lecturerids, $timefrom, $timeto);

    // Output CSV.
    $filename = 'lecturer_time_report_' . $datefrom . '_to_' . $dateto;
    $csvexport = new csv_export_writer();
    $csvexport->set_filename($filename);
    $csvexport->add_data([
        get_string('export_col_lecturer', 'local_coifish'),
        get_string('export_col_course', 'local_coifish'),
        get_string('export_col_shortname', 'local_coifish'),
        get_string('export_col_marking', 'local_coifish'),
        get_string('export_col_communication', 'local_coifish'),
        get_string('export_col_livesessions', 'local_coifish'),
        get_string('export_col_total', 'local_coifish'),
        get_string('export_col_from', 'local_coifish'),
        get_string('export_col_to', 'local_coifish'),
    ]);

    foreach ($rows as $row) {
        $csvexport->add_data([
            $row['fullname'],
            $row['coursename'],
            $row['shortname'],
            $row['marking'],
            $row['communication'],
            $row['livesessions'],
            $row['total'],
            $datefrom,
            $dateto,
        ]);
    }

    $csvexport->download_file();
    exit;
}

// Show the export form page.
$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/coifish/export.php'));
$PAGE->set_title(get_string('export_title', 'local_coifish'));
$PAGE->set_heading(get_string('export_title', 'local_coifish'));
$PAGE->set_pagelayout('report');

$PAGE->navbar->add(
    get_string('lecturer_profiles_title', 'local_coifish'),
    new moodle_url('/local/coifish/lecturerprofile.php')
);
$PAGE->navbar->add(get_string('export_title', 'local_coifish'));

echo $OUTPUT->header();

$renderable = new \local_coifish\output\export_form($datefrom, $dateto, $cohortid);
echo $OUTPUT->render_from_template('local_coifish/export_form', $renderable->export_for_template($OUTPUT));

echo $OUTPUT->footer();

/**
 * Generate the export data: one row per lecturer per course.
 *
 * @param array|null $lecturerids Lecturer IDs to include (null for all).
 * @param int $timefrom Start timestamp.
 * @param int $timeto End timestamp.
 * @return array Array of row data.
 */
function local_coifish_generate_export_data(?array $lecturerids, int $timefrom, int $timeto): array {
    global $DB;

    // Get lecturer profiles.
    if ($lecturerids !== null) {
        if (empty($lecturerids)) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($lecturerids, SQL_PARAMS_NAMED);
        $lecturers = $DB->get_records_select('local_coifish_lecturer', "userid $insql", $inparams);
    } else {
        $lecturers = $DB->get_records('local_coifish_lecturer');
    }

    if (empty($lecturers)) {
        return [];
    }

    // Bulk-load lecturer user records in one query keyed by userid.
    $allids = array_column(array_values($lecturers), 'userid');
    [$uinsql, $uinparams] = $DB->get_in_or_equal($allids, SQL_PARAMS_NAMED, 'uu');
    $namefields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
    $users = $DB->get_records_select('user', "id $uinsql", $uinparams, '', "id, $namefields");

    // Bulk-load all teacher role assignments for these lecturers in one query.
    [$rinsql, $rinparams] = $DB->get_in_or_equal($allids, SQL_PARAMS_NAMED, 'rl');
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

    // Group courses by lecturer id.
    $coursesbylecturer = [];
    foreach ($assignments as $a) {
        $coursesbylecturer[(int)$a->lectid][] = $a;
    }

    // Programme scoping pattern (same for every row, so compute once).
    $pattern = '';
    if (!is_siteadmin()) {
        $pattern = \local_coifish\filter_helper::get_user_course_pattern();
    }

    $rows = [];
    foreach ($lecturers as $lecturer) {
        $user = $users[$lecturer->userid] ?? null;
        if (!$user) {
            continue;
        }
        $fullname = fullname($user);

        $courses = $coursesbylecturer[(int)$lecturer->userid] ?? [];
        foreach ($courses as $course) {
            if (!empty($pattern) && !preg_match('/' . $pattern . '/i', $course->shortname)) {
                continue;
            }

            // Compute time for this specific course in the user's chosen date range.
            $hours = \local_coifish\lecturer_api::estimate_activity_hours(
                $lecturer->userid,
                [$course->courseid],
                $timefrom,
                $timeto
            );

            $rows[] = [
                'fullname' => $fullname,
                'coursename' => format_string($course->coursename),
                'shortname' => $course->shortname,
                'marking' => $hours['marking'],
                'communication' => $hours['communication'],
                'livesessions' => $hours['livesessions'],
                'total' => $hours['total'],
            ];
        }
    }

    return $rows;
}

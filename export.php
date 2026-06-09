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

echo '<div class="mb-4">';
echo '<h4><i class="fa fa-download me-2"></i>' . get_string('export_title', 'local_coifish') . '</h4>';
echo '<p class="text-muted">' . get_string('export_desc', 'local_coifish') . '</p>';
echo '</div>';

echo '<form method="get" action="' . (new moodle_url('/local/coifish/export.php'))->out(true) . '">';
echo '<input type="hidden" name="download" value="1">';
echo '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';

// Cohort filter for cohort mode.
$mode = \local_coifish\filter_helper::get_mode();
if ($mode === 'cohort') {
    $filter = \local_coifish\filter_helper::get_filter_options($cohortid);
    echo '<div class="form-group row mb-3">';
    echo '  <label class="col-md-2 col-form-label">' . $filter['label'] . '</label>';
    echo '  <div class="col-md-4">';
    echo '    <select name="cohortid" class="form-select">';
    echo '      <option value="0">' . $filter['alllabel'] . '</option>';
    foreach ($filter['options'] as $opt) {
        $sel = $opt['selected'] ? ' selected' : '';
        echo '      <option value="' . $opt['id'] . '"' . $sel . '>' . s($opt['name']) . '</option>';
    }
    echo '    </select>';
    echo '  </div>';
    echo '</div>';
}

echo '<div class="form-group row mb-3">';
echo '  <label class="col-md-2 col-form-label" for="export-datefrom">'
    . get_string('lecturer_filter_from', 'local_coifish') . '</label>';
echo '  <div class="col-md-4">';
echo '    <input type="date" class="form-control" id="export-datefrom" name="datefrom" '
    . 'value="' . s($datefrom) . '" required>';
echo '  </div>';
echo '</div>';

echo '<div class="form-group row mb-3">';
echo '  <label class="col-md-2 col-form-label" for="export-dateto">'
    . get_string('lecturer_filter_to', 'local_coifish') . '</label>';
echo '  <div class="col-md-4">';
echo '    <input type="date" class="form-control" id="export-dateto" name="dateto" '
    . 'value="' . s($dateto) . '" required>';
echo '  </div>';
echo '</div>';

echo '<div class="form-group row">';
echo '  <div class="col-md-6">';
echo '    <button type="submit" class="btn btn-primary">';
echo '      <i class="fa fa-download me-1"></i>' . get_string('export_download', 'local_coifish');
echo '    </button>';
echo '    <a href="' . (new moodle_url('/local/coifish/lecturerprofile.php'))->out(true)
    . '" class="btn btn-outline-secondary ms-2">' . get_string('cancel') . '</a>';
echo '  </div>';
echo '</div>';

echo '</form>';
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

    $rows = [];

    foreach ($lecturers as $lecturer) {
        $user = $DB->get_record('user', ['id' => $lecturer->userid], 'id, firstname, lastname');
        if (!$user) {
            continue;
        }
        $fullname = fullname($user);

        // Get courses this lecturer teaches.
        [$trinsql, $trparams] = \local_coifish\filter_helper::get_teacher_role_sql();
        $courses = $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.fullname, c.shortname
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
               JOIN {course} c ON c.id = ctx.instanceid
              WHERE ra.userid = :uid
                AND ra.roleid $trinsql
                AND c.id != :siteid
           ORDER BY c.shortname ASC",
            array_merge(['ctxlevel' => CONTEXT_COURSE, 'uid' => $lecturer->userid, 'siteid' => SITEID], $trparams)
        );

        // Apply programme scoping for non-admins.
        $pattern = '';
        if (!is_siteadmin()) {
            $pattern = \local_coifish\filter_helper::get_user_course_pattern();
        }

        foreach ($courses as $course) {
            if (!empty($pattern) && !preg_match('/' . $pattern . '/i', $course->shortname)) {
                continue;
            }

            // Compute time for this specific course in the date range.
            $hours = \local_coifish\lecturer_api::estimate_activity_hours(
                $lecturer->userid,
                [$course->id],
                $timeto
            );

            $rows[] = [
                'fullname' => $fullname,
                'coursename' => format_string($course->fullname),
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

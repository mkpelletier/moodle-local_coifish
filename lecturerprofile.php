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
 * Lecturer profile page.
 *
 * Shows either a single lecturer's performance profile or a list
 * of all lecturers for programme coordinators.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

$userid = optional_param('userid', 0, PARAM_INT);
$categoryid = optional_param('categoryid', 0, PARAM_INT);
$cohortid = optional_param('cohortid', 0, PARAM_INT);
$datefrom = optional_param('datefrom', '', PARAM_TEXT);
$dateto = optional_param('dateto', '', PARAM_TEXT);
$courseid = optional_param('courseid', 0, PARAM_INT);

$mode = \local_coifish\filter_helper::get_mode();
$filterid = ($mode === 'cohort') ? $cohortid : $categoryid;

require_login();

global $USER;

$systemcontext = context_system::instance();
$iscoordinator = has_capability('local/coifish:viewfullprofile', $systemcontext)
    || has_capability('local/coifish:viewlecturerprofile', $systemcontext);

if ($userid > 0) {
    // Viewing a specific lecturer's profile.
    $isself = ($userid == $USER->id);
    if (!$isself && !$iscoordinator) {
        require_capability('local/coifish:viewlecturerprofile', $systemcontext);
    }

    $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    $title = $isself
        ? get_string('my_teaching_profile', 'local_coifish')
        : get_string('lecturer_profile_title', 'local_coifish', fullname($user));

    $PAGE->set_context($systemcontext);
    $PAGE->set_url(new moodle_url('/local/coifish/lecturerprofile.php', ['userid' => $userid]));
    $PAGE->set_title($title);
    $PAGE->set_heading($title);
    $PAGE->set_pagelayout('report');

    if ($iscoordinator && !$isself) {
        $PAGE->navbar->add(
            get_string('lecturer_profiles_title', 'local_coifish'),
            new moodle_url('/local/coifish/lecturerprofile.php')
        );
        $PAGE->navbar->add(fullname($user));
    }

    $renderable = new \local_coifish\output\lecturer_profile($userid, $isself, $datefrom, $dateto, $courseid);
    $PAGE->requires->js_call_amd('local_coifish/feedback_breakdown', 'init', [$userid]);
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_coifish/lecturer_profile', $renderable->export_for_template($OUTPUT));
    echo $OUTPUT->footer();
} else {
    // List all lecturers — requires coordinator capability.
    if (!$iscoordinator) {
        // Non-coordinators: redirect to self-view.
        redirect(new moodle_url('/local/coifish/lecturerprofile.php', ['userid' => $USER->id]));
    }

    $PAGE->set_context($systemcontext);
    $PAGE->set_url(new moodle_url('/local/coifish/lecturerprofile.php', ['categoryid' => $categoryid]));
    $PAGE->set_title(get_string('lecturer_profiles_title', 'local_coifish'));
    $PAGE->set_heading(get_string('lecturer_profiles_title', 'local_coifish'));
    $PAGE->set_pagelayout('report');

    $renderable = new \local_coifish\output\lecturer_list($filterid);
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_coifish/lecturer_list', $renderable->export_for_template($OUTPUT));
    echo $OUTPUT->footer();
}

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
 * Student risk overview page.
 *
 * Displays all at-risk students across the institution with filtering
 * by course category and risk level.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

// Accept both categoryid and cohortid — the filter helper determines which is active.
$categoryid = optional_param('categoryid', 0, PARAM_INT);
$cohortid = optional_param('cohortid', 0, PARAM_INT);
$risklevel = optional_param('risklevel', 'all', PARAM_ALPHA);
$enrolstatus = optional_param('enrolstatus', 'current', PARAM_ALPHA);
if (!in_array($enrolstatus, ['all', 'current', 'notenrolled'], true)) {
    $enrolstatus = 'current';
}

require_login();

$mode = \local_coifish\filter_helper::get_mode();
$filterid = ($mode === 'cohort') ? $cohortid : $categoryid;

$context = context_system::instance();
require_capability('local/coifish:viewfullprofile', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/coifish/index.php', [
    ($mode === 'cohort' ? 'cohortid' : 'categoryid') => $filterid,
    'risklevel' => $risklevel,
    'enrolstatus' => $enrolstatus,
]));
$PAGE->set_title(get_string('risk_overview_title', 'local_coifish'));
$PAGE->set_heading(get_string('risk_overview_title', 'local_coifish'));
$PAGE->set_pagelayout('report');

$renderable = new \local_coifish\output\risk_overview($filterid, $risklevel, $enrolstatus);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_coifish/risk_overview', $renderable->export_for_template($OUTPUT));
echo $OUTPUT->footer();

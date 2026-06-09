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
 * On-demand refresh of a student's current-enrolment active snapshots.
 *
 * Each course is throttled to at most one refresh per hour. Iterates the
 * student's currently active enrolments and re-runs the shared metrics
 * calculation for any course whose snapshot is older than the throttle window
 * (or missing). Redirects back to the drill-down with a status message
 * summarising what was refreshed vs skipped.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

$userid = required_param('userid', PARAM_INT);

require_login();
require_sesskey();

$context = context_system::instance();
require_capability('local/coifish:viewfullprofile', $context);

$student = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);

$returnurl = new moodle_url('/local/coifish/studentprofile.php', ['userid' => $userid]);
$throttle = 3600; // 1 hour per course.
$now = time();

// Active enrolled courses for this student.
[$catfrag, $catparams] = \local_coifish\filter_helper::get_category_scope_sql('c');
$courses = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.fullname, c.shortname, c.category, c.startdate, c.enddate
       FROM {user_enrolments} ue
       JOIN {enrol} e ON e.id = ue.enrolid
       JOIN {course} c ON c.id = e.courseid
      WHERE ue.userid = :uid
        AND ue.status = 0
        AND (ue.timeend = 0 OR ue.timeend > :now1)
        AND (ue.timestart = 0 OR ue.timestart <= :now2)
        AND c.id != :siteid
        AND c.visible = 1
        AND (c.enddate = 0 OR c.enddate > :now3)
        $catfrag",
    array_merge([
        'uid' => $userid,
        'now1' => $now,
        'now2' => $now,
        'now3' => $now,
        'siteid' => SITEID,
    ], $catparams)
);

$refreshed = 0;
$skipped = 0;
$nodata = 0;
$inscopeids = [];

// Pre-load all this user's existing snapshot rows in one query so the loop
// below does in-memory lookups instead of issuing get_record per course.
$existingbycourse = $DB->get_records(
    'local_coifish_active_snapshot',
    ['userid' => $userid],
    '',
    'courseid, id, timecomputed'
);

foreach ($courses as $course) {
    $inscopeids[] = (int)$course->id;
    $existing = $existingbycourse[$course->id] ?? null;

    if ($existing && ((int)$existing->timecomputed) > ($now - $throttle)) {
        $skipped++;
        continue;
    }

    $ok = \local_coifish\task\build_active_snapshots::refresh_one(
        $course, $userid, null, null, $now, $existing
    );
    if ($ok) {
        $refreshed++;
    } else {
        $nodata++;
    }
}

// Remove rows for courses the student is no longer actively enrolled in (or
// that have fallen out of scope: hidden, ended, or excluded by category).
$removed = 0;
if (empty($inscopeids)) {
    $removed = $DB->count_records('local_coifish_active_snapshot', ['userid' => $userid]);
    $DB->delete_records('local_coifish_active_snapshot', ['userid' => $userid]);
} else {
    [$insql, $inparams] = $DB->get_in_or_equal($inscopeids, SQL_PARAMS_NAMED, 'rk', false);
    $select = "userid = :uid AND courseid $insql";
    $removed = $DB->count_records_select(
        'local_coifish_active_snapshot',
        $select,
        array_merge(['uid' => $userid], $inparams)
    );
    $DB->delete_records_select(
        'local_coifish_active_snapshot',
        $select,
        array_merge(['uid' => $userid], $inparams)
    );
}

$a = (object)['refreshed' => $refreshed, 'skipped' => $skipped, 'nodata' => $nodata, 'removed' => $removed];
if ($refreshed === 0 && $skipped > 0 && $removed === 0) {
    \core\notification::info(get_string('refresh_throttled', 'local_coifish', $a));
} else if ($refreshed === 0 && $nodata === 0 && $skipped === 0 && $removed === 0) {
    \core\notification::info(get_string('refresh_none', 'local_coifish'));
} else {
    \core\notification::success(get_string('refresh_done', 'local_coifish', $a));
}

redirect($returnurl);

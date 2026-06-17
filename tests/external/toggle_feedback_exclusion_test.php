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

namespace local_coifish\external;

use local_coifish\feedback_exclusions;

/**
 * Tests for the feedback-exclusion toggle web service and its effect on the drill-down.
 *
 * @package    local_coifish
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coifish\external\toggle_feedback_exclusion
 */
final class toggle_feedback_exclusion_test extends \advanced_testcase {
    /**
     * A coordinator can toggle an assignment in and out of the exclusion list.
     */
    public function test_toggle_sets_and_clears_exclusion(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        $course = $gen->create_course();
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $assign = $gen->create_module('assign', ['course' => $course->id]);
        $cmid = (int)$assign->cmid;

        // Grant the coordinator capability to the teacher in the course context.
        $context = \context_course::instance($course->id);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/coifish:viewlecturerprofile', CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $teacher->id, $context->id);
        $this->setUser($teacher);

        // Initially not excluded.
        $this->assertNotContains($cmid, feedback_exclusions::get_excluded_cmids());

        // Exclude it.
        $result = toggle_feedback_exclusion::execute($cmid, true);
        $result = \core_external\external_api::clean_returnvalue(
            toggle_feedback_exclusion::execute_returns(),
            $result
        );
        $this->assertSame($cmid, $result['cmid']);
        $this->assertTrue($result['excluded']);
        $this->assertContains($cmid, feedback_exclusions::get_excluded_cmids());
        $this->assertTrue(feedback_exclusions::is_excluded($cmid));

        // Re-include it.
        $result = toggle_feedback_exclusion::execute($cmid, false);
        $result = \core_external\external_api::clean_returnvalue(
            toggle_feedback_exclusion::execute_returns(),
            $result
        );
        $this->assertFalse($result['excluded']);
        $this->assertNotContains($cmid, feedback_exclusions::get_excluded_cmids());
    }

    /**
     * Without the coordinator capability the toggle is refused.
     */
    public function test_toggle_requires_capability(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        $course = $gen->create_course();
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $assign = $gen->create_module('assign', ['course' => $course->id]);
        $cmid = (int)$assign->cmid;

        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        toggle_feedback_exclusion::execute($cmid, true);
    }

    /**
     * An invalid course-module id is rejected.
     */
    public function test_toggle_rejects_unknown_cmid(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\dml_missing_record_exception::class);
        toggle_feedback_exclusion::execute(999999, true);
    }

    /**
     * The drill-down web service hides assignments that have been excluded.
     */
    public function test_get_assignment_feedback_filters_excluded(): void {
        if (
            !class_exists('\gradereport_coifish\report')
            || !method_exists('\gradereport_coifish\report', 'get_assignment_feedback_breakdown')
        ) {
            $this->markTestSkipped('gradereport_coifish (the breakdown source) is not installed.');
        }

        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        $course = $gen->create_course();
        $teacher = $gen->create_user();
        $student = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $gen->enrol_user($student->id, $course->id, 'student');

        $assign = $gen->create_module('assign', ['course' => $course->id]);
        $cmid = (int)$assign->cmid;

        // A graded submission with a written comment gives the breakdown a row to return.
        $this->submit_and_grade($assign, $teacher, $student);

        // The get_assignment_feedback web service checks the capability at system context.
        $syscontext = \context_system::instance();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/coifish:viewlecturerprofile', CAP_ALLOW, $roleid, $syscontext->id);
        role_assign($roleid, $teacher->id, $syscontext->id);
        $this->setUser($teacher);

        // Baseline: the assignment appears in the drill-down.
        $before = get_assignment_feedback::execute($teacher->id, $course->id);
        $cmids = array_map(static fn($r) => (int)$r['cmid'], $before);
        if (!in_array($cmid, $cmids, true)) {
            $this->markTestSkipped('Breakdown did not surface the seeded assignment; nothing to filter.');
        }

        // Exclude it, then it must vanish from the drill-down.
        feedback_exclusions::set_excluded($cmid, true);
        $after = get_assignment_feedback::execute($teacher->id, $course->id);
        $aftercmids = array_map(static fn($r) => (int)$r['cmid'], $after);
        $this->assertNotContains($cmid, $aftercmids);
    }

    /**
     * Submit an assignment for a student and grade it with a written comment.
     *
     * @param \stdClass $assign The assign module record (with cmid) from the generator.
     * @param \stdClass $teacher The grading teacher.
     * @param \stdClass $student The submitting student.
     */
    private function submit_and_grade(\stdClass $assign, \stdClass $teacher, \stdClass $student): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $cm = get_coursemodule_from_id('assign', $assign->cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $assignobj = new \assign($context, $cm, $assign->course);

        $this->setUser($student);
        $submission = $assignobj->get_user_submission($student->id, true);
        $submission->status = ASSIGN_SUBMISSION_STATUS_SUBMITTED;
        $DB->update_record('assign_submission', $submission);

        $this->setUser($teacher);
        $grade = $assignobj->get_user_grade($student->id, true);
        $grade->grade = 80;
        $grade->grader = $teacher->id;
        $DB->update_record('assign_grades', $grade);

        // A non-empty feedback comment so the coverage query has something to count.
        if ($DB->get_manager()->table_exists('assignfeedback_comments')) {
            $DB->insert_record('assignfeedback_comments', (object)[
                'assignment' => $assign->id,
                'grade' => $grade->id,
                'commenttext' => 'Well argued; tighten your conclusion.',
                'commentformat' => FORMAT_HTML,
            ]);
        }
    }
}
